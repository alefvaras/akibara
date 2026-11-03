'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const raiz = path.resolve( __dirname, '..' );
const scriptAtc = fs.readFileSync( path.join( raiz, 'assets/js/pdp-ajax-atc.js' ), 'utf8' );
const enqueue = fs.readFileSync( path.join( raiz, 'inc/enqueue.php' ), 'utf8' );
const css = fs.readFileSync( path.join( raiz, 'assets/css/woocommerce.css' ), 'utf8' );

const ramaError = scriptAtc.slice( scriptAtc.indexOf( 'if (!res || res.error)' ), scriptAtc.indexOf( 'if (res.fragments)' ) );

test( 'el rechazo libera busy: si no, el propio código fabrica un clic muerto', () => {
	// `busy` en true con el botón habilitado convierte los clics siguientes en
	// no-ops silenciosos (`if (busy) return;`). Es exactamente el sitio donde
	// Clarity midió clics muertos, y no había ningún error del servidor detrás.
	assert.match( ramaError, /busy\s*=\s*false/, 'la rama de rechazo tiene que liberar busy' );
} );

test( 'el rechazo NO recarga como camino normal', () => {
	// `form.submit()` programático no serializa el submitter, y el único portador
	// de name="add-to-cart" es el botón (cero inputs ocultos, verificado en
	// producción). Así que la recarga NUNCA reintentaba el add: sólo mostraba el
	// aviso que ya estaba en sesión, a cambio de 941 ms y de perder el scroll.
	assert.match( ramaError, /drain_notices=1/, 'se pide el motivo al endpoint propio' );
	assert.match( ramaError, /mostrarMotivo\(/, 'y se pinta junto al botón' );

	// La recarga sobrevive SÓLO como último recurso, cuando no hay motivo que dar.
	// Se cuentan llamadas REALES: las menciones en comentarios no son código.
	const sinComentarios = ramaError
		.split( '\n' )
		.filter( ( l ) => ! /^\s*(\/\/|\*|\/\*)/.test( l ) )
		.join( '\n' );
	const recargas = ( sinComentarios.match( /form\.submit\(\)/g ) || [] ).length;
	assert.ok( recargas >= 1 && recargas <= 2, `form.submit() sólo como respaldo (encontradas ${ recargas })` );
	assert.doesNotMatch(
		ramaError,
		/setTimeout\(function \(\) \{ form\.submit\(\); \}, 600\)/,
		'la recarga a los 600 ms dejó de ser el camino normal'
	);
} );

test( 'el motivo se construye con nodos, nunca con innerHTML', () => {
	// El aviso de WooCommerce trae markup y es filtrable por terceros
	// (`woocommerce_cart_product_not_enough_stock_message`). Inyectarlo como HTML
	// sería meter markup ajeno en la página de compra.
	const helper = scriptAtc.slice( scriptAtc.indexOf( 'function mostrarMotivo' ), scriptAtc.indexOf( 'form.addEventListener' ) );

	assert.match( helper, /createTextNode|textContent/ );
	assert.doesNotMatch( helper, /innerHTML/, 'nunca innerHTML con texto del servidor' );
} );

test( 'los scripts de la ficha están excluidos de la optimización de LiteSpeed', () => {
	// Si LiteSpeed atrasa pdp-ajax-atc.js hasta la primera interacción, ese primer
	// clic hace submit nativo y produce la recarga completa en el camino FELIZ.
	// `akibara-atc-guard` ya estaba excluido; la ficha es más frágil y no lo estaba.
	assert.match( enqueue, /\$list\[\]\s*=\s*'akibara-pdp-ajax-atc';/ );
	assert.match( enqueue, /\$list\[\]\s*=\s*'akibara-sticky-atc';/ );
	assert.match( enqueue, /\$handles\s*=\s*array\([^)]*'akibara-pdp-ajax-atc'[^)]*\)/ );
} );

test( 'el motivo tiene estilo y se puede ocultar', () => {
	assert.match( css, /\.akb-atc-motivo\s*\{/ );
	assert.match( css, /\.akb-atc-motivo\[hidden\]\s*\{[^}]*display:\s*none/ );
} );

test('tras un alta exitosa el botón queda listo para el siguiente clic', () => {
	// Hallazgo S-7295000a: `busy` se liberaba dentro del `reset` diferido, así
	// que durante los dos segundos del «Agregado» el botón estaba habilitado
	// pero los clics eran no-ops. Quien quería dos tomos hacía clic y no pasaba
	// nada — el mismo clic muerto que la rama de error ya evitaba.
	const fuente = fs.readFileSync(
		path.join( raiz, 'assets/js/pdp-ajax-atc.js' ),
		'utf8'
	);

	const exito = fuente.slice( fuente.indexOf( "setState('is-added'" ) );
	const hastaReset = exito.slice( 0, exito.indexOf( 'programarReset(2000)' ) );

	assert.match( hastaReset, /busy = false;/, 'el candado tiene que soltarse ANTES del reset diferido' );
});

/*
 * UM-3: con la barra de compra a la vista, el motivo va DENTRO de la barra.
 *
 * Se ejecuta pdp-ajax-atc.js con dobles del DOM y de fetch: primero el
 * `wc-ajax=add_to_cart` rechaza, después `akb_get_cart` devuelve el motivo.
 * Antes el motivo se insertaba siempre junto al botón real, que con la barra
 * visible está fuera de pantalla (medido a -2.439 px).
 */
const vm = require( 'node:vm' );

function fichaDePrueba( { barraVisible, motivo, extra = [], jquery = false, params = null, primera = null, redCaida = false, barraSinCajas = false } ) {
	const hacer = ( clase ) => {
		const n = {
			className: clase || '',
			hidden: false,
			hijos: [],
			atributos: {},
			// Cada escritura de `disabled` queda anotada: QA-ESC-2 exige que el
			// botón real nunca pase a deshabilitado (soltaría el foco).
			_disabled: false,
			escriturasDisabled: [],
			get disabled() { return this._disabled; },
			set disabled( v ) { this.escriturasDisabled.push( v ); this._disabled = v; },
			_texto: '',
			get textContent() { return this._texto + this.hijos.map( ( h ) => h.textContent ).join( '' ); },
			set textContent( v ) { this._texto = v; this.hijos = []; },
			classList: {
				_s: new Set(),
				add( c ) { this._s.add( c ); },
				remove( ...cs ) { cs.forEach( ( c ) => this._s.delete( c ) ); },
				contains( c ) { return this._s.has( c ); },
			},
			// Cajas de layout: `display:none` no tiene ninguna (QA-ESC-TECLADO-9).
			sinCajas: false,
			getClientRects() { return this.sinCajas ? [] : [ {} ]; },
			appendChild( h ) { this.hijos.push( h ); return h; },
			insertBefore( h ) { this.hijos.unshift( h ); return h; },
			setAttribute( k, v ) { this.atributos[ k ] = String( v ); },
			removeAttribute( k ) { delete this.atributos[ k ]; },
			getAttribute( k ) { return Object.prototype.hasOwnProperty.call( this.atributos, k ) ? this.atributos[ k ] : null; },
			querySelector( sel ) {
				return this.hijos.find( ( h ) => '.' + h.className === sel ) || null;
			},
		};
		return n;
	};

	const btn = hacer( 'single_add_to_cart_button' );
	btn._texto = 'Añadir al carrito';
	const fila = hacer( 'fila' );
	btn.parentNode = fila;
	const pid = { value: '13914' };
	const barra = hacer( 'akb-sticky-atc' );
	if ( barraVisible ) { barra.classList.add( 'is-visible' ); }
	// sticky-atc.js la marca visible, pero la hoja la retira (ventana ≤ 500 px de alto).
	barra.sinCajas = barraSinCajas;
	const proxy = hacer( 'akb-sticky-atc__btn' );
	proxy._texto = 'Añadir al carrito';

	let enviar = null;
	const form = hacer( 'cart' );
	form.addEventListener = ( ev, fn ) => { if ( 'submit' === ev ) { enviar = fn; } };
	form.querySelector = ( sel ) => ( {
		'.single_add_to_cart_button': btn,
		'[name="add-to-cart"]': pid,
		'input.qty': { value: '1' },
		'.akb-atc-motivo': fila.hijos.find( ( h ) => 'akb-atc-motivo' === h.className ) || null,
	}[ sel ] || null );
	form.submit = () => { form.recargas = ( form.recargas || 0 ) + 1; };

	const respuestas = [
		...( primera ? [ primera ] : [] ),
		{ error: true, product_url: '/blue-lock-7/' },
		{ data: { errors: [ 'No puedes añadir esa cantidad al carrito.' ], cart_url: '/carrito/', motivo: motivo || '' } },
		...extra,
	];
	const pedidos = [];
	const temporizadores = [];
	const document = {
		querySelector: ( s ) => ( 'form.cart' === s ? form : null ),
		getElementById: ( id ) => ( { 'akb-sticky-atc': barra, 'akb-sticky-atc-btn': proxy }[ id ] || null ),
		createElement: () => hacer( '' ),
		createTextNode: ( t ) => ( { textContent: t } ),
	};
	const window = { location: { href: '/blue-lock-7/' } };
	// jQuery de mentira: guarda los manejadores de `document.body` por evento.
	const manejadores = {};
	if ( jquery ) {
		window.jQuery = () => ( {
			on( eventos, fn ) { eventos.split( ' ' ).forEach( ( ev ) => { manejadores[ ev ] = fn; } ); return this; },
			// Como WooCommerce (add-to-cart.js): al oír `added_to_cart` marca el botón con `added`.
			trigger( ev ) { if ( 'added_to_cart' === ev ) { btn.classList.add( 'added' ); } return this; },
		} );
	}
	if ( params ) {
		window.wc_add_to_cart_params = params;
	}
	const ctx = vm.createContext( {
		document,
		window,
		FormData: class { append() {} },
		fetch: ( url ) => {
			pedidos.push( url );
			// Sin conexión, fetch rechaza (TypeError «Failed to fetch»).
			if ( redCaida ) { return Promise.reject( new TypeError( 'Failed to fetch' ) ); }
			return Promise.resolve( { json: () => Promise.resolve( respuestas.shift() ) } );
		},
		setTimeout: ( fn, ms ) => { temporizadores.push( { fn, ms, id: temporizadores.length + 1 } ); return temporizadores.length; },
		clearTimeout: ( id ) => { const t = temporizadores.find( ( x ) => x.id === id ); if ( t ) { t.anulado = true; } },
	} );
	vm.runInContext( scriptAtc, ctx );
	return { enviar, barra, fila, form, proxy, btn, pedidos, window, manejadores, temporizadores };
}

const promesas = () => new Promise( ( r ) => setImmediate( r ) );

test( 'UM-3: con la barra a la vista, el motivo se pinta DENTRO de la barra y no junto al botón real', async () => {
	const f = fichaDePrueba( { barraVisible: true } );
	f.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();

	const enBarra = f.barra.hijos.find( ( h ) => 'akb-sticky-atc__motivo' === h.className );
	assert.ok( enBarra, 'la barra lleva el motivo' );
	assert.equal( enBarra.hidden, false );
	assert.match( enBarra.textContent, /No puedes añadir esa cantidad al carrito\. Ver carrito/ );
	assert.equal( f.fila.hijos.length, 0, 'nada se inserta junto al botón real, que está fuera de pantalla' );
	assert.equal( f.proxy.textContent, 'No se pudo agregar' );
	assert.equal( f.form.recargas || 0, 0, 'sin recargar la ficha' );
} );

test( 'UM-3: sin barra (el botón real a la vista), el motivo sigue junto al botón', async () => {
	const f = fichaDePrueba( { barraVisible: false } );
	f.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();

	assert.equal( f.barra.hijos.length, 0 );
	assert.ok( f.fila.hijos.find( ( h ) => 'akb-atc-motivo' === h.className ) );
} );

/*
 * QA-ESC-2 (QA integral, 2026-09-24): con Enter en «Añadir al carrito» el botón
 * se deshabilitaba durante la petición, el foco caía al <body>, el cajón lo
 * guardaba como disparador y, al cerrarlo con Escape, el foco volvía al
 * principio de la página. En vuelo va `aria-disabled`, nunca `disabled`.
 */
test( 'QA-ESC-2: en vuelo el botón real no se deshabilita (conserva el foco) y al responder vuelve a estar activo', async () => {
	const alta = { fragments: {}, cart_hash: 'h' };
	for ( const [ caso, primera ] of [ [ 'alta', alta ], [ 'rechazo', null ] ] ) {
		const f = fichaDePrueba( { barraVisible: false, primera } );
		f.enviar( { preventDefault() {} } );
		assert.equal( f.btn.getAttribute( 'aria-disabled' ), 'true', `${ caso }: en vuelo se anuncia como no disponible` );
		assert.equal( f.btn.disabled, false, `${ caso }: pero sigue enfocable` );
		await promesas();
		await promesas();
		assert.equal( f.btn.getAttribute( 'aria-disabled' ), null, `${ caso }: al responder vuelve a estar activo` );
		assert.deepEqual( f.btn.escriturasDisabled.filter( Boolean ), [], `${ caso }: nunca se escribió disabled = true` );
	}
} );

test( 'UM-3: el motivo de la barra está posicionado sobre ella (no cambia su alto)', () => {
	assert.match( css, /\.akb-sticky-atc__motivo\s*\{[^}]*position:\s*absolute;[^}]*bottom:\s*100%;/ );
	assert.match( css, /\.akb-sticky-atc__motivo\[hidden\]\s*\{[^}]*display:\s*none/ );
} );

/*
 * R-T2-2: el «No se pudo agregar» va sólo en el botón que lleva el motivo.
 *
 * Con los dos botones en rojo, al subir desde la barra hasta el botón real la
 * barra se retiraba llevándose el motivo y el botón real quedaba en rojo sin
 * explicación (medido en el espejo: WebKit móvil, Chromium 1440 y 1366). Y al
 * revés: tras un rechazo junto al botón real, al bajar aparecía la barra en rojo
 * sin motivo.
 */
test( 'R-T2-2: rechazo desde la barra → el botón real vuelve a su etiqueta, sin error sin explicar', async () => {
	const f = fichaDePrueba( { barraVisible: true } );
	f.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();

	assert.equal( f.proxy.textContent, 'No se pudo agregar' );
	assert.ok( f.proxy.classList.contains( 'is-error' ), 'el error queda en la barra, junto a su motivo' );
	assert.equal( f.btn.textContent, 'Añadir al carrito', 'el botón real no dice «No se pudo agregar» sin motivo al lado' );
	assert.equal( f.btn.classList.contains( 'is-error' ), false );
	assert.equal( f.btn.classList.contains( 'is-adding' ), false, 'ni se queda en «Agregando…»' );
} );

test( 'R-T2-2: rechazo desde el botón real → la barra no aparece en rojo sin motivo', async () => {
	const f = fichaDePrueba( { barraVisible: false } );
	f.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();

	assert.equal( f.btn.textContent, 'No se pudo agregar' );
	assert.ok( f.btn.classList.contains( 'is-error' ) );
	assert.equal( f.proxy.textContent, 'Añadir al carrito', 'la barra, si aparece, no lleva un error sin su porqué' );
	assert.equal( f.proxy.classList.contains( 'is-error' ), false );
	assert.equal( f.proxy.classList.contains( 'is-adding' ), false );
} );

/*
 * R-T2-1: mientras el motivo está a la vista, el botón de WhatsApp cede.
 *
 * Joinchat (tercero, fijo abajo a la derecha) tapaba el final de la primera
 * línea del motivo de la barra en 390x844 (Chromium y WebKit), y según el scroll
 * el «Ver carrito» del motivo junto al botón real. El comportamiento en el
 * navegador lo cubre tools/akibara-e2e/specs/tema-rechazo-tarjeta.spec.mjs;
 * aquí, la forma de la regla.
 */
test( 'R-T2-1: el botón de WhatsApp cede mientras se ve un motivo, nunca con el chat abierto', () => {
	const cede = '\\.joinchat:not\\(\\.joinchat--chatbox\\):not\\(:focus-within\\)';
	const regla = css.match( new RegExp( '(body:has\\([^{]*' + cede + ')\\s*\\{([^}]*)\\}' ) );
	assert.ok( regla, 'hay una regla que retira Joinchat según el motivo' );
	const [ , selectores, cuerpo ] = regla;
	const lista = selectores.split( /,\s*\n/ ).map( ( x ) => x.trim() );
	assert.ok(
		lista.includes( 'body:has(#akb-sticky-atc.is-visible .akb-sticky-atc__motivo:not([hidden])) .joinchat:not(.joinchat--chatbox):not(:focus-within)' ),
		'el motivo de la barra cuenta sólo con la barra a la vista'
	);
	assert.ok(
		lista.includes( 'body:has(form.cart .akb-atc-motivo:not([hidden])):not(:has(#akb-sticky-atc.is-visible)) .joinchat:not(.joinchat--chatbox):not(:focus-within)' ),
		'el de junto al botón real, sólo mientras la barra no se muestra (el form está en pantalla)'
	);
	assert.equal( lista.length, 2, 'y nada más en la lista' );
	assert.match( cuerpo, /visibility:\s*hidden;/ );
	assert.match( cuerpo, /pointer-events:\s*none;/ );
	// Regla sola: si compartiera la lista con un selector sin `:has()`, un
	// navegador que no lo entiende tiraría también el otro.
	assert.ok( lista.every( ( x ) => x.startsWith( 'body:has(' ) ), 'todos sus selectores usan :has()' );
} );

/*
 * UM-3 (lote de integración): con el código `ya_en_carrito` los DOS botones
 * pasan a «Ver carrito» (sin rojo) y llevan al carrito; volver a pulsar ya no
 * repite el rechazo. El motivo se pinta sin su propio enlace.
 */
test( 'UM-3: con el código ya_en_carrito, los dos botones pasan a «Ver carrito» y el siguiente envío lleva al carrito', async () => {
	const f = fichaDePrueba( { barraVisible: true, motivo: 'ya_en_carrito' } );
	f.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();

	assert.match( f.pedidos[ 1 ], /wc-ajax=akb_get_cart&drain_notices=1&product_id=13914&quantity=1$/, 'la petición del motivo dice qué se intentó agregar' );
	assert.equal( f.proxy.textContent, 'Ver carrito' );
	assert.equal( f.btn.textContent, 'Ver carrito' );
	assert.ok( f.proxy.classList.contains( 'is-ver-carrito' ) );
	assert.ok( f.btn.classList.contains( 'is-ver-carrito' ) );
	assert.equal( f.proxy.classList.contains( 'is-error' ), false, 'no queda en rojo invitando a repetir' );
	const enBarra = f.barra.hijos.find( ( h ) => 'akb-sticky-atc__motivo' === h.className );
	assert.equal( enBarra.textContent, 'No puedes añadir esa cantidad al carrito. ', 'sin «Ver carrito» duplicado: el botón ya lo es' );

	const antes = f.pedidos.length;
	f.enviar( { preventDefault() {} } );
	assert.equal( f.window.location.href, '/carrito/' );
	assert.equal( f.pedidos.length, antes, 'no se vuelve a intentar agregar' );
} );

test( 'UM-3: el estado «Ver carrito» es secundario, no el rojo de comprar', () => {
	assert.match( css, /\.single_add_to_cart_button\.is-ver-carrito,[^{]*\.akb-sticky-atc__btn\.is-ver-carrito[^{]*\{[^}]*border-color:\s*var\(--aki-btn-secondary-border\)/ );
} );

/*
 * R-I1-1 (revisión del lote I1): al cambiar el carrito, los botones volvían a
 * decir «Añadir al carrito» pero se quedaban con `is-ver-carrito`: negros y
 * secundarios hasta recargar, y un alta posterior («Agregado ✓») también salía
 * negra, porque esa regla va después de la de `is-added`.
 */
test( 'R-I1-1: cuando el carrito cambia, los dos botones vuelven a comprar sin «Ver carrito» y el siguiente envío agrega', async () => {
	const f = fichaDePrueba( { barraVisible: false, motivo: 'ya_en_carrito', jquery: true, extra: [ { fragments: {}, cart_hash: 'h' } ] } );
	// WooCommerce dejó `added` en el botón en el alta anterior (add-to-cart.js).
	f.btn.classList.add( 'added' );
	f.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();
	assert.ok( f.btn.classList.contains( 'is-ver-carrito' ) );
	assert.ok( f.proxy.classList.contains( 'is-ver-carrito' ) );
	// R-I1-3: sin la ✓ de WooCommerce (`.added::after`) en un intento que no agregó.
	assert.equal( f.btn.classList.contains( 'added' ), false, '«Ver carrito ✓» mentía: este intento no agregó nada' );

	assert.equal( typeof f.manejadores.wc_fragment_refresh, 'function', 'escucha el aviso del cajón' );
	assert.equal( f.manejadores.removed_from_cart, f.manejadores.wc_fragment_refresh, 'y el del mini-carrito de WooCommerce' );
	f.manejadores.wc_fragment_refresh();
	for ( const b of [ f.btn, f.proxy ] ) {
		assert.equal( b.classList.contains( 'is-ver-carrito' ), false, 'sin «Ver carrito» pegado: si no, el botón queda negro' );
		assert.equal( b.textContent, 'Añadir al carrito' );
	}
	const motivo = f.fila.hijos.find( ( h ) => 'akb-atc-motivo' === h.className );
	assert.equal( motivo.hidden, true, 'el motivo hablaba del carrito de antes' );

	const antes = f.pedidos.length;
	f.enviar( { preventDefault() {} } );
	assert.equal( f.window.location.href, '/blue-lock-7/', 'ya no navega al carrito' );
	assert.equal( f.pedidos.length, antes + 1 );
	assert.match( f.pedidos[ antes ], /wc-ajax=add_to_cart/, 'vuelve a POSTear el alta' );
	await promesas();
	await promesas();
	assert.ok( f.btn.classList.contains( 'is-added' ) );
	assert.equal( f.btn.classList.contains( 'is-ver-carrito' ), false, '«Agregado ✓» en verde, no en el negro de «Ver carrito»' );
	assert.equal( f.proxy.classList.contains( 'is-ver-carrito' ), false );
	// R-I1-3: WooCommerce acaba de poner `added` (su ✓ en ::after): «AGREGADO ✓ ✓».
	assert.equal( f.btn.classList.contains( 'added' ), false, 'una sola ✓: la del rótulo' );
} );

/*
 * Revisión RV-I1-2-1: un rechazo SIN «Ver carrito» (sin_stock, o sin código)
 * también hablaba del carrito de antes. Medido en el espejo: con el carrito ya
 * vacío el botón seguía en «No se pudo agregar» y el aviso decía «has añadido 1
 * en tu carrito» hasta 6 s. Antes esta prueba fijaba justo eso.
 */
test( 'RV-I1-2-1: cuando el carrito cambia, un rechazo sin «Ver carrito» también se retira', async () => {
	const f = fichaDePrueba( { barraVisible: false, motivo: 'sin_stock', jquery: true } );
	f.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();
	assert.ok( f.btn.classList.contains( 'is-error' ) );
	const motivo = f.fila.hijos.find( ( h ) => 'akb-atc-motivo' === h.className );
	assert.equal( motivo.hidden, false );

	f.manejadores.wc_fragment_refresh();
	assert.equal( f.btn.textContent, 'Añadir al carrito', 'el botón vuelve a su rótulo' );
	assert.equal( f.btn.classList.contains( 'is-error' ), false );
	assert.equal( motivo.hidden, true, 'el motivo contaba un carrito que ya cambió' );
	const vivos = f.temporizadores.filter( ( t ) => ! t.anulado );
	assert.equal( vivos.length, 0, 'y el reinicio de 6 s ya no tiene nada que hacer' );
} );

test( 'RV-I1-2-1: un «Agregado ✓» no se toca cuando el carrito cambia', async () => {
	const f = fichaDePrueba( { barraVisible: false, motivo: '', jquery: true, primera: { fragments: {}, cart_hash: 'h' } } );
	f.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();
	assert.ok( f.btn.classList.contains( 'is-added' ) );
	f.manejadores.wc_fragment_refresh();
	assert.ok( f.btn.classList.contains( 'is-added' ), 'no habla del carrito de antes' );
	assert.equal( f.btn.textContent, 'Agregado ✓' );
	assert.ok( f.temporizadores.some( ( t ) => 2000 === t.ms && ! t.anulado ), 'su reinicio de 2 s sigue en pie' );
} );

/*
 * Revisión RV2-T2-2: el motivo de un intento no puede seguir a la vista en el
 * OTRO sitio cuando llega el siguiente. Rechazo en la barra, se sube y se pulsa
 * el botón real: al bajar, la barra enseñaba el motivo viejo con su botón en
 * «Añadir al carrito».
 */
test( 'RV2-T2-2: un rechazo nuevo en el otro sitio retira el motivo del anterior', async () => {
	const f = fichaDePrueba( {
		barraVisible: true,
		motivo: '',
		extra: [
			{ error: true, product_url: '/blue-lock-7/' },
			{ data: { errors: [ 'Otra vez no.' ], cart_url: '/carrito/', motivo: '' } },
		],
	} );
	f.enviar( { preventDefault() {} } ); // desde la barra
	await promesas();
	await promesas();
	const enBarra = f.barra.hijos.find( ( h ) => 'akb-sticky-atc__motivo' === h.className );
	assert.equal( enBarra.hidden, false, 'primer motivo, en la barra' );

	f.barra.classList.remove( 'is-visible' ); // el cliente sube al botón real
	f.enviar( { preventDefault() {} } );
	assert.equal( enBarra.hidden, true, 'el intento nuevo retira el motivo anterior al empezar' );
	await promesas();
	await promesas();
	const junto = f.fila.hijos.find( ( h ) => 'akb-atc-motivo' === h.className );
	assert.equal( junto.hidden, false, 'el nuevo, junto al botón real' );
	assert.equal( enBarra.hidden, true, 'y el de la barra no vuelve' );
	assert.ok( f.btn.classList.contains( 'is-error' ) );
	assert.equal( f.proxy.classList.contains( 'is-error' ), false, 'el error sólo donde está su motivo' );
	const vivos = f.temporizadores.filter( ( t ) => ! t.anulado );
	assert.deepEqual( vivos.map( ( t ) => t.ms ), [ 6000 ], 'un solo reinicio: el del último rechazo' );
} );

/*
 * R-I1-3: en el teléfono el :hover se queda pegado tras el toque, y el hover
 * del botón de compra (`.woocommerce button.button.alt:hover`, (0,4,1)) le
 * ganaba a «Ver carrito» (0,4,0): salía en el rojo oscuro de comprar.
 */
test( 'R-I1-3: «Ver carrito» conserva su negro también con :hover pegado (fuera de la media de hover)', () => {
	const base = css.match( /((?:[^{}]*is-ver-carrito[^{}]*,\s*)+[^{}]*is-ver-carrito[^{}]*)\{([^}]*)\}/ );
	assert.ok( base, 'regla de «Ver carrito»' );
	const selectores = base[ 1 ].split( ',' ).map( ( x ) => x.replace( /\/\*[\s\S]*?\*\//g, '' ).trim() );
	assert.ok( selectores.includes( '.woocommerce .product .single_add_to_cart_button.is-ver-carrito:hover' ), '(0,5,0): le gana a `.woocommerce button.button.alt:hover`' );
	assert.ok( selectores.includes( '.akb-sticky-atc__btn.is-ver-carrito:hover' ) );
	assert.match( base[ 2 ], /background:\s*var\(--aki-black\)/ );
	const posBase = css.indexOf( base[ 0 ] );
	const media = css.indexOf( '@media (hover: hover)', posBase );
	assert.ok( media > posBase, 'el blanco del hover de verdad va DESPUÉS, dentro de la media: con la misma especificidad, gana ahí' );
	assert.match( css.slice( media, media + 400 ), /is-ver-carrito:hover[^{]*\{[^}]*background:\s*var\(--aki-white\)/ );
} );

/*
 * Revisión RV-I1-2-2 (preexistente desde 2.1.142): la misma cascada que R-I1-3.
 * `.single_add_to_cart_button.is-error` (0,2,0) perdía siempre contra
 * `.woocommerce button.button.alt` (0,3,1) y su :hover (0,4,1): «No se pudo
 * agregar» salía en el rojo de comprar en el botón real y en negro en la barra.
 */
test( 'RV-I1-2-2: «No se pudo agregar» gana en el botón real, también con :hover pegado', () => {
	const sinComentarios = css.replace( /\/\*[\s\S]*?\*\//g, '' );
	const base = sinComentarios.match( /([^{}]*\.single_add_to_cart_button\.is-error[^{}]*)\{([^}]*)\}/ );
	assert.ok( base, 'regla del estado de error' );
	const selectores = base[ 1 ].split( ',' ).map( ( x ) => x.replace( /\/\*[\s\S]*?\*\//g, '' ).trim() );
	assert.ok( selectores.includes( '.woocommerce .product .single_add_to_cart_button.is-error' ), '(0,4,0): le gana a `.woocommerce button.button.alt` (0,3,1)' );
	assert.ok( selectores.includes( '.woocommerce .product .single_add_to_cart_button.is-error:hover' ), '(0,5,0): le gana a su :hover (0,4,1)' );
	assert.ok( selectores.includes( '.akb-sticky-atc__btn.is-error:hover' ) );
	assert.ok( ! selectores.includes( '.single_add_to_cart_button.is-error' ), 'la versión de (0,2,0) no gana nunca: sería código muerto' );
	assert.match( base[ 2 ], /background:\s*var\(--aki-black\)/ );
	assert.match( base[ 2 ], /border-color:\s*var\(--aki-red\)/ );
} );

/*
 * R-I1-6: el rótulo del botón y del enlace del motivo es el de WooCommerce
 * (`i18n_view_cart`), el mismo que usan las tarjetas (atc-guard.js).
 */
test( 'R-I1-6: «Ver carrito» usa el rótulo de WooCommerce, como las tarjetas', async () => {
	const f = fichaDePrueba( { barraVisible: false, motivo: 'ya_en_carrito', params: { i18n_view_cart: 'Ir al carrito' } } );
	f.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();
	assert.equal( f.btn.textContent, 'Ir al carrito' );
	assert.equal( f.proxy.textContent, 'Ir al carrito' );

	const g = fichaDePrueba( { barraVisible: false, motivo: '', params: { i18n_view_cart: 'Ir al carrito' } } );
	g.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();
	const motivo = g.fila.hijos.find( ( h ) => 'akb-atc-motivo' === h.className );
	assert.match( motivo.textContent, /Ir al carrito$/, 'el enlace del motivo dice lo mismo' );
	assert.doesNotMatch( scriptAtc.replace( /\/\*[\s\S]*?\*\//g, '' ).replace( /^\s*\/\/.*$/gm, '' ), /textContent = 'Ver carrito'|, 'Ver carrito'\)/, 'sin el literal clavado en el código' );
} );

/*
 * Carrera del reinicio (medida en el espejo al corregir R-I1-1): agregar, cerrar
 * el cajón y volver a pulsar antes de 2 s. El reinicio del alta (2 s) seguía
 * pendiente y escondía el motivo del rechazo a ~1,3 s de aparecer, en vez de a
 * los 6. Queda UN solo reinicio pendiente: el del último resultado.
 */
test( 'el reinicio del alta anterior no esconde el motivo del rechazo siguiente', async () => {
	const f = fichaDePrueba( { barraVisible: false, motivo: 'ya_en_carrito', primera: { fragments: {}, cart_hash: 'h' } } );
	f.enviar( { preventDefault() {} } ); // alta correcta
	await promesas();
	await promesas();
	assert.ok( f.btn.classList.contains( 'is-added' ) );
	const delAlta = f.temporizadores.find( ( t ) => 2000 === t.ms );
	assert.ok( delAlta, 'el alta programa su reinicio' );

	f.enviar( { preventDefault() {} } ); // rechazo antes de los 2 s
	await promesas();
	await promesas();
	assert.ok( delAlta.anulado, 'el reinicio del alta se anula al pulsar otra vez' );
	const vivos = f.temporizadores.filter( ( t ) => ! t.anulado );
	assert.deepEqual( vivos.map( ( t ) => t.ms ), [ 6000 ], 'queda sólo el del motivo' );
	const motivo = f.fila.hijos.find( ( h ) => 'akb-atc-motivo' === h.className );
	assert.equal( motivo.hidden, false );
	vivos[ 0 ].fn();
	assert.equal( motivo.hidden, true, 'y a los 6 s se retira' );
} );


/*
 * QAM-8 (QA integral, 2026-09-24): con la red caída la ficha decía «Reintentar»
 * 3 s y volvía a «Añadir al carrito» sin explicar nada a la vista; el motivo sólo
 * lo oía el lector de pantalla. La tarjeta, en cambio, ya lo pintaba.
 */
const motivoDe = ( nodo, clase ) => nodo.hijos.find( ( h ) => clase === h.className );

test( 'QAM-8: red caída con la barra a la vista → el motivo se VE en la barra, con «Reintentar» sólo en ella', async () => {
	const f = fichaDePrueba( { barraVisible: true, redCaida: true } );
	f.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();

	const caja = motivoDe( f.barra, 'akb-sticky-atc__motivo' );
	assert.ok( caja, 'antes no había motivo visible, sólo el anuncio al lector' );
	assert.equal( caja.hidden, false );
	assert.equal( caja.textContent, 'No se pudo conectar, reintenta ', 'el mismo texto que la tarjeta (atc-guard.js)' );
	assert.equal( f.proxy.textContent, 'Reintentar' );
	assert.ok( f.proxy.classList.contains( 'is-error' ) );
	assert.equal( f.btn.textContent, 'Añadir al carrito', 'el botón real no queda en error sin su porqué' );
	assert.equal( f.form.recargas || 0, 0, 'sin recargar' );

	// Dura lo mismo que un rechazo y, al vencer, todo vuelve a su reposo.
	const pendiente = f.temporizadores.filter( ( t ) => ! t.anulado ).pop();
	assert.equal( pendiente.ms, 6000, 'antes eran 3 s' );
	pendiente.fn();
	assert.equal( caja.hidden, true );
	assert.equal( f.proxy.textContent, 'Añadir al carrito' );
	assert.equal( f.proxy.classList.contains( 'is-error' ), false );
} );

test( 'QAM-8: red caída sin barra → el motivo se VE junto al botón real, y el reintento lo retira', async () => {
	const f = fichaDePrueba( { barraVisible: false, redCaida: true } );
	f.enviar( { preventDefault() {} } );
	await promesas();
	await promesas();

	const caja = motivoDe( f.fila, 'akb-atc-motivo' );
	assert.ok( caja );
	assert.equal( caja.hidden, false );
	assert.equal( f.btn.textContent, 'Reintentar' );
	assert.equal( f.btn.getAttribute( 'aria-disabled' ), null, 'el botón queda activo para reintentar' );
	assert.equal( f.proxy.classList.contains( 'is-error' ), false );

	// Un intento nuevo retira la explicación del anterior.
	f.enviar( { preventDefault() {} } );
	assert.equal( caja.hidden, true );
} );

/*
 * QA-ESC-TECLADO-9: en una ventana baja la hoja retira la barra con
 * `display:none` aunque sticky-atc.js la marque `.is-visible`. El motivo no puede
 * ir a una barra que no se ve.
 */
test( 'QA-ESC-TECLADO-9: barra marcada visible pero sin pintar → el motivo va junto al botón real', async () => {
	for ( const redCaida of [ false, true ] ) {
		const f = fichaDePrueba( { barraVisible: true, barraSinCajas: true, redCaida } );
		f.enviar( { preventDefault() {} } );
		await promesas();
		await promesas();

		assert.equal( f.barra.hijos.length, 0, `${ redCaida ? 'red caída' : 'rechazo' }: nada dentro de la barra oculta` );
		assert.ok( motivoDe( f.fila, 'akb-atc-motivo' ), 'el motivo junto al botón real, que es el que se ve' );
		assert.ok( f.btn.classList.contains( 'is-error' ) );
		assert.equal( f.proxy.classList.contains( 'is-error' ), false );
	}
} );
