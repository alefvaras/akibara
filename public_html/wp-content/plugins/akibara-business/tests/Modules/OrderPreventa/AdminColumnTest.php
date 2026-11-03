<?php
/**
 * La columna «Llegada» del listado de pedidos dice lo mismo que ve el cliente.
 *
 * Un pedido con promesa del 25 de agosto seguía mostrando en septiembre
 * «Pendiente · llega ~25 ago» en presente, mientras el cliente leía
 * «Llegaba ~25 Ago · atrasado». Si el cliente escribe citando su pantalla, el
 * dueño tiene que ver lo mismo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\AdminColumn;
use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class AdminColumnTest extends TestCase {

	private const DIA = 86400;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $f, ?int $ts = null, $tz = null ): string => gmdate( $f, (int) $ts ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function columna( array $meta ): string {
		$order = new WC_Order( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) + $meta );
		ob_start();
		( new AdminColumn() )->render_column( 'akb_preventa', $order );
		return (string) ob_get_clean();
	}

	/** @testdox Con la promesa vencida, la columna dice «llegaba … · atrasado», en rojo. */
	public function test_an_overdue_promise_reads_as_late(): void {
		$html = $this->columna( array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) ( time() - 10 * self::DIA ) ) );

		self::assertStringContainsString( 'llegaba ~', $html );
		self::assertStringContainsString( 'atrasado', $html );
		self::assertStringContainsString( '#b32d2e', $html );
		self::assertStringNotContainsString( 'llega ~', $html );
	}

	/** @testdox Con la promesa viva sigue diciendo «llega ~». */
	public function test_a_live_promise_reads_as_forecast(): void {
		$html = $this->columna( array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) ( time() + 10 * self::DIA ) ) );

		self::assertStringContainsString( 'llega ~', $html );
		self::assertStringNotContainsString( 'atrasado', $html );
	}

	/** @testdox Marcado listo, no mira fechas. */
	public function test_ready_wins(): void {
		$html = $this->columna( array( MetaKeys::ORDER_READY_TO_SHIP => 'yes', MetaKeys::ORDER_SUMMARY_MAX => (string) ( time() - 10 * self::DIA ) ) );

		self::assertStringContainsString( 'Lista', $html );
		self::assertStringNotContainsString( 'atrasado', $html );
	}

	/**
	 * @testdox R3-cbc69f7a: si el cron ya dejó la nota de reembolso, la columna que lista los atrasados lo distingue sin abrir el pedido.
	 */
	public function test_an_overdue_promise_flagged_for_refund_shows_the_badge(): void {
		$html = $this->columna(
			array(
				MetaKeys::ORDER_READY_TO_SHIP           => 'no',
				MetaKeys::ORDER_SUMMARY_MAX             => (string) ( time() - 65 * self::DIA ),
				MetaKeys::ORDER_REFUND_ELIGIBLE_NOTED   => 'yes',
			)
		);

		self::assertStringContainsString( 'atrasado', $html );
		self::assertStringContainsString( 'reembolso 100%', $html );
	}

	/**
	 * @testdox Atrasado pero SIN cruzar el plazo de reembolso todavía: sin el badge.
	 */
	public function test_an_overdue_promise_without_the_refund_note_has_no_badge(): void {
		$html = $this->columna( array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) ( time() - 10 * self::DIA ) ) );

		self::assertStringContainsString( 'atrasado', $html );
		self::assertStringNotContainsString( 'reembolso 100%', $html );
	}

	/**
	 * @testdox Con la promesa viva, el badge de reembolso no puede aparecer aunque el flag esté encendido (dato inconsistente).
	 */
	public function test_a_live_promise_never_shows_the_refund_badge(): void {
		$html = $this->columna(
			array(
				MetaKeys::ORDER_READY_TO_SHIP         => 'no',
				MetaKeys::ORDER_SUMMARY_MAX           => (string) ( time() + 10 * self::DIA ),
				MetaKeys::ORDER_REFUND_ELIGIBLE_NOTED => 'yes',
			)
		);

		self::assertStringNotContainsString( 'reembolso 100%', $html );
	}
}
