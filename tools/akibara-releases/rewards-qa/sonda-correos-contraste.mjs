/**
 * Sonda de los CUATRO correos del gate, medida sobre lo SERVIDO.
 *
 * No lee el árbol: pide a Mailpit el mensaje tal como salió del transporte,
 * comprueba el multipart en la fuente cruda y pinta el HTML en Chromium para
 * medir el color COMPUTADO del botón — que es donde vivía el defecto:
 * `-webkit-text-fill-color` pisa a `color`, así que un botón «blanco» según el
 * markup se renderiza rojo sobre rojo.
 *
 * Requiere que `enviar-cuatro-correos.php` haya corrido antes (los correos
 * tienen que estar en Mailpit).
 *
 *   cd tools/akibara-releases/rewards-qa && node sonda-correos-contraste.mjs
 */

import { chromium } from 'playwright';

const MAILPIT = 'http://localhost:8025';

/** Tokens de marca. Fuente de verdad: theme.json del tema. */
const ROJO_MARCA = '#e60012';
const ROJO_LEGIBLE = '#cc0010';
const AMARILLO = '#ffd700';

/** Rojos que NO son de marca y no pueden reaparecer. */
const ROJOS_PROHIBIDOS = [ '#cc0000', '#c00000', '#ffc21a', '#8526ff' ];

const CORREOS = [
	{ clave: 'series-follow', asunto: /Salió el tomo/i, cta: /Ver el tomo/i },
	{ clave: 'bis-instock', asunto: /está de vuelta/i, cta: /Comprar ahora/i },
	{ clave: 'compra', asunto: /Hemos recibido tu pedido/i, cta: /Ver mi pedido/i },
	{ clave: 'puntos-vencimiento', asunto: /Puntos Akibara por vencer/i, cta: /Canjear mis puntos/i },
];

const checks = [];
const check = ( nombre, ok, detalle = '' ) => {
	checks.push( { nombre, ok, detalle } );
	console.log( `${ ok ? 'OK  ' : 'FALLA' } ${ nombre }${ detalle ? ' — ' + detalle : '' }` );
};

const api = async ( ruta ) => {
	const r = await fetch( MAILPIT + ruta );
	if ( ! r.ok ) throw new Error( `${ ruta } → ${ r.status }` );
	return r.headers.get( 'content-type' )?.includes( 'json' ) ? r.json() : r.text();
};

/** Luminancia relativa WCAG de un `rgb(r, g, b)`. */
const luminancia = ( rgb ) => {
	const [ r, g, b ] = rgb.match( /\d+(\.\d+)?/g ).slice( 0, 3 ).map( Number );
	const canal = ( c ) => {
		const s = c / 255;
		return s <= 0.03928 ? s / 12.92 : ( ( s + 0.055 ) / 1.055 ) ** 2.4;
	};
	return 0.2126 * canal( r ) + 0.7152 * canal( g ) + 0.0722 * canal( b );
};

const contraste = ( a, b ) => {
	const [ l1, l2 ] = [ luminancia( a ), luminancia( b ) ].sort( ( x, y ) => y - x );
	return ( l1 + 0.05 ) / ( l2 + 0.05 );
};

const main = async () => {
	const listado = await api( '/api/v1/messages?limit=50' );
	const navegador = await chromium.launch();
	const pagina = await navegador.newPage();

	for ( const correo of CORREOS ) {
		const resumen = listado.messages.find( ( m ) => correo.asunto.test( m.Subject ) );
		if ( ! resumen ) {
			check( `${ correo.clave }: el correo llegó a Mailpit`, false, 'no está en la bandeja' );
			continue;
		}
		check( `${ correo.clave }: el correo llegó a Mailpit`, true, resumen.Subject );

		const crudo = await api( `/api/v1/message/${ resumen.ID }/raw` );
		const detalle = await api( `/api/v1/message/${ resumen.ID }` );

		// ── multipart con parte plana legible ──────────────────────────────
		check(
			`${ correo.clave }: Content-Type multipart/alternative`,
			/content-type:\s*multipart\/alternative/i.test( crudo )
		);

		const plano = ( detalle.Text || '' ).trim();
		check( `${ correo.clave }: la parte plana no viene vacía`, plano.length > 40, `${ plano.length } bytes` );
		check(
			`${ correo.clave }: la parte plana NO es HTML disfrazado`,
			! /<(p|div|table|a)\b/i.test( plano )
		);

		const html = detalle.HTML || '';

		// ── mismos enlaces en las dos partes ───────────────────────────────
		const urlsHtml = [ ...html.matchAll( /href="(https?:\/\/[^"]+)"/g ) ].map( ( m ) => m[ 1 ] );
		const urlsPlano = [ ...plano.matchAll( /(https?:\/\/\S+)/g ) ].map( ( m ) => m[ 1 ].replace( /[.,)]+$/, '' ) );
		const normaliza = ( u ) => u.replace( /&amp;/g, '&' ).replace( /\/$/, '' );
		const faltan = urlsPlano.filter( ( u ) => ! urlsHtml.map( normaliza ).includes( normaliza( u ) ) );
		check(
			`${ correo.clave }: los enlaces del plano existen también en el HTML`,
			faltan.length === 0,
			faltan.length ? `huérfanos: ${ faltan.slice( 0, 2 ).join( ', ' ) }` : `${ urlsPlano.length } enlaces`
		);

		// ── paleta: un solo rojo, un solo amarillo ─────────────────────────
		const bajo = html.toLowerCase();
		const intrusos = ROJOS_PROHIBIDOS.filter( ( c ) => bajo.includes( c ) );
		check(
			`${ correo.clave }: ningún color fuera de los tokens de marca`,
			intrusos.length === 0,
			intrusos.length ? `aparecen ${ intrusos.join( ', ' ) }` : `sólo ${ ROJO_MARCA }/${ ROJO_LEGIBLE }/${ AMARILLO }`
		);

		// ── contraste COMPUTADO del CTA ────────────────────────────────────
		await pagina.setContent( html, { waitUntil: 'domcontentloaded' } );

		const medida = await pagina.evaluate( ( patron ) => {
			const re = new RegExp( patron, 'i' );
			const enlaces = [ ...document.querySelectorAll( 'a' ) ];
			const boton = enlaces.find( ( a ) => re.test( ( a.textContent || '' ).trim() ) );
			if ( ! boton ) return null;

			const cs = getComputedStyle( boton );
			// El fondo puede vivir en el propio `<a>` o en un ancestro.
			let fondo = cs.backgroundColor;
			let nodo = boton;
			while ( nodo && ( fondo === 'rgba(0, 0, 0, 0)' || fondo === 'transparent' ) ) {
				nodo = nodo.parentElement;
				if ( ! nodo ) break;
				fondo = getComputedStyle( nodo ).backgroundColor;
			}
			const caja = boton.getBoundingClientRect();

			return {
				texto: ( boton.textContent || '' ).trim(),
				href: boton.getAttribute( 'href' ),
				color: cs.color,
				// LO QUE DE VERDAD SE PINTA en WebKit y Chromium.
				relleno: cs.webkitTextFillColor || cs.color,
				fondo,
				alto: Math.round( caja.height ),
				ancho: Math.round( caja.width ),
			};
		}, correo.cta.source );

		if ( ! medida ) {
			check( `${ correo.clave }: el correo tiene un CTA pulsable`, false, 'no se encontró el botón' );
			continue;
		}
		check( `${ correo.clave }: el correo tiene un CTA pulsable`, true, `«${ medida.texto }»` );

		const ratio = contraste( medida.relleno, medida.fondo );
		check(
			`${ correo.clave }: contraste del CTA ≥ 4.5:1`,
			ratio >= 4.5,
			`${ ratio.toFixed( 2 ) }:1 (relleno ${ medida.relleno } sobre ${ medida.fondo })`
		);

		check(
			`${ correo.clave }: el destino del CTA es ABSOLUTO`,
			/^https?:\/\//.test( medida.href || '' ),
			medida.href
		);

		check(
			`${ correo.clave }: el CTA mide ≥44px de alto`,
			medida.alto >= 44,
			`${ medida.ancho }×${ medida.alto }px`
		);
	}

	// ── El correo de compra: el botón de pedido, medido aparte ─────────────
	const compra = listado.messages.find( ( m ) => /Hemos recibido tu pedido/i.test( m.Subject ) );
	if ( compra ) {
		const detalle = await api( `/api/v1/message/${ compra.ID }` );
		const plano = detalle.Text || '';
		check(
			'compra: la parte plana también lleva el destino del pedido',
			/Ver mi pedido:/.test( plano ) && /https?:\/\/\S+/.test( plano )
		);
	}

	await navegador.close();

	const fallos = checks.filter( ( c ) => ! c.ok );
	console.log( `\n${ checks.length - fallos.length }/${ checks.length } checks en verde.` );
	process.exit( fallos.length ? 1 : 0 );
};

main().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
