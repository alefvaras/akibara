<?php
/**
 * Bootstrap de phpstan-dba para análisis y validación de consultas SQL de WordPress/WooCommerce.
 *
 * @package Akibara\DevSecOps
 */

declare( strict_types = 1 );

use staabm\PHPStanDba\QueryReflection\QueryReflection;
use staabm\PHPStanDba\QueryReflection\ReplayAndRecordingQueryReflector;
use staabm\PHPStanDba\QueryReflection\ReflectionCache;
use staabm\PHPStanDba\QueryReflection\RuntimeConfiguration;

$cache_file = __DIR__ . '/.phpstan-dba.dump';
$config     = new RuntimeConfiguration();
$config->analyzeQueryPlans( true );

try {
	if ( class_exists( 'mysqli' ) ) {
		$mysqli = @new mysqli( '127.0.0.1', 'akibara', 'akibara_local', 'akibara' );
		if ( ! $mysqli->connect_errno ) {
			QueryReflection::setupReflector(
				new ReplayAndRecordingQueryReflector(
					ReflectionCache::create( $cache_file ),
					$mysqli,
					$config
				)
			);
		}
	}
} catch ( Throwable $e ) {
	// Fallback silencioso cuando el contenedor DB no está expuesto en localhost:3306
}
