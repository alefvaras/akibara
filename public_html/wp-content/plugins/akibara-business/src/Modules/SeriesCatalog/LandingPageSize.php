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
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress HookNotFound
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
	 * Engancha el tamaño y la redirección de páginas fuera de rango.
	 */
	public function register(): void {
		add_filter( 'loop_shop_per_page', array( $this, 'handle_loop_shop_per_page' ), 20 );
		add_filter( 'pre_handle_404', array( $this, 'handle_pre_handle_404' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'handle_template_redirect' ), 1 );
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
	 * Valor válido, o null si no lo es. La única definición, para validar constantes y lecturas.
	 *
	 * @param mixed $raw Valor de la constante o de WP-CLI.
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

	/**
	 * Tamaño de página vigente para las landings de serie.
	 *
	 * Arquitectura de 2 niveles (12-Factor App / WordPress VIP):
	 * 1. Override opcional en wp-config.php (`AKB_SERIES_LANDING_PER_PAGE`).
	 * 2. Default canónico en código (`DEFAULT_PER_PAGE = 48`).
	 *
	 * Cero consultas a base de datos.
	 */
	public static function per_page(): int {
		if ( defined( 'AKB_SERIES_LANDING_PER_PAGE' ) ) {
			$override = self::parse( AKB_SERIES_LANDING_PER_PAGE );
			if ( null !== $override ) {
				return $override;
			}
		}

		return self::DEFAULT_PER_PAGE;
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
}
