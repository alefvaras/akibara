#!/usr/bin/env node
/**
 * Genera las copias `.min` que el tema SIRVE, y parte woocommerce.css por contexto.
 *
 * Lo invoca build-theme-core-css.sh al final (después de concatenar core.css). Se
 * puede correr solo: `node tools/akibara-releases/build-theme-min-assets.mjs`.
 *
 * Por qué existe (auditoría del tema, 2026-09-22, PC-1 = PM-4 + PC-7)
 * ----------------------------------------------------------------------
 * El tema dejaba el minificado a LiteSpeed. LiteSpeed NO optimiza una respuesta que
 * no se puede cachear (`optimize.cls.php`: sin Guest Mode, `! is_cacheable()` =
 * bypass), así que carrito, checkout, mi-cuenta, la búsqueda y las series marcadas
 * no-cache recibían las fuentes comentadas: core.css, woocommerce.css y loop.css
 * suman ~67 KB br de más, bloqueantes (el 41-56 % de cada fuente son comentarios).
 * Y el JS del tema va SIEMPRE crudo, porque está fuera del optimizador a propósito
 * (`data-no-optimize`: window.gsap y el orden de los eventos `akb:*`).
 *
 * Qué genera
 * ----------
 * - `assets/css/<x>.min.css` por cada hoja de CSS_MIN (y CSS_MIN_BUSINESS, las del tema
 *   que encola akibara-business): sólo se quitan comentarios y
 *   espacios (`--minify-whitespace`). Medido el 2026-09-23 sobre las diez hojas: la
 *   minificación completa de esbuild ahorra < 30 B br por hoja frente a esto y,
 *   en cambio, reescribe la hoja (`inset`, colores de 8 dígitos, prefijos según el
 *   destino). Una hoja sólo sin comentarios es la misma cascada, byte a byte de
 *   reglas.
 * - `assets/js/<x>.min.js` por cada guion de JS_MIN (y JS_MIN_BUSINESS), con
 *   `--minify --target=es5`. Los fuentes son ES5 (IIFE + `var`): el destino es5
 *   garantiza que el minificador no INTRODUZCA sintaxis nueva (`a ||= b` rompería
 *   Safari 13) y, si un día alguien escribe ES2015+, el build falla en voz alta en
 *   vez de degradar en silencio. La única excepción declarada es JS_DESTINO.
 *   Sin empaquetar, esbuild no renombra nada del ámbito global: `window.gsap` y los
 *   eventos `akb:*` son propiedades y cadenas, no se tocan.
 * - `assets/css/woocommerce-<contexto>.min.css`: woocommerce.css (174 KB de fuente)
 *   se usaba entre el 2,3 % y el 16 % según la página (DS-10 = PC-5 = PM-13). Cada
 *   contexto recibe el SUBCONJUNTO de reglas que puede casar ahí, EN EL ORDEN
 *   ORIGINAL: la cascada entre las reglas que quedan no cambia, y la hoja sigue
 *   impresa en el mismo sitio (antes de loop.css). Ver `contextosDeSelector()`.
 *
 * Cada fichero generado empieza con `/*! … sha256:<16> … gen:<16> … *\/`: el hash de
 * su fuente y el de ESTE script. tests/min-assets.test.js (tema) los recalcula: si
 * alguien edita una fuente (o este generador) y no regenera, la prueba falla con el
 * comando exacto. Así una edición no puede quedarse sin publicar en silencio, que
 * es el fallo que ya se dio con core.css.
 *
 * esbuild va FIJADO a 0.28.2 (`AKB_ESBUILD` apunta a otro binario si hace falta):
 * con otra versión la salida podría cambiar sin que cambie ninguna fuente. Se toma
 * de la caché de npx sin descargar (`npx --offline`).
 */
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, renameSync, chmodSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const ESBUILD_VERSION = '0.28.2';

const AQUI = dirname( fileURLToPath( import.meta.url ) );
const REPO = join( AQUI, '..', '..' );
export const TEMA = join( REPO, 'public_html', 'wp-content', 'themes', 'akibara-gp' );

/**
 * Hojas que el tema encola y que se sirven minificadas. `woocommerce` no va aquí:
 * se sirve partida por contexto (WC_CONTEXTOS). `critical.css` tampoco: viaja en
 * línea y la compacta akibara_gp_compact_css() al imprimirla. forms.css y
 * brands.css las encola akibara-business: van en CSS_MIN_BUSINESS.
 */
export const CSS_MIN = [ 'core', 'loop', 'blog', 'pages', 'empty-states', 'home', 'trust', 'brands-home', 'reviews-p5-v4' ];

/**
 * Guiones que el tema encola. akibara-forms.js y akibara-brands-filter.js los
 * encola akibara-business (JS_MIN_BUSINESS); vendor/ ya viene minificado.
 */
export const JS_MIN = [
	'wc-event-bridge', 'main', 'franja-inferior', 'sticky-atc', 'pdp-ajax-atc', 'atc-guard',
	'animations', 'animations-loader', 'archive-intro', 'page-toc', 'rank-math-content-analysis',
	'toque-temprano',
];

/**
 * Hojas y guiones que viven en el tema pero ENCOLA akibara-business: Forms
 * (forms.css y akibara-forms.js, en /contacto/) y BrandsDirectory (brands.css y
 * akibara-brands-filter.js, en /editoriales/). Business elige la copia servida con
 * `ThemeAssetVersion::served_path()`, que delega en akibara_gp_asset_servido():
 * la `.min` salvo SCRIPT_DEBUG, la fuente si la copia falta (auditoría del tema
 * 2026-09-22, T4). akibara-brands-filter.js va siempre fuera de LiteSpeed
 * (`data-no-optimize`, lee `?region=` antes del primer pintado) y ninguna de las
 * cuatro se optimiza en una respuesta que LiteSpeed no cachea.
 */
export const CSS_MIN_BUSINESS = [ 'forms', 'brands' ];
export const JS_MIN_BUSINESS = [ 'akibara-forms', 'akibara-brands-filter' ];

/**
 * Destino de esbuild de un guion cuyo fuente NO es ES5: el nivel de SU sintaxis,
 * para que el minificador ni la baje (esbuild reescribiría `async`/`await` con
 * generadores y un ayudante) ni introduzca nada más nuevo que lo que ya pide el
 * fuente. akibara-forms.js usa `const`/`let`, flechas y `async`/`await` (ES2017)
 * desde antes de servirse minificado. El resto va con es5 (ver la cabecera).
 */
export const JS_DESTINO = { 'akibara-forms': 'es2017' };

/**
 * Contextos de woocommerce.css. Deben coincidir con akibara_gp_woocommerce_css_contexto()
 * (inc/enqueue.php): listado = todo lo que no es ficha, carrito/checkout ni cuenta
 * (catálogo, búsqueda, portada, 404).
 */
export const WC_CONTEXTOS = [ 'listado', 'ficha', 'carrito', 'cuenta' ];

const L = 'listado';
const F = 'ficha';
const C = 'carrito';
const A = 'cuenta';

/**
 * Marcas de contexto: una clase (o id) que SÓLO existe en ciertas páginas.
 *
 * La regla es conservadora a propósito: un selector sin ninguna marca va a los
 * cuatro contextos. Sólo sale de un contexto lo que está atado sin ambigüedad a
 * otro: una clase de <body> que pone WordPress/WooCommerce (`single-product`,
 * `woocommerce-cart`, `woocommerce-account`…) o un componente que sólo se pinta en
 * una plantilla (las pestañas de la ficha, la tabla del carrito, la navegación de
 * Mi cuenta). Cada entrada dice dónde se imprime. Si una marca se equivoca, la
 * regla falta en una página: por eso cada contexto se compara contra la hoja
 * entera con getComputedStyle en el espejo antes de publicar.
 *
 * Carrito = carrito, checkout y «pedido recibido» (is_cart() || is_checkout()).
 * El detalle de un pedido sale en «pedido recibido» Y en Mi cuenta → Ver pedido,
 * por eso esas marcas valen para los dos.
 */
export const MARCAS = {
	// Clases de <body>.
	'single-product': [ F ],
	'woocommerce-cart': [ C ],
	'woocommerce-checkout': [ C ],
	'woocommerce-order-received': [ C ],
	'woocommerce-account': [ A ],
	'woocommerce-edit-account': [ A ],
	'woocommerce-edit-address': [ A ],
	'woocommerce-view-order': [ A ],
	'woocommerce-orders': [ A ],
	'woocommerce-lost-password': [ A ],
	archive: [ L ], // body de los archivos del catálogo (el blog no carga esta hoja).
	'akb-distrito': [ L ], // inc/woocommerce.php: /manga/ y /comics/.

	// Ficha (single-product.php y sus plantillas).
	'woocommerce-tabs': [ F ],
	'woocommerce-Tabs-panel--description': [ F ],
	'woocommerce-product-gallery': [ F ],
	'woocommerce-product-gallery__image': [ F ],
	'woocommerce-product-gallery__wrapper': [ F ],
	'woocommerce-product-details__short-description': [ F ],
	'woocommerce-product-attributes': [ F ],
	shop_attributes: [ F ],
	product_meta: [ F ],
	product_title: [ F ],
	summary: [ F ],
	'akb-editorial-eyebrow--single': [ F ],
	'akb-sticky-atc': [ F ],
	'akb-sticky-atc__btn': [ F ],
	'akb-sticky-atc__price': [ F ],
	'akb-sticky-atc__motivo': [ F ],
	'akb-sticky-atc__title': [ F ],
	'#akb-sticky-atc': [ F ],
	'akb-atc-motivo': [ F ], // pdp-ajax-atc.js
	single_add_to_cart_button: [ F ],
	'comment-form-rating': [ F ],
	'woocommerce-noreviews': [ F ],
	'#reviews': [ F ],
	'#respond': [ F ], // el blog tiene #respond, pero no carga esta hoja.
	related: [ F ],
	'up-sells': [ F ],
	upsells: [ F ],
	'product-faqs': [ F ], // inc/seo-faq.php, woocommerce_after_single_product.
	'product-faq': [ F ],
	'product-faq__q': [ F ],
	'product-faq__a': [ F ],
	pswp: [ F ],
	pswp__bg: [ F ],
	'akb-qty-btn': [ F, C ], // stepper de cantidad: ficha y carrito clásico.
	'akb-qty-btn--plus': [ F, C ],
	'akb-qty-btn--minus': [ F, C ],
	'akb-qty-hint': [ F, C ],

	// Carrito y checkout.
	'woocommerce-cart-form': [ C ],
	cart_item: [ C ],
	cart_totals: [ C ],
	'cart-collaterals': [ C ],
	'cart-subtotal': [ C ],
	'order-total': [ C ],
	'cross-sells': [ C ],
	'product-remove': [ C ],
	'product-thumbnail': [ C ],
	'product-price': [ C ],
	'product-subtotal': [ C ],
	coupon: [ C ],
	'#coupon_code': [ C ],
	'woocommerce-remove-coupon': [ C ],
	checkout_coupon: [ C ],
	'wc-proceed-to-checkout': [ C ],
	'checkout-button': [ C ],
	checkout: [ C ],
	'woocommerce-shipping-methods': [ C ],
	'#shipping_method': [ C ],
	'#order_review': [ C ],
	'woocommerce-checkout-review-order': [ C ],
	'woocommerce-checkout-review-order-table': [ C ],
	'woocommerce-checkout-section': [ C ],
	'#payment': [ C ],
	payment_box: [ C ],
	'place-order': [ C ],
	'#place_order': [ C ],
	'wc-block-components-address-form__address_2-toggle': [ C ],

	// Detalle de un pedido: «pedido recibido» y Mi cuenta → Ver pedido.
	'woocommerce-order': [ C, A ],
	'woocommerce-order-overview': [ C, A ],
	'woocommerce-thankyou-order-received': [ C, A ],
	'woocommerce-order-details': [ C, A ],
	'woocommerce-order-details__title': [ C, A ],
	'woocommerce-customer-details': [ C, A ],
	'woocommerce-column__title': [ C, A ],
	'woocommerce-bacs-bank-details': [ C, A ],
	'wc-bacs-bank-details': [ C, A ],
	'wc-bacs-bank-details-heading': [ C, A ],
	'wc-bacs-bank-details-account-name': [ C, A ],
	'col2-set': [ C, A ],
	'product-name': [ C, A ],
	'product-quantity': [ C, A ],
	'woocommerce-verification-required': [ C, A ],
	'akb-timeline': [ C, A ], // OrderTimeline (business).
	'akb-timeline--terminal': [ C, A ],
	'wc-correios-tracking__table': [ C, A ], // woocommerce/myaccount/tracking-codes.php

	// Formularios de cuenta (Mi cuenta; el login/registro también puede salir en el
	// checkout clásico, así que valen para los dos).
	'select2-container--default': [ C, A ], // selectWoo: país/región del checkout y de Mis direcciones.
	'select2-dropdown': [ C, A ],
	'select2-results__option': [ C, A ],
	'select2-search--dropdown': [ C, A ],
	'woocommerce-form-login': [ C, A ],
	'woocommerce-form-register': [ C, A ],
	'woocommerce-password-strength': [ C, A ],
	'woocommerce-password-hint': [ C, A ],
	'show-password-input': [ C, A ],
	lost_password: [ C, A ],
	'woocommerce-LostPassword': [ C, A ],
	'woocommerce-ResetPassword': [ A ],
	'akb-account-auth': [ C, A ], // templates/myaccount/form-login.php (business)
	'akb-account-disclosure': [ C, A ],
	'akb-account-divider': [ C, A ],
	'akb-google-login-wrap': [ C, A ],
	'wp_google_login__button': [ C, A ],
	'akb-pwd-strength': [ A ], // MyAccountUx (business): editar cuenta.

	// Mi cuenta (templates/myaccount/* de business).
	'woocommerce-MyAccount-navigation': [ A ],
	'woocommerce-MyAccount-content': [ A ],
	'woocommerce-EditAccountForm': [ A ],
	'woocommerce-Address': [ A ],
	'woocommerce-Address-title': [ A ],
	'woocommerce-Addresses': [ A ],
	'woocommerce-orders-table': [ A ],
	'u-columns': [ A ],
	'akb-dashboard': [ A ],
	'akb-dashboard__card': [ A ],
	'akb-orders': [ A ],
	'akb-orders-card': [ A ],
	'akb-orders-claim': [ A ], // GuestOrderLinking
	'akb-orders-empty': [ A ],
	'akb-orders-pagination': [ A ],
	'akb-addresses': [ A ],
	'akb-address-card': [ A ],
	'akb-address-intro': [ A ],
	'akb-edit-account': [ A ],

	// Listados (barra de la tienda, facetas, cabecera del archivo).
	'woocommerce-products-header': [ L ],
	'woocommerce-products-header__title': [ L ],
	'woocommerce-result-count': [ L ],
	'woocommerce-ordering': [ L ],
	'akb-shop-toolbar': [ L ],
	'akb-filter-toggle': [ L ],
	'akb-facets': [ L ],
	'term-description': [ L ],
	'archive-description': [ L ],
};

/** Prefijos de marca: toda clase que empiece así pertenece al componente. */
const PREFIJOS = [
	[ 'akb-sticky-atc__', [ F ] ],
	[ 'woocommerce-MyAccount-', [ A ] ],
	[ 'woocommerce-orders-table__', [ A ] ],
	[ 'akb-dashboard__', [ A ] ],
	[ 'akb-orders-', [ A ] ],
	[ 'akb-address-card__', [ A ] ],
	[ 'akb-edit-account__', [ A ] ],
	[ 'akb-account-auth__', [ C, A ] ],
	[ 'akb-account-disclosure__', [ C, A ] ],
	[ 'akb-timeline', [ C, A ] ],
	[ 'akb-pwd-strength', [ A ] ],
	[ 'wc-bacs-bank-details', [ C, A ] ],
	[ 'woocommerce-thankyou-', [ C, A ] ],
	[ 'akb-shop-toolbar', [ L ] ],
	[ 'akb-filter-toggle', [ L ] ],
	[ 'select2-', [ C, A ] ],
];

/**
 * Parte una hoja en reglas conservando el texto original de cada una.
 *
 * Sólo lo necesario para decidir por selector: reglas, `@media`/`@supports`/
 * `@container`/`@layer` (con hijos) y bloques atómicos (`@keyframes`, `@font-face`).
 * Respeta comentarios, cadenas y paréntesis (una `url(data:…)` con `{` no rompe).
 *
 * @param {string} css
 * @param {number} [desde]
 * @param {number} [hasta]
 * @returns {Array<{tipo:'regla'|'grupo'|'sentencia', preludio:string, inicio:number, fin:number, cuerpo?:[number,number], hijos?:any[]}>}
 */
export function partirCss( css, desde = 0, hasta = css.length ) {
	const items = [];
	let i = desde;

	const saltarAtomo = ( j ) => {
		const c = css[ j ];
		if ( '"' === c || "'" === c ) {
			j++;
			while ( j < hasta && css[ j ] !== c ) {
				j += '\\' === css[ j ] ? 2 : 1;
			}
			return j + 1;
		}
		if ( '/' === c && '*' === css[ j + 1 ] ) {
			const e = css.indexOf( '*/', j + 2 );
			if ( e < 0 || e >= hasta ) {
				throw new Error( `comentario sin cerrar en el byte ${ j }` );
			}
			return e + 2;
		}
		return -1;
	};

	for ( ;; ) {
		// Espacios y comentarios entre reglas.
		for ( ;; ) {
			while ( i < hasta && /\s/.test( css[ i ] ) ) {
				i++;
			}
			if ( i < hasta && '/' === css[ i ] && '*' === css[ i + 1 ] ) {
				i = saltarAtomo( i );
				continue;
			}
			break;
		}
		if ( i >= hasta ) {
			break;
		}

		const inicio = i;
		let j = i;
		let parentesis = 0;
		while ( j < hasta ) {
			const k = saltarAtomo( j );
			if ( k >= 0 ) {
				j = k;
				continue;
			}
			const c = css[ j ];
			if ( '(' === c ) {
				parentesis++;
			} else if ( ')' === c ) {
				parentesis--;
			} else if ( 0 === parentesis && ( '{' === c || ';' === c ) ) {
				break;
			}
			j++;
		}
		if ( j >= hasta ) {
			throw new Error( `regla sin cerrar desde el byte ${ inicio }` );
		}
		const preludio = css.slice( inicio, j ).trim();

		if ( ';' === css[ j ] ) {
			items.push( { tipo: 'sentencia', preludio, inicio, fin: j + 1 } );
			i = j + 1;
			continue;
		}

		let profundidad = 1;
		let k = j + 1;
		while ( k < hasta && profundidad > 0 ) {
			const a = saltarAtomo( k );
			if ( a >= 0 ) {
				k = a;
				continue;
			}
			if ( '{' === css[ k ] ) {
				profundidad++;
			} else if ( '}' === css[ k ] ) {
				profundidad--;
			}
			k++;
		}
		if ( 0 !== profundidad ) {
			throw new Error( `bloque sin cerrar desde el byte ${ inicio }` );
		}

		const item = { tipo: 'regla', preludio, inicio, fin: k, cuerpo: [ j + 1, k - 1 ] };
		if ( /^@(media|supports|container|layer)\b/i.test( preludio ) ) {
			item.tipo = 'grupo';
			item.hijos = partirCss( css, j + 1, k - 1 );
		}
		items.push( item );
		i = k;
	}

	return items;
}

/**
 * Parte una lista de selectores por las comas de primer nivel.
 *
 * @param {string} lista
 * @returns {string[]}
 */
export function partirSelectores( lista ) {
	const partes = [];
	let nivel = 0;
	let actual = '';
	for ( let i = 0; i < lista.length; i++ ) {
		const c = lista[ i ];
		if ( '(' === c || '[' === c ) {
			nivel++;
		} else if ( ')' === c || ']' === c ) {
			nivel--;
		}
		if ( ',' === c && 0 === nivel ) {
			partes.push( actual.trim() );
			actual = '';
			continue;
		}
		actual += c;
	}
	if ( actual.trim() ) {
		partes.push( actual.trim() );
	}
	return partes;
}

/**
 * Quita de un selector lo que NO obliga a que una clase esté presente.
 *
 * `:not(.x)` dice lo contrario de «hay .x»; `:is()`, `:where()` y `:has()` son
 * listas (basta una rama) y los atributos (`[class*=…]`) no son clases. Quitarlos
 * sólo puede hacer el selector MÁS amplio, que es el lado seguro.
 *
 * @param {string} selector
 * @returns {string}
 */
function sinCondicionesOpcionales( selector ) {
	let s = selector;
	let previo;
	do {
		previo = s;
		s = s.replace( /:(not|is|where|has|matches|-webkit-any|-moz-any)\([^()]*\)/g, '' );
	} while ( s !== previo );
	return s.replace( /\[[^\]]*\]/g, '' );
}

/**
 * Contextos donde un selector puede casar.
 *
 * Cada marca presente restringe (intersección). Sin marcas → los cuatro. Si dos
 * marcas no comparten ningún contexto el selector no casaría en ninguna página,
 * pero eso lo decide quien lo escribió, no el build: se deja en los cuatro y se
 * informa, para no borrar en silencio una regla que alguien cree viva.
 *
 * @param {string} selector
 * @returns {{contextos: string[], conflicto: boolean}}
 */
export function contextosDeSelector( selector ) {
	const limpio = sinCondicionesOpcionales( selector );
	let permitidos = new Set( WC_CONTEXTOS );
	let marcado = false;

	const aplicar = ( lista ) => {
		marcado = true;
		permitidos = new Set( [ ...permitidos ].filter( ( c ) => lista.includes( c ) ) );
	};

	for ( const m of limpio.matchAll( /([.#])(-?[_a-zA-Z][\w-]*)/g ) ) {
		const token = '#' === m[ 1 ] ? `#${ m[ 2 ] }` : m[ 2 ];
		if ( MARCAS[ token ] ) {
			aplicar( MARCAS[ token ] );
			continue;
		}
		if ( '.' === m[ 1 ] ) {
			const prefijo = PREFIJOS.find( ( [ p ] ) => token.startsWith( p ) );
			if ( prefijo ) {
				aplicar( prefijo[ 1 ] );
			}
		}
	}
	// `div.product` es el envoltorio de la ficha; en los listados el producto es
	// `li.product` y en el armador/vista rápida, clases propias.
	if ( /(^|[\s>+~])div\.product(?![\w-])/.test( limpio ) ) {
		aplicar( [ F ] );
	}

	if ( marcado && 0 === permitidos.size ) {
		return { contextos: [ ...WC_CONTEXTOS ], conflicto: true };
	}
	return { contextos: WC_CONTEXTOS.filter( ( c ) => permitidos.has( c ) ), conflicto: false };
}

/**
 * Contextos de una regla: la unión de los de sus selectores (basta que uno case).
 *
 * @param {string} preludio
 * @returns {{contextos: string[], conflictos: string[]}}
 */
export function contextosDeRegla( preludio ) {
	if ( preludio.startsWith( '@' ) ) {
		// @keyframes, @font-face, @page…: sin selector de página; a todos.
		return { contextos: [ ...WC_CONTEXTOS ], conflictos: [] };
	}
	const union = new Set();
	const conflictos = [];
	for ( const sel of partirSelectores( preludio ) ) {
		const r = contextosDeSelector( sel );
		r.contextos.forEach( ( c ) => union.add( c ) );
		if ( r.conflicto ) {
			conflictos.push( sel );
		}
	}
	return { contextos: WC_CONTEXTOS.filter( ( c ) => union.has( c ) ), conflictos };
}

/**
 * Reparte una hoja por contexto conservando el orden original de las reglas.
 *
 * @param {string} css
 * @returns {{hojas: Record<string, string>, reglas: Record<string, number>, total: number, conflictos: string[]}}
 */
export function partirPorContexto( css ) {
	const hojas = Object.fromEntries( WC_CONTEXTOS.map( ( c ) => [ c, [] ] ) );
	const reglas = Object.fromEntries( WC_CONTEXTOS.map( ( c ) => [ c, 0 ] ) );
	const conflictos = [];
	let total = 0;

	const emitir = ( lista ) => {
		const salida = Object.fromEntries( WC_CONTEXTOS.map( ( c ) => [ c, [] ] ) );
		for ( const item of lista ) {
			if ( 'grupo' === item.tipo ) {
				const dentro = emitir( item.hijos );
				for ( const c of WC_CONTEXTOS ) {
					if ( dentro[ c ].length ) {
						salida[ c ].push( `${ item.preludio }{${ dentro[ c ].join( '\n' ) }}` );
					}
				}
				continue;
			}
			const texto = css.slice( item.inicio, item.fin );
			if ( 'sentencia' === item.tipo ) {
				WC_CONTEXTOS.forEach( ( c ) => salida[ c ].push( texto ) );
				continue;
			}
			total++;
			const r = contextosDeRegla( item.preludio );
			conflictos.push( ...r.conflictos );
			for ( const c of r.contextos ) {
				salida[ c ].push( texto );
				reglas[ c ]++;
			}
		}
		return salida;
	};

	const raiz = emitir( partirCss( css ) );
	for ( const c of WC_CONTEXTOS ) {
		hojas[ c ] = raiz[ c ].join( '\n' );
	}
	return { hojas, reglas, total, conflictos };
}

/**
 * Primeros 16 hex del sha256.
 *
 * @param {string|Buffer} contenido
 * @returns {string}
 */
export function huella( contenido ) {
	return createHash( 'sha256' ).update( contenido ).digest( 'hex' ).slice( 0, 16 );
}

/** Huella de ESTE script: si cambia el generador, todo lo generado queda viejo. */
export function huellaGenerador() {
	return huella( readFileSync( fileURLToPath( import.meta.url ) ) );
}

/**
 * Cabecera de un fichero generado. tests/min-assets.test.js (tema) la lee.
 *
 * @param {string} fuente   Nombre del fichero de origen (p. ej. `loop.css`).
 * @param {string} hFuente  huella() de la fuente.
 * @param {string} hGen     huellaGenerador().
 * @param {string} [contexto]
 * @returns {string}
 */
export function cabecera( fuente, hFuente, hGen, contexto = '' ) {
	const ctx = contexto ? ` contexto:${ contexto }` : '';
	return `/*! akibara-gp GENERADO, no editar: ${ fuente } sha256:${ hFuente }${ ctx } gen:${ hGen } (tools/akibara-releases/build-theme-core-css.sh) */\n`;
}

/** Binario de esbuild, comprobando la versión. */
function esbuild() {
	let bin = process.env.AKB_ESBUILD || '';
	if ( ! bin ) {
		bin = execFileSync( 'npx', [ '--offline', '--yes', `--package=esbuild@${ ESBUILD_VERSION }`, '-c', 'command -v esbuild' ], { encoding: 'utf8' } ).trim();
	}
	const version = execFileSync( bin, [ '--version' ], { encoding: 'utf8' } ).trim();
	if ( ESBUILD_VERSION !== version ) {
		throw new Error( `esbuild ${ version } en ${ bin }; hace falta ${ ESBUILD_VERSION } (la salida debe ser reproducible).` );
	}
	return bin;
}

function minificar( bin, texto, tipo, destino = 'es5' ) {
	const args = 'css' === tipo
		? [ '--loader=css', '--minify-whitespace', '--legal-comments=none', '--log-level=warning' ]
		: [ '--loader=js', '--minify', `--target=${ destino }`, '--legal-comments=none', '--log-level=warning' ];
	return execFileSync( bin, args, { input: texto, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 } ).trim() + '\n';
}

function escribir( ruta, contenido ) {
	const tmp = `${ ruta }.tmp-${ process.pid }`;
	writeFileSync( tmp, contenido );
	chmodSync( tmp, 0o644 );
	renameSync( tmp, ruta );
}

function main() {
	const bin = esbuild();
	const hGen = huellaGenerador();
	const css = join( TEMA, 'assets', 'css' );
	const js = join( TEMA, 'assets', 'js' );
	const informe = [];

	for ( const nombre of [ ...CSS_MIN, ...CSS_MIN_BUSINESS ] ) {
		const fuente = readFileSync( join( css, `${ nombre }.css` ), 'utf8' );
		const salida = cabecera( `${ nombre }.css`, huella( fuente ), hGen ) + minificar( bin, fuente, 'css' );
		escribir( join( css, `${ nombre }.min.css` ), salida );
		informe.push( [ `${ nombre }.min.css`, fuente.length, salida.length ] );
	}

	for ( const nombre of [ ...JS_MIN, ...JS_MIN_BUSINESS ] ) {
		const fuente = readFileSync( join( js, `${ nombre }.js` ), 'utf8' );
		const salida = cabecera( `${ nombre }.js`, huella( fuente ), hGen ) + minificar( bin, fuente, 'js', JS_DESTINO[ nombre ] );
		escribir( join( js, `${ nombre }.min.js` ), salida );
		informe.push( [ `${ nombre }.min.js`, fuente.length, salida.length ] );
	}

	const wc = readFileSync( join( css, 'woocommerce.css' ), 'utf8' );
	const partido = partirPorContexto( wc );
	if ( partido.conflictos.length ) {
		process.stderr.write( `Aviso: selectores con marcas de contextos incompatibles (van a todos):\n  ${ partido.conflictos.join( '\n  ' ) }\n` );
	}
	for ( const ctx of WC_CONTEXTOS ) {
		const salida = cabecera( 'woocommerce.css', huella( wc ), hGen, ctx ) + minificar( bin, partido.hojas[ ctx ], 'css' );
		escribir( join( css, `woocommerce-${ ctx }.min.css` ), salida );
		informe.push( [ `woocommerce-${ ctx }.min.css (${ partido.reglas[ ctx ] }/${ partido.total } reglas)`, wc.length, salida.length ] );
	}

	for ( const [ nombre, antes, despues ] of informe ) {
		process.stdout.write( `${ nombre }: ${ antes } → ${ despues } bytes\n` );
	}
}

if ( process.argv[ 1 ] && import.meta.url === pathToFileURL( process.argv[ 1 ] ).href ) {
	if ( ! existsSync( TEMA ) ) {
		process.stderr.write( `No encuentro el tema en ${ TEMA }\n` );
		process.exit( 1 );
	}
	main();
}
