<?php
/**
 * Pruebas de interpretación y orden de tomos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Services\VolumeTitle;
use PHPUnit\Framework\TestCase;

final class VolumeTitleTest extends TestCase {

	/**
	 * @dataProvider volume_title_provider
	 */
	public function test_extracts_only_unambiguous_volume_numbers( string $title, ?int $expected ): void {
		self::assertSame( $expected, VolumeTitle::extract_number( $title ) );
	}

	/**
	 * @return array<string, array{string, ?int}>
	 */
	public function volume_title_provider(): array {
		return array(
			'padded two digits'       => array( 'Blue Lock 01', 1 ),
			'padded three digits'     => array( 'One Piece 100', 100 ),
			'number in series name'   => array( '20th Century Boys 11', 11 ),
			'publisher ordinal'       => array( 'One Piece nº 07', 7 ),
			'descriptive suffix'      => array( 'One Piece nº 07 (3 en 1)', 7 ),
			'numeric suffix details'  => array( 'Maximum Berserk 21 (Portada alternativa + 4 postales)', 21 ),
			'decimal special edition' => array( 'Ghost in the Shell 1.5', null ),
			'non-terminal number'     => array( 'Shangri La Frontier 1 Expansion Pass', null ),
			'bundle title'            => array( 'Pack Vinland Saga 1+2+3', null ),
			'integral title'          => array( 'Uzumaki (Integral)', null ),
			'no volume'               => array( 'From Hell (nueva edición)', null ),
		);
	}

	/**
	 * El padding es lo que hace que el catálogo ordene «02» antes que «10». La
	 * lista de negativos NO es decorativa: cada uno es un título real del
	 * catálogo que un padding de más rompería.
	 *
	 * @dataProvider padding_provider
	 */
	public function test_pads_only_unambiguous_single_digit_volumes( string $title, string $expected ): void {
		self::assertSame( $expected, VolumeTitle::pad_single_digit( $title ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function padding_provider(): array {
		return array(
			'final de cadena'          => array( 'Gannibal 1', 'Gannibal 01' ),
			'guion editorial'          => array( 'Gannibal 1 – Ivrea', 'Gannibal 01 – Ivrea' ),
			'sufijo entre paréntesis'  => array( 'Demon Slayer 5 (Edición Especial)', 'Demon Slayer 05 (Edición Especial)' ),
			'decimal'                  => array( 'Ghost in the Shell 1.5', 'Ghost in the Shell 1.5' ),
			'pack sumado'              => array( 'Pack Vinland Saga 1+2+3', 'Pack Vinland Saga 1+2+3' ),
			'palabra después'          => array( 'Shangri La Frontier 1 Expansion Pass', 'Shangri La Frontier 1 Expansion Pass' ),
			'fracción en el nombre'    => array( 'Ranma 1/2 7', 'Ranma 1/2 07' ),
			'tres dígitos'             => array( 'Mob Psycho 100', 'Mob Psycho 100' ),
			'ya paddeado'              => array( 'Gannibal 01', 'Gannibal 01' ),
			'omnibus con paréntesis'   => array( 'One Piece nº 01 (3 en 1)', 'One Piece nº 01 (3 en 1)' ),
			'dos dígitos con sufijo'   => array( 'Demon Slayer 23 (Edición Especial)', 'Demon Slayer 23 (Edición Especial)' ),
			'sufijo con número dentro' => array( 'Maximum Berserk 21 (Portada alternativa + 4 postales )', 'Maximum Berserk 21 (Portada alternativa + 4 postales )' ),
		);
	}

	public function test_sorts_99_to_100_and_places_special_editions_last(): void {
		$sorted = VolumeTitle::sort_records(
			array(
				array(
					'id'    => 100,
					'title' => 'One Piece 100',
				),
				array(
					'id'    => 1,
					'title' => 'One Piece 001',
				),
				array(
					'id'    => 99,
					'title' => 'One Piece 099',
				),
				array(
					'id'    => 500,
					'title' => 'One Piece Fanbook',
				),
			)
		);

		self::assertSame( array( 1, 99, 100, 500 ), array_column( $sorted, 'id' ) );
		self::assertSame( array( 1, 99, 100, null ), array_column( $sorted, 'volume_number' ) );
	}

	public function test_sort_is_deterministic_for_duplicate_volume_numbers(): void {
		$sorted = VolumeTitle::sort_records(
			array(
				array(
					'id'    => 2,
					'title' => 'Serie 01 (Edición B)',
				),
				array(
					'id'    => 1,
					'title' => 'Serie 01 (Edición A)',
				),
			)
		);

		self::assertSame( array( 1, 2 ), array_column( $sorted, 'id' ) );
	}

	public function test_next_records_starts_after_highest_owned_volume(): void {
		$next = VolumeTitle::next_records(
			array(
				array(
					'id'    => 4,
					'title' => 'Serie 04',
				),
				array(
					'id'    => 3,
					'title' => 'Serie 03',
				),
				array(
					'id'    => 30,
					'title' => 'Serie 03 (Edición especial)',
				),
				array(
					'id'    => 5,
					'title' => 'Serie Fanbook',
				),
			),
			3,
			2,
			array( 3 )
		);

		self::assertSame( array( 4 ), array_column( $next, 'id' ) );
	}

	public function test_next_records_honours_zero_limit(): void {
		self::assertSame(
			array(),
			VolumeTitle::next_records(
				array(
					array(
						'id'    => 2,
						'title' => 'Serie 02',
					),
				),
				1,
				0
			)
		);
	}
}
