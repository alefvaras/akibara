<?php
/**
 * Plugin Name: Akibara — Rotación de debug.log y canal limpio de fatales
 * Description: Rota debug.log y fatal_crash.log por tamaño con generaciones acotadas y registra sólo errores fatales en el segundo canal. Permanece inerte fuera del entorno local.
 * Version: 1.1.0
 * Author: Akibara
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/** Tamaño a partir del cual se rota `debug.log`. */
const AKIBARA_DEBUG_LOG_MAX_BYTES = 2097152; // 2 MB.

/** Generaciones conservadas (`debug.log.1` … `.3`). */
const AKIBARA_DEBUG_LOG_KEEP = 3;

/**
 * Tamaño a partir del cual se rota `fatal_crash.log`.
 *
 * Más bajo que el de `debug.log` a propósito: este canal existe para leerse
 * entero de una pasada, y un archivo de señal alta que hay que filtrar deja de
 * ser señal alta. Un fatal ocupa unas 200 bytes: 512 KB son miles de líneas.
 */
const AKIBARA_FATAL_LOG_MAX_BYTES = 524288; // 512 KB.

/** Generaciones conservadas de `fatal_crash.log`. */
const AKIBARA_FATAL_LOG_KEEP = 2;

/**
 * Ruta del log de depuración según la configuración vigente.
 *
 * @return string Ruta absoluta, o '' si el log no es un archivo.
 */
function akibara_debug_log_path(): string {
	if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
		return WP_DEBUG_LOG;
	}
	if ( defined( 'WP_DEBUG_LOG' ) && true === WP_DEBUG_LOG ) {
		return WP_CONTENT_DIR . '/debug.log';
	}
	return '';
}

/**
 * Rota ambos canales con el mismo contrato de tamaño y generaciones.
 *
 * @param string $log          Ruta absoluta del log.
 * @param int    $tope         Bytes a partir de los cuales se rota.
 * @param int    $generaciones Cuántas copias `.1`, `.2`… se conservan.
 * @return void
 */
function akibara_log_maybe_rotate( string $log, int $tope, int $generaciones ): void {
	if ( '' === $log || ! is_file( $log ) ) {
		return;
	}

	$size = @filesize( $log ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- un log ilegible no debe romper el request.
	if ( ! is_int( $size ) || $size < $tope ) {
		return;
	}

	// Descartar la generación más vieja y correr el resto un peldaño.
	$oldest = $log . '.' . $generaciones;
	if ( is_file( $oldest ) ) {
		@unlink( $oldest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	for ( $i = $generaciones - 1; $i >= 1; $i-- ) {
		$from = $log . '.' . $i;
		if ( is_file( $from ) ) {
			@rename( $from, $log . '.' . ( $i + 1 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
	@rename( $log, $log . '.1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

/**
 * Rota `debug.log` cuando supera el tope, conservando N generaciones.
 *
 * Se engancha en `shutdown` con guarda de transient (una comprobación por
 * minuto como máximo): no se usa WP-Cron a propósito, porque el propio evento
 * programado depende de que el cron funcione — exactamente lo que falló y
 * produjo el archivo gigante.
 *
 * @return void
 */
function akibara_debug_log_maybe_rotate(): void {
	if ( 'local' !== wp_get_environment_type() ) {
		return;
	}

	if ( get_transient( 'akibara_debug_log_rotation_checked' ) ) {
		return;
	}
	set_transient( 'akibara_debug_log_rotation_checked', 1, MINUTE_IN_SECONDS );

	akibara_log_maybe_rotate( akibara_debug_log_path(), AKIBARA_DEBUG_LOG_MAX_BYTES, AKIBARA_DEBUG_LOG_KEEP );
	akibara_log_maybe_rotate( WP_CONTENT_DIR . '/fatal_crash.log', AKIBARA_FATAL_LOG_MAX_BYTES, AKIBARA_FATAL_LOG_KEEP );
}
add_action( 'shutdown', 'akibara_debug_log_maybe_rotate', 9999 );

/**
 * Canal separado de errores FATALES.
 *
 * `fatal_crash.log` existía vacío y sin dueño: sugería una observabilidad que
 * nadie implementaba. Acá se le da contenido real — E_ERROR, E_PARSE,
 * E_COMPILE_ERROR, E_CORE_ERROR y también E_USER_ERROR, que es el que lanza
 * código de aplicación con `trigger_error()` y por donde los terceros meten más
 * ruido (el comentario decía cuatro y la línea capturaba cinco: la lista de
 * abajo es la que manda) — para que revisar «¿se rompió algo?» sea
 * mirar un archivo corto en vez de filtrar miles de notices de terceros.
 * `debug.log` sigue recibiendo todo, sin cambios.
 *
 * La rotación de ESTE archivo no puede colgar sólo del `shutdown` de arriba: un
 * fatal corta la petición y ese hook no llega a correr, así que el único momento
 * garantizado para mirar su tamaño es justo antes de escribir.
 *
 * @return void
 */
function akibara_capture_fatal_to_own_channel(): void {
	if ( 'local' !== wp_get_environment_type() ) {
		return;
	}

	$error = error_get_last();
	if ( null === $error ) {
		return;
	}

	$fatal_levels = E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR | E_USER_ERROR;
	if ( 0 === ( $error['type'] & $fatal_levels ) ) {
		return;
	}

	$destino = WP_CONTENT_DIR . '/fatal_crash.log';
	akibara_log_maybe_rotate( $destino, AKIBARA_FATAL_LOG_MAX_BYTES, AKIBARA_FATAL_LOG_KEEP );

	$line = sprintf(
		"[%s] %s en %s:%d%s",
		gmdate( 'd-M-Y H:i:s' ) . ' UTC',
		$error['message'],
		$error['file'],
		$error['line'],
		PHP_EOL
	);

	// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- canal de crash: no puede depender de WP_Filesystem ni fallar ruidosamente.
	@file_put_contents( $destino, $line, FILE_APPEND | LOCK_EX );
}
register_shutdown_function( 'akibara_capture_fatal_to_own_channel' );
