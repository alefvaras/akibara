<?php
/**
 * Un clon del sitio no puede hablar con la cuenta real de Clarity.
 *
 * Hallazgo S-62b187e9: el guardián sólo miraba `WP_ENVIRONMENT_TYPE`, que vive
 * en `wp-config.php` y viaja con cualquier copia. Un staging de Hostinger o un
 * backup restaurado en otro dominio hereda `production` y se pone a mandar
 * sesiones a las métricas del dueño. Es el mismo modo de fallo que ya cerró el
 * cepo del correo (S-6625d798): lo único que NO viaja con la copia es el
 * dominio.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Akibara\HostingerMail\Transport;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ClaritySoloEnLaTiendaDeVerdadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( Transport::class, false ) ) {
			require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-correo-smtp.php';
		}
		if ( ! function_exists( 'akibara_clarity_es_la_tienda_de_verdad' ) ) {
			require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-clarity-production-only.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Sólo el dominio real Y el entorno production dejan pasar el tag.
	 * @dataProvider sitios
	 */
	public function test_solo_la_tienda_de_verdad( string $entorno, string $home, bool $esperado, string $porque ): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( $entorno );
		Functions\when( 'home_url' )->justReturn( $home );

		self::assertSame( $esperado, akibara_clarity_es_la_tienda_de_verdad(), $porque );
	}

	/** @return array<string, array{0:string,1:string,2:bool,3:string}> */
	public static function sitios(): array {
		return array(
			'la tienda'            => array( 'production', 'https://akibara.cl', true, 'La tienda real sí mide.' ),
			'clon con production'  => array( 'production', 'https://staging.hostingersite.com', false, 'Un staging hereda el wp-config entero, incluido el entorno.' ),
			'espejo local'         => array( 'local', 'http://localhost:8080', false, 'El QA en Docker ensuciaba páginas populares y embudo.' ),
			'dominio real en local'=> array( 'local', 'https://akibara.cl', false, 'Las dos condiciones, no una.' ),
		);
	}
}
