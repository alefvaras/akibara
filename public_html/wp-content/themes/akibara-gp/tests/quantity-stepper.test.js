const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const themeRoot = path.join(__dirname, '..');
const script = fs.readFileSync(path.join(themeRoot, 'assets/js/main.js'), 'utf8');
const styles = fs.readFileSync(path.join(themeRoot, 'assets/css/woocommerce.css'), 'utf8');

test('quantity steppers synchronize disabled boundaries on init and updates', () => {
	assert.match(script, /function syncQuantityStepper\(wrap\)/);
	assert.match(script, /value <= min/);
	assert.match(script, /value >= max/);
	assert.match(script, /state\.button\.disabled = state\.disabled/);
	assert.match(script, /setAttribute\('aria-disabled'/);
	assert.match(script, /syncAllQuantitySteppers\(doc\)/);
	// Antes esto exigía un oyente de `akb:updated_wc_div`, el evento con el que
	// el carrito CLÁSICO reemplazaba la tabla entera. Ese carrito no existe en
	// esta tienda (es de bloques) y el shim dejó de re-emitir el evento. Lo que
	// mantiene los steppers al día hoy es el `input` delegado en document —que
	// sobrevive a cualquier re-render porque no captura nodos— y la pasada
	// inicial. Eso es lo que se exige.
	assert.match(script, /doc\.addEventListener\('input', function \(e\) \{/);
	assert.match(script, /e\.target\.matches\('input\.qty'\)/);
	assert.doesNotMatch(
		script,
		/akb:updated_wc_div|akb:updated_cart_totals/,
		'Ningún oyente puede depender de eventos que el carrito de bloques no dispara.'
	);
});

test('disabled quantity controls have a visible non-interactive state', () => {
	assert.match(styles, /\.akb-qty-btn:disabled/);
	assert.match(styles, /\[aria-disabled="true"\]/);
	assert.match(styles, /cursor: not-allowed/);
});
