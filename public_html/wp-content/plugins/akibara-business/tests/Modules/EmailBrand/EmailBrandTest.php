<?php
/**
 * Contrato ejecutable de `Modules\EmailBrand`: la excepción de contraste del
 * botón y el CTA de los correos de pedido.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EmailBrand;

use Akibara\Business\Modules\EmailBrand\EmailBrand;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class EmailBrandTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_html' )->alias(
			static fn( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Pedido doble: cliente registrado (view-order) o invitado (recibido).
	 *
	 * @param int    $customer_id Cliente detrás del pedido (0 = invitado).
	 * @param string $view        URL de view-order.
	 * @param string $received    URL de la página de recibido.
	 * @return \WC_Order
	 */
	private function order( int $customer_id, string $view, string $received ) {
		return new class( $customer_id, $view, $received ) extends \WC_Order {
			private int $customer_id;
			private string $view;
			private string $received;

			public function __construct( int $customer_id, string $view, string $received ) {
				$this->customer_id = $customer_id;
				$this->view        = $view;
				$this->received    = $received;
			}

			public function get_customer_id() {
				return $this->customer_id;
			}

			public function get_view_order_url() {
				return $this->view;
			}

			public function get_checkout_order_received_url() {
				return $this->received;
			}
		};
	}

	/**
	 * El defecto que cierra: el botón heredaba `-webkit-text-fill-color:#CC0010`
	 * del wrapper y salía rojo sobre rojo (1,23:1) en WebKit. La hoja tiene que
	 * reponer el relleno BLANCO y con `!important`, que es la única forma de
	 * ganarle a la regla `#body_content_inner a` del theme.
	 *
	 * @testdox La hoja de correo repone el relleno blanco del botón con !important.
	 */
	public function test_la_hoja_repone_el_relleno_blanco_del_boton(): void {
		$css = ( new EmailBrand() )->add_button_exception( '#body_content_inner a { color:#CC0010; }' );

		self::assertStringContainsString( '#body_content_inner a { color:#CC0010; }', $css, 'No puede pisar la hoja entrante.' );
		self::assertMatchesRegularExpression(
			'/\.akb-email-btn[^{]*\{[^}]*-webkit-text-fill-color:\s*#ffffff\s*!important/i',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.akb-email-btn[^{]*\{[^}]*color:\s*#ffffff\s*!important/i',
			$css
		);
	}

	/**
	 * @testdox El correo de pedido al cliente gana un botón con la URL absoluta de view-order.
	 */
	public function test_el_correo_al_cliente_gana_el_boton_con_url_absoluta(): void {
		$order = $this->order( 42, 'https://akibara.cl/mi-cuenta/pedido/1001/', 'https://akibara.cl/recibido/1001/' );

		ob_start();
		( new EmailBrand() )->render_order_cta( $order, false, false, null );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'https://akibara.cl/mi-cuenta/pedido/1001/', $html );
		self::assertStringContainsString( 'Ver mi pedido', $html );
		self::assertStringContainsString( 'akb-email-btn', $html );
		self::assertStringContainsString( '-webkit-text-fill-color:#ffffff', $html );
	}

	/**
	 * El admin ya tiene su enlace de edición; el botón lo mandaría a la ficha
	 * pública del cliente.
	 *
	 * @testdox El correo al admin NO gana botón.
	 */
	public function test_el_correo_al_admin_no_gana_boton(): void {
		$order = $this->order( 42, 'https://akibara.cl/mi-cuenta/pedido/1001/', 'https://akibara.cl/recibido/1001/' );

		ob_start();
		( new EmailBrand() )->render_order_cta( $order, true, false, null );

		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * `view-order` exige sesión: a un invitado lo dejaría en un muro de login.
	 * La página de recibido la autoriza la `key` del propio pedido.
	 *
	 * @testdox El pedido de invitado apunta a la página de recibido, no a view-order.
	 */
	public function test_el_pedido_de_invitado_apunta_a_recibido(): void {
		$order = $this->order( 0, 'https://akibara.cl/mi-cuenta/pedido/1001/', 'https://akibara.cl/recibido/1001/?key=wc_x' );

		ob_start();
		( new EmailBrand() )->render_order_cta( $order, false, false, null );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'https://akibara.cl/recibido/1001/?key=wc_x', $html );
		self::assertStringNotContainsString( '/mi-cuenta/pedido/', $html );
	}

	/**
	 * En un correo no hay documento base: una ruta relativa no resuelve.
	 *
	 * @testdox Una URL relativa no produce botón.
	 */
	public function test_una_url_relativa_no_produce_boton(): void {
		$order = $this->order( 7, '/mi-cuenta/pedido/1001/', '/recibido/1001/' );

		ob_start();
		( new EmailBrand() )->render_order_cta( $order, false, false, null );

		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * La parte plana del multipart tiene que llevar el MISMO destino que el
	 * HTML: si divergen, el cliente en modo texto va a otro sitio.
	 *
	 * @testdox En texto plano el botón se degrada a etiqueta + URL, con el mismo destino.
	 */
	public function test_en_plano_el_boton_es_etiqueta_y_url(): void {
		$order = $this->order( 42, 'https://akibara.cl/mi-cuenta/pedido/1001/', 'https://akibara.cl/recibido/1001/' );

		ob_start();
		( new EmailBrand() )->render_order_cta( $order, false, true, null );
		$plano = (string) ob_get_clean();

		self::assertStringContainsString( 'Ver mi pedido:', $plano );
		self::assertStringContainsString( 'https://akibara.cl/mi-cuenta/pedido/1001/', $plano );
		self::assertStringNotContainsString( '<a', $plano );
	}

	/**
	 * R11-portada-avif-en-correos (2026-09-20): los ocho correos de pedido
	 * pintaban la portada como recorte 100x100 en AVIF, que Gmail no muestra.
	 *
	 * @testdox La portada de un artículo del correo pasa a su hermana JPG, con el alto real y sin srcset; fuera del correo no se toca.
	 */
	public function test_la_portada_del_articulo_viaja_en_jpeg_en_los_correos(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			$root = sys_get_temp_dir() . '/akb-emailbrand-' . uniqid();
			mkdir( $root . '/uploads/portadas', 0777, true );
			define( 'WP_CONTENT_DIR', $root );
		}
		$dir = WP_CONTENT_DIR . '/uploads/portadas';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$jpg    = $dir . '/kaiju-8-og.jpg';
		$lienzo = imagecreatetruecolor( 40, 60 );
		imagejpeg( $lienzo, $jpg );
		imagedestroy( $lienzo );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_getimagesize' )->alias( static fn( string $ruta ) => @getimagesize( $ruta ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$acciones = array( 'woocommerce_email_header' => 1, 'woocommerce_email_footer' => 0 );
		Functions\when( 'did_action' )->alias( static fn( string $hook ) => $acciones[ $hook ] ?? 0 );

		$img = '<img width="100" height="100" src="https://akibara.cl/wp-content/uploads/portadas/kaiju-8-100x100.avif" srcset="https://akibara.cl/wp-content/uploads/portadas/kaiju-8-100x100.avif 1x" sizes="48px" alt="Portada" style="font-size: 14px;">';
		$sut = new EmailBrand();
		try {
			$out = $sut->jpeg_cover_for_email( $img );
			self::assertStringContainsString( 'src="https://akibara.cl/wp-content/uploads/portadas/kaiju-8-og.jpg"', $out );
			self::assertStringContainsString( 'width="100" height="150"', $out, 'El alto se recalcula desde la hermana (40×60 → 100×150): la portada no se deforma. WordPress escribe las medidas del intermedio resuelto (100x100), no las pedidas (48).' );
			self::assertStringNotContainsString( 'srcset=', $out );
			self::assertStringNotContainsString( 'sizes=', $out );
			self::assertStringContainsString( 'alt="Portada"', $out );

			// Sin hermana posible (ni AVIF ni JPG), la etiqueta se deja tal cual.
			$ajena = str_replace( 'kaiju-8', 'sin-hermana', $img );
			self::assertSame( $ajena, $sut->jpeg_cover_for_email( $ajena ) );

			// Fuera de un correo (el preview de admin; defensa en profundidad), no se toca.
			$acciones = array( 'woocommerce_email_header' => 1, 'woocommerce_email_footer' => 1 );
			Functions\when( 'did_action' )->alias( static fn( string $hook ) => $acciones[ $hook ] ?? 0 );
			self::assertSame( $img, $sut->jpeg_cover_for_email( $img ) );
		} finally {
			@unlink( $jpg ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * @testdox protect_gmail_dark_mode envuelve <h1>, botones y .akb-vol con capas mix-blend-mode.
	 */
	public function test_protect_gmail_dark_mode_envelops_heading_buttons_and_volumes(): void {
		$raw_html = '<!DOCTYPE html><html><head><title>Test</title></head><body>'
			. '<table><tr><td id="header_wrapper"><h1>Pedido cancelado: n.º 28560</h1></td></tr></table>'
			. '<p><span class="akb-title-name">One Piece</span> <span class="akb-vol">051</span></p>'
			. '<p><a class="akb-email-btn" href="https://akibara.cl/pedido/">Ver mi pedido</a></p>'
			. '<table id="template_footer"><tr><td><a href="https://akibara.cl">Inicio</a></td></tr></table>'
			. '</body></html>';

		$sut = new EmailBrand();
		$processed = $sut->protect_gmail_dark_mode( $raw_html );

		self::assertStringContainsString( 'u + .body .akb-blend-screen', $processed );
		self::assertStringContainsString( 'u + .body .akb-blend-diff', $processed );
		self::assertStringContainsString( '<body class="body">', $processed );
		self::assertStringContainsString( '<span class="akb-blend-screen"><span class="akb-blend-diff">Pedido cancelado: n.º 28560</span></span>', $processed );
		self::assertStringContainsString( '<span class="akb-vol"><span class="akb-blend-screen"><span class="akb-blend-diff">051</span></span></span>', $processed );
		self::assertStringContainsString( '<a class="akb-email-btn" href="https://akibara.cl/pedido/"><span class="akb-blend-screen"><span class="akb-blend-diff">Ver mi pedido</span></span></a>', $processed );
		self::assertStringContainsString( '<a href="https://akibara.cl"><span class="akb-blend-screen"><span class="akb-blend-diff">Inicio</span></span></a>', $processed );
	}
}
