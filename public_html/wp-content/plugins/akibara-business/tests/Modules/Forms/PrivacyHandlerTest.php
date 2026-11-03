<?php
/**
 * Candado: los mensajes de contacto se exportan y se borran por privacidad.
 *
 * S-ee97d92f (2026-09-19): `ContactoLog` guarda nombre, email y mensaje en
 * `wp_options` sin exportador ni borrador de privacidad — un pedido de
 * acceso o borrado con la herramienta estándar de WordPress no veía esos
 * mensajes ni podía retirarlos. La prueba corre `PrivacyHandler::export()` y
 * `::erase()` contra la misma bitácora real (con `wp_options` en memoria),
 * no contra un doble de `ContactoLog`: lo que hay que probar es que el
 * handler encuentra y borra SOLO las filas del email pedido.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Forms;

use Akibara\Business\Modules\Forms\ContactoLog;
use Akibara\Business\Modules\Forms\PrivacyHandler;
use Akibara\Business\Tests\Support\OptionsRowDouble;
use Akibara\Business\Tests\Support\OptionsRowWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PrivacyHandlerTest extends TestCase {

	use OptionsRowDouble;

	/** @var array<string, mixed> */
	private array $options = array();

	private PrivacyHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		$this->handler = new PrivacyHandler();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => array_key_exists( $name, $this->options ) ? maybe_unserialize( $this->options[ $name ] ) : $default
		);
		Functions\when( 'maybe_unserialize' )->alias( static fn( $v ) => is_string( $v ) && str_starts_with( $v, 'a:' ) ? unserialize( $v ) : $v );
		Functions\when( 'add_option' )->alias(
			function ( string $name, $value = '', $deprecated = '', $autoload = null ): bool {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ] = maybe_serialize( $value );

				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value, $autoload = null ): bool {
				$this->options[ $name ] = maybe_serialize( $value );

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );

				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ): string => (string) json_encode( $value ) );
		Functions\when( 'wp_generate_uuid4' )->alias(
			static function (): string {
				static $n = 0;
				++$n;

				return sprintf( 'uuid-%03d', $n );
			}
		);

		$this->instalar_doble_de_options( $this->options, new PrivacyHandlerOptionsDb( $this->options ) );
	}

	protected function tearDown(): void {
		$this->retirar_doble_de_options();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El exportador lista sólo los mensajes del email pedido.
	 */
	public function test_export_lists_only_the_requested_email_messages(): void {
		ContactoLog::record( 'Ana', 'ana@example.cl', 'Hola, tengo una duda' );
		ContactoLog::record( 'Beto', 'beto@example.cl', 'Otro mensaje' );

		$result = $this->handler->export( 'ana@example.cl' );

		self::assertTrue( $result['done'] );
		self::assertCount( 1, $result['data'] );
		$campos = array_column( $result['data'][0]['data'], 'value', 'name' );
		self::assertSame( 'Ana', $campos['Nombre'] );
		self::assertSame( 'Hola, tengo una duda', $campos['Mensaje'] );
	}

	/**
	 * @testdox Sin mensajes de ese email, el exportador no truena y devuelve vacío.
	 */
	public function test_export_returns_empty_when_no_message_matches(): void {
		ContactoLog::record( 'Ana', 'ana@example.cl', 'Hola' );

		$result = $this->handler->export( 'nadie@example.cl' );

		self::assertSame( array(), $result['data'] );
		self::assertTrue( $result['done'] );
	}

	/**
	 * @testdox El borrador retira SOLO los mensajes del email pedido y conserva los demás.
	 */
	public function test_erase_removes_only_the_requested_email_and_keeps_the_rest(): void {
		ContactoLog::record( 'Ana', 'ana@example.cl', 'Mensaje de Ana' );
		ContactoLog::record( 'Beto', 'beto@example.cl', 'Mensaje de Beto' );

		$result = $this->handler->erase( 'ana@example.cl' );

		self::assertTrue( $result['items_removed'] );
		self::assertFalse( $result['items_retained'] );
		self::assertSame( array( 'Mensaje de Beto' ), array_column( ContactoLog::all(), 'mensaje' ) );
	}

	/**
	 * @testdox Borrar un email sin mensajes no reporta items removidos.
	 */
	public function test_erase_reports_nothing_removed_when_email_has_no_messages(): void {
		ContactoLog::record( 'Ana', 'ana@example.cl', 'Mensaje de Ana' );

		$result = $this->handler->erase( 'nadie@example.cl' );

		self::assertFalse( $result['items_removed'] );
		self::assertSame( array( 'Mensaje de Ana' ), array_column( ContactoLog::all(), 'mensaje' ) );
	}

	/**
	 * @testdox El módulo registra el exportador y el borrador ante WordPress.
	 */
	public function test_register_declares_both_filters(): void {
		$exporters = $this->handler->register_exporter( array() );
		$erasers   = $this->handler->register_eraser( array() );

		self::assertArrayHasKey( 'akibara-contacto', $exporters );
		self::assertSame( array( $this->handler, 'export' ), $exporters['akibara-contacto']['callback'] );
		self::assertArrayHasKey( 'akibara-contacto', $erasers );
		self::assertSame( array( $this->handler, 'erase' ), $erasers['akibara-contacto']['callback'] );
	}
}

/**
 * Doble mínimo de `$wpdb` que sirve el barrido por prefijo sobre un array.
 *
 * Igual al de `ContactoLogTest`: `ContactoLog::all()` (que `PrivacyHandler`
 * usa por debajo) sólo emite un `LIKE 'prefijo%'`.
 */
final class PrivacyHandlerOptionsDb extends OptionsRowWpdb {

	/**
	 * @param array<string, mixed> $store
	 */
	public function __construct( array &$store ) {
		$this->filas = &$store;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function get_col( string $query ): array {
		if ( 1 !== preg_match( "/LIKE (?<like>.+)$/", trim( $query ), $m ) ) {
			return array();
		}

		$prefix = trim( $this->crudo( trim( $m['like'] ) ), "'" );
		$prefix = str_replace( array( '\\_', '%' ), array( '_', '' ), $prefix );

		$out = array();
		foreach ( $this->filas as $name => $value ) {
			if ( str_starts_with( $name, $prefix ) ) {
				$out[] = $value;
			}
		}

		return $out;
	}
}
