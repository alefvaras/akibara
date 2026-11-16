// @ts-check
/**
 * woocommerce.css partida por contexto: ninguna regla PODADA de un contexto puede
 * casar con algo que se pinta en ese contexto (revisión T4-R2, 2026-09-23).
 *
 * Desde el lote T4 cada página baja sólo `woocommerce-<contexto>.min.css`
 * (listado, ficha, carrito o cuenta): el build deja fuera de cada copia las reglas
 * que llevan una MARCA de otro contexto (tabla MARCAS de
 * tools/akibara-releases/build-theme-min-assets.mjs). El reparto es correcto
 * mientras cada clase marcada no salga nunca de su contexto. Si mañana una
 * plantilla o un JS pinta, por ejemplo, el stepper `.akb-qty-btn` en la vista
 * rápida del catálogo, esa regla falta en silencio: node, phpunit y el resto del
 * e2e siguen en verde porque sólo miran nombres de fichero y un puñado de
 * selectores.
 *
 * Aquí se recorren las páginas tipo de cada contexto y sus estados (vista rápida,
 * filtros, cajón del carrito, armador, reseñas, barra fija, carrito lleno y
 * vacío, Mi cuenta con y sin sesión) y, en cada uno, se prueba contra el DOM real
 * cada selector podado para el contexto que la página SIRVE. Antes se le quitan
 * los pseudoelementos y las pseudoclases de estado (`::before`, `:hover`,
 * `:checked`…): basta que el elemento exista para que la regla haga falta.
 *
 * Dos controles impiden que la sonda pase por ciega: cada selector podado tiene
 * que poder consultarse (ninguno se descarta en silencio), y la misma sonda con
 * la lista de OTRO contexto sí encuentra coincidencias en la página.
 *
 * La lista sale del generador y de la fuente del árbol; la cabecera de la hoja
 * servida (`sha256:` de woocommerce.css y `gen:` del generador) prueba que es la
 * misma partición que llega al navegador. Lo que escribe (carrito, sesión del
 * cliente de pruebas) se salta con AKB_SOLO_LECTURA y se deshace al terminar,
 * también si la prueba falla: la sesión de WooCommerce que abrió se borra del
 * espejo en un `afterEach` (revisión T4-R4, soporte/tema-espejo.mjs).
 */

import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import {
	WC_CONTEXTOS,
	contextosDeRegla,
	huella,
	huellaGenerador,
	partirCss,
	partirSelectores,
} from '../../akibara-releases/build-theme-min-assets.mjs';
import { borrarSesionWcAlTerminar, RAIZ, wpEspejo } from './soporte/tema-espejo.mjs';
import { erroresDePagina, productoConBadge, soloLectura } from './soporte/tienda.mjs';

const FUENTE = readFileSync( `${ RAIZ }public_html/wp-content/themes/akibara-gp/assets/css/woocommerce.css`, 'utf8' );

/** Pseudoclases de ESTADO: el elemento existe aunque hoy no esté en ese estado. */
const ESTADOS = [
	'hover', 'focus', 'focus-visible', 'focus-within', 'active', 'visited', 'link', 'any-link',
	'checked', 'disabled', 'enabled', 'placeholder-shown', 'invalid', 'valid', 'user-invalid',
	'user-valid', 'required', 'optional', 'read-only', 'read-write', 'target', 'target-within',
	'indeterminate', 'autofill', 'default', 'in-range', 'out-of-range', 'open', 'closed',
	'popover-open', 'modal', 'fullscreen', 'defined',
	// Pseudoelementos con la sintaxis vieja de un solo «:».
	'before', 'after', 'first-letter', 'first-line', 'placeholder', 'selection', 'marker', 'backdrop',
];
const PSEUDO = new RegExp(
	`::[\\w-]+(?:\\([^()]*\\))?|:-(?:webkit|moz|ms)-[\\w-]+(?:\\([^()]*\\))?|:(?:${ ESTADOS.join( '|' ) })(?![\\w-])`,
	'g'
);

/**
 * El selector que se consulta en el DOM: sin pseudoelementos ni estados.
 *
 * Quitar una condición sólo puede AMPLIAR lo que casa, que es el lado seguro. Un
 * estado suelto (`.a :hover`) pasa a `*`, no a nada, para no cambiar de elemento.
 *
 * @param {string} selector
 * @returns {string}
 */
function paraConsultar( selector ) {
	const atributos = [];
	let s = selector.replace( /\[[^\]]*\]/g, ( m ) => `\u0000${ atributos.push( m ) - 1 }\u0000` );
	s = s.replace( PSEUDO, ( _m, donde, texto ) => ( 0 === donde || /[\s>+~]/.test( texto[ donde - 1 ] ) ? '*' : '' ) );
	let previo;
	do {
		previo = s;
		s = s.replace( /:(?:not|is|where|has|matches)\(\s*\)/g, '' ).replace( /\(\s*,/g, '(' ).replace( /,\s*\)/g, ')' ).replace( /,\s*,/g, ',' );
	} while ( s !== previo );
	s = s.replace( /\u0000(\d+)\u0000/g, ( _m, i ) => atributos[ Number( i ) ] ).trim();
	if ( /[>+~]$/.test( s ) ) {
		s += ' *';
	}
	return s || '*';
}

/**
 * Selectores que el build deja FUERA de la copia de un contexto.
 *
 * @param {string} contexto
 * @returns {{original: string, limpio: string}[]}
 */
function podados( contexto ) {
	const vistos = new Map();
	const recorrer = ( items ) => items.forEach( ( it ) => {
		if ( 'grupo' === it.tipo ) {
			return recorrer( it.hijos );
		}
		if ( 'regla' !== it.tipo || it.preludio.startsWith( '@' ) || contextosDeRegla( it.preludio ).contextos.includes( contexto ) ) {
			return;
		}
		for ( const original of partirSelectores( it.preludio ) ) {
			vistos.set( original, { original, limpio: paraConsultar( original ) } );
		}
	} );
	recorrer( partirCss( FUENTE ) );
	return [ ...vistos.values() ];
}

const PODADOS = Object.fromEntries( WC_CONTEXTOS.map( ( c ) => [ c, podados( c ) ] ) );
const HUELLA_FUENTE = huella( FUENTE );
const HUELLA_GEN = huellaGenerador();

/** @type {Map<string, string>} */
const cabeceras = new Map();

/**
 * Contexto de la hoja que la página SIRVE, leído de su cabecera generada (LiteSpeed
 * conserva los comentarios `/*!` también cuando reescribe la URL).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} etiqueta
 */
async function contextoServido( page, etiqueta ) {
	const href = await page.evaluate( () => document.querySelector( 'link#akibara-woocommerce-css' )?.getAttribute( 'href' ) || '' );
	expect( href, `${ etiqueta }: la página carga woocommerce.css` ).not.toBe( '' );
	const url = new URL( href, page.url() ).href;
	if ( ! cabeceras.has( url ) ) {
		const r = await page.request.get( url );
		expect( r.status(), url ).toBe( 200 );
		cabeceras.set( url, ( await r.text() ).split( '\n', 1 )[ 0 ] );
	}
	const cabecera = /** @type {string} */ ( cabeceras.get( url ) );
	expect( cabecera, `${ etiqueta }: ${ url } es una copia partida` ).toMatch( /^\/\*! akibara-gp GENERADO, no editar: woocommerce\.css / );
	expect( cabecera, `${ etiqueta }: la hoja servida sale de otra woocommerce.css que la del árbol` ).toContain( `sha256:${ HUELLA_FUENTE }` );
	expect( cabecera, `${ etiqueta }: la hoja servida sale de otro generador que el del árbol` ).toContain( `gen:${ HUELLA_GEN }` );
	return /** @type {string} */ ( cabecera.match( / contexto:([a-z]+) / )?.[ 1 ] );
}

/**
 * Selectores de la lista que casan con algo del DOM actual.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} contexto Contexto cuya lista de podados se prueba.
 */
function sondear( page, contexto ) {
	return page.evaluate( ( lista ) => {
		const casan = [];
		const invalidos = [];
		for ( const { original, limpio } of lista ) {
			let nodos;
			try {
				nodos = document.querySelectorAll( limpio );
			} catch {
				invalidos.push( `${ original }  →  ${ limpio }` );
				continue;
			}
			if ( nodos.length ) {
				const n = nodos[ 0 ];
				const clases = n.classList.length ? '.' + [ ...n.classList ].slice( 0, 4 ).join( '.' ) : '';
				casan.push( { selector: original, n: nodos.length, muestra: `<${ n.tagName.toLowerCase() }${ n.id ? '#' + n.id : '' }${ clases }>` } );
			}
		}
		return { casan, invalidos };
	}, PODADOS[ contexto ] );
}

/**
 * Revisa el estado actual: contexto esperado y cero reglas podadas que casen.
 * Acumula en `hallazgos` para ver TODOS al final, no sólo el primero.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} esperado
 * @param {string} etiqueta
 * @param {string[]} hallazgos
 */
async function revisar( page, esperado, etiqueta, hallazgos ) {
	const contexto = await contextoServido( page, etiqueta );
	expect( contexto, `${ etiqueta }: contexto de woocommerce.css` ).toBe( esperado );
	const { casan, invalidos } = await sondear( page, contexto );
	expect( invalidos, `${ etiqueta }: selectores podados que la sonda no sabe consultar (quedarían sin vigilar)` ).toEqual( [] );
	hallazgos.push( ...casan.map( ( c ) => `${ etiqueta } [${ contexto }] «${ c.selector }» casa ×${ c.n }: ${ c.muestra }` ) );
}

/**
 * Control: con la lista de OTRO contexto la sonda sí encuentra algo aquí.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} ajeno
 * @param {string} etiqueta
 */
async function controlNoCiega( page, ajeno, etiqueta ) {
	const { casan } = await sondear( page, ajeno );
	expect( casan.length, `${ etiqueta }: la sonda con la lista de «${ ajeno }» tiene que ver reglas de esta página (si no, está ciega)` ).toBeGreaterThan( 0 );
}

/** @param {import('@playwright/test').Page} page */
async function abrirCajon( page ) {
	await page.locator( '.akb-cart-trigger:visible' ).first().click();
	await expect( page.locator( '#akb-native-cart-drawer' ) ).toHaveClass( /\bakb-open\b/ );
	// El cajón abre con «Cargando...» y pinta después lo que trae la Store API (en el
	// espejo, hasta ~4 s con el carrito vacío): se sondea lo pintado, no el esqueleto.
	await expect( page.locator( '#akb-native-cart-drawer' ).locator( '.akb-cart-empty, .akb-cart-item' ).first() ).toBeAttached( { timeout: 15_000 } );
}

/** @param {import('@playwright/test').Page} page */
async function cerrarCajon( page ) {
	await page.keyboard.press( 'Escape' );
	await expect( page.locator( '#akb-native-cart-drawer' ) ).not.toHaveClass( /\bakb-open\b/ );
}

/**
 * Agrega un tomo disponible por la Store API. No se vacía a mano: la sesión que
 * abre se borra del espejo al terminar la prueba, pase o falle
 * (`borrarSesionWcAlTerminar()`).
 *
 * @param {import('@playwright/test').Page} page
 */
async function agregarTomo( page ) {
	const carrito = await page.request.get( '/wp-json/wc/store/v1/cart' );
	const lista = await ( await page.request.get( '/wp-json/wc/store/v1/products?stock_status=instock&per_page=30' ) ).json();
	const tomo = lista.find( ( p ) => p.is_purchasable && p.is_in_stock && 'simple' === p.type );
	expect( tomo, 'hay un tomo disponible para llenar el carrito' ).toBeTruthy();
	const alta = await page.request.post( '/wp-json/wc/store/v1/cart/add-item', { headers: { Nonce: carrito.headers().nonce }, data: { id: tomo.id, quantity: 1 } } );
	expect( alta.status() ).toBe( 201 );
}

test.describe( 'woocommerce.css partida: lo podado de un contexto no casa en él', () => {
	test.describe.configure( { timeout: 120_000 } );

	/** @type {string[]} */
	let errores;
	test.beforeEach( ( { page } ) => {
		errores = erroresDePagina( page );
	} );
	test.afterEach( () => {
		expect( errores, 'errores de JavaScript en la página' ).toEqual( [] );
	} );
	borrarSesionWcAlTerminar();

	test( 'la partición del árbol deja algo fuera de cada contexto (si no, esta prueba no mide nada)', () => {
		for ( const ctx of WC_CONTEXTOS ) {
			expect( PODADOS[ ctx ].length, ctx ).toBeGreaterThan( 20 );
		}
		// La limpieza de selectores: estados fuera, estructura dentro, nunca vacía.
		expect( paraConsultar( '.a:hover .b::before' ) ).toBe( '.a .b' );
		expect( paraConsultar( '.a :focus-visible' ) ).toBe( '.a *' );
		expect( paraConsultar( '.a:not(:hover) > .b:first-child' ) ).toBe( '.a > .b:first-child' );
		expect( paraConsultar( 'input[type="checkbox"]:checked+label:before' ) ).toBe( 'input[type="checkbox"]+label' );
		expect( paraConsultar( '.a::-webkit-inner-spin-button' ) ).toBe( '.a' );
		expect( paraConsultar( '::selection' ) ).toBe( '*' );
	} );

	test( 'listado: portada y tienda con vista rápida, filtros y cajón', async ( { page }, info ) => {
		const hallazgos = [];
		expect( ( await page.goto( '/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await revisar( page, 'listado', 'portada', hallazgos );

		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await revisar( page, 'listado', 'tienda', hallazgos );
		await controlNoCiega( page, 'ficha', 'tienda' );

		// En el teléfono no hay vista rápida (V[UM-13]; tema, revisión T7-1 D5):
		// su diálogo sólo se revisa donde el cliente puede abrirlo.
		if ( 'movil' !== info.project.name ) {
			const vistaRapida = page.locator( '.akb-qv-trigger:visible' ).first();
			await expect( vistaRapida, 'las tarjetas traen vista rápida' ).toBeVisible();
			await vistaRapida.click();
			await expect( page.locator( 'dialog.akb-qv[open]' ) ).toBeVisible();
			await revisar( page, 'listado', 'tienda + vista rápida', hallazgos );
			await page.keyboard.press( 'Escape' );
			await expect( page.locator( 'dialog.akb-qv[open]' ) ).toHaveCount( 0 );
		}

		// El cajón de facetas es de móvil; en escritorio las facetas ya están a la vista.
		const filtros = page.locator( '.akb-filter-toggle:visible' ).first();
		if ( await filtros.count() ) {
			await filtros.click();
			await expect( filtros ).toHaveAttribute( 'aria-expanded', 'true' );
			await revisar( page, 'listado', 'tienda + filtros', hallazgos );
			await page.keyboard.press( 'Escape' );
			await expect( filtros ).toHaveAttribute( 'aria-expanded', 'false' );
		}

		await abrirCajon( page );
		await revisar( page, 'listado', 'tienda + cajón vacío', hallazgos );
		await cerrarCajon( page );

		if ( ! soloLectura ) {
			await agregarTomo( page );
			await page.reload( { waitUntil: 'load' } );
			await abrirCajon( page );
			await expect( page.locator( '#akb-native-cart-drawer .akb-cart-item' ).first() ).toBeVisible();
			await revisar( page, 'listado', 'tienda + cajón con un tomo', hallazgos );
			await cerrarCajon( page );
		}
		expect( hallazgos ).toEqual( [] );
	} );

	test( 'listado: distrito, serie con el armador, búsqueda con y sin resultados y 404', async ( { page } ) => {
		const hallazgos = [];
		expect( ( await page.goto( '/manga/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await revisar( page, 'listado', 'distrito /manga/', hallazgos );

		expect( ( await page.goto( '/serie/blue-lock/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await revisar( page, 'listado', 'serie', hallazgos );
		expect( await page.locator( 'li.product' ).count(), 'la serie trae tomos' ).toBeGreaterThan( 0 );

		expect( ( await page.goto( '/tienda/?_buscar=one+piece', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		expect( await page.locator( 'li.product' ).count(), 'la búsqueda trae resultados' ).toBeGreaterThan( 0 );
		await revisar( page, 'listado', 'búsqueda con resultados', hallazgos );

		expect( ( await page.goto( '/tienda/?_buscar=zzqqxxnohay', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		// Cero resultados en la rejilla; las tarjetas que salen son «Lo más vendido»
		// del tema DENTRO del aviso (UM-8, lote de integración).
		expect( await page.locator( 'li.product' ).evaluateAll( ( lis ) => lis.filter( ( li ) => ! li.closest( '[data-akb-empty]' ) ).length ) ).toBe( 0 );
		await revisar( page, 'listado', 'búsqueda sin resultados', hallazgos );

		expect( ( await page.goto( '/esta-pagina-no-existe-akbqa/', { waitUntil: 'load' } ) )?.status() ).toBe( 404 );
		await revisar( page, 'listado', '404', hallazgos );
		expect( hallazgos ).toEqual( [] );
	} );

	test( 'ficha: reseñas, barra fija, cajón y armador', async ( { page } ) => {
		const hallazgos = [];
		const disponible = await productoConBadge( page, 'Disponible' );
		expect( disponible, 'hay un tomo disponible en el catálogo' ).toBeTruthy();
		expect( ( await page.goto( /** @type {string} */ ( disponible ), { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await revisar( page, 'ficha', 'ficha disponible', hallazgos );
		await controlNoCiega( page, 'listado', 'ficha' );

		// En móvil las pestañas se apilan y la de reseñas ya está abierta.
		const pestana = page.locator( '#tab-title-reviews a' );
		if ( await pestana.isVisible() ) {
			await pestana.click();
		}
		await expect( page.locator( '#tab-reviews' ) ).toBeVisible();
		await revisar( page, 'ficha', 'ficha + reseñas', hallazgos );

		await page.evaluate( () => window.scrollTo( 0, document.querySelector( 'form.cart' ).getBoundingClientRect().bottom + window.scrollY + 400 ) );
		await expect( page.locator( '#akb-sticky-atc' ) ).toHaveClass( /is-visible/ );
		await revisar( page, 'ficha', 'ficha + barra fija', hallazgos );

		await abrirCajon( page );
		await revisar( page, 'ficha', 'ficha + cajón', hallazgos );
		await cerrarCajon( page );

		if ( ! soloLectura ) {
			await page.evaluate( () => window.scrollTo( 0, 0 ) );
			await page.locator( 'form.cart .single_add_to_cart_button' ).first().click();
			await expect( page.locator( '#akb-native-cart-drawer .akb-cart-item' ).first() ).toBeAttached( { timeout: 15_000 } );
			await revisar( page, 'ficha', 'ficha + tomo agregado', hallazgos );
		}
		expect( hallazgos ).toEqual( [] );
	} );

	test( 'carrito: vacío, lleno y caja', async ( { page } ) => {
		const hallazgos = [];
		expect( ( await page.goto( '/carrito/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await revisar( page, 'carrito', 'carrito vacío', hallazgos );
		await controlNoCiega( page, 'listado', 'carrito vacío' );

		if ( ! soloLectura ) {
			await agregarTomo( page );
			expect( ( await page.goto( '/carrito/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			await expect( page.locator( '.wc-block-cart-items__row, .cart_item' ).first() ).toBeVisible( { timeout: 15_000 } );
			await revisar( page, 'carrito', 'carrito lleno', hallazgos );

			// La URL de la caja, la que ofrece el propio carrito (/checkout/ en el espejo).
			const caja = await page.locator( 'a.wc-block-cart__submit-button, a.checkout-button' ).first().getAttribute( 'href' );
			expect( caja, 'el carrito ofrece ir a pagar' ).toBeTruthy();
			expect( ( await page.goto( /** @type {string} */ ( caja ), { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			await expect( page.locator( '.wc-block-checkout, form.checkout' ).first() ).toBeVisible( { timeout: 15_000 } );
			await revisar( page, 'carrito', 'caja', hallazgos );
		}
		expect( hallazgos ).toEqual( [] );
	} );

	test( 'cuenta sin sesión: entrar, registrarse y recuperar la contraseña', async ( { page } ) => {
		const hallazgos = [];
		expect( ( await page.goto( '/mi-cuenta/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await revisar( page, 'cuenta', 'Mi cuenta sin sesión', hallazgos );
		await controlNoCiega( page, 'listado', 'Mi cuenta sin sesión' );

		const recuperar = page.locator( 'a[href*="lost-password"]' ).first();
		await expect( recuperar ).toBeAttached();
		expect( ( await page.goto( /** @type {string} */ ( await recuperar.getAttribute( 'href' ) ), { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await revisar( page, 'cuenta', 'recuperar contraseña', hallazgos );
		expect( hallazgos ).toEqual( [] );
	} );

	test.describe( 'cuenta con sesión (cliente de pruebas del espejo)', () => {
		test.skip( soloLectura, 'abre una sesión del cliente de pruebas: sólo contra el espejo' );

		/** @type {{id:number,nombre:string,valor:string,token:string}|null} */
		let sesion = null;

		test.beforeAll( async () => {
			// El cliente lo crea la siembra de canje-de-puntos.spec.mjs; aquí sólo se
			// le abre una sesión (crear un usuario dispara hooks de alta: correo, puntos).
			const salida = await wpEspejo( `
				$u = get_user_by( 'login', 'qa_e2e_puntos' );
				if ( ! $u ) { echo '{}'; return; }
				$exp = time() + HOUR_IN_SECONDS;
				$token = WP_Session_Tokens::get_instance( $u->ID )->create( $exp );
				echo wp_json_encode( array( 'id' => $u->ID, 'nombre' => LOGGED_IN_COOKIE, 'valor' => wp_generate_auth_cookie( $u->ID, $exp, 'logged_in', $token ), 'token' => $token ) );
			` );
			const datos = JSON.parse( salida.split( '\n' ).find( ( l ) => l.startsWith( '{' ) ) || '{}' );
			sesion = datos.id ? datos : null;
		} );

		test.afterAll( async () => {
			if ( sesion ) {
				await wpEspejo( `WP_Session_Tokens::get_instance( ${ Number( sesion.id ) } )->destroy( ${ JSON.stringify( sesion.token ) } );` );
			}
		} );

		test( 'escritorio de la cuenta y cada sección del menú', async ( { page, context, baseURL } ) => {
			test.skip( ! sesion, 'no existe el cliente qa_e2e_puntos en el espejo (lo siembra canje-de-puntos.spec.mjs)' );
			const s = /** @type {{nombre:string,valor:string}} */ ( sesion );
			await context.addCookies( [ { name: s.nombre, value: s.valor, domain: new URL( /** @type {string} */ ( baseURL ) ).hostname, path: '/' } ] );
			const hallazgos = [];

			expect( ( await page.goto( '/mi-cuenta/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			await expect( page.locator( '.woocommerce-MyAccount-navigation' ) ).toBeAttached();
			await revisar( page, 'cuenta', 'Mi cuenta: escritorio', hallazgos );

			const secciones = await page.locator( '.woocommerce-MyAccount-navigation a' ).evaluateAll(
				( as ) => [ ...new Set( as.map( ( a ) => a.href ).filter( ( h ) => ! h.includes( 'customer-logout' ) ) ) ]
			);
			expect( secciones.length ).toBeGreaterThan( 3 );
			for ( const url of secciones ) {
				expect( ( await page.goto( url, { waitUntil: 'load' } ) )?.status(), url ).toBe( 200 );
				await revisar( page, 'cuenta', `Mi cuenta: ${ new URL( url ).pathname }`, hallazgos );
				if ( url.includes( '/edit-address/' ) ) {
					const direcciones = await page.locator( '.woocommerce-MyAccount-content a[href*="/edit-address/"]' ).evaluateAll( ( as ) => [ ...new Set( as.map( ( a ) => a.href ) ) ] );
					for ( const formulario of direcciones ) {
						expect( ( await page.goto( formulario, { waitUntil: 'load' } ) )?.status(), formulario ).toBe( 200 );
						await revisar( page, 'cuenta', `Mi cuenta: ${ new URL( formulario ).pathname }`, hallazgos );
					}
				}
			}
			expect( hallazgos ).toEqual( [] );
		} );
	} );
} );
