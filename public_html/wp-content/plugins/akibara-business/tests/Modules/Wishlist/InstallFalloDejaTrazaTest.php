<?php
/**
 * Una migración fallida de Favoritos deja traza, como las de sus dos hermanos.
 *
 * Revisión de 1.116.8: BackInStock y SeriesFollow escriben en el log cuando
 * `dbDelta()` no crea su tabla; Wishlist volvía sin decir nada. El aviso de la
 * activación mandaba a buscar el detalle en los registros y, para este módulo,
 * el detalle no estaba escrito en ninguna parte.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Wishlist;

use Akibara\Business\Modules\Wishlist\Install;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class InstallFalloDejaTrazaTest extends TestCase {

	/** Doble de $wpdb: la tabla «existe» sólo si el caso lo dice. */
	private object $db;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array{0: string, 1: array<string, mixed>}> */
	private array $avisos = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// La traza lleva un freno de una hora (`maybe_install()` corre en cada
		// petición): sin transient previo, escribe.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$this->options = array();
		$this->avisos  = array();

		$this->db = new class() {
			public string $prefix = 'wp_';

			public string $last_error = '';

			public bool $tabla_creada = true;

			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4';
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
				return $this->tabla_creada ? 'wp_akb_wishlist' : null;
			}
		};

		$GLOBALS['wpdb'] = $this->db;
		defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 6 ) . '/public_html/' );

		Functions\when( 'wp_load_alloptions' )->justReturn( array() );
		Functions\when( 'get_option' )->alias(
			fn ( string $option, $default_value = false ) => $this->options[ $option ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $option, $value ): bool {
				$this->options[ $option ] = $value;
				return true;
			}
		);
		Functions\when( 'dbDelta' )->justReturn( array() );

		$avisos = &$this->avisos;
		Functions\when( 'wc_get_logger' )->justReturn(
			new class( $avisos ) {
				/** @var array<int, array{0: string, 1: array<string, mixed>}> */
				private array $avisos;

				/** @param array<int, array{0: string, 1: array<string, mixed>}> $avisos Bitácora compartida. */
				public function __construct( array &$avisos ) {
					$this->avisos = &$avisos;
				}

				/** @param array<string, mixed> $context Contexto del log. */
				public function warning( string $message, array $context = array() ): void {
					$this->avisos[] = array( $message, $context );
				}
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox [NEG] Si dbDelta no crea la tabla, no se sella la versión y el error queda en el log de Favoritos. */
	public function test_a_failed_migration_is_logged(): void {
		$this->db->tabla_creada = false;
		$this->db->last_error   = 'CREATE command denied to user';

		Install::maybe_install();

		self::assertArrayNotHasKey( Install::OPTION_KEY, $this->options, 'Sin sellar: la petición siguiente reintenta.' );
		self::assertCount( 1, $this->avisos );
		self::assertStringContainsString( 'CREATE command denied to user', $this->avisos[0][0] );
		self::assertStringContainsString( 'wp_akb_wishlist', $this->avisos[0][0] );
		self::assertSame( 'akibara-wishlist', $this->avisos[0][1]['source'] ?? null, 'La fuente que nombra el aviso de la activación.' );
	}

	/** @testdox Con la tabla creada se sella la versión y no se escribe nada en el log. */
	public function test_a_successful_migration_seals_without_logging(): void {
		Install::maybe_install();

		self::assertSame( Install::DB_VERSION, $this->options[ Install::OPTION_KEY ] ?? null );
		self::assertSame( array(), $this->avisos );
	}

	/**
	 * Revisión independiente de 1.116.9 (MEDIA): `maybe_install()` corre en cada
	 * petición y, con la tabla rota, el log crecía una línea por visita.
	 *
	 * @testdox Con la tabla rota, la traza se escribe una vez por hora, no en cada visita.
	 */
	public function test_a_failed_migration_is_logged_once_per_hour(): void {
		$this->db->tabla_creada = false;
		$transients             = array();
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( &$transients ) {
				return $transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$transients ): bool {
				$transients[ $key ] = $value;
				return true;
			}
		);

		Install::maybe_install();
		Install::maybe_install();
		Install::maybe_install();

		self::assertCount( 1, $this->avisos, 'Tres visitas, una traza.' );
		self::assertArrayNotHasKey( Install::OPTION_KEY, $this->options, 'Y sigue sin sellar: cada visita reintenta.' );
	}
}
