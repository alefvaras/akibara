<?php
/**
 * Contrato de persistencia honesta para los formularios admin de Search.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\SearchAdminSection;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__ ) . '/src/SearchAdminSection.php';

/** Doble stateful que ejecuta el CAS raw de wp_options y puede inyectar una carrera. */
final class SearchAdminCasWpdb {

	public string $options = 'wp_options';
	public string $last_error = '';

	/** @var array<string, array{option_value: string, autoload: string}> */
	public array $rows = array();

	public ?\Closure $before_query = null;
	public bool $fail_query = false;
	public bool $fail_read = false;

	/** @param mixed ...$args Valores SQL. */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace( '/%s/', $replacement, $query, 1 );
		}
		return $query;
	}

	/** @return object|null */
	public function get_row( string $query ): ?object {
		if ( $this->fail_read ) {
			$this->last_error = 'simulated snapshot error';
			return null;
		}
		$this->last_error = '';
		$values           = self::quoted_values( $query );
		$name             = (string) ( $values[0] ?? '' );
		if ( ! isset( $this->rows[ $name ] ) ) {
			return null;
		}
		return (object) $this->rows[ $name ];
	}

	/** @return int|false */
	public function query( string $query ) {
		if ( $this->before_query instanceof \Closure ) {
			$callback           = $this->before_query;
			$this->before_query = null;
			$callback( $this );
		}
		if ( $this->fail_query ) {
			$this->last_error = 'simulated CAS error';
			return false;
		}
		$this->last_error = '';
		$values           = self::quoted_values( $query );
		if ( 0 === stripos( ltrim( $query ), 'UPDATE' ) ) {
			$name = (string) ( $values[2] ?? '' );
			if ( ! isset( $this->rows[ $name ] )
				|| (string) $this->rows[ $name ]['option_value'] !== (string) ( $values[3] ?? '' )
				|| (string) $this->rows[ $name ]['autoload'] !== (string) ( $values[4] ?? '' ) ) {
				return 0;
			}
			$this->rows[ $name ] = array( 'option_value' => (string) $values[0], 'autoload' => (string) $values[1] );
			return 1;
		}
		if ( 0 === stripos( ltrim( $query ), 'DELETE' ) ) {
			$name = (string) ( $values[0] ?? '' );
			if ( ! isset( $this->rows[ $name ] )
				|| (string) $this->rows[ $name ]['option_value'] !== (string) ( $values[1] ?? '' )
				|| (string) $this->rows[ $name ]['autoload'] !== (string) ( $values[2] ?? '' ) ) {
				return 0;
			}
			unset( $this->rows[ $name ] );
			return 1;
		}
		if ( 0 === stripos( ltrim( $query ), 'INSERT IGNORE' ) ) {
			$name = (string) ( $values[0] ?? '' );
			if ( isset( $this->rows[ $name ] ) ) {
				return 0;
			}
			$this->rows[ $name ] = array( 'option_value' => (string) $values[1], 'autoload' => (string) $values[2] );
			return 1;
		}
		return 0;
	}

	/** @return array<int, string> */
	private static function quoted_values( string $query ): array {
		preg_match_all( "/'((?:''|[^'])*)'/", $query, $matches );
		return array_map( static fn( string $value ): string => str_replace( "''", "'", $value ), $matches[1] ?? array() );
	}
}

/** @covers \Akibara\Search\SearchAdminSection */
final class SearchAdminPersistenceTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	private string $fail_option = '';

	private ?\Closure $on_fail = null;

	/** @var mixed */
	private $previous_wpdb;

	private bool $had_previous_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options     = array();
		$this->fail_option = '';
		$this->on_fail     = null;
		$this->had_previous_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb     = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['wpdb'] );

		Functions\when( 'wp_generate_uuid4' )->justReturn( '00000000-0000-4000-8000-000000000001' );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'esc_html' )->alias( static fn( $value ): string => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ) );

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				if ( $name === $this->fail_option ) {
					if ( $this->on_fail instanceof \Closure ) {
						$callback      = $this->on_fail;
						$this->on_fail = null;
						$callback();
					}
					return false; // Simula pre-hook que conserva el valor anterior.
				}
				$changed                = ! array_key_exists( $name, $this->options ) || $this->options[ $name ] !== $value;
				$this->options[ $name ] = $value;
				return $changed;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				if ( $name === $this->fail_option || ! array_key_exists( $name, $this->options ) ) {
					return false;
				}
				unset( $this->options[ $name ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		if ( $this->had_previous_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox update_option false por no-op cuenta como éxito sólo si la relectura es exacta. */
	public function test_noop_is_success_after_exact_reread(): void {
		$this->options['one'] = 'yes';

		self::assertTrue( $this->persist( array( 'one' => 'yes' ) ) );
		self::assertSame( 'yes', $this->options['one'] );
	}

	/** @testdox Si una option del grupo no persiste, las anteriores se compensan en orden inverso. */
	public function test_multi_option_failure_rolls_back_previous_writes(): void {
		$this->options = array( 'one' => 'old-one', 'two' => 'old-two' );
		$this->fail_option = 'two';

		self::assertFalse( $this->persist( array( 'one' => 'new-one', 'two' => 'new-two' ) ) );
		self::assertSame( array( 'one' => 'old-one', 'two' => 'old-two' ), $this->options );
	}

	/** @testdox Rollback restaura ausencia, no deja una option creada por un grupo parcial. */
	public function test_group_rollback_restores_absent_option(): void {
		$this->options = array( 'two' => 'old-two' );
		$this->fail_option = 'two';

		self::assertFalse( $this->persist( array( 'one' => 'new-one', 'two' => 'new-two' ) ) );
		self::assertArrayNotHasKey( 'one', $this->options );
		self::assertSame( 'old-two', $this->options['two'] );
	}

	/** @testdox Rollback no pisa el valor de otro writer que ganó después del write parcial. */
	public function test_group_rollback_preserves_concurrent_update(): void {
		$this->options     = array( 'one' => 'old-one', 'two' => 'old-two' );
		$this->fail_option = 'two';
		$this->on_fail     = function (): void {
			$this->options['one'] = 'concurrent-one';
		};

		self::assertFalse( $this->persist( array( 'one' => 'new-one', 'two' => 'new-two' ) ) );
		self::assertSame( 'concurrent-one', $this->options['one'] );
		self::assertSame( 'old-two', $this->options['two'] );
	}

	/** @testdox Rollback no borra una option originalmente ausente que otro writer reemplazó. */
	public function test_group_rollback_preserves_concurrent_creation(): void {
		$this->options     = array( 'two' => 'old-two' );
		$this->fail_option = 'two';
		$this->on_fail     = function (): void {
			$this->options['one'] = 'concurrent-one';
		};

		self::assertFalse( $this->persist( array( 'one' => 'new-one', 'two' => 'new-two' ) ) );
		self::assertSame( 'concurrent-one', $this->options['one'] );
		self::assertSame( 'old-two', $this->options['two'] );
	}

	/** @testdox Soltar una fila la deja sin fila; soltar una que no existe es un no-op correcto. */
	public function test_release_leaves_no_row_and_absent_is_noop(): void {
		$this->options = array( 'one' => 'de fábrica' );

		self::assertTrue( $this->persist( array(), array( 'one', 'two' ) ) );
		self::assertSame( array(), $this->options );
	}

	/** @testdox Si una fila no se deja soltar, el grupo entero vuelve atrás: lo escrito y lo ya soltado. */
	public function test_release_failure_rolls_back_writes_and_previous_releases(): void {
		$this->options     = array( 'one' => 'old-one', 'two' => 'de fábrica', 'three' => 'no se va' );
		$this->fail_option = 'three';

		self::assertFalse( $this->persist( array( 'one' => 'new-one' ), array( 'two', 'three' ) ) );
		ksort( $this->options );
		self::assertSame( array( 'one' => 'old-one', 'three' => 'no se va', 'two' => 'de fábrica' ), $this->options );
	}

	/** @testdox Por SQL, la fila ya soltada vuelve con sus bytes y su autoload si otra del grupo no se deja soltar. */
	public function test_sql_release_failure_reinserts_released_row_exactly(): void {
		$db                = new SearchAdminCasWpdb();
		$db->rows['two']   = array( 'option_value' => 'Prueba con el nombre de la serie.', 'autoload' => 'off' );
		$db->rows['three'] = array( 'option_value' => 'no se va', 'autoload' => 'auto' );
		$GLOBALS['wpdb']   = $db;
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) use ( $db ): bool {
				if ( 'three' === $name || ! isset( $db->rows[ $name ] ) ) {
					return false;
				}
				unset( $db->rows[ $name ] );
				return true;
			}
		);

		self::assertFalse( $this->persist( array(), array( 'two', 'three' ) ) );
		self::assertSame( array( 'option_value' => 'Prueba con el nombre de la serie.', 'autoload' => 'off' ), $db->rows['two'] ?? null );
		self::assertSame( array( 'option_value' => 'no se va', 'autoload' => 'auto' ), $db->rows['three'] );
	}

	/** @testdox El CAS SQL restaura bytes y autoload sólo cuando la fila sigue siendo la intentada. */
	public function test_sql_cas_restores_exact_attempted_row(): void {
		$db = new SearchAdminCasWpdb();
		$db->rows['one'] = array( 'option_value' => 'C', 'autoload' => 'off' );
		$GLOBALS['wpdb'] = $db;

		$result = $this->restore_cas(
			'one',
			array( 'present' => true, 'value' => 'A', 'raw' => 'A', 'autoload' => 'on' ),
			array( 'present' => true, 'value' => 'C', 'raw' => 'C', 'autoload' => 'off' )
		);

		self::assertSame( array( 'ok' => true, 'changed' => true ), $result );
		self::assertSame( array( 'option_value' => 'A', 'autoload' => 'on' ), $db->rows['one'] );
	}

	/** @testdox El CAS DELETE restaura ausencia sólo si valor y autoload aún coinciden. */
	public function test_sql_cas_delete_restores_original_absence(): void {
		$db = new SearchAdminCasWpdb();
		$db->rows['one'] = array( 'option_value' => 'C', 'autoload' => 'off' );
		$GLOBALS['wpdb'] = $db;

		$result = $this->restore_cas(
			'one',
			array( 'present' => false, 'value' => null, 'raw' => '', 'autoload' => '' ),
			array( 'present' => true, 'value' => 'C', 'raw' => 'C', 'autoload' => 'off' )
		);

		self::assertSame( array( 'ok' => true, 'changed' => true ), $result );
		self::assertArrayNotHasKey( 'one', $db->rows );
	}

	/** @testdox affected_rows cero preserva al writer concurrente y reporta contención. */
	public function test_sql_cas_preserves_writer_that_wins_between_read_and_write(): void {
		$db = new SearchAdminCasWpdb();
		$db->rows['one'] = array( 'option_value' => 'C', 'autoload' => 'off' );
		$db->before_query = static function ( SearchAdminCasWpdb $database ): void {
			$database->rows['one'] = array( 'option_value' => 'D', 'autoload' => 'on' );
		};
		$GLOBALS['wpdb'] = $db;

		$result = $this->restore_cas(
			'one',
			array( 'present' => true, 'value' => 'A', 'raw' => 'A', 'autoload' => 'on' ),
			array( 'present' => true, 'value' => 'C', 'raw' => 'C', 'autoload' => 'off' )
		);

		self::assertSame( array( 'ok' => false, 'changed' => false ), $result );
		self::assertSame( array( 'option_value' => 'D', 'autoload' => 'on' ), $db->rows['one'] );
	}

	/** @testdox Un cambio concurrente sólo de autoload también derrota el CAS. */
	public function test_sql_cas_compares_autoload(): void {
		$db = new SearchAdminCasWpdb();
		$db->rows['one'] = array( 'option_value' => 'C', 'autoload' => 'off' );
		$db->before_query = static function ( SearchAdminCasWpdb $database ): void {
			$database->rows['one']['autoload'] = 'on';
		};
		$GLOBALS['wpdb'] = $db;

		$result = $this->restore_cas(
			'one',
			array( 'present' => true, 'value' => 'A', 'raw' => 'A', 'autoload' => 'on' ),
			array( 'present' => true, 'value' => 'C', 'raw' => 'C', 'autoload' => 'off' )
		);

		self::assertSame( array( 'ok' => false, 'changed' => false ), $result );
		self::assertSame( array( 'option_value' => 'C', 'autoload' => 'on' ), $db->rows['one'] );
	}

	/** @testdox Error SQL de compensación falla cerrado y conserva la fila. */
	public function test_sql_cas_error_fails_closed(): void {
		$db = new SearchAdminCasWpdb();
		$db->rows['one'] = array( 'option_value' => 'C', 'autoload' => 'off' );
		$db->fail_query = true;
		$GLOBALS['wpdb'] = $db;

		$result = $this->restore_cas(
			'one',
			array( 'present' => true, 'value' => 'A', 'raw' => 'A', 'autoload' => 'on' ),
			array( 'present' => true, 'value' => 'C', 'raw' => 'C', 'autoload' => 'off' )
		);

		self::assertSame( array( 'ok' => false, 'changed' => false ), $result );
		self::assertSame( array( 'option_value' => 'C', 'autoload' => 'off' ), $db->rows['one'] );
	}

	/** @testdox Un error al fotografiar autoload impide iniciar el grupo. */
	public function test_snapshot_metadata_error_fails_before_write(): void {
		$db = new SearchAdminCasWpdb();
		$db->rows['one'] = array( 'option_value' => 'A', 'autoload' => 'on' );
		$db->fail_read   = true;
		$GLOBALS['wpdb'] = $db;

		self::assertFalse( $this->persist( array( 'one' => 'C' ) ) );
		self::assertSame( array( 'option_value' => 'A', 'autoload' => 'on' ), $db->rows['one'] );
		self::assertArrayNotHasKey( 'one', $this->options, 'update_option no debe ejecutarse sin snapshot verificable.' );
	}

	/** @testdox Servicios distingue intención configurada de disponibilidad efectiva. */
	public function test_services_render_structured_configured_and_effective_flags_exactly(): void {
		$method = new ReflectionMethod( SearchAdminSection::class, 'render_services' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke(
			new SearchAdminSection(),
			array(
				'indexing' => array( 'configured' => false, 'published' => false, 'effective' => false ),
				'suggest'  => array( 'configured' => true, 'published' => true, 'effective' => false ),
				'facets'   => array( 'configured' => true, 'published' => true, 'effective' => true ),
			)
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Autocompletado</th><td><span style="color:#996800">Encendido, pero no disponible ahora</span><br><small>Configurado: sí · efectivo: no</small>', $html );
		self::assertStringContainsString( 'Filtros del catálogo</th><td><span style="color:#16803d">Activos</span><br><small>Configurado: sí · efectivo: sí</small>', $html );
		self::assertStringNotContainsString( 'Autocompletado</th><td><span style="color:#16803d">Activo</span>', $html );
	}

	/**
	 * @param array<string, mixed> $values   Valores.
	 * @param array<int, string>   $releases Options que tienen que quedar sin fila.
	 * @return bool
	 */
	private function persist( array $values, array $releases = array() ): bool {
		$method = new \ReflectionMethod( SearchAdminSection::class, 'persist_options_exact' );
		$method->setAccessible( true );
		return (bool) $method->invoke( new SearchAdminSection(), $values, $releases );
	}

	/**
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $snapshot Original.
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $attempted Intentado.
	 * @return array{ok: bool, changed: bool}
	 */
	private function restore_cas( string $option, array $snapshot, array $attempted ): array {
		$method = new \ReflectionMethod( SearchAdminSection::class, 'restore_option_snapshot_cas' );
		$method->setAccessible( true );
		/** @var array{ok: bool, changed: bool} $result */
		$result = $method->invoke( new SearchAdminSection(), $option, $snapshot, $attempted );
		return $result;
	}
}
