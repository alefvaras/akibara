<?php
/**
 * Plugin Name: Akibara — Clarity solo en producción
 * Description: Desactiva el tag de Microsoft Clarity en cualquier sitio que no sea la tienda de verdad. Las sesiones de QA en Docker (localhost:8080) estaban contaminando las métricas reales del sitio (aparecían en páginas populares, embudo y scroll depth). Se quitan los hooks del plugin oficial sin tocarlo.
 * Version: 1.1.0
 * Author: Akibara
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Exige simultáneamente entorno production y dominio oficial. Un clon puede
 * heredar el entorno de wp-config.php. Si falta el transporte que define los
 * dominios admitidos, mantiene desactivado el tag de Clarity.
 *
 * @return bool
 */
function akibara_clarity_es_la_tienda_de_verdad(): bool {
	if ( 'production' !== wp_get_environment_type() ) {
		return false;
	}

	return class_exists( '\Akibara\HostingerMail\Transport' )
		&& \Akibara\HostingerMail\Transport::is_production_host( (string) home_url() );
}

add_action(
	'init',
	static function (): void {
		if ( akibara_clarity_es_la_tienda_de_verdad() ) {
			return;
		}

		// El plugin oficial inyecta el tag en wp_head y un módulo frontend aparte.
		remove_action( 'wp_head', 'clarity_add_script_to_header' );
		remove_action( 'wp_head', 'brand_agent_add_script_to_header' );
		remove_action( 'wp_enqueue_scripts', 'brand_agent_enqueue_frontend_module' );
	},
	1
);
