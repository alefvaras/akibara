<?php
/**
 * La unidad no usa i18n, y eso es una decisión sellada.
 *
 * Hasta 1.111.0 el plugin arrastraba 97 llamadas i18n en 18 archivos mientras
 * no declaraba `Text Domain`, no llamaba a `load_plugin_textdomain()` y no
 * tenía `languages/`. Ninguna traducía nada —sin dominio cargado `__()`
 * devuelve su argumento— y tres dominios distintos convivían: el propio (que no
 * existe), el del THEME y ninguno.
 *
 * Decisión del dueño (2026-08-31): tienda monolingüe es-CL, sin plan de
 * traducción; se retiran las envolturas y se escapa directo. Este test impide
 * que vuelvan de a una, que es como llegaron.
 *
 * Si algún día el negocio quiere traducir, el camino es el contrario y
 * completo: declarar el dominio en la cabecera, cargarlo, generar el `.pot` y
 * `wp_set_script_translations()` para el JS. Ese día se borra este archivo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class I18nPolicyTest extends TestCase {

	/** Funciones de traducción de WordPress, todas prohibidas acá. */
	private const FUNCIONES_I18N = array(
		'__',
		'_e',
		'_n',
		'_x',
		'_ex',
		'_nx',
		'esc_html__',
		'esc_html_e',
		'esc_html_x',
		'esc_attr__',
		'esc_attr_e',
		'esc_attr_x',
		'translate',
		'load_plugin_textdomain',
	);

	private function plugin_root(): string {
		return dirname( __DIR__ );
	}

	/**
	 * @return array<int, string> Rutas PHP del runtime (sin tests).
	 */
	private function archivos_de_runtime(): array {
		$rutas = array();

		foreach ( array( 'src', 'templates' ) as $carpeta ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->plugin_root() . '/' . $carpeta )
			);
			foreach ( $iterator as $archivo ) {
				if ( $archivo->isFile() && 'php' === $archivo->getExtension() ) {
					$rutas[] = $archivo->getPathname();
				}
			}
		}

		$rutas[] = $this->plugin_root() . '/akibara-business.php';
		$rutas[] = $this->plugin_root() . '/uninstall.php';

		sort( $rutas );

		return $rutas;
	}

	/**
	 * Ninguna llamada i18n sobrevive en el runtime.
	 */
	public function test_no_translation_call_survives_in_runtime_code(): void {
		$patron   = '/(?<![A-Za-z0-9_$>\-])(' . implode( '|', array_map( 'preg_quote', self::FUNCIONES_I18N ) ) . ')\s*\(/';
		$hallazgos = array();

		foreach ( $this->archivos_de_runtime() as $ruta ) {
			$fuente = (string) file_get_contents( $ruta );
			// Los comentarios pueden nombrar `__()` al explicar por qué NO se usa.
			$sin_comentarios = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $fuente );

			if ( 1 === preg_match( $patron, $sin_comentarios, $m ) ) {
				$hallazgos[] = substr( $ruta, strlen( $this->plugin_root() ) + 1 ) . ' → ' . $m[1] . '()';
			}
		}

		self::assertSame(
			array(),
			$hallazgos,
			"Envoltura i18n decorativa: el plugin no carga ningún dominio, así que no traduce nada.\n"
			. "Escapa directo (esc_html/esc_attr) o, para plurales, un ternario sobre el conteo.\n\n"
			. implode( "\n", $hallazgos )
		);
	}

	/**
	 * Y la cabecera sigue sin declarar un dominio que nadie carga.
	 */
	public function test_the_plugin_header_declares_no_text_domain(): void {
		$cabecera = (string) file_get_contents( $this->plugin_root() . '/akibara-business.php' );

		self::assertDoesNotMatchRegularExpression(
			'/^\s*\*\s*Text Domain:/mi',
			$cabecera,
			'Declarar el dominio sin cargarlo ni tener .pot es la promesa vacía que se retiró.'
		);
		self::assertDoesNotMatchRegularExpression(
			'/^\s*\*\s*Domain Path:/mi',
			$cabecera
		);
	}

	/**
	 * La decisión está escrita donde se busca: la cabecera del plugin.
	 */
	public function test_the_decision_is_documented_where_it_is_looked_for(): void {
		$cabecera = (string) file_get_contents( $this->plugin_root() . '/akibara-business.php' );

		self::assertStringContainsString( 'monolingüe es-CL', $cabecera );
	}
}
