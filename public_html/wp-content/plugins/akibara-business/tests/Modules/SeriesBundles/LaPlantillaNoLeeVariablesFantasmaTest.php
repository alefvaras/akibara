<?php
/**
 * La plantilla del armador no puede leer una variable que nadie definió.
 *
 * El 2026-09-18, con business 1.116.20 en producción, TODAS las fichas de
 * producto respondieron HTTP 500 durante nueve minutos. La causa: al separar
 * `builder_view_model()` de `build_html()`, la bandera `$has_preventa_real`
 * quedó como variable local del modelo y la plantilla la siguió leyendo. En
 * PHP 8 una variable indefinida vale `null`, y `pack_notice( bool )` lanza
 * `TypeError`.
 *
 * No se vio antes porque el bloque que la usa está detrás de
 * `if ( $has_preventa )`, y con el interruptor de fábrica los tomos reservables
 * ni siquiera se pintaban: la bandera era siempre false y la línea nunca corría.
 * El defecto llevaba meses ahí, esperando a que alguien encendiera esa rama.
 *
 * Este candado lee la fuente: junta lo que `build_html()` ASIGNA y lo que USA,
 * y falla si usa algo que no salió del modelo de vista ni de sí misma.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use PHPUnit\Framework\TestCase;

final class LaPlantillaNoLeeVariablesFantasmaTest extends TestCase {

	private function fuente(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/SeriesBundles/BuilderWidget.php' );
	}

	/** Cuerpo de `build_html()`, hasta el siguiente método. */
	private function cuerpo_de_build_html(): string {
		$src   = $this->fuente();
		$desde = strpos( $src, 'private function build_html( WC_Product $product ): string {' );
		self::assertIsInt( $desde, 'Si el método se renombró, este candado deja de medir: actualízalo.' );

		$resto = substr( $src, $desde );
		$hasta = preg_match( '/\n\t(?:private|public|protected) (?:static )?function (?!build_html)/', $resto, $m, PREG_OFFSET_CAPTURE )
			? (int) $m[0][1]
			: strlen( $resto );

		$cuerpo = substr( $resto, 0, $hasta );
		$tpl    = dirname( __DIR__, 3 ) . '/templates/series-bundles/builder-widget.php';
		if ( file_exists( $tpl ) ) {
			$cuerpo .= "\n" . (string) file_get_contents( $tpl );
		}

		return $cuerpo;
	}

	/** @testdox Toda variable que la plantilla usa está definida antes en el mismo método. */
	public function test_ninguna_variable_sale_de_la_nada(): void {
		$cuerpo = $this->cuerpo_de_build_html();

		$definidas = array( 'this', 'view', 'product' );

		// Asignaciones directas, listas y `foreach ... as`.
		preg_match_all( '/\$([a-z_][a-z0-9_]*)\s*=[^=]/i', $cuerpo, $m );
		$definidas = array_merge( $definidas, $m[1] );
		preg_match_all( '/as\s+\$([a-z_][a-z0-9_]*)(?:\s*=>\s*\$([a-z_][a-z0-9_]*))?/i', $cuerpo, $m );
		$definidas = array_merge( $definidas, $m[1], array_filter( $m[2] ) );
		preg_match_all( '/function\s*\([^)]*\$([a-z_][a-z0-9_]*)/i', $cuerpo, $m );
		$definidas = array_merge( $definidas, $m[1] );
		$definidas = array_unique( $definidas );

		preg_match_all( '/\$([a-z_][a-z0-9_]*)/i', $cuerpo, $m );
		$usadas = array_unique( $m[1] );

		$fantasmas = array_values( array_diff( $usadas, $definidas ) );

		self::assertSame(
			array(),
			$fantasmas,
			sprintf(
				'build_html() lee %s sin definirla: en PHP 8 eso vale null y la ficha entera responde 500.',
				implode( ', ', array_map( static fn( string $v ): string => '$' . $v, $fantasmas ) )
			)
		);
	}

	/** @testdox La bandera de «preventa de verdad» viaja por el modelo de vista, no por el aire. */
	public function test_la_bandera_viaja_en_el_modelo(): void {
		$src = $this->fuente();

		self::assertStringContainsString( "'has_preventa_real' => (bool) \$has_preventa_real,", $src, 'El modelo tiene que publicarla.' );
		self::assertStringContainsString( "\$has_preventa_real = \$view['has_preventa_real'];", $src, 'Y la plantilla tiene que sacarla de ahí.' );
		self::assertStringContainsString( 'has_preventa_real:bool', $src, 'La forma declarada del modelo también la nombra.' );
	}
}
