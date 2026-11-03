<?php
/**
 * Bootstrap de las pruebas PHP de Akibara Rewards.
 *
 * Reusa el vendor de tools/akibara-business (mismo PHPUnit y Brain Monkey),
 * igual que tools/akibara-search. Los stubs son PROPIOS y mínimos: el plugin
 * es «totalmente aparte» por mandato del dueño y su suite no debe depender de
 * los dobles de business.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

$repo_root = dirname( __DIR__, 5 );

defined( 'ABSPATH' ) || define( 'ABSPATH', $repo_root . '/public_html/' );
defined( 'AKIBARA_REWARDS_FILE' ) || define( 'AKIBARA_REWARDS_FILE', dirname( __DIR__ ) . '/akibara-rewards.php' );
defined( 'AKIBARA_REWARDS_DIR' ) || define( 'AKIBARA_REWARDS_DIR', dirname( __DIR__ ) . '/' );
defined( 'AKIBARA_REWARDS_VERSION' ) || define( 'AKIBARA_REWARDS_VERSION', 'test-version' );

$autoload = $repo_root . '/tools/akibara-business/vendor/autoload.php';
if ( ! is_readable( $autoload ) ) {
	throw new RuntimeException(
		'Falta el tooling local. Ejecuta ./tools/akibara-business/install.sh desde la raíz del proyecto.'
	);
}
require_once $autoload;

/*
 * Patchwork ANTES de declarar nada redefinible (lección de la suite business:
 * una función declarada antes del primer Monkey\setUp() queda fuera de su
 * instrumentación y muere con DefinedTooEarly).
 */
require_once $repo_root . '/tools/akibara-business/vendor/antecedent/patchwork/Patchwork.php';

/*
 * Constantes que Core define siempre en runtime. Centralizadas para que un
 * test aislado no dependa del orden de ejecución.
 */
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );

// Autoloader del árbol src/ del plugin (espejo del runtime, sin WordPress).
spl_autoload_register(
	static function ( string $class ): void {
		$prefix   = 'Akibara\\Rewards\\';
		$base_dir = dirname( __DIR__ ) . '/src/';

		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/Support/wp-stubs.php';
require_once __DIR__ . '/Support/FakeWpdb.php';
require_once __DIR__ . '/Support/RewardsTestCase.php';
