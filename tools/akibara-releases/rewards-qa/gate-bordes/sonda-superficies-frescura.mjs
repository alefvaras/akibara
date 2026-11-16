/**
 * sonda-superficies-frescura.mjs — CASO 5 del gate de SUPERFICIES Y CACHÉ.
 *
 * «Verificar la superficie servida»: el bundle `.min`, el `?ver` y la copia de
 * LiteSpeed pueden dejar al cliente con código viejo. Esta sonda no mira el
 * árbol: navega con un navegador REAL, apunta cada recurso first-party que el
 * navegador PIDE, y lo contrasta contra `public_html/`.
 *
 * Checks:
 *   5.1 `?ver` = <versión>.<filemtime>. El filemtime servido tiene que ser el
 *       del fichero en disco, bundle por bundle. Nombrados a mano por el gate:
 *       main.js del tema y cart-checkout-blocks.js.
 *   5.2 Identidad de bytes: SHA-1 de lo servido == SHA-1 del fichero. Un `?ver`
 *       correcto sobre un cuerpo viejo es exactamente la trampa documentada.
 *   5.3 Nada 4xx/5xx entre los recursos first-party (una copia de LiteSpeed
 *       purgada devuelve 404 y deja la página muerta).
 *   5.4 LiteSpeed sirve TODO el CSS minificado desde /wp-content/litespeed/css/.
 *       Para cada fuente first-party que de verdad está cargada en la página
 *       (solape de selectores >= 90 %) se comprueba que la copia servida
 *       contiene TODOS los selectores `.akb*` de la fuente ACTUAL: una copia
 *       construida antes de la última edición se delata por los que faltan.
 *   5.5 `admin-panel.css` y el resto de CSS de administración NO viajan al
 *       front en ninguna de las páginas medidas.
 *   5.6 El sello de LiteSpeed («Page supported by … on <fecha>») no puede ser
 *       ANTERIOR al fichero first-party más nuevo que esa misma página
 *       referencia: si lo fuera, el HTML cacheado estaría sirviendo `?ver`
 *       caducados.
 *
 * LÍMITE DEL ESPEJO — leer antes de interpretar 5.6: en el Docker local la
 * caché de PÁGINA de LiteSpeed no está guardando HTML (el plugin necesita
 * LiteSpeed/OpenLiteSpeed; aquí sólo corre el optimizador). Medido: tres
 * peticiones seguidas a /tienda/ devolvieron sellos 02:27:44 / 02:27:45 /
 * 02:27:45, es decir HTML regenerado, y no hay cabecera `x-litespeed-cache`.
 * Así que 5.6 pasa siempre por construcción en LOCAL —vale como candado para
 * producción, donde el sello sí se congela— y la mitad del riesgo que de
 * verdad se mide aquí es la de los ASSETS (5.1, 5.2 y sobre todo 5.4: las
 * copias minificadas de /wp-content/litespeed/css/ SÍ persisten en disco y sí
 * pueden quedarse viejas).
 *
 * Sólo lee: no siembra, no purga cachés, no toca términos.
 *
 * Uso: node sonda-superficies-frescura.mjs   (exit != 0 si algo falla)
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { BASE, ESCRITORIO, abrir, crearMarcador, RAIZ_REPO } from './lib-bordes.mjs';

const m = crearMarcador( 'superficies-frescura' );
const DOCROOT = path.join( RAIZ_REPO, 'public_html' );

const PAGINAS = [
	'/',
	'/tienda/',
	'/product/frieren-1-ivrea-argentina/',
	'/carrito/',
	'/mi-cuenta/',
	'/editoriales/',
];

/** Bundles que el gate nombra explícitamente, con la página donde deben salir. */
const EXIGIDOS = [
	{ pagina: '/tienda/', ruta: '/wp-content/themes/akibara-gp/assets/js/main.js' },
	{ pagina: '/carrito/', ruta: '/wp-content/plugins/akibara-cart-checkout-blocks/assets/js/cart-checkout-blocks.js' },
];

const esFirstParty = ( ruta ) =>
	/^\/wp-content\/(themes\/akibara-gp|plugins\/akibara-[a-z-]+|mu-plugins)\//.test( ruta );

const sinComentarios = ( t ) => t.replace( /\/\*[\s\S]*?\*\//g, ' ' );
const selectores = ( txt ) =>
	new Set(
		[ ...sinComentarios( txt ).matchAll( /\.(akb[a-z0-9_-]{3,}|akibara[a-z0-9_-]{2,})/gi ) ].map( ( x ) =>
			x[ 1 ].toLowerCase()
		)
	);

const sha1 = ( buf ) => crypto.createHash( 'sha1' ).update( buf ).digest( 'hex' ).slice( 0, 12 );

/** Fuentes CSS first-party del árbol. */
function fuentesCss() {
	const dirs = [
		'wp-content/themes/akibara-gp/assets/css',
		'wp-content/plugins/akibara-business/assets/css',
		'wp-content/plugins/akibara-search/assets/css',
		'wp-content/plugins/akibara-cart-checkout-blocks/assets/css',
		'wp-content/plugins/akibara-rewards/assets/css',
	];
	const out = [];
	for ( const d of dirs ) {
		const abs = path.join( DOCROOT, d );
		if ( ! fs.existsSync( abs ) ) {
			continue;
		}
		for ( const f of fs.readdirSync( abs ) ) {
			if ( f.endsWith( '.css' ) ) {
				out.push( path.join( abs, f ) );
			}
		}
	}
	return out;
}

let navegador = null;

try {
	const sesion = await abrir( ESCRITORIO );
	navegador = sesion.navegador;
	const pagina = sesion.pagina;

	const pedidos = new Map(); // ruta -> { query, status, pagina }
	pagina.on( 'response', ( r ) => {
		const u = new URL( r.url() );
		if ( u.origin !== new URL( BASE ).origin ) {
			return;
		}
		if ( ! pedidos.has( u.pathname + u.search ) ) {
			pedidos.set( u.pathname + u.search, { pathname: u.pathname, search: u.search, status: r.status() } );
		}
	} );

	const sellos = {};
	const cssServidas = new Set();
	const htmlPorPagina = {};
	const crudoPorPagina = {};

	for ( const p of PAGINAS ) {
		await pagina.goto( BASE + p, { waitUntil: 'load' } );
		await pagina.waitForTimeout( 2200 );
		const info = await pagina.evaluate( () => ( {
			html: document.documentElement.outerHTML,
			css: [ ...document.querySelectorAll( 'link[rel="stylesheet"]' ) ].map( ( l ) => l.getAttribute( 'href' ) ),
		} ) );
		htmlPorPagina[ p ] = info.html;
		info.css.forEach( ( h ) => cssServidas.add( h ) );
		// El sello de LiteSpeed va DESPUÉS de `</html>`, así que
		// `documentElement.outerHTML` no lo contiene y el check salía siempre
		// «(sin sello)» — un check que no puede fallar (visto el 2026-09-01).
		// Hay que leer el cuerpo CRUDO que manda el servidor.
		const crudo = await ( await fetch( BASE + p ) ).text();
		crudoPorPagina[ p ] = crudo;
		// El grupo tiene que acotar la fecha: `[0-9: -]+` se tragaba el `--` del
		// cierre del comentario y `Date.parse` devolvía NaN, así que el desfase se
		// saltaba en silencio — otro check incapaz de fallar (2026-09-01).
		const sello = /Page supported by LiteSpeed Cache [^ ]+ on (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/.exec( crudo );
		sellos[ p ] = sello ? sello[ 1 ].trim() : '(sin sello)';
	}

	/* ------------------------------------------------------------------
	 * 5.1 + 5.2 `?ver` y bytes de cada recurso first-party pedido.
	 * --------------------------------------------------------------- */
	const conVer = [];
	const desfasados = [];
	const distintos = [];
	const rotos = [];
	const sinFichero = [];

	for ( const [ , info ] of pedidos ) {
		if ( ! esFirstParty( info.pathname ) ) {
			continue;
		}
		if ( info.status >= 400 ) {
			rotos.push( `${ info.status } ${ info.pathname }` );
		}
		const disco = path.join( DOCROOT, info.pathname.replace( /^\/+/, '' ) );
		if ( ! fs.existsSync( disco ) ) {
			sinFichero.push( info.pathname );
			continue;
		}
		if ( ! /\.(js|css)$/.test( info.pathname ) ) {
			continue;
		}

		const mtime = Math.floor( fs.statSync( disco ).mtimeMs / 1000 );
		const ver = /[?&]ver=([^&]+)/.exec( info.search );
		if ( ver ) {
			const cola = ver[ 1 ].split( '.' ).pop();
			conVer.push( `${ path.basename( info.pathname ) }?ver=${ ver[ 1 ] } | filemtime=${ mtime }` );
			if ( String( cola ) !== String( mtime ) ) {
				desfasados.push( `${ info.pathname } servido ?ver=${ ver[ 1 ] } (cola ${ cola }) vs filemtime ${ mtime } — desfase ${ mtime - Number( cola ) }s` );
			}
		}

		const r = await fetch( BASE + info.pathname + info.search );
		const cuerpo = Buffer.from( await r.arrayBuffer() );
		const enDisco = fs.readFileSync( disco );
		if ( sha1( cuerpo ) !== sha1( enDisco ) ) {
			distintos.push(
				`${ info.pathname }: servido ${ cuerpo.length }B sha1=${ sha1( cuerpo ) } vs disco ${ enDisco.length }B sha1=${ sha1( enDisco ) }`
			);
		}
	}

	m.check(
		'todos-los-ver-first-party-llevan-el-filemtime-actual',
		desfasados.length === 0 && conVer.length > 0,
		`${ conVer.length } recursos con ?ver comprobados, ${ desfasados.length } desfasados${ desfasados.length ? ' :: ' + desfasados.join( ' || ' ) : '' }`
	);

	m.check(
		'el-cuerpo-servido-es-byte-a-byte-el-del-arbol',
		distintos.length === 0,
		`${ distintos.length } diferencias${ distintos.length ? ' :: ' + distintos.join( ' || ' ) : ' (SHA-1 de los ' + conVer.length + ' assets coincide con public_html/)' }`
	);

	m.check(
		'ningun-recurso-first-party-responde-4xx-5xx',
		rotos.length === 0,
		`${ rotos.length } rotos${ rotos.length ? ' :: ' + rotos.slice( 0, 8 ).join( ' || ' ) : '' }`
	);

	/* ------------------------------------------------------------------
	 * Los bundles que el gate nombra, uno a uno y con su número.
	 * --------------------------------------------------------------- */
	for ( const { pagina: pg, ruta } of EXIGIDOS ) {
		const html = htmlPorPagina[ pg ] || '';
		const encontrado = new RegExp( ruta.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\?ver=([^"\']+)' ).exec( html );
		const mtime = Math.floor( fs.statSync( path.join( DOCROOT, ruta.replace( /^\/+/, '' ) ) ).mtimeMs / 1000 );
		m.check(
			`bundle-${ path.basename( ruta ) }-servido-fresco-en-${ pg }`,
			!! encontrado && String( encontrado[ 1 ].split( '.' ).pop() ) === String( mtime ),
			encontrado
				? `?ver=${ encontrado[ 1 ] } · filemtime=${ mtime } · ${ pg }`
				: `NO aparece en el HTML de ${ pg } (filemtime en disco ${ mtime })`
		);
	}

	/* ------------------------------------------------------------------
	 * 5.4 Copias minificadas de LiteSpeed: ¿traen la fuente ACTUAL?
	 * --------------------------------------------------------------- */
	const textosCss = {};
	for ( const href of cssServidas ) {
		if ( ! href || ! /litespeed\/css\//.test( href ) ) {
			continue;
		}
		const u = new URL( href, BASE );
		textosCss[ u.pathname ] = await ( await fetch( u.href ) ).text();
	}

	const stale = [];
	let comparadas = 0;
	for ( const f of fuentesCss() ) {
		const fuente = selectores( fs.readFileSync( f, 'utf8' ) );
		if ( fuente.size < 5 ) {
			continue;
		}
		let mejor = null;
		let mejorHit = 0;
		for ( const [ ruta, txt ] of Object.entries( textosCss ) ) {
			const servida = selectores( txt );
			const hit = [ ...fuente ].filter( ( x ) => servida.has( x ) ).length;
			if ( hit > mejorHit ) {
				mejorHit = hit;
				mejor = ruta;
			}
		}
		// Solape bajo = esa hoja no está cargada en ninguna de las páginas
		// medidas; no hay nada que comparar y forzarlo daría un falso rojo.
		if ( ! mejor || mejorHit / fuente.size < 0.9 ) {
			continue;
		}
		comparadas++;
		const servida = selectores( textosCss[ mejor ] );
		const faltan = [ ...fuente ].filter( ( x ) => ! servida.has( x ) );
		if ( faltan.length ) {
			stale.push( `${ path.basename( f ) } -> ${ path.basename( mejor ) }: faltan ${ faltan.length }/${ fuente.size } selectores (${ faltan.slice( 0, 6 ).join( ', ' ) })` );
		}
	}

	// En el espejo la optimización CSS de LiteSpeed está APAGADA a propósito
	// (CSS Combine prohibido por política): 0 copias servidas desde
	// /wp-content/litespeed/css/ es el estado esperado y el check con
	// `comparadas > 0` era imposible de pasar aquí (NO-MEDIBLE de la pasada
	// 3). Con copias presentes (prod) el contrato completo sigue vigente.
	if ( Object.keys( textosCss ).length === 0 ) {
		m.info(
			'litespeed-no-sirve-copia-vieja-de-ningun-css-first-party',
			'SKIP medido: 0 ficheros de /wp-content/litespeed/css/ servidos (optimización CSS apagada a propósito en el espejo) — el check aplica solo donde LiteSpeed materializa copias (prod)'
		);
	} else {
	m.check(
		'litespeed-no-sirve-copia-vieja-de-ningun-css-first-party',
		stale.length === 0 && comparadas > 0,
		`${ comparadas } hojas first-party cargadas y comparadas contra su copia minificada, ${ stale.length } desfasadas${ stale.length ? ' :: ' + stale.join( ' || ' ) : '' } · ${ Object.keys( textosCss ).length } ficheros de /wp-content/litespeed/css/ servidos`
	);
	}

	/* ------------------------------------------------------------------
	 * 5.5 CSS de administración: fuera del front.
	 * --------------------------------------------------------------- */
	const ADMIN = [ 'admin-panel.css', 'admin-eda.css', 'admin-search.css', 'home-hero-admin.css', 'series-bundles-admin.css', 'taxonomy-discount-admin.css', 'upsells-admin.css' ];
	const filtrados = [];
	for ( const nombre of ADMIN ) {
		const disco = fuentesCss().find( ( f ) => path.basename( f ) === nombre );
		if ( ! disco ) {
			continue;
		}
		const fuente = selectores( fs.readFileSync( disco, 'utf8' ) );
		if ( fuente.size < 5 ) {
			continue;
		}
		for ( const [ ruta, txt ] of Object.entries( textosCss ) ) {
			const servida = selectores( txt );
			const hit = [ ...fuente ].filter( ( x ) => servida.has( x ) ).length;
			if ( hit / fuente.size >= 0.9 ) {
				filtrados.push( `${ nombre } aparece en ${ path.basename( ruta ) } (${ hit }/${ fuente.size } selectores)` );
			}
		}
		// Y por URL directa, por si viajara sin minificar.
		for ( const [ , info ] of pedidos ) {
			if ( info.pathname.endsWith( '/' + nombre ) ) {
				filtrados.push( `${ nombre } pedido directo en ${ info.pathname }` );
			}
		}
	}
	m.check(
		'el-css-de-administracion-no-viaja-al-front',
		filtrados.length === 0,
		`${ ADMIN.length } hojas de admin buscadas en ${ PAGINAS.length } páginas del front; coincidencias: ${ filtrados.length ? filtrados.join( ' || ' ) : 'ninguna' }`
	);

	/* ------------------------------------------------------------------
	 * 5.6 El HTML cacheado no puede ser más viejo que sus propios assets.
	 * --------------------------------------------------------------- */
	const desfaseHtml = [];
	for ( const p of PAGINAS ) {
		const html = crudoPorPagina[ p ] || '';
		let masNuevo = 0;
		let deQuien = '';
		for ( const mm of html.matchAll( /\/wp-content\/(?:themes\/akibara-gp|plugins\/akibara-[a-z-]+)\/[^"'?]+\.(?:js|css)/g ) ) {
			const disco = path.join( DOCROOT, mm[ 0 ].replace( /^\/+/, '' ) );
			if ( ! fs.existsSync( disco ) ) {
				continue;
			}
			const t = Math.floor( fs.statSync( disco ).mtimeMs / 1000 );
			if ( t > masNuevo ) {
				masNuevo = t;
				deQuien = path.basename( mm[ 0 ] );
			}
		}
		const sello = sellos[ p ];
		// El sello de LiteSpeed viene en hora LOCAL del sitio (America/Santiago),
		// no en UTC: parsearlo con 'Z' lo corría 4 horas y cualquier asset tocado
		// en esa ventana parecía más nuevo que el HTML (falso rojo de la pasada
		// 3). El offset se calcula CONTRA LA ZONA REAL (Intl), no con un literal:
		// Chile cambia de huso el 2026-09-06 y un «-4» clavado volvería a mentir.
		const offsetSitioSeg = ( () => {
			const ahora = new Date();
			const enSantiago = new Date( ahora.toLocaleString( 'en-US', { timeZone: 'America/Santiago' } ) );
			const enUtc = new Date( ahora.toLocaleString( 'en-US', { timeZone: 'UTC' } ) );
			return Math.round( ( enUtc - enSantiago ) / 1000 );
		} )();
		const selloTs = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test( sello ) ? Math.floor( Date.parse( sello.replace( ' ', 'T' ) + 'Z' ) / 1000 ) + offsetSitioSeg : 0;
		if ( sello !== '(sin sello)' && ! selloTs ) {
			desfaseHtml.push( `${ p }: sello ilegible «${ sello }»` );
		}
		if ( selloTs && masNuevo && selloTs < masNuevo ) {
			desfaseHtml.push( `${ p }: HTML sellado ${ sello } (${ selloTs }) < ${ deQuien } ${ masNuevo }` );
		}
	}
	const sinSello = PAGINAS.filter( ( p ) => ! /\d{4}-\d{2}-\d{2}/.test( sellos[ p ] ) );
	m.check(
		'el-html-cacheado-no-es-anterior-a-sus-assets',
		desfaseHtml.length === 0 && sinSello.length === 0,
		( desfaseHtml.length ? 'DESFASE :: ' + desfaseHtml.join( ' || ' ) + ' · ' : '' ) +
			( sinSello.length ? 'SIN SELLO :: ' + sinSello.join( ', ' ) + ' · ' : '' ) +
			PAGINAS.map( ( p ) => `${ p }=${ sellos[ p ] }` ).join( ' · ' )
	);

	m.info( 'assets-first-party-sin-fichero-en-el-arbol', sinFichero.length ? sinFichero.slice( 0, 6 ).join( ', ' ) : 'ninguno' );
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, `EXCEPCIÓN: ${ error.message }` );
} finally {
	if ( navegador ) {
		await navegador.close();
	}
}

process.exit( m.cerrar() );
