<?php
/**
 * Plugin Name: Akibara — retención de Action Scheduler a 14 días
 * Description: Configura 14 días de retención para acciones completadas o canceladas. Action Scheduler ejecuta su limpieza programada por lotes; no modifica las acciones pendientes ni la retención independiente de las fallidas.
 *
 * @package Akibara
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_filter(
	'action_scheduler_retention_period',
	static function (): int {
		return 14 * DAY_IN_SECONDS;
	}
);
