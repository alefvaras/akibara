<?php
/**
 * Tests for the public checkout data service.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Modules\AddressAutocomplete\AddressAutocomplete;
use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Modules\EstimatedDelivery\Settings as EstimatedDeliverySettings;
use Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress;
use Akibara\Business\Contracts\EtaProvider;
use Akibara\Business\Modules\SeriesBundles\SeriesBundles;
use Akibara\Business\Services\CheckoutDataService;
use Akibara\Business\Tests\Support\CatalogoDeAccesos;
use Akibara\Business\Modules\Stock\Availability;
use Brain\Monkey;
use Brain\Monkey\Functions;
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
		$GLOBALS['akibara_test_options'] = array(
			'akb_copy_eta_tba' => 'Te avisamos en cuanto tengamos fecha',
		);
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
	 * @testdox El resumen de preventa del checkout usa la MISMA palabra que la etiqueta de cada tomo, y sigue a la option.
	 */
	public function test_preventa_summary_uses_the_editable_word(): void {
		$carrito = new class() {
			public function is_empty(): bool {
				return false;
			}

			/** @return array<string, array<string, mixed>> */
			public function get_cart(): array {
				return array(
					'a' => array( 'data' => new WC_Product( 'onbackorder' ), 'quantity' => 1 ),
					'b' => array( 'data' => new WC_Product( 'instock' ), 'quantity' => 1 ),
				);
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $carrito ) );

		$sut     = $this->service( new CheckoutEtaProviderStub( EtaResult::range( 100, 200 ) ) );
		$resumen = new \ReflectionMethod( CheckoutDataService::class, 'structured_preventa_summary' );
		$resumen->setAccessible( true );

		$palabra = mb_strtolower( Availability::label_for_state( Availability::A_PEDIDO ) );
		self::assertStringContainsString( 'disponibles y uno ' . $palabra, $resumen->invoke( $sut )['explanation'] );

		// La palabra es la del filtro que gobierna la etiqueta de cada tomo.
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value, ...$args ) => 'akb_availability_label' === $hook ? 'Por encargo' : $value );
		self::assertStringContainsString( 'disponibles y uno por encargo', $resumen->invoke( $sut )['explanation'], 'La palabra sigue a la etiqueta de cada tomo, no a un literal.' );
	}

	/**
	 * @testdox Should not ask for an ETA when the product is available immediately.
	 */
	public function test_instock_product_never_calculates_eta(): void {
		$eta_provider = new CheckoutEtaProviderStub( EtaResult::range( 100, 200 ) );
		$sut          = $this->service( $eta_provider );

		$result = $sut->product_availability( new WC_Product( 'instock' ) );

		self::assertSame( 0, $eta_provider->calculate_calls, 'ETA must be queried only for preventa products.' );
		self::assertSame( 'instock', $result['stock_status'] );
		self::assertSame( 'Disponible', $result['stock_label'] );
		self::assertFalse( $result['is_preventa'] );
		self::assertNull( $result['eta'] );
		self::assertNull( $result['eta_timestamp'] );
	}

	/**
	 * @testdox El precio futuro conserva la semántica de la ficha sin alterar los importes.
	 */
	public function test_reservation_presentation_preserves_prices_and_real_sales(): void {
		Functions\when( 'wc_price' )->alias( static fn( $amount ): string => '&#36;' . number_format( (float) $amount, 0, ',', '.' ) );
		$product = new WC_Product( 'onbackorder' );
		$product->akb_regular_price = '11500';
		$product->akb_sale_price = '11000';
		$product->akb_on_sale = true;
		$sut = $this->service( new CheckoutEtaProviderStub( EtaResult::tba() ) );
		$result = $sut->product_availability( $product );
		self::assertSame( 'Precio de reserva', $result['reservation_price_label'] );
		self::assertSame( 'Al llegar el stock sube a $11.500', $result['reservation_price_note'] );
		self::assertSame( '11500', $product->get_regular_price( 'edit' ) );
		self::assertSame( '11000', $product->get_sale_price( 'edit' ) );

		$GLOBALS['akibara_test_postmeta'] = array( 0 => array( '_akb_eta_type' => 'tba' ) );
		self::assertSame( 'Precio de preventa', $sut->product_availability( $product )['reservation_price_label'] );
		unset( $GLOBALS['akibara_test_postmeta'] );

		$product->akb_sale_price = '9000';
		$result = $sut->product_availability( $product );
		self::assertSame( '', $result['reservation_price_label'], 'Una oferta real conserva la presentación nativa de oferta.' );
		self::assertSame( '', $result['reservation_price_note'] );
	}

	/**
	 * @testdox Should expose TBA copy without inventing an ETA timestamp.
	 */
	public function test_tba_preventa_remains_conservative(): void {
		$eta_provider = new CheckoutEtaProviderStub( EtaResult::tba() );
		$sut          = $this->service( $eta_provider );

		// Una preventa TBA de verdad lleva su meta: sin ella, el estado comercial
		// (1.109.0) sería «A pedido» y este test dejaría de cubrir la preventa.
		$GLOBALS['akibara_test_postmeta'] = array( 0 => array( '_akb_eta_type' => 'tba' ) );

		$result = $sut->product_availability( new WC_Product( 'onbackorder' ) );

		unset( $GLOBALS['akibara_test_postmeta'] );

		self::assertSame( 1, $eta_provider->calculate_calls );
		self::assertSame( 'onbackorder', $result['stock_status'] );
		self::assertSame( 'Preventa', $result['stock_label'] );
		self::assertTrue( $result['is_preventa'] );
		self::assertSame( 'Te avisamos en cuanto tengamos fecha', $result['eta'] );
		self::assertNull(
			$result['eta_timestamp'],
			'An unconfirmed preventa must never expose a fabricated sortable timestamp.'
		);
	}

	/**
	 * @testdox Should expose the maximum timestamp for a concrete ETA range.
	 */
	public function test_concrete_preventa_uses_maximum_eta_timestamp(): void {
		$eta_provider = new CheckoutEtaProviderStub( EtaResult::range( 1735689600, 1736294400 ) );
		$sut          = $this->service( $eta_provider );

		// Sin metas de lanzamiento: el caso más común de producción — un tomo a
		// pedido con rango de fechas. El label lo confirma más abajo.
		$result = $sut->product_availability( new WC_Product( 'onbackorder' ) );

		self::assertSame( 'A pedido', $result['stock_label'] );
		self::assertIsString( $result['eta'] );
		self::assertNotSame( '', $result['eta'] );
		self::assertSame(
			1736294400,
			$result['eta_timestamp'],
			'The adapter should sort and aggregate using the conservative upper ETA bound.'
		);
	}

	/**
	 * @testdox Should expose a complete versioned contract without presentation markup.
	 */
	public function test_structured_contract_is_versioned_and_html_free(): void {
		$sut    = $this->service( new CheckoutEtaProviderStub( null ) );
		$result = $sut->structured_cart_data();

		self::assertSame( 2, $result['schema_version'] );
		self::assertSame(
			array( 'schema_version', 'bundle', 'free_shipping', 'preventa', 'trust_signals' ),
			array_keys( $result ),
			'Adapters rely on the stable top-level contract order and vocabulary.'
		);
		self::assertSame( 'akb_sb_', $result['bundle']['coupon_prefix'] );
		self::assertSame( array(), $result['bundle']['notices'] );
		self::assertSame( 'hidden', $result['free_shipping']['state'] );
		self::assertSame( 'unavailable', $result['free_shipping']['reason'] );
		self::assertSame( 0, $result['preventa']['count'] );
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

	private function service( EtaProvider $eta_provider ): CheckoutDataService {
		$calculator = new DateCalculator(
			new EstimatedDeliverySettings(),
			static fn(): int => 1735689600,
			new \DateTimeZone( 'UTC' )
		);

		return new CheckoutDataService(
			$eta_provider,
			new BannerRenderer( $calculator ),
			new SeriesBundles(),
			new FreeShippingProgress(),
			new AddressAutocomplete()
		);
	}
}

final class CheckoutEtaProviderStub implements EtaProvider {

	public int $calculate_calls = 0;

	private ?EtaResult $result;

	public function __construct( ?EtaResult $result ) {
		$this->result = $result;
	}

	public function calculate( WC_Product $product ): ?EtaResult {
		unset( $product );
		++$this->calculate_calls;

		return $this->result;
	}

	public function resolve_country( WC_Product $product ): ?string {
		unset( $product );

		return null;
	}

	public function get_country_name( string $slug ): ?string {
		unset( $slug );

		return null;
	}
}
