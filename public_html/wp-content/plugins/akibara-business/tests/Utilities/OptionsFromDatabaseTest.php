<?php
/**
 * Lectura cruda de un LOTE de options, la fuente única que evita que cada
 * módulo copie su propia versión de `window_state_from_database()`
 * (R4-83d4b7c3, R4-198b3376, 2026-09-19).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Tests\Support\OptionsFromDatabaseWpdb;
use Akibara\Business\Utilities\OptionsFromDatabase;
use PHPUnit\Framework\TestCase;

final class OptionsFromDatabaseTest extends TestCase {

	/** @var mixed */
	private $wpdb_previo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb_previo = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		if ( null === $this->wpdb_previo ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->wpdb_previo;
		}
		parent::tearDown();
	}

	/** @testdox Sin nombres que leer, no consulta nada. */
	public function test_lista_vacia_no_consulta(): void {
		unset( $GLOBALS['wpdb'] );

		self::assertSame( array(), OptionsFromDatabase::read( array() ) );
	}

	/** @testdox Sin `$wpdb` disponible, devuelve null para que el llamador caiga a get_option(). */
	public function test_sin_wpdb_devuelve_null(): void {
		unset( $GLOBALS['wpdb'] );

		self::assertNull( OptionsFromDatabase::read( array( 'akibara_algo' ) ) );
	}

	/** @testdox Lee EXACTAMENTE las filas que existen, en una sola consulta. */
	public function test_lee_solo_las_filas_que_existen(): void {
		$doble        = new OptionsFromDatabaseWpdb();
		$doble->filas = array(
			'akibara_a' => 'valor-a',
			'akibara_b' => 'valor-b',
			// 'akibara_c' NO tiene fila: como una option nunca guardada.
		);
		$GLOBALS['wpdb'] = $doble;

		$leidas = OptionsFromDatabase::read( array( 'akibara_a', 'akibara_b', 'akibara_c' ) );

		self::assertSame( array( 'akibara_a' => 'valor-a', 'akibara_b' => 'valor-b' ), $leidas );
		self::assertCount( 1, $doble->consultas, 'Un lote, una sola consulta.' );
	}

	/** @testdox Una fila mal formada (sin las dos columnas) se descarta, no rompe la lectura. */
	public function test_descarta_filas_mal_formadas(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $options = 'wp_options';
			public function prepare( string $sql, ...$args ) {
				unset( $args );
				return $sql;
			}
			public function get_results( string $sql, $output = null ) {
				unset( $sql, $output );
				return array(
					array( 'option_name' => 'akibara_a' ), // sin option_value.
					'esto-ni-siquiera-es-un-array',
					array( 'option_name' => 'akibara_b', 'option_value' => 'valor-b' ),
				);
			}
		};

		self::assertSame( array( 'akibara_b' => 'valor-b' ), OptionsFromDatabase::read( array( 'akibara_a', 'akibara_b' ) ) );
	}

	/** @testdox Una consulta que no devuelve un array (fallo de la base) también cae a null. */
	public function test_resultado_no_array_cae_a_null(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $options = 'wp_options';
			public function prepare( string $sql, ...$args ) {
				unset( $args );
				return $sql;
			}
			public function get_results( string $sql, $output = null ) {
				unset( $sql, $output );
				return false;
			}
		};

		self::assertNull( OptionsFromDatabase::read( array( 'akibara_a' ) ) );
	}
}
