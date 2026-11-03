/**
 * Igualdad PHP <-> JS del normalizador de comunas sobre el catálogo REAL.
 *
 * El combobox decide en el navegador con `comuna-combobox.js::normalize` y el
 * pedido se valida en el servidor con `ComunaDirectory::normalize`. Si los dos
 * no producen exactamente lo mismo, el cliente elige una comuna que la lista le
 * ofreció y el checkout responde «No encontramos la comuna»: la venta se cae.
 * Los dos casos que ya lo hacían eran el espacio duro (U+00A0) que pegan los
 * autocompletados de dirección y la forma descompuesta (NFD) de los teclados de
 * iOS/macOS.
 *
 * Esta prueba cruza los dos runtimes de verdad: PHP normaliza, Node compara.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const PLUGIN_DIR = path.join(__dirname, '..');
const VENDOR_CATALOG = path.join(
	PLUGIN_DIR,
	'../bluex-for-woocommerce/includes/data/chile-communes.php'
);

function loadJsNormalize() {
	const script = fs.readFileSync(
		path.join(PLUGIN_DIR, 'assets/js/comuna-combobox.js'),
		'utf8'
	);
	const window = {};
	const context = { window };
	vm.createContext(context);
	vm.runInContext(script, context);

	return window.akbComunaCombobox.normalize;
}

/**
 * Corre PHP con el intérprete del proyecto y devuelve el JSON que imprime.
 * Un fallo aquí no se salta: sin PHP no hay forma de comprobar la igualdad,
 * que es justo lo que esta prueba existe para garantizar.
 */
function php(source, env) {
	const run = spawnSync('php', ['-d', 'display_errors=stderr', '-r', source], {
		encoding: 'utf8',
		env: { ...process.env, ...env },
		maxBuffer: 32 * 1024 * 1024
	});

	assert.equal(
		run.error,
		undefined,
		'No se pudo ejecutar php: la igualdad PHP<->JS del normalizador de comunas no se puede verificar sin él.'
	);
	assert.equal(run.status, 0, `php terminó en ${run.status}: ${run.stderr}`);

	return JSON.parse(run.stdout);
}

const CATALOG_SOURCE = `
$names = array();
foreach ( bluex_get_communes_by_region() as $communes ) {
	foreach ( (array) $communes as $commune ) {
		if ( is_array( $commune ) && isset( $commune['name'] ) && is_string( $commune['name'] ) ) {
			$names[] = $commune['name'];
		}
	}
}
echo json_encode( $names );
`;

const NORMALIZE_SOURCE = `
$out = array();
foreach ( json_decode( (string) getenv( 'AKB_COMUNA_INPUTS' ), true ) as $input ) {
	$out[] = \\Akibara\\CartCheckoutBlocks\\ComunaDirectory::normalize( (string) $input );
}
echo json_encode( $out );
`;

function bootstrap(body) {
	return (
		`define( 'ABSPATH', __DIR__ );` +
		`require ${JSON.stringify(path.join(PLUGIN_DIR, 'src/ComunaDirectory.php'))};` +
		`require ${JSON.stringify(VENDOR_CATALOG)};` +
		body
	);
}

test('el catálogo real de Blue Express normaliza igual en PHP y en JS', () => {
	assert.ok(
		fs.existsSync(VENDOR_CATALOG),
		'Blue Express movió su catálogo de comunas: sin él no hay contrato que comprobar.'
	);

	const names = php(bootstrap(CATALOG_SOURCE));
	assert.ok(names.length >= 340, `el catálogo trae ${names.length} comunas`);

	// Las tres formas en que la misma comuna llega al servidor: tal cual, con el
	// espacio duro que inyectan los autocompletados, y descompuesta.
	const inputs = [];
	names.forEach((name) => {
		inputs.push(name, name.replace(/ /g, '\u00a0'), name.normalize('NFD'));
	});

	const fromPhp = php(bootstrap(NORMALIZE_SOURCE), {
		AKB_COMUNA_INPUTS: JSON.stringify(inputs)
	});
	const normalize = loadJsNormalize();

	assert.equal(fromPhp.length, inputs.length);

	const divergences = [];
	inputs.forEach((input, index) => {
		const fromJs = normalize(input);
		if (fromPhp[index] !== fromJs) {
			divergences.push({ input, php: fromPhp[index], js: fromJs });
		}
	});

	assert.deepEqual(
		divergences,
		[],
		'PHP y JS normalizan distinto: el combobox aceptaría una comuna que el pedido rechaza.'
	);
});

test('las tres formas de una comuna colapsan en la misma clave', () => {
	const normalize = loadJsNormalize();
	const inputs = [
		'Viña del Mar',
		'Viña\u00a0del\u00a0Mar',
		'Viña del Mar'.normalize('NFD')
	];
	const fromPhp = php(bootstrap(NORMALIZE_SOURCE), {
		AKB_COMUNA_INPUTS: JSON.stringify(inputs)
	});

	assert.deepEqual(fromPhp, ['vina del mar', 'vina del mar', 'vina del mar']);
	assert.deepEqual(inputs.map(normalize), fromPhp);
});
