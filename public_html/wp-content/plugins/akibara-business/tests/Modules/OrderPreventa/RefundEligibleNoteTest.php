<?php
/**
 * R3-cbc69f7a (2026-09-19): la SEÑAL del derecho a reembolso, no el reembolso.
 *
 * `BannerRenderer::promesas_para()` imprime en cada ficha reservable «si se
 * atrasa más de N días desde esa fecha, cancelas y te devolvemos el 100%»,
 * pero hasta este arreglo ningún código rastreaba ese plazo: el único cron
 * emparentado (`maybe_mark_stale`) medía con OPT_STALE_PENDING_DAYS (30) y
 * OPT_STALE_MAX_AGE_DAYS (120) — dos números editables y distintos, para un
 * propósito distinto (avisar que revise, no señalar el derecho del cliente).
 *
 * Esta prueba fija `maybe_mark_refund_eligible()`: una nota interna,
 * idempotente, cuando el pedido cruza `Settings::REFUND_AFTER_DAYS` de atraso
 * sobre su PROMESA VIGENTE. No cambia estado, no envía correo, y es
 * independiente de `ORDER_STALE_NOTED` (los dos flags y los dos plazos no se
 * pisan entre sí).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Tests\Support\NullEtaProvider;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\ReadyToShipCron;
use Akibara\Business\Modules\OrderPreventa\Settings;
use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;
use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

final class RefundEligibleNoteTest extends TestCase {

	/** «Hoy» de las pruebas: 2026-10-03 00:00 UTC. */
	private const AHORA = 1790985600;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['akibara_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param int|null $promesa_en_dias Arribo prometido HOY, en días relativos a «hoy» (negativo = ya pasó); null = sin fecha (TBA).
	 */
	private function evaluar( ?int $promesa_en_dias, bool $ya_avisado = false ): WC_Order {
		$meta = array(
			MetaKeys::ORDER_HAS_PREVENTA  => 'yes',
			MetaKeys::ORDER_READY_TO_SHIP => 'no',
			MetaKeys::ORDER_PENDING_COUNT => 2,
		);
		if ( null !== $promesa_en_dias ) {
			$meta[ MetaKeys::ORDER_SUMMARY_MAX ] = self::AHORA + $promesa_en_dias * DAY_IN_SECONDS;
		}
		if ( $ya_avisado ) {
			$meta[ MetaKeys::ORDER_REFUND_ELIGIBLE_NOTED ] = 'yes';
		}

		$order                   = new WC_Order( $meta );
		$order->akb_date_created = ( new DateTimeImmutable( '@' . self::AHORA ) );

		$cron   = new ReadyToShipCron( new SummaryRecalculator( new NullEtaProvider() ), static fn(): int => self::AHORA );
		$method = new ReflectionMethod( ReadyToShipCron::class, 'maybe_mark_refund_eligible' );
		$method->setAccessible( true );
		$method->invoke( $cron, $order );

		return $order;
	}

	/**
	 * La política publicada dice «MÁS de 15 días»: con 15 justos el derecho
	 * todavía no nació, y decirle al dueño «ya tiene derecho» sería adelantarse.
	 *
	 * @testdox Con 14 o con 15 días de atraso justos todavía no hay nota.
	 */
	public function test_below_the_threshold_nothing_happens(): void {
		foreach ( array( Settings::REFUND_AFTER_DAYS - 1, Settings::REFUND_AFTER_DAYS ) as $dias ) {
			$order = $this->evaluar( -$dias );

			self::assertSame( array(), $order->akb_notes, "{$dias} días no es «más de " . Settings::REFUND_AFTER_DAYS . '».' );
			self::assertSame( '', $order->get_meta( MetaKeys::ORDER_REFUND_ELIGIBLE_NOTED ) );
		}
	}

	/**
	 * @testdox Al pasar del plazo (16 días de atraso) deja la nota y marca el flag.
	 */
	public function test_at_the_threshold_the_note_is_left(): void {
		$order = $this->evaluar( -( Settings::REFUND_AFTER_DAYS + 1 ) );

		self::assertCount( 1, $order->akb_notes );
		self::assertStringContainsString( (string) Settings::REFUND_AFTER_DAYS . ' días', $order->akb_notes[0] );
		self::assertStringContainsString( 'derecho a cancelar', $order->akb_notes[0] );
		self::assertStringContainsString( 'reembolso del 100%', $order->akb_notes[0] );
		self::assertSame( 'yes', $order->get_meta( MetaKeys::ORDER_REFUND_ELIGIBLE_NOTED ) );
	}

	/**
	 * @testdox No envía nada al cliente: la nota es interna (WooCommerce sólo notifica al cliente si `is_customer_note` es verdadero).
	 */
	public function test_the_note_is_never_a_customer_note(): void {
		$order = $this->evaluar( -( Settings::REFUND_AFTER_DAYS + 1 ) );

		self::assertCount( 1, $order->akb_note_flags );
		self::assertFalse( (bool) $order->akb_note_flags[0][0], 'is_customer_note debe ser falso: nada de correo al cliente.' );
	}

	/**
	 * @testdox Ya avisado: no se repite aunque el atraso siga creciendo.
	 */
	public function test_the_note_is_left_only_once(): void {
		$order = $this->evaluar( -( Settings::REFUND_AFTER_DAYS + 30 ), true );

		self::assertSame( array(), $order->akb_notes, 'Idempotente: una sola vez por pedido.' );
	}

	/**
	 * @testdox Sin fecha prometida (TBA), no hay reloj que echar a andar: nunca deja la nota, sin importar cuánto lleve comprado.
	 */
	public function test_without_a_promise_nothing_is_measured(): void {
		$order = $this->evaluar( null );

		self::assertSame( array(), $order->akb_notes );
	}

	/**
	 * @testdox El plazo de reembolso es independiente del de «revisar manualmente»: uno no dispara ni calla al otro.
	 */
	public function test_independent_from_the_operational_stale_note(): void {
		// 20 días de atraso: cruza el plazo de reembolso (15) pero NO la
		// gracia operativa (30, default).
		$meta  = array(
			MetaKeys::ORDER_HAS_PREVENTA  => 'yes',
			MetaKeys::ORDER_READY_TO_SHIP => 'no',
			MetaKeys::ORDER_PENDING_COUNT => 2,
			MetaKeys::ORDER_SUMMARY_MAX   => self::AHORA - 20 * DAY_IN_SECONDS,
		);
		$order                   = new WC_Order( $meta );
		$order->akb_date_created = new DateTimeImmutable( '@' . ( self::AHORA - 90 * DAY_IN_SECONDS ) );

		$cron = new ReadyToShipCron( new SummaryRecalculator( new NullEtaProvider() ), static fn(): int => self::AHORA );

		$stale = new ReflectionMethod( ReadyToShipCron::class, 'maybe_mark_stale' );
		$stale->setAccessible( true );
		$stale->invoke( $cron, $order );

		$reembolso = new ReflectionMethod( ReadyToShipCron::class, 'maybe_mark_refund_eligible' );
		$reembolso->setAccessible( true );
		$reembolso->invoke( $cron, $order );

		self::assertSame( '', $order->get_meta( MetaKeys::ORDER_STALE_NOTED ), 'La gracia operativa (30 días) todavía no se cruzó.' );
		self::assertSame( 'yes', $order->get_meta( MetaKeys::ORDER_REFUND_ELIGIBLE_NOTED ), 'El plazo de reembolso (15 días) sí se cruzó.' );
		self::assertCount( 1, $order->akb_notes, 'Sólo la nota de reembolso; la operativa no tiene nada que decir todavía.' );
	}

	/**
	 * @testdox La pasada diaria real (`process_order()`) deja la nota de reembolso — no sólo el método aislado.
	 *
	 * Distinto de las pruebas de arriba (que invocan `maybe_mark_refund_eligible()`
	 * por reflection): ésta pasa por la ENTRADA pública que corre el cron todos
	 * los días, para fijar que la llamada quedó de verdad enganchada ahí y no
	 * sólo escrita y nunca invocada.
	 */
	public function test_the_daily_pass_leaves_the_note_through_process_order(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $h, $v ) => $v );
		Functions\when( 'sanitize_key' )->returnArg();

		$item              = new WC_Order_Item_Product();
		$item->akb_id      = 1;
		$item->akb_meta    = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
		$item->akb_product = new WC_Product( 'onbackorder' );

		$order = new WC_Order(
			array(
				MetaKeys::ORDER_HAS_PREVENTA  => 'yes',
				MetaKeys::ORDER_READY_TO_SHIP => 'no',
				MetaKeys::ORDER_PENDING_COUNT => 1,
				MetaKeys::ORDER_SUMMARY_MAX   => self::AHORA - ( Settings::REFUND_AFTER_DAYS + 1 ) * DAY_IN_SECONDS,
			)
		);
		$order->akb_status       = 'processing';
		$order->akb_items        = array( $item );
		$order->akb_date_created = new DateTimeImmutable( '@' . self::AHORA );

		$cron = new ReadyToShipCron( new SummaryRecalculator( new NullEtaProvider() ), static fn(): int => self::AHORA );
		$cron->process_order( $order );

		self::assertSame( 'yes', $order->get_meta( MetaKeys::ORDER_REFUND_ELIGIBLE_NOTED ) );
		self::assertTrue(
			(bool) array_filter(
				$order->akb_notes,
				static fn( string $nota ): bool => false !== strpos( $nota, 'derecho a cancelar' )
			),
			'process_order() tiene que dejar la nota de reembolso, no sólo la operativa.'
		);
	}
	/**
	 * `ORDER_SUMMARY_MAX` se reescribe cada vez que la editorial posterga. Medir
	 * sólo contra la fecha vigente dejaba que una fecha corrida cada 45 días
	 * reiniciara la cuenta y la señal no naciera nunca (revisión independiente
	 * de R3-cbc69f7a): el reloj cuelga de lo que el cliente vio AL COMPRAR.
	 *
	 * @testdox Una fecha que la editorial va corriendo no reinicia el reloj: cuenta la prometida al comprar.
	 */
	public function test_a_rolling_postponement_does_not_reset_the_clock(): void {
		// `PromiseWindow::of()` pregunta por la editorial del tomo SI `get_the_terms`
		// existe — y en la suite completa existe o no según qué prueba corrió antes
		// (Brain Monkey contagia `function_exists()`). Con doble propio, el
		// resultado no depende del orden.
		Functions\when( 'get_the_terms' )->justReturn( false );

		$item              = new WC_Order_Item_Product();
		$item->akb_id      = 1;
		$item->akb_meta    = array(
			MetaKeys::ITEM_WAS_PREVENTA    => 'yes',
			MetaKeys::ITEM_MIN_AT_PURCHASE => self::AHORA - 110 * DAY_IN_SECONDS,
			MetaKeys::ITEM_MAX_AT_PURCHASE => self::AHORA - 100 * DAY_IN_SECONDS,
		);
		$item->akb_product = new WC_Product( 'onbackorder' );

		$order                   = new WC_Order(
			array(
				MetaKeys::ORDER_HAS_PREVENTA  => 'yes',
				MetaKeys::ORDER_READY_TO_SHIP => 'no',
				MetaKeys::ORDER_PENDING_COUNT => 1,
				// La vigente está en el FUTURO: la editorial la corrió otra vez.
				MetaKeys::ORDER_SUMMARY_MAX   => self::AHORA + 10 * DAY_IN_SECONDS,
			)
		);
		$order->akb_items        = array( $item );
		$order->akb_date_created = new DateTimeImmutable( '@' . ( self::AHORA - 150 * DAY_IN_SECONDS ) );

		$cron   = new ReadyToShipCron( new SummaryRecalculator( new NullEtaProvider() ), static fn(): int => self::AHORA );
		$method = new ReflectionMethod( ReadyToShipCron::class, 'maybe_mark_refund_eligible' );
		$method->setAccessible( true );
		$method->invoke( $cron, $order );

		self::assertCount( 1, $order->akb_notes, 'Cien días sobre la fecha de compra: el derecho ya nació aunque la vigente sea futura.' );
		self::assertStringContainsString( '100 días de atraso', $order->akb_notes[0] );
		self::assertStringContainsString( 'prometida al comprar', $order->akb_notes[0] );
		self::assertSame( 'yes', $order->get_meta( MetaKeys::ORDER_REFUND_ELIGIBLE_NOTED ) );
	}

	/** @testdox Si la fecha nunca se movió, la nota habla de «la fecha prometida al cliente», sin más. */
	public function test_without_postponement_the_note_does_not_mention_it(): void {
		$order = $this->evaluar( -( Settings::REFUND_AFTER_DAYS + 5 ) );

		self::assertStringContainsString( 'fecha de llegada prometida al cliente:', $order->akb_notes[0] );
		self::assertStringNotContainsString( 'al comprar', $order->akb_notes[0] );
	}
}
