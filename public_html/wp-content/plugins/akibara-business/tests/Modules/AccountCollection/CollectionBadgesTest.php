<?php
/**
 * Tests para CollectionBadges — reconocimiento universal de posesión de colección.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AccountCollection;

use Akibara\Business\Modules\AccountCollection\CollectionBadges;
use Akibara\Business\Modules\AccountCollection\CollectionResolver;
use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Product;

final class CollectionBadgesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Registra los hooks de frontend, AJAX privado/público y placeholders PDP. */
	public function test_it_registers_expected_hooks(): void {
		$customer_collection = new CustomerCollection();
		$badges = new CollectionBadges( $customer_collection );

		Monkey\Actions\expectAdded( 'wp_enqueue_scripts' )->once();
		Monkey\Actions\expectAdded( 'wp_ajax_akb_get_my_collection_ids' )->once();
		Monkey\Actions\expectAdded( 'wp_ajax_nopriv_akb_get_my_collection_ids' )->once();
		Monkey\Actions\expectAdded( 'woocommerce_single_product_summary' )->once();
		Monkey\Actions\expectAdded( 'woocommerce_after_add_to_cart_form' )->once();

		$badges->register();
		self::assertInstanceOf( CollectionBadges::class, $badges );
	}

	/** @testdox Endpoint AJAX consolida compras reales con estantería manual sin duplicados y ordenado. */
	public function test_ajax_returns_consolidated_and_sorted_ids(): void {
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				return array(
					'version'     => 3,
					'status'      => 'ok',
					'product_ids' => array( 101, 105, 102 ),
				);
			}
		);

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );

		// Simular tomos en estantería manual (102 duplicado, 103 nuevo)
		Functions\when( 'get_user_meta' )
			->alias(
				static function ( $user_id, $meta_key, $single = false ) {
					if ( CollectionResolver::USER_META_MANUAL_OWNED === $meta_key ) {
						return array( 102, 103 );
					}
					return 'gen1';
				}
			);

		$captured = null;
		Functions\when( 'wp_send_json_success' )
			->alias(
				static function ( $data ) use ( &$captured ): void {
					$captured = $data;
				}
			);

		$customer_collection = new CustomerCollection();
		$badges = new CollectionBadges( $customer_collection );
		$badges->ajax_get_owned_ids();

		self::assertIsArray( $captured );
		self::assertSame( 4, $captured['count'] );
		// Debe estar unificado, sin duplicados y ordenado numéricamente
		self::assertSame( array( 101, 102, 103, 105 ), $captured['owned_ids'] );
	}

	/** @testdox Endpoint nopriv devuelve array vacío de inmediato para visitantes. */
	public function test_ajax_nopriv_returns_empty_array(): void {
		$customer_collection = new CustomerCollection();
		$badges = new CollectionBadges( $customer_collection );

		$captured = null;
		Functions\when( 'wp_send_json_success' )
			->alias(
				static function ( $data ) use ( &$captured ): void {
					$captured = $data;
				}
			);

		$badges->ajax_nopriv_get_owned_ids();

		self::assertIsArray( $captured );
		self::assertSame( array(), $captured['owned_ids'] );
	}

	/** @testdox El placeholder de PDP emite el contenedor accesible con data-attribute del producto. */
	public function test_pdp_placeholder_renders_data_attribute(): void {
		$customer_collection = new CustomerCollection();
		$badges = new CollectionBadges( $customer_collection );

		$product = $this->createMock( WC_Product::class );
		$product->method( 'get_id' )->willReturn( 999 );

		$GLOBALS['product'] = $product;

		ob_start();
		$badges->render_pdp_placeholder();
		$html = ob_get_clean();

		self::assertStringContainsString( 'class="akb-pdp-collection-banner"', $html );
		self::assertStringContainsString( 'data-akb-pdp-collection-product="999"', $html );
		self::assertStringContainsString( 'hidden', $html );

		unset( $GLOBALS['product'] );
	}

	/** @testdox El placeholder de estantería en PDP emite el contenedor accesible con data-attribute del producto. */
	public function test_pdp_shelve_button_renders_data_attribute(): void {
		$customer_collection = new CustomerCollection();
		$badges = new CollectionBadges( $customer_collection );

		$product = $this->createMock( WC_Product::class );
		$product->method( 'get_id' )->willReturn( 999 );

		$GLOBALS['product'] = $product;

		ob_start();
		$badges->render_pdp_shelve_button();
		$html = ob_get_clean();

		self::assertStringContainsString( 'class="akb-pdp-shelve-action"', $html );
		self::assertStringContainsString( 'data-akb-pdp-shelve-product="999"', $html );
		self::assertStringContainsString( 'hidden', $html );

		unset( $GLOBALS['product'] );
	}
}
