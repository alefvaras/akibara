<?php
/**
 * Test de contrato ejecutable para EmailTemplateGateway.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Services\EmailTemplateGateway;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Akibara\Business\Services\EmailTemplateGateway
 */
final class EmailTemplateGatewayTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		\WC_Email::$akb_last_send = array();

		Functions\when( 'esc_html' )->alias(
			static fn( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'wp_date' )->alias( static fn( $format ) => date( $format ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox html_to_plain convierte enlaces HTML a texto plano con URL visible.
	 */
	public function test_html_to_plain_preserves_link_urls(): void {
		$html  = '<p>Hola, visita nuestro <a href="https://akibara.cl/tienda/">Catálogo</a> para ver ofertas.</p>';
		$plain = EmailTemplateGateway::html_to_plain( $html );

		self::assertStringContainsString( 'Catálogo: https://akibara.cl/tienda/', $plain );
		self::assertStringNotContainsString( '<p>', $plain );
		self::assertStringNotContainsString( '</a>', $plain );
	}

	/**
	 * @testdox html_to_plain ignora etiquetas de estilo y scripts.
	 */
	public function test_html_to_plain_strips_styles_and_scripts(): void {
		$html  = '<style>body { color: red; }</style><script>alert(1);</script><p>Contenido real.</p>';
		$plain = EmailTemplateGateway::html_to_plain( $html );

		self::assertSame( 'Contenido real.', $plain );
	}

	/**
	 * @testdox html_to_plain limpia los caracteres invisibles del preheader y colapsa tabulaciones.
	 */
	public function test_html_to_plain_strips_invisible_preheader_chars_and_tabs(): void {
		$html  = "<div>Preheader &#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>\n\t\t<p>Línea limpia</p>";
		$plain = EmailTemplateGateway::html_to_plain( $html );

		self::assertSame( "Preheader\n\nLínea limpia", $plain );
	}

	/**
	 * @testdox send rechaza destinatarios vacíos.
	 */
	public function test_send_rejects_empty_recipient(): void {
		$result = EmailTemplateGateway::send( '', 'Asunto', 'Encabezado', '<p>Test</p>' );
		self::assertFalse( $result );
	}

	/**
	 * @testdox fallback_html produce un correo con la tríada brutalista Akibara si no hay WooCommerce.
	 */
	public function test_fallback_renders_brand_tokens(): void {
		$rendered = EmailTemplateGateway::render( 'Aviso urgente', '<p>Cuerpo del correo</p>' );

		self::assertStringContainsString( '#0d0d0d', $rendered );
		self::assertStringContainsString( '#E60012', $rendered );
		self::assertStringContainsString( 'Aviso urgente', $rendered );
		self::assertStringContainsString( 'Cuerpo del correo', $rendered );
		self::assertStringContainsString( 'Tu rincón de Akihabara en Chile', $rendered );
	}

	/**
	 * @testdox render ensambla header, cuerpo y footer en orden sin llamar a apply_filters.
	 */
	public function test_render_assembles_header_body_footer_without_apply_filters(): void {
		Functions\when( 'wc_get_template' )->alias( static function ( $template, $args = array() ) {
			if ( 'emails/email-header.php' === $template ) {
				echo '<div id="template_header"><h1>' . ( $args['email_heading'] ?? '' ) . '</h1></div>';
			} elseif ( 'emails/email-footer.php' === $template ) {
				echo '<table id="template_footer"><tr><td>Pie</td></tr></table>';
			}
		} );
		Functions\expect( 'apply_filters' )->never();

		$rendered = EmailTemplateGateway::render( '¡Llegó tu tomo!', '<p class="msg">One Piece 105</p>' );

		$header_pos = strpos( $rendered, '<div id="template_header"><h1>¡Llegó tu tomo!</h1></div>' );
		$body_pos   = strpos( $rendered, '<p class="msg">One Piece 105</p>' );
		$footer_pos = strpos( $rendered, '<table id="template_footer">' );

		self::assertNotFalse( $header_pos );
		self::assertNotFalse( $body_pos );
		self::assertNotFalse( $footer_pos );
		self::assertTrue( $header_pos < $body_pos );
		self::assertTrue( $body_pos < $footer_pos );
	}

	/**
	 * @testdox send entrega a través de GatewayEmail cuando WC_Email está disponible.
	 */
	public function test_send_delivers_through_gateway_email(): void {
		Functions\when( 'wc_get_template' )->alias( static function ( $template, $args = array() ) {
			if ( 'emails/email-header.php' === $template ) {
				echo '<div id="template_header"><h1>' . ( $args['email_heading'] ?? '' ) . '</h1></div>';
			} elseif ( 'emails/email-footer.php' === $template ) {
				echo '<table id="template_footer"><tr><td>Pie</td></tr></table>';
			}
		} );

		Functions\expect( 'wp_mail' )->never();

		$sent = EmailTemplateGateway::send(
			'cliente@ejemplo.com',
			'Recordatorio de transferencia',
			'Paga tu pedido',
			'<p>Detalles del pedido #1234</p>',
			array(
				'key'     => 'bacs_reminder',
				'headers' => array( 'List-Unsubscribe: <https://akibara.cl/baja>' ),
			)
		);

		self::assertTrue( $sent );
		self::assertSame( 'akb_bacs_reminder', \WC_Email::$akb_last_send['id'] );
		self::assertSame( 'cliente@ejemplo.com', \WC_Email::$akb_last_send['to'] );
		self::assertSame( 'Recordatorio de transferencia', \WC_Email::$akb_last_send['subject'] );
		self::assertStringContainsString( 'template_footer', \WC_Email::$akb_last_send['message'] );
		self::assertStringContainsString( '<p>Detalles del pedido #1234</p>', \WC_Email::$akb_last_send['message'] );
		self::assertSame( "List-Unsubscribe: <https://akibara.cl/baja>\r\n", \WC_Email::$akb_last_send['headers'] );
	}

	/**
	 * @testdox send entrega vía GatewayEmail::deliver() y nunca agrega hooks a phpmailer_init.
	 */
	public function test_send_never_hooks_phpmailer_init(): void {
		Functions\when( 'wc_get_template' )->justReturn();
		Actions\expectAdded( 'phpmailer_init' )->never();

		$sent = EmailTemplateGateway::send(
			'cliente@ejemplo.com',
			'Asunto',
			'Encabezado',
			'<p>Contenido</p>'
		);

		self::assertTrue( $sent );
	}
}
