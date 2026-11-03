<?php
/**
 * Tests for the native PUDO Blocks adapter.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\CartCheckoutBlocks\BlocksPage;
use Akibara\CartCheckoutBlocks\PudoIntegration;
use Akibara\CartCheckoutBlocks\PudoServiceAdapter;
use PHPUnit\Framework\TestCase;

final class PudoIntegrationTest extends TestCase {

	/** @var array<int,string> Lo que PudoLog escribió en el registro de WooCommerce. */
	private array $registro = array();

	/**
	 * Loads isolated WooCommerce PUDO fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		// PudoLog consulta transients y el logger de WooCommerce. En la suite
		// completa `function_exists()` da true por contagio de otras pruebas y
		// Brain Monkey exige declararlos: sin esto, los caminos que registran un
		// fallo sólo pasaban corriendo esta clase sola.
		\Brain\Monkey\setUp();
		\Brain\Monkey\Functions\when( 'get_transient' )->justReturn( false );
		\Brain\Monkey\Functions\when( 'set_transient' )->justReturn( true );
		$registro = &$this->registro;
		\Brain\Monkey\Functions\when( 'wc_get_logger' )->justReturn(
			new class( $registro ) {
				/** @var array<int,string> */
				private array $registro;

				/** @param array<int,string> $registro Destino. */
				public function __construct( array &$registro ) {
					$this->registro = &$registro;
				}

				/** @param array<string,string> $context Contexto. */
				public function error( string $message, array $context = array() ): void {
					unset( $context );
					$this->registro[] = $message;
				}
			}
		);

		require_once __DIR__ . '/fixtures/pudo-runtime.php';
		$GLOBALS['akibara_test_pudo_update_callback'] = null;
		$GLOBALS['akibara_test_blocks_page']          = array(
			'cart'     => false,
			'checkout' => true,
		);
	}

	/**
	 * Clears shared runtime fixtures.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['akibara_test_woocommerce'],
			$GLOBALS['akibara_test_pudo_update_callback'],
			$GLOBALS['akibara_test_blocks_page']
		);

		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should replace the vendor callback under the exact bluex_pudo namespace.
	 */
	public function test_registers_authoritative_bluex_namespace_callback(): void {
		$sut = $this->integration( new PudoBusinessServiceStub(), $this->runtime() );

		$sut->register_store_api_update_callback();

		$registration = $GLOBALS['akibara_test_pudo_update_callback'];
		self::assertIsArray( $registration, 'The Store API callback should be registered.' );
		self::assertSame( 'bluex_pudo', $registration['namespace'] );
		self::assertSame( array( $sut, 'handle_store_api_update' ), $registration['callback'] );
	}

	/**
	 * @testdox Should resolve an agency through Business using only the server-side commune and region.
	 */
	public function test_resolves_selection_without_mutating_customer(): void {
		$service = new PudoBusinessServiceStub();
		$runtime = $this->runtime();
		$sut     = $this->integration( $service, $runtime );

		$sut->handle_store_api_update(
			array(
				'agency_id'   => 'AG-27136',
				'address'     => 'Dato mínimo del widget',
				'region_code' => 'CL-RM',
				'attempt_id'  => str_repeat( '1', 32 ),
			)
		);

		self::assertSame( 1, $service->canonicalize_calls );
		self::assertSame( 'Santiago', $service->server_address );
		self::assertSame( 'CL-RM', $service->server_region );
		self::assertSame( 'AG-27136', $service->request['agency_id'] );
		self::assertContains(
			'shipping_for_package_0',
			$runtime->session->unset_keys,
			'A successful selection should invalidate only its shipping package cache.'
		);
	}

	/**
	 * @testdox Should reject vendor presentation fields before Business sees the request.
	 */
	public function test_rejects_untrusted_vendor_presentation_fields(): void {
		$service = new PudoBusinessServiceStub();
		$sut     = $this->integration( $service, $this->runtime() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'datos no permitidos' );

		try {
			$sut->handle_store_api_update(
				array(
					'agency_id'      => 'AG-27136',
					'attempt_id'     => str_repeat( '2', 32 ),
					'agency_name'    => 'Nombre forjado',
					'agency_address' => 'Dirección forjada',
				)
			);
		} finally {
			self::assertSame( 0, $service->canonicalize_calls );
		}
	}

	/**
	 * @testdox Should reject selection writes without a fresh attempt correlation ID.
	 */
	public function test_rejects_missing_attempt_id_before_business_is_called(): void {
		$service = new PudoBusinessServiceStub();
		$sut     = $this->integration( $service, $this->runtime() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'formato válido' );

		try {
			$sut->handle_store_api_update( array( 'agency_id' => 'AG-27136' ) );
		} finally {
			self::assertSame( 0, $service->canonicalize_calls );
		}
	}

	/**
	 * @testdox Should fail closed and clear selection for a multi-package PUDO cart.
	 */
	public function test_rejects_multi_package_selection(): void {
		$service = new PudoBusinessServiceStub();
		$runtime = $this->runtime( array( $this->package(), $this->package( 'Valparaíso', 'CL-VS' ) ) );
		$sut     = $this->integration( $service, $runtime );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'más de un envío' );

		try {
			$sut->handle_store_api_update(
				array(
					'agency_id'  => 'AG-27136',
					'attempt_id' => str_repeat( '3', 32 ),
				)
			);
		} finally {
			self::assertSame( 1, $service->invalidate_calls );
			self::assertSame( 0, $service->canonicalize_calls );
		}
	}

	/**
	 * @testdox Should reject agency updates unless PUDO is the selected shipping method.
	 */
	public function test_rejects_selection_for_another_shipping_method(): void {
		$service = new PudoBusinessServiceStub();
		$runtime = $this->runtime();
		$runtime->session->values['chosen_shipping_methods'] = array( 'bluex-ex:3' );
		$sut = $this->integration( $service, $runtime );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'antes de elegir una agencia' );

		try {
			$sut->handle_store_api_update(
				array(
					'agency_id'  => 'AG-27136',
					'attempt_id' => str_repeat( '4', 32 ),
				)
			);
		} finally {
			self::assertSame( 1, $service->invalidate_calls );
		}
	}

	/**
	 * @testdox Should clear canonical selection when the client explicitly leaves PUDO.
	 */
	public function test_empty_agency_clears_selection_without_customer_writes(): void {
		$service = new PudoBusinessServiceStub();
		$runtime = $this->runtime();
		$sut     = $this->integration( $service, $runtime );

		$sut->handle_store_api_update(
			array(
				'agency_id'  => '',
				'attempt_id' => str_repeat( '5', 32 ),
			)
		);

		self::assertSame( 1, $service->clear_calls );
		self::assertContains( 'shipping_for_package_0', $runtime->session->unset_keys );
	}

	/**
	 * @testdox Should tombstone PUDO when the official Store API selects home delivery.
	 */
	public function test_non_pudo_shipping_transition_invalidates_inflight_selection(): void {
		$service = new PudoBusinessServiceStub();
		$runtime = $this->runtime();
		$sut     = $this->integration( $service, $runtime );

		$sut->handle_shipping_rate_selected( '0', 'bluex-ex:4', new \stdClass() );

		self::assertSame( 1, $service->invalidate_calls );
	}

	/**
	 * @testdox Should stamp packages from Business authority before WooCommerce cache lookup.
	 */
	public function test_prepares_shipping_packages_before_cache_lookup(): void {
		$service  = new PudoBusinessServiceStub();
		$runtime  = $this->runtime();
		$sut      = $this->integration( $service, $runtime );
		$packages = array( $this->package() );

		$prepared = $sut->prepare_shipping_packages( $packages );

		self::assertSame( 1, $service->prepare_calls );
		self::assertSame( 'business-generation', $prepared[0]['_akb_pudo_generation'] );
		self::assertSame(
			$runtime->session,
			$service->prepared_session,
			'The pre-hash hook must reconcile the current WooCommerce session without recursively loading packages.'
		);
	}

	/**
	 * @testdox Should invalidate old package caches when the Business contract is unavailable.
	 */
	public function test_stamps_unavailable_business_contract_fail_closed(): void {
		$runtime = $this->runtime();
		$GLOBALS['akibara_test_woocommerce'] = $runtime;
		$sut = new PudoIntegration( new \stdClass(), new BlocksPage() );

		$prepared = $sut->prepare_shipping_packages( array( $this->package() ) );

		self::assertMatchesRegularExpression(
			'/\A[a-f0-9]{64}\z/',
			$prepared[0]['_akb_pudo_generation'],
			'Adding a stable unavailable generation prevents reuse of a pre-release PUDO cache.'
		);
	}

	/**
	 * @testdox Should delegate rates with all calculated packages and preserve the initial PUDO wrapper.
	 */
	public function test_filters_rates_with_complete_calculated_packages(): void {
		$service     = new PudoBusinessServiceStub();
		$calculated  = $this->package();
		$calculated['rates'] = array(
			'bluex-pudo:3' => array(
				'method_id' => 'bluex-pudo',
				'meta_data' => array( '_bluex_pudo_quoted' => 'yes' ),
			),
		);
		$runtime = $this->runtime( null, array( $calculated ) );
		$sut     = $this->integration( $service, $runtime );
		$rates   = array(
			'bluex-pudo:3' => array( 'method_id' => 'bluex-pudo' ),
			'bluex-ex:4'   => array( 'method_id' => 'bluex-ex' ),
		);

		$filtered = $sut->filter_package_rates( $rates, $this->package() );

		self::assertSame( $rates, $filtered, 'Business should preserve the initial unselected PUDO wrapper.' );
		self::assertSame( 1, $service->filter_calls );
		self::assertSame( 1, $service->filter_package_count );
		self::assertArrayHasKey( 'rates', $service->filtered_packages[0] );
	}

	/**
	 * @testdox Should preserve the complete cart package set while Woo shipping calculation is partial.
	 */
	public function test_does_not_collapse_multi_package_cart_during_partial_shipping_calculation(): void {
		$service    = new PudoBusinessServiceStub();
		$first      = $this->package();
		$second     = $this->package( 'Valparaíso', 'CL-VS' );
		$calculated = $first;
		$calculated['rates'] = array(
			'bluex-pudo:3' => array( 'method_id' => 'bluex-pudo' ),
		);
		$runtime = $this->runtime( array( $first, $second ), array( $calculated ) );
		$sut     = $this->integration( $service, $runtime );

		$sut->filter_package_rates(
			array( 'bluex-pudo:3' => array( 'method_id' => 'bluex-pudo' ) ),
			$first
		);

		self::assertSame(
			2,
			$service->filter_package_count,
			'PUDO must see the complete multi-package cart even while WC_Shipping has calculated only one package.'
		);
	}

	/**
	 * @testdox Should obey Business when it removes an unsafe selected PUDO rate.
	 */
	public function test_removes_rate_rejected_by_business(): void {
		$service = new PudoBusinessServiceStub();
		$service->filter_result = array(
			'bluex-ex:4' => array( 'method_id' => 'bluex-ex' ),
		);
		$sut   = $this->integration( $service, $this->runtime() );
		$rates = array(
			'bluex-pudo:3' => array( 'method_id' => 'bluex-pudo' ),
			'bluex-ex:4'   => array( 'method_id' => 'bluex-ex' ),
		);

		$filtered = $sut->filter_package_rates( $rates, $this->package() );

		self::assertArrayNotHasKey( 'bluex-pudo:3', $filtered );
		self::assertArrayHasKey( 'bluex-ex:4', $filtered );
	}

	/**
	 * @testdox Should replace checkout request data with the canonical Business selection before BlueX.
	 */
	public function test_rewrites_checkout_extension_before_vendor_hook(): void {
		$service = new PudoBusinessServiceStub();
		$runtime = $this->runtime();
		$sut     = $this->integration( $service, $runtime );
		$order   = new \Akibara_Test_Pudo_Order(
			array( new \Akibara_Test_Pudo_Shipping_Item( 'bluex-pudo' ) )
		);
		$request = new \Akibara_Test_Pudo_Request(
			array(
				'bluex_pudo' => array(
					'agency_id'      => 'FORGED',
					'agency_name'    => 'Forjado',
					'agency_address' => 'Forjada',
				),
				'another_extension' => array( 'kept' => true ),
			)
		);

		$sut->rewrite_checkout_request( $order, $request );

		self::assertSame( 1, $service->validate_calls );
		self::assertSame( 1, $service->release_calls );
			self::assertSame(
				array(
				'agency_id'      => 'AG-27136',
				'agency_name'    => 'Punto Akibara',
				'agency_address' => 'Santiago',
			),
			$request->params['extensions']['bluex_pudo']
		);
		self::assertSame( array( 'kept' => true ), $request->params['extensions']['another_extension'] );
		self::assertArrayHasKey( 'rates', $service->validated_packages[0] );
		self::assertSame(
			array(
				'agencyId'       => 'AG-27136',
				'isPudoSelected' => 'pudoShipping',
				'agencyName'     => 'Punto Akibara',
				'agencyAddress'  => 'Santiago',
			),
				$order->meta,
				'The adapter should persist only the server-validated BlueX order contract.'
			);
			self::assertSame(
				array(
					'address_1' => 'Av. Providencia 1234',
					'address_2' => 'Punto Akibara',
					'city'      => 'Santiago',
					'state'     => 'CL-RM',
					'country'   => 'CL',
					'postcode'  => '7500000',
				),
				$order->shipping_address,
				'Only the order shipping address should change to the server-confirmed agency.'
			);
			self::assertSame(
				array( 'address_1' => 'Facturación original' ),
				$order->billing_address,
				'PUDO must never overwrite billing data.'
			);
		}

	/**
	 * @testdox Should recognize and persist a PUDO line already normalized by BlueX.
	 */
	public function test_handles_bluex_pre_rewritten_shipping_item(): void {
		$service = new PudoBusinessServiceStub();
		$sut     = $this->integration( $service, $this->runtime() );
		$order   = new \Akibara_Test_Pudo_Order(
			array(
				new \Akibara_Test_Pudo_Shipping_Item(
					'bluex-ex',
					array( '_bluex_original_method' => 'bluex-pudo' )
				),
			)
		);
		$request = new \Akibara_Test_Pudo_Request();

		$sut->rewrite_checkout_request( $order, $request );

		self::assertSame( 1, $service->validate_calls );
		self::assertSame( 'AG-27136', $order->meta['agencyId'] );
		self::assertSame( 'pudoShipping', $order->meta['isPudoSelected'] );
		self::assertSame( 'AG-27136', $request->params['extensions']['bluex_pudo']['agency_id'] );
	}

	/**
	 * @testdox Should fail closed when Business does not confirm a PUDO checkout.
	 */
	public function test_rejects_non_pudo_validation_result_for_pudo_order(): void {
		$service = new PudoBusinessServiceStub();
		$service->validate_is_pudo = false;
		$sut     = $this->integration( $service, $this->runtime() );
		$order   = new \Akibara_Test_Pudo_Order(
			array( new \Akibara_Test_Pudo_Shipping_Item( 'bluex-pudo' ) )
		);
		$request = new \Akibara_Test_Pudo_Request();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ya no están disponibles' );

		try {
			$sut->rewrite_checkout_request( $order, $request );
		} finally {
			self::assertSame( 1, $service->clear_calls );
			self::assertArrayNotHasKey( 'bluex_pudo', $request->params['extensions'] );
		}
	}

	/**
	 * @testdox Should fail closed when Business does not provide a complete agency shipping address.
	 */
	public function test_rejects_incomplete_order_shipping_address(): void {
		$service = new PudoBusinessServiceStub();
		$service->selection['shipping_address']['country'] = 'AR';
		$sut     = $this->integration( $service, $this->runtime() );
		$order   = new \Akibara_Test_Pudo_Order(
			array( new \Akibara_Test_Pudo_Shipping_Item( 'bluex-pudo' ) )
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ya no están disponibles' );

		try {
			$sut->rewrite_checkout_request( $order, new \Akibara_Test_Pudo_Request() );
		} finally {
			self::assertSame( 1, $service->clear_calls );
			self::assertSame( array( 'address_1' => 'Dirección residencial original' ), $order->shipping_address );
			self::assertSame( array(), $order->meta );
		}
	}

	/**
	 * @testdox Should strip stale bluex_pudo data from a home-delivery checkout.
	 */
	public function test_strips_stale_extension_from_non_pudo_order(): void {
		$service = new PudoBusinessServiceStub();
		$sut     = $this->integration( $service, $this->runtime() );
		$order   = new \Akibara_Test_Pudo_Order(
			array( new \Akibara_Test_Pudo_Shipping_Item( 'bluex-ex' ) )
		);
		$request = new \Akibara_Test_Pudo_Request(
			array(
				'bluex_pudo' => array( 'agency_id' => 'STALE' ),
				'payment'    => array( 'kept' => true ),
			)
		);

		$sut->rewrite_checkout_request( $order, $request );

		self::assertArrayNotHasKey( 'bluex_pudo', $request->params['extensions'] );
		self::assertSame( array( 'kept' => true ), $request->params['extensions']['payment'] );
		self::assertSame( 0, $service->validate_calls );
	}

	/**
	 * @testdox Should not trust a BlueX delivery line with a forged original-method marker.
	 */
	public function test_rejects_non_exact_bluex_original_method_marker(): void {
		$service = new PudoBusinessServiceStub();
		$sut     = $this->integration( $service, $this->runtime() );
		$order   = new \Akibara_Test_Pudo_Order(
			array(
				new \Akibara_Test_Pudo_Shipping_Item(
					'bluex-ex',
					array( '_bluex_original_method' => 'bluex-pudo:112' )
				),
			)
		);
		$request = new \Akibara_Test_Pudo_Request(
			array( 'bluex_pudo' => array( 'agency_id' => 'STALE' ) )
		);

		$sut->rewrite_checkout_request( $order, $request );

		self::assertSame( 0, $service->validate_calls );
		self::assertArrayNotHasKey( 'bluex_pudo', $request->params['extensions'] );
		self::assertSame( array(), $order->meta );
	}

	/**
	 * @testdox Should feature-detect the entire Business contract before accepting PUDO writes.
	 */
	public function test_adapter_requires_complete_business_contract(): void {
		$adapter = new PudoServiceAdapter(
			new class() {
				public function clear_selection( object $session ): void {
					unset( $session );
				}
			}
		);

		self::assertFalse( $adapter->is_available() );
	}

	/**
	 * @testdox Should gate only on the methods the transport really calls.
	 *
	 * El gate exigía además `select_agency`, `get_selection` y
	 * `cart_fingerprint`, que ningún llamador usaba. Con eso, renombrar
	 * cualquiera de los tres en Business ponía `is_available()` en false,
	 * `runtime()` devolvía null y `filter_package_rates` retiraba TODAS las
	 * tarifas PUDO: el retiro en punto desaparecía del checkout por un método
	 * muerto.
	 */
	public function test_adapter_ignores_methods_the_transport_never_calls(): void {
		$adapter = new PudoServiceAdapter(
			new class() {
				/**
				 * @param array<string,string> $request  Client request.
				 * @param array<int,array>     $packages Shipping packages.
				 * @return array<string,mixed>
				 */
				public function canonicalize_request(
					array $request,
					string $server_address,
					string $server_region,
					array $packages,
					object $session
				): array {
					unset( $request, $server_address, $server_region, $packages, $session );

					return array();
				}

				public function clear_selection( object $session, ?string $attempt_id = null ): void {
					unset( $session, $attempt_id );
				}

				public function invalidate_selection( object $session ): void {
					unset( $session );
				}

				/**
				 * @param array<int,array> $packages Shipping packages.
				 * @return array<int,array>
				 */
				public function prepare_shipping_packages( array $packages, object $session ): array {
					unset( $session );

					return $packages;
				}

				/**
				 * @param array<string|int,mixed> $rates    Package rates.
				 * @param array<int,array>        $packages Shipping packages.
				 * @return array<string|int,mixed>
				 */
				public function filter_package_rates( array $rates, array $packages, object $session ): array {
					unset( $packages, $session );

					return $rates;
				}

				/**
				 * @param array<int,array>  $packages       Shipping packages.
				 * @param array<int,string> $chosen_methods Chosen shipping methods.
				 * @return array<string,mixed>
				 */
				public function validate_checkout( array $packages, array $chosen_methods, object $session ): array {
					unset( $packages, $chosen_methods, $session );

					return array();
				}

				/**
				 * @param array<string,mixed> $claim Checkout claim.
				 */
				public function release_checkout_claim( array $claim ): void {
					unset( $claim );
				}
			}
		);

		self::assertTrue(
			$adapter->is_available(),
			'Sin select_agency, get_selection ni cart_fingerprint el transporte funciona igual.'
		);
		foreach ( array( 'select_agency', 'get_selection', 'cart_fingerprint' ) as $retirado ) {
			self::assertFalse(
				method_exists( PudoServiceAdapter::class, $retirado ),
				"El envoltorio {$retirado} no tenía llamadores: reponerlo devuelve el gate a un método muerto."
			);
		}
	}

	/**
	 * @testdox Should never address WC_Customer or third-party source files.
	 */
	public function test_source_uses_only_public_transport_and_asset_boundaries(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/PudoIntegration.php' );

		self::assertIsString( $source );
		self::assertStringNotContainsString( '->customer', $source );
		self::assertStringNotContainsString( 'WC()->customer', $source );
		self::assertStringNotContainsString( 'set_billing_', $source );
		self::assertStringContainsString( "'address_1' => 'set_shipping_address_1'", $source );
		self::assertStringContainsString( "private const VENDOR_SCRIPT = 'bluex-blocks-pudo-integration';", $source );
		self::assertStringContainsString( "private const VENDOR_STYLE = 'bluex-blocks-pudo-css';", $source );
		self::assertStringContainsString(
			"'woocommerce_store_api_checkout_update_order_from_request', array( \$this, 'rewrite_checkout_request' ), 1, 2",
			$source
		);
		self::assertStringContainsString(
			"'woocommerce_blocks_loaded', array( \$this, 'register_store_api_update_callback' ), 1000",
			$source
		);
		self::assertStringContainsString(
			"'woocommerce_cart_shipping_packages', array( \$this, 'prepare_shipping_packages' ), PHP_INT_MAX",
			$source
		);
	}

	/**
	 * @testdox Una limpieza que falla dentro del filtro de tarifas no rompe el carrito.
	 *
	 * S-7fd65b78: el catch de `filter_package_rates` volvía a llamar al servicio
	 * que acababa de fallar, fuera de todo try. Si esa limpieza lanzaba, la
	 * excepción escapaba de un filtro de WooCommerce y el carrito entero caía.
	 */
	public function test_rate_filter_survives_a_failing_cleanup(): void {
		$service                 = new PudoBusinessServiceStub();
		$service->filter_throws  = new \RuntimeException( 'Blue Express no responde.' );
		$service->cleanup_throws = new \InvalidArgumentException( 'La sesión no expone get/set.' );
		$sut   = $this->integration( $service, $this->runtime() );
		$rates = array(
			'bluex-pudo:3' => array( 'method_id' => 'bluex-pudo' ),
			'bluex-ex:4'   => array( 'method_id' => 'bluex-ex' ),
		);

		$filtered = $sut->filter_package_rates( $rates, $this->package() );

		self::assertArrayNotHasKey( 'bluex-pudo:3', $filtered, 'Sin servicio, el retiro en punto se retira…' );
		self::assertArrayHasKey( 'bluex-ex:4', $filtered, '…y el despacho a domicilio sigue a la venta.' );
		self::assertSame( 1, $service->clear_calls );
		self::assertCount( 2, $this->registro, 'Los dos fallos quedan en el registro: el del servicio y el de la limpieza.' );
		self::assertStringStartsWith( 'filter_package_rates.clear_selection:', $this->registro[1] );
	}

	/**
	 * @testdox Un claim de pago que no se puede soltar no convierte un pago válido en un 500.
	 *
	 * S-cd119862: `release_checkout_claim()` corría en un `finally` sin
	 * protección. El claim vence solo a los 30 segundos; el pago no puede
	 * caerse por no haberlo soltado antes.
	 */
	public function test_a_failing_claim_release_does_not_break_a_valid_checkout(): void {
		$service                 = new PudoBusinessServiceStub();
		$service->cleanup_throws = new \InvalidArgumentException( 'Invalid PUDO checkout claim.' );
		$sut     = $this->integration( $service, $this->runtime() );
		$order   = new \Akibara_Test_Pudo_Order( array( new \Akibara_Test_Pudo_Shipping_Item( 'bluex-pudo' ) ) );
		$request = new \Akibara_Test_Pudo_Request();

		$sut->rewrite_checkout_request( $order, $request );

		self::assertSame( 1, $service->release_calls );
		self::assertSame( array( 'rewrite_checkout_request.release_checkout_claim: Invalid PUDO checkout claim. (InvalidArgumentException en PudoIntegrationTest.php:' ), array( substr( $this->registro[0] ?? '', 0, strlen( 'rewrite_checkout_request.release_checkout_claim: Invalid PUDO checkout claim. (InvalidArgumentException en PudoIntegrationTest.php:' ) ) ), 'El fallo del release queda en el registro con su propio contexto.' );
		self::assertSame( 'AG-27136', $request->params['extensions']['bluex_pudo']['agency_id'] );
		self::assertSame( 'AG-27136', $order->meta['agencyId'] );
	}

	/**
	 * @testdox Ni la limpieza ni el claim tapan el aviso de un punto que ya no sirve.
	 */
	public function test_failing_cleanup_and_release_do_not_hide_the_customer_error(): void {
		$service = new PudoBusinessServiceStub();
		$service->selection['shipping_address']['country'] = 'AR';
		$service->cleanup_throws = new \InvalidArgumentException( 'Invalid PUDO checkout claim.' );
		$sut   = $this->integration( $service, $this->runtime() );
		$order = new \Akibara_Test_Pudo_Order( array( new \Akibara_Test_Pudo_Shipping_Item( 'bluex-pudo' ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ya no están disponibles' );

		try {
			$sut->rewrite_checkout_request( $order, new \Akibara_Test_Pudo_Request() );
		} finally {
			self::assertSame( 1, $service->clear_calls );
			self::assertSame( 1, $service->release_calls );
		}
	}

	/**
	 * @testdox Una validación caída con limpieza caída sigue explicándose al cliente.
	 */
	public function test_failing_cleanup_after_a_failed_validation_still_explains(): void {
		$service                  = new PudoBusinessServiceStub();
		$service->validate_throws = new \LogicException( 'Blue Express caído.' );
		$service->cleanup_throws  = new \InvalidArgumentException( 'La sesión no expone get/set.' );
		$sut   = $this->integration( $service, $this->runtime() );
		$order = new \Akibara_Test_Pudo_Order( array( new \Akibara_Test_Pudo_Shipping_Item( 'bluex-pudo' ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ya no están disponibles' );

		$sut->rewrite_checkout_request( $order, new \Akibara_Test_Pudo_Request() );
	}

	/**
	 * @testdox Una lápida que falla no tapa el aviso de pedido dividido.
	 */
	public function test_failing_tombstone_does_not_hide_the_multi_package_error(): void {
		$service                 = new PudoBusinessServiceStub();
		$service->cleanup_throws = new \InvalidArgumentException( 'La sesión no expone get/set.' );
		$runtime = $this->runtime( array( $this->package(), $this->package( 'Valparaíso', 'CL-VS' ) ) );
		$sut     = $this->integration( $service, $runtime );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'más de un envío' );

		$sut->handle_store_api_update(
			array(
				'agency_id'  => 'AG-27136',
				'attempt_id' => str_repeat( '6', 32 ),
			)
		);
	}

	/**
	 * @testdox Una lápida que falla no tapa el aviso de método de envío equivocado.
	 */
	public function test_failing_tombstone_does_not_hide_the_wrong_method_error(): void {
		$service                 = new PudoBusinessServiceStub();
		$service->cleanup_throws = new \InvalidArgumentException( 'La sesión no expone get/set.' );
		$runtime = $this->runtime();
		$runtime->session->values['chosen_shipping_methods'] = array( 'bluex-ex:3' );
		$sut = $this->integration( $service, $runtime );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'antes de elegir una agencia' );

		$sut->handle_store_api_update(
			array(
				'agency_id'  => 'AG-27136',
				'attempt_id' => str_repeat( '7', 32 ),
			)
		);
	}

	/**
	 * @testdox Una lápida que falla no tapa el aviso de punto no confirmado.
	 */
	public function test_failing_tombstone_does_not_hide_the_mismatch_error(): void {
		$service                         = new PudoBusinessServiceStub();
		$service->selection['agency_id'] = 'AG-OTRA';
		$service->cleanup_throws         = new \InvalidArgumentException( 'La sesión no expone get/set.' );
		$sut = $this->integration( $service, $this->runtime() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'no confirmó el punto' );

		$sut->handle_store_api_update(
			array(
				'agency_id'  => 'AG-27136',
				'attempt_id' => str_repeat( '8', 32 ),
			)
		);
	}

	/**
	 * @param array<int,array>|null $packages Shipping packages.
	 */
	private function runtime(
		?array $packages = null,
		?array $calculated_packages = null
	): \Akibara_Test_Pudo_Runtime {
		$session = new \Akibara_Test_Pudo_Session(
			array( 'chosen_shipping_methods' => array( 'bluex-pudo:3' ) )
		);
		$cart_packages = $packages ?? array( $this->package() );
		if ( null === $calculated_packages ) {
			$calculated = $cart_packages;
			$calculated[0]['rates'] = array(
				'bluex-pudo:3' => array(
					'method_id' => 'bluex-pudo',
					'meta_data' => array( '_bluex_pudo_quoted' => 'yes' ),
				),
			);
		} else {
			$calculated = $calculated_packages;
		}

		return new \Akibara_Test_Pudo_Runtime(
			new \Akibara_Test_Pudo_Cart( $cart_packages ),
			$session,
			$calculated
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function package( string $city = 'Santiago', string $state = 'CL-RM' ): array {
		return array(
			'destination' => array(
				'address_1' => 'Dirección que Blocks no debe enviar a BlueX Geo',
				'city'      => $city,
				'state'     => $state,
				'country'   => 'CL',
			),
			'contents'    => array( 'cart-key' => array( 'quantity' => 1 ) ),
		);
	}

	private function integration(
		PudoBusinessServiceStub $service,
		\Akibara_Test_Pudo_Runtime $runtime
	): PudoIntegration {
		$GLOBALS['akibara_test_woocommerce'] = $runtime;

		return new PudoIntegration( $service, new BlocksPage() );
	}
}

final class PudoBusinessServiceStub {

	public int $canonicalize_calls = 0;

	public int $clear_calls = 0;

	public int $invalidate_calls = 0;

	public int $validate_calls = 0;

	public int $release_calls = 0;

	public int $filter_calls = 0;

	public int $prepare_calls = 0;

	public int $filter_package_count = 0;

	public string $server_address = '';

	public string $server_region = '';

	/** @var array<string,string> */
	public array $request = array();

	/** @var array<int,array> */
	public array $filtered_packages = array();

	/** @var array<int,array> */
	public array $validated_packages = array();

	public ?object $prepared_session = null;

	/** @var array<string|int,mixed>|null */
	public ?array $filter_result = null;

	public bool $validate_is_pudo = true;

	/** Lo que lanzan las limpiezas (clear, invalidate, release): la caja no puede depender de que funcionen. */
	public ?\Throwable $cleanup_throws = null;

	/** Lo que lanza el filtro de tarifas de Business (Blue Express caído). */
	public ?\Throwable $filter_throws = null;

	/** Lo que lanza la validación final del pago. */
	public ?\Throwable $validate_throws = null;

	/** @var array<string,mixed> */
	public array $selection = array(
		'agency_id'        => 'AG-27136',
		'agency_name'      => 'Punto Akibara',
		'agency_address'   => 'Santiago',
		'shipping_address' => array(
			'address_1' => 'Av. Providencia 1234',
			'address_2' => 'Punto Akibara',
			'city'      => 'Santiago',
			'state'     => 'CL-RM',
			'country'   => 'CL',
			'postcode'  => '7500000',
		),
	);

	/**
	 * @param array<string,string> $request Client request.
	 * @param array<int,array>     $packages Shipping packages.
	 * @return array<string,mixed>
	 */
	public function canonicalize_request(
		array $request,
		string $server_address,
		string $server_region,
		array $packages,
		object $session
	): array {
		unset( $packages, $session );
		++$this->canonicalize_calls;
		$this->request        = $request;
		$this->server_address = $server_address;
		$this->server_region  = $server_region;

		return $this->selection;
	}

	/**
	 * @param array<int,array> $packages Shipping packages.
	 * @return array<string,mixed>
	 */
	public function select_agency(
		string $agency_id,
		string $server_address,
		string $server_region,
		array $packages,
		object $session
	): array {
		unset( $agency_id, $server_address, $server_region, $packages, $session );

		return $this->selection;
	}

	/**
	 * @param array<int,array> $packages Shipping packages.
	 * @return array<string,mixed>
	 */
	public function get_selection( array $packages, object $session ): array {
		unset( $packages, $session );

		return $this->selection;
	}

	public function clear_selection( object $session, ?string $attempt_id = null ): void {
		unset( $session, $attempt_id );
		++$this->clear_calls;
		if ( null !== $this->cleanup_throws ) {
			throw $this->cleanup_throws;
		}
	}

	public function invalidate_selection( object $session ): void {
		unset( $session );
		++$this->invalidate_calls;
		if ( null !== $this->cleanup_throws ) {
			throw $this->cleanup_throws;
		}
	}

	/**
	 * @param array<int,array> $packages Shipping packages.
	 */
	public function cart_fingerprint( array $packages ): string {
		unset( $packages );

		return 'fingerprint';
	}

	/**
	 * @param array<int,array> $packages Shipping packages.
	 * @return array<int,array>
	 */
	public function prepare_shipping_packages( array $packages, object $session ): array {
		++$this->prepare_calls;
		$this->prepared_session = $session;
		foreach ( $packages as $key => $package ) {
			$packages[ $key ]['_akb_pudo_generation'] = 'business-generation';
		}

		return $packages;
	}

	/**
	 * @param array<string|int,mixed> $rates    Package rates.
	 * @param array<int,array>        $packages Shipping packages.
	 * @return array<string|int,mixed>
	 */
	public function filter_package_rates( array $rates, array $packages, object $session ): array {
		unset( $session );
		++$this->filter_calls;
		$this->filter_package_count = count( $packages );
		$this->filtered_packages    = $packages;
		if ( null !== $this->filter_throws ) {
			throw $this->filter_throws;
		}

		return $this->filter_result ?? $rates;
	}

	/**
	 * @param array<int,array>  $packages       Shipping packages.
	 * @param array<int,string> $chosen_methods Chosen methods.
	 * @return array<string,mixed>
	 */
	public function validate_checkout( array $packages, array $chosen_methods, object $session ): array {
		unset( $chosen_methods, $session );
		++$this->validate_calls;
		$this->validated_packages = $packages;
		if ( null !== $this->validate_throws ) {
			throw $this->validate_throws;
		}

		return array(
			'is_pudo'         => $this->validate_is_pudo,
			'selection'       => $this->validate_is_pudo ? $this->selection : null,
			'shipping_method' => $this->validate_is_pudo ? 'bluex-pudo' : null,
			'checkout_claim'  => $this->validate_is_pudo
				? array( 'version_token' => str_repeat( 'a', 64 ) )
				: null,
		);
	}

	/**
	 * @param array<string,mixed> $claim Checkout claim.
	 */
	public function release_checkout_claim( array $claim ): void {
		unset( $claim );
		++$this->release_calls;
		if ( null !== $this->cleanup_throws ) {
			throw $this->cleanup_throws;
		}
	}
}
