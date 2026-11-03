'use strict';

/*
 * Favoritos de un invitado: el clic no puede perderse en el camino al acceso.
 *
 * WooCommerce SÍ devuelve al producto tras entrar —el `redirect_to` acaba como
 * `<input name="redirect">` en su formulario, medido en producción el
 * 2026-08-23—, pero el tomo no quedaba guardado: el cliente volvía a la misma
 * ficha, con el corazón vacío, y tenía que darse cuenta solo de que su clic se
 * había perdido. Estas pruebas fijan la propiedad —la intención sobrevive al
 * acceso—, no el mecanismo concreto que hoy la cumple.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );

const source = fs.readFileSync( require.resolve( '../../../assets/js/wishlist.js' ), 'utf8' );

assert.match(
	source,
	/rememberIntent\(\s*productId\s*\)/,
	'el invitado tiene que dejar anotado QUÉ quería guardar antes de irse a acceder'
);

assert.match(
	source,
	/redirect_to=/,
	'y tiene que volver al mismo sitio del que salió'
);

// El orden importa: si se anota DESPUÉS de cambiar `location.href`, la
// navegación puede llevarse la página antes de que se escriba nada. Desde UM-9
// el invitado primero ve un aviso en la página; la navegación sale de
// `goToLogin()`, que es el único sitio que anota y navega.
const irAlAcceso = source.match( /function goToLogin\(productId\) \{([\s\S]*?)\n\t\}/ );
assert.ok( irAlAcceso, 'goToLogin existe' );
const posAnota = irAlAcceso[ 1 ].search( /rememberIntent\(\s*productId\s*\)/ );
const posSalto = irAlAcceso[ 1 ].search( /window\.location\.href\s*=\s*loginHref\(\)/ );
assert.ok( posAnota > -1 && posSalto > -1, 'ambas piezas existen' );
assert.ok(
	posAnota < posSalto,
	'la intención se anota ANTES de navegar; después es una carrera que se pierde'
);

// Abrir el aviso NO anota nada: si el cliente elige «Seguir mirando» y más tarde
// entra por otro camino, guardarle el tomo sería una sorpresa.
const aviso = source.match( /function promptGuest\(productId, trigger\) \{([\s\S]*?)\n\t\}/ );
assert.ok( aviso, 'promptGuest existe' );
assert.doesNotMatch( aviso[ 1 ], /rememberIntent/, 'mostrar el aviso no anota la intención' );
assert.match( aviso[ 1 ], /goToLogin\(productId\)/, 'sin <dialog> o con el aviso apagado, navega como siempre' );
assert.match( source, /akb-wl-login__cta'\)\.addEventListener\('click', function \(e\) \{\s*e\.preventDefault\(\);\s*goToLogin\(guestProductId\);/, 'el botón de entrar pasa por goToLogin' );

// Caducidad: completar un guardado que la persona ya no recuerda haber pedido
// es una sorpresa, no una ayuda.
assert.match(
	source,
	/Date\.now\(\)\s*-\s*Number\(\s*data\.at\s*\)\s*\)\s*>\s*\d+/,
	'la intención guardada tiene que caducar'
);

// Y nunca puede convertirse en un "quitar": si el tomo ya está guardado, repetir
// el toggle lo sacaría de la lista.
assert.match(
	source,
	/classList\.contains\(\s*'is-saved'\s*\)/,
	'antes de completar la intención hay que comprobar que el tomo NO esté ya guardado'
);

// El almacenamiento puede estar bloqueado (modo privado): eso degrada, no rompe.
assert.match(
	source,
	/try\s*\{[\s\S]*?sessionStorage[\s\S]*?\}\s*catch/,
	'el acceso al almacenamiento va protegido'
);

console.log( 'wishlist-guest-intent: ok' );
