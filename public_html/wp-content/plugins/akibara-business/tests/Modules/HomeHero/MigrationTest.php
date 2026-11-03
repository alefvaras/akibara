<?php
/**
 * Tests del guard de Migration::maybe_seed (1.99.17/1.99.18).
 *
 * La clase de regresión que ya causó un incidente real (2026-08-07): el seed
 * trataba «no pude leer la option» igual que «nunca existió» y PISABA la
 * config del owner con defaults. El guard distingue tres mundos:
 *
 *   1. option legible            → no siembra (aunque slides esté vacío);
 *   2. DB no respondió           → no siembra, no toca nada;
 *   3. fila existe pero
 *      get_option devolvió null  → no siembra Y PURGA el object cache
 *                                   (notoptions envenenado) para auto-curar;
 *   4. de verdad no existe       → siembra defaults (fresh install).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\HomeHero;

use Akibara\Business\Modules\HomeHero\Migration;
use Akibara\Business\Modules\HomeHero\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Doble mínimo de $wpdb para el guard: modela wp_options (la fila del hero) y
 * wp_postmeta (attachments del seed), con el contrato real de last_error —
 * cada consulta exitosa lo limpia; una fallida devuelve null y deja rastro.
 */
final class SeedGuardWpdbFake {

	public string $options = 'wp_options';

	public string $postmeta = 'wp_postmeta';

	public string $last_error = '';

	/** ¿Existe la fila akibara_hero_settings en la tabla? */
	public bool $option_row_exists = false;

	/** Rutas `_wp_attached_file` disponibles (para el seed). */
	public array $attached_files = array();

	/** Fallos programados: fragmento de SQL → mensaje. Se consumen una vez. */
	public array $fail_once = array();

	/** Consultas recibidas, en orden. */
	public array $queries = array();

	public function fail_next( string $needle, string $message = 'MySQL server has gone away' ): void {
		$this->fail_once[ $needle ] = $message;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query   = str_replace( array( '%d', '%f' ), '%s', $query );
		$escaped = array_map(
			static fn( $value ): string => "'" . str_replace( "'", "''", (string) $value ) . "'",
			$args
		);
		return vsprintf( $query, $escaped );
	}

	/**
	 * @return string|null
	 */
	public function get_var( string $query ) {
		$this->queries[] = $query;

		foreach ( $this->fail_once as $needle => $message ) {
			if ( false !== strpos( $query, $needle ) ) {
				unset( $this->fail_once[ $needle ] );
				$this->last_error = $message;
				return null;
			}
		}
		// Consulta exitosa: MySQL limpia el rastro anterior.
		$this->last_error = '';

		if ( false !== strpos( $query, $this->options ) ) {
			return $this->option_row_exists ? '4242' : null;
		}

		if ( false !== strpos( $query, $this->postmeta ) ) {
			foreach ( $this->attached_files as $id => $file ) {
				if ( false !== strpos( $query, $file ) ) {
					return (string) $id;
				}
			}
			return null;
		}

		return null;
	}
}

final class MigrationTest extends TestCase {

	private SeedGuardWpdbFake $wpdb;

	/** @var mixed Valor que get_option devuelve para la option del hero. */
	private $stored_option = null;

	/** @var array<int, array{0:string,1:mixed}> Capturas de update_option. */
	private array $updates = array();

	/** @var array<int, array{0:string,1:string}> Capturas de wp_cache_delete. */
	private array $cache_deletes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb          = new SeedGuardWpdbFake();
		$this->stored_option = null;
		$this->updates       = array();
		$this->cache_deletes = array();

		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				if ( Settings::OPTION_KEY === $key ) {
					return $this->stored_option ?? $default;
				}
				return $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $autoload = null ): bool {
				unset( $autoload );
				$this->updates[] = array( $key, $value );
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, string $group = '' ): bool {
				$this->cache_deletes[] = array( (string) $key, $group );
				return true;
			}
		);

		// Sanitizadores del payload del seed: passthrough suficiente.
		Functions\when( 'sanitize_key' )->alias(
			static fn( $value ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Option legible (incluso con slides vaciados a propósito) → no siembra ni consulta la tabla.
	 */
	public function test_existing_option_short_circuits_before_any_query(): void {
		$this->stored_option = array(
			'slides'   => array(),
			'carousel' => Settings::default_carousel(),
		);

		Migration::maybe_seed();

		self::assertSame( array(), $this->updates, 'No debe re-sembrar una option vaciada a propósito.' );
		self::assertSame( array(), $this->wpdb->queries, 'Con option legible no debe tocar la DB.' );
		self::assertSame( array(), $this->cache_deletes );
	}

	/**
	 * @testdox DB sana y fila inexistente (fresh install) → siembra e inicializa la option.
	 */
	public function test_fresh_install_seeds_defaults(): void {
		$this->wpdb->option_row_exists = false;
		$this->wpdb->attached_files    = array(
			25168 => '/hero-desktop.webp',
			25169 => '/hero-movile.webp',
		);

		Migration::maybe_seed();

		self::assertCount( 1, $this->updates, 'Fresh install debe persistir el seed.' );
		self::assertSame( Settings::OPTION_KEY, $this->updates[0][0] );
		self::assertNotEmpty(
			$this->updates[0][1]['slides'],
			'Con attachments presentes, el seed lleva el slide histórico.'
		);
		self::assertSame( array(), $this->cache_deletes, 'Fresh install no purga caches.' );
	}

	/**
	 * @testdox get_var null CON last_error (DB caída) → NO siembra: null-por-error no es «nunca existió».
	 */
	public function test_db_error_never_seeds(): void {
		$this->wpdb->option_row_exists = true; // La verdad: la fila existe.
		$this->wpdb->fail_next( 'option_name', 'MySQL server has gone away' );

		Migration::maybe_seed();

		self::assertSame(
			array(),
			$this->updates,
			'El incidente 2026-08-07: sembrar aquí PISA la config real del owner.'
		);
		self::assertSame( array(), $this->cache_deletes, 'Con DB dudosa no se toca ningún cache.' );
	}

	/**
	 * @testdox Fila existe pero get_option dijo null (notoptions envenenado) → NO siembra y PURGA el object cache.
	 */
	public function test_poisoned_cache_heals_instead_of_seeding(): void {
		$this->stored_option           = null;  // get_option miente…
		$this->wpdb->option_row_exists = true;  // …pero la tabla tiene la fila.

		Migration::maybe_seed();

		self::assertSame( array(), $this->updates, 'No debe pisar la fila real con defaults.' );

		$purged = array_map( static fn( array $call ): string => $call[0], $this->cache_deletes );
		self::assertContains( Settings::OPTION_KEY, $purged, 'Debe purgar la key del hero.' );
		self::assertContains( 'notoptions', $purged, 'Debe purgar notoptions: ahí vive el veneno.' );
		self::assertContains( 'alloptions', $purged );
		foreach ( $this->cache_deletes as $call ) {
			self::assertSame( 'options', $call[1], 'Las purgas van al grupo options.' );
		}
	}
}
