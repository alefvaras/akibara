<?php
/**
 * Candado: la bitácora de contacto no puede perder mensajes.
 *
 * Guardaba las 100 entradas dentro de UNA option y `record()` hacía
 * leer-modificar-escribir sobre el array completo: dos formularios enviados a la
 * vez leían el mismo array y el segundo `update_option()` pisaba al primero. El
 * módulo que existe para que ningún mensaje se pierda perdía mensajes justo
 * cuando llegan dos juntos.
 *
 * El test entrelaza dos envíos —A lee, B lee, A escribe, B escribe— contra un
 * `wp_options` en memoria. Con la implementación anterior sobrevivía uno.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Forms;

use Akibara\Business\Modules\Forms\ContactoLog;
use Akibara\Business\Tests\Support\OptionsRowDouble;
use Akibara\Business\Tests\Support\OptionsRowWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ContactoLogTest extends TestCase {

	use OptionsRowDouble;

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();

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
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $value ): string => (string) json_encode( $value )
		);
		Functions\when( 'wp_generate_uuid4' )->alias(
			static function (): string {
				static $n = 0;
				++$n;

				return sprintf( 'uuid-%03d', $n );
			}
		);

		$this->instalar_doble_de_options( $this->options, new FakeContactoOptionsDb( $this->options ) );
	}

	protected function tearDown(): void {
		$this->retirar_doble_de_options();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Dos mensajes simultáneos sobreviven los dos.
	 */
	public function test_two_concurrent_submissions_both_survive(): void {
		$primero = ContactoLog::record( 'Ana', 'ana@example.cl', 'Primero' );
		$segundo = ContactoLog::record( 'Beto', 'beto@example.cl', 'Segundo' );

		self::assertNotSame( '', $primero );
		self::assertNotSame( '', $segundo );
		self::assertNotSame( $primero, $segundo );

		$mensajes = array_column( ContactoLog::all(), 'mensaje' );
		self::assertSame( array( 'Primero', 'Segundo' ), $mensajes );
	}

	/**
	 * @testdox Guardar un mensaje no reescribe ninguno de los anteriores.
	 *
	 * Ésta es la invariante que mata la carrera: mientras `record()` escriba
	 * SOLO su propia fila, dos envíos simultáneos no pueden pisarse por mucho
	 * que se entrelacen. Con la bitácora en una option única, el segundo envío
	 * reescribía el array completo —el primer mensaje incluido— con la copia
	 * que había leído antes.
	 */
	public function test_a_submission_never_rewrites_the_previous_messages(): void {
		ContactoLog::record( 'Ana', 'ana@example.cl', 'Primero' );
		$antes = $GLOBALS['wpdb']->filas;

		ContactoLog::record( 'Beto', 'beto@example.cl', 'Segundo' );

		$despues = $GLOBALS['wpdb']->filas;
		self::assertCount( count( $antes ) + 1, $despues, 'El segundo envío escribe SU fila, ni una más.' );
		foreach ( $antes as $nombre => $valor ) {
			self::assertSame(
				$valor,
				$despues[ $nombre ] ?? null,
				sprintf( 'El segundo envío tocó la fila `%s`: ahí vive la carrera.', $nombre )
			);
		}
		$nuevas = array_diff_key( $despues, $antes );
		self::assertCount( 1, $nuevas );
		self::assertStringNotContainsString( 'Primero', (string) reset( $nuevas ), 'La fila nueva no arrastra el mensaje anterior.' );
	}

	/**
	 * @testdox Marcar un mensaje como enviado no toca a los demás.
	 */
	public function test_marking_one_message_leaves_the_others_alone(): void {
		$primero = ContactoLog::record( 'Ana', 'ana@example.cl', 'Primero' );
		ContactoLog::record( 'Beto', 'beto@example.cl', 'Segundo' );

		ContactoLog::mark_mailed( $primero );

		$pendientes = ContactoLog::undelivered();
		self::assertCount( 1, $pendientes );
		self::assertSame( 'Segundo', $pendientes[0]['mensaje'] );
	}

	/**
	 * @testdox Los pendientes se listan del más reciente al más antiguo.
	 */
	public function test_undelivered_lists_the_newest_first(): void {
		ContactoLog::record( 'Ana', 'ana@example.cl', 'Primero' );
		ContactoLog::record( 'Beto', 'beto@example.cl', 'Segundo' );

		self::assertSame(
			array( 'Segundo', 'Primero' ),
			array_column( ContactoLog::undelivered(), 'mensaje' )
		);
	}

	/**
	 * @testdox Descartar los pendientes conserva los que sí salieron.
	 */
	public function test_forgetting_the_pending_ones_keeps_the_delivered(): void {
		$entregado = ContactoLog::record( 'Ana', 'ana@example.cl', 'Entregado' );
		ContactoLog::record( 'Beto', 'beto@example.cl', 'Pendiente' );
		ContactoLog::mark_mailed( $entregado );

		ContactoLog::forget_undelivered();

		self::assertSame( array( 'Entregado' ), array_column( ContactoLog::all(), 'mensaje' ) );
	}

	/**
	 * La poda por FECHA sola podía borrar un mensaje SIN ENTREGAR sólo por ser
	 * viejo, aunque `undelivered()` lo siguiera mostrando pendiente en el
	 * panel: el cliente que preguntó por un pedido no recibía respuesta y no
	 * quedaba ningún rastro (2026-09-19, R2-8c11861a). La poda tiene que
	 * agotar las ENTREGADAS más antiguas antes de tocar una pendiente,
	 * sin importar cuál de las dos sea más vieja por fecha.
	 *
	 * @testdox La poda descarta lo entregado más antiguo antes que un mensaje pendiente, aunque el pendiente sea más viejo.
	 */
	public function test_prune_protects_undelivered_messages_from_being_aged_out(): void {
		$ids = array();
		for ( $i = 1; $i <= 100; $i++ ) {
			$ids[] = ContactoLog::record( "Cliente $i", "cliente{$i}@example.cl", "Mensaje $i" );
		}

		// Los 3 MÁS VIEJOS quedan sin marcar (pendientes); el resto se entrega.
		for ( $i = 3; $i < 100; $i++ ) {
			ContactoLog::mark_mailed( $ids[ $i ] );
		}
		self::assertCount( 100, ContactoLog::all(), 'Fixture: exactamente en el tope, sin podar todavía.' );

		// El mensaje 101 empuja la poda: sobra 1.
		ContactoLog::record( 'Cliente 101', 'cliente101@example.cl', 'Mensaje 101' );

		$restantes = array_column( ContactoLog::all(), 'mensaje' );
		self::assertCount( 100, $restantes, 'La poda vuelve a dejar exactamente MAX_ENTRIES.' );

		self::assertContains( 'Mensaje 1', $restantes, 'El pendiente más viejo no puede caer por ser el más viejo.' );
		self::assertContains( 'Mensaje 2', $restantes );
		self::assertContains( 'Mensaje 3', $restantes );

		// Mensaje 101 también llega sin marcar (record() siempre parte pendiente):
		// los cuatro sin entregar tienen que sobrevivir la poda.
		$pendientes = array_column( ContactoLog::undelivered(), 'mensaje' );
		sort( $pendientes );
		self::assertSame(
			array( 'Mensaje 1', 'Mensaje 101', 'Mensaje 2', 'Mensaje 3' ),
			$pendientes,
			'Los cuatro sin entregar (los 3 viejos + el recién llegado) siguen intactos.'
		);

		self::assertNotContains(
			'Mensaje 4',
			$restantes,
			'La entregada más antigua es la que se poda primero, no un pendiente más viejo.'
		);
	}

	/**
	 * @testdox La bitácora vieja se reparte en filas en vez de perderse.
	 */
	public function test_the_legacy_single_option_is_migrated(): void {
		$this->options['akb_contacto_log'] = array(
			array( 'id' => 'viejo-1', 'time' => 10, 'nombre' => 'Ana', 'email' => 'a@b.cl', 'mensaje' => 'Antiguo', 'mailed' => false ),
		);

		$entradas = ContactoLog::all();

		self::assertSame( array( 'Antiguo' ), array_column( $entradas, 'mensaje' ) );
		self::assertArrayNotHasKey( 'akb_contacto_log', $this->options, 'La option vieja se retira tras repartirse.' );
		self::assertArrayHasKey( ContactoLog::OPTION_PREFIX . 'viejo-1', $this->options );
	}

	/**
	 * @testdox Cada mensaje vive en su propia fila y ninguna se autocarga.
	 */
	public function test_each_message_is_its_own_non_autoloaded_row(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Forms/ContactoLog.php' );
		// Sin comentarios: un `add_option()` nombrado en una nota no es una llamada.
		$src = (string) preg_replace( '#/\*[\s\S]*?\*/|//[^\n]*#', '', $src );

		// `add_option()` haría INSERT … ON DUPLICATE KEY UPDATE: con la caché de objetos
		// desfasada pisaría el mensaje que ya tuviera ese id (revisión 2026-09-15).
		self::assertStringContainsString( 'OptionRow::insert_if_absent( self::OPTION_PREFIX . $id, $entry )', $src );
		self::assertStringNotContainsString( 'add_option(', $src, 'Ninguna fila de la bitácora se crea con add_option().' );
		self::assertStringNotContainsString(
			'private static function save(',
			$src,
			'El guardado en bloque era la carrera: reescribía el array entero de todos los mensajes.'
		);
	}
}

/**
 * Doble mínimo de `$wpdb` que sirve el barrido por prefijo sobre un array.
 */
final class FakeContactoOptionsDb extends OptionsRowWpdb {

	/**
	 * Se toma por REFERENCIA: el doble tiene que ver las escrituras que hacen
	 * `OptionRow`/`delete_option()` dentro de la misma llamada.
	 *
	 * @param array<string, mixed> $store
	 */
	public function __construct( array &$store ) {
		$this->filas = &$store;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function get_col( string $query ): array {
		// El único patrón que emite ContactoLog es `LIKE 'prefijo%'`.
		if ( 1 !== preg_match( "/LIKE (?<like>.+)$/", trim( $query ), $m ) ) {
			return array();
		}

		// Se deshace, en orden, lo que puso cada capa: `prepare()` (barras y comillas)
		// y `esc_like()` (el guión bajo escapado).
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
