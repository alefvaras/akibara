<?php
/**
 * Akibara GP — Dequeue de assets de WooCommerce que la página actual no usa.
 *
 * Módulo desacoplado para permitir pruebas unitarias sobre el comportamiento
 * de desregistro condicional de scripts y estilos. Ver tests/AssetDequeueTest.php.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Dequeue unused plugin assets per page (performance).
 * Prevents payment gateway, shipping, analytics scripts from loading on non-shop pages.
 */
function akibara_gp_dequeue_unused_assets(): void {
	// Guarda de seguridad: previene fatals globales si WooCommerce está inactivo.
	if ( ! akibara_gp_wc_activo() ) {
		return;
	}

	// El carrito es de BLOQUES: el guion del carrito CLÁSICO (`wc-cart`,
	// frontend/cart.js) no tiene formulario que manejar, pero sigue escuchando
	// `added_to_cart` y RECARGA /carrito/ entera (cart.js:100 y 114). Agregar
	// desde «Más vendidos» del carrito vacío costaba una recarga de ~2,8 MB (en
	// móvil lento, unos 15 s hasta ver el carrito lleno); sin él, el bloque se
	// repinta solo con el evento del alta (medido en el espejo: la línea aparece
	// en ~0,4 s, sin navegar). QAM-3. Sólo si la página del carrito lleva el
	// bloque: un carrito clásico lo necesita.
	if ( is_cart() && akibara_gp_carrito_de_bloques() ) {
		wp_dequeue_script( 'wc-cart' );
	}

	if ( is_checkout() || is_cart() || is_account_page() ) {
		return; // Need all assets on checkout/cart/account
	}

	// WooCommerce blocks/scripts not needed on non-shop pages
	if ( ! is_shop() && ! is_product() && ! is_product_category() && ! is_product_tag() ) {
		// EXCEPCIÓN: toda superficie con rejilla de producto embebe botón add-to-cart
		// AJAX. Sin `wc-add-to-cart` el click navega (recarga) en vez de hacer AJAX,
		// y el drawer de NativeCart —que abre escuchando el evento `added_to_cart`
		// de WC— nunca se despliega.
		//
		// La lista NO se escribe acá: es `akibara_gp_surface_has_product_loop()`
		// (inc/page-targets.php), la misma que decide loop.css y el guard del
		// add-to-cart. La copia literal que vivía aquí se había quedado sin
		// `pa_genero` ni `pa_autor` y sin la búsqueda de productos, así que esos
		// archivos servían el botón muerto. Los ESTILOS WC sí se quitan igual
		// (el theme usa su loop.css).
		if ( ! akibara_gp_surface_has_product_loop() ) {
			wp_dequeue_script( 'wc-add-to-cart' );
			wp_dequeue_script( 'woocommerce' );
		}
		wp_dequeue_style( 'woocommerce-layout' );
		wp_dequeue_style( 'woocommerce-smallscreen' );
		wp_dequeue_style( 'woocommerce-general' );
	}

	/*
	 * `wc-cart-fragments` no se retira: sincroniza dinámicamente el contador
	 * del carrito del header en páginas servidas desde la caché de página.
	 * Se encola explícitamente en `akibara_gp_enqueue_cart_badge_sync()`.
	 */

	// Shipping calculators only on cart/checkout
	if ( ! is_cart() && ! is_checkout() ) {
		wp_dequeue_script( 'wc-country-select' );
		wp_dequeue_script( 'wc-address-i18n' );
	}
}
// Prioridad 99: el dequeue debe correr DESPUÉS de que WooCommerce y los gateways
// encolen sus assets (prio 10). A prio 5 corría antes y el dequeue era no-op.
add_action( 'wp_enqueue_scripts', 'akibara_gp_dequeue_unused_assets', 99 );

/**
 * ¿La página del carrito usa el bloque de carrito de WooCommerce?
 *
 * Se mira la página configurada como carrito, no la consulta actual: es la que
 * `is_cart()` reconoce.
 */
function akibara_gp_carrito_de_bloques(): bool {
	if ( ! function_exists( 'has_block' ) || ! function_exists( 'wc_get_page_id' ) ) {
		return false;
	}
	$pagina = (int) wc_get_page_id( 'cart' );

	return $pagina > 0 && has_block( 'woocommerce/cart', $pagina );
}

/**
 * Encola `wc-cart-fragments` en toda página cacheable que pinta el contador del
 * carrito del header.
 *
 * El contador (`data-akb-cart-count`) se renderiza en el HTML público cacheado
 * por LiteSpeed. `wc-cart-fragments` realiza la sincronización en el cliente
 * mediante eventos `wc_fragments_loaded`/`wc_fragments_refreshed` propagados
 * por `assets/js/wc-event-bridge.js` hacia `main.js`.
 * Encolarlo directamente en el tema garantiza funcionamiento autónomo sin depender
 * de plugins de terceros.
 *
 * Costo: el script sólo hace su petición (`get_refreshed_fragments`) cuando no
 * tiene en sessionStorage una copia para el hash de carrito actual: una vez por
 * sesión y tras cada cambio del carrito, no una por página.
 *
 * Carrito, checkout y Mi cuenta no se cachean y pintan el contador correcto
 * desde el servidor: ahí no hace falta.
 */
function akibara_gp_enqueue_cart_badge_sync(): void {
	if ( ! akibara_gp_wc_activo() || is_cart() || is_checkout() || is_account_page() ) {
		return;
	}

	wp_enqueue_script( 'wc-cart-fragments' );
}
// Prioridad 20: después de que WooCommerce REGISTRE sus scripts (prio 10) y
// antes del dequeue de arriba (prio 99), que ya no toca este handle.
add_action( 'wp_enqueue_scripts', 'akibara_gp_enqueue_cart_badge_sync', 20 );
