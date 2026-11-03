<?php
/**
 * HomeCarousel — strip de editoriales para la home (grid desktop + scroll-snap mobile).
 *
 * Separación de capas:
 *   - Plugin (este archivo) = data fetching, transient cache, hooks,
 *     shortcode, country detection.
 *   - Theme = template (`template-parts/brands/home-carousel.php`) + CSS
 *     (`assets/css/brands-home.css`). Si el theme no provee el template,
 *     este módulo no renderiza nada (theme-agnostic vía degradación).
 *
 * Fuentes:
 *   - Taxonomy `product_brand` (WC native) con `thumbnail_id` por term.
 *     Owner sube imágenes desde Productos → Marcas → editar marca →
 *     campo "Thumbnail" (Imagen). NO requiere UI nueva.
 *
 * Render:
 *   - Shortcode `[akibara_brands_home]` para inserción explícita.
 *
 * Performance:
 *   - Transient cache 12h (invalida al editar/crear/borrar terms Y al entrar/
 *     salir un producto de una marca, ver register()).
 *   - CSS-only (sin JS): grid 4-col desktop, scroll-snap touch en mobile.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use Akibara\Business\Services\BrandsHubUrl;
use Akibara\Business\Utilities\ProductVisibility;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class HomeCarousel {

	public const SHORTCODE  = 'akibara_brands_home';
	private const CACHE_KEY = 'akibara_home_carousel_brands_v4';
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Tope de seguridad de rendimiento para los logos del carrusel. Las marcas
	 * con productos y logo aparecen automáticamente; el filtro
	 * akibara_home_carousel_max_brands permite ajustar el límite.
	 */
	private const HOME_MAX_BRANDS = 40;

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

		// Invalidación de cache cuando cambian terms.
		add_action( 'edited_' . Shortcode::TAXONOMY,  array( $this, 'flush_cache' ) );
		add_action( 'created_' . Shortcode::TAXONOMY, array( $this, 'flush_cache' ) );
		add_action( 'delete_' . Shortcode::TAXONOMY,  array( $this, 'flush_cache' ) );

		// Los conteos de marca pueden actualizarse mediante clean_term_cache sin
		// pasar por hooks de edición. Esa vía también invalida el carrusel para
		// reflejar la primera publicación o la retirada del último producto.
		add_action( 'set_object_terms', array( $this, 'flush_on_term_change' ), 10, 6 );
		add_action( 'deleted_term_relationships', array( $this, 'flush_on_deleted_term_relationships' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'flush_on_status_change' ), 10, 3 );
	}

	/**
	 * Invalida cuando un producto entra a una marca o sale de ella. Un guardado
	 * que deja las marcas como estaban no vacía nada: la regla vive en
	 * `Shortcode::brand_terms_changed()`.
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

		if ( Shortcode::brand_terms_changed( $taxonomy, $tt_ids, $old_tt_ids ) ) {
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
	 * Invalida el carrusel cuando la transición de publicación de un producto
	 * cambia el conteo de su editorial, sin depender de set_object_terms.
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
	public function render_shortcode( $atts ): string {
		return $this->render();
	}

	public function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Render principal — fetch + locate template + buffered include.
	 */
	private function render(): string {
		$brands = $this->fetch_brands();
		if ( empty( $brands ) ) {
			return '';
		}

		$template = locate_template( 'template-parts/brands/home-carousel.php' );
		if ( '' === $template ) {
			return '';
		}

		// Expone al template la URL resuelta por BrandsHubUrl, compartida con menú
		// y grilla. El helper busca la página por shortcode y usa el slug como respaldo.
		$more_url = BrandsHubUrl::url();

		// El teaser muestra hasta HOME_MAX_BRANDS; si hay MÁS editoriales, el CTA
		// señala la amplitud que la vitrina ya no lista ("Ver las N editoriales").
		$total_brands = (int) wp_count_terms( array( 'taxonomy' => Shortcode::TAXONOMY, 'hide_empty' => true ) );
		$more_label   = $total_brands > count( $brands )
			? sprintf( 'Ver las %d editoriales →', $total_brands )
			: 'Ver editoriales →';

		ob_start();
		include $template;
		return (string) ob_get_clean();
	}

	/**
	 * @return list<array{name:string,slug:string,url:string,img:string,img_w:int,img_h:int,count:int,country:string}>
	 */
	private function fetch_brands(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => Shortcode::TAXONOMY,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);

		$items = array();
		if ( is_array( $terms ) ) {
			$term_ids = array();
			foreach ( $terms as $term ) {
				if ( $term instanceof WP_Term && (int) $term->term_id > 0 ) {
					$term_ids[] = (int) $term->term_id;
				}
			}
			if ( array() !== $term_ids && function_exists( 'update_termmeta_cache' ) ) {
				update_termmeta_cache( $term_ids );
			}

			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$thumb_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
				if ( ! $thumb_id ) {
					continue;
				}
				// Size `full` a propósito: los sizes registrados (medium, etc.)
				// pasan por Cloudinary como w,h,c_fill,g_auto → re-crop cuadrado
				// que zoomea los wordmarks. `full` recibe solo las transforms
				// globales (e_trim/f_auto/q_auto), que normalizan el aire del
				// archivo sin recortar el logo. El tamaño visual lo gobierna el
				// CSS del theme (object-fit contain + caps).
				$src = wp_get_attachment_image_src( $thumb_id, 'full' );
				if ( ! is_array( $src ) || empty( $src[0] ) ) {
					continue;
				}
				$link = get_term_link( $term );

				// Resolución de región delegada a BrandRegion (single source of
				// truth). El template recibe badge_text + badge_color directos
				// para no duplicar lógica de mapeo país→color.
				$region      = BrandRegion::for_term( $term );
				$region_data = BrandRegion::region( $region );

				$items[] = array(
					'name'         => BrandRegion::display_name( $term, $region ),
					'slug'         => (string) $term->slug,
					'url'          => is_wp_error( $link ) ? '' : (string) $link,
					'img'          => (string) $src[0],
					'img_w'        => (int) $src[1],
					'img_h'        => (int) $src[2],
					'count'        => (int) $term->count,
					'featured'     => BrandFeatured::is_featured( $term->term_id ),
					'region'       => $region,                                 // 'argentina'|'espana'|'otros'|...
					'badge_text'   => (string) ( $region_data['badge_text']  ?? '' ),
					'badge_color'  => (string) ( $region_data['badge_color'] ?? '' ),
					'badge_label'  => (string) ( $region_data['label']       ?? '' ),
				);
			}
		}

		// Las destacadas (term meta) van primero, conservando el orden por catálogo
		// dentro de cada grupo; recién después se capa al top-N. Así una editorial
		// nueva/estratégica con poco catálogo entra a la vitrina si se la marca.
		$items = self::order_featured_first( $items );

		// Cap a las top-N DESPUÉS del filtro por thumbnail y del orden destacadas-
		// primero, para garantizar hasta N marcas CON logo en la vitrina.
		/**
		 * Filtra el tope de editoriales del carrusel del home.
		 *
		 * @since 1.99.0
		 *
		 * @param int $max Tope actual.
		 */
		$max = (int) apply_filters( 'akibara_home_carousel_max_brands', self::HOME_MAX_BRANDS );
		if ( $max > 0 ) {
			$items = array_slice( $items, 0, $max );
		}

		set_transient( self::CACHE_KEY, $items, self::CACHE_TTL );
		return $items;
	}

	/**
	 * Reordena las marcas poniendo las destacadas (`$item['featured'] === true`)
	 * primero y preservando el orden previo (por catálogo) dentro de cada grupo.
	 * usort es estable en PHP 8.0+. Pura y estática para poder testearla aislada.
	 *
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public static function order_featured_first( array $items ): array {
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$fa = ! empty( $a['featured'] );
				$fb = ! empty( $b['featured'] );
				if ( $fa === $fb ) {
					return 0;
				}
				return $fa ? -1 : 1;
			}
		);
		return $items;
	}
}
