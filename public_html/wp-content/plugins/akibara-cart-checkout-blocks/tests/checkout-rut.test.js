const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const script = fs.readFileSync(
	path.join(__dirname, '../assets/js/checkout-rut.js'),
	'utf8'
);

// Carga el script con un DOM mínimo y un store wc/store/validation simulado,
// capturando los listeners de document y las llamadas al store.
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
	const storage = { getItem() { return null; }, removeItem() {} };
	const window = {
		localStorage: storage,
		sessionStorage: storage,
		requestAnimationFrame(callback) {
			callback();
		},
		wp
	};
	vm.runInNewContext(script, { document, Object, window });
	return { listeners, storeCalls, rut: window.akbCheckoutRut };
}

test('el dígito verificador sigue el módulo 11 de RutValidator', () => {
	const { rut } = boot();
	assert.equal(rut.checkDigit('12345678'), '5');
	assert.equal(rut.checkDigit('7654321'), '6');
	assert.equal(rut.isValid('12.345.678-5'), true);
	assert.equal(rut.isValid('12345678-5'), true);
	assert.equal(rut.isValid('12345678-0'), false, 'dígito verificador incorrecto');
	assert.equal(rut.isValid('11.111.111-1'), false, 'cuerpo trivial de dígitos iguales');
	assert.equal(rut.isValid('123-4'), false, 'demasiado corto');
	assert.equal(rut.isValid('abc'), false);
	assert.equal(rut.clean(' 12.345.678-k '), '12345678K');
});

test('al salir del campo con un RUT inválido se publica el error en wc/store/validation bajo la clave del name (contact_akibara/rut)', () => {
	const { listeners, storeCalls } = boot();
	assert.equal(typeof listeners.focusout, 'function', 'initialize registra focusout en document');
	listeners.focusout({ target: { id: 'contact-akibara-rut', value: '12345678-0' } });
	// Los objetos nacen en el realm del vm: comparar por valor serializado, no por prototipo.
	assert.equal(
		JSON.stringify(storeCalls),
		JSON.stringify([
			['set', { 'contact_akibara/rut': { message: 'El RUT ingresado no es válido. Verifica el formato y el dígito verificador.', hidden: false } }]
		])
	);
});

test('un RUT válido limpia el error y los demás campos o el vacío no tocan el store', () => {
	const { listeners, storeCalls } = boot();
	listeners.focusout({ target: { id: 'contact-akibara-rut', value: '12.345.678-5' } });
	listeners.focusout({ target: { id: 'contact-akibara-rut', value: '' } });
	listeners.focusout({ target: { id: 'billing-phone', value: '12345678-0' } });
	listeners.focusout({ target: null });
	assert.equal(JSON.stringify(storeCalls), JSON.stringify([['clear', 'contact_akibara/rut']]));
});
