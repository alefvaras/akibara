<?php
/**
 * La máquina de despacho del aviso «preventa lista», ya fuera del orquestador.
 *
 * `OrderPreventa` es la RAÍZ DE COMPOSICIÓN del módulo: instancia dieciocho
 * colaboradores y les dice cuándo registrarse. Con la máquina adentro era además
 * el dueño de una lógica con estado propio —agendado, reintento, idempotencia
 * por marker, supresión del email nativo y reenvío manual—, y no había forma de
 * probar la máquina sin construir el módulo entero (auditoría 2026-08-31).
 *
 * Lo que fija esta prueba es el CONTRATO de hooks que se movió: si la extracción
 * hubiera perdido uno, el aviso dejaría de salir sin que nada fallara.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\ReadyEmailDispatcher;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ReadyEmailDispatcherTest extends TestCase {

	/** @var array<int, string> Hooks enganchados. */
	private array $hooks = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->hooks = array();
		Functions\when( 'add_action' )->alias(
			function ( string $hook ): bool {
				$this->hooks[] = $hook;
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( string $hook ): bool {
				$this->hooks[] = $hook;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Engancha todos los puntos que hacen salir, reintentar y reenviar los avisos.
	 */
	public function test_engancha_el_contrato_completo(): void {
		( new ReadyEmailDispatcher() )->register();

		foreach (
			array(
				// El flip que marca el pedido listo.
				'akb_order_preventa_marked_ready',
				// El worker propio.
				'akb_order_preventa_dispatch_ready_email',
				// Los tres avisos de fallo de Action Scheduler.
				'action_scheduler_failed_execution',
				'action_scheduler_failed_action',
				'action_scheduler_unexpected_shutdown',
				// La supresión del genérico de WooCommerce.
				'woocommerce_email_enabled_customer_completed_order',
				// El reintento al entrar a un estado apto.
				'woocommerce_order_status_changed',
				// El reenvío manual desde «Acciones del pedido».
				'woocommerce_order_actions',
				'woocommerce_order_action_akb_resend_preventa_ready',
				'woocommerce_order_action_akb_resend_preventa_reminder',
				'woocommerce_order_action_akb_resend_preventa_date_change',
			) as $hook
		) {
			self::assertContains( $hook, $this->hooks, "Falta el enganche de {$hook}." );
		}
	}

	/**
	 * El orquestador queda SOLO como raíz de composición: sin la lógica del
	 * despacho escrita adentro.
	 */
	public function test_el_orquestador_ya_no_es_dueno_del_despacho(): void {
		$fuente = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/OrderPreventa/OrderPreventa.php'
		);

		self::assertStringContainsString( 'ready_email_dispatcher->register()', $fuente );

		foreach (
			array(
				'as_schedule_single_action',
				'READY_EMAIL_DISPATCH_HOOK',
				'ORDER_ACTION_RESEND_READY',
				'maybe_suppress_native_completed_email',
			) as $resto
		) {
			self::assertStringNotContainsString(
				$resto,
				$fuente,
				'La máquina de despacho vive en ReadyEmailDispatcher, no en la raíz de composición.'
			);
		}
	}

	/**
	 * @testdox Un pedido con preventa pendiente suprime el «pedido completado» nativo.
	 */
	public function test_suprime_el_generico_de_woocommerce(): void {
		$order = new \WC_Order( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) );

		$item                                          = new \WC_Order_Item_Product();
		$item->akb_id                                  = 1;
		$item->akb_quantity                            = 1;
		$item->akb_meta[ MetaKeys::ITEM_WAS_PREVENTA ] = 'yes';
		$order->akb_items                              = array( $item );

		self::assertFalse(
			( new ReadyEmailDispatcher() )->maybe_suppress_native_completed_email( true, $order )
		);
	}

	/**
	 * @testdox Un pedido sin preventa deja pasar el email nativo.
	 */
	public function test_un_pedido_normal_conserva_el_generico(): void {
		self::assertTrue(
			( new ReadyEmailDispatcher() )->maybe_suppress_native_completed_email( true, new \WC_Order() )
		);
	}

	/**
	 * @testdox Sin un pedido de verdad, el filtro devuelve lo que le dieron.
	 */
	public function test_sin_pedido_devuelve_el_valor_recibido(): void {
		self::assertTrue(
			( new ReadyEmailDispatcher() )->maybe_suppress_native_completed_email( true, null )
		);
	}

	/**
	 * Un grupo por pedido dejaba una fila de `actionscheduler_groups` por cada
	 * preventa (QA 2026-09-01). Con grupo fijo, la unicidad la dan los args y el
	 * flag `$unique` de AS debe quedar apagado: dedupea por hook+grupo y
	 * habría bloqueado el aviso de los demás pedidos.
	 *
	 * @testdox Agenda en el grupo fijo del módulo y dedupea por args, no por grupo.
	 */
	public function test_agenda_en_el_grupo_fijo_y_dedupea_por_args(): void {
		$agendado = array();
		$unicos   = array();
		Functions\when( 'as_has_scheduled_action' )->alias(
			static function ( string $hook, array $args, string $group ) use ( &$agendado ): bool {
				return in_array( array( $hook, $args, $group ), $agendado, true );
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( int $ts, string $hook, array $args = array(), string $group = '', bool $unique = false ) use ( &$agendado, &$unicos ): int {
				$agendado[] = array( $hook, $args, $group );
				$unicos[]   = $unique;
				return count( $agendado );
			}
		);

		$dispatcher = new ReadyEmailDispatcher();
		$dispatcher->schedule_ready_email_dispatch( 101 );
		$dispatcher->schedule_ready_email_dispatch( 101 );
		$dispatcher->schedule_ready_email_dispatch( 202 );
		$dispatcher->schedule_ready_email_dispatch_retry( 202 );

		self::assertCount( 2, $agendado, 'El mismo pedido no se agenda dos veces; otro pedido sí, aunque comparta grupo; el retry ve al primario pendiente.' );
		self::assertSame( ReadyEmailDispatcher::AS_GROUP, $agendado[0][2] );
		self::assertSame( ReadyEmailDispatcher::AS_GROUP, $agendado[1][2] );
		self::assertSame( array( 'order_id' => 202 ), $agendado[1][1] );
		self::assertSame( array( false, false ), $unicos, 'El flag unique de AS dedupea por hook+grupo: con grupo fijo dejaría sin aviso a los demás pedidos.' );
	}
}
