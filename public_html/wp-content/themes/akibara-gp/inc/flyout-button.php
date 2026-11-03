<?php
/**
 * Flyout button — trigger del drawer mobile de filtros del buscador propio.
 *
 * Renderea un `<button data-akb-drawer-open>` antes del shop loop; akb-search.js
 * abre el drawer off-canvas con las facetas. Solo visible en mobile (CSS hide
 * >= 768px) porque en desktop el `#left-sidebar` está visible de forma nativa.
 *
 * Toolbar wrapper: en mobile el botón "Filtrar" comparte fila con el
 * `<form class="woocommerce-ordering">` para una pill-bar Filtrar+Ordenar
 * estándar de e-commerce. Para lograrlo abrimos `<div class="akb-shop-toolbar">`
 * antes de cualquier hook (priority 4) y lo cerramos después del ordering
 * (priority 35). En desktop el wrapper usa `display: contents` y no afecta
 * el layout original. Result-count, filter button y ordering quedan dentro.
 *
 * Counter de filtros activos: contamos URL params que empiezan con `_`
 * (gramática `_<facet>=value` del buscador propio). Lo actualiza `main.js` al
 * cargar y al evento `akb:pjax`.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * ¿Esta superficie renderea el botón "Filtrar"?
 *
 * Excluye:
 *   - single product (no hay sidebar de filtros).
 *   - pa_serie / pa_autor (akibara_gp_archive_sidebar_layout fuerza no-sidebar).
 *   - cuando el buscador propio no está activo (degradación graceful).
 *
 * Se extrajo del render porque el wrapper del toolbar —que abre TRES hooks
 * antes, en prioridad 4— necesita la misma respuesta para marcarse: en las
 * landings de serie no hay botón, y el CSS mobile seguía repartiendo la fila
 * en dos mitades de 50%, dejando el select de orden encogido contra media fila
 * muerta. Una sola condición con dos consumidores: si se duplicara y divergiera,
 * el CSS reservaría espacio para un botón que el PHP ya decidió no imprimir.
 */
function akibara_gp_shop_toolbar_has_filters(): bool {
	if ( is_singular( 'product' ) ) {
		return false;
	}
	if ( is_tax( array( 'pa_serie', 'pa_autor' ) ) ) {
		return false;
	}

	// El botón abre NUESTRO drawer (data-akb-drawer-open, lo maneja
	// akb-search.js). Sin el filtrado propio activo no hay facetas que mostrar.
	return function_exists( 'akibara_search_facets_active' ) && akibara_search_facets_active();
}

/**
 * Renderea el botón "Filtrar" antes del shop loop.
 */
function akibara_gp_render_flyout_trigger(): void {
	if (
		( ! function_exists( 'is_woocommerce' ) || ! is_woocommerce() )
		&& ( ! function_exists( 'akibara_gp_is_catalog_archive' ) || ! akibara_gp_is_catalog_archive() )
	) {
		return;
	}
	if ( ! akibara_gp_shop_toolbar_has_filters() ) {
		return;
	}
	?>
	<button
		type="button"
		class="akb-filter-toggle" data-akb-drawer-open aria-expanded="false" aria-controls="akb-facets-drawer"
	>
		<svg class="akb-filter-toggle__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
			<line x1="4" y1="6" x2="20" y2="6"/>
			<line x1="4" y1="12" x2="14" y2="12"/>
			<line x1="4" y1="18" x2="9" y2="18"/>
		</svg>
		<span class="akb-filter-toggle__label">Filtrar</span>
		<span class="akb-filter-toggle__count" hidden></span>
	</button>
	<?php
}
add_action( 'woocommerce_before_shop_loop', 'akibara_gp_render_flyout_trigger', 7 );
add_action( 'woocommerce_no_products_found', 'akibara_gp_render_flyout_trigger', 13 );

/**
 * Enlace de salto a la barra de filtros, para teclado (QA-ESC-TECLADO-4).
 *
 * En escritorio la barra se VE a la izquierda y antes de la rejilla, pero en el
 * documento va DESPUÉS de `<main>` (GeneratePress pinta `#left-sidebar` tras
 * `#primary`, y el CSS la coloca con la rejilla de `#main-content`): quien
 * tabula llegaba a «Disponibilidad» en la parada 117, tras 94 de productos y 3
 * de paginación (medido a 1440x900 en /tienda/). Mover la barra en el DOM
 * cambiaría el orden de lectura de todo el listado; el enlace deja el listado
 * como está y ofrece el atajo justo después del total, antes del orden.
 *
 * Invisible hasta que recibe el foco (woocommerce.css), y sólo donde la barra
 * se ve (≥ 769 px): en el teléfono los filtros están en el cajón y el botón
 * «Filtrar» ya va en ese mismo sitio. La misma condición que el botón decide si
 * hay barra. main.js lo trata como el «Saltar al contenido»: enfoca el
 * formulario de filtros, así el siguiente Tab cae en el primer filtro también
 * en los navegadores que no mueven el punto de partida del Tab al ancla.
 *
 * Y sólo si la barra va a tener algún filtro (QF-gp-R3): en una búsqueda sin
 * resultados y sin filtros marcados el buscador imprime el formulario igual,
 * para conservar `_buscar`, pero vacío —0 px y ningún control—. Saltar ahí
 * dejaba el foco en nada y el siguiente Tab en el logo del pie, por encima de
 * «Más vendidos».
 */
function akibara_gp_render_skip_to_filters(): void {
	if (
		( ! function_exists( 'is_woocommerce' ) || ! is_woocommerce() )
		&& ( ! function_exists( 'akibara_gp_is_catalog_archive' ) || ! akibara_gp_is_catalog_archive() )
	) {
		return;
	}
	if ( ! akibara_gp_shop_toolbar_has_filters() || ! akibara_gp_facets_sidebar_has_filters() ) {
		return;
	}
	echo '<a class="akb-skip-filtros" href="#akb-facets-drawer">Saltar a los filtros</a>';
}
add_action( 'woocommerce_before_shop_loop', 'akibara_gp_render_skip_to_filters', 6 );
add_action( 'woocommerce_no_products_found', 'akibara_gp_render_skip_to_filters', 12 );

/**
 * ¿La barra de filtros de esta página lleva algún filtro?
 *
 * La misma señal con que el buscador pinta los grupos de la barra
 * (`akibara_gp_akb_render_sidebar()`): las `facets` del view-model. Sin
 * view-model (buscador caído) no hay formulario; sin facetas, el formulario
 * sale vacío. El buscador ya lo pidió para el título de la página y memoriza
 * las cuentas por petición: preguntar aquí no abre otra consulta.
 */
function akibara_gp_facets_sidebar_has_filters(): bool {
	if ( ! function_exists( 'akibara_search_facets_view' ) ) {
		return false;
	}
	$view = akibara_search_facets_view();
	return is_array( $view ) && ! empty( $view['facets'] );
}

/**
 * Abre/cierra el wrapper `.akb-shop-toolbar` alrededor de:
 *   - .akb-search-correction + .akb-result-count (priority 5)
 *   - .akb-filter-toggle (priority 7)
 *   - .woocommerce-result-count (priority 20)
 *   - .woocommerce-ordering (priority 30)
 *
 * Mismas exclusiones que el botón flyout: solo si estamos en archive WC.
 */
function akibara_gp_open_shop_toolbar(): void {
	if (
		( ! function_exists( 'is_woocommerce' ) || ! is_woocommerce() )
		&& ( ! function_exists( 'akibara_gp_is_catalog_archive' ) || ! akibara_gp_is_catalog_archive() )
	) {
		return;
	}
	if ( is_singular( 'product' ) ) {
		return;
	}
	$classes = 'akb-shop-toolbar';
	if ( 'woocommerce_no_products_found' === current_filter() ) {
		$classes .= ' akb-shop-toolbar--empty';
	}
	// Marca las superficies sin "Filtrar" (series/autor) para que el CSS mobile
	// le dé la fila entera al control de orden en vez de reservarle la mitad a
	// un botón que no existe. La condición es la misma que decide el render.
	if ( ! akibara_gp_shop_toolbar_has_filters() ) {
		$classes .= ' akb-shop-toolbar--no-filters';
	}
	echo '<div class="' . esc_attr( $classes ) . '">';
}
add_action( 'woocommerce_before_shop_loop', 'akibara_gp_open_shop_toolbar', 4 );
add_action( 'woocommerce_no_products_found', 'akibara_gp_open_shop_toolbar', 11 );

function akibara_gp_close_shop_toolbar(): void {
	if (
		( ! function_exists( 'is_woocommerce' ) || ! is_woocommerce() )
		&& ( ! function_exists( 'akibara_gp_is_catalog_archive' ) || ! akibara_gp_is_catalog_archive() )
	) {
		return;
	}
	if ( is_singular( 'product' ) ) {
		return;
	}
	echo '</div>';
}
add_action( 'woocommerce_before_shop_loop', 'akibara_gp_close_shop_toolbar', 35 );
add_action( 'woocommerce_no_products_found', 'akibara_gp_close_shop_toolbar', 14 );
