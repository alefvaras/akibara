'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const themeRoot = path.resolve( __dirname, '..' );
const read = ( relative ) => fs.readFileSync( path.join( themeRoot, relative ), 'utf8' );

/**
 * R3-0d42d2e2 (2026-09-19). En mobile (≤600px), `.footer-column__list`
 * colapsaba INCONDICIONALMENTE por CSS (`max-height:0; visibility:hidden;
 * pointer-events:none`) y sólo el listener de click de assets/js/main.js
 * volvía a abrirla. Con JS bloqueado, o con una excepción previa en el mismo
 * main.js que cortara la ejecución antes de llegar al acordeón, Devoluciones/
 * FAQ/Contacto/Akibara Puntos quedaban inalcanzables — ni clic ni Tab
 * (`visibility:hidden` saca el contenido del orden de foco).
 *
 * Arreglo: el colapso queda gateado tras `.site-footer.js-footer-accordion`,
 * clase que sólo agrega assets/js/main.js una vez que la ejecución síncrona
 * llegó al final de la inicialización del acordeón. Sin esa clase (JS
 * bloqueado o abortado antes), `.footer-column__list` no tiene ninguna regla
 * de colapso y queda con su `display:flex` por defecto: abierta y alcanzable.
 */

// Reglas de un selector exacto (no substring), para distinguir la versión
// gateada (`.site-footer.js-footer-accordion .footer-column__list`) de la
// versión desnuda (`.footer-column__list`) que ya NO debe colapsar sola.
const bloques = ( css, selector ) => {
	const salida = [];
	const patron = new RegExp(
		'(^|[},])\\s*' + selector.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\s*\\{([^}]*)\\}',
		'g'
	);
	let m;
	while ( ( m = patron.exec( css ) ) !== null ) {
		salida.push( m[ 2 ] );
	}
	return salida;
};

// Sin comentarios: varias de las reglas gateadas de este archivo llevan un
// `/* ... */` justo encima explicando el porqué, y ese `*/` (no un `}` ni una
// `,`) es lo último antes del selector — `bloques()` (heredado de
// mobile-drawer-a11y.test.js) sólo reconoce límites de regla, no de
// comentario, así que sin este stripping los matches gateados se pierden.
const sinComentarios = ( css ) => css.replace( /\/\*[\s\S]*?\*\//g, '' );

const footerCss = sinComentarios( read( 'assets/css/footer.css' ) );
const coreCss = read( 'assets/css/core.css' );
const mainJs = read( 'assets/js/main.js' );
const footerTemplate = read( 'template-parts/footer/main.php' );

test( 'sin la clase de JS, .footer-column__list no tiene ninguna regla de colapso propia', () => {
	// La regla base (fuera de @media, ver más arriba en el archivo) SÍ existe
	// y es legítima (display:flex, gap...): lo que no debe existir es una
	// declaración de COLAPSO (max-height/visibility/pointer-events) sobre el
	// selector desnudo — esa es justo la que dejaba el panel inalcanzable sin
	// JS.
	const desnudas = bloques( footerCss, '.footer-column__list' );
	for ( const cuerpo of desnudas ) {
		assert.doesNotMatch(
			cuerpo,
			/max-height|visibility\s*:\s*hidden|pointer-events\s*:\s*none/,
			'.footer-column__list NO debe colapsar por sí sola: el colapso tiene que vivir SÓLO bajo ' +
				'.site-footer.js-footer-accordion, o vuelve a ser incondicional.'
		);
	}

	const desnudasActive = bloques( footerCss, '.footer-column__list.active' );
	assert.equal( desnudasActive.length, 0, '.footer-column__list.active no debe existir suelta (fuera de la clase gateada).' );
});

test( 'el colapso y el estado abierto sólo existen bajo .site-footer.js-footer-accordion', () => {
	const colapsadas = bloques( footerCss, '.site-footer.js-footer-accordion .footer-column__list' );
	assert.ok( colapsadas.length > 0, 'Debe existir la regla de colapso gateada.' );
	assert.match( colapsadas[ 0 ], /max-height:\s*0/, 'El estado cerrado debe llevar max-height:0.' );
	assert.match( colapsadas[ 0 ], /visibility:\s*hidden/, 'El estado cerrado debe sacar el panel del foco.' );

	const abiertas = bloques( footerCss, '.site-footer.js-footer-accordion .footer-column__list.active' );
	assert.ok( abiertas.length > 0, 'Debe existir la regla del estado abierto gateada.' );
	assert.match( abiertas[ 0 ], /visibility:\s*visible/ );
});

test( 'el cursor "clicable" y el glyph +/- tampoco se prometen sin la clase de JS', () => {
	// Sin JS el botón no colapsa nada: prometer un cursor de mano o un "+" que
	// ningún clic resuelve sería peor que no tener affordance.
	const cursorDesnudo = bloques( footerCss, '.footer-column__title' ).find( ( cuerpo ) =>
		/cursor:\s*pointer/.test( cuerpo )
	);
	assert.equal( cursorDesnudo, undefined, '.footer-column__title no debe llevar cursor:pointer fuera de la clase gateada.' );

	assert.ok(
		bloques( footerCss, '.site-footer.js-footer-accordion .footer-column__title' ).some( ( cuerpo ) =>
			/cursor:\s*pointer/.test( cuerpo )
		),
		'El cursor de mano debe vivir bajo .site-footer.js-footer-accordion.'
	);
	assert.ok(
		footerCss.includes( '.site-footer.js-footer-accordion .footer-column__title::after' ),
		'El glyph +/- debe estar gateado igual que el colapso.'
	);
});

test( 'main.js sólo marca el footer como "acordeón vivo" tras cablear los listeners de click', () => {
	const posQuery = mainJs.indexOf( "var footerEl = doc.querySelector('.site-footer');" );
	const posListeners = mainJs.indexOf( 'footerToggles.forEach(function (btn) {\n\t\tbtn.addEventListener' );
	const posMarca = mainJs.indexOf( "footerEl.classList.add('js-footer-accordion');" );
	const posSync = mainJs.indexOf( 'syncFooterAccordionMode();' );

	assert.ok( posQuery >= 0, 'Falta la referencia a .site-footer.' );
	assert.ok( posListeners >= 0, 'Faltan los listeners de click del acordeón.' );
	assert.ok( posMarca >= 0, 'Falta la línea que marca .site-footer con js-footer-accordion.' );
	assert.ok( posSync >= 0, 'Falta la llamada a syncFooterAccordionMode().' );

	// El orden importa: si la marca se agregara ANTES de cablear los
	// listeners (o peor, al principio del IIFE), una excepción posterior en
	// esta misma sección dejaría el footer marcado como "vivo" sin que el
	// click realmente haga nada — la clase mentiría.
	assert.ok(
		posQuery < posListeners && posListeners < posMarca && posMarca <= posSync,
		'La marca debe agregarse DESPUÉS de cablear los listeners y justo antes de sincronizar el modo.'
	);

	assert.match(
		mainJs,
		/if \(footerEl\) \{\s*\n\s*footerEl\.classList\.add\('js-footer-accordion'\);\s*\n\s*\}/,
		'La marca debe estar guardada con un `if (footerEl)`, no incondicional.'
	);
});

test( 'el botón del footer nace con aria-expanded="true": sin JS el panel está abierto de verdad', () => {
	assert.match(
		footerTemplate,
		/footer-column__toggle" aria-expanded="true"/,
		'aria-expanded="false" de entrada mentiría: sin la clase de JS el panel nunca colapsa.'
	);
	assert.doesNotMatch(
		footerTemplate,
		/footer-column__toggle" aria-expanded="false"/,
		'No debe quedar un aria-expanded="false" por defecto en el botón del acordeón.'
	);
});

test( 'core.css (el bundle servido) incluye el gate, no una copia desactualizada de footer.css', () => {
	assert.ok(
		coreCss.includes( '.site-footer.js-footer-accordion .footer-column__list' ),
		'core.css está desactualizado: ejecuta tools/akibara-releases/build-theme-core-css.sh tras editar footer.css.'
	);
});
