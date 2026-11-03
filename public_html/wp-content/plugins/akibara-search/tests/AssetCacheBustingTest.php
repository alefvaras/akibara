<?php
/**
 * Contrato de cache-busting de los assets del plugin.
 *
 * `.htaccess` sirve estos ficheros con un año de caché (`public_html/.htaccess`),
 * así que el `?ver=` es lo ÚNICO que hace llegar un cambio al navegador. El
 * bundle del autocomplete —el único asset del plugin presente en todas las
 * páginas— viajaba con la constante pelada: editarlo sin subir la versión del
 * plugin dejaba la URL byte-idéntica y el cliente seguía con el JS viejo.
 *
 * La invariante es «ningún asset del plugin se sirve con la versión pelada»,
 * no una cadena concreta: si mañana el esquema cambia, cambia en un solo sitio.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Search;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/Search.php';

defined( 'AKIBARA_SEARCH_DIR' ) || define( 'AKIBARA_SEARCH_DIR', dirname( __DIR__ ) . '/' );
defined( 'AKIBARA_SEARCH_URL' ) || define( 'AKIBARA_SEARCH_URL', 'https://example.test/wp-content/plugins/akibara-search/' );
defined( 'AKIBARA_SEARCH_VERSION' ) || define( 'AKIBARA_SEARCH_VERSION', '0.0.0-test' );

/** @covers \Akibara\Search\Search::asset_version */
final class AssetCacheBustingTest extends TestCase {

	/** @testdox Un asset existente se sirve con un token que se mueve cuando el fichero se mueve. */
	public function test_un_asset_existente_lleva_la_fecha_del_fichero(): void {
		$relative = 'assets/js/search-suggest.js';
		$path     = AKIBARA_SEARCH_DIR . $relative;

		self::assertFileExists( $path, 'El bundle del autocomplete es el asset que este contrato protege.' );

		$version = Search::asset_version( $relative );

		self::assertNotSame( AKIBARA_SEARCH_VERSION, $version, 'La versión pelada no invalida nada.' );
		self::assertStringContainsString( (string) filemtime( $path ), $version );
		self::assertSame( $version, Search::asset_version( '/' . $relative ), 'La barra inicial no cambia el token.' );
	}

	/** @testdox Un asset que no llegó al despliegue degrada a la versión del plugin, no fatalea. */
	public function test_un_asset_ausente_degrada_a_la_version_del_plugin(): void {
		self::assertSame( AKIBARA_SEARCH_VERSION, Search::asset_version( 'assets/js/no-existe.js' ) );
	}

	/**
	 * @testdox Ningún encolado del plugin pasa la versión pelada como `?ver=`.
	 *
	 * El defecto no era de cálculo sino de OMISIÓN: tres encolados aplicaban el
	 * esquema y el cuarto —el que se sirve en todas las páginas— no.
	 */
	public function test_ningun_encolado_del_plugin_usa_la_version_pelada(): void {
		$sources = array_merge(
			(array) glob( dirname( __DIR__ ) . '/src/*.php' ),
			(array) glob( dirname( __DIR__ ) . '/frontend/*.php' )
		);

		$checked = 0;
		foreach ( $sources as $file ) {
			$source = (string) file_get_contents( (string) $file );
			preg_match_all(
				'/wp_enqueue_(?:script|style)\(\s*(?:[^;]*?)\);/s',
				$source,
				$matches
			);
			foreach ( $matches[0] as $call ) {
				++$checked;
				self::assertStringContainsString(
					'asset_version(',
					$call,
					basename( (string) $file ) . ': el `?ver=` sale del helper único, no de la constante.'
				);
			}
		}

		self::assertGreaterThan( 0, $checked, 'El escaneo tiene que ver encolados reales.' );
	}
}
