<?php
/**
 * SEO social metadata compatibility.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoSocial;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps Open Graph page types aligned with the visible WooCommerce template.
 */
final class SeoSocial {

	/**
	 * Registers Rank Math social metadata filters.
	 */
	public function register(): void {
		add_filter( 'rank_math/opengraph/type', array( $this, 'handle_open_graph_type' ) );
		add_filter( 'rank_math/opengraph/facebook/og_locale', array( $this, 'handle_facebook_locale' ) );

		// Los meta sociales cambian juntos URL y tipo MIME de la variante compatible.
		// No declarar image/avif cuando la URL social apunta a una imagen JPG.

		foreach ( array( 'facebook', 'twitter' ) as $network ) {
			add_filter( "rank_math/opengraph/{$network}/image_array", array( $this, 'prefer_series_cover_image' ), 10 );
			add_filter( "rank_math/opengraph/{$network}/image_array", array( $this, 'prefer_jpeg_social_image' ), 20 );
		}
	}

	/**
	 * En landings de serie, la imagen social es la portada del primer tomo.
	 *
	 * Las 106 landings compartían un banner genérico idéntico: se pierde la
	 * portada como gancho visual justo en las páginas palanca. El primer
	 * producto por título ASC es el tomo 01 por la convención del pipeline
	 * (todo título termina en nº de tomo). Corre ANTES del swap AVIF→JPG
	 * (prioridad 10 vs 20) para que la portada elegida también gane su
	 * variante JPG.
	 *
	 * @param mixed $attachment Imagen que Rank Math va a emitir.
	 * @return mixed
	 *
	 * @since 1.106.0
	 */
	public function prefer_series_cover_image( $attachment ) {
		try {
			if ( ! is_array( $attachment ) || ! function_exists( 'is_tax' ) || ! is_tax( 'pa_serie' ) ) {
				return $attachment;
			}

			$term = get_queried_object();
			if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
				return $attachment;
			}

			$witnesses = get_posts(
				array(
					'post_type'     => 'product',
					'post_status'   => 'publish',
					'numberposts'   => 1,
					'orderby'       => 'title',
					'order'         => 'ASC',
					'fields'        => 'ids',
					'no_found_rows' => true,
					'tax_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Un solo testigo por landing de serie; la página se cachea.
						array(
							'taxonomy' => 'pa_serie',
							'field'    => 'term_id',
							'terms'    => (int) $term->term_id,
						),
					),
				)
			);
			if ( ! is_array( $witnesses ) || array() === $witnesses ) {
				return $attachment;
			}

			$thumbnail_id = (int) get_post_thumbnail_id( (int) $witnesses[0] );
			$url          = $thumbnail_id > 0 ? wp_get_attachment_image_url( $thumbnail_id, 'full' ) : false;
			if ( ! is_string( $url ) || '' === $url ) {
				return $attachment;
			}

			return array( 'url' => $url );
		} catch ( \Throwable $throwable ) {
			return $attachment;
		}
	}

	/**
	 * Cambia un og:image AVIF de /portadas/ por su hermana JPG si existe.
	 *
	 * La variante la produce `SocialImageDerivatives`; sin hermana física se
	 * conserva el AVIF (mejor un preview posiblemente roto que un 404 seguro).
	 *
	 * @param mixed $attachment Imagen que Rank Math va a emitir.
	 * @return mixed
	 *
	 * @since 1.106.0
	 */
	public function prefer_jpeg_social_image( $attachment ) {
		try {
			if ( ! is_array( $attachment ) || ! isset( $attachment['url'] ) || ! is_string( $attachment['url'] ) ) {
				return $attachment;
			}
			$url = $attachment['url'];
			// Aceptar originales y variantes de tamaño que elija Rank Math.
			// Descartar el sufijo de tamaño para generar la derivada social del original.

			if ( 1 !== preg_match( '#/uploads/portadas/([a-z0-9-]+?)(?:-\d+x\d+)?\.avif$#', $url, $matches ) ) {
				return $attachment;
			}

			// ensure_for_slug() no convierte durante wp_head. Usa una JPG existente y
			// ENCOLA su actualización si cambió la fuente. Sin variante, ENCOLA el trabajo
			// y conserva la URL de origen para esta visita.

			if ( ! SocialImageDerivatives::ensure_for_slug( $matches[1] ) ) {
				return $attachment;
			}

			$attachment['url']  = preg_replace(
				'#/uploads/portadas/[a-z0-9-]+?(?:-\d+x\d+)?\.avif$#',
				'/uploads/portadas/' . $matches[1] . SocialImageDerivatives::SUFFIX,
				$url
			);
			$attachment['type'] = 'image/jpeg';
			// Publicar las dimensiones reales de la hermana JPG servida, no las del AVIF.
			// La variante social limita su lado mayor a1200px.

			$medidas = self::image_dimensions( SocialImageDerivatives::portadas_dir() . '/' . $matches[1] . SocialImageDerivatives::SUFFIX );
			if ( null === $medidas ) {
				unset( $attachment['width'], $attachment['height'] );
			} else {
				$attachment['width']  = $medidas[0];
				$attachment['height'] = $medidas[1];
			}

			return $attachment;
		} catch ( \Throwable $throwable ) {
			return $attachment;
		}
	}

	/**
	 * Ancho y alto de un fichero de imagen local, o null si no se puede leer.
	 *
	 * @param string $path Ruta física.
	 * @return array{0:int,1:int}|null
	 */
	private static function image_dimensions( string $path ): ?array {
		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}
		$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $path ) : @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- sin WordPress (suite) el fallback nativo avisa en ficheros raros; se prefiere null.
		if ( ! is_array( $size ) || (int) ( $size[0] ?? 0 ) < 1 || (int) ( $size[1] ?? 0 ) < 1 ) {
			return null;
		}

		return array( (int) $size[0], (int) $size[1] );
	}

	/**
	 * Represents shop and product taxonomy archives as website collections.
	 *
	 * @internal
	 *
	 * @param string $type Open Graph type selected by Rank Math.
	 * @return string
	 */
	public function handle_open_graph_type( string $type ): string {
		return is_shop() || is_product_taxonomy() ? 'website' : $type;
	}

	/**
	 * Uses Rank Math's closest supported locale for a Chilean storefront.
	 *
	 * Facebook does not provide `es_CL`; `es_LA` is the appropriate supported
	 * regional value while WordPress keeps `es_ES` for plugin translations.
	 */
	public function handle_facebook_locale( string $locale ): string {
		return 'es_ES' === $locale ? 'es_LA' : $locale;
	}
}
