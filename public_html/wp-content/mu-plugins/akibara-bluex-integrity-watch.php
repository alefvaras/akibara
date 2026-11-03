<?php
/**
 * Plugin Name: Akibara — vigía de integridad de Blue Express
 * Description: Verifica los include/require resolubles de Blue Express después de actualizar plugins y en un barrido semanal. Notifica al dueño las cargas de archivos ausentes y la recuperación posterior.
 *
 * Lee el árbol del tercero sin modificarlo. El aviso identifica archivo y
 * línea de cada carga rota. No aporta clases de respaldo: cualquier include
 * ausente se trata como riesgo inmediato para el checkout.
 *
 * Version: 1.2.0
 * Author: Akibara
 *
 * @package Akibara
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Escanea los PHP de bluex buscando include/require de ficheros ausentes.
 *
 * @return array<int,string>|null Líneas «fichero:carga» rotas (vacío = sano, null = el plugin no está instalado).
 */
function akibara_bluex_broken_includes(): ?array {
	$base = WP_PLUGIN_DIR . '/bluex-for-woocommerce';
	if ( ! is_dir( $base ) ) {
		// null distingue un plugin ausente de un árbol sano y evita anunciar
		// recuperación cuando el transportista no está instalado.
		return null;
	}

	$rotos = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $it as $file ) {
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$contenido = (string) file_get_contents( $file->getPathname() );
		// Solo cargas RESOLUBLES estáticamente: literal, o __DIR__/plugin_dir_path + literal.
		if ( ! preg_match_all( '/(?:include|require)(?:_once)?\s*\(?\s*(?:__DIR__\s*\.\s*|dirname\s*\(\s*__FILE__\s*\)\s*\.\s*|plugin_dir_path\s*\(\s*__FILE__\s*\)\s*\.\s*)?([\'"])([^\'"]+\.php)\1/i', $contenido, $m, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		foreach ( $m[2] as $hit ) {
			$ruta = $hit[0];
			// Relativas al fichero que las carga (el caso de class-wc-correios.php).
			$candidatos = array(
				dirname( $file->getPathname() ) . '/' . ltrim( $ruta, '/' ),
				$base . '/' . ltrim( $ruta, '/' ),
			);
			$existe = false;
			foreach ( $candidatos as $c ) {
				if ( file_exists( $c ) ) {
					$existe = true;
					break;
				}
			}
			if ( ! $existe && false === strpos( $ruta, '$' ) ) {
				$linea   = substr_count( substr( $contenido, 0, (int) $hit[1] ), "\n" ) + 1;
				$rotos[] = str_replace( $base . '/', '', $file->getPathname() ) . ':' . $linea . ' → ' . $ruta;
			}
		}
	}

	return array_values( array_unique( $rotos ) );
}

/**
 * Notifica cada comprobación con includes rotos y una única recuperación
 * posterior. La ausencia del plugin conserva las marcas sin anunciar éxito.
 *
 * @param string $origen Qué disparó el chequeo (para el asunto).
 * @return void
 */
function akibara_bluex_integrity_check( string $origen ): void {
	$rotos = akibara_bluex_broken_includes();
	$admin = (string) get_option( 'admin_email' );

	if ( null === $rotos ) {
		// Sin plugin no hay nada que vigilar, pero tampoco nada que celebrar: no
		// se toca ninguna marca, así que si vuelve roto el vigía sigue donde
		// estaba, y si vuelve sano la buena noticia sale entonces y no ahora.
		return;
	}

	if ( array() !== $rotos ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- integridad de pagos no puede quedar muda.
		error_log( '[akibara-bluex-watch] Includes rotos (' . $origen . '): ' . implode( ' | ', $rotos ) );
		update_option( 'akb_bluex_watch_baseline_seen', 'yes', false );
		delete_option( 'akb_bluex_watch_resolved_notified' );

		if ( '' !== $admin ) {
			wp_mail(
				$admin,
				'[Akibara] ⚠ Blue Express embarcó includes rotos — checkout en riesgo',
				"El chequeo ($origen) encontró cargas de ficheros AUSENTES en bluex-for-woocommerce. Ya no hay guard amortiguando: el checkout puede estar caído AHORA.\n\n- "
					. implode( "\n- ", $rotos )
					. "\n\nRearme exprés (mientras Blue publica el arreglo): bajar las clases ausentes de plugins.svn.wordpress.org/bluex-for-woocommerce/trunk/includes/ a mu-plugins/bluex-fallback/ y recrear el guard de carga condicional en plugins_loaded:99 — pasos completos en COORDINACION.md (sección del ciclo Blue Express)."
					. "\n\nRevisar ANTES de que un cliente lo encuentre."
			);
		}
		return;
	}

	// Buena noticia, una sola vez: Blue arregló su paquete.
	if ( 'yes' === get_option( 'akb_bluex_watch_baseline_seen' )
		&& 'yes' !== get_option( 'akb_bluex_watch_resolved_notified' )
		&& '' !== $admin ) {
		update_option( 'akb_bluex_watch_resolved_notified', 'yes', false );
		wp_mail(
			$admin,
			'[Akibara] ✅ Blue Express arregló su paquete',
			"El chequeo ($origen) ya no encuentra includes rotos en bluex-for-woocommerce: Blue embarcó los ficheros que faltaban. Si se montó el rearme exprés (guard + bluex-fallback/), ya puede retirarse — el vigía se queda."
		);
	}
}

// Disparo 1: inmediatamente después de cualquier actualización de plugins.
add_action(
	'upgrader_process_complete',
	static function ( $upgrader, $extra ): void {
		unset( $upgrader );
		if ( is_array( $extra ) && 'plugin' === ( $extra['type'] ?? '' ) ) {
			akibara_bluex_integrity_check( 'post-actualización' );
		}
	},
	10,
	2
);

/**
 * Agenda el barrido semanal de integridad de Blue, con grupo y `unique`.
 */
function akibara_bluex_watch_agendar(): void {
	// `is_admin()` también es true en admin-ajax.php, que es por donde se
	// hidrata el carrito del cliente —medido en el mu-plugin hermano,
	// akibara-bluex-log-retention.php— y en producción DISABLE_WP_CRON es
	// true, así que el disparo real viene de WP-CLI, donde `wp_doing_cron()`
	// es FALSE. Los tres caminos de abajo son los únicos donde alguien puede
	// reparar la cola.
	$puede_reparar = wp_doing_cron()
		|| ( defined( 'WP_CLI' ) && WP_CLI )
		|| ( is_admin() && ! wp_doing_ajax() );

	if ( ! $puede_reparar ) {
		return;
	}

	// El memo evita consultar la agenda más de una vez al día; unique protege
	// la programación aunque no exista ese memo.
	if ( 'ya' === get_transient( 'akb_bluex_watch_agendado' ) ) {
		return;
	}
	if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
		return;
	}

	set_transient( 'akb_bluex_watch_agendado', 'ya', DAY_IN_SECONDS );

	// Migra una sola vez las agendas sin grupo antes de crear la recurrente
	// agrupada. La eliminación sin grupo alcanza todas las acciones del hook.
	if ( 'yes' !== get_option( 'akb_bluex_watch_migracion_grupo' )
		&& function_exists( 'as_unschedule_all_actions' )
	) {
		as_unschedule_all_actions( 'akibara_bluex_integrity_weekly' );
		update_option( 'akb_bluex_watch_migracion_grupo', 'yes', false );
	}

	// `as_has_scheduled_action` y no `as_next_scheduled_action`: la primera
	// responde sí/no y la segunda arma el objeto entero para devolver una
	// fecha que aquí nadie mira.
	if ( as_has_scheduled_action( 'akibara_bluex_integrity_weekly', null, 'akibara' ) ) {
		return;
	}

	// El grupo y unique evitan duplicar la recurrente entre peticiones.
	as_schedule_recurring_action(
		time() + HOUR_IN_SECONDS,
		WEEK_IN_SECONDS,
		'akibara_bluex_integrity_weekly',
		array(),
		'akibara',
		true
	);
}

// Disparo 2: barrido semanal de respaldo (Action Scheduler, patrón de la casa).
add_action( 'init', 'akibara_bluex_watch_agendar', 30 );
add_action(
	'akibara_bluex_integrity_weekly',
	static function (): void {
		akibara_bluex_integrity_check( 'barrido semanal' );
	}
);
