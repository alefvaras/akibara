<?php
/**
 * Tests for the first-party PUDO checkout domain service.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Integrations\Bluex\Pudo;

require_once __DIR__ . '/PudoTestDoubles.php';

use Akibara\Business\Integrations\Bluex\Pudo\PickupPointProvider;
use Akibara\Business\Integrations\Bluex\Pudo\PudoCheckoutService;
use Akibara\Business\Integrations\Bluex\Pudo\PudoMutationGuard;
use Akibara\Business\Integrations\Bluex\Pudo\PudoMutationRepository;
use Akibara\Business\Integrations\Bluex\Pudo\PudoSelectionStore;
use Akibara\Business\Integrations\Bluex\Pudo\PudoValidationException;
use PHPUnit\Framework\TestCase;

final class PudoCheckoutServiceTest extends TestCase {

	/**
	 * @testdox Should trust only agency ID from the client request.
	 */
	public function test_canonicalizes_request_using_provider_data_only(): void {
		$sut      = $this->service();
		$session  = new PudoSessionStub();
		$packages = $this->packages();

		$selection = $sut->canonicalize_request(
			array(
				'agency_id'       => 'AGENCY-123',
				'attempt_id'      => str_repeat( '1', 32 ),
				'agency_name'     => 'Nombre falsificado',
				'agency_address'  => 'Dirección falsificada',
				'shipping_address' => array( 'address_1' => 'Ataque' ),
			),
			'Santiago',
			'CL-RM',
			$packages,
			$session,
			1000
		);

		self::assertIsArray( $selection );
		self::assertSame( 'Punto Blue Centro', $selection['agency_name'] );
		self::assertSame( 'Av. Siempre Viva 123, Santiago', $selection['agency_address'] );
		self::assertSame( 'Av. Siempre Viva 123', $selection['shipping_address']['address_1'] );
		self::assertSame( 'Punto Blue Centro', $session->get( 'bluex_agency_name' ) );
	}

	/**
	 * @testdox Should clear selection when the request has no agency ID.
	 */
	public function test_empty_request_clears_selection(): void {
		$sut      = $this->service();
		$session  = new PudoSessionStub();
		$packages = $this->packages();
		$sut->select_agency( 'AGENCY-123', 'Santiago', 'RM', $packages, $session, 1000 );

		$result = $sut->canonicalize_request(
			array(
				'agency_id'  => '',
				'attempt_id' => str_repeat( '2', 32 ),
			),
			'Santiago',
			'RM',
			$packages,
			$session,
			1100
		);

		self::assertNull( $result );
		self::assertNull( $session->get( 'bluex_agency_id' ) );
	}

	/**
	 * @testdox Should clear stale state before a replacement selection is validated.
	 */
	public function test_failed_replacement_selection_cannot_reuse_stale_state(): void {
		$sut      = $this->service();
		$session  = new PudoSessionStub();
		$packages = $this->packages();
		$sut->select_agency( 'AGENCY-123', 'Santiago', 'RM', $packages, $session, 1000 );

		try {
			$sut->select_agency( 'ATTACKER-999', 'Santiago', 'RM', $packages, $session, 1100 );
			self::fail( 'A provider agency mismatch must fail closed.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'bluex_agency_mismatch', $exception->error_code() );
		}

		self::assertNull( $session->get( 'akb_pudo_selection' ) );
		self::assertNull( $session->get( 'bluex_agency_id' ) );
		self::assertNull( $session->get( 'bluex_pudo_selected' ) );
	}

	/**
	 * @testdox Should produce one stable fingerprint and invalidate it on cart mutation.
	 */
	public function test_cart_fingerprint_tracks_cart_mutations(): void {
		$sut      = $this->service();
		$packages = $this->packages();
		$first    = $sut->cart_fingerprint( $packages );
		$second   = $sut->cart_fingerprint( $packages );

		$packages[0]['contents']['cart-key']['quantity'] = 2;
		$mutated = $sut->cart_fingerprint( $packages );
		$packages[0]['_akb_pudo_generation'] = str_repeat( 'a', 64 );
		$stamped = $sut->cart_fingerprint( $packages );

		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $first );
		self::assertSame( $first, $second );
		self::assertNotSame( $first, $mutated );
		self::assertSame( $mutated, $stamped, 'The cache-only authority stamp must not enter the cart fingerprint.' );
	}

	/**
	 * @testdox Should replace stale session and shipping cache generations from durable authority.
	 */
	public function test_prepares_shipping_cache_from_durable_generation(): void {
		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$service_a  = $this->coordinated_service( $repository, $tokens, new PudoInterleavingApiClient() );
		$service_b  = $this->coordinated_service( $repository, $tokens, new PudoInterleavingApiClient() );
		$session_a  = new PudoSessionStub( 'shared-cache-session' );
		$session_b  = new PudoSessionStub( 'shared-cache-session' );
		$packages   = $this->packages();

		$service_a->select_agency(
			'AGENCY-A',
			'Santiago',
			'RM',
			$packages,
			$session_a,
			1000,
			str_repeat( 'a', 32 )
		);
		$cache_a = $service_a->prepare_shipping_packages( $packages, $session_a, 1001 );

		$pending_guard = new PudoMutationGuard(
			$repository,
			static fn( object $session ): string => $session->get_customer_id(),
			$tokens,
			str_repeat( 'k', 64 )
		);
		$pending_guard->begin_selection(
			$session_b,
			str_repeat( 'c', 32 ),
			$service_a->cart_fingerprint( $packages ),
			1005
		);
		$cache_pending = $service_a->prepare_shipping_packages( $packages, $session_a, 1006 );
		self::assertNull( $session_a->get( 'bluex_agency_id' ) );
		self::assertNotSame(
			$cache_a[0]['_akb_pudo_generation'],
			$cache_pending[0]['_akb_pudo_generation'],
			'A newer pending B must invalidate cached rates and clear the stale A projection.'
		);

		$session_a->set( 'bluex_agency_id', 'AGENCY-A' );
		$service_b->select_agency(
			'AGENCY-B',
			'Santiago',
			'RM',
			$packages,
			$session_b,
			1010,
			str_repeat( 'b', 32 )
		);
		self::assertSame( 'AGENCY-A', $session_a->get( 'bluex_agency_id' ), 'Session A simulates a late stale snapshot.' );

		$cache_b = $service_a->prepare_shipping_packages( $packages, $session_a, 1011 );
		self::assertSame( 'AGENCY-B', $session_a->get( 'bluex_agency_id' ) );
		self::assertNotSame(
			$cache_a[0]['_akb_pudo_generation'],
			$cache_b[0]['_akb_pudo_generation'],
			'Durable B must force a new WooCommerce package hash before stale A rates can be reused.'
		);

		$service_b->invalidate_selection( $session_b, 1020 );
		$cache_clear = $service_a->prepare_shipping_packages( $packages, $session_a, 1021 );
		self::assertNull( $session_a->get( 'bluex_agency_id' ) );
		self::assertNotSame(
			$cache_b[0]['_akb_pudo_generation'],
			$cache_clear[0]['_akb_pudo_generation'],
			'A clear tombstone must invalidate the committed B package cache.'
		);

		$cache_none = $service_a->prepare_shipping_packages(
			$packages,
			new PudoSessionStub( 'session-without-authority' ),
			1021
		);
		self::assertNotSame(
			$cache_clear[0]['_akb_pudo_generation'],
			$cache_none[0]['_akb_pudo_generation'],
			'A tombstone and no authority must remain distinct cache generations.'
		);
		self::assertSame(
			$service_a->cart_fingerprint( $packages ),
			$service_a->cart_fingerprint( $cache_b ),
			'Authority cache metadata must not alter the canonical cart fingerprint.'
		);
	}

	/**
	 * @testdox Should keep the initial unquoted PUDO wrapper before a point is selected.
	 */
	public function test_keeps_initial_wrapper_rate_without_selection(): void {
		$sut     = $this->service();
		$session = new PudoSessionStub();
		$rates   = array(
			'bluex-pudo:7' => $this->rate( array(), 0.0 ),
			'bluex-ex:4'   => array( 'id' => 'bluex-ex:4', 'method_id' => 'bluex-ex', 'cost' => 4990 ),
		);

		$filtered = $sut->filter_package_rates( $rates, $this->packages(), $session, 1000 );

		self::assertArrayHasKey( 'bluex-pudo:7', $filtered );
		self::assertArrayHasKey( 'bluex-ex:4', $filtered );
	}

	/**
	 * @testdox Should remove fallback, mismatched and stale PUDO rates after selection.
	 *
	 * @dataProvider invalid_rate_provider
	 *
	 * @param array<string, mixed> $rate Invalid rate.
	 */
	public function test_removes_untrusted_rate_after_selection( array $rate ): void {
		$sut      = $this->service();
		$session  = new PudoSessionStub();
		$packages = $this->packages();
		$sut->select_agency( 'AGENCY-123', 'Santiago', 'RM', $packages, $session, 1000 );

		$filtered = $sut->filter_package_rates(
			array( 'bluex-pudo:7' => $rate ),
			$packages,
			$session,
			1100
		);

		self::assertArrayNotHasKey( 'bluex-pudo:7', $filtered );
	}

	/**
	 * Provides invalid provider rate scenarios.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function invalid_rate_provider(): array {
		return array(
			'fallback without marker' => array( $this->rate( array(), 3990.0 ) ),
			'wrong agency'            => array(
				$this->rate(
					array(
						'_bluex_pudo_quoted' => 'yes',
						'_bluex_agency_id'   => 'ATTACKER-999',
					),
					3990.0
				),
			),
			'wrong price'             => array(
				$this->rate(
					array(
						'_bluex_pudo_quoted' => 'yes',
						'_bluex_agency_id'   => 'AGENCY-123',
					),
					0.0
				),
			),
		);
	}

	/**
	 * @testdox Should keep a real quoted rate matching the independent API quote.
	 */
	public function test_keeps_independently_confirmed_quote(): void {
		$sut      = $this->service();
		$session  = new PudoSessionStub();
		$packages = $this->packages();
		$sut->select_agency( 'AGENCY-123', 'Santiago', 'RM', $packages, $session, 1000 );
		$rates = array(
			'bluex-pudo:7' => $this->rate(
				array(
					'_bluex_pudo_quoted' => 'yes',
					'_bluex_agency_id'   => 'AGENCY-123',
				),
				3990.0
			),
		);

		$filtered = $sut->filter_package_rates( $rates, $packages, $session, 1100 );

		self::assertArrayHasKey( 'bluex-pudo:7', $filtered );
	}

	/**
	 * @testdox Should accept the rate BlueX charges with its instance fee on top of the quote.
	 *
	 * BlueX 3.3 adds `get_fee( fee, Valor )` (19 % by default on new instances) to the
	 * PUDO rate. The first-party quote has no fee: without adding it the rate
	 * vanished right after choosing the pickup point.
	 */
	public function test_accepts_rate_with_the_instance_fee(): void {
		$sut      = $this->service( null, static fn( string $rate_id, float $total ): float => 'bluex-pudo:7' === $rate_id ? round( $total * 0.19, 2 ) : 0.0 );
		$session  = new PudoSessionStub();
		$packages = $this->packages();
		$sut->select_agency( 'AGENCY-123', 'Santiago', 'RM', $packages, $session, 1000 );
		$meta     = array(
			'_bluex_pudo_quoted' => 'yes',
			'_bluex_agency_id'   => 'AGENCY-123',
		);

		$con_recargo = $sut->filter_package_rates( array( 'bluex-pudo:7' => $this->rate( $meta, 4748.1 ) ), $packages, $session, 1100 );
		self::assertArrayHasKey( 'bluex-pudo:7', $con_recargo, '3.990 + 19 % es lo que cobra Blue.' );

		$sin_recargo = $sut->filter_package_rates( array( 'bluex-pudo:7' => $this->rate( $meta, 3990.0 ) ), $packages, $session, 1100 );
		self::assertArrayNotHasKey( 'bluex-pudo:7', $sin_recargo, 'Con recargo configurado, el monto sin recargo no es la tarifa de Blue.' );
	}

	/**
	 * @testdox Should read the fee from the WooCommerce instance of the rate, or none.
	 */
	public function test_shipping_method_fee_without_instance_is_zero(): void {
		self::assertSame( 0.0, PudoCheckoutService::shipping_method_fee( 'bluex-pudo', 3990.0 ) );
		self::assertSame( 0.0, PudoCheckoutService::shipping_method_fee( 'bluex-pudo:0', 3990.0 ) );
		self::assertSame( 0.0, PudoCheckoutService::shipping_method_fee( 'bluex-pudo:112', 3990.0 ), 'Sin WooCommerce cargado no hay instancia que leer.' );
	}

	/**
	 * @testdox Should accept a zero rate only when BlueX independently marked the quote free.
	 */
	public function test_accepts_confirmed_free_quote(): void {
		$sut      = $this->service(
			array(
				'code' => '01',
				'data' => array(
					'total'          => 0,
					'isShipmentFree' => true,
					'promiseDay'     => '2',
				),
			)
		);
		$session  = new PudoSessionStub();
		$packages = $this->packages();
		$sut->select_agency( 'AGENCY-123', 'Santiago', 'RM', $packages, $session, 1000 );
		$rates = array(
			'bluex-pudo:7' => $this->rate(
				array(
					'_bluex_pudo_quoted' => 'yes',
					'_bluex_agency_id'   => 'AGENCY-123',
				),
				0.0
			),
		);

		$filtered = $sut->filter_package_rates( $rates, $packages, $session, 1100 );

		self::assertArrayHasKey( 'bluex-pudo:7', $filtered );
	}

	/**
	 * @testdox Should validate a single-package quoted PUDO checkout.
	 */
	public function test_validates_final_pudo_checkout(): void {
		$sut      = $this->service();
		$session  = new PudoSessionStub();
		$packages = $this->packages();
		$sut->select_agency( 'AGENCY-123', 'Santiago', 'RM', $packages, $session, 1000 );
		$packages[0]['rates'] = array(
			'bluex-pudo:7' => $this->rate(
				array(
					'_bluex_pudo_quoted' => 'yes',
					'_bluex_agency_id'   => 'AGENCY-123',
				),
				3990.0
			),
		);

		$result = $sut->validate_checkout( $packages, array( 'bluex-pudo:7' ), $session, 1100 );

		self::assertTrue( $result['is_pudo'] );
		self::assertSame( 'AGENCY-123', $result['selection']['agency_id'] );
		self::assertSame( 'bluex-pudo', $result['shipping_method'] );
	}

	/**
	 * @testdox Should reject PUDO whenever checkout has multiple packages.
	 */
	public function test_rejects_multi_package_checkout(): void {
		$sut      = $this->service();
		$session  = new PudoSessionStub();
		$packages = $this->packages();
		$packages[] = $packages[0];

		$this->expectException( PudoValidationException::class );

		$sut->validate_checkout( $packages, array( 'bluex-pudo:7', 'bluex-ex:4' ), $session, 1000 );
	}

	/**
	 * @testdox Should leave non-PUDO checkout methods outside this domain.
	 */
	public function test_non_pudo_checkout_is_unchanged(): void {
		$sut = $this->service();

		$result = $sut->validate_checkout(
			$this->packages(),
			array( 'bluex-ex:4' ),
			new PudoSessionStub(),
			1000
		);

		self::assertFalse( $result['is_pudo'] );
		self::assertNull( $result['selection'] );
	}

	/**
	 * @testdox Should keep B authoritative when slow selection A resolves last across two request instances.
	 */
	public function test_newer_selection_wins_across_two_instances_when_older_resolves_last(): void {
		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$session_a  = new PudoSessionStub( 'shared-browser' );
		$session_b  = new PudoSessionStub( 'shared-browser' );
		$reader     = new PudoSessionStub( 'shared-browser' );
		$service_b  = $this->coordinated_service(
			$repository,
			$tokens,
			new PudoInterleavingApiClient()
		);
		$service_a  = $this->coordinated_service(
			$repository,
			$tokens,
			new PudoInterleavingApiClient(
				function () use ( $service_b, $session_b ): void {
					$service_b->select_agency(
						'AGENCY-B',
						'Santiago',
						'CL-RM',
						$this->packages(),
						$session_b,
						1001,
						str_repeat( 'b', 32 )
					);
				}
			)
		);

		try {
			$service_a->select_agency(
				'AGENCY-A',
				'Santiago',
				'CL-RM',
				$this->packages(),
				$session_a,
				1000,
				str_repeat( 'a', 32 )
			);
			self::fail( 'The obsolete A lease must not commit after B.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'pudo_stale_attempt', $exception->error_code() );
		}

		$selection = $service_b->get_selection( $this->packages(), $reader, 1100 );
		self::assertIsArray( $selection );
		self::assertSame( 'AGENCY-B', $selection['agency_id'] );
		self::assertSame( 'AGENCY-B', $reader->get( 'bluex_agency_id' ) );
		self::assertNull(
			$session_a->get( 'bluex_agency_id' ),
			'The stale request must not project A after its CAS loses.'
		);
	}

	/**
	 * @testdox Should keep an explicit clear tombstone authoritative when slow A resolves last.
	 */
	public function test_clear_tombstone_prevents_inflight_selection_from_resurrecting(): void {
		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$session_a  = new PudoSessionStub( 'shared-clear-browser' );
		$session_b  = new PudoSessionStub( 'shared-clear-browser' );
		$service_b  = $this->coordinated_service( $repository, $tokens, new PudoInterleavingApiClient() );
		$service_a  = $this->coordinated_service(
			$repository,
			$tokens,
			new PudoInterleavingApiClient(
				static function () use ( $service_b, $session_b ): void {
					$service_b->clear_selection( $session_b, str_repeat( 'c', 32 ), 1001 );
				}
			)
		);

		try {
			$service_a->select_agency(
				'AGENCY-A',
				'Santiago',
				'CL-RM',
				$this->packages(),
				$session_a,
				1000,
				str_repeat( 'a', 32 )
			);
			self::fail( 'A must not resurrect after a newer clear.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'pudo_stale_attempt', $exception->error_code() );
		}

		self::assertNull(
			$service_b->get_selection(
				$this->packages(),
				new PudoSessionStub( 'shared-clear-browser' ),
				1100
			)
		);
		self::assertSame( 'cleared', reset( $repository->records )['phase'] );
	}

	/**
	 * @testdox Should keep B authoritative when A is superseded during its quote call.
	 */
	public function test_newer_selection_wins_when_older_quote_resolves_last(): void {
		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$session_a  = new PudoSessionStub( 'shared-quote-browser' );
		$session_b  = new PudoSessionStub( 'shared-quote-browser' );
		$service_b  = $this->coordinated_service(
			$repository,
			$tokens,
			new PudoInterleavingApiClient()
		);
		$service_a  = $this->coordinated_service(
			$repository,
			$tokens,
			new PudoInterleavingApiClient(
				null,
				function () use ( $service_b, $session_b ): void {
					$service_b->select_agency(
						'AGENCY-B',
						'Santiago',
						'CL-RM',
						$this->packages(),
						$session_b,
						1001,
						str_repeat( 'b', 32 )
					);
				}
			)
		);

		try {
			$service_a->select_agency(
				'AGENCY-A',
				'Santiago',
				'CL-RM',
				$this->packages(),
				$session_a,
				1000,
				str_repeat( 'a', 32 )
			);
			self::fail( 'A must not commit after its quote was superseded.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'pudo_stale_attempt', $exception->error_code() );
		}

		$selection = $service_b->get_selection(
			$this->packages(),
			new PudoSessionStub( 'shared-quote-browser' ),
			1100
		);
		self::assertIsArray( $selection );
		self::assertSame( 'AGENCY-B', $selection['agency_id'] );
	}

	/**
	 * @testdox Should prevent A from committing when newer B fails provider validation.
	 */
	public function test_newer_failed_attempt_still_supersedes_older_inflight_selection(): void {
		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$session_a  = new PudoSessionStub( 'shared-failure-browser' );
		$session_b  = new PudoSessionStub( 'shared-failure-browser' );
		$service_b  = $this->coordinated_service(
			$repository,
			$tokens,
			new PudoInterleavingApiClient( null, null, true )
		);
		$service_a  = $this->coordinated_service(
			$repository,
			$tokens,
			new PudoInterleavingApiClient(
				function () use ( $service_b, $session_b ): void {
					try {
						$service_b->select_agency(
							'AGENCY-B',
							'Santiago',
							'CL-RM',
							$this->packages(),
							$session_b,
							1001,
							str_repeat( 'b', 32 )
						);
					} catch ( PudoValidationException $exception ) {
						self::assertSame( 'bluex_agency_mismatch', $exception->error_code() );
					}
				}
			)
		);

		try {
			$service_a->select_agency(
				'AGENCY-A',
				'Santiago',
				'CL-RM',
				$this->packages(),
				$session_a,
				1000,
				str_repeat( 'a', 32 )
			);
			self::fail( 'A must not commit after newer B failed.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'pudo_stale_attempt', $exception->error_code() );
		}

		self::assertNull(
			$service_a->get_selection(
				$this->packages(),
				new PudoSessionStub( 'shared-failure-browser' ),
				1100
			)
		);
		self::assertSame( 'failed', reset( $repository->records )['phase'] );
	}

	/**
	 * @testdox Should reject a quote that completes after its server lease expires.
	 */
	public function test_expired_pending_lease_cannot_commit_after_slow_quote(): void {
		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$now        = 1000;
		$client     = new PudoInterleavingApiClient(
			null,
			static function () use ( &$now ): void {
				$now = 1061;
			}
		);
		$service = $this->coordinated_service(
			$repository,
			$tokens,
			$client,
			static function () use ( &$now ): int {
				return $now;
			}
		);

		try {
			$service->select_agency(
				'AGENCY-A',
				'Santiago',
				'CL-RM',
				$this->packages(),
				new PudoSessionStub( 'lease-expiry-browser' ),
				null,
				str_repeat( 'a', 32 )
			);
			self::fail( 'An expired pending lease must not commit.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'pudo_stale_attempt', $exception->error_code() );
		}

		self::assertSame( 'failed', reset( $repository->records )['phase'] );
	}

	/**
	 * @testdox Should ignore a stale Woo session snapshot and rehydrate from authoritative state.
	 */
	public function test_authoritative_state_overrides_stale_whole_session_snapshot(): void {
		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$service    = $this->coordinated_service( $repository, $tokens, new PudoInterleavingApiClient() );
		$writer     = new PudoSessionStub( 'snapshot-browser' );
		$stale      = new PudoSessionStub( 'snapshot-browser' );

		$service->select_agency(
			'AGENCY-B',
			'Santiago',
			'CL-RM',
			$this->packages(),
			$writer,
			1000,
			str_repeat( 'b', 32 )
		);
		$stale->set( 'bluex_agency_id', 'AGENCY-A' );
		$stale->set( 'akb_pudo_selection', array( 'agency_id' => 'AGENCY-A' ) );

		$selection = $service->get_selection( $this->packages(), $stale, 1100 );

		self::assertIsArray( $selection );
		self::assertSame( 'AGENCY-B', $selection['agency_id'] );
		self::assertSame( 'AGENCY-B', $stale->get( 'bluex_agency_id' ) );
	}

	/**
	 * @testdox Should freeze the exact committed generation while order data is copied.
	 */
	public function test_checkout_claim_blocks_parallel_mutations_until_exact_release(): void {
		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$service    = $this->coordinated_service( $repository, $tokens, new PudoInterleavingApiClient() );
		$session    = new PudoSessionStub( 'checkout-freeze-browser' );
		$packages   = $this->packages();
		$service->select_agency(
			'AGENCY-B',
			'Santiago',
			'CL-RM',
			$packages,
			$session,
			1000,
			str_repeat( 'b', 32 )
		);
		$packages[0]['rates'] = array(
			'bluex-pudo:7' => $this->rate(
				array(
					'_bluex_pudo_quoted' => 'yes',
					'_bluex_agency_id'   => 'AGENCY-B',
				),
				3990.0
			),
		);

		$validation = $service->validate_checkout(
			$packages,
			array( 'bluex-pudo:7' ),
			$session,
			1100
		);
		self::assertSame( 'checkout', reset( $repository->records )['phase'] );

		try {
			$service->clear_selection( $session, str_repeat( 'c', 32 ), 1101 );
			self::fail( 'A parallel clear must not change order data while it is copied.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'pudo_checkout_in_progress', $exception->error_code() );
		}

		try {
			$service->select_agency(
				'AGENCY-A',
				'Santiago',
				'CL-RM',
				$this->packages(),
				new PudoSessionStub( 'checkout-freeze-browser' ),
				1101,
				str_repeat( 'e', 32 )
			);
			self::fail( 'A parallel selection must not supersede the checkout claim.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'pudo_checkout_in_progress', $exception->error_code() );
		}

		$service->release_checkout_claim( $validation['checkout_claim'], 1101 );
		self::assertSame( 'committed', reset( $repository->records )['phase'] );

		$service->clear_selection( $session, str_repeat( 'd', 32 ), 1102 );
		self::assertSame( 'cleared', reset( $repository->records )['phase'] );
	}

	/**
	 * @testdox Should keep a renewed tombstone when cleanup races the old pending expiry.
	 */
	public function test_cleanup_predicate_cannot_delete_a_renewed_state(): void {
		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$guard      = new PudoMutationGuard(
			$repository,
			static fn( object $session ): string => $session->get_customer_id(),
			$tokens,
			str_repeat( 'k', 64 )
		);
		$session = new PudoSessionStub( 'cleanup-browser' );
		$guard->begin_selection(
			$session,
			str_repeat( 'a', 32 ),
			str_repeat( 'f', 64 ),
			1000
		);
		$guard->clear( $session, str_repeat( 'b', 32 ), 1050 );

		self::assertSame( 0, $repository->cleanup_expired( 1061, 25 ) );
		self::assertSame( 'cleared', reset( $repository->records )['phase'] );
	}

	/**
	 * @testdox Should recover from an abandoned checkout claim after its short lease expires.
	 */
	public function test_expired_checkout_claim_cannot_block_a_later_clear(): void {
		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$service    = $this->coordinated_service( $repository, $tokens, new PudoInterleavingApiClient() );
		$session    = new PudoSessionStub( 'expired-checkout-browser' );
		$packages   = $this->packages();
		$service->select_agency(
			'AGENCY-B',
			'Santiago',
			'CL-RM',
			$packages,
			$session,
			1000,
			str_repeat( 'b', 32 )
		);
		$packages[0]['rates'] = array(
			'bluex-pudo:7' => $this->rate(
				array(
					'_bluex_pudo_quoted' => 'yes',
					'_bluex_agency_id'   => 'AGENCY-B',
				),
				3990.0
			),
		);
		$validation = $service->validate_checkout(
			$packages,
			array( 'bluex-pudo:7' ),
			$session,
			1100
		);

		$service->clear_selection( $session, str_repeat( 'c', 32 ), 1131 );
		self::assertSame( 'cleared', reset( $repository->records )['phase'] );

		try {
			$service->release_checkout_claim( $validation['checkout_claim'], 1131 );
			self::fail( 'An expired/replaced checkout claim must not be revived.' );
		} catch ( PudoValidationException $exception ) {
			self::assertSame( 'pudo_checkout_changed', $exception->error_code() );
		}
	}

	/**
	 * @testdox Should isolate authoritative state between different Woo session identities.
	 */
	public function test_different_session_id_cannot_read_another_browser_selection(): void {
		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$service    = $this->coordinated_service( $repository, $tokens, new PudoInterleavingApiClient() );
		$service->select_agency(
			'AGENCY-A',
			'Santiago',
			'CL-RM',
			$this->packages(),
			new PudoSessionStub( 'browser-one' ),
			1000,
			str_repeat( 'a', 32 )
		);

		self::assertNull(
			$service->get_selection(
				$this->packages(),
				new PudoSessionStub( 'browser-two' ),
				1100
			)
		);
		self::assertCount( 1, $repository->records );
	}

	/**
	 * Candado de invariante, no de literal: fija cuánto tiempo puede quedarse
	 * quieto un cliente en el checkout sin perder su punto de retiro.
	 *
	 * El defecto: con el TTL por defecto de 900 s el cliente que seleccionaba
	 * su punto y tardaba más de 15 minutos en pulsar «Realizar pedido» —sin
	 * tocar el carrito en el intervalo, que es el único camino sin aviso
	 * previo— recibía «Selecciona nuevamente un punto de retiro Blue Express»
	 * recién al final. Medido el 2026-08-23 con este mismo test: en 1901
	 * lanzaba PudoValidationException(pudo_selection_missing).
	 *
	 * Se afloja el reloj, no la validación: la huella del carrito, la CAS del
	 * guard y la comparación tarifa↔cotización siguen intactas. Por eso la
	 * invariante que fijamos es «una hora quieto sigue siendo válida», no el
	 * número 3600.
	 *
	 * @testdox Should keep the selection through an hour of checkout inactivity.
	 */
	public function test_default_selection_survives_checkout_inactivity(): void {
		$sut      = $this->default_ttl_service();
		$session  = new PudoSessionStub();
		$packages = $this->packages();
		$sut->select_agency( 'AGENCY-123', 'Santiago', 'RM', $packages, $session, 1000 );
		$packages[0]['rates'] = array(
			'bluex-pudo:7' => $this->rate(
				array(
					'_bluex_pudo_quoted' => 'yes',
					'_bluex_agency_id'   => 'AGENCY-123',
				),
				3990.0
			),
		);

		// 1901 = el acantilado viejo (900 s + 1). 4500 = 58 minutos quieto.
		$first = $sut->validate_checkout( $packages, array( 'bluex-pudo:7' ), $session, 1901 );
		self::assertTrue( $first['is_pudo'] );

		// El claim se libera como en el flujo real; si no, la fila queda en fase
		// 'checkout' y el segundo intento fallaría por el claim, no por el TTL.
		$sut->release_checkout_claim( $first['checkout_claim'], 1901 );

		self::assertTrue( $sut->validate_checkout( $packages, array( 'bluex-pudo:7' ), $session, 4500 )['is_pudo'] );
	}

	/**
	 * El TTL no es eterno: pasada la ventana sancionada la selección sigue
	 * cayendo, para que ninguna cotización de Blue se cobre indefinidamente.
	 *
	 * @testdox Should still expire the selection past the sanctioned window.
	 */
	public function test_default_selection_still_expires(): void {
		$sut      = $this->default_ttl_service();
		$session  = new PudoSessionStub();
		$packages = $this->packages();
		$sut->select_agency( 'AGENCY-123', 'Santiago', 'RM', $packages, $session, 1000 );

		self::assertNull( $sut->get_selection( $packages, $session, 1000 + 3601 ) );
	}

	/**
	 * @testdox Should never contain a customer profile mutation path.
	 */
	public function test_domain_has_no_customer_mutation_path(): void {
		$directory = dirname( __DIR__, 4 ) . '/src/Integrations/Bluex/Pudo';
		$source    = '';
		foreach ( glob( $directory . '/*.php' ) ?: array() as $file ) {
			$source .= (string) file_get_contents( $file );
		}

		self::assertStringNotContainsString( 'WC()->customer', $source );
		self::assertStringNotContainsString( 'set_shipping_', $source );
		self::assertStringNotContainsString( '->save()', $source );
	}

	/**
	 * Construye el servicio SIN pasar store, para ejercitar el TTL por defecto
	 * (el resto de las pruebas lo fija en 900 s y no vería una regresión aquí).
	 */
	private function default_ttl_service(): PudoCheckoutService {
		$client   = new PudoApiClientStub( $this->valid_geolocation(), $this->paid_pricing() );
		$settings = new PudoSettingsStub();
		$provider = new PickupPointProvider(
			static fn(): object => $client,
			static fn(): object => $settings,
			static fn(): float => 0.0
		);
		$guard = new PudoMutationGuard(
			new InMemoryPudoMutationRepository(),
			static fn( object $session ): string => $session->get_customer_id(),
			new PudoDeterministicTokenFactory(),
			str_repeat( 'k', 64 )
		);

		return new PudoCheckoutService( $provider, null, $guard );
	}

	/**
	 * @param array<string, mixed>|null $pricing Pricing response.
	 */
	private function service( ?array $pricing = null, ?callable $rate_fee = null ): PudoCheckoutService {
		$client   = new PudoApiClientStub(
			$this->valid_geolocation(),
			$pricing ?? $this->paid_pricing()
		);
		$settings = new PudoSettingsStub();
		$provider = new PickupPointProvider(
			static fn(): object => $client,
			static fn(): object => $settings,
			static fn(): float => 0.0
		);

		$repository = new InMemoryPudoMutationRepository();
		$tokens     = new PudoDeterministicTokenFactory();
		$guard      = new PudoMutationGuard(
			$repository,
			static fn( object $session ): string => $session->get_customer_id(),
			$tokens,
			str_repeat( 'k', 64 )
		);

		return new PudoCheckoutService( $provider, new PudoSelectionStore( 900 ), $guard, null, $rate_fee );
	}

	/**
	 * Creates an independently instantiated service sharing only durable state.
	 *
	 * @param callable|null $clock Timestamp source.
	 */
	private function coordinated_service(
		InMemoryPudoMutationRepository $repository,
		PudoDeterministicTokenFactory $tokens,
		PudoInterleavingApiClient $client,
		?callable $clock = null
	): PudoCheckoutService {
		$settings = new PudoSettingsStub();
		$provider = new PickupPointProvider(
			static fn(): object => $client,
			static fn(): object => $settings,
			static fn(): float => 0.0
		);
		$guard = new PudoMutationGuard(
			$repository,
			static fn( object $session ): string => $session->get_customer_id(),
			$tokens,
			str_repeat( 'k', 64 )
		);

		return new PudoCheckoutService(
			$provider,
			new PudoSelectionStore( 900 ),
			$guard,
			$clock
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
	private function packages(): array {
		return array(
			array(
				'contents'      => array(
					'cart-key' => array(
						'product_id'   => 42,
						'variation_id' => 0,
						'quantity'     => 1,
						'line_total'   => 55000,
						'data'         => new PudoProductStub(),
					),
				),
				'contents_cost' => 55000,
				'destination'   => array(
					'country'  => 'CL',
					'state'    => 'CL-RM',
					'city'     => 'Santiago',
					'postcode' => '8320000',
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $meta Meta.
	 * @return array<string, mixed>
	 */
	private function rate( array $meta, float $cost ): array {
		return array(
			'id'        => 'bluex-pudo:7',
			'method_id' => 'bluex-pudo',
			'cost'      => $cost,
			'meta_data' => $meta,
		);
	}
}

final class InMemoryPudoMutationRepository implements PudoMutationRepository {

	/** @var array<string,array<string,mixed>> */
	public array $records = array();

	public function create_if_absent( string $key, array $record ): bool {
		if ( isset( $this->records[ $key ] ) ) {
			return false;
		}

		$this->records[ $key ] = $record;
		return true;
	}

	public function compare_and_swap( string $key, array $expected, array $replacement ): bool {
		if ( ! isset( $this->records[ $key ] ) || $this->records[ $key ] !== $expected ) {
			return false;
		}

		$this->records[ $key ] = $replacement;
		return true;
	}

	public function get( string $key ): ?array {
		return $this->records[ $key ] ?? null;
	}

	public function cleanup_expired( int $now, int $limit ): int {
		$deleted = 0;
		foreach ( array_keys( $this->records ) as $key ) {
			if ( $deleted >= $limit ) {
				break;
			}
			if ( is_int( $this->records[ $key ]['expires_at'] ?? null ) && $this->records[ $key ]['expires_at'] <= $now ) {
				unset( $this->records[ $key ] );
				++$deleted;
			}
		}

		return $deleted;
	}
}

final class PudoDeterministicTokenFactory {

	private int $counter = 0;

	public function __invoke(): string {
		++$this->counter;
		return str_pad( dechex( $this->counter ), 64, '0', STR_PAD_LEFT );
	}
}

final class PudoInterleavingApiClient {

	private ?\Closure $on_geolocation;
	private ?\Closure $on_pricing;
	private bool $force_mismatch;

	public function __construct(
		?callable $on_geolocation = null,
		?callable $on_pricing = null,
		bool $force_mismatch = false
	) {
		$this->on_geolocation = null === $on_geolocation ? null : \Closure::fromCallable( $on_geolocation );
		$this->on_pricing     = null === $on_pricing ? null : \Closure::fromCallable( $on_pricing );
		$this->force_mismatch = $force_mismatch;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_geolocation( string $address, string $region, ?string $agency_id, bool $is_pudo ): array {
		unset( $address, $region, $is_pudo );
		if ( null !== $this->on_geolocation ) {
			$callback = $this->on_geolocation;
			$this->on_geolocation = null;
			$callback();
		}

		$confirmed_id = $this->force_mismatch ? 'MISMATCHED' : (string) $agency_id;
		return array(
			'porcentageDeExito' => '100%',
			'regionCode'        => 13,
			'nameRegion'        => 'Metropolitana de Santiago',
			'cidadeName'        => 'Santiago',
			'districtCode'      => 'SCL',
			'pickupInfo'        => array(
				'agency_id'      => $confirmed_id,
				'agency_name'    => 'Punto ' . $confirmed_id,
				'agency_address' => 'Av. ' . $confirmed_id . ' 123, Santiago',
				'location'       => array(
					'street_name'   => 'Av. ' . $confirmed_id,
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
	 * @param array<string,mixed>             $from
	 * @param array<string,mixed>             $to
	 * @param array<int,array<string,mixed>> $bultos
	 * @param array<string,mixed>|null         $user_data
	 * @return array<string,mixed>
	 */
	public function get_pricing(
		array $from,
		array $to,
		string $service,
		array $bultos,
		float $declared_value,
		string $family,
		?array $user_data = null
	): array {
		unset( $from, $to, $service, $bultos, $declared_value, $family, $user_data );
		if ( null !== $this->on_pricing ) {
			$callback = $this->on_pricing;
			$this->on_pricing = null;
			$callback();
		}

		return array(
			'code' => '00',
			'data' => array(
				'total'          => 3990,
				'isShipmentFree' => false,
				'promiseDay'     => '2',
			),
		);
	}
}
