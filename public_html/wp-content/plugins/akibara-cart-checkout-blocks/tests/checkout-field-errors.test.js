const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const script = fs.readFileSync(
	path.join(__dirname, '../assets/js/checkout-field-errors.js'),
	'utf8'
);

const PLANTILLA = 'El campo %s no es válido.';

// Lo que quedó en `wc/store/validation` al cargar el checkout del espejo el
// 2026-09-24: Woo validó con las etiquetas por defecto antes de que llegara
// Chile, y las etiquetas visibles ya son las del locale.
function erroresDelEspejo() {
	return {
		billing_email: { message: 'El campo dirección de correo electrónico no es válido.', hidden: true },
		'contact_akibara/rut': { message: 'El campo rut no es válido.', hidden: true },
		billing_first_name: { message: 'El campo nombre no es válido.', hidden: true },
		billing_city: { message: 'El campo ciudad no es válido.', hidden: true },
		billing_phone: { message: 'El campo teléfono no es válido.', hidden: false }
	};
}

const ETIQUETAS = {
	'contact_akibara/rut': 'RUT',
	billing_first_name: 'Nombre',
	billing_city: 'Comuna',
	billing_phone: 'Celular'
};

function boot({ errors = erroresDelEspejo(), labels = ETIQUETAS, translate = () => PLANTILLA, i18n = true } = {}) {
	const state = { errors };
	const dispatched = [];
	let subscriber = null;
	let frames = [];

	const document = {
		readyState: 'complete',
		addEventListener() {},
		getElementsByName(name) {
			if (!Object.prototype.hasOwnProperty.call(labels, name)) {
				return [];
			}
			return [{ labels: [{ textContent: `${labels[name]}\n` }] }];
		}
	};
	const wp = {
		data: {
			select(store) {
				assert.equal(store, 'wc/store/validation');
				return { getValidationErrors: () => state.errors };
			},
			dispatch(store) {
				assert.equal(store, 'wc/store/validation');
				return {
					setValidationErrors(fixes) {
						dispatched.push(JSON.parse(JSON.stringify(fixes)));
						state.errors = { ...state.errors, ...fixes };
					}
				};
			},
			subscribe(callback) {
				subscriber = callback;
				return function () {};
			}
		}
	};
	if (i18n) {
		wp.i18n = {
			__(text, domain) {
				assert.equal(text, 'Please enter a valid %s');
				assert.equal(domain, 'woocommerce');
				return translate(text);
			}
		};
	}
	const window = {
		wp,
		requestAnimationFrame(callback) {
			frames.push(callback);
		}
	};

	vm.runInNewContext(script, { document, window, Object, String, Boolean });

	return {
		state,
		dispatched,
		// Corre los cuadros pendientes, como el navegador antes de pintar.
		frame() {
			const pending = frames;
			frames = [];
			pending.forEach((callback) => callback());
		},
		storeChanged() {
			subscriber();
		},
		pendingFrames() {
			return frames.length;
		}
	};
}

test('the generic Blocks message names the label the customer sees, hidden or not', () => {
	const runtime = boot();
	runtime.frame();

	assert.equal(runtime.dispatched.length, 1);
	assert.deepEqual(runtime.dispatched[0], {
		'contact_akibara/rut': { message: 'El campo RUT no es válido.', hidden: true },
		billing_city: { message: 'El campo comuna no es válido.', hidden: true },
		billing_phone: { message: 'El campo celular no es válido.', hidden: false }
	});
	// Lo que ya nombraba bien su etiqueta, y el correo (su campo se llama
	// `contact_email`, su error `billing_email`), no se tocan.
	assert.equal(runtime.state.errors.billing_first_name.message, 'El campo nombre no es válido.');
	assert.equal(runtime.state.errors.billing_email.message, 'El campo dirección de correo electrónico no es válido.');
});

test('the plugin own messages (RUT, phone, commune) are never rewritten', () => {
	const runtime = boot({
		errors: {
			'contact_akibara/rut': { message: 'El RUT ingresado no es válido. Verifica el formato y el dígito verificador.', hidden: false },
			billing_phone: { message: 'Ingresa tu número de celular (9 dígitos). Ejemplo: 9 8765 4321.', hidden: false },
			billing_city: { message: 'No encontramos la comuna "Zzz". Selecciónala de la lista de comunas.', hidden: false },
			billing_first_name: { message: 'Please check this box if you want to proceed.', hidden: false }
		}
	});
	runtime.frame();

	assert.equal(runtime.dispatched.length, 0);
});

test('one fix per change: after its own dispatch the next frame writes nothing', () => {
	const runtime = boot();
	runtime.frame();
	runtime.storeChanged();
	runtime.storeChanged();

	assert.equal(runtime.pendingFrames(), 1, 'store changes in the same frame are coalesced');
	runtime.frame();
	assert.equal(runtime.dispatched.length, 1);
});

test('a label change arriving later is picked up on the next store change', () => {
	const labels = { ...ETIQUETAS, billing_city: 'Ciudad' };
	const runtime = boot({
		errors: { billing_city: { message: 'El campo ciudad no es válido.', hidden: true } },
		labels
	});
	runtime.frame();
	assert.equal(runtime.dispatched.length, 0, 'while the label is still the default nothing changes');

	labels.billing_city = 'Comuna';
	runtime.storeChanged();
	runtime.frame();
	assert.deepEqual(runtime.dispatched, [
		{ billing_city: { message: 'El campo comuna no es válido.', hidden: true } }
	]);
});

test('works with the untranslated template too', () => {
	const runtime = boot({
		errors: { billing_city: { message: 'Please enter a valid city', hidden: true } },
		translate: (text) => text
	});
	runtime.frame();

	assert.deepEqual(runtime.dispatched, [
		{ billing_city: { message: 'Please enter a valid comuna', hidden: true } }
	]);
});

test('without wp.i18n or with a template it cannot read, it does nothing and never throws', () => {
	for (const options of [{ i18n: false }, { translate: () => 'El campo no es válido.' }, { translate: () => 42 }]) {
		const runtime = boot(options);
		assert.doesNotThrow(() => runtime.frame());
		assert.equal(runtime.dispatched.length, 0, JSON.stringify(options));
	}
});
