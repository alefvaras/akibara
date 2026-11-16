/**
 * sonda-superficies-micuenta-movil.mjs — CASO 6 del gate de SUPERFICIES Y CACHÉ.
 *
 * Sesión REAL del cliente 229 en móvil (390x844), por el formulario de Woo:
 *   6.1 La tira de pestañas de Mi cuenta ATERRIZA AL INICIO (scrollLeft < 40)
 *       en el panel y en las subpáginas: si se centrara la activa, Pedidos y
 *       Colección quedarían fuera de pantalla nada más entrar.
 *   6.2 El seguimiento de Blue Express del pedido 27448 se VE en el detalle
 *       (código 2374683382, guardado en `_correios_tracking_code`).
 *   6.3 «Volver a comprar» AUSENTE en el listado Y en el detalle: la tienda no
 *       ofrece re-pedir (`woocommerce_valid_order_statuses_for_order_again`
 *       vacío + `unset($actions['order-again'])`).
 *   6.4 /mi-cuenta/coleccion/: el interruptor «Avisarme» presente y con
 *       `aria-pressed` COHERENTE — no consigo mismo, sino contra la verdad del
 *       servidor: se compara término a término con FollowRegistry.
 *
 * SIEMBRA Y RESTAURACIÓN: sólo se cambia la contraseña de 229, y se devuelve
 * por UPDATE con el hash guardado por `foto-229.mjs` — que mantiene la foto EN
 * MEMORIA y escribe su helper PHP en el docroot sólo mientras dura cada
 * llamada. La versión anterior dejaba `qa-bordes-snapshot.php` y
 * `.qa-bordes-229.json` VIVOS en `public_html/`, sirviendo por HTTP el hash y
 * las usermeta de una persona real. La sonda EXIGE que el espejo esté IDÉNTICO
 * antes de sembrar y vuelve a exigirlo después: hash, TODAS las usermeta
 * (incluido `session_tokens`, que el login toca) y el ledger.
 *
 * NO clica el interruptor de seguimiento ni guarda ningún término: escribir
 * termmeta editorial dispara el rebuild del buscador (suggest en 503 ~6 min).
 *
 * Uso: node sonda-superficies-micuenta-movil.mjs   (exit != 0 si algo falla)
 */

import { BASE, MOVIL, abrir, crearMarcador, wp, evalPhp, sqlUno } from './lib-bordes.mjs';
import { snapshot, residuosEnDocroot } from './foto-229.mjs';

const m = crearMarcador( 'superficies-micuenta-movil' );

const USUARIO = 229;
const CORREO = 'miaucraaft@gmail.com';
const CLAVE = 'QaSuperficies!2026';
const PEDIDO = 27448;

const ultimaLinea = ( s ) => s.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );

/* --------------------------------------------------------------------------
 * 0. Antes de tocar nada: el espejo tiene que estar donde lo dejaron.
 * ----------------------------------------------------------------------- */
let previo = snapshot( 'verificar' );
if ( /no existe la foto/.test( previo ) ) {
	previo = snapshot( 'guardar' );
	m.check( 'foto-229-creada', /GUARDADO/.test( previo ), ultimaLinea( previo ).slice( -1 )[ 0 ] );
} else {
	m.check(
		'espejo-limpio-antes-de-sembrar',
		/IDENTICO/.test( previo ),
		ultimaLinea( previo ).filter( ( l ) => /IDENTICO|DIFERENTE|RESUMEN/.test( l ) ).join( ' || ' )
	);
}

const hashAntes = sqlUno( `SELECT user_pass FROM wp_users WHERE ID=${ USUARIO }` );
const trackingEnBase = sqlUno(
	`SELECT meta_value FROM wp_wc_orders_meta WHERE order_id=${ PEDIDO } AND meta_key='_correios_tracking_code'`
);

let navegador = null;

try {
	wp( [ 'user', 'update', String( USUARIO ), `--user_pass=${ CLAVE }` ] );
	const hashSembrado = sqlUno( `SELECT user_pass FROM wp_users WHERE ID=${ USUARIO }` );
	m.check(
		'siembra-de-contrasena-efectiva',
		hashSembrado !== hashAntes && hashSembrado.length > 20,
		`hash cambiado: ${ hashAntes.slice( 0, 12 ) }… -> ${ hashSembrado.slice( 0, 12 ) }…`
	);

	const sesion = await abrir( MOVIL );
	navegador = sesion.navegador;
	const pagina = sesion.pagina;

	/* ------------------------------------------------------------------
	 * Login por el formulario REAL. El panel viene PLEGADO: sin abrir el
	 * <details> el form está en el DOM pero invisible y `fill` falla.
	 * --------------------------------------------------------------- */
	await pagina.goto( `${ BASE }/mi-cuenta/`, { waitUntil: 'domcontentloaded' } );
	await pagina.locator( 'details.akb-account-disclosure summary' ).first().click().catch( () => {} );
	await pagina.waitForSelector( '#username', { state: 'visible', timeout: 15000 } );
	await pagina.fill( '#username', CORREO );
	await pagina.fill( '#password', CLAVE );
	await Promise.all( [
		pagina.waitForNavigation( { timeout: 45000 } ).catch( () => {} ),
		pagina.locator( 'button[name="login"]' ).click(),
	] );
	await pagina.waitForTimeout( 1500 );

	const quien = await pagina.evaluate( () => ( {
		body: document.body.className,
		nav: !! document.querySelector( '.woocommerce-MyAccount-navigation' ),
		saludo: ( document.querySelector( '.woocommerce-MyAccount-content' )?.textContent || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 90 ),
	} ) );
	m.check(
		'sesion-de-cliente-229-viva',
		quien.nav && /logged-in/.test( quien.body ),
		`nav de Mi cuenta=${ quien.nav } · body="${ quien.body.split( ' ' ).filter( ( c ) => /logged|account|dashboard/.test( c ) ).join( ' ' ) }" · contenido="${ quien.saludo }"`
	);

	/* ------------------------------------------------------------------
	 * 6.1 La tira de pestañas aterriza al inicio.
	 * --------------------------------------------------------------- */
	const tira = async ( etiqueta ) => {
		const r = await pagina.evaluate( () => {
			const nav = document.querySelector( '.woocommerce-MyAccount-navigation' );
			const ul = nav ? nav.querySelector( 'ul' ) : null;
			if ( ! ul ) {
				return null;
			}
			const primera = ul.querySelector( 'li' );
			const activa = ul.querySelector( 'li.is-active, li.is-active a' );
			const visibles = [ ...ul.querySelectorAll( 'li' ) ].filter( ( li ) => {
				const r = li.getBoundingClientRect();
				const u = ul.getBoundingClientRect();
				return r.left >= u.left - 1 && r.right <= u.right + 1;
			} );
			return {
				scrollLeft: Math.round( ul.scrollLeft ),
				desbordada: ul.scrollWidth > ul.clientWidth + 4,
				pestanas: ul.querySelectorAll( 'li' ).length,
				visibles: visibles.map( ( li ) => ( li.textContent || '' ).trim() ),
				primera: primera ? ( primera.textContent || '' ).trim() : '',
				activa: activa ? ( activa.textContent || '' ).trim() : '(ninguna)',
			};
		} );
		m.check(
			`pestanas-al-inicio-${ etiqueta }`,
			!! r && r.scrollLeft < 40,
			r
				? `scrollLeft=${ r.scrollLeft }px (umbral 40) · tira desbordada=${ r.desbordada } · ${ r.pestanas } pestañas, visibles al aterrizar=[${ r.visibles.join( ', ' ) }] · activa="${ r.activa }"`
				: 'no hay .woocommerce-MyAccount-navigation en esta página'
		);
		return r;
	};

	await tira( 'panel' );

	/* ------------------------------------------------------------------
	 * 6.3a «Volver a comprar» en el LISTADO de pedidos.
	 * --------------------------------------------------------------- */
	await pagina.goto( `${ BASE }/mi-cuenta/orders/`, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForTimeout( 900 );
	await tira( 'listado-de-pedidos' );

	const listado = await pagina.evaluate( () => {
		const texto = ( document.querySelector( '.woocommerce-MyAccount-content' )?.textContent || '' ).replace( /\s+/g, ' ' );
		return {
			// La tienda sustituye la tabla de Woo por tarjetas propias: la clase es
			// `akb-orders-card` (plural). Con `.akb-order-card` el conteo daba 0
			// sobre un listado perfecto (medido el 2026-09-01).
			filas: document.querySelectorAll( '.akb-orders-card' ).length,
			vacio: document.querySelectorAll( '.akb-orders-empty' ).length,
			acciones: [ ...document.querySelectorAll( '.woocommerce-MyAccount-content a.button, .woocommerce-MyAccount-content .woocommerce-button' ) ].map( ( a ) => ( a.textContent || '' ).trim() ),
			orderAgainHref: [ ...document.querySelectorAll( '.woocommerce-MyAccount-content a[href]' ) ].filter( ( a ) => /order[-_]again/.test( a.getAttribute( 'href' ) || '' ) ).length,
			claseOrderAgain: document.querySelectorAll( '.woocommerce-MyAccount-content .order-again, .woocommerce-MyAccount-content a.order-again' ).length,
			mencion: /volver a comprar/i.test( texto ),
			pedido27448: texto.includes( '27448' ),
		};
	} );
	m.check(
		'listado-de-pedidos-tiene-pedidos-del-229',
		listado.filas > 0,
		`tarjetas .akb-orders-card=${ listado.filas } · bloque vacío=${ listado.vacio } · ¿aparece el 27448?=${ listado.pedido27448 } · acciones ofrecidas=[${ [ ...new Set( listado.acciones ) ].join( ', ' ) }]`
	);
	m.check(
		'volver-a-comprar-ausente-en-el-listado',
		listado.orderAgainHref === 0 && listado.claseOrderAgain === 0 && ! listado.mencion,
		`enlaces order_again=${ listado.orderAgainHref } · nodos .order-again=${ listado.claseOrderAgain } · texto «volver a comprar»=${ listado.mencion } · acciones=[${ [ ...new Set( listado.acciones ) ].join( ', ' ) }]`
	);

	/* ------------------------------------------------------------------
	 * 6.2 + 6.3b Detalle del pedido 27448.
	 * --------------------------------------------------------------- */
	await pagina.goto( `${ BASE }/mi-cuenta/view-order/${ PEDIDO }/`, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForTimeout( 900 );
	await tira( 'detalle-de-pedido' );

	const detalle = await pagina.evaluate( ( codigo ) => {
		const cont = document.querySelector( '.woocommerce-MyAccount-content' );
		const texto = ( cont?.textContent || '' ).replace( /\s+/g, ' ' );
		const visto = ( n ) => !! ( n && ( n.offsetWidth || n.offsetHeight || n.getClientRects().length ) );
		const nodoConCodigo = [ ...( cont?.querySelectorAll( '*' ) || [] ) ]
			.filter( ( n ) => n.children.length === 0 && ( n.textContent || '' ).includes( codigo ) )
			.pop();
		return {
			trackingEnTexto: texto.includes( codigo ),
			trackingVisible: visto( nodoConCodigo ),
			trackingContexto: nodoConCodigo
				? ( nodoConCodigo.closest( 'li, p, tr, div' )?.textContent || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 140 )
				: '',
			enlaceSeguimiento: [ ...( cont?.querySelectorAll( 'a[href]' ) || [] ) ]
				.filter( ( a ) => /bluex|blue-?express|track/i.test( a.getAttribute( 'href' ) || '' ) )
				.map( ( a ) => ( a.textContent || '' ).trim() ),
			orderAgainHref: [ ...( cont?.querySelectorAll( 'a[href]' ) || [] ) ].filter( ( a ) => /order[-_]again/.test( a.getAttribute( 'href' ) || '' ) ).length,
			claseOrderAgain: cont ? cont.querySelectorAll( '.order-again, a.order-again' ).length : 0,
			mencion: /volver a comprar/i.test( texto ),
			numero: texto.slice( 0, 120 ),
		};
	}, trackingEnBase );

	m.check(
		'tracking-de-blue-visible-en-el-pedido-27448',
		detalle.trackingEnTexto && detalle.trackingVisible,
		`código en base (_correios_tracking_code) = ${ trackingEnBase } · presente en el DOM=${ detalle.trackingEnTexto } · visible=${ detalle.trackingVisible } · contexto="${ detalle.trackingContexto }" · enlaces de seguimiento=[${ detalle.enlaceSeguimiento.join( ', ' ) }]`
	);

	m.check(
		'volver-a-comprar-ausente-en-el-detalle',
		detalle.orderAgainHref === 0 && detalle.claseOrderAgain === 0 && ! detalle.mencion,
		`enlaces order_again=${ detalle.orderAgainHref } · nodos .order-again=${ detalle.claseOrderAgain } · texto «volver a comprar»=${ detalle.mencion } · cabecera="${ detalle.numero.slice( 0, 80 ) }"`
	);

	/* ------------------------------------------------------------------
	 * 6.4 Mi colección: el interruptor y su aria-pressed contra la verdad.
	 * --------------------------------------------------------------- */
	await pagina.goto( `${ BASE }/mi-cuenta/coleccion/`, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForTimeout( 1200 );
	await tira( 'coleccion' );

	const coleccion = await pagina.evaluate( () => {
		const visto = ( n ) => !! ( n && ( n.offsetWidth || n.offsetHeight || n.getClientRects().length ) );
		const botones = [ ...document.querySelectorAll( '[data-akb-series-follow]' ) ];
		return {
			tarjetas: document.querySelectorAll( '.akb-account-collection__card, .akb-account-collection__item' ).length,
			total: botones.length,
			visibles: botones.filter( visto ).length,
			estado: botones.map( ( b ) => ( {
				term: b.getAttribute( 'data-term-id' ),
				serie: b.getAttribute( 'data-series-name' ),
				pressed: b.getAttribute( 'aria-pressed' ),
				etiqueta: ( b.querySelector( '.akb-series-follow__label' )?.textContent || '' ).trim(),
			} ) ),
		};
	} );

	m.check(
		'interruptor-avisarme-presente-en-la-coleccion',
		coleccion.total > 0 && coleccion.visibles === coleccion.total,
		`tarjetas=${ coleccion.tarjetas } · interruptores=${ coleccion.total } (visibles ${ coleccion.visibles }) · muestra=[${ coleccion.estado.slice( 0, 3 ).map( ( e ) => `${ e.serie }:aria-pressed=${ e.pressed }/"${ e.etiqueta }"` ).join( ' | ' ) }]`
	);

	// Coherencia interna: la etiqueta tiene que decir lo mismo que aria-pressed.
	const incoherentes = coleccion.estado.filter(
		( e ) => ( e.pressed === 'true' ) !== /avisos activados/i.test( e.etiqueta )
	);
	m.check(
		'aria-pressed-coherente-con-la-etiqueta',
		incoherentes.length === 0 && coleccion.total > 0,
		`${ coleccion.total } interruptores, ${ incoherentes.length } con etiqueta que contradice aria-pressed${ incoherentes.length ? ' :: ' + incoherentes.slice( 0, 4 ).map( ( e ) => `${ e.serie }=${ e.pressed }/"${ e.etiqueta }"` ).join( ' || ' ) : '' }`
	);

	// Coherencia con el SERVIDOR: el DOM no puede ser su propio testigo.
	const termIds = coleccion.estado.map( ( e ) => parseInt( e.term, 10 ) ).filter( Boolean );
	if ( termIds.length ) {
		// La verdad del servidor es OPT-OUT: se sigue una serie comprada mientras
		// el cliente no se haya dado de baja (Renderer.php:219). `is_following()`
		// no existe — invocarla fatalaba el `wp eval` (medido el 2026-09-01).
		const verdad = evalPhp(
			`$r = array(); foreach ( array( ${ termIds.join( ',' ) } ) as $t ) { $r[] = $t . '=' . ( \\Akibara\\Business\\Modules\\SeriesFollow\\FollowRegistry::is_opted_out( ${ USUARIO }, $t ) ? 'false' : 'true' ); } echo implode( ',', $r );`
		);
		const mapa = Object.fromEntries( verdad.split( ',' ).filter( Boolean ).map( ( p ) => p.split( '=' ) ) );
		const desalineados = coleccion.estado.filter( ( e ) => mapa[ e.term ] !== undefined && mapa[ e.term ] !== e.pressed );
		m.check(
			'aria-pressed-coherente-con-FollowRegistry',
			Object.keys( mapa ).length === termIds.length && desalineados.length === 0,
			`servidor: ${ verdad } · desalineados=${ desalineados.length }${ desalineados.length ? ' :: ' + desalineados.map( ( e ) => `${ e.serie } DOM=${ e.pressed } servidor=${ mapa[ e.term ] }` ).join( ' || ' ) : '' }`
		);
	} else {
		m.check( 'aria-pressed-coherente-con-FollowRegistry', false, 'no hubo interruptores con data-term-id que contrastar' );
	}
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, `EXCEPCIÓN: ${ error.message }` );
} finally {
	if ( navegador ) {
		await navegador.close();
	}

	/* ------------------------------------------------------------------
	 * Restauración: hash por UPDATE con el valor guardado + todas las
	 * usermeta que el login haya tocado. Antes = después, o rojo.
	 * --------------------------------------------------------------- */
	const restaurado = snapshot( 'restaurar' );
	const hashDespues = sqlUno( `SELECT user_pass FROM wp_users WHERE ID=${ USUARIO }` );
	m.check(
		'restauracion-229-identica',
		/IDENTICO/.test( restaurado ) && hashDespues === hashAntes,
		`${ ultimaLinea( restaurado ).filter( ( l ) => /RESTAURADO|IDENTICO|DIFERENTE|ANTES|AHORA/.test( l ) ).join( ' || ' ) } · hash antes=${ hashAntes.slice( 0, 16 ) }… después=${ hashDespues.slice( 0, 16 ) }… (${ hashDespues === hashAntes ? 'IDÉNTICO' : 'DISTINTO' })`
	);

	// Check FINAL: el docroot no puede quedar sirviendo ningún helper del gate.
	const residuos = residuosEnDocroot();
	m.check(
		'docroot-sin-helpers-del-gate',
		residuos.length === 0,
		residuos.length ? `SERVIDOS: ${ residuos.join( ', ' ) }` : 'ninguno (ni qa-bordes-snapshot.php, ni .qa-bordes-229.json, ni qa-bordes-reparar-229.php)'
	);
}

process.exit( m.cerrar() );
