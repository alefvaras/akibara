<?php
/**
 * Cambiar el descuento fuera del panel tiene que purgar la caché.
 *
 * Este purgador existe, según su propio comentario, para «cubrir el
 * `wp option update` que no pasa por el formulario». No lo cubría: estaba
 * enganchado a `updated_option_{$option}`, un hook que WordPress no dispara
 * nunca. Core usa `update_option_{$option}` (wp-includes/option.php:1019, y
 * salta DESPUÉS de escribir con éxito) y `add_option_{$option}` (:1176); las
 * formas en pasado, `added_option` y `updated_option`, van sin sufijo.
 *
 * Consecuencia real mientras estuvo mal: bajar el tope de descuento por línea de
 * comandos cambiaba el precio y dejaba las fichas cacheadas con el precio viejo,
 * sin que nada avisara.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\LscPurger;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Utilities\DiscountCeiling;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LscPurgerHooksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		unset( $_POST['akibara_panel_save'] );
	}

	protected function tearDown(): void {
		unset( $_POST['akibara_panel_save'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Se engancha a los hooks que WordPress sí dispara, para cada option de precio.
	 */
	public function test_it_listens_on_hooks_that_actually_fire(): void {
		( new LscPurger() )->register();

		foreach ( Settings::OPTIONS as $option ) {
			self::assertNotFalse(
				has_action( 'update_option_' . $option ),
				'Sin esto, cambiar ' . $option . ' fuera del panel deja la caché con el precio viejo.'
			);
			self::assertNotFalse(
				has_action( 'add_option_' . $option ),
				'La primera escritura crea la fila y sólo dispara add_option_{option}.'
			);
		}

		self::assertNotFalse( has_action( 'update_option_' . DiscountCeiling::OPTION ) );
		self::assertNotFalse( has_action( 'add_option_' . DiscountCeiling::OPTION ) );
	}

	/**
	 * El hook viejo no puede volver: enganchado ahí, el purgador no corre nunca
	 * y el fichero sigue diciendo que cubre algo que no cubre.
	 *
	 * @testdox No queda ningún enganche a los hooks que no existen.
	 */
	public function test_the_ghost_hooks_are_gone(): void {
		( new LscPurger() )->register();

		foreach ( Settings::OPTIONS as $option ) {
			self::assertFalse(
				has_action( 'updated_option_' . $option ),
				'`updated_option_{option}` no lo dispara nadie: la forma en pasado va sin sufijo.'
			);
		}
	}

	/**
	 * BORRAR una option de precio cambia el precio igual que escribirla, y no lo
	 * cubría ningún hook. No es hipotético: la madrugada del 2026-09-06 alguien
	 * creó y borró `akb_discount_max_pct` en producción, que es exactamente
	 * `DiscountCeiling::OPTION`. Con el borrado sin cubrir, las fichas se quedan
	 * cacheadas con el precio de antes.
	 *
	 * Core dispara `delete_option_{$option}` (wp-includes/option.php:1264),
	 * después de borrar con éxito. La forma en pasado, `deleted_option`, va sin
	 * sufijo — la misma trampa de nombres de siempre.
	 *
	 * @testdox También se escucha el BORRADO de cada option de precio.
	 */
	public function test_it_listens_on_deletes_too(): void {
		( new LscPurger() )->register();

		foreach ( Settings::OPTIONS as $option ) {
			self::assertNotFalse(
				has_action( 'delete_option_' . $option ),
				'Borrar ' . $option . ' cambia el precio y tiene que purgar igual que escribirla.'
			);
		}

		self::assertNotFalse(
			has_action( 'delete_option_' . DiscountCeiling::OPTION ),
			'El tope es la option que de verdad se borra a mano cuando algo va mal.'
		);
	}

	/**
	 * @testdox No se escucha `deleted_option_`, que tampoco existe.
	 */
	public function test_the_past_tense_delete_hook_is_not_used(): void {
		( new LscPurger() )->register();

		foreach ( Settings::OPTIONS as $option ) {
			self::assertFalse(
				has_action( 'deleted_option_' . $option ),
				'`deleted_option` va sin sufijo: con sufijo no lo dispara nadie.'
			);
		}
	}

	/**
	 * @testdox Una escritura fuera del panel sí programa la purga.
	 */
	public function test_a_write_outside_the_panel_schedules_the_purge(): void {
		( new LscPurger() )->schedule_now();

		self::assertNotFalse(
			has_action( 'shutdown' ),
			'Cambiar el descuento por CLI tiene que dejar programada la purga.'
		);
	}

	/**
	 * Purgar el borde devuelve al origen las ~1.568 fichas, las 106 landings y
	 * la portada. Hacerlo dos veces en el mismo guardado es pagarlo dos veces.
	 *
	 * @testdox Si el guardado viene del panel, no se purga por duplicado.
	 */
	public function test_it_stands_down_when_the_panel_is_already_purging(): void {
		$_POST['akibara_panel_save'] = 'Guardar ajustes';

		( new LscPurger() )->schedule_now();

		self::assertFalse(
			has_action( 'shutdown' ),
			'El panel ya purga en el mismo shutdown: hacerlo otra vez devuelve al origen 1.568 fichas por gusto.'
		);
	}

	/**
	 * @testdox Dos escrituras en la misma petición purgan una sola vez.
	 */
	public function test_two_writes_in_one_request_purge_once(): void {
		Actions\expectAdded( 'shutdown' )->once();

		$purgador = new LscPurger();
		$purgador->schedule_now();
		$purgador->schedule_now();
		$purgador->schedule_now();

		self::assertTrue( true, 'La expectativa la verifica Brain Monkey al cerrar el test.' );
	}

	/**
	 * @testdox La purga que se dispara es la total de LiteSpeed.
	 */
	public function test_the_purge_is_the_litespeed_one(): void {
		Actions\expectDone( 'litespeed_purge_all' )->once();

		( new LscPurger() )->do_purge();

		self::assertTrue( true, 'La expectativa la verifica Brain Monkey al cerrar el test.' );
	}
}
