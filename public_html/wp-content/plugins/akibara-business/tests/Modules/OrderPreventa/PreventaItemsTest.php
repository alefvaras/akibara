<?php
/**
 * `PreventaItems` es la única dueña de «¿qué está esperando este cliente?».
 *
 * Estas pruebas fijan las cinco respuestas que hasta 1.114.11 cada superficie
 * daba por su cuenta: cuánto queda tras un reembolso, si el tomo ya llegó, si
 * la línea se compró como preventa o a pedido, y la palabra para la línea y
 * para el pedido — leída SIEMPRE de las options que el dueño edita.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\PreventaItems;
use Akibara\Business\Modules\Stock\Availability;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

final class PreventaItemsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['akibara_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		parent::tearDown();
	}

	/**
	 * @param array<string,string> $meta     Snapshot de la línea.
	 * @param string|null          $stock    Stock del producto de hoy; null = producto borrado.
	 * @param int                  $qty      Unidades compradas.
	 */
	private function linea( int $id, array $meta, ?string $stock = 'onbackorder', int $qty = 1, string $status = 'publish' ): WC_Order_Item_Product {
		$item               = new WC_Order_Item_Product();
		$item->akb_id       = $id;
		$item->akb_quantity = $qty;
		$item->akb_meta     = array_merge( array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' ), $meta );
		if ( null !== $stock ) {
			$producto             = new WC_Product( $stock );
			$producto->akb_id     = 1000 + $id;
			$producto->akb_status = $status;
			$item->akb_product    = $producto;
		}
		return $item;
	}

	/**
	 * @param array<int, WC_Order_Item_Product> $items    Líneas.
	 * @param array<int, int>                   $refunded Reembolsado por id de línea.
	 * @param array<string, string>             $meta     Meta del pedido.
	 */
	private function pedido( array $items, array $refunded = array(), array $meta = array() ): WC_Order {
		$order               = new WC_Order( $meta );
		$order->akb_items    = $items;
		$order->akb_refunded = $refunded;
		return $order;
	}

	// ───────────────────────────────────────────── cuánto queda

	public function test_la_cantidad_vigente_descuenta_el_reembolso(): void {
		$item  = $this->linea( 1, array(), 'onbackorder', 3 );
		$order = $this->pedido( array( $item ), array( 1 => 1 ) );

		self::assertSame( 2, PreventaItems::outstanding_qty( $order, $item ) );
		self::assertTrue( PreventaItems::is_outstanding_item( $order, $item ) );
	}

	public function test_una_linea_devuelta_del_todo_deja_de_estar_vigente(): void {
		$item  = $this->linea( 1, array(), 'onbackorder', 2 );
		$order = $this->pedido( array( $item ), array( 1 => 2 ) );

		self::assertSame( 0, PreventaItems::outstanding_qty( $order, $item ) );
		self::assertFalse( PreventaItems::is_outstanding_item( $order, $item ) );
		self::assertSame( array(), PreventaItems::outstanding_items( $order ) );
	}

	// ───────────────────────────────────────────── qué sigue sin llegar

	public function test_un_tomo_en_stock_ya_llego_y_deja_de_estar_pendiente(): void {
		$llego  = $this->linea( 1, array( MetaKeys::ITEM_WAS_PREVENTA => 'no' ), 'instock' );
		$espera = $this->linea( 2, array(), 'onbackorder' );
		$order  = $this->pedido( array( $llego, $espera ) );

		self::assertTrue( PreventaItems::has_arrived( $order, $llego ) );
		self::assertFalse( PreventaItems::is_pending_item( $order, $llego ) );
		self::assertTrue( PreventaItems::is_pending_item( $order, $espera ) );
		self::assertSame( array( $espera ), PreventaItems::pending_items( $order ) );
	}

	/**
	 * La marca del pedido manda sobre el stock de hoy: el #25538 de producción
	 * la tiene en `yes` con su producto de vuelta en `outofstock`.
	 */
	public function test_la_marca_listo_del_pedido_manda_sobre_el_stock_de_hoy(): void {
		$item  = $this->linea( 1, array(), 'outofstock' );
		$order = $this->pedido( array( $item ), array(), array( MetaKeys::ORDER_READY_TO_SHIP => 'yes' ) );

		self::assertTrue( PreventaItems::has_arrived( $order, $item ) );
		self::assertSame( array(), PreventaItems::pending_items( $order ) );
	}

	/**
	 * @dataProvider tomosQueNoHanLlegado
	 */
	public function test_un_tomo_borrado_o_en_la_papelera_sigue_pendiente( ?string $stock, string $status ): void {
		$item  = $this->linea( 1, array(), $stock, 1, $status );
		$order = $this->pedido( array( $item ) );

		self::assertFalse( PreventaItems::has_arrived( $order, $item ) );
		self::assertTrue( PreventaItems::is_pending_item( $order, $item ), 'El dueño tiene que resolverlo a mano; mientras tanto el pedido cuenta como pendiente.' );
	}

	/** @return array<string, array{0:?string,1:string}> */
	public static function tomosQueNoHanLlegado(): array {
		return array(
			'producto borrado'             => array( null, 'publish' ),
			'en la papelera, aún instock' => array( 'instock', 'trash' ),
		);
	}

	// ───────────────────────────────────────────── la palabra

	/**
	 * @dataProvider snapshots
	 */
	public function test_la_palabra_sigue_a_como_se_compro_la_linea( string $tipo, bool $esperado ): void {
		$item = $this->linea( 1, array( MetaKeys::ITEM_TYPE_AT_PURCHASE => $tipo ), 'instock' );

		self::assertSame( $esperado, PreventaItems::era_preventa( $item ), 'El tomo ya está `instock`: lo que decide es el snapshot, no el estado de hoy.' );
	}

	/** @return array<string, array{0:string,1:bool}> */
	public static function snapshots(): array {
		return array(
			'a pedido (auto)'          => array( Availability::TYPE_AUTO, false ),
			'preventa con fecha'       => array( Availability::TYPE_RELEASE_DATE, true ),
			'preventa sin fecha (tba)' => array( Availability::TYPE_TBA, true ),
		);
	}

	public function test_sin_snapshot_la_linea_es_a_pedido_aunque_el_producto_sea_preventa_hoy(): void {
		$producto = new WC_Product( 'onbackorder', array( '_akb_eta_type' => Availability::TYPE_RELEASE_DATE ) );
		$item     = $this->linea( 1, array(), 'onbackorder' );
		$item->akb_product = $producto;

		self::assertFalse( PreventaItems::era_preventa( $item ), 'Los pedidos anteriores al snapshot eran a pedido; el estado de hoy no reescribe lo que se compró.' );
	}

	public function test_el_sustantivo_del_cartel_tambien_se_edita(): void {
		$GLOBALS['akibara_test_options'] = array( 'akb_copy_eta_noun_pedido' => 'tomos por encargo' );
		$order = $this->pedido( array( $this->linea( 1, array( MetaKeys::ITEM_TYPE_AT_PURCHASE => Availability::TYPE_AUTO ) ) ) );

		self::assertSame( 'tomos por encargo', PreventaItems::order_noun( $order ) );
	}

	/**
	 * Una fila VACÍA (existe pero es '') no es lo mismo que una fila ausente:
	 * `get_option($id, $default)` sólo aplica el default cuando la fila NO
	 * EXISTE, así que una escritura directa a `wp_options` —`wp option
	 * update`, un import, cualquier vía que no pase por el formulario del
	 * panel, donde corre `sanitize_required_copy()`— dejaba `order_noun()`
	 * devolviendo la cadena vacía. Un correo o cartel de pedido decía
	 * literalmente «Tu pedido incluye .» a un cliente real (2026-09-19,
	 * R2-2f374da1, reabre S-6492daca por la puerta que su arreglo no cerró).
	 *
	 * @testdox Una option vaciada fuera del panel cae al literal de fábrica, no a la cadena vacía.
	 */
	public function test_el_sustantivo_vaciado_fuera_del_panel_cae_al_literal_de_fabrica(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akb_copy_eta_noun_preventa' => '',
			'akb_copy_eta_noun_pedido'   => '',
		);

		$a_pedido = $this->pedido( array( $this->linea( 1, array( MetaKeys::ITEM_TYPE_AT_PURCHASE => Availability::TYPE_AUTO ) ) ) );
		$preventa = $this->pedido( array( $this->linea( 1, array( MetaKeys::ITEM_TYPE_AT_PURCHASE => Availability::TYPE_RELEASE_DATE ) ) ) );

		self::assertSame( 'productos a pedido', PreventaItems::order_noun( $a_pedido ) );
		self::assertSame( 'preventa', PreventaItems::order_noun( $preventa ) );
	}

	public function test_las_etiquetas_salen_de_las_options_que_edita_el_dueno(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akb_copy_eta_single_pedido'   => 'Por encargo',
			'akb_copy_eta_single_preventa' => 'Reserva anticipada',
		);
		$pedido   = $this->linea( 1, array( MetaKeys::ITEM_TYPE_AT_PURCHASE => Availability::TYPE_AUTO ) );
		$preventa = $this->linea( 2, array( MetaKeys::ITEM_TYPE_AT_PURCHASE => Availability::TYPE_RELEASE_DATE ) );

		self::assertSame( 'Por encargo', PreventaItems::item_label( $pedido ) );
		self::assertSame( 'Reserva anticipada', PreventaItems::item_label( $preventa ) );

		$solo_pedido = $this->pedido( array( $pedido ) );
		$mixto       = $this->pedido( array( $pedido, $preventa ) );
		self::assertSame( 'Por encargo', PreventaItems::order_label( $solo_pedido ) );
		self::assertSame( 'Reserva anticipada', PreventaItems::order_label( $mixto ), 'Basta una preventa de verdad entre lo que falta para que el pedido lo sea.' );
		self::assertFalse( PreventaItems::has_real_preventa( $solo_pedido ) );
		self::assertTrue( PreventaItems::has_real_preventa( $mixto ) );
	}

	/**
	 * Una preventa reembolsada del todo ya no cuenta para la palabra del pedido:
	 * lo que queda es a pedido.
	 */
	public function test_la_preventa_devuelta_no_decide_la_palabra_del_pedido(): void {
		$pedido   = $this->linea( 1, array( MetaKeys::ITEM_TYPE_AT_PURCHASE => Availability::TYPE_AUTO ) );
		$preventa = $this->linea( 2, array( MetaKeys::ITEM_TYPE_AT_PURCHASE => Availability::TYPE_RELEASE_DATE ) );
		$order    = $this->pedido( array( $pedido, $preventa ), array( 2 => 1 ) );

		self::assertFalse( PreventaItems::has_real_preventa( $order ) );
		self::assertSame( 'productos a pedido', PreventaItems::order_noun( $order ) );
	}

	// ───────────────────────────────────────────── la ventana que rige hoy

	/**
	 * @testdox promised_window: nunca recalculada → la de compra; recalculada → la vigente; retirada (0) → ninguna.
	 */
	public function test_promised_window_has_three_states(): void {
		$compra = new WC_Order_Item_Product();
		$compra->akb_meta = array( MetaKeys::ITEM_MIN_AT_PURCHASE => '100', MetaKeys::ITEM_MAX_AT_PURCHASE => '200' );
		self::assertSame( array( 'min' => 100, 'max' => 200 ), PreventaItems::promised_window( $compra ) );

		$vigente = new WC_Order_Item_Product();
		$vigente->akb_meta = $compra->akb_meta + array( MetaKeys::ITEM_MIN_CURRENT => '300', MetaKeys::ITEM_MAX_CURRENT => '400' );
		self::assertSame( array( 'min' => 300, 'max' => 400 ), PreventaItems::promised_window( $vigente ) );

		$retirada = new WC_Order_Item_Product();
		$retirada->akb_meta = $compra->akb_meta + array( MetaKeys::ITEM_MIN_CURRENT => '0', MetaKeys::ITEM_MAX_CURRENT => '0' );
		self::assertSame( array( 'min' => null, 'max' => null ), PreventaItems::promised_window( $retirada ), 'Retirada NO es «nunca recalculada»: no se vuelve a la de compra.' );

		$sin_nada = new WC_Order_Item_Product();
		self::assertSame( array( 'min' => null, 'max' => null ), PreventaItems::promised_window( $sin_nada ) );
	}

	/**
	 * @testdox all_arrived: todo en bodega aunque el pedido no esté marcado; nada que esperar no cuenta.
	 */
	public function test_all_arrived_looks_at_the_stock_not_the_flag(): void {
		$en_bodega              = new WC_Order_Item_Product();
		$en_bodega->akb_id      = 1;
		$en_bodega->akb_meta    = array(
			MetaKeys::ITEM_WAS_PREVENTA => 'yes',
			MetaKeys::ITEM_ARRIVED      => 'yes',
			MetaKeys::ITEM_ARRIVED_QTY  => 1,
		);
		$en_bodega->akb_product = new WC_Product( 'instock' );

		$pendiente              = new WC_Order_Item_Product();
		$pendiente->akb_id      = 2;
		$pendiente->akb_meta    = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
		$pendiente->akb_product = new WC_Product( 'onbackorder' );

		$todo             = new WC_Order( array( MetaKeys::ORDER_READY_TO_SHIP => 'no' ) );
		$todo->akb_items  = array( $en_bodega );
		self::assertTrue( PreventaItems::all_arrived( $todo ) );

		$mixto            = new WC_Order( array( MetaKeys::ORDER_READY_TO_SHIP => 'no' ) );
		$mixto->akb_items = array( $en_bodega, $pendiente );
		self::assertFalse( PreventaItems::all_arrived( $mixto ) );

		$vacio = new WC_Order();
		self::assertFalse( PreventaItems::all_arrived( $vacio ), 'Sin nada que esperar no hay nada que haya llegado.' );
	}

	// ───────────────────────────────────────────── sellado físico y recepción parcial

	/**
	 * @testdox Un tomo sellado en la línea cuenta como llegado aunque el catálogo siga 'onbackorder' o 'outofstock'.
	 */
	public function test_un_tomo_sellado_como_recibido_en_la_linea_ya_llego_aunque_el_catalogo_siga_a_pedido_o_agotado(): void {
		$item_a_pedido = $this->linea( 1, array( MetaKeys::ITEM_ARRIVED => 'yes', MetaKeys::ITEM_ARRIVED_QTY => '1' ), 'onbackorder' );
		$order1        = $this->pedido( array( $item_a_pedido ) );

		self::assertTrue( PreventaItems::is_item_sealed( $order1, $item_a_pedido ) );
		self::assertTrue( PreventaItems::has_arrived( $order1, $item_a_pedido ) );
		self::assertFalse( PreventaItems::is_pending_item( $order1, $item_a_pedido ) );
		self::assertSame( 0, PreventaItems::pending_qty( $order1, $item_a_pedido ) );

		// Mismo comportamiento si el producto quedó agotado en el catálogo.
		$item_agotado = $this->linea( 2, array( MetaKeys::ITEM_ARRIVED => 'yes', MetaKeys::ITEM_ARRIVED_QTY => '1' ), 'outofstock' );
		$order2       = $this->pedido( array( $item_agotado ) );

		self::assertTrue( PreventaItems::is_item_sealed( $order2, $item_agotado ) );
		self::assertTrue( PreventaItems::has_arrived( $order2, $item_agotado ) );
		self::assertFalse( PreventaItems::is_pending_item( $order2, $item_agotado ) );
		self::assertSame( 0, PreventaItems::pending_qty( $order2, $item_agotado ) );
	}

	/**
	 * @testdox Recepción parcial en línea multicopia descuenta sólo lo recibido hasta completar el total.
	 */
	public function test_recepcion_parcial_en_linea_multicopia_descuenta_solo_lo_recibido(): void {
		$item  = $this->linea( 1, array(), 'onbackorder', 3 );
		$order = $this->pedido( array( $item ) );

		self::assertSame( 3, PreventaItems::pending_qty( $order, $item ) );
		self::assertFalse( PreventaItems::is_item_sealed( $order, $item ) );
		self::assertFalse( PreventaItems::has_arrived( $order, $item ) );

		// Asignamos 1 unidad: faltan 2.
		$asignado1 = PreventaItems::seal_item_arrived( $order, $item, 1 );
		self::assertSame( 1, $asignado1 );
		self::assertSame( 2, PreventaItems::pending_qty( $order, $item ) );
		self::assertFalse( PreventaItems::is_item_sealed( $order, $item ) );
		self::assertFalse( PreventaItems::has_arrived( $order, $item ) );
		self::assertSame( 1, (int) $item->get_meta( MetaKeys::ITEM_ARRIVED_QTY ) );
		self::assertSame( '', (string) $item->get_meta( MetaKeys::ITEM_ARRIVED ) );

		// Asignamos 2 unidades más: completa las 3.
		$asignado2 = PreventaItems::seal_item_arrived( $order, $item, 2 );
		self::assertSame( 2, $asignado2 );
		self::assertSame( 0, PreventaItems::pending_qty( $order, $item ) );
		self::assertTrue( PreventaItems::is_item_sealed( $order, $item ) );
		self::assertTrue( PreventaItems::has_arrived( $order, $item ) );
		self::assertSame( 3, (int) $item->get_meta( MetaKeys::ITEM_ARRIVED_QTY ) );
		self::assertSame( 'yes', (string) $item->get_meta( MetaKeys::ITEM_ARRIVED ) );

		// Intentar asignar más unidades cuando ya está completo devuelve 0.
		$exceso = PreventaItems::seal_item_arrived( $order, $item, 1 );
		self::assertSame( 0, $exceso );
	}

	/**
	 * @testdox PA-03: Dos pedidos esperando una unidad del mismo tomo no se vuelven ambos listos cuando el catálogo pasa a instock sin asignación física.
	 */
	public function test_dos_pedidos_compitiendo_por_una_unidad_no_vuelven_ambos_listos_sin_asignacion_fisica(): void {
		$producto_compartido             = new WC_Product( 'instock' );
		$producto_compartido->akb_id     = 8099;
		$producto_compartido->akb_status = 'publish';

		$item1               = new WC_Order_Item_Product();
		$item1->akb_id       = 1;
		$item1->akb_quantity = 1;
		$item1->akb_meta     = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
		$item1->akb_product  = $producto_compartido;

		$order1            = new WC_Order( array( MetaKeys::ORDER_READY_TO_SHIP => 'no' ) );
		$order1->akb_items = array( $item1 );

		$item2               = new WC_Order_Item_Product();
		$item2->akb_id       = 2;
		$item2->akb_quantity = 1;
		$item2->akb_meta     = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
		$item2->akb_product  = $producto_compartido;

		$order2            = new WC_Order( array( MetaKeys::ORDER_READY_TO_SHIP => 'no' ) );
		$order2->akb_items = array( $item2 );

		// Sin asignación física (ITEM_ARRIVED / ITEM_ARRIVED_QTY), NINGUNO ha llegado
		self::assertFalse( PreventaItems::has_arrived( $order1, $item1 ) );
		self::assertFalse( PreventaItems::has_arrived( $order2, $item2 ) );
		self::assertTrue( PreventaItems::is_pending_item( $order1, $item1 ) );
		self::assertTrue( PreventaItems::is_pending_item( $order2, $item2 ) );
		self::assertSame( 1, PreventaItems::pending_qty( $order1, $item1 ) );
		self::assertSame( 1, PreventaItems::pending_qty( $order2, $item2 ) );

		// Tras sellar físicamente 1 unidad para la orden 1:
		PreventaItems::seal_item_arrived( $order1, $item1, 1, time() );
		self::assertTrue( PreventaItems::has_arrived( $order1, $item1 ) );
		self::assertFalse( PreventaItems::is_pending_item( $order1, $item1 ) );

		// La orden 2 DEBE seguir pendiente porque no tiene asignación física
		self::assertFalse( PreventaItems::has_arrived( $order2, $item2 ) );
		self::assertTrue( PreventaItems::is_pending_item( $order2, $item2 ) );
		self::assertSame( 1, PreventaItems::pending_qty( $order2, $item2 ) );
	}
}
