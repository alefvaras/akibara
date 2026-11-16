/**
 * sonda-superficies-busqueda.mjs — CASO 2 del gate de SUPERFICIES Y CACHÉ.
 *
 * Mide el buscador por la superficie que ve el cliente (panel de sugerencias
 * pintado en el DOM) y por el listado servido, nunca por el status del REST:
 * `suggest` responde 200 con `items: []` cuando el índice está caído, así que
 * un check contra el código HTTP no puede fallar y no sería un check.
 *
 * Casos:
 *   2.1 suggest VIVO: «frieren» pinta items con badge en el panel real
 *   2.2 sin resultados: «zzzxxx» → panel con estado vacío, y el listado
 *       /tienda/?_buscar=zzzxxx con 0 cards y salida digna que FUNCIONA
 *   2.3 mayúsculas «FRIEREN» y typo «freiren» → el fuzzy responde
 *   2.4 tildes: «murio» encuentra «El verano que Hikaru murió»
 *
 * NO guarda ningún término (eso dispara el rebuild del índice → suggest en
 * 503 seis minutos). Sólo lee.
 *
 * Uso: node sonda-superficies-busqueda.mjs   (exit != 0 si algo falla)
 */

import { BASE, MOVIL, abrir, crearMarcador } from './lib-bordes.mjs';

const m = crearMarcador( 'superficies-busqueda' );

/**
 * El search sheet móvil no usa el input de la cabecera.
 *
 * En móvil `#akb-search-input` VIVE en el DOM pero está oculto; la lupa de la
 * bottom-nav abre el drawer en modo búsqueda y enfoca OTRO input,
 * `#akb-mobile-search-input`. Teclear en el de la cabecera agotaba los 30 s
 * contra un elemento presente-pero-invisible (medido el 2026-09-01), así que
 * la sonda resuelve cuál está servido de verdad y usa ese.
 */
const SELECTOR_INPUT = '#akb-mobile-search-input, #akb-search-input';

async function abrirHoja( pagina ) {
	const yaVisible = await pagina.locator( SELECTOR_INPUT ).locator( 'visible=true' ).count();
	if ( yaVisible > 0 ) {
		return 'ya-visible';
	}
	await pagina.click( '#bottom-nav-search' );
	await pagina.waitForSelector( '#akb-mobile-search-input', { state: 'visible', timeout: 15000 } );
	return 'abierta-por-bottom-nav';
}

/** Input REALMENTE servido (el de la hoja en móvil, el del header en escritorio). */
function campo( pagina ) {
	return pagina.locator( SELECTOR_INPUT ).locator( 'visible=true' ).first();
}

/** Abre el buscador y teclea, como el cliente. */
async function teclear( pagina, texto ) {
	await abrirHoja( pagina );
	const input = campo( pagina );
	await input.fill( '' );
	await input.click();
	// La RESPUESTA DE RED de esta consulta es el único asentamiento real: el
	// panel conserva la respuesta anterior mientras vuela la nueva y cualquier
	// espera sobre el DOM puede leer STALE (pasada 6: con la máquina cargada,
	// el estado viejo satisfacía la condición y la fresca llegaba después del
	// timeout fijo). Se arma ANTES de teclear para no perder la respuesta.
	const respuesta = pagina
		.waitForResponse(
			( r ) => r.url().includes( '/search/suggest' )
				&& decodeURIComponent( r.url() ).includes( texto.normalize ? texto.normalize( 'NFC' ) : texto ),
			{ timeout: 20000 }
		)
		.catch( () => null );
	await input.type( texto, { delay: 45 } );
	await respuesta;
	// Esperar «que haya items en algún panel» NO sirve: el panel de la hoja
	// conserva la respuesta ANTERIOR mientras vuela la nueva, así que el
	// selector casaba al instante y se leía el panel viejo — «FRIEREN items=0»
	// con el buscador perfecto (medido el 2026-09-01). Se espera a que el panel
	// del input visible declare ESTA consulta: el marcado del suggest imprime
	// el término dentro del propio panel (título resaltado, vacío o corrección).
	await pagina
		.waitForFunction(
			( q ) => {
				const visto = ( n ) => !! ( n && ( n.offsetWidth || n.offsetHeight || n.getClientRects().length ) );
				const inputs = [ ...document.querySelectorAll( '#akb-mobile-search-input, #akb-search-input' ) ];
				const el = inputs.find( visto ) || inputs[ 0 ];
				if ( ! el || el.value !== q ) {
					return false;
				}
				const panel = document.getElementById( el.getAttribute( 'aria-controls' ) );
				if ( ! panel || panel.hasAttribute( 'hidden' ) ) {
					return false;
				}
				const cargando = panel.querySelector( '.akb-suggest-loading' );
				return ! cargando && !! panel.querySelector(
					'.akb-suggest-item, .akb-suggest-empty, .akb-suggest-correction, .akb-suggest-error'
				);
			},
			texto,
			{ timeout: 20000 }
		)
		.catch( () => {} );
	// Respiro corto SOLO para el paint post-respuesta: la espera real ya fue
	// la respuesta de red de ESTA consulta (armada en teclear()).
	await pagina.waitForTimeout( 250 );
}

/**
 * Fotografía del panel servido.
 *
 * OJO: hay DOS paneles en el DOM, uno por input (cabecera y hoja móvil).
 * `querySelector('.akb-suggest-panel')` devolvía siempre el de la CABECERA
 * —vacío y oculto en móvil— y los cinco checks del suggest «fallaban» con
 * items=0 estando el buscador perfecto (medido el 2026-09-01). El panel bueno
 * es el que el input visible declara en `aria-controls`.
 */
async function leerPanel( pagina ) {
	return pagina.evaluate( () => {
		const visto = ( n ) => !! ( n && ( n.offsetWidth || n.offsetHeight || n.getClientRects().length ) );
		const inputs = [ ...document.querySelectorAll( '#akb-mobile-search-input, #akb-search-input' ) ];
		const input = inputs.find( visto ) || inputs[ 0 ];
		const id = input ? input.getAttribute( 'aria-controls' ) : '';
		const panel = ( id && document.getElementById( id ) ) ||
			[ ...document.querySelectorAll( '.akb-suggest-panel' ) ].find( visto ) ||
			document.querySelector( '.akb-suggest-panel' );
		if ( ! panel ) {
			return { existe: false, panelId: id || '(ninguno)' };
		}
		const visible = !! ( panel.offsetWidth || panel.offsetHeight || panel.getClientRects().length );
		// `.akb-suggest-more` («Ver los N resultados») comparte la clase para
		// heredar foco y flechas, pero es un pseudo-item SIN thumb ni badge:
		// exigirle badge hacía fallar un panel perfecto (medido el 2026-09-01).
		const todos = [ ...panel.querySelectorAll( '.akb-suggest-item' ) ];
		const items = todos.filter( ( i ) => ! i.classList.contains( 'akb-suggest-more' ) );
		const mas = panel.querySelector( '.akb-suggest-more' );
		return {
			existe: true,
			panelId: panel.id,
			nodos: todos.length,
			mas: mas ? ( mas.textContent || '' ).trim() : '',
			inputId: input ? input.id : '(ninguno)',
			visible,
			items: items.length,
			conBadge: items.filter( ( i ) => !! i.querySelector( '.akb-suggest-badge' ) ).length,
			badges: items.slice( 0, 3 ).map( ( i ) => ( i.querySelector( '.akb-suggest-badge' )?.textContent || '' ).trim() ),
			titulos: items.slice( 0, 3 ).map( ( i ) => ( i.querySelector( '.akb-suggest-title' )?.textContent || '' ).trim() ),
			vacio: ( panel.querySelector( '.akb-suggest-empty' )?.textContent || '' ).trim(),
			correccion: ( panel.querySelector( '.akb-suggest-correction' )?.textContent || '' ).trim(),
		};
	} );
}

let navegador = null;

try {
	const sesion = await abrir( MOVIL );
	navegador = sesion.navegador;
	const pagina = sesion.pagina;

	await pagina.goto( `${ BASE }/tienda/`, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForTimeout( 800 );

	const comoAbre = await abrirHoja( pagina );
	const hoja = await pagina.evaluate( () => {
		const i = document.getElementById( 'akb-mobile-search-input' );
		const r = i ? i.getBoundingClientRect() : null;
		const cancelar = document.getElementById( 'akb-search-cancel' );
		const visto = ( n ) => !! ( n && ( n.offsetWidth || n.offsetHeight || n.getClientRects().length ) );
		return {
			top: r ? Math.round( r.top ) : -1,
			cancelar: visto( cancelar ),
			enfocado: document.activeElement === i,
		};
	} );
	m.check(
		'search-sheet-movil-se-abre-con-input-arriba-y-cancelar',
		hoja.top >= 0 && hoja.top < 200 && hoja.cancelar && hoja.enfocado,
		`apertura=${ comoAbre } input.top=${ hoja.top }px cancelar_visible=${ hoja.cancelar } enfocado=${ hoja.enfocado }`
	);

	/* ------------------------------------------------------------------
	 * 2.1 «frieren» → sugerencias con badge, pintadas.
	 * --------------------------------------------------------------- */
	await teclear( pagina, 'frieren' );
	const frieren = await leerPanel( pagina );
	m.check(
		'suggest-vivo-frieren-con-badge',
		!! frieren.existe && frieren.visible && frieren.items > 0 && frieren.conBadge === frieren.items,
		`panel=${ frieren.panelId } input=${ frieren.inputId } nodos=${ frieren.nodos } items=${ frieren.items } con_badge=${ frieren.conBadge } visible=${ frieren.visible } titulos=[${ ( frieren.titulos || [] ).join( ' | ' ) }] badges=[${ ( frieren.badges || [] ).join( ' | ' ) }] pie="${ frieren.mas }"`
	);

	/* ------------------------------------------------------------------
	 * 2.2 Mayúsculas: el mismo resultado que en minúsculas.
	 * --------------------------------------------------------------- */
	await teclear( pagina, 'FRIEREN' );
	const mayus = await leerPanel( pagina );
	m.check(
		'suggest-insensible-a-mayusculas',
		mayus.items === frieren.items && mayus.items > 0,
		`FRIEREN items=${ mayus.items } (frieren=${ frieren.items }) titulos=[${ ( mayus.titulos || [] ).join( ' | ' ) }]`
	);

	/* ------------------------------------------------------------------
	 * 2.3 Typo «freiren»: el fuzzy tiene que responder con producto real.
	 * --------------------------------------------------------------- */
	await teclear( pagina, 'freiren' );
	const typo = await leerPanel( pagina );
	m.check(
		'fuzzy-responde-al-typo-freiren',
		typo.items > 0 && /frieren/i.test( ( typo.titulos || [] ).join( ' ' ) ),
		`items=${ typo.items } titulos=[${ ( typo.titulos || [] ).join( ' | ' ) }] correccion="${ typo.correccion }"`
	);

	/* ------------------------------------------------------------------
	 * 2.4 Tildes: «murio» sin tilde encuentra «murió».
	 * --------------------------------------------------------------- */
	await teclear( pagina, 'murio' );
	const tilde = await leerPanel( pagina );
	m.check(
		'busqueda-insensible-a-tildes',
		tilde.items > 0 && /muri[óo]/i.test( ( tilde.titulos || [] ).join( ' ' ) ),
		`«murio» items=${ tilde.items } titulos=[${ ( tilde.titulos || [] ).join( ' | ' ) }]`
	);

	/* ------------------------------------------------------------------
	 * 2.5 «zzzxxx»: panel con estado vacío ANUNCIADO (role=status).
	 * --------------------------------------------------------------- */
	await teclear( pagina, 'zzzxxx' );
	const nada = await leerPanel( pagina );
	m.check(
		'suggest-sin-resultados-avisa',
		nada.items === 0 && ( nada.vacio.length > 0 || nada.correccion.length > 0 ),
		`items=${ nada.items } vacio="${ nada.vacio }" correccion="${ nada.correccion }"`
	);

	/* ------------------------------------------------------------------
	 * 2.6 Listado de resultados: la superficie servida, contando CARDS.
	 * --------------------------------------------------------------- */
	await pagina.goto( `${ BASE }/?s=frieren&post_type=product`, { waitUntil: 'domcontentloaded' } );
	const listado = await pagina.evaluate( () => ( {
		url: location.pathname + location.search,
		cards: document.querySelectorAll( 'ul.products li.product' ).length,
	} ) );
	m.check(
		'listado-de-busqueda-sirve-cards',
		listado.cards > 0,
		`url=${ listado.url } cards=${ listado.cards }`
	);

	await pagina.goto( `${ BASE }/?s=zzzxxx&post_type=product`, { waitUntil: 'domcontentloaded' } );
	const vacioListado = await pagina.evaluate( () => {
		const est = document.querySelector( '.akb-empty-state' );
		const salidas = est
			? [ ...est.querySelectorAll( 'a[href], button' ) ].map( ( n ) => ( n.textContent || '' ).trim() )
			: [];
		return {
			url: location.pathname + location.search,
			cards: document.querySelectorAll( 'ul.products li.product' ).length,
			titulo: ( est?.querySelector( '.akb-empty-title' )?.textContent || '' ).trim(),
			pista: ( est?.querySelector( '.akb-empty-hint' )?.textContent || '' ).trim(),
			salidas,
			// Salidas del resto de la barra (chip «Quitar filtro», «Limpiar todo»).
			salidasBarra: [ ...document.querySelectorAll( '.akb-shop-toolbar a[href], .akb-shop-toolbar button' ) ]
				.map( ( n ) => ( n.textContent || '' ).trim() )
				.filter( Boolean ),
		};
	} );
	m.check(
		'busqueda-vacia-estado-digno',
		vacioListado.cards === 0 && vacioListado.titulo.length > 0 && vacioListado.salidas.length > 0,
		`cards=${ vacioListado.cards } titulo="${ vacioListado.titulo }" pista="${ vacioListado.pista }" salidas=[${ vacioListado.salidas.join( ' | ' ) }] barra=[${ vacioListado.salidasBarra.join( ' | ' ) }]`
	);

	/* ------------------------------------------------------------------
	 * 2.7 La salida del estado vacío TIENE que devolver el catálogo. Un
	 *     botón que sólo existe no es una salida.
	 * --------------------------------------------------------------- */
	await pagina.click( '.akb-empty-state [data-akb-clear]' );
	await pagina
		.waitForFunction( () => document.querySelectorAll( 'ul.products li.product' ).length > 0, null, { timeout: 20000 } )
		.catch( () => {} );
	const tras = await pagina.evaluate( () => ( {
		url: location.pathname + location.search,
		cards: document.querySelectorAll( 'ul.products li.product' ).length,
	} ) );
	m.check(
		'salida-del-estado-vacio-devuelve-catalogo',
		tras.cards > 0 && ! /zzzxxx/.test( tras.url ),
		`tras el click: url=${ tras.url } cards=${ tras.cards }`
	);
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, `EXCEPCIÓN: ${ error.message }` );
} finally {
	if ( navegador ) {
		await navegador.close();
	}
}

process.exit( m.cerrar() );
