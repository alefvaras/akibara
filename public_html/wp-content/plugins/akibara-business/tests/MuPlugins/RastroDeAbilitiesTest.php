<?php
/**
 * Queda rastro de quién ejecutó qué ability.
 *
 * Hallazgo S-31b76a50: la API de abilities dispara `wp_before_execute_ability`
 * y `wp_after_execute_ability` y nadie los escuchaba. Hay abilities que cambian
 * las redirecciones del sitio, y no había forma de saber quién las corrió.
 *
 * Lo que este candado fija, además de que el rastro exista: que anote las
 * CLAVES de la entrada y nunca sus valores. Una ability puede recibir un correo
 * del cliente o una clave de proveedor, y un log no es sitio para eso.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RastroDeAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! function_exists( 'akibara_abilities_audit_claves' ) ) {
			require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-abilities-audit.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Del input se anotan las claves, jamás los valores. */
	public function test_no_se_filtran_los_valores(): void {
		$claves = akibara_abilities_audit_claves(
			array(
				'email'  => 'clienta@example.com',
				'api_key' => 'AIzaSyDsecretosecretosecretosecretose',
				'ids'    => array( 1, 2, 3 ),
			)
		);

		self::assertSame( 'api_key,email,ids', $claves );
		self::assertStringNotContainsString( 'example.com', $claves );
		self::assertStringNotContainsString( 'AIza', $claves );
	}

	/** @testdox Sin entrada lo dice, en vez de anotar una lista vacía. */
	public function test_sin_entrada(): void {
		self::assertSame( 'sin entrada', akibara_abilities_audit_claves( array() ) );
		self::assertSame( 'sin entrada', akibara_abilities_audit_claves( null ) );
	}

	/** @testdox El usuario se identifica por id y login; sin sesión también se dice. */
	public function test_quien_ejecuta(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		self::assertSame( 'sin sesión', akibara_abilities_audit_quien() );

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_login' => 'ale' ) );
		self::assertSame( '#7 ale', akibara_abilities_audit_quien() );
	}

	/** @testdox Los dos hooks de la API quedan enganchados. */
	public function test_los_hooks_existen(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 4 ) . '/mu-plugins/akibara-abilities-audit.php' );

		self::assertStringContainsString( "'wp_before_execute_ability'", $src );
		self::assertStringContainsString( "'wp_after_execute_ability'", $src );
	}

	/**
	 * @testdox REAPERTURA S-31b76a50: no se promete un desenlace de error que este gancho no puede escribir.
	 *
	 * `WP_Ability::execute()` del núcleo devuelve el WP_Error ANTES de disparar
	 * `wp_after_execute_ability`, así que ese gancho nunca ve un resultado de
	 * error: una rama `'ERROR ' . $result->get_error_code()` aquí es código
	 * muerto que promete un desenlace que jamás se escribe.
	 */
	public function test_no_se_promete_un_desenlace_que_no_se_escribe(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 4 ) . '/mu-plugins/akibara-abilities-audit.php' );

		self::assertStringNotContainsString( "'ERROR '", $src );
	}
}
