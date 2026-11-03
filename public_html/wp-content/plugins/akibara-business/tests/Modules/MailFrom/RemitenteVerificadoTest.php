<?php
/**
 * TODO el correo del sitio sale del buzón verificado.
 *
 * WordPress arma `From: WordPress <wordpress@akibara.cl>` por su cuenta, y ese
 * buzón no existe: Hostinger rechaza el envío y el cliente no recibe ni la
 * confirmación de su pedido. El módulo lo fuerza, y lo fuerza SIEMPRE —ignora
 * lo que traiga el llamador—, porque un remitente no verificado no es una
 * preferencia estética sino un correo que no llega.
 *
 * El módulo no tenía ninguna prueba: 92 líneas en el camino de todo el correo
 * saliente (ampliación de cobertura, 2026-09-18).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\MailFrom;

use Akibara\Business\Modules\MailFrom\MailFrom;
use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RemitenteVerificadoTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox El remitente que propone WordPress se ignora: manda el verificado. */
	public function test_el_remitente_de_wordpress_se_ignora(): void {
		$sut = new MailFrom();

		self::assertSame( 'contacto@akibara.cl', $sut->filter_from_email( 'wordpress@akibara.cl' ) );
		self::assertSame( 'contacto@akibara.cl', $sut->filter_from_email( 'cualquier-cosa@example.com' ) );
		self::assertSame( 'Akibara', $sut->filter_from_name( 'WordPress' ) );
	}

	/** @testdox El buzón forzado es el mismo que declara el catálogo de accesos y el transporte. */
	public function test_el_buzon_es_el_del_transporte(): void {
		// Si estos dos se separan, el ESP rechaza y nadie se entera hasta que un
		// cliente pregunta por su pedido.
		self::assertSame( 'contacto@akibara.cl', MailFrom::FROM_EMAIL );
		self::assertStringContainsString(
			"'contacto@akibara.cl'",
			(string) file_get_contents( dirname( __DIR__, 5 ) . '/mu-plugins/akibara-correo-smtp.php' ),
			'El mu-plugin del transporte tiene que autenticar con ESE mismo buzón.'
		);
	}

	/** @testdox Queda una perilla por código para el día que cambie el buzón verificado. */
	public function test_se_puede_sobreescribir_por_filtro(): void {
		Filters\expectApplied( 'akibara_mail_from_email' )->once()->andReturn( 'ventas@akibara.cl' );
		Filters\expectApplied( 'akibara_mail_from_name' )->once()->andReturn( 'Akibara Ventas' );

		$sut = new MailFrom();

		self::assertSame( 'ventas@akibara.cl', $sut->filter_from_email( 'wordpress@akibara.cl' ) );
		self::assertSame( 'Akibara Ventas', $sut->filter_from_name( 'WordPress' ) );
	}

	/** @testdox Se engancha tarde (15) para ganarle a cualquier plugin que ponga el suyo. */
	public function test_se_engancha_despues_que_los_demas(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$sut = new MailFrom();

		$sut->register();

		self::assertSame( 15, Filters\has( 'wp_mail_from', array( $sut, 'filter_from_email' ) ) );
		self::assertSame( 15, Filters\has( 'wp_mail_from_name', array( $sut, 'filter_from_name' ) ) );
	}
}
