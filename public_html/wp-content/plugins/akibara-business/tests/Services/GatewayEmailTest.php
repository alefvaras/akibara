<?php
/**
 * Test de contrato ejecutable para GatewayEmail.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Services\GatewayEmail;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Akibara\Business\Services\GatewayEmail
 */
final class GatewayEmailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\WC_Email::$akb_last_send           = array();
		\WC_Email::$akb_fail_send_exception = null;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox for() devuelve la misma instancia en llamadas repetidas con la misma clave.
	 */
	public function test_for_returns_singleton_instance_per_key(): void {
		$instance1 = GatewayEmail::for( 'bacs_reminder' );
		$instance2 = GatewayEmail::for( 'bacs_reminder' );

		self::assertSame( $instance1, $instance2 );
		self::assertSame( 'akb_bacs_reminder', $instance1->id );
	}

	/**
	 * @testdox for() con clave vacía produce una instancia con id akb_generico.
	 */
	public function test_for_empty_key_defaults_to_akb_generico(): void {
		$email = GatewayEmail::for( '' );

		self::assertSame( 'akb_generico', $email->id );
	}

	/**
	 * @testdox email_type se establece en multipart para permitir que handle_multipart adjunte AltBody.
	 */
	public function test_email_type_is_multipart(): void {
		$email = GatewayEmail::for( 'bis_subscription' );

		self::assertSame( 'multipart', $email->email_type );
	}

	/**
	 * @testdox deliver() llama a send() exponiendo get_content_plain() y limpiando estado en finally.
	 */
	public function test_deliver_exposes_plain_content_during_send_and_cleans_up(): void {
		$email = GatewayEmail::for( 'delivery_check' );

		$sent = $email->deliver(
			'dest@ejemplo.com',
			'Asunto de entrega',
			'<p>HTML</p>',
			'Texto plano para AltBody',
			"X-Custom: 123\r\n"
		);

		self::assertTrue( $sent );
		self::assertSame( 'akb_delivery_check', \WC_Email::$akb_last_send['id'] );
		self::assertSame( 'dest@ejemplo.com', \WC_Email::$akb_last_send['to'] );
		self::assertSame( 'Asunto de entrega', \WC_Email::$akb_last_send['subject'] );
		self::assertSame( '<p>HTML</p>', \WC_Email::$akb_last_send['message'] );
		self::assertSame( "X-Custom: 123\r\n", \WC_Email::$akb_last_send['headers'] );
		self::assertTrue( \WC_Email::$akb_last_send['sending'], 'sending debe estar encendido durante send().' );
		self::assertSame( 'Texto plano para AltBody', \WC_Email::$akb_last_send['content_plain'] );

		self::assertFalse( $email->sending, 'sending debe quedar apagado tras deliver().' );
		self::assertSame( '', $email->get_content_plain(), 'get_content_plain() debe quedar vacío tras deliver().' );
	}

	/**
	 * @testdox deliver() limpia sending y get_content_plain() incluso si send() lanza una excepción.
	 */
	public function test_deliver_cleans_up_state_even_on_exception(): void {
		$email = GatewayEmail::for( 'exception_check' );

		\WC_Email::$akb_fail_send_exception = new \RuntimeException( 'Simulated transport error' );

		try {
			$email->deliver(
				'dest@ejemplo.com',
				'Asunto con fallo',
				'<p>HTML</p>',
				'Texto plano'
			);
			self::fail( 'deliver() debió propagar la excepción de send().' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Simulated transport error', $e->getMessage() );
		}

		self::assertFalse( $email->sending, 'sending debe limpiarse en finally tras excepción.' );
		self::assertSame( '', $email->get_content_plain(), 'get_content_plain() debe limpiarse en finally tras excepción.' );
	}

	/**
	 * @testdox get_content_html() devuelve cadena vacía porque el cuerpo llega pre-renderizado.
	 */
	public function test_get_content_html_returns_empty_string(): void {
		$email = GatewayEmail::for( 'custom_notice' );

		self::assertSame( '', $email->get_content_html() );
	}

	/**
	 * @testdox init_form_fields() inicializa form_fields como array vacío.
	 */
	public function test_init_form_fields_sets_empty_array(): void {
		$email = GatewayEmail::for( 'no_settings' );
		$email->init_form_fields();

		self::assertSame( array(), $email->form_fields );
	}
}
