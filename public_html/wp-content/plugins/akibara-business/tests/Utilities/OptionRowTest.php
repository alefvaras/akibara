<?php
/**
 * La fila de `wp_options` como primitiva de coordinación: insertar sólo si falta,
 * cambiar sólo si nadie la tocó, borrar sólo la propia y leer sin caché.
 *
 * Lo que se fija acá es justo lo que `add_option()` NO da: su INSERT lleva
 * `ON DUPLICATE KEY UPDATE`, así que con la caché de objetos desfasada pisa el
 * valor de otro proceso y devuelve verdadero igual.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Tests\Support\OptionsRowDouble;
use Akibara\Business\Utilities\OptionRow;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class OptionRowTest extends TestCase {

	use OptionsRowDouble;

	/** @var array<int, string> */
	private array $cache_borrada = array();


	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->cache_borrada = array();

		Functions\expect( 'get_option' )->never();

		$this->instalar_doble_de_options();
		Functions\when( 'wp_cache_delete' )->alias(
			function ( string $clave, string $grupo ): bool {
				$this->cache_borrada[] = $grupo . ':' . $clave;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$this->retirar_doble_de_options();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Sólo uno inserta la fila: el segundo recibe false y NO pisa el valor del primero.
	 */
	public function test_only_one_process_inserts_the_row(): void {
		self::assertTrue( OptionRow::insert_if_absent( 'akb_candado', 'primero' ) );
		self::assertFalse( OptionRow::insert_if_absent( 'akb_candado', 'segundo' ) );
		self::assertSame( 'primero', $GLOBALS['wpdb']->filas['akb_candado'], 'Esto es lo que `add_option()` no garantiza.' );
	}

	/**
	 * @testdox El cambio sólo entra si la fila conserva el valor que se leyó.
	 */
	public function test_compare_and_swap_needs_the_value_it_read(): void {
		OptionRow::insert_if_absent( 'akb_candado', 'v1' );

		self::assertTrue( OptionRow::compare_and_swap( 'akb_candado', 'v1', 'v2' ) );
		self::assertFalse( OptionRow::compare_and_swap( 'akb_candado', 'v1', 'v3' ), 'Ya no vale v1: otro lo cambió.' );
		self::assertSame( 'v2', $GLOBALS['wpdb']->filas['akb_candado'] );
	}

	/**
	 * WordPress no activa `MYSQLI_CLIENT_FOUND_ROWS`: un UPDATE que deja el mismo
	 * valor afecta 0 filas y parecería que otro robó el candado.
	 *
	 * @testdox Cambiar por el mismo valor devuelve false: quien renueva tiene que escribir algo distinto.
	 */
	public function test_swapping_for_the_same_value_reports_no_change(): void {
		OptionRow::insert_if_absent( 'akb_candado', 'igual' );

		self::assertFalse( OptionRow::compare_and_swap( 'akb_candado', 'igual', 'igual' ) );
	}

	/**
	 * @testdox Cada quien borra sólo su propia fila.
	 */
	public function test_delete_only_removes_its_own_value(): void {
		OptionRow::insert_if_absent( 'akb_candado', 'mio' );

		self::assertFalse( OptionRow::delete_if_equals( 'akb_candado', 'ajeno' ) );
		self::assertArrayHasKey( 'akb_candado', $GLOBALS['wpdb']->filas );

		self::assertTrue( OptionRow::delete_if_equals( 'akb_candado', 'mio' ) );
		self::assertArrayNotHasKey( 'akb_candado', $GLOBALS['wpdb']->filas );
	}

	/**
	 * @testdox La lectura va a la base, nunca a `get_option()`.
	 */
	public function test_reading_goes_to_the_database(): void {
		self::assertNull( OptionRow::read_raw( 'akb_candado' ) );

		OptionRow::insert_if_absent( 'akb_candado', '1789443926|abc|1' );
		self::assertSame( '1789443926|abc|1', OptionRow::read_raw( 'akb_candado' ) );
		self::assertStringContainsString( "SELECT option_value FROM wp_options WHERE option_name = 'akb_candado'", implode( ' ', $GLOBALS['wpdb']->consultas ) );
	}

	/**
	 * Tras escribir por SQL, la caché de esa clave miente. Tirar `alloptions` o
	 * `notoptions`, en cambio, se paga en TODAS las peticiones del sitio.
	 *
	 * @testdox Cada escritura retira de la caché esa clave, y sólo esa.
	 */
	public function test_every_write_forgets_only_that_key(): void {
		OptionRow::insert_if_absent( 'akb_candado', 'v1' );
		OptionRow::compare_and_swap( 'akb_candado', 'v1', 'v2' );
		OptionRow::delete_if_equals( 'akb_candado', 'v2' );

		self::assertSame( array( 'options:akb_candado', 'options:akb_candado', 'options:akb_candado' ), $this->cache_borrada );
	}

	/**
	 * @testdox Un array se guarda serializado como lo haría WordPress.
	 */
	public function test_arrays_are_serialized_like_core(): void {
		OptionRow::insert_if_absent( 'akb_lease', array( 'token' => 'abc', 'expires_at' => 10 ) );

		self::assertSame( serialize( array( 'token' => 'abc', 'expires_at' => 10 ) ), $GLOBALS['wpdb']->filas['akb_lease'] );
		self::assertTrue( OptionRow::compare_and_swap( 'akb_lease', array( 'token' => 'abc', 'expires_at' => 10 ), array( 'token' => 'xyz', 'expires_at' => 20 ) ) );
	}

	/**
	 * En WP-CLI temprano y en algunas pruebas no hay `$wpdb`: nada revienta.
	 *
	 * @testdox Sin base de datos usable, cada operación dice que no pudo.
	 */
	public function test_without_a_database_nothing_explodes(): void {
		unset( $GLOBALS['wpdb'] );

		self::assertFalse( OptionRow::insert_if_absent( 'akb_candado', 'v1' ) );
		self::assertFalse( OptionRow::compare_and_swap( 'akb_candado', 'v1', 'v2' ) );
		self::assertFalse( OptionRow::delete_if_equals( 'akb_candado', 'v1' ) );
		self::assertNull( OptionRow::read_raw( 'akb_candado' ) );
	}

	/**
	 * `add_option()` de core quita la clave de `notoptions` al crear la fila. Si esto
	 * no lo hace, quien lea con `get_option()` recibe «no existe» aunque la fila esté:
	 * tras desactivar y reactivar el plugin, el lease de `llms.txt` quedaba sin poder
	 * soltarse ni tomarse (revisión independiente, 2026-09-15).
	 *
	 * @testdox Crear la fila la saca de «notoptions»; si no estaba marcada, no se escribe la caché.
	 */
	public function test_inserting_unmarks_the_key_in_notoptions(): void {
		$this->notoptions = array( 'akb_lease' => true, 'otra_option' => true );

		self::assertTrue( OptionRow::insert_if_absent( 'akb_lease', 'v1' ) );
		self::assertSame( array( 'otra_option' => true ), $this->notoptions, 'Sólo sale la clave creada.' );

		$escrituras = 0;
		Functions\when( 'wp_cache_set' )->alias(
			static function () use ( &$escrituras ): bool {
				++$escrituras;
				return true;
			}
		);
		self::assertTrue( OptionRow::insert_if_absent( 'akb_sin_marca', 'v1' ) );
		self::assertSame( 0, $escrituras, 'Si la clave no estaba en notoptions, la caché no se toca.' );

		self::assertFalse( OptionRow::insert_if_absent( 'akb_lease', 'v2' ) );
	}

	/**
	 * `wp_options` usa una intercalación que ignora mayúsculas y espacios finales: un
	 * candado tiene que coincidir byte a byte.
	 *
	 * @testdox El cambio y el borrado comparan en binario y el UPDATE no toca autoload.
	 */
	public function test_comparisons_are_binary_and_autoload_is_left_alone(): void {
		OptionRow::insert_if_absent( 'akb_candado', 'abc' );
		OptionRow::compare_and_swap( 'akb_candado', 'abc', 'def' );
		OptionRow::delete_if_equals( 'akb_candado', 'def' );

		$sql = implode( "\n", $GLOBALS['wpdb']->consultas );
		self::assertStringContainsString( "UPDATE wp_options SET option_value = 'def' WHERE option_name = 'akb_candado' AND BINARY option_value = 'abc'", $sql );
		self::assertStringContainsString( "DELETE FROM wp_options WHERE option_name = 'akb_candado' AND BINARY option_value = 'def'", $sql );
		self::assertDoesNotMatchRegularExpression( '/UPDATE[^\n]*autoload/', $sql );
	}
}
