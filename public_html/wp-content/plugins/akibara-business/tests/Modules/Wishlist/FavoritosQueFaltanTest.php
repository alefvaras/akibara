<?php
/**
 * Favoritos no puede decir un número y pintar otro sin explicarlo — y, si
 * explica, la explicación tiene que ser cierta.
 *
 * Dos hallazgos de la ronda de QA (S-2fab1560 y S-de2e7a74): el encabezado
 * cuenta lo guardado —bien— pero la grilla pinta como mucho 100 y excluye lo
 * despublicado o fuera del catálogo. El cliente veía «137 productos guardados»
 * con 100 tarjetas, o «3 productos guardados» con ninguna, y ni una palabra.
 *
 * REAPERTURA (S-2fab1560, 2026-09-19): el primer cierre fundió las dos causas
 * de que falten tarjetas —la cota MAX_ITEMS_RENDERED (nunca se consultaron) y
 * que la consulta excluya despublicados/ocultos (se consultaron y no
 * sobrevivieron)— en una sola frase que, para la cota, afirma una
 * indisponibilidad falsa: con 137 guardados y los 100 primeros publicados,
 * decía «Mostramos 100; el resto no está disponible ahora mismo» sobre 37
 * tomos que SÍ estaban a la venta. Esta prueba deja de fijar esa frase (el
 * test viejo exigía literalmente `if ( $pintados < $total )` en el fuente,
 * lo que impedía cualquier arreglo correcto) y en su lugar prueba el
 * COMPORTAMIENTO de `Render::aviso_grilla()`, que es pura y no necesita WP.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Wishlist;

use Akibara\Business\Modules\Wishlist\Render;
use PHPUnit\Framework\TestCase;

final class FavoritosQueFaltanTest extends TestCase {

	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Wishlist/Render.php' );
	}

	/**
	 * El escenario EXACTO del hallazgo: 137 guardados, los 100 primeros
	 * publicados y en catálogo. Los 37 restantes nunca se consultaron (la
	 * cota los recortó antes del WP_Query), así que no se puede decir que
	 * "no están disponibles".
	 *
	 * @testdox Con la cota de por medio, el aviso NO dice que lo recortado esté indisponible.
	 */
	public function test_lo_recortado_por_la_cota_no_se_declara_indisponible(): void {
		$aviso = Render::aviso_grilla( 137, 100, 100 );

		self::assertStringContainsString( 'más recientes', $aviso );
		self::assertStringNotContainsString( 'no está', $aviso );
		self::assertStringNotContainsString( 'no están', $aviso );
	}

	/** @testdox Sin recorte y sin ocultos, no hay nada que avisar. */
	public function test_sin_diferencia_no_hay_aviso(): void {
		self::assertSame( '', Render::aviso_grilla( 3, 3, 3 ) );
	}

	/** @testdox Con todo guardado consultado y ninguno disponible, se dice "ninguno". */
	public function test_cero_tarjetas_sin_recorte_dice_ninguno(): void {
		$aviso = Render::aviso_grilla( 3, 3, 0 );

		self::assertStringContainsString( 'Ninguno está disponible ahora mismo', $aviso );
	}

	/**
	 * Las dos causas a la vez: de 137 guardados se consultaron 100 (37
	 * recortados por la cota) y de esos 100 sólo 98 sobrevivieron a la
	 * consulta (2 ocultos/despublicados). El aviso tiene que nombrar las DOS,
	 * con el número correcto para cada una (2 ocultos, no 37).
	 *
	 * @testdox Cota y ocultamiento a la vez se avisan por separado, cada uno con su número.
	 */
	public function test_las_dos_causas_a_la_vez_se_avisan_por_separado(): void {
		$aviso = Render::aviso_grilla( 137, 100, 98 );

		self::assertStringContainsString( '2 de los que guardaste no están disponibles', $aviso );
		self::assertStringContainsString( 'más recientes', $aviso );
		self::assertStringNotContainsString( '37', $aviso );
	}

	/**
	 * Con la cota de por medio pero CERO tarjetas pintadas, no se puede
	 * afirmar "ninguno" sobre el total: de los recortados no se sabe nada.
	 *
	 * @testdox Cero tarjetas con recorte no dice "ninguno" del total, avisa de los no consultados.
	 */
	public function test_cero_tarjetas_con_recorte_no_dice_ninguno_del_total(): void {
		$aviso = Render::aviso_grilla( 137, 100, 0 );

		self::assertStringNotContainsString( 'Ninguno está disponible ahora mismo', $aviso );
		self::assertStringContainsString( '100', $aviso );
		self::assertStringContainsString( '37', $aviso );
	}

	/** @testdox El total sigue saliendo del Storage, no de la consulta recortada. */
	public function test_el_total_no_se_cuenta_desde_la_grilla(): void {
		$src = $this->source();

		self::assertStringContainsString( '$total = Storage::count();', $src );
		self::assertStringNotContainsString( '$total = count( $product_ids )', $src );
	}

	/** @testdox La nota tiene su estilo propio: no se cuela como texto suelto. */
	public function test_la_nota_tiene_clase(): void {
		self::assertStringContainsString( 'akb-wl-grid__nota', $this->source() );
		self::assertStringContainsString(
			'.akb-wl-grid__nota',
			(string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/wishlist.css' )
		);
	}
}
