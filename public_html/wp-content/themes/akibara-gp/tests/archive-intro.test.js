'use strict';

const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');

const themeRoot = join(__dirname, '..');
const archivePhp = readFileSync(
	join(themeRoot, 'inc', 'woocommerce.php'),
	'utf8'
);
const enqueuePhp = readFileSync(
	join(themeRoot, 'inc', 'enqueue.php'),
	'utf8'
);
const stylesheet = readFileSync(
	join(themeRoot, 'assets', 'css', 'loop.css'),
	'utf8'
);
const behavior = readFileSync(
	join(themeRoot, 'assets', 'js', 'archive-intro.js'),
	'utf8'
);

test('archive intros share one accessible controller contract', () => {
	assert.match(archivePhp, /data-akb-archive-intro/);
	assert.match(archivePhp, /data-akb-archive-intro-toggle/);
	assert.match(archivePhp, /aria-controls=/);
	assert.match(archivePhp, /aria-expanded="false"/);
	assert.match(behavior, /querySelectorAll\('\[data-akb-archive-intro-toggle\]'\)/);
	assert.match(behavior, /getAttribute\('aria-controls'\)/);
	assert.match(behavior, /getElementById\(id\)/);
});

test('genre, author and series load the same progressive enhancement asset', () => {
	assert.match(
		enqueuePhp,
		/is_tax\( array\( 'pa_serie', 'pa_genero', 'pa_autor' \) \)/
	);
	assert.match(enqueuePhp, /'akibara-archive-intro'/);

	// La regla: UN solo contrato de controlador para los tres archives. Antes
	// esto sólo vigilaba el JS, así que el marcado podía seguir emitiendo un
	// atributo propio de serie que nadie leía —y lo hacía—. Ahora se fija en los
	// dos lados: ni el comportamiento lo lee, ni el marcado lo emite.
	assert.doesNotMatch(
		behavior,
		/querySelector\('\[data-akb-series-intro\]'\)/
	);
	assert.doesNotMatch(archivePhp, /data-akb-series-intro/);
});

test('mobile copy is clamped before deferred JavaScript runs', () => {
	assert.match(archivePhp, /akb-archive-intro__content--series is-collapsed/);
	assert.match(
		stylesheet,
		/\.akb-archive-intro__content\.is-collapsed[\s\S]*?-webkit-line-clamp:\s*3/
	);
	// El invariante que este test protege —y que le da nombre— es que el recorte
	// exista en CSS ANTES de que corra el JS diferido, no cuántas líneas son.
	// La serie clampaba a 5 mientras el resto de los archives clampa a 3; se
	// igualó a 3 el 2026-08-10 porque esos 51px (medidos a 375px de ancho)
	// empujaban la primera PORTADA a 771px sobre un viewport útil de ~700 en
	// Safari iOS, dejando el primer pantallazo 100% texto en una página de
	// catálogo. El botón "Leer más" sigue dando el resto del texto.
	assert.match(
		stylesheet,
		/\.akb-archive-intro__content--series\.is-collapsed[\s\S]*?-webkit-line-clamp:\s*3/
	);
	assert.match(stylesheet, /\.akb-archive-intro__toggle:focus-visible/);
	assert.match(archivePhp, /<noscript><style>/);
});
