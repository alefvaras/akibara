<?php
/**
 * El asunto y el encabezado del correo de fecha dicen lo que el cliente compró.
 *
 * Decían «preventa» a fuego. Las 20 líneas en espera de la tienda el
 * 2026-09-02 eran a pedido, así que a los dos pedidos vivos que podían recibir
 * este correo (#27443, #27786) les habría llegado «¡Ya tenemos fecha para tu
 * preventa!» sobre tomos que compraron a pedido — dos renglones encima de una
 * línea que, correctamente, decía «A pedido».
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\Stock\Availability;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WC_Order;
use WC_Order_Item_Product;

final class ReminderEmailWordTest extends TestCase {

	private const PEDIDO   = 27443;
	private const PRODUCTO = 4242;

	private WC_Order $order;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) );

		Functions\when( 'trailingslashit' )->alias( static fn( string $path ): string => rtrim( $path, '/\\' ) . '/' );
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);
		Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => $default );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Pedido en espera con UNA línea del producto que cambia de fecha.
	 *
	 * @param string $tipo `auto` | `release_date` | `tba` (snapshot de compra).
	 * @param bool   $tba  Si se compró sin fecha.
	 */
	private function pedido( string $tipo, bool $tba = false ): WC_Order {
		$item                 = new WC_Order_Item_Product();
		$item->akb_id         = 7;
		$item->akb_name       = 'Tomo que espera';
		$item->akb_product_id = self::PRODUCTO;
		$item->akb_meta       = array(
			MetaKeys::ITEM_WAS_PREVENTA        => 'yes',
			MetaKeys::ITEM_TYPE_AT_PURCHASE    => $tipo,
			MetaKeys::ITEM_IS_TBA_AT_PURCHASE  => $tba ? 'yes' : 'no',
		);

		$order             = new WC_Order( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) );
		$order->akb_id     = self::PEDIDO;
		$order->akb_status = 'processing';
		$order->akb_items  = array( $item );

		$this->order = $order;
		Functions\when( 'wc_get_order' )->alias( static fn() => $order );

		return $order;
	}

	/**
	 * @testdox Postergación sobre un tomo A PEDIDO: «tu pedido», no «tu preventa».
	 */
	public function test_date_change_on_a_pedido_says_pedido(): void {
		$this->pedido( Availability::TYPE_AUTO );
		$email = new ReminderEmailSpy();

		$email->trigger_date_changed( self::PEDIDO, self::PRODUCTO, '2026-10-17', '2026-11-07' );

		self::assertCount( 1, $email->akb_sent );
		self::assertSame( 'Actualización de tu pedido #27443: cambio de fecha', $email->get_subject(), 'Sin fecha de llegada calculable en el cuerpo, el asunto no puede prometer una.' );
		self::assertSame( 'Cambio de fecha en tu pedido', $email->get_heading() );
	}

	/**
	 * @testdox Postergación sobre una PREVENTA de verdad: «tu preventa».
	 */
	public function test_date_change_on_real_preventa_says_preventa(): void {
		$this->pedido( Availability::TYPE_RELEASE_DATE );
		$email = new ReminderEmailSpy();

		$email->trigger_date_changed( self::PEDIDO, self::PRODUCTO, '2026-10-17', '2026-11-07' );

		self::assertSame( 'Actualización de tu preventa #27443: nueva fecha', $email->get_subject() );
		self::assertSame( 'Cambio de fecha en tu preventa', $email->get_heading() );
	}

	/**
	 * @testdox Fecha anunciada sobre un tomo a pedido (tenía rango prometido): no es «buena noticia».
	 */
	public function test_date_announced_on_a_pedido_is_not_good_news(): void {
		$this->pedido( Availability::TYPE_AUTO, false );
		$email = new ReminderEmailSpy();

		$email->trigger_date_announced( self::PEDIDO, self::PRODUCTO, '', '2027-03-05' );

		self::assertCount( 1, $email->akb_sent );
		self::assertSame( 'Tu pedido #27443: la editorial anunció fecha de salida', $email->get_subject() );
		self::assertSame( 'La editorial anunció fecha de salida', $email->get_heading() );

		$args = new ReflectionMethod( $email, 'template_args' );
		$args->setAccessible( true );
		self::assertFalse( $args->invoke( $email, false )['bought_without_date'], 'El cuerpo no puede decir «que compraste sin fecha confirmada».' );
	}

	/**
	 * @testdox Fecha anunciada sobre una preventa comprada CON fecha (la editorial la retiró y volvió a anunciar): es un cambio, no una celebración.
	 */
	public function test_date_announced_on_a_preventa_bought_with_a_date_is_a_change(): void {
		$this->pedido( Availability::TYPE_RELEASE_DATE, false );
		$email = new ReminderEmailSpy();
		$email->trigger_date_announced( self::PEDIDO, self::PRODUCTO, '', '2027-03-05' );
		self::assertCount( 1, $email->akb_sent );
		self::assertSame( 'Actualización de tu preventa #27443: nueva fecha', $email->get_subject(), 'El asunto no puede celebrar lo que el cuerpo lamenta.' );
		self::assertSame( 'Cambio de fecha en tu preventa', $email->get_heading() );
	}

	/**
	 * @testdox Fecha anunciada sobre una preventa comprada SIN fecha: sí es buena noticia.
	 */
	public function test_date_announced_on_tba_is_good_news(): void {
		$this->pedido( Availability::TYPE_TBA, true );
		$email = new ReminderEmailSpy();

		$email->trigger_date_announced( self::PEDIDO, self::PRODUCTO, '', '2027-03-05' );

		self::assertSame( '¡Ya tenemos fecha para tu preventa #27443!', $email->get_subject() );
		self::assertSame( 'Tu pedido ya tiene fecha', $email->get_heading() );

		$args = new ReflectionMethod( $email, 'template_args' );
		$args->setAccessible( true );
		self::assertTrue( $args->invoke( $email, false )['bought_without_date'] );
	}

	/**
	 * @testdox Un tomo a pedido con la editorial pausada (tba al comprar) sigue siendo «tu pedido», no «tu preventa».
	 */
	public function test_a_pedido_bought_as_tba_is_still_a_pedido(): void {
		$this->pedido( Availability::TYPE_AUTO, true );
		$email = new ReminderEmailSpy();

		$email->trigger_date_announced( self::PEDIDO, self::PRODUCTO, '', '2027-03-05' );

		self::assertSame( 'Tu pedido #27443: la editorial anunció fecha de salida', $email->get_subject() );
		self::assertSame( 'La editorial anunció fecha de salida', $email->get_heading() );
		$args = new ReflectionMethod( $email, 'template_args' );
		$args->setAccessible( true );
		$t = $args->invoke( $email, false );
		self::assertTrue( $t['bought_without_date'], 'Sí compró sin fecha: el cuerpo puede decirlo; la PALABRA no.' );
		self::assertSame( 'Tomo que espera', $t['date_change_ctx']['product_name'], 'El correo nombra el tomo que cambió.' );
	}

	/**
	 * @testdox «Te llegaría alrededor del» es el arribo del tomo que cambió, no el máximo del pedido.
	 */
	public function test_the_date_notice_speaks_about_the_tomo_that_changed(): void {
		$order = $this->pedido( Availability::TYPE_AUTO );
		$order->akb_items[0]->akb_meta[ MetaKeys::ITEM_MAX_CURRENT ] = '1796000000'; // el tomo que cambió
		$order->akb_items[0]->akb_meta[ MetaKeys::ITEM_MIN_CURRENT ] = '1795000000';

		$otro                 = new WC_Order_Item_Product();
		$otro->akb_id         = 9;
		$otro->akb_product_id = 9997;
		$otro->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes', MetaKeys::ITEM_MAX_AT_PURCHASE => '1799000000' ); // el otro tomo, más tarde
		$order->akb_items[]   = $otro;
		$order->akb_meta[ MetaKeys::ORDER_SUMMARY_MAX ] = '1799000000';

		$email = new ReminderEmailSpy();
		$email->trigger_date_changed( self::PEDIDO, self::PRODUCTO, '2026-10-17', '2026-11-07' );

		$args = new ReflectionMethod( $email, 'template_args' );
		$args->setAccessible( true );
		$t = $args->invoke( $email, false );

		self::assertSame( gmdate( 'Y-m-d', 1796000000 ), $t['item_max_ymd'], 'Con el nombre del tomo delante, el cliente leía un atraso de dos meses donde había diez días.' );
		self::assertSame( gmdate( 'Y-m-d', 1799000000 ), $t['summary_max_ymd'], 'El pedido completo sigue siendo el máximo.' );
		self::assertNotSame( $t['item_max_ymd'], $t['summary_max_ymd'] );
	}

	/**
	 * @testdox El recordatorio T-7 tampoco lista lo que ya está en bodega.
	 */
	public function test_the_t7_reminder_lists_only_what_is_still_pending(): void {
		$order = $this->pedido( Availability::TYPE_AUTO );

		$llego                 = new WC_Order_Item_Product();
		$llego->akb_id         = 8;
		$llego->akb_product_id = 9998;
		$llego->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
		$llego->akb_product    = new \WC_Product( 'instock' );
		$order->akb_items[]    = $llego;

		$email = new ReminderEmailSpy();
		$reset = new ReflectionMethod( $email, 'reset_send_state' );
		$reset->setAccessible( true );
		$reset->invoke( $email, 't7', $order );

		$args = new ReflectionMethod( $email, 'template_args' );
		$args->setAccessible( true );
		$items = $args->invoke( $email, false )['preventa_items'];

		self::assertSame( array( 7 ), array_map( static fn( WC_Order_Item_Product $i ): int => $i->get_id(), $items ), '«Están por llegar» listaba un tomo que ya estaba en la estantería.' );
	}

	/**
	 * @testdox El aviso de fecha no lista el tomo que ya está en bodega.
	 */
	public function test_a_date_notice_lists_only_what_is_still_pending(): void {
		$order = $this->pedido( Availability::TYPE_AUTO );

		$llego                 = new WC_Order_Item_Product();
		$llego->akb_id         = 8;
		$llego->akb_name       = 'Tomo que ya llegó';
		$llego->akb_product_id = 9998;
		$llego->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
		$llego->akb_product    = new \WC_Product( 'instock' );
		$order->akb_items[]    = $llego;

		$email = new ReminderEmailSpy();
		$email->trigger_date_changed( self::PEDIDO, self::PRODUCTO, '2026-10-17', '2026-11-07' );

		$args  = new ReflectionMethod( $email, 'template_args' );
		$args->setAccessible( true );
		$items = $args->invoke( $email, false )['preventa_items'];

		self::assertSame( array( 7 ), array_map( static fn( WC_Order_Item_Product $i ): int => $i->get_id(), $items ), '«Lo que esperabas» listaba el tomo ya en bodega bajo un correo sobre la fecha de otro.' );
	}

	/**
	 * @testdox El correo no le nombra al cliente el tomo que ya se le devolvió.
	 */
	public function test_refunded_line_is_not_listed(): void {
		$order = $this->pedido( Availability::TYPE_AUTO );

		$devuelto                 = new WC_Order_Item_Product();
		$devuelto->akb_id         = 8;
		$devuelto->akb_product_id = 9999;
		$devuelto->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
		$order->akb_items[]       = $devuelto;
		$order->akb_refunded      = array( 8 => 1 );

		$email = new ReminderEmailSpy();
		$email->trigger_date_changed( self::PEDIDO, self::PRODUCTO, '2026-10-17', '2026-11-07' );

		$args = new ReflectionMethod( $email, 'template_args' );
		$args->setAccessible( true );
		$items = $args->invoke( $email, false )['preventa_items'];

		self::assertCount( 1, $items, 'El recordatorio listaba bajo «Lo que esperabas» el tomo reembolsado.' );
		self::assertSame( 7, $items[0]->get_id() );
	}
}
