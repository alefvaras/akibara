<?php
/**
 * Test: Intercepción de posts_clauses para ordenar por d.price_effective
 * cuando el usuario solicita orderby=price o orderby=price-desc.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\FacetQueryIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use WP_Query;

final class OrdenPrecioEfectivoTest extends TestCase {

	private function make_integration(): FacetQueryIntegration {
		return ( new ReflectionClass( FacetQueryIntegration::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * @testdox requested_price_orderby detecta 'price' y 'price-desc' limpiamente.
	 */
	public function test_detecta_orderby_precio(): void {
		$integration = $this->make_integration();
		$method      = new ReflectionMethod( FacetQueryIntegration::class, 'requested_price_orderby' );
		$method->setAccessible( true );

		$_GET['orderby'] = 'price';
		self::assertSame( 'price', $method->invoke( $integration ) );

		$_GET['orderby'] = 'price-desc';
		self::assertSame( 'price-desc', $method->invoke( $integration ) );

		$_GET['orderby'] = 'date';
		self::assertNull( $method->invoke( $integration ) );

		unset( $_GET['orderby'] );
		self::assertNull( $method->invoke( $integration ) );
	}

	/**
	 * @testdox filter_price_orderby_clauses reemplaza orderby por akb_d.price_effective ASC.
	 */
	public function test_reemplaza_orderby_por_precio_efectivo_asc(): void {
		global $wpdb;
		$wpdb = (object) array(
			'posts'  => 'wp_posts',
			'prefix' => 'wp_',
		);

		$integration = $this->make_integration();

		$_GET['orderby'] = 'price';

		$query = $this->createMock( WP_Query::class );
		$query->method( 'is_main_query' )->willReturn( true );

		$clauses = array(
			'join'    => '',
			'orderby' => 'wc_product_meta_lookup.min_price ASC, wc_product_meta_lookup.product_id ASC',
		);

		$result = $integration->filter_price_orderby_clauses( $clauses, $query );

		self::assertStringContainsString( 'LEFT JOIN wp_akb_search_docs akb_d ON akb_d.post_id = wp_posts.ID', $result['join'] );
		self::assertStringContainsString( 'akb_d.price_effective ASC, wp_posts.ID ASC', $result['orderby'] );

		unset( $_GET['orderby'] );
	}

	/**
	 * @testdox filter_price_orderby_clauses reemplaza orderby por akb_d.price_effective DESC.
	 */
	public function test_reemplaza_orderby_por_precio_efectivo_desc(): void {
		global $wpdb;
		$wpdb = (object) array(
			'posts'  => 'wp_posts',
			'prefix' => 'wp_',
		);

		$integration = $this->make_integration();

		$_GET['orderby'] = 'price-desc';

		$query = $this->createMock( WP_Query::class );
		$query->method( 'is_main_query' )->willReturn( true );

		$clauses = array(
			'join'    => 'INNER JOIN wp_akb_search_docs ON ...',
			'orderby' => 'wc_product_meta_lookup.max_price DESC, wc_product_meta_lookup.product_id DESC',
		);

		$result = $integration->filter_price_orderby_clauses( $clauses, $query );

		self::assertStringContainsString( 'akb_d.price_effective DESC, wp_posts.ID DESC', $result['orderby'] );

		unset( $_GET['orderby'] );
	}
}
