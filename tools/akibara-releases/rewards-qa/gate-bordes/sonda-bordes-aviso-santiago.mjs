/**
 * sonda-bordes-aviso-santiago.mjs — CASO 6: aviso «¿No estás en Santiago?».
 *
 * Lo que se exige:
 *   1. El config SERVIDO trae `window.akbCartCheckoutBlocks.shippingHint` no
 *      vacío (móvil y escritorio). Cadena vacía = carril apagado.
 *   2. En /carrito/, con solo-retiro (en el espejo la única tarifa que
 *      cotiza es `local_pickup:70`), el aviso está PRESENTE y VISIBLE.
 *
 * Y tres controles NEGATIVOS, porque un check que no puede fallar no es un
 * check. El aviso tiene que DESAPARECER cuando:
 *   3. La página es el checkout (allí la dirección está a la vista; el guard
 *      es `context === 'woocommerce/checkout'`).
 *   4. No hay NINGUNA tarifa (dirección sin cobertura): el aviso hablaría de
 *      «ver opciones» que no existen.
 *   5. Aparece una tarifa que NO es retiro. Esa rama se ejercita empujando
 *      un carrito modificado al store `wc/store/cart` del propio bundle
 *      servido (cero escrituras en la base): en el espejo NINGÚN método de
 *      despacho cotiza —Blue Express necesita su API y el `free_shipping` de
 *      la zona RM está deshabilitado—, así que por dirección no es
 *      alcanzable.
 *
 * No siembra pedidos ni toca stock. Salida != 0 si algún check falla.
 */

import {
	abrir, BASE, MOVIL, ESCRITORIO, agregarAlCarrito, vaciarCarrito,
	prepararEnvio, crearMarcador, contarPedidos,
	fotoOpcionesPudo, limpiarOpcionesPudo, opcionesPudoNuevas,
} from './lib-bordes.mjs';

const PRODUCTO = 17083;
const COPIA = '¿No estás en Santiago?';
const m = crearMarcador( 'bordes-aviso-santiago' );
const pedidosAntes = contarPedidos();
const pudoAntes = fotoOpcionesPudo();

async function leerAviso( pagina ) {
	return pagina.evaluate( () => {
		const n = document.querySelector( '.akb-blocks-shipping-hint' );
		return {
			existe: !! n,
			texto: n ? ( n.textContent || '' ).trim() : '',
			visible: !! ( n && ( n.offsetWidth || n.offsetHeight || n.getClientRects().length ) ),
		};
	} );
}

async function esperar( pagina, cond, ms = 15000 ) {
	const t0 = Date.now();
	while ( Date.now() - t0 < ms ) {
		if ( await pagina.evaluate( cond ) ) {
			return Date.now() - t0;
		}
		await pagina.waitForTimeout( 400 );
	}
	return -1;
}

let navegador = null;

try {
	for ( const [ etiqueta, viewport ] of [ [ 'movil', MOVIL ], [ 'escritorio', ESCRITORIO ] ] ) {
		const sesion = await abrir( viewport );
		navegador = sesion.navegador;
		const pagina = sesion.pagina;

		await vaciarCarrito( pagina );
		await agregarAlCarrito( pagina, PRODUCTO );
		const envio = await prepararEnvio( pagina, { city: 'Providencia', state: 'CL-RM' } );
		m.info( `${ etiqueta }-tarifas-rm`, `tarifas=${ envio.tarifas } elegida=${ envio.elegida }` );

		/* ---- 1 y 2: /carrito/ con solo-retiro ---- */
		await pagina.goto( `${ BASE }/carrito/`, { waitUntil: 'domcontentloaded' } );
		const t = await esperar( pagina, () => !! document.querySelector( '.akb-blocks-shipping-hint' ) );
		m.info( `${ etiqueta }-aviso-aparece-en-ms`, String( t ) );

		const config = await pagina.evaluate( () => window.akbCartCheckoutBlocks || null );
		m.check(
			`${ etiqueta }-config-servido-con-shippingHint`,
			!! config && typeof config.shippingHint === 'string' && config.shippingHint.trim() !== '',
			`window.akbCartCheckoutBlocks = ${ JSON.stringify( config ) }`
		);

		const soloRetiro = await pagina.evaluate( async () => {
			const r = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
			const c = await r.json();
			const tarifas = ( c.shipping_rates || [] ).flatMap( ( p ) => p.shipping_rates || [] );
			return {
				n: tarifas.length,
				ids: tarifas.map( ( x ) => x.method_id ).join( ',' ),
				todasRetiro: tarifas.length > 0 && tarifas.every( ( x ) => x.method_id === 'local_pickup' || x.method_id === 'pickup_location' ),
			};
		} );
		m.check(
			`${ etiqueta }-premisa-solo-retiro`,
			soloRetiro.todasRetiro === true,
			`${ soloRetiro.n } tarifas [${ soloRetiro.ids }] todas-retiro=${ soloRetiro.todasRetiro }`
		);

		const enCarrito = await leerAviso( pagina );
		m.check(
			`${ etiqueta }-carrito-muestra-el-aviso`,
			enCarrito.existe && enCarrito.visible && enCarrito.texto.includes( COPIA ),
			`existe=${ enCarrito.existe } visible=${ enCarrito.visible } texto="${ enCarrito.texto }"`
		);
		m.check(
			`${ etiqueta }-aviso-coincide-con-el-config`,
			!! config && enCarrito.texto === config.shippingHint,
			`DOM="${ enCarrito.texto }" config="${ config && config.shippingHint }"`
		);

		/* ---- 5: tarifa que NO es retiro => el aviso se retira ---- */
		const trasFalsa = await pagina.evaluate( async () => {
			const r = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
			const carro = await r.json();
			const paquete = ( carro.shipping_rates || [] )[ 0 ];
			if ( ! paquete ) {
				return { aplicado: false };
			}
			const plantilla = paquete.shipping_rates[ 0 ];
			paquete.shipping_rates = paquete.shipping_rates.concat( [
				Object.assign( {}, plantilla, {
					rate_id: 'flat_rate:999',
					method_id: 'flat_rate',
					name: 'Despacho a domicilio (sonda)',
					selected: false,
				} ),
			] );
			wp.data.dispatch( 'wc/store/cart' ).receiveCart( carro );
			await new Promise( ( ok ) => setTimeout( ok, 1200 ) );
			const n = document.querySelector( '.akb-blocks-shipping-hint' );
			return { aplicado: true, sigue: !! n };
		} );
		m.check(
			`${ etiqueta }-con-tarifa-de-despacho-el-aviso-desaparece`,
			trasFalsa.aplicado === true && trasFalsa.sigue === false,
			`carrito empujado al store con flat_rate:999 -> aviso presente=${ trasFalsa.sigue }`
		);

		/* ---- 3: el checkout NO lo muestra ---- */
		await pagina.goto( `${ BASE }/checkout/`, { waitUntil: 'domcontentloaded' } );
		// Ancla: el botón de pago, NO `#billing-city`. Con la dirección ya
		// completa (la fijó `prepararEnvio`), Blocks pliega el formulario y
		// deja el campo comuna en el DOM pero OCULTO: `waitForSelector`
		// esperaba 30 s a algo que nunca se iba a ver.
		await pagina.waitForSelector( 'button.wc-block-components-checkout-place-order-button', { timeout: 30000 } );
		await pagina.waitForTimeout( 5000 );
		const enCheckout = await leerAviso( pagina );
		const configCheckout = await pagina.evaluate( () => window.akbCartCheckoutBlocks || null );
		m.check(
			`${ etiqueta }-checkout-tambien-sirve-el-config`,
			!! configCheckout && configCheckout.shippingHint === ( config && config.shippingHint ),
			`shippingHint en checkout = "${ configCheckout && configCheckout.shippingHint }"`
		);
		m.check(
			`${ etiqueta }-checkout-no-muestra-el-aviso`,
			enCheckout.existe === false,
			`existe=${ enCheckout.existe } texto="${ enCheckout.texto }"`
		);

		/* ---- 4: sin ninguna tarifa, el aviso no aparece ---- */
		const sinCobertura = await prepararEnvio( pagina, { city: 'Valparaíso', state: 'CL-VS' } );
		m.check(
			`${ etiqueta }-premisa-sin-cobertura`,
			sinCobertura.tarifas === 0,
			`tarifas para Valparaíso = ${ sinCobertura.tarifas }`
		);
		await pagina.goto( `${ BASE }/carrito/`, { waitUntil: 'domcontentloaded' } );
		await pagina.waitForTimeout( 6000 );
		const sinTarifas = await leerAviso( pagina );
		m.check(
			`${ etiqueta }-sin-tarifas-no-hay-aviso`,
			sinTarifas.existe === false,
			`existe=${ sinTarifas.existe } texto="${ sinTarifas.texto }"`
		);

		// Se deja la dirección de vuelta en RM para no dejar la sesión coja.
		await prepararEnvio( pagina, { city: 'Providencia', state: 'CL-RM' } );
		await vaciarCarrito( pagina );
		await navegador.close();
		navegador = null;
	}
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, String( error && error.message ? error.message : error ) );
} finally {
	if ( navegador ) {
		await navegador.close();
	}
	const pedidosDespues = contarPedidos();
	m.check(
		'restauracion-esta-sonda-no-crea-pedidos',
		pedidosDespues === pedidosAntes,
		`antes=${ pedidosAntes } despues=${ pedidosDespues }`
	);

	// Lo que se exige es NO DEJAR filas nuevas. Comparar el CONTEO contra el
	// de antes daba rojo cuando una fila PREVIA vencía sola durante la sonda
	// (TTL de 1 h): una expiración ajena no es un residuo de esta corrida.
	const pudoNuevas = limpiarOpcionesPudo( pudoAntes );
	const pudoResidual = opcionesPudoNuevas( pudoAntes );
	m.check(
		'restauracion-sin-filas-akb-pudo-state-nuevas',
		pudoResidual.length === 0,
		`akb_pudo_state antes=${ pudoAntes.length } creadas=${ pudoNuevas.length } [${ pudoNuevas.join( ',' ) }] residuales=${ pudoResidual.length } [${ pudoResidual.join( ',' ) }] despues=${ fotoOpcionesPudo().length }`
	);
}

process.exit( m.cerrar() > 0 ? 1 : 0 );
