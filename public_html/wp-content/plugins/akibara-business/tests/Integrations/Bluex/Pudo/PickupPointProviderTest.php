<?php
/**
 * Tests for the Blue Express pickup point provider.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Integrations\Bluex\Pudo;

require_once __DIR__ . '/PudoTestDoubles.php';

use Akibara\Business\Integrations\Bluex\Pudo\PickupPointProvider;
use Akibara\Business\Integrations\Bluex\Pudo\PudoValidationException;
use PHPUnit\Framework\TestCase;

final class PickupPointProviderTest extends TestCase {

	/**
	 * @testdox Should resolve only provider-confirmed pickup data.
	 */
	public function test_resolves_canonical_pickup_data(): void {
		$client = new PudoApiClientStub( $this->valid_geolocation(), $this->paid_pricing() );
		$sut    = $this->provider( $client );

		$result = $sut->resolve( 'AGENCY-123', 'Santiago', 'CL-RM' );

		self::assertSame( 'AGENCY-123', $result['agency_id'] );
		self::assertSame( 'Punto Blue Centro', $result['agency_name'] );
		self::assertSame( 'Av. Siempre Viva 123, Santiago', $result['agency_address'] );
			self::assertSame( 'CL-RM', $result['region_code'] );
			self::assertSame( 13, $result['provider_region_id'] );
		self::assertSame( 'SCL', $result['district_code'] );
		self::assertSame( 'Santiago', $result['city'] );
		self::assertSame(
			array(
				'address_1' => 'Av. Siempre Viva 123',
				'address_2' => 'Punto Blue Centro',
				'city'      => 'Santiago',
				'state'     => 'CL-RM',
				'country'   => 'CL',
				'postcode'  => '8320000',
			),
			$result['shipping_address']
		);
		self::assertSame(
			array( 'Santiago', 'RM', 'AGENCY-123', true ),
			$client->geolocation_calls[0],
			'The provider must call BlueX PUDO v2 through its public client method.'
		);
	}

	/**
	 * @testdox Should never fabricate an order shipping address from incomplete API data.
	 */
	public function test_omits_incomplete_shipping_address(): void {
		$geolocation                                      = $this->valid_geolocation();
		$geolocation['pickupInfo']['location']['city_name'] = '';
		$client                                           = new PudoApiClientStub( $geolocation, $this->paid_pricing() );
		$sut                                              = $this->provider( $client );

		$result = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );

		self::assertNull(
			$result['shipping_address'],
			'Only a complete physical location returned by BlueX may become an order address.'
		);
		self::assertSame(
			'Av. Siempre Viva 123, Santiago',
			$result['agency_address'],
			'A separately confirmed display address may remain available.'
		);
	}

	/**
	 * @testdox Should fail closed when BlueX returns a different agency ID.
	 */
	public function test_rejects_agency_id_mismatch(): void {
		$geolocation                                  = $this->valid_geolocation();
		$geolocation['pickupInfo']['agency_id']       = 'ATTACKER-999';
		$sut                                          = $this->provider(
			new PudoApiClientStub( $geolocation, $this->paid_pricing() )
		);

		$this->expectException( PudoValidationException::class );
		$this->expectExceptionMessage( 'no coincide' );

		$sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );
	}

	/**
	 * BlueX no restringe el retiro a la región de despacho: su propio módulo
	 * cotiza con la comuna del cliente más el ID de la agencia y nunca lee
	 * `state_name`. Akibara imponía esa restricción por su cuenta; este test
	 * fija que ya no lo hace y que la dirección del pedido no se contamina.
	 *
	 * @testdox Should accept a pickup point outside the customer region without writing an address.
	 */
	public function test_accepts_pickup_point_outside_the_customer_region(): void {
		$geolocation = $this->valid_geolocation();
		$geolocation['pickupInfo']['location']['state_name'] = 'Valparaíso';

		$sut    = $this->provider( new PudoApiClientStub( $geolocation, $this->paid_pricing() ) );
		$result = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );

		self::assertSame( 'AGENCY-123', $result['agency_id'] );
		self::assertSame(
			'CL-RM',
			$result['region_code'],
			'El destino cotizado sigue siendo la región del cliente, igual que en BlueX.'
		);
		self::assertNull(
			$result['shipping_address'],
			'Con el punto en otra región no se escribe dirección: se evita mezclar calle y región.'
		);
	}

	/**
	 * @testdox Should fail closed for low-confidence or drifted API schemas.
	 *
	 * @dataProvider invalid_geolocation_provider
	 *
	 * @param array<string, mixed> $geolocation Invalid geolocation.
	 */
	public function test_rejects_invalid_geolocation_schema( array $geolocation ): void {
		$sut = $this->provider( new PudoApiClientStub( $geolocation, $this->paid_pricing() ) );

		$this->expectException( PudoValidationException::class );

		$sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );
	}

	/**
	 * Provides fail-closed geolocation scenarios.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function invalid_geolocation_provider(): array {
		$low_confidence                           = $this->valid_geolocation();
		$low_confidence['porcentageDeExito']      = '79%';
		$missing_pickup                           = $this->valid_geolocation();
		unset( $missing_pickup['pickupInfo'] );
			$wrong_region                             = $this->valid_geolocation();
			$wrong_region['regionCode']               = 5;
			$wrong_name                               = $this->valid_geolocation();
			$wrong_name['nameRegion']                 = 'Valparaíso';
			$missing_district                         = $this->valid_geolocation();
			unset( $missing_district['districtCode'] );

		return array(
			'low confidence'   => array( $low_confidence ),
				'missing pickup'   => array( $missing_pickup ),
				'wrong region'     => array( $wrong_region ),
				'wrong region name' => array( $wrong_name ),
				'missing district' => array( $missing_district ),
		);
	}

	/**
	 * @testdox Should quote through BlueX public pricing API with PUDO family.
	 */
	public function test_quotes_pudo_through_public_api(): void {
		$client   = new PudoApiClientStub( $this->valid_geolocation(), $this->paid_pricing() );
		$sut      = $this->provider( $client );
		$point    = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );
		$packages = $this->packages();
		$packages[0]['_akb_pudo_generation'] = str_repeat( 'a', 64 );

		$quote = $sut->quote( $point, $packages );

		self::assertSame(
			array(
				'total'       => 3990.0,
				'is_free'     => false,
				'promise_day' => 2,
			),
			$quote
		);
		self::assertCount( 1, $client->pricing_calls );
		self::assertSame( 'PUDO', $client->pricing_calls[0][5] );
		self::assertSame( 'EX', $client->pricing_calls[0][2] );
		self::assertSame( 'SCL', $client->pricing_calls[0][0]['district'] );
			self::assertSame( 13, $client->pricing_calls[0][1]['state'] );
		self::assertSame( 'SCL', $client->pricing_calls[0][1]['district'] );
		self::assertSame( 55000.0, $client->pricing_calls[0][4] );
		self::assertCount( 7, $client->pricing_calls[0], 'Cache metadata must never enter the BlueX API argument contract.' );
	}

	/**
	 * @testdox Should read the promise day from the human sentence BlueX really returns.
	 */
	public function test_accepts_the_production_promise_day_sentence(): void {
		// Verbatim /eplin/pricing/v1 payload logged in production on 2026-07-27: the
		// API answers with the sentence it shows shoppers, never a bare integer.
		$pricing = array(
			'code' => '00',
			'data' => array(
				'total'          => 5600,
				'isShipmentFree' => false,
				'promiseDay'     => 'Hasta 3 días hábiles',
			),
		);
		$client = new PudoApiClientStub( $this->valid_geolocation(), $pricing );
		$sut    = $this->provider( $client );
		$point  = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );

		$quote = $sut->quote( $point, $this->packages() );

		self::assertSame(
			array(
				'total'       => 5600.0,
				'is_free'     => false,
				'promise_day' => 3,
			),
			$quote,
			'A real BlueX quote must be accepted; rejecting it made PUDO unselectable in production.'
		);
	}

	/**
	 * @testdox Should still reject a promise day that carries no usable number.
	 */
	public function test_rejects_promise_day_without_a_number(): void {
		foreach ( array( 'sin plazo definido', '', null, array( 3 ), 'Hasta 400 días hábiles' ) as $promise_day ) {
			$pricing = array(
				'code' => '00',
				'data' => array(
					'total'          => 5600,
					'isShipmentFree' => false,
					'promiseDay'     => $promise_day,
				),
			);
			$client = new PudoApiClientStub( $this->valid_geolocation(), $pricing );
			$sut    = $this->provider( $client );
			$point  = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );

			try {
				$sut->quote( $point, $this->packages() );
				self::fail( 'Expected a fail-closed rejection for promiseDay ' . var_export( $promise_day, true ) );
			} catch ( PudoValidationException $exception ) {
				self::assertSame( 'bluex_pricing_schema', $exception->error_code() );
			}
		}
	}

	/**
	 * @testdox Should reproduce BlueX gross-price contract for order 27136 at the free-shipping threshold.
	 */
	public function test_matches_vendor_gross_price_contract_for_order_27136(): void {
		$pricing = array(
			'code' => '01',
			'data' => array(
				'total'          => 0,
				'isShipmentFree' => true,
				'promiseDay'     => '2',
			),
		);
		$client  = new PudoApiClientStub( $this->valid_geolocation(), $pricing );
		$sut     = $this->provider( $client );
		$point   = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );
		$product = new PudoProductStub( '1.4', '1.5', '1.6', '0.25', '57980' );

		$quote = $sut->quote( $point, $this->packages( $product, 48724.0 ) );

		self::assertSame(
			array(
				array(
					'ancho'      => 2,
					'largo'      => 2,
					'alto'       => 2,
					'pesoFisico' => 0.25,
					'cantidad'   => 1,
				),
			),
			$client->pricing_calls[0][3],
			'First-party bultos must reproduce BlueX 3.3.1: centimeters rounded up.'
		);
		self::assertSame(
			57980.0,
			$client->pricing_calls[0][4],
			'BlueX uses the editable gross product price, not the tax-exclusive contents_cost.'
		);
		self::assertSame(
			array( 'edit' ),
			$product->price_contexts,
			'The first-party adapter must request the same editable price context as BlueX 3.2.2.'
		);
		self::assertSame(
			array(
				'total'       => 0.0,
				'is_free'     => true,
				'promise_day' => 2,
			),
			$quote,
			'The 57,980 gross value must retain the free-shipping decision returned by BlueX.'
		);
	}

	/**
	 * @testdox Should subtract the exact WooCommerce cart discount before BlueX pricing.
	 */
	public function test_subtracts_discount_and_crosses_below_55000_exactly(): void {
		$client  = new PudoApiClientStub( $this->valid_geolocation(), $this->paid_pricing() );
		$sut     = $this->provider( $client, 4000.0 );
		$point   = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );
		$product = new PudoProductStub( '18', '25', '4', '0.5', '57980' );

		$quote = $sut->quote( $point, $this->packages( $product, 48724.0 ) );

		self::assertSame(
			53980.0,
			$client->pricing_calls[0][4],
			'BlueX 3.2.2 sends gross product prices minus the cart discount without substituting contents_cost.'
		);
		self::assertSame(
			array(
				'total'       => 3990.0,
				'is_free'     => false,
				'promise_day' => 2,
			),
			$quote,
			'The exact discounted value below 55,000 must preserve BlueX paid pricing.'
		);
	}

	/**
	 * @testdox Should preserve the exact 55000 free-shipping threshold after discount.
	 */
	public function test_preserves_exact_55000_threshold_after_discount(): void {
		$pricing = array(
			'code' => '01',
			'data' => array(
				'total'          => 0,
				'isShipmentFree' => true,
				'promiseDay'     => '2',
			),
		);
		$client  = new PudoApiClientStub( $this->valid_geolocation(), $pricing );
		$sut     = $this->provider( $client, 5000.0 );
		$point   = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );
		$product  = new PudoProductStub( '18', '25', '4', '0.5', '20000' );
		$second   = new PudoProductStub( '18', '25', '4', '0.5', '20000' );
		$packages = $this->packages( $product, 49580.0 );
		$packages[0]['contents']['cart-key']['quantity'] = 2;
		$packages[0]['contents']['second-key'] = array(
			'product_id' => 43,
			'quantity'   => 1,
			'line_total' => 20000.0,
			'data'       => $second,
		);

		$quote = $sut->quote( $point, $packages );

		self::assertSame(
			55000.0,
			$client->pricing_calls[0][4],
			'BlueX exact sum(price edit times quantity) minus discount arithmetic must retain the 55,000 boundary.'
		);
		self::assertSame( array( 'edit' ), $product->price_contexts );
		self::assertSame( array( 'edit' ), $second->price_contexts );
		self::assertTrue( $quote['is_free'], 'BlueX explicit free-shipping confirmation must be retained.' );
	}

	/**
	 * @testdox Should fail closed when the WooCommerce discount contract is unavailable.
	 */
	public function test_rejects_missing_discount_contract(): void {
		$client = new PudoApiClientStub( $this->valid_geolocation(), $this->paid_pricing() );
		$sut    = new PickupPointProvider(
			static fn(): object => $client,
			static fn(): object => new PudoSettingsStub()
		);
		$point  = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );

		try {
			$sut->quote( $point, $this->packages() );
			self::fail( 'A missing WooCommerce cart contract must reject PUDO pricing.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'bluex_contract_missing', $exception->error_code() );
		}
	}

	/**
	 * @testdox Should fail closed for malformed discounts or negative declared values.
	 *
	 * @dataProvider invalid_discount_provider
	 *
	 * @param mixed $discount Invalid discount.
	 */
	public function test_rejects_invalid_discount_values( $discount ): void {
		$client = new PudoApiClientStub( $this->valid_geolocation(), $this->paid_pricing() );
		$sut    = new PickupPointProvider(
			static fn(): object => $client,
			static fn(): object => new PudoSettingsStub(),
			static fn() => $discount
		);
		$point  = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );

		try {
			$sut->quote( $point, $this->packages() );
			self::fail( 'An invalid cart discount must reject PUDO pricing.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'pudo_invalid_package_value', $exception->error_code() );
		}
	}

	/**
	 * Provides invalid discount values.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function invalid_discount_provider(): array {
		return array(
			'non numeric'       => array( 'not-a-number' ),
			'negative discount' => array( -1 ),
			'exceeds gross'     => array( 55001 ),
		);
	}

	/**
	 * @testdox Should fail closed when a package item cannot match BlueX WC Product pricing.
	 */
	public function test_rejects_non_wc_product_for_declared_value(): void {
		$client   = new PudoApiClientStub( $this->valid_geolocation(), $this->paid_pricing() );
		$sut      = $this->provider( $client );
		$point    = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );
		$packages = $this->packages();
		$packages[0]['contents']['cart-key']['data'] = new \stdClass();

		try {
			$sut->quote( $point, $packages );
			self::fail( 'A non-WC product must reject PUDO pricing.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'pudo_invalid_package_value', $exception->error_code() );
		}
	}

	/**
	 * @testdox Should accept zero cost only when BlueX explicitly confirms free shipping.
	 */
	public function test_accepts_explicit_free_pricing(): void {
		$pricing = array(
			'code' => '01',
			'data' => array(
				'total'          => 0,
				'isShipmentFree' => true,
				'promiseDay'     => '2',
			),
		);
		$client  = new PudoApiClientStub( $this->valid_geolocation(), $pricing );
		$sut     = $this->provider( $client );
		$point   = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );

		self::assertSame(
			array(
				'total'       => 0.0,
				'is_free'     => true,
				'promise_day' => 2,
			),
			$sut->quote( $point, $this->packages( null, 57990.0 ) )
		);
	}

	/**
	 * @testdox Should reject zero-cost pricing unless BlueX explicitly marks it free.
	 */
	public function test_rejects_unconfirmed_zero_cost(): void {
		$pricing            = $this->paid_pricing();
		$pricing['data']['total'] = 0;
		$sut                = $this->provider(
			new PudoApiClientStub( $this->valid_geolocation(), $pricing )
		);
		$point              = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );

		$this->expectException( PudoValidationException::class );

		$sut->quote( $point, $this->packages() );
	}

	/**
	 * @testdox Should reject multi-package PUDO quotes.
	 */
	public function test_rejects_multi_package_quote(): void {
		$sut      = $this->provider(
			new PudoApiClientStub( $this->valid_geolocation(), $this->paid_pricing() )
		);
		$point    = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );
		$packages = $this->packages();
		$packages[] = $packages[0];

		$this->expectException( PudoValidationException::class );

		$sut->quote( $point, $packages );
	}

	/**
	 * @testdox Should leave out of the parcels what does not ship, as BlueX 3.3.1 does.
	 *
	 * `WC_Correios_Package::get_bultos()` salta lo que no necesita envío. Contarlo
	 * aquí cambia los bultos, la tarifa deja de cuadrar al centavo y el retiro en
	 * punto desaparece al elegir la agencia.
	 */
	public function test_skips_items_that_do_not_need_shipping(): void {
		$client   = new PudoApiClientStub( $this->valid_geolocation(), $this->paid_pricing() );
		$sut      = $this->provider( $client );
		$point    = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );
		$packages = $this->packages();
		$packages[0]['contents']['virtual'] = array(
			'product_id' => 43,
			'quantity'   => 2,
			'line_total' => 1000.0,
			'data'       => new PudoVirtualProductStub(),
		);

		$sut->quote( $point, $packages );

		self::assertCount( 1, $client->pricing_calls[0][3], 'Sólo el tomo físico viaja como bulto.' );
		self::assertSame( 18, $client->pricing_calls[0][3][0]['ancho'] );
	}

	/**
	 * @testdox Should send BlueX's defaults when a product has no measures or weight.
	 */
	public function test_missing_measures_fall_back_to_bluex_defaults(): void {
		$client = new PudoApiClientStub( $this->valid_geolocation(), $this->paid_pricing() );
		$sut    = $this->provider( $client );
		$point  = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );

		$sut->quote( $point, $this->packages( new PudoProductStub( '', '0', '', '0', '55000' ) ) );

		self::assertSame(
			array(
				'ancho'      => 10,
				'largo'      => 10,
				'alto'       => 10,
				'pesoFisico' => 0.01,
				'cantidad'   => 1,
			),
			$client->pricing_calls[0][3][0]
		);
	}

	/**
	 * @testdox Should refuse to quote an empty package instead of asking BlueX for nothing.
	 */
	public function test_rejects_empty_package(): void {
		$client   = new PudoApiClientStub( $this->valid_geolocation(), $this->paid_pricing() );
		$sut      = $this->provider( $client );
		$point    = $sut->resolve( 'AGENCY-123', 'Santiago', 'RM' );
		$packages = $this->packages();
		$packages[0]['contents'] = array();

		try {
			$sut->quote( $point, $packages );
			self::fail( 'Un paquete vacío no se cotiza.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( array(), $client->pricing_calls, 'No se llama a Blue.' );
		}
	}

	private function provider( PudoApiClientStub $client, float $discount_total = 0.0 ): PickupPointProvider {
		$settings = new PudoSettingsStub();

		return new PickupPointProvider(
			static fn(): object => $client,
			static fn(): object => $settings,
			static fn(): float => $discount_total
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function valid_geolocation(): array {
			return array(
				'porcentageDeExito' => '100%',
				'regionCode'        => 13,
				'nameRegion'        => 'Metropolitana de Santiago',
				'cidadeName'        => 'Santiago',
			'districtCode'      => 'SCL',
			'pickupInfo'        => array(
				'agency_id'      => 'AGENCY-123',
				'agency_name'    => 'Punto Blue Centro',
				'agency_address' => 'Av. Siempre Viva 123, Santiago',
				'location'       => array(
					'street_name'   => 'Av. Siempre Viva',
					'street_number' => '123',
					'city_name'     => 'Santiago',
						'state_name'    => 'Metropolitana de Santiago',
						'country_name'  => 'CL',
					'postcode'      => '8320000',
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function paid_pricing(): array {
		return array(
			'code' => '00',
			'data' => array(
				'total'          => 3990,
				'isShipmentFree' => false,
				'promiseDay'     => '2',
			),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function packages( ?PudoProductStub $product = null, float $contents_cost = 55000.0 ): array {
		return array(
			array(
				'contents'      => array(
					'cart-key' => array(
						'product_id' => 42,
						'quantity'   => 1,
						'line_total' => $contents_cost,
						'data'       => $product ?? new PudoProductStub(),
					),
				),
				'contents_cost' => $contents_cost,
				'destination'   => array(
					'country' => 'CL',
					'state'   => 'CL-RM',
					'city'    => 'Santiago',
				),
			),
		);
	}
}
