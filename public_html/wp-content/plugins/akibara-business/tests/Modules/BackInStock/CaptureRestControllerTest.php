<?php
/**
 * Tests for BackInStock capture persistence guarantees.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
	defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
	defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

	if ( ! class_exists( 'wpdb' ) ) {
		class wpdb {
			/** @var int|false */
			public $insert_result = 1;

			public int $insert_id = 1;

			/** @var list<array<string, mixed>|null> */
			public array $rows = array();

			/** @var int|false */
			public $query_result = 1;

			public int $var_result = 0;

			public int $delete_result = 0;

			/** @var array{table:string,data:array<string,mixed>,format:list<string>}|null */
			public ?array $delete_call = null;

			public string $last_query = '';

			/**
			 * @param array<string, mixed> $data
			 * @param list<string>         $format
			 * @return int|false
			 */
			public function insert( string $table, array $data, array $format ) {
				return $this->insert_result;
			}

			/**
			 * @param mixed ...$args
			 */
			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			/**
			 * @return array<string, mixed>|null
			 */
			public function get_row( string $query, string $output ) {
				return array_shift( $this->rows );
			}

			/**
			 * @return int|false
			 */
			public function query( string $query ) {
				$this->last_query = $query;
				return $this->query_result;
			}

			public function get_var( string $query ): int {
				return $this->var_result;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param list<string>         $format
			 */
			public function delete( string $table, array $data, array $format ): int {
				$this->delete_call = compact( 'table', 'data', 'format' );
				return $this->delete_result;
			}
		}
	}
}

namespace Akibara\Business\Tests\Modules\BackInStock {

	use Akibara\Business\Modules\BackInStock\CaptureRestController;
	use Akibara\Business\Modules\BackInStock\NotificationDispatcher;
	use Akibara\Business\Modules\BackInStock\Retention;
	use Akibara\Business\Modules\BackInStock\StockWatcher;
	use Akibara\Business\Modules\BackInStock\Subscriber;
	use Akibara\Business\Modules\BackInStock\SubscriberRepository;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class CaptureRestControllerTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			Functions\when( 'apply_filters' )->alias(
				static fn( string $hook, $value, ...$args ) => $value
			);
			Functions\when( 'get_option' )->justReturn( 'yes' );
			Functions\when( 'is_email' )->justReturn( true );
			// Staff exento del rate limit (desde 1.98.0 la exención es por
			// capability manage_woocommerce, no por estar logueado).
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'wc_get_product' )->justReturn( new \WC_Product( 'outofstock' ) );
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		public function test_valid_submission_is_persisted_without_an_artificial_delay(): void {
			$db                = new \wpdb();
			$db->rows          = array( null );
			$db->insert_result = 1;
			$db->insert_id     = 91;

			$result = $this->controller( $db )->subscribe( 44, 'cliente@example.test', null );

			self::assertSame( 'subscribed', $result['code'] );
			self::assertSame( 201, $result['status'] );
		}

		public function test_insert_failure_without_a_persisted_row_returns_storage_error(): void {
			$db                = new \wpdb();
			$db->rows          = array( null, null );
			$db->insert_result = false;

			$result = $this->controller( $db )->subscribe( 44, 'cliente@example.test', null );

			self::assertSame( 'storage_error', $result['code'] );
			self::assertSame( 500, $result['status'] );
		}

		public function test_unique_race_is_confirmed_by_reloading_the_row(): void {
			$db                = new \wpdb();
			$db->rows          = array(
				null,
				$this->subscriber_row( Subscriber::STATUS_SUBSCRIBED ),
			);
			$db->insert_result = false;

			$result = $this->controller( $db )->subscribe( 44, 'cliente@example.test', null );

			self::assertSame( 'already_subscribed', $result['code'] );
			self::assertSame( 409, $result['status'] );
		}

		public function test_failed_reactivation_does_not_report_success(): void {
			$db               = new \wpdb();
			$db->rows         = array( $this->subscriber_row( Subscriber::STATUS_UNSUBSCRIBED ) );
			$db->query_result = false;

			$result = $this->controller( $db )->subscribe( 44, 'cliente@example.test', null );

			self::assertSame( 'storage_error', $result['code'] );
			self::assertSame( 500, $result['status'] );
		}

		public function test_paused_dispatch_requeues_waiting_rows(): void {
			$db             = new \wpdb();
			$db->var_result = 2;
			$scheduled      = array();

			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					if ( 'akb_bis_dispatch_enabled' === $hook ) {
						return false;
					}
					return $value;
				}
			);
			Functions\when( 'wc_get_product' )->justReturn( new \WC_Product( 'instock' ) );
			Functions\when( 'do_action' )->justReturn( null );
			Functions\when( 'as_schedule_single_action' )->alias(
				static function ( int $timestamp, string $hook, array $args, string $group ) use ( &$scheduled ): void {
					$scheduled = compact( 'timestamp', 'hook', 'args', 'group' );
				}
			);

			$before = time();
			( new NotificationDispatcher(
				new SubscriberRepository( $db, 'wp_akb_back_in_stock' ),
				static fn() => null
			) )->dispatch( 44 );

			self::assertSame( StockWatcher::ASYNC_HOOK, $scheduled['hook'] );
			self::assertSame(
				array(
					'product_id' => 44,
					'pause_try'  => 1,
				),
				$scheduled['args'],
				'El reintento de pausa lleva su contador en los args: es lo que le pone techo.'
			);
			self::assertSame( StockWatcher::AS_GROUP, $scheduled['group'] );
			self::assertGreaterThanOrEqual( $before + 900, $scheduled['timestamp'] );
			self::assertLessThanOrEqual( time() + 901, $scheduled['timestamp'] );
		}

		/**
		 * El reintento de pausa tiene TECHO.
		 *
		 * Sin él, una cola con el despacho apagado se re-agendaba cada 15 minutos
		 * para siempre: 96 acciones al día por producto, esperando a que alguien
		 * encendiera un ajuste. Al cuarto intento la cadena se suelta; los
		 * suscriptores siguen en 'subscribed' y los retoma el guardado del ajuste
		 * o la próxima transición de stock.
		 */
		public function test_paused_dispatch_stops_after_the_retry_ceiling(): void {
			$db             = new \wpdb();
			$db->var_result = 2;
			$scheduled      = array();

			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					if ( 'akb_bis_dispatch_enabled' === $hook ) {
						return false;
					}
					return $value;
				}
			);
			Functions\when( 'wc_get_product' )->justReturn( new \WC_Product( 'instock' ) );
			Functions\when( 'do_action' )->justReturn( null );
			Functions\when( 'as_schedule_single_action' )->alias(
				static function ( int $timestamp, string $hook, array $args, string $group ) use ( &$scheduled ): void {
					$scheduled[] = compact( 'timestamp', 'hook', 'args', 'group' );
				}
			);

			$dispatcher = new NotificationDispatcher(
				new SubscriberRepository( $db, 'wp_akb_back_in_stock' ),
				static fn() => null
			);

			// Cuarto reintento ya gastado → no se agenda ninguno más.
			$dispatcher->dispatch( 44, 4 );
			self::assertSame( array(), $scheduled, 'Con el techo alcanzado no se agenda otro reintento.' );

			// Y el anterior sí, con el contador avanzado.
			$dispatcher->dispatch( 44, 3 );
			self::assertCount( 1, $scheduled );
			self::assertSame(
				array(
					'product_id' => 44,
					'pause_try'  => 4,
				),
				$scheduled[0]['args']
			);
		}

		/**
		 * El fatal que perdía avisos: en 1 de cada 4 corridas de WP-Cron el
		 * worker moría con `Class "WC_Email" not found` —el autoloader de
		 * WooCommerce no sabe resolver esa clase— y Action Scheduler NO
		 * reintenta un fatal. Ahora se re-encola a +60s (no a los 15 minutos de
		 * la pausa: esto es una carrera de arranque, no un ajuste apagado) y
		 * NINGUNA fila se marca `notified` sin haber enviado.
		 */
		public function test_unloadable_mailer_reschedules_instead_of_burning_the_batch(): void {
			$db             = new \wpdb();
			$db->var_result = 2;
			$scheduled      = array();

			Functions\when( 'wc_get_product' )->justReturn( new \WC_Product( 'instock' ) );
			Functions\when( 'do_action' )->justReturn( null );
			// `function_exists('wc_get_logger')` da TRUE por contagio de otra
			// prueba de la suite: sin este doble, el aviso del log revienta en la
			// corrida completa y pasa en aislamiento.
			Functions\when( 'wc_get_logger' )->justReturn(
				new class() {
					/** @param array<string, mixed> $context */
					public function warning( string $message, array $context = array() ): void {
						unset( $message, $context );
					}
				}
			);
			Functions\when( 'as_schedule_single_action' )->alias(
				static function ( int $timestamp, string $hook, array $args, string $group ) use ( &$scheduled ): void {
					$scheduled[] = compact( 'timestamp', 'hook', 'args', 'group' );
				}
			);

			$before     = time();
			$dispatcher = new NotificationDispatcher(
				new SubscriberRepository( $db, 'wp_akb_back_in_stock' ),
				static function (): void {
					self::fail( 'El correo NO puede resolverse con el mailer caído: ahí es donde reventaba.' );
				},
				static fn(): bool => false
			);

			$dispatcher->dispatch( 44 );

			self::assertCount( 1, $scheduled );
			self::assertSame( StockWatcher::ASYNC_HOOK, $scheduled[0]['hook'] );
			self::assertSame(
				array(
					'product_id' => 44,
					'pause_try'  => 1,
				),
				$scheduled[0]['args']
			);
			self::assertGreaterThanOrEqual( $before + 60, $scheduled[0]['timestamp'] );
			self::assertLessThanOrEqual( time() + 61, $scheduled[0]['timestamp'] );
		}

		/** El reintento por mailer caído comparte el techo de la pausa. */
		public function test_unloadable_mailer_retry_stops_at_the_ceiling(): void {
			$db             = new \wpdb();
			$db->var_result = 2;
			$scheduled      = array();

			Functions\when( 'wc_get_product' )->justReturn( new \WC_Product( 'instock' ) );
			Functions\when( 'do_action' )->justReturn( null );
			Functions\when( 'as_schedule_single_action' )->alias(
				static function ( int $timestamp, string $hook, array $args, string $group ) use ( &$scheduled ): void {
					$scheduled[] = compact( 'timestamp', 'hook', 'args', 'group' );
				}
			);

			$dispatcher = new NotificationDispatcher(
				new SubscriberRepository( $db, 'wp_akb_back_in_stock' ),
				static fn() => null,
				static fn(): bool => false
			);

			$dispatcher->dispatch( 44, 4 );

			self::assertSame( array(), $scheduled, 'Con el techo alcanzado la cadena se suelta; las filas siguen esperando.' );
		}

		public function test_terminal_retention_runs_without_touching_waiting_rows(): void {
			$db               = new \wpdb();
			$db->query_result = 3;
			$retention        = new Retention( new SubscriberRepository( $db, 'wp_akb_back_in_stock' ) );

			self::assertSame( 3, $retention->run() );
			self::assertStringContainsString( 'status IN (%s, %s, %s)', $db->last_query );
			self::assertStringContainsString( 'COALESCE(notified_at, created_at)', $db->last_query );
			self::assertStringNotContainsString( "status = 'subscribed'", $db->last_query );
		}

		public function test_product_deletion_removes_orphan_subscriptions(): void {
			$db                = new \wpdb();
			$db->delete_result = 2;
			Functions\when( 'get_post_type' )->justReturn( 'product' );

			( new Retention( new SubscriberRepository( $db, 'wp_akb_back_in_stock' ) ) )
				->erase_product_subscriptions( 44 );

			self::assertSame( 'wp_akb_back_in_stock', $db->delete_call['table'] );
			self::assertSame( array( 'product_id' => 44 ), $db->delete_call['data'] );
			self::assertSame( array( '%d' ), $db->delete_call['format'] );
		}

		public function test_retention_schedules_a_single_daily_event(): void {
			$db        = new \wpdb();
			$scheduled = array();
			Functions\when( 'wp_next_scheduled' )->justReturn( false );
			Functions\when( 'wp_schedule_event' )->alias(
				static function ( int $timestamp, string $recurrence, string $hook ) use ( &$scheduled ): void {
					$scheduled = compact( 'timestamp', 'recurrence', 'hook' );
				}
			);

			$before = time();
			( new Retention( new SubscriberRepository( $db, 'wp_akb_back_in_stock' ) ) )->maybe_schedule();

			self::assertSame( 'daily', $scheduled['recurrence'] );
			self::assertSame( Retention::HOOK, $scheduled['hook'] );
			self::assertGreaterThanOrEqual( $before + HOUR_IN_SECONDS, $scheduled['timestamp'] );
		}

		private function controller( \wpdb $db ): CaptureRestController {
			return new CaptureRestController( new SubscriberRepository( $db, 'wp_akb_back_in_stock' ) );
		}

		/**
		 * @return array<string, mixed>
		 */
		private function subscriber_row( string $status ): array {
			return array(
				'id'              => 7,
				'product_id'      => 44,
				'email'           => 'cliente@example.test',
				'user_id'         => null,
				'token'           => str_repeat( 'a', 64 ),
				'status'          => $status,
				'notify_attempts' => 0,
				'created_at'      => '2026-07-24 00:00:00',
				'notified_at'     => null,
			);
		}
	}
}
