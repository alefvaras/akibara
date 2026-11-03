<?php
/**
 * Availability es la ÚNICA declaración del vocabulario de `_akb_eta_type`.
 *
 * La clase se documenta como dueña única del estado comercial y sin embargo la
 * lista `auto|release_date|tba|disabled` estaba escrita en cuatro sitios, dos de
 * ellos con los literales a mano DENTRO del snapshot que se estampa en cada
 * línea de pedido (auditoría 2026-08-31). Una lista así no diverge el día que se
 * copia: diverge el día que alguien agrega un quinto tipo y encuentra tres de
 * las cuatro copias.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Stock;

use Akibara\Business\Modules\Stock\Availability;
use PHPUnit\Framework\TestCase;

final class AvailabilityTypeVocabularyTest extends TestCase {

	/**
	 * @testdox El vocabulario son exactamente los cuatro tipos declarados.
	 */
	public function test_el_vocabulario_es_el_de_las_constantes(): void {
		self::assertSame(
			array(
				Availability::TYPE_AUTO,
				Availability::TYPE_RELEASE_DATE,
				Availability::TYPE_TBA,
				Availability::TYPE_DISABLED,
			),
			Availability::TYPES
		);
	}

	/**
	 * @dataProvider tipos
	 */
	public function test_sanitize_type_normaliza_al_default( string $crudo, string $esperado ): void {
		self::assertSame( $esperado, Availability::sanitize_type( $crudo ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function tipos(): array {
		return array(
			'auto'                 => array( 'auto', Availability::TYPE_AUTO ),
			'fecha de lanzamiento' => array( 'release_date', Availability::TYPE_RELEASE_DATE ),
			'sin fecha'            => array( 'tba', Availability::TYPE_TBA ),
			'sin promesa'          => array( 'disabled', Availability::TYPE_DISABLED ),
			// El producto que nunca pasó por el panel: default del negocio.
			'vacío'                => array( '', Availability::TYPE_AUTO ),
			'basura'               => array( 'preventa', Availability::TYPE_AUTO ),
			'mayúsculas'           => array( 'TBA', Availability::TYPE_AUTO ),
		);
	}

	/**
	 * El candado: NADIE fuera de Availability vuelve a escribir la lista.
	 *
	 * Se inspeccionan TOKENS de string, no el texto: un docblock que enumera los
	 * valores documenta, no declara (mismo criterio que el candado de
	 * `onbackorder`). Y se prohíbe la ENUMERACIÓN, no la comparación: un
	 * `Availability::TYPE_TBA === $type` suelto es legítimo; dos o más de estos
	 * literales juntos, dentro de una ventana corta de tokens, son una copia del
	 * conjunto — que es como nacieron las cuatro que había.
	 */
	public function test_ninguna_otra_clase_reenumera_el_vocabulario(): void {
		$src       = dirname( __DIR__, 3 ) . '/src';
		$ofensores = array();
		$valores   = array( 'auto', 'release_date', 'tba', 'disabled' );

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( false !== strpos( $file->getPathname(), '/Modules/Stock/Availability.php' ) ) {
				continue;
			}

			$literales = array();
			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
					continue;
				}
				$texto = trim( $token[1], "'\"" );
				if ( in_array( $texto, $valores, true ) ) {
					$literales[] = array( $texto, $token[2] );
				}
			}

			// Dos literales del vocabulario a menos de 10 líneas: enumeración.
			$total = count( $literales );
			for ( $i = 1; $i < $total; $i++ ) {
				if ( $literales[ $i ][0] !== $literales[ $i - 1 ][0]
					&& $literales[ $i ][1] - $literales[ $i - 1 ][1] <= 10 ) {
					$ofensores[] = $file->getPathname() . ':' . $literales[ $i ][1];
				}
			}
		}

		self::assertSame(
			array(),
			$ofensores,
			'El vocabulario de _akb_eta_type se declara SOLO en Availability::TYPES; usá sus constantes o sanitize_type().'
		);
	}
}
