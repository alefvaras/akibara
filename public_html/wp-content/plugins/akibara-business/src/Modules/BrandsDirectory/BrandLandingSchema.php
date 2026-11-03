<?php
/**
 * Declara la landing paraguas como CollectionPage con ItemList de las
 * ediciones que muestra. Adjunta al grafo Rank Math sin sustituirlo.
 * No enumera todo el catálogo ni emite Product, Offer, precios o disponibilidad.
 * El schema representa contenido visible y no garantiza resultados enriquecidos.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use Akibara\Business\Utilities\SchemaGraph;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class BrandLandingSchema {

	public function register(): void {
		add_filter( 'rank_math/json_ld', array( $this, 'handle_json_ld' ), SchemaGraph::PAGE_ATTACHMENTS, 2 );
	}

	/**
	 * Adjunta CollectionPage + ItemList cuando la página actual es una landing.
	 *
	 * @internal Callback de `rank_math/json_ld`.
	 *
	 * @param mixed $data   Grafo emitido por Rank Math.
	 * @param mixed $jsonld Instancia interna de Rank Math, no usada.
	 * @return mixed
	 */
	public function handle_json_ld( $data, $jsonld = null ) {
		unset( $jsonld );

		if ( ! is_array( $data ) ) {
			return $data;
		}

		$view = $this->resolve_view_model();
		if ( null === $view ) {
			return $data;
		}

		$list_id = $view['url'] . '#ediciones';
		$items   = array();
		foreach ( $view['editions'] as $position => $edition ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position + 1,
				'item'     => array(
					'@type' => 'CollectionPage',
					'@id'   => $edition['url'] . '#webpage',
					'name'  => $edition['name'],
					'url'   => $edition['url'],
				),
			);
		}

		$item_list = array(
			'@type'           => 'ItemList',
			'@id'             => $list_id,
			'name'            => sprintf( 'Ediciones de %s en Akibara', $view['label'] ),
			'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);

		// Reutilizar el nodo de página que Rank Math ya emitió en vez de añadir
		// un segundo WebPage con otra @id: dos nodos compitiendo por describir la
		// misma URL es justo lo que ensucia el grafo.
		$page_key = null;
		$list_key = null;
		foreach ( $data as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( SchemaGraph::node_has_type( $node, 'ItemList' ) && (string) ( $node['@id'] ?? '' ) === $list_id ) {
				$list_key = $key;
			}
			if ( SchemaGraph::node_has_type( $node, 'CollectionPage' ) || SchemaGraph::node_has_type( $node, 'WebPage' ) ) {
				$page_key = $key;
			}
		}

		$collection_page = ( null !== $page_key && is_array( $data[ $page_key ] ) )
			? $data[ $page_key ]
			: array();

		$collection_page['@type']      = 'CollectionPage';
		$collection_page['@id']        = $view['url'] . '#webpage';
		$collection_page['url']        = $view['url'];
		$collection_page['name']       = $view['title'];
		$collection_page['mainEntity'] = array( '@id' => $list_id );

		$data[ $page_key ?? 'BrandLandingCollectionPage' ] = $collection_page;
		$data[ $list_key ?? 'BrandLandingItemList' ]       = $item_list;

		return $data;
	}

	/**
	 * Datos del schema, o null si la página actual no es una landing paraguas
	 * con al menos una edición resoluble.
	 *
	 * @return array{url:string,title:string,label:string,editions:list<array{name:string,url:string}>}|null
	 */
	private function resolve_view_model(): ?array {
		if ( is_admin() || ! is_singular( 'page' ) ) {
			return null;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$landing = BrandLandings::for_page_slug( (string) $post->post_name );
		if ( null === $landing ) {
			return null;
		}

		$url = get_permalink( $post );
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return null;
		}
		if ( function_exists( 'user_trailingslashit' ) ) {
			$url = user_trailingslashit( $url );
		}

		$editions = array();
		foreach ( (array) ( $landing['brands'] ?? array() ) as $brand_slug ) {
			$term = get_term_by( 'slug', (string) $brand_slug, Shortcode::TAXONOMY );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$link = get_term_link( $term );
			if ( ! is_string( $link ) || '' === trim( $link ) ) {
				continue;
			}

			$editions[] = array(
				'name' => (string) $term->name,
				'url'  => $link,
			);
		}

		// Sin ediciones resolubles no se emite nada: un ItemList vacío describe
		// peor que la ausencia de ItemList.
		if ( empty( $editions ) ) {
			return null;
		}

		$title = get_the_title( $post );
		$title = is_string( $title ) ? trim( wp_strip_all_tags( $title, true ) ) : '';

		return array(
			'url'      => $url,
			'title'    => '' !== $title ? $title : (string) $landing['callout_label'],
			'label'    => (string) $landing['callout_label'],
			'editions' => $editions,
		);
	}

	/**
	 * `@type` puede venir como cadena o como lista.
	 *
	 * @param array<string,mixed> $node Nodo del grafo.
	 */
}
