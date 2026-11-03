<?php
/**
 * Shortcode `[akibara_brands_grid]` — renderiza directorio de brands
 * agrupado por región.
 *
 * Atributos:
 *   show_empty   "yes"|"no"   default "no" — incluir brands con count=0
 *   group        "yes"|"no"   default "yes" — agrupar por región
 *
 * Output: delega al template del theme `template-parts/brands/grid.php`.
 * Si el theme no provee el template, fallback markup mínimo embebido.
 *
 * Cache: transient 1 hora con key derivada de los args Y de un contador de
 * generación en `wp_options`. Invalida al editar/crear/borrar terms del
 * taxonomy `product_brand`, y al entrar/salir un producto de una marca
 * (`set_object_terms`/`deleted_term_relationships`), incrementando ese contador.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use Akibara\Business\Services\BrandsHubUrl;
use Akibara\Business\Utilities\ProductVisibility;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public const TAG       = 'akibara_brands_grid';
	public const TAXONOMY  = 'product_brand';
	private const CACHE_KEY_PREFIX = 'akb_brands_grid_';
	private const CACHE_TTL        = HOUR_IN_SECONDS;

	/**
	 * Una generación invalida todas las variantes de la grilla. Las claves
	 * dependen de atributos abiertos del shortcode y pueden vivir en object cache
	 * persistente; por ello no se enumeran ni se borran transients por SQL.
	 */
	private const CACHE_GEN_OPTION = 'akb_brands_grid_gen';

	/** Impide registrar más de una purga tardía del hub por request. */
	private bool $hub_purge_scheduled = false;

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );

		// Invalidate cache when brands change.
		add_action( 'edited_' . self::TAXONOMY,  array( $this, 'flush_cache' ) );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'flush_cache' ) );
		add_action( 'delete_' . self::TAXONOMY,  array( $this, 'flush_cache' ) );

		// clean_term_cache también refleja cambios de conteo provocados por
		// wp_update_term_count_now; esa vía debe invalidar la grilla de marcas.
		add_action( 'set_object_terms', array( $this, 'flush_on_term_change' ), 10, 6 );
		add_action( 'deleted_term_relationships', array( $this, 'flush_on_deleted_term_relationships' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'flush_on_status_change' ), 10, 3 );
	}

	/**
	 * Detecta cambios efectivos de product_brand comparando términos anteriores
	 * y nuevos. WordPress también dispara set_object_terms al guardar sin cambios.
	 * Comparte la decisión con HomeCarousel para evitar purgas redundantes.
	 *
	 * @param mixed $taxonomy   Taxonomía del hook.
	 * @param mixed $tt_ids     term_taxonomy_id que quedan.
	 * @param mixed $old_tt_ids term_taxonomy_id que había.
	 */
	public static function brand_terms_changed( $taxonomy, $tt_ids, $old_tt_ids ): bool {
		if ( self::TAXONOMY !== (string) $taxonomy ) {
			return false;
		}
		$quedan = array_map( 'intval', (array) $tt_ids );
		$habia  = array_map( 'intval', (array) $old_tt_ids );
		sort( $quedan );
		sort( $habia );

		return $quedan !== $habia;
	}

	/**
	 * Invalida cuando un producto entra a una marca o sale de ella.
	 *
	 * @internal Callback de `set_object_terms`.
	 *
	 * @param mixed $object_id  Producto, no usado.
	 * @param mixed $terms      Términos pedidos, no usados.
	 * @param mixed $tt_ids     term_taxonomy_id que quedan.
	 * @param mixed $taxonomy   Taxonomía afectada.
	 * @param mixed $append     Si se añadieron, no usado.
	 * @param mixed $old_tt_ids term_taxonomy_id que había.
	 * @return void
	 */
	public function flush_on_term_change( $object_id, $terms, $tt_ids, $taxonomy = '', $append = false, $old_tt_ids = array() ): void {
		unset( $object_id, $terms, $append );

		if ( self::brand_terms_changed( $taxonomy, $tt_ids, $old_tt_ids ) ) {
			$this->flush_cache();
		}
	}

	/**
	 * Cubre `wp_remove_object_terms()`, que no termina en `set_object_terms`.
	 *
	 * @internal Callback de `deleted_term_relationships`.
	 *
	 * @param mixed  $object_id Producto, no usado.
	 * @param mixed  $tt_ids    Relaciones borradas, no usadas.
	 * @param string $taxonomy  Taxonomía afectada.
	 * @return void
	 */
	public function flush_on_deleted_term_relationships( $object_id, $tt_ids, $taxonomy = '' ): void {
		// Aquí `$tt_ids` son las relaciones BORRADAS: lo que queda es «sin ellas».
		$this->flush_on_term_change( $object_id, array(), array(), $taxonomy, false, $tt_ids );
	}

	/**
	 * Invalida el directorio cuando una transición de publicación cambia el
	 * conteo de la editorial sin pasar por set_object_terms.
	 *
	 * @internal Callback de `transition_post_status`.
	 *
	 * @param mixed $new_status Estado nuevo.
	 * @param mixed $old_status Estado anterior.
	 * @param mixed $post       Post que cambia.
	 * @return void
	 */
	public function flush_on_status_change( $new_status, $old_status, $post ): void {
		if ( ProductVisibility::changed( $new_status, $old_status, $post ) ) {
			$this->flush_cache();
		}
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public function render( $atts ): string {
		// Carga defensiva: el shortcode puede ejecutarse desde template, widget
		// o bloque sincronizado y no ser detectable en post_content.
		do_action( 'akibara_brands_directory_assets' );

		$atts = shortcode_atts(
			array(
				'show_empty' => 'no',
				'group'      => 'yes',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		$cache_key = $this->cache_key( $atts );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_string( $cached ) ) {
			return $cached;
		}

		$brands = $this->fetch_brands( 'yes' === $atts['show_empty'] );
		if ( empty( $brands ) ) {
			return '';
		}

		$grouped = ( 'yes' === $atts['group'] )
			? $this->group_by_region( $brands )
			: array( BrandRegion::REGION_OTROS => $brands );

		// Metadata por región para los chips del filtro. El filtro en cliente NO
		// necesita nada más: cada `.akb-brand-card` ya trae slug, región y nombre
		// normalizado en sus `data-akb-brand-*`, y el conteo lo saca la plantilla
		// de `$grouped`. La lista plana que se publicaba como JSON —2.233 B por
		// render de /editoriales/— no la parseaba ningún JS.
		$regions_meta = $this->build_regions_meta( $grouped );

		$html = $this->render_via_template( $grouped, $atts, $regions_meta );

		set_transient( $cache_key, $html, self::CACHE_TTL );

		return $html;
	}

	/**
	 * Invalida TODAS las variantes subiendo la generación, y programa la purga
	 * del HTML del hub.
	 *
	 * Las entradas de la generación anterior quedan huérfanas y caducan solas
	 * en 1 h (`CACHE_TTL`); nadie vuelve a pedirlas porque la clave ya cambió.
	 * Editar una editorial es una acción rara, así que la basura es acotada.
	 */
	public function flush_cache(): void {
		$next = $this->cache_generation() + 1;
		// `PHP_INT_MAX` no se alcanza jamás con este ritmo de edición, pero un
		// desbordamiento devolvería la generación a un valor ya usado y con él a
		// una entrada vieja todavía viva: se vuelve a 1 antes de llegar ahí.
		if ( $next >= PHP_INT_MAX ) {
			$next = 1;
		}
		update_option( self::CACHE_GEN_OPTION, $next, true );

		// El transient no es la única capa: LiteSpeed puede seguir sirviendo el
		// HTML completo de la página que contiene el shortcode, y ninguno de sus
		// hooks nativos (todos de POST) dispara al guardar un TÉRMINO. Misma
		// barrera tardía que `SeriesDirectory\Shortcode::purge_hub_page_cache()`:
		// un guardado en ráfaga (import de editoriales) purga una sola vez.
		if ( ! $this->hub_purge_scheduled ) {
			add_action( 'shutdown', array( $this, 'purge_hub_page_cache' ), PHP_INT_MAX );
			$this->hub_purge_scheduled = true;
		}
	}

	/**
	 * Emite una única purga tardía de la página del directorio, cuya URL se
	 * resuelve mediante BrandsHubUrl, compartido con menú y carrusel.
	 *
	 * @internal Callback de `shutdown` y seam de pruebas.
	 */
	public function purge_hub_page_cache(): void {
		do_action( 'litespeed_purge_url', BrandsHubUrl::url() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
	}

	/**
	 * Clave de caché de esta variante en la generación vigente.
	 *
	 * @param array<string, string> $atts
	 */
	private function cache_key( array $atts ): string {
		return self::CACHE_KEY_PREFIX . $this->cache_generation() . '_' . md5( (string) wp_json_encode( $atts ) );
	}

	/** Generación vigente. Ausente = 1: nunca se invalidó todavía. */
	private function cache_generation(): int {
		$generation = (int) get_option( self::CACHE_GEN_OPTION, 1 );

		return $generation > 0 ? $generation : 1;
	}

	/**
	 * @return list<WP_Term>
	 */
	private function fetch_brands( bool $include_empty ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => ! $include_empty,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		return is_array( $terms ) ? array_values( array_filter( $terms, static fn( $t ) => $t instanceof WP_Term ) ) : array();
	}

	/**
	 * Agrupa brands por región, manteniendo el orden de regiones.
	 *
	 * @param list<WP_Term> $brands
	 * @return array<string, list<WP_Term>>
	 */
	private function group_by_region( array $brands ): array {
		$grouped = array();
		foreach ( BrandRegion::ordered_regions() as $region ) {
			$grouped[ $region ] = array();
		}
		foreach ( $brands as $brand ) {
			// for_term() respeta el override de Región del admin (term meta) y recién
			// después cae al slug/nombre; for_slug() lo ignoraba. Igual que
			// HomeCarousel y MenuInjector.
			$region = BrandRegion::for_term( $brand );
			$grouped[ $region ][] = $brand;
		}
		// Drop regions vacías para no renderear sections vacías.
		return array_filter( $grouped, static fn( $v ) => ! empty( $v ) );
	}

	/**
	 * Carga el template del theme. Si no existe, fallback inline.
	 *
	 * Pasa al template:
	 *   - $grouped      : brands por región
	 *   - $atts         : shortcode atts
	 *   - $regions_meta : metadata por región para chips de filtrado
	 *
	 * @param array<string, list<WP_Term>>                 $grouped
	 * @param array<string, string>                        $atts
	 * @param array<string, array{label:string,count:int}> $regions_meta
	 */
	private function render_via_template( array $grouped, array $atts, array $regions_meta ): string {
		$template = locate_template( 'template-parts/brands/grid.php' );

		ob_start();

		if ( '' !== $template ) {
			include $template;
		} else {
			echo $this->fallback_markup( $grouped ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe markup builder.
		}

		return (string) ob_get_clean();
	}

	/**
	 * Metadata por región para los chips de filtro: label + count.
	 *
	 * @param array<string, list<WP_Term>> $grouped
	 * @return array<string, array{label:string,count:int}>
	 */
	private function build_regions_meta( array $grouped ): array {
		$out = array();
		foreach ( $grouped as $region => $brands ) {
			$out[ $region ] = array(
				'label' => BrandRegion::label( $region ),
				'count' => count( $brands ),
			);
		}
		return $out;
	}

	/**
	 * Fallback minimal si el theme no provee template.
	 *
	 * @param array<string, list<WP_Term>> $grouped
	 */
	private function fallback_markup( array $grouped ): string {
		$out = '<div class="akb-brands-directory">';
		foreach ( $grouped as $region => $brands ) {
			$out .= '<section class="akb-brands-section">';
			$out .= '<h2>' . esc_html( BrandRegion::label( $region ) ) . '</h2>';
			$out .= '<ul class="akb-brands-list">';
			foreach ( $brands as $brand ) {
				$out .= sprintf(
					'<li><a href="%s">%s <span>(%d)</span></a></li>',
					esc_url( get_term_link( $brand ) ),
					esc_html( BrandRegion::display_name( $brand, (string) $region ) ),
					(int) $brand->count
				);
			}
			$out .= '</ul></section>';
		}
		$out .= '</div>';
		return $out;
	}
}
