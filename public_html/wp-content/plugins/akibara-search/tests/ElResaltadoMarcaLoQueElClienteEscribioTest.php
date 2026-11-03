<?php
/**
 * El resaltado marca lo que el cliente escribió, como lo escribió.
 *
 * Dos huecos del mismo resaltador (2026-09-19):
 *  - «one piece 1» marcaba «one piece» y no el 1 —el dato que el cliente
 *    escribió para elegir tomo—, porque los tokens de un carácter se
 *    descartaban todos (S-05cfb1c0);
 *  - «pokemon» no marcaba nada en «Pokémon», porque se comparaba en minúsculas
 *    pero sin plegar las tildes que el motor sí pliega (S-62d9ce2b).
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Highlighter;
use Akibara\Search\Normalizer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/Normalizer.php';
require_once dirname( __DIR__ ) . '/src/Highlighter.php';

final class ElResaltadoMarcaLoQueElClienteEscribioTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->alias( static fn( $t ): string => htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox «one piece 1» marca también el número del tomo, aunque el título lo escriba «001». */
	public function test_el_numero_del_tomo_se_marca_por_su_valor(): void {
		self::assertSame(
			'<mark>One Piece</mark> <mark>001</mark>',
			Highlighter::mark( 'One Piece 001', array( 'one piece', '1' ) )
		);
	}

	/** @testdox Un dígito no marca dentro de otro número: ni el 1 de «Tomo 10» ni el de «2019». */
	public function test_un_digito_no_marca_dentro_de_otro_numero(): void {
		self::assertSame( 'Tomo 10 (2019)', Highlighter::mark( 'Tomo 10 (2019)', array( '1' ) ) );
		self::assertSame( 'Tomo <mark>1</mark> de 10', Highlighter::mark( 'Tomo 1 de 10', array( '1' ) ) );
	}

	/** @testdox Una LETRA suelta sigue sin marcarse: pintaría media página. */
	public function test_una_letra_suelta_no_se_marca(): void {
		self::assertSame( 'Vagabond', Highlighter::mark( 'Vagabond', array( 'a' ) ) );
	}

	/** @testdox «pokemon» marca «Pokémon», y lo pinta con su tilde. */
	public function test_sin_tilde_marca_el_titulo_con_tilde(): void {
		self::assertSame( '<mark>Pokémon</mark> Adventures', Highlighter::mark( 'Pokémon Adventures', array( 'pokemon' ) ) );
		self::assertSame( 'La <mark>canción</mark> del mar', Highlighter::mark( 'La canción del mar', array( 'cancion' ) ) );
		self::assertSame( '<mark>Pokémon</mark>', Highlighter::mark( 'Pokémon', array( 'pokémon' ) ), 'Y con tilde, igual.' );
	}

	/**
	 * Revisión independiente 2026-09-19 (MEDIA): la minúscula de «İ» ocupa DOS
	 * caracteres, y con una sola en el título el resaltado marcaba «ne » en vez
	 * de «One».
	 *
	 * @testdox Una letra cuya minúscula ocupa más que ella no corre el resaltado.
	 */
	public function test_una_minuscula_mas_larga_no_corre_las_marcas(): void {
		$titulo = 'İstanbul One Piece 01 Edición';

		self::assertSame( mb_strlen( $titulo ), mb_strlen( Normalizer::fold( $titulo ) ), 'El 1:1 es una promesa.' );
		self::assertSame(
			'İstanbul <mark>One</mark> <mark>Piece</mark> 01 <mark>Edición</mark>',
			Highlighter::mark( $titulo, array( 'one', 'piece', 'edicion' ) )
		);
		self::assertSame( '<mark>İstanbul</mark> One', Highlighter::mark( 'İstanbul One', array( 'istanbul' ) ), 'Y la propia palabra se encuentra por su letra base.' );
	}

	/** @testdox El plegado es carácter a carácter: las posiciones del original no se corren. */
	public function test_el_plegado_conserva_las_posiciones(): void {
		foreach ( array( 'Pokémon', 'Ñandú áéíóú ÀÈÌ', 'Nausicaä del Valle', 'Haikyu!! Nº 01 – Ivrea' ) as $texto ) {
			self::assertSame( mb_strlen( $texto ), mb_strlen( Normalizer::fold( $texto ) ), $texto );
		}
	}

	/** @testdox Lo que escriba el cliente o traiga el título sigue saliendo escapado. */
	public function test_el_resaltado_no_abre_un_xss(): void {
		$html = Highlighter::mark( 'Tomo <b>1</b> & más', array( '1', 'más' ) );

		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringContainsString( '&lt;b&gt;<mark>1</mark>&lt;/b&gt;', $html );
		self::assertStringContainsString( '<mark>más</mark>', $html );
	}
}
