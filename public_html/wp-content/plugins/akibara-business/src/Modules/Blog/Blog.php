<?php
/**
 * Blog — auto-linking de series mencionadas en el contenido de los posts.
 *
 * El matcheo de series (get_terms + lógica sobre the_content) vivía en el theme
 * (`inc/blog.php`), violando la regla theme=solo-UI. Movido acá; además se
 * corrige la inyección de backreference de `preg_replace` (un nombre de serie
 * con `$`/`\` corrompía el replacement) usando `preg_replace_callback`, y se
 * invalida el cache del mapa al cambiar los términos `pa_serie`.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Blog;

use Akibara\Business\Modules\SeriesDirectory\SeriesIndex;

defined( 'ABSPATH' ) || exit;

final class Blog {

	/** Transient con el mapa nombre-de-serie → URL (24 h). */
	private const SERIES_MAP_CACHE = 'akb_blog_series_map';

	/** Largo mínimo del nombre de serie para auto-linkear. */
	private const MIN_NAME_LEN = 4;

	/**
	 * Series cuyo nombre también es una palabra corriente en español. Para
	 * estas exige la caja exacta del término, evitando enlazar usos comunes
	 * como saga al cómic Saga. Las demás series admiten coincidencia sin caja.
	 * Se amplía al incorporar otra ambigüedad; los valores se comparan en minúsculas.
	 *
	 * @var array<int, string>
	 */
	private const CASE_SENSITIVE_NAMES = array( 'saga' );

	/** Conjunto de series publicables; la misma autoridad que el directorio. */
	private SeriesIndex $series_index;

	public function __construct( ?SeriesIndex $series_index = null ) {
		$this->series_index = $series_index ?? new SeriesIndex();
	}

	public function register(): void {
		add_filter( 'the_content', array( $this, 'auto_product_links' ), 20 );
		add_action( 'created_pa_serie', array( $this, 'flush_series_map' ) );
		add_action( 'edited_pa_serie', array( $this, 'flush_series_map' ) );
		add_action( 'delete_pa_serie', array( $this, 'flush_series_map' ) );
		// El mapa se arma con el CATÁLOGO de cada serie, no sólo con el término:
		// publicar el primer tomo de una serie —o sacarle el último— cambia el
		// conjunto sin tocar la taxonomía. Sin estos dos hooks el blog enlazaba
		// hasta 24 h a una serie que ya no tenía nada que enseñar, o dejaba de
		// enlazar una recién estrenada.
		add_action( 'set_object_terms', array( $this, 'flush_series_map_for_object' ), 10, 6 );
		add_action( 'woocommerce_update_product', array( $this, 'flush_series_map' ) );
		add_action( 'woocommerce_new_product', array( $this, 'flush_series_map' ) );
	}

	/**
	 * Purga el mapa sólo cuando cambian las series de un producto.
	 *
	 * `set_object_terms` dispara para TODA taxonomía y todo tipo de post; sin
	 * este filtro, editar las categorías de una entrada del blog tiraría el mapa.
	 *
	 * @internal Callback de `set_object_terms`.
	 *
	 * @param mixed $object_id  Objeto afectado.
	 * @param mixed $terms      Términos enviados.
	 * @param mixed $tt_ids     IDs de term_taxonomy resultantes.
	 * @param mixed $taxonomy   Taxonomía afectada.
	 * @param mixed $append     Si se añadieron sin reemplazar.
	 * @param mixed $old_tt_ids IDs previos.
	 */
	public function flush_series_map_for_object( $object_id, $terms = null, $tt_ids = null, $taxonomy = '', $append = false, $old_tt_ids = null ): void {
		unset( $object_id, $terms, $append );

		if ( SeriesIndex::TAXONOMY !== (string) $taxonomy ) {
			return;
		}

		$nuevos = is_array( $tt_ids ) ? array_map( 'intval', $tt_ids ) : array();
		$viejos = is_array( $old_tt_ids ) ? array_map( 'intval', $old_tt_ids ) : array();
		sort( $nuevos );
		sort( $viejos );
		if ( $nuevos === $viejos ) {
			return;
		}

		$this->flush_series_map();
	}

	/**
	 * Linkea la primera mención de cada serie en el contenido de un post de blog,
	 * sin tocar texto dentro de `<a>` o de headings.
	 */
	public function auto_product_links( string $content ): string {
		if ( ! is_single() || 'post' !== get_post_type() ) {
			return $content;
		}

		$series_map = $this->series_map();
		if ( empty( $series_map ) ) {
			return $content;
		}

		$parts = preg_split( '/(<[^>]+>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $content;
		}

		// Una sola pasada por texto original: un nombre corto nunca debe volver
		// a buscarse dentro del href o título generado para una edición larga.
		$patterns = array();
		$names    = array();
		foreach ( $series_map as $serie => $url ) {
			$name       = (string) $serie;
			$normalized = mb_strtolower( $name );
			$quoted     = preg_quote( $name, '/' );
			$patterns[] = in_array( $normalized, self::CASE_SENSITIVE_NAMES, true ) ? $quoted : '(?i:' . $quoted . ')';
			$names[ $normalized ] = $name;
		}
		$pattern = '/\b(?:' . implode( '|', $patterns ) . ')\b/u';

		$linked     = array();
		$in_a       = 0;
		$in_heading = 0;

		foreach ( $parts as &$part ) {
			if ( preg_match( '/<a[\s>]/i', $part ) ) {
				$in_a++;
				continue;
			}
			if ( preg_match( '/<\/a>/i', $part ) ) {
				$in_a = max( 0, $in_a - 1 );
				continue;
			}
			if ( preg_match( '/<h[1-6][\s>]/i', $part ) ) {
				$in_heading++;
				continue;
			}
			if ( preg_match( '/<\/h[1-6]>/i', $part ) ) {
				$in_heading = max( 0, $in_heading - 1 );
				continue;
			}

			if ( '' === $part || '<' === $part[0] || $in_a > 0 || $in_heading > 0 ) {
				continue;
			}

			$new = preg_replace_callback(
				$pattern,
				static function ( array $match ) use ( $series_map, $names, &$linked ): string {
					$serie = $names[ mb_strtolower( $match[0] ) ] ?? null;
					if ( null === $serie || isset( $linked[ $serie ] ) ) {
						return $match[0];
					}

					$linked[ $serie ] = true;
					return '<a href="' . esc_url( $series_map[ $serie ] ) . '" class="blog-product-link" title="Ver ' . esc_attr( $serie ) . ' en Akibara">' . $match[0] . '</a>';
				},
				$part
			);
			if ( null !== $new ) {
				$part = $new;
			}
		}
		unset( $part );

		return implode( '', $parts );
	}

	public function flush_series_map(): void {
		delete_transient( self::SERIES_MAP_CACHE );
	}

	/**
	 * Mapa de nombres de serie a URL canónica, ordenado por longitud descendente
	 * para priorizar títulos más específicos. SeriesIndex define el catálogo
	 * público y excluye noindex; también resuelve get_term_link para respetar
	 * jerarquía y estructura de enlaces de la taxonomía.
	 *
	 * @return array<string, string>
	 */
	private function series_map(): array {
		$map = get_transient( self::SERIES_MAP_CACHE );
		if ( false !== $map ) {
			return is_array( $map ) ? $map : array();
		}

		$map = array();
		foreach ( $this->series_index->entries() as $entry ) {
			if ( strlen( (string) $entry['name'] ) < self::MIN_NAME_LEN ) {
				continue;
			}
			$map[ (string) $entry['name'] ] = (string) $entry['url'];
		}

		uksort( $map, static fn ( $a, $b ): int => strlen( (string) $b ) - strlen( (string) $a ) );

		// Una lectura degradada devuelve el conjunto vacío igual que un catálogo
		// realmente vacío. Cachear ese vacío 24 h dejaría el blog sin un solo
		// enlace interno por un fallo de un segundo.
		if ( array() !== $map || $this->series_index->is_reliable() ) {
			set_transient( self::SERIES_MAP_CACHE, $map, DAY_IN_SECONDS );
		}

		return $map;
	}
}
