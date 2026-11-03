'use strict';

/*
 * El gancho de cuenta no puede mandar a buscar una casilla que está tres
 * pantallas más arriba.
 *
 * Medido en producción el 2026-09-15 (390×844): la casilla nativa «Crear una
 * cuenta con Akibara» a 515 px, el gancho del resumen a 2.805 px. El texto
 * decía «Marca "Crear una cuenta"» y el cliente tenía que subir a buscarla.
 * El dueño lo vio en su teléfono: «al tocarla me lleve arriba y marque crear
 * cuenta».
 *
 * Estas pruebas EJECUTAN el script con dobles de wp/wc y un DOM mínimo: no
 * buscan texto en el fuente, pulsan el botón y miran qué le pasó a la casilla.
 */

const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );

const { INVITADO, WOO, montar, nodos, texto, boton } = require( './support/arnes.js' );

test( 'el botón marca la casilla nativa, la trae al centro y le da el foco', () => {
	const m = montar();
	const b = boton( m.pintar() );

	assert.ok( b, 'el gancho tiene que ofrecer un botón' );
	assert.equal( b.p.type, 'button', 'un botón sin type enviaría el formulario del checkout' );
	assert.equal( texto( b ), 'Crear mi cuenta' );

	b.p.onClick();

	assert.equal( m.casilla.checked, true, 'la casilla de WooCommerce queda marcada' );
	assert.equal( m.casilla.clics, 1, 'se marca con UN clic real, el mismo camino que el dedo' );
	// Copias en este reino: los objetos creados dentro de vm tienen otro prototipo.
	assert.deepEqual( { ...m.caja.scroll }, { behavior: 'smooth', block: 'center' } );
	assert.deepEqual( { ...m.casilla.foco }, { preventScroll: true }, 'el foco no puede pelear con el desplazamiento' );
	assert.match( m.caja.style.outline, /#FFFFFF/, 'tras un toque no hay anillo de foco: el resalte marca dónde quedó' );

	m.temporizadores.forEach( ( fn ) => fn() );
	assert.equal( m.caja.style.outline, '', 'el resalte se retira solo' );
} );

test( 'con carrito dice lo que gana ESTE pedido, y en pesos', () => {
	// El aviso decía «2 puntos por cada $100» y la cuenta la tenía que hacer el
	// cliente. El número viene del servidor, calculado con la misma función que
	// después acredita: si el dueño cambia el tramo en el panel, cambia solo.
	const arbol = montar( { cuenta: { ...INVITADO, earn_now: 2000, redeem_value: 1 } } ).pintar();
	const t = texto( arbol );

	assert.match( t, /Este pedido suma 2\.000 puntos = \$2\.000/ );
	assert.match( t, /500 puntos de bienvenida = \$500/ );
	// «hasta» y «próximas» en plural: el bono es uno por persona y el tope por
	// pedido casi nunca deja gastar el saldo de una sola vez.
	assert.match( t, /En total hasta \$2\.500 para tus próximas compras/ );
	assert.doesNotMatch( t, /En total \$/, 'sin «hasta» se promete un total que el motor no garantiza' );
	assert.match(
		t,
		/para tus próximas compras/,
		'los puntos se acreditan al llegar al estado que fija el panel: no existen todavía cuando el cliente paga'
	);
	assert.doesNotMatch( t, /en ésta no se descuentan/, 'el remache lo retiró el dueño el 2026-09-17' );
	assert.doesNotMatch( t, /de descuento/, 'el canje tiene mínimo y tope: prometer descuento plano sería mentir' );
} );

test( 'sin carrito todavía, cae a la regla general', () => {
	// El gancho también se pinta con el carrito vacío.
	const t = texto( montar( { cuenta: { ...INVITADO, earn_now: 0, redeem_value: 1 } } ).pintar() );

	assert.match( t, /Acumulas 2 puntos por cada \$100/ );
	assert.doesNotMatch( t, /Este pedido suma/ );
	assert.doesNotMatch( t, /En total/, 'sin carrito no hay total que prometer' );
} );

test( 'dice desde qué monto se pueden usar, si el panel lo exige', () => {
	// El canje tiene mínimo de carrito. Prometer «$2.698 para tu próxima compra»
	// sin decirlo deja al cliente descubriéndolo en la caja siguiente.
	const t = texto( montar( { cuenta: { ...INVITADO, earn_now: 2000, redeem_value: 1, min_cart: 50000 } } ).pintar() );

	assert.match( t, /para tus próximas compras desde \$50\.000/ );
} );

test( 'el cambio a pesos sale del servidor, no de un 1 escrito en la caja', () => {
	const t = texto( montar( { cuenta: { ...INVITADO, earn_now: 2000, redeem_value: 2 } } ).pintar() );

	assert.match( t, /2\.000 puntos = \$4\.000/ );
	assert.match( t, /500 puntos de bienvenida = \$1\.000/ );
} );

test( 'el título ya no manda a marcar una casilla lejana', () => {
	const arbol = montar().pintar();
	assert.doesNotMatch( texto( arbol ), /Marca/ );
	assert.match( texto( arbol ), /Crea tu cuenta y llévate más/ );
} );

test( 'marcada: confirma, avisa del correo de la contraseña y deja desmarcar', () => {
	const m = montar( { marcada: true } );
	const arbol = m.pintar();

	assert.match( texto( arbol ), /Crearemos tu cuenta con este pedido/ );
	assert.match( texto( arbol ), /correo para elegir tu contraseña/ );

	const b = boton( arbol );
	assert.equal( texto( b ), 'Prefiero comprar sin cuenta' );
	b.p.onClick();
	assert.equal( m.casilla.checked, false, 'el cliente puede volver atrás desde el mismo gancho' );
	assert.ok( m.caja.scroll, 'y ve la casilla desmarcarse' );
} );

test( 'si la tienda pide contraseña en el checkout no promete un correo: manda al campo', () => {
	const arbol = montar( { marcada: true, ajustes: { ...WOO, generatePassword: false } } ).pintar();
	assert.doesNotMatch( texto( arbol ), /correo para elegir tu contraseña/ );
	assert.match( texto( arbol ), /Elige tu contraseña junto a la casilla/ );
} );

test( 'en el carrito no hay casilla: ni botón ni promesa de marcarla', () => {
	// El bloque Carrito monta el mismo plugin (scope woocommerce-checkout) con
	// context woocommerce/cart. Aunque los ajustes estuvieran, allí no hay casilla.
	const arbol = montar( { contexto: 'woocommerce/cart' } ).pintar();
	assert.equal( boton( arbol ), undefined );
	assert.match( texto( arbol ), /Con una cuenta en Akibara te llevas más/ );
	assert.doesNotMatch( texto( arbol ), /Marca|Crearemos/ );
} );

test( 'en el carrito ofrece el enlace a Mi cuenta, que es lo único que hay allí', () => {
	// Prometer puntos sin nada que pulsar es media promesa: en /carrito/ el
	// gancho manda a la página de Mi cuenta que fija WooCommerce.
	const arbol = montar( {
		contexto: 'woocommerce/cart',
		cuenta: { ...INVITADO, account_url: 'https://akibara.cl/mi-cuenta/' },
	} ).pintar();
	const enlace = nodos( arbol ).find( ( n ) => 'a' === n.t );

	assert.ok( enlace, 'sin acción, el aviso del carrito no lleva a ninguna parte' );
	assert.equal( enlace.p.href, 'https://akibara.cl/mi-cuenta/', 'la ruta la manda el servidor, no el JS' );
	assert.equal( texto( enlace ), 'Crear mi cuenta' );
	assert.equal( enlace.p.style.textDecoration, 'none', 'con el marco de botón, un subrayado encima se ve roto' );
} );

test( 'sin página de Mi cuenta configurada, no inventa un enlace roto', () => {
	const arbol = montar( { contexto: 'woocommerce/cart', cuenta: { ...INVITADO, account_url: '' } } ).pintar();

	assert.equal( nodos( arbol ).find( ( n ) => 'a' === n.t ), undefined );
} );

test( 'en el checkout con casilla manda a la casilla, no a otra página', () => {
	// Sacar al cliente del checkout para registrarse pierde la compra: ahí el
	// enlace no aparece aunque el servidor mande la URL.
	const arbol = montar( { cuenta: { ...INVITADO, account_url: 'https://akibara.cl/mi-cuenta/' } } ).pintar();

	assert.equal( nodos( arbol ).find( ( n ) => 'a' === n.t ), undefined );
	assert.equal( texto( boton( arbol ) ), 'Crear mi cuenta' );
} );

test( 'sin wc.wcSettings no ofrece botón (no puede saber si hay casilla)', () => {
	assert.equal( boton( montar( { sinAjustes: true } ).pintar() ), undefined );
} );

test( 'los hooks se llaman igual con y sin sesión (reglas de hooks de React)', () => {
	const invitado = montar();
	invitado.pintar();
	const conSesion = montar( { cuenta: { ...INVITADO, logged_in: true } } );
	conSesion.pintar();
	assert.ok( invitado.usos.useSelect > 0 );
	// El doble de createElement no ejecuta CajaDeCanje: sólo cuentan los useSelect del gancho.
	assert.equal( conSesion.usos.useSelect, invitado.usos.useSelect );
} );

test( 'sin registro en el checkout no ofrece un botón hacia una casilla que no existe', () => {
	for ( const ajustes of [ { ...WOO, checkoutAllowsSignup: false }, { ...WOO, checkoutAllowsGuest: false } ] ) {
		const arbol = montar( { ajustes } ).pintar();
		assert.equal( boton( arbol ), undefined );
		assert.match( texto( arbol ), /Con una cuenta en Akibara te llevas más/ );
	}
} );

test( 'con movimiento reducido salta sin animación', () => {
	const m = montar( { reducido: true } );
	boton( m.pintar() ).p.onClick();
	assert.equal( m.caja.scroll.behavior, 'auto' );
} );

test( 'si WooCommerce aún no pintó la casilla, el botón no rompe ni marca nada', () => {
	const m = montar( { pintada: false } );
	assert.doesNotThrow( () => boton( m.pintar() ).p.onClick() );
	assert.equal( m.casilla.clics, 0 );
} );

test( 'con sesión iniciada no hay gancho', () => {
	assert.equal( montar( { cuenta: { ...INVITADO, logged_in: true } } ).pintar(), null );
} );

test( 'no marca dos veces si la casilla ya estaba marcada y el store aún no se enteró', () => {
	const m = montar();
	const b = boton( m.pintar() );
	m.casilla.checked = true;
	b.p.onClick();
	assert.equal( m.casilla.clics, 0, 'un segundo clic la DESMARCARÍA' );
	assert.equal( m.casilla.checked, true );
} );

test( 'un doble toque no marca y desmarca', () => {
	const m = montar();
	boton( m.pintar() ).p.onClick();
	boton( m.pintar() ).p.onClick(); // ya dice «Prefiero comprar sin cuenta», en el mismo instante
	assert.equal( m.casilla.checked, true, 'el segundo toque del doble toque se ignora' );
	assert.equal( m.casilla.clics, 1 );

	m.reloj.ahora += 800;
	boton( m.pintar() ).p.onClick();
	assert.equal( m.casilla.checked, false, 'pasado el rebote, desmarcar sigue funcionando' );
} );

test( 'sin bono de bienvenida, la línea del pedido sigue diciendo que no se descuenta aquí', () => {
	// Sin bono no se pinta la línea del total, que era la única que lo aclaraba:
	// el invitado se quedaba viendo «$2.400» junto al precio del pedido.
	const t = texto( montar( { cuenta: { ...INVITADO, welcome_bonus: 0, earn_now: 2400, redeem_value: 1 } } ).pintar() );

	assert.match( t, /Este pedido suma 2\.400 puntos = \$2\.400 para tus próximas compras/ );
	assert.doesNotMatch( t, /bienvenida/ );
	assert.doesNotMatch( t, /En total/ );
} );

test( 'el CTA es un botón sólido: un solo marco dentro de la caja', () => {
	// La caja ya tiene su borde dorado. Un botón dorado sobre transparente con
	// otro borde de 2 px dejaba dos marcos concéntricos a 15 px, y se leía como
	// un encuadre torcido (lo vio el dueño en producción el 2026-09-17).
	const b = boton( montar().pintar() );

	assert.equal( b.p.style.border, 0, 'sin borde: el marco lo pone la caja' );
	assert.equal( b.p.style.background, '#E60012' );
	assert.equal( b.p.style.color, '#ffffff', 'texto blanco sobre el rojo de marca' );
	assert.equal( b.p.style.boxSizing, 'border-box', 'con width 100% y padding, sin esto se sale de la caja' );
	assert.equal( b.p.style.textAlign, 'center' );

	// Y el enlace del carrito hereda el mismo botón, sin subrayado.
	const arbol = montar( { contexto: 'woocommerce/cart', cuenta: { ...INVITADO, account_url: 'https://akibara.cl/mi-cuenta/' } } ).pintar();
	const enlace = nodos( arbol ).find( ( n ) => 'a' === n.t );
	assert.equal( enlace.p.style.background, '#E60012' );
	assert.equal( enlace.p.style.border, 0 );
	assert.equal( enlace.p.style.textDecoration, 'none' );
} );
