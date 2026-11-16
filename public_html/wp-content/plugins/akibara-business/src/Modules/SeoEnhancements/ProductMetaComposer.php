<?php
/**
 * ProductMetaComposer — Composición de metadatos SEO enriquecidos para fichas de producto.
 *
 * Genera títulos y descripciones SEO que combinan título, serie, tomo, autor, editorial,
 * medio y estado de preventa/a pedido, garantizando unicidad en SERP chilena.
 *
 * @package Akibara\Business\Modules\SeoEnhancements
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoEnhancements;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 */
final class ProductMetaComposer {

	/**
	 * Memo por request del meta compuesto de la ficha servida.
	 *
	 * @var array<int,array{title:string,description:string}|null>
	 */
	private array $product_meta_memo = array();

	/**
	 * Título rico para fichas de producto sin `rank_math_title` propio.
	 *
	 * @param string $title Título original.
	 * @return string
	 */
	public function prefer_product_title( string $title ): string {
		if ( ! function_exists( 'is_singular' ) || is_admin() || ! is_singular( 'product' ) ) {
			return $title;
		}

		$post_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		if ( $post_id < 1 ) {
			return $title;
		}

		$custom_title = get_post_meta( $post_id, 'rank_math_title', true );
		if ( '' !== trim( (string) $custom_title ) ) {
			return $title;
		}

		$meta = $this->generated_product_meta( $post_id );

		return ( null !== $meta && '' !== $meta['title'] ) ? $meta['title'] : $title;
	}

	/**
	 * Compone (una vez por request) el meta rico de la ficha servida.
	 *
	 * @param int $post_id ID del producto.
	 * @return array{title:string,description:string}|null
	 */
	public function generated_product_meta( int $post_id ): ?array {
		if ( $post_id < 1 ) {
			return null;
		}
		if ( array_key_exists( $post_id, $this->product_meta_memo ) ) {
			return $this->product_meta_memo[ $post_id ];
		}

		$meta = null;
		try {
			$medium = $this->product_medium( $post_id );
			$post   = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
			$name   = is_object( $post ) && isset( $post->post_title ) ? (string) $post->post_title : ''; // @phpstan-ignore isset.property
			if ( '' !== $medium && '' !== $name ) {
				$composed = self::compose_product_meta(
					$name,
					self::single_term_name( $post_id, 'pa_serie', true ),
					self::single_term_name( $post_id, 'pa_autor' ),
					self::single_term_name( $post_id, 'product_brand' ),
					$medium,
					VolumeTitle::extract_number( $name )
				);
				if ( '' !== $composed['title'] || '' !== $composed['description'] ) {
					$meta = $composed;
				}
			}
		} catch ( \Throwable $throwable ) {
			$meta = null;
		}

		$this->product_meta_memo[ $post_id ] = $meta;

		return $meta;
	}

	/**
	 * Sustantivo del medio para un producto individual desde sus categorías.
	 *
	 * @param int $product_id ID del producto.
	 * @return string 'manga' | 'cómic' | 'manhwa' | ''.
	 */
	public function product_medium( int $product_id ): string {
		try {
			if ( $product_id < 1 || ! function_exists( 'get_the_terms' ) ) {
				return '';
			}
			$categories = get_the_terms( $product_id, 'product_cat' );

			return is_array( $categories ) ? SeriesMetaComposer::medium_from_categories( $categories ) : '';
		} catch ( \Throwable $throwable ) {
			return '';
		}
	}

	/**
	 * Nombre del término asignado, solo cuando el dato es inequívoco.
	 *
	 * @param int    $post_id     ID del post.
	 * @param string $taxonomy    Taxonomía a leer.
	 * @param bool   $allow_first Si permite tomar el primero de varios.
	 * @return string
	 */
	public static function single_term_name( int $post_id, string $taxonomy, bool $allow_first = false ): string {
		if ( ! function_exists( 'get_the_terms' ) ) {
			return '';
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return '';
		}
		if ( ! $allow_first && 1 !== count( $terms ) ) {
			return '';
		}
		$first = reset( $terms );

		return is_object( $first ) && isset( $first->name ) ? trim( (string) $first->name ) : ''; // @phpstan-ignore isset.property
	}

	/**
	 * Compone título y descripción ricos de una ficha de producto.
	 *
	 * @param string   $product_title Título del producto.
	 * @param string   $serie_name    Nombre de la serie.
	 * @param string   $author        Autor único.
	 * @param string   $brand         Editorial única.
	 * @param string   $medium        'manga' | 'cómic' | 'manhwa'.
	 * @param int|null $volume        Nº de tomo extraído.
	 * @return array{title:string,description:string}
	 */
	public static function compose_product_meta(
		string $product_title,
		string $serie_name,
		string $author,
		string $brand,
		string $medium,
		?int $volume
	): array {
		$name = trim( wp_strip_all_tags( $product_title, true ) );
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = (string) preg_replace( '/\s+/u', ' ', $name );
		$name = trim( $name );

		$empty = array(
			'title'       => '',
			'description' => '',
		);
		if ( '' === $name || ! in_array( $medium, array( 'manga', 'cómic', 'manhwa' ), true ) ) {
			return $empty;
		}

		$title_candidates = array();
		if ( '' !== $brand ) {
			$title_candidates[] = sprintf( '%s · %s · %s en español | Akibara', $name, $brand, $medium );
		}
		$title_candidates = array_merge(
			$title_candidates,
			array(
				sprintf( '%s %s en español | Akibara Chile', $name, $medium ),
				sprintf( '%s %s en español | Akibara', $name, $medium ),
				sprintf( '%s %s | Akibara Chile', $name, $medium ),
				sprintf( '%s | Comprar en Chile - Akibara', $name ),
				sprintf( '%s | Akibara', $name ),
			)
		);

		$title = '';
		foreach ( $title_candidates as $candidate ) {
			if ( Text::length( $candidate ) <= 62 ) {
				$title = $candidate;
				break;
			}
		}

		$serie_clause = ( '' !== $serie_name && null !== $volume )
			? sprintf( 'Tomo %d de la serie %s.', $volume, $serie_name )
			: '';
		$close       = 'Cómpralo en Akibara con despacho a todo Chile.';
		$short_close = 'Despacho a todo Chile desde Akibara.';

		$description_candidates = array();
		if ( '' !== $author && '' !== $brand ) {
			$description_candidates[] = trim( sprintf( '%s de %s, edición %s en español. %s %s', $name, $author, $brand, $serie_clause, $close ) );
			$description_candidates[] = trim( sprintf( '%s de %s, edición %s en español. %s', $name, $author, $brand, $close ) );
		}
		if ( '' !== $brand ) {
			$description_candidates[] = trim( sprintf( '%s, edición %s en español. %s %s', $name, $brand, $serie_clause, $close ) );
			$description_candidates[] = trim( sprintf( '%s, edición %s en español. %s', $name, $brand, $close ) );
		}
		if ( '' !== $author ) {
			$description_candidates[] = trim( sprintf( '%s de %s en español. %s %s', $name, $author, $serie_clause, $close ) );
		}
		$description_candidates[] = trim( sprintf( '%s %s en español. %s %s', $name, $medium, $serie_clause, $close ) );
		$description_candidates[] = trim( sprintf( '%s %s en español. %s', $name, $medium, $close ) );
		$description_candidates[] = trim( sprintf( '%s %s en español. %s', $name, $medium, $short_close ) );

		$description = '';
		foreach ( $description_candidates as $candidate ) {
			$candidate = trim( (string) preg_replace( '/\s+/u', ' ', $candidate ) );
			if ( Text::length( $candidate ) <= 155 ) {
				$description = $candidate;
				break;
			}
		}

		return array(
			'title'       => $title,
			'description' => $description,
		);
	}

	/**
	 * Limpia la plantilla expandida y acorta solo los resultados sobre 160.
	 *
	 * @param string $description Descripción cruda.
	 * @return string
	 */
	public static function sanitize_product_meta_description( string $description ): string {
		$description = trim( wp_strip_all_tags( $description, true ) );
		$description = preg_replace( '/\s+/u', ' ', $description );
		$description = is_string( $description ) ? trim( $description ) : '';

		if ( Text::length( $description ) <= 160 ) {
			return $description;
		}

		$cut       = Text::substr( $description, 0, 154 );
		$word_safe = preg_replace( '/\s+\S*$/u', '', $cut );
		if ( is_string( $word_safe ) && Text::length( trim( $word_safe ) ) >= 100 ) {
			$cut = $word_safe;
		}

		return rtrim( trim( $cut ), " \t\n\r\0\x0B,;:-" ) . '…';
	}
}
