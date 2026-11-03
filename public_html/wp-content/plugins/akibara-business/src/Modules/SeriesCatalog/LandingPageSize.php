<?php
/**
 * Tamaño editable de las páginas de una landing de serie.
 *
 * El valor predeterminado es 48 productos para presentar la serie en orden de
 * lectura con menos paginación. parse() define un entero entre MIN y MAX y se
 * comparte entre saneador y lector: el panel rechaza entradas inválidas y la
 * lectura usa el valor de fábrica para filas inválidas.
 *
 * Una página limpia fuera de rango redirige con 301 a la última existente.
 * Facetas y parámetros de orden conservan el 404; los parámetros de atribución
 * como utm_*, fbclid y los identificadores de Google se conservan sin impedir
 * la redirección. Esa 301 es no cacheable para que otro cambio de tamaño pueda
 * restablecer la página sin una redirección retenida.
 *
 * Al cambiar el tamaño efectivo, por panel o CLI, se purgan las landings de
 * series en LiteSpeed mediante T.<term_id> y en el borde por URL. No se purgan
 * fichas, portada ni blog: mezclar páginas de tamaños distintos omitiría o
 * repetiría productos al navegar.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesCatalog;

use Akibara\Business\Modules\SiteSpeed\EdgePurge;

defined( 'ABSPATH' ) || exit;

/**
 * Tamaño de página de las landings de serie y redirección de las que sobran.
 */
final class LandingPageSize {

	/** Option del panel (pestaña Vitrina). */
	public const OPTION = 'akb_series_landing_per_page';

	/** De fábrica: 109 de las 110 series del catálogo caben en una página. */
	public const DEFAULT_PER_PAGE = 48;

	/** Mínimo aceptado: la mitad del tamaño general del catálogo. */
	public const MIN = 12;

	/** Máximo aceptado: ver el docblock de la clase. */
	public const MAX = 120;

	/** Taxonomía de las landings de serie. */
	private const TAXONOMY = SeriesCatalogEligibility::TAXONOMY;

	/** Pestaña del panel donde vive el ajuste (`CatalogOrder::PANEL_TAB_ID`). */
	private const PANEL_TAB = 'catalog_order';

	/**
	 * Parámetros de seguimiento que no cambian lo que lista la página: con ellos
	 * (y sólo con ellos) la página sigue siendo «limpia». Ver el docblock.
	 */
	private const TRACKING_PARAMS = array( 'fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'srsltid', 'yclid', 'ttclid', 'twclid', 'igshid', '_ga', '_gl', 'mc_cid', 'mc_eid' );

	/** Prefijo de los parámetros de campaña (`utm_source`, `utm_medium`…). */
	private const TRACKING_PREFIX = 'utm_';

	/**
	 * El número de página en la consulta (`?paged=2`). No es un filtro: es la
	 * misma página que `/page/2/`, que ya se redirige. Con él fuera de la lista,
	 * `?paged=2` de una serie que ahora cabe en una página daba 404 y `/page/2/`
	 * daba 301. El destino lo arma
	 * `get_pagenum_link()`, que quita `paged` de la consulta.
	 */
	private const PAGE_PARAM = 'paged';

	/** Autoridad del conteo que publica la rejilla. */
	private SeriesCatalogEligibility $eligibility;

	/** Destino de la redirección decidido antes de que WordPress fije el 404. */
	private ?string $pending_redirect = null;

	/**
	 * @param SeriesCatalogEligibility $eligibility Autoridad compartida del catálogo visible.
	 */
	public function __construct( SeriesCatalogEligibility $eligibility ) {
		$this->eligibility = $eligibility;
	}

	/**
	 * Engancha el tamaño, la redirección y el saneado del panel.
	 */
	public function register(): void {
		add_filter( 'loop_shop_per_page', array( $this, 'handle_loop_shop_per_page' ), 20 );
		add_filter( 'pre_handle_404', array( $this, 'handle_pre_handle_404' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'handle_template_redirect' ), 1 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPTION, array( self::class, 'sanitize_for_save' ), 10, 3 );
		add_filter( 'akibara_panel_options_not_saved', array( $this, 'report_rejected_value' ), 10, 2 );
		add_filter( 'akibara_panel_option_not_saved_reason', array( $this, 'rejected_value_reason' ), 10, 3 );
		add_action( 'add_option_' . self::OPTION, array( $this, 'handle_option_added' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION, array( $this, 'handle_option_updated' ), 10, 2 );
		add_action( 'delete_option', array( $this, 'handle_option_deleting' ) );
	}

	/**
	 * La fila se crea: antes regía el de fábrica.
	 *
	 * @internal Callback de `add_option_{OPTION}`.
	 *
	 * @param mixed $option Nombre de la option (sin uso).
	 * @param mixed $value  Valor nuevo.
	 */
	public function handle_option_added( $option, $value ): void {
		unset( $option );
		$this->purge_if_size_changed( self::DEFAULT_PER_PAGE, $value );
	}

	/**
	 * La fila cambia de valor (WordPress no dispara esto si el valor es igual).
	 *
	 * @internal Callback de `update_option_{OPTION}`.
	 *
	 * @param mixed $old_value Valor anterior.
	 * @param mixed $value     Valor nuevo.
	 */
	public function handle_option_updated( $old_value, $value ): void {
		$this->purge_if_size_changed( $old_value, $value );
	}

	/**
	 * La fila se borra (WP-CLI): vuelve a regir el de fábrica. Se mira ANTES de
	 * borrar, que es cuando todavía se puede leer el tamaño que había.
	 *
	 * @internal Callback de `delete_option`.
	 *
	 * @param mixed $option Option que se va a borrar.
	 */
	public function handle_option_deleting( $option ): void {
		if ( self::OPTION !== $option ) {
			return;
		}
		$this->purge_if_size_changed( get_option( self::OPTION, self::DEFAULT_PER_PAGE ), self::DEFAULT_PER_PAGE );
	}

	/**
	 * Purga las landings sólo si cambia el tamaño que de verdad se pinta: un
	 * valor inválido lo lee `per_page()` como el de fábrica, así que «48» → «abc»
	 * no cambia nada que esté en la caché.
	 *
	 * @param mixed $old_value Valor anterior (crudo).
	 * @param mixed $value     Valor nuevo (crudo).
	 */
	private function purge_if_size_changed( $old_value, $value ): void {
		$before = self::parse( $old_value ) ?? self::DEFAULT_PER_PAGE;
		$after  = self::parse( $value ) ?? self::DEFAULT_PER_PAGE;
		if ( $before === $after ) {
			return;
		}

		$this->purge_landings();
	}

	/**
	 * Purga la landing de cada serie —con sus páginas— en LiteSpeed y en el borde.
	 *
	 * @internal Público para probar la purga sin pasar por la option.
	 */
	public function purge_landings(): void {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			// `T.` es `LiteSpeed\Tag::TYPE_ARCHIVE_TERM`: LiteSpeed etiqueta así
			// cada archive de taxonomía, incluidas sus páginas 2+. No se referencia
			// la clase para que Business siga activable sin LiteSpeed.
			do_action( 'litespeed_purge', 'T.' . (int) $term->term_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
			$url = get_term_link( $term, self::TAXONOMY );
			if ( is_string( $url ) ) {
				EdgePurge::anotar( $url );
			}
		}
	}

	/**
	 * Campos del panel. Se suman a los de la pestaña Vitrina.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_settings(): array {
		return array(
			array(
				'title' => 'Landings de serie',
				'type'  => 'title',
				'id'    => 'akb_series_landing_page_size_settings',
			),
			array(
				'title'             => 'Tomos por página',
				'id'                => self::OPTION,
				'type'              => 'number',
				'default'           => (string) self::DEFAULT_PER_PAGE,
				'css'               => 'width: 90px;',
				'custom_attributes' => array(
					'min'  => (string) self::MIN,
					'max'  => (string) self::MAX,
					'step' => '1',
				),
				'desc'              => sprintf(
					'Cuántos tomos muestra cada página de una serie (/serie/…), en orden «Tomo 1 → N». Con %1$d caben enteras todas las series salvo las más largas. Más tomos por página es una página más pesada en el celular: con 48 una serie larga tarda del orden de 0,1–0,2 s más en pintarse en un teléfono lento. Entre %2$d y %3$d; el resto de la tienda sigue con su propio tamaño de página. Al cambiarlo se limpian de la caché las páginas de serie.',
					self::DEFAULT_PER_PAGE,
					self::MIN,
					self::MAX
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_series_landing_page_size_settings',
			),
		);
	}

	/**
	 * Valor válido, o null si no lo es. La única definición, para guardar y leer.
	 *
	 * @param mixed $raw Valor del formulario, de la option o de WP-CLI.
	 */
	public static function parse( $raw ): ?int {
		if ( is_int( $raw ) ) {
			$value = $raw;
		} elseif ( is_string( $raw ) && 1 === preg_match( '/^\s*\d{1,4}\s*$/', $raw ) ) {
			$value = (int) trim( $raw );
		} else {
			return null;
		}

		return ( $value >= self::MIN && $value <= self::MAX ) ? $value : null;
	}

	/** Tamaño vigente: el guardado si es válido, el de fábrica si no. */
	public static function per_page(): int {
		return self::parse( get_option( self::OPTION, self::DEFAULT_PER_PAGE ) ) ?? self::DEFAULT_PER_PAGE;
	}

	/**
	 * Saneador del panel: null deja la fila como estaba (WooCommerce no escribe).
	 *
	 * @internal Callback de `woocommerce_admin_settings_sanitize_option_{OPTION}`.
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del campo.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	public static function sanitize_for_save( $value, array $option = array(), $raw_value = null ): ?string {
		unset( $option );
		$parsed = self::parse( null !== $raw_value ? $raw_value : $value );

		return null === $parsed ? null : (string) $parsed;
	}

	/**
	 * Tamaño de página del archive principal de una serie.
	 *
	 * WooCommerce lo pide en `pre_get_posts` de la consulta principal, donde
	 * `is_tax()` ya responde por ella.
	 *
	 * @internal Callback de `loop_shop_per_page`.
	 *
	 * @param mixed $per_page Tamaño que trae WooCommerce.
	 * @return mixed
	 */
	public function handle_loop_shop_per_page( $per_page ) {
		if ( is_admin() || ! is_tax( self::TAXONOMY ) ) {
			return $per_page;
		}

		return self::per_page();
	}

	/**
	 * Decide la redirección ANTES de que WordPress marque el 404: después de
	 * `set_404()` la consulta ya no dice que era una serie.
	 *
	 * @internal Callback de `pre_handle_404`. No altera el veredicto.
	 *
	 * @param mixed $preempt  Veredicto de otro filtro.
	 * @param mixed $wp_query Consulta principal.
	 * @return mixed
	 */
	public function handle_pre_handle_404( $preempt, $wp_query ) {
		if ( false === $preempt ) {
			$this->pending_redirect = $this->out_of_range_target( $wp_query );
		}

		return $preempt;
	}

	/**
	 * Ejecuta la redirección decidida en `pre_handle_404`.
	 *
	 * @internal Callback de `template_redirect` prio 1.
	 */
	public function handle_template_redirect(): void {
		if ( null === $this->pending_redirect ) {
			return;
		}

		$target                 = $this->pending_redirect;
		$this->pending_redirect = null;
		// Ni LiteSpeed ni el navegador la guardan: el destino depende del tamaño
		// de página del panel, que el dueño puede bajar mañana (ver el docblock).
		do_action( 'litespeed_control_set_nocache', 'serie: página fuera de rango' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
		wp_safe_redirect( $target, 301, 'Akibara' );
		exit;
	}

	/**
	 * URL de la última página que existe si la pedida quedó fuera de rango.
	 *
	 * Sólo para la consulta principal de una serie, en una página limpia
	 * (sin parámetros, salvo los de seguimiento y `paged`) y sin resultados. El total sale
	 * de la misma autoridad que decide si la serie tiene catálogo público; si no
	 * es legible o es cero, no se redirige.
	 *
	 * El destino conserva los parámetros de seguimiento (`get_pagenum_link()`
	 * arrastra la consulta de la petición) y va SIN escapar para HTML: escapado,
	 * el `&` entre dos de ellos salía como `&#038;` en la cabecera `Location`.
	 *
	 * @internal Público para probar la decisión sin redirigir.
	 *
	 * @param mixed $wp_query Consulta principal.
	 */
	public function out_of_range_target( $wp_query ): ?string {
		if (
			! $wp_query instanceof \WP_Query
			|| ! $wp_query->is_main_query()
			|| ! $wp_query->is_tax( self::TAXONOMY )
			|| ! empty( $wp_query->posts )
			|| ! self::only_tracking_params( $_GET ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo se miran los nombres de los parámetros.
		) {
			return null;
		}

		$paged = (int) $wp_query->get( 'paged' );
		$term  = $wp_query->get_queried_object();
		if ( $paged < 2 || ! is_object( $term ) || ! isset( $term->term_taxonomy_id ) ) {
			return null;
		}

		$total = $this->eligibility->count( (int) $term->term_taxonomy_id );
		if ( ! $this->eligibility->is_reliable() || $total < 1 ) {
			return null;
		}

		$last = (int) ceil( $total / self::per_page() );
		if ( $paged <= $last ) {
			return null;
		}

		$target = get_pagenum_link( $last, false );

		return is_string( $target ) && '' !== $target ? $target : null;
	}

	/**
	 * ¿La consulta trae sólo parámetros de seguimiento y el número de página (o
	 * ninguno)?
	 *
	 * `paged` se compara tal cual, sin pasar a minúsculas: WordPress sólo lee esa
	 * forma, y un `?PAGED=2` no pagina nada.
	 *
	 * @param mixed $query `$_GET` de la petición.
	 */
	private static function only_tracking_params( $query ): bool {
		if ( ! is_array( $query ) ) {
			return false;
		}

		foreach ( array_keys( $query ) as $key ) {
			if ( self::PAGE_PARAM === (string) $key ) {
				continue;
			}
			$key = strtolower( (string) $key );
			if ( ! in_array( $key, self::TRACKING_PARAMS, true ) && 0 !== strpos( $key, self::TRACKING_PREFIX ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Nombra el campo cuando el valor escrito no entró.
	 *
	 * El saneador devuelve null y WooCommerce no escribe: sin esto el panel
	 * diría «guardado» y el dueño vería otra vez el número de antes.
	 *
	 * @internal Callback de `akibara_panel_options_not_saved`.
	 *
	 * @param array<int, string> $no_guardados Options que no quedaron.
	 * @param string             $tab          Pestaña guardada.
	 * @return array<int, string>
	 */
	public function report_rejected_value( array $no_guardados, string $tab ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- AdminPanel::handle_save() verifica el nonce antes de este filtro.
		if ( self::PANEL_TAB !== $tab || ! isset( $_POST[ self::OPTION ] ) ) {
			return $no_guardados;
		}

		$sent = is_scalar( $_POST[ self::OPTION ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::OPTION ] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( null === self::parse( $sent ) ) {
			$no_guardados[] = self::OPTION;
		}

		return $no_guardados;
	}

	/**
	 * Por qué no entró: el rango, no el buscador (motivo por defecto del panel).
	 *
	 * @internal Callback de `akibara_panel_option_not_saved_reason`.
	 *
	 * @param string $motivo Motivo por defecto.
	 * @param string $option Option que no quedó.
	 * @param string $tab    Pestaña guardada.
	 */
	public function rejected_value_reason( string $motivo, string $option, string $tab ): string {
		unset( $tab );
		if ( self::OPTION !== $option ) {
			return $motivo;
		}

		return sprintf( 'Tiene que ser un número entero entre %1$d y %2$d. Se mantiene el valor anterior.', self::MIN, self::MAX );
	}
}
