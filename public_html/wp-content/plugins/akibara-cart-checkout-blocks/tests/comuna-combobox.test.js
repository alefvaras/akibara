const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const script = fs.readFileSync(
	path.join(__dirname, '../assets/js/comuna-combobox.js'),
	'utf8'
);

/**
 * Runs the IIFE without config so it exposes its pure helpers and returns
 * before touching any DOM (the file guards on config and document).
 */
function loadHelpers() {
	const window = {};
	const context = { window };
	vm.createContext(context);
	vm.runInContext(script, context);

	assert.ok(window.akbComunaCombobox, 'helpers must be exposed for tests');
	return window.akbComunaCombobox;
}

const REGIONS = {
	'CL-RM': ['San Miguel', 'Ñuñoa', 'Providencia', 'La Florida'],
	'CL-VS': ['Viña del Mar', 'Valparaíso']
};

test('normalize strips accents, case, spacing and stray punctuation', () => {
	const { normalize } = loadHelpers();

	assert.equal(normalize('Ñuñoa'), 'nunoa');
	assert.equal(normalize('  Viña   del Mar. '), 'vina del mar');
	assert.equal(normalize('SAN MIGUEL,'), 'san miguel');
	assert.equal(normalize(''), '');
	assert.equal(normalize(null), '');
});

test('buildModel flattens regions, keeps names and sorts alphabetically', () => {
	const { buildModel } = loadHelpers();
	const model = buildModel(REGIONS);

	assert.equal(model.length, 6);
	assert.deepEqual(
		Array.from(model, (entry) => entry.name),
		['La Florida', 'Ñuñoa', 'Providencia', 'San Miguel', 'Valparaíso', 'Viña del Mar']
	);
	assert.equal(model.find((entry) => entry.name === 'Ñuñoa').region, 'CL-RM');

	assert.equal(buildModel({ 'CL-RM': ['', 42, null] }).length, 0);
	assert.equal(buildModel(null).length, 0);
});

test('rank filters by region, puts prefix matches first and ignores accents', () => {
	const { buildModel, rank } = loadHelpers();
	const model = buildModel(REGIONS);

	const all = rank(model, '', 'CL-RM');
	assert.deepEqual(
		Array.from(all, (entry) => entry.name),
		['La Florida', 'Ñuñoa', 'Providencia', 'San Miguel']
	);

	const nun = rank(model, 'nuñ', 'CL-RM');
	assert.deepEqual(Array.from(nun, (entry) => entry.name), ['Ñuñoa']);

	const substringAfterPrefix = rank(model, 'vi', '');
	assert.deepEqual(
		Array.from(substringAfterPrefix, (entry) => entry.name),
		['Viña del Mar', 'Providencia']
	);

	assert.equal(rank(model, 'zzz', 'CL-RM').length, 0);
	assert.equal(rank(model, 'viña', 'CL-RM').length, 0, 'region filter wins over match');
});

test('shouldClearCity mirrors the classic dependent-select reset', () => {
	const { buildModel, shouldClearCity } = loadHelpers();
	const model = buildModel(REGIONS);

	assert.equal(shouldClearCity(model, 'Ñuñoa', 'CL-VS'), true, 'known commune not in new region clears');
	assert.equal(shouldClearCity(model, 'nunoa', 'CL-VS'), true, 'accent-less spelling clears too');
	assert.equal(shouldClearCity(model, 'Ñuñoa', 'CL-RM'), false, 'commune of the new region stays');
	assert.equal(shouldClearCity(model, 'texto libre', 'CL-VS'), false, 'unknown free text is not ours to wipe');
	assert.equal(shouldClearCity(model, '', 'CL-VS'), false);
	assert.equal(shouldClearCity(model, 'Ñuñoa', ''), false, 'clearing the region never wipes the commune');
});

test('the IIFE stays inert without its localized config', () => {
	const window = {};
	const context = { window };
	vm.createContext(context);
	vm.runInContext(script, context);

	assert.ok(window.akbComunaCombobox);
	assert.equal(typeof window.akbComunaCombobox.rank, 'function');
});

/**
 * Candado por invariante: la lista NUNCA puede terminar fuera del viewport.
 *
 * Medido el 2026-08-23 con Playwright (iPhone 390x664, checkout local):
 * con el campo cerca del fondo la lista iba de 596 a 860 px, o sea 196 px por
 * debajo del viewport, con 542 px libres por encima; con el viewport aplastado
 * a 360 px desbordaba 98. Después: -126 y -9 (dentro), abriendo hacia arriba en
 * el primer caso y recortada a 157 px en el segundo.
 *
 * Se fija la propiedad (cabe), no los píxeles de esa medición.
 */
test('placeListbox keeps the suggestion list inside the viewport', () => {
	const { placeListbox } = loadHelpers();
	const GAP = 12; // 4 px de separación del campo + 8 px de respiro al canto.

	const fits = placeListbox({
		inputTop: 321,
		inputBottom: 371,
		viewportTop: 0,
		viewportHeight: 664,
		naturalHeight: 900
	});
	assert.equal(fits.above, false, 'con espacio de sobra abajo, abre hacia abajo');
	assert.equal(fits.maxHeight, null, 'y no impone ningún límite extra');

	const dropup = placeListbox({
		inputTop: 542,
		inputBottom: 592,
		viewportTop: 0,
		viewportHeight: 664,
		naturalHeight: 900
	});
	assert.equal(dropup.above, true, 'sin hueco abajo y con hueco arriba, abre hacia arriba');
	assert.equal(dropup.maxHeight, null, 'y arriba caben los 264 px del diseño');

	const squeezed = placeListbox({
		inputTop: 140,
		inputBottom: 190,
		viewportTop: 0,
		viewportHeight: 360,
		naturalHeight: 900
	});
	assert.equal(squeezed.above, false);
	assert.ok(squeezed.maxHeight <= 360 - 190 - GAP, 'recorta al hueco real, no a los 264 fijos');
	assert.ok(190 + 4 + squeezed.maxHeight <= 360, 'y así la lista termina dentro del viewport');
});

test('placeListbox respects the visual viewport offset left by the keyboard', () => {
	const { placeListbox } = loadHelpers();

	// Teclado abierto: la banda visible empieza en 120 y mide 300.
	const withKeyboard = placeListbox({
		inputTop: 150,
		inputBottom: 200,
		viewportTop: 120,
		viewportHeight: 300,
		naturalHeight: 900
	});

	assert.equal(withKeyboard.above, false, 'abajo quedan 208 px y arriba sólo 18');
	assert.ok(
		200 + 4 + withKeyboard.maxHeight <= 120 + 300,
		'la lista no puede pasar del borde inferior de la banda visible'
	);
});

test('placeListbox never collapses the list into a sliver', () => {
	const { placeListbox } = loadHelpers();

	const noRoom = placeListbox({
		inputTop: 180,
		inputBottom: 210,
		viewportTop: 0,
		viewportHeight: 240,
		naturalHeight: 900
	});

	assert.ok(noRoom.maxHeight >= 120, 'con 18 px libres preferimos asomar antes que una rendija');
});

test('placeListbox degrades open when the viewport cannot be measured', () => {
	const { placeListbox } = loadHelpers();

	// Nada de deepEqual contra un literal: el objeto viene del realm del vm y
	// su prototipo no es el de este contexto (deepStrictEqual falla con dos
	// objetos idénticos). Se comprueban los campos.
	const blind = placeListbox({ inputTop: 0, inputBottom: 0, viewportTop: 0, viewportHeight: 0, naturalHeight: 0 });

	assert.equal(blind.above, false);
	assert.equal(
		blind.maxHeight,
		null,
		'sin medidas fiables se deja el comportamiento del CSS, nunca una lista de 0 px'
	);
});

/**
 * Candado por invariante: la comuna escrita a mano se juzga en el cliente con
 * el MISMO criterio que CheckoutFields::validate_chilean_comuna().
 *
 * Medido el 2026-08-23 con Playwright sobre el checkout local: antes, escribir
 * «Stgo» y salir del campo dejaba aria-invalid="false", sin error en
 * `wc/store/validation` y sin texto junto al campo — el rechazo llegaba recién
 * al pulsar «Realizar el pedido», como nota general. Después: aria-invalid
 * "true" y el mensaje anclado al campo.
 *
 * Lo que NO hace, y es deliberado: autocorregir. El servidor ya canoniza
 * tildes y mayúsculas al empujar la dirección, así que «nunoa» no es un error.
 */
test('comunaIssue flags only what the server would reject', () => {
	const { buildModel, comunaIssue } = loadHelpers();
	const model = buildModel(REGIONS);

	assert.equal(comunaIssue(model, 'Ñuñoa', 'CL-RM'), null, 'la comuna correcta no se marca');
	assert.equal(comunaIssue(model, 'nunoa', 'CL-RM'), null, 'lo que el servidor canoniza solo no es un error');
	assert.equal(comunaIssue(model, 'NUNOA', ''), null, 'sin región elegida basta con que exista');
	assert.equal(comunaIssue(model, '', 'CL-RM'), null, 'el «obligatorio» es de Blocks, no nuestro');

	const desconocida = comunaIssue(model, 'Stgo', 'CL-RM');
	assert.equal(desconocida.kind, 'unknown');
	assert.equal(desconocida.suggestion, null, 'sin parecido no se inventa una sugerencia');

	const conSugerencia = comunaIssue(model, 'San Miguel del Sur', 'CL-RM');
	assert.equal(conSugerencia.kind, 'unknown');
	assert.equal(conSugerencia.suggestion, 'San Miguel', 'la comuna contenida en lo escrito se propone');

	const aMedias = comunaIssue(model, 'Providen', 'CL-RM');
	assert.equal(aMedias.suggestion, 'Providencia', 'y también lo escrito a medias');
});

test('comunaIssue mirrors the server region mismatch', () => {
	const { buildModel, comunaIssue } = loadHelpers();
	const model = buildModel(REGIONS);

	const cruzada = comunaIssue(model, 'Ñuñoa', 'CL-VS');

	assert.equal(cruzada.kind, 'region', 'una comuna real de otra región es un caso distinto');
	assert.equal(cruzada.name, 'Ñuñoa', 'el mensaje usa la ortografía canónica, no la escrita');
	assert.equal(
		comunaIssue(model, 'Viña del Mar', 'CL-VS'),
		null,
		'la comuna de la región elegida pasa'
	);
});
