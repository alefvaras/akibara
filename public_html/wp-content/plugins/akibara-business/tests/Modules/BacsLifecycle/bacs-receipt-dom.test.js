'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { createDOM } = require( '../../../../../../../tools/testing/dom-harness.js' );

const SCRIPT_PATH = 'public_html/wp-content/plugins/akibara-business/assets/js/bacs-receipt.js';

const PORTAL_HTML = `
	<div class="akb-bacs-portal">
		<form id="akb_bacs_submit_receipt_form" class="akb-bacs-portal__form" method="post">
			<input type="hidden" name="action" value="akb_submit_bacs_receipt">
			<input type="hidden" name="_ajax_nonce" value="nonce123">
			<input type="hidden" name="order_id" value="42">
			<input type="hidden" name="order_key" value="wc_order_abc">
			<div class="akb-bacs-portal__field">
				<label class="akb-bacs-portal__label" for="akb_bacs_ref_input">N.º de transferencia o ID de operación</label>
				<input type="text" name="reference" id="akb_bacs_ref_input" class="akb-bacs-portal__input" required>
			</div>
			<button type="submit" class="button alt akb-bacs-portal__submit">Ya transferí / Informar pago</button>
		</form>
		<div id="akb_bacs_receipt_msg" class="akb-bacs-portal__msg" role="status" aria-live="polite" hidden></div>
	</div>
`;

async function flushMicrotasks() {
	await new Promise( function ( resolve ) {
		setTimeout( resolve, 0 );
	} );
}

test( 'valida referencia vacía con atributos ARIA, foco y limpieza al escribir', function () {
	const dom = createDOM( {
		html: PORTAL_HTML,
		url: 'https://akibara.cl/mi-cuenta/view-order/42/',
		globals: {
			akbBacsReceiptConfig: {
				ajaxUrl: 'https://akibara.cl/wp-admin/admin-ajax.php',
				reloadDelayMs: 5,
			},
		},
	} );

	try {
		dom.loadScript( SCRIPT_PATH );

		const form = dom.document.getElementById( 'akb_bacs_submit_receipt_form' );
		const input = dom.document.getElementById( 'akb_bacs_ref_input' );
		const msg = dom.document.getElementById( 'akb_bacs_receipt_msg' );

		assert.equal( form.noValidate, true );

		dom.fill( input, '   ' );
		form.dispatchEvent( new dom.window.Event( 'submit', { bubbles: true, cancelable: true } ) );

		assert.equal( input.getAttribute( 'aria-invalid' ), 'true' );
		assert.equal( input.getAttribute( 'aria-describedby' ), 'akb_bacs_receipt_msg' );
		assert.equal( dom.document.activeElement, input );
		assert.equal( msg.hidden, false );
		assert.equal( msg.classList.contains( 'akb-bacs-portal__msg--error' ), true );
		assert.match( msg.textContent, /^✕ / );

		dom.fill( input, '987654' );
		assert.equal( input.hasAttribute( 'aria-invalid' ), false );
		assert.equal( input.hasAttribute( 'aria-describedby' ), false );
	} finally {
		dom.teardown();
	}
} );

test( 'envío fallido re-habilita el botón, anuncia error y marca el campo inválido', async function () {
	let fetchCalls = 0;
	const dom = createDOM( {
		html: PORTAL_HTML,
		url: 'https://akibara.cl/mi-cuenta/view-order/42/',
		globals: {
			akbBacsReceiptConfig: {
				ajaxUrl: 'https://akibara.cl/wp-admin/admin-ajax.php',
				submitLabel: 'Ya transferí / Informar pago',
				submittingLabel: 'Enviando...',
				reloadDelayMs: 5,
			},
			fetch() {
				fetchCalls += 1;
				return Promise.resolve( {
					ok: true,
					json() {
						return Promise.resolve( {
							success: false,
							data: { message: 'No autorizado para este pedido.' },
						} );
					},
				} );
			},
		},
	} );

	try {
		dom.loadScript( SCRIPT_PATH );

		const form = dom.document.getElementById( 'akb_bacs_submit_receipt_form' );
		const input = dom.document.getElementById( 'akb_bacs_ref_input' );
		const msg = dom.document.getElementById( 'akb_bacs_receipt_msg' );
		const btn = form.querySelector( 'button[type="submit"]' );

		dom.fill( input, 'OP-ERR-1' );
		form.dispatchEvent( new dom.window.Event( 'submit', { bubbles: true, cancelable: true } ) );

		assert.equal( btn.disabled, true );
		assert.equal( btn.textContent, 'Enviando...' );

		await flushMicrotasks();

		assert.equal( fetchCalls, 1 );
		assert.equal( btn.disabled, false );
		assert.equal( btn.textContent, 'Ya transferí / Informar pago' );
		assert.equal( input.getAttribute( 'aria-invalid' ), 'true' );
		assert.equal( msg.hidden, false );
		assert.equal( msg.classList.contains( 'akb-bacs-portal__msg--error' ), true );
		assert.equal( msg.textContent, '✕ No autorizado para este pedido.' );
	} finally {
		dom.teardown();
	}
} );

test( 'envío exitoso bloquea doble submit, emite evento y recarga tras la demora configurada', async function () {
	let fetchCalls = 0;
	let capturedUrl = '';
	let reloadCalls = 0;
	const scheduledDelays = [];
	const events = [];

	const dom = createDOM( {
		html: PORTAL_HTML,
		url: 'https://akibara.cl/mi-cuenta/view-order/42/',
		globals: {
			akbBacsReceiptConfig: {
				ajaxUrl: 'https://akibara.cl/wp-admin/admin-ajax.php',
				reloadDelayMs: 25,
			},
			fetch( url ) {
				fetchCalls += 1;
				capturedUrl = url;
				return Promise.resolve( {
					ok: true,
					json() {
						return Promise.resolve( {
							success: true,
							data: { message: '¡Comprobante informado con éxito!' },
						} );
					},
				} );
			},
		},
	} );

	try {
		dom.window.setTimeout = function ( fn, delay ) {
			scheduledDelays.push( delay );
			if ( typeof fn === 'function' ) {
				fn();
			}
			return 1;
		};
		Object.defineProperty( dom.window.location, 'reload', {
			configurable: true,
			value() {
				reloadCalls += 1;
			},
		} );

		dom.document.addEventListener( 'akb:bacs-receipt:submitted', function ( evt ) {
			events.push( evt.detail );
		} );

		dom.loadScript( SCRIPT_PATH );

		const form = dom.document.getElementById( 'akb_bacs_submit_receipt_form' );
		const input = dom.document.getElementById( 'akb_bacs_ref_input' );
		const msg = dom.document.getElementById( 'akb_bacs_receipt_msg' );

		dom.fill( input, '  TRF-778899  ' );
		form.dispatchEvent( new dom.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
		form.dispatchEvent( new dom.window.Event( 'submit', { bubbles: true, cancelable: true } ) );

		await flushMicrotasks();

		assert.equal( fetchCalls, 1 );
		assert.equal( capturedUrl, 'https://akibara.cl/wp-admin/admin-ajax.php' );
		assert.equal( input.value, 'TRF-778899' );
		assert.equal( form.getAttribute( 'data-akb-bacs-state' ), 'submitted' );
		assert.equal( msg.hidden, false );
		assert.equal( msg.classList.contains( 'akb-bacs-portal__msg--ok' ), true );
		assert.equal( msg.textContent, '✓ ¡Comprobante informado con éxito!' );
		assert.equal( events.length, 1 );
		assert.equal( events[ 0 ].reference, 'TRF-778899' );
		assert.deepEqual( scheduledDelays, [ 25 ] );
		assert.equal( reloadCalls, 1 );
	} finally {
		dom.teardown();
	}
} );

test( 'respuesta HTTP no JSON (ej. 500 HTML o 0) muestra genericError sin filtrar SyntaxError técnico', async function () {
	const dom = createDOM( {
		html: PORTAL_HTML,
		url: 'https://akibara.cl/mi-cuenta/view-order/42/',
		globals: {
			akbBacsReceiptConfig: {
				ajaxUrl: 'https://akibara.cl/wp-admin/admin-ajax.php',
				genericError: 'Error al enviar el comprobante. Inténtalo de nuevo.',
				reloadDelayMs: 5,
			},
			fetch() {
				return Promise.resolve( {
					ok: false,
					text() {
						return Promise.resolve( '<html><body>500 Error</body></html>' );
					},
				} );
			},
		},
	} );

	try {
		dom.loadScript( SCRIPT_PATH );

		const form = dom.document.getElementById( 'akb_bacs_submit_receipt_form' );
		const input = dom.document.getElementById( 'akb_bacs_ref_input' );
		const msg = dom.document.getElementById( 'akb_bacs_receipt_msg' );
		const btn = form.querySelector( 'button[type="submit"]' );

		dom.fill( input, 'OP-500' );
		form.dispatchEvent( new dom.window.Event( 'submit', { bubbles: true, cancelable: true } ) );

		await flushMicrotasks();

		assert.equal( btn.disabled, false );
		assert.equal( input.getAttribute( 'aria-invalid' ), 'true' );
		assert.equal( msg.hidden, false );
		assert.equal( msg.textContent, '✕ Error al enviar el comprobante. Inténtalo de nuevo.' );
		assert.doesNotMatch( msg.textContent, /SyntaxError|Unexpected token/i );
	} finally {
		dom.teardown();
	}
} );
