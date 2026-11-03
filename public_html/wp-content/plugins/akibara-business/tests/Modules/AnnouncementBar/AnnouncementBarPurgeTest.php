<?php
/**
 * S-d2980364 (2026-09-19): la barra de anuncios purgaba el borde hasta NUEVE
 * veces por guardado, una por cada `add_action` propio (cinco options + cuatro
 * del carril promocional), EN LÍNEA dentro del mismo `woocommerce_update_
 * options()` que las escribe todas. Y encima, `AdminPanel::handle_save()` ya
 * programa su propia purga en `shutdown` para cualquier cambio real de la
 * pestaña `announcement` (no está en `SECTIONS_WITHOUT_CACHED_SURFACE`), así
 * que un guardado por el panel purgaba diez veces en total.
 *
 * El mismo defecto ya se había corregido para el descuento por taxonomía —ver
 * `LscPurgerHooksTest`—: programar en `shutdown`, deduplicar por petición, y
 * bajarse cuando el panel ya se encarga.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AnnouncementBar;

use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AnnouncementBarPurgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		unset( $_POST['akibara_panel_save'] );
		// `register()` también engancha `maybe_init_bar` al hook `wp` cuando NO es
		// admin; nada de eso importa para esta prueba de purga, pero el símbolo
		// tiene que existir para que `register()` corra.
		Functions\when( 'is_admin' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $_POST['akibara_panel_save'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Se engancha a los hooks que WordPress sí dispara, para cada option de la barra.
	 */
	public function test_it_listens_on_hooks_for_every_bar_option(): void {
		( new AnnouncementBar() )->register();

		foreach ( $this->opciones_purgables() as $option ) {
			self::assertNotFalse(
				has_action( 'update_option_' . $option ),
				'Sin esto, cambiar ' . $option . ' fuera del panel deja la caché con la barra vieja.'
			);
			self::assertNotFalse(
				has_action( 'add_option_' . $option ),
				'La primera escritura crea la fila y sólo dispara add_option_{option}.'
			);
		}
	}

	/**
	 * EL CANDADO QUE IMPORTA. Nueve escrituras en la misma petición —el caso real
	 * de guardar la pestaña entera— programaban nueve `do_action` inmediatos.
	 * Con el arreglo, todas comparten un único `shutdown`.
	 *
	 * @testdox Nueve escrituras en el mismo guardado programan un solo shutdown.
	 */
	public function test_nine_writes_in_one_request_schedule_the_purge_once(): void {
		Actions\expectAdded( 'shutdown' )->once();

		$barra = new AnnouncementBar();
		foreach ( $this->opciones_purgables() as $option ) {
			unset( $option );
			$barra->schedule_purge();
		}

		self::assertTrue( true, 'La expectativa la verifica Brain Monkey al cerrar el test.' );
	}

	/**
	 * Purgar el borde devuelve al origen las ~1.568 fichas, las 106 landings y
	 * la portada: hacerlo dos veces en el mismo guardado —una desde acá y otra
	 * desde `AdminPanel::handle_save()`— es pagarlo dos veces.
	 *
	 * @testdox Si el guardado viene del panel, no se purga por duplicado.
	 */
	public function test_it_stands_down_when_the_panel_is_already_purging(): void {
		$_POST['akibara_panel_save'] = 'Guardar ajustes';

		( new AnnouncementBar() )->schedule_purge();

		self::assertFalse(
			has_action( 'shutdown' ),
			'El panel ya purga en el mismo shutdown: hacerlo otra vez devuelve al origen 1.568 fichas por gusto.'
		);
	}

	/**
	 * @testdox La purga que se dispara es la total de LiteSpeed.
	 */
	public function test_the_purge_is_the_litespeed_one(): void {
		Actions\expectDone( 'litespeed_purge_all' )->once();

		( new AnnouncementBar() )->do_purge();

		self::assertTrue( true, 'La expectativa la verifica Brain Monkey al cerrar el test.' );
	}

	/**
	 * @return array<int, string>
	 */
	private function opciones_purgables(): array {
		return array(
			'akibara_announcement_enabled',
			'akibara_announcement_messages',
			'akibara_announcement_bg',
			'akibara_announcement_color',
			'akibara_announcement_border',
			'akibara_announcement_promo_text',
			'akibara_announcement_promo_from',
			'akibara_announcement_promo_to',
			'akibara_announcement_promo_follow_discount',
		);
	}
}
