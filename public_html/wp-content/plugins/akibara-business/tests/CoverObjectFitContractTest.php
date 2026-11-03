<?php
/**
 * Contrato: las portadas de producto llenan su caja (`object-fit: cover`).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Decisión del dueño (2026-07-06, confirmada el 2026-09-15): la portada llena la
 * caja y se aceptan de 1 a 4 % de recorte por lado. Con `contain` (1.116.11) las
 * portadas nuevas —proporción A5, no 2:3— dejaban franjas oscuras arriba y abajo.
 * El tema fija lo mismo para el catálogo en tests/catalog-cover-sizes.test.js.
 */
final class CoverObjectFitContractTest extends TestCase {

	/**
	 * Hoja => selector exacto de la portada.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function superficies(): array {
		return array(
			'carrito'            => array( 'native-cart-v2.css', '.akb-cart-item-img img' ),
			'venta cruzada'      => array( 'native-cart-v2.css', '.akb-cs-img img' ),
			'vista rápida'       => array( 'quick-view.css', '.akb-qv__media img' ),
			'mi colección'       => array( 'account-collection.css', '.akb-account-collection__cover img' ),
			'vitrina de series'  => array( 'home-series-destacadas.css', '.akb-home-series__cover img' ),
			'tarjeta de edición' => array( 'brand-family.css', '.akb-edition-card__covers img' ),
		);
	}

	/**
	 * Cada superficie declara `cover` y ninguna regla suya vuelve a `contain`.
	 *
	 * @dataProvider superficies
	 * @testdox La portada de $hoja llena su caja
	 *
	 * @param string $hoja     Hoja de estilos dentro de assets/css.
	 * @param string $selector Selector exacto de la imagen de portada.
	 */
	public function test_la_portada_llena_su_caja( string $hoja, string $selector ): void {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/' . $hoja ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichero local del propio plugin.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		$reglas = array();
		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $coincidencias, PREG_SET_ORDER );
		foreach ( $coincidencias as $regla ) {
			$selectores = array_map( 'trim', explode( ',', $regla[1] ) );
			if ( in_array( $selector, $selectores, true ) ) {
				$reglas[] = $regla[2];
			}
		}

		self::assertNotEmpty( $reglas, "{$hoja}: no se encontró la regla de {$selector}" );
		$ultimo = null;
		foreach ( $reglas as $cuerpo ) {
			if ( preg_match_all( '/object-fit:\s*([a-z-]+)/', $cuerpo, $fit ) ) {
				$ultimo = end( $fit[1] );
			}
			self::assertDoesNotMatchRegularExpression( '/object-fit:\s*(contain|scale-down)/', $cuerpo, "{$hoja}: {$selector} no vuelve a contain" );
		}
		self::assertSame( 'cover', $ultimo, "{$hoja}: {$selector} declara object-fit: cover" );
	}
}
