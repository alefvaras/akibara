/**
 * Igualdad PHP <-> JS del validador de celular.
 *
 * El checkout decide en el navegador con `checkout-phone.js` y el pedido se
 * valida en el servidor con `ChileanPhone::validation_error()`. Si las dos
 * copias se separan, pasa lo peor de los dos mundos: el cliente corrige lo que
 * el navegador le pide y el servidor lo rechaza igual al pagar, o al revés, el
 * navegador calla y el error aparece con la tarjeta ya tecleada — que es
 * exactamente lo que este gemelo vino a evitar (auditoría UX 2026-09-05).
 *
 * Duplicar una regla de negocio en dos lenguajes es un coste aceptado, igual
 * que en el RUT y en la comuna. Esta prueba es lo que lo hace aceptable: cruza
 * los dos runtimes de verdad sobre los mismos números, así que la divergencia
 * sale en rojo en vez de en una venta perdida.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const PLUGIN_DIR = path.join(__dirname, '..');
const CHILEAN_PHONE = path.join(
	PLUGIN_DIR,
	'../akibara-business/src/Utilities/ChileanPhone.php'
);

// Números tal como los escribe la gente en Chile, más las formas que rompen.
const CASES = [
	'87654321',
	'9 8765 4321',
	'987654321',
	'+56 9 8765 4321',
	'+56987654321',
	'56987654321',
	'(9) 8765-4321',
	'9-8765-4321',
	'  87654321  ',
	'1234',
	'123456789012',
	'99999999',
	'11111111',
	'12345678',
	'02345678',
	'+1 555 0100',
	'-87654321',
	'8765--4321',
	'8765 4321 x12',
	'teléfono',
	'',
	'   ',
	// Paréntesis. El JS no llevaba el control que sí tiene ChileanPhone, así que
	// daba por buenos los cuatro primeros y el servidor los rechazaba al pagar
	// (auditoría de la superficie servida, 2026-09-05).
	'9(87654321',
	'(987654321',
	'9)87654321',
	'98()765432',
	'(9)8765 4321',
	'9(8)7654321',
	'((9))87654321',
	'(9)(8)7654321'
];

function loadJs() {
	const script = fs.readFileSync(
		path.join(PLUGIN_DIR, 'assets/js/checkout-phone.js'),
		'utf8'
	);
	const listeners = {};
	const document = {
		readyState: 'complete',
		addEventListener(type, callback) {
			listeners[type] = callback;
		}
	};
	const window = { wp: { data: { dispatch() { return null; } } } };
	vm.runInNewContext(script, { document, Object, window });

	return window.akbCheckoutPhone;
}

function php(source) {
	const run = spawnSync('php', ['-d', 'display_errors=stderr', '-r', source], {
		encoding: 'utf8'
	});
	assert.ok(
		run.status !== null,
		'No se pudo ejecutar php: la igualdad PHP<->JS del validador de celular no se puede verificar sin él.'
	);
	assert.equal(run.status, 0, `php terminó en ${run.status}: ${run.stderr}`);

	return run.stdout;
}

test('los 8 dígitos que extrae el navegador son los mismos que extrae el servidor', () => {
	const js = loadJs();
	const source =
		'define("ABSPATH", "/");' +
		`require ${JSON.stringify(CHILEAN_PHONE)};` +
		`$casos = json_decode(${JSON.stringify(JSON.stringify(CASES))}, true);` +
		'$out = [];' +
		'foreach ($casos as $c) { $out[] = \\Akibara\\Business\\Utilities\\ChileanPhone::extract_digits($c); }' +
		'echo json_encode($out);';
	const desdePhp = JSON.parse(php(source));

	CASES.forEach((caso, i) => {
		assert.equal(
			js.extractDigits(caso),
			desdePhp[i],
			`«${caso}»: el navegador saca «${js.extractDigits(caso)}» y el servidor «${desdePhp[i]}»`
		);
	});
});

test('el navegador acepta y rechaza exactamente lo mismo que el servidor, con el mismo mensaje', () => {
	const js = loadJs();
	const source =
		'define("ABSPATH", "/");' +
		`require ${JSON.stringify(CHILEAN_PHONE)};` +
		`$casos = json_decode(${JSON.stringify(JSON.stringify(CASES))}, true);` +
		'$out = [];' +
		'foreach ($casos as $c) { $e = \\Akibara\\Business\\Utilities\\ChileanPhone::validation_error($c, true); $out[] = $e === null ? null : $e["message"]; }' +
		'echo json_encode($out);';
	const desdePhp = JSON.parse(php(source));

	CASES.forEach((caso, i) => {
		const mensajePhp = desdePhp[i];
		const mensajeJs = js.validationMessage(caso);

		// El campo vacío es la única diferencia deliberada: el servidor dice
		// «obligatorio» y el navegador se calla porque de eso ya se encarga
		// Blocks con su propio mensaje.
		if (caso.trim() === '') {
			assert.equal(mensajePhp, 'El celular es obligatorio.');
			return;
		}

		assert.equal(
			mensajeJs,
			mensajePhp,
			`«${caso}»: el navegador dice ${JSON.stringify(mensajeJs)} y el servidor ${JSON.stringify(mensajePhp)}`
		);
	});
});
