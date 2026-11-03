<?php
/**
 * Tests for the Chilean commune directory.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\CartCheckoutBlocks\ComunaDirectory;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class ComunaDirectoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string,array<int,array{name:string,code:string}>>
	 */
	private function fixture_catalog(): array {
		return array(
			'CL-RM' => array(
				array(
					'name' => 'Ñuñoa',
					'code' => 'NUN',
				),
				array(
					'name' => 'San Miguel',
					'code' => 'SMG',
				),
				array(
					'name' => 'Providencia',
					'code' => 'PRO',
				),
			),
			'CL-VS' => array(
				array(
					'name' => 'Viña del Mar',
					'code' => 'KNA',
				),
			),
		);
	}

	private function directory(): ComunaDirectory {
		return new ComunaDirectory( fn(): array => $this->fixture_catalog() );
	}

	/**
	 * @testdox Should normalize accents, case, spacing and stray punctuation.
	 */
	public function test_normalization(): void {
		self::assertSame( 'nunoa', ComunaDirectory::normalize( 'Ñuñoa' ) );
		self::assertSame( 'vina del mar', ComunaDirectory::normalize( '  Viña   del Mar. ' ) );
		self::assertSame( 'san miguel', ComunaDirectory::normalize( 'SAN MIGUEL,' ) );
		self::assertSame( '', ComunaDirectory::normalize( ' .,; ' ) );
	}

	/**
	 * @testdox Acepta el espacio duro y la forma descompuesta que el combobox ya acepta.
	 *
	 * El gemelo JS descompone a NFD, borra las marcas y colapsa `\s` (que en
	 * JavaScript incluye U+00A0). El servidor usaba un mapa de precompuestos y
	 * `preg_replace('/\s+/', ...)` SIN `/u`, byte a byte: no veía ninguna de las
	 * dos formas. Consecuencia medida: el cliente elegía «Viña del Mar» de la
	 * lista, el autocompletado de dirección pegaba el nombre con espacio duro, y
	 * `validate_chilean_comuna()` respondía «No encontramos la comuna» — el
	 * pedido no se podía pagar con una comuna que la propia lista había aceptado.
	 */
	public function test_normalization_matches_the_javascript_twin(): void {
		$nbsp = "Viña\u{00A0}del\u{00A0}Mar";
		$nfd  = "N\u{0303}un\u{0303}oa";

		self::assertSame( 'vina del mar', ComunaDirectory::normalize( $nbsp ) );
		self::assertSame( 'nunoa', ComunaDirectory::normalize( $nfd ) );
		self::assertSame(
			ComunaDirectory::normalize( 'Viña del Mar' ),
			ComunaDirectory::normalize( $nbsp )
		);
		self::assertSame(
			ComunaDirectory::normalize( 'Ñuñoa' ),
			ComunaDirectory::normalize( $nfd )
		);
	}

	/**
	 * @testdox Canoniza la comuna del cliente aunque llegue con espacio duro o en NFD.
	 */
	public function test_match_accepts_hard_spaces_and_decomposed_input(): void {
		$directory = $this->directory();

		self::assertSame(
			array(
				'name'   => 'Viña del Mar',
				'region' => 'CL-VS',
			),
			$directory->match( "Viña\u{00A0}del\u{00A0}Mar" )
		);
		self::assertSame(
			array(
				'name'   => 'Ñuñoa',
				'region' => 'CL-RM',
			),
			$directory->match( "N\u{0303}un\u{0303}oa" )
		);
	}

	/**
	 * @testdox Should match typed communes ignoring accents and report their region.
	 */
	public function test_match_is_accent_insensitive_and_carries_region(): void {
		$directory = $this->directory();

		self::assertSame(
			array(
				'name'   => 'Ñuñoa',
				'region' => 'CL-RM',
			),
			$directory->match( 'nunoa' )
		);
		self::assertSame(
			array(
				'name'   => 'Viña del Mar',
				'region' => 'CL-VS',
			),
			$directory->match( 'VIÑA DEL MAR' )
		);
		self::assertNull( $directory->match( 'Comuna Inexistente' ) );
		self::assertNull( $directory->match( '' ) );
	}

	/**
	 * @testdox Should suggest the closest commune for a typo, preferring the selected region.
	 */
	public function test_suggestions(): void {
		$directory = $this->directory();

		self::assertSame( 'Providencia', $directory->suggest( 'Probidencia', 'CL-RM' ) );
		self::assertSame( 'Ñuñoa', $directory->suggest( 'nunua', 'CL-RM' ) );
		self::assertNull( $directory->suggest( 'xyzabc', 'CL-RM' ) );
	}

	/**
	 * @testdox Should fail open when the vendor loader throws or returns garbage.
	 */
	public function test_fails_open_on_loader_problems(): void {
		$throwing = new ComunaDirectory(
			static function (): array {
				throw new \RuntimeException( 'vendor exploded' );
			}
		);
		self::assertFalse( $throwing->is_available() );
		self::assertNull( $throwing->match( 'Ñuñoa' ) );
		self::assertSame( array(), $throwing->all_by_region() );

		$garbage = new ComunaDirectory( static fn(): string => 'not-an-array' );
		self::assertFalse( $garbage->is_available() );

		$malformed = new ComunaDirectory(
			static fn(): array => array(
				'CL-RM' => array( array( 'code' => 'SMG' ), 'plain-string', array( 'name' => '' ) ),
			)
		);
		self::assertFalse( $malformed->is_available() );
	}

	/**
	 * @testdox Should expose the catalog keyed by region for the frontend payload.
	 */
	public function test_all_by_region_shape(): void {
		$directory = $this->directory();

		self::assertSame(
			array(
				'CL-RM' => array( 'Ñuñoa', 'San Miguel', 'Providencia' ),
				'CL-VS' => array( 'Viña del Mar' ),
			),
			$directory->all_by_region()
		);
		self::assertTrue( $directory->is_available() );
	}

	/**
	 * @testdox Should let the kill-switch filter empty the catalog and disable the feature.
	 */
	public function test_kill_switch_filter(): void {
		Monkey\Filters\expectApplied( 'akibara_comuna_directory' )
			->once()
			->andReturn( array() );

		$directory = $this->directory();

		self::assertFalse( $directory->is_available() );
		self::assertNull( $directory->match( 'Ñuñoa' ) );
	}

	/**
	 * @testdox Nunca sugiere una comuna de OTRA región cuando la región se conoce.
	 *
	 * La puntuación era `distancia * 2 + ( misma_región ? 0 : 1 )`, así que la
	 * región sólo desempataba: una comuna de otra región a distancia 1 (score 3)
	 * le ganaba a la correcta a distancia 2 (score 4). Medido el 2026-08-23 con
	 * el catálogo real: «Renaca» proponía «Renca» (Santiago) aunque la región
	 * elegida fuera Valparaíso. Si el cliente aceptaba, su dirección quedaba en
	 * otra región — cotización de envío equivocada y paquete a otro destino.
	 */
	public function test_never_suggests_a_commune_from_another_region(): void {
		$directory = $this->directory();

		self::assertSame(
			'Viña del Mar',
			$directory->suggest( 'Vina del Mar', 'CL-VS' ),
			'Dentro de su región sí corrige el tipeo.'
		);
		self::assertNull(
			$directory->suggest( 'Vina del Mar', 'CL-RM' ),
			'Con la región equivocada prefiere NO sugerir antes que cruzar de región.'
		);
		self::assertNull(
			$directory->suggest( 'Providencia', 'CL-VS' ),
			'Ni siquiera una coincidencia exacta puede saltar de región.'
		);
	}

	/**
	 * @testdox Con entradas muy cortas no inventa: exige una coincidencia mucho más estrecha.
	 *
	 * Con el umbral fijo de 2, una entrada de cuatro letras admitía cambiar la
	 * MITAD de la palabra. En el catálogo real «Vina» sugería «Pica» —comuna de
	 * Tarapacá, a 1.800 km de Viña del Mar— con toda seguridad.
	 */
	public function test_short_input_does_not_match_a_different_word(): void {
		$directory = $this->directory();

		// «Nuno» (4) está a distancia 1 de «nunoa»: sigue siendo un tipeo creíble.
		self::assertSame( 'Ñuñoa', $directory->suggest( 'Nuno', 'CL-RM' ) );

		// «Vina» (4) está a distancia 8 de «vina del mar»: no es un tipeo, y no
		// puede caer en cualquier otra comuna que quede a dos ediciones.
		self::assertNull( $directory->suggest( 'Vina', 'CL-VS' ) );
	}
}
