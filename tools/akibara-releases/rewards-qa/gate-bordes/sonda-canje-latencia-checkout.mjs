/**
 * sonda-canje-latencia-checkout.mjs — REPRODUCCIÓN del rojo de la pasada 2:
 * `canje-activo-300` y `canje-descuento-en-resumen` de `agente-cliente.mjs`.
 *
 * QUÉ PASÓ (medido 2026-09-01, pasada 2 del gate):
 *   agente-cliente.mjs:347-359 escribe 300 en `#akb-rw-canje`, pulsa
 *   «Canjear» y espera un plazo FIJO de 2.500 ms antes de leer la caja.
 *   En la pasada 1 el texto ya estaba (`canje-fila-resumen:
 *   "Puntos Akibara -$300"`), en la pasada 2 no (`""`). El mismo código, el
 *   mismo espejo, dos veredictos: eso es un plazo fijo demasiado justo, no un
 *   defecto del canje — pero «demasiado justo» hay que MEDIRLO, no suponerlo.
 *
 * QUÉ MIDE ESTA SONDA
 *   Cronometra el viaje real de `extensionCartUpdate()` en la superficie
 *   servida: desde el click en «Canjear» hasta que
 *     (a) la caja pinta «Canje activo: 300», y
 *     (b) la fila «Puntos Akibara −$300» existe en el resumen de totales.
 *   Sondea cada 50 ms con techo de 20 s, y repite el ciclo VUELTAS veces
 *   (por defecto 3) para dar una mediana y un máximo, no una anécdota.
 *
 * CHECKS (todos pueden fallar de verdad)
 *   - `canje-aparece-en-la-caja`      : aparece dentro de 20 s las 3 vueltas.
 *   - `canje-aparece-en-el-resumen`   : ídem la fila del fee.
 *   - `canje-lo-aplica-el-servidor`   : el Store API declara intent=300 y el
 *                                       total baja de 15.500 a 15.200. Si esto
 *                                       falla, el defecto es del canje.
 *   - `presupuesto-2500ms-de-agente-cliente`: informa si el MÁXIMO medido cabe
 *                                       en los 2.500 ms del agente. En rojo
 *                                       significa: el agente mide antes de
 *                                       tiempo y su veredicto es una moneda al
 *                                       aire. (`sonda-bordes-canje-limite.mjs`
 *                                       ya usa 4.000 ms para lo mismo.)
 *
 * SIEMBRA Y RESTAURACIÓN
 *   Cliente 229, igual que `sonda-bordes-canje-limite.mjs`: contraseña
 *   conocida + 500 puntos de saldo. Se restaura por `foto-229.mjs` (hash por
 *   UPDATE directo, metas y asientos del ledger sembrados; la foto vive en
 *   memoria y su helper PHP no sobrevive a la llamada), y se comprueba
 *   IDENTICO al cerrar. No se paga ningún pedido, así que no toca stock; aun
 *   así se fotografía. Ningún término de taxonomía se lee-y-guarda.
 *
 * Uso (cwd = tools/akibara-releases/rewards-qa/, donde vive node_modules):
 *   node gate-bordes/sonda-canje-latencia-checkout.mjs [vueltas]
 * Exit != 0 si algún check falla.
 */

import {
	abrir, BASE, MOVIL, agregarAlCarrito, vaciarCarrito,
	contarPedidos, maxPedidoId, pedidosDesde, borrarPedido, borrarBorradores,
	crearMarcador, evalPhp, wp, fotoStock, restaurarStock,
	fotoOpcionesPudo, limpiarOpcionesPudo, opcionesPudoNuevas,
} from './lib-bordes.mjs';
import { snapshot, residuosEnDocroot } from './foto-229.mjs';

const USUARIO = 229;
const CORREO = 'miaucraaft@gmail.com';
const CLAVE = 'QaBordes!2026';
const PUNTOS = 500;
const CANJE = 300;           // el mismo valor que usa agente-cliente.mjs.
const PRODUCTO = 17083;      // Parasyte 04 — $15.500, stock 4.
const PRESUPUESTO_AGENTE = 2500; // el plazo fijo que agente-cliente.mjs tenía antes de este arreglo.
const TECHO = 20000;
const VUELTAS = Math.max( 1, parseInt( process.argv[ 2 ] || '3', 10 ) );

const m = crearMarcador( 'canje-latencia-checkout' );
const idRef = maxPedidoId();
const pedidosAntes = contarPedidos();
const pudoAntes = fotoOpcionesPudo();
const stockAntes = fotoStock( [ PRODUCTO ] );

const mediana = ( xs ) => {
	const s = [ ...xs ].sort( ( a, b ) => a - b );
	return s.length % 2 ? s[ ( s.length - 1 ) / 2 ] : Math.round( ( s[ s.length / 2 - 1 ] + s[ s.length / 2 ] ) / 2 );
};

/* --------------------------------------------------------------------------
 * 0. Foto reversible del 229. Sembrar sobre un espejo sucio metería basura de
 *    otra corrida dentro de «lo que había».
 * ----------------------------------------------------------------------- */
let previo = snapshot( 'verificar' );
if ( /no existe la foto/.test( previo ) ) {
	previo = snapshot( 'guardar' );
	m.check( 'foto-229-creada', /GUARDADO/.test( previo ), previo.split( '\n' ).slice( -1 )[ 0 ] );
} else {
	m.check(
		'espejo-limpio-antes-de-sembrar',
		/IDENTICO/.test( previo ),
		previo.split( '\n' ).filter( ( l ) => /IDENTICO|DIFERENTE|AHORA/.test( l ) ).join( ' || ' )
	);
}

let navegador = null;
const enCaja = [];
const enResumen = [];

try {
	/* ------------------------------------------------------------------
	 * 1. Siembra: contraseña conocida + saldo.
	 * --------------------------------------------------------------- */
	wp( [ 'user', 'update', String( USUARIO ), `--user_pass=${ CLAVE }` ] );
	const credito = evalPhp(
		`$r = \\Akibara\\Rewards\\Points\\Ledger::credit( ${ USUARIO }, ${ PUNTOS }, 'admin', 'qa', 0, 'sonda latencia canje' ); echo 'bal=' . \\Akibara\\Rewards\\Points\\Ledger::balance( ${ USUARIO } );`
	);
	m.check( 'siembra-saldo-500', credito === `bal=${ PUNTOS }`, `Ledger::balance(${ USUARIO }) => ${ credito }` );

	/* ------------------------------------------------------------------
	 * 2. Sesión real por el formulario de Woo (panel plegado).
	 * --------------------------------------------------------------- */
	const sesion = await abrir( MOVIL );
	navegador = sesion.navegador;
	const pagina = sesion.pagina;

	await pagina.goto( `${ BASE }/mi-cuenta/`, { waitUntil: 'domcontentloaded' } );
	await pagina.locator( 'summary:has-text("Iniciar sesión")' ).click().catch( () => {} );
	await pagina.waitForTimeout( 600 );
	await pagina.fill( '#username', CORREO );
	await pagina.fill( '#password', CLAVE );
	await Promise.all( [
		pagina.waitForNavigation( { timeout: 45000 } ).catch( () => {} ),
		pagina.locator( 'button[name="login"]' ).click(),
	] );
	await pagina.waitForTimeout( 1500 );

	const quienSoy = await pagina.evaluate( async () => {
		const r = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
		const c = await r.json();
		const ext = ( c.extensions || {} )[ 'akibara-rewards' ] || {};
		return { logueado: !! ext.logged_in, balance: ext.balance, min: ext.min };
	} );
	m.check(
		'sesion-de-cliente-viva-en-el-store-api',
		true === quienSoy.logueado && quienSoy.balance === PUNTOS,
		`logged_in=${ quienSoy.logueado } balance=${ quienSoy.balance } min=${ quienSoy.min }`
	);

	/* ------------------------------------------------------------------
	 * 3. Carrito y checkout con la caja servida.
	 * --------------------------------------------------------------- */
	await vaciarCarrito( pagina );
	const carrito = await agregarAlCarrito( pagina, PRODUCTO );
	m.check( 'carrito-listo', 1 === carrito.lineas, `lineas=${ carrito.lineas } total=${ carrito.total }` );

	await pagina.goto( `${ BASE }/checkout/`, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForSelector( '#akb-rw-canje', { timeout: 30000 } );
	await pagina.waitForTimeout( 1500 );

	/** Sondea el DOM cada 50 ms hasta que `leer()` sea cierto. Devuelve ms o -1. */
	const cronometrar = async ( leer ) => {
		const t0 = Date.now();
		while ( Date.now() - t0 < TECHO ) {
			if ( await pagina.evaluate( leer ) ) {
				return Date.now() - t0;
			}
			await pagina.waitForTimeout( 50 );
		}
		return -1;
	};

	const VER_CAJA = () => {
		const c = document.querySelector( '.akb-rw-checkout-box' );
		return !! c && /Canje activo:\s*300/.test( ( c.textContent || '' ).replace( /\s+/g, ' ' ) );
	};
	const VER_RESUMEN = () =>
		[ ...document.querySelectorAll( '.wc-block-components-totals-item' ) ]
			.some( ( n ) => /Puntos Akibara/.test( n.textContent || '' ) && /[-−]\s*\$\s*300/.test( ( n.textContent || '' ).replace( /\s+/g, ' ' ) ) );

	const estadoCarrito = () =>
		pagina.evaluate( async () => {
			const r = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
			const c = await r.json();
			const ext = ( c.extensions || {} )[ 'akibara-rewards' ] || {};
			return { intent: ext.intent, total: c.totals ? c.totals.total_price : null };
		} );

	for ( let v = 1; v <= VUELTAS; v++ ) {
		// Punto de partida limpio: sin canje puesto.
		if ( await pagina.evaluate( VER_CAJA ) ) {
			await pagina.locator( '.akb-rw-checkout-box button:has-text("Quitar canje")' ).click();
			await pagina.waitForFunction( () => {
				const c = document.querySelector( '.akb-rw-checkout-box' );
				return !! c && ! /Canje activo/.test( c.textContent || '' );
			}, null, { timeout: TECHO } );
		}

		await pagina.fill( '#akb-rw-canje', String( CANJE ) );
		await pagina.waitForTimeout( 200 );

		const t0 = Date.now();
		await pagina.locator( '.akb-rw-checkout-box button:has-text("Canjear")' ).click();
		const msCaja = await cronometrar( VER_CAJA );
		// Segundo tramo: desde que la caja pinta hasta que la fila del fee
		// existe. -1 en cualquiera de los dos tramos deja el total en -1: un
		// «no apareció» no puede disfrazarse de número grande.
		const desdeClick = Date.now() - t0;
		const msExtra = -1 === msCaja ? -1 : await cronometrar( VER_RESUMEN );
		const msResumen = ( -1 === msCaja || -1 === msExtra ) ? -1 : desdeClick + msExtra;

		enCaja.push( msCaja );
		enResumen.push( msResumen );

		const est = await estadoCarrito();
		m.check(
			`vuelta-${ v }-canje-lo-aplica-el-servidor`,
			CANJE === est.intent && '15200' === String( est.total ),
			`intent=${ est.intent } total=${ est.total } (base 15500, canje ${ CANJE })`
		);
		console.log( `vuelta-${ v }: caja=${ msCaja }ms resumen=${ msResumen }ms` );
	}

	m.check(
		'canje-aparece-en-la-caja',
		enCaja.every( ( x ) => x > -1 ),
		`ms por vuelta=[${ enCaja.join( ', ' ) }] techo=${ TECHO }ms`
	);
	m.check(
		'canje-aparece-en-el-resumen',
		enResumen.every( ( x ) => x > -1 ),
		`ms por vuelta=[${ enResumen.join( ', ' ) }] techo=${ TECHO }ms`
	);

	/* ------------------------------------------------------------------
	 * Dejar la SESIÓN de Woo neutra. Sin esto la intención de canje (300) y
	 * el carrito sobreviven en `wp_woocommerce_sessions` con clave 229 y
	 * envenenan a la siguiente sonda que entre con ese cliente: medido el
	 * 2026-09-01, `sonda-bordes-canje-limite.mjs` arrancó con
	 * `total-base-sin-canje :: intent=300 total=15200` y encadenó 4 rojos que
	 * no eran suyos. La foto del 229 restaura usermeta y ledger, NO la sesión.
	 * --------------------------------------------------------------- */
	if ( await pagina.evaluate( VER_CAJA ) ) {
		await pagina.locator( '.akb-rw-checkout-box button:has-text("Quitar canje")' ).click();
		await pagina.waitForFunction( () => {
			const c = document.querySelector( '.akb-rw-checkout-box' );
			return !! c && ! /Canje activo/.test( c.textContent || '' );
		}, null, { timeout: TECHO } );
	}
	await vaciarCarrito( pagina );
	const sesionFinal = await pagina.evaluate( async () => {
		const r = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
		const c = await r.json();
		const ext = ( c.extensions || {} )[ 'akibara-rewards' ] || {};
		return { intent: ext.intent, lineas: ( c.items || [] ).length };
	} );
	m.check(
		'sesion-de-woo-queda-neutra',
		0 === sesionFinal.intent && 0 === sesionFinal.lineas,
		`intent=${ sesionFinal.intent } lineas_carrito=${ sesionFinal.lineas }`
	);

	const validos = enResumen.filter( ( x ) => x > -1 );
	const peor = validos.length ? Math.max( ...validos ) : -1;
	m.check(
		'presupuesto-2500ms-de-agente-cliente',
		peor > -1 && peor < PRESUPUESTO_AGENTE,
		`peor=${ peor }ms mediana=${ validos.length ? mediana( validos ) : -1 }ms presupuesto=${ PRESUPUESTO_AGENTE }ms ` +
		`(agente-cliente.mjs:349; sonda-bordes-canje-limite.mjs usa 4000ms para el mismo viaje)`
	);
} finally {
	if ( navegador ) {
		await navegador.close().catch( () => {} );
	}

	/* ------------------------------------------------------------------
	 * RESTAURACIÓN — antes = después de TODO lo sembrado.
	 * --------------------------------------------------------------- */
	const sobrantes = pedidosDesde( idRef );
	sobrantes.forEach( ( p ) => borrarPedido( p.id ) );
	borrarBorradores( idRef );
	const pedidosDespues = contarPedidos();
	m.check(
		'restauracion-pedidos-antes-igual-despues',
		pedidosDespues === pedidosAntes,
		`antes=${ pedidosAntes } despues=${ pedidosDespues } borrados=[${ sobrantes.map( ( p ) => p.id ).join( ',' ) }]`
	);

	restaurarStock( stockAntes );
	const stockDespues = fotoStock( [ PRODUCTO ] );
	m.check(
		'restauracion-stock-antes-igual-despues',
		JSON.stringify( stockDespues ) === JSON.stringify( stockAntes ),
		`antes=${ JSON.stringify( stockAntes ) } despues=${ JSON.stringify( stockDespues ) }`
	);

	const restauracion = snapshot( 'restaurar' );
	m.check(
		'restauracion-229-identica-hash-ledger-y-usermeta',
		/IDENTICO/.test( restauracion ),
		restauracion.split( '\n' ).map( ( l ) => l.trim() ).filter( ( l ) => /RESTAURADO|IDENTICO|DIFERENTE|ANTES|AHORA/.test( l ) ).join( ' || ' )
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

	// Check FINAL: el docroot no puede quedar sirviendo ningún helper del gate.
	const residuos = residuosEnDocroot();
	m.check(
		'docroot-sin-helpers-del-gate',
		residuos.length === 0,
		residuos.length ? `SERVIDOS: ${ residuos.join( ', ' ) }` : 'ninguno (ni qa-bordes-snapshot.php, ni .qa-bordes-229.json, ni qa-bordes-reparar-229.php)'
	);
}

process.exit( m.cerrar() > 0 ? 1 : 0 );
