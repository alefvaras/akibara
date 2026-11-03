<?php
/**
 * El esquema de la cola.
 *
 * Una migración fallida NO puede sellar la versión: dbDelta falla en silencio
 * ante permisos, engine o largo de key, y sellar a ciegas dejaría el módulo
 * envenenado para siempre — sin tabla, sin señal y sin auto-reparación, porque
 * el early-return de `maybe_install()` no volvería a intentarlo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesFollow\Install;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** Doble de `$wpdb` que decide si la tabla «existe» tras el dbDelta. */
final class InstallWpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';

	public bool $tabla_creada = true;

	/** @var array<int, string> Sentencias que pasaron por dbDelta. */
	public array $esquemas = array();

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}

	public function esc_like( string $value ): string {
		return $value;
	}

	/** @param mixed ...$args Argumentos ligados. */
	public function prepare( string $sql, ...$args ): string {
		return str_replace( '%s', (string) ( $args[0] ?? '' ), $sql );
	}

	/** @return string|null */
	public function get_var( string $sql ) {
		unset( $sql );
		return $this->tabla_creada ? 'wp_akb_series_follow_queue' : null;
	}
}

final class InstallTest extends TestCase {

	private InstallWpdb $db;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, string> */
	private array $avisos = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->db      = new InstallWpdb();
		$this->options = array();
		$this->avisos  = array();

		$GLOBALS['wpdb'] = $this->db;
		defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 6 ) . '/public_html/' );

		Functions\when( 'get_option' )->alias(
			fn ( string $option, $default_value = false ) => $this->options[ $option ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $option, $value ): bool {
				$this->options[ $option ] = $value;
				return true;
			}
		);
		Functions\when( 'dbDelta' )->alias(
			function ( string $sql ): array {
				$this->db->esquemas[] = $sql;
				return array();
			}
		);
		Functions\when( 'wc_get_logger' )->justReturn(
			new class( $this->avisos ) {
				/** @var array<int, string> */
				private array $avisos;

				/** @param array<int, string> $avisos Bitácora compartida. */
				public function __construct( array &$avisos ) {
					$this->avisos = &$avisos;
				}

				/** @param array<string, mixed> $context Contexto del log. */
				public function warning( string $message, array $context = array() ): void {
					unset( $context );
					$this->avisos[] = $message;
				}
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox dbDelta crea la tabla con uniq_event y sólo entonces sella la versión. */
	public function test_dbdelta_crea_la_tabla_con_uniq_event_y_solo_entonces_sella(): void {
		Install::maybe_install();

		self::assertCount( 1, $this->db->esquemas );
		self::assertStringContainsString( 'UNIQUE KEY uniq_event (event_key)', $this->db->esquemas[0] );
		self::assertSame( Install::DB_VERSION, $this->options[ Install::OPTION_KEY ] );
	}

	/** @testdox [NEG] Una migración fallida NO sella la versión y deja traza. */
	public function test_una_migracion_fallida_no_sella_la_version(): void {
		$this->db->tabla_creada = false;
		$this->db->last_error   = 'Specified key was too long';

		Install::maybe_install();

		self::assertArrayNotHasKey(
			Install::OPTION_KEY,
			$this->options,
			'Sin sellar, la próxima petición reintenta y el módulo se arregla solo cuando se corrige la causa.'
		);
		self::assertNotSame( array(), $this->avisos );
		self::assertStringContainsString( 'Specified key was too long', $this->avisos[0] );
	}

	/** @testdox Con la versión ya sellada no se vuelve a correr dbDelta. */
	public function test_con_la_version_sellada_no_se_repite_la_migracion(): void {
		$this->options[ Install::OPTION_KEY ] = Install::DB_VERSION;

		Install::maybe_install();

		self::assertSame( array(), $this->db->esquemas );
	}

	/**
	 * `schema_ready()` es `public` porque el activation hook llama a
	 * `install()` + `update_option()` por su cuenta: sin la sonda sellaría a
	 * ciegas y una activación con dbDelta fallido envenenaría el módulo.
	 *
	 * @testdox La sonda de esquema es pública y sólo lee.
	 */
	public function test_la_sonda_de_esquema_es_publica_y_solo_lee(): void {
		self::assertTrue( Install::schema_ready() );

		$this->db->tabla_creada = false;
		self::assertFalse( Install::schema_ready() );
		self::assertSame( array(), $this->db->esquemas, 'La sonda no crea ni migra nada.' );
	}

	/** @testdox El esquema declara los índices que usan la cola y la expiración. */
	public function test_el_esquema_declara_sus_indices(): void {
		Install::maybe_install();

		self::assertStringContainsString( 'KEY idx_user_status (user_id, status)', $this->db->esquemas[0] );
		self::assertStringContainsString( 'KEY idx_status_created (status, created_at)', $this->db->esquemas[0] );
	}

	/**
	 * `VolumeTitle::extract_number()` extrae como mucho cuatro dígitos, así que
	 * un SMALLINT UNSIGNED (hasta 65535) cubre el catálogo entero con holgura.
	 *
	 * @testdox La columna del tomo cabe: cuatro dígitos como máximo.
	 */
	public function test_la_columna_del_tomo_cabe(): void {
		Install::maybe_install();

		self::assertStringContainsString( 'volume SMALLINT UNSIGNED NOT NULL', $this->db->esquemas[0] );
		self::assertGreaterThan( 9999, 65535 );
	}
}
