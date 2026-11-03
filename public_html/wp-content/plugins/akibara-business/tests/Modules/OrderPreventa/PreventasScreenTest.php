<?php
/**
 * La pantalla «Por llegar»: el guardado de fecha y la cabecera.
 *
 * Es la única puerta sin código para mover la fecha de salida de un tomo, y
 * un guardado suyo dispara correos a clientes. Lo que aquí se fija:
 *
 *   · Un año con un dígito de más (2206) NO se guarda ni avisa a nadie.
 *   · Guardar la misma fecha no escribe ni dispara el evento.
 *   · Vaciar la fecha deja el tomo como preventa SIN fecha (tba), no «a pedido».
 *   · Una fecha válida se guarda por el CRUD de WooCommerce, purga la ficha y
 *     dispara el hook real que escucha el listener.
 *   · Un tomo en la papelera o borrado no se toca.
 *   · La cabecera cuenta TODO lo pendiente aunque la tabla se corte en 60.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;

use Akibara\Business\Tests\Support\NullEtaProvider;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\PreventaItems;
use Akibara\Business\Modules\OrderPreventa\PreventasScreen;
use Akibara\Business\Modules\OrderPreventa\ReadyToShipCron;
use Akibara\Business\Modules\Stock\Availability;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/** `wp_safe_redirect` + `exit`: el redirect corta el flujo como en producción. */
final class PantallaRedirigida extends \RuntimeException {}

/** Tomo con el CRUD que usa la pantalla al guardar. */
final class TomoEnFicha extends WC_Product {

	/** @var array<string, mixed> */
	public array $akb_written = array();

	/** @var list<string> */
	public array $akb_deleted = array();

	public int $akb_saves = 0;

	public function get_name(): string {
		return '[QA] Frieren 06';
	}

	/** @param mixed $value */
	public function update_meta_data( string $key, $value ): void {
		$this->akb_written[ $key ] = $value;
	}

	public function delete_meta_data( string $key ): void {
		$this->akb_deleted[] = $key;
	}

	public function get_meta( string $key ): string {
		return (string) ( $this->akb_written[ $key ] ?? parent::get_meta( $key ) );
	}

	public function get_stock_status(): string {
		return (string) ( $this->akb_written['_stock_status'] ?? parent::get_stock_status() );
	}

	public function save(): int {
		++$this->akb_saves;
		return $this->akb_id;
	}
}

/** Doble de prueba de la pantalla que intercepta `terminate()` para no cortar PHPUnit al probar descargas. */
final class PreventasScreenTestable extends PreventasScreen {
	public bool $terminated = false;

	protected function terminate(): void {
		$this->terminated = true;
	}
}

/** Pedido con los datos de cliente que la tabla imprime. */
final class PedidoQueEspera extends WC_Order {

	public string $akb_phone = '';

	public function get_billing_first_name(): string {
		return 'Cliente';
	}

	public function get_billing_last_name(): string {
		return (string) $this->akb_id;
	}

	public function get_billing_phone(): string {
		return $this->akb_phone;
	}

	public function get_item( $item_id ) {
		foreach ( $this->get_items() as $item ) {
			if ( (int) $item->get_id() === (int) $item_id ) {
				return $item;
			}
		}
		return false;
	}
}

final class PreventasScreenTest extends TestCase {

	private const TOMO = 4242;

	/** @var array<int, WC_Product> */
	private array $productos = array();

	/** @var array<int, WC_Order> */
	private array $pedidos = array();

	/** @var array<int, array<string, mixed>> Meta cruda de línea (`wc_get_order_item_meta`). */
	private array $item_meta = array();

	/** @var list<array{0:string, 1:array<int, mixed>}> */
	private array $fired = array();

	private string $redirect = '';

	private int $nonces = 0;

	/** @var array<string, mixed> */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->productos                  = array();
		$this->pedidos                    = array();
		$this->item_meta                  = array();
		$this->fired                      = array();
		$this->redirect                   = '';
		$this->nonces                     = 0;
		$this->server_backup              = $_SERVER;
		$_GET                             = array();
		$_POST                            = array();
		$_REQUEST                         = array();
		$GLOBALS['akibara_test_options']  = array();
		$GLOBALS['akibara_test_postmeta'] = array();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'check_admin_referer' )->alias(
			function (): bool {
				++$this->nonces;
				return true;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function ( string $message ): void {
				throw new \RuntimeException( 'wp_die: ' . $message );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$args ): string {
				if ( is_array( $args[0] ) ) {
					$url = $args[1] ?? 'https://akibara.test/wp-admin/admin.php';
					return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args[0] );
				}
				$url = $args[2] ?? 'https://akibara.test/wp-admin/admin.php';
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $args[0] . '=' . $args[1];
			}
		);
		Functions\when( 'wp_nonce_url' )->returnArg( 1 );
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $url ): bool {
				$this->redirect = $url;
				throw new PantallaRedirigida();
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( $v ) );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'get_edit_post_link' )->alias( static fn( int $id ): string => 'https://akibara.test/wp-admin/post.php?post=' . $id );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wc_get_order' )->alias( fn( int $id ) => $this->pedidos[ $id ] ?? false );
		Functions\when( 'wc_get_order_item_meta' )->alias( fn( int $item_id, string $key ) => $this->item_meta[ $item_id ][ $key ] ?? '' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $f, ?int $ts = null, $tz = null ): string => gmdate( $f, (int) $ts ) );
		Functions\when( 'wc_get_product' )->alias( fn( int $id ) => $this->productos[ $id ] ?? false );
		Functions\when( 'get_post_meta' )->alias(
			static fn( int $id, string $key = '', bool $single = false ) => $GLOBALS['akibara_test_postmeta'][ $id ][ $key ] ?? ''
		);
		// El paginador traga la excepción de un pedido y la manda al log: aquí
		// el log la relanza para que el test enseñe el fallo real, no un «60».
		Functions\when( 'wc_get_logger' )->justReturn(
			new class() {
				/** @param array<int, mixed> $args */
				public function __call( string $level, array $args ): void {
					throw new \RuntimeException( 'log ' . $level . ': ' . (string) ( $args[0] ?? '' ) );
				}
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( string $hook, ...$args ): void {
				$this->fired[] = array( $hook, $args );
			}
		);
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	protected function tearDown(): void {
		$_SERVER  = $this->server_backup;
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['akibara_test_options'], $GLOBALS['akibara_test_postmeta'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function pantalla(): PreventasScreenTestable {
		return new PreventasScreenTestable( new ReadyToShipCron( new SummaryRecalculator( new NullEtaProvider() ) ) );
	}

	private function tomo( string $status = 'publish', string $fecha_actual = '' ): TomoEnFicha {
		$tomo             = new TomoEnFicha( 'onbackorder' );
		$tomo->akb_id     = self::TOMO;
		$tomo->akb_status = $status;

		$this->productos[ self::TOMO ] = $tomo;
		if ( '' !== $fecha_actual ) {
			$GLOBALS['akibara_test_postmeta'][ self::TOMO ] = array(
				Availability::META_TYPE         => Availability::TYPE_RELEASE_DATE,
				Availability::META_RELEASE_DATE => $fecha_actual,
			);
		}
		return $tomo;
	}

	/** Envía el formulario y devuelve el resultado con el que la pantalla redirige. */
	private function enviar( string $fecha, string $method = 'POST' ): string {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_REQUEST['product_id']    = (string) self::TOMO;
		$_POST['fecha']            = $fecha;

		try {
			$this->pantalla()->handle_fecha();
		} catch ( PantallaRedirigida $expected ) {
			// esperado
		}

		parse_str( (string) parse_url( $this->redirect, PHP_URL_QUERY ), $q );
		return (string) ( $q['akb_preventa'] ?? $q['accion'] ?? '' );
	}

	/** @return list<string> */
	private function hooks(): array {
		return array_column( $this->fired, 0 );
	}

	private function futura( int $dias ): string {
		return gmdate( 'Y-m-d', time() + $dias * 86400 );
	}

	// ───────────────────────────────────────────────────────────── guardado

	/**
	 * @testdox Un enlace viejo por GET vuelve a la vista del panel sin exigir nonce.
	 */
	public function test_a_get_goes_back_to_the_panel_view(): void {
		$this->tomo();

		self::assertSame( 'fecha', $this->enviar( '', 'GET' ) );
		self::assertStringContainsString( 'product_id=' . self::TOMO, $this->redirect );
		self::assertSame( 0, $this->nonces, 'Un nonce caducado haría wp_die antes de poder redirigir.' );
		self::assertSame( 0, $this->productos[ self::TOMO ]->akb_saves );
	}

	/**
	 * @testdox Un año con un dígito de más (2206) no se guarda ni avisa a nadie.
	 */
	public function test_an_impossible_year_is_rejected_without_side_effects(): void {
		$tomo = $this->tomo( 'publish', '' );

		self::assertSame( 'fecha_invalida', $this->enviar( '2206-10-12' ) );
		self::assertSame( 0, $tomo->akb_saves );
		self::assertSame( array(), $this->fired, 'Con el tomo sin fecha previa, el listener lo clasificaba «anunciada» y mandaba el correo sin umbral.' );
		self::assertSame( 1, $this->nonces );
	}

	/**
	 * @testdox Una fecha que ya pasó no se guarda: no anuncia nada ni manda correo.
	 */
	public function test_a_past_date_is_rejected_because_it_announces_nothing(): void {
		$tomo = $this->tomo( 'publish', '' );

		self::assertSame( 'fecha_pasada', $this->enviar( gmdate( 'Y-m-d', time() - 2 * 86400 ) ) );
		self::assertSame( 0, $tomo->akb_saves );
		self::assertSame( array(), $this->fired, 'Antes se guardaba, el aviso prometía correo y nota, y el listener la clasificaba «none» en silencio.' );
	}

	/**
	 * @testdox Guardar la fecha que ya tenía no escribe ni dispara nada.
	 */
	public function test_saving_the_same_date_is_a_no_op(): void {
		$hoy_mas_60 = $this->futura( 60 );
		$tomo       = $this->tomo( 'publish', $hoy_mas_60 );

		self::assertSame( 'fecha', $this->enviar( $hoy_mas_60 ) );
		self::assertSame( 0, $tomo->akb_saves );
		self::assertSame( array(), $this->fired );
	}

	/**
	 * @testdox Vaciar la fecha deja el tomo como preventa SIN fecha, no «a pedido».
	 */
	public function test_clearing_the_date_keeps_it_a_preorder_without_date(): void {
		$anterior = $this->futura( 60 );
		$tomo     = $this->tomo( 'publish', $anterior );

		self::assertSame( 'fecha_retirada', $this->enviar( '' ), 'Retirar la fecha tiene su propio aviso: no sale ningún correo.' );
		self::assertSame( 1, $tomo->akb_saves );
		self::assertSame( Availability::TYPE_TBA, $tomo->akb_written[ Availability::META_TYPE ] );
		self::assertContains( Availability::META_RELEASE_DATE, $tomo->akb_deleted );
		self::assertContains( array( 'akb_eta_release_date_changed', array( self::TOMO, $anterior, '' ) ), $this->fired );
	}

	/**
	 * @testdox Una fecha válida se guarda por el CRUD, purga la ficha y dispara el hook real.
	 */
	public function test_a_valid_date_is_saved_purged_and_announced(): void {
		$anterior = $this->futura( 60 );
		$nueva    = $this->futura( 90 );
		$tomo     = $this->tomo( 'publish', $anterior );

		self::assertSame( 'fecha', $this->enviar( $nueva ) );
		self::assertSame( 1, $tomo->akb_saves, 'Escribir la meta a pelo dejaba el índice del buscador y las cachés con la estimación vieja.' );
		self::assertSame( $nueva, $tomo->akb_written[ Availability::META_RELEASE_DATE ] );
		self::assertSame( Availability::TYPE_RELEASE_DATE, $tomo->akb_written[ Availability::META_TYPE ] );
		self::assertSame(
			array( 'litespeed_purge_post', 'akb_eta_release_date_changed' ),
			$this->hooks(),
			'Primero la purga de la ficha, después el aviso: el cliente no puede leer la estimación vieja con el correo nuevo.'
		);
		self::assertContains( array( 'akb_eta_release_date_changed', array( self::TOMO, $anterior, $nueva ) ), $this->fired );
	}

	/**
	 * @testdox Un tomo en la papelera no se toca.
	 */
	public function test_a_trashed_tomo_is_left_alone(): void {
		$tomo = $this->tomo( 'trash', $this->futura( 60 ) );

		self::assertSame( 'papelera', $this->enviar( $this->futura( 90 ) ) );
		self::assertSame( 0, $tomo->akb_saves );
		self::assertSame( array(), $this->fired );
	}

	/**
	 * @testdox Un tomo borrado del catálogo tampoco.
	 */
	public function test_a_deleted_tomo_is_an_error(): void {
		self::assertSame( 'error', $this->enviar( $this->futura( 90 ) ) );
		self::assertSame( array(), $this->fired );
	}

	/**
	 * Un pedido que espera un tomo, con el snapshot de compra que decide la palabra.
	 *
	 * @param string $tipo `auto` (a pedido) | `release_date` (preventa real).
	 */
	private function pedido_que_espera( int $order_id, int $product_id, string $tipo = Availability::TYPE_AUTO, bool $sin_producto_en_linea = false ): PedidoQueEspera {
		$producto         = $this->productos[ $product_id ] ?? new TomoEnFicha( 'onbackorder' );
		$producto->akb_id = $product_id;
		$this->productos[ $product_id ] = $producto;

		$item                 = new WC_Order_Item_Product();
		$item->akb_id         = $order_id * 10;
		$item->akb_name       = 'Tomo ' . $product_id;
		$item->akb_product_id = $sin_producto_en_linea ? 0 : $product_id;
		$item->akb_product    = $sin_producto_en_linea ? null : $producto;
		$item->akb_meta       = array(
			MetaKeys::ITEM_WAS_PREVENTA     => 'yes',
			MetaKeys::ITEM_TYPE_AT_PURCHASE => $tipo,
		);
		$this->item_meta[ $item->akb_id ] = array( '_product_id' => $product_id );

		$order                   = new PedidoQueEspera( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ) );
		$order->akb_id           = $order_id;
		$order->akb_items        = array( $item );
		$order->akb_date_created = new \DateTimeImmutable( '@' . ( time() - 3 * 86400 ) );
		$this->pedidos[ $order_id ] = $order;
		return $order;
	}

	/** @param list<WC_Order> $activos Lo que devuelve la consulta de pedidos activos. */
	private function consulta_devuelve( array $activos ): void {
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( $activos ): object {
				// La tabla pregunta por los pedidos activos; el bloque de
				// cumplimiento, por «any»: ese universo se deja vacío aquí.
				return (object) array(
					'orders'        => 'any' !== ( $args['status'] ?? '' ) ? $activos : array(),
					'max_num_pages' => 1,
				);
			}
		);
	}

	private function pantalla_html(): string {
		ob_start();
		$this->pantalla()->render_admin_section();
		return (string) ob_get_clean();
	}

	// ───────────────────────────────────────────────────────────── cabecera

	/**
	 * @testdox La cabecera cuenta los 61 tomos pendientes aunque la tabla enseñe 60.
	 */
	public function test_the_header_counts_everything_even_when_the_table_is_cut(): void {
		$pedidos = array();
		for ( $i = 1; $i <= 61; $i++ ) {
			$producto                = new WC_Product( 'onbackorder' );
			$producto->akb_id        = 100 + $i;
			$this->productos[ 100 + $i ] = $producto;

			$item                 = new WC_Order_Item_Product();
			$item->akb_id         = $i;
			$item->akb_name       = 'Tomo ' . $i;
			$item->akb_product_id = 100 + $i;
			$item->akb_product    = $producto;
			$item->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );

			$order                   = new PedidoQueEspera( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ) );
			$order->akb_id           = 5000 + $i;
			$order->akb_items        = array( $item );
			$order->akb_date_created = new \DateTimeImmutable( '@' . ( time() - 3 * 86400 ) );
			$pedidos[]               = $order;
		}
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( $pedidos ): object {
				// La tabla pregunta por los pedidos activos; el bloque de
				// cumplimiento, por «any»: ese universo se deja vacío aquí.
				$activos = 'any' !== ( $args['status'] ?? '' );
				return (object) array(
					'orders'        => $activos ? $pedidos : array(),
					'max_num_pages' => 1,
				);
			}
		);

		ob_start();
		$this->pantalla()->render_admin_section();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<strong>61</strong> tomos pendientes en <strong>61</strong> pedidos.', $html );
		self::assertStringContainsString( '(se muestran los 60 más urgentes)', $html );
		self::assertSame( 60, substr_count( $html, 'data-label="Tomo"' ), 'La tabla se corta en 60; la cabecera no.' );
		// En móvil la celda se rotula con su data-label: tiene que decir lo
		// mismo que la cabecera («Plazo / Lanzamiento»), no «Llega» ni «Sale (editorial)».
		self::assertSame( 60, substr_count( $html, 'data-label="Plazo / Lanzamiento"' ) );
		self::assertStringNotContainsString( 'data-label="Llega"', $html );
		self::assertStringNotContainsString( 'data-label="Sale (editorial)"', $html );

		// Verificación de tarjetas KPI operativas
		self::assertStringContainsString( '📚 Total Requerido', $html );
		self::assertStringContainsString( '61 unidades físicas', $html );
		self::assertStringContainsString( '🗂️ Casilleros de Clientes', $html );
		self::assertStringContainsString( '🏢 Por Editorial', $html );
		self::assertStringContainsString( '🚨 Alertas Operativas', $html );
	}

	/**
	 * @testdox Un tomo borrado del catálogo sigue en la lista gracias a la meta `_product_id` de la línea.
	 */
	public function test_a_deleted_tomo_is_rescued_from_the_line_meta(): void {
		$order = $this->pedido_que_espera( 7001, 4242, Availability::TYPE_AUTO, true );
		unset( $this->productos[4242] ); // ya no existe en el catálogo
		$this->consulta_devuelve( array( $order ) );

		$html = $this->pantalla_html();

		self::assertStringContainsString( 'Tomo 4242', $html, 'Sin el rescate, el pedido que lo esperaba desaparecía de la única pantalla que lo lista.' );
		self::assertStringContainsString( 'Eliminado', $html );
		self::assertStringContainsString( 'ya no está en el catálogo', $html );
	}

	/**
	 * @testdox Un enlace guardado a un tomo en la papelera no pinta el formulario, sino el aviso.
	 */
	public function test_the_form_view_refuses_a_trashed_tomo(): void {
		$this->tomo( 'trash' );
		$this->consulta_devuelve( array() );
		$_GET = array( 'accion' => 'fecha', 'product_id' => (string) self::TOMO );

		$html = $this->pantalla_html();

		self::assertStringContainsString( 'Este tomo está en la papelera', $html );
		self::assertStringNotContainsString( 'name="fecha"', $html, 'El formulario fantasma: se pintaba, se guardaba y salía el correo.' );
	}

	/**
	 * @testdox El formulario dice a quién le llega qué asunto, con los asuntos del propio correo.
	 */
	public function test_the_form_names_the_real_subjects_per_customer(): void {
		$this->tomo( 'publish', '' ); // sin fecha: la primera fecha manda «anunciada»
		$a_pedido = $this->pedido_que_espera( 7001, self::TOMO, Availability::TYPE_AUTO );
		$preventa = $this->pedido_que_espera( 7002, self::TOMO, Availability::TYPE_RELEASE_DATE );
		$this->consulta_devuelve( array( $a_pedido, $preventa ) );
		$_GET = array( 'accion' => 'fecha', 'product_id' => (string) self::TOMO );

		$html = $this->pantalla_html();

		self::assertStringContainsString( 'Lo esperan <strong>2 pedidos</strong>', $html );
		self::assertStringContainsString( 'a 1 con asunto «Tu pedido: la editorial anunció fecha de salida» (para ese cliente es un cambio de fecha)', $html, 'Al cliente a pedido (el caso normal) no le llega «Ya tenemos fecha».' );
		// La línea de preventa de este fixture se compró CON fecha (sin
		// `ITEM_IS_TBA_AT_PURCHASE`): para ese cliente «ya tenemos fecha» es un
		// cambio, y la pantalla cita el asunto que el correo va a mandar de verdad.
		self::assertStringContainsString( 'a 1 con asunto «Actualización de tu preventa: nueva fecha» (compraron con fecha: para ellos es un cambio)', $html );
		self::assertStringNotContainsString( '¡Ya tenemos fecha para tu preventa!', $html, 'Ese asunto sólo sale si la línea se compró sin fecha.' );
		self::assertStringContainsString( 'name="fecha"', $html );
		self::assertStringContainsString( 'min="' . gmdate( 'Y-m-d' ) . '"', $html, 'El calendario no ofrece fechas pasadas.' );
	}

	/**
	 * @testdox Con fecha, el formulario avisa que borrarla NO manda correo.
	 */
	public function test_the_form_says_that_clearing_sends_no_email(): void {
		$this->tomo( 'publish', $this->futura( 60 ) );
		$this->consulta_devuelve( array( $this->pedido_que_espera( 7001, self::TOMO ) ) );
		$_GET = array( 'accion' => 'fecha', 'product_id' => (string) self::TOMO );

		self::assertStringContainsString( 'Si borras la fecha <strong>no sale ningún correo</strong>', $this->pantalla_html() );
	}

	/**
	 * @testdox El formulario del tomo 61 (fuera de la tabla) sigue viendo sus pedidos.
	 */
	public function test_the_form_of_a_tomo_beyond_the_table_cut_sees_its_orders(): void {
		$pedidos = array();
		for ( $i = 1; $i <= 61; $i++ ) {
			$pedidos[] = $this->pedido_que_espera( 5000 + $i, 100 + $i );
		}
		$this->consulta_devuelve( $pedidos );
		$_GET = array( 'accion' => 'fecha', 'product_id' => '161' );

		$html = $this->pantalla_html();

		self::assertStringContainsString( 'Lo esperan <strong>1 pedido</strong>', $html, 'pedidos_de() leía la lista cortada a 60 y prometía «no manda ningún correo».' );
		self::assertStringNotContainsString( 'no hay ningún pedido esperando este tomo', $html );
	}

	// ───────────────────────────────────────────────────────────── centro operativo: recepción y casilleros

	/**
	 * @testdox Recepción exacta asigna por FIFO, sella las líneas, marca pedidos listos y no publica excedente.
	 */
	public function test_recepcion_exacta_asigna_unidades_por_fifo_y_marca_pedidos_listos(): void {
		$tomo = $this->tomo( 'publish', '' );

		$order1                   = $this->pedido_que_espera( 7001, self::TOMO );
		$order1->akb_date_created = new \DateTimeImmutable( '@' . ( time() - 5 * 86400 ) );
		$order2                   = $this->pedido_que_espera( 7002, self::TOMO );
		$order2->akb_date_created = new \DateTimeImmutable( '@' . ( time() - 3 * 86400 ) );

		$this->consulta_devuelve( array( $order1, $order2 ) );

		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_REQUEST['product_id']      = (string) self::TOMO;
		$_POST['unidades_recibidas'] = '2';

		try {
			$this->pantalla()->handle_recibir();
		} catch ( PantallaRedirigida $expected ) {
			// esperado
		}

		$item1 = $order1->get_items()[0];
		$item2 = $order2->get_items()[0];

		self::assertTrue( PreventaItems::is_item_sealed( $order1, $item1 ), 'La línea del pedido 1 debe quedar sellada.' );
		self::assertTrue( PreventaItems::is_item_sealed( $order2, $item2 ), 'La línea del pedido 2 debe quedar sellada.' );
		self::assertSame( 'yes', (string) $order1->get_meta( MetaKeys::ORDER_READY_TO_SHIP ), 'El pedido 1 debe quedar listo para despachar.' );
		self::assertSame( 'yes', (string) $order2->get_meta( MetaKeys::ORDER_READY_TO_SHIP ), 'El pedido 2 debe quedar listo para despachar.' );

		parse_str( (string) parse_url( $this->redirect, PHP_URL_QUERY ), $q );
		self::assertSame( 'recibido', $q['akb_preventa'] ?? '' );
		self::assertSame( '2', $q['apartadas'] ?? '' );
		self::assertSame( '2', $q['listos'] ?? '' );
		self::assertSame( '0', $q['excedente'] ?? '' );
		self::assertEmpty( $tomo->akb_written['_stock_status'] ?? '' );
	}

	/**
	 * @testdox Recepción parcial asigna por FIFO al pedido más antiguo; el segundo sigue pendiente sin falsos avisos.
	 */
	public function test_recepcion_parcial_asigna_por_fifo_al_mas_antiguo_sin_falsos_listos_en_el_segundo(): void {
		$tomo = $this->tomo( 'publish', '' );

		$order1                   = $this->pedido_que_espera( 7001, self::TOMO );
		$order1->akb_date_created = new \DateTimeImmutable( '@' . ( time() - 5 * 86400 ) );
		$order2                   = $this->pedido_que_espera( 7002, self::TOMO );
		$order2->akb_date_created = new \DateTimeImmutable( '@' . ( time() - 3 * 86400 ) );

		$this->consulta_devuelve( array( $order1, $order2 ) );

		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_REQUEST['product_id']      = (string) self::TOMO;
		$_POST['unidades_recibidas'] = '1';

		try {
			$this->pantalla()->handle_recibir();
		} catch ( PantallaRedirigida $expected ) {
			// esperado
		}

		$item1 = $order1->get_items()[0];
		$item2 = $order2->get_items()[0];

		// Pedido 1 (primero en FIFO): sellado y listo.
		self::assertTrue( PreventaItems::is_item_sealed( $order1, $item1 ) );
		self::assertSame( 'yes', (string) $order1->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) );

		// Pedido 2: sigue pendiente, sin sellar y sin marcar como listo.
		self::assertFalse( PreventaItems::is_item_sealed( $order2, $item2 ) );
		self::assertTrue( PreventaItems::is_pending_item( $order2, $item2 ) );
		self::assertNotSame( 'yes', (string) $order2->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) );

		parse_str( (string) parse_url( $this->redirect, PHP_URL_QUERY ), $q );
		self::assertSame( 'recibido', $q['akb_preventa'] ?? '' );
		self::assertSame( '1', $q['apartadas'] ?? '' );
		self::assertSame( '1', $q['listos'] ?? '' );
		self::assertSame( '0', $q['excedente'] ?? '' );
	}

	/**
	 * @testdox Recepción con excedente aparta en los pedidos y publica el remanente en stock del catálogo eliminando el 5% de descuento.
	 */
	public function test_recepcion_con_excedente_aparta_en_pedidos_y_publica_sobrante_en_tienda(): void {
		$tomo                     = $this->tomo( 'publish', '' );
		$tomo->akb_regular_price  = '15000';
		$GLOBALS['akibara_test_postmeta'][ self::TOMO ]['_regular_price'] = '15000';

		$order1 = $this->pedido_que_espera( 7001, self::TOMO );
		$order2 = $this->pedido_que_espera( 7002, self::TOMO );
		$this->consulta_devuelve( array( $order1, $order2 ) );

		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_REQUEST['product_id']        = (string) self::TOMO;
		$_POST['unidades_recibidas']   = '5';
		$_POST['publicar_excedente']   = 'yes';

		try {
			$this->pantalla()->handle_recibir();
		} catch ( PantallaRedirigida $expected ) {
			// esperado
		}

		$item1 = $order1->get_items()[0];
		$item2 = $order2->get_items()[0];

		self::assertTrue( PreventaItems::is_item_sealed( $order1, $item1 ) );
		self::assertTrue( PreventaItems::is_item_sealed( $order2, $item2 ) );

		// Sobrante: 5 recibidas - 2 apartadas = 3 excedente publicado en tienda
		self::assertSame( 'instock', $tomo->akb_written['_stock_status'] );
		self::assertSame( 3, $tomo->akb_written['_stock'] );
		self::assertSame( 'yes', $tomo->akb_written['_manage_stock'] );
		self::assertSame( 'no', $tomo->akb_written['_backorders'] );
		self::assertSame( 15000.0, (float) $tomo->akb_written['_price'] );
		self::assertContains( '_sale_price', $tomo->akb_deleted, 'El descuento del 5% de reserva se debe eliminar al llegar a stock.' );
		self::assertContains( array( 'litespeed_purge_post', array( self::TOMO ) ), $this->fired );

		parse_str( (string) parse_url( $this->redirect, PHP_URL_QUERY ), $q );
		self::assertSame( 'recibido', $q['akb_preventa'] ?? '' );
		self::assertSame( '2', $q['apartadas'] ?? '' );
		self::assertSame( '2', $q['listos'] ?? '' );
		self::assertSame( '3', $q['excedente'] ?? '' );
	}

	/**
	 * @testdox La vista de pedidos agrupa por casillero y handle_apartar_pedido sella los tomos en stock.
	 */
	public function test_vista_pedidos_y_sellado_en_casillero(): void {
		$tomo_en_stock              = new TomoEnFicha( 'instock' );
		$tomo_en_stock->akb_id      = 8001;
		$this->productos[ 8001 ]    = $tomo_en_stock;

		$tomo_pendiente             = new TomoEnFicha( 'onbackorder' );
		$tomo_pendiente->akb_id     = 8002;
		$this->productos[ 8002 ]    = $tomo_pendiente;

		// Pedido mixto con 2 tomos: uno en stock y otro a pedido
		$item1                 = new WC_Order_Item_Product();
		$item1->akb_id         = 901;
		$item1->akb_name       = 'Tomo en Bodega';
		$item1->akb_product_id = 8001;
		$item1->akb_product    = $tomo_en_stock;
		$item1->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );

		$item2                 = new WC_Order_Item_Product();
		$item2->akb_id         = 902;
		$item2->akb_name       = 'Tomo en Espera';
		$item2->akb_product_id = 8002;
		$item2->akb_product    = $tomo_pendiente;
		$item2->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );

		$order                   = new PedidoQueEspera( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ) );
		$order->akb_id           = 7005;
		$order->akb_items        = array( $item1, $item2 );
		$order->akb_date_created = new \DateTimeImmutable( '@' . time() );
		$this->pedidos[ 7005 ]   = $order;
		$this->consulta_devuelve( array( $order ) );

		// 1) Renderizar con vista=pedidos
		$_GET = array( 'vista' => 'pedidos' );
		$html = $this->pantalla_html();
		self::assertStringContainsString( 'Vista por Pedido / Casillero (Consolidación)', $html );
		self::assertStringContainsString( '#7005', $html );
		self::assertStringContainsString( 'Sellar apartado de estos tomos en casillero', $html );
		self::assertStringContainsString( '1 de 2 tomos listos en bodega', $html );

		// 2) Ejecutar handle_apartar_pedido
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['order_id']      = '7005';

		try {
			$this->pantalla()->handle_apartar_pedido();
		} catch ( PantallaRedirigida $expected ) {
			// esperado
		}

		self::assertTrue( PreventaItems::is_item_sealed( $order, $item1 ), 'El tomo en stock debe quedar sellado en el casillero.' );
		self::assertFalse( PreventaItems::is_item_sealed( $order, $item2 ), 'El tomo pendiente aún no ha llegado.' );
		self::assertStringContainsString( 'Apartado en casillero', implode( ' ', $order->akb_notes ) );
		parse_str( (string) parse_url( $this->redirect, PHP_URL_QUERY ), $q );
		self::assertSame( 'apartado', $q['akb_preventa'] ?? '' );
		self::assertSame( 'pedidos', $q['vista'] ?? '' );
	}

	/**
	 * @testdox La acción de CSV exporta la planilla con BOM UTF-8 y las columnas requeridas para compras.
	 */
	public function test_descarga_csv_para_proveedor(): void {
		$tomo          = $this->tomo( 'publish', '' );
		$tomo->akb_sku = 'IVR-FRI-06';
		$order         = $this->pedido_que_espera( 7001, self::TOMO );
		$this->consulta_devuelve( array( $order ) );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$pantalla                  = $this->pantalla();

		ob_start();
		$pantalla->handle_csv();
		$csv = (string) ob_get_clean();

		self::assertTrue( $pantalla->terminated );
		self::assertStringContainsString( 'SKU / ISBN', $csv );
		self::assertStringContainsString( 'Unidades Requeridas', $csv );
		self::assertStringContainsString( 'Pedidos Esperando', $csv );
		self::assertStringContainsString( 'Plazo o Lanzamiento', $csv );
		self::assertStringContainsString( 'IVR-FRI-06', $csv );
	}

	/**
	 * @testdox desglose_casillero_orden consolida pedidos mixtos (9 en stock y 6 a pedido = 9 de 15 listos).
	 */
	public function test_desglose_casillero_orden_mixto_15_tomos(): void {
		$items = array();

		// 9 tomos comprados en stock
		for ( $i = 1; $i <= 9; $i++ ) {
			$item                 = new WC_Order_Item_Product();
			$item->akb_id         = 800 + $i;
			$item->akb_name       = 'Solo Leveling ' . sprintf( '%02d', $i );
			$item->akb_product_id = 9000 + $i;
			$item->akb_quantity   = 1;
			$items[]              = $item;
		}

		// 6 tomos comprados a pedido (preventa)
		for ( $i = 10; $i <= 15; $i++ ) {
			$item                 = new WC_Order_Item_Product();
			$item->akb_id         = 800 + $i;
			$item->akb_name       = 'Solo Leveling ' . sprintf( '%02d', $i );
			$item->akb_product_id = 9000 + $i;
			$item->akb_quantity   = 1;
			$item->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
			$items[]              = $item;
		}

		$order                   = new PedidoQueEspera( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ) );
		$order->akb_id           = 28190;
		$order->akb_items        = $items;
		$order->akb_date_created = new \DateTimeImmutable( '@' . time() );

		// 1) Desglose antes de sellar
		$desglose = PreventasScreen::desglose_casillero_orden( $order );
		self::assertSame( 15, $desglose['total_count'], 'Total de tomos debe ser 15' );
		self::assertSame( 9, $desglose['listos_count'], '9 tomos en stock deben estar listos en bodega' );
		self::assertSame( 6, $desglose['faltan_count'], 'Faltan 6 tomos por llegar' );
		self::assertTrue( $desglose['hay_sin_sellar'], 'Los 9 tomos en stock están sin sellar aún' );

		// 2) Sellar pedido mixto
		$this->pedidos[ 28190 ] = $order;
		$this->consulta_devuelve( array( $order ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['order_id']      = '28190';

		try {
			$this->pantalla()->handle_apartar_pedido();
		} catch ( PantallaRedirigida $expected ) {
			// esperado
		}

		// Los 9 tomos en stock deben quedar sellados
		for ( $i = 0; $i < 9; $i++ ) {
			self::assertTrue( PreventaItems::is_item_sealed( $order, $items[ $i ] ), 'Item en stock #' . ( $i + 1 ) . ' debe quedar sellado' );
		}
		// Los 6 tomos a pedido no deben quedar sellados aún
		for ( $i = 9; $i < 15; $i++ ) {
			self::assertFalse( PreventaItems::is_item_sealed( $order, $items[ $i ] ), 'Item a pedido #' . ( $i + 1 ) . ' no debe estar sellado' );
		}

		// 3) Desglose tras sellar
		$desglose_tras = PreventasScreen::desglose_casillero_orden( $order );
		self::assertSame( 9, $desglose_tras['listos_count'] );
		self::assertSame( 6, $desglose_tras['faltan_count'] );
		self::assertFalse( $desglose_tras['hay_sin_sellar'], 'Ya no deben quedar tomos sin sellar' );
	}

	/**
	 * @testdox normalizar_telefono_cl y url_whatsapp_pedido generan URLs válidas de WhatsApp para clientes chilenos.
	 */
	public function test_normalizar_telefono_cl_y_url_whatsapp(): void {
		self::assertSame( '56912345678', PreventasScreen::normalizar_telefono_cl( '+56 9 1234 5678' ) );
		self::assertSame( '56912345678', PreventasScreen::normalizar_telefono_cl( '912345678' ) );
		self::assertSame( '56912345678', PreventasScreen::normalizar_telefono_cl( '12345678' ) );
		self::assertSame( '56912345678', PreventasScreen::normalizar_telefono_cl( '56912345678' ) );
		self::assertSame( '', PreventasScreen::normalizar_telefono_cl( '' ) );

		$order            = new PedidoQueEspera();
		$order->akb_id    = 28190;
		$order->akb_phone = '+56 9 8765 4321';

		$wa_url = PreventasScreen::url_whatsapp_pedido( $order );
		self::assertStringContainsString( 'api.whatsapp.com/send', $wa_url );
		self::assertStringContainsString( 'phone=56987654321', $wa_url );
		self::assertStringContainsString( '28190', $wa_url );
	}

	/**
	 * @testdox handle_desapartar_item revierte el sellado físico de la línea y recalcula el pedido.
	 */
	public function test_handle_desapartar_item(): void {
		$item                 = new WC_Order_Item_Product();
		$item->akb_id         = 555;
		$item->akb_name       = 'Tomo Equivocado';
		$item->akb_quantity   = 1;
		$item->akb_meta       = array(
			MetaKeys::ITEM_WAS_PREVENTA => 'yes',
			MetaKeys::ITEM_ARRIVED      => 'yes',
			MetaKeys::ITEM_ARRIVED_QTY  => 1,
			MetaKeys::ITEM_ARRIVED_AT   => time(),
		);

		$order                   = new PedidoQueEspera( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'yes' ) );
		$order->akb_id           = 7088;
		$order->akb_items        = array( $item );
		$this->pedidos[ 7088 ]   = $order;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['order_id']      = '7088';
		$_REQUEST['item_id']       = '555';

		try {
			$this->pantalla()->handle_desapartar_item();
		} catch ( PantallaRedirigida $expected ) {
			// esperado
		}

		self::assertFalse( PreventaItems::is_item_sealed( $order, $item ), 'El item ya no debe figurar sellado' );
		self::assertNotSame( 'yes', $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) );
		self::assertStringContainsString( 'Deshecho apartado', implode( ' ', $order->akb_notes ) );

		parse_str( (string) parse_url( $this->redirect, PHP_URL_QUERY ), $q );
		self::assertSame( 'desapartado', $q['akb_preventa'] ?? '' );
		self::assertSame( 'pedidos', $q['vista'] ?? '' );
	}

	/**
	 * @testdox render_vista_pedidos filtra correctamente por búsqueda de texto (q) y por estado (filtro_pedido).
	 */
	public function test_render_vista_pedidos_filtros_y_busqueda(): void {
		$tomo                   = $this->tomo( 'publish', '' );
		$tomo->akb_id           = 8099;
		$this->productos[ 8099 ] = $tomo;

		$item1                 = new WC_Order_Item_Product();
		$item1->akb_id         = 101;
		$item1->akb_name       = 'Tomo Filtrable A';
		$item1->akb_product_id = 8099;
		$item1->akb_quantity   = 1;
		$item1->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );

		$order1                   = new PedidoQueEspera( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ) );
		$order1->akb_id           = 7001;
		$order1->akb_items        = array( $item1 );
		$order1->akb_date_created = new \DateTimeImmutable( '@' . time() );

		$item2                 = new WC_Order_Item_Product();
		$item2->akb_id         = 102;
		$item2->akb_name       = 'Tomo Filtrable B';
		$item2->akb_product_id = 8099;
		$item2->akb_quantity   = 1;
		$item2->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );

		$order2                   = new PedidoQueEspera( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes', MetaKeys::ORDER_READY_TO_SHIP => 'no' ) );
		$order2->akb_id           = 7002;
		$order2->akb_items        = array( $item2 );
		$order2->akb_date_created = new \DateTimeImmutable( '@' . time() );

		$this->pedidos[ 7001 ] = $order1;
		$this->pedidos[ 7002 ] = $order2;
		$this->consulta_devuelve( array( $order1, $order2 ) );

		// 1) Filtrar por q=7001 -> Debe mostrar #7001 y no #7002
		$_GET = array(
			'vista' => 'pedidos',
			'q'     => '7001',
		);
		$html_q = $this->pantalla_html();
		self::assertStringContainsString( 'Pedido #7001', $html_q );
		self::assertStringNotContainsString( 'Pedido #7002', $html_q );

		// 2) Filtrar por filtro_pedido=listos -> Ninguno está 100% listo, debe mostrar mensaje de no encontrados
		$_GET = array(
			'vista'         => 'pedidos',
			'filtro_pedido' => 'listos',
		);
		$html_listos = $this->pantalla_html();
		self::assertStringContainsString( 'No se encontraron pedidos que coincidan con los filtros aplicados', $html_listos );
	}
}
