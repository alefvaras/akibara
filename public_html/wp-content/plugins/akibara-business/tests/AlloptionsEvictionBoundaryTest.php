<?php
/**
 * INVARIANTE transversal: el plugin no evicta `alloptions`.
 *
 * Las cuatro escrituras directas sobre `wp_options` del árbol (los
 * compare-and-swap de LlmsManifest, SeriesSitemapLastmod, SeriesFacts y la
 * config BYP de SeriesBundles) purgaban `alloptions` junto a su propia clave.
 * Las cuatro options se escriben con autoload apagado, así que ese blob no
 * puede contenerlas: la purga obligaba a recomponer TODAS las options
 * autocargadas del sitio —en producción, contra un object cache persistente—
 * para invalidar una fila que no estaba ahí.
 *
 * Se comprueba sobre el árbol entero y no sobre cada clase porque el defecto se
 * propagó por copia: cerrar cuatro sitios y dejar el patrón vivo garantiza que
 * el quinto vuelva a nacer igual. Si algún día el plugin necesita de verdad
 * escribir una option AUTOCARGADA por SQL directo, este test es la conversación
 * que debe ocurrir antes.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class AlloptionsEvictionBoundaryTest extends TestCase {

	/**
	 * Única excepción viva, y NO es una exención por comodidad.
	 *
	 * `HomeHero\Migration::maybe_seed()` purga las tres entradas para auto-curar
	 * un `notoptions` envenenado: la fila EXISTE en la base y `get_option()`
	 * devuelve null. Hoy `Settings::save()` escribe esa option con autoload
	 * apagado, pero la fila puede venir de una instalación anterior a esa
	 * decisión, y en ese caso el blob autocargado sí la retendría. Cerrarlo
	 * exige comprobar el `autoload` de la fila REAL en producción, que es
	 * evidencia que este árbol no tiene.
	 */
	private const EXENTOS = array( 'Modules/HomeHero/Migration.php' );

	/** @testdox Should never evict the autoloaded options blob from plugin code. */
	public function test_no_module_evicts_the_autoloaded_options_blob(): void {
		$raiz       = dirname( __DIR__ ) . '/src';
		$infractores = array();
		$ficheros   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz ) );

		foreach ( $ficheros as $fichero ) {
			if ( ! $fichero->isFile() || 'php' !== $fichero->getExtension() ) {
				continue;
			}
			$relativa = str_replace( $raiz . '/', '', (string) $fichero->getPathname() );
			if ( in_array( $relativa, self::EXENTOS, true ) ) {
				continue;
			}
			$contenido = (string) file_get_contents( (string) $fichero->getPathname() );
			if ( 1 === preg_match( "#wp_cache_delete\(\s*'alloptions'#", $contenido ) ) {
				$infractores[] = $relativa;
			}
		}

		self::assertSame(
			array(),
			$infractores,
			'Purgar `alloptions` recompone todas las options autocargadas del sitio para invalidar una fila que ese blob no contiene.'
		);
	}

	/**
	 * La purga que SÍ hace falta se conserva: sin tirar `notoptions`, una
	 * option recién creada por SQL directo seguiría marcada como inexistente.
	 *
	 * @testdox Should keep purging the negative-cache marker next to the option key.
	 */
	public function test_keeps_purging_the_negative_cache_marker(): void {
		$claves = array(
			'Plugin.php',
			'Modules/LlmsManifest/LlmsManifest.php',
			'Modules/SeoIndexing/SeriesSitemapLastmod.php',
			'Modules/SeriesFacts/SeriesFacts.php',
			'Modules/SeriesBundles/Settings.php',
		);

		foreach ( $claves as $relativa ) {
			$contenido = (string) file_get_contents( dirname( __DIR__ ) . '/src/' . $relativa );
			self::assertStringContainsString(
				"wp_cache_delete( 'notoptions', 'options' )",
				$contenido,
				"{$relativa} escribe por SQL directo y necesita invalidar el marcador negativo."
			);
		}
	}
}
