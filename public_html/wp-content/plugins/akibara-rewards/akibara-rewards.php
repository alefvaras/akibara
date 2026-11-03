<?php
/**
 * Plugin Name:       Akibara Rewards
 * Plugin URI:        https://akibara.cl
 * Description:       Programa de comunidad first-party de akibara.cl: Puntos Akibara (acumulación, canje IVA-correcto, bono de bienvenida, cumpleaños) y reseñas con foto (formulario por invitación, cupón, carrusel del home). Reemplaza a customer-reviews-woocommerce y simple-points-and-rewards.
 * Version:           0.4.26
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

define( 'AKIBARA_REWARDS_VERSION', '0.4.26' );
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

	if ( function_exists( 'spar_update_user_points' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-warning"><p>';
				echo '<strong>Akibara Rewards</strong> está dormido: Simple Points and Rewards sigue activo ';
				echo 'y ambos acreditarían los mismos pedidos. Runbook del cutover: (1) <code>wp akb-rewards migrate</code> ';
				echo 'con SPAR aún activo — lee su mundo y escribe solo claves akb_; (2) desactivar SPAR; ';
				echo '(3) Rewards despierta con los saldos ya puestos.';
				echo '</p></div>';
			}
		);
		return false;
	}

	return true;
}

/**
 * Boot del plugin, tras cargar todos los plugins (SPAR incluido, para poder
 * detectarlo en el gate de convivencia).
 *
 * @return void
 */
function akibara_rewards_bootstrap(): void {
	if ( ! akibara_rewards_check_dependencies() ) {
		return;
	}

	\Akibara\Rewards\Plugin::instance()->boot();
}

/*
 * El autoloader va a NIVEL DE FICHERO: el hook de activación y el comando
 * WP-CLI corren fuera del boot (y el boot ni siquiera ocurre mientras SPAR
 * siga activo).
 */
akibara_rewards_register_autoloader();

/*
 * Activación: en la request que activa el plugin, `plugins_loaded` ya pasó
 * (activate_plugin() incluye el fichero a media petición), así que registrar
 * el hook dentro del boot lo dejaba muerto. El guard de `init` de Schema es
 * la red para deploys por rsync.
 */
register_activation_hook( __FILE__, array( \Akibara\Rewards\Support\Schema::class, 'maybe_install' ) );

/*
 * El comando de migración se registra SIEMPRE, gate aparte: la migración
 * corre PRECISAMENTE con SPAR aún activo (lee su mundo, escribe solo claves
 * akb_). Con el registro dentro del boot, el runbook documentado era
 * imposible de ejecutar (hallazgo de la célula 2026-08-30).
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action(
		'plugins_loaded',
		static function (): void {
			\Akibara\Rewards\Points\Migration::register_cli();
			\Akibara\Rewards\Support\Audit::register_cli();
		},
		15
	);
}

add_action( 'plugins_loaded', 'akibara_rewards_bootstrap', 20 );

/*
 * Shim de compatibilidad para el llamador de SPAR (spec 37): la tarjeta
 * «Recompensas» del dashboard de business —el ÚNICO consumidor vivo, medido
 * el 2026-09-18: el menú móvil que este comentario nombraba desde el principio
 * nunca la llamó (hallazgo S-f2f32ad0), y creer que hay dos consumidores donde
 * hay uno cambia la decisión de retirar esta pieza— pregunta por
 * spar_get_user_points() en el namespace GLOBAL — por eso vive AQUÍ y no
 * dentro de src/ (declararla en un fichero con namespace la dejaba en
 * Akibara\Rewards\Points y la tarjeta moría muda; ALTA de la célula 1b).
 * Se define en plugins_loaded:25, DESPUÉS del gate: con SPAR activo la real
 * ya existe y este bloque no hace nada.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( function_exists( 'spar_get_user_points' ) || ! class_exists( '\\Akibara\\Rewards\\Points\\Ledger' ) ) {
			return;
		}
		if ( ! akibara_rewards_check_dependencies() ) {
			return;
		}

		/**
		 * Saldo de puntos del usuario (compatibilidad SPAR).
		 *
		 * @param int|string $user_id Usuario.
		 * @return int
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- el nombre SIN prefijo es justamente el contrato: es el shim de compatibilidad hacia los llamadores del plugin SPAR retirado, y sólo se declara si nadie más lo declaró (el function_exists de arriba). Prefijarlo lo dejaría sin servir a nadie.
		function spar_get_user_points( $user_id ): int {
			return \Akibara\Rewards\Points\Ledger::balance( (int) $user_id );
		}
	},
	25
);
