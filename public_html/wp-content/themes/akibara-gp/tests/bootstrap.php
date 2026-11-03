<?php
/**
 * Bootstrap de las pruebas PHP del theme.
 *
 * Cierra THM-06 de la auditoría 2026-07-29: hasta acá TODA la cobertura del
 * theme era `assert.match` sobre el texto de los archivos, que verifica que una
 * cadena no cambió — no que la función se comporte. Las condiciones de carga de
 * assets y los callbacks de filtro sí tienen ramas no triviales, y una
 * regresión ahí llega a producción con la suite en verde.
 *
 * Reusa el tooling ya instalado de akibara-business (mismo PHPUnit, mismo
 * Brain Monkey) en vez de introducir un segundo árbol de dependencias.
 *
 * @package akibara-gp\Tests
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

require_once __DIR__ . '/wp-stubs.php';
