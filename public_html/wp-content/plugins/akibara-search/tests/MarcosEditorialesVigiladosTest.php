<?php
/**
 * Candado de la guarda de marcos editoriales (0.1.64).
 *
 * La suite imprimía `OK (680 tests…)` y salía con rc=255: una prueba dejaba un
 * marco editorial abierto, `release_on_shutdown()` corría al apagar PHP con Brain
 * Monkey desmontado y `AuditLog::record()` moría sin stub de `get_option`. Un rojo
 * que sólo se veía en el código de salida. `MarcosEditorialesVigilados` convierte
 * ese descuido en el fallo de la prueba que lo causa; este fichero fija dos cosas:
 * que la guarda detecta un marco abierto, y que ningún fichero de la suite que
 * construya el runtime editorial se la salta.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\Tests\Support\MarcosEditorialesVigilados;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class MarcosEditorialesVigiladosTest extends TestCase {

	/**
	 * @testdox Todo fichero de prueba que construye el runtime editorial pasa por la guarda.
	 */
	public function test_todo_runtime_editorial_de_la_suite_esta_vigilado(): void {
		$revisados = 0;
		foreach ( (array) glob( __DIR__ . '/*Test.php' ) as $fichero ) {
			if ( realpath( (string) $fichero ) === realpath( __FILE__ ) ) {
				continue; // Este candado nombra el constructor en sus mensajes, no lo usa.
			}
			$fuente = (string) file_get_contents( (string) $fichero );
			$nombre = basename( (string) $fichero );
			$construcciones = preg_match_all( '/new EditorialDiscountPublication\(/', $fuente );
			if ( 0 === $construcciones ) {
				continue;
			}
			++$revisados;
			self::assertSame(
				$construcciones,
				preg_match_all( '/vigilar_publicacion\( new EditorialDiscountPublication\(/', $fuente ),
				$nombre . ': cada `new EditorialDiscountPublication(` tiene que pasar por vigilar_publicacion().'
			);
			self::assertMatchesRegularExpression(
				'/use \\\\?Akibara\\\\Search\\\\Tests\\\\Support\\\\MarcosEditorialesVigilados;/',
				$fuente,
				$nombre . ': la clase tiene que usar el trait MarcosEditorialesVigilados.'
			);
			self::assertMatchesRegularExpression(
				'/protected function tearDown\(\): void \{\s*(?:\/\/[^\n]*\s*)*\$this->soltar_publicaciones_vigiladas\(\);/',
				$fuente,
				$nombre . ': soltar_publicaciones_vigiladas() va PRIMERO en tearDown, con los stubs montados.'
			);
		}
		self::assertGreaterThanOrEqual( 7, $revisados, 'La guarda tiene que estar mirando los ficheros que construyen el runtime editorial.' );
	}

	/**
	 * @testdox La guarda falla la prueba que deja un marco abierto, y no la que lo cerró.
	 */
	public function test_la_guarda_detecta_un_marco_abierto(): void {
		// Sin constructor: construir fija `EditorialDiscountPublication::$runtime`,
		// un estático que contagiaría a los ficheros siguientes.
		$publicacion = ( new ReflectionClass( EditorialDiscountPublication::class ) )->newInstanceWithoutConstructor();
		$marcos      = new ReflectionProperty( EditorialDiscountPublication::class, 'option_frames' );
		$marcos->setAccessible( true );

		$sonda = new class( 'sonda' ) extends TestCase {
			use MarcosEditorialesVigilados;

			public function vigilar( EditorialDiscountPublication $publicacion ): void {
				$this->vigilar_publicacion( $publicacion );
			}

			public function comprobar(): void {
				$this->assertPostConditions();
			}

			/** @return array<string, int> */
			public function deuda( EditorialDiscountPublication $publicacion ): array {
				return self::deuda_de_cierre( $publicacion );
			}
		};
		$sonda->vigilar( $publicacion );

		self::assertSame( array(), $sonda->deuda( $publicacion ), 'Un runtime recién creado no debe nada.' );
		$sonda->comprobar();

		$marcos->setValue( $publicacion, array( 'gmt_offset' => array( array( 'seq' => 2 ) ) ) );
		self::assertSame( array( 'option_frames' => 1 ), $sonda->deuda( $publicacion ) );
		try {
			$sonda->comprobar();
			self::fail( 'Un marco abierto tenía que fallar la prueba.' );
		} catch ( AssertionFailedError $error ) {
			self::assertStringContainsString( 'marco editorial abierto', $error->getMessage() );
		}

		$marcos->setValue( $publicacion, array() );
		$sonda->comprobar();
	}
}
