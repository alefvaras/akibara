<?php
/**
 * El mu-plugin `akibara-mercadopago-nota-sin-correo.php` dentro de la suite
 * (R7-nota-mercado-pago, decisión del dueño 2026-09-20). El candado sin
 * WordPress vive en `tools/test-mercadopago-nota-sin-correo.php`; este wrapper
 * existe para que el invariante corra en cada corrida de la suite y no sólo
 * cuando alguien se acuerda.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class MercadoPagoNotaSinCorreoTest extends TestCase {

	private const MU_PLUGIN = '/mu-plugins/akibara-mercadopago-nota-sin-correo.php';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
		Monkey\setUp();
		require_once dirname( __DIR__, 4 ) . self::MU_PLUGIN;
		Monkey\tearDown();
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function correo( ?string $nota ): object {
		$email = new \stdClass();
		if ( null !== $nota ) {
			$email->customer_note = $nota;
		}
		return $email;
	}

	/** @testdox Sin Mercado Pago cargado no engancha nada; con él, engancha el filtro con tres argumentos */
	public function test_muere_con_el_tercero(): void {
		if ( defined( 'MP_PLUGIN_FILE' ) ) {
			self::markTestSkipped( 'MP_PLUGIN_FILE ya definido por otra prueba.' );
		}
		akibara_mp_nota_sin_correo_enganchar();
		self::assertFalse( has_filter( 'woocommerce_email_enabled_customer_note', 'akibara_mp_nota_sin_correo_filtrar' ) );

		define( 'MP_PLUGIN_FILE', '/plugins/woocommerce-mercadopago/woocommerce-mercadopago.php' );
		akibara_mp_nota_sin_correo_enganchar();
		self::assertSame( 10, has_filter( 'woocommerce_email_enabled_customer_note', 'akibara_mp_nota_sin_correo_filtrar' ) );
	}

	/** @testdox Las notas de Mercado Pago (Chile y la HTML de Pix) no salen por correo; las del dueño sí */
	public function test_que_correos_se_callan(): void {
		self::assertFalse( akibara_mp_nota_sin_correo_filtrar( true, null, $this->correo( 'Mercado Pago: Pago aprobado.' ) ) );
		self::assertFalse( akibara_mp_nota_sin_correo_filtrar( true, null, $this->correo( "\n  <div style='text-align: justify;'><p>Mercado Pago: Now you just need to pay with Pix to finalize your purchase.</p></div>" ) ) );
		self::assertTrue( akibara_mp_nota_sin_correo_filtrar( true, null, $this->correo( 'Tu pedido sale mañana por Blue Express.' ) ) );
		self::assertTrue( akibara_mp_nota_sin_correo_filtrar( true, null, $this->correo( 'Pagaste con Mercado Pago: gracias.' ) ) );
		self::assertFalse( akibara_mp_nota_sin_correo_filtrar( false, null, $this->correo( 'Tu pedido sale mañana.' ) ) );
		self::assertTrue( akibara_mp_nota_sin_correo_filtrar( true, null, null ), 'Listado de correos del admin: sin objeto, no se toca.' );
		self::assertTrue( akibara_mp_nota_sin_correo_filtrar( true, null, $this->correo( null ) ) );
		self::assertTrue( akibara_mp_nota_sin_correo_filtrar( true, null, (object) array( 'customer_note' => 42 ) ) );
	}
}
