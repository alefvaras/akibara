<?php
/**
 * Dónde vive la marca de throttle de la reconciliación editorial.
 *
 * `reconcile_on_init()` corre en `init:23` en TODA petición del front y sellaba
 * su marca con `update_option( …, true )` —autoload SÍ— cada 60 s. Core
 * invalida `alloptions` en cada escritura de una option autoloaded, así que el
 * blob completo del sitio se reconstruía una vez por minuto para ahorrar 21
 * consultas: el ahorro era real y el precio, mayor. Con el object cache
 * persistente ya encendido, ese precio se paga de verdad.
 *
 * Las dos invariantes: la marca NO se escribe como option autoloaded, y la
 * degradación sigue siendo ABIERTA (si no se puede leer, se reconcilia).
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\EditorialDiscountPublication;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ReconcileThrottleStoreTest extends TestCase {

	/** @var array<int, array{name: string, autoload: mixed}> */
	private array $option_writes = array();

	/** @var array<int, array{key: string, group: string, ttl: int}> */
	private array $cache_writes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->option_writes = array();
		$this->cache_writes  = array();

		$options = &$this->option_writes;
		$cache   = &$this->cache_writes;

		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value, $autoload = true ) use ( &$options ): bool {
				$options[] = array( 'name' => $name, 'autoload' => $autoload );
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->alias(
			static function ( string $key, $value, string $group = '', int $ttl = 0 ) use ( &$cache ): bool {
				$cache[] = array( 'key' => $key, 'group' => $group, 'ttl' => $ttl );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Con object cache persistente la marca no toca wp_options. */
	public function test_con_object_cache_la_marca_no_toca_wp_options(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );

		$this->write_mark( 1756000000, 60 );

		self::assertSame( array(), $this->option_writes, 'Nada de options: cada escritura autoloaded tira alloptions.' );
		self::assertCount( 1, $this->cache_writes );
		self::assertSame( 'akb-search', $this->cache_writes[0]['group'] );
		self::assertSame( 60, $this->cache_writes[0]['ttl'], 'La entrada caduca sola: no deja residuo.' );
	}

	/** @testdox Sin object cache cae a una option, pero NUNCA autoloaded. */
	public function test_sin_object_cache_la_option_no_es_autoloaded(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		$this->write_mark( 1756000000, 60 );

		self::assertSame( array(), $this->cache_writes );
		self::assertCount( 1, $this->option_writes );
		self::assertFalse( $this->option_writes[0]['autoload'], 'Autoloaded reconstruye alloptions en cada escritura.' );
	}

	/** @testdox Una marca ilegible se lee como 0: la degradación es abierta, reconcilia. */
	public function test_una_marca_ilegible_reconcilia(): void {
		$read = new ReflectionMethod( EditorialDiscountPublication::class, 'read_reconcile_mark' );
		$read->setAccessible( true );

		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		self::assertSame( 0, $read->invoke( null ), 'Un miss del object cache no puede leerse como «espera».' );

		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		self::assertSame( 0, $read->invoke( null ) );
	}

	/**
	 * @param int $now    Sello.
	 * @param int $window Ventana.
	 * @return void
	 */
	private function write_mark( int $now, int $window ): void {
		$method = new ReflectionMethod( EditorialDiscountPublication::class, 'write_reconcile_mark' );
		$method->setAccessible( true );
		$method->invoke( null, $now, $window );
	}
}
