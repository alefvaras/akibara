<?php
/**
 * TaxonomyDiscount Settings — read/write de opciones globales + term-meta.
 *
 * Storage:
 *   - wp_options:
 *     - `akibara_taxonomy_discount_enabled`         (yes/no)
 *     - `akibara_taxonomy_discount_from`            (Y-m-d o vacío)
 *     - `akibara_taxonomy_discount_to`              (Y-m-d o vacío)
 *     - `akibara_taxonomy_discount_apply_to`        (instock_only|instock_and_onbackorder|all)
 *     - `akibara_taxonomy_discount_sale_policy`     (greatest_wins|skip_if_on_sale|from_regular|stack_on_sale)
 *     - `akibara_taxonomy_discount_stacks_coupons`  (yes/no)
 *   - term_meta (cualquier término de cualquier taxonomía soportada):
 *     - `_akb_taxonomy_discount_pct`                (int 0..MAX_PCT; default 15, filtrable)
 *
 * Taxonomías soportadas por default: product_brand, product_cat, product_tag,
 * todos los atributos WC (`pa_*`). Extender via filter `akibara_taxonomy_discount_taxonomies`.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\DiscountCeiling;
use Akibara\Business\Utilities\OptionsFromDatabase;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.16.1
 */
final class Settings {

	public const OPTION_ENABLED        = 'akibara_taxonomy_discount_enabled';

	/**
	 * Con la fila ausente la campaña está APAGADA. Es constante porque el
	 * Centro de Mando leía la misma option con el defecto contrario y anunciaba
	 * «módulos: todos encendidos» con los descuentos apagados ().
	 */
	public const DEFAULT_ENABLED = 'no';
	public const OPTION_FROM           = 'akibara_taxonomy_discount_from';
	public const OPTION_TO             = 'akibara_taxonomy_discount_to';
	public const OPTION_APPLY_TO       = 'akibara_taxonomy_discount_apply_to';
	public const OPTION_SALE_POLICY    = 'akibara_taxonomy_discount_sale_policy';
	public const OPTION_STACKS_COUPONS = 'akibara_taxonomy_discount_stacks_coupons';

	/**
	 * El conjunto CERRADO de options del módulo — quién quiera escuchar sus
	 * guardados engancha `updated_option_{option}` sobre esta lista en vez del
	 * hook global.
	 *
	 * @var array<int, string>
	 */
	public const OPTIONS = array(
		self::OPTION_ENABLED,
		self::OPTION_FROM,
		self::OPTION_TO,
		self::OPTION_APPLY_TO,
		self::OPTION_SALE_POLICY,
		self::OPTION_STACKS_COUPONS,
	);

	public const TERM_META_PCT = '_akb_taxonomy_discount_pct';

	/**
	 * Tope de descuento editorial (%) por DEFECTO. La paridad con el cap de BYP
	 * dejó de ser una intención escrita en un comentario: desde 1.110.1 ambos
	 * motores leen el MISMO número, que el dueño edita en Akibara → Descuentos
	 * ({@see DiscountCeiling}). Esta constante sobrevive como el literal de
	 * siempre — el fallback cuando la option no existe — y como el default que
	 * `TaxonomyDiscount` entrega al filtro `akibara_taxonomy_discount_max_pct`.
	 *
	 * Un % editorial se aplica best-wins sobre el precio regular y, sumado al
	 * envío gratis, puede vender BAJO COSTO; por eso hay techo de margen y no un
	 * clamp a 90 a secas. El filtro sigue siendo el override programático.
	 */
	public const MAX_PCT = DiscountCeiling::DEFAULT_PCT;

	public const APPLY_INSTOCK_ONLY             = 'instock_only';
	public const APPLY_INSTOCK_AND_ONBACKORDER  = 'instock_and_onbackorder';
	public const APPLY_ALL                      = 'all';

	public const POLICY_GREATEST_WINS   = 'greatest_wins';
	public const POLICY_SKIP_IF_ON_SALE = 'skip_if_on_sale';
	public const POLICY_FROM_REGULAR    = 'from_regular';
	public const POLICY_STACK_ON_SALE   = 'stack_on_sale';

	private ?array $supported_taxonomies_cache = null;
	private array $term_pct_cache = array();

	/**
	 * Estado de autoridad COMPARTIDO con el resolver y el proveedor estricto.
	 * Si cualquiera pierde la certeza, la pierden todos.
	 */
	private PriceAuthority $authority;

	/** Lector crudo y observable de las fronteras que deciden precio. */
	private AuthoritativeReader $reader;

	/**
	 * Modo estricto: los getters dejan de usar las APIs cacheadas de WordPress
	 * y leen de la foto cruda. Lo activa el camino de Search, nunca el
	 * storefront.
	 */
	private bool $strict = false;

	/**
	 * Foto adoptada de Search. Cuando existe, TODA la configuración sale de acá:
	 * cero `get_option()`, cero hooks de config y cero SQL propio. Sólo las
	 * relaciones objeto↔término siguen siendo product-local.
	 */
	private ?AdoptedSnapshot $adopted = null;

	/**
	 * @param PriceAuthority|null $authority Estado compartido; se crea uno si no se inyecta.
	 */
	public function __construct( ?PriceAuthority $authority = null ) {
		$this->authority = $authority ?? new PriceAuthority();
		$this->reader    = new AuthoritativeReader( $this->authority );
	}

	public function authority(): PriceAuthority {
		return $this->authority;
	}

	public function reader(): AuthoritativeReader {
		return $this->reader;
	}

	/**
	 * Entra en modo estricto: a partir de acá, todo lo que decide el precio se
	 * lee en crudo y cualquier fallo envenena la autoridad.
	 */
	public function enter_strict_mode(): void {
		// IDEMPOTENTE: sólo la transición tolerante → estricto hace trabajo. Si
		// cada producto reentrara, se reaplicarían los hooks y dos productos de
		// la misma foto podrían resolverse con configuraciones distintas sin que
		// el token cambie. Para iniciar una captura NUEVA está
		// `invalidate_derived_memos()`.
		if ( $this->strict ) {
			return;
		}

		$this->strict = true;

		// El cache tolerante pudo llenarse con valores de APIs cacheadas; se
		// descarta para que el camino estricto no lo herede.
		$this->term_pct_cache             = array();
		$this->supported_taxonomies_cache = null;

		// La foto se valida ENTERA acá, no cuando cada rama consulta su opción:
		// una opción de precio fuera de dominio invalida el resultado aunque la
		// rama de este producto no llegue a leerla. Se memoizan de una vez el
		// tope, las taxonomías efectivas, los decimales y la zona horaria: esos
		// valores exactos gobiernan TODOS los productos del snapshot.
		$this->reader->validate_price_options();
		$this->reader->max_pct();
		$this->reader->effective_taxonomy_map();
	}

	public function in_strict_mode(): bool {
		return $this->strict;
	}

	/**
	 * Adopta la foto de Search y entra en modo estricto con ella.
	 *
	 * @param AdoptedSnapshot $snapshot Foto validada.
	 * @return void
	 */
	public function adopt( AdoptedSnapshot $snapshot ): void {
		$this->adopted                    = $snapshot;
		$this->strict                     = true;
		$this->term_pct_cache             = array();
		$this->supported_taxonomies_cache = null;
	}

	/** ¿Esta instancia está ligada a esta foto exacta? */
	public function is_bound_to( AdoptedSnapshot $snapshot ): bool {
		return $this->adopted === $snapshot;
	}

	/** Foto adoptada, o null. */
	public function adopted_snapshot(): ?AdoptedSnapshot {
		return $this->adopted;
	}

	/**
	 * Descarta los memos de esta instancia y la foto cruda ya leída, de modo que
	 * la siguiente lectura estricta vuelva a la base. Parte de la API pública que
	 * consume Search antes de capturar una configuración nueva.
	 *
	 * @return void
	 */
	public function invalidate_derived_memos(): void {
		$this->term_pct_cache             = array();
		$this->supported_taxonomies_cache = null;
		$this->reader                     = new AuthoritativeReader( $this->authority );
		// Se vuelve a tolerante para que la próxima entrada estricta sea una
		// transición real y vuelva a tomar la foto completa. La foto ADOPTADA
		// también se suelta: dejarla con `strict = false` produciría un estado
		// híbrido donde una ruta legacy seguiría cobrando de `A`.
		$this->strict                     = false;
		$this->adopted                    = null;
	}

	/**
	 * Master switch + vigencia activa.
	 */
	public function is_enabled(): bool {
		if ( null !== $this->adopted ) {
			// Todo sale de la foto: el estado del switch y la vigencia ya vienen
			// resueltos y firmados por Search.
			return 'yes' === $this->adopted->option( self::OPTION_ENABLED, self::DEFAULT_ENABLED )
				&& $this->adopted->window_active();
		}

		$enabled = $this->strict
			? $this->reader->enumerated_option( self::OPTION_ENABLED, array( 'yes', 'no' ), self::DEFAULT_ENABLED )
			: (string) get_option( self::OPTION_ENABLED, self::DEFAULT_ENABLED );

		if ( 'yes' !== $enabled ) {
			return false;
		}
		return $this->is_within_window();
	}

	private function is_within_window(): bool {
		if ( $this->strict ) {
			$window = $this->reader->options( array( self::OPTION_FROM, self::OPTION_TO ) );
			$from   = (string) ( $window[ self::OPTION_FROM ] ?? '' );
			$to     = (string) ( $window[ self::OPTION_TO ] ?? '' );
		} else {
			$from = (string) get_option( self::OPTION_FROM, '' );
			$to   = (string) get_option( self::OPTION_TO, '' );
		}

		// Sin fechas no hace falta saber qué día es, y preguntarlo cuesta: en
		// estricto es una lectura del lector crudo, y en tolerante una llamada a
		// WordPress que en el camino del precio se paga en cada producto.
		if ( '' === $from && '' === $to ) {
			return true;
		}

		// La fecha del sitio decide la vigencia: en estricto se calcula desde la
		// zona horaria leída en crudo, porque un default de timezone puede dar
		// por expirado un descuento vigente sin que nada falle visiblemente.
		$today = $this->strict ? $this->reader->site_today() : current_time( 'Y-m-d' );

		return self::window_covers( $from, $to, $today );
	}

	/**
	 * ¿La ventana `[from, to]` cubre el día `today`? Ambos extremos incluidos.
	 *
	 * Vive aquí, público y estático, porque hay DOS sitios que necesitan la
	 * respuesta: el precio, que la pide con las fechas que trae la foto, y la
	 * tarjeta de estado del panel, que la pide con las fechas que acaba de leer
	 * de la base. Si cada uno se escribiera su versión, podrían separarse y la
	 * pantalla diría «Activo» sobre una promoción que ya no descuenta.
	 *
	 * @param string $from  Fecha de inicio (Y-m-d), o cadena vacía.
	 * @param string $to    Fecha de fin (Y-m-d), o cadena vacía.
	 * @param string $today Día del sitio (Y-m-d). Vacío = no se puede afirmar.
	 */
	public static function window_covers( string $from, string $to, string $today ): bool {
		if ( '' === $from && '' === $to ) {
			return true; // Vigencia indefinida: la gobierna sólo el interruptor.
		}

		if ( '' === $today ) {
			return false; // Sin fecha del sitio no se afirma que algo esté vivo.
		}

		if ( '' !== $from && $today < $from ) {
			return false;
		}
		if ( '' !== $to && $today > $to ) {
			return false;
		}

		return true;
	}

	public function get_from_date(): string {
		return (string) get_option( self::OPTION_FROM, '' );
	}

	public function get_to_date(): string {
		return (string) get_option( self::OPTION_TO, '' );
	}

	/**
	 * Lee desde la base el estado completo que confirma el guardado del panel.
	 *
	 * La caché persistente puede conservar un blob de opciones anterior a la
	 * escritura. La tarjeta no debe mezclar vigencia fresca con política de rebajas,
	 * productos incluidos y cupones procedentes de otra generación cacheada.
	 * OptionsFromDatabase obtiene las seis filas en una sola consulta indexada,
	 * compartida con la tarjeta de SeriesBundles. Esta lectura administrativa no
	 * sustituye el camino del cliente ni cambia su estrategia de autoridad.
	 *
	 * @return array{from: string, to: string, enabled: string, sale_policy: string, apply_to: string, stacks_coupons: bool} Lo que hay escrito.
	 */
	public function window_state_from_database(): array {
		$fallback = array(
			'from'           => $this->get_from_date(),
			'to'             => $this->get_to_date(),
			'enabled'        => (string) get_option( self::OPTION_ENABLED, self::DEFAULT_ENABLED ),
			'sale_policy'    => $this->get_sale_policy(),
			'apply_to'       => $this->get_apply_to(),
			'stacks_coupons' => $this->stacks_with_coupons(),
		);

		$filas = OptionsFromDatabase::read(
			array(
				self::OPTION_FROM,
				self::OPTION_TO,
				self::OPTION_ENABLED,
				self::OPTION_SALE_POLICY,
				self::OPTION_APPLY_TO,
				self::OPTION_STACKS_COUPONS,
			)
		);

		if ( null === $filas ) {
			return $fallback;
		}

		$sale_policy = $filas[ self::OPTION_SALE_POLICY ] ?? self::POLICY_SKIP_IF_ON_SALE;
		$apply_to    = $filas[ self::OPTION_APPLY_TO ] ?? self::APPLY_INSTOCK_ONLY;

		return array(
			'from'    => $filas[ self::OPTION_FROM ] ?? '',
			'to'      => $filas[ self::OPTION_TO ] ?? '',
			'enabled' => $filas[ self::OPTION_ENABLED ] ?? 'no', // Ausente = apagado, igual que el default de lectura.
			// Igual que en los getters tolerantes: un valor fuera del dominio
			// cerrado (fila corrupta, filtro de terceros) no se pinta tal cual.
			'sale_policy'    => in_array( $sale_policy, self::sale_policies(), true ) ? $sale_policy : self::POLICY_SKIP_IF_ON_SALE,
			'apply_to'       => in_array( $apply_to, self::apply_to_values(), true ) ? $apply_to : self::APPLY_INSTOCK_ONLY,
			'stacks_coupons' => 'yes' === ( $filas[ self::OPTION_STACKS_COUPONS ] ?? 'no' ),
		);
	}

	/**
	 * Dominio cerrado de «a qué stock aplica»: lo comparte el lector con esta
	 * lectura cruda, para que ninguno acepte lo que el otro rechaza.
	 *
	 * @return array<int, string>
	 */
	public static function apply_to_values(): array {
		return array(
			self::APPLY_INSTOCK_ONLY,
			self::APPLY_INSTOCK_AND_ONBACKORDER,
			self::APPLY_ALL,
		);
	}

	/**
	 * Dominio cerrado de la política ante rebajas manuales: lo comparten el
	 * lector y el saneador del panel, para que ninguno acepte lo que el otro
	 * rechaza.
	 *
	 * @return array<int, string>
	 */
	public static function sale_policies(): array {
		return array(
			self::POLICY_GREATEST_WINS,
			self::POLICY_SKIP_IF_ON_SALE,
			self::POLICY_FROM_REGULAR,
			self::POLICY_STACK_ON_SALE,
		);
	}

	public function get_sale_policy(): string {
		$allowed = self::sale_policies();
		// Default conservador: respeta el _sale_price manual existente. Si el
		// owner se tomó el trabajo de poner sale manual a un producto, la regla
		// taxonómica no lo pisa silenciosamente. Cambiar a GREATEST_WINS u
		// otra política es decisión consciente desde el panel.
		if ( null !== $this->adopted ) {
			$value = $this->adopted->option( self::OPTION_SALE_POLICY, self::POLICY_SKIP_IF_ON_SALE );
			return in_array( $value, $allowed, true ) ? $value : self::POLICY_SKIP_IF_ON_SALE;
		}

		if ( $this->strict ) {
			return $this->reader->enumerated_option( self::OPTION_SALE_POLICY, $allowed, self::POLICY_SKIP_IF_ON_SALE );
		}

		$value = (string) get_option( self::OPTION_SALE_POLICY, self::POLICY_SKIP_IF_ON_SALE );
		return in_array( $value, $allowed, true ) ? $value : self::POLICY_SKIP_IF_ON_SALE;
	}

	public function get_apply_to(): string {
		$allowed = self::apply_to_values();
		if ( null !== $this->adopted ) {
			$value = $this->adopted->option( self::OPTION_APPLY_TO, self::APPLY_INSTOCK_ONLY );
			return in_array( $value, $allowed, true ) ? $value : self::APPLY_INSTOCK_ONLY;
		}

		if ( $this->strict ) {
			return $this->reader->enumerated_option( self::OPTION_APPLY_TO, $allowed, self::APPLY_INSTOCK_ONLY );
		}

		$value = (string) get_option( self::OPTION_APPLY_TO, self::APPLY_INSTOCK_ONLY );
		return in_array( $value, $allowed, true ) ? $value : self::APPLY_INSTOCK_ONLY;
	}

	public function stacks_with_coupons(): bool {
		return 'yes' === get_option( self::OPTION_STACKS_COUPONS, 'no' );
	}

	/**
	 * Mapa de taxonomías soportadas: `taxonomy_slug => label`.
	 *
	 * Default core: product_brand + product_cat + product_tag.
	 * Auto-discovery de atributos WC (`pa_*`).
	 * Extensible via filter `akibara_taxonomy_discount_taxonomies`.
	 *
	 * @return array<string, string>
	 */
	public function get_supported_taxonomies(): array {
		if ( null !== $this->supported_taxonomies_cache ) {
			return $this->supported_taxonomies_cache;
		}

		if ( null !== $this->adopted ) {
			// Slugs efectivos ya firmados por Search: sin hooks ni consultas.
			$map = array();
			foreach ( $this->adopted->taxonomies() as $slug ) {
				$map[ $slug ] = $slug;
			}
			$this->supported_taxonomies_cache = $map;
			return $this->supported_taxonomies_cache;
		}

		if ( $this->strict ) {
			// Mapa EFECTIVO ya filtrado y validado, idéntico al que compara
			// Search. Se memoiza para que gobierne todos los productos.
			$this->supported_taxonomies_cache = $this->reader->effective_taxonomy_map();
			return $this->supported_taxonomies_cache;
		}

		$taxonomies = array();

		$core = array(
			'product_brand' => 'Editoriales',
			'product_cat'   => 'Categorías',
			'product_tag'   => 'Etiquetas',
		);
		foreach ( $core as $slug => $label ) {
			if ( taxonomy_exists( $slug ) ) {
				$taxonomies[ $slug ] = $label;
			}
		}

		// Auto-discovery atributos WC (pa_*). En estricto se leen de su tabla:
		// `wc_get_attribute_taxonomies()` transienta su resultado y un vacío
		// nacido de un fallo SQL borraría una taxonomía entera del barrido
		//.
		if ( $this->strict ) {
			// Label REAL y orden por attribute_id: el mapa tiene que ser idéntico
			// al que Search entrega al filtro, o un callback que decida por label
			// vería configuraciones distintas en cada lado.
			foreach ( $this->reader->attribute_taxonomy_map() as $tax_name => $tax_label ) {
				if ( taxonomy_exists( $tax_name ) ) {
					$taxonomies[ $tax_name ] = $tax_label;
				}
			}
		} elseif ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attr ) {
				$tax_name = 'pa_' . $attr->attribute_name;
				if ( taxonomy_exists( $tax_name ) ) {
					$taxonomies[ $tax_name ] = ! empty( $attr->attribute_label ) ? (string) $attr->attribute_label : $tax_name;
				}
			}
		}

		/**
		 * Permite agregar/quitar taxonomías soportadas sin modificar código.
		 *
		 * @param array<string, string> $taxonomies Mapa slug → label.
		 */
		$filtered = apply_filters( 'akibara_taxonomy_discount_taxonomies', $taxonomies );

		if ( ! is_array( $filtered ) ) {
			// Tolerante: se ignora el filtro roto. Estricto: no se puede saber
			// qué taxonomías debían barrerse, así que no hay precio autoritativo
			// posible.
			if ( $this->strict ) {
				$this->authority->fail( 'El filtro akibara_taxonomy_discount_taxonomies devolvió una forma inválida.' );
			}
			$this->supported_taxonomies_cache = $taxonomies;
			return $this->supported_taxonomies_cache;
		}

		$this->supported_taxonomies_cache = $filtered;
		return $this->supported_taxonomies_cache;
	}

	/**
	 * Atajo: lista plana de slugs de taxonomías soportadas (para queries).
	 *
	 * @return string[]
	 */
	public function get_supported_taxonomy_slugs(): array {
		return array_keys( $this->get_supported_taxonomies() );
	}

	/**
	 * Tope efectivo del % editorial. {@see self::MAX_PCT}; filtrable hacia arriba
	 * vía `akibara_taxonomy_discount_max_pct` para campañas puntuales, con techo
	 * absoluto duro de 90 y piso de 1 (un filtro mal seteado no puede romper el
	 * límite ni anular el descuento por completo).
	 */
	public function max_pct(): int {
		if ( null !== $this->adopted ) {
			return $this->adopted->max_pct();
		}

		if ( $this->strict ) {
			return $this->reader->max_pct();
		}

		// La option configurada entra como DEFAULT del filtro (jamás provider):
		// los lectores autoritativos resuelven la suya desde bytes raw.
		$max = (int) apply_filters( 'akibara_taxonomy_discount_max_pct', \Akibara\Business\Utilities\DiscountCeiling::pct() );
		return max( 1, min( 90, $max ) );
	}

	/**
	 * Lee el % de descuento de un término. Retorna 0 si no aplica.
	 */
	public function get_term_pct( int $term_id ): int {
		if ( $term_id <= 0 ) {
			return 0;
		}
		if ( isset( $this->term_pct_cache[ $term_id ] ) ) {
			return $this->term_pct_cache[ $term_id ];
		}

		if ( null !== $this->adopted ) {
			// El term-meta viaja DENTRO de la foto: Business no vuelve a la base
			// por él.
			return $this->adopted->term_pct( $term_id );
		}

		if ( $this->strict ) {
			// Sin memo: el object cache de term-meta pudo quedar contaminado con
			// un vacío indistinguible de "sin descuento" [contraejemplo 5].
			return $this->reader->term_pct( $term_id, self::TERM_META_PCT, $this->max_pct() );
		}

		global $wpdb;
		$has_wpdb = isset( $wpdb ) && is_object( $wpdb );

		// `$wpdb->last_error` es GLOBAL y volátil: la siguiente consulta exitosa
		// lo borra. Por eso se limpia justo antes y se lee justo después — la
		// autoridad se decide en el instante de ESTA lectura, no al final del
		// request, cuando el rastro ya no existe.
		if ( $has_wpdb ) {
			$wpdb->last_error = '';
		}

		try {
			$raw = get_term_meta( $term_id, self::TERM_META_PCT, true );
		} catch ( \Throwable $e ) {
			$this->note_authority_failure(
				sprintf( 'get_term_meta(%d) lanzó %s: %s', $term_id, get_class( $e ), $e->getMessage() )
			);
			return 0;
		}

		if ( $has_wpdb && '' !== (string) $wpdb->last_error ) {
			$this->note_authority_failure(
				sprintf( 'get_term_meta(%d) falló en SQL: %s', $term_id, (string) $wpdb->last_error )
			);
			return 0; // sin memoizar: un 0 de un fallo mentiría el resto del request.
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $raw ) ) {
			$this->note_authority_failure( sprintf( 'get_term_meta(%d) devolvió WP_Error', $term_id ) );
			return 0;
		}

		// Forma real de `get_term_meta( …, true)`: escalar, o '' si no hay meta.
		// Cualquier otra cosa (p. ej. un filtro de terceros devolviendo array) es
		// una forma inválida y NO puede interpretarse como "sin descuento".
		if ( '' !== $raw && ! is_scalar( $raw ) ) {
			$this->note_authority_failure(
				sprintf( 'get_term_meta(%d) devolvió una forma inválida (%s)', $term_id, gettype( $raw ) )
			);
			return 0;
		}
		if ( '' !== $raw && ! is_numeric( $raw ) ) {
			$this->note_authority_failure(
				sprintf( 'get_term_meta(%d) devolvió un valor no numérico', $term_id )
			);
			return 0;
		}

		$pct = is_numeric( $raw ) ? (int) $raw : 0;
		$pct = max( 0, min( $this->max_pct(), $pct ) );
		$this->term_pct_cache[ $term_id ] = $pct;
		return $pct;
	}

	/**
	 * ¿Todas las lecturas sensibles de esta instancia fueron concluyentes?
	 *
	 * Estado REQUEST-LOCAL y monótono: una sola lectura fallida envenena la
	 * instancia hasta el final de la petición. La recuperación es una instancia
	 * nueva (petición nueva), no un reintento dentro de la misma.
	 */
	public function authority_available(): bool {
		return $this->authority->available();
	}

	/** Motivo del primer fallo de autoridad, o cadena vacía. */
	public function authority_error(): string {
		return $this->authority->error();
	}

	/**
	 * Marca la instancia como no autoritativa. Conserva el PRIMER motivo: es el
	 * que explica la causa raíz; los posteriores suelen ser consecuencia.
	 *
	 * @param string $why Motivo.
	 * @return void
	 */
	private function note_authority_failure( string $why ): void {
		$this->authority->fail( $why );
	}

	/**
	 * Persiste el % de un término. Clamp 0..max_pct() (default 15).
	 */
	public function set_term_pct( int $term_id, int $pct ): void {
		if ( $term_id <= 0 ) {
			return;
		}
		$pct = max( 0, min( $this->max_pct(), $pct ) );
		$this->term_pct_cache[ $term_id ] = $pct;

		if ( 0 === $pct ) {
			delete_term_meta( $term_id, self::TERM_META_PCT );
			return;
		}
		update_term_meta( $term_id, self::TERM_META_PCT, $pct );
	}

	/**
	 * Verifica si un producto tiene stock_status elegible según `apply_to`.
	 */
	public function is_stock_eligible( \WC_Product $product ): bool {
		$apply = $this->get_apply_to();

		if ( self::APPLY_ALL === $apply ) {
			return true;
		}

		/*
		 * Se decide por el estado COMERCIAL, no por `_stock_status`.
		 *
		 * Ahí «a pedido» y «preventa» son el mismo valor (`onbackorder`), y este
		 * era el ÚNICO sitio de la tienda que no los distinguía: el badge, la
		 * faceta, la ruta, la copia y el CTA sí lo hacen. Y es donde más importa,
		 * porque **una preventa es dinero cobrado por un tomo que todavía no
		 * existe**: descontarlo es regalar margen sobre una venta que aún no se
		 * ha servido.
		 *
		 * Lo peor no era el alcance, era el retardo: con cero preventas en
		 * catálogo esto no hacía nada, y el día que alguien pusiera la primera
		 * fecha de lanzamiento ese tomo habría entrado al descuento SOLO, con la
		 * configuración intacta y sin un aviso. Nadie relaciona «puse una fecha»
		 con «se está descontando una preventa».
		 *
		 * DECISIÓN DEL DUEÑO (): las preventas quedan FUERA de
		 * «Disponibles + a pedido». Cuando su fecha pase, `Availability::of()`
		 * las reclasifica sola como «a pedido» —el tomo ya salió— y entran al
		 * descuento como cualquier otro, que es lo que él pidió: «si pasa la
		 * fecha debería llegar a pedido».
		 *
		 * Queda pendiente la cuarta opción explícita para INCLUIR preventas a
		 * propósito. No entra aquí porque estrena un valor de
		 * `akibara_taxonomy_discount_apply_to`, y ese dominio está replicado en
		 * los validadores de business Y de search: moverlo por un lado rechaza la
		 * foto entera y apaga el buscador.
		 */
		$estado = Availability::of( $product );

		if ( self::APPLY_INSTOCK_ONLY === $apply ) {
			return Availability::DISPONIBLE === $estado;
		}

		return Availability::DISPONIBLE === $estado || Availability::A_PEDIDO === $estado;
	}
}
