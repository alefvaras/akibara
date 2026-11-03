<?php
/**
 * Bluex database log retention for Akibara.
 *
 * @package Akibara
 * @since   1.0.0
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const AKIBARA_BLUEX_LOG_RETENTION_HOOK = 'akibara_bluex_log_retention_cleanup';
const AKIBARA_BLUEX_LOG_RETENTION_GROUP = 'akibara-bluex';
const AKIBARA_BLUEX_LOG_RETENTION_DAYS = 2;
const AKIBARA_BLUEX_LOG_RETENTION_BATCH_SIZE = 1000;

/**
 * Delete Bluex logs that are outside the retention window.
 *
 * @since 1.0.0
 *
 * @return int Number of deleted log rows.
 */
function akibara_bluex_log_retention_cleanup(): int {
	global $wpdb;

	$table_name = $wpdb->prefix . 'bluex_logs';
	$table_exists = $wpdb->get_var(
		$wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$wpdb->esc_like( $table_name )
		)
	);

	if ( $table_name !== $table_exists ) {
		return 0;
	}

	$deleted_rows = 0;
	do {
		$deleted_batch = (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE log_timestamp < DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY log_timestamp LIMIT %d',
				$table_name,
				AKIBARA_BLUEX_LOG_RETENTION_DAYS,
				AKIBARA_BLUEX_LOG_RETENTION_BATCH_SIZE
			)
		);
		$deleted_rows += $deleted_batch;
	} while ( AKIBARA_BLUEX_LOG_RETENTION_BATCH_SIZE === $deleted_batch );

	return $deleted_rows;
}

/**
 * Ensure the Action Scheduler retention task exists.
 *
 * @since 1.0.0
 *
 * @return void
 */
function akibara_bluex_schedule_log_retention(): void {
	// Repara la agenda sólo desde cron, WP-CLI o administración sin AJAX.
	// is_admin() también es true para admin-ajax del carrito; WP-CLI puede
	// ejecutar la cola con wp_doing_cron() falso. La acción recurrente ya
	// programada mantiene su reprogramación independiente de este guard.
	$puede_reparar = wp_doing_cron()
		|| ( defined( 'WP_CLI' ) && WP_CLI )
		|| ( is_admin() && ! wp_doing_ajax() );

	if ( ! $puede_reparar ) {
		return;
	}

	if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
		return;
	}

	// Sólo necesita saber si existe la acción: as_has_scheduled_action evita
	// construir el objeto completo para consultar una fecha que no se usa.
	if ( function_exists( 'as_has_scheduled_action' ) ) {
		if ( as_has_scheduled_action( AKIBARA_BLUEX_LOG_RETENTION_HOOK, array(), AKIBARA_BLUEX_LOG_RETENTION_GROUP ) ) {
			return;
		}
	} elseif ( function_exists( 'as_next_scheduled_action' ) ) {
		if ( false !== as_next_scheduled_action( AKIBARA_BLUEX_LOG_RETENTION_HOOK, array(), AKIBARA_BLUEX_LOG_RETENTION_GROUP ) ) {
			return;
		}
	} else {
		return;
	}

	as_schedule_recurring_action(
		time() + HOUR_IN_SECONDS,
		DAY_IN_SECONDS,
		AKIBARA_BLUEX_LOG_RETENTION_HOOK,
		array(),
		AKIBARA_BLUEX_LOG_RETENTION_GROUP,
		true
	);
}

add_action( 'bluex_clean_logs', 'akibara_bluex_log_retention_cleanup' );
add_action( AKIBARA_BLUEX_LOG_RETENTION_HOOK, 'akibara_bluex_log_retention_cleanup' );
add_action( 'action_scheduler_init', 'akibara_bluex_schedule_log_retention', 20 );
