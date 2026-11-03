<?php
/**
 * El aviso interno «revisar manualmente» se mide contra la PROMESA, con tope.
 *
 * Hasta 1.114.11 se medía desde la compra: acusaba de retraso a preventas
 * perfectamente sanas (un tomo que sale en 90 días lleva 30 «pendiente» sin
 * que nadie haya fallado) y callaba sobre el #27443, comprado el 12-ago con
 * llegada prometida el 2-sep, que venció sin una sola nota.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Tests\Support\NullEtaProvider;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\ReadyToShipCron;
use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;
use Brain\Monkey;
use Brain\Monkey\Functions;
use WC_Product;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WC_Order;
use WC_Order_Item_Product;

final class ReadyToShipCronStaleTest extends TestCase {

	/** «Hoy» de las pruebas: 2026-10-03 00:00 UTC. */
	private const AHORA = 1790985600;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['akibara_test_options'] = array(); // gracia 30, tope 120 (defaults)
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param int      $dias_desde_compra Días entre la compra y «hoy».
	 * @param int|null $promesa_en_dias   Arribo prometido HOY, en días relativos a «hoy» (negativo = ya pasó); null = sin fecha.
	 * @param bool     $ya_avisado        Si la nota ya se dejó antes.
	 * @param int|null $compra_en_dias    Arribo prometido AL COMPRAR (snapshot), en días relativos a «hoy»; null = igual que el vigente.
	 */
	private function evaluar( int $dias_desde_compra, ?int $promesa_en_dias, bool $ya_avisado = false, ?int $compra_en_dias = null ): WC_Order {
		$meta = array(
			MetaKeys::ORDER_HAS_PREVENTA  => 'yes',
			MetaKeys::ORDER_READY_TO_SHIP => 'no',
			MetaKeys::ORDER_PENDING_COUNT => 2,
		);
		if ( null !== $promesa_en_dias ) {
			$meta[ MetaKeys::ORDER_SUMMARY_MAX ] = self::AHORA + $promesa_en_dias * DAY_IN_SECONDS;
		}
		if ( $ya_avisado ) {
			$meta[ MetaKeys::ORDER_STALE_NOTED ] = 'yes';
		}

		$order                   = new WC_Order( $meta );
		$order->akb_date_created = ( new DateTimeImmutable( '@' . ( self::AHORA - $dias_desde_compra * DAY_IN_SECONDS ) ) );

		// La promesa de COMPRA (snapshot de la línea): es el origen del tope.
		// `$compra_en_dias` null = igual que la vigente; los tests que quieren
		// «sin snapshot» vacían `akb_items` después.
		$snapshot = $compra_en_dias ?? $promesa_en_dias;
		if ( null !== $snapshot ) {
			$item           = new WC_Order_Item_Product();
			$item->akb_id   = 1;
			$item->akb_meta = array(
				MetaKeys::ITEM_WAS_PREVENTA    => 'yes',
				MetaKeys::ITEM_MAX_AT_PURCHASE => (string) ( self::AHORA + $snapshot * DAY_IN_SECONDS ),
			);
			$order->akb_items = array( $item );
		}

		$cron   = new ReadyToShipCron( new SummaryRecalculator( new NullEtaProvider() ), static fn(): int => self::AHORA );
		$method = new ReflectionMethod( ReadyToShipCron::class, 'maybe_mark_stale' );
		$method->setAccessible( true );
		$method->invoke( $cron, $order );

		return $order;
	}

	/**
	 * @testdox Una preventa sana (llega dentro de 10 días) NO se acusa de retraso aunque lleve 45 días comprada.
	 */
	public function test_healthy_preorder_is_not_flagged_by_purchase_age(): void {
		$order = $this->evaluar( 45, +10 );

		self::assertSame( array(), $order->akb_notes, 'Medir desde la compra acusaba a preventas que van bien.' );
		self::assertSame( '', $order->get_meta( MetaKeys::ORDER_STALE_NOTED ) );
	}

	/**
	 * @testdox El #27443: prometido el 2-sep, sigue sin llegar 31 días después → nota anclada a la promesa.
	 */
	public function test_broken_promise_is_flagged_after_the_grace_period(): void {
		$order = $this->evaluar( 52, -31 );

		self::assertCount( 1, $order->akb_notes );
		self::assertStringContainsString( '31 días después de la fecha de llegada prometida', $order->akb_notes[0] );
		self::assertStringContainsString( '2 producto(s) sin llegar', $order->akb_notes[0] );
		self::assertSame( 'promesa', $order->get_meta( MetaKeys::ORDER_STALE_NOTED ), 'El flag guarda POR QUÉ se avisó.' );
	}

	/**
	 * @testdox Dentro de la gracia después de la promesa, todavía no.
	 */
	public function test_inside_the_grace_period_nothing_happens(): void {
		$order = $this->evaluar( 40, -5 );

		self::assertSame( array(), $order->akb_notes );
	}

	/**
	 * @testdox Si la editorial pospone en bucle, el tope desde la PRIMERA promesa avisa igual.
	 */
	public function test_moving_promise_cannot_silence_the_cap(): void {
		// Comprado hace 160 días prometiendo hace 130; la promesa vigente se ha
		// ido corriendo y hoy dice «dentro de 5 días».
		$order = $this->evaluar( 160, +5, false, -130 );

		self::assertCount( 1, $order->akb_notes );
		self::assertStringContainsString( '130 días después de la fecha prometida al comprar, aunque la estimación se haya ido corriendo', $order->akb_notes[0] );
	}

	/**
	 * @testdox Una preventa larga y sana (comprada 9 meses antes de la salida) NO es un atraso para el tope.
	 */
	public function test_long_healthy_preorder_is_not_flagged_by_the_cap(): void {
		// Comprado hace 200 días; la promesa de compra y la vigente son dentro de 70 días.
		$order = $this->evaluar( 200, +70, false, +70 );

		self::assertSame( array(), $order->akb_notes, 'El tope se mide desde la promesa de compra, no desde la compra.' );
	}

	/**
	 * @testdox Sin fecha (tba), la gracia se mide desde la compra.
	 */
	public function test_without_a_promise_the_grace_counts_from_purchase(): void {
		self::assertSame( array(), $this->evaluar( 29, null )->akb_notes );

		$order = $this->evaluar( 31, null );
		self::assertCount( 1, $order->akb_notes );
		self::assertStringContainsString( '31 días desde la compra sin fecha de llegada', $order->akb_notes[0] );
	}

	/**
	 * @testdox El estimado se recalcula cuando LLEGA un tomo, y no en cada pasada diaria.
	 */
	public function test_recompute_only_when_pending_changes(): void {
		$espia = new class( new NullEtaProvider() ) extends SummaryRecalculator {
			public int $llamadas = 0;
			public function recompute_for_order( WC_Order $order ): bool {
				++$this->llamadas;
				return false;
			}
		};
		Functions\when( 'apply_filters' )->alias( static fn( string $h, $v ) => $v );
		Functions\when( 'sanitize_key' )->returnArg();

		$item             = new WC_Order_Item_Product();
		$item->akb_id     = 1;
		$item->akb_meta   = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
		$item->akb_product = new WC_Product( 'onbackorder' );
		$order            = new WC_Order( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_PENDING_COUNT => 1 ) );
		$order->akb_status = 'processing';
		$order->akb_items  = array( $item );
		$order->akb_date_created = new DateTimeImmutable( '@' . self::AHORA );

		$cron = new ReadyToShipCron( $espia, static fn(): int => self::AHORA );

		$cron->process_order( $order ); // pasada diaria: sigue 1 pendiente
		self::assertSame( 0, $espia->llamadas, 'La 1.114.12 recalculaba en cada pasada y la promesa vencida se corría un día por día.' );

		$order->akb_items[] = ( static function (): WC_Order_Item_Product {
			$i = new WC_Order_Item_Product(); $i->akb_id = 2; $i->akb_meta = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' ); $i->akb_product = new WC_Product( 'onbackorder' ); return $i;
		} )();
		$order->update_meta_data( MetaKeys::ORDER_PENDING_COUNT, 3 ); // «ayer» había 3 pendientes: uno llegó
		$cron->process_order( $order );
		self::assertSame( 1, $espia->llamadas, 'Al llegar un tomo, el arribo deja de contarlo.' );
	}

	/**
	 * @testdox La nota se deja una sola vez.
	 */
	public function test_the_note_is_left_once(): void {
		$order = $this->evaluar( 52, -31, true );

		self::assertSame( array(), $order->akb_notes );
	}

	/**
	 * @testdox Una nota por tope NO tapa la del incumplimiento real de la promesa.
	 */
	public function test_a_cap_note_does_not_silence_the_real_one(): void {
		// Ya se avisó por tope (promesa que se iba corriendo); ahora la promesa
		// vigente lleva 31 días vencida.
		$order = $this->evaluar( 200, -31, false, -160 );
		$order->update_meta_data( MetaKeys::ORDER_STALE_NOTED, 'tope' );
		$order->akb_notes = array();
		$cron   = new ReadyToShipCron( new SummaryRecalculator( new NullEtaProvider() ), static fn(): int => self::AHORA );
		$method = new ReflectionMethod( ReadyToShipCron::class, 'maybe_mark_stale' );
		$method->setAccessible( true );
		$method->invoke( $cron, $order );

		self::assertCount( 1, $order->akb_notes );
		self::assertStringContainsString( 'después de la fecha de llegada prometida al cliente', $order->akb_notes[0] );
		self::assertSame( 'promesa', $order->get_meta( MetaKeys::ORDER_STALE_NOTED ) );

		// Y al revés no: con «promesa» ya avisada, el tope calla.
		$method->invoke( $cron, $order );
		self::assertCount( 1, $order->akb_notes );
	}

	/**
	 * @testdox Sin fecha al comprar, el tope no inventa una «fecha prometida al comprar».
	 */
	public function test_cap_without_purchase_promise_tells_the_truth(): void {
		// Vigente dentro de 5 días, pero la línea nunca tuvo snapshot (tba).
		$order = $this->evaluar( 130, +5, false, null );
		$order->akb_items = array();
		$order->akb_notes = array();
		$cron   = new ReadyToShipCron( new SummaryRecalculator( new NullEtaProvider() ), static fn(): int => self::AHORA );
		$method = new ReflectionMethod( ReadyToShipCron::class, 'maybe_mark_stale' );
		$method->setAccessible( true );
		$method->invoke( $cron, $order );

		self::assertCount( 1, $order->akb_notes );
		self::assertStringContainsString( '130 días desde la compra (el tomo no tenía fecha al comprar)', $order->akb_notes[0] );
		self::assertStringNotContainsString( 'prometida al comprar', $order->akb_notes[0] );
	}

	/**
	 * @testdox La gracia y el tope son los que el dueño configura.
	 */
	public function test_thresholds_are_the_configured_ones(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akb_preventa_stale_pending_days' => 7,
			'akb_preventa_stale_max_age_days' => 60,
		);

		self::assertCount( 1, $this->evaluar( 20, -8 )->akb_notes, 'gracia de 7 días' );
		self::assertCount( 1, $this->evaluar( 90, +30, false, -61 )->akb_notes, 'tope de 60 días desde la promesa de compra' );
		self::assertCount( 0, $this->evaluar( 90, +30, false, -59 )->akb_notes );
	}
}
