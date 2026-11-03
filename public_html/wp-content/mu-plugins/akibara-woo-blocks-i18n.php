<?php
/**
 * Plugin Name: Akibara — Concordancia de los mensajes de validación de Woo Blocks
 * Description: El catálogo es_CL de WooCommerce traduce la plantilla de validación «Please enter a valid %s» como «Por favor, introduce un %s válido», que asume que toda etiqueta es masculina singular. En el checkout de Akibara eso produce «un dirección de correo electrónico válido», «un apellidos válido», «un comuna válido», «un celular válido» y «un rut válido» — justo en el punto de mayor fricción comercial. Se reescribe SOLO esa entrada por una formulación neutra de género y número, vía el filtro de traducciones de script (el mensaje lo emite el bundle JS de Blocks, no PHP). Sin tocar el plugin de terceros ni su catálogo.
 * Version: 1.0.0
 * Author: Akibara
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Entradas del catálogo JS de WooCommerce que se reescriben.
 *
 * `%s` es la ETIQUETA del campo, así que la traducción no puede concordar con
 * ella: cualquier artículo o adjetivo flexionado falla para la mitad de los
 * campos. La formulación neutra ancla la concordancia en «el campo», que es
 * invariable, y deja la etiqueta como nombre propio del campo.
 *
 * @return array<string, string>
 */
function akibara_woo_blocks_message_overrides(): array {
	return array(
		'Please enter a valid %s' => 'El campo %s no es válido.',
	);
}

/**
 * Reescribe las traducciones de script de WooCommerce antes de que WP las
 * imprima como `wp.i18n.setLocaleData`.
 *
 * El payload llega como JSON serializado (formato Jed): se decodifica, se
 * pisan solo las claves conocidas y se vuelve a serializar. Si el JSON no
 * trae ninguna de esas claves se devuelve el original sin tocar — así el
 * filtro es un no-op para los demás ficheros del dominio.
 *
 * @param string|false|null $translations JSON de traducciones, o falsy.
 * @param string|false      $file         Ruta del fichero de traducción.
 * @param string            $handle       Handle del script.
 * @param string            $domain       Text domain.
 * @return string|false|null
 */
function akibara_woo_blocks_script_translations( $translations, $file, $handle, $domain ) {
	if ( 'woocommerce' !== $domain || ! is_string( $translations ) || '' === $translations ) {
		return $translations;
	}

	$data = json_decode( $translations, true );
	if ( ! is_array( $data ) || ! isset( $data['locale_data']['messages'] ) || ! is_array( $data['locale_data']['messages'] ) ) {
		return $translations;
	}

	$changed = false;
	foreach ( akibara_woo_blocks_message_overrides() as $msgid => $replacement ) {
		if ( ! isset( $data['locale_data']['messages'][ $msgid ] ) ) {
			continue;
		}
		$data['locale_data']['messages'][ $msgid ] = array( $replacement );
		$changed = true;
	}

	if ( ! $changed ) {
		return $translations;
	}

	$encoded = wp_json_encode( $data );

	return is_string( $encoded ) ? $encoded : $translations;
}
add_filter( 'load_script_translations', 'akibara_woo_blocks_script_translations', 10, 4 );
