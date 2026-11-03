import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

/**
 * R3-dc2c02a9 (2026-09-19). Antes `<form data-akb-form="contacto" novalidate>`
 * no declaraba `method` ni `action`: sin JS (bloqueado, o el fetch de
 * akibara-forms.js fallando antes de registrar su listener) el navegador hacía
 * el submit por defecto — GET a la URL actual, nombre/email/mensaje como query
 * string — y ningún servidor lo procesaba: el mensaje se perdía en silencio y
 * los datos del cliente quedaban expuestos en historial/referrer/logs.
 *
 * El contrato: el `<form>` pasa a POST contra admin-post.php (que
 * akibara-business procesará con `admin_post(_nopriv)_akb_contacto`), y
 * `novalidate` se retira del markup — lo agrega akibara-forms.js SÓLO si
 * llega a correr, así que sin JS queda la validación nativa del navegador
 * sobre los `required`.
 */

const read = ( rel ) => readFileSync( new URL( `../${ rel }`, import.meta.url ), 'utf8' );

const contacto = read( 'template-parts/forms/contacto.php' );
const formsJs = read( 'assets/js/akibara-forms.js' );

/** Aísla la etiqueta `<form ...>` de apertura, sin arrastrar el resto del documento. */
const formTag = ( () => {
	// Por índice de línea y no por regex `[^>]*`: el `action` embebe un
	// `<?php echo esc_url( ... ); ?>` cuyo `?>` contiene un `>` que un regex
	// ingenuo tomaría como cierre de la etiqueta, cortando el match a la
	// mitad. La etiqueta vive entera en una sola línea.
	const inicio = contacto.indexOf( '<form ' );
	assert.ok( inicio >= 0, 'contacto.php debe declarar un <form> con atributos.' );
	const finLinea = contacto.indexOf( '\n', inicio );
	return contacto.slice( inicio, finLinea );
} )();

test( 'el form de contacto hace POST nativo a admin-post.php con el action akb_contacto', () => {
	assert.match( formTag, /\bmethod="post"/, 'Sin method="post" el navegador cae a GET por defecto.' );
	assert.match(
		formTag,
		/\baction="<\?php echo esc_url\( admin_url\( 'admin-post\.php' \) \); \?>"/,
		'El action debe resolver admin_url(\'admin-post.php\') escapado con esc_url().'
	);

	assert.match(
		contacto,
		/<input type="hidden" name="action" value="akb_contacto">/,
		'Falta el input oculto que admin-post.php usa para enrutar al handler de business.'
	);

	// El hidden input tiene que ir DENTRO del <form>, antes de su cierre —
	// si quedara fuera, el navegador no lo manda con el submit nativo.
	const inicioForm = contacto.indexOf( formTag );
	const cierreForm = contacto.indexOf( '</form>', inicioForm );
	const posInput = contacto.indexOf( 'name="action" value="akb_contacto"', inicioForm );
	assert.ok( posInput > inicioForm && posInput < cierreForm, 'El hidden input de action debe vivir dentro del <form>.' );
});

test( 'novalidate ya no viene en el HTML: sin JS manda la validación nativa del navegador', () => {
	assert.doesNotMatch(
		formTag,
		/novalidate/,
		'novalidate en el markup desactiva los required nativos ANTES de que akibara-forms.js pueda correr.'
	);

	// Los tres campos siguen siendo obligatorios para el navegador: quitar
	// novalidate sin required no serviría de nada.
	for ( const campo of [ 'id="akb-c-nombre"', 'id="akb-c-email"', 'id="akb-c-mensaje"' ] ) {
		const idx = contacto.indexOf( campo );
		assert.ok( idx >= 0, `Falta el campo ${ campo }.` );
		const etiquetaCompleta = contacto.slice( idx, contacto.indexOf( '>', idx ) );
		assert.match( etiquetaCompleta, /\brequired\b/, `${ campo } debe seguir siendo required.` );
	}
});

test( 'akibara-forms.js agrega novalidate por JS SÓLO si llega a inicializar, para cada [data-akb-form]', () => {
	// Tiene que vivir DESPUÉS de la guarda `if (typeof window.AkibaraFormsConfig
	// !== 'object') return;`: si el script no llega a esta línea (config
	// ausente, o una excepción previa), novalidate no se agrega y el
	// navegador se queda con su validación nativa.
	const guarda = formsJs.indexOf( "if (typeof window.AkibaraFormsConfig !== 'object')" );
	const marcaNovalidate = formsJs.indexOf( "form.setAttribute('novalidate', '')" );
	assert.ok( guarda >= 0, 'Falta la guarda de configuración.' );
	assert.ok( marcaNovalidate > guarda, 'novalidate debe agregarse DESPUÉS de la guarda de configuración, no incondicionalmente.' );

	assert.match(
		formsJs,
		/document\.querySelectorAll\('\[data-akb-form\]'\)\.forEach\(function \(form\) \{\s*\n\s*form\.setAttribute\('novalidate', ''\);/,
		'Debe recorrer TODOS los [data-akb-form], no sólo el de contacto.'
	);

	// El submit sigue interceptado por fetch + preventDefault: este cambio no
	// toca el flujo CON JS, sólo el fallback sin JS.
	assert.match( formsJs, /event\.preventDefault\(\);/ );
	assert.match( formsJs, /await fetch\(CONFIG\.restUrl \+ kind/ );
});
