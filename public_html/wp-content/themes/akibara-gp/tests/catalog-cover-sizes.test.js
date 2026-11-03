const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const themeRoot = path.resolve(__dirname, '..');
const read = (relative) => fs.readFileSync(path.join(themeRoot, relative), 'utf8');

// Mismo cuidado que el resto de la suite: las aserciones son regex sobre el
// fuente y un comentario no puede hacerlas pasar ni fallar.
function sinComentarios(fuente) {
	return String(fuente)
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

// El `sizes` de las portadas del catálogo (`akibara_gp_catalog_image_sizes()`)
// es aritmética sobre la rejilla. Con peldaños de 450/560/~700 px, 1-2 px de más
// cambian el archivo que baja el cliente (medido 2026-09-14). Si alguien toca un
// hueco, la barra de filtros, un margen, el borde de la tarjeta o un corte, esta
// prueba recalcula la fórmula desde el CSS y exige que el PHP la acompañe.
const pxDeEspaciado = (() => {
	const json = JSON.parse(read('theme.json'));
	const tamanos = json.settings.spacing.spacingSizes;
	const px = {};
	for (const { slug, size } of tamanos) {
		const rem = /^([\d.]+)rem$/.exec(size);
		if (rem) { px[slug] = Number(rem[1]) * 16; }
	}
	return px;
})();

function rejilla() {
	const loop = sinComentarios(read('assets/css/loop.css'));
	const woo = sinComentarios(read('assets/css/woocommerce.css'));
	const json = JSON.parse(read('theme.json'));

	const base = /\.woocommerce ul\.products \{[^}]*grid-template-columns:\s*repeat\(auto-fill[^}]*gap:\s*var\(--space-(\d+)\);[^}]*max-width:\s*(\d+)px;/.exec(loop);
	assert.ok(base, 'loop.css: la rejilla base con su hueco y su ancho máximo');
	assert.match(loop, /@media \(min-width: 1025px\) \{[\s\S]*?:where\(\.columns-4\) \{\s*grid-template-columns:\s*repeat\(4, minmax\(0, 1fr\)\);/, 'loop.css: 4 columnas desde 1025 px');
	assert.match(loop, /@media \(max-width: 1024px\) \{\s*\.woocommerce ul\.products \{\s*grid-template-columns:\s*repeat\(3, 1fr\);\s*\}/, 'loop.css: 3 columnas hasta 1024 px');
	const movil = /@media \(max-width: 768px\) \{\s*\.woocommerce ul\.products \{\s*grid-template-columns:\s*repeat\(2, 1fr\);\s*gap:\s*var\(--space-(\d+)\);/.exec(loop);
	assert.ok(movil, 'loop.css: 2 columnas y su hueco hasta 768 px');
	const estrecho = /@media \(max-width: 380px\) \{\s*\.woocommerce ul\.products \{\s*gap:\s*var\(--space-(\d+)\);/.exec(loop);
	assert.ok(estrecho, 'loop.css: hueco menor hasta 380 px');
	const borde = /\.woocommerce ul\.products li\.product \{[^}]*padding:\s*var\(--space-(\d+)\);[^}]*border:\s*(\d+)px solid/.exec(loop);
	assert.ok(borde, 'loop.css: relleno y borde de la tarjeta');
	// La portada sangra sobre el relleno de la tarjeta: sólo el borde le quita ancho.
	assert.match(loop, new RegExp(`\\.akb-product-cover-wrap \\{[^}]*margin:\\s*calc\\(-1 \\* var\\(--space-${borde[1]}\\)\\) calc\\(-1 \\* var\\(--space-${borde[1]}\\)\\)`), 'loop.css: la portada anula el relleno lateral de la tarjeta');
	// Cada --space-N apunta al preset N de theme.json, que es de donde salen los px.
	const tokens = sinComentarios(read('assets/css/tokens.css'));
	for (const n of ['2', '3', '4', '6']) {
		assert.match(tokens, new RegExp(`--space-${n}:\\s*var\\(--wp--preset--spacing--${n}\\);`), `tokens.css: --space-${n}`);
	}
	// En móvil la barra se oculta y el contenedor queda sin margen: los dos contextos miden igual.
	assert.match(woo, /@media \(max-width: 768px\) \{\s*body\.left-sidebar\.woocommerce-page #main-content \{\s*display:\s*block;\s*padding-left:\s*0;\s*padding-right:\s*0;/, 'woocommerce.css: contenedor con barra sin margen en móvil');

	const barra = /body\.left-sidebar\.woocommerce-page #main-content \{[^}]*grid-template-columns:\s*(\d+)px minmax\(0, 1fr\);[^}]*gap:\s*var\(--space-(\d+)\);[^}]*max-width:\s*var\(--container-max, (\d+)px\);[^}]*padding-left:\s*var\(--space-(\d+)\);[^}]*padding-right:\s*var\(--space-(\d+)\);/.exec(woo);
	assert.ok(barra, 'woocommerce.css: la columna de filtros, su hueco, el contenedor y sus márgenes');
	assert.equal(barra[4], barra[5], 'márgenes laterales simétricos');
	assert.equal(json.settings.custom.layout['container-max'], `${barra[3]}px`, 'theme.json y woocommerce.css acuerdan el contenedor');
	assert.equal(base[2], barra[3], 'la rejilla y el contenedor con barra comparten ancho máximo');

	// Sin barra (serie, autor): desde 769 px la columna deja un canalón a cada
	// lado y llega al contenedor cuando la ventana lo iguala más el canalón (X1).
	const canal = /@media \(min-width: 769px\) \{\s*body\.archive\.tax-pa_serie \.entry-content > :is\(([^)]*)\),\s*body\.archive\.tax-pa_autor \.entry-content > :is\(\1\) \{[^}]*margin-inline:\s*max\((\d+)px, \(100% - var\(--container-max\)\) \/ 2\);/.exec(woo);
	assert.ok(canal, 'woocommerce.css: el canalón de las landings sin barra (serie y autor, desde 769 px)');
	assert.ok(canal[1].split(',').map((x) => x.trim()).includes('ul.products'), 'el canalón alcanza a la rejilla');
	assert.match(canal[0], /max-width:\s*none;/, 'el canalón manda sobre el max-width de la rejilla');

	return {
		hueco: pxDeEspaciado[base[1]],
		huecoMovil: pxDeEspaciado[movil[1]],
		huecoEstrecho: pxDeEspaciado[estrecho[1]],
		borde: Number(borde[2]),
		barra: Number(barra[1]) + pxDeEspaciado[barra[2]] + 2 * pxDeEspaciado[barra[4]],
		canal: 2 * Number(canal[2]),
		maximo: Number(base[2]),
	};
}

test('el sizes de las portadas se deriva de la rejilla real (con y sin barra de filtros)', () => {
	const r = rejilla();
	const b2 = 2 * r.borde;
	const movil = `(max-width: 380px) calc(50vw - ${r.huecoEstrecho / 2 + b2}px), (max-width: 768px) calc(50vw - ${r.huecoMovil / 2 + b2}px), `;
	const conBarra = `(max-width: 1024px) calc((100vw - ${r.barra + 2 * r.hueco}px) / 3 - ${b2}px), (max-width: ${r.maximo}px) calc((100vw - ${r.barra + 3 * r.hueco}px) / 4 - ${b2}px), ${(r.maximo - r.barra - 3 * r.hueco) / 4 - b2}px`;
	const sinBarra = `(max-width: 1024px) calc((100vw - ${r.canal + 2 * r.hueco}px) / 3 - ${b2}px), (max-width: ${r.maximo + r.canal}px) calc((100vw - ${r.canal + 3 * r.hueco}px) / 4 - ${b2}px), ${(r.maximo - 3 * r.hueco) / 4 - b2}px`;

	const php = sinComentarios(read('inc/performance.php'));
	assert.ok(php.includes(`'${movil}'`), `performance.php declara el tramo móvil: ${movil}`);
	assert.ok(php.includes(`'${conBarra}'`), `performance.php declara el tramo con barra: ${conBarra}`);
	assert.ok(php.includes(`'${sinBarra}'`), `performance.php declara el tramo sin barra: ${sinBarra}`);
	// Quién lleva barra lo decide la dueña única del layout, no una lista repetida.
	assert.match(php, /'left-sidebar' === akibara_gp_archive_sidebar_layout\( '' \)/);
});

test('en móvil todos los archivos de producto usan el ancho completo que supone el sizes', () => {
	const css = sinComentarios(read('assets/css/base.css'));
	const bloques = css.split('@media (max-width: 768px)').slice(1).join('\n');
	// /pais/ faltaba y sus portadas medían 12 px menos (173 contra 185 a 390 px).
	for (const clase of ['post-type-archive-product', 'tax-product_cat', 'tax-product_brand', 'tax-pa_serie', 'tax-pa_genero', 'tax-pa_pais', 'tax-pa_autor', 'tax-product_tag']) {
		assert.match(bloques, new RegExp(`body\\.${clase} \\.inside-article`), `${clase}: sin margen del artículo`);
		assert.match(bloques, new RegExp(`body\\.${clase} #main-content`), `${clase}: sin margen del contenedor`);
	}
});

test('la caja 2:3 de la portada se hace con padding y la portada la llena', () => {
	const loop = sinComentarios(read('assets/css/loop.css'));
	const caja = /\.woocommerce ul\.products li\.product \.akb-product-cover-wrap \{([^}]*)\}/.exec(loop);
	assert.ok(caja, 'loop.css: la jaula de la portada');
	// WebKit (Safari, y por tanto todo iPhone) no cuenta la altura de una caja con
	// `aspect-ratio` al medir la tarjeta: la dejaba 36 px más baja y encogía la
	// portada hasta 0,776, que con `object-fit: cover` recorta arriba y abajo
	// (medido el 2026-09-15 a 393 px en el espejo y en producción: 220,2 contra
	// 256,2 de Chromium). El padding es layout normal y no se mide distinto.
	assert.doesNotMatch(caja[1], /aspect-ratio/, 'la jaula NO usa aspect-ratio');
	assert.match(caja[1], /flex-shrink:\s*0/, 'la jaula no se encoge dentro de la tarjeta');
	assert.match(loop, /\.woocommerce ul\.products li\.product \.akb-product-cover-wrap::before \{[^}]*padding-top:\s*150%;/, 'loop.css: el 2:3 sale del padding del ::before');
	const img = /\.woocommerce ul\.products li\.product \.akb-product-cover-wrap > img \{([^}]*)\}/.exec(loop);
	assert.ok(img, 'loop.css: la imagen dentro de la jaula');
	assert.match(img[1], /position:\s*absolute/, 'la imagen se posiciona sobre la caja del padding');
	assert.match(img[1], /inset:\s*0/, 'la imagen ocupa la caja entera');
	// El marcador de un tomo sin portada trae de WooCommerce un borde de 1 px
	// (`.woocommerce ul.products li.product .woocommerce-placeholder`, 0,4,2): con
	// el 100 % de ancho se pintaba 2 px más grande que la caja y el `sizes` quedaba
	// corto (medido 2026-09-25: 143 px en la celda de 141 del carrito vacío). La
	// regla de la jaula (0,4,3) lo quita.
	assert.match(img[1], /border:\s*0\s*;/, 'la imagen de la jaula no lleva borde: el marcador llena la caja como una portada');
	// La portada LLENA la caja (decisión del dueño 2026-07-06, confirmada el
	// 2026-09-15): con `contain` las portadas nuevas —proporción A5, no 2:3— dejaban
	// una franja oscura arriba y abajo, y el dueño prefirió el recorte de los bordes.
	assert.match(img[1], /object-fit:\s*cover/, 'la portada llena la caja, sin franjas');
	assert.doesNotMatch(loop, /akb-product-cover-wrap[^{]*\{[^}]*object-fit:\s*contain/, 'ninguna regla de la jaula vuelve a contain');
	const generica = /\.woocommerce ul\.products li\.product img \{([^}]*)\}/.exec(loop);
	assert.ok(generica, 'loop.css: la regla genérica de la imagen de la tarjeta');
	assert.match(generica[1], /object-fit:\s*cover/, 'la regla genérica también llena la caja');
	const woo = sinComentarios(read('assets/css/woocommerce.css'));
	const pedido = /\.akb-orders-card__thumb-img \{([^}]*)\}/.exec(woo);
	assert.ok(pedido, 'woocommerce.css: la miniatura de la tarjeta de pedido');
	assert.match(pedido[1], /object-fit:\s*cover/, 'la miniatura del pedido llena su caja');
	// Ninguna regla de la tarjeta (en cualquier hoja, en cualquier @media) deja la portada en contain.
	for (const [hoja, fuente] of [['loop.css', loop], ['woocommerce.css', woo]]) {
		for (const regla of fuente.matchAll(/([^{}]*)\{([^{}]*)\}/g)) {
			if (/li\.product[^,{]*img|akb-product-cover-wrap|akb-orders-card__thumb-img/.test(regla[1])) {
				assert.doesNotMatch(regla[2], /object-fit:\s*(contain|scale-down)/, `${hoja}: «${regla[1].trim()}» no vuelve a contain`);
			}
		}
	}
	// La jaula sin prefijo tampoco puede volver a aspect-ratio (el mismo fallo de WebKit).
	const jaulaBase = /(^|\})\s*\.akb-product-cover-wrap \{([^}]*)\}/.exec(loop);
	assert.ok(jaulaBase, 'loop.css: la regla base de la jaula');
	assert.doesNotMatch(jaulaBase[2], /aspect-ratio/, 'la jaula base NO usa aspect-ratio');
	// El zoom de woocommerce.css (0,5,3) gana al reduced-motion de loop.css: necesita el suyo.
	const zoom = /\.woocommerce ul\.products li\.product:hover \.woocommerce-loop-product__link img \{\s*transform:\s*scale\(1\.02\);/.exec(woo);
	const quieto = /@media \(prefers-reduced-motion: reduce\) \{\s*\.woocommerce ul\.products li\.product:hover \.woocommerce-loop-product__link img \{\s*transform:\s*none;/.exec(woo);
	assert.ok(zoom && quieto, 'woocommerce.css: el zoom y su anulación para quien pide menos movimiento');
	assert.ok(quieto.index > zoom.index, 'la anulación va DESPUÉS del zoom: con la misma especificidad gana la última');
});

// ─── Lote T1 (2026-09-22): las portadas que no son la rejilla del catálogo ───

test('la venta cruzada de los estados vacíos marca su loop y lo devuelve como estaba', () => {
	const plantilla = sinComentarios(read('template-parts/empty/cross-sell.php'));
	const guarda = plantilla.indexOf("$akb_loop_name = wc_get_loop_prop( 'name' );");
	const marca = plantilla.indexOf("wc_set_loop_prop( 'name', AKIBARA_GP_CROSS_SELL_LOOP );");
	const bucle = plantilla.indexOf('while ( $akb_cross_sell->have_posts() )');
	const fin = plantilla.indexOf('endwhile;');
	const devuelve = plantilla.indexOf("wc_set_loop_prop( 'name', $akb_loop_name );");
	assert.ok(guarda > -1 && marca > guarda && bucle > marca, 'se guarda el nombre y se marca el loop ANTES del bucle');
	assert.ok(devuelve > fin, 'el nombre anterior vuelve DESPUÉS del bucle');

	const php = sinComentarios(read('inc/performance.php'));
	assert.match(php, /const AKIBARA_GP_CROSS_SELL_LOOP = 'akb-cross-sell';/);
	// La regla lee el global: `wc_get_loop_prop()` montaría el loop en cada imagen del sitio.
	const lector = /function akibara_gp_is_cross_sell_loop\(\): bool \{([\s\S]*?)\n\}/.exec(php);
	assert.ok(lector, 'performance.php: el lector del loop');
	assert.doesNotMatch(lector[1], /wc_get_loop_prop|wc_setup_loop/);
	assert.match(lector[1], /\$GLOBALS\['woocommerce_loop'\]\['name'\]/);
});

test('el sizes de la venta cruzada del 404 es la rejilla del catálogo dentro del relleno de .akb-empty', () => {
	const r = rejilla();
	const vacio = sinComentarios(read('assets/css/empty-states.css'));
	const caja = /\.akb-empty \{[^}]*max-width:\s*var\(--container-max\);[^}]*padding:\s*var\(--space-\d+\) var\(--space-(\d+)\);/.exec(vacio);
	assert.ok(caja, 'empty-states.css: el contenedor del estado vacío con su relleno lateral');
	const lado = pxDeEspaciado[caja[1]];
	const b2 = 2 * r.borde;
	// `max-width` de contenido: la rejilla deja de crecer cuando el viewport llega al máximo MÁS el relleno.
	const esperado = `(max-width: 380px) calc(50vw - ${r.huecoEstrecho / 2 + b2 + lado}px), (max-width: 768px) calc(50vw - ${r.huecoMovil / 2 + b2 + lado}px), (max-width: 1024px) calc((100vw - ${2 * r.hueco + 2 * lado}px) / 3 - ${b2}px), (max-width: ${r.maximo + 2 * lado}px) calc((100vw - ${3 * r.hueco + 2 * lado}px) / 4 - ${b2}px), ${(r.maximo - 3 * r.hueco) / 4 - b2}px`;

	const php = sinComentarios(read('inc/performance.php'));
	assert.ok(php.includes(`'${esperado}'`), `performance.php declara la venta cruzada del 404: ${esperado}`);
	// El carrito tiene su propia fórmula (bloque del carrito + relleno de GeneratePress): la fija la prueba PHP con lo medido.
	assert.match(php, /function akibara_gp_cross_sell_image_sizes\(\): string \{\s*if \( function_exists\( 'is_cart' \) && is_cart\(\) \)/);
});

test('el sizes de las tarjetas del blog sale de la rejilla auto-fill de blog.css', () => {
	const css = sinComentarios(read('assets/css/blog.css'));
	const pista = /\.akb-blog__grid \{[^}]*grid-template-columns:\s*repeat\(auto-fill, minmax\(min\((\d+)px, 100%\), 1fr\)\);[^}]*gap:\s*var\(--space-(\d+)\);/.exec(css);
	assert.ok(pista, 'blog.css: la rejilla auto-fill con su pista mínima y su hueco');
	const envoltura = /\.site-main\.akb-blog \.akb-blog__hero,\s*\.site-main\.akb-blog \.akb-blog__wrap \{[^}]*box-sizing:\s*border-box;[^}]*max-width:\s*(\d+)px;[^}]*padding-left:\s*var\(--space-(\d+)[^)]*\);/.exec(css);
	assert.ok(envoltura, 'blog.css: la envoltura de 1.200 con su relleno');
	const movil = css.split('@media (max-width: 768px)').slice(1).join('\n');
	const huecoMovil = /\.akb-blog__grid \{\s*gap:\s*var\(--space-(\d+)\);/.exec(movil);
	const rellenoMovil = /\.site-main\.akb-blog \.akb-blog__wrap \{\s*padding-left:\s*var\(--space-(\d+)/.exec(movil);
	assert.ok(huecoMovil && rellenoMovil, 'blog.css: hueco y relleno de la rejilla en móvil');
	assert.match(css, /\.akb-blog-card \{[^}]*border:\s*1px solid/, 'blog.css: la tarjeta pierde 1 px de borde por lado');

	const min = Number(pista[1]);
	const hueco = pxDeEspaciado[pista[2]];
	const tope = Number(envoltura[1]);
	const relleno = pxDeEspaciado[envoltura[2]];
	const hM = pxDeEspaciado[huecoMovil[1]];
	const rM = pxDeEspaciado[rellenoMovil[1]];
	// auto-fill: caben N pistas cuando el ancho útil llega a N × mínimo + (N − 1) × hueco.
	// El corte va BARRA px más arriba: con barra de desplazamiento clásica (Windows,
	// 17 px) la rejilla es así de más estrecha que la media query, y con el corte
	// de la cuenta se declaraba una columna de más (portada blanda, revisión T1-D1).
	const BARRA = 17;
	const dosMovil = 2 * min + hM + 2 * rM + BARRA;
	const tres = 3 * min + 2 * hueco + 2 * relleno + BARRA;
	assert.ok(4 * min + 3 * hueco > tope - 2 * relleno, 'en 1.200 px nunca caben 4 pistas');
	const techo = (tope - 2 * relleno - 2 * hueco) / 3 - 2;
	const esperado = `(min-width: ${tope}px) ${techo}px, (min-width: ${tres}px) calc((100vw - ${2 * relleno + 2 * hueco}px) / 3 - 2px), (min-width: 769px) calc(50vw - ${(2 * relleno + hueco) / 2 + 2}px), (min-width: ${dosMovil}px) calc(50vw - ${(2 * rM + hM) / 2 + 2}px), calc(100vw - ${2 * rM + 2}px)`;

	const php = sinComentarios(read('inc/blog.php'));
	assert.ok(php.includes(`'${esperado}'`), `blog.php declara la tarjeta: ${esperado}`);
	for (const plantilla of ['home.php', 'archive.php']) {
		assert.match(sinComentarios(read(plantilla)), /'sizes'\s*=>\s*akibara_blog_card_image_sizes\(\),/, `${plantilla} usa el sizes de la tarjeta`);
	}
});

test('el sizes de la cabecera de una entrada sale del artículo de blog.css', () => {
	const css = sinComentarios(read('assets/css/blog.css'));
	const articulo = /\.akb-post__article \{[^}]*max-width:\s*(\d+)px;[^}]*padding:\s*var\(--space-\d+\) var\(--space-(\d+)\)/.exec(css);
	const movil = /@media \(max-width: 768px\) \{[\s\S]*?\.akb-post__article \{\s*padding:\s*var\(--space-\d+\) var\(--space-(\d+)\)/.exec(css);
	assert.ok(articulo && movil, 'blog.css: el artículo con su tope y su relleno (y el de móvil)');
	assert.match(css, /\.akb-post__media \{[^}]*border:\s*1px solid/, 'blog.css: la cabecera lleva 1 px de borde por lado');
	const tope = Number(articulo[1]);
	const r = pxDeEspaciado[articulo[2]];
	const rM = pxDeEspaciado[movil[1]];
	// `max-width` de contenido: el tope llega con el viewport en tope + relleno.
	const esperado = `(max-width: 768px) calc(100vw - ${2 * rM + 2}px), (max-width: ${tope + 2 * r - 1}px) calc(100vw - ${2 * r + 2}px), ${tope - 2}px`;

	assert.ok(sinComentarios(read('inc/blog.php')).includes(`'${esperado}'`), `blog.php declara la cabecera: ${esperado}`);
	assert.match(sinComentarios(read('single.php')), /'sizes'\s*=>\s*akibara_blog_post_image_sizes\(\),/);
});

test('el sizes de la portada de la ficha sigue a las reglas de woocommerce.css y el preload pasa por el mismo filtro', () => {
	const woo = sinComentarios(read('assets/css/woocommerce.css'));
	const movil = /@media \(max-width: 768px\) \{\s*\.woocommerce div\.product \.woocommerce-product-gallery,\s*\.woocommerce div\.product \.woocommerce-product-gallery__wrapper \{[^}]*max-height:\s*50vh;[^}]*\}\s*\.woocommerce div\.product \.woocommerce-product-gallery img,\s*\.woocommerce div\.product \.woocommerce-product-gallery__image img \{([^}]*)\}/.exec(woo);
	assert.ok(movil, 'woocommerce.css: en móvil la portada tiene techo de alto');
	assert.match(movil[1], /max-height:\s*50vh;/);
	assert.match(movil[1], /object-fit:\s*contain;/, 'con contain se DIBUJA a 50vh × proporción: eso es lo que declara el sizes');
	assert.match(woo, /@media \(min-width: 769px\) \{\s*\.woocommerce div\.product \.woocommerce-product-gallery \{\s*max-width:\s*500px;/, 'woocommerce.css: techo de 500 px en escritorio');

	const php = sinComentarios(read('inc/performance.php'));
	assert.match(php, /'\(max-width: 768px\) min\(calc\(100vw - 32px\), calc\(50vh \* ' \. \$proporcion \. '\)\), '/);
	assert.match(php, /\$movil \. ', \(max-width: 1073px\) calc\(\(100vw - 32px\) \* 0\.48\), 500px'/);
	assert.match(php, /add_filter\( 'wp_calculate_image_sizes', 'akibara_gp_single_cover_sizes', 10, 5 \);/);
	// El preload pide el mismo tamaño con la función que pasa por ese filtro.
	const preload = sinComentarios(read('functions.php'));
	assert.match(preload, /\$size\s*=\s*'woocommerce_single';/);
	assert.match(preload, /\$sizes\s*=\s*wp_get_attachment_image_sizes\( \$image_id, \$size \);/);
	// No se poda el srcset (regla escrita del tema).
	assert.doesNotMatch(php, /add_filter\( 'wp_calculate_image_srcset'/);
});
