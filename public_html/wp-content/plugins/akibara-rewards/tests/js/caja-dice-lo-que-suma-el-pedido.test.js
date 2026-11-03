'use strict';

/*
 * Quien YA tiene cuenta también tiene derecho a ver lo que gana.
 *
 * El invitado leía «⭐ Este pedido suma 2.198 puntos = $2.198» y el cliente
 * registrado —el que compra— no veía ese número en ninguna parte: se enteraba
 * de sus puntos después de pagar, mirando Mi cuenta. El dueño lo pidió el
 * 2026-09-16 y aprobó la línea, incluida la cara del saldo 0, que antes no se
 * pintaba en absoluto.
 *
 * Estas pruebas EJECUTAN el script y pintan la caja de canje con dobles: miran
 * el texto que sale, no el fuente.
 */

const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );

const { montar, texto } = require( './support/arnes.js' );

// Cliente con cuenta, carrito de $109.890 (2 puntos por cada $100 → 2.198).
const REGISTRADO = {
	logged_in: true,
	teaser: true,
	balance: 0,
	intent: 0,
	min: 100,
	max: 0,
	min_cart: 50000,
	missing: 0,
	first_min: 0,
	earn_points: 2,
	earn_amount: 100,
	welcome_bonus: 500,
	earn_now: 2198,
	redeem_value: 1,
};

const conCuenta = ( extra ) => texto( montar( { cuenta: { ...REGISTRADO, ...extra } } ).pintarCanje() );

test( 'saldo 0: la caja aparece sólo para decir lo que suma este pedido', () => {
	// Es el cliente recién registrado. Antes de 0.4.4 aquí no se pintaba NADA.
	const t = conCuenta();

	assert.match( t, /Tus Puntos Akibara/ );
	assert.match( t, /Este pedido suma 2\.198 puntos = \$2\.198/ );
	// «Para tus próximas compras» es lo que impide leerlo como descuento de ahora:
	// el remache «— en ésta no se descuentan» lo retiró el dueño el 2026-09-17.
	assert.match( t, /para tus próximas compras/, 'con saldo 0 no puede parecer un descuento de ahora' );
	assert.doesNotMatch( t, /en ésta no se descuentan/ );
} );

test( 'saldo 0 y carrito vacío: no hay caja que pintar', () => {
	assert.equal( conCuenta( { earn_now: 0 } ), '' );
} );

test( 'con saldo bajo el carrito mínimo, la caja dice lo que falta Y lo que suma', () => {
	const t = conCuenta( { balance: 900, missing: 12000 } );

	assert.match( t, /Te faltan \$12\.000/ );
	assert.match( t, /Este pedido suma 2\.198 puntos/, 'no poder canjear hoy no es no ganar nada' );
} );

test( 'con saldo canjeable, la línea acompaña al formulario de canje', () => {
	const t = conCuenta( { balance: 5000, max: 5000 } );

	assert.match( t, /Tienes 5\.000 puntos/ );
	assert.match( t, /Este pedido suma 2\.198 puntos = \$2\.198/ );
} );

test( 'con un canje pedido y sin saldo, se avisa del canje muerto y de lo que suma', () => {
	const t = conCuenta( { balance: 0, intent: 300 } );

	assert.match( t, /no se aplica en este carrito/ );
	assert.match( t, /Este pedido suma 2\.198 puntos/ );
} );

test( 'el cambio a pesos sale del servidor, no de un 1 escrito en la caja', () => {
	// Si el dueño pone el punto a $2 desde el panel, la caja lo dice sola.
	assert.match( conCuenta( { redeem_value: 2 } ), /2\.198 puntos = \$4\.396/ );
} );

test( 'sin acumulación configurada, la caja no promete puntos', () => {
	// `earn_now` llega en 0 desde el servidor cuando el carril está apagado.
	const t = conCuenta( { balance: 5000, max: 5000, earn_now: 0 } );

	assert.match( t, /Tienes 5\.000 puntos/ );
	assert.doesNotMatch( t, /Este pedido suma/ );
} );

test( 'el cambio del punto lo compone la caja, ya no viaja un texto aparte', () => {
	// `value_label` era el mismo número que `redeem_value`, en dos formas: dos
	// verdades que mantener sincronizadas en el payload.
	// Hace falta saldo canjeable: es la cara de la caja que nombra el cambio.
	assert.match( conCuenta( { balance: 5000, max: 5000 } ), /1 punto = \$1/ );
	assert.match( conCuenta( { redeem_value: 3, balance: 5000, max: 5000 } ), /1 punto = \$3/ );
} );
