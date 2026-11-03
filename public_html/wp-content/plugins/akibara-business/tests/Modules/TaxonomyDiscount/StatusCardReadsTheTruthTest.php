<?php
/**
 * La tarjeta que confirma el guardado tiene que leer de la BASE, entera.
 *
 * Es la pantalla en la que el dueño comprueba si su cambio entró. En producción
 * `get_option()` no lee la base: lee el blob `alloptions` de una caché de
 * objetos persistente que, justo después de guardar, puede ir por detrás. Cuando
 * eso pasa, la tarjeta le dice que su cambio no entró —cuando sí entró— y él
 * vuelve a guardar. Cada guardado de más mueve la huella editorial y encarece el
 * índice.
 *
 * El primer arreglo (1.115.12) sacó de la caché las DOS FECHAS y dejó dentro el
 * estado «Activo / Apagado», que se calcula con `Settings::is_enabled()` y por
 * debajo vuelve a `get_option()`. Media reparación es peor que ninguna: la
 * pantalla mezclaba dos verdades de instantes distintos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use PHPUnit\Framework\TestCase;

final class StatusCardReadsTheTruthTest extends TestCase {

	private function fuente_de_la_tarjeta(): string {
		$fuente = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/TaxonomyDiscount/TaxonomyDiscount.php'
		);

		$inicio = strpos( $fuente, 'private function render_status_card(): void {' );
		self::assertNotFalse( $inicio, 'La tarjeta de estado tiene que existir.' );

		$fin = strpos( $fuente, "\n\tprivate function render_active_rules_card", (int) $inicio );
		self::assertNotFalse( $fin, 'No se pudo acotar el cuerpo de la tarjeta.' );

		$cuerpo = substr( $fuente, (int) $inicio, (int) $fin - (int) $inicio );

		return self::sin_comentarios( $cuerpo );
	}

	/**
	 * El candado mide CÓDIGO, no prosa.
	 *
	 * Sin esto, el comentario que explica por qué la tarjeta no usa
	 * `get_option()` hace fallar la prueba que lo prohíbe. Es una trampa
	 * conocida de esta casa y ya ha mordido varias veces: el docblock que
	 * justifica una decisión contiene el literal que la prueba busca.
	 */
	private static function sin_comentarios( string $codigo ): string {
		$limpio = array();
		foreach ( explode( "\n", $codigo ) as $linea ) {
			$sin_espacios = ltrim( $linea );
			if ( str_starts_with( $sin_espacios, '//' ) || str_starts_with( $sin_espacios, '*' )
				|| str_starts_with( $sin_espacios, '/*' ) ) {
				continue;
			}
			$limpio[] = $linea;
		}

		return implode( "\n", $limpio );
	}

	/**
	 * @testdox La tarjeta de estado no lee ninguna option por caché.
	 */
	public function test_the_status_card_never_reads_through_the_cache(): void {
		$cuerpo = $this->fuente_de_la_tarjeta();

		self::assertStringNotContainsString(
			'get_option(',
			$cuerpo,
			'`get_option()` puede devolver el valor anterior justo después de guardar: es lo que hacía que el panel dijera que el cambio no entró.'
		);
	}

	/**
	 * @testdox El estado «Activo / Apagado» se decide con datos de la base.
	 */
	public function test_the_on_off_state_comes_from_the_database(): void {
		$cuerpo = $this->fuente_de_la_tarjeta();

		self::assertStringContainsString(
			'window_state_from_database()',
			$cuerpo,
			'La vigencia y el interruptor tienen que salir de la misma lectura, y de la base.'
		);
		self::assertStringNotContainsString(
			'$this->settings->is_enabled()',
			$cuerpo,
			'`is_enabled()` cae en `get_option()` fuera de modo estricto: es la caché otra vez.'
		);
	}

	/**
	 * R4-83d4b7c3 (2026-09-19): el candado de arriba sólo mide el literal
	 * `get_option(` DENTRO del cuerpo de la tarjeta. `get_sale_policy()`,
	 * `get_apply_to()` y `stacks_with_coupons()` se llaman por NOMBRE de
	 * método, no por ese literal, y las tres caen en `get_option()` por
	 * DEBAJO — el candado las dejaba pasar. Ahora la tarjeta tiene que sacar
	 * esas tres piezas de la MISMA lectura cruda que ya usa para from/to/enabled.
	 *
	 * @testdox Rebajas manuales, productos incluidos y cupones también salen de la lectura cruda, no de los getters tolerantes.
	 */
	public function test_the_three_extra_fields_also_come_from_the_database(): void {
		$cuerpo = $this->fuente_de_la_tarjeta();

		self::assertStringNotContainsString(
			'$this->settings->get_sale_policy()',
			$cuerpo,
			'get_sale_policy() cae en get_option() fuera de modo estricto/adoptado: es la caché rancia otra vez.'
		);
		self::assertStringNotContainsString(
			'$this->settings->get_apply_to()',
			$cuerpo,
			'get_apply_to() cae en get_option() fuera de modo estricto/adoptado: es la caché rancia otra vez.'
		);
		self::assertStringNotContainsString(
			'$this->settings->stacks_with_coupons()',
			$cuerpo,
			'stacks_with_coupons() cae en get_option() sin bypass: es la caché rancia otra vez.'
		);
		self::assertStringContainsString(
			"\$ventana['sale_policy']",
			$cuerpo
		);
		self::assertStringContainsString(
			"\$ventana['apply_to']",
			$cuerpo
		);
		self::assertStringContainsString(
			"\$ventana['stacks_coupons']",
			$cuerpo
		);
	}

	/**
	 * La comparación de la ventana tiene que vivir en UN solo sitio. Si la
	 * tarjeta se escribiera su propia versión, los dos criterios podrían
	 * separarse y la pantalla diría «Activo» sobre una promoción apagada.
	 *
	 * @testdox La regla de «hoy cae dentro de la ventana» es una sola.
	 */
	public function test_the_window_rule_has_a_single_implementation(): void {
		self::assertTrue(
			method_exists( Settings::class, 'window_covers' ),
			'La comparación tiene que estar expuesta para que la use quien la necesite, en vez de copiarla.'
		);

		self::assertTrue( Settings::window_covers( '', '', '2026-09-06' ), 'Sin fechas, la vigencia es indefinida.' );
		self::assertTrue( Settings::window_covers( '2026-09-05', '2026-09-06', '2026-09-06' ), 'El último día cuenta entero.' );
		self::assertTrue( Settings::window_covers( '2026-09-05', '2026-09-06', '2026-09-05' ), 'El primer día también.' );
		self::assertFalse( Settings::window_covers( '2026-09-05', '2026-09-06', '2026-09-07' ), 'Pasado el último día, se acabó.' );
		self::assertFalse( Settings::window_covers( '2026-09-05', '2026-09-06', '2026-09-04' ), 'Antes del primero, todavía no.' );
		self::assertTrue( Settings::window_covers( '2026-09-05', '', '2026-12-31' ), 'Sin fin declarado, no termina.' );
		self::assertTrue( Settings::window_covers( '', '2026-09-06', '2020-01-01' ), 'Sin inicio declarado, ya estaba viva.' );
		self::assertFalse( Settings::window_covers( '2026-09-05', '2026-09-06', '' ), 'Sin fecha del sitio no se puede afirmar que esté viva.' );
	}
}
