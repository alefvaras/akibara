/**
 * Pruebas DOM reales para bis-form.js usando el arnés centralizado (happy-dom).
 *
 * Verifica:
 * 1. Al hacer clic en `.akb-notify-link` dentro de Quick View (`dialog.akb-qv`),
 *    se cierra `dialog.akb-qv` antes de abrir el modal de lista de espera (`dialog#akb-bis-dialog`).
 * 2. Al enviar exitosamente el correo en el modal, se recuerda en `sessionStorage` (`akb_bis_last_email`)
 *    y se pre-rellena automáticamente en el siguiente modal de producto agotado.
 */
const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const { createDOM } = require( '../../../../../../../tools/testing/dom-harness.js' );

const SCRIPT_PATH = path.resolve( __dirname, '../../../assets/js/bis-form.js' );

test( 'bis-form.js: cierra Quick View antes de abrir modal BIS y recuerda el correo en sessionStorage', async ( t ) => {
	let qvClosed = false;
	const dom = createDOM( {
		url: 'https://akibara.cl/catalogo/',
		html: `
			<!DOCTYPE html>
			<html>
			<body>
				<dialog class="akb-qv" open>
					<a href="https://akibara.cl/producto/berserk-01/#akb-bis-form"
					   class="button akb-notify-link"
					   data-product-id="101"
					   data-product-name="Berserk 01">Avísame cuando vuelva</a>
				</dialog>
				<a href="https://akibara.cl/producto/vagabond-02/#akb-bis-form"
				   id="second-bis-btn"
				   class="button akb-notify-link"
				   data-product-id="202"
				   data-product-name="Vagabond 02">Avísame cuando vuelva</a>
			</body>
			</html>
		`,
		globals: {
			akbBis: {
				endpoint: 'https://akibara.cl/wp-json/akibara/v1/back-in-stock',
			},
			fetch: async () => ( {
				ok: true,
				status: 200,
				text: async () => JSON.stringify( {
					code: 'subscribed',
					message: '¡Listo! Te avisaremos apenas vuelva a stock.',
				} ),
			} ),
		},
	} );
	t.after( () => dom.teardown() );

	const qvDialog = dom.document.querySelector( 'dialog.akb-qv' );
	const origClose = qvDialog.close ? qvDialog.close.bind( qvDialog ) : () => {};
	qvDialog.close = () => {
		qvClosed = true;
		qvDialog.removeAttribute( 'open' );
		origClose();
	};

	dom.loadScript( SCRIPT_PATH );

	const qvTrigger = qvDialog.querySelector( '.akb-notify-link' );
	dom.click( qvTrigger );

	assert.equal( qvClosed, true, 'El modal Quick View debe cerrarse antes de abrir el diálogo BIS' );

	const bisDialog = dom.document.getElementById( 'akb-bis-dialog' );
	assert.ok( bisDialog, 'Se debe crear el diálogo #akb-bis-dialog' );

	const emailInput = bisDialog.querySelector( '.akb-bis-dialog__email' );
	assert.ok( emailInput, 'El diálogo contiene el input de correo' );

	dom.fill( emailInput, 'lector@akibara.cl' );
	const form = bisDialog.querySelector( '.akb-bis-dialog__form' );
	form.dispatchEvent( new dom.window.Event( 'submit', { bubbles: true, cancelable: true } ) );

	await new Promise( ( resolve ) => setTimeout( resolve, 30 ) );
	assert.equal(
		dom.window.sessionStorage.getItem( 'akb_bis_last_email' ),
		'lector@akibara.cl',
		'El correo enviado se persiste en sessionStorage para la sesión actual'
	);

	// Abrir un segundo producto agotado debe pre-rellenar el correo guardado
	const secondBtn = dom.document.getElementById( 'second-bis-btn' );
	emailInput.value = '';
	dom.click( secondBtn );
	assert.equal(
		emailInput.value,
		'lector@akibara.cl',
		'El segundo modal pre-rellena automáticamente el correo desde sessionStorage'
	);
} );
