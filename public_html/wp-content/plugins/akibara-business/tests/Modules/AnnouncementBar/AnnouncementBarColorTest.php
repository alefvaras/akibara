<?php
/**
 * La barra de anuncios no puede romperse porque alguien guarde algo que no es un color.
 *
 * El manual del dueño lo declaraba así, con esas palabras: «Colores de la barra
 * sin comprobar que sean colores (defecto)». El panel guarda lo que venga y el
 * valor iba tal cual al atributo `style` de la barra: `esc_attr()` impide que se
 * escape del atributo, pero no que la barra salga sin fondo, sin borde o con el
 * texto invisible — y sin nada en pantalla que diga por qué.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AnnouncementBar;

use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use PHPUnit\Framework\TestCase;

final class AnnouncementBarColorTest extends TestCase {

	/**
	 * @testdox A real colour is served as it is, in both lengths and either case.
	 */
	public function test_un_color_de_verdad_se_sirve_tal_cual(): void {
		self::assertSame( '#D90010', AnnouncementBar::color_o_por_defecto( '#D90010', '#000000' ) );
		self::assertSame( '#fff', AnnouncementBar::color_o_por_defecto( '#fff', '#000000' ), 'la forma corta es la que ofrece el propio selector' );
		self::assertSame( '#AbC123', AnnouncementBar::color_o_por_defecto( '#AbC123', '#000000' ) );
		self::assertSame( '#ffffff', AnnouncementBar::color_o_por_defecto( '  #ffffff  ', '#000000' ), 'un espacio de más al pegar no es un color inválido' );
	}

	/**
	 * @testdox Anything that is not a colour falls back to the factory one.
	 */
	public function test_lo_que_no_es_color_cae_al_de_fabrica(): void {
		$casos = array(
			''                        => 'vacío',
			'rojo'                    => 'un nombre en castellano',
			'red'                     => 'un nombre CSS, que el selector del panel no ofrece',
			'#12345'                  => 'cinco dígitos: ni corta ni larga',
			'#gggggg'                 => 'letras fuera del hexadecimal',
			'D90010'                  => 'sin la almohadilla',
			'#fff;background:url(x)'  => 'un intento de colarse en el atributo',
			'rgb(255,0,0)'            => 'una función CSS',
		);

		foreach ( $casos as $valor => $porque ) {
			self::assertSame(
				'#D90010',
				AnnouncementBar::color_o_por_defecto( $valor, '#D90010' ),
				sprintf( 'debía caer al de fábrica: %s', $porque )
			);
		}
	}

	/**
	 * @testdox A value that is not even text must not blow up the front.
	 */
	public function test_un_valor_que_ni_siquiera_es_texto_no_rompe_nada(): void {
		// Una option puede volver como array tras una restauración a medias o un
		// `wp option update --format=json`. Esto corre en CADA página del sitio.
		self::assertSame( '#ffffff', AnnouncementBar::color_o_por_defecto( array( '#000' ), '#ffffff' ) );
		self::assertSame( '#ffffff', AnnouncementBar::color_o_por_defecto( null, '#ffffff' ) );
		self::assertSame( '#ffffff', AnnouncementBar::color_o_por_defecto( false, '#ffffff' ) );
	}
}
