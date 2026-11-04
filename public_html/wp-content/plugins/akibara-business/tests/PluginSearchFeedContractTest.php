<?php
/**
 * Tests for the Business upgrade lease and Search feed contract.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search {
	/** Test double for the Search rebuild scheduler. */
	final class RebuildJob {

		/** @var list<bool> */
		public static array $scheduled = array();

		/**
		 * Record a rebuild request.
		 *
		 * @param bool $force Whether to force a stalled rebuild.
		 */
		public static function schedule( bool $force = false ): bool {
			self::$scheduled[] = $force;

			return true;
		}
	}
}

namespace {
	if ( ! function_exists( 'wp_generate_uuid4' ) ) {
		/** Return deterministic lease tokens for this contract suite. */
		function wp_generate_uuid4(): string {
			$GLOBALS['akibara_upgrade_test_uuid'] = (int) ( $GLOBALS['akibara_upgrade_test_uuid'] ?? 0 ) + 1;

			return 'test-owner-' . $GLOBALS['akibara_upgrade_test_uuid'];
		}
	}
}

namespace Akibara\Business\Tests {

	use Akibara\Business\Internal\UpgradeRetryRequired;
	use Akibara\Business\Plugin;
	use Akibara\Search\RebuildJob;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use RuntimeException;
	use Throwable;

	defined( 'AKIBARA_BUSINESS_VERSION' ) || define( 'AKIBARA_BUSINESS_VERSION', '1.95.3' );

	/** Stateful wpdb fake implementing the option compare-and-swap statements used by Plugin. */
	final class UpgradeWpdbFake {

		public string $options = 'wp_options';

		/** @var array<string,string> */
		public array $option_values = array();

		/** @var list<array{query:string,args:list<mixed>}> */
		public array $prepare_calls = array();

		/** @var list<array{option:string,from:?string,to:string}> */
		public array $mutations = array();

		/** @var callable|null */
		public $before_query = null;

		/** Record a prepared statement and return an opaque deterministic handle. */
		public function prepare( string $query, ...$args ): string {
			$this->prepare_calls[] = array(
				'query' => $query,
				'args'  => $args,
			);

			return 'prepared:' . ( count( $this->prepare_calls ) - 1 );
		}

		/** Execute the prepared option mutation with exact CAS semantics. */
		public function query( string $prepared ): int {
			$index = (int) substr( $prepared, strlen( 'prepared:' ) );
			$call  = $this->prepare_calls[ $index ];

			if ( is_callable( $this->before_query ) ) {
				( $this->before_query )( $this, $call );
			}

			$args = $call['args'];
			$sql  = $call['query'];

			if ( 3 === count( $args ) ) {
				return $this->compare_and_swap( (string) $args[1], (string) $args[2], (string) $args[0] );
			}

			if ( str_contains( $sql, 'UPDATE' ) && str_contains( $sql, 'LEFT JOIN' ) ) {
				$installed_option = (string) $args[0];
				$queued           = (string) $args[1];
				$pending_option   = (string) $args[2];
				$stored           = (string) $args[3];

				if (
					array_key_exists( $pending_option, $this->option_values )
					&& '' === $this->option_values[ $pending_option ]
					&& $this->installed_matches( $installed_option, $stored )
				) {
					$this->write( $pending_option, $queued );
					return 1;
				}

				return 0;
			}

			if ( 5 === count( $args ) ) {
				$pending_option   = (string) $args[0];
				$queued           = (string) $args[1];
				$installed_option = (string) $args[2];
				$stored           = (string) $args[3];

				if (
					! array_key_exists( $pending_option, $this->option_values )
					&& $this->installed_matches( $installed_option, $stored )
				) {
					$this->write( $pending_option, $queued );
					return 1;
				}

				return 0;
			}

			if ( 2 === count( $args ) ) {
				$option = (string) $args[0];
				if ( array_key_exists( $option, $this->option_values ) ) {
					return 0;
				}

				$this->write( $option, (string) $args[1] );
				return 1;
			}

			throw new RuntimeException( 'Unexpected prepared query in UpgradeWpdbFake.' );
		}

		/** Apply an exact option CAS. */
		private function compare_and_swap( string $option, string $expected, string $replacement ): int {
			if (
				! array_key_exists( $option, $this->option_values )
				|| $expected !== $this->option_values[ $option ]
			) {
				return 0;
			}

			$this->write( $option, $replacement );
			return 1;
		}

		/** Determine whether the installed row satisfies a queue publication. */
		private function installed_matches( string $option, string $stored ): bool {
			return ( array_key_exists( $option, $this->option_values ) && $stored === $this->option_values[ $option ] )
				|| ( '' === $stored && ! array_key_exists( $option, $this->option_values ) );
		}

		/** Persist and record one option mutation. */
		private function write( string $option, string $value ): void {
			$previous = $this->option_values[ $option ] ?? null;
			$this->option_values[ $option ] = $value;
			$this->mutations[]               = array(
				'option' => $option,
				'from'   => $previous,
				'to'     => $value,
			);
		}
	}

	/** Tests for the versioned upgrade lease and Search feed contract. */
	final class PluginSearchFeedContractTest extends TestCase {

		private const VERSION_OPTION = 'akibara_business_version_installed';
		private const PENDING_OPTION = 'akibara_business_upgrade_tasks_pending';
		private const CONTRACT_OPTION = 'akibara_business_search_feed_contract_version';

		/** The System Under Test. */
		private Plugin $sut;

		private UpgradeWpdbFake $db;

		/** @var list<array{hook:string,args:list<mixed>}> */
		private array $actions = array();

		/** @var callable|null */
		private $action_handler = null;

		private int $flushes = 0;

		private bool $had_wpdb;

		/** @var mixed */
		private $previous_wpdb;

		/** Set up deterministic WordPress, database, and Search doubles. */
		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
			$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
			$this->db            = new UpgradeWpdbFake();
			$GLOBALS['wpdb']     = $this->db;
			$GLOBALS['akibara_upgrade_test_uuid'] = 0;
			RebuildJob::$scheduled = array();

			$this->set_options();
			Functions\when( 'get_option' )->alias(
				fn( string $key, $default = false ) => array_key_exists( $key, $this->db->option_values )
					? $this->db->option_values[ $key ]
					: $default
			);
			Functions\when( 'wp_cache_delete' )->justReturn( true );
			Functions\when( 'wp_unschedule_hook' )->justReturn( 0 );
			Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
			Functions\when( 'flush_rewrite_rules' )->alias(
				function ( bool $hard ): void {
					self::assertTrue( $hard, 'Upgrade rewrite flushes must be hard flushes.' );
					++$this->flushes;
				}
			);
			Functions\when( 'do_action' )->alias(
				function ( string $hook, ...$args ): void {
					$this->actions[] = array( 'hook' => $hook, 'args' => $args );
					if ( is_callable( $this->action_handler ) ) {
						( $this->action_handler )( $hook, ...$args );
					}
				}
			);

			$reflection = new ReflectionClass( Plugin::class );
			$this->sut  = $reflection->newInstanceWithoutConstructor();
		}

		/** Restore global state and Brain Monkey. */
		protected function tearDown(): void {
			if ( $this->had_wpdb ) {
				$GLOBALS['wpdb'] = $this->previous_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}

			unset( $GLOBALS['akibara_upgrade_test_uuid'] );
			RebuildJob::$scheduled = array();
			Monkey\tearDown();
			parent::tearDown();
		}

		/** @testdox A normal bump queues, owns, seals, emits, and clears in order for two callers. */
		public function test_normal_bump_runs_once_and_clears_the_lease(): void {
			$this->set_options( array( self::VERSION_OPTION => '1.95.2' ) );

			$this->sut->maybe_upgrade();
			$this->sut->maybe_upgrade();

			self::assertSame( AKIBARA_BUSINESS_VERSION, $this->db->option_values[ self::VERSION_OPTION ] );
			self::assertSame( '', $this->db->option_values[ self::PENDING_OPTION ] );
			self::assertSame( 1, $this->flushes, 'Only the lease owner may run the upgrade body.' );
			self::assertSame( array( $this->upgrade_action( '1.95.2' ) ), $this->actions );
			self::assertSame(
				array( 'queued', 'running', 'sealed', 'cleared' ),
				$this->upgrade_mutation_phases(),
				'The queue must be durable before the installed version is sealed.'
			);
		}

		/** @testdox An unexpired running lease blocks another request without emitting the hook. */
		public function test_unexpired_running_lease_is_not_stolen(): void {
			$running = $this->state( 'running', AKIBARA_BUSINESS_VERSION, '1.95.2', 'live-owner', time() + 300 );
			$this->set_options(
				array(
					self::VERSION_OPTION => '1.95.2',
					self::PENDING_OPTION => $running,
				)
			);

			$this->sut->maybe_upgrade();

			self::assertSame( $running, $this->db->option_values[ self::PENDING_OPTION ] );
			self::assertSame( array(), $this->actions );
			self::assertSame( 0, $this->flushes );
			self::assertSame( array(), $this->db->mutations );
		}

		/** @testdox An expired running lease is stolen and completed by a new owner. */
		public function test_expired_running_lease_is_stolen_and_completed(): void {
			$this->set_options(
				array(
					self::VERSION_OPTION => '1.95.2',
					self::PENDING_OPTION => $this->state( 'running', AKIBARA_BUSINESS_VERSION, '1.95.2', 'dead-owner', time() - 1 ),
				)
			);

			$this->sut->maybe_upgrade();

			$running = $this->decode( $this->db->mutations[0]['to'] );
			self::assertSame( 'running', $running['state'] );
			self::assertSame( 'test-owner-1', $running['token'] );
			self::assertSame( AKIBARA_BUSINESS_VERSION, $this->db->option_values[ self::VERSION_OPTION ] );
			self::assertSame( '', $this->db->option_values[ self::PENDING_OPTION ] );
			self::assertSame( array( $this->upgrade_action( '1.95.2' ) ), $this->actions );
		}

		/** @testdox A queued pre-seal marker is recovered, sealed, run, and cleared. */
		public function test_queued_pre_seal_marker_is_recovered(): void {
			$queued = $this->state( 'queued', AKIBARA_BUSINESS_VERSION, '1.95.2' );
			$this->set_options(
				array(
					self::VERSION_OPTION => '1.95.2',
					self::PENDING_OPTION => $queued,
				)
			);

			$this->sut->maybe_upgrade();

			self::assertSame( array( 'running', 'sealed', 'cleared' ), $this->upgrade_mutation_phases() );
			self::assertSame( array( $this->upgrade_action( '1.95.2' ) ), $this->actions );
		}

		/** @testdox UpgradeRetryRequired requeues the lease and the next request succeeds. */
		public function test_retryable_listener_failure_requeues_then_succeeds(): void {
			$this->set_options( array( self::VERSION_OPTION => '1.95.2' ) );
			$failures = 0;
			$this->action_handler = static function ( string $hook ) use ( &$failures ): void {
				if ( 'akibara_business_upgraded' === $hook && 0 === $failures++ ) {
					throw new UpgradeRetryRequired( 'retry once' );
				}
			};

			$this->sut->maybe_upgrade();
			$requeued = $this->decode( $this->db->option_values[ self::PENDING_OPTION ] );
			self::assertSame( 'queued', $requeued['state'] );
			self::assertSame( AKIBARA_BUSINESS_VERSION, $this->db->option_values[ self::VERSION_OPTION ] );

			$this->sut->maybe_upgrade();

			self::assertSame( '', $this->db->option_values[ self::PENDING_OPTION ] );
			self::assertSame( 2, $this->flushes );
			self::assertCount( 2, $this->actions );
		}

		/** @testdox A generic listener throwable leaves the owned running lease for expiry recovery. */
		public function test_generic_listener_throwable_leaves_running_lease_and_rethrows(): void {
			$this->set_options( array( self::VERSION_OPTION => '1.95.2' ) );
			$this->action_handler = static function ( string $hook ): void {
				if ( 'akibara_business_upgraded' === $hook ) {
					throw new RuntimeException( 'listener failed' );
				}
			};

			$caught = null;
			try {
				$this->sut->maybe_upgrade();
			} catch ( Throwable $error ) {
				$caught = $error;
			}

			self::assertInstanceOf( RuntimeException::class, $caught );
			self::assertSame( 'listener failed', $caught->getMessage() );
			self::assertSame( 'running', $this->decode( $this->db->option_values[ self::PENDING_OPTION ] )['state'] );
			self::assertSame( AKIBARA_BUSINESS_VERSION, $this->db->option_values[ self::VERSION_OPTION ] );
		}

		/** @testdox A stale owner cannot clear a replacement owner's token. */
		public function test_stale_owner_cas_cannot_clear_a_new_token(): void {
			$this->set_options( array( self::VERSION_OPTION => '1.95.2' ) );
			$new_owner = $this->state( 'running', AKIBARA_BUSINESS_VERSION, '1.95.2', 'new-owner', time() + 600 );
			$this->db->before_query = static function ( UpgradeWpdbFake $db, array $call ) use ( $new_owner ): void {
				$args = $call['args'];
				if (
					3 === count( $args )
					&& '' === $args[0]
					&& self::PENDING_OPTION === $args[1]
					&& str_contains( (string) $args[2], 'test-owner-1' )
				) {
					$db->option_values[ self::PENDING_OPTION ] = $new_owner;
					$db->before_query = null;
				}
			};

			$this->sut->maybe_upgrade();

			self::assertSame( $new_owner, $this->db->option_values[ self::PENDING_OPTION ] );
			self::assertSame( 1, $this->flushes, 'The old owner had already run, but must not clear new ownership.' );
			self::assertCount( 1, $this->actions );
		}

		/** @testdox A valid legacy marker runs with its exact semantic versions. */
		public function test_valid_legacy_marker_is_consumed(): void {
			$legacy = AKIBARA_BUSINESS_VERSION . '|1.95.2';
			$this->set_options(
				array(
					self::VERSION_OPTION => '1.95.2',
					self::PENDING_OPTION => $legacy,
				)
			);

			$this->sut->maybe_upgrade();

			self::assertSame( array( $this->upgrade_action( '1.95.2' ) ), $this->actions );
			self::assertSame( '', $this->db->option_values[ self::PENDING_OPTION ] );
		}

		/**
		 * @testdox Corrupt markers are cleared and only the canonical current bump is emitted.
		 * @dataProvider corrupt_marker_provider
		 */
		public function test_corrupt_marker_is_cleared_before_current_bump( string $corrupt ): void {
			$this->set_options(
				array(
					self::VERSION_OPTION => '1.95.2',
					self::PENDING_OPTION => $corrupt,
				)
			);

			$this->sut->maybe_upgrade();

			self::assertSame( array( $this->upgrade_action( '1.95.2' ) ), $this->actions );
			self::assertSame( '', $this->db->option_values[ self::PENDING_OPTION ] );
			self::assertSame( $corrupt, $this->db->mutations[0]['from'] );
			self::assertSame( '', $this->db->mutations[0]['to'] );
		}

		/** @return array<string,array{string}> */
		public static function corrupt_marker_provider(): array {
			return array(
				'plain garbage' => array( 'not-an-upgrade-marker' ),
				'corrupt JSON'  => array( '{"state":"running","current":"not-semver","token":"bad","expires_at":9999999999}' ),
			);
		}

		/**
		 * @testdox A marker with a corrupt previous version is cleared and never reaches the public hook.
		 * @dataProvider corrupt_previous_version_provider
		 */
		public function test_corrupt_previous_version_is_never_emitted( string $corrupt ): void {
			$this->set_options(
				array(
					self::VERSION_OPTION => AKIBARA_BUSINESS_VERSION,
					self::PENDING_OPTION => $corrupt,
				)
			);

			$this->sut->maybe_upgrade();

			self::assertSame( '', $this->db->option_values[ self::PENDING_OPTION ] );
			self::assertSame( array(), $this->actions, 'Malformed version strings must never reach the public hook.' );
			self::assertSame( 0, $this->flushes );
		}

		/** @return array<string,array{string}> */
		public static function corrupt_previous_version_provider(): array {
			return array(
				'legacy marker' => array( AKIBARA_BUSINESS_VERSION . '|not-semver' ),
				'JSON marker'   => array(
					(string) json_encode(
						array(
							'state'      => 'queued',
							'current'    => AKIBARA_BUSINESS_VERSION,
							'previous'   => 'not-semver',
							'token'      => '',
							'expires_at' => 0,
						)
					),
				),
			);
		}

		/** @testdox First Search contract adoption seeds baseline then schedules its pending jump once. */
		public function test_first_search_contract_adoption_rebuilds_once(): void {
			$this->set_options( array( self::VERSION_OPTION => AKIBARA_BUSINESS_VERSION ) );
			unset( $this->db->option_values[ self::CONTRACT_OPTION ] );

			$this->sut->maybe_upgrade();

			self::assertSame( $this->contract_version(), $this->db->option_values[ self::CONTRACT_OPTION ] );
			self::assertSame( array( true ), RebuildJob::$scheduled );
			self::assertSame( $this->adoption_version(), $this->db->mutations[0]['to'] );
			self::assertSame( $this->contract_version(), $this->db->mutations[1]['to'] );
		}

		/** @testdox A Business bump with the same Search contract does not rebuild Search. */
		public function test_business_bump_with_same_contract_does_not_reindex(): void {
			$this->set_options( array( self::VERSION_OPTION => '1.95.2' ) );

			$this->sut->maybe_upgrade();

			self::assertSame( array(), RebuildJob::$scheduled );
			self::assertSame( array( $this->upgrade_action( '1.95.2' ) ), $this->actions );
		}

		/** @testdox A Search contract bump schedules one forced rebuild without a Business bump. */
		public function test_search_contract_bump_reindexes_without_business_bump(): void {
			$this->set_options(
				array(
					self::VERSION_OPTION  => AKIBARA_BUSINESS_VERSION,
					self::CONTRACT_OPTION => $this->adoption_version(),
				)
			);

			$this->sut->maybe_upgrade();

			self::assertSame( array( true ), RebuildJob::$scheduled );
			self::assertSame( array(), $this->actions );
			self::assertSame( 0, $this->flushes );
		}

		/** Reset the mutable option table to a canonical idle installation. */
		private function set_options( array $overrides = array() ): void {
			$this->db->option_values = array_merge(
				array(
					self::VERSION_OPTION  => AKIBARA_BUSINESS_VERSION,
					self::PENDING_OPTION  => '',
					self::CONTRACT_OPTION => $this->contract_version(),
				),
				$overrides
			);
			$this->db->mutations = array();
		}

		/** Encode one canonical persisted lease state. */
		private function state(
			string $state,
			string $current,
			string $previous,
			string $token = '',
			int $expires_at = 0
		): string {
			return (string) json_encode(
				array(
					'state'      => $state,
					'current'    => $current,
					'previous'   => $previous,
					'token'      => $token,
					'expires_at' => $expires_at,
				)
			);
		}

		/** @return array{state:string,current:string,previous:string,token:string,expires_at:int} */
		private function decode( string $state ): array {
			/** @var array{state:string,current:string,previous:string,token:string,expires_at:int} $decoded */
			$decoded = json_decode( $state, true, 512, JSON_THROW_ON_ERROR );

			return $decoded;
		}

		/** Map persisted mutations to their behavioral lease phase. */
		private function upgrade_mutation_phases(): array {
			$phases = array();
			foreach ( $this->db->mutations as $mutation ) {
				if ( self::VERSION_OPTION === $mutation['option'] ) {
					$phases[] = 'sealed';
					continue;
				}

				if ( self::PENDING_OPTION !== $mutation['option'] ) {
					continue;
				}

				if ( '' === $mutation['to'] ) {
					$phases[] = 'cleared';
					continue;
				}

				$phases[] = $this->decode( $mutation['to'] )['state'];
			}

			return $phases;
		}

		/** Build the exact public hook record expected for one Business bump. */
		private function upgrade_action( string $previous ): array {
			return array(
				'hook' => 'akibara_business_upgraded',
				'args' => array( AKIBARA_BUSINESS_VERSION, $previous ),
			);
		}

		/** Read the private production contract version without duplicating it. */
		private function contract_version(): string {
			return (string) ( new ReflectionClass( Plugin::class ) )->getConstant( 'SEARCH_FEED_CONTRACT_VERSION' );
		}

		/** Read the private production adoption baseline without duplicating it. */
		private function adoption_version(): string {
			return (string) ( new ReflectionClass( Plugin::class ) )->getConstant( 'SEARCH_FEED_CONTRACT_ADOPTION_VERSION' );
		}
	}
}
