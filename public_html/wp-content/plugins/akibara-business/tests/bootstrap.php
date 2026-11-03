<?php
/**
 * Bootstrap de pruebas unitarias.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

$repo_root = dirname( __DIR__, 5 );

defined( 'ABSPATH' ) || define( 'ABSPATH', $repo_root . '/public_html/' );

$autoload = $repo_root . '/tools/akibara-business/vendor/autoload.php';
if ( ! is_readable( $autoload ) ) {
	throw new RuntimeException(
		'Falta el tooling local. Ejecuta ./tools/akibara-business/install.sh desde la raíz del proyecto.'
	);
}
require_once $autoload;

/*
 * Patchwork ANTES que cualquier test.
 *
 * Brain Monkey lo carga perezosamente, en su primer `Monkey\setUp()`. Un test
 * que no usa Brain Monkey —`PudoIntegrationTest` de cart-checkout-blocks carga
 * `fixtures/woocommerce-functions.php`, que declara `is_cart()` y
 * `wc_get_page_id()`— podía correr antes y dejar esas funciones definidas sin
 * instrumentar. A partir de ahí, cualquier test que quisiera stubearlas moría
 * con `DefinedTooEarly`… pero sólo en las permutaciones donde ese archivo
 * corría primero: verde en orden natural, rojo con `--order-by=random`.
 *
 * La whitelist de `tools/akibara-business/patchwork.json` es la otra mitad:
 * por defecto Patchwork sólo instrumenta el árbol del proyecto que lo aloja
 * (el runner), y los tests viven en `public_html/`.
 */
require_once $repo_root . '/tools/akibara-business/vendor/antecedent/patchwork/Patchwork.php';

/*
 * Constantes de `$wpdb` que WordPress define siempre en runtime. Sin esto, un
 * test que corra AISLADO revienta con "Undefined constant ARRAY_A" mientras la
 * suite completa pasa —porque otro test la define primero—, que es la peor
 * clase de verde: depende del orden [ronda 7.1].
 */
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'OBJECT_K' ) || define( 'OBJECT_K', 'OBJECT_K' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );

/*
 * Constantes temporales que Core carga siempre en `wp-includes/default-constants.php`.
 * Centralizarlas evita que una suite pase sólo porque otro test las definió antes.
 */
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );

/*
 * La versión es global e inmutable en PHP. Varios tests de assets la definían
 * perezosamente como `test-version`, por lo que el resultado de la suite
 * dependía del orden: el contrato de upgrade (correctamente semver-only) veía
 * ese valor si otro archivo corría primero. Sembrar la versión real del release
 * en el bootstrap reproduce WordPress y hace la suite determinista.
 */
if ( ! defined( 'AKIBARA_BUSINESS_VERSION' ) ) {
	$plugin_header = file_get_contents( dirname( __DIR__ ) . '/akibara-business.php' );
	if ( ! is_string( $plugin_header ) || 1 !== preg_match( '/^ \* Version:\s+(\d+\.\d+\.\d+)\s*$/m', $plugin_header, $version_match ) ) {
		throw new RuntimeException( 'No se pudo medir la versión del candidato Business.' );
	}
	define( 'AKIBARA_BUSINESS_VERSION', $version_match[1] );
}

/*
 * Y lo mismo con la ruta y la URL del plugin. Las definía, al cargarse,
 * `FrontendCompatibilityTest.php`; las demás pruebas que las usan pasaban sólo
 * porque PHPUnit carga TODOS los ficheros de prueba antes de correr ninguno, y
 * corridas solas caían con «Undefined constant» (2026-09-19,
 * `tools/test-clases-aisladas.sh`). Son los mismos valores que ya ganaban en la
 * suite completa, para no cambiar lo que ninguna prueba mide.
 */
defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__ ) . '/' );
defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://example.test/wp-content/plugins/akibara-business/' );

require_once __DIR__ . '/wp-stubs.php';
