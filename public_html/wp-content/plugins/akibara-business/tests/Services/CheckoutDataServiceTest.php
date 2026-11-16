<?php
/**
 * Tests for the public checkout data service.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Modules\AddressAutocomplete\AddressAutocomplete;
use Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress;
use Akibara\Business\Services\CheckoutDataService;
use Akibara\Business\Tests\Support\CatalogoDeAccesos;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WC_Product;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CheckoutDataServiceTest extends TestCase {

	/**
	 * Loads deterministic WordPress function fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once dirname( __DIR__ ) . '/fixtures/wordpress-functions.php';
		// `AddressAutocomplete` le pide la clave de Maps al catálogo de accesos,
		// que es un mu-plugin y no entra por el autoload del plugin.
		CatalogoDeAccesos::cargar();
	}

	/**
	 * Clears isolated option fixtures.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();

		parent::tearDown();
	}

	/**
	 * @testdox Should expose product availability for instock and outofstock products.
	 */
	public function test_product_availability(): void {
		$sut = $this->service();

		$instock = $sut->product_availability( new WC_Product( 'instock' ) );
		self::assertSame( 'instock', $instock['stock_status'] );
		self::assertSame( 'Disponible', $instock['stock_label'] );

		$outofstock = $sut->product_availability( new WC_Product( 'outofstock' ) );
		self::assertSame( 'outofstock', $outofstock['stock_status'] );
		self::assertSame( 'Agotado', $outofstock['stock_label'] );
	}

	/**
	 * @testdox Should expose a complete versioned contract without presentation markup.
	 */
	public function test_structured_contract_is_versioned_and_html_free(): void {
		$sut    = $this->service();
		$result = $sut->structured_cart_data();

		self::assertSame( 2, $result['schema_version'] );
		self::assertSame(
			array( 'schema_version', 'free_shipping', 'trust_signals' ),
			array_keys( $result ),
			'Adapters rely on the stable top-level contract order and vocabulary.'
		);
		self::assertSame( 'hidden', $result['free_shipping']['state'] );
		self::assertSame( 'unavailable', $result['free_shipping']['reason'] );
		self::assertSame(
			array( 'shipping', 'installments', 'support' ),
			array_column( $result['trust_signals'], 'id' )
		);
		self::assertSame( 'Envíos a todo Chile', $result['trust_signals'][0]['label'] );
		$trust_copy = mb_strtolower( implode( ' ', array_column( $result['trust_signals'], 'label' ) ), 'UTF-8' );
		self::assertStringContainsString( 'todo chile', $trust_copy );
		self::assertStringNotContainsString( 'nacional', $trust_copy );

		$json = json_encode( $result, JSON_THROW_ON_ERROR );
		self::assertStringNotContainsString( '<', $json, 'Structured checkout data must never include HTML.' );
		self::assertStringNotContainsString( '>', $json, 'Structured checkout data must never include HTML.' );
		self::assertStringNotContainsString( '_html', $json, 'Structured checkout data must not expose renderer-specific keys.' );
	}

	private function service(): CheckoutDataService {
		return new CheckoutDataService(
			new FreeShippingProgress(),
			new AddressAutocomplete()
		);
	}
}
