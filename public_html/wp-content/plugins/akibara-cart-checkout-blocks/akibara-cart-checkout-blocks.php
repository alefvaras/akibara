<?php
/**
 * Plugin Name:       Akibara Cart and Checkout Blocks
 * Description:       Dedicated WooCommerce Cart and Checkout Blocks adapter for Akibara Business.
 * Version:           2.2.34
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce, akibara-business
 * Author:            Akibara
 * License:           Proprietary
 * Update URI:        false
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

defined( 'ABSPATH' ) || exit;

define( 'AKIBARA_CART_CHECKOUT_BLOCKS_VERSION', '2.2.34' );
define( 'AKIBARA_CART_CHECKOUT_BLOCKS_FILE', __FILE__ );
define( 'AKIBARA_CART_CHECKOUT_BLOCKS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AKIBARA_CART_CHECKOUT_BLOCKS_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', AKIBARA_CART_CHECKOUT_BLOCKS_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', AKIBARA_CART_CHECKOUT_BLOCKS_FILE, true );
	}
);

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ), 30 );
