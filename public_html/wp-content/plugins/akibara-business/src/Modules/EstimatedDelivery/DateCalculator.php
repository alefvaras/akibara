<?php
/**
 * Calcula ETA para reservables y devuelve EtaResult con rango o null.
 * Los tipos son auto, release_date, tba y disabled. Un reloj inyectable
 * permite cálculos deterministas; disabled sólo se calcula como auto cuando
 * el llamador solicita ignorar Sin promesa.
 * El país procede de pa_pais y se representa por slug. Sin país o plazos
 * configurados no se inventa un rango: devuelve null o TBA según el tipo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

use Akibara\Business\Contracts\EtaProvider;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\Log;
use Akibara\Business\Utilities\StoreTime;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Date calculator puro para preventas Akibara.
 *
 * Implementa OrderPreventa\EtaProvider desde 1.14.0 — el módulo OrderPreventa
 * consume `calculate()`, `resolve_country()` y `get_country_name()` a través
 * de esa interfaz para desacoplar y permitir mocking en tests.
 *
 * @since 1.7.0
 */
final class DateCalculator implements EtaProvider {

	/**
	 * Postmeta del override per-producto.
	 * Valores: los de `Availability::TYPES`, que es su única declaración.
	 *
	 * Desde 1.109.0 el key canónico vive en Stock\Availability: el estado
	 * comercial (A pedido vs Preventa) se deriva de este meta, así que la
	 * autoridad es suya; acá queda la referencia para el cálculo de fechas.
	 */
	public const META_TYPE = Availability::META_TYPE;

	/**
	 * Postmeta de la fecha de lanzamiento editorial (formato Y-m-d).
	 * Solo aplica si META_TYPE = 'release_date'. Canónico en Stock\Availability.
	 */
	public const META_RELEASE_DATE = Availability::META_RELEASE_DATE;

	/**
	 * Plazo personalizado en días corridos tras el lanzamiento para tipos
	 * release_date/tba. Si falta o es inválido, hereda la tanda editorial o el país.
	 * Precedencia: ficha → tanda de editorial → país.
	 */
	public const META_MANUAL_MIN_DIAS = '_akb_eta_manual_min_dias';
	public const META_MANUAL_MAX_DIAS = '_akb_eta_manual_max_dias';

	/**
	 * Taxonomy WooCommerce que modela el país de origen del producto.
	 * Owner gestiona desde wp-admin → Productos → Atributos → País.
	 */
	private const PA_PAIS_TAXONOMY = 'pa_pais';

	private Settings $settings;

	/** Política de preventa por editorial; por defecto, la de siempre. */
	private PedidoPolicy $policy;

	private array $country_resolution_cache = array();

	/**
	 * Clock injectable. Default: PHP `time()`.
	 *
	 * @var callable():int
	 */
	private $now;

	/**
	 * Zona del sitio para aritmética de fechas y días de semana. El runtime
	 * puede usar UTC; interpretar allí una fecha chilena puede cambiar su día.
	 */
	private \DateTimeZone $tz;

	/**
	 * @param Settings           $settings Storage de configuración.
	 * @param callable|null      $now      Clock override (tests). Si null usa `time`.
	 * @param \DateTimeZone|null $tz       Timezone (tests). Default: wp_timezone() o UTC.
	 */
	public function __construct( Settings $settings, ?callable $now = null, ?\DateTimeZone $tz = null, ?PedidoPolicy $policy = null ) {
		$this->settings = $settings;
		$this->policy   = $policy ?? new PedidoPolicy();
		$this->now      = $now ?? 'time';

		$this->tz = $tz ?? StoreTime::zone();
	}

	/**
	 * «Ahora» según el reloj de ESTE calculador.
	 *
	 * Existe para que quien pinta (BannerRenderer) mida la cuenta atrás del corte
	 * contra el mismo reloj que produjo la fecha del banner. Con `time()` suelto
	 * en el renderer, un test que congela el reloj del calculador seguiría viendo
	 * la hora real y la prueba pasaría sin probar nada.
	 */
	public function now(): int {
		return (int) ( $this->now )();
	}

	/**
	 * Zona horaria de la tienda según ESTE calculador. Misma razón que `now()`.
	 */
	public function timezone(): \DateTimeZone {
		return $this->tz;
	}

	/**
	 * Calcula la ETA para un producto. Null si no aplica banner.
	 *
	 * @param WC_Product $product             Producto Woo.
	 * @param bool       $ignorar_sin_promesa Tratar «Sin promesa de fecha» como un «a pedido» normal (sólo el puente a Google, en modo «fecha del próximo pedido»).
	 * @return EtaResult|null
	 */
	public function calculate( WC_Product $product, bool $ignorar_sin_promesa = false ): ?EtaResult {
		if ( ! StockStatus::is_reservable( $product ) ) {
			return null;
		}

		$type   = $this->resolve_type( $product );
		$policy = $this->policy->for_product( $product );

		if ( Availability::TYPE_DISABLED === $type ) {
			// Sin promesa de fecha no se muestra ETA en la ficha. El modo Google
			// fecha del próximo pedido puede solicitar el cálculo como tipo automático.
			if ( ! $ignorar_sin_promesa ) {
				return null;
			}
			$type = Availability::TYPE_AUTO;
		}

		// Editorial con la preventa pausada: sin fecha, y a propósito.
		//
		// Cuando una distribuidora deja de ser fiable, lo honesto no es estirar el
		// plazo sino dejar de prometer una fecha. Heredar el plazo del país sería
		// prometer sobre una cadena que no responde.
		if ( PedidoPolicy::MODO_PAUSADA === $policy['modo'] ) {
			return EtaResult::tba();
		}

		$country_slug = $this->resolve_country( $product );
		$days         = null !== $country_slug ? $this->settings->get_country_days( $country_slug ) : null;

		// Plazo personalizado de la ficha: SOLO para preventas (el «a pedido»
		// se rige por su editorial/país, que es el punto de tener reglas). Si
		// existe, gana sobre la tanda y sobre el país: es el override artesanal
		// que el dueño escribe caso a caso.
		$manual = in_array( $type, Availability::TYPES_PREVENTA, true )
			? $this->resolve_manual_lead( $product )
			: null;

		if ( Availability::TYPE_TBA === $type ) {
			if ( null !== $manual ) {
				// «Llega ~X-Y días tras su publicación», sin país: el plazo lo
				// puso el dueño para ESTA ficha, no la regla de un origen.
				return EtaResult::tba_with_lead( $manual['min'], $manual['max'], null );
			}
			// Bajo una editorial por tanda, el plazo del país ya no describe nada: la
			// espera real es «hasta el próximo cierre, y desde ahí el plazo de la
			// tanda». Sin fecha de publicación no hay cierre que calcular, así que
			// decir «llega ~2 semanas tras su salida» sería prometer un número que la
			// política ya no sostiene. Se dice lo único cierto: todavía no hay fecha.
			if ( PedidoPolicy::MODO_TANDA === $policy['modo'] && $policy['dia'] > 0 && $policy['max'] > 0 ) {
				return EtaResult::tba();
			}

			// TBA con lead time conocido → renderer muestra "Llega ~X semanas tras su
			// salida en {país}". Sin pa_pais/lead → fallback genérico tba().
			if ( null === $days ) {
				return EtaResult::tba();
			}
			$country_name = null !== $country_slug ? $this->settings->get_country_name( $country_slug ) : null;
			return EtaResult::tba_with_lead( $days['min'], $days['max'], $country_name );
		}

		// Una tanda BIEN configurada es autosuficiente: el cierre y el plazo salen
		// de la editorial, no del país. Exigirle además un `pa_pais` con lead times
		// dejaba el banner MUDO en un producto perfectamente configurado — y el
		// dueño no tendría cómo adivinar que el culpable es un atributo que su
		// política ya no usa. `Settings::get_country_days()` sólo trae defaults
		// para argentina y espana, así que cualquier país nuevo caía acá.
		$tanda_completa = PedidoPolicy::tanda_utilizable( $policy );

		// Tipos con fecha calculable (auto, release_date).
		if ( null === $days && ! $tanda_completa && null === $manual ) {
			return null;
		}

		$base = $this->resolve_base_timestamp( $product, $type );

		// Override artesanal por ficha (solo preventas; ver arriba): días
		// CORRIDOS desde el lanzamiento, como lo escribe el dueño («~2 meses»).
		// Gana sobre la tanda: si el dueño escribió un plazo en ESTA ficha, esa
		// es la promesa, no el corte general de la editorial.
		if ( null !== $manual ) {
			return EtaResult::range(
				self::sumar_dias_corridos( $base, $manual['min'], $this->tz ),
				self::sumar_dias_corridos( $base, $manual['max'], $this->tz )
			);
		}

		// Modo TANDA: el plazo corre desde el CIERRE, no desde la compra.
		//
		// Es la diferencia que hace la promesa sostenible, y es la redacción que
		// usa el mercado («2 a 3 semanas después del cierre del pedido»). Con el
		// plazo contado desde la compra, dos clientes de la misma tanda reciben
		// fechas distintas para mercadería que viaja en la misma caja.
		//
		// `$base` ya es lo más tardío entre hoy y la fecha de lanzamiento, así que
		// un tomo que sale el 20 no puede colarse en el corte del 5: el cierre se
		// busca A PARTIR de que la mercadería existe.
		if ( $tanda_completa ) {
			$cierre = $this->policy->proximo_cierre( $base, $policy['dia'], $this->tz, (string) ( $policy['frecuencia'] ?? '' ), (string) ( $policy['meses'] ?? '' ) );

			// Días CORRIDOS, no hábiles: «tres semanas después del cierre» es como
			// lo lee un cliente y como lo escribe la competencia. Los días hábiles
			// tienen sentido contando trabajo de bodega, no una espera de barco.
			// Días de CALENDARIO, no múltiplos de 86.400 segundos. Chile atrasa el
			// reloj el primer domingo de abril: con aritmética de segundos, un
			// cierre del 5 de marzo más 35 días caía a las 23:00 del día ANTERIOR y
			// el banner imprimía un día menos del prometido. El resto del archivo ya
			// usa `DateTimeImmutable::modify()` por exactamente esta razón.
			return EtaResult::range(
				self::sumar_dias_corridos( $cierre, $policy['min'], $this->tz ),
				self::sumar_dias_corridos( $cierre, $policy['max'], $this->tz )
			);
		}

		$business_only = $this->settings->business_days_only();

		$min_ts = $this->add_days( $base, $days['min'], $business_only );
		$max_ts = $this->add_days( $base, $days['max'], $business_only );

		return EtaResult::range( $min_ts, $max_ts );
	}

	/**
	 * Preview admin del cálculo auto — omite stock check y fuerza type=auto.
	 *
	 * Usado por ProductDataPanel para mostrar inline debajo del radio "Auto"
	 * lo que la lógica produciría cuando el producto pase a onbackorder, así
	 * el owner valida que el mapping `pa_pais` + lead times está bien antes
	 * de activar la preventa.
	 *
	 * Devuelve un array discriminado por `status`:
	 *
	 *   - `no_pa_pais`         → producto sin término en `pa_pais`.
	 *   - `invalid_lead_time`  → término pa_pais asignado pero sin lead times
	 *                            válidos (term-meta vacía + sin option legacy +
	 *                            slug no está en DEFAULT_LEAD_TIMES).
	 *   - `ok`                 → preview computado (country_slug, country_name,
	 *                            min/max days, min/max timestamps).
	 *
	 * @param WC_Product $product Producto a previewar.
	 * @return array{status:string, country_slug?:string, country_name?:string, min_days?:int, max_days?:int, min_ts?:int, max_ts?:int}
	 */
	public function preview_for_admin( WC_Product $product ): array {
		$country_slug = $this->resolve_country( $product );
		if ( null === $country_slug ) {
			return array( 'status' => 'no_pa_pais' );
		}

		$country_name = $this->settings->get_country_name( $country_slug ) ?? $country_slug;
		$days         = $this->settings->get_country_days( $country_slug );

		if ( null === $days ) {
			return array(
				'status'       => 'invalid_lead_time',
				'country_slug' => $country_slug,
				'country_name' => $country_name,
			);
		}

		$base          = $this->resolve_base_timestamp( $product, Availability::TYPE_AUTO );
		$business_only = $this->settings->business_days_only();
		$min_ts        = $this->add_days( $base, $days['min'], $business_only );
		$max_ts        = $this->add_days( $base, $days['max'], $business_only );

		return array(
			'status'       => 'ok',
			'country_slug' => $country_slug,
			'country_name' => $country_name,
			'min_days'     => $days['min'],
			'max_days'     => $days['max'],
			'min_ts'       => $min_ts,
			'max_ts'       => $max_ts,
		);
	}

	/**
	 * Resuelve el slug pa_pais del producto.
	 *
	 * Defensive: `get_the_terms` retorna array de objetos WP_Term — extrae los
	 * slugs y toma el primero. Si el producto tuviera múltiples términos pa_pais
	 * (error humano), gana el primero sin fallar.
	 *
	 * Público desde 1.14.0 — consumido por OrderPreventa\LineItemSnapshot
	 * para snapshot inmutable del país al momento de la compra.
	 *
	 * @param WC_Product $product Producto.
	 * @return string|null Slug del término (e.g., 'argentina', 'espana') o null.
	 */
	public function resolve_country( WC_Product $product ): ?string {
		$product_id = $product->get_id();
		if ( array_key_exists( $product_id, $this->country_resolution_cache ) ) {
			return $this->country_resolution_cache[ $product_id ];
		}

		$result = $this->do_resolve_country( $product );
		$this->country_resolution_cache[ $product_id ] = $result;
		return $result;
	}

	private function do_resolve_country( WC_Product $product ): ?string {
		if ( ! function_exists( 'get_the_terms' ) ) {
			return null;
		}
		// get_the_terms lee el object-term cache que el WP_Query del loop ya primó
		// (update_post_term_cache) → 0 queries en caliente. wp_get_post_terms con
		// fields=>slugs forzaba un get_terms() fresco (1 query/card en /preventas/).
		$terms = get_the_terms( $product->get_id(), self::PA_PAIS_TAXONOMY );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) {
			return null;
		}
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return null;
		}
		// Toma el primer slug (mismo patrón foreach que DiscountResolver/BundleCouponEngine
		// sobre get_the_terms; sin wp_list_pluck para no depender de él en aislamiento).
		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->slug ) && '' !== (string) $term->slug ) {
				return strtolower( (string) $term->slug );
			}
		}
		return null;
	}

	/**
	 * Devuelve el display name del país para un slug. Delega a Settings.
	 *
	 * Público desde 1.14.0 — consumido por OrderPreventa\LineItemSnapshot
	 * (snapshot inmutable del nombre legible al momento de la compra, así el
	 * snapshot sobrevive si owner renombra el término).
	 *
	 * @param string $slug Slug del término pa_pais.
	 * @return string|null Display name o null si no hay manera de resolverlo.
	 */
	public function get_country_name( string $slug ): ?string {
		return $this->settings->get_country_name( $slug );
	}

	/**
	 * Lee el plazo personalizado de la ficha, o null si no es utilizable.
	 *
	 * Ambos extremos deben ser enteros 1–365 y max >= min: un plazo a medias
	 * (solo min, o max menor que min) se ignora COMPLETO — mejor caer a la
	 * regla general que imprimir un rango imposible.
	 *
	 * @param WC_Product $product Producto.
	 * @return array{min:int, max:int}|null
	 */
	private function resolve_manual_lead( WC_Product $product ): ?array {
		// get_post_meta por la misma razón que resolve_type (meta-cache primado).
		$min = (int) get_post_meta( $product->get_id(), self::META_MANUAL_MIN_DIAS, true );
		$max = (int) get_post_meta( $product->get_id(), self::META_MANUAL_MAX_DIAS, true );

		if ( $min < 1 || $min > 365 || $max < $min || $max > 365 ) {
			return null;
		}

		return array(
			'min' => $min,
			'max' => $max,
		);
	}

	/**
	 * Resuelve el tipo de preventa (auto/release_date/tba/disabled).
	 *
	 * @param WC_Product $product Producto.
	 * @return string
	 */
	/**
	 * ¿La ficha lleva «Sin promesa de fecha (no mostrar plazos en la ficha)»?
	 *
	 * @param WC_Product $product Producto.
	 * @return bool
	 */
	public function is_sin_promesa( WC_Product $product ): bool {
		// «Sin promesa de fecha» y «preventa sin fecha confirmada» (tba) tienen
		// lo mismo que decirle a Google: ninguna fecha. Sin esto, un tba iba al
		// feed como `PreOrder` sin `availabilityDate` — el rechazo de Merchant
		// Center que R8 cerró para el tipo `disabled` (revisión 1.116.40).
		return in_array( $this->resolve_type( $product ), array( Availability::TYPE_DISABLED, Availability::TYPE_TBA ), true );
	}

	private function resolve_type( WC_Product $product ): string {
		// Lee del meta-cache de WordPress ya primado por el loop. WC_Data puede
		// consultar postmeta directamente por tarjeta; ProductDataPanel comparte
		// este canal de lectura para las metas de ETA.
		$raw = get_post_meta( $product->get_id(), self::META_TYPE, true );
		return Availability::sanitize_type( is_string( $raw ) ? $raw : '' );
	}

	/**
	 * Timestamp base sobre el cual sumar el lead time.
	 *
	 * Siempre anclado a medianoche local (CL) para que el ts retornado represente
	 * un día calendario sin tiempo derivado de "now". Sin este floor, llamadas
	 * después de las 20:00 CL devolverían ts a horas que en UTC ya son del día
	 * siguiente y `BannerRenderer::format_range` (que usa `date()` en tz del
	 * server = UTC) imprimiría el día equivocado.
	 *
	 * - Tipo A: hoy CL a medianoche.
	 * - Tipo B con fecha futura: la release_date interpretada como medianoche CL.
	 * - Tipo B con fecha pasada/inválida: hoy CL a medianoche (fallback).
	 *
	 * @param WC_Product $product Producto.
	 * @param string     $type    Tipo resuelto.
	 * @return int Timestamp Unix.
	 */
	private function resolve_base_timestamp( WC_Product $product, string $type ): int {
		$now_local = $this->floor_to_local_midnight( ( $this->now )() );

		if ( Availability::TYPE_RELEASE_DATE !== $type ) {
			return $now_local;
		}

		// get_post_meta por la misma razón que resolve_type (meta-cache primado).
		$raw = get_post_meta( $product->get_id(), self::META_RELEASE_DATE, true );
		$raw = is_string( $raw ) ? $raw : '';
		if ( '' === $raw ) {
			return $now_local;
		}

		// Intérprete único, en Availability, que es la dueña de la meta.
		$release_ts = Availability::release_timestamp( $raw, $this->tz );
		if ( null === $release_ts ) {
			// release_date corrupta → fallback a 'hoy'. Sin traza, la ETA
			// incorrecta resultante es indistinguible de una válida.
			Log::warn(
				'eda',
				sprintf( 'release_date inválida "%s" en producto #%d — fallback a hoy para la ETA.', $raw, $product->get_id() )
			);
			return $now_local;
		}

		if ( $release_ts < $now_local ) {
			return $now_local;
		}
		return $release_ts;
	}

	/**
	 * Suma días de CALENDARIO respetando los cambios de hora.
	 *
	 * `$ts + N * DAY_IN_SECONDS` parece equivalente y no lo es: en la zona de la
	 * tienda hay días de 23 y de 25 horas dos veces al año, así que la suma de
	 * segundos desplaza la fecha impresa. Se ancla a medianoche local para que el
	 * resultado sea siempre el mismo día natural.
	 *
	 * @param int           $desde Marca de tiempo de partida.
	 * @param int           $dias  Días corridos a sumar.
	 * @param \DateTimeZone $tz    Zona de la tienda.
	 */
	private static function sumar_dias_corridos( int $desde, int $dias, \DateTimeZone $tz ): int {
		return ( new \DateTimeImmutable( '@' . $desde ) )
			->setTimezone( $tz )
			->setTime( 0, 0, 0 )
			->modify( '+' . $dias . ' days' )
			->getTimestamp();
	}

	/**
	 * Suma N días al timestamp base usando wall-clock CL. Si business_only=true,
	 * salta sáb/dom (también evaluado en CL).
	 *
	 * `DateTimeImmutable::modify('+1 day')` es tz-aware: avanza el día calendario
	 * en la tz del objeto, no es un offset fijo de 86400s — esto preserva el
	 * comportamiento correcto incluso en transiciones DST.
	 *
	 * @param int  $base_ts       Timestamp base.
	 * @param int  $days          Días a sumar (>=0).
	 * @param bool $business_only Saltar fines de semana.
	 * @return int Nuevo timestamp.
	 */
	private function add_days( int $base_ts, int $days, bool $business_only ): int {
		$dt    = ( new \DateTimeImmutable( '@' . $base_ts ) )->setTimezone( $this->tz );
		$added = 0;
		while ( $added < $days ) {
			$dt = $dt->modify( '+1 day' );
			if ( ! $business_only || ! $this->is_weekend_dt( $dt ) ) {
				++$added;
			}
		}
		return $dt->getTimestamp();
	}

	/**
	 * True si el datetime cae en sábado o domingo (en su propia tz).
	 *
	 * @param \DateTimeImmutable $dt Datetime con tz ya seteado.
	 * @return bool
	 */
	private function is_weekend_dt( \DateTimeImmutable $dt ): bool {
		return (int) $dt->format( 'N' ) >= 6;
	}

	/**
	 * Floor del timestamp a medianoche en la tz del calculator.
	 *
	 * @param int $ts Timestamp arbitrario.
	 * @return int Timestamp a las 00:00:00 del mismo día calendario en $this->tz.
	 */
	private function floor_to_local_midnight( int $ts ): int {
		return ( new \DateTimeImmutable( '@' . $ts ) )
			->setTimezone( $this->tz )
			->setTime( 0, 0, 0 )
			->getTimestamp();
	}
}
