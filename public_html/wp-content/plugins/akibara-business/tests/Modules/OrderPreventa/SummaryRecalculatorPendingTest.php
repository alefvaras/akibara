<?php
/**
 * El estimado de arribo se calcula sólo sobre lo que sigue SIN LLEGAR.
 *
 * Hasta 1.114.11 contaba todas las líneas compradas en espera: las que ya
 * estaban en bodega y las reembolsadas seguían gobernando la fecha, así que
 * el cliente veía un arribo POSTERIOR a la verdad, y el tomo que se le devolvió
 * seguía decidiendo su estimado.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\PreventaItems;

use Akibara\Business\Tests\Support\NullEtaProvider;

use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;
use Akibara\Business\Modules\Stock\Availability;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

final class SummaryRecalculatorPendingTest extends TestCase {

	private const SEPTIEMBRE = 1789000000;
	private const OCTUBRE    = 1791000000;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_date' )->alias( static fn( string $f, int $ts ): string => gmdate( $f, $ts ) );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );
		// `Availability::is_preventa()` lee la fila por get_post_meta (canal de
		// performance): el doble sirve lo que cada prueba deje en el global.
		$GLOBALS['akibara_test_postmeta'] = array();
		Functions\when( 'get_post_meta' )->alias(
			static fn( int $id, string $key = '', bool $single = false ) => $GLOBALS['akibara_test_postmeta'][ $id ][ $key ] ?? ''
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_postmeta'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function linea( int $id, int $max_at_purchase, string $stock, string $tipo = Availability::TYPE_AUTO ): WC_Order_Item_Product {
		$item               = new WC_Order_Item_Product();
		$item->akb_id       = $id;
		$item->akb_meta     = array(
			MetaKeys::ITEM_WAS_PREVENTA     => 'yes',
			MetaKeys::ITEM_MAX_AT_PURCHASE  => (string) $max_at_purchase,
		);
		$producto           = new WC_Product( $stock, array( DateCalculator::META_TYPE => $tipo ) );
		$producto->akb_id   = 1000 + $id;
		$item->akb_product  = $producto;
		return $item;
	}

	/**
	 * @testdox El tomo que ya llegó deja de gobernar la fecha.
	 */
	public function test_arrived_line_no_longer_drives_the_estimate(): void {
		$order            = new WC_Order();
		$order->akb_items = array(
			$this->linea( 1, self::OCTUBRE, 'instock' ),      // ya en bodega
			$this->linea( 2, self::SEPTIEMBRE, 'onbackorder' ), // sigue pendiente
		);

		self::assertSame( self::SEPTIEMBRE, ( new SummaryRecalculator( new NullEtaProvider() ) )->compute_max_ts( $order ) );
	}

	/**
	 * @testdox El tomo reembolsado deja de gobernar la fecha.
	 */
	public function test_refunded_line_no_longer_drives_the_estimate(): void {
		$order               = new WC_Order();
		$order->akb_items    = array(
			$this->linea( 1, self::OCTUBRE, 'onbackorder' ),
			$this->linea( 2, self::SEPTIEMBRE, 'onbackorder' ),
		);
		$order->akb_refunded = array( 1 => 1 );

		self::assertSame( self::SEPTIEMBRE, ( new SummaryRecalculator( new NullEtaProvider() ) )->compute_max_ts( $order ) );
	}

	/**
	 * @testdox Cuando TODO llegó no se borra la fecha: el cron marcará el pedido listo.
	 */
	public function test_when_everything_arrived_the_date_is_kept_for_the_cron(): void {
		$order            = new WC_Order( array( MetaKeys::ORDER_SUMMARY_MAX => (string) self::OCTUBRE ) );
		$order->akb_items = array( $this->linea( 1, self::OCTUBRE, 'instock' ) );

		$cambio = ( new SummaryRecalculator( new NullEtaProvider() ) )->recompute_for_order( $order );

		self::assertFalse( $cambio );
		self::assertSame( (string) self::OCTUBRE, $order->get_meta( MetaKeys::ORDER_SUMMARY_MAX ), 'Borrarla dejaría el cartel sin fecha justo antes de que el cron lo marque listo.' );
	}

	/**
	 * @testdox Reembolsado todo, la fecha sí se borra (no queda nada vigente).
	 */
	public function test_when_everything_is_refunded_the_date_goes_away(): void {
		$order               = new WC_Order( array( MetaKeys::ORDER_SUMMARY_MAX => (string) self::OCTUBRE ) );
		$order->akb_items    = array( $this->linea( 1, self::OCTUBRE, 'onbackorder' ) );
		$order->akb_refunded = array( 1 => 1 );

		self::assertTrue( ( new SummaryRecalculator( new NullEtaProvider() ) )->recompute_for_order( $order ) );
		self::assertSame( '', $order->get_meta( MetaKeys::ORDER_SUMMARY_MAX ) );
	}

	/**
	 * @testdox Una preventa cuya fecha de salida ya pasó conserva la última promesa (vencida), no la corre a hoy.
	 */
	public function test_a_released_but_missing_tomo_keeps_the_last_promise(): void {
		// Tipo release_date con la fecha vencida: Availability ya no lo considera
		// preventa (get_post_meta sin fila → no pendiente de lanzamiento).
		$order            = new WC_Order( array( MetaKeys::ORDER_SUMMARY_MAX => (string) self::OCTUBRE ) );
		$linea            = $this->linea( 1, self::SEPTIEMBRE, 'onbackorder', Availability::TYPE_RELEASE_DATE );
		$linea->akb_meta[ MetaKeys::ITEM_MAX_CURRENT ] = (string) self::OCTUBRE; // lo último que ESTA línea prometió
		$order->akb_items = array( $linea );
		$GLOBALS['akibara_test_postmeta'][1001] = array( '_akb_eta_type' => Availability::TYPE_RELEASE_DATE, '_akb_eta_release_date' => '2026-08-01' ); // ya pasó

		self::assertSame( self::OCTUBRE, ( new SummaryRecalculator( new NullEtaProvider() ) )->compute_max_ts( $order ), 'La última promesa recibida se queda vencida, para que el aviso «revisar» la vea.' );
		self::assertFalse( ( new SummaryRecalculator( new NullEtaProvider() ) )->recompute_for_order( $order ) );
		self::assertSame( array(), $order->akb_notes, 'Ni una nota por día.' );
	}

	/**
	 * @testdox Una línea vencida NO hereda la promesa de OTRO tomo: si ese otro se reembolsa, el estimado baja.
	 */
	public function test_an_expired_line_keeps_its_own_promise_not_the_orders(): void {
		$a = $this->linea( 1, self::SEPTIEMBRE, 'onbackorder', Availability::TYPE_RELEASE_DATE ); // vencida, nunca recalculada
		$b = $this->linea( 2, self::OCTUBRE, 'onbackorder' );                                        // a pedido, fijaba el máximo
		$GLOBALS['akibara_test_postmeta'][1001] = array( '_akb_eta_type' => Availability::TYPE_RELEASE_DATE, '_akb_eta_release_date' => '2026-08-01' );
		$order               = new WC_Order( array( MetaKeys::ORDER_SUMMARY_MAX => (string) self::OCTUBRE ) );
		$order->akb_items    = array( $a, $b );
		$order->akb_refunded = array( 2 => 1 ); // B devuelta

		self::assertSame( self::SEPTIEMBRE, ( new SummaryRecalculator( new NullEtaProvider() ) )->compute_max_ts( $order ), 'Con la promesa de la ORDEN heredada, el estimado nunca podía bajar.' );
	}

	/**
	 * @testdox Al retirar la fecha, la línea anota «sin fecha vigente» y no vuelve a la promesa de compra.
	 */
	public function test_a_retracted_date_is_remembered_as_no_date(): void {
		$linea = $this->linea( 1, self::SEPTIEMBRE, 'onbackorder', Availability::TYPE_TBA );
		$linea->akb_meta[ MetaKeys::ITEM_MAX_CURRENT ] = (string) self::OCTUBRE; // lo que prometía antes de la retractación
		$order            = new WC_Order( array( MetaKeys::ORDER_SUMMARY_MAX => (string) self::OCTUBRE ) );
		$order->akb_items = array( $linea );

		self::assertSame( 0, ( new SummaryRecalculator( new NullEtaProvider() ) )->compute_max_ts( $order ) );
		self::assertSame( '0', (string) $linea->get_meta( MetaKeys::ITEM_MAX_CURRENT ), 'Sin la marca, la línea del pedido caía a la promesa de compra.' );
		self::assertSame( array( 'min' => null, 'max' => null ), PreventaItems::promised_window( $linea ) );
	}

	/**
	 * @testdox Si el tomo vuelve a «a pedido» (auto), la línea olvida la marca y lee lo mismo que el cartel.
	 */
	public function test_back_to_auto_forgets_the_current_window(): void {
		$linea = $this->linea( 1, self::SEPTIEMBRE, 'onbackorder', Availability::TYPE_AUTO );
		$linea->akb_meta[ MetaKeys::ITEM_MIN_CURRENT ] = (string) ( self::OCTUBRE - 5 * 86400 );
		$linea->akb_meta[ MetaKeys::ITEM_MAX_CURRENT ] = (string) self::OCTUBRE; // postergación anterior
		$order            = new WC_Order();
		$order->akb_items = array( $linea );

		self::assertSame( self::SEPTIEMBRE, ( new SummaryRecalculator( new NullEtaProvider() ) )->compute_max_ts( $order ), 'A pedido = el snapshot de compra (anti-inflado).' );
		self::assertSame( self::SEPTIEMBRE, PreventaItems::promised_window( $linea )['max'], 'La línea decía «Llega entre el 25 y el 30 de junio» (marca) bajo un cartel con el 5 de junio (snapshot).' );
	}

	/**
	 * @testdox Al calcular en vivo, la línea recuerda su promesa (min/max vigentes).
	 */
	public function test_a_live_calculation_is_remembered_on_the_line(): void {
		$vivo = new class() extends NullEtaProvider {
			public function calculate( WC_Product $product ): ?EtaResult {
				return EtaResult::range( 1795000000, 1796000000 );
			}
		};
		$linea = $this->linea( 1, self::SEPTIEMBRE, 'onbackorder', Availability::TYPE_RELEASE_DATE );
		$GLOBALS['akibara_test_postmeta'][1001] = array( '_akb_eta_type' => Availability::TYPE_RELEASE_DATE, '_akb_eta_release_date' => '2099-01-01' ); // futura
		$order            = new WC_Order();
		$order->akb_items = array( $linea );

		self::assertSame( 1796000000, ( new SummaryRecalculator( $vivo ) )->compute_max_ts( $order ) );
		self::assertSame( 1796000000, (int) $linea->get_meta( MetaKeys::ITEM_MAX_CURRENT ), 'Es lo que la línea le enseña al cliente y lo que conserva si la fecha vence.' );
		self::assertSame( 1795000000, (int) $linea->get_meta( MetaKeys::ITEM_MIN_CURRENT ) );
	}

	/**
	 * @testdox Un pedido que NACE sin fecha y la recibe también re-arma el aviso «revisar».
	 */
	public function test_a_promise_that_is_born_rearms_the_stale_note(): void {
		$order            = new WC_Order( array( MetaKeys::ORDER_STALE_NOTED => 'yes' ) );
		$order->akb_items = array( $this->linea( 1, self::SEPTIEMBRE, 'onbackorder' ) );

		self::assertTrue( ( new SummaryRecalculator( new NullEtaProvider() ) )->recompute_for_order( $order ) );
		self::assertSame( '', $order->get_meta( MetaKeys::ORDER_STALE_NOTED ) );
	}

	/**
	 * @testdox Cuando la promesa cambia, el aviso interno «revisar» se vuelve a armar.
	 */
	public function test_a_changed_promise_rearms_the_stale_note(): void {
		$order            = new WC_Order(
			array(
				MetaKeys::ORDER_SUMMARY_MAX => (string) self::OCTUBRE,
				MetaKeys::ORDER_STALE_NOTED => 'yes',
			)
		);
		$order->akb_items = array( $this->linea( 1, self::SEPTIEMBRE, 'onbackorder' ) );

		self::assertTrue( ( new SummaryRecalculator( new NullEtaProvider() ) )->recompute_for_order( $order ) );
		self::assertSame( '', $order->get_meta( MetaKeys::ORDER_STALE_NOTED ), 'Un segundo retraso no puede quedar mudo por haber avisado del primero.' );
		self::assertCount( 1, $order->akb_notes );
		self::assertStringContainsString( 'Estimado de arribo recalculado', $order->akb_notes[0] );
	}
}
