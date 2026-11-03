<?php
/**
 * Contrato de ancho de los campos del panel en el teléfono (QA2-PANEL-7).
 *
 * En móvil, el `forms.css` de wp-admin quita el tope a los campos de una tabla
 * de ajustes (`.form-table td input[type="email"] { max-width: none }`,
 * especificidad 0,2,2). El panel les devuelve `max-width: 100%` con
 * `.akb-panel .form-table …` (0,3,1), que gana por tener más clases. Hasta el
 * 2026-09-24 esa lista no traía `email`: el correo de Contacto, con
 * `width: 400px` en línea, dejaba la pestaña Textos en 425 px de ancho en un
 * teléfono de 390 (medido en el espejo, Chromium y WebKit).
 *
 * Nada más avisaba si alguien quitaba el selector: ninguna prueba leía la hoja
 * (REV-QFB-3). Se comprueban DECLARACIONES con los comentarios quitados, no
 * apariciones del literal: el comentario de la propia hoja nombra
 * `input[type="email"]` para explicar el defecto.
 *
 * @package Akibara\Business\Tests
 */

declare(strict_types=1);

namespace Akibara\Business\Tests\Modules\AdminPanel;

use PHPUnit\Framework\TestCase;

final class AdminPanelCssContractTest extends TestCase {

	/** Hoja del panel (sólo se encola en `toplevel_page_akibara-panel`). */
	private const HOJA = '/assets/css/admin-panel.css';

	/**
	 * Selectores que la hoja del panel topa a `max-width: 100%`.
	 *
	 * @return list<string>
	 */
	private function selectores_topados(): array {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . self::HOJA );
		self::assertNotSame( '', $css, 'No se pudo leer admin-panel.css' );

		$topados = array();
		foreach ( $this->reglas( $css ) as $regla ) {
			if ( 1 === preg_match( '/(?:^|;)\s*max-width\s*:\s*100%\s*(?:;|$)/', $regla['cuerpo'] ) ) {
				$topados = array_merge( $topados, $regla['selectores'] );
			}
		}

		return $topados;
	}

	/**
	 * Reglas de una hoja sin comentarios: `@media` incluidas, aplanadas.
	 *
	 * @param string $css Hoja.
	 * @return list<array{media:string, selectores:list<string>, cuerpo:string}>
	 */
	private function reglas( string $css ): array {
		$css    = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$reglas = array();
		$media  = '';
		$nivel  = 0;
		$buffer = '';
		$largo  = strlen( $css );
		for ( $i = 0; $i < $largo; $i++ ) {
			$c = $css[ $i ];
			if ( '{' === $c ) {
				$cabecera = trim( $buffer );
				$buffer   = '';
				if ( str_starts_with( $cabecera, '@' ) ) {
					$media = $cabecera;
					++$nivel;
					continue;
				}
				$fin      = strpos( $css, '}', $i );
				$cuerpo   = substr( $css, $i + 1, (int) $fin - $i - 1 );
				$reglas[] = array(
					'media'      => $media,
					'selectores' => array_values(
						array_filter(
							array_map(
								static fn( string $s ): string => trim( (string) preg_replace( '/\s+/', ' ', $s ) ),
								explode( ',', $cabecera )
							)
						)
					),
					'cuerpo'     => trim( $cuerpo ),
				);
				$i = (int) $fin;
				continue;
			}
			if ( '}' === $c ) {
				$buffer = '';
				if ( $nivel > 0 ) {
					--$nivel;
					$media = '';
				}
				continue;
			}
			if ( ';' === $c && str_starts_with( ltrim( $buffer ), '@' ) ) {
				$buffer = ''; // `@charset`/`@import`: sentencias sin bloque.
				continue;
			}
			$buffer .= $c;
		}

		return $reglas;
	}

	/**
	 * Especificidad (ids, clases/atributos/pseudoclases, elementos) de un
	 * selector sin `:is()`/`:not()`, que es lo que usan las dos hojas.
	 *
	 * Se devuelve como un solo número (ids × 10⁴ + clases × 10² + elementos)
	 * para compararla de un golpe.
	 *
	 * @param string $selector Selector.
	 * @return int
	 */
	private static function especificidad( string $selector ): int {
		$sin_atributos = (string) preg_replace( '/\[[^\]]*\]/', '', $selector );
		$ids           = substr_count( $sin_atributos, '#' );
		$clases        = substr_count( $sin_atributos, '.' )
			+ preg_match_all( '/\[[^\]]*\]/', $selector )
			+ preg_match_all( '/(?<!:):(?!:)[a-z-]+/', $sin_atributos );
		$elementos     = preg_match_all( '/(?:^|[\s>+~])([a-z][a-z0-9-]*)/i', $sin_atributos );

		return $ids * 10000 + $clases * 100 + (int) $elementos;
	}

	/**
	 * @testdox En el teléfono, el correo y la URL también quedan topados al ancho de la tabla, junto al resto de los campos.
	 */
	public function test_los_campos_del_panel_quedan_topados_al_ancho(): void {
		$topados = $this->selectores_topados();

		foreach ( array( 'input[type="text"]', 'input[type="email"]', 'input[type="url"]', 'input[type="number"]', 'textarea', 'select' ) as $campo ) {
			self::assertContains(
				'.akb-panel .form-table ' . $campo,
				$topados,
				"QA2-PANEL-7: sin «{$campo}» en el tope, un campo con ancho en línea desborda la pestaña en un teléfono."
			);
		}
	}

	/**
	 * El tope del panel sólo sirve si le gana a la regla de wp-admin que lo
	 * quita. Los tipos se leen del `forms.css` del WordPress que corre el sitio,
	 * así que si una actualización destopa otro campo que el panel usa, esto lo
	 * dice. `password` queda fuera mientras ningún ajuste del plugin lo declare.
	 *
	 * @testdox Cada campo que wp-admin destopa en móvil y el panel usa queda topado con más especificidad que la regla de wp-admin.
	 */
	public function test_el_tope_le_gana_a_la_regla_de_wp_admin(): void {
		$forms = dirname( __DIR__, 6 ) . '/wp-admin/css/forms.css';
		self::assertFileExists( $forms, 'La prueba compara contra el forms.css del WordPress del repo.' );

		$destopados = array();
		foreach ( $this->reglas( (string) file_get_contents( $forms ) ) as $regla ) {
			if ( '' === $regla['media'] || 1 !== preg_match( '/max-width\s*:\s*none/', $regla['cuerpo'] ) ) {
				continue;
			}
			foreach ( $regla['selectores'] as $selector ) {
				if ( 1 === preg_match( '/^\.form-table td (input\[type="([a-z]+)"\]|select|textarea)$/', $selector, $m ) ) {
					$destopados[ $m[1] ] = array(
						'selector' => $selector,
						'tipo'     => $m[2] ?? $m[1],
					);
				}
			}
		}
		self::assertArrayHasKey( 'input[type="email"]', $destopados, 'El forms.css de wp-admin ya no destopa el correo: revisar esta prueba y la hoja.' );

		$fuentes = '';
		$iter    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iter as $fichero ) {
			if ( 'php' === $fichero->getExtension() ) {
				$fuentes .= (string) file_get_contents( $fichero->getPathname() );
			}
		}

		$topados = $this->selectores_topados();
		$medidos = 0;
		foreach ( $destopados as $campo => $wp ) {
			if ( 1 !== preg_match( "/'type'\s*=>\s*'" . preg_quote( $wp['tipo'], '/' ) . "'/", $fuentes ) ) {
				continue; // Ningún ajuste del plugin lo usa (hoy: `password`).
			}
			++$medidos;
			$nuestro = '.akb-panel .form-table ' . $campo;
			self::assertContains( $nuestro, $topados, "wp-admin destopa «{$wp['selector']}» en móvil y el panel usa ese campo." );
			self::assertGreaterThan(
				self::especificidad( $wp['selector'] ),
				self::especificidad( $nuestro ),
				"«{$nuestro}» tiene que ganarle a «{$wp['selector']}» (0,3,1 contra 0,2,2)."
			);
		}
		self::assertGreaterThanOrEqual( 4, $medidos, 'Texto, correo, select y textarea: el panel los usa todos.' );
	}
}
