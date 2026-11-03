const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

/*
 * Gemelo en cliente del celular. Sin él, un número mal escrito sólo aparecía al
 * pulsar «Realizar el pedido», con la tarjeta ya tecleada en los iframes del
 * medio de pago: el checkout se lleva 107 s de media y ese viaje de vuelta es
 * donde se pierde gente (auditoría UX 2026-09-05).
 *
 * Los casos son los mismos que fija ChileanPhone en PHP: si las dos copias se
 * separan, el cliente ve un mensaje y el servidor decide otra cosa.
 */

const script = fs.readFileSync(
	path.join(__dirname, '../assets/js/checkout-phone.js'),
	'utf8'
);

function boot() {
	const listeners = {};
	const storeCalls = [];
	const store = {
		setValidationErrors(errors) {
			storeCalls.push(['set', errors]);
		},
		clearValidationError(id) {
			storeCalls.push(['clear', id]);
		}
	};
	const document = {
		readyState: 'complete',
		addEventListener(type, callback) {
			listeners[type] = callback;
		},
		getElementById() {
			return null;
		},
		querySelector() {
			return null;
		},
		querySelectorAll() {
			return [];
		}
	};
	const wp = {
		data: {
			dispatch(name) {
				return name === 'wc/store/validation' ? store : {};
			},
			subscribe() {
				return function () {};
			}
		}
	};
	const window = { wp };
	vm.runInNewContext(script, { document, Object, window });
	return { listeners, storeCalls, phone: window.akbCheckoutPhone };
}

test('acepta el número como lo escribe la gente y saca sus 8 dígitos', () => {
	const { phone } = boot();
	// Las cuatro formas que ChileanPhone::extract_digits() reconoce.
	assert.equal(phone.extractDigits('87654321'), '87654321', 'ocho dígitos pelados');
	assert.equal(phone.extractDigits('987654321'), '87654321', 'con el 9 delante');
	assert.equal(phone.extractDigits('56987654321'), '87654321', 'con el código de país');
	assert.equal(phone.extractDigits('+56 9 8765 4321'), '87654321', 'internacional con espacios');
	assert.equal(phone.extractDigits('(9) 8765-4321'), '87654321', 'con paréntesis y guion');
});

test('rechaza lo que no es un celular chileno', () => {
	const { phone } = boot();
	assert.equal(phone.extractDigits('+1 555 0100'), '', 'otro país');
	assert.equal(phone.extractDigits('-87654321'), '', 'guion al principio');
	assert.equal(phone.extractDigits('8765--4321'), '', 'guion doble');
	assert.equal(phone.extractDigits('8765 4321 x12'), '', 'letras');
	assert.equal(phone.extractDigits(''), '');
});

test('los mensajes son los mismos que devuelve el servidor', () => {
	const { phone } = boot();
	assert.equal(phone.validationMessage('987654321'), null, 'un número válido no molesta');
	assert.equal(
		phone.validationMessage('1234'),
		'Ingresa tu número de celular (9 dígitos). Ejemplo: 9 8765 4321.'
	);
	assert.equal(
		phone.validationMessage('99999999'),
		'El celular ingresado no parece válido.',
		'todos los dígitos iguales'
	);
	assert.equal(
		phone.validationMessage('12345678'),
		'El celular no parece válido. Revisa el número.',
		'los celulares chilenos no empiezan en 0 ni en 1'
	);
});

test('avisa al salir del campo, no al pulsar pagar', () => {
	const { listeners, storeCalls, phone } = boot();
	assert.equal(typeof listeners.focusout, 'function', 'escucha el focusout de document');

	listeners.focusout({ target: { tagName: 'INPUT', type: 'tel', id: 'billing-phone', value: '1234' } });
	assert.deepEqual(storeCalls[0][0], 'set');
	assert.equal(
		storeCalls[0][1]['billing_phone'].message,
		'Ingresa tu número de celular (9 dígitos). Ejemplo: 9 8765 4321.'
	);
	assert.equal(storeCalls[0][1]['billing_phone'].hidden, false, 'el error tiene que verse');

	listeners.focusout({ target: { tagName: 'INPUT', type: 'tel', id: 'billing-phone', value: '9 8765 4321' } });
	assert.deepEqual(storeCalls[1], ['clear', 'billing_phone'], 'al corregirlo se limpia');

	assert.equal(phone.validateField(null), null, 'sin input no hace nada');
});

/*
 * La clave del store la publica Blocks, no nosotros. Medido en el checkout del
 * espejo el 2026-09-05: al dejar el campo vacío, `wc/store/validation` trae
 * `billing_phone` con «El campo teléfono no es válido». Escribiendo en otra
 * clave —`billing/phone`, que es como se nombra el campo en la Store API— el
 * mensaje sale como nota suelta del formulario, sin quedar anclado al input.
 */
test('el error se ancla al campo: la clave es la que usa Blocks, y sale del propio input', () => {
	const { listeners, storeCalls } = boot();

	listeners.focusout({ target: { tagName: 'INPUT', type: 'tel', id: 'shipping-phone', value: '1234' } });
	assert.ok(storeCalls[0][1]['shipping_phone'], 'en el flujo de envío la clave es la de shipping');
	assert.equal(storeCalls[0][1]['billing_phone'], undefined, 'no se toca el campo del otro bloque');

	listeners.focusout({ target: { tagName: 'INPUT', type: 'tel', id: 'shipping-phone', value: '987654321' } });
	assert.deepEqual(storeCalls[1], ['clear', 'shipping_phone']);
});

test('el campo vacío lo gestiona Blocks, no nosotros', () => {
	const { listeners, storeCalls } = boot();
	listeners.focusout({ target: { tagName: 'INPUT', type: 'tel', id: 'billing-phone', value: '   ' } });
	assert.equal(storeCalls.length, 0, '«obligatorio» ya lo dice Blocks con su propio mensaje');
});

test('no se mete con los demás campos del checkout', () => {
	const { listeners, storeCalls } = boot();
	listeners.focusout({ target: { tagName: 'INPUT', type: 'text', id: 'contact-akibara-rut', value: 'basura' } });
	listeners.focusout({ target: { tagName: 'INPUT', type: 'text', id: 'billing-city', value: 'basura' } });
	assert.equal(storeCalls.length, 0, 'sólo mira el campo de teléfono');
});

/*
 * `type="tel"` a secas marcaría cualquier campo de teléfono del documento con un
 * mensaje sobre celulares chilenos y una clave de store ajena. El del bloque de
 * Blue Express es el caso concreto que hay al lado.
 */
test('un campo de teléfono de otro bloque no es asunto nuestro', () => {
	const { listeners, storeCalls } = boot();
	listeners.focusout({ target: { tagName: 'INPUT', type: 'tel', id: 'bluex-contacto-fono', value: '1234' } });
	listeners.focusout({ target: { tagName: 'INPUT', type: 'tel', id: 'algun-widget', value: '1234' } });
	assert.equal(storeCalls.length, 0, 'no lleva sufijo -phone ni pertenece a los bloques de dirección');

	listeners.focusout({ target: { tagName: 'INPUT', type: 'tel', id: 'billing-telefono', value: '1234' } });
	assert.equal(storeCalls.length, 1, 'un id distinto dentro de billing sí es nuestro');
});

test('sin el store de validación no revienta: el servidor sigue siendo el juez', () => {
	const listeners = {};
	const document = {
		readyState: 'complete',
		addEventListener(type, callback) {
			listeners[type] = callback;
		}
	};
	const window = { wp: { data: { dispatch() { return null; } } } };
	vm.runInNewContext(script, { document, Object, window });
	assert.doesNotThrow(() => {
		listeners.focusout({ target: { tagName: 'INPUT', type: 'tel', id: 'billing-phone', value: '1234' } });
	});
});
