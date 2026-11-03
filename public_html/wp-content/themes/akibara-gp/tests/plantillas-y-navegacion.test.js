'use strict';

/**
 * Contratos del lote T7 «plantillas y navegación» (auditoría del tema 2026-09-22):
 * UM-13 + UC-10 (vista rápida como icono y cabecera del catálogo en una fila),
 * UM-10 (vitrinas de la portada en una fila deslizable en el teléfono), UC-16
 * (placeholder del buscador) y UC-12 = UM-17 (serie de la guía en la entrada).
 *
 * El comportamiento se mide en el navegador (tools/akibara-e2e/specs/
 * tema-plantillas-y-navegacion.spec.mjs); aquí se fija lo que un «arreglo» de
 * paso podría deshacer sin que ninguna página dé error.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const raiz = path.resolve( __dirname, '..' );
const leer = ( f ) => fs.readFileSync( path.join( raiz, f ), 'utf8' );
const sinComentarios = ( s ) => s.replace( /\/\*[\s\S]*?\*\//g, '' );

/**
 * Cuerpo de la PRIMERA regla cuyo selector contiene `selector`, sin comentarios.
 *
 * @param {string} css
 * @param {string} selector
 */
function regla( css, selector ) {
	const limpio = sinComentarios( css );
	const i = limpio.indexOf( selector );
	assert.notEqual( i, -1, `no existe la regla ${ selector }` );
	const abre = limpio.indexOf( '{', i );
	return limpio.slice( abre + 1, limpio.indexOf( '}', abre ) );
}

test( 'UM-13/UC-10: «Vista rápida» es un icono de 44 px sobre la portada, fuera del flujo de la tarjeta', () => {
	const loop = leer( 'assets/css/loop.css' );
	const cuerpo = regla( loop, '.woocommerce ul.products li.product > .akb-qv-trigger {' );

	assert.match( cuerpo, /position:\s*absolute;/, 'apilado en el flujo sumaba 56 px por tarjeta' );
	assert.match( cuerpo, /top:\s*var\(--space-2\);/ );
	assert.match( cuerpo, /right:\s*var\(--space-2\);/, 'arriba a la derecha: las insignias van arriba a la izquierda y el corazón abajo' );
	assert.match( cuerpo, /width:\s*44px;/ );
	assert.match( cuerpo, /height:\s*44px;/ );
	assert.match( cuerpo, /font-size:\s*0;/, 'el texto queda en el DOM; el nombre lo da el aria-label del plugin' );
	// El plugin pinta el botón `hidden` hasta comprobar <dialog>: declarar
	// `display` aquí, con más especificidad que `.akb-qv-trigger[hidden]`, lo
	// mostraría en navegadores donde no funciona.
	assert.doesNotMatch( cuerpo, /display\s*:/ );

	// Visible siempre: no depende del ratón (portátiles táctiles, teclado).
	assert.doesNotMatch( sinComentarios( loop ), /\.akb-qv-trigger\s*\{[^}]*opacity:\s*0\s*;/ );
	// El rótulo aparece con teclado además de con ratón.
	assert.match( sinComentarios( loop ), /> \.akb-qv-trigger:focus-visible::after\s*\{\s*opacity:\s*1;/ );
	// Y su texto es el del panel (D5b): lo trae el botón del plugin, no un literal del tema.
	assert.match( regla( loop, '.woocommerce ul.products li.product > .akb-qv-trigger::after {' ), /content:\s*attr\(data-akb-qv-label\);/ );
	assert.match( leer( '../../plugins/akibara-business/src/Modules/QuickView/QuickView.php' ), /data-akb-qv-label="%s"/ );

	// En el teléfono no se pinta (V[UM-13]; revisión T7-1, D5): tapaba el título o
	// el número del tomo en la esquina de portadas de ~158 px.
	const limpio = sinComentarios( loop );
	const movil = limpio.slice( limpio.indexOf( '@media (max-width: 768px)', limpio.indexOf( '.akb-qv-trigger' ) ) );
	assert.match(
		movil,
		/^@media \(max-width: 768px\) \{\s*\.woocommerce ul\.products li\.product > \.akb-qv-trigger \{\s*display:\s*none;\s*\}/,
		'la vista rápida vuelve a pintarse en el teléfono'
	);
} );

test( 'UC-10: el H1 y la fila de orden comparten fila sólo cuando la cabecera trae el título solo y la fila va detrás', () => {
	const wc = sinComentarios( leer( 'assets/css/woocommerce.css' ) );
	const guarda = ':has(> .woocommerce-products-header > .page-title:only-child):has(> .woocommerce-products-header + .akb-shop-toolbar)';
	const bloque = wc.slice( wc.indexOf( '@media (min-width: 1025px)', wc.indexOf( guarda ) - 400 ) );

	assert.ok( wc.includes( guarda ), 'sin la guarda, /manga/ (con descripción) y la búsqueda con banda de serie perderían su cabecera apilada' );
	assert.match( bloque, /display:\s*flex;\s*flex-wrap:\s*wrap;/, 'flex con salto y no rejilla: con rejilla la miga salía centrada o el aviso sin resultados desbordaba' );
	assert.match( bloque, /> \* \{\s*flex:\s*1 1 100%;\s*min-width:\s*0;/, 'cada hijo en su línea, encogiendo con sus márgenes' );
	assert.match( bloque, /> \.woocommerce-products-header \{\s*flex:\s*0 1 auto;/ );
	assert.match( bloque, /> \.akb-shop-toolbar \{\s*flex:\s*1 1 26rem;/, 'si no cabe junto al título, la fila de orden baja' );
	// El [hidden] de los chips lo respeta su dueño, akb-search.css (T7): el
	// guard temporal del tema se retiró en el mismo cambio.
	assert.match( leer( '../../plugins/akibara-search/assets/css/akb-search.css' ), /\.akb-facet-chips\[hidden\] \{ display: none; \}/ );
	// El tema sólo nombra los chips para el canalón de las landings sin barra
	// (lote X1: margen lateral); ninguna regla suya decide si se ven.
	for ( const m of wc.matchAll( /([^{}]*\.akb-facet-chips[^{}]*)\{([^}]*)\}/g ) ) {
		assert.doesNotMatch( m[ 2 ], /display\s*:/, `el tema vuelve a decidir la visibilidad de los chips: ${ m[ 1 ].trim() }` );
		assert.doesNotMatch( m[ 1 ], /\[hidden\]|:empty/, `el tema vuelve a vigilar el [hidden] de los chips: ${ m[ 1 ].trim() }` );
	}

	// Revisión T7-1, D1: con un mensaje dentro de la fila de orden —aviso de
	// WooCommerce, corrección del buscador o «No tenemos X de <editorial>»— la
	// cabecera se apila. Las SEIS reglas del bloque —las cinco de la fila y la del
	// canalón de /autor/* en ella (revisión X1-1)— llevan la exclusión: si una la
	// pierde, esa parte de la maqueta se aplica a medias.
	const sinMensaje = ':not(:has(> .akb-shop-toolbar .woocommerce-notices-wrapper > *, > .akb-shop-toolbar .akb-search-correction:not([hidden]), > .akb-shop-toolbar .akb-relaxed:not([hidden])))';
	assert.equal( wc.split( guarda + sinMensaje ).length - 1, 6, 'las seis reglas de la cabecera en una fila excluyen la fila de orden con mensaje' );
	assert.equal( wc.split( guarda ).length - 1, 6, 'no queda ninguna regla con la guarda vieja, sin la exclusión' );
	// Los avisos del buscador se ocultan con `hidden`, no vaciándose: `:empty` no serviría.
	assert.match( leer( '../../plugins/akibara-search/assets/css/akb-search.css' ), /\.akb-search-correction\[hidden\] \{ display: none; \}/ );
	assert.match( leer( '../../plugins/akibara-search/assets/css/akb-search.css' ), /\.akb-relaxed\[hidden\] \{ display: none; \}/ );
} );

test( 'Revisión X1-1, D1: en la fila en línea de /autor/* el canalón no se suma entre el título y el total', () => {
	const wc = sinComentarios( leer( 'assets/css/woocommerce.css' ) );
	const fila = ':has(> .woocommerce-products-header > .page-title:only-child):has(> .woocommerce-products-header + .akb-shop-toolbar):not(:has(> .akb-shop-toolbar .woocommerce-notices-wrapper > *, > .akb-shop-toolbar .akb-search-correction:not([hidden]), > .akb-shop-toolbar .akb-relaxed:not([hidden])))';
	const selector = `body.archive.woocommerce-page.tax-pa_autor .woocommerce-archive-wrapper .entry-content${ fila } > .woocommerce-products-header {`;
	const i = wc.indexOf( selector );
	assert.notEqual( i, -1, 'la regla del canalón de /autor/* en la fila en línea' );
	assert.ok( wc.lastIndexOf( '@media (min-width: 1025px)', i ) > wc.lastIndexOf( '}\n}', i ), 'va dentro de la media de la fila en línea (≥ 1025 px)' );
	const cuerpo = regla( wc, selector );

	// El canalón de las landings sin barra (≥ 769 px), con su mínimo en px.
	const canal = /body\.archive\.tax-pa_autor \.entry-content > :is\([^)]*\) \{[^}]*margin-inline:\s*max\((\d+)px, \(100% - var\(--container-max\)\) \/ 2\);/.exec( wc );
	assert.ok( canal, 'el canalón de /autor/*' );
	const px = canal[ 1 ];
	// El lado derecho de la cabecera lleva el canalón en negativo: en la misma
	// línea anula el izquierdo de la fila de orden, y si ésta baja de línea
	// conserva el suyo (anularlo en ella la pegaba al borde de la ventana).
	assert.match( cuerpo, new RegExp( `margin-inline-end:\\s*min\\(-${ px }px, \\(var\\(--container-max\\) - 100%\\) \\/ 2\\);` ), 'el negativo exacto del canalón' );
	// Con el negativo, una cabecera sola en su línea (título largo) se saldría de
	// la columna por la derecha: su ancho máximo es la columna.
	assert.match( cuerpo, new RegExp( `max-width:\\s*calc\\(100% - 2 \\* max\\(${ px }px, \\(100% - var\\(--container-max\\)\\) \\/ 2\\)\\);` ), 'la cabecera no pasa de la columna' );
	// Ninguna regla de la fila le quita el canalón a la fila de orden.
	for ( const m of wc.matchAll( /([^{}]*> \.akb-shop-toolbar) \{([^}]*)\}/g ) ) {
		if ( m[ 1 ].includes( fila ) ) {
			assert.doesNotMatch( m[ 2 ], /margin-inline(-start)?\s*:/, `la fila de orden pierde su canalón: ${ m[ 1 ].trim().slice( 0, 80 ) }…` );
		}
	}
} );

test( 'UM-10: en el teléfono cada vitrina de la portada es una fila deslizable con la tercera tarjeta asomando', () => {
	const home = sinComentarios( leer( 'assets/css/home.css' ) );
	const movil = home.slice( home.indexOf( '@media (max-width: 768px)' ) );
	const cuerpo = regla( movil, '.akb-section .akb-home-series__grid {' );

	assert.match( cuerpo, /grid-auto-flow:\s*column;/ );
	assert.match( cuerpo, /grid-auto-columns:\s*clamp\(140px, 44%, 200px\);/, '44 %: dos tarjetas y el borde de la tercera' );
	assert.match( cuerpo, /overflow-x:\s*auto;/ );
	assert.match( cuerpo, /scroll-snap-type:\s*x mandatory;/ );
	assert.match( cuerpo, /margin-inline:\s*calc\(-1 \* var\(--akb-fila-canal\)\);/ );
	assert.match( cuerpo, /--akb-fila-canal:\s*clamp\(14px, 4vw, 32px\);/ );
	// El canal de la fila tiene que ser el padding lateral de la sección, o la
	// fila a sangre se sale de la pantalla (scroll horizontal de la página).
	assert.match( regla( home, '.akb-section {' ), /padding-inline:\s*clamp\(14px, 4vw, 32px\);/ );
	assert.match( movil, /\.akb-section \.woocommerce ul\.products > li\.product,\s*\.akb-section \.akb-home-series__grid > \.akb-home-series__item \{\s*scroll-snap-align:\s*start;/ );
	// Sólo la portada: home.css no se encola en el catálogo.
	assert.match( leer( 'inc/enqueue.php' ), /if \( akibara_gp_is_home_surface\(\) \) \{\s*list\( \$src, \$ver \) = akibara_gp_asset\( 'assets\/css\/home\.css' \);/ );
} );

test( 'UM-10 / revisión T7-1 D2: las portadas de la 4.ª tarjeta en adelante no se piden hasta deslizar, sin cambiar el alto', () => {
	const home = sinComentarios( leer( 'assets/css/home.css' ) );
	const movil = home.slice( home.indexOf( '@media (max-width: 768px)' ) );
	const fila = regla( movil, '.akb-section .akb-home-series__grid {' );

	// La caja de la portada, no la tarjeta: el texto se pinta siempre y la fila no cambia de alto.
	const producto = regla( movil, '.akb-section .woocommerce ul.products > li.product:nth-child(n+4) .akb-product-cover-wrap {' );
	assert.match( producto, /content-visibility:\s*auto;/ );
	assert.doesNotMatch( movil, /li\.product:nth-child\([^)]*\)\s*\{[^}]*content-visibility/, 'en la tarjeta entera, un título más largo haría crecer la fila al deslizar' );
	assert.match( regla( movil, '.akb-section .akb-home-series__grid > .akb-home-series__item:nth-child(n+4) .akb-home-series__cover {' ), /content-visibility:\s*auto;/ );
	// Desde la 4.ª: la 3.ª asoma y tiene que verse con su portada.
	assert.doesNotMatch( movil, /:nth-child\(n\+3\)/ );

	// El alto sin pintar = 2:3 del ancho de la caja = (columna − 2 × borde de la tarjeta).
	const m = producto.match( /contain-intrinsic-block-size:\s*auto calc\(1\.5 \* \(clamp\((\d+)px, (\d+)cqi, (\d+)px\) - (\d+)px\)\);/ );
	assert.ok( m, 'falta el alto de reserva de la caja sin pintar' );
	const columna = fila.match( /grid-auto-columns:\s*clamp\((\d+)px, (\d+)%, (\d+)px\);/ );
	assert.deepEqual( m.slice( 1, 4 ), columna.slice( 1, 4 ), 'la reserva usa la misma columna que la fila (en cqi en vez de %)' );
	const borde = regla( sinComentarios( leer( 'assets/css/loop.css' ) ), '.woocommerce ul.products li.product {' ).match( /border:\s*(\d+)px solid/ );
	assert.equal( Number( m[ 4 ] ), 2 * Number( borde[ 1 ] ), 'si cambia el borde de la tarjeta, cambia el ancho de la portada y la reserva deja de cuadrar' );
	assert.match( regla( movil, '.akb-section .woocommerce ul.products {' ), /container-type:\s*inline-size;/, 'sin contenedor, cqi mide la ventana' );
	// La serie reserva con su propio 2:3 (plugin).
	assert.match( leer( '../../plugins/akibara-business/assets/css/home-series-destacadas.css' ), /\.akb-home-series__cover \{[^}]*aspect-ratio:\s*2 \/ 3;/ );
} );

test( 'UC-16: la cabecera invita a buscar series y autores; el cajón móvil conserva su texto corto', () => {
	const shell = leer( 'template-parts/header/shell.php' );
	const drawer = leer( 'template-parts/header/mobile-drawer.php' );

	assert.match( shell, /placeholder="<\?php echo esc_attr\( akibara_gp_search_placeholder\(\) \); \?>"/ );
	assert.doesNotMatch( shell, /placeholder="Buscar productos…"/ );
	assert.match( drawer, /placeholder="Buscar…"/, 'decisión escrita: a 340 px no cabe más' );
	assert.match( leer( 'inc/site-copy.php' ), /akibara_gp_copy\( 'akb_copy_search_placeholder', 'Buscar manga, series o autor…' \)/ );
} );

test( 'UC-12: la entrada pinta la tarjeta de su serie y el cierre lleva a la serie; sin serie, a la tienda', () => {
	const single = leer( 'single.php' );

	assert.match( single, /akibara_gp_guide_series_data\( \(int\) get_the_ID\(\) \)/ );
	assert.match( single, /echo akibara_gp_insert_guide_series_card\( \$akb_content, \$akb_serie_card \);/ );
	assert.doesNotMatch( single, /<\?php the_content\(\); \?>/, 'el contenido se imprime una sola vez, con la tarjeta intercalada' );
	assert.match( single, /apply_filters\( 'the_content', get_the_content\(\) \)/ );
	assert.match( single, /str_replace\( '\]\]>', '\]\]&gt;'/, 'lo mismo que the_content()' );
	assert.match( single, /esc_url\( \$akb_serie_datos\['url'\] \)/ );
	assert.match( single, /esc_url\( home_url\( '\/tienda\/' \) \)/, 'sin serie ligada, el cierre sigue yendo a la tienda' );
	// Los textos del cierre ya no son literales de la plantilla.
	assert.doesNotMatch( single, />Explorar la tienda</ );
	assert.doesNotMatch( single, /¿Te inspiró\?/ );

	assert.match( leer( 'functions.php' ), /require_once \$akb_inc \. 'guide-series\.php';/ );
	// El dato (qué serie, portada, conteos, etiqueta de caché) es de business
	// (D6): el tema sólo lo recibe por el filtro y lo pinta.
	const guia = leer( 'inc/guide-series.php' ).replace( /\/\*[\s\S]*?\*\//g, '' ).replace( /^\s*\/\/.*$/gm, '' );
	assert.match( guia, /apply_filters\( 'akb_guide_series_card', null, \$post_id \)/ );
	assert.doesNotMatch( guia, /get_terms\(|SeriesCover|catalog_facts|litespeed_tag_add|_akb_related_guide_post_id|' tomos?'/ );
	const tarjeta = leer( '../../plugins/akibara-business/src/Modules/RelatedGuides/GuideSeriesCard.php' );
	assert.match( tarjeta, /public const FILTER = 'akb_guide_series_card';/ );

	const blog = sinComentarios( leer( 'assets/css/blog.css' ) );
	assert.match( regla( blog, '.akb-post__content .akb-guide-series__cover {' ), /aspect-ratio:\s*2 \/ 3;/, 'alto reservado: la tarjeta no desplaza el texto' );
} );

/**
 * Monta el bloque real de las vitrinas deslizables de main.js sobre filas de
 * mentira que guardan TODOS sus oyentes (puede haber dos del mismo tipo).
 *
 * @param {object} [medidas] Rectángulo y anchos de la fila.
 */
function montarFilas( medidas = {} ) {
	const main = leer( 'assets/js/main.js' );
	const inicio = main.indexOf( '/* ── Vitrinas deslizables de la portada: soltar sus portadas al tocar la fila' );
	assert.notEqual( inicio, -1 );
	const fin = main.indexOf( '/* ── El botón de WhatsApp cede', inicio );
	const bloque = main.slice( inicio, fin );

	const fila = () => {
		const oyentes = [];
		const clases = new Set();
		return {
			oyentes,
			clases,
			scrollWidth: medidas.scrollWidth ?? 1400,
			clientWidth: medidas.clientWidth ?? 390,
			getBoundingClientRect: () => ( { left: 0, right: 390, top: 100, bottom: 500 } ),
			classList: { add: ( c ) => clases.add( c ) },
			addEventListener: ( tipo, fn, op ) => {
				assert.equal( op && op.passive, true, `${ tipo } pasivo: no puede frenar el desplazamiento` );
				assert.notEqual( op && op.capture, true );
				oyentes.push( { tipo, fn } );
			},
			removeEventListener: ( tipo, fn ) => {
				const i = oyentes.findIndex( ( o ) => o.tipo === tipo && o.fn === fn );
				if ( i !== -1 ) {
					oyentes.splice( i, 1 );
				}
			},
			tipos: () => oyentes.map( ( o ) => o.tipo ).sort(),
			disparar( tipo, evento = {} ) {
				for ( const o of oyentes.filter( ( x ) => x.tipo === tipo ) ) {
					o.fn( evento );
				}
			},
		};
	};
	const filas = [ fila(), fila() ];
	const salida = { filas, selector: '' };
	const doc = { querySelectorAll: ( s ) => { salida.selector = s; return filas; } };
	vm.runInContext( '(function(){' + bloque + '})();', vm.createContext( { doc } ) );
	return salida;
}

/**
 * Elemento enfocado de mentira.
 *
 * @param {number}         left     Borde izquierdo en la vista.
 * @param {number}         right    Borde derecho.
 * @param {boolean|string} visible  `:focus-visible`; 'lanza' = navegador sin soporte.
 */
function enfocado( left, right, visible = true, caja = null ) {
	// La tarjeta que lo contiene (`li.product`, con scroll-snap-align: start).
	// Sin `caja`, mide lo mismo que el elemento enfocado.
	const tarjeta = {
		llamadas: [],
		scrollIntoView: ( op ) => tarjeta.llamadas.push( op ),
		getBoundingClientRect: () => ( caja ? { left: caja[ 0 ], right: caja[ 1 ], top: 110, bottom: 490 } : { left, right, top: 120, bottom: 480 } ),
	};
	const el = {
		llamadas: [],
		tarjeta,
		getBoundingClientRect: () => ( { left, right, top: 120, bottom: 480 } ),
		matches: ( sel ) => {
			assert.equal( sel, ':focus-visible' );
			if ( 'lanza' === visible ) {
				throw new SyntaxError( 'selector no soportado' );
			}
			return visible;
		},
		closest: ( sel ) => {
			assert.equal( sel, 'li.product, .akb-home-series__item' );
			return tarjeta;
		},
		scrollIntoView: ( op ) => el.llamadas.push( op ),
	};
	return el;
}

test( 'revisión T7-2 D1: el foco de teclado arrastra la fila hasta la tarjeta que asoma, y el ratón no la mueve', () => {
	const { filas } = montarFilas();
	const [ fila ] = filas;

	// La tarjeta que asoma: enlace en x=369..499 de una fila de 0..390 (medido en Chromium).
	const asoma = enfocado( 369, 499 );
	fila.disparar( 'focusin', { target: asoma } );
	// JSON: el objeto nace en el contexto de vm y deepStrictEqual compara prototipos.
	// Se mueve la TARJETA al inicio (punto de encaje), no el enlace con «nearest»:
	// con `scroll-snap-type: x mandatory` el navegador devolvía la fila a donde
	// estaba entre 560 y 650 px (revisión REV-T7-3-D1).
	assert.equal( JSON.stringify( asoma.tarjeta.llamadas ), '[{"block":"nearest","inline":"start"}]', 'la tarjeta, a un punto de encaje' );
	assert.equal( asoma.llamadas.length, 0, 'el enlace no se desplaza por su cuenta' );
	assert.ok( fila.clases.has( 'akb-fila--activa' ), 'el foco también suelta las portadas' );

	// El oyente del foco NO se retira con soltar(): la 5.ª tarjeta también se sigue.
	const quinta = enfocado( 369, 499 );
	fila.disparar( 'focusin', { target: quinta } );
	assert.equal( quinta.tarjeta.llamadas.length, 1 );

	// Por la izquierda (Mayús+Tab) igual.
	const atras = enfocado( -120, 40 );
	fila.disparar( 'focusin', { target: atras } );
	assert.equal( atras.tarjeta.llamadas.length, 1 );

	// Ya visible entera: no se toca (WebKit ya la desliza solo).
	const entera = enfocado( 16, 186 );
	fila.disparar( 'focusin', { target: entera } );
	assert.equal( entera.tarjeta.llamadas.length + entera.llamadas.length, 0 );

	// QA-ESC-5: el enlace cabe (vive dentro del relleno de la tarjeta) pero la
	// TARJETA no: entre 636 y 649 px quedaba cortada hasta 13 px por la derecha
	// y la fila no se movía. Se mide la tarjeta.
	const enlaceDentro = enfocado( 262, 378, true, [ 250, 399.6 ] );
	fila.disparar( 'focusin', { target: enlaceDentro } );
	assert.equal( JSON.stringify( enlaceDentro.tarjeta.llamadas ), '[{"block":"nearest","inline":"start"}]', 'la tarjeta cortada se lleva a un punto de encaje' );

	// Foco de ratón o dedo (sin :focus-visible): mover la fila entre mousedown y
	// mouseup dejaría el clic en otra tarjeta.
	const raton = enfocado( 369, 499, false );
	fila.disparar( 'focusin', { target: raton } );
	assert.equal( raton.tarjeta.llamadas.length + raton.llamadas.length, 0 );

	// Navegador sin :focus-visible: como antes, sin error.
	const viejo = enfocado( 369, 499, 'lanza' );
	assert.doesNotThrow( () => fila.disparar( 'focusin', { target: viejo } ) );
	assert.equal( viejo.tarjeta.llamadas.length + viejo.llamadas.length, 0 );

	// En escritorio la fila no desliza (rejilla): nada que seguir.
	const { filas: rejilla } = montarFilas( { scrollWidth: 1200, clientWidth: 1200 } );
	const enRejilla = enfocado( 369, 1500 );
	rejilla[ 0 ].disparar( 'focusin', { target: enRejilla } );
	assert.equal( enRejilla.tarjeta.llamadas.length + enRejilla.llamadas.length, 0 );
} );

test( 'revisión T7-1 D2: al primer toque, desplazamiento o foco, la fila suelta sus portadas (y sólo esa fila)', () => {
	const { filas, selector } = montarFilas();

	assert.equal( selector, '.akb-section .woocommerce ul.products, .akb-section .akb-home-series__grid' );
	for ( const f of filas ) {
		assert.deepEqual( f.tipos(), [ 'focusin', 'focusin', 'pointerdown', 'scroll', 'touchstart' ], 'los cuatro de soltar más el que sigue al foco' );
	}

	filas[ 0 ].disparar( 'scroll' );
	assert.ok( filas[ 0 ].clases.has( 'akb-fila--activa' ) );
	assert.deepEqual( filas[ 0 ].tipos(), [ 'focusin' ], 'tras el primero sólo queda el que sigue al foco' );
	assert.equal( filas[ 1 ].clases.size, 0, 'la fila que nadie tocó sigue sin pedir nada' );
	assert.equal( filas[ 1 ].tipos().length, 5 );

	filas[ 1 ].disparar( 'touchstart' );
	assert.ok( filas[ 1 ].clases.has( 'akb-fila--activa' ) );

	// Y la hoja la entiende: activa → portadas visibles, en las dos vitrinas.
	const home = sinComentarios( leer( 'assets/css/home.css' ) );
	const movil = home.slice( home.indexOf( '@media (max-width: 768px)' ) );
	assert.match(
		movil,
		/\.akb-section \.woocommerce ul\.products\.akb-fila--activa > li\.product:nth-child\(n\+4\) \.akb-product-cover-wrap,\s*\.akb-section \.akb-home-series__grid\.akb-fila--activa > \.akb-home-series__item:nth-child\(n\+4\) \.akb-home-series__cover \{\s*content-visibility:\s*visible;/
	);
	assert.ok( movil.indexOf( 'content-visibility: visible' ) > movil.lastIndexOf( 'content-visibility: auto' ), 'la regla que suelta va detrás de la que difiere' );
} );

test( 'revisión T7-2: con «reducir movimiento» el panel ancho del menú conserva su centrado y no ensancha la página', () => {
	const header = sinComentarios( leer( 'assets/css/header.css' ) );
	const reduce = header.slice( header.lastIndexOf( '@media (prefers-reduced-motion: reduce)' ) );
	const quita = reduce.search( /\.mainnav__sub \{\s*transform:\s*none;/ );
	const centra = reduce.search( /\.mainnav__sub--auto \{\s*transform:\s*translateX\(-50%\);/ );
	assert.notEqual( quita, -1, 'el bloque reduce sigue neutralizando el deslizamiento del panel' );
	assert.notEqual( centra, -1, 'sin el centrado, el panel cerrado quedaba corrido media anchura: 74-281 px de scroll lateral' );
	assert.ok( centra > quita, 'misma especificidad: el centrado tiene que ir DETRÁS del «transform: none»' );
	// El panel sigue centrándose con left: 50% + ese mismo -50%.
	assert.match( regla( header, '.mainnav__sub--auto {' ), /left:\s*50%;[\s\S]*transform:\s*translate\(-50%, -8px\) scale\(0\.98\);/ );
} );
