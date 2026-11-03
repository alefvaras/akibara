<?php
/**
 * Autoload del guard de esquema de Favoritos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Wishlist;

use Akibara\Business\Modules\Wishlist\Install;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * `Wishlist::register()` llama a `Install::maybe_install()` en boot, o sea en
 * CADA petición del sitio. La invariante que fijan estas pruebas no es «qué
 * función se llama» sino que ese guard NO cueste una lectura dedicada cuando
 * la tabla ya está al día: tiene que resolverse contra `alloptions`, que la
 * petición ya cargó.
 *
 * Medido el 2026-08-23 en Docker (sin object cache persistente):
 * `get_option('akb_wishlist_db_version')` con autoload='off' = 1 consulta por
 * petición; con autoload='on' = 0. En producción los cuatro flags de
 * Favoritos/BackInStock estaban en 'off'.
 */
final class InstallAutoloadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_lee_fuera_de_alloptions_cuando_el_esquema_esta_al_dia(): void {
		Functions\expect( 'wp_load_alloptions' )
			->once()
			->andReturn( array( Install::OPTION_KEY => Install::DB_VERSION ) );

		// El caso caliente —el de todas las peticiones— no puede tocar ninguna
		// de estas tres: cualquiera implica una lectura o escritura dedicada
		// contra wp_options (o un GET dedicado al object cache).
		$accesos = 0;
		$contar  = static function () use ( &$accesos ) {
			++$accesos;
			return true;
		};
		Functions\when( 'get_option' )->alias( $contar );
		Functions\when( 'update_option' )->alias( $contar );
		Functions\when( 'wp_set_option_autoload' )->alias( $contar );

		Install::maybe_install();

		self::assertSame(
			0,
			$accesos,
			'El guard caliente tiene que resolverse contra alloptions, sin lecturas dedicadas.'
		);
	}

	public function test_repara_el_autoload_de_una_instalacion_previa_sin_recrear_la_tabla(): void {
		// La opción existe con el valor correcto pero fuera de alloptions:
		// exactamente el estado que tenía producción.
		Functions\expect( 'wp_load_alloptions' )->once()->andReturn( array( 'otra_cosa' => '1' ) );
		Functions\expect( 'get_option' )->once()->with( Install::OPTION_KEY )->andReturn( Install::DB_VERSION );
		Functions\expect( 'wp_set_option_autoload' )->once()->with( Install::OPTION_KEY, true );
		Functions\expect( 'update_option' )->never();

		// install() no debe volver a correr: el esquema no cambió.
		$corrio = false;
		\Patchwork\redefine(
			array( Install::class, 'install' ),
			static function () use ( &$corrio ): void {
				$corrio = true;
			}
		);

		Install::maybe_install();

		self::assertFalse( $corrio, 'Reparar el autoload no puede disparar dbDelta.' );
	}

	public function test_sella_la_version_con_autoload_activo_tras_instalar(): void {
		Functions\expect( 'wp_load_alloptions' )->once()->andReturn( array() );
		Functions\expect( 'get_option' )->once()->with( Install::OPTION_KEY )->andReturn( false );

		// `install()` hace `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`,
		// que arrastraría medio WordPress dentro de la suite. Patchwork ya está
		// cargado por el bootstrap, así que se sustituye el método completo:
		// lo que fija esta prueba es el sellado de la opción, no dbDelta.
		$corrio = false;
		\Patchwork\redefine(
			array( Install::class, 'install' ),
			static function () use ( &$corrio ): void {
				$corrio = true;
			}
		);

		// La tabla existe: es lo que autoriza a sellar la versión (S-5a871596).
		\Patchwork\redefine( array( Install::class, 'schema_ready' ), static fn(): bool => true );

		// El tercer argumento es la invariante: sin él la opción vuelve a
		// quedar fuera de alloptions y el guard vuelve a costar una lectura.
		Functions\expect( 'update_option' )
			->once()
			->with( Install::OPTION_KEY, Install::DB_VERSION, true );

		Install::maybe_install();

		self::assertTrue( $corrio, 'La rama de instalación tiene que llamar a install().' );
	}

	/**
	 * Hallazgo S-5a871596: `dbDelta()` falla en silencio. Sellando a ciegas, el
	 * guard de versión corta en la petición siguiente y el módulo queda roto
	 * para siempre, sin reinstalar y sin decir nada.
	 *
	 * @testdox Si la tabla no se creó, la versión NO se sella y el próximo arranque reintenta.
	 */
	public function test_no_sella_la_version_si_la_tabla_no_existe(): void {
		Functions\expect( 'wp_load_alloptions' )->once()->andReturn( array() );
		Functions\expect( 'get_option' )->once()->with( Install::OPTION_KEY )->andReturn( false );
		$corrio = false;
		\Patchwork\redefine(
			array( Install::class, 'install' ),
			static function () use ( &$corrio ): void {
				$corrio = true;
			}
		);
		\Patchwork\redefine( array( Install::class, 'schema_ready' ), static fn(): bool => false );
		Functions\expect( 'update_option' )->never();

		// Desde 1.116.9 el fallo deja traza (`InstallFalloDejaTrazaTest`): el
		// mensaje nombra la tabla y el último error de la base.
		$GLOBALS['wpdb'] = (object) array(
			'prefix'     => 'wp_',
			'last_error' => '',
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wc_get_logger' )->justReturn(
			new class() {
				/** @param array<string, mixed> $context Contexto del log. */
				public function warning( string $message, array $context = array() ): void {
					unset( $message, $context );
				}
			}
		);

		try {
			Install::maybe_install();
		} finally {
			unset( $GLOBALS['wpdb'] );
		}

		self::assertTrue( $corrio, 'Se intentó instalar: lo que no se hace es firmar que quedó instalado.' );
	}
}
