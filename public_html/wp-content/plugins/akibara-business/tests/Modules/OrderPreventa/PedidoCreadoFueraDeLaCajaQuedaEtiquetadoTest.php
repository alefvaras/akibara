<?php
/**
 * Un pedido con producto «a pedido»/preventa creado FUERA del checkout (a mano
 * en wp-admin, por REST, por importación) tiene que quedar etiquetado igual
 * que uno de caja — no invisible para siempre.
 *
 * `LineItemSnapshot::register()` sólo cuelga de
 * `woocommerce_checkout_create_order_line_item` y `OrderTagging::register()`
 * sólo de los tres hooks de checkout/Store API. Un pedido armado con
 * `add_product()` a secas (el AJAX de admin, la API REST, una importación)
 * nunca dispara ninguno de los dos: nace SIN `_akb_eta_was_preventa` en la
 * línea y SIN `_akb_eta_has_preventa` en el pedido — la meta no vale «no»,
 * simplemente no existe — y los ocho consumidores del módulo (cron, listeners,
 * columna admin, visibilidad cliente...) exigen igualdad estricta a 'yes',
 * nunca «meta ausente = candidato», así que el pedido queda esperando en
 * silencio para siempre (ficha R4-9d738de7, 2026-09-19).
 *
 * `OrderTagging::maybe_tag_order_created_outside_checkout()` cierra el hueco
 * colgando del guardado GENÉRICO de toda orden
 * (`woocommerce_before_order_object_save`) con un etiquetado perezoso e
 * idempotente: no una lista de hooks nueva por cada vía de creación futura.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\LineItemSnapshot;
use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\OrderTagging;
use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Tests\Support\NullEtaProvider;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

final class PedidoCreadoFueraDeLaCajaQuedaEtiquetadoTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// La guarda de «ya salió de la tienda» pasa por
		// FulfillmentCapabilities::departed_order_statuses(), que normaliza con
		// sanitize_key() y consulta apply_filters(): dobles propios para no
		// depender de qué clase corrió antes (Brain Monkey contagia
		// function_exists()).
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( (string) $k ) );
		// El transportista declara su estado de entrega por filtro: aquí se
		// simula Blue Express (`shipping-progress`) para que la lista de «ya
		// salió» tenga los DOS miembros que tiene en producción.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( 'akb_order_preventa_carrier_handoff_statuses' === $hook ) {
					return array_merge( (array) $value, array( 'shipping-progress' ) );
				}
				return $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function tagging(): OrderTagging {
		$eta = new NullEtaProvider();
		return new OrderTagging( new SummaryRecalculator( $eta ), new LineItemSnapshot( $eta ) );
	}

	/**
	 * @testdox register() engancha el etiquetado perezoso al guardado GENÉRICO de toda orden, no sólo a los tres hooks de checkout.
	 *
	 * Sin este cable, el método puede estar perfecto y el pedido armado a mano
	 * en wp-admin sigue sin recibirlo NUNCA: nada lo invoca.
	 */
	public function test_register_engancha_el_guardado_generico_de_toda_orden(): void {
		$es_el_metodo_perezoso = static function ( $callback ): bool {
			return is_array( $callback )
				&& $callback[0] instanceof OrderTagging
				&& 'maybe_tag_order_created_outside_checkout' === $callback[1];
		};

		Actions\expectAdded( 'woocommerce_before_order_object_save' )
			->once()
			->withArgs( $es_el_metodo_perezoso );

		$this->tagging()->register();

		self::assertTrue( true, 'La expectativa la verifica Brain Monkey al cerrar.' );
	}

	private function item_onbackorder( int $id ): WC_Order_Item_Product {
		$item              = new WC_Order_Item_Product();
		$item->akb_id      = $id;
		$item->akb_product = new WC_Product( StockStatus::ON_BACKORDER, array() );
		return $item;
	}

	/**
	 * @testdox Un pedido armado con add_product() a secas queda etiquetado tras guardarse.
	 */
	public function test_un_pedido_armado_con_add_product_a_secas_queda_etiquetado_tras_guardarse(): void {
		$item = $this->item_onbackorder( 1 );

		$order            = new WC_Order();
		$order->akb_status = 'processing';
		$order->akb_items  = array( $item );

		$this->tagging()->maybe_tag_order_created_outside_checkout( $order );

		self::assertSame( 'yes', $item->get_meta( MetaKeys::ITEM_WAS_PREVENTA ), 'La línea reservable debe quedar snapeada con el estado ACTUAL del producto.' );
		self::assertNotSame( '', $item->get_meta( MetaKeys::ITEM_TYPE_AT_PURCHASE ), 'stamp_preventa_details() debe correr, igual que en checkout.' );
		self::assertSame( 'yes', $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) );
		self::assertSame( 1, $order->get_meta( MetaKeys::ORDER_PENDING_COUNT ) );
		self::assertSame( 'no', $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) );

		self::assertCount( 1, $order->akb_notes, 'Se avisa en una nota que la fecha se tomó hoy, no al comprar.' );
		self::assertStringContainsString( 'fuera de la caja', $order->akb_notes[0] );
	}

	/**
	 * @testdox Un pedido YA taggeado no cambia (el guard barato del pedido ni mira los items).
	 *
	 * El producto de la línea HOY es `release_date` (pudo cambiar de tipo desde
	 * que se compró), pero el snapshot dice `auto`: si algo reescribiera la
	 * línea con el estado actual, este test lo vería cambiar.
	 */
	public function test_un_pedido_que_ya_tenia_snapshot_no_cambia(): void {
		$item              = $this->item_onbackorder( 1 );
		$item->akb_product = new WC_Product( StockStatus::ON_BACKORDER, array( '_akb_eta_type' => 'release_date' ) );
		$item->akb_meta    = array(
			MetaKeys::ITEM_WAS_PREVENTA     => 'yes',
			MetaKeys::ITEM_TYPE_AT_PURCHASE => 'auto',
		);

		$order = new WC_Order(
			array(
				MetaKeys::ORDER_HAS_PREVENTA   => 'yes',
				MetaKeys::ORDER_PENDING_COUNT  => 1,
			)
		);
		$order->akb_status = 'processing';
		$order->akb_items  = array( $item );

		$this->tagging()->maybe_tag_order_created_outside_checkout( $order );

		self::assertSame( 'yes', $item->get_meta( MetaKeys::ITEM_WAS_PREVENTA ) );
		self::assertSame( 'auto', $item->get_meta( MetaKeys::ITEM_TYPE_AT_PURCHASE ), 'No se reescribe un snapshot existente, aunque el producto sea hoy otro tipo.' );
		self::assertSame( 1, $order->get_meta( MetaKeys::ORDER_PENDING_COUNT ), 'No se recalcula nada: el guard corta ANTES de tocar items.' );
		self::assertSame( array(), $order->akb_notes, 'Pedido ya tageado de antes: cero notas nuevas.' );
	}

	/**
	 * @testdox Dentro de un pedido recién tocado, una línea que YA tenía snapshot no se reescribe (sólo se completa la que faltaba).
	 *
	 * A diferencia del test anterior, acá el PEDIDO no está taggeado todavía
	 * (`ORDER_HAS_PREVENTA` ausente), así que el guard barato del pedido no
	 * corta y el bucle SÍ llega a mirar cada línea — es la única forma de
	 * probar, aislado, que el skip por línea (`ITEM_WAS_PREVENTA` ya presente)
	 * hace su trabajo.
	 */
	public function test_una_linea_con_snapshot_propio_no_se_reescribe_aunque_el_pedido_si_se_procese(): void {
		// Línea A: el back-fill histórico ya la marcó 'unknown' (producto hoy
		// instock, no se puede saber con certeza el estado al comprar) sobre un
		// producto que HOY volvió a estar onbackorder. Si el skip por línea
		// fallara, se reescribiría a 'yes' con un snapshot nuevo.
		$linea_a           = $this->item_onbackorder( 1 );
		$linea_a->akb_meta = array( MetaKeys::ITEM_WAS_PREVENTA => 'unknown' );

		// Línea B: nunca pasó por nada — la que sí debe quedar etiquetada.
		$linea_b = $this->item_onbackorder( 2 );

		$order             = new WC_Order();
		$order->akb_status = 'processing';
		$order->akb_items  = array( $linea_a, $linea_b );

		$this->tagging()->maybe_tag_order_created_outside_checkout( $order );

		self::assertSame( 'unknown', $linea_a->get_meta( MetaKeys::ITEM_WAS_PREVENTA ), 'Snapshot previo intacto: no se reescribe.' );
		self::assertArrayNotHasKey( MetaKeys::ITEM_TYPE_AT_PURCHASE, $linea_a->akb_meta, 'stamp_preventa_details() no debió correr sobre esta línea.' );
		self::assertSame( 'yes', $linea_b->get_meta( MetaKeys::ITEM_WAS_PREVENTA ), 'La línea sin snapshot sí se etiqueta.' );
		self::assertSame( 1, $order->get_meta( MetaKeys::ORDER_PENDING_COUNT ), 'Sólo la línea B cuenta: "unknown" no es "yes".' );
	}

	/**
	 * @testdox El guard barato corta en el PRIMER get_meta() del pedido: ni siquiera llega a pedir los items.
	 *
	 * Es la comprobación que exige la ficha para no costar nada en el camino
	 * normal — un pedido ya taggeado nunca debe pagar ni la vuelta de iterar
	 * sus líneas. `get_items()` acá REVIENTA si se llama.
	 */
	public function test_el_guard_barato_no_llega_a_pedir_los_items(): void {
		$order = new class( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) ) extends WC_Order {
			public function get_items( string $types = 'line_item' ): array {
				throw new \RuntimeException( 'get_items() no debía llamarse: el guard barato corta antes, con un solo get_meta().' );
			}
		};
		$order->akb_status = 'processing';

		$this->tagging()->maybe_tag_order_created_outside_checkout( $order );

		self::assertSame( 'yes', $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) );
	}

	/**
	 * @testdox Un pedido cancelado no se toca.
	 */
	public function test_un_pedido_cancelado_no_se_toca(): void {
		$item = $this->item_onbackorder( 1 );

		$order             = new WC_Order();
		$order->akb_status = 'cancelled';
		$order->akb_items  = array( $item );

		$this->tagging()->maybe_tag_order_created_outside_checkout( $order );

		self::assertSame( '', $item->get_meta( MetaKeys::ITEM_WAS_PREVENTA ), 'Un pedido cerrado no recibirá avisos futuros: etiquetarlo no sirve.' );
		self::assertSame( '', $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) );
		self::assertSame( array(), $order->akb_notes );
	}

	/**
	 * Un pedido histórico nacido fuera de la caja y ya entregado no tiene avisos
	 * futuros; reetiquetarlo al guardarlo por una nota o un seguimiento lo hacía
	 * reaparecer como «Pendiente» con una nota falsa (ronda 5, 2026-09-19).
	 *
	 * @testdox Un pedido completado (o entregado al transportista) no se reetiqueta aunque su tomo vuelva a estar a pedido.
	 * @dataProvider estados_que_ya_salieron
	 */
	public function test_un_pedido_que_ya_salio_no_se_reetiqueta( string $estado ): void {
		$item = $this->item_onbackorder( 1 );

		$order             = new WC_Order();
		$order->akb_status = $estado;
		$order->akb_items  = array( $item );

		$this->tagging()->maybe_tag_order_created_outside_checkout( $order );

		self::assertSame( '', $item->get_meta( MetaKeys::ITEM_WAS_PREVENTA ), "{$estado}: ya salió de la tienda." );
		self::assertSame( '', $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) );
		self::assertSame( array(), $order->akb_notes );
	}

	/** @return array<string, array{0:string}> */
	public static function estados_que_ya_salieron(): array {
		return array(
			'completado'                 => array( 'completed' ),
			'entregado al transportista' => array( 'shipping-progress' ),
		);
	}

	/**
	 * @testdox Un pedido sin líneas todavía (el auto-draft de wp-admin) no se marca «sin preventa» antes de tiempo.
	 */
	public function test_un_pedido_sin_lineas_todavia_no_se_marca_sin_preventa(): void {
		$order             = new WC_Order();
		$order->akb_status = 'auto-draft';
		$order->akb_items  = array();

		$this->tagging()->maybe_tag_order_created_outside_checkout( $order );

		self::assertSame(
			'',
			$order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ),
			'Marcar "no" ahora dejaría el guard cerrado para siempre: el próximo guardado, ya con líneas, nunca se reintentaría.'
		);
	}

	/**
	 * @testdox Un pedido con líneas pero ninguna reservable AHORA queda definitivamente sin preventa.
	 */
	public function test_un_pedido_sin_ninguna_linea_reservable_queda_marcado_sin_preventa(): void {
		$item              = new WC_Order_Item_Product();
		$item->akb_id      = 1;
		$item->akb_product = new WC_Product( 'instock', array() );

		$order             = new WC_Order();
		$order->akb_status = 'processing';
		$order->akb_items  = array( $item );

		$this->tagging()->maybe_tag_order_created_outside_checkout( $order );

		self::assertSame( 'no', $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ), 'Sin esto, cada guardado futuro volvería a escanear el pedido.' );
		self::assertSame( '', $item->get_meta( MetaKeys::ITEM_WAS_PREVENTA ), 'No reservable: se deja ausente, igual que en checkout.' );
		self::assertSame( array(), $order->akb_notes, 'Sin preventa real de por medio, no hay nada que anotarle al pedido.' );
	}
}
