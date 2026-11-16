/**
 * sonda-bordes-canje-limite.mjs — CASO 5: canje de Puntos Akibara AL LÍMITE,
 * con el cliente 229 (swap de contraseña reversible).
 *
 * `bajo-minimo-avisa-al-cliente-en-el-momento` documentaba un defecto ABIERTO
 * (CERRADO el 2026-09-01: la caja publica el aviso del intento rechazado)
 * (el rechazo bajo el mínimo es mudo). Los otros 22 checks pasan. Si alguno
 * MÁS se pone rojo, eso sí es una regresión.
 *
 * Bordes cubiertos:
 *   A) Canje BAJO EL MÍNIMO (mínimo 100, se piden 50): no puede aplicarse y
 *      el cliente tiene que enterarse. Se mide qué señal recibe de verdad:
 *      validez nativa del <input min>, mensaje visible, intención y total.
 *   B) Canje EXACTO AL SALDO (500 de 500): se aplica entero y el total baja
 *      exactamente el valor facial.
 *   C) Aplicar y QUITAR dos veces seguidas: idempotente. El total tiene que
 *      volver al MISMO céntimo, no «parecido».
 *   D) Captura y reversa: se paga con el canje puesto (el saldo se debita),
 *      se CANCELA el pedido y el saldo tiene que volver solo.
 *
 * TODO se restaura: contraseña (por hash guardado, comparación byte a byte),
 * asientos del ledger, usermeta de puntos, pedidos, borradores y stock.
 *
 * La foto reversible del 229 la pone `foto-229.mjs`: vive en memoria y su
 * helper PHP sólo existe mientras dura cada llamada (antes quedaban
 * `qa-bordes-snapshot.php` y `.qa-bordes-229.json` servidos en el docroot).
 * Salida != 0 si algún check falla.
 */

import {
	abrir, BASE, MOVIL, agregarAlCarrito, vaciarCarrito, contarCarrito,
	contarPedidos, maxPedidoId, pedidosDesde, borrarPedido, borrarBorradores,
	enviarCheckout, prepararEnvio, crearMarcador, sqlUno, evalPhp, wp,
	fotoStock, restaurarStock,
	fotoOpcionesPudo, limpiarOpcionesPudo, opcionesPudoNuevas,
} from './lib-bordes.mjs';
import { snapshot, residuosEnDocroot } from './foto-229.mjs';

const USUARIO = 229;
const CLAVE = 'QaBordes!2026';
const PUNTOS = 500;
const PRODUCTO = 17083; // Parasyte 04 — $15.500, stock 4.

const m = crearMarcador( 'bordes-canje-limite' );
const idRef = maxPedidoId();
const pedidosAntes = contarPedidos();
const pudoAntes = fotoOpcionesPudo();
const stockAntes = fotoStock( [ PRODUCTO ] );

/* --------------------------------------------------------------------------
 * 0. Foto reversible. Si ya existe, el espejo TIENE que estar idéntico antes
 *    de sembrar: sembrar sobre un espejo sucio dejaría basura de otra corrida
 *    dentro de «lo que había».
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

try {
	/* ------------------------------------------------------------------
	 * 1. Siembra: contraseña conocida + saldo de 500 puntos.
	 * --------------------------------------------------------------- */
	wp( [ 'user', 'update', String( USUARIO ), `--user_pass=${ CLAVE }` ] );
	const credito = evalPhp(
		`$r = \\Akibara\\Rewards\\Points\\Ledger::credit( ${ USUARIO }, ${ PUNTOS }, 'admin', 'qa', 0, 'gate bordes' ); echo 'bal=' . \\Akibara\\Rewards\\Points\\Ledger::balance( ${ USUARIO } );`
	);
	m.check( 'siembra-saldo-500', credito === `bal=${ PUNTOS }`, `Ledger::balance(${ USUARIO }) => ${ credito }` );

	/* ------------------------------------------------------------------
	 * 2. Sesión de cliente por el formulario REAL de Woo.
	 * --------------------------------------------------------------- */
	const sesion = await abrir( MOVIL );
	navegador = sesion.navegador;
	const pagina = sesion.pagina;

	await pagina.goto( `${ BASE }/mi-cuenta/`, { waitUntil: 'domcontentloaded' } );
	// El panel de acceso viene PLEGADO: sin abrirlo el formulario está en el
	// DOM pero no es visible y `fill` falla.
	await pagina.locator( 'summary:has-text("Iniciar sesión")' ).click().catch( () => {} );
	await pagina.waitForTimeout( 600 );
	await pagina.fill( '#username', String( USUARIO === 229 ? 'miaucraaft@gmail.com' : '' ) );
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
		return { logueado: !! ext.logged_in, balance: ext.balance, min: ext.min, intent: ext.intent };
	} );
	m.check(
		'sesion-de-cliente-viva-en-el-store-api',
		quienSoy.logueado === true && quienSoy.balance === PUNTOS,
		`logged_in=${ quienSoy.logueado } balance=${ quienSoy.balance } min=${ quienSoy.min } intent=${ quienSoy.intent }`
	);

	/* ------------------------------------------------------------------
	 * 3. Carrito y checkout con la caja de canje servida.
	 * --------------------------------------------------------------- */
	await vaciarCarrito( pagina );
	const carrito = await agregarAlCarrito( pagina, PRODUCTO );
	m.check( 'carrito-listo', carrito.lineas === 1, `lineas=${ carrito.lineas } total=${ carrito.total }` );

	await pagina.goto( `${ BASE }/checkout/`, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForSelector( '#akb-rw-canje', { timeout: 30000 } );
	await pagina.waitForTimeout( 1500 );

	const caja = await pagina.evaluate( () => {
		const input = document.getElementById( 'akb-rw-canje' );
		const contenedor = input ? input.closest( '.akb-rw-checkout-box' ) : null;
		return {
			min: input ? input.getAttribute( 'min' ) : null,
			max: input ? input.getAttribute( 'max' ) : null,
			texto: contenedor ? ( contenedor.textContent || '' ).replace( /\s+/g, ' ' ).trim() : '',
		};
	} );
	m.check(
		'caja-de-canje-servida-con-saldo-y-minimo',
		caja.min === '100' && caja.max === String( PUNTOS ) && /500 puntos/.test( caja.texto ),
		`min=${ caja.min } max=${ caja.max } copia="${ caja.texto }"`
	);

	/** Lee intención + total del Store API (la superficie servida). */
	const estadoCarrito = () =>
		pagina.evaluate( async () => {
			const r = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
			const c = await r.json();
			const ext = ( c.extensions || {} )[ 'akibara-rewards' ] || {};
			return {
				intent: ext.intent,
				balance: ext.balance,
				total: c.totals ? c.totals.total_price : null,
				fees: ( c.fees || [] ).map( ( f ) => `${ f.name }=${ f.totals.total }` ).join( ',' ),
			};
		} );

	/** Escribe puntos en la caja y pulsa «Canjear». */
	const canjear = async ( puntos ) => {
		await pagina.fill( '#akb-rw-canje', String( puntos ) );
		await pagina.waitForTimeout( 200 );
		await pagina.locator( '.akb-rw-checkout-box button:has-text("Canjear")' ).click();
		await pagina.waitForTimeout( 4000 );
	};

	const quitar = async () => {
		await pagina.locator( '.akb-rw-checkout-box button:has-text("Quitar canje")' ).click();
		await pagina.waitForTimeout( 4000 );
	};

	const base = await estadoCarrito();
	m.check(
		'total-base-sin-canje',
		base.intent === 0 && base.total === '15500',
		`intent=${ base.intent } total=${ base.total } fees="${ base.fees }"`
	);

	/* ---- A) BAJO EL MÍNIMO ---- */
	await canjear( 50 );
	const bajoMin = await estadoCarrito();
	const senal = await pagina.evaluate( () => {
		const input = document.getElementById( 'akb-rw-canje' );
		const contenedor = input ? input.closest( '.akb-rw-checkout-box' ) : null;
		const avisos = Array.from(
			document.querySelectorAll( '.wc-block-components-notice-banner, .wc-block-components-validation-error' )
		)
			.filter( ( n ) => n.offsetWidth || n.offsetHeight || n.getClientRects().length )
			.map( ( n ) => ( n.textContent || '' ).trim() );
		return {
			rangeUnderflow: input ? input.validity.rangeUnderflow : null,
			valida: input ? input.checkValidity() : null,
			mensajeNativo: input ? input.validationMessage : '',
			copia: contenedor ? ( contenedor.textContent || '' ).replace( /\s+/g, ' ' ).trim() : '',
			avisos: avisos.join( ' | ' ),
		};
	} );
	m.check(
		'bajo-minimo-no-aplica-descuento',
		bajoMin.intent === 0 && bajoMin.total === base.total,
		`intent=${ bajoMin.intent } total ${ base.total } -> ${ bajoMin.total } fees="${ bajoMin.fees }"`
	);
	m.check(
		'bajo-minimo-el-campo-queda-invalido',
		senal.rangeUnderflow === true && senal.valida === false,
		`rangeUnderflow=${ senal.rangeUnderflow } checkValidity=${ senal.valida } mensajeNativo="${ senal.mensajeNativo }"`
	);
	m.check(
		'bajo-minimo-la-copia-declara-el-minimo',
		/canje parte desde 100/i.test( senal.copia ),
		`copia de la caja = "${ senal.copia }"`
	);
	m.info( 'bajo-minimo-avisos-visibles-del-checkout', `"${ senal.avisos }" (nada sobre el canje = el rechazo no publica banner)` );

	// DEFECTO ABIERTO. El enunciado pide «rechazo claro»; lo que hay es
	// rechazo CORRECTO y MUDO. Medido el 2026-09-01 en el checkout servido,
	// tras pulsar «Canjear» con 50 puntos:
	//   · borde del campo rgb(88,88,88) -> rgb(88,88,88)
	//   · fondo rgb(17,17,17) -> rgb(17,17,17), outline sin cambios
	//   · la caja NO gana ningún texto (su HTML pasa de 607 a 597 bytes: es
	//     el botón volviendo de «Aplicando…» a «Canjear»)
	//   · `input.validationMessage` SÍ dice «Value must be greater than or
	//     equal to 100.», pero nunca se ve: el botón es type="button", no un
	//     submit, así que el navegador no dibuja su globo.
	// El cliente ve parpadear el botón y nada más. Este check queda ROJO a
	// propósito hasta que la caja publique un mensaje propio.
	const avisoPropio = await pagina.evaluate( () => {
		const caja = document.querySelector( '.akb-rw-checkout-box' );
		const texto = caja ? caja.textContent.replace( /\s+/g, ' ' ) : '';
		// Un mensaje de rechazo hablaría del intento, no del mínimo en frío.
		return /no pudimos|no se apli|mínimo para canjear|debes canjear al menos/i.test( texto );
	} );
	m.check(
		'bajo-minimo-avisa-al-cliente-en-el-momento',
		avisoPropio,
		avisoPropio
			? 'la caja publica el aviso del intento rechazado («No se aplicó: el mínimo…») — defecto CERRADO 2026-09-01'
			: 'REGRESIÓN: tras pulsar «Canjear» bajo el mínimo no aparece ningún mensaje propio; ' +
				`el validationMessage nativo ("${ senal.mensajeNativo }") no se dibuja porque el botón no es submit`
	);

	/* ---- B) EXACTO AL SALDO ---- */
	await canjear( PUNTOS );
	const exacto = await estadoCarrito();
	m.check(
		'canje-exacto-al-saldo-se-aplica',
		exacto.intent === PUNTOS,
		`intent=${ exacto.intent } (saldo=${ exacto.balance })`
	);
	m.check(
		'canje-exacto-descuenta-el-valor-facial',
		Number( exacto.total ) === Number( base.total ) - PUNTOS,
		`total ${ base.total } -> ${ exacto.total } (esperado ${ Number( base.total ) - PUNTOS }) fees="${ exacto.fees }"`
	);
	m.check(
		'canje-exacto-aparece-como-linea-puntos-akibara',
		/Puntos Akibara=-?\d+/.test( exacto.fees ),
		`fees="${ exacto.fees }"`
	);

	/* ---- C) APLICAR / QUITAR DOS VECES: IDEMPOTENCIA ---- */
	const ciclos = [];
	for ( let i = 1; i <= 2; i++ ) {
		await quitar();
		const sin = await estadoCarrito();
		await canjear( PUNTOS );
		const con = await estadoCarrito();
		ciclos.push( { i, sinIntent: sin.intent, sinTotal: sin.total, conIntent: con.intent, conTotal: con.total } );
	}
	await quitar();
	const finalSin = await estadoCarrito();

	m.check(
		'quitar-canje-devuelve-el-total-exacto',
		ciclos.every( ( c ) => c.sinIntent === 0 && c.sinTotal === base.total ) && finalSin.total === base.total,
		`ciclos=${ JSON.stringify( ciclos ) } final=${ finalSin.total } base=${ base.total }`
	);
	m.check(
		'reaplicar-canje-da-el-mismo-total',
		ciclos.every( ( c ) => c.conIntent === PUNTOS && c.conTotal === exacto.total ),
		`ciclos=${ JSON.stringify( ciclos ) } esperado con canje=${ exacto.total }`
	);
	m.check(
		'aplicar-y-quitar-no-mueve-el-saldo',
		( await estadoCarrito() ).balance === PUNTOS,
		`balance tras 3 quitar + 2 aplicar = ${ ( await estadoCarrito() ).balance } (esperado ${ PUNTOS })`
	);

	/* ---- D) CAPTURA Y REVERSA ---- */
	await canjear( PUNTOS );
	const antesPago = await estadoCarrito();
	m.check( 'canje-puesto-antes-de-pagar', antesPago.intent === PUNTOS, `intent=${ antesPago.intent } total=${ antesPago.total }` );

	await prepararEnvio( pagina );
	const cuentaAntes = contarPedidos();
	const res = await enviarCheckout( pagina, { direccion: { email: 'miaucraaft@gmail.com' } } );
	const cuentaDespues = contarPedidos();
	m.check(
		'pedido-con-canje-nace',
		res.status === 200 && res.orderId > 0 && cuentaDespues === cuentaAntes + 1,
		`HTTP ${ res.status } order_id=${ res.orderId } pedidos ${ cuentaAntes } -> ${ cuentaDespues } msg="${ res.message }"`
	);

	if ( res.orderId > 0 ) {
		const debitados = sqlUno(
			`SELECT IFNULL(meta_value,'(sin meta)') FROM wp_wc_orders_meta WHERE order_id=${ res.orderId } AND meta_key='_akb_rewards_redeemed_points'`
		);
		const saldoTrasPago = parseInt(
			evalPhp( `echo \\Akibara\\Rewards\\Points\\Ledger::balance( ${ USUARIO } );` ),
			10
		);
		m.check(
			'captura-debita-los-500-puntos',
			debitados === String( PUNTOS ) && saldoTrasPago === 0,
			`_akb_rewards_redeemed_points=${ debitados } saldo tras pagar=${ saldoTrasPago }`
		);

		// Cancelar: la reversa tiene que devolver el saldo sola.
		wp( [ 'eval', `$o = wc_get_order( ${ res.orderId } ); $o->update_status( 'cancelled', 'gate bordes' );` ] );
		const saldoTrasCancelar = parseInt(
			evalPhp( `echo \\Akibara\\Rewards\\Points\\Ledger::balance( ${ USUARIO } );` ),
			10
		);
		const asientoReversa = sqlUno(
			`SELECT CONCAT(action,':',delta) FROM wp_akb_rewards_ledger WHERE user_id=${ USUARIO } AND ref_id=${ res.orderId } AND action='redeem_refund' ORDER BY id DESC LIMIT 1`
		);
		m.check(
			'cancelar-el-pedido-devuelve-los-puntos',
			saldoTrasCancelar === PUNTOS,
			`saldo ${ saldoTrasPago } -> ${ saldoTrasCancelar } (esperado ${ PUNTOS }); asiento de reversa = "${ asientoReversa }"`
		);
	}
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, String( error && error.message ? error.message : error ) );
} finally {
	if ( navegador ) {
		await navegador.close();
	}

	/* ------------------------------------------------------------------
	 * RESTAURACIÓN
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

	const arreglos = restaurarStock( stockAntes );
	const stockDespues = fotoStock( [ PRODUCTO ] );
	m.check(
		'restauracion-stock-antes-igual-despues',
		JSON.stringify( stockDespues ) === JSON.stringify( stockAntes ),
		`antes=${ JSON.stringify( stockAntes ) } despues=${ JSON.stringify( stockDespues ) } reparado=[${ arreglos.join( '; ' ) }]`
	);

	const restauracion = snapshot( 'restaurar' );
	const lineas = restauracion.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );
	m.check(
		'restauracion-229-identica-hash-ledger-y-usermeta',
		/IDENTICO/.test( restauracion ),
		lineas.filter( ( l ) => /RESTAURADO|IDENTICO|DIFERENTE|ANTES|AHORA/.test( l ) ).join( ' || ' )
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

	// Check FINAL: el docroot no puede quedar sirviendo ningún helper del gate.
	const residuos = residuosEnDocroot();
	m.check(
		'docroot-sin-helpers-del-gate',
		residuos.length === 0,
		residuos.length ? `SERVIDOS: ${ residuos.join( ', ' ) }` : 'ninguno (ni qa-bordes-snapshot.php, ni .qa-bordes-229.json, ni qa-bordes-reparar-229.php)'
	);
}

process.exit( m.cerrar() > 0 ? 1 : 0 );
