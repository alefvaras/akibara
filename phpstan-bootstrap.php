<?php
/**
 * Bootstrap global para PHPStan, stubs y constantes de WordPress/WooCommerce.
 *
 * @package Akibara\DevSecOps
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/public_html/' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
defined( 'WP_PLUGIN_DIR' ) || define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );

defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'OBJECT_K' ) || define( 'OBJECT_K', 'OBJECT_K' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', ABSPATH . 'wp-content/plugins/akibara-business/' );
defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://akibara.cl/wp-content/plugins/akibara-business/' );
defined( 'AKIBARA_BUSINESS_VERSION' ) || define( 'AKIBARA_BUSINESS_VERSION', '1.122.0' );

defined( 'AKIBARA_SEARCH_DIR' ) || define( 'AKIBARA_SEARCH_DIR', ABSPATH . 'wp-content/plugins/akibara-search/' );
defined( 'AKIBARA_SEARCH_URL' ) || define( 'AKIBARA_SEARCH_URL', 'https://akibara.cl/wp-content/plugins/akibara-search/' );
defined( 'AKIBARA_SEARCH_VERSION' ) || define( 'AKIBARA_SEARCH_VERSION', '0.1.95' );

defined( 'AKIBARA_REWARDS_DIR' ) || define( 'AKIBARA_REWARDS_DIR', ABSPATH . 'wp-content/plugins/akibara-rewards/' );
defined( 'AKIBARA_REWARDS_URL' ) || define( 'AKIBARA_REWARDS_URL', 'https://akibara.cl/wp-content/plugins/akibara-rewards/' );
defined( 'AKIBARA_REWARDS_VERSION' ) || define( 'AKIBARA_REWARDS_VERSION', '0.4.37' );

defined( 'AKIBARA_CART_CHECKOUT_BLOCKS_DIR' ) || define( 'AKIBARA_CART_CHECKOUT_BLOCKS_DIR', ABSPATH . 'wp-content/plugins/akibara-cart-checkout-blocks/' );
defined( 'AKIBARA_CART_CHECKOUT_BLOCKS_URL' ) || define( 'AKIBARA_CART_CHECKOUT_BLOCKS_URL', 'https://akibara.cl/wp-content/plugins/akibara-cart-checkout-blocks/' );
defined( 'AKIBARA_CART_CHECKOUT_BLOCKS_VERSION' ) || define( 'AKIBARA_CART_CHECKOUT_BLOCKS_VERSION', '1.0.0' );
defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );

if ( file_exists( __DIR__ . '/phpstan-dba-bootstrap.php' ) ) {
	require_once __DIR__ . '/phpstan-dba-bootstrap.php';
}
