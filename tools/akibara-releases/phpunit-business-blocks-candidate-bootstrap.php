<?php
/**
 * PHPUnit bootstrap for an extracted Business + Blocks release candidate.
 *
 * This helper is local tooling only. The candidate root is mounted read-only
 * by the release test and never becomes part of either plugin ZIP.
 */

declare( strict_types = 1 );

$repo_root      = (string) getenv( 'AKB_REPO_ROOT' );
$candidate_root = (string) getenv( 'AKB_CANDIDATE_ROOT' );

if ( '' === $repo_root || '' === $candidate_root ) {
	throw new RuntimeException( 'AKB_REPO_ROOT and AKB_CANDIDATE_ROOT are required.' );
}

$repo_root      = rtrim( $repo_root, '/' );
$candidate_root = rtrim( $candidate_root, '/' );
$autoload       = $repo_root . '/tools/akibara-business/vendor/autoload.php';

if ( ! is_readable( $autoload ) ) {
	throw new RuntimeException( 'Missing the local Composer autoloader.' );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $repo_root . '/public_html/' );

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;
$loader->addPsr4(
	'Akibara\\Business\\',
	$candidate_root . '/public_html/wp-content/plugins/akibara-business/src/',
	true
);
$loader->addPsr4(
	'Akibara\\Business\\Tests\\',
	$candidate_root . '/public_html/wp-content/plugins/akibara-business/tests/',
	true
);
$loader->addPsr4(
	'Akibara\\CartCheckoutBlocks\\',
	$candidate_root . '/public_html/wp-content/plugins/akibara-cart-checkout-blocks/src/',
	true
);
$loader->addPsr4(
	'Akibara\\CartCheckoutBlocks\\Tests\\',
	$candidate_root . '/public_html/wp-content/plugins/akibara-cart-checkout-blocks/tests/php/',
	true
);

// Stubs de WordPress/WooCommerce: se cargan del CANDIDATO, no del árbol de
// trabajo, para que el gate pruebe exactamente lo que se va a publicar. Fuente
// única compartida con `tests/bootstrap.php` — antes estaban duplicados aquí y
// la copia se desincronizó (auditoría 2026-07-28).
$stubs = $candidate_root . '/public_html/wp-content/plugins/akibara-business/tests/wp-stubs.php';
if ( ! is_readable( $stubs ) ) {
    throw new RuntimeException( 'Faltan los stubs compartidos en el candidato: ' . $stubs );
}
require_once $stubs;
