/**
 * esceptico-01-triple-click-pedido-unico.mjs — RE-VERIFICACIÓN INDEPENDIENTE
 * del verde «doble click en Realizar el pedido → UN solo pedido».
 *
 * Por qué existe si ya hay `sonda-bordes-doble-click.mjs`:
 *
 *   1. Aquella sonda pulsa DOS veces. El cliente real con la conexión mala
 *      pulsa TRES o CUATRO. Aquí se pulsa 3 veces (0 ms, 350 ms, 1200 ms)
 *      con el POST del Store API retrasado 5 s, así que las tres caen DENTRO
 *      de la ventana en la que la primera petición sigue en vuelo.
 *
 *   2. Aquella sonda cuenta los POST de pago con `m.info` — un dato que NO
 *      puede poner la sonda en rojo. Aquí el nº de POST es un CHECK: si el
 *      navegador dispara dos intentos de pago y sólo se salva porque Woo
 *      reusa el borrador, eso tiene que verse en rojo, no en una nota.
 *
 *   3. Aquella sonda no mira los correos. Un pedido duplicado se le nota
 *      también al cliente en la bandeja: aquí se cuenta cuántos correos
 *      «Hemos recibido tu pedido» llegan a Mailpit para ESTE email.
 *
 * SIEMBRA: un pedido (bacs) y una unidad de stock del producto 17083.
 * RESTAURA: pedidos + borradores + stock + filas `akb_pudo_state` + los
 * correos de Mailpit BORRADOS POR ID (no por patrón de asunto: borrar por
 * patrón se llevó por delante correos ajenos el 2026-09-01).
 *
 * Uso (cwd = tools/akibara-releases/rewards-qa/):
 *   node gate-bordes/esceptico-01-triple-click-pedido-unico.mjs
 */

import { execFileSync } from 'node:child_process';
import {
	abrir, BASE, ESCRITORIO, agregarAlCarrito, vaciarCarrito,
	contarPedidos, maxPedidoId, pedidosDesde, pedidosRealesDesde, borrarPedido,
	borrarBorradores, crearMarcador, fotoStock, restaurarStock, DORMIR,
	fotoOpcionesPudo, limpiarOpcionesPudo, opcionesPudoNuevas,
} from './lib-bordes.mjs';

const PRODUCTO = 17083;              // Parasyte 04 — instock.
const EMAIL = 'qa.esceptico.triple@example.test';
const RETRASO_MS = 5000;             // El POST de pago se queda 5 s en vuelo.
const PULSACIONES = [ 0, 350, 1200 ]; // Tres dedos dentro de la ventana.
const MAILPIT = 'http://localhost:8025';

const m = crearMarcador( 'esceptico-triple-click' );

/* --------------------------------------------------------------------------
 * Mailpit por ID: se listan los mensajes ANTES, y al final se borran sólo los
 * IDs que no estaban. Nada de patrones de asunto.
 * ----------------------------------------------------------------------- */

function mailpitIds() {
	const salida = execFileSync( 'curl', [ '-s', `${ MAILPIT }/api/v1/messages?limit=200` ], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 } );
	const datos = JSON.parse( salida );
	return ( datos.messages || [] ).map( ( x ) => ( { id: x.ID, asunto: x.Subject, para: ( x.To || [] ).map( ( t ) => t.Address ).join( ',' ) } ) );
}

function mailpitBorrar( ids ) {
	if ( ! ids.length ) {
		return 0;
	}
	execFileSync( 'curl', [
		'-s', '-X', 'DELETE', `${ MAILPIT }/api/v1/messages`,
		'-H', 'Content-Type: application/json',
		'-d', JSON.stringify( { IDs: ids } ),
	], { encoding: 'utf8' } );
	return ids.length;
}

const idRef = maxPedidoId();
const pedidosAntes = contarPedidos();
const pudoAntes = fotoOpcionesPudo();
const stockAntes = fotoStock( [ PRODUCTO ] );
const correosAntes = mailpitIds();
const idsAntes = new Set( correosAntes.map( ( c ) => c.id ) );

let navegador = null;
let creados = [];

try {
	const sesion = await abrir( ESCRITORIO );
	navegador = sesion.navegador;
	const pagina = sesion.pagina;

	await vaciarCarrito( pagina );
	const carrito = await agregarAlCarrito( pagina, PRODUCTO );
	m.check( 'carrito-real-de-una-linea', carrito.lineas === 1, `lineas=${ carrito.lineas } cantidad=${ carrito.cantidad } total=${ carrito.total }` );

	// Contador de POST de PAGO (el PUT del borrador lleva override y no cuenta).
	const posts = [];
	await pagina.route( '**/wp-json/wc/store/v1/checkout**', async ( route ) => {
		const req = route.request();
		const cab = await req.allHeaders();
		if ( req.method() === 'POST' && ! cab[ 'x-http-method-override' ] ) {
			posts.push( Date.now() );
			await DORMIR( RETRASO_MS );
		}
		await route.continue();
	} );

	await pagina.goto( `${ BASE }/checkout/`, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForSelector( '#billing-city', { timeout: 30000 } );
	await pagina.waitForTimeout( 2500 );
	await pagina.fill( '#email', EMAIL );
	await pagina.fill( '#contact-akibara-rut', '12.345.678-5' );
	await pagina.locator( '#contact-akibara-rut' ).press( 'Tab' );
	await pagina.fill( '#billing-first_name', 'QA' );
	await pagina.fill( '#billing-last_name', 'Triple' );
	await pagina.fill( '#billing-address_1', 'Av Siempre Viva 742' );
	await pagina.selectOption( '#billing-state', 'CL-RM' );
	await pagina.waitForTimeout( 900 );
	await pagina.fill( '#billing-city', 'Providencia' );
	await pagina.keyboard.press( 'Escape' );
	await pagina.waitForTimeout( 400 );
	await pagina.fill( '#billing-phone', '987654321' );
	await pagina.waitForTimeout( 2500 );
	await pagina.check( '#radio-control-wc-payment-method-options-bacs' );
	await pagina.waitForTimeout( 2500 );

	const bacs = await pagina.evaluate( () => ( document.getElementById( 'radio-control-wc-payment-method-options-bacs' ) || {} ).checked );
	m.check( 'transferencia-elegida', bacs === true, `radio bacs checked=${ bacs }` );

	const idAntes = maxPedidoId();
	const cuentaAntes = contarPedidos();

	// Tres pulsaciones dentro de la ventana del POST en vuelo. La 2.ª y la 3.ª
	// se fuerzan aunque React ya haya deshabilitado el botón: se dispara el
	// evento a mano, que es lo que hace un doble toque en móvil.
	const estados = [];
	for ( const t of PULSACIONES ) {
		if ( t ) {
			await DORMIR( t - ( PULSACIONES[ PULSACIONES.indexOf( t ) - 1 ] || 0 ) );
		}
		const estado = await pagina.evaluate( () => {
			const b = document.querySelector( '.wc-block-components-checkout-place-order-button' );
			if ( ! b ) {
				return { hay: false };
			}
			const info = { hay: true, disabled: b.disabled === true, aria: b.getAttribute( 'aria-disabled' ) };
			b.click();
			b.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true, view: window } ) );
			return info;
		} );
		estados.push( `t=${ t }ms disabled=${ estado.hay ? estado.disabled : 'sin-boton' } aria-disabled=${ estado.hay ? estado.aria : '-' }` );
	}

	m.check( 'el-boton-existe-y-se-pulso-tres-veces', estados.length === PULSACIONES.length && ! estados.some( ( e ) => /sin-boton/.test( e ) ), estados.join( ' | ' ) );

	await pagina.waitForTimeout( RETRASO_MS + 15000 );

	const cuentaDespues = contarPedidos();
	const nuevos = pedidosRealesDesde( idAntes );
	creados = pedidosDesde( idRef ).map( ( p ) => p.id );

	m.check(
		'tres-pulsaciones-nace-exactamente-un-pedido',
		cuentaDespues === cuentaAntes + 1 && nuevos.length === 1,
		`pedidos ${ cuentaAntes } -> ${ cuentaDespues }; nuevos NO borrador=[${ nuevos.map( ( p ) => `${ p.id }:${ p.status }:${ p.total }` ).join( ' , ' ) }]`
	);

	// AQUÍ está la diferencia con la sonda original: el nº de POST de pago es
	// un CHECK, no una nota. Dos intentos de pago que Woo desduplique por el
	// borrador seguirían siendo un fallo del botón.
	m.check(
		'sale-un-solo-POST-de-pago-al-Store-API',
		posts.length === 1,
		`${ posts.length } POST de pago; separaciones ms=[${ posts.map( ( t, i ) => ( i ? t - posts[ i - 1 ] : 0 ) ).join( ',' ) }] · pulsaciones programadas=${ PULSACIONES.join( ',' ) } ms · retraso inyectado=${ RETRASO_MS } ms`
	);

	const url = pagina.url();
	m.check( 'termina-en-pedido-recibido', /pedido-recibido|order-received/.test( url ), `url final = ${ url }` );

	const porEmail = parseInt(
		execFileSync( 'docker', [ 'compose', 'run', '--rm', '-T', 'cli', 'wp', 'db', 'query',
			`SELECT COUNT(*) FROM wp_wc_orders WHERE billing_email='${ EMAIL }'`, '--skip-column-names', '--path=/var/www/html' ],
		{ cwd: '/Users/alefvaras/Documents/akibara', encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } ).trim().split( '\n' ).pop().trim(),
		10
	);
	m.check( 'un-solo-pedido-con-ese-email', porEmail === 1, `pedidos con billing_email=${ EMAIL } → ${ porEmail }` );

	// Correo: el cliente tiene que recibir UNA confirmación, no tres.
	await DORMIR( 2500 );
	const correosDespues = mailpitIds();
	const nuevosCorreos = correosDespues.filter( ( c ) => ! idsAntes.has( c.id ) );
	const alCliente = nuevosCorreos.filter( ( c ) => c.para.includes( EMAIL ) );
	m.check(
		'una-sola-confirmacion-al-cliente',
		alCliente.length === 1,
		`correos nuevos=${ nuevosCorreos.length } [${ nuevosCorreos.map( ( c ) => c.asunto ).join( ' | ' ) }] · dirigidos a ${ EMAIL }=${ alCliente.length }`
	);

	await vaciarCarrito( pagina );
	await navegador.close();
	navegador = null;
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, `EXCEPCIÓN: ${ error && error.message ? error.message : error }` );
} finally {
	if ( navegador ) {
		await navegador.close();
	}

	// ---- RESTAURACIÓN ----
	const sobrantes = pedidosDesde( idRef );
	sobrantes.forEach( ( p ) => borrarPedido( p.id ) );
	borrarBorradores( idRef );
	const pedidosDespues = contarPedidos();
	m.check(
		'restauracion-pedidos-antes-igual-despues',
		pedidosDespues === pedidosAntes,
		`antes=${ pedidosAntes } despues=${ pedidosDespues } borrados=[${ sobrantes.map( ( p ) => p.id ).join( ',' ) }]`
	);

	const arreglos = restaurarStock( stockAntes );
	const stockDespues = fotoStock( [ PRODUCTO ] );
	m.check(
		'restauracion-stock-antes-igual-despues',
		JSON.stringify( stockDespues ) === JSON.stringify( stockAntes ),
		`antes=${ JSON.stringify( stockAntes ) } despues=${ JSON.stringify( stockDespues ) } reparado=[${ arreglos.join( '; ' ) }]`
	);

	// Lo que se exige es NO DEJAR filas nuevas. Comparar el CONTEO contra el
	// de antes daba rojo cuando una fila PREVIA vencía sola durante la sonda
	// (TTL de 1 h): una expiración ajena no es un residuo de esta corrida.
	const pudoNuevas = limpiarOpcionesPudo( pudoAntes );
	const pudoResidual = opcionesPudoNuevas( pudoAntes );
	m.check(
		'restauracion-sin-filas-akb-pudo-state-nuevas',
		pudoResidual.length === 0,
		`akb_pudo_state antes=${ pudoAntes.length } creadas=${ pudoNuevas.length } residuales=${ pudoResidual.length } [${ pudoResidual.join( ',' ) }] despues=${ fotoOpcionesPudo().length }`
	);

	const finales = mailpitIds();
	const aBorrar = finales.filter( ( c ) => ! idsAntes.has( c.id ) ).map( ( c ) => c.id );
	mailpitBorrar( aBorrar );
	const tras = mailpitIds();
	m.check(
		'restauracion-mailpit-por-ID-antes-igual-despues',
		tras.length === correosAntes.length && tras.every( ( c ) => idsAntes.has( c.id ) ),
		`antes=${ correosAntes.length } creados=${ aBorrar.length } despues=${ tras.length } · ajenos borrados=${ tras.filter( ( c ) => ! idsAntes.has( c.id ) ).length }`
	);
}

process.exit( m.cerrar() > 0 ? 1 : 0 );
