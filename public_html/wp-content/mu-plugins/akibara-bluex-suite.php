<?php
/**
 * Plugin Name: Akibara — Suite Unificada de Blue Express
 * Description: Suite consolidada para Blue Express en Akibara: cotización con comuna, traducciones en español, vigía de integridad, retención de logs y compatibilidad REST tracking.
 * Version: 2.0.0
 * Author: Akibara
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* =========================================================================
 * SECCIÓN 1: Cotización sólo con región y comuna
 * ========================================================================= */

/**
 * Métodos de Blue Express que cotizan contra su API en cada cálculo.
 *
 * Los ids salen de `includes/shipping/class-wc-bluex-express.php` del plugin.
 *
 * @return array<int, string>
 */
function akibara_bluex_metodos_que_cotizan(): array {
	return array( 'bluex-ex', 'bluex-py', 'bluex-md' );
}

/**
 * Sin región y comuna no hay cotización posible: el método no está disponible.
 *
 * @param mixed $disponible Lo que WooCommerce ya decidió.
 * @param mixed $package    Paquete del carrito, con `destination`.
 * @return bool
 */
function akibara_bluex_disponible_solo_con_comuna( $disponible, $package = array() ): bool {
	if ( ! $disponible ) {
		return false;
	}

	$destino = is_array( $package ) && isset( $package['destination'] ) && is_array( $package['destination'] )
		? $package['destination']
		: array();
	$region  = trim( (string) ( $destino['state'] ?? '' ) );
	$comuna  = trim( (string) ( $destino['city'] ?? '' ) );

	return '' !== $region && '' !== $comuna;
}

/**
 * Engancha la disponibilidad sólo si el plugin de Blue Express está cargado.
 *
 * `plugins_loaded` a prioridad 20: Blue Express incluye sus clases en
 * `WC_Correios::init()`, colgado de `plugins_loaded` a prioridad 10.
 * @psalm-suppress HookNotFound
 */
function akibara_bluex_registrar_cotiza_solo_con_comuna(): void {
	if ( ! class_exists( 'WC_Correios_Webservice' ) ) {
		return;
	}

	foreach ( akibara_bluex_metodos_que_cotizan() as $id ) {
		add_filter( 'woocommerce_shipping_' . $id . '_is_available', 'akibara_bluex_disponible_solo_con_comuna', 10, 2 );
	}
}
/** @psalm-suppress HookInvalidArgs */
add_action( 'plugins_loaded', 'akibara_bluex_registrar_cotiza_solo_con_comuna', 20 );

/* =========================================================================
 * SECCIÓN 2: Traducciones en español y enlace de seguimiento
 * ========================================================================= */

const AKIBARA_BLUEX_TEXT_DOMAIN = 'woocommerce-correios';

/**
 * Lo que ve el CLIENTE, en español.
 *
 * @return array<string, string>
 */
function akibara_bluex_traducciones(): array {
	return array(
		// Correo de seguimiento (asunto, encabezado y cuerpo).
		'Your order has been sent'                                                                  => 'Tu pedido ha sido despachado',
		'[{site_title}] Your order {order_number} has been sent by BlueX'                           => '[{site_title}] Tu pedido {order_number} ha sido despachado por Blue Express',
		'Hi there. Your recent order on {site_title} has been sent by BlueX.'                       => '¡Hola! Tu pedido en {site_title} ha sido despachado por Blue Express.',
		'To track your delivery, use the following the tracking code(s): {tracking_code}'           => 'Para rastrear tu envío, utiliza el siguiente número de seguimiento: {tracking_code}',
		'The delivery service is the responsibility of the BlueX, but if you have any questions, please contact us.' => 'El servicio de entrega es responsabilidad de Blue Express, pero si tienes alguna consulta no dudes en contactarnos.',
		'For your reference, your order details are shown below.'                                   => 'Aquí abajo tienes el detalle de tu pedido.',
		'This email is sent when configured a tracking code within an order.'                       => 'Se envía al cliente cuando se agrega un código de seguimiento al pedido.',

		// Bloque de seguimiento en Mi cuenta → detalle del pedido.
		'BlueX delivery tracking'                                                                   => 'Seguimiento de tu envío',
		'Query on BlueX'                                                                            => 'Ver el estado en Blue Express',
		'History for the tracking code:'                                                            => 'Historial del código de seguimiento:',
		'Date'                                                                                      => 'Fecha',
		'Location'                                                                                  => 'Lugar',
		'Status'                                                                                    => 'Estado',
		'In transit to %s'                                                                          => 'En tránsito a %s',
	);
}

/**
 * Plurales del bloque de Mi cuenta.
 *
 * @return array<string, array{0:string,1:string}>
 */
function akibara_bluex_plurales(): array {
	return array(
		'Tracking code:' => array( 'Código de seguimiento:', 'Códigos de seguimiento:' ),
	);
}

const AKIBARA_BLUEX_SEGUIMIENTO_URL = 'https://www.blue.cl/enviar/seguimiento';

/**
 * En el correo, el número de seguimiento lleva a Blue Express.
 *
 * @param string $html          Enlace que arma el plugin.
 * @param string $tracking_code Número de seguimiento.
 * @return string
 * @psalm-suppress RedundantCast
 */
function akibara_bluex_enlace_del_correo( $html, $tracking_code ) {
	$codigo = trim( (string) $tracking_code );
	if ( '' === $codigo ) {
		return $html;
	}

	return sprintf(
		'<a href="%s" target="_blank" rel="nofollow noopener noreferrer">%s</a>',
		esc_url( add_query_arg( 'n_seguimiento', $codigo, AKIBARA_BLUEX_SEGUIMIENTO_URL ) ),
		esc_html( $codigo )
	);
}
/** @psalm-suppress HookNotFound */
add_filter( 'woocommerce_correios_email_tracking_core_url', 'akibara_bluex_enlace_del_correo', 10, 2 );

/**
 * Traduce una cadena simple del plugin de Blue Express.
 *
 * @param string $traduccion Traducción actual.
 * @param string $texto      Original en inglés.
 * @param string $dominio    Text domain.
 * @return string
 */
function akibara_bluex_gettext( $traduccion, $texto, $dominio ) {
	if ( AKIBARA_BLUEX_TEXT_DOMAIN !== $dominio ) {
		return $traduccion;
	}

	$mapa = akibara_bluex_traducciones();

	return $mapa[ $texto ] ?? $traduccion;
}
add_filter( 'gettext', 'akibara_bluex_gettext', 10, 3 );

/**
 * Traduce los plurales del plugin de Blue Express.
 *
 * @param string $traduccion Traducción actual.
 * @param string $singular   Original singular.
 * @param string $plural     Original plural.
 * @param int    $numero     Cantidad.
 * @param string $dominio    Text domain.
 * @return string
 * @psalm-suppress RedundantCast
 */
function akibara_bluex_ngettext( $traduccion, $singular, $plural, $numero, $dominio ) {
	unset( $plural );

	if ( AKIBARA_BLUEX_TEXT_DOMAIN !== $dominio ) {
		return $traduccion;
	}

	$mapa = akibara_bluex_plurales();
	if ( ! isset( $mapa[ $singular ] ) ) {
		return $traduccion;
	}

	return 1 === (int) $numero ? $mapa[ $singular ][0] : $mapa[ $singular ][1];
}
add_filter( 'ngettext', 'akibara_bluex_ngettext', 10, 5 );

/* =========================================================================
 * SECCIÓN 3: Vigía de integridad estructural de Blue Express
 * ========================================================================= */

/**
 * Escanea los PHP de bluex buscando include/require de ficheros ausentes.
 *
 * @return array<int,string>|null Líneas «fichero:carga» rotas (vacío = sano, null = no instalado).
 * @psalm-suppress RedundantCast
 */
function akibara_bluex_broken_includes(): ?array {
	$base = WP_PLUGIN_DIR . '/bluex-for-woocommerce';
	if ( ! is_dir( $base ) ) {
		return null;
	}

	$rotos = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $it as $file ) {
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$contenido = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! preg_match_all( '/(?:include|require)(?:_once)?\s*\(?\s*(?:__DIR__\s*\.\s*|dirname\s*\(\s*__FILE__\s*\)\s*\.\s*|plugin_dir_path\s*\(\s*__FILE__\s*\)\s*\.\s*)?([\'"])([^\'"]+\.php)\1/i', $contenido, $m, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		foreach ( $m[2] as $hit ) {
			$ruta = $hit[0];
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
 * Notifica comprobaciones de integridad.
 *
 * @param string $origen Qué disparó el chequeo.
 * @return void
 */
function akibara_bluex_integrity_check( string $origen ): void {
	$rotos = akibara_bluex_broken_includes();
	$admin = (string) get_option( 'admin_email' );

	if ( null === $rotos ) {
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
				"El chequeo ($origen) encontró cargas de ficheros AUSENTES en bluex-for-woocommerce.\n\n- "
					. implode( "\n- ", $rotos )
			);
		}
		return;
	}

	if ( 'yes' === get_option( 'akb_bluex_watch_baseline_seen' )
		&& 'yes' !== get_option( 'akb_bluex_watch_resolved_notified' )
		&& '' !== $admin ) {
		update_option( 'akb_bluex_watch_resolved_notified', 'yes', false );
		wp_mail(
			$admin,
			'[Akibara] ✅ Blue Express arregló su paquete',
			"El chequeo ($origen) ya no encuentra includes rotos en bluex-for-woocommerce."
		);
	}
}

add_action(
	'upgrader_process_complete',
	static function ( $upgrader, $extra ): void {
		unset( $upgrader );
		/** @psalm-suppress RedundantCondition */
		if ( is_array( $extra ) && 'plugin' === ( $extra['type'] ?? '' ) ) {
			akibara_bluex_integrity_check( 'post-actualización' );
		}
	},
	10,
	2
);

/**
 * Agenda el barrido semanal de integridad de Blue.
 */
function akibara_bluex_watch_agendar(): void {
	$puede_reparar = wp_doing_cron()
		|| ( defined( 'WP_CLI' ) && WP_CLI )
		|| ( is_admin() && ! wp_doing_ajax() );

	if ( ! $puede_reparar ) {
		return;
	}

	if ( 'ya' === get_transient( 'akb_bluex_watch_agendado' ) ) {
		return;
	}
	if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
		return;
	}

	set_transient( 'akb_bluex_watch_agendado', 'ya', DAY_IN_SECONDS );

	if ( 'yes' !== get_option( 'akb_bluex_watch_migracion_grupo' )
		&& function_exists( 'as_unschedule_all_actions' )
	) {
		as_unschedule_all_actions( 'akibara_bluex_integrity_weekly' );
		update_option( 'akb_bluex_watch_migracion_grupo', 'yes', false );
	}

	if ( as_has_scheduled_action( 'akibara_bluex_integrity_weekly', null, 'akibara' ) ) {
		return;
	}

	as_schedule_recurring_action(
		time() + HOUR_IN_SECONDS,
		WEEK_IN_SECONDS,
		'akibara_bluex_integrity_weekly',
		array(),
		'akibara',
		true
	);
}

/** @psalm-suppress HookInvalidArgs */
add_action( 'init', 'akibara_bluex_watch_agendar', 30 );
/** @psalm-suppress HookInvalidArgs */
add_action(
	'akibara_bluex_integrity_weekly',
	static function (): void {
		akibara_bluex_integrity_check( 'barrido semanal' );
	}
);

/* =========================================================================
 * SECCIÓN 4: Retención y purga de logs de Blue Express en base de datos
 * ========================================================================= */

const AKIBARA_BLUEX_LOG_RETENTION_HOOK       = 'akibara_bluex_log_retention_cleanup';
const AKIBARA_BLUEX_LOG_RETENTION_GROUP      = 'akibara-bluex';
const AKIBARA_BLUEX_LOG_RETENTION_DAYS       = 2;
const AKIBARA_BLUEX_LOG_RETENTION_BATCH_SIZE = 1000;

/**
 * Elimina logs de Blue Express fuera de la ventana de retención.
 *
 * @return int Cantidad de filas eliminadas.
 */
function akibara_bluex_log_retention_cleanup(): int {
	global $wpdb;

	$table_name   = $wpdb->prefix . 'bluex_logs';
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
 * Programa la tarea recurrente de Action Scheduler.
 */
function akibara_bluex_schedule_log_retention(): void {
	$puede_reparar = wp_doing_cron()
		|| ( defined( 'WP_CLI' ) && WP_CLI )
		|| ( is_admin() && ! wp_doing_ajax() );

	if ( ! $puede_reparar ) {
		return;
	}

	if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
		return;
	}

	if ( as_has_scheduled_action( AKIBARA_BLUEX_LOG_RETENTION_HOOK, array(), AKIBARA_BLUEX_LOG_RETENTION_GROUP ) ) {
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

/** @psalm-suppress HookNotFound */
add_action( 'bluex_clean_logs', 'akibara_bluex_log_retention_cleanup' );
/** @psalm-suppress HookInvalidArgs */
add_action( AKIBARA_BLUEX_LOG_RETENTION_HOOK, 'akibara_bluex_log_retention_cleanup' );
/** @psalm-suppress HookNotFound */
add_action( 'action_scheduler_init', 'akibara_bluex_schedule_log_retention', 20 );

/* =========================================================================
 * SECCIÓN 5: Compatibilidad con WooCommerce REST API (wc/v3 tracking)
 * ========================================================================= */

/**
 * Guarda códigos de seguimiento recibidos por REST API autenticada.
 *
 * @param mixed $order         Instancia de WC_Order.
 * @param mixed $tracking_code Códigos separados por coma.
 * @return bool
 */
function akibara_bluex_store_tracking_codes( $order, $tracking_code ): bool {
	if ( ! $order instanceof WC_Order || ! is_string( $tracking_code ) || '' === trim( $tracking_code ) ) {
		return false;
	}

	if ( ! function_exists( 'wc_correios_updateTrackingCode' ) ) {
		akibara_bluex_log_discarded_tracking( $order, $tracking_code );
		return false;
	}

	$updated = false;
	foreach ( preg_split( '/\s*,\s*/', $tracking_code ) as $code ) {
		$code = sanitize_text_field( $code );
		if ( '' !== $code ) {
			$updated = wc_correios_updateTrackingCode( $order, $code ) || $updated;
		}
	}

	return $updated;
}

/**
 * Registra advertencia si no existe la función del plugin Blue Express.
 *
 * @param WC_Order $order         Pedido afectado.
 * @param string   $tracking_code Código recibido.
 * @return void
 */
function akibara_bluex_log_discarded_tracking( WC_Order $order, string $tracking_code ): void {
	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}

	wc_get_logger()->error(
		sprintf(
			'wc_correios_updateTrackingCode() no existe: se descartó el seguimiento «%1$s» del pedido #%2$d. Revisar bluex-for-woocommerce.',
			$tracking_code,
			$order->get_id()
		),
		array( 'source' => 'akibara-bluex' )
	);
}

/**
 * Callback para el campo REST.
 *
 * @param string   $tracking_code Código de seguimiento.
 * @param WC_Order $order         Instancia de WC_Order.
 * @return bool
 */
function akibara_bluex_update_rest_tracking_field( $tracking_code, $order ): bool {
	return akibara_bluex_store_tracking_codes( $order, $tracking_code );
}

/**
 * Reemplaza el callback de terceros en rest_api_init.
 */
function akibara_bluex_register_rest_tracking_compatibility(): void {
	global $wp_rest_additional_fields;

	if ( isset( $wp_rest_additional_fields['shop_order']['correios_tracking_code'] ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wp_rest_additional_fields['shop_order']['correios_tracking_code']['update_callback'] = 'akibara_bluex_update_rest_tracking_field';
	}
}
add_action( 'rest_api_init', 'akibara_bluex_register_rest_tracking_compatibility', 101 );

/**
 * Fallback para controladores REST WooCommerce que no procesan campos adicionales.
 *
 * @param WC_Order        $order    Pedido actualizado.
 * @param WP_REST_Request $request  Petición REST.
 * @param bool            $creating Si el pedido se estaba creando.
 * @return void
 */
function akibara_bluex_sync_rest_tracking_code( $order, $request, bool $creating ): void {
	unset( $creating );

	if ( ! $request instanceof WP_REST_Request ) {
		return;
	}

	akibara_bluex_store_tracking_codes( $order, $request->get_param( 'correios_tracking_code' ) );
}
/** @psalm-suppress HookNotFound */
add_action( 'woocommerce_rest_insert_shop_order_object', 'akibara_bluex_sync_rest_tracking_code', 20, 3 );
