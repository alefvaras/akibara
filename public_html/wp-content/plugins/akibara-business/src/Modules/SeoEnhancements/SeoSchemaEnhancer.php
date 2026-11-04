<?php
/**
 * SeoSchemaEnhancer — Alineación y saneamiento de grafos Schema JSON-LD.
 *
 * Conecta las superficies de series, migas de pan y páginas de servicio al grafo
 * emitido por Rank Math, asegurando correspondencia factual y eliminando nodos vacíos.
 *
 * @package Akibara\Business\Modules\SeoEnhancements
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoEnhancements;

use Akibara\Business\Modules\SeriesDirectory\SeriesIndex;
use Akibara\Business\Modules\SeriesDirectory\Shortcode as SeriesDirectoryShortcode;
use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Akibara\Business\Services\SeriesHubUrl;
use Akibara\Business\Utilities\SchemaGraph;

defined( 'ABSPATH' ) || exit;

final class SeoSchemaEnhancer {

	/** Taxonomía canónica de series. */
	public const SERIES_TAXONOMY = 'pa_serie';

	/** Nombre público exacto del responsable editorial presentado en /nosotros/. */
	public const EDITOR_PERSON_NAME = 'Alejandro Fernández';

	/** Fragmento estable del nodo Person editorial. */
	public const EDITOR_PERSON_FRAGMENT = 'alejandro-fernandez';

	/** Página pública que describe al responsable editorial. */
	public const ABOUT_PATH = '/nosotros/';

	/** Página factual de opciones de despacho y retiro coordinado. */
	public const SHIPPING_PATH = '/envios/';

	/**
	 * Miga de WooCommerce de la ficha en curso memoizada por request.
	 *
	 * @var array{0:mixed}|null
	 */
	private ?array $product_crumbs_memo = null;

	/**
	 * Índice del directorio de series para ItemList schema.
	 */
	private SeriesIndex $series_index;

	/**
	 * Constructor.
	 *
	 * @param SeriesIndex|null $series_index Instancia opcional para inyección o pruebas.
	 */
	public function __construct( ?SeriesIndex $series_index = null ) {
		$this->series_index = $series_index ?? new SeriesIndex();
	}

	/**
	 * Inserta el nodo intermedio de Series en el BreadcrumbList de Rank Math para landings de serie.
	 *
	 * @param array<string,mixed> $data Grafo emitido por Rank Math.
	 * @return array<string,mixed>
	 */
	public function align_series_breadcrumb_schema( array $data ): array {
		if ( ! function_exists( 'is_tax' ) || is_admin() || ! is_tax( self::SERIES_TAXONOMY ) ) {
			return $data;
		}

		$hub_url = SeriesHubUrl::url();
		if ( function_exists( 'user_trailingslashit' ) ) {
			$hub_url = user_trailingslashit( $hub_url );
		}

		foreach ( $data as $key => $node ) {
			if (
				! is_array( $node )
				|| 'BreadcrumbList' !== ( $node['@type'] ?? '' )
				|| ! isset( $node['itemListElement'] )
				|| ! is_array( $node['itemListElement'] )
			) {
				continue;
			}

			$items   = array_values( $node['itemListElement'] );
			$has_hub = false;
			foreach ( $items as &$item ) {
				$item_data = is_array( $item ) && isset( $item['item'] ) && is_array( $item['item'] )
					? $item['item']
					: array();
				$item_url  = (string) ( $item_data['@id'] ?? '' );
				$item_name = (string) ( $item_data['name'] ?? '' );
				if ( rtrim( $item_url, '/' ) === rtrim( $hub_url, '/' ) || 'Series' === $item_name ) {
					$has_hub = true;
					if ( ! empty( $item_data ) ) {
						$item['item']['@id']  = $hub_url;
						$item['item']['name'] = 'Series';
					}
					break;
				}
			}
			unset( $item );

			if ( ! $has_hub ) {
				array_splice(
					$items,
					min( 1, count( $items ) ),
					0,
					array(
						array(
							'@type'    => 'ListItem',
							'position' => 2,
							'item'     => array(
								'@id'  => $hub_url,
								'name' => 'Series',
							),
						),
					)
				);
			}

			foreach ( $items as $position => &$item ) {
				if ( is_array( $item ) ) {
					$item['position'] = $position + 1;
				}
			}
			unset( $item );

			$node['itemListElement'] = $items;
			$data[ $key ]             = $node;
		}

		return $data;
	}

	/**
	 * Alinea el marcado BreadcrumbList (JSON-LD) con la miga visible en la ficha de producto.
	 *
	 * @param array<string,mixed> $data Grafo emitido por Rank Math.
	 * @return array<string,mixed>
	 */
	public function align_product_breadcrumb_schema( array $data ): array {
		if ( ! function_exists( 'is_singular' ) || is_admin() || ! is_singular( 'product' ) || ! class_exists( '\WC_Breadcrumb' ) ) {
			return $data;
		}

		try {
			if ( null === $this->product_crumbs_memo ) {
				$this->product_crumbs_memo = array( ( new \WC_Breadcrumb() )->generate() );
			}
			$crumbs  = $this->product_crumbs_memo[0];
			$enlaces = array();
			foreach ( is_array( $crumbs ) ? $crumbs : array() as $crumb ) {
				if ( ! is_array( $crumb ) || ! is_scalar( $crumb[0] ?? null ) || ! is_scalar( $crumb[1] ?? null ) ) {
					continue;
				}
				$nombre = trim( (string) $crumb[0] );
				$url    = trim( (string) $crumb[1] );
				if ( '' === $nombre || '' === $url ) {
					continue;
				}
				$enlaces[] = array( $nombre, $url );
			}
		} catch ( \Throwable $throwable ) {
			return $data;
		}

		$middle = array();
		foreach ( array_slice( $enlaces, 0, -1 ) as $crumb ) {
			$middle[] = array(
				'@type' => 'ListItem',
				'item'  => array(
					'@id'  => $crumb[1],
					'name' => $crumb[0],
				),
			);
		}
		if ( array() === $middle ) {
			return $data;
		}

		foreach ( $data as $key => $node ) {
			if (
				! is_array( $node )
				|| ! SchemaGraph::node_has_type( $node, 'BreadcrumbList' )
				|| ! isset( $node['itemListElement'] )
				|| ! is_array( $node['itemListElement'] )
				|| count( $node['itemListElement'] ) < 2
			) {
				continue;
			}

			$items = array_values( $node['itemListElement'] );
			$items = array_merge( array( $items[0] ), $middle, array( end( $items ) ) );
			foreach ( $items as $position => &$item ) {
				if ( is_array( $item ) ) {
					$item['position'] = $position + 1;
				}
			}
			unset( $item );

			$node['itemListElement'] = $items;
			$data[ $key ]             = $node;
		}

		return $data;
	}

	/**
	 * Declara `/envios/` como WebPage factual conectada al grafo de Rank Math.
	 *
	 * @param array<string,mixed> $data Grafo emitido por Rank Math.
	 * @return array<string,mixed>
	 */
	public function add_shipping_page_schema( array $data ): array {
		if ( ! function_exists( 'is_page' ) || ! is_page( trim( self::SHIPPING_PATH, '/' ) ) ) {
			return $data;
		}

		$page_key      = null;
		$breadcrumb_id = '';
		foreach ( $data as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( SchemaGraph::node_has_type( $node, 'WebPage' ) ) {
				$page_key = $key;
			}
			if ( SchemaGraph::node_has_type( $node, 'BreadcrumbList' ) ) {
				$breadcrumb_id = trim( (string) ( $node['@id'] ?? '' ) );
			}
		}

		if ( '' === $breadcrumb_id ) {
			return $data;
		}

		$url      = home_url( self::SHIPPING_PATH );
		$web_page = null !== $page_key && is_array( $data[ $page_key ] )
			? $data[ $page_key ]
			: array();

		$web_page['@type']       = 'WebPage';
		$web_page['@id']         = $url . '#webpage';
		$web_page['url']         = $url;
		$web_page['name']        = 'Envíos y retiro de pedidos';
		$web_page['description'] = 'Consulta opciones de despacho a todo Chile y retiro coordinado en Metro San Miguel. El costo se muestra antes de pagar.';
		$web_page['isPartOf']    = array( '@id' => home_url( '/#website' ) );
		$web_page['breadcrumb']  = array( '@id' => $breadcrumb_id );

		$data[ $page_key ?? 'ShippingInformationWebPage' ] = $web_page;

		return $data;
	}

	/**
	 * Declara el hub `/series/` como CollectionPage con su ItemList real.
	 *
	 * @param array<string,mixed> $data Grafo emitido por Rank Math.
	 * @return array<string,mixed>
	 */
	public function add_series_hub_schema( array $data ): array {
		$post = $this->current_series_directory_post();
		if ( null === $post || ! isset( $post->ID ) ) {
			return $data;
		}

		$entries = $this->series_index->entries();
		if ( ! $this->series_index->is_reliable() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constante estándar de plugins de caché.
			}
			do_action( 'litespeed_control_set_nocache', 'series catalogue eligibility unavailable' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed.

			return $data;
		}

		$url = get_permalink( (int) $post->ID );
		if ( empty( $entries ) || ! is_string( $url ) || '' === trim( $url ) ) {
			return $data;
		}
		if ( function_exists( 'user_trailingslashit' ) ) {
			$url = user_trailingslashit( $url );
		}

		$title = get_the_title( (int) $post->ID );
		$title = is_string( $title ) ? trim( wp_strip_all_tags( $title, true ) ) : '';
		$title = '' !== $title ? $title : 'Series';

		$list_id = $url . '#series-list';
		$items   = array();
		foreach ( $entries as $position => $entry ) {
			$item = array(
				'@type' => 'Thing',
				'name'  => $entry['name'],
				'url'   => $entry['url'],
			);
			if ( SeriesSchemaType::supports_comic_series( (int) $entry['term_id'] ) ) {
				$item['@type'] = 'ComicSeries';
				$item['@id']   = rtrim( $entry['url'], '#' ) . '#serie';
			}
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position + 1,
				'item'     => $item,
			);
		}
		$item_list = array(
			'@type'           => 'ItemList',
			'@id'             => $list_id,
			'name'            => 'Series de manga y cómics en Akibara',
			'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);

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

		$collection_page = null !== $page_key && is_array( $data[ $page_key ] )
			? $data[ $page_key ]
			: array();
		$collection_page['@type']      = 'CollectionPage';
		$collection_page['@id']        = $url . '#webpage';
		$collection_page['url']        = $url;
		$collection_page['name']       = $title;
		$collection_page['mainEntity'] = array( '@id' => $list_id );

		$data[ $page_key ?? 'SeriesDirectoryCollectionPage' ] = $collection_page;
		$data[ $list_key ?? 'SeriesDirectoryItemList' ]       = $item_list;

		return $data;
	}

	/**
	 * Limpia el @graph global de Rank Math (Place vacío, dirección vacía, referencias de responsable editorial).
	 *
	 * @param array<string,mixed>|mixed $data Grafo JSON-LD.
	 * @return array<string,mixed>|mixed
	 */
	public function cleanup_global_schema( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$home_url = function_exists( 'home_url' )
			? rtrim( (string) home_url( '/' ), '/' ) . '/'
			: 'https://akibara.cl/';
		$about_url        = rtrim( $home_url, '/' ) . self::ABOUT_PATH;
		$editor_person_id = $about_url . '#' . self::EDITOR_PERSON_FRAGMENT;
		$organization_id  = $home_url . '#organization';

		$removed_place_ids = array();
		foreach ( $data as $key => $node ) {
			if ( is_array( $node ) && self::node_is_empty_place( $node ) ) {
				$id = (string) ( $node['@id'] ?? '' );
				if ( '' !== $id ) {
					$removed_place_ids[] = $id;
				}
				unset( $data[ $key ] );
			}
		}

		$id_replacements   = array();
		$has_editor_person = false;
		foreach ( $data as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( isset( $node['location'] ) ) {
				$location    = $node['location'];
				$location_id = is_array( $location ) ? (string) ( $location['@id'] ?? '' ) : '';
				$dangling    = in_array( $location_id, $removed_place_ids, true );
				$inline_hole = is_array( $location ) && self::node_is_empty_place( $location );
				if ( $dangling || $inline_hole ) {
					unset( $data[ $key ]['location'] );
				}
			}

			if ( isset( $node['address'] ) && is_array( $node['address'] ) ) {
				$address_filled = array_filter(
					array_diff_key( $node['address'], array( '@type' => true ) ),
					static function ( $value ) {
						return is_array( $value ) ? array() !== array_filter( $value ) : '' !== trim( (string) $value );
					}
				);
				if ( array() === $address_filled ) {
					unset( $data[ $key ]['address'] );
				}
			}

			if ( self::node_is_editor_person( $node, $organization_id ) ) {
				$old_id = isset( $node['@id'] ) && is_string( $node['@id'] )
					? $node['@id']
					: '';
				if ( '' !== $old_id && $editor_person_id !== $old_id ) {
					$id_replacements[ $old_id ] = $editor_person_id;
				}

				$data[ $key ]['@id'] = $editor_person_id;
				$data[ $key ]['url'] = $about_url;
				$has_editor_person   = true;
			}
		}

		if ( array() !== $id_replacements ) {
			$data = self::rewrite_schema_id_references( $data, $id_replacements );
		}

		if ( $has_editor_person ) {
			foreach ( $data as $key => $node ) {
				if ( ! is_array( $node ) || ! self::node_is_about_page( $node, $about_url ) ) {
					continue;
				}

				$main_entity = $node['mainEntity'] ?? null;
				if ( null === $main_entity || '' === $main_entity || array() === $main_entity ) {
					$data[ $key ]['mainEntity'] = array( '@id' => $editor_person_id );
				}
			}
		}

		return $data;
	}

	/**
	 * Devuelve la página singular que contiene el shortcode del directorio.
	 *
	 * @return object|null
	 */
	private function current_series_directory_post(): ?object {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return null;
		}

		$post = get_post();
		if ( ! is_object( $post ) || ! is_string( $post->post_content ?? null ) ) {
			return null;
		}

		return has_shortcode( $post->post_content, SeriesDirectoryShortcode::TAG ) ? $post : null;
	}

	/**
	 * Verifica si un nodo del grafo representa al editor Alejandro Fernández.
	 *
	 * @param array<string,mixed> $node Nodo JSON-LD.
	 * @param string              $organization_id ID de organización canónico.
	 * @return bool
	 */
	public static function node_is_editor_person( array $node, string $organization_id ): bool {
		$types = (array) ( $node['@type'] ?? array() );
		if ( ! in_array( 'Person', $types, true )
			|| self::EDITOR_PERSON_NAME !== trim( (string) ( $node['name'] ?? '' ) ) ) {
			return false;
		}

		$works_for = $node['worksFor'] ?? null;
		if ( is_array( $works_for )
			&& isset( $works_for['@id'] )
			&& is_string( $works_for['@id'] ) ) {
			return $organization_id === $works_for['@id'];
		}
		if ( ! is_array( $works_for ) ) {
			return false;
		}

		foreach ( $works_for as $organization ) {
			if ( is_array( $organization )
				&& isset( $organization['@id'] )
				&& $organization_id === $organization['@id'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reemplaza recursivamente identificadores @id en fragmentos de grafo.
	 *
	 * @param array<mixed>         $value Fragmento de grafo.
	 * @param array<string,string> $replacements Mapa de reemplazos.
	 * @return array<mixed>
	 */
	public static function rewrite_schema_id_references( array $value, array $replacements ): array {
		foreach ( $value as $key => $item ) {
			if ( '@id' === $key && is_string( $item ) && isset( $replacements[ $item ] ) ) {
				$value[ $key ] = $replacements[ $item ];
				continue;
			}
			if ( is_array( $item ) ) {
				$value[ $key ] = self::rewrite_schema_id_references( $item, $replacements );
			}
		}

		return $value;
	}

	/**
	 * Verifica si un nodo representa la página /nosotros/.
	 *
	 * @param array<string,mixed> $node Nodo JSON-LD.
	 * @param string              $about_url URL canónica.
	 * @return bool
	 */
	public static function node_is_about_page( array $node, string $about_url ): bool {
		$types = (array) ( $node['@type'] ?? array() );
		if ( ! in_array( 'AboutPage', $types, true ) ) {
			return false;
		}

		$url = isset( $node['url'] ) && is_string( $node['url'] )
			? rtrim( $node['url'], '/' ) . '/'
			: '';
		if ( $about_url === $url ) {
			return true;
		}

		$id = isset( $node['@id'] ) && is_string( $node['@id'] ) ? $node['@id'] : '';
		return 0 === strpos( $id, $about_url . '#' );
	}

	/**
	 * Verifica si un nodo Place está vacío.
	 *
	 * @param array<string,mixed> $node Nodo JSON-LD.
	 * @return bool
	 */
	public static function node_is_empty_place( array $node ): bool {
		$types = (array) ( $node['@type'] ?? array() );
		if ( ! in_array( 'Place', $types, true ) ) {
			return false;
		}

		foreach ( array( 'name', 'geo', 'telephone' ) as $signal ) {
			if ( isset( $node[ $signal ] ) && '' !== $node[ $signal ] && array() !== $node[ $signal ] ) {
				return false;
			}
		}

		$address = $node['address'] ?? null;
		if ( null === $address || '' === $address ) {
			return true;
		}
		if ( ! is_array( $address ) ) {
			return false;
		}
		foreach ( $address as $property => $value ) {
			if ( '@type' === $property || '@id' === $property ) {
				continue;
			}
			if ( '' !== $value && array() !== $value && null !== $value ) {
				return false;
			}
		}

		return true;
	}
}
