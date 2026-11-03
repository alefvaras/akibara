'use strict';

const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');

const themeRoot = join(__dirname, '..');
const read = (relative) => readFileSync(join(themeRoot, relative), 'utf8');

const seriesOrderPhp = read('inc/series-order.php');
const orderbyTemplate = read('woocommerce/loop/orderby.php');
const flyoutPhp = read('inc/flyout-button.php');
const functionsPhp = read('functions.php');
const css = read('assets/css/woocommerce.css');

test('el orden por defecto se mueve a title SOLO en pa_serie', () => {
	// El gate es una condicional de ruta, no la URL: el archive se sirve también
	// paginado (/serie/one-piece/page/5/) y bajo el PJAX del buscador propio.
	assert.match(
		seriesOrderPhp,
		/function akibara_gp_is_series_archive\(\): bool \{\s*return function_exists\( 'is_tax' \) && is_tax\( 'pa_serie' \);\s*\}/
	);

	// El callback devuelve 'title' ÚNICAMENTE detrás de ese gate. Sin esto la
	// rejilla de /tienda/ perdería el interleaver de round-robin, que está
	// bien pensado ahí y cuyo docblock lo explica.
	assert.match(
		seriesOrderPhp,
		// Sin búsqueda: con `?_buscar=` manda la relevancia (revisión B1-fix).
		/function akibara_gp_series_default_orderby\( \$orderby \) \{\s*return akibara_gp_is_series_archive\(\) && ! akibara_gp_series_search_active\(\) \? 'title' : \$orderby;\s*\}/
	);
	assert.match(
		seriesOrderPhp,
		/add_filter\( 'woocommerce_default_catalog_orderby', 'akibara_gp_series_default_orderby' \)/
	);

	// Ninguna otra superficie entra al módulo: si alguien añade is_shop(),
	// is_product_category() o cualquier otro archive, este contrato se rompe.
	for (const forbidden of [
		'is_shop',
		'is_product_category',
		'is_product_tag',
		'is_post_type_archive',
	]) {
		assert.ok(
			!seriesOrderPhp.includes(forbidden + '('),
			`${forbidden}() ampliaría el cambio de orden fuera de las landings de serie`
		);
	}

	assert.match(functionsPhp, /require_once \$akb_inc \. 'series-order\.php';/);
});

test('"Novedades primero" (menu_order) sobrevive en el select', () => {
	// wc-template-functions.php:1680/:1726 — WooCommerce calcula
	// $show_default_orderby ANTES de disparar woocommerce_catalog_orderby y
	// después hace unset($catalog_orderby_options['menu_order']) si el defecto
	// no es menu_order. La reposición NO puede vivir en ese filtro.
	assert.match(
		seriesOrderPhp,
		/function akibara_gp_series_orderby_restore_menu_order\( array \$options \): array/
	);
	// Idempotente y acotada: si la opción sigue ahí, o no estamos en serie,
	// devuelve el array intacto.
	assert.match(
		seriesOrderPhp,
		/if \( isset\( \$options\['menu_order'\] \) \|\| ! akibara_gp_is_series_archive\(\) \) \{\s*return \$options;/
	);
	// Vuelve a la cabecera del array, que es el orden que arma akibara-business.
	assert.match(
		seriesOrderPhp,
		/return array\( 'menu_order' => \$labels\['menu_order'\] \) \+ \$options;/
	);

	// El único punto del render posterior al unset es la plantilla.
	assert.match(
		orderbyTemplate,
		/akibara_gp_series_orderby_restore_menu_order\( \$catalog_orderby_options \)/
	);
	// Segunda mitad de la misma trampa: WooCommerce descarta el ?orderby del
	// usuario cuando esa opción ya no está en la lista (:1735). Reponer la
	// opción SIN re-marcarla dejaba el selector diciendo "Más recientes"
	// mientras la rejilla salía en menu_order.
	assert.match(
		orderbyTemplate,
		/\$orderby\s+= akibara_gp_series_orderby_selected\( \$orderby, \$catalog_orderby_options \);/
	);
	assert.match(
		seriesOrderPhp,
		/function akibara_gp_series_orderby_selected\( \$orderby, array \$options \)/
	);
	// Sólo puede devolver una clave que exista: un ?orderby inventado sigue
	// cayendo al fallback de WooCommerce.
	assert.match(
		seriesOrderPhp,
		/isset\( \$options\[ \$requested \] \)/
	);
	// Guardado con function_exists: la plantilla no puede fatalear si el módulo
	// se retira del theme.
	assert.match(
		orderbyTemplate,
		/function_exists\( 'akibara_gp_series_orderby_restore_menu_order' \)/
	);
	// Copia de WooCommerce 9.7.0: la marca de versión debe seguir declarada para
	// que WC → Estado detecte el drift en la próxima actualización.
	assert.match(orderbyTemplate, /@version\s+9\.7\.0/);
	// El resto de la plantilla es la de WooCommerce, sin desviaciones extra.
	assert.match(orderbyTemplate, /<option value="<\?php echo esc_attr\( \$id \); \?>" <\?php selected\( \$orderby, \$id \); \?>>/);
	assert.match(orderbyTemplate, /wc_query_string_form_fields\( null, array\( 'orderby', 'submit', 'paged', 'product-page' \) \)/);
});

test('las etiquetas nombran el resultado, no el mecanismo', () => {
	// "Título: A-Z" no dice nada en una página donde los 17 títulos empiezan
	// igual; "Destacados" tampoco explica qué destaca.
	assert.match(seriesOrderPhp, /'menu_order' => 'Novedades primero'/);
	assert.match(seriesOrderPhp, /'title'\s+=> 'Número de tomo'/);

	// Prioridad > 10: akibara-business reconstruye el array completo en 10 (ahí
	// nace 'title'). Correr antes dejaría estas etiquetas pisadas sin rastro.
	const priority = seriesOrderPhp.match(
		/add_filter\( 'woocommerce_catalog_orderby', 'akibara_gp_series_orderby_options', (\d+) \)/
	);
	assert.ok(priority, 'el rename debe registrarse en woocommerce_catalog_orderby');
	assert.ok(
		Number(priority[1]) > 10,
		'debe correr DESPUÉS de akibara-business, que reconstruye el array en prioridad 10'
	);
});

test('el toolbar declara cuándo no hay "Filtrar" en vez de que el CSS lo adivine', () => {
	// Una sola condición con dos consumidores (render del botón y clase del
	// wrapper). Duplicarla dejaría al CSS reservando media fila para un botón
	// que el PHP ya decidió no imprimir.
	assert.match(
		flyoutPhp,
		/function akibara_gp_shop_toolbar_has_filters\(\): bool \{/
	);
	assert.match(
		flyoutPhp,
		/if \( ! akibara_gp_shop_toolbar_has_filters\(\) \) \{\s*return;\s*\}/
	);
	assert.match(
		flyoutPhp,
		/if \( ! akibara_gp_shop_toolbar_has_filters\(\) \) \{\s*\$classes \.= ' akb-shop-toolbar--no-filters';/
	);
	// El gate original sigue intacto dentro del predicado.
	assert.match(flyoutPhp, /is_tax\( array\( 'pa_serie', 'pa_autor' \) \)/);
	assert.match(flyoutPhp, /akibara_search_facets_active\(\)/);
	// El modificador --empty no se perdió en la refactorización.
	assert.match(flyoutPhp, /\$classes \.= ' akb-shop-toolbar--empty';/);
});

test('en móvil la etiqueta "Ordenar por:" existe donde vive el 72,5% del tráfico', () => {
	const mobile = css.slice(
		css.indexOf('@media (max-width: 768px)', css.indexOf('Shop toolbar wrapper'))
	);

	// Fila completa: sin "Filtrar" no hay con quién repartirla.
	assert.match(
		mobile,
		/body\.archive\.woocommerce-page \.akb-shop-toolbar--no-filters \.woocommerce-ordering \{\s*flex: 1 1 100%;\s*display: flex;/
	);
	// La etiqueta visible, en el mismo renglón de 44px (cero alto añadido: la
	// primera portada ya arranca en 771px sobre un viewport útil de ~700).
	assert.match(
		mobile,
		/\.akb-shop-toolbar--no-filters \.woocommerce-ordering::before \{\s*content: "Ordenar por:";/
	);
	// El select cede el ancho; sin min-width:0 su min-content gana al shrink y
	// expulsa la etiqueta de la fila.
	assert.match(
		mobile,
		/\.akb-shop-toolbar--no-filters \.woocommerce-ordering select \{\s*flex: 1 1 auto;\s*min-width: 0;\s*\}/
	);

	// El reparto 50/50 de /tienda/ (Filtrar + Ordenar) queda intacto.
	assert.match(
		mobile,
		/body\.archive\.woocommerce-page \.akb-shop-toolbar \.woocommerce-ordering \{\s*flex: 0 0 calc\(\(100% - var\(--space-2\)\) \/ 2\);/
	);
});
