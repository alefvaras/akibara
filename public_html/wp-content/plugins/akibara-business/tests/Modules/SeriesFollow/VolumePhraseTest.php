<?php
/**
 * Cómo se nombran los tomos de una tanda, sin prometer lo que no hay.
 *
 * «71 al 74» PROMETE el 72 y el 73. Esta suite existe porque las tandas reales
 * del catálogo tienen huecos —Fire Force salió 1 y 5..33; Demon Slayer 2,3,4,5 y
 * 21,22— y en esos casos un rango sería una afirmación falsa en la bandeja del
 * cliente. Ninguna frase dice nunca «todos».
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesFollow\VolumePhrase;
use PHPUnit\Framework\TestCase;

final class VolumePhraseTest extends TestCase {

	/** @testdox Un solo tomo es su número, y nada más. */
	public function test_un_solo_tomo_es_su_numero(): void {
		self::assertSame( '74', VolumePhrase::of( array( 74 ) ) );
	}

	/** @testdox Dos tomos se unen con «y»: dos contiguos NO son un rango. */
	public function test_dos_tomos_se_unen_con_y(): void {
		$frase = VolumePhrase::of( array( 73, 74 ) );

		self::assertSame( '73 y 74', $frase );
		self::assertStringNotContainsString( ' al ', $frase, 'Un rango de dos se lee peor que la lista.' );
	}

	/** @testdox Tres o más contiguos se leen como rango. */
	public function test_tres_o_mas_contiguos_se_leen_como_rango(): void {
		self::assertSame( '71 al 74', VolumePhrase::of( array( 71, 72, 73, 74 ) ) );
		self::assertSame( '1 al 33', VolumePhrase::of( range( 1, 33 ) ) );
	}

	/**
	 * @testdox Un hueco NUNCA se lee como rango.
	 *
	 * El caso que hace falso el atajo: decir «71 al 74» cuando el 72 no viaja es
	 * prometerle al cliente un tomo que no le vamos a enseñar.
	 */
	public function test_un_hueco_nunca_se_lee_como_rango(): void {
		$frase = VolumePhrase::of( array( 71, 73, 74 ) );

		self::assertSame( '71, 73 y 74', $frase );
		self::assertStringNotContainsString( '71 al 74', $frase );
	}

	/**
	 * @testdox La tanda real de Demon Slayer no promete los intermedios.
	 *
	 * Tanda real del catálogo: 2, 3, 4, 5, 21 y 22. Seis caben nombrados.
	 */
	public function test_la_tanda_real_de_demon_slayer_no_promete_los_intermedios(): void {
		self::assertSame( '2, 3, 4, 5, 21 y 22', VolumePhrase::of( array( 2, 3, 4, 5, 21, 22 ) ) );
	}

	/**
	 * @testdox Muchos con hueco nombran los extremos, sin decir «todos».
	 *
	 * Fire Force real: el 1 y del 5 al 33. Ni rango —mentiría sobre el 2, 3 y 4—
	 * ni lista de treinta números en la línea de asunto.
	 */
	public function test_muchos_con_hueco_nombran_los_extremos(): void {
		$frase = VolumePhrase::of( array_merge( array( 1 ), range( 5, 33 ) ) );

		self::assertSame( 'entre el 1 y el 33', $frase );
		self::assertStringNotContainsString( ' al ', $frase );
		self::assertStringNotContainsString( ',', $frase );
	}

	/** @testdox Desordenados y repetidos dan exactamente la misma frase. */
	public function test_desordenados_y_repetidos_dan_la_misma_frase(): void {
		self::assertSame( '71 al 74', VolumePhrase::of( array( 74, 71, 72, 74, 73 ) ) );
	}

	/**
	 * @testdox Una reedición con el mismo número es UN tomo, no dos.
	 *
	 * Dos productos distintos pueden llevar el mismo número de tomo. Contarlos
	 * dos veces diría «Salieron 2 tomos» enseñando uno.
	 */
	public function test_una_reedicion_con_el_mismo_numero_es_un_solo_tomo(): void {
		self::assertSame( array( 55 ), VolumePhrase::normalize( array( 55, 55 ) ) );
		self::assertSame( '55', VolumePhrase::of( array( 55, 55 ) ) );
	}

	/**
	 * @testdox El número de tomo no lleva separador de miles.
	 *
	 * Un tomo 1023 no son mil veintitrés pesos: «1.023» se lee como un precio.
	 */
	public function test_no_agrupa_miles_en_el_numero_de_tomo(): void {
		self::assertSame( '1023', VolumePhrase::of( array( 1023 ) ) );
	}

	/** @testdox La frase de la peor tanda del catálogo cabe en una línea. */
	public function test_la_frase_de_la_peor_tanda_cabe_en_una_linea(): void {
		self::assertLessThan(
			60,
			strlen( VolumePhrase::of( array_merge( array( 1 ), range( 5, 33 ) ) ) ),
			'Va en la línea de asunto, que se corta en el móvil.'
		);
	}

	/** @testdox Sin tomos no hay frase, y no revienta. */
	public function test_sin_tomos_no_hay_frase(): void {
		self::assertSame( '', VolumePhrase::of( array() ) );
		self::assertSame( '', VolumePhrase::of( array( 0, -3 ) ) );
	}
}
