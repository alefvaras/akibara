/**
 * lib-bordes.mjs — utilidades compartidas por las sondas de BORDES DE COMPRA.
 *
 * Reglas de la casa que este módulo hace cumplir:
 *  - Todo corre EN SERIE contra el espejo (http://localhost:8080). Ninguna
 *    sonda abre dos navegadores a la vez.
 *  - Nunca se crean ni se guardan TÉRMINOS de taxonomía (dispara rebuild del
 *    buscador). Sólo se usan productos ya existentes.
 *  - Cada sonda sale con código != 0 si algún check falla, para que la
 *    pasada 2 pueda re-correrlas sin leer el log a ojo.
 *
 * Uso: `node sonda-*.mjs` con cwd = tools/akibara-releases/rewards-qa/
 * (ahí vive node_modules) o desde este mismo directorio (resuelve solo).
 */

import { execFileSync, execSync } from 'node:child_process';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = path.dirname( fileURLToPath( import.meta.url ) );
export const RAIZ_REPO = path.resolve( AQUI, '../../../..' );
const QA_DIR = path.resolve( AQUI, '..' );

// node_modules vive en rewards-qa/, no aquí: resolvemos playwright desde allí
// para poder ejecutar la sonda con cualquier cwd.
const require = createRequire( path.join( QA_DIR, 'package.json' ) );
export const { chromium } = require( 'playwright' );

export const BASE = process.env.AKB_BASE || 'http://localhost:8080';
export const MOVIL = { width: 390, height: 844 };
export const ESCRITORIO = { width: 1440, height: 900 };

/* -------------------------------------------------------------------------
 * WP-CLI en el contenedor. cwd SIEMPRE la raíz del repo.
 * ---------------------------------------------------------------------- */

export function wp( args ) {
	const salida = execFileSync(
		'docker',
		[ 'compose', 'run', '--rm', '-T', 'cli', 'wp', ...args, '--path=/var/www/html' ],
		{ cwd: RAIZ_REPO, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ], maxBuffer: 64 * 1024 * 1024 }
	);
	return salida.trim();
}

/** Consulta SQL cruda, devuelve las filas como texto sin cabecera. */
export function sql( consulta ) {
	return wp( [ 'db', 'query', consulta, '--skip-column-names' ] );
}

/** Consulta SQL que devuelve un único escalar. */
export function sqlUno( consulta ) {
	const filas = sql( consulta ).split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );
	return filas.length ? filas[ filas.length - 1 ] : '';
}

/** `wp eval` devolviendo la última línea (evita el ruido de docker compose). */
export function evalPhp( codigo ) {
	const salida = wp( [ 'eval', codigo ] );
	const lineas = salida.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );
	return lineas.length ? lineas[ lineas.length - 1 ] : '';
}

/* -------------------------------------------------------------------------
 * Marcador de checks. Un check que no puede fallar NO es un check: cada uno
 * guarda la evidencia numérica que lo sostiene.
 * ---------------------------------------------------------------------- */

/**
 * Auto-barrido de Mailpit por sonda: se fotografían los IDs del buzón al
 * CREAR el marcador y, al salir el proceso (éxito, falla o crash), se borran
 * SOLO los correos nacidos durante la corrida. Vive aquí y no en cada sonda
 * porque la asimetría era el defecto (rojo 2 de la pasada 5): 3 sondas
 * limpiaban por ID y las 22 restantes dejaban sus avisos de pedido y
 * «contraseña cambiada» acumulándose contra el sello mailpit=0. Borrar IDs
 * ya borrados por una sonda aplicada es un no-op — no hay doble-limpieza.
 */
function armarBarridoMailpit() {
	let idsAntes = null;
	try {
		const res = JSON.parse( execSync( `curl -s 'http://localhost:8025/api/v1/messages?limit=2000'`, { encoding: 'utf8' } ) );
		idsAntes = new Set( ( res.messages || [] ).map( ( m ) => m.ID ) );
	} catch ( e ) {
		return; // Sin Mailpit accesible no hay nada que custodiar.
	}
	process.on( 'exit', () => {
		try {
			const res = JSON.parse( execSync( `curl -s 'http://localhost:8025/api/v1/messages?limit=2000'`, { encoding: 'utf8' } ) );
			const nuevos = ( res.messages || [] ).map( ( m ) => m.ID ).filter( ( id ) => ! idsAntes.has( id ) );
			if ( nuevos.length ) {
				execSync(
					`curl -s -X DELETE 'http://localhost:8025/api/v1/messages' -H 'Content-Type: application/json' -d '${ JSON.stringify( { IDs: nuevos } ) }'`,
					{ encoding: 'utf8' }
				);
				console.log( `mailpit-auto-barrido: ${ nuevos.length } correo(s) de esta sonda borrados` );
			}
		} catch ( e ) { /* el sello de restauración lo reportará si algo quedó */ }
	} );
}

export function crearMarcador( nombreSonda ) {
	armarBarridoMailpit();
	const checks = [];
	return {
		checks,
		check( id, ok, evidencia ) {
			checks.push( { id, ok: !! ok, evidencia: String( evidencia ) } );
			console.log( `${ ok ? 'OK  ' : 'FALLA' }  ${ id }  ::  ${ evidencia }` );
			return !! ok;
		},
		info( id, evidencia ) {
			console.log( `info   ${ id }  ::  ${ evidencia }` );
		},
		cerrar() {
			const fallos = checks.filter( ( c ) => ! c.ok );
			console.log( `\n[${ nombreSonda }] ${ checks.length - fallos.length }/${ checks.length } checks OK` );
			if ( fallos.length ) {
				console.log( `[${ nombreSonda }] FALLAS: ${ fallos.map( ( f ) => f.id ).join( ', ' ) }` );
			}
			return fallos.length;
		},
	};
}

/* -------------------------------------------------------------------------
 * Navegador
 * ---------------------------------------------------------------------- */

export async function abrir( viewport = MOVIL ) {
	const navegador = await chromium.launch();
	const contexto = await navegador.newContext( {
		viewport,
		locale: 'es-CL',
		isMobile: viewport.width < 768,
		hasTouch: viewport.width < 768,
	} );
	const pagina = await contexto.newPage();
	pagina.setDefaultTimeout( 30000 );
	return { navegador, contexto, pagina };
}

/* -------------------------------------------------------------------------
 * Carrito
 * ---------------------------------------------------------------------- */

/** Vacía el carrito de la sesión del navegador vía Store API. */
export async function vaciarCarrito( pagina ) {
	await pagina.goto( `${ BASE }/carrito/`, { waitUntil: 'domcontentloaded' } );
	return pagina.evaluate( async () => {
		const r = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
		const nonce = r.headers.get( 'nonce' ) || r.headers.get( 'Nonce' );
		const carrito = await r.json();
		for ( const item of carrito.items || [] ) {
			await fetch( '/wp-json/wc/store/v1/cart/remove-item', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', Nonce: nonce },
				body: JSON.stringify( { key: item.key } ),
			} );
		}
		return ( carrito.items || [] ).length;
	} );
}

/**
 * Añade un producto por la ruta REAL del cliente (`?add-to-cart=`), la misma
 * que usan los botones del catálogo. Devuelve el nº de líneas del carrito
 * leído del Store API (la superficie servida, no el código).
 */
export async function agregarAlCarrito( pagina, idProducto ) {
	await pagina.goto( `${ BASE }/?add-to-cart=${ idProducto }`, { waitUntil: 'domcontentloaded' } );
	return contarCarrito( pagina );
}

export async function contarCarrito( pagina ) {
	return pagina.evaluate( async () => {
		const r = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
		const c = await r.json();
		return {
			lineas: ( c.items || [] ).length,
			cantidad: c.items_count,
			total: c.totals ? c.totals.total_price : null,
			ids: ( c.items || [] ).map( ( i ) => i.id ),
		};
	} );
}

/* -------------------------------------------------------------------------
 * Lectura del error de un campo del checkout de bloques.
 *
 * `aria-describedby` NO sirve como ancla: en el checkout servido apunta al
 * aviso «estás comprando como invitado», el mismo para el email y para el
 * RUT, así que un lector ingenuo devolvía ese texto y los tres casos del RUT
 * «fallaban» con el mismo mensaje (medido el 2026-09-01). El error de campo
 * cuelga de `aria-errormessage` y del bloque
 * `.wc-block-components-validation-error` dentro del envoltorio.
 * ---------------------------------------------------------------------- */

export async function leerErrorCampo( pagina, idInput ) {
	return pagina.evaluate( ( id ) => {
		const input = document.getElementById( id );
		if ( ! input ) {
			return { existe: false, texto: '', visible: false, ariaInvalid: null, mejorada: false };
		}

		const visto = ( n ) => !! ( n && ( n.offsetWidth || n.offsetHeight || n.getClientRects().length ) );

		// El id lleva una barra («validate-error-contact_akibara/rut»), que
		// rompe querySelector: getElementById es el único que lo resuelve.
		let nodo = null;
		const errId = input.getAttribute( 'aria-errormessage' );
		if ( errId ) {
			nodo = document.getElementById( errId );
		}
		if ( ! nodo ) {
			const envoltorio = input.closest( '.wc-block-components-text-input' );
			nodo = envoltorio ? envoltorio.querySelector( '.wc-block-components-validation-error' ) : null;
		}

		return {
			existe: true,
			texto: nodo ? ( nodo.textContent || '' ).trim() : '',
			visible: visto( nodo ),
			ariaInvalid: input.getAttribute( 'aria-invalid' ),
			conClaseError: !! (
				input.closest( '.wc-block-components-text-input' ) &&
				input.closest( '.wc-block-components-text-input' ).classList.contains( 'has-error' )
			),
			mejorada: input.dataset.akbComunaEnhanced === '1',
		};
	}, idInput );
}

/* -------------------------------------------------------------------------
 * Pedidos: conteo y limpieza
 * ---------------------------------------------------------------------- */

/**
 * Pedidos REALES: excluye `checkout-draft`.
 *
 * Woo Blocks crea un borrador en cuanto el POST del checkout entra, y lo
 * conserva aunque la validación lo rechace (lo reutiliza en el reintento y lo
 * recoge su propio cron). Contar la tabla entera hacía «fallar» un rechazo
 * correcto: HTTP 400, order_id 0, y aun así +1 fila. Y al revés: cuando el
 * pago SÍ prospera, Woo ASCIENDE ese mismo borrador —misma fila, mismo id—,
 * así que el total tampoco sube y un pedido real parecía no haber nacido.
 * Contar sólo los NO borrador arregla los dos lados. Medido el 2026-09-01.
 *
 * HPOS guarda el estado con prefijo (`wc-checkout-draft`), no pelado.
 */
const NO_BORRADOR = "status NOT IN ('wc-checkout-draft','checkout-draft','trash','wc-trash')";

export function contarPedidos() {
	return parseInt( sqlUno( `SELECT COUNT(*) FROM wp_wc_orders WHERE ${ NO_BORRADOR }` ), 10 );
}

export function contarPedidosTotales() {
	return parseInt( sqlUno( 'SELECT COUNT(*) FROM wp_wc_orders' ), 10 );
}

export function maxPedidoId() {
	return parseInt( sqlUno( 'SELECT IFNULL(MAX(id),0) FROM wp_wc_orders' ), 10 );
}

/** Pedidos creados por encima de un id de referencia. */
export function pedidosDesde( idRef ) {
	const filas = sql(
		`SELECT id, status, billing_email, total_amount FROM wp_wc_orders WHERE id > ${ idRef } ORDER BY id`
	);
	return filas
		.split( '\n' )
		.map( ( l ) => l.trim() )
		.filter( Boolean )
		.map( ( l ) => {
			const [ id, status, email, total ] = l.split( '\t' );
			return { id: parseInt( id, 10 ), status, email, total };
		} );
}

/** Pedidos NO borrador creados por encima de un id de referencia. */
export function pedidosRealesDesde( idRef ) {
	return pedidosDesde( idRef ).filter(
		( p ) => 'wc-checkout-draft' !== p.status && 'checkout-draft' !== p.status
	);
}

/** Borra un pedido de verdad: papelera + borrado forzado. */
export function borrarPedido( id ) {
	try {
		wp( [ 'post', 'delete', String( id ), '--force' ] );
	} catch ( e ) {
		// HPOS: si el pedido no vive en wp_posts, `wc order delete` es la vía.
	}
	try {
		wp( [ 'wc', 'shop_order', 'delete', String( id ), '--force=true', '--user=1' ] );
	} catch ( e ) {
		// Ya estaba borrado por la vía anterior.
	}
	// Barrido final por SQL: HPOS deja fila en wp_wc_orders + meta + items.
	sql( `DELETE FROM wp_wc_order_addresses WHERE order_id=${ id }` );
	sql( `DELETE FROM wp_wc_orders_meta WHERE order_id=${ id }` );
	sql(
		`DELETE oim FROM wp_woocommerce_order_itemmeta oim JOIN wp_woocommerce_order_items oi ON oi.order_item_id=oim.order_item_id WHERE oi.order_id=${ id }`
	);
	sql( `DELETE FROM wp_woocommerce_order_items WHERE order_id=${ id }` );
	sql( `DELETE FROM wp_wc_order_operational_data WHERE order_id=${ id }` );
	sql( `DELETE FROM wp_wc_orders WHERE id=${ id }` );
	sql( `DELETE FROM wp_posts WHERE ID=${ id }` );
	sql( `DELETE FROM wp_postmeta WHERE post_id=${ id }` );
	// Las NOTAS del pedido viven en wp_comments (comment_type=order_note con
	// comment_post_ID = id HPOS) y NINGUNA de las vías anteriores las toca:
	// así se acumularon 251 huérfanas que ensuciaron el sello del baseline
	// (hueco confirmado en la pasada 3 del gate, 2026-09-01).
	sql(
		`DELETE cm FROM wp_commentmeta cm JOIN wp_comments c ON c.comment_ID=cm.comment_id WHERE c.comment_post_ID=${ id } AND c.comment_type='order_note'`
	);
	sql( `DELETE FROM wp_comments WHERE comment_post_ID=${ id } AND comment_type='order_note'` );
}

/** Borra los borradores de checkout que deja Woo Blocks al pintar el paso de pago. */
export function borrarBorradores( idRef ) {
	const ids = sql(
		`SELECT id FROM wp_wc_orders WHERE status IN ('wc-checkout-draft','checkout-draft') AND id > ${ idRef }`
	)
		.split( '\n' )
		.map( ( l ) => parseInt( l.trim(), 10 ) )
		.filter( ( n ) => Number.isInteger( n ) && n > 0 );
	ids.forEach( borrarPedido );
	return ids;
}

/* -------------------------------------------------------------------------
 * Store API: envío directo del MISMO cuerpo que arma el checkout de bloques.
 *
 * El cuerpo se capturó interceptando el POST real del formulario (recon del
 * 2026-09-01), así que no es un contrato inventado: es el que viaja de verdad.
 * Se usa para probar la capa SERVIDOR sin que la validación en cliente
 * intercepte antes — que es justo lo que hay que comprobar cuando la pregunta
 * es «¿nace un pedido?».
 * ---------------------------------------------------------------------- */

export const DIRECCION_BASE = {
	first_name: 'QA',
	last_name: 'Bordes',
	company: '',
	address_1: 'Av Siempre Viva 742',
	address_2: '',
	city: 'Providencia',
	state: 'CL-RM',
	postcode: '',
	country: 'CL',
	email: 'qa.bordes@example.test',
	phone: '987654321',
};

/**
 * Deja la sesión lista para pagar: fija la dirección del cliente y elige una
 * tarifa de envío, igual que hace el checkout de bloques antes del POST.
 *
 * Sin esto el Store API responde `woocommerce_rest_invalid_shipping_option` y
 * el rechazo que se mide es el de envío, NO el de la comuna: el check pasaba
 * "por el motivo equivocado" (visto el 2026-09-01).
 *
 * @return {Promise<{tarifas:number, elegida:string, paquetes:number}>}
 */
export async function prepararEnvio( pagina, direccion = {} ) {
	const addr = { ...DIRECCION_BASE, ...direccion };
	return pagina.evaluate(
		async ( addr ) => {
			const g = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
			const nonce = g.headers.get( 'nonce' ) || g.headers.get( 'Nonce' );
			const cab = { 'Content-Type': 'application/json', Nonce: nonce };

			const u = await fetch( '/wp-json/wc/store/v1/cart/update-customer', {
				method: 'POST',
				credentials: 'same-origin',
				headers: cab,
				body: JSON.stringify( { billing_address: addr, shipping_address: addr } ),
			} );
			const carrito = await u.json();
			const paquetes = carrito.shipping_rates || [];
			const tarifas = paquetes.length ? paquetes[ 0 ].shipping_rates || [] : [];
			if ( ! tarifas.length ) {
				return { tarifas: 0, elegida: '', paquetes: paquetes.length };
			}

			const elegida = ( tarifas.find( ( t ) => t.selected ) || tarifas[ 0 ] ).rate_id;
			const s = await fetch( '/wp-json/wc/store/v1/cart/select-shipping-rate', {
				method: 'POST',
				credentials: 'same-origin',
				headers: cab,
				body: JSON.stringify( { package_id: paquetes[ 0 ].package_id, rate_id: elegida } ),
			} );
			await s.json().catch( () => null );
			return { tarifas: tarifas.length, elegida, paquetes: paquetes.length };
		},
		addr
	);
}

/**
 * Envía el checkout por el Store API con la dirección y el RUT indicados.
 *
 * @param {import('playwright').Page} pagina  Página con la sesión/carrito viva.
 * @param {object} opciones                   { direccion, rut }
 * @return {Promise<{status:number, code:string, message:string, orderId:number}>}
 */
export async function enviarCheckout( pagina, { direccion = {}, rut = '12.345.678-5' } = {} ) {
	const addr = { ...DIRECCION_BASE, ...direccion };
	return pagina.evaluate(
		async ( { addr, rut } ) => {
			const g = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
			const nonce = g.headers.get( 'nonce' ) || g.headers.get( 'Nonce' );
			const r = await fetch( '/wp-json/wc/store/v1/checkout', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', Nonce: nonce },
				body: JSON.stringify( {
					additional_fields: { 'akibara/rut': rut },
					billing_address: addr,
					shipping_address: addr,
					create_account: false,
					customer_note: '',
					customer_password: '',
					extensions: {},
					payment_method: 'bacs',
					payment_data: [ { key: 'wc-bacs-new-payment-method', value: false } ],
				} ),
			} );
			let cuerpo = null;
			try {
				cuerpo = await r.json();
			} catch ( e ) {
				cuerpo = null;
			}
			return {
				status: r.status,
				code: ( cuerpo && cuerpo.code ) || '',
				message: ( cuerpo && ( cuerpo.message || '' ) ) || '',
				detalles: cuerpo && cuerpo.data && cuerpo.data.details ? JSON.stringify( cuerpo.data.details ) : '',
				orderId: ( cuerpo && cuerpo.order_id ) || 0,
			};
		},
		{ addr, rut }
	);
}

/* -------------------------------------------------------------------------
 * Stock: un pedido que prospera lo DESCUENTA, y borrar el pedido NO lo
 * devuelve. Sin esta foto, el espejo queda con menos existencias de las que
 * tenía y el siguiente `add-to-cart` falla en silencio (visto el 2026-09-01:
 * el producto quedó en 0/outofstock y la sonda siguiente midió un carrito
 * vacío creyendo que era un defecto del checkout).
 * ---------------------------------------------------------------------- */

export function fotoStock( ids ) {
	const foto = {};
	ids.forEach( ( id ) => {
		const fila = sqlUno(
			`SELECT CONCAT(IFNULL(stock_quantity,'NULL'),'|',stock_status) FROM wp_wc_product_meta_lookup WHERE product_id=${ id }`
		);
		foto[ id ] = fila;
	} );
	return foto;
}

export function restaurarStock( foto ) {
	const diffs = [];
	Object.entries( foto ).forEach( ( [ id, esperado ] ) => {
		const actual = sqlUno(
			`SELECT CONCAT(IFNULL(stock_quantity,'NULL'),'|',stock_status) FROM wp_wc_product_meta_lookup WHERE product_id=${ id }`
		);
		if ( actual === esperado ) {
			return;
		}
		const [ cantidad, estado ] = esperado.split( '|' );
		const setQty = 'NULL' === cantidad ? 'null' : `${ parseInt( cantidad, 10 ) }`;
		evalPhp(
			`$p = wc_get_product( ${ id } ); $p->set_stock_quantity( ${ setQty } ); $p->set_stock_status( '${ estado }' ); $p->save(); echo 'ok';`
		);
		const tras = sqlUno(
			`SELECT CONCAT(IFNULL(stock_quantity,'NULL'),'|',stock_status) FROM wp_wc_product_meta_lookup WHERE product_id=${ id }`
		);
		diffs.push( `${ id }: ${ actual } -> ${ tras } (esperado ${ esperado })` );
	} );
	return diffs;
}

export function stockIgual( foto ) {
	return Object.entries( foto ).every(
		( [ id, esperado ] ) =>
			sqlUno(
				`SELECT CONCAT(IFNULL(stock_quantity,'NULL'),'|',stock_status) FROM wp_wc_product_meta_lookup WHERE product_id=${ id }`
			) === esperado
	);
}

/* -------------------------------------------------------------------------
 * Opciones `akb_pudo_state_*`: cada sesión de checkout deja UNA fila (no
 * autoload, TTL de 1 h). Seis corridas dejaron seis filas que el espejo no
 * tenía (medido el 2026-09-01: ninguna aparecía en el baseline previo y sus
 * marcas de tiempo caen dentro de esta ronda). No es basura inofensiva: la
 * regla de la casa es antes = después, así que se recogen.
 *
 * LO QUE SE COMPARA SON NOMBRES, NO EL CONTEO. Estas filas tienen TTL de 1 h y
 * se autoborran: si una fila PREVIA vencía durante la sonda, el conteo bajaba y
 * el check «antes = después» se ponía rojo por una expiración que la sonda ni
 * provocó ni podía evitar. Lo que se le exige a una sonda es no dejar filas
 * NUEVAS; que el espejo recoja las suyas es asunto del espejo. Por eso la foto
 * guarda `option_name` (estable) y no `option_id`.
 * ---------------------------------------------------------------------- */

export function fotoOpcionesPudo() {
	return sql( "SELECT option_name FROM wp_options WHERE option_name LIKE 'akb_pudo_state\\_%'" )
		.split( '\n' )
		.map( ( l ) => l.trim() )
		.filter( Boolean );
}

/** Nombres `akb_pudo_state_*` presentes AHORA que no estaban en `foto`. */
export function opcionesPudoNuevas( foto ) {
	const previas = new Set( foto );
	return fotoOpcionesPudo().filter( ( nombre ) => ! previas.has( nombre ) );
}

/** Borra por NOMBRE EXACTO las filas nacidas después de `foto`. Devuelve cuáles. */
export function limpiarOpcionesPudo( foto ) {
	const nuevas = opcionesPudoNuevas( foto );

	if ( nuevas.length ) {
		const lista = nuevas.map( ( n ) => `'${ n.replace( /'/g, "''" ) }'` ).join( ',' );
		sql( `DELETE FROM wp_options WHERE option_name IN (${ lista })` );
	}

	return nuevas;
}

export const DORMIR = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
