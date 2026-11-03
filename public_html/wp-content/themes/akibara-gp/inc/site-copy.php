<?php
/**
 * Textos del sitio que el dueño edita desde Akibara → Textos.
 *
 * El tema pinta varias promesas y datos de contacto que estaban escritos a mano
 * en las plantillas: el sello de cuotas (que el checkout YA leía de una option,
 * así que la tienda podía contradecirse a sí misma) y el horario y el teléfono
 * del pie, que aparecen en TODAS las páginas y sólo se podían cambiar tocando
 * código.
 *
 * Reglas de este archivo:
 *
 *   - Cada helper trae como default el literal que estaba en la plantilla, así
 *     que estrenar la configurabilidad no cambia ni una letra de lo servido.
 *   - El tema lee `get_option()` en vez de llamar a una clase de
 *     `akibara-business`: un tema no puede quedarse sin pie porque un plugin
 *     esté desactivado. El nombre de la option es el contrato compartido.
 *   - Un valor vacío es una decisión, no un error: apaga la línea en vez de
 *     imprimir una etiqueta huérfana.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Texto de una option de copy, ya recortado, o el literal de fábrica.
 *
 * @param string $option   Nombre de la option compartida con akibara-business.
 * @param string $fallback Literal que la plantilla tenía escrito a mano.
 */
function akibara_gp_copy( string $option, string $fallback ): string {
	$copy = get_option( $option, $fallback );
	$copy = is_string( $copy ) ? trim( $copy ) : '';

	return '' !== $copy ? $copy : $fallback;
}

/**
 * La promesa comercial de cuotas (ficha, carrito vacío y checkout).
 * Lee unificadamente la opción configurable `akb_copy_promise_installments`.
 */
function akibara_gp_promise_installments(): string {
	return akibara_gp_copy( 'akb_copy_promise_installments', '3 cuotas sin interés' );
}

/**
 * Nombre de marca de las reseñas: el título de la sección de reseñas de la
 * ficha y el del carrusel de reseñas de la portada son el MISMO texto («Voces
 * del Distrito»). Lo edita el dueño en Akibara → Puntos y Reseñas → sección
 * «Carrusel del home» → «Título de las reseñas (portada y fichas)», cuya ayuda
 * ya dice que titula las dos superficies. La option es de akibara-rewards y su
 * nombre es el contrato compartido, igual que las de Akibara → Textos. Nunca
 * vacío: un título en blanco dejaría la barra roja del encabezado huérfana.
 */
function akibara_gp_reviews_title(): string {
	return akibara_gp_copy( 'akb_rewards_carousel_title', 'Voces de Akibara' );
}

/**
 * Placeholder del buscador de la cabecera de escritorio (UC-16). Nunca vacío:
 * un campo de búsqueda sin pista se lee como una caja cualquiera. El cajón
 * móvil no lo usa: allí «Buscar…» es corto a propósito (no cabe más a 340 px).
 *
 * Se edita en Akibara → Textos → «Blog / Buscador» (CopyManager de
 * akibara-business, que la sanea a una línea y vuelve al de fábrica si queda
 * vacía). El nombre de la option es el contrato entre los dos: no cambia.
 */
function akibara_gp_search_placeholder(): string {
	return akibara_gp_copy( 'akb_copy_search_placeholder', 'Buscar manga, series o autor…' );
}

/**
 * Nombre accesible del buscador de la cabecera: el MISMO texto que se ve como
 * pista, sin los puntos suspensivos del final (QA-ESC-TECLADO-6, WCAG 2.5.3
 * «etiqueta en el nombre»). La etiqueta oculta decía «Buscar productos» y la
 * pista «Buscar manga, series o autor…»: quien usa control por voz dice lo que
 * ve («clic en buscar manga») y no acertaba, y el lector anunciaba sólo
 * productos. Sale de la misma option, así que lo que escriba el dueño en
 * Akibara → Textos cambia a la vez lo que se ve y lo que se anuncia; si la
 * pista fuera sólo puntos suspensivos, vuelve a la de fábrica.
 *
 * Con `preg_replace` en modo /u y no con `rtrim()`: rtrim cuenta BYTES, y «…»
 * son tres (E2 80 A6) que también terminan otras letras (la «À» es C3 80): una
 * pista acabada en esa letra salía partida por la mitad.
 */
function akibara_gp_search_label(): string {
	$label = preg_replace( '/[\s.…]+$/u', '', akibara_gp_search_placeholder() );

	return is_string( $label ) && '' !== $label ? $label : 'Buscar manga, series o autor';
}

/**
 * Título de la fila de los más vendidos en un vacío: la búsqueda sin resultados
 * (inc/woocommerce.php, UM-8) y el listado vacío de WooCommerce
 * (woocommerce/loop/no-products-found.php). El mismo texto en las dos: son la
 * misma salida comercial. Se edita en Akibara → Textos → «Blog / Buscador»
 * (R-I1-6: era un literal del tema en una superficie nueva).
 */
function akibara_gp_empty_popular_title(): string {
	return akibara_gp_copy( 'akb_copy_search_empty_popular', 'Lo más vendido' );
}

/**
 * Textos del cierre de las entradas del blog y de la tarjeta de la serie de una
 * guía (UC-12 = UM-17, inc/guide-series.php). Los dos primeros eran literales de
 * single.php; los otros tres son nuevos: el botón del cierre cuando la guía tiene
 * serie, el rótulo de la tarjeta y su botón (corto: el nombre de la serie ya va
 * justo encima). `{serie}` se reemplaza por el nombre de la serie (str_replace,
 * nunca sprintf): sin el marcador, el texto sale tal cual (el panel lo avisa al
 * guardar). Se editan en la misma sección que la pista del buscador.
 *
 * @return array{texto:string,tienda:string,serie:string,kicker:string,boton:string}
 */
function akibara_gp_guide_copy(): array {
	return array(
		'texto'  => akibara_gp_copy( 'akb_copy_guide_cta_text', '¿Te inspiró? Suma estos títulos a tu colección.' ),
		'tienda' => akibara_gp_copy( 'akb_copy_guide_cta_shop', 'Explorar la tienda' ),
		'serie'  => akibara_gp_copy( 'akb_copy_guide_cta_series', 'Ver los tomos de {serie}' ),
		'kicker' => akibara_gp_copy( 'akb_copy_guide_card_kicker', 'La serie en Akibara' ),
		'boton'  => akibara_gp_copy( 'akb_copy_guide_card_cta', 'Ver los tomos' ),
	);
}

/**
 * Línea de horario de atención del pie. Vacía = la línea no se pinta: un
 * horario en blanco junto al icono del reloj promete atención sin decir cuándo.
 */
function akibara_gp_footer_support_hours(): string {
	$copy = get_option( 'akb_copy_footer_support_hours', 'Horario de soporte: Lun a Vie · 10:00 a 18:00 hrs' );

	return is_string( $copy ) ? trim( $copy ) : '';
}

/**
 * Teléfono público tal como se muestra. Vacío = ni el enlace `tel:` ni el de
 * WhatsApp se pintan.
 *
 * Es UN campo del que se derivan las tres formas: el número visible con sus
 * espacios, el `tel:` y el `wa.me`. Antes eran tres literales independientes en
 * la misma plantilla, y cambiar el número obligaba a acertarle a los tres.
 */
function akibara_gp_footer_phone(): string {
	$copy = get_option( 'akb_copy_footer_phone', '+56 9 4424 2844' );

	return is_string( $copy ) ? trim( $copy ) : '';
}

/**
 * Sólo los dígitos del teléfono: lo que necesitan `tel:` y `wa.me`. Cadena
 * vacía cuando no hay número que marcar.
 */
function akibara_gp_footer_phone_digits(): string {
	$digits = preg_replace( '/\D+/', '', akibara_gp_footer_phone() );

	return is_string( $digits ) ? $digits : '';
}
