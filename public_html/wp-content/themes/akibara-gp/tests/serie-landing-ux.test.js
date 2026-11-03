const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const themeRoot = path.resolve(__dirname, '..');
const read = (relative) => fs.readFileSync(path.join(themeRoot, relative), 'utf8');

// Invariantes de la revisión UX de las landings /serie/* (2026-08-10).
// Son tests ESTÁTICOS sobre el fuente — el theme no tiene harness de DOM — y
// cada uno ancla un defecto que se MIDIÓ en producción, no una preferencia.
// El 72,5% del tráfico de estas páginas es móvil: todo lo de acá es móvil.

// Igual que en brands-marquee.test.js: las aserciones son regex sobre el
// fuente, así que un comentario que mencione la propiedad prohibida las haría
// pasar (o fallar) por la prosa. Esta suite ya se comió ese error dos veces.
function sinComentarios(fuente) {
	return String(fuente)
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

test('el foco de teclado no queda bajo las barras fijas (WCAG 2.2 SC 2.4.11)', () => {
	const css = sinComentarios(read('assets/css/base.css'));
	// Cuando el elemento enfocado ya está dentro del viewport pero tapado por una
	// barra fija, ningún motor hace scroll: el foco desaparece. Medido: header
	// sticky 70px + la franja inferior (bottom-nav 66 y, en la ficha, la barra
	// de compra apilada encima) ocultan por completo un CTA de 44px.
	assert.match(
		css,
		/html\s*\{[^}]*scroll-padding-top:\s*calc\(\s*var\(--header-height/,
		'falta scroll-padding-top contra el header sticky'
	);
	// Aquí se exigía el literal `calc(64px`: era el alto de UNA barra sin su
	// borde, y en la ficha móvil se apilan dos (132 medidos en producción el
	// 2026-09-11 contra 72 reservados). El candado fijaba el número equivocado y
	// habría frenado el arreglo. La invariante es «tanto como ocupe la franja»:
	// la mide franja-inferior.js y la escribe en `--akb-franja-inferior`; el
	// respaldo es el peor caso de tokens.css MÁS el safe-area. El `env()` va
	// dentro del respaldo y no fuera: la medida en vivo ya lo trae (el alto del
	// bottom-nav es token + env), y sumarlo fuera lo contaba dos veces con JS.
	const bloqueMovil = css.match(/@media\s*\(max-width:\s*1024px\)[\s\S]*?scroll-padding-bottom:\s*calc\(([^;]*)\)\s*;/);
	assert.ok(bloqueMovil, 'falta scroll-padding-bottom en el bloque ≤1024px');
	assert.match(
		bloqueMovil[1],
		/^\s*var\(--akb-franja-inferior\s*,\s*calc\(\s*var\(--franja-inferior-peor[^)]*\)\s*\+\s*env\(safe-area-inset-bottom/,
		'la reserva de abajo debe leer la franja medida en vivo, con el peor caso de tokens.css + safe-area de respaldo'
	);
	assert.equal(
		(bloqueMovil[1].match(/env\(safe-area-inset-bottom/g) || []).length,
		1,
		'el safe-area se suma UNA vez, dentro del respaldo: la medida en vivo ya lo incluye'
	);
	assert.doesNotMatch(
		bloqueMovil[1],
		/^\s*\d+px/,
		'la reserva de abajo no puede volver a ser un literal de píxeles'
	);
	// La ficha reserva al pie el MÁXIMO entre el peor caso estático y la medida
	// en vivo: la barra escala con la tipografía raíz (precio en rem) y con la
	// fuente del navegador a 24px la franja mide 185, no 134; al fondo de la
	// página no hay recorrido para que nada la rescate. Y la ficha agotada, que
	// no pinta la barra, no reserva la franja entera.
	const woo = sinComentarios(read('assets/css/woocommerce.css'));
	const pieFicha = woo.match(/body\.single-product\s*\{[^}]*padding-bottom:\s*([^;]*);/);
	assert.ok(pieFicha, 'falta el padding-bottom de body.single-product');
	assert.match(pieFicha[1], /^\s*max\(/, 'el pie de la ficha es max(peor caso estático, medida en vivo)');
	assert.match(pieFicha[1], /var\(--franja-inferior-peor/, 'el peor caso estático sigue siendo el suelo');
	assert.match(pieFicha[1], /var\(--akb-franja-inferior/, 'la medida en vivo tiene que poder superar el suelo');
	assert.match(
		woo,
		/body\.single-product:not\(:has\(\.akb-sticky-atc\)\)\s*\{[^}]*padding-bottom:\s*calc\(\s*var\(--bottom-nav-height/,
		'la ficha sin barra de compra reserva sólo el bottom-nav (como :not(:has()) para que sin soporte quede el peor caso)'
	);
	const enqueue = read('inc/enqueue.php');
	// Se encola la copia servida (`.min` salvo SCRIPT_DEBUG) que resuelve
	// akibara_gp_asset() a partir de la fuente.
	assert.match(
		enqueue,
		/akibara_gp_asset\(\s*'assets\/js\/franja-inferior\.js'\s*\);\s*wp_enqueue_script\(\s*'akibara-franja-inferior',\s*\$src,/,
		'franja-inferior.js tiene que encolarse: sin él la reserva es sólo el respaldo estático'
	);
	// Y se encola en TODAS las páginas: el bottom-nav lo pinta footer.php en
	// todas. Metido dentro del `if ( is_product() )` la portada, la tienda y el
	// carrito caen al respaldo estático (68px de reserva de más) y esta suite
	// seguía en verde.
	// Sólo cuenta el cuerpo de akibara_gp_enqueue(): los ayudantes de arriba
	// (p. ej. el contexto de woocommerce.css) preguntan por is_product() sin
	// condicionar ningún encolado.
	const cuerpoEnqueue = sinComentarios(enqueue).split('function akibara_gp_enqueue()')[1] || '';
	const [antesDelEncolado, despuesDelEncolado] = cuerpoEnqueue.split("'akibara-franja-inferior'");
	assert.ok(despuesDelEncolado !== undefined, 'no se encontró el handle akibara-franja-inferior');
	assert.doesNotMatch(antesDelEncolado, /is_product\(\)/, 'franja-inferior.js debe encolarse ANTES de cualquier condicional is_product()');
	const franja = sinComentarios(read('assets/js/franja-inferior.js'));
	assert.match(franja, /setProperty\(PROPIEDAD/, 'el JS debe escribir la custom property');
	assert.match(franja, /'--akb-franja-inferior'/, 'el nombre de la custom property es el que lee base.css');
	assert.match(franja, /ResizeObserver/, 'el alto de la barra cambia al rotar: hay que observarlo');
	assert.match(franja, /observe\([^)]*\{\s*box:\s*'border-box'\s*\}/, 'se observa la caja de borde, que es la que tapa (el padding y el borde no mueven la de contenido)');
	assert.match(franja, /requestAnimationFrame/, 'las remedidas se agrupan por cuadro: resize en iOS dispara de continuo durante el scroll');
	assert.match(franja, /evento\.target === evento\.currentTarget/, 'transitionend burbujea desde los hijos de la barra: sólo cuenta el de la propia barra');
	assert.match(franja, /addEventListener\('focusin'/, 'falta la red de seguridad al enfocar');
	assert.match(franja, /elementsFromPoint/, 'la red mira lo dibujado, no una lista de selectores');
	// La red de foco NO puede correr con foco por puntero: llega entre el
	// mousedown y el mouseup, y desplazar ahí mueve el control bajo el dedo (el
	// click acaba en el <li>, no en «Añadir al carrito»). Medido en el espejo el
	// 2026-09-11; la sonda de aceptación no lo ve porque enfoca con focus().
	// Prueba dinámica: tools/akibara-releases/rewards-qa/qa-clic-bajo-la-barra.mjs.
	assert.match(franja, /addEventListener\('pointerdown'/, 'la red debe saber si el foco vino del puntero');
	assert.match(franja, /ultimoPuntero/, 'la red debe salir sin desplazar cuando el foco vino del puntero');
	assert.doesNotMatch(franja, /getPropertyValue\('--space-2'\)/, '--space-2 computa «0.5rem», nunca px: el aire se deduce del scroll-padding computado');
	// scroll-behavior es otro eje (movimiento, no foco) y tiene su propia regla
	// de reduced-motion: este arreglo no debe tocarlo.
	assert.doesNotMatch(
		css.split('scroll-padding-top')[1] || '',
		/scroll-behavior/,
		'este bloque no debe tocar scroll-behavior'
	);
});

test('aria-current distingue «esta página» de «esta sección»', () => {
	const php = read('template-parts/footer/bottom-nav.php');
	// El resaltado VISUAL cubre toda la rama de catálogo a propósito, pero
	// `aria-current="page"` afirma que la URL del enlace ES la servida. En
	// /serie/*, /manga/, /marca/*, /autor/* y en la ficha eso era falso.
	assert.match(php, /aria-current="true"/, 'falta el valor «true» para «dentro de la sección»');
	assert.match(
		php,
		/\$shop_is_exact\s*=[^;]*is_shop\(\)[^;]*!\s*is_paged\(\)/,
		'«page» en Catálogo sólo vale en /tienda/ sin paginar'
	);
	// Y nadie puede volver a emitir `page` desde el flag visual.
	assert.doesNotMatch(
		sinComentarios(php),
		/\$is_shop_active\s*\?\s*' aria-current="page"'/,
		'el flag visual no puede decidir el aria-current'
	);
});

test('el select de orden se anuncia en español', () => {
	const php = read('woocommerce/loop/orderby.php');
	// WooCommerce emite `esc_attr_e('Shop order','woocommerce')`, que en es_CL
	// sale «Pedido de la tienda» — en Chile «pedido» es una orden de compra. La
	// cadena va SIN contexto de traducción, así que ningún filtro gettext la
	// alcanza sin pisarla en toda la instalación: el override es la única vía.
	assert.match(php, /aria-label="Ordenar resultados"/);
	assert.doesNotMatch(sinComentarios(php), /esc_attr_e\(\s*'Shop order'/);
});

test('la descripción de serie clampa igual que el resto de los archives', () => {
	const css = sinComentarios(read('assets/css/loop.css'));
	// Clampaba a 5 donde el resto clampa a 3: 51px medidos a 375px de ancho, con
	// la primera portada arrancando en 771px sobre un viewport útil de ~700 en
	// Safari iOS. El botón «Leer más» sigue dando el resto del texto.
	const bloque = css.match(/\.akb-archive-intro__content--series\.is-collapsed\s*\{[^}]*\}/);
	assert.ok(bloque, 'debe existir la regla de clamp para series');
	assert.match(bloque[0], /line-clamp:\s*3/);
	assert.doesNotMatch(bloque[0], /line-clamp:\s*5/);
});

test('el orden por defecto en pa_serie es por número de tomo, y sigue acotado', () => {
	const php = sinComentarios(read('inc/series-order.php'));
	// `?orderby=title` devuelve 01…17 exacto (verificado con curl contra
	// producción) porque el auto-padding de títulos lo garantiza.
	assert.match(php, /woocommerce_default_catalog_orderby/);
	assert.match(php, /is_tax\(\s*'pa_serie'\s*\)/, 'el gate debe ser pa_serie, no la URL');
	// El interleaver de /tienda/ NO se toca: ahí el round-robin es lo correcto.
	assert.match(
		php,
		/akibara_gp_is_series_archive\(\)\s*&&\s*!\s*akibara_gp_series_search_active\(\)\s*\?\s*'title'\s*:\s*\$orderby/,
		'fuera de pa_serie hay que devolver el valor entrante sin tocarlo'
	);
	// Y la reposición de menu_order tiene que seguir viva: WooCommerce la borra
	// del selector en cuanto el default deja de ser menu_order.
	assert.match(php, /akibara_gp_series_orderby_restore_menu_order/);
	assert.match(
		read('woocommerce/loop/orderby.php'),
		/akibara_gp_series_orderby_restore_menu_order\(\s*\$catalog_orderby_options\s*\)/,
		'la reposición vive en el override porque es el único punto tras el unset'
	);
});

test('ninguna regla oculta el precio de los productos agotados', () => {
	const css = sinComentarios(read('assets/css/loop.css'));
	// `<ins>` es el PRECIO VIGENTE, no una insignia. La regla que lo ocultaba
	// dejaba los agotados en oferta con el precio viejo tachado y ningún precio
	// actual — verificado en producción sobre los tomos 11, 08 y 06 de
	// The Climber. Y la insignia que decía ocultar no coexiste con «Agotado»:
	// las variantes de .akb-badge se excluyen (0 de 17 cards con ambas).
	assert.doesNotMatch(
		css,
		/akb-badge--out_of_stock\)\s*ins\s*\{[^}]*display:\s*none/,
		'esa regla ocultaba la cifra, no la insignia'
	);
	// El grayscale de la portada SÍ es el estado agotado y se queda.
	assert.match(css, /:has\(\.akb-badge--out_of_stock\)\s*img\s*\{[^}]*grayscale/);
});

test('el breadcrumb sube al hub de series y se anuncia en español', () => {
	const php = sinComentarios(read('inc/series-breadcrumb.php'));
	// El peldaño del medio era "Serie del producto" — la etiqueta cruda de la
	// taxonomía — y NO enlazaba a nada, justo donde el usuario sube un nivel.
	assert.match(php, /woocommerce_get_breadcrumb/);
	assert.match(php, /'Series'/);
	// Se empareja por FIRMA (el primer peldaño sin URL que no es el último), no
	// por posición: desde la página 2 WooCommerce añade «Página N» al final y el
	// penúltimo pasa a ser el término, con URL (revisión R12, 2). El matcher
	// first-party que existe (DisponiblesRoute) descarta los peldaños sin URL,
	// y acá el que hay que tocar es exactamente ése.
	assert.doesNotMatch(php, /count\(\s*\$crumbs\s*\)\s*-\s*2/, 'el matcher posicional fallaba en páginas 2+');
	assert.match(php, /empty\(\s*\$crumb\[1\]\s*\)/);
	assert.match(php, /\$pos === count\( \$crumbs \) - 1/, 'el último peldaño (término o «Página N») nunca es la etiqueta');
	// Las tres taxonomías de atributo, con su palabra humana.
	assert.match(php, /'pa_autor'\s*=> 'Autores'/);
	assert.match(php, /'pa_genero'\s*=> 'Géneros'/);
	// El hub se resuelve por el SHORTCODE, no por slug: en local vive en
	// /series-directorio-local/ y en producción en /series/. Un slug clavado
	// habría dado 404 en una de las dos.
	assert.match(php, /has_shortcode\(/);
	assert.doesNotMatch(php, /home_url\(\s*'\/series\/'/);
	// El literal "Breadcrumb" está hardcodeado sin __() en WooCommerce: la única
	// vía es reescribir el wrap_before de los defaults.
	assert.match(php, /woocommerce_breadcrumb_defaults/);
	assert.match(php, /Ruta de navegación/);
	assert.doesNotMatch(sinComentarios(read('single.php')), /aria-label="Breadcrumb"/);
});

test('las portadas del catálogo declaran el hueco real, no el tamaño del archivo', () => {
	const php = sinComentarios(read('inc/performance.php'));
	// `sizes` decía 350px para una celda de 184px: a DPR2 el navegador elegía el
	// candidato de 768w (78.555 B) donde bastaba el de 350w (24.672 B).
	assert.match(php, /wp_calculate_image_sizes/);
	// Y declaran el ancho EXACTO en móvil: `48vw` pasaba por 1-2 px y a DPR 3 eso
	// cruza el peldaño de 560 (185 × 3 = 555 cabe; 187,2 × 3 = 561,6 no).
	assert.match(php, /max-width:\s*380px\)\s*calc\(50vw - 8px\)/);
	assert.match(php, /max-width:\s*768px\)\s*calc\(50vw - 10px\)/);
	assert.doesNotMatch(php, /48vw/);
	// Este filtro NO recibe el nombre del tamaño sino un array [350, 525]: un
	// guard sólo-string lo descarta y el filtro no hace nada.
	assert.match(php, /is_array\(\s*\$size\s*\)/);
	// Y no se poda el srcset por arriba: una tablet de 2 columnas a DPR 2 pide 748.
	assert.doesNotMatch(php, /max_srcset_image_width/);
	// fetchpriority en el catálogo, sin wp_is_mobile (la ruta se cachea y la
	// rama quedaría horneada para todos los user-agents). Se busca DENTRO de la
	// regla del catálogo: la de la home también hace `unset`, y un match sobre el
	// fichero entero seguía verde aunque la del catálogo no tuviera ninguno. Este
	// candado sólo asegura que la regla pone y quita la prioridad; que cada rama
	// lo haga en la celda correcta lo prueban PerformanceHooksTest (mutación
	// medida: quitar uno de los dos `unset` pone rojo el test de prioridad).
	const reglaCatalogo = php.match(/function akibara_gp_catalog_cover_loading\([^)]*\): array \{([\s\S]*?)\n\}/);
	assert.ok(reglaCatalogo, 'la regla de carga del catálogo tiene que existir');
	assert.match(reglaCatalogo[1], /unset\(\s*\$attr\['fetchpriority'\]\s*\)/);
	assert.match(reglaCatalogo[1], /\$attr\['fetchpriority'\]\s*=\s*'high'/);
	assert.doesNotMatch(php, /wp_is_mobile/);
	// Y no se redeclara el helper que ya vive en inc/woocommerce.php.
	assert.doesNotMatch(php, /function akibara_gp_is_catalog_archive/);
});

test('la carga diferida del catálogo está enganchada y no depende del lazy de core', () => {
	const php = sinComentarios(read('inc/performance.php'));
	// PerformanceHooksTest ejecuta las reglas, pero Brain Monkey intercepta
	// add_filter: una regla sin enganchar pasaría la suite y no llegaría al HTML.
	// En producción LiteSpeed apagaba `wp_lazy_loading_enabled` y el catálogo
	// bajaba 22 portadas fuera de pantalla [2026-09-14]; la regla va por
	// atributos para que eso no vuelva a depender de un tercero.
	assert.match(
		php,
		/add_filter\(\s*'wp_get_attachment_image_attributes',\s*'akibara_gp_catalog_cover_loading',\s*11,\s*3\s*\)/
	);
	// Después (10 → 11) de la regla de la home, que la del catálogo no puede deshacer.
	assert.match(
		php,
		/add_filter\(\s*'wp_get_attachment_image_attributes',\s*'akibara_gp_handle_home_product_image_loading',\s*10,\s*3\s*\)/
	);
	// N es uno solo para core y para el tema.
	assert.match(php, /add_filter\(\s*'wp_omit_loading_attr_threshold',\s*'akibara_gp_catalog_lazy_threshold'\s*\)/);
	assert.match(php, /akibara_gp_catalog_lazy_threshold\(\s*3\s*\)/);
	// La rejilla se abre en prioridad 1 y la cuenta es de celdas, no de imágenes.
	// Ya no se escucha lo pintado encima (UM-1, integración 2026-09-23): la
	// escucha de la ficha de serie quedó muda cuando business la bajó bajo la
	// rejilla, y se retiró entera, búfer incluido.
	assert.match(php, /add_action\(\s*'woocommerce_before_shop_loop',\s*'akibara_gp_catalog_grid_start',\s*1\s*\)/);
	assert.doesNotMatch(php, /akibara_gp_catalog_grid_detect_above|akibara_gp_catalog_grid_below_fold|'empujada'/);
	assert.match(php, /did_action\(\s*'woocommerce_before_shop_loop_item'\s*\)/);
	// Una rejilla de shortcode dentro de la descripción de un término dispara el
	// hook ANTES que la principal: sin esta guarda se queda con el reinicio.
	assert.match(php, /wc_get_loop_prop\(\s*'is_shortcode'\s*\)/);
	// El único sitio que mira `wp_lazy_loading_enabled` es la excepción de los
	// correos: ninguna regla de carga pregunta a core si su lazy está encendido,
	// que es justo la opción que LiteSpeed apagaba.
	const usos = php.match(/wp_lazy_loading_enabled/g) || [];
	assert.equal(usos.length, 1, 'wp_lazy_loading_enabled sólo en la excepción de correos');
	assert.match(php, /add_filter\(\s*'wp_lazy_loading_enabled',\s*'akibara_gp_email_images_without_lazy',\s*99\s*\)/);
	// Y se decide por la acción en curso, no por una marca que pueda quedarse
	// encendida si la plantilla del correo revienta. Las dos tablas de artículos
	// (pedido y preparación) se pintan dentro de estas dos acciones.
	assert.match(php, /doing_action\(\s*'woocommerce_email_order_details'\s*\)/);
	assert.match(php, /doing_action\(\s*'woocommerce_email_fulfillment_details'\s*\)/);
	assert.doesNotMatch(php, /woocommerce_email_order_items_(args|table)/);
	// Y la regla vieja de sólo-prioridad no convive con la nueva.
	assert.doesNotMatch(php, /akibara_gp_catalog_image_priority/);
});

test('la ficha completa de la serie va bajo la rejilla en akibara-business (el umbral de 2 lo da por hecho)', (t) => {
	// El tema carga de entrada las 2 primeras portadas también en las series
	// porque encima de la rejilla ya sólo va el resumen de una línea de
	// SeriesFacts (lote B3). Si el plugin vuelve a pintar la ficha completa
	// ENCIMA, la rejilla se va bajo el pliegue y las dos portadas eager con
	// `fetchpriority` compiten con lo que sí se ve: esta prueba se pone roja para
	// que se vuelva a medir el umbral (akibara_gp_catalog_lazy_threshold).
	const fuente = path.join(themeRoot, '../../plugins/akibara-business/src/Modules/SeriesFacts/SeriesFacts.php');
	if (!fs.existsSync(fuente)) {
		t.skip('akibara-business no está en el árbol: no se puede contrastar el contrato');
		return;
	}
	const facts = fs.readFileSync(fuente, 'utf8');
	const tema = read('inc/performance.php');
	assert.match(facts, /<section class="akb-series-facts"/);
	assert.match(facts, /add_action\(\s*'woocommerce_after_shop_loop',\s*array\(\s*\$this,\s*'render'\s*\),\s*\d+\s*\)/);
	assert.match(facts, /add_action\(\s*'woocommerce_before_shop_loop',\s*array\(\s*\$this,\s*'render_summary'\s*\),\s*2\s*\)/);
	assert.doesNotMatch(facts, /add_action\(\s*'woocommerce_before_shop_loop',\s*array\(\s*\$this,\s*'render'\s*\)/);
	assert.doesNotMatch(tema, /'class="akb-series-facts"'/);
});
