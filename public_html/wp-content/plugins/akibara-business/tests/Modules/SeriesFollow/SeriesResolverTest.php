<?php
/**
 * De un producto a «qué serie y qué tomo es».
 *
 * Los negativos son el grueso a propósito: cada uno describe un producto real
 * del catálogo que NO debe disparar un aviso.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesFollow\SeriesResolver;
use Akibara\Business\Services\VolumeVariant;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SeriesResolverTest extends TestCase {

	/** Marca `_akb_variante_tomo` del producto bajo prueba. */
	private string $variante = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->variante = '';
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_post_meta' )->alias(
			fn ( int $id, string $key = '', bool $single = false ): string => VolumeVariant::META_KEY === $key
				? $this->variante
				: ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, object> $terms Términos de pa_serie devueltos.
	 */
	private function stub_product( array $terms, string $title ): void {
		Functions\when( 'get_the_terms' )->justReturn( $terms );
		Functions\when( 'get_the_title' )->justReturn( $title );
	}

	private function term( int $term_id, string $name ): object {
		$term                   = new \stdClass();
		$term->term_id          = $term_id;
		$term->term_taxonomy_id = $term_id + 1000;
		$term->name             = $name;

		return $term;
	}

	/**
	 * R7-descarte-mal-explicado (2026-09-19): el aviso del resumen decía siempre
	 * «sin una única pa_serie o sin número de tomo» aunque el descarte real
	 * fuera una edición especial (producto 27916, «Demon Slayer 23 (Edición
	 * Especial)», con una serie y tomo 23).
	 *
	 * @testdox El motivo del descarte dice cuál de las tres puertas cerró: serie, número o variante.
	 */
	public function test_el_motivo_del_descarte_nombra_la_puerta(): void {
		$this->stub_product( array( $this->term( 5, 'Demon Slayer' ) ), 'Demon Slayer 23 (Edición Especial)' );
		$this->variante = VolumeVariant::KIND_EDICION_ESPECIAL;
		self::assertNull( SeriesResolver::resolve( 27916 ) );
		self::assertSame( 'variante', SeriesResolver::discard_reason( 27916 ) );
		self::assertSame( 'es una edición especial o variante, no un tomo nuevo', SeriesResolver::discard_reason_text( 'variante' ) );

		$this->variante = '';
		$this->stub_product( array( $this->term( 5, 'Demon Slayer' ) ), 'Demon Slayer Guía oficial' );
		self::assertSame( 'sin_numero_de_tomo', SeriesResolver::discard_reason( 27916 ) );

		$this->stub_product( array( $this->term( 5, 'A' ), $this->term( 6, 'B' ) ), 'Tomo 3' );
		self::assertSame( 'sin_serie_unica', SeriesResolver::discard_reason( 27916 ) );

		$this->stub_product( array( $this->term( 5, 'Demon Slayer' ) ), 'Demon Slayer 23' );
		self::assertSame( 'resuelto', SeriesResolver::discard_reason( 27916 ) );
	}

	/** @testdox Un producto con una sola pa_serie y número devuelve serie y tomo. */
	public function test_producto_con_una_pa_serie_y_numero_devuelve_serie_y_tomo(): void {
		$this->stub_product( array( $this->term( 42, 'One Piece' ) ), 'One Piece 107' );

		self::assertSame(
			array(
				'term_id'          => 42,
				'term_taxonomy_id' => 1042,
				'name'             => 'One Piece',
				'volume'           => 107,
			),
			SeriesResolver::resolve( 900 )
		);
	}

	/**
	 * @testdox [NEG] Una variante marcada del mismo tomo no es un tomo nuevo.
	 *
	 * Es el corte que cierra el riesgo grande: sin él, meter «Demon Slayer 23
	 * (Edición Especial)» en su serie anuncia el tomo 23 por segunda vez.
	 */
	public function test_una_variante_marcada_no_se_resuelve(): void {
		$this->variante = VolumeVariant::KIND_EDICION_ESPECIAL;
		$this->stub_product( array( $this->term( 435, 'Demon Slayer' ) ), 'Demon Slayer 23 (Edición Especial)' );

		self::assertNull( SeriesResolver::resolve( 27916 ) );
	}

	/** @testdox Un valor de marca desconocido NO convierte un tomo normal en variante. */
	public function test_una_marca_fuera_del_enum_no_silencia_el_tomo(): void {
		$this->variante = 'lo-que-sea';
		$this->stub_product( array( $this->term( 435, 'Demon Slayer' ) ), 'Demon Slayer 23' );

		$resolved = SeriesResolver::resolve( 24549 );

		self::assertIsArray( $resolved );
		self::assertSame( 23, $resolved['volume'] );
	}

	/** @testdox [REG] El tomo normal con el MISMO número se sigue resolviendo. */
	public function test_el_tomo_normal_del_mismo_numero_se_sigue_resolviendo(): void {
		$this->stub_product( array( $this->term( 435, 'Demon Slayer' ) ), 'Demon Slayer 23' );

		self::assertSame(
			array(
				'term_id'          => 435,
				'term_taxonomy_id' => 1435,
				'name'             => 'Demon Slayer',
				'volume'           => 23,
			),
			SeriesResolver::resolve( 24549 )
		);
	}

	/** @testdox [NEG] Un producto sin pa_serie no se puede clasificar. */
	public function test_producto_sin_pa_serie_devuelve_null(): void {
		$this->stub_product( array(), 'One Piece 107' );

		self::assertNull( SeriesResolver::resolve( 900 ) );
	}

	/** @testdox [NEG] Un producto con DOS pa_serie es inclasificable. */
	public function test_producto_con_dos_pa_serie_devuelve_null(): void {
		$this->stub_product(
			array( $this->term( 42, 'One Piece' ), $this->term( 43, 'One Piece Omnibus' ) ),
			'One Piece 107'
		);

		self::assertNull( SeriesResolver::resolve( 900 ) );
	}

	/**
	 * @testdox [NEG] Un título sin número inequívoco no se avisa.
	 * @dataProvider titulosAmbiguos
	 */
	public function test_titulo_sin_numero_inequivoco_devuelve_null( string $titulo ): void {
		$this->stub_product( array( $this->term( 42, 'Serie' ) ), $titulo );

		self::assertNull(
			SeriesResolver::resolve( 900 ),
			sprintf( '«%s» no describe un tomo lineal y no puede disparar un aviso.', $titulo )
		);
	}

	/** @return array<string, array{0: string}> */
	public static function titulosAmbiguos(): array {
		return array(
			'pase de expansión' => array( 'Shangri La Frontier 1 Expansion Pass' ),
			'edición decimal'   => array( 'One Piece 1.5' ),
			'sin número'        => array( 'Berserk Artbook' ),
		);
	}

	/**
	 * El tomo 0 existe en el catálogo (precuelas, números 0 promocionales) y
	 * `VolumeTitle` lo descarta a propósito. Avisar «salió el tomo 0» a quien ya
	 * tiene el 5 sería peor que no avisar.
	 *
	 * @testdox [NEG] El tomo 0 no se avisa nunca.
	 */
	public function test_tomo_cero_devuelve_null(): void {
		$this->stub_product( array( $this->term( 42, 'Berserk' ) ), 'Berserk 0' );

		self::assertNull( SeriesResolver::resolve( 900 ) );
	}
}
