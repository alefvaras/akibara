<?php
/**
 * Plugin Name: Akibara — Indexación externa bloqueada fuera de producción
 * Description: Evita que Docker, tests o réplicas locales envíen URLs localhost a IndexNow. La configuración productiva de Rank Math permanece intacta.
 * Version: 1.1.0
 * Author: Akibara
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Exige entorno production y dominio oficial mediante el mismo predicado que
 * el correo y Clarity. Los clones pueden heredar el entorno y las claves.
 * Si falta el transporte que valida el dominio, bloquea la indexación externa.
 *
 * @return bool
 */
function akibara_external_indexing_es_la_tienda_de_verdad(): bool {
	if ( 'production' !== wp_get_environment_type() ) {
		return false;
	}

	return class_exists( '\Akibara\HostingerMail\Transport' )
		&& \Akibara\HostingerMail\Transport::is_production_host( (string) home_url() );
}

/**
 * Cancela cualquier URL que Rank Math intente publicar fuera de producción.
 *
 * Se cubren los dos seams oficiales del módulo: el automático (`publish_url`)
 * y el común a envíos automáticos/manuales (`submit_url`). Devolver false es
 * el contrato documentado por el propio módulo para cancelar el envío antes de
 * abrir una conexión o escribir una entrada de éxito en su log.
 *
 * @param mixed $url URL candidata.
 * @return mixed URL original en producción; false en cualquier otro entorno.
 */
function akibara_external_indexing_url_guard( $url ) {
	return akibara_external_indexing_es_la_tienda_de_verdad() ? $url : false;
}

add_filter(
	'rank_math/instant_indexing/publish_url',
	'akibara_external_indexing_url_guard',
	PHP_INT_MAX
);
add_filter(
	'rank_math/instant_indexing/submit_url',
	'akibara_external_indexing_url_guard',
	PHP_INT_MAX
);

/**
 * Impide que un envío bloqueado deje trazas mutables en la réplica local.
 *
 * La ruta REST/manual de Rank Math llama `Api::submit()` directamente y puede
 * saltarse `rank_math/instant_indexing/submit_url`. Aunque el guard HTTP evita
 * la red, Rank Math registra el WP_Error mediante `update_option()`. Conservar
 * exactamente el valor anterior hace que `update_option()` sea un no-op, incluso
 * cuando la opción todavía no existe y WordPress pretendía crearla.
 *
 * @param mixed $value     Nuevo valor propuesto.
 * @param mixed $old_value Valor persistido antes del intento.
 * @return mixed Nuevo valor intacto en producción; valor anterior fuera de ella.
 */
function akibara_external_indexing_log_guard( $value, $old_value ) {
	return akibara_external_indexing_es_la_tienda_de_verdad() ? $value : $old_value;
}

add_filter(
	'pre_update_option_rank_math_indexnow_log',
	'akibara_external_indexing_log_guard',
	PHP_INT_MAX,
	2
);

/**
 * Repite la protección en el filtro global, que WordPress ejecuta después del
 * filtro dinámico de la opción.
 *
 * @param mixed  $value     Nuevo valor propuesto tras filtros específicos.
 * @param string $option    Nombre de la opción.
 * @param mixed  $old_value Valor persistido antes del intento.
 * @return mixed
 */
function akibara_external_indexing_global_option_guard( $value, string $option, $old_value ) {
	if ( 'rank_math_indexnow_log' !== $option || akibara_external_indexing_es_la_tienda_de_verdad() ) {
		return $value;
	}

	return $old_value;
}

add_filter(
	'pre_update_option',
	'akibara_external_indexing_global_option_guard',
	PHP_INT_MAX,
	3
);

/**
 * Defensa en profundidad si un caller salta los filtros públicos de Rank Math.
 *
 * @param mixed                $preempt Respuesta preemptiva anterior.
 * @param array<string, mixed> $args    Argumentos HTTP (no se modifican).
 * @param string               $url     URL remota.
 * @return mixed
 */
function akibara_external_indexing_http_guard( $preempt, array $args, string $url ) {
	unset( $args );

	if ( akibara_external_indexing_es_la_tienda_de_verdad() ) {
		return $preempt;
	}

	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! is_string( $host ) || 'api.indexnow.org' !== strtolower( $host ) ) {
		return $preempt;
	}

	return new WP_Error(
		'akibara_local_external_indexing_blocked',
		'IndexNow está bloqueado fuera de producción.'
	);
}

add_filter(
	'pre_http_request',
	'akibara_external_indexing_http_guard',
	PHP_INT_MAX,
	3
);
