<?php
/**
 * Plugin Name:       Akibara Rewards
 * Plugin URI:        https://akibara.cl
 * Description:       Programa de comunidad first-party de akibara.cl: Puntos Akibara (acumulación, canje IVA-correcto, bono de bienvenida, cumpleaños) y reseñas con foto (formulario por invitación, cupón, carrusel del home). Reemplaza a customer-reviews-woocommerce y simple-points-and-rewards.
 * Version:           0.4.39
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * WC requires at least: 10.0
 * WC tested up to:      11.0
 * Author:            Akibara
 * Author URI:        https://akibara.cl
 * License:           Proprietary
 * Update URI:        false
 * Requires Plugins:  woocommerce
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants.
// ---------------------------------------------------------------------------

define( 'AKIBARA_REWARDS_VERSION', '0.4.39' );
define( 'AKIBARA_REWARDS_FILE', __FILE__ );
define( 'AKIBARA_REWARDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AKIBARA_REWARDS_URL', plugin_dir_url( __FILE__ ) );
define( 'AKIBARA_REWARDS_MIN_WC', '10.0' );

// ---------------------------------------------------------------------------
// HPOS + WC Blocks compatibility (declared before WC init).
// ---------------------------------------------------------------------------

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				AKIBARA_REWARDS_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				AKIBARA_REWARDS_FILE,
				true
			);
		}
	}
);

// ---------------------------------------------------------------------------
// Bootstrap.
// ---------------------------------------------------------------------------

/**
 * Autoloader propio del plugin. Sin vendor/: el árbol runtime no carga
 * dependencias de terceros y así el ZIP publicado es sólo código first-party
 * (mismo criterio que el fallback de akibara-business).
 *
 * @return void
 */
function akibara_rewards_register_autoloader(): void {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix   = 'Akibara\\Rewards\\';
			$base_dir = AKIBARA_REWARDS_DIR . 'src/';

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
}

/**
 * Verifica dependencias antes de bootear. La convivencia con SPAR está
 * PROHIBIDA: ambos acreditarían el mismo pedido (doble recompensa) y ambos
 * registrarían el endpoint `rewards`. Mientras el plugin viejo siga activo,
 * este se queda dormido con un aviso, sin tocar nada.
 *
 * @return bool True si se puede bootear.
 */
function akibara_rewards_check_dependencies(): bool {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-error"><p>';
				echo '<strong>Akibara Rewards</strong> requiere WooCommerce activo.';
				echo '</p></div>';
			}
		);
		return false;
	}

	if ( defined( 'WC_VERSION' ) && version_compare( (string) WC_VERSION, AKIBARA_REWARDS_MIN_WC, '<' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-error"><p>';
				echo esc_html(
					sprintf(
						'Akibara Rewards requiere WooCommerce %s o superior (activa: %s).',
						AKIBARA_REWARDS_MIN_WC,
						(string) WC_VERSION
					)
				);
				echo '</p></div>';
			}
		);
		return false;
	}

	return true;
}

/**
 * Boot del plugin.
 *
 * @return void
 */
function akibara_rewards_bootstrap(): void {
	if ( ! akibara_rewards_check_dependencies() ) {
		return;
	}

	\Akibara\Rewards\Plugin::instance()->boot();
}

akibara_rewards_register_autoloader();

register_activation_hook( __FILE__, array( \Akibara\Rewards\Support\Schema::class, 'maybe_install' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action(
		'plugins_loaded',
		static function (): void {
			\Akibara\Rewards\Support\Audit::register_cli();
		},
		15
	);
}

add_action( 'plugins_loaded', 'akibara_rewards_bootstrap', 20 );
