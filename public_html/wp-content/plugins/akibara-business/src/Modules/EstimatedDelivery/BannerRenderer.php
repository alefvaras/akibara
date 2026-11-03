<?php
/**
 * Banner front-end de fecha estimada de llegada.
 *
 * Contextos:
 *   1) Single product page (después de price/short-description, prio 25).
 *   2) Cards de producto en loops/archives.
 *   3) Single product page de un tomo EN STOCK: una línea con el plazo de
 *      despacho bajo el botón (`render_despacho()`, UC-2).
 *
 * La agregación de carrito se conserva como lógica pura para el contrato
 * estructurado que consume el adaptador first-party de WooCommerce Blocks:
 *   - MAX(min) a MAX(max) de los productos en preventa. Items instock se ignoran.
 *   - Si hay ≥1 producto TBA → resultado TBA ("Algunos productos sin fecha").
 *   - Si ningún producto está en preventa → no se muestra banner.
 *
 * Formato de fechas: meses ES abreviados (ene/feb/...); año suprimido si coincide
 * con el año actual, mostrado explícito cuando difiere (caso Berserk 2026+).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

use Akibara\Business\Modules\OrderPreventa\Settings;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Business\Utilities\StoreTime;
use Akibara\Business\Utilities\Text;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Banner ETA — render front-end.
 *
 * @since 1.7.0
 */
final class BannerRenderer {

	/**
	 * La línea de llegada de la barra de compra de la ficha (UM-7). Se edita en
	 * Akibara → Textos; `{fecha}` se reemplaza por el rango («7 – 13 nov»).
	 */
	public const OPTION_BARRA = 'akb_copy_eta_bar';

	public const DEFAULT_BARRA = 'Llega {fecha}';

	public const MARCADOR_FECHA = '{fecha}';

	/**
	 * Meses ES abreviados (index 1-12 para evitar offset).
	 *
	 * @var array<int, string>
	 */
	private const MONTHS_ES = array(
		1  => 'ene',
		2  => 'feb',
		3  => 'mar',
		4  => 'abr',
		5  => 'may',
		6  => 'jun',
		7  => 'jul',
		8  => 'ago',
		9  => 'sep',
		10 => 'oct',
		11 => 'nov',
		12 => 'dic',
	);

	private DateCalculator $calc;

	/**
	 * Política de tanda del módulo.
	 *
	 * UNA instancia para todo el request y no un `new PedidoPolicy()` por
	 * llamada: la clase memoriza la resolución por producto, y en un archive de
	 * 24 tarjetas la píldora pregunta por la política de cada una. Instanciarla
	 * dentro del método tiraba ese memo en cada card.
	 */
	private PedidoPolicy $policy;

	/**
	 * @param DateCalculator    $calc   Calculator compartido del módulo.
	 * @param PedidoPolicy|null $policy Política de tanda (inyectable en pruebas).
	 */
	public function __construct( DateCalculator $calc, ?PedidoPolicy $policy = null ) {
		$this->calc   = $calc;
		$this->policy = $policy ?? new PedidoPolicy();
	}

	/**
	 * Hooks entry point. Llamado desde EstimatedDelivery::register().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_single' ), 25 );

		// Promesas de preventa: PRIORIDAD 31, es decir DESPUÉS del boton de compra
		// (woocommerce_template_single_add_to_cart va en 30). No es cosmetico. El
		// banner de fecha va en 25 porque la fecha informa la decision y tiene que
		// verse antes de decidir; estas cuatro lineas responden «¿le doy o no?», y
		// colgarlas de 25 empujaba el boton fuera de pantalla en movil.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_promesas' ), 31 );
		// El tomo EN STOCK dice su plazo de despacho junto al botón. Mismo
		// principio que las promesas: después del formulario, nunca en 25, para no
		// bajar el botón. En `woocommerce_after_add_to_cart_form` a prioridad 5
		// para posicionarse pegado al botón antes de los sellos de confianza (prio 15).
		//
		// La visibilidad sobre el pliegue requiere que el extracto del producto no empuje
		// el formulario: desplegar con el extracto reubicado bajo el botón, o el tema primero.
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'render_despacho' ), 5 );
		// Pildora compacta en las cards del loop/archive (preventas, home, /tienda/).
		//
		// Prioridad 12 para ejecutarse tras el hint de disponibilidad (prio 11).
		// LoopEnhancer::render_availability_hint_loop reside en 11; este orden determinístico
		// permite que las reglas CSS del tema colapsen adecuadamente el placeholder cuando
		// coexiste la píldora de ETA sin desalinear la grilla.
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_loop_item' ), 12 );
		// La fecha, en una línea, para la barra de compra fija de la ficha: la
		// renderiza el tema (template-parts/woocommerce/sticky-atc.php), delegando el
		// cálculo de fechas a este módulo.
		add_filter( 'akb_eta_linea_corta', array( $this, 'linea_corta' ), 10, 2 );
	}

	/**
	 * La llegada estimada en una línea corta, para la barra de compra de la ficha.
	 *
	 * Comunica el rango de entrega en la barra flotante que acompaña al usuario durante
	 * el scroll en productos bajo reserva o preventa.
	 *
	 * Sólo con rango de fechas («Llega 7 – 13 nov»): la rama sin fecha es una
	 * frase entera («Te avisamos en cuanto tengamos fecha», «Llega ~4 semanas
	 * tras su publicación») que no cabe en la barra y que el cartel ya dice. Un
	 * tomo en stock no tiene ETA: nada.
	 *
	 * El texto es del panel (`akb_copy_eta_bar`, «Llega {fecha}»): el marcador se
	 * reemplaza con `str_replace`, nunca `sprintf`; sin marcador, la fecha va al
	 * final.
	 *
	 * @param mixed $linea   Lo que traiga el filtro (vacío por defecto).
	 * @param mixed $product Producto de la ficha.
	 * @return string Texto plano; quien lo imprime lo escapa.
	 */
	public function linea_corta( $linea, $product = null ): string {
		if ( ! $product instanceof WC_Product ) {
			return is_string( $linea ) ? $linea : '';
		}

		$eta = $this->calc->calculate( $product );
		if ( null === $eta || $eta->is_tba() ) {
			return '';
		}

		$fecha     = $this->format_inline( $eta );
		$plantilla = Text::one_line_option( self::OPTION_BARRA, self::DEFAULT_BARRA );

		return false !== strpos( $plantilla, self::MARCADOR_FECHA )
			? str_replace( self::MARCADOR_FECHA, $fecha, $plantilla )
			: trim( $plantilla . ' ' . $fecha );
	}

	/**
	 * Enqueue del CSS sólo en páginas relevantes.
	 *
	 * El predicado replica `$has_loop` del theme (inc/enqueue.php): donde el theme
	 * carga loop.css hay cards, y donde hay cards puede haber una preventa que emita
	 * esta píldora. Cuando este guard era más estrecho que el del theme, el `<p
	 * class="akb-eta-loop">` salía SIN su hoja en carrito, /mi-cuenta/favoritos/ y 404
	 * —párrafo crudo con márgenes por defecto— y encima loop.css sí colapsaba el
	 * placeholder, porque ese colapso vive en el theme. Mantener ambos predicados
	 * alineados es el contrato.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! is_product()
			&& ! ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) )
			&& ! is_cart()
			&& ! is_account_page()
			&& ! is_404()
			&& ! is_front_page() ) {
			return; // El loop muestra la pildora ETA en archives/home con cards de preventa.
		}

		wp_enqueue_style(
			'akb-estimated-delivery',
			AKIBARA_BUSINESS_URL . 'assets/css/estimated-delivery.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/estimated-delivery.css' )
		);
	}

	/**
	 * Single product page banner.
	 *
	 * @return void
	 */
	public function render_single(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$eta = $this->calc->calculate( $product );
		if ( null === $eta ) {
			return;
		}
		// Se arma en una variable y se emite en UNA línea: `phpcs:ignore` sólo
		// cubre la línea siguiente, así que un `echo` de varias líneas deja la
		// mitad del argumento fuera de la exención y el sniff de escaping se
		// dispara sobre HTML que sí está escapado.
		$html = $this->render_banner_html(
			$eta,
			'single',
			$this->nota_de_tanda( $product, $eta ),
			$product,
			$this->urgencia_de_tanda( $product, $eta )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML construido con datos escapados internamente.
		echo $html;
	}

	/**
	 * Seleccion de promesas segun haya fecha o no. Puro y estatico a proposito:
	 * es lo unico que hay que fijar con una prueba, y montarlo exigiria el
	 * DateCalculator entero.
	 *
	 * La rama TBA NO promete el recordatorio de una semana antes. Sin fecha no
	 * hay T-7 que contar, y prometerlo seria exactamente la clase de promesa
	 * aspiracional que este bloque existe para no hacer.
	 *
	 * La cancelacion NO es libre, y esto es una regla de negocio, no una
	 * limitacion tecnica: cuando entra una preventa Akibara encarga el tomo a
	 * Espana o Argentina y paga por adelantado. Ese dinero ya esta gastado antes
	 * de que el tomo llegue, asi que «cancela cuando quieras» convertiria el
	 * cobro anticipado —que es lo que financia la compra al proveedor— en un
	 * deposito a la vista.
	 *
	 * Lo que sí se garantiza es la cobertura ante atrasos: pasados Settings::REFUND_AFTER_DAYS
	 * de la fecha estimada, el cliente puede solicitar la cancelación y devolución íntegra.
	 * Se lee directamente de la constante `Settings::REFUND_AFTER_DAYS` compartida con
	 * `ReadyToShipCron::maybe_mark_refund_eligible()` para garantizar coherencia en todo el sistema.
	 *
	 * La rama TBA no incluye el plazo en días por no existir fecha inicial, garantizando
	 * en su lugar el reembolso total si la editorial confirma el título agotado.
	 *
	 * @param bool $es_tba       Si el ETA no tiene fecha todavía.
	 * @param bool $aviso_previo Si el aviso de una semana antes está activado en configuración.
	 * @return string[]
	 */
	public static function promesas_para( bool $es_tba, bool $aviso_previo = true ): array {
		if ( $es_tba ) {
			return array(
				'Apenas llegue a bodega, lo despachamos.',
				'Te escribimos en cuanto tengamos fecha.',
				'El precio que pagas hoy no cambia.',
				'Si la editorial confirma que se agotó, te devolvemos el 100%.',
			);
		}

		return array(
			'Si llega antes de esa fecha, lo despachamos de inmediato.',
			'Si la fecha se mueve, te escribimos.',
			$aviso_previo ? 'Te avisamos una semana antes y cuando llegue a bodega.' : 'Te avisamos cuando llegue a bodega.',
			'El precio que pagas hoy no cambia.',
			sprintf( 'Si se atrasa más de %d días desde esa fecha, cancelas y te devolvemos el 100%%.', Settings::REFUND_AFTER_DAYS ),
		);
	}

	/**
	 * Las promesas que Akibara YA cumple y no decia en ninguna parte.
	 *
	 * Ninguna es aspiracional; cada una esta respaldada por codigo vivo y se
	 * verifico en produccion antes de escribirla:
	 *
	 * - «llega antes → sale antes»: StockChangeListener::on_stock_status_change
	 *   engancha woocommerce_product_set_stock_status y agenda la accion en cuanto
	 *   cambia el stock. No espera al cron diario.
	 * - «si la fecha se mueve, te escribimos»: ReleaseDateChangeListener recalcula
	 *   ORDER_SUMMARY_MAX y notifica ante postergacion, adelanto y anuncio.
	 * - «te avisamos una semana antes»: RemindersCron (OPT_T7_ENABLED, default
	 *   'yes'). Solo se promete cuando hay fecha —sin fecha no hay T-7 que
	 *   contar— y con el interruptor encendido.
	 * - «el precio no cambia»: el cobro es 100% al comprar.
	 *
	 * Si alguna deja de ser cierta hay que BORRARLA de aqui, no matizarla.
	 *
	 * @return void
	 */
	public function render_promesas(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		// Mismo guard que el banner: calculate() devuelve null en stock, asi que
		// esto solo sale en preventa/backorder.
		$eta = $this->calc->calculate( $product );
		if ( null === $eta ) {
			return;
		}

		$items = '';
		foreach ( self::promesas_para( $eta->is_tba(), Settings::get_bool( Settings::OPT_T7_ENABLED ) ) as $promesa ) {
			$items .= '<li>' . esc_html( $promesa ) . '</li>';
		}

		// El CONTENIDO de las promesas es el mismo para los dos estados (son las
		// garantías que ya se publicaban para estos mismos productos); lo que
		// cambia es el NOMBRE del negocio: «preventa» solo si el tomo aún no sale.
		$titulo = Availability::is_preventa( $product )
			? 'Cómo funciona esta preventa'
			: 'Cómo funciona este pedido';

		printf(
			'<div class="akb-promesas"><p class="akb-promesas__titulo">%s</p><ul class="akb-promesas__lista">%s</ul></div>',
			esc_html( $titulo ),
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cada item se escapa arriba.
		);
	}

	/**
	 * Plazo de despacho de un tomo EN STOCK, una línea bajo el botón.
	 *
	 * Comunica el tiempo de despacho estimado ("Despacha en 24–48 h") inmediatamente
	 * junto al botón de compra en productos disponibles, reforzando la decisión de compra.
	 *
	 * El texto se gestiona centralizadamente mediante `akb_copy_promise_dispatch`
	 * (Akibara → Textos → Promesas de la tienda), aplicando fallback por defecto si está vacío.
	 * Aplica exclusivamente a productos disponibles y comprables.
	 *
	 * @return void
	 */
	public function render_despacho(): void {
		global $product;
		if ( ! $product instanceof WC_Product
			|| Availability::DISPONIBLE !== Availability::of( $product )
			|| ! $product->is_purchasable() ) {
			return;
		}

		$html = sprintf(
			'<p class="akb-despacho"><span class="akb-despacho__icon" aria-hidden="true">%1$s</span><span class="akb-despacho__texto">%2$s</span></p>',
			self::truck_svg(),
			esc_html( Text::one_line_option( 'akb_copy_promise_dispatch', 'Despacha en 24–48 h' ) )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- el texto se escapa arriba y el SVG es un literal propio.
		echo $html;
	}

	/**
	 * Card del loop/archive — pildora compacta de ETA debajo del titulo.
	 *
	 * Hook `woocommerce_after_shop_loop_item_title` en **prio 12** — después del
	 * título (prio 10) y después del marcador de disponibilidad de
	 * LoopEnhancer::render_availability_hint_loop (prio 11). Ese orden NO es
	 * cosmético: el colapso del placeholder en loop.css lo exige. El por qué está
	 * en el comentario de register(); no volver a empatar en 11.
	 * `calculate()` devuelve null para productos en stock, asi que la
	 * pildora solo aparece en cards de preventa/backorder (lee el object-term cache
	 * del loop, 0 queries nuevas). El dato ya se calculaba pero solo se exponia en
	 * la PDP/cart; esto lo lleva al archive (`/preventas/`).
	 *
	 * @since 1.49.9
	 * @return void
	 */
	public function render_loop_item(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$eta = $this->calc->calculate( $product );
		if ( null === $eta ) {
			return;
		}
		/*
		 * Un solo literal unificado para las dos ramas.
		 *
		 * Este rótulo identifica el campo de entrega ("Llegada") y no un estado comercial,
		 * manteniendo concisión en una sola línea en resoluciones compactas. El valor en sí
		 * comunica si corresponde a un rango de fechas o a una estimación tras publicación.
		 */
		$valor = $this->format_inline( $eta );

		if ( $eta->is_tba() ) {
			printf( '<p class="akb-eta-loop">%s</p>', esc_html( $valor ) );
			return;
		}

		// Cuenta atrás del corte, en versión COMPACTA: aquí el dato que falta es
		// la urgencia, no la fecha del cierre —que la ficha ya nombra entera—. En
		// /a-pedido/ las 24 tarjetas comparten origen, así que repetir «…en el
		// pedido del 5 de septiembre» en cada una sería la misma oración escrita
		// dos docenas de veces. Sale del mismo método que la de la ficha.
		$urgencia = $this->urgencia_de_tanda( $product, $eta, true );

		printf(
			'<p class="akb-eta-loop"><span class="akb-eta-loop__label">%s:</span> %s%s</p>',
			esc_html( 'Llegada' ),
			esc_html( $valor ),
			'' === $urgencia
				? ''
				: '<span class="akb-eta-loop__urgencia">' . esc_html( $urgencia ) . '</span>'
		);
	}

	/**
	 * Pure function — agrega ETAs de un cart array.
	 *
	 * Lógica:
	 *   - Sin items en preventa → null (no banner).
	 *   - ≥1 TBA sin lead conocido → EtaResult::tba() (fallback genérico).
	 *   - ≥1 TBA y todos con lead conocido → EtaResult::tba_with_lead( max(min),
	 *     max(max), null ) — sin país porque puede haber mix de orígenes.
	 *     El renderer usará copy plural "Llegan ~X semanas tras su publicación".
	 *   - Solo ranges → MAX(min) a MAX(max) (el más tardío manda).
	 *
	 * @param array<int|string, array<string, mixed>>     $cart_items   Items del cart WC.
	 * @param callable(WC_Product): (EtaResult|null)      $eta_resolver Resolver inyectable (calculator en prod, stub en tests).
	 * @return EtaResult|null
	 */
	public static function aggregate( array $cart_items, callable $eta_resolver ): ?EtaResult {
		$max_min          = 0;
		$max_max          = 0;
		$max_tba_min      = 0;
		$max_tba_max      = 0;
		$has_tba_with_lead = false;
		$has_tba_unknown   = false;
		$has_real          = false;

		foreach ( $cart_items as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$eta = $eta_resolver( $product );
			if ( null === $eta ) {
				continue;
			}

			$has_real = true;

			if ( $eta->is_tba() ) {
				if ( $eta->has_tba_lead() ) {
					$has_tba_with_lead = true;
					$min_days          = (int) $eta->tba_min_days();
					$max_days          = (int) $eta->tba_max_days();
					if ( $min_days > $max_tba_min ) {
						$max_tba_min = $min_days;
					}
					if ( $max_days > $max_tba_max ) {
						$max_tba_max = $max_days;
					}
				} else {
					$has_tba_unknown = true;
				}
				continue;
			}

			$min = (int) $eta->min_ts();
			$max = (int) $eta->max_ts();
			if ( $min > $max_min ) {
				$max_min = $min;
			}
			if ( $max > $max_max ) {
				$max_max = $max;
			}
		}

		if ( ! $has_real ) {
			return null;
		}
		// Cualquier TBA contagia el cart-overall. Unknown gana sobre with_lead
		// (más conservador: si no sabemos un lead, no podemos comprometernos).
		if ( $has_tba_unknown ) {
			return EtaResult::tba();
		}
		if ( $has_tba_with_lead ) {
			return EtaResult::tba_with_lead( $max_tba_min, $max_tba_max, null );
		}
		return EtaResult::range( $max_min, $max_max );
	}

	/**
	 * Pure function — formatea un rango de timestamps a texto ES.
	 *
	 * Reglas:
	 *   - Mismo día                → "10 jun".
	 *   - Mismo mes/año            → "10 – 15 jun".
	 *   - Distinto mes mismo año   → "27 jun – 1 jul".
	 *   - Año distinto del actual  → incluye año(s) explícitos.
	 *
	 * @param int $min_ts       Timestamp mínimo.
	 * @param int $max_ts       Timestamp máximo.
	 * @param int $current_year Año actual (inyectado para determinismo en tests).
	 * @return string
	 */
	public static function format_range( int $min_ts, int $max_ts, int $current_year, ?\DateTimeZone $tz = null ): string {
		// Lee día/mes/año en la tz inyectada (America/Santiago en prod, vía los
		// callers con StoreTime); default UTC para la función pura/tests. Evita
		// date() crudo dependiente de la tz del runtime y de que el ts venga floored
		// — tripwire #2.
		$tz  = $tz ?? new \DateTimeZone( 'UTC' );
		$min = ( new \DateTimeImmutable( '@' . $min_ts ) )->setTimezone( $tz );
		$max = ( new \DateTimeImmutable( '@' . $max_ts ) )->setTimezone( $tz );

		$min_day   = (int) $min->format( 'j' );
		$max_day   = (int) $max->format( 'j' );
		$min_month = self::MONTHS_ES[ (int) $min->format( 'n' ) ];
		$max_month = self::MONTHS_ES[ (int) $max->format( 'n' ) ];
		$min_year  = (int) $min->format( 'Y' );
		$max_year  = (int) $max->format( 'Y' );

		$show_year = ( $min_year !== $current_year || $max_year !== $current_year );

		if ( $min_ts === $max_ts ) {
			return $show_year
				? "{$min_day} {$min_month} {$min_year}"
				: "{$min_day} {$min_month}";
		}

		if ( $min_year !== $max_year ) {
			return "{$min_day} {$min_month} {$min_year} – {$max_day} {$max_month} {$max_year}";
		}

		if ( $min_month === $max_month ) {
			return $show_year
				? "{$min_day} – {$max_day} {$min_month} {$min_year}"
				: "{$min_day} – {$max_day} {$min_month}";
		}

		return $show_year
			? "{$min_day} {$min_month} – {$max_day} {$max_month} {$max_year}"
			: "{$min_day} {$min_month} – {$max_day} {$max_month}";
	}

	/**
	 * Año actual en la tz del sitio (America/Santiago), no en la del runtime.
	 * Evita que después de las 21:00 CL del 31-dic el banner muestre el año
	 * siguiente y decida mal el show-year (tripwire #2). Fallback a UTC en tests.
	 */
	private static function current_year_local(): int {
		return (int) ( new \DateTimeImmutable( 'now', StoreTime::zone() ) )->format( 'Y' );
	}

	/**
	 * Formats an ETA with the canonical customer-facing vocabulary.
	 *
	 * @param EtaResult $eta ETA result.
	 * @return string
	 *
	 * @since 1.95.0
	 */
	public function format_eta( EtaResult $eta ): string {
		return $this->format_inline( $eta );
	}

	/**
	 * Summarizes all cart ETAs using the conservative aggregate rule.
	 *
	 * Devuelve también `nota`: la explicación del corte de tanda para dar
	 * certidumbre al usuario en carrito y checkout sobre los plazos de despacho.
	 *
	 * @param array<int|string, array<string, mixed>> $cart_items Cart items.
	 * @return array{label:string,value:string,is_tba:bool,nota:string}|null
	 *
	 * @since 1.95.0
	 */
	public function summarize_cart( array $cart_items ): ?array {
		$eta = self::aggregate(
			$cart_items,
			function ( WC_Product $product ): ?EtaResult {
				return $this->calc->calculate( $product );
			}
		);

		if ( null === $eta ) {
			return null;
		}

		$labels = $this->labels_for( 'cart-overall', $eta );

		return array(
			'label'  => $labels['label'],
			'value'  => $labels['value'],
			'is_tba' => $eta->is_tba(),
			'nota'   => $this->nota_de_tanda_del_carrito( $cart_items, $eta ),
			'min_ts' => $eta->min_ts(),
			'max_ts' => $eta->max_ts(),
		);
	}

	/**
	 * La nota del corte MÁS TARDÍO del carrito, o '' si ninguna línea va por tanda.
	 *
	 * Misma regla conservadora que `aggregate()`: el pedido viaja completo, así
	 * que manda el corte que lo retiene. Nombrar el primero sería prometer que la
	 * caja sale antes de que exista.
	 *
	 * Y las semanas salen de la política de ESE corte, no de otra línea: es la
	 * editorial que decide cuándo parte el pedido la que describe cuánto viaja.
	 *
	 * Con el resultado agregado en TBA no hay nota, por la misma razón que en la
	 * ficha: «te avisamos en cuanto tengamos fechas» seguido de «sale en el
	 * pedido que cierra el 5» es el par de frases más contradictorio posible.
	 *
	 * @param array<int|string, array<string, mixed>> $cart_items Items del carrito.
	 * @param EtaResult                               $agregado   Resultado ya agregado.
	 */
	private function nota_de_tanda_del_carrito( array $cart_items, EtaResult $agregado ): string {
		if ( $agregado->is_tba() ) {
			return '';
		}

		$tz            = StoreTime::zone();
		$mejor_cierre  = null;
		$mejor_ancla   = 0;
		$mejor_politica = array();

		foreach ( $cart_items as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$eta = $this->calc->calculate( $product );
			if ( null === $eta || $eta->is_tba() ) {
				continue;
			}

			$policy = $this->policy->for_product( $product );
			$ancla  = PedidoPolicy::ancla_de_tanda( $eta, $policy );
			if ( null === $ancla ) {
				continue;
			}

			$cierre = $this->policy->proximo_cierre( $ancla, $policy['dia'], $tz, (string) ( $policy['frecuencia'] ?? '' ), (string) ( $policy['meses'] ?? '' ) );
			if ( null === $mejor_cierre || $cierre > $mejor_cierre ) {
				$mejor_cierre   = $cierre;
				$mejor_ancla    = $ancla;
				$mejor_politica = $policy;
			}
		}

		if ( null === $mejor_cierre ) {
			return '';
		}

		return $this->policy->frase_de_tanda( $mejor_politica, $mejor_ancla, $tz );
	}

	/**
	 * Formato inline para cart-item ("Llegada estimada: <esto>").
	 *
	 * Estados:
	 *   - Range          → "27 may – 1 jun" (formato ES).
	 *   - TBA con lead   → "Llega ~2 semanas tras su salida en Argentina"
	 *                      (incluye país porque es item individual de origen único).
	 *   - TBA sin lead   → "Te avisamos en cuanto tengamos fecha".
	 *
	 * @param EtaResult $eta Resultado.
	 * @return string
	 */
	private function format_inline( EtaResult $eta ): string {
		if ( $eta->is_tba() ) {
			if ( ! $eta->has_tba_lead() ) {
				// `Text::one_line_option()` aplica el fallback por defecto si la opción
				// está ausente o fue guardada como cadena vacía.
				return Text::one_line_option( 'akb_copy_eta_tba', 'Te avisamos en cuanto tengamos fecha' );
			}
			$lead    = self::format_lead_text(
				(int) $eta->tba_min_days(),
				(int) $eta->tba_max_days()
			);
			$country = $eta->tba_country_name();
			return null !== $country
				? "Llega {$lead} tras su salida en {$country}"
				: "Llega {$lead} tras su publicación";
		}
		return self::format_range(
			(int) $eta->min_ts(),
			(int) $eta->max_ts(),
			self::current_year_local(),
			StoreTime::zone()
		);
	}

	/**
	 * Pure function — formatea un lead time [min,max] en días a texto cálido.
	 *
	 * Regla: si avg(min,max) redondea a un valor entre 1 y 5 semanas, devuelve
	 * "~X semanas" (o "~1 semana" singular). Fuera de ese rango, fallback a
	 * "~X-Y días" para no decir cosas raras como "~0 semanas".
	 *
	 * Ejemplos para Akibara:
	 *   AR (12-15 días) → avg=13.5 → 2 sem → "~2 semanas"
	 *   ES (17-20 días) → avg=18.5 → 3 sem → "~3 semanas"
	 *
	 * @param int $min_days Lead time mínimo.
	 * @param int $max_days Lead time máximo.
	 * @return string
	 */
	public static function format_lead_text( int $min_days, int $max_days ): string {
		$avg   = ( $min_days + $max_days ) / 2;
		$weeks = (int) round( $avg / 7 );

		if ( 1 === $weeks ) {
			return '~1 semana';
		}
		if ( $weeks >= 2 && $weeks <= 5 ) {
			return "~{$weeks} semanas";
		}
		return "~{$min_days}-{$max_days} días";
	}

	/**
	 * Nota de tanda para un producto, o '' si su editorial no va por tanda.
	 *
	 * Se resuelve aquí y no dentro de `EtaResult` a propósito: el resultado del
	 * cálculo es un rango de fechas y no tiene por qué saber de políticas
	 * comerciales. Sólo la ficha muestra la nota; en la píldora de la grilla no
	 * cabe y repetirla en cada tarjeta sería ruido.
	 */
	private function nota_de_tanda( WC_Product $product, EtaResult $eta ): string {
		// Sin fecha calculada no hay tanda que nombrar. `EtaResult::tba()` es
		// justamente la rama que decide NO prometer (editorial pausada, o un tomo
		// anunciado sin fecha bajo una editorial por tanda); añadir ahí «sale en
		// el pedido que cierra el 5» produciría el banner más contradictorio
		// posible: «Te avisamos en cuanto tengamos fecha» seguido de una fecha.
		if ( $eta->is_tba() ) {
			return '';
		}

		$policy = $this->policy->for_product( $product );
		$ancla  = PedidoPolicy::ancla_de_tanda( $eta, $policy );
		if ( null === $ancla ) {
			return '';
		}

		return $this->policy->frase_de_tanda( $policy, $ancla, StoreTime::zone() );
	}

	/**
	 * Cuenta atrás del corte para un producto, o '' si no toca avisar.
	 *
	 * La ventana y el texto los decide `PedidoPolicy`; aquí sólo se le entrega el
	 * ancla del cálculo y el reloj del calculador. El «hoy» viene de
	 * `DateCalculator::now()` y no de `time()` a propósito: es el MISMO reloj que
	 * produjo la fecha de arriba, así que ficha y contador no pueden discrepar —y
	 * una prueba que congela el reloj congela las dos mitades.
	 *
	 * @param WC_Product $product  Producto de la ficha o de la tarjeta.
	 * @param EtaResult  $eta      Resultado ya calculado.
	 * @param bool       $compacta Versión sin fecha, para la píldora de la grilla.
	 */
	private function urgencia_de_tanda( WC_Product $product, EtaResult $eta, bool $compacta = false ): string {
		if ( $eta->is_tba() ) {
			return '';
		}

		$policy = $this->policy->for_product( $product );
		$ancla  = PedidoPolicy::ancla_de_tanda( $eta, $policy );
		if ( null === $ancla ) {
			return '';
		}

		return $this->policy->frase_de_urgencia(
			$policy,
			$ancla,
			$this->calc->timezone(),
			$this->calc->now(),
			$compacta
		);
	}

	/**
	 * Construye el HTML del banner. Pre-escapado internamente.
	 *
	 * @param EtaResult       $eta      Resultado del cálculo.
	 * @param string          $context  'single' | 'cart-overall'.
	 * @param string          $nota     Nota de tanda ('' si la editorial no va por tanda).
	 * @param WC_Product|null $product  Producto del banner (null en el agregado del carrito).
	 * @param string          $urgencia Aviso de cierre de tanda ('' sin urgencia).
	 * @return string HTML.
	 */
	private function render_banner_html( EtaResult $eta, string $context, string $nota = '', ?WC_Product $product = null, string $urgencia = '' ): string {
		$copy           = $this->labels_for( $context, $eta, $product );
		$modifier_class = 'akb-eta--' . $context;
		$tba_class      = $eta->is_tba() ? ' akb-eta--tba' : '';

		// La nota explica en qué pedido entra la compra cuando la editorial va por
		// tanda. Sin ella la fecha no se distingue de la del modelo anterior: lo
		// que sostiene la promesa es que el cliente entienda que la cuenta empieza
		// el día del cierre, no el día que compró.
		$nota_html = '' === $nota
			? ''
			: '<span class="akb-eta__nota">' . esc_html( $nota ) . '</span>';

		// La urgencia va DESPUÉS de la nota: primero se entiende la mecánica («el
		// pedido sale el 5 y desde ahí viaja»), y sólo entonces significa algo que
		// queden cinco días. Al revés es una presión sin explicación.
		$urgencia_html = '' === $urgencia
			? ''
			: '<span class="akb-eta__urgencia">' . esc_html( $urgencia ) . '</span>';

		return sprintf(
			'<div class="akb-eta %1$s%2$s"><span class="akb-eta__icon" aria-hidden="true">%3$s</span>'
			. '<span class="akb-eta__body"><span class="akb-eta__label">%4$s</span>'
			. '<span class="akb-eta__value">%5$s</span>%6$s%7$s</span></div>',
			esc_attr( $modifier_class ),
			esc_attr( $tba_class ),
			self::truck_svg(),
			esc_html( $copy['label'] ),
			esc_html( $copy['value'] ),
			$nota_html,
			$urgencia_html
		);
	}

	/**
	 * Copy completo (label + value) por contexto + estado del EtaResult.
	 *
	 * Banners:
	 *   single + range          → "Fecha estimada de llegada" / "27 may – 1 jun"
	 *   single + tba_with_lead  → "Preventa" / "Llega ~X sem tras su salida en {país}"
	 *   single + tba_unknown    → "Preventa" / "Te avisamos en cuanto tengamos fecha"
	 *   cart   + range          → "Tu pedido completo llega" / "27 may – 1 jun"
	 *   cart   + tba_with_lead  → "Tu pedido incluye preventas" / "Llegan ~X sem tras su publicación"
	 *   cart   + tba_unknown    → "Tu pedido incluye preventas" / "Te avisamos en cuanto tengamos fechas"
	 *
	 * @param string          $context 'single' | 'cart-overall'.
	 * @param EtaResult       $eta     Resultado.
	 * @param WC_Product|null $product Producto del single (null en cart-overall):
	 *                                 decide la PALABRA de la rama TBA, porque a
	 *                                 TBA también se llega con una editorial
	 *                                 PAUSADA sobre un tomo a pedido — y ahí el
	 *                                 label «Preventa» sería la mentira que la
	 *                                 separación 1.109.0 vino a retirar.
	 * @return array{label: string, value: string}
	 */
	private function labels_for( string $context, EtaResult $eta, ?WC_Product $product = null ): array {
		$is_tba       = $eta->is_tba();
		$is_cart      = 'cart-overall' === $context;

		if ( ! $is_tba ) {
			$value = self::format_range(
				(int) $eta->min_ts(),
				(int) $eta->max_ts(),
				self::current_year_local(),
				StoreTime::zone()
			);
			return array(
				'label' => $is_cart
					? Text::one_line_option( 'akb_copy_eta_cart_ready', 'Tu pedido completo llega' )
					: Text::one_line_option( 'akb_copy_eta_single_ready', 'Fecha estimada de llegada' ),
				'value' => $value,
			);
		}

		if ( $is_cart ) {
			// «reservas» y no «preventas»: el carrito puede mezclar tomos a pedido
			// con preventas reales y la palabra debe cubrir ambos sin mentir.
			$label = Text::one_line_option( 'akb_copy_eta_cart_preventa', 'Tu pedido incluye reservas' );
		} elseif ( null !== $product && Availability::is_a_pedido( $product ) ) {
			// Por la fuente única, no por `get_option` a pelo: ahí una option
			// vaciada por `wp option update` dejaba el cartel EN BLANCO mientras
			// el badge de la tarjeta seguía diciendo la palabra de fábrica.
			$label = Availability::palabra( Availability::A_PEDIDO );
		} else {
			$label = Availability::palabra( Availability::PREVENTA );
		}

		if ( ! $eta->has_tba_lead() ) {
			return array(
				'label' => $label,
				'value' => Text::one_line_option( 'akb_copy_eta_tba', 'Te avisamos en cuanto tengamos fecha' ),
			);
		}

		$lead    = self::format_lead_text(
			(int) $eta->tba_min_days(),
			(int) $eta->tba_max_days()
		);
		$country = $eta->tba_country_name();

		if ( $is_cart ) {
			// Cart-overall siempre plural ("Llegan") porque el banner agrupa items.
			// Sin país (puede ser mix de orígenes en el cart).
			return array(
				'label' => $label,
				'value' => "Llegan {$lead} tras su publicación",
			);
		}

		// Single: con país si está disponible (item de origen único).
		return array(
			'label' => $label,
			'value' => null !== $country
				? "Llega {$lead} tras su salida en {$country}"
				: "Llega {$lead} tras su publicación",
		);
	}

	/**
	 * Truck icon SVG — inline para evitar HTTP extra.
	 *
	 * @return string
	 */
	private static function truck_svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>';
	}
}
