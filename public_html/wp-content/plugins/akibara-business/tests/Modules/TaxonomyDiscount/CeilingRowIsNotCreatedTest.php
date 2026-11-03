<?php
/**
 * Guardar la pestaña Descuentos no puede CREAR el tope si nadie lo tocó.
 *
 * El 2026-09-05 el dueño puso la promo del fin de semana desde el panel. El
 * panel la puso bien, y el mismo guardado dejó la tienda 30 minutos sin buscador
 * ni filtros: WooCommerce escribe todos los campos de la pestaña, así que creó
 * `akb_discount_max_pct` —una fila que estaba deliberadamente ausente— y crearla
 * mueve la huella de la fuente editorial. La huella se movió con un rebuild en
 * vuelo y el índice se quedó a medias, en silencio.
 *
 * «No existe» y «existe con el default» dan el mismo 15 al leer, y son cosas
 * distintas para la foto: el primero la deja quieta, el segundo la mueve.
 *
 * La invariante no cambió; el sitio donde vive, sí. La primera versión puso una
 * guarda propia del tope en `pre_update_option_akb_discount_max_pct`, y ese
 * mismo día se comprobó que encadenarla con la del panel producía un aviso
 * falso en cada guardado. Ahora hay UNA sola guarda, la del panel, que cubre
 * todos los campos de todas las pestañas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\TaxonomyDiscount\TaxonomyDiscount;
use Akibara\Business\Utilities\DiscountCeiling;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class CeilingRowIsNotCreatedTest extends TestCase {

	/** @var array<string, mixed> */
	private array $filas = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->filas = array();

		Functions\when( 'get_option' )->alias(
			fn( string $nombre, $default = false ) => array_key_exists( $nombre, $this->filas )
				? $this->filas[ $nombre ]
				: $default
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Panel con el mapa de defaults que produce la pestaña Descuentos. */
	private function panel(): AdminPanel {
		$panel = new AdminPanel();
		$prop  = new ReflectionProperty( AdminPanel::class, 'untouched_defaults' );
		$prop->setAccessible( true );
		$prop->setValue( $panel, array( DiscountCeiling::OPTION => (string) DiscountCeiling::DEFAULT_PCT ) );

		return $panel;
	}

	/** @return array<int, string> */
	private function acusados( AdminPanel $panel ): array {
		$metodo = new ReflectionMethod( AdminPanel::class, 'options_that_did_not_stick' );
		$metodo->setAccessible( true );

		return (array) $metodo->invoke( $panel );
	}

	/**
	 * `update_option()` no escribe cuando el filtro devuelve el valor viejo, así
	 * que devolverlo es la forma de decir «no crees esta fila».
	 *
	 * @testdox Con la fila ausente y el default en el formulario, no se crea nada.
	 */
	public function test_an_untouched_default_does_not_create_the_row(): void {
		$viejo = false; // Lo que da `get_option()` cuando la fila no existe.

		$resultado = $this->panel()->skip_untouched_default(
			DiscountCeiling::DEFAULT_PCT,
			DiscountCeiling::OPTION,
			$viejo
		);

		self::assertSame(
			$viejo,
			$resultado,
			'Devolver el valor viejo es lo que hace que WordPress no escriba: crear la fila mueve la huella editorial.'
		);
	}

	/**
	 * El otro medio incidente: no basta con no crearla, además hay que no
	 * acusarla. El aviso de «no se guardó todo» remata con «vuelve a pulsar
	 * Guardar», y guardar otra vez es justo lo que mueve la huella.
	 *
	 * @testdox La fila que no se creó a propósito NO sale como escritura fallida.
	 */
	public function test_the_row_left_absent_is_not_reported_as_a_failure(): void {
		$panel = $this->panel();
		$panel->skip_untouched_default( DiscountCeiling::DEFAULT_PCT, DiscountCeiling::OPTION, false );

		self::assertSame(
			array(),
			$this->acusados( $panel ),
			'Nadie intentó escribirla: acusarla empuja al dueño a un reintento que no hace falta.'
		);
	}

	/**
	 * @testdox Si el operador escribe otro tope, se guarda aunque la fila no existiera.
	 */
	public function test_a_real_change_is_always_saved(): void {
		foreach ( array( 1, 10, 25, 90 ) as $nuevo ) {
			self::assertSame(
				$nuevo,
				$this->panel()->skip_untouched_default( $nuevo, DiscountCeiling::OPTION, false ),
				'Esto no le quita el mando a nadie: un valor distinto del default se guarda.'
			);
		}
	}

	/**
	 * @testdox Con la fila ya existente, guardar el default la actualiza con normalidad.
	 */
	public function test_an_existing_row_keeps_saving_normally(): void {
		$this->filas[ DiscountCeiling::OPTION ] = '20';

		self::assertSame(
			DiscountCeiling::DEFAULT_PCT,
			$this->panel()->skip_untouched_default( DiscountCeiling::DEFAULT_PCT, DiscountCeiling::OPTION, '20' ),
			'Quien ya tiene la fila puede volver al valor por defecto cuando quiera.'
		);
	}

	/**
	 * Una segunda guarda sobre la misma option vuelve a producir el aviso falso:
	 * la de fuera devuelve el valor viejo, la del panel recibe ya ese valor y
	 * deja de reconocerlo como «el default intacto».
	 *
	 * @testdox El tope no lleva una guarda propia: la del panel es la única.
	 */
	public function test_the_ceiling_has_no_second_guard_of_its_own(): void {
		self::assertFalse(
			method_exists( TaxonomyDiscount::class, 'keep_ceiling_absent_when_untouched' ),
			'Dos guardas encadenadas sobre la misma option acusaron al tope en CADA guardado de la pestaña.'
		);
	}

	/**
	 * La guarda compara lo enviado contra el `default` DECLARADO en el campo. Si
	 * el campo declarase otro número que la constante, la guarda quedaría muerta
	 * sin que nada fallara: el formulario pintaría un valor y la comparación
	 * esperaría otro.
	 *
	 * @testdox El campo del tope declara como default la misma constante que lee el código.
	 */
	public function test_the_field_declares_the_same_default_the_code_reads(): void {
		$fuente = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/TaxonomyDiscount/TaxonomyDiscount.php'
		);

		self::assertStringContainsString(
			"'default'           => (string) DiscountCeiling::DEFAULT_PCT,",
			$fuente,
			'El default pintado y el comparado tienen que salir de la misma constante.'
		);
	}
}
