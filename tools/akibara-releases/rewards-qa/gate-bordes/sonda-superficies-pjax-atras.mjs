/**
 * sonda-superficies-pjax-atras.mjs — CASO 3 del gate de SUPERFICIES Y CACHÉ.
 *
 * EL CASO: el fetch tardío que pisa el Atrás. El cliente está en un listado
 * filtrado (estado B), marca OTRA casilla y pulsa Atrás antes de que ese
 * segundo filtro aterrice. El Atrás es intención explícita: tiene que ganar.
 *
 * Estados de la historia, todos construidos POR PJAX en el MISMO documento
 * (llegar a B con `goto` mete una entrada de otro documento y el Atrás pasa a
 * ser una navegación completa: se mediría otra cosa):
 *   A = /tienda/                                   1.568 productos
 *   B = /tienda/?_disponibilidad=instock             994 productos
 *   C = /tienda/?_disponibilidad=instock,a_pedido  1.416 productos
 *
 * Tras el Atrás el destino legítimo es A o B — NUNCA C, que es justamente el
 * estado del que el usuario se estaba yendo. Y sea cual sea el destino, el
 * listado pintado tiene que coincidir con lo que el SERVIDOR sirve para la URL
 * de la barra: se pide esa misma URL y se comparan total, primer título y
 * casillas marcadas. `cards` no discrimina (siempre 24 por página).
 *
 * DEFECTO VIVO que esta sonda caza (medido el 2026-09-01, reproducible):
 * con retardos de 0–80 ms el Atrás se pierde. `popstate` llama a
 * `cancelInflight()`, que aborta el fetch EN VUELO... pero el cambio de la
 * casilla todavía no ha disparado ningún fetch: está esperando el debounce de
 * 100 ms (`debounce = setTimeout( submitForm, 100 )`, akb-search.js ~789).
 * Ese timer NO se cancela, dispara después del popstate y hace `pushState` a
 * C. Medido: URL y DOM en C, y `history.length` sin crecer — la entrada de
 * adelante se destruye. Con 95 ms o más el fetch ya vuela y `cancelInflight()`
 * sí lo mata.
 *
 * No siembra nada ni guarda ningún término: sólo navega.
 *
 * Uso: node sonda-superficies-pjax-atras.mjs   (exit != 0 si algo falla)
 */

import { BASE, ESCRITORIO, abrir, crearMarcador } from './lib-bordes.mjs';

const m = crearMarcador( 'superficies-pjax-atras' );

const URL_A = '/tienda/';
const URL_B = '/tienda/?_disponibilidad=instock';
const URL_C = '/tienda/?_disponibilidad=instock%2Ca_pedido';

/** Estado visible del listado tal y como está pintado AHORA. */
async function estadoDom( pagina ) {
	return pagina.evaluate( () => ( {
		url: location.pathname + location.search,
		total: ( document.querySelector( '.akb-result-count' )?.textContent || '' ).trim(),
		cards: document.querySelectorAll( 'ul.products li.product' ).length,
		marcados: [ ...document.querySelectorAll( 'input[name="_disponibilidad[]"]:checked' ) ].map( ( i ) => i.value ).sort(),
		primero: ( document.querySelector( '.woocommerce-loop-product__title' )?.textContent || '' ).trim(),
		historia: history.length,
	} ) );
}

/** Lo que el SERVIDOR sirve hoy para la URL que la barra muestra. */
async function estadoServidor( pagina ) {
	return pagina.evaluate( async () => {
		const r = await fetch( location.href, { credentials: 'same-origin' } );
		const doc = new DOMParser().parseFromString( await r.text(), 'text/html' );
		return {
			status: r.status,
			total: ( doc.querySelector( '.akb-result-count' )?.textContent || '' ).trim(),
			marcados: [ ...doc.querySelectorAll( 'input[name="_disponibilidad[]"][checked]' ) ].map( ( i ) => i.value ).sort(),
			primero: ( doc.querySelector( '.woocommerce-loop-product__title' )?.textContent || '' ).trim(),
		};
	} );
}

/**
 * Espera a que la barra muestre una URL concreta.
 *
 * Esperar «a que el DOM deje de moverse» daba falsos verdes: si el swap tarda
 * más que el sondeo, dos lecturas PRE-swap salen iguales y la espera termina
 * antes de tiempo (medido el 2026-09-01). Los pasos deterministas esperan la
 * URL; sólo el desenlace de la carrera se deja asentar por tiempo.
 */
async function esperarUrl( pagina, esperada ) {
	await pagina.waitForFunction(
		( u ) => location.pathname + location.search === u,
		esperada,
		{ timeout: 20000 }
	);
	await pagina.waitForTimeout( 500 );
}

/**
 * Espera a que el listado TERMINE de intercambiarse.
 *
 * Tras un `popstate` el swap es otro fetch: la URL cambia primero y el
 * contenido llega después. Asegurar sólo la URL medía el DOM a medio camino y
 * el check de coherencia daba rojo con el buscador correcto (medido el
 * 2026-09-01). La espera está ACOTADA y no decide nada: si el contenido no
 * converge nunca, el check sigue fallando — que es justo lo que debe pasar.
 */
async function esperarSwap( pagina, urlEsperada, ms = 12000 ) {
	await esperarUrl( pagina, urlEsperada );
	const hasta = Date.now() + ms;
	let previo = '';
	while ( Date.now() < hasta ) {
		const s = JSON.stringify( await estadoDom( pagina ) );
		if ( s === previo ) {
			return;
		}
		previo = s;
		await pagina.waitForTimeout( 700 );
	}
}

/** Historia A -> B por PJAX, dentro del mismo documento. */
async function historiaAB( pagina ) {
	await pagina.goto( BASE + URL_A, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForTimeout( 1200 );
	await pagina.check( 'input[name="_disponibilidad[]"][value="instock"]' );
	await esperarSwap( pagina, URL_B );
}

let navegador = null;

try {
	const sesion = await abrir( ESCRITORIO );
	navegador = sesion.navegador;
	const pagina = sesion.pagina;

	await pagina.goto( BASE + URL_A, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForTimeout( 1200 );
	const a = await estadoDom( pagina );
	m.check(
		'estado-A-catalogo-completo',
		a.cards > 0 && a.marcados.length === 0,
		`url=${ a.url } total="${ a.total }" cards=${ a.cards } primero="${ a.primero }"`
	);

	/* ------------------------------------------------------------------
	 * 3.1 El filtro hace PJAX de verdad: cambia URL y listado sin recarga.
	 * --------------------------------------------------------------- */
	await pagina.check( 'input[name="_disponibilidad[]"][value="instock"]' );
	await esperarUrl( pagina, URL_B );
	const b = await estadoDom( pagina );
	m.check(
		'filtro-disponibilidad-hace-pjax',
		b.url === URL_B && b.total !== a.total && b.marcados.join() === 'instock',
		`url=${ b.url } total="${ b.total }" (antes "${ a.total }") marcados=[${ b.marcados }] primero="${ b.primero }"`
	);

	/* ------------------------------------------------------------------
	 * 3.1b Control positivo: A, B y C tienen que ser distinguibles, o los
	 *      checks de abajo no podrían fallar y no serían checks.
	 * --------------------------------------------------------------- */
	const totalC = await pagina.evaluate( async ( url ) => {
		const r = await fetch( url, { credentials: 'same-origin' } );
		const doc = new DOMParser().parseFromString( await r.text(), 'text/html' );
		return ( doc.querySelector( '.akb-result-count' )?.textContent || '' ).trim();
	}, URL_C );
	m.check(
		'control-A-B-C-son-distinguibles',
		new Set( [ a.total, b.total, totalC ] ).size === 3,
		`A="${ a.total }" B="${ b.total }" C="${ totalC }"`
	);

	/* ------------------------------------------------------------------
	 * 3.2 LA CARRERA. Cuatro retardos: dos dentro de la ventana del
	 *     debounce de 100 ms y dos fuera. Un solo retardo puede caer en la
	 *     zona buena y dar verde por suerte.
	 * --------------------------------------------------------------- */
	for ( const retardo of [ 0, 60, 250, 700 ] ) {
		await historiaAB( pagina );
		const antes = await estadoDom( pagina );

		await pagina.check( 'input[name="_disponibilidad[]"][value="a_pedido"]' );
		await pagina.waitForTimeout( retardo );
		await pagina.goBack();
		await pagina.waitForTimeout( 5000 );

		const tras = await estadoDom( pagina );
		const servidor = await estadoServidor( pagina );

		// (a) El Atrás manda: el destino sólo puede ser A o B, jamás C.
		m.check(
			`atras-${ retardo }ms-no-aterriza-en-el-estado-que-se-abandonaba`,
			tras.url === URL_A || tras.url === URL_B,
			`retardo=${ retardo }ms · URL tras el Atrás = ${ tras.url } (legítimas: ${ URL_A } o ${ URL_B }; C = ${ URL_C } es el estado del que se salía) · marcados=[${ tras.marcados }] total="${ tras.total }" · history.length ${ antes.historia }->${ tras.historia }`
		);

		// (b) Sea cual sea el destino, DOM y URL cuentan la misma historia.
		m.check(
			`atras-${ retardo }ms-listado-coherente-con-su-URL`,
			tras.total === servidor.total &&
				tras.primero === servidor.primero &&
				tras.marcados.join() === servidor.marcados.join(),
			`retardo=${ retardo }ms url=${ tras.url } · DOM total="${ tras.total }" primero="${ tras.primero }" marcados=[${ tras.marcados }] || SERVIDO total="${ servidor.total }" primero="${ servidor.primero }" marcados=[${ servidor.marcados }] (HTTP ${ servidor.status })`
		);
	}

	/* ------------------------------------------------------------------
	 * 3.3 Adelante/Atrás sin carrera: la historia tiene que seguir viva.
	 * --------------------------------------------------------------- */
	await historiaAB( pagina );
	await pagina.check( 'input[name="_disponibilidad[]"][value="a_pedido"]' );
	await esperarSwap( pagina, URL_C );

	await pagina.goBack();
	await esperarSwap( pagina, URL_B );
	const vuelta = await estadoDom( pagina );
	const vueltaServida = await estadoServidor( pagina );
	m.check(
		'atras-sin-carrera-devuelve-al-estado-B',
		vuelta.url === URL_B && vuelta.total === vueltaServida.total && vuelta.marcados.join() === 'instock',
		`url=${ vuelta.url } DOM total="${ vuelta.total }" servido="${ vueltaServida.total }" marcados=[${ vuelta.marcados }]`
	);

	await pagina.goForward();
	await esperarSwap( pagina, URL_C );
	const adelante = await estadoDom( pagina );
	const adelanteServido = await estadoServidor( pagina );
	m.check(
		'adelante-restituye-el-estado-con-los-dos-filtros',
		adelante.marcados.length === 2 && adelante.total === adelanteServido.total,
		`url=${ adelante.url } marcados=[${ adelante.marcados }] DOM total="${ adelante.total }" servido="${ adelanteServido.total }"`
	);

	await pagina.goBack();
	await esperarSwap( pagina, URL_B );
	await pagina.goBack();
	await esperarSwap( pagina, URL_A );
	const alPrincipio = await estadoDom( pagina );
	m.check(
		'dos-atras-devuelven-al-catalogo-sin-filtros',
		alPrincipio.marcados.length === 0 && alPrincipio.total === a.total,
		`url=${ alPrincipio.url } marcados=[${ alPrincipio.marcados }] total="${ alPrincipio.total }" (inicio "${ a.total }")`
	);
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, `EXCEPCIÓN: ${ error.message }` );
} finally {
	if ( navegador ) {
		await navegador.close();
	}
}

process.exit( m.cerrar() );
