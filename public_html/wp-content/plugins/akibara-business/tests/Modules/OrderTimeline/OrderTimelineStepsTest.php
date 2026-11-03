<?php
/**
 * Contrato del timeline del pedido: la misma página no puede contradecirse.
 *
 * El módulo no tenía ni una prueba, y tenía dos contradicciones visibles para el
 * cliente (medidas el 2026-08-23 sobre el HTML servido):
 *
 *   1. Con la mercadería YA llegada (`_akb_eta_ready_to_ship = yes`, que es lo
 *      que marca el cron `akb_order_preventa_recompute_ready`), el cartel decía
 *      «listo para despachar» mientras el timeline seguía con «En preventa» como
 *      paso actual y la pista «Llega ~octubre». El cron no borra el estimado a
 *      propósito: quien tiene que dejar de mostrarlo es esta vista.
 *
 *   2. En `shipping-progress`, WooCommerce le dice al cliente «está actualmente
 *      Listo para enviar» y el timeline marcaba «Despachado» — dos nombres para
 *      un mismo momento, y el más avanzado era el que no correspondía.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderTimeline;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderTimeline\OrderTimeline;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Akibara\Business\Modules\OrderPreventa\PreventaItems;
use Akibara\Business\Modules\Stock\Availability;
use ReflectionMethod;
use WC_Order_Item_Product;

/**
 * @since 1.108.18
 */
final class OrderTimelineStepsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['akibara_test_options'] = array();
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( 'wc_format_datetime' )->justReturn( '23 de Ago' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $format, ?int $ts = null, $tz = null ): string => gmdate( $format, (int) $ts ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Una línea comprada en espera, con el snapshot que decide la palabra.
	 *
	 * @param string $tipo `auto` (a pedido) | `release_date` | `tba` (preventa).
	 */
	private function linea( int $id, string $tipo ): WC_Order_Item_Product {
		$item           = new WC_Order_Item_Product();
		$item->akb_id   = $id;
		$item->akb_meta = array(
			MetaKeys::ITEM_WAS_PREVENTA      => 'yes',
			MetaKeys::ITEM_TYPE_AT_PURCHASE  => $tipo,
		);
		return $item;
	}

	/**
	 * @param array<string,string>              $meta     Meta del pedido.
	 * @param array<int, WC_Order_Item_Product> $items    Líneas del pedido.
	 * @param array<int, int>                   $refunded Reembolsado por id de línea.
	 * @return array<int, array<string,mixed>>
	 */
	private function steps( string $status, array $meta, bool $isPreventa, array $items = array(), array $refunded = array() ): array {
		$order               = new \WC_Order();
		$order->akb_status   = $status;
		$order->akb_meta     = $meta;
		$order->akb_items    = $items;
		$order->akb_refunded = $refunded;

		$method = new ReflectionMethod( OrderTimeline::class, 'build_steps' );
		$method->setAccessible( true );

		return (array) $method->invoke( new OrderTimeline(), $order, $isPreventa );
	}

	/** Meta de un pedido que espera, sin marcar listo. */
	private const ESPERANDO = array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' );

	/** Meta de un pedido que esperaba y ya tiene todo en bodega. */
	private const LISTO = array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'yes' );

	/**
	 * @param array<int, array<string,mixed>> $steps
	 * @return array<string,mixed>|null
	 */
	private function step( array $steps, string $label ): ?array {
		foreach ( $steps as $s ) {
			if ( $label === ( $s['label'] ?? '' ) ) {
				return $s;
			}
		}

		return null;
	}

	/**
	 * @testdox Con la preventa aún pendiente, el paso es el actual y muestra la fecha estimada.
	 */
	public function test_pending_preorder_is_current_and_keeps_its_eta(): void {
		$steps = $this->steps( 'processing', self::ESPERANDO, true, array( $this->linea( 1, Availability::TYPE_RELEASE_DATE ) ) );

		$preventa = $this->step( $steps, 'Preventa' );
		self::assertNotNull( $preventa );
		self::assertSame( 'current', $preventa['state'] );
	}

	/**
	 * @testdox Una promesa vencida se dice en pasado y como atraso, no como previsión.
	 */
	public function test_an_overdue_promise_is_not_a_forecast(): void {
		$vencida = self::ESPERANDO + array( MetaKeys::ORDER_SUMMARY_MAX => (string) ( time() - 10 * 86400 ) );
		$steps   = $this->steps( 'processing', $vencida, true, array( $this->linea( 1, Availability::TYPE_RELEASE_DATE ) ) );

		$hint = (string) $this->step( $steps, 'Preventa' )['hint'];
		self::assertStringStartsWith( 'Llegaba ~', $hint, 'El rastreador decía «Llega ~agosto» en septiembre mientras el cartel ya admitía el atraso.' );
		self::assertStringContainsString( 'atrasado', $hint );
	}

	/**
	 * @testdox Con todo en bodega y el cron aún sin pasar, la etapa se cierra igual.
	 */
	public function test_everything_arrived_before_the_cron_closes_the_step(): void {
		$linea              = $this->linea( 1, Availability::TYPE_RELEASE_DATE );
		$linea->akb_product = new \WC_Product( 'instock' );
		$steps              = $this->steps( 'processing', self::ESPERANDO, true, array( $linea ) );

		$preventa = $this->step( $steps, 'Preventa' );
		self::assertSame( 'done', $preventa['state'], 'La línea decía «Ya llegó» y el rastreador seguía «En preventa».' );
		self::assertNull( $preventa['hint'] );
	}

	/**
	 * @testdox Cuando la mercadería llegó, «En preventa» se cierra y DEJA de mostrar la fecha.
	 */
	public function test_ready_preorder_closes_the_step_and_drops_the_date(): void {
		$steps = $this->steps( 'processing', self::LISTO, true, array( $this->linea( 1, Availability::TYPE_RELEASE_DATE ) ) );

		$preventa = $this->step( $steps, 'Preventa' );
		self::assertNotNull( $preventa );
		self::assertSame( 'done', $preventa['state'], 'El cartel ya dice «listo para despachar»: el timeline no puede seguir esperando.' );
		self::assertNull( $preventa['hint'], 'Mostrar «Llega ~octubre» junto a «listo para despachar» es la contradicción original.' );

		$preparando = $this->step( $steps, 'Preparando despacho' );
		self::assertNotNull( $preparando );
		self::assertSame( 'current', $preparando['state'], 'El paso actual pasa a ser el siguiente, no uno que ya terminó.' );
	}

	/**
	 * @testdox En «Listo para enviar» el timeline usa ESE nombre, no «Despachado».
	 */
	public function test_final_step_matches_the_status_label_the_customer_reads(): void {
		$steps = $this->steps( 'shipping-progress', array(), false );
		$final = $steps[ count( $steps ) - 1 ];

		self::assertSame( 'Listo para enviar', $final['label'], 'WooCommerce le muestra ese mismo nombre en la cabecera del pedido.' );
		self::assertSame( 'current', $final['state'] );
	}

	/**
	 * @testdox Un pedido completado sí dice «Despachado».
	 */
	public function test_completed_order_still_says_despachado(): void {
		$steps = $this->steps( 'completed', array(), false );
		$final = $steps[ count( $steps ) - 1 ];

		self::assertSame( 'Despachado', $final['label'] );
		self::assertSame( 'done', $final['state'] );
	}

	/**
	 * @testdox Nunca hay dos pasos marcados como actuales a la vez.
	 *
	 * @dataProvider escenarios
	 *
	 * @param array<string,string> $meta
	 */
	public function test_never_two_current_steps( string $status, array $meta, bool $isPreventa ): void {
		$steps   = $this->steps( $status, $meta, $isPreventa );
		$actuales = array_filter( $steps, static fn( array $s ): bool => 'current' === ( $s['state'] ?? '' ) );

		self::assertLessThanOrEqual( 1, count( $actuales ), 'Dos pasos «actuales» dejan al cliente sin saber dónde está su pedido.' );
	}

	/** @return array<string, array{0:string, 1:array<string,string>, 2:bool}> */
	public static function escenarios(): array {
		return array(
			'preventa pendiente' => array( 'processing', self::ESPERANDO, true ),
			'preventa lista'     => array( 'processing', self::LISTO, true ),
			'sin preventa'       => array( 'processing', array(), false ),
			'listo para enviar'  => array( 'shipping-progress', array(), false ),
			'completado'         => array( 'completed', array(), false ),
			'esperando pago'     => array( 'pending', array(), false ),
		);
	}

	// ───────────────────────────────────────────── la palabra del nodo

	/**
	 * @testdox Un pedido a pedido NO dice «En preventa»: dice lo que compró.
	 */
	public function test_a_pedido_order_says_a_pedido_not_preventa(): void {
		$steps = $this->steps( 'processing', self::ESPERANDO, true, array( $this->linea( 1, Availability::TYPE_AUTO ) ) );

		self::assertSame( 'A pedido', $steps[1]['label'] ?? null, 'Las 20 líneas en espera de la tienda (2026-09-02) eran a pedido y el rastreador les decía «En preventa».' );
		self::assertSame( 'current', $steps[1]['state'] );
	}

	/**
	 * @testdox Una preventa de verdad sigue diciendo «Preventa».
	 */
	public function test_real_preventa_keeps_its_word(): void {
		$steps = $this->steps( 'processing', self::ESPERANDO, true, array( $this->linea( 1, Availability::TYPE_TBA ) ) );

		self::assertSame( 'Preventa', $steps[1]['label'] ?? null );
	}

	/**
	 * @testdox La palabra es la que el dueño edita en Akibara → Textos.
	 */
	public function test_the_word_is_the_editable_copy(): void {
		$GLOBALS['akibara_test_options']['akb_copy_eta_single_pedido'] = 'Por encargo';

		$steps = $this->steps( 'processing', self::ESPERANDO, true, array( $this->linea( 1, Availability::TYPE_AUTO ) ) );

		self::assertSame( 'Por encargo', $steps[1]['label'] ?? null );
	}

	/**
	 * @testdox Si la única reserva se reembolsó, el nodo desaparece (la bandera del pedido nunca se apaga).
	 */
	public function test_refunded_reservation_leaves_no_step(): void {
		$steps = $this->steps( 'shipping-progress', self::ESPERANDO, true, array( $this->linea( 7, Availability::TYPE_RELEASE_DATE ) ), array( 7 => 1 ) );

		self::assertCount( 3, $steps, 'Réplica de #27760 y #27782: reserva devuelta, pedido en marcha.' );
		self::assertSame( 'Preparando despacho', $steps[1]['label'] ?? null );
		self::assertNull( $this->step( $steps, 'Preventa' ) );
		self::assertNull( $this->step( $steps, 'A pedido' ) );
	}
}
