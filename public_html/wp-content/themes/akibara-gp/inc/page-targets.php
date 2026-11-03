<?php
/**
 * Akibara GP — Resolución de páginas objetivo por SLUG, no por ID.
 *
 * Las condiciones de carga de assets referenciaban IDs de base de datos
 * incrustados (210, 22637, 208, 24356, 185, 240, 23641, 3, 23640, 24484)
 * repartidos en `inc/enqueue.php`, `inc/performance.php` y `functions.php`.
 * Eso acopla el theme al autoincremento de `wp_posts` de UN entorno: una
 * importación, una migración o un ambiente nuevo cambia el ID y la rama deja
 * de coincidir en silencio — la página se queda sin su CSS y nada falla
 * ruidosamente. Dos de esos IDs (22637 «encargos» y 24484
 * «suscripcion-confirmada») ya no correspondían a ningún post: eran ramas
 * muertas, y peor, un ID reciclado por otro contenido las habría activado
 * sobre la página equivocada.
 *
 * `is_page()` acepta slugs de forma nativa, así que acá se centraliza el
 * conjunto en helpers con nombre. Un cambio de contenido ahora se arregla
 * renombrando un slug, no cazando números por tres archivos.
 *
 * Pasar de ID a slug dejó las dos ramas muertas a la vista pero las conservó.
 * En 2.1.139 se retiraron: medido en producción, `encargos` y
 * `suscripcion-confirmada` no existen como post en NINGÚN estado (ni en la
 * papelera), y `/suscripcion-confirmada/` responde 404. Un slug declarado aquí
 * sin página publicada detrás no protege nada: no casa nunca y hace creer al
 * que lee que la superficie existe.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Páginas estáticas content-driven que usan el sistema de `pages.css`
 * (`.akb-page__hero`, `.akb-page__section`, `.akb-info-grid`, `.akb-faq`,
 * `.akb-page__toc`).
 */
const AKIBARA_GP_CONTENT_PAGES = array(
	'contacto',
	'editoriales',
	'series',
	'faq',
	'nosotros',
	'envios',
	'embalaje-seguro',
	'devoluciones',
	'terminos-y-condiciones',
	'politica-de-privacidad',
);

/**
 * Slug de la home alternativa (la página «Inicio» editable, distinta de la
 * front page cuando el sitio sirve una estática).
 */
const AKIBARA_GP_HOME_ALT = 'home-1';

/**
 * ¿WooCommerce está activo? Comprueba la existencia de funciones condicionales núcleo.
 *
 * Fuente única centralizada para toda guarda de disponibilidad de WooCommerce
 * en el tema. Al cargarse en el primer `require_once` de `functions.php`,
 * garantiza protección uniforme para hooks y filtros posteriores frente a
 * escenarios de mantenimiento, actualizaciones o plugins desactivados.
 *
 * @return bool
 */
function akibara_gp_wc_activo(): bool {
	return function_exists( 'is_shop' ) && function_exists( 'is_product' );
}

/**
 * ¿Estamos en una superficie «home»? Front page o la home alternativa.
 *
 * @return bool
 */
function akibara_gp_is_home_surface(): bool {
	return is_front_page() || is_page( AKIBARA_GP_HOME_ALT );
}

/**
 * ¿Estamos en una de las páginas estáticas content-driven?
 *
 * @return bool
 */
function akibara_gp_is_content_page(): bool {
	return is_page( AKIBARA_GP_CONTENT_PAGES );
}

/**
 * ¿Esta superficie renderiza una rejilla de productos con botón de compra?
 *
 * FUENTE ÚNICA. La lista vivía copiada en tres sitios y las copias derivaron:
 * el dequeue de `wc-add-to-cart` (functions.php) y el encolado de GSAP/atc-guard
 * (enqueue.php) se quedaron sin `pa_genero` ni `pa_autor`, así que los archivos
 * de /genero/ y /autor/ —que sí rinden la rejilla y sí pintan «Añadir al
 * carrito»— servían esos botones sin el script que los hace funcionar: el clic
 * navegaba en vez de agregar, y el drawer nunca se abría.
 *
 * `is_cart()`/`is_account_page()` entran porque el carrito vacío y
 * /mi-cuenta/favoritos/ rinden cross-sell y wishlist con el mismo marcado.
 *
 * @return bool
 */
function akibara_gp_surface_has_product_loop(): bool {
	$is_catalog_archive = function_exists( 'akibara_gp_is_catalog_archive' )
		&& akibara_gp_is_catalog_archive();

	return ( function_exists( 'is_woocommerce' ) && (
			is_shop()
			|| is_product_category()
			|| is_product_tag()
			|| is_tax( array( 'product_brand', 'pa_serie', 'pa_genero', 'pa_autor' ) )
			|| is_product()
			|| ( is_search() && 'product' === get_query_var( 'post_type' ) )
			|| is_cart()
			|| is_account_page()
		) )
		|| $is_catalog_archive
		|| is_404()
		|| akibara_gp_is_home_surface();
}
