<?php
/**
 * Lo que el cliente VE de su espera: la línea por tomo, el cartel y la columna.
 *
 * Tres superficies con tres porteros distintos, y ninguno tenía prueba. Las
 * reglas que aquí se fijan salieron de correos reales:
 *
 *   · La LÍNEA se queda como traza en pedidos completados («Ya llegó a nuestra
 *     bodega · Argentina»); el CARTEL, que promete, no.
 *   · Tras una postergación, línea y cartel dicen la MISMA fecha: la línea
 *     leía siempre la promesa de compra y el cartel la recalculada.
 *   · Una promesa vencida no se lee como previsión: «La fecha estimada era el
 *     1 de octubre» un 20 de octubre, no «estimamos que llega el 1 de octubre».
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\OrderPreventa\CustomerVisibility;
use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

final class CustomerVisibilityTest extends TestCase {

	private const DIA = 86400;

	/** Reloj fijo de la prueba, redondeado a medianoche UTC. */
	private int $hoy;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->hoy                       = (int) floor( time() / self::DIA ) * self::DIA;
		$GLOBALS['akibara_test_options'] = array();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( $v ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $t ): string => strip_tags( $t ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( string $format, ?int $ts = null, $tz = null ): string => gmdate( $format, (int) $ts )
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ───────────────────────────────────────────────────────────── fixtures

	/**
	 * @param array<string, mixed> $meta Metas de la línea además del snapshot.
	 */
	private function linea( int $min, int $max, array $meta = array(), string $stock = 'onbackorder' ): WC_Order_Item_Product {
		$item               = new WC_Order_Item_Product();
		$item->akb_id       = 1;
		$item->akb_name     = 'Frieren 06';
		$item->akb_quantity = 1;
		$item->akb_meta     = array(
			MetaKeys::ITEM_WAS_PREVENTA              => 'yes',
			MetaKeys::ITEM_MIN_AT_PURCHASE           => (string) $min,
			MetaKeys::ITEM_MAX_AT_PURCHASE           => (string) $max,
			MetaKeys::ITEM_COUNTRY_NAME_AT_PURCHASE  => 'Argentina',
		) + $meta;
		$producto           = new WC_Product( $stock );
		$producto->akb_id   = 1001;
		$item->akb_product  = $producto;
		return $item;
	}

	/**
	 * @param array<string, mixed> $meta Metas del pedido.
	 */
	private function pedido( string $status, array $meta, WC_Order_Item_Product $item ): WC_Order {
		$order             = new WC_Order( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) + $meta );
		$order->akb_status = $status;
		$order->akb_items  = array( $item );
		return $order;
	}

	private function fecha_larga( int $ts ): string {
		return sprintf( '%d de %s', (int) gmdate( 'j', $ts ), PedidoPolicy::mes( (int) gmdate( 'n', $ts ) ) );
	}

	private function linea_html( WC_Order $order, WC_Order_Item_Product $item ): string {
		ob_start();
		( new CustomerVisibility() )->render_item_line( 1, $item, $order );
		return (string) ob_get_clean();
	}

	private function cartel_html( WC_Order $order ): string {
		ob_start();
		( new CustomerVisibility() )->render_customer_banner( $order );
		return (string) ob_get_clean();
	}

	private function columna_html( WC_Order $order ): string {
		ob_start();
		( new CustomerVisibility() )->render_orders_column( $order );
		return (string) ob_get_clean();
	}

	// ───────────────────────────────────────────────────────────── la línea

	/**
	 * @testdox Mientras no llega, la línea dice el rango prometido y el país.
	 */
	public function test_a_pending_line_promises_the_window_and_the_country(): void {
		$min   = $this->hoy + 30 * self::DIA;
		$max   = $this->hoy + 40 * self::DIA;
		$item  = $this->linea( $min, $max );
		$order = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no' ), $item );

		$html = $this->linea_html( $order, $item );

		self::assertStringContainsString( 'Llega', $html );
		self::assertStringContainsString( $this->fecha_larga( $max ), $html );
		self::assertStringContainsString( 'Argentina', $html );
	}

	/**
	 * @testdox Tras una postergación, la línea y el cartel dicen la MISMA fecha (la vigente, no la de compra).
	 */
	public function test_after_a_postponement_the_line_and_the_banner_agree(): void {
		$compra_max  = $this->hoy + 30 * self::DIA;
		$vigente_min = $this->hoy + 60 * self::DIA;
		$vigente_max = $this->hoy + 70 * self::DIA;

		$item  = $this->linea(
			$compra_max - 5 * self::DIA,
			$compra_max,
			array(
				MetaKeys::ITEM_MIN_CURRENT => (string) $vigente_min,
				MetaKeys::ITEM_MAX_CURRENT => (string) $vigente_max,
			)
		);
		$order = $this->pedido(
			'processing',
			array(
				MetaKeys::ORDER_READY_TO_SHIP => 'no',
				MetaKeys::ORDER_SUMMARY_MAX   => (string) $vigente_max,
			),
			$item
		);

		$linea  = $this->linea_html( $order, $item );
		$cartel = $this->cartel_html( $order );

		self::assertStringContainsString( $this->fecha_larga( $vigente_max ), $linea, 'La línea seguía imprimiendo la promesa de compra.' );
		self::assertStringNotContainsString( 'el ' . $this->fecha_larga( $compra_max ), $linea );
		self::assertStringContainsString( $this->fecha_larga( $vigente_max ), $cartel );
	}

	/**
	 * @testdox Si la editorial retiró la fecha, la línea dice «sin fecha confirmada» en vez de volver a la de compra.
	 */
	public function test_a_retracted_date_leaves_the_line_without_a_date(): void {
		$max   = $this->hoy + 30 * self::DIA;
		$item  = $this->linea(
			$max - 5 * self::DIA,
			$max,
			array(
				MetaKeys::ITEM_MIN_CURRENT => '0', // la marca que deja el recalculador al retractar
				MetaKeys::ITEM_MAX_CURRENT => '0',
			)
		);
		$order = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no' ), $item );

		$linea = $this->linea_html( $order, $item );

		self::assertStringContainsString( 'Sin fecha confirmada · Argentina', $linea );
		self::assertStringNotContainsString( 'Llega', $linea, 'La línea decía «Llega entre…» mientras la columna del mismo pedido decía «sin fecha».' );
		self::assertStringContainsString( 'sin fecha', $this->columna_html( $order ) );
	}

	/**
	 * @testdox La columna de Mis pedidos tampoco lee una promesa vencida como previsión.
	 */
	public function test_the_orders_column_admits_an_overdue_promise(): void {
		$max   = $this->hoy - 10 * self::DIA;
		$item  = $this->linea( $max - 5 * self::DIA, $max );
		$order = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) $max ), $item );

		$columna = $this->columna_html( $order );

		self::assertStringContainsString( 'Llegaba ~', $columna );
		self::assertStringContainsString( 'atrasado', $columna );
		self::assertStringNotContainsString( 'Llega ~', $columna );
	}

	/**
	 * @testdox Con todo en bodega y el cron aún sin pasar, el cartel no estima una llegada de algo que ya llegó.
	 */
	public function test_everything_arrived_before_the_cron_reads_as_ready(): void {
		$max   = $this->hoy + 30 * self::DIA;
		$item  = $this->linea( $max - 5 * self::DIA, $max, array(), 'instock' );
		$order = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) $max ), $item );

		$cartel = $this->cartel_html( $order );

		self::assertStringContainsString( 'listo para despachar', $cartel, 'La línea decía «Ya llegó» y el cartel «estimamos que llega el X»: stock cargado sin el hook.' );
		self::assertStringNotContainsString( 'Estimamos', $cartel );
		self::assertStringContainsString( 'Lista para despachar', $this->columna_html( $order ) );
		self::assertStringContainsString( 'Ya llegó a nuestra bodega', $this->linea_html( $order, $item ) );
	}

	/**
	 * @testdox En un pedido mixto, la línea que llegó lo dice y el cartel sigue estimando la que falta.
	 */
	public function test_a_mixed_order_keeps_estimating_what_is_still_missing(): void {
		$max     = $this->hoy + 30 * self::DIA;
		$llego   = $this->linea( $max - 5 * self::DIA, $max, array(), 'instock' );
		$espera  = $this->linea( $max - 5 * self::DIA, $max );
		$espera->akb_id = 2;
		$order   = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) $max ), $llego );
		$order->akb_items[] = $espera;

		self::assertStringContainsString( 'Ya llegó a nuestra bodega', $this->linea_html( $order, $llego ) );
		self::assertStringContainsString( 'Llega entre', $this->linea_html( $order, $espera ) );
		self::assertStringContainsString( 'Estimamos que el último producto llega el ' . $this->fecha_larga( $max ), $this->cartel_html( $order ) );
	}

	/**
	 * @testdox Cuando el tomo ya está en bodega, la línea deja de prometer una fecha.
	 */
	public function test_an_arrived_line_says_so_instead_of_a_future_date(): void {
		$max   = $this->hoy - 20 * self::DIA;
		$item  = $this->linea( $max - 3 * self::DIA, $max, array(), 'instock' );
		$order = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no' ), $item );

		$html = $this->linea_html( $order, $item );

		self::assertStringContainsString( 'Ya llegó a nuestra bodega · Argentina', $html );
		self::assertStringNotContainsString( 'Llega', $html );
	}

	/**
	 * @testdox El texto de «ya llegó» se edita sin código.
	 */
	public function test_the_arrived_wording_is_an_option(): void {
		$GLOBALS['akibara_test_options']['akb_copy_eta_item_arrived'] = 'En bodega';
		$item  = $this->linea( 1, 2, array(), 'instock' );
		$order = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'yes' ), $item );

		self::assertStringContainsString( 'En bodega · Argentina', $this->linea_html( $order, $item ) );
	}

	/**
	 * @testdox La línea se conserva como traza en un pedido completado; el cartel no.
	 */
	public function test_a_completed_order_keeps_the_line_but_not_the_banner(): void {
		$item  = $this->linea( 1, 2 );
		$order = $this->pedido( 'completed', array( MetaKeys::ORDER_READY_TO_SHIP => 'yes' ), $item );

		self::assertStringContainsString( 'Ya llegó a nuestra bodega', $this->linea_html( $order, $item ) );
		self::assertSame( '', $this->cartel_html( $order ), '«Listo para despachar, sale en X días» salía en el correo del código de seguimiento.' );
		self::assertStringContainsString( '—', $this->columna_html( $order ) );
	}

	/**
	 * @testdox Un pedido completado sin la bandera «listo» y con el tomo hoy agotado dice «Ya llegó», nunca una fecha.
	 */
	public function test_a_completed_order_never_prints_a_date(): void {
		$max   = $this->hoy - 60 * self::DIA;
		$item  = $this->linea( $max - 3 * self::DIA, $max, array(), 'outofstock' ); // vendido y agotado de nuevo
		$order = $this->pedido( 'completed', array( MetaKeys::ORDER_READY_TO_SHIP => 'no' ), $item );

		$linea = $this->linea_html( $order, $item );

		self::assertStringContainsString( 'Ya llegó a nuestra bodega · Argentina', $linea, 'Un pedido completado SALIÓ: la línea no depende del stock de hoy.' );
		self::assertStringNotContainsString( 'Llegaba', $linea );
	}

	/**
	 * @testdox Un pedido sin pagar con todo en bodega NO dice «listo para despachar».
	 */
	public function test_an_unpaid_order_is_never_ready(): void {
		$max   = $this->hoy + 30 * self::DIA;
		$item  = $this->linea( $max - 5 * self::DIA, $max, array(), 'instock' );
		$order = $this->pedido( 'on-hold', array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) $max ), $item );

		self::assertStringNotContainsString( 'listo para despachar', $this->cartel_html( $order ), 'Sin pago no sale nada; el cron tampoco lo marca.' );
		self::assertStringNotContainsString( 'Lista para despachar', $this->columna_html( $order ) );
		self::assertStringContainsString( 'Ya llegó a nuestra bodega', $this->linea_html( $order, $item ), 'La línea sí puede decir que el tomo está: es verdad.' );
	}

	/**
	 * @testdox Un «%» en el texto de promesa vencida no tumba la página; sin «%s», la fecha va al final.
	 */
	public function test_owner_copy_is_never_a_format_string(): void {
		$max   = $this->hoy - 10 * self::DIA;
		$item  = $this->linea( $max - 5 * self::DIA, $max );
		$order = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) $max ), $item );

		$GLOBALS['akibara_test_options']['akb_copy_eta_overdue'] = 'Estamos al 100% con la editorial; era el %s.';
		self::assertStringContainsString( 'Estamos al 100% con la editorial; era el ' . $this->fecha_larga( $max ) . '.', $this->cartel_html( $order ), 'Con sprintf esto era un fatal en /mi-cuenta y en los correos.' );

		$GLOBALS['akibara_test_options']['akb_copy_eta_overdue'] = 'Se retrasó un 10%';
		self::assertStringContainsString( 'Se retrasó un 10% (era el ' . $this->fecha_larga( $max ) . ')', $this->cartel_html( $order ), 'Sin marcador, la fecha no desaparece.' );

		$GLOBALS['akibara_test_options']['akb_copy_eta_overdue'] = '   ';
		self::assertStringContainsString( 'La fecha estimada era el ' . $this->fecha_larga( $max ), $this->cartel_html( $order ), 'Vacío vuelve al literal.' );
	}

	/**
	 * @dataProvider estados_sin_promesa
	 * @testdox Un pedido $status no pinta línea, cartel ni columna.
	 */
	public function test_a_dead_order_shows_nothing( string $status ): void {
		$item  = $this->linea( 1, 2 );
		$order = $this->pedido( $status, array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) ( $this->hoy + 30 * self::DIA ) ), $item );

		self::assertSame( '', $this->linea_html( $order, $item ) );
		self::assertSame( '', $this->cartel_html( $order ) );
		self::assertStringContainsString( '—', $this->columna_html( $order ) );
	}

	/** @return array<string, array{0:string}> */
	public static function estados_sin_promesa(): array {
		return array(
			'cancelado'   => array( 'cancelled' ),
			'reembolsado' => array( 'refunded' ),
			'fallido'     => array( 'failed' ),
		);
	}

	/**
	 * Hallazgo S-d35d8a8b: la guarda decía evitar un doble render con
	 * `OrderItemDisplay`, y eso no podía pasar —son hooks distintos:
	 * `woocommerce_order_item_meta_end` sólo lo disparan las plantillas de
	 * correo, «pedido recibido», «ver pedido» y «pagar pedido»; el admin usa
	 * `woocommerce_after_order_itemmeta`—. Lo que sí hacía era borrar la línea
	 * de TODO correo enviado desde una pantalla de wp-admin (reenviar el correo
	 * del pedido, cambiar el estado desde la lista, crear el pedido a mano),
	 * porque ahí `is_admin()` vale true y este sitio manda los correos en la
	 * misma petición.
	 *
	 * @testdox Un correo enviado desde el escritorio conserva la línea de preventa.
	 */
	public function test_an_email_sent_from_wp_admin_keeps_the_line(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$item  = $this->linea( 1, 2 );
		$order = $this->pedido( 'processing', array(), $item );

		self::assertStringContainsString( 'akb-eta-badge', $this->linea_html( $order, $item ) );
	}

	// ─────────────────────────────────────────────────────── cartel y columna

	/**
	 * @testdox Un pedido entregado al courier (estado configurable) ya no promete nada.
	 */
	public function test_a_carrier_handoff_status_silences_the_banner(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value, ...$args ) => 'akb_order_preventa_carrier_handoff_statuses' === $hook ? array( 'shipped' ) : $value
		);
		$item  = $this->linea( 1, 2 );
		$order = $this->pedido( 'shipped', array( MetaKeys::ORDER_READY_TO_SHIP => 'yes' ), $item );

		self::assertSame( '', $this->cartel_html( $order ) );
		self::assertStringContainsString( '—', $this->columna_html( $order ) );
	}

	/**
	 * @testdox Listo para despachar: cartel verde y columna «Lista».
	 */
	public function test_a_ready_order_announces_the_dispatch(): void {
		$item  = $this->linea( 1, 2 );
		$order = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'yes' ), $item );

		self::assertStringContainsString( 'listo para despachar', $this->cartel_html( $order ) );
		self::assertStringContainsString( 'Lista para despachar', $this->columna_html( $order ) );
	}

	/**
	 * @testdox Esperando con fecha: columna «Llega ~» y cartel con la estimación.
	 */
	public function test_a_waiting_order_shows_the_estimate(): void {
		$max   = $this->hoy + 30 * self::DIA;
		$item  = $this->linea( $max - 5 * self::DIA, $max );
		$order = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) $max ), $item );

		self::assertStringContainsString( 'Llega ~', $this->columna_html( $order ) );
		$cartel = $this->cartel_html( $order );
		self::assertStringContainsString( 'Estimamos que el último producto llega el ' . $this->fecha_larga( $max ), $cartel );
		self::assertStringContainsString( 'Te avisaremos por correo', $cartel );
	}

	/**
	 * @testdox Con la promesa vencida, el cartel lo reconoce en vez de fingir una previsión.
	 */
	public function test_an_overdue_promise_is_admitted(): void {
		$max   = $this->hoy - 10 * self::DIA;
		$item  = $this->linea( $max - 5 * self::DIA, $max );
		$order = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) $max ), $item );

		$cartel = $this->cartel_html( $order );

		self::assertStringContainsString( 'La fecha estimada era el ' . $this->fecha_larga( $max ), $cartel );
		self::assertStringNotContainsString( 'Estimamos que', $cartel );

		$linea = $this->linea_html( $order, $item );
		self::assertStringContainsString( 'Llegaba entre el', $linea, 'La línea seguía en presente bajo un cartel que reconocía el retraso.' );
		self::assertStringNotContainsString( 'Llega entre', $linea );
	}

	/**
	 * @testdox El texto de la promesa vencida se edita sin código y conserva la fecha.
	 */
	public function test_the_overdue_wording_is_an_option(): void {
		$GLOBALS['akibara_test_options']['akb_copy_eta_overdue'] = 'Se nos pasó el %s, perdona.';
		$max   = $this->hoy - 10 * self::DIA;
		$item  = $this->linea( $max - 5 * self::DIA, $max );
		$order = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) $max ), $item );

		self::assertStringContainsString( 'Se nos pasó el ' . $this->fecha_larga( $max ) . ', perdona.', $this->cartel_html( $order ) );
	}

	/**
	 * @testdox Con todas las esperas reembolsadas no hay cartel aunque el pedido siga marcado.
	 */
	public function test_a_fully_refunded_wait_has_no_banner(): void {
		$item                = $this->linea( 1, 2 );
		$order               = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no' ), $item );
		$order->akb_refunded = array( 1 => 1 );

		self::assertSame( '', $this->cartel_html( $order ) );
		self::assertSame( '', $this->linea_html( $order, $item ) );
	}

	/**
	 * @testdox La columna de Mis pedidos escribe el mes corto de la casa («14 dic»), no el de WordPress («14 Dic»).
	 */
	public function test_the_orders_column_uses_the_house_short_month(): void {
		$max     = $this->hoy + 30 * self::DIA;
		$item    = $this->linea( $max - 5 * self::DIA, $max );
		$order   = $this->pedido( 'processing', array( MetaKeys::ORDER_READY_TO_SHIP => 'no', MetaKeys::ORDER_SUMMARY_MAX => (string) $max ), $item );
		$columna = $this->columna_html( $order );
		self::assertMatchesRegularExpression( '/Llega ~\d{1,2} [a-z]{3}(?![a-z])/u', $columna, $columna );
	}
}
