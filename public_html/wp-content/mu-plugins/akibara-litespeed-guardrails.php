<?php
/**
 * Plugin Name: Akibara — Barandas de LiteSpeed
 * Description: Neutraliza las optimizaciones de LiteSpeed que rompen el tema, cachean el carrito, apagan la carga diferida de WordPress o dejan al celular sin caché de página, aunque alguien las active desde la interfaz.
 * Version:     1.5.0
 * Author:      Akibara
 *
 * @package Akibara
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Desactiva la combinación de CSS para conservar el patrón media=print y
 * onload de las hojas diferidas del tema. LiteSpeed no conserva ese onload
 * al combinar. El filtro actúa al leer la configuración, sin alterar el
 * minificado independiente.
 *
 * @return bool
 */
add_filter(
	'litespeed_conf_load_option_optm-css_comb',
	static function () {
		return false;
	},
	99
);

/**
 * Desactiva la combinación de JavaScript para conservar los archivos y
 * dependencias declarados por el tema y los plugins.
 *
 * @return bool
 */
add_filter(
	'litespeed_conf_load_option_optm-js_comb',
	static function () {
		return false;
	},
	99
);

/**
 * Desactiva Guest Optimization para que no fuerce el combinado por encima
 * de los filtros de configuración. La opción se llama guest_optm, con guion
 * bajo. Guest Mode y su caché conservan su configuración propia.
 *
 * @return bool
 */
add_filter(
	'litespeed_conf_load_option_guest_optm',
	static function () {
		return false;
	},
	99
);

/**
 * Reserva la carga diferida de imágenes a WordPress y al tema. El lazyload
 * de LiteSpeed desactiva el nativo y puede diferir también la portada marcada
 * fetchpriority=high. Requiere mantener desactivado guest_optm, que puede
 * forzar la reescritura de imágenes por encima de media-lazy.
 *
 * @return bool
 */
add_filter(
	'litespeed_conf_load_option_media-lazy',
	static function () {
		return false;
	},
	99
);

/**
 * Añade las rutas Store API y REST de WooCommerce a las exclusiones de
 * caché sin reemplazar las rutas existentes del sitio. Protege también las
 * respuestas de clientes autenticados frente a la caché privada de LiteSpeed.
 *
 * @param mixed $valor_guardado Lo que LiteSpeed tenga guardado en `cache-exc`.
 * @return array<int, string>
 */
function akibara_litespeed_rutas_sin_cache( $valor_guardado ): array {
	$obligatorias = array( '/wp-json/wc/store/', '/wp-json/wc/v3/' );
	$excluidas    = akibara_litespeed_lista_de_rutas( $valor_guardado );

	foreach ( $obligatorias as $ruta ) {
		if ( ! in_array( $ruta, $excluidas, true ) ) {
			$excluidas[] = $ruta;
		}
	}

	return $excluidas;
}

/**
 * Acepta listas y texto con una ruta por línea. Recorta espacios y descarta
 * líneas vacías, duplicados y entradas no escalares para conservar exclusiones
 * introducidas por la interfaz, importaciones o WP-CLI.
 *
 * @param mixed $valor Lo guardado: array, texto con saltos de línea, o cualquier otra cosa.
 * @return array<int, string>
 */
function akibara_litespeed_lista_de_rutas( $valor ): array {
	if ( is_string( $valor ) ) {
		$valor = preg_split( '/[\r\n]+/', $valor );
	}

	if ( ! is_array( $valor ) ) {
		return array();
	}

	$limpias = array();
	foreach ( $valor as $entrada ) {
		if ( ! is_scalar( $entrada ) ) {
			continue;
		}
		$entrada = trim( (string) $entrada );
		if ( '' !== $entrada && ! in_array( $entrada, $limpias, true ) ) {
			$limpias[] = $entrada;
		}
	}

	return $limpias;
}

add_filter(
	'litespeed_conf_load_option_cache-exc',
	'akibara_litespeed_rutas_sin_cache',
	99
);

/**
 * Desactiva la caché separada por dispositivo porque el tema sirve HTML
 * responsivo común. LiteSpeed requiere una lista de agentes y el marcador
 * MOBILE de .htaccess para variar la copia móvil; sin esos datos, cache-mobile
 * puede impedir cachear las visitas desde el teléfono.
 *
 * Si el HTML pasa a variar por dispositivo, deben revisarse juntos este filtro,
 * la lista de agentes y el marcador MOBILE. Los lectores de .htaccess y
 * QUIC.cloud pueden consultar opciones sin pasar por este filtro.
 *
 * @return bool
 */
add_filter(
	'litespeed_conf_load_option_cache-mobile',
	static function () {
		return false;
	},
	99
);

/**
 * Limita optm-js_defer a 0 (apagado) o 1 (defer). El valor 2 retrasa la
 * ejecución hasta la primera interacción y no permite que los fragments del
 * carrito y el formulario de compra se inicialicen al cargar la página.
 * El panel puede conservar el valor 2 guardado; el filtro aplica 1 al leerlo.
 *
 * @param mixed $valor_guardado Lo que dice la opción (0, 1 o 2).
 * @return int 0 o 1.
 */
function akibara_litespeed_js_sin_retraso( $valor_guardado ): int {
	return min( 1, max( 0, (int) $valor_guardado ) );
}
add_filter( 'litespeed_conf_load_option_optm-js_defer', 'akibara_litespeed_js_sin_retraso', 99 );
