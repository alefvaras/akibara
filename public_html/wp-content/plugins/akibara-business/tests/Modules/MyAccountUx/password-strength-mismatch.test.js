'use strict';

/*
 * S-f70eab1b (2026-09-19): el medidor de contraseña de Mi cuenta nunca podía
 * decir «No coincide».
 *
 * `wp.passwordStrength.meter( pwd, blacklist, confirmacion )` recibe la
 * contraseña de CONFIRMACIÓN como tercer argumento; el script del template le
 * pasaba `pwd` otra vez, así que comparar la contraseña consigo misma hacía
 * imposible que `meter()` devolviera el score 5 («No coincide»), justo el que
 * el propio mapa de etiquetas del script tiene reservado para eso.
 *
 * La prueba extrae el <script> real del template (no un doble reescrito a
 * mano: eso sólo probaría la copia, no el fichero que el navegador sirve),
 * lo corre en una sandbox de `vm` con un `wp.passwordStrength.meter` que
 * registra sus argumentos, y dispara eventos `input` sobre elementos falsos
 * que imitan #password_1 y #password_2.
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const templatePath = require.resolve( '../../../templates/myaccount/form-edit-account.php' );
const templateSource = fs.readFileSync( templatePath, 'utf8' );

/**
 * El template es PHP+HTML: el script vive entre el único par de etiquetas
 * <script>…</script> que imprime.
 */
function extractInlineScript( html ) {
	const match = html.match( /<script>([\s\S]*?)<\/script>/ );
	assert.ok( match, 'el template debe traer exactamente un bloque <script> inline' );
	return match[ 1 ];
}

/**
 * Elemento mínimo: value/className/textContent + un solo listener 'input'.
 */
function createFakeInput( id ) {
	const listeners = [];
	return {
		id,
		value: '',
		className: '',
		classList: {
			add( cls ) {
				this._classes = this._classes || [];
				this._classes.push( cls );
			},
			contains( cls ) {
				return ( this._classes || [] ).includes( cls );
			},
			_classes: []
		},
		textContent: '',
		addEventListener( type, callback ) {
			if ( type === 'input' ) {
				listeners.push( callback );
			}
		},
		fireInput() {
			listeners.forEach( ( callback ) => callback() );
		}
	};
}

function runScript( { password1Value = '', password2Value = '' } = {} ) {
	const script = extractInlineScript( templateSource );

	const password1 = createFakeInput( 'password_1' );
	password1.value = password1Value;
	const password2 = createFakeInput( 'password_2' );
	password2.value = password2Value;
	const out = createFakeInput( 'akb-pwd-strength' );

	const meterCalls = [];
	const fakeDocument = {
		readyState: 'complete',
		addEventListener() {},
		getElementById( id ) {
			if ( id === 'password_1' ) {
				return password1;
			}
			if ( id === 'password_2' ) {
				return password2;
			}
			if ( id === 'akb-pwd-strength' ) {
				return out;
			}
			return null;
		}
	};

	const sandbox = {
		document: fakeDocument,
		wp: {
			passwordStrength: {
				userInputDisallowedList: () => [],
				meter: ( pwd, blacklist, confirmacion ) => {
					meterCalls.push( { pwd, confirmacion } );
					// Doble fiel al contrato real de WP: 5 SÓLO si no coincide.
					if ( confirmacion !== pwd ) {
						return 5;
					}
					return pwd.length < 4 ? 1 : 4;
				}
			}
		},
		console
	};
	vm.createContext( sandbox );
	vm.runInContext( script, sandbox );

	return { password1, password2, out, meterCalls };
}

test( 'meter() recibe la confirmación real, no la contraseña otra vez', () => {
	const { password1, meterCalls } = runScript( { password1Value: 'unaClaveFuerte1', password2Value: 'otraCosa' } );
	password1.fireInput();

	assert.equal( meterCalls.length, 1 );
	assert.equal( meterCalls[ 0 ].pwd, 'unaClaveFuerte1' );
	assert.equal(
		meterCalls[ 0 ].confirmacion,
		'otraCosa',
		'el tercer argumento tiene que ser #password_2, no #password_1 consigo misma'
	);
} );

test( 'contraseñas distintas muestran «No coincide»', () => {
	const { password1, out } = runScript( { password1Value: 'unaClaveFuerte1', password2Value: 'otraCosa' } );
	password1.fireInput();

	assert.equal( out.textContent, 'No coincide' );
} );

test( 'la misma contraseña en ambos campos no dispara el mismatch', () => {
	const { password1, out } = runScript( { password1Value: 'unaClaveFuerte1', password2Value: 'unaClaveFuerte1' } );
	password1.fireInput();

	assert.notEqual( out.textContent, 'No coincide' );
} );

test( 'escribir la confirmación primero también refresca el mensaje', () => {
	// Si sólo se escuchara #password_1, teclear la confirmación primero
	// dejaría el mismatch sin recalcular hasta el próximo tecleo en la clave.
	const { password2, out } = runScript( { password1Value: 'unaClaveFuerte1', password2Value: 'otraCosa' } );
	password2.fireInput();

	assert.equal( out.textContent, 'No coincide' );
} );

console.log( 'password-strength-mismatch: ok' );
