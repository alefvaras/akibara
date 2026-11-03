<?php
/**
 * R3-dc2c02a9 (2026-09-19): el respaldo sin JS no sirve de nada si nadie
 * engancha `Forms::register()` a los hooks que WordPress dispara para un
 * `<form action="admin-post.php">` con `action=akb_contacto` — sin esto,
 * WordPress responde "0" o un 400 "Método no permitido" y el mensaje del
 * cliente se pierde igual que antes del arreglo, sólo que ahora silenciado
 * por un handler que nunca se registró.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Forms;

use Akibara\Business\Modules\Forms\ContactoSubmit;
use Akibara\Business\Modules\Forms\Forms;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FormsAdminPostWiringTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Registro de otros módulos que Forms::register() dispara de paso
		// (shortcode de Contacto, filtros de privacidad de datos): no son lo
		// que esta prueba mide, sólo hace falta que no revienten.
		Functions\when( 'add_shortcode' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox `register()` engancha admin_post_akb_contacto Y admin_post_nopriv_akb_contacto al MISMO handler.
	 *
	 * Los dos, no sólo uno: el formulario de contacto no exige sesión, así que
	 * el visitante anónimo (nopriv) tiene que llegar al mismo sitio que un
	 * usuario logueado.
	 */
	public function test_both_admin_post_hooks_reach_the_shared_handler(): void {
		$es_el_handler_compartido = static function ( $callback ): bool {
			return is_array( $callback )
				&& $callback[0] instanceof ContactoSubmit
				&& 'handle_admin_post' === $callback[1];
		};

		Actions\expectAdded( 'admin_post_' . ContactoSubmit::ADMIN_POST_ACTION )
			->once()
			->withArgs( $es_el_handler_compartido );
		Actions\expectAdded( 'admin_post_nopriv_' . ContactoSubmit::ADMIN_POST_ACTION )
			->once()
			->withArgs( $es_el_handler_compartido );

		( new Forms() )->register();

		self::assertTrue( true, 'La expectativa la verifica Brain Monkey al cerrar.' );
	}

	/**
	 * @testdox La acción del `<form>` del tema («akb_contacto») es EXACTAMENTE el sufijo de los dos hooks.
	 *
	 * WordPress despacha `admin_post_{$_REQUEST['action']}`: un desfase entre
	 * el valor del `<input type="hidden" name="action">` del tema y el sufijo
	 * de estos `add_action()` deja el respaldo muerto sin ningún error visible.
	 */
	public function test_the_action_constant_matches_what_the_hooks_listen_for(): void {
		self::assertSame( 'akb_contacto', ContactoSubmit::ADMIN_POST_ACTION );
	}
}
