<?php
/**
 * Quien escribe «one piece 1» tiene que ver el tomo 1.
 *
 * EL DEFECTO, medido en producción el 2026-09-06: el desplegable del buscador
 * devolvía los tomos 100 a 107 y **nunca** el 1. Sólo acertaba escribiendo
 * «001», con los dos ceros, que no lo escribe nadie. Y no era cosa de One Piece:
 * pasaba con Kingdom, Blue Lock y Dan Da Dan. Los dos casos que parecían
 * funcionar («naruto 1», «berserk 1») acertaban **por casualidad**, casando con
 * otra serie cuyo título acaba en 01 — que es peor que fallar, porque parece que
 * funciona.
 *
 * LA CAUSA no era el matcher: el tomo 1 se indexa bajo `1`, `01` y `001`, y
 * siempre era candidato. Era el ORDEN. `«one piece 100»` empieza con la cadena
 * `«one piece 1»`, así que se llevaba TIER_TITLE_PREFIX (1000), mientras que
 * `«One Piece 001»` —el tomo que el cliente pidió— sumaba 100 de residual + 300
 * de bonus = 400. Mil gana a cuatrocientos, y el desplegable sólo enseña siete.
 *
 * LA PRUEBA DE QUE ERA UN DEFECTO y no una opinión: la página de resultados
 * completa SÍ ponía «One Piece 001» primero. El propio sitio ya tenía resuelto
 * el comportamiento correcto en una superficie y no en la otra, y la que se
 * equivocaba era la que el cliente ve primero.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Scorer;
use PHPUnit\Framework\TestCase;

final class TomoNombradoTest extends TestCase {

	/**
	 * @testdox El tomo que el cliente nombró gana al prefijo accidental de dígitos.
	 */
	public function test_el_tomo_pedido_gana_al_prefijo(): void {
		$pedido    = $this->puntuar( 'one piece 001', 'one piece', 1, 'one piece 1' );
		$accidental = $this->puntuar( 'one piece 100', 'one piece', 100, 'one piece 1' );

		self::assertGreaterThan(
			$accidental,
			$pedido,
			'«one piece 1» tiene que poner el tomo 1 por delante del 100.'
		);
	}

	/**
	 * @testdox También con el cero delante, que es la otra forma de escribirlo.
	 */
	public function test_tambien_con_cero_delante(): void {
		self::assertGreaterThan(
			$this->puntuar( 'one piece 010', 'one piece', 10, 'one piece 01' ),
			$this->puntuar( 'one piece 001', 'one piece', 1, 'one piece 01' )
		);
	}

	/**
	 * @testdox El prefijo accidental NO desaparece: sigue puntuando y sigue saliendo.
	 *
	 * Es la mitad que importa del arreglo. Quien va camino de «100» sigue
	 * tecleando y la lista se refina sola; si el tomo 100 desapareciera, se
	 * cambiaría un defecto por otro.
	 */
	public function test_el_prefijo_sigue_puntuando(): void {
		self::assertSame(
			Scorer::TIER_TITLE_PREFIX,
			$this->puntuar( 'one piece 100', 'one piece', 100, 'one piece 1' ),
			'El tomo 100 conserva su tier de prefijo: baja un puesto, no se cae de la lista.'
		);
	}

	/**
	 * @testdox RED-TEAM M5: «jujutsu kaisen 2» camino de «26» no colapsa al tomo 2.
	 *
	 * El caso declarado en el docblock de QueryEngine. Se sigue cumpliendo porque
	 * el conjunto de candidatos no cambia — sólo el orden.
	 */
	public function test_no_colapsa_el_caso_m5(): void {
		$tomo_2  = $this->puntuar( 'jujutsu kaisen 02', 'jujutsu kaisen', 2, 'jujutsu kaisen 2' );
		$tomo_26 = $this->puntuar( 'jujutsu kaisen 26', 'jujutsu kaisen', 26, 'jujutsu kaisen 2' );

		self::assertGreaterThan( $tomo_26, $tomo_2, 'El tomo 2 va primero: es lo que se escribió.' );
		self::assertGreaterThan( 0, $tomo_26, 'Y el 26 sigue en la lista, detrás.' );
	}

	/**
	 * @testdox Un tomo de OTRA serie no se cuela por compartir el número.
	 *
	 * La condición que hace seguro subir el tier. Sin ella, «one piece 1» sacaría
	 * primero a «Naruto 001» por tener el tomo 1, que es un defecto peor que el
	 * que se está arreglando.
	 */
	public function test_otra_serie_con_el_mismo_tomo_no_se_cuela(): void {
		$otra = $this->puntuar( 'naruto 001', 'naruto', 1, 'one piece 1' );

		self::assertLessThan(
			Scorer::TIER_TOMO_NOMBRADO,
			$otra,
			'El tomo 1 de Naruto no es el que pidió quien escribió «one piece».'
		);
	}

	/**
	 * @testdox Escribir sólo un número no nombra ninguna serie.
	 *
	 * Si «1» ganara el tier, todos los tomos 1 del catálogo saltarían a la cabeza
	 * de una consulta que no pide nada en concreto.
	 */
	public function test_solo_un_numero_no_gana_el_tier(): void {
		self::assertLessThan(
			Scorer::TIER_TOMO_NOMBRADO,
			$this->puntuar( 'one piece 001', 'one piece', 1, '1' )
		);
	}

	/**
	 * @testdox La serie también sirve para nombrar, no sólo el título.
	 *
	 * El título indexado no siempre lleva el nombre de la serie tal cual; el
	 * campo `serie_norm` existe justamente para eso.
	 */
	public function test_la_serie_tambien_nombra(): void {
		self::assertGreaterThanOrEqual(
			Scorer::TIER_TOMO_NOMBRADO,
			$this->puntuar( 'kanzenban 01', 'saint seiya', 1, 'saint seiya 1' )
		);
	}

	/**
	 * @testdox Un agotado no se cuela por delante de un comprable a igualdad de acierto.
	 *
	 * La penalización por disponibilidad se conserva: el tier nuevo se aplica
	 * ANTES de restarla, así que sigue decidiendo entre dos aciertos iguales.
	 */
	public function test_la_penalizacion_por_stock_sigue_mandando(): void {
		$comprable = $this->puntuar( 'one piece 001', 'one piece', 1, 'one piece 1', 0 );
		$agotado   = $this->puntuar( 'one piece 001', 'one piece', 1, 'one piece 1', 2 );

		self::assertGreaterThan( $agotado, $comprable );
	}

	/**
	 * @param string $title      title_norm del doc.
	 * @param string $serie      serie_norm del doc.
	 * @param int    $tomo       Tomo del doc.
	 * @param string $consulta   Lo que escribió el cliente (ya normalizado).
	 * @param int    $stock_rank 0 instock, 1 preventa, 2 agotado.
	 * @return int
	 */
	private function puntuar( string $title, string $serie, int $tomo, string $consulta, int $stock_rank = 0 ): int {
		return Scorer::score(
			array(
				'title_norm'   => $title,
				'serie_norm'   => $serie,
				'autores_norm' => '',
				'tomo'         => $tomo,
				'stock_rank'   => $stock_rank,
			),
			$consulta,
			array_values( array_filter( explode( ' ', $consulta ) ) )
		);
	}
}
