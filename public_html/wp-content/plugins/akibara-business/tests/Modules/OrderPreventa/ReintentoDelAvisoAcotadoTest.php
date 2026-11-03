<?php
/**
 * El reintento del aviso «tu pedido ya llegó» tiene tope también cuando el que
 * falla es el WORKER.
 *
 * Hallazgo S-686f69f2 (QA, reproducido): hay dos modos de fallo y sólo uno
 * estaba acotado. `SendFailureRearm` cuenta intentos cuando el transporte
 * rechaza el correo o el email revienta al armarse. Pero si la acción de
 * Action Scheduler muere antes —un fatal de PHP al cargar el mailer, por
 * ejemplo—, el contador del email nunca se mueve, Action Scheduler la marca
 * fallida y `maybe_retry_failed_ready_email_dispatch()` la re-agenda cada
 * 305 s, para siempre.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	if ( ! class_exists( 'ActionScheduler' ) ) {
		/**
		 * Doble mínimo: sólo la tienda de acciones que consulta el reintento.
		 */
		final class ActionScheduler {

			/** @var object|null Tienda falsa. */
			public static $akb_store = null;

			/** @return object|null */
			public static function store() {
				return self::$akb_store;
			}
		}
	}
}

namespace Akibara\Business\Tests\Modules\OrderPreventa {

	use Akibara\Business\Modules\OrderPreventa\MetaKeys;
	use Akibara\Business\Modules\OrderPreventa\ReadyEmailDispatcher;
	use Akibara\Business\Modules\OrderPreventa\SendFailureRearm;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class ReintentoDelAvisoAcotadoTest extends TestCase {

		private \WC_Order $order;

		/** @var array<int, array<string, int>> Argumentos de cada reintento agendado. */
		private array $agendados = array();

		/** @var array<int, string> Lo que se escribió en el registro de WooCommerce. */
		private array $registro = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			$this->order         = new \WC_Order();
			$this->order->akb_id = 202;
			$order               = $this->order;

			Functions\when( 'wc_get_order' )->alias( static fn( $id ) => 202 === (int) $id ? $order : false );
			Functions\when( 'as_has_scheduled_action' )->justReturn( false );

			$agendados = &$this->agendados;
			Functions\when( 'as_schedule_single_action' )->alias(
				static function ( int $ts, string $hook, array $args = array(), string $group = '' ) use ( &$agendados ): int {
					unset( $ts, $hook, $group );
					$agendados[] = $args;
					return count( $agendados );
				}
			);

			$registro = &$this->registro;
			Functions\when( 'wc_get_logger' )->justReturn(
				new class( $registro ) {
					/** @var array<int, string> */
					private array $registro;

					/** @param array<int, string> $registro Destino. */
					public function __construct( array &$registro ) {
						$this->registro = &$registro;
					}

					/** @param array<string, string> $context Contexto. */
					public function warning( string $message, array $context = array() ): void {
						unset( $context );
						$this->registro[] = $message;
					}
				}
			);

			\ActionScheduler::$akb_store = new class() {
				/** @return object */
				public function fetch_action( int $id ) {
					unset( $id );
					return new class() {
						public function get_hook(): string {
							return 'akb_order_preventa_dispatch_ready_email';
						}

						/** @return array<string, int> */
						public function get_args(): array {
							return array( 'order_id' => 202 );
						}
					};
				}
			};
		}

		protected function tearDown(): void {
			\ActionScheduler::$akb_store = null;
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * @testdox Un worker que falla siempre se reintenta dos veces y se para, con una sola nota.
		 */
		public function test_un_worker_que_falla_siempre_deja_de_reintentar(): void {
			$dispatcher = new ReadyEmailDispatcher();

			for ( $accion = 1; $accion <= SendFailureRearm::MAX_ATTEMPTS + 2; $accion++ ) {
				$dispatcher->maybe_retry_failed_ready_email_dispatch( $accion );
			}

			self::assertCount( SendFailureRearm::MAX_ATTEMPTS - 1, $this->agendados, 'Tres fallos del worker: dos reintentos y basta.' );
			self::assertSame( array( 'order_id' => 202 ), $this->agendados[0] );
			self::assertCount( 1, $this->order->akb_notes, 'El operador se entera una vez, no en cada fallo siguiente.' );
			self::assertStringContainsString( 'Acciones del pedido', $this->order->akb_notes[0] );
			self::assertCount( 1, $this->registro, 'Y queda en el registro «preventa».' );
		}

		/**
		 * @testdox Cada fallo contado queda guardado en el pedido.
		 */
		public function test_cada_fallo_contado_se_guarda(): void {
			$dispatcher = new ReadyEmailDispatcher();
			$antes      = $this->order->akb_saves;

			$dispatcher->maybe_retry_failed_ready_email_dispatch( 1 );
			$dispatcher->maybe_retry_failed_ready_email_dispatch( 2, null );

			self::assertSame( $antes + 2, $this->order->akb_saves, 'Sin guardar, el contador no sobrevive al siguiente worker.' );
			self::assertSame( 2, (int) $this->order->get_meta( MetaKeys::ORDER_READY_EMAIL_WORKER_FAILURES ) );
		}

		/**
		 * @testdox Si el propio email ya agotó su presupuesto, no hay segundo contador ni segunda nota.
		 */
		public function test_si_el_email_ya_agoto_su_presupuesto_no_hay_segunda_nota(): void {
			$this->order->update_meta_data( MetaKeys::ORDER_READY_EMAIL_ATTEMPTS, SendFailureRearm::MAX_ATTEMPTS );

			( new ReadyEmailDispatcher() )->maybe_retry_failed_ready_email_dispatch( 1 );

			self::assertSame( array(), $this->agendados );
			self::assertSame( array(), $this->order->akb_notes );
			self::assertSame( '', (string) $this->order->get_meta( MetaKeys::ORDER_READY_EMAIL_WORKER_FAILURES ) );
		}

		/**
		 * @testdox Un error al evaluar el reintento se registra y no corta la cola.
		 */
		public function test_un_error_al_evaluar_no_corta_la_cola(): void {
			Functions\when( 'wc_get_order' )->alias(
				static function (): void {
					throw new \RuntimeException( 'base de datos caída' );
				}
			);

			( new ReadyEmailDispatcher() )->maybe_retry_failed_ready_email_dispatch( 1 );

			self::assertCount( 1, $this->registro );
			self::assertStringContainsString( 'base de datos caída', $this->registro[0] );
		}

		/**
		 * @testdox Si el aviso ya salió, un fallo posterior del worker no agenda nada.
		 */
		public function test_un_aviso_ya_enviado_no_se_reintenta(): void {
			$this->order->update_meta_data( MetaKeys::ORDER_READY_EMAIL_SENT, (string) time() );

			( new ReadyEmailDispatcher() )->maybe_retry_failed_ready_email_dispatch( 1 );

			self::assertSame( array(), $this->agendados );
		}

		/**
		 * @testdox El reenvío manual reabre el cupo de fallos del worker.
		 */
		public function test_el_reenvio_manual_reabre_el_cupo(): void {
			$this->order->update_meta_data( MetaKeys::ORDER_READY_EMAIL_WORKER_FAILURES, SendFailureRearm::MAX_ATTEMPTS );

			( new ReadyEmailDispatcher() )->handle_resend_ready_email_action( $this->order );

			self::assertSame( '', (string) $this->order->get_meta( MetaKeys::ORDER_READY_EMAIL_WORKER_FAILURES ) );
		}
	}
}
