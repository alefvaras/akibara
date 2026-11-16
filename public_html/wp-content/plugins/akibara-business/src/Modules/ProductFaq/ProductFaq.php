<?php
/**
 * Compone los datos de preguntas frecuentes de producto.
 *
 * La lógica combina datos de producto, taxonomías, número de tomos y estado
 * comercial. El tema presenta el acordeón mediante akibara/product_faq_data;
 * no decide esas reglas. El acordeón visible se conserva sin inyectar un nodo
 * FAQPage en el JSON-LD.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\ProductFaq;

use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\CheckoutTrustSignals;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookNotFound
 * @psalm-suppress InvalidArgument
 * @psalm-suppress UnusedParam
 * @psalm-suppress UnusedVariable
 * @psalm-suppress UnusedParam
 */
final class ProductFaq {

	private const MANGA_CATEGORY_SLUG = 'manga';

	private const COMIC_CATEGORY_SLUG = 'comics';

	public function register(): void {
		add_filter( 'akibara/product_faq_data', array( $this, 'get_faq_data' ), 10, 2 );
	}

	/**
	 * Arma las FAQs de un producto. Cache por-request keyed por product ID.
	 *
	 * @param mixed           $fallback Valor por defecto del filtro (se ignora).
	 * @param WC_Product|null $product  Producto; si null, el del loop actual.
	 * @return array<int, array{q:string, a:string}>
	 * @psalm-suppress UnusedParam
	 */
	public function get_faq_data( $fallback = array(), ?WC_Product $product = null ): array {
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		static $cache = array();
		$pid = $product->get_id();
		if ( isset( $cache[ $pid ] ) ) {
			return $cache[ $pid ];
		}

		$name = $product->get_name();

		// La serie procede canónicamente de pa_serie y el número del título mediante VolumeTitle.
		$serie      = '';
		$serie_term = null;
		$serie_terms = get_the_terms( $pid, 'pa_serie' );
		if ( $serie_terms && ! is_wp_error( $serie_terms ) ) {
			$serie_term = $serie_terms[0];
			$serie      = (string) $serie_term->name;
		}

		$brands = get_the_terms( $pid, 'product_brand' );
		$brand  = ( $brands && ! is_wp_error( $brands ) ) ? $brands[0]->name : '';

		$authors = get_the_terms( $pid, 'pa_autor' );
		$author  = ( $authors && ! is_wp_error( $authors ) ) ? $authors[0]->name : '';

		$genres    = get_the_terms( $pid, 'pa_genero' );
		$genre_str = ( $genres && ! is_wp_error( $genres ) )
			? implode( ', ', wp_list_pluck( $genres, 'name' ) )
			: '';
		$publication_noun = $this->publication_noun( $pid );

		$in_stock   = StockStatus::is_instock( $product );
		$stock_text = $in_stock ? 'disponible en stock' : 'actualmente agotado';
		$serie_or_name = $serie ?: $name;

		$faqs = array();

		// Las preguntas complementan los datos visibles: compra, despacho, autor,
		// género y edición original. No repiten precio, tomo o editorial ya presentados
		// junto al producto ni introducen importes volátiles en una FAQ.

		$faqs[] = array(
			'q' => '¿Cómo comprar ' . $name . ' en Chile?',
			'a' => $name . ' está ' . $stock_text . ' en Akibara. Despacho a todo Chile. Puedes pagar en hasta ' . CheckoutTrustSignals::installments_copy() . '.',
		);

		// 4. Autor.
		if ( $author ) {
			$author_term = get_term_by( 'name', $author, 'pa_autor' );
			$author_link = $author_term ? '<a href="' . esc_url( get_term_link( $author_term ) ) . '">' . esc_html( $author ) . '</a>' : $author;

			$faqs[] = array(
				'q' => '¿Quién es el autor de ' . $serie_or_name . '?',
				'a' => $serie_or_name . ' fue creado por ' . $author_link . '. Encuentra más obras de ' . $author . ' en Akibara Chile.',
			);
		}

		// 5. Género.
		if ( $genre_str ) {
			$genre_answer = null !== $publication_noun
				? $serie_or_name . ' es un ' . $publication_noun . ' de ' . $genre_str . '. Explora más títulos de estos géneros en Akibara.'
				: $serie_or_name . ' pertenece a los géneros ' . $genre_str . '. Explora más títulos de estos géneros en Akibara.';

			$faqs[] = array(
				'q' => '¿De qué género es ' . $serie_or_name . '?',
				'a' => $genre_answer,
			);
		}

		// 6. ¿Es original?
		if ( $brand ) {
			$faqs[] = array(
				'q' => '¿' . $name . ' es original?',
				'a' => 'Sí. Es una edición original publicada por ' . $brand . ' y vendida por Akibara.',
			);
		}

		$cache[ $pid ] = $faqs;
		return $faqs;
	}

	/**
	 * Devuelve el sustantivo editorial sólo cuando la categoría es inequívoca.
	 *
	 * Un producto puede pertenecer simultáneamente a ramas distintas o no tener
	 * como categoría ni ancestro las ramas canónicas `manga` o `comics`. En esos
	 * casos la FAQ usa una frase neutral en vez de inventar el tipo editorial.
	 */
	private function publication_noun( int $product_id ): ?string {
		$categories = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $categories ) ) {
			return null;
		}

		$slugs = array();
		foreach ( $categories as $category ) {
			if ( ! is_object( $category ) ) {
				continue;
			}

			if ( isset( $category->slug ) && is_string( $category->slug ) ) { // @phpstan-ignore isset.property
				$slugs[] = $category->slug;
			}

			$term_id = isset( $category->term_id ) ? (int) $category->term_id : 0; // @phpstan-ignore isset.property
			if ( $term_id < 1 ) {
				continue;
			}

			foreach ( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
				$ancestor = get_term( (int) $ancestor_id, 'product_cat' );
				if ( is_object( $ancestor ) && ! is_wp_error( $ancestor ) && isset( $ancestor->slug ) && is_string( $ancestor->slug ) ) { // @phpstan-ignore isset.property
					$slugs[] = $ancestor->slug;
				}
			}
		}

		$is_manga = in_array( self::MANGA_CATEGORY_SLUG, $slugs, true );
		$is_comic = in_array( self::COMIC_CATEGORY_SLUG, $slugs, true );

		if ( $is_manga === $is_comic ) {
			return null;
		}

		return $is_manga ? 'manga' : 'cómic';
	}
}
