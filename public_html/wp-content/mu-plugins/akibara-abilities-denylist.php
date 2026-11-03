<?php
/**
 * Plugin Name: Akibara — denylist de abilities peligrosas
 * Version: 1.2.0
 * Description: Bloquea las abilities de autoactualización y desactivación de módulos Rank Math y la eliminación de productos WooCommerce. Durante el registro las oculta del adaptador MCP y reemplaza permission_callback por una denegación registrada en el log. Conserva disponibles las demás abilities.
 *
 * @package Akibara
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_filter(
	'wp_register_ability_args',
	/**
	 * Veto en el registro de las abilities selladas como peligrosas.
	 *
	 * @param array<string,mixed> $args Argumentos del registro.
	 * @param string              $name Nombre de la ability.
	 * @return array<string,mixed>
	 */
	static function ( $args, $name ) {
		$vetadas = array(
			'rank-math/set-plugin-preferences', // Solo sirve para auto-updates: NUNCA.
			'rank-math/set-module-status',      // Apagar redirections/sitemap/llms con un clic remoto: no.

			// La política del dueño permite crear y editar; veta la eliminación de productos.
			'woocommerce/product-delete',
		);

		if ( ! in_array( (string) $name, $vetadas, true ) || ! is_array( $args ) ) {
			return $args;
		}

		// Registra la denegación aquí: check_permissions() corta antes de los
		// hooks de ejecución, tanto para REST y MCP como para WP_Ability::execute().
		$args['permission_callback'] = static function () use ( $name ): bool {
			if ( function_exists( 'akibara_abilities_audit_anota' ) ) {
				akibara_abilities_audit_anota(
					sprintf(
						'VETADA %s · por %s · la lista de la casa la bloqueó',
						(string) $name,
						akibara_abilities_audit_quien()
					)
				);
			}

			return false;
		};

		if ( isset( $args['meta']['mcp'] ) && is_array( $args['meta']['mcp'] ) ) {
			$args['meta']['mcp']['public'] = false;
		}

		return $args;
	},
	10,
	2
);
