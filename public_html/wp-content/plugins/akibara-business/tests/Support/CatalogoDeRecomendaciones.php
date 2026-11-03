<?php
/**
 * Catálogo de mentira para las pruebas de recomendaciones: productos con
 * título, serie y estado, y los stubs de WordPress/WooCommerce que leen los
 * adaptadores (`wc_get_product`, `get_post_field`, `get_the_terms`,
 * `_prime_post_caches` y `$wpdb` para la consulta de tomos por serie).
 *
 * Lo usan las pruebas de tests/Modules/Recommendations y PopularProducts.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

use Brain\Monkey\Functions;

final class CatalogoDeRecomendaciones {

	/** @var array<int, array{title:string, serie:int|null, comprable:bool}> */
	public array $productos = array();

	/** @var array<int, array<int, int>> Lotes pedidos a `_prime_post_caches()`. */
	public array $primados = array();

	/** Consultas de tomos por serie hechas a `$wpdb`. */
	public int $consultas_de_tomos = 0;

	/**
	 * Ediciones de la misma obra por serie (`_akb_serie_ediciones_relacionadas`,
	 * term_id → term_ids). En los stubs term_id y term_taxonomy_id coinciden.
	 *
	 * @var array<int, array<int, int>>
	 */
	public array $ediciones = array();

	/** Pasos de term_id a term_taxonomy_id pedidos a `get_terms()`. */
	public int $consultas_de_ediciones = 0;

	public function agregar( int $id, string $title, ?int $serie, bool $comprable = true ): void {
		$this->productos[ $id ] = array(
			'title'     => $title,
			'serie'     => $serie,
			'comprable' => $comprable,
		);
	}

	/** Instala los stubs. Llamar después de `Monkey\setUp()`. */
	public function instalar(): void {
		$catalogo = $this;

		Functions\when( '_prime_post_caches' )->alias(
			static function ( array $ids ) use ( $catalogo ): void {
				$catalogo->primados[] = array_values( $ids );
			}
		);
		Functions\when( 'get_post_field' )->alias(
			static fn( string $field, $id ) => $catalogo->productos[ (int) $id ]['title'] ?? ''
		);
		Functions\when( 'get_the_terms' )->alias(
			static function ( $id, string $taxonomy ) use ( $catalogo ) {
				$serie = $catalogo->productos[ (int) $id ]['serie'] ?? null;
				if ( 'pa_serie' !== $taxonomy || null === $serie ) {
					return false;
				}
				return array( new \WP_Term( $serie, 'serie-' . $serie, 'Serie ' . $serie ) );
			}
		);
		Functions\when( 'get_term_meta' )->alias(
			static function ( $term_id, string $key = '', bool $single = false ) use ( $catalogo ) {
				unset( $single );
				return '_akb_serie_ediciones_relacionadas' === $key ? ( $catalogo->ediciones[ (int) $term_id ] ?? '' ) : '';
			}
		);
		Functions\when( 'get_terms' )->alias(
			static function ( array $args ) use ( $catalogo ) {
				++$catalogo->consultas_de_ediciones;
				return 'tt_ids' === ( $args['fields'] ?? '' ) ? array_values( array_map( 'intval', (array) ( $args['include'] ?? array() ) ) ) : array();
			}
		);
		Functions\when( 'wc_get_product' )->alias(
			static function ( $id ) use ( $catalogo ) {
				$datos = $catalogo->productos[ (int) $id ] ?? null;
				if ( null === $datos ) {
					return false;
				}
				$producto = new class( $datos['comprable'] ? 'instock' : 'outofstock' ) extends \WC_Product {
					public function is_visible(): bool {
						return true;
					}

					public function is_in_stock(): bool {
						return 'outofstock' !== $this->get_stock_status();
					}
				};
				$producto->akb_id = (int) $id;
				return $producto;
			}
		);

		$GLOBALS['wpdb'] = new class( $catalogo ) {
			public string $posts              = 'wp_posts';
			public string $term_relationships = 'wp_term_relationships';
			public string $term_taxonomy      = 'wp_term_taxonomy';

			private CatalogoDeRecomendaciones $catalogo;

			/** @var array<int, int> */
			private array $series = array();

			public function __construct( CatalogoDeRecomendaciones $catalogo ) {
				$this->catalogo = $catalogo;
			}

			/**
			 * @param string          $sql  SQL.
			 * @param array<int, int> $args Series pedidas.
			 */
			public function prepare( string $sql, $args ): string {
				$this->series = array_map( 'intval', (array) $args );
				return $sql;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $sql, string $output ): array {
				unset( $sql, $output );
				++$this->catalogo->consultas_de_tomos;
				$filas = array();
				foreach ( $this->catalogo->productos as $id => $datos ) {
					if ( null !== $datos['serie'] && in_array( $datos['serie'], $this->series, true ) ) {
						$filas[] = array(
							'id'    => $id,
							'title' => $datos['title'],
							'serie' => $datos['serie'],
						);
					}
				}
				return $filas;
			}
		};
	}

	public function desinstalar(): void {
		unset( $GLOBALS['wpdb'] );
	}
}
