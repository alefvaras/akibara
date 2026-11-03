<?php
/**
 * LiteSpeed puede diferir el JS, pero no RETRASARLO hasta la primera interacción.
 *
 * Con «JS Delayed» (`optm-js_defer = 2`) lo que el tema y WooCommerce hacen al
 * cargar deja de ocurrir: el contador del carrito del header, impreso en HTML
 * cacheado, se queda con el número de la copia pública hasta que el cliente
 * toque algo. Los scripts de WooCommerce dependen de jQuery y no se pueden
 * excluir uno a uno, así que la baranda fija el techo en «Deferred» (revisión
 * independiente de R3-2c3c4720, 2026-09-19).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class LitespeedNoRetrasaElJsTest extends TestCase {

	private const FICHERO = '/mu-plugins/akibara-litespeed-guardrails.php';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// El fichero registra sus filtros al cargarse; la guarda evita redeclarar
		// sus funciones cuando otro test de la suite ya lo cargó.
		if ( ! function_exists( 'akibara_litespeed_js_sin_retraso' ) ) {
			require_once dirname( __DIR__, 4 ) . self::FICHERO;
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox «Delayed» se sirve como «Deferred»; apagado y «Deferred» se respetan.
	 * @dataProvider valores
	 *
	 * @param mixed $guardado Lo que dice la opción.
	 */
	public function test_el_techo_es_deferred( $guardado, int $servido ): void {
		self::assertSame( $servido, \akibara_litespeed_js_sin_retraso( $guardado ) );
	}

	/** @return array<string, array{0:mixed,1:int}> */
	public static function valores(): array {
		return array(
			'apagado'                      => array( 0, 0 ),
			'deferred (producción hoy)'    => array( 1, 1 ),
			'delayed'                      => array( 2, 1 ),
			'delayed guardado como texto'  => array( '2', 1 ),
			'casilla booleana de fábrica'  => array( false, 0 ),
			'un valor que no existe'       => array( 7, 1 ),
			'basura'                       => array( 'delayed', 0 ),
		);
	}

	/** @testdox La baranda cuelga del filtro de LECTURA de esa opción, como las demás. */
	public function test_se_engancha_al_filtro_de_lectura(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 4 ) . self::FICHERO );

		self::assertMatchesRegularExpression(
			"/add_filter\\(\\s*'litespeed_conf_load_option_optm-js_defer',\\s*'akibara_litespeed_js_sin_retraso'/",
			$fuente,
			'El id lleva GUION (`optm-js_defer`): con guion bajo el filtro no dispara nunca.'
		);
	}
}
