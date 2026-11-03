'use strict';

const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');

const trigger = readFileSync(
	join(__dirname, '..', 'inc', 'flyout-button.php'),
	'utf8'
);

test('mobile filter trigger derives its accessible name from the visible label', () => {
	assert.match(trigger, /class="akb-filter-toggle__label">Filtrar<\/span>/);
	assert.match(trigger, /aria-expanded="false"/);
	assert.match(trigger, /aria-controls="akb-facets-drawer"/);
	assert.doesNotMatch(trigger, /aria-label="Abrir filtros"/);
});
