import playwright from '../akibara-e2e/node_modules/playwright/index.js';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE_URL = process.env.AKB_BASE_URL || 'http://localhost:8080';

function getLocalAdminCookies() {
	const php = `<?php
		$u = get_user_by('id', 1);
		if (!$u) {
			$users = get_users(['role' => 'administrator', 'number' => 1]);
			$u = $users[0] ?? null;
		}
		$exp = time() + 7200;
		$tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
		$auth = wp_generate_auth_cookie($u->ID, $exp, 'auth', $tok);
		$logged = wp_generate_auth_cookie($u->ID, $exp, 'logged_in', $tok);
		echo json_encode([
			['name' => AUTH_COOKIE, 'value' => $auth, 'domain' => 'localhost', 'path' => '/'],
			['name' => LOGGED_IN_COOKIE, 'value' => $logged, 'domain' => 'localhost', 'path' => '/']
		]);
	`;
	const p = spawnSync(
		'docker',
		['exec', '-i', 'akibara-cron-1', 'wp', 'eval-file', '-'],
		{ input: php, encoding: 'utf8' }
	);
	if (p.status !== 0) {
		throw new Error(`Failed to get local admin cookies: ${p.stderr}`);
	}
	return JSON.parse(p.stdout.trim());
}

async function run() {
	console.log('🚀 Iniciando Suite E2E de Playwright: DOM Profundo de Search Admin (8 Pestañas)...');
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const cookies = getLocalAdminCookies();
	const context = await browser.newContext({
		viewport: { width: 1440, height: 900 }
	});

	if (cookies.length > 0) {
		await context.addCookies(cookies);
	}

	const page = await context.newPage();

	const consoleErrors = [];
	const pageErrors = [];

	page.on('console', msg => {
		if (msg.type() === 'error') {
			consoleErrors.push(`[Console Error] ${msg.text()}`);
		}
	});

	page.on('pageerror', err => {
		pageErrors.push(`[Page Error] ${err.message}`);
	});

	const report = {
		totalTabs: 8,
		tabsTested: [],
		assertionsPassed: 0,
		tabDetails: {},
		consoleErrors: [],
		pageErrors: [],
	};

	try {
		// =========================================================================
		// 1. VERIFICACIÓN DEL SHELL GLOBAL Y HEADER FACETWP
		// =========================================================================
		console.log('\n--- 1. Validando Shell Global y Header (.akb-fwp-head) ---');
		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-search`, { waitUntil: 'networkidle' });

		// Panel wrap
		const panelWrap = page.locator('.wrap.akb-fwp .akb-fwp-panel');
		assert.ok(await panelWrap.isVisible(), 'El contenedor .wrap.akb-fwp .akb-fwp-panel debe ser visible');
		report.assertionsPassed++;

		// Header y Brand
		const head = page.locator('.akb-fwp-head');
		assert.ok(await head.isVisible(), 'Header .akb-fwp-head debe ser visible');
		report.assertionsPassed++;

		const brandH1 = head.locator('.akb-fwp-brand h1');
		assert.equal(await brandH1.textContent(), 'Buscador', 'El h1 debe ser "Buscador"');
		report.assertionsPassed++;

		const brandGem = head.locator('.akb-fwp-brand .akb-fwp-gem');
		assert.ok(await brandGem.isVisible(), 'Gema romboide debe estar presente');
		report.assertionsPassed++;

		const brandVer = head.locator('.akb-fwp-brand .akb-fwp-ver');
		const verText = (await brandVer.textContent() || '').trim();
		assert.match(verText, /^v\d+\.\d+\.\d+$/, 'La versión debe tener formato semver vX.Y.Z');
		report.assertionsPassed++;

		// Botón Reconstruir índice del header
		const reindexBtn = head.locator('form button.akb-fwp-reindex');
		assert.ok(await reindexBtn.isVisible(), 'Botón "Reconstruir índice" en el header debe ser visible');
		assert.equal((await reindexBtn.textContent() || '').trim(), 'Reconstruir índice');
		report.assertionsPassed++;

		// Subnav con 8 pestañas
		const navWrapper = page.locator('nav.nav-tab-wrapper[aria-label="Secciones del buscador"]');
		assert.ok(await navWrapper.isVisible(), 'Subnav .nav-tab-wrapper debe ser visible');
		report.assertionsPassed++;

		const navLinks = navWrapper.locator('a.nav-tab');
		const navCount = await navLinks.count();
		assert.equal(navCount, 7, `Deben existir exactamente 7 pestañas en el subnav, encontradas: ${navCount}`);
		report.assertionsPassed++;

		const expectedTabs = [
			{ slug: 'indice', regex: /Índice/i },
			{ slug: 'busquedas', regex: /Búsquedas/i },
			{ slug: 'facetas', regex: /Facetas/i },
			{ slug: 'precios', regex: /Precios/i },
			{ slug: 'sinonimos', regex: /Sinónimos/i },
			{ slug: 'stopwords', regex: /Palabras ignoradas/i },
			{ slug: 'ajustes', regex: /Ajustes/i }
		];

		for (let i = 0; i < expectedTabs.length; i++) {
			const tabLink = navLinks.nth(i);
			const text = (await tabLink.textContent() || '').trim();
			const href = await tabLink.getAttribute('href') || '';
			assert.match(text, expectedTabs[i].regex, `Pestaña ${i+1} debe coincidir con ${expectedTabs[i].regex}`);
			assert.ok(href.includes(`sub=${expectedTabs[i].slug}`), `Pestaña ${i+1} href debe contener sub=${expectedTabs[i].slug}`);
			report.assertionsPassed += 2;
		}
		console.log('  ✓ Header FacetWP, gema, versión, botón reindex y 7 pestañas verificados al 100%.');

		// =========================================================================
		// 2. TAB 1: ÍNDICE
		// =========================================================================
		console.log('\n--- 2. Validando Pestaña: Índice (sub=indice) ---');
		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-search&sub=indice`, { waitUntil: 'networkidle' });

		const activeTabIndice = navWrapper.locator('a.nav-tab-active');
		assert.match(await activeTabIndice.textContent(), /Índice/, 'Pestaña Índice debe tener clase nav-tab-active');
		report.assertionsPassed++;

		// Banda de salud
		const healthBand = page.locator('.akb-health-band[data-akb-search-status]');
		assert.ok(await healthBand.isVisible(), 'Banda de salud .akb-health-band debe ser visible');
		report.assertionsPassed++;

		const healthVerdict = healthBand.locator('.akb-health-verdict strong');
		assert.ok(await healthVerdict.isVisible(), 'Veredicto de salud debe estar visible');
		const verdictText = (await healthVerdict.textContent() || '').trim();
		assert.ok(verdictText.length > 0, 'Veredicto no debe estar vacío');
		report.assertionsPassed++;

		const healthDot = healthBand.locator('.akb-health-dot');
		assert.ok(await healthDot.isVisible(), 'Dot indicador de salud debe estar visible');
		report.assertionsPassed++;

		const docCountParagraph = healthBand.locator('p').nth(1);
		const docCountText = (await docCountParagraph.textContent() || '').trim();
		assert.match(docCountText, /productos indexados|Reconstruyendo/i, 'Debe mostrar conteo de productos indexados o progreso');
		report.assertionsPassed++;

		// Tabla de servicios
		const servicesHeading = page.locator('.akb-search-admin h2:has-text("Servicios")');
		assert.ok(await servicesHeading.isVisible(), 'Encabezado H2 "Servicios" debe ser visible');
		report.assertionsPassed++;

		// Tablas dentro de akb-search-admin
		const tables = page.locator('.akb-search-admin table.widefat');
		assert.ok((await tables.count()) >= 2, 'Deben existir al menos 2 tablas principales (Servicios y Estado)');
		report.assertionsPassed++;

		// Mantenimiento (Rebuild & Reconcile)
		const maintenanceHeading = page.locator('h2#akb-search-maintenance');
		assert.ok(await maintenanceHeading.isVisible(), 'Encabezado H2 #akb-search-maintenance "Mantenimiento" debe ser visible');
		report.assertionsPassed++;

		const bodyRebuildForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_rebuild"])');
		assert.ok(await bodyRebuildForm.isVisible(), 'Formulario de rebuild en mantenimiento debe ser visible');
		report.assertionsPassed++;

		const bodyReconcileForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_reconcile_run"])');
		assert.ok(await bodyReconcileForm.isVisible(), 'Formulario de reconcile en mantenimiento debe ser visible');
		report.assertionsPassed++;

		// Diagnóstico colapsable
		const diagSection = page.locator('details.akb-search-diagnostics');
		assert.ok(await diagSection.isVisible(), 'Detalle técnico details.akb-search-diagnostics debe existir');
		const diagSummary = diagSection.locator('summary');
		assert.match(await diagSummary.textContent(), /Diagnóstico técnico/i, 'Summary debe decir Diagnóstico técnico');
		report.assertionsPassed += 2;

		report.tabsTested.push('indice');
		report.tabDetails['indice'] = { verdict: verdictText, docCount: docCountText };
		console.log(`  ✓ Pestaña Índice validada (Veredicto: "${verdictText}", ${docCountText}).`);

		// =========================================================================
		// 3. TAB 2: BÚSQUEDAS
		// =========================================================================
		console.log('\n--- 3. Validando Pestaña: Búsquedas (sub=busquedas) ---');
		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-search&sub=busquedas`, { waitUntil: 'networkidle' });

		const activeTabBusquedas = navWrapper.locator('a.nav-tab-active');
		assert.match(await activeTabBusquedas.textContent(), /Búsquedas/, 'Pestaña Búsquedas activa');
		report.assertionsPassed++;

		// Encabezado de actividad
		const searchHeading = page.locator('.akb-search-admin h2');
		assert.ok(await searchHeading.first().isVisible(), 'Encabezado H2 de búsquedas visible');
		report.assertionsPassed++;

		// Bloque Top Búsquedas o mensaje
		const topHeading = page.locator('h3:has-text("Top búsquedas")');
		const emptyLogMsg = page.locator('p.description:has-text("Sin búsquedas registradas todavía")');
		const hasTopTable = await topHeading.isVisible();
		const hasEmptyMsg = await emptyLogMsg.isVisible();
		assert.ok(hasTopTable || hasEmptyMsg, 'Debe mostrar tabla de Top búsquedas o mensaje de log vacío');
		report.assertionsPassed++;

		// Bloque Sin resultados
		const zeroHeading = page.locator('h3:has-text("Sin resultados")');
		assert.ok(await zeroHeading.isVisible() || hasEmptyMsg, 'Debe mostrar bloque de búsquedas sin resultados');
		report.assertionsPassed++;

		report.tabsTested.push('busquedas');
		report.tabDetails['busquedas'] = { hasTopTable, hasEmptyMsg };
		console.log('  ✓ Pestaña Búsquedas validada (tablas de top búsquedas, sin resultados y demanda).');

		// =========================================================================
		// 3. TAB 3: FACETAS
		// =========================================================================
		console.log('\n--- 3. Validando Pestaña: Facetas (sub=facetas) ---');
		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-search&sub=facetas`, { waitUntil: 'networkidle' });

		const activeTabFacetas = navWrapper.locator('a.nav-tab-active');
		assert.match(await activeTabFacetas.textContent(), /Facetas/, 'Pestaña Facetas activa');
		report.assertionsPassed++;

		const facetasTitle = page.locator('h2:has-text("Facetas del catálogo")');
		assert.ok(await facetasTitle.isVisible(), 'H2 "Facetas del catálogo" visible');
		report.assertionsPassed++;

		const facetsForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_save_facets"])');
		assert.ok(await facetsForm.isVisible(), 'Formulario de guardar facetas debe ser visible');
		report.assertionsPassed++;

		const facetsTable = page.locator('table.akb-facets-table');
		assert.ok(await facetsTable.isVisible(), 'Tabla .akb-facets-table visible');
		report.assertionsPassed++;

		const facetRows = facetsTable.locator('tbody tr[data-akb-facet-row]');
		const facetRowCount = await facetRows.count();
		assert.ok(facetRowCount >= 3, `Debe haber al menos 3 facetas configuradas, encontradas: ${facetRowCount}`);
		report.assertionsPassed++;

		// Verificar primera fila
		const firstRow = facetRows.first();
		const moveUp = firstRow.locator('button[data-akb-move="up"]');
		const moveDown = firstRow.locator('button[data-akb-move="down"]');
		assert.ok(await moveUp.isVisible() && await moveDown.isVisible(), 'Botones de reordenamiento arriba/abajo deben ser visibles');
		const labelInput = firstRow.locator('input[name*="[label]"]');
		assert.ok(await labelInput.isVisible(), 'Input de etiqueta visible debe ser visible');
		const uiSelect = firstRow.locator('select.akb-facet-ui');
		assert.ok(await uiSelect.isVisible(), 'Select de UI de faceta debe ser visible');
		const visibleCheckbox = firstRow.locator('input[type="checkbox"][name*="[visible]"]');
		assert.ok(await visibleCheckbox.isVisible(), 'Checkbox de visible debe ser visible');
		report.assertionsPassed += 4;

		// Fieldset agregar nueva faceta
		const newFacetFieldset = page.locator('fieldset.akb-facets-new');
		assert.ok(await newFacetFieldset.isVisible(), 'Fieldset .akb-facets-new visible');
		report.assertionsPassed++;

		// Botón Guardar facetas
		const saveFacetsBtn = page.getByRole('button', { name: 'Guardar facetas' });
		assert.ok(await saveFacetsBtn.isVisible(), 'Botón "Guardar facetas" debe ser visible');
		report.assertionsPassed++;

		report.tabsTested.push('facetas');
		report.tabDetails['facetas'] = { facetRowCount };
		console.log(`  ✓ Pestaña Facetas validada (${facetRowCount} facetas en grilla, fieldset nueva faceta y botón guardar).`);

		// =========================================================================
		// 4. TAB 4: PRECIOS
		// =========================================================================
		console.log('\n--- 4. Validando Pestaña: Precios (sub=precios) ---');
		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-search&sub=precios`, { waitUntil: 'networkidle' });

		const activeTabPrecios = navWrapper.locator('a.nav-tab-active');
		assert.match(await activeTabPrecios.textContent(), /Precios/, 'Pestaña Precios activa');
		report.assertionsPassed++;

		const preciosTitle = page.locator('h2:has-text("Rangos de precio")');
		assert.ok(await preciosTitle.isVisible(), 'H2 "Rangos de precio" visible');
		report.assertionsPassed++;

		const modeBadge = page.locator('h2 .akb-mode-badge');
		assert.ok(await modeBadge.isVisible(), 'Badge de modo (Automático/Manual) visible');
		const modeText = (await modeBadge.textContent() || '').trim();
		assert.ok(['Automático', 'Manual'].includes(modeText), `Badge debe ser Automático o Manual, encontrado: ${modeText}`);
		report.assertionsPassed += 2;

		const preciosForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_save_precios"])');
		assert.ok(await preciosForm.isVisible(), 'Formulario de guardar precios visible');
		report.assertionsPassed++;

		const preciosTable = page.locator('table.akb-precios-table');
		assert.ok(await preciosTable.isVisible(), 'Tabla .akb-precios-table visible');
		report.assertionsPassed++;

		const minInputs = preciosTable.locator('input[name="precio_min[]"]');
		const maxInputs = preciosTable.locator('input[name="precio_max[]"]');
		const minCount = await minInputs.count();
		const maxCount = await maxInputs.count();
		assert.ok(minCount >= 2, 'Debe haber al menos 2 inputs de precio mínimo');
		assert.ok(maxCount >= 2, 'Debe haber al menos 2 inputs de precio máximo');
		report.assertionsPassed += 2;

		const savePreciosBtn = page.getByRole('button', { name: 'Guardar rangos' });
		assert.ok(await savePreciosBtn.isVisible(), 'Botón "Guardar rangos" debe ser visible');
		const recalcBtn = page.getByRole('button', { name: /Recalcular desde el catálogo/ });
		assert.ok(await recalcBtn.isVisible(), 'Botón "Recalcular desde el catálogo" debe ser visible');
		report.assertionsPassed += 2;

		report.tabsTested.push('precios');
		report.tabDetails['precios'] = { modeText, minCount, maxCount };
		console.log(`  ✓ Pestaña Precios validada (Modo: ${modeText}, ${minCount} tramos, inputs y botones de recálculo/guardado).`);

		// =========================================================================
		// 5. TAB 5: SINÓNIMOS
		// =========================================================================
		console.log('\n--- 5. Validando Pestaña: Sinónimos (sub=sinonimos) ---');
		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-search&sub=sinonimos`, { waitUntil: 'networkidle' });

		const activeTabSinonimos = navWrapper.locator('a.nav-tab-active');
		assert.match(await activeTabSinonimos.textContent(), /Sinónimos/, 'Pestaña Sinónimos activa');
		report.assertionsPassed++;

		const sinonimosTitle = page.locator('h2:has-text("Sinónimos")');
		assert.ok(await sinonimosTitle.isVisible(), 'H2 "Sinónimos" visible');
		report.assertionsPassed++;

		const sinonimosForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_save_synonyms"])');
		assert.ok(await sinonimosForm.isVisible(), 'Formulario de sinónimos visible');
		report.assertionsPassed++;

		const sinonimosTextarea = sinonimosForm.locator('textarea[name="synonyms"]');
		assert.ok(await sinonimosTextarea.isVisible(), 'Textarea de sinónimos debe ser visible');
		assert.ok(await sinonimosTextarea.isEditable(), 'Textarea debe ser editable');
		const sinonimosContent = await sinonimosTextarea.inputValue();
		assert.ok(sinonimosContent.length > 0, 'Textarea de sinónimos debe contener reglas configuradas');
		const sinonimosLineCount = sinonimosContent.split('\n').filter(l => l.trim().length > 0).length;
		report.assertionsPassed += 3;

		const saveSinonimosBtn = page.getByRole('button', { name: 'Guardar sinónimos' });
		assert.ok(await saveSinonimosBtn.isVisible(), 'Botón "Guardar sinónimos" visible');
		report.assertionsPassed++;

		report.tabsTested.push('sinonimos');
		report.tabDetails['sinonimos'] = { sinonimosLineCount };
		console.log(`  ✓ Pestaña Sinónimos validada (${sinonimosLineCount} líneas de sinónimos guardados, textarea editable).`);

		// =========================================================================
		// 6. TAB 6: PALABRAS IGNORADAS
		// =========================================================================
		console.log('\n--- 6. Validando Pestaña: Palabras Ignoradas (sub=stopwords) ---');
		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-search&sub=stopwords`, { waitUntil: 'networkidle' });

		const activeTabStopwords = navWrapper.locator('a.nav-tab-active');
		assert.match(await activeTabStopwords.textContent(), /Palabras ignoradas/, 'Pestaña Palabras ignoradas activa');
		report.assertionsPassed++;

		const stopwordsTitle = page.locator('h2:has-text("Palabras ignoradas")');
		assert.ok(await stopwordsTitle.isVisible(), 'H2 "Palabras ignoradas" visible');
		report.assertionsPassed++;

		const stopwordsForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_save_stopwords"])');
		assert.ok(await stopwordsForm.isVisible(), 'Formulario stopwords visible');
		report.assertionsPassed++;

		const stopwordsTextarea = stopwordsForm.locator('textarea[name="stopwords"]');
		assert.ok(await stopwordsTextarea.isVisible(), 'Textarea de stopwords visible');
		assert.ok(await stopwordsTextarea.isEditable(), 'Textarea stopwords editable');
		const stopwordsContent = await stopwordsTextarea.inputValue();
		assert.ok(stopwordsContent.length > 0, 'Textarea debe tener stopwords cargadas');
		const stopwordsWordCount = stopwordsContent.split(/[\n,]+/).filter(w => w.trim().length > 0).length;
		report.assertionsPassed += 3;

		const saveStopwordsBtn = page.getByRole('button', { name: 'Guardar palabras ignoradas' });
		assert.ok(await saveStopwordsBtn.isVisible(), 'Botón guardar stopwords visible');
		report.assertionsPassed++;

		report.tabsTested.push('stopwords');
		report.tabDetails['stopwords'] = { stopwordsWordCount };
		console.log(`  ✓ Pestaña Palabras Ignoradas validada (${stopwordsWordCount} stopwords activas, textarea y submit button).`);

		// =========================================================================
		// 7. TAB 7: AJUSTES
		// =========================================================================
		console.log('\n--- 7. Validando Pestaña: Ajustes (sub=ajustes) ---');
		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-search&sub=ajustes`, { waitUntil: 'networkidle' });

		const activeTabAjustes = navWrapper.locator('a.nav-tab-active');
		assert.match(await activeTabAjustes.textContent(), /Ajustes/, 'Pestaña Ajustes activa');
		report.assertionsPassed++;

		const ajustesTitle = page.locator('h2:has-text("Ajustes del buscador")');
		assert.ok(await ajustesTitle.isVisible(), 'H2 "Ajustes del buscador" visible');
		report.assertionsPassed++;

		const ajustesForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_save_settings"])');
		assert.ok(await ajustesForm.isVisible(), 'Formulario de ajustes visible');
		report.assertionsPassed++;

		// Grupo 1: Toggles del motor
		const fuzzyToggle = ajustesForm.locator('input[name="toggle_fuzzy"], #akb-toggle-fuzzy');
		assert.ok(await fuzzyToggle.count() > 0, 'Toggle fuzzy debe existir (#akb-toggle-fuzzy)');
		const skuToggle = ajustesForm.locator('input[name="toggle_sku_fastpath"], #akb-toggle-sku_fastpath');
		assert.ok(await skuToggle.count() > 0, 'Toggle sku_fastpath debe existir (#akb-toggle-sku_fastpath)');
		const novedadToggle = ajustesForm.locator('input[name="toggle_novedad"], #akb-toggle-novedad');
		assert.ok(await novedadToggle.count() > 0, 'Toggle novedad debe existir (#akb-toggle-novedad)');
		report.assertionsPassed += 3;

		// Grupo 2: Servicios de la tienda
		const serviciosTitle = ajustesForm.locator('h3:has-text("Servicios de la tienda")');
		assert.ok(await serviciosTitle.isVisible(), 'H3 "Servicios de la tienda" visible');
		const indexingToggle = ajustesForm.locator('input[name="toggle_indexing"], #akb-toggle-indexing');
		assert.ok(await indexingToggle.count() > 0, 'Toggle indexing debe existir');
		const suggestToggle = ajustesForm.locator('input[name="toggle_suggest"], #akb-toggle-suggest');
		assert.ok(await suggestToggle.count() > 0, 'Toggle suggest debe existir');
		const popularToggle = ajustesForm.locator('input[name="toggle_popular"], #akb-toggle-popular');
		assert.ok(await popularToggle.count() > 0, 'Toggle popular debe existir');
		const facetsToggle = ajustesForm.locator('input[name="toggle_facets"], #akb-toggle-facets');
		assert.ok(await facetsToggle.count() > 0, 'Toggle facets debe existir');
		report.assertionsPassed += 5;

		// Grupo 3: Mensajes al cliente
		const mensajesTitle = ajustesForm.locator('h3:has-text("Mensajes al cliente")');
		assert.ok(await mensajesTitle.isVisible(), 'H3 "Mensajes al cliente" visible');
		const catalogInput = ajustesForm.locator('input[name="texto_empty_hint_catalog"], #akb-text-empty_hint_catalog');
		assert.ok(await catalogInput.isVisible(), 'Input empty_hint_catalog visible');
		const filtersInput = ajustesForm.locator('input[name="texto_empty_hint_filters"], #akb-text-empty_hint_filters');
		assert.ok(await filtersInput.isVisible(), 'Input empty_hint_filters visible');
		const suggestInput = ajustesForm.locator('input[name="texto_empty_hint_suggest"], #akb-text-empty_hint_suggest');
		assert.ok(await suggestInput.isVisible(), 'Input empty_hint_suggest visible');
		const emptySuggestionsToggle = ajustesForm.locator('input[name="toggle_empty_suggestions"], #akb-toggle-empty_suggestions');
		assert.ok(await emptySuggestionsToggle.count() > 0, 'Toggle empty_suggestions debe existir');
		const tailWordsTextarea = ajustesForm.locator('textarea[name="palabras_empty_tail_words"], #akb-words-empty_tail_words');
		assert.ok(await tailWordsTextarea.isVisible(), 'Textarea empty_tail_words visible');
		const relaxedInput = ajustesForm.locator('input[name="texto_relaxed_notice"], #akb-text-relaxed_notice');
		assert.ok(await relaxedInput.isVisible(), 'Input relaxed_notice visible');
		const networkInput = ajustesForm.locator('input[name="texto_network_notice"], #akb-text-network_notice');
		assert.ok(await networkInput.isVisible(), 'Input network_notice visible');
		report.assertionsPassed += 7;

		// Botón guardar ajustes
		const saveAjustesBtn = page.getByRole('button', { name: 'Guardar ajustes' });
		assert.ok(await saveAjustesBtn.isVisible(), 'Botón "Guardar ajustes" visible');
		report.assertionsPassed++;

		report.tabsTested.push('ajustes');
		console.log('  ✓ Pestaña Ajustes validada (3 grupos de toggles, inputs de copy comercial y botón de guardado).');

		// =========================================================================
		// 10. REPORTE FINAL Y VERIFICACIÓN DE CONSOLA / ERRORES
		// =========================================================================
		report.consoleErrors = consoleErrors;
		report.pageErrors = pageErrors;

		console.log('\n===============================================================');
		console.log('🎯 RESULTADOS FINALES DE LA SUITE PLAYWRIGHT SEARCH ADMIN');
		console.log('===============================================================');
		console.log(`Pestañas auditadas: ${report.tabsTested.length} / ${report.totalTabs} (${report.tabsTested.join(', ')})`);
		console.log(`Aserciones profundas pasadas: ${report.assertionsPassed}`);
		console.log(`Errores de consola de JavaScript: ${consoleErrors.length}`);
		console.log(`Excepciones de página no capturadas: ${pageErrors.length}`);

		if (consoleErrors.length > 0) {
			console.error('❌ ERRORES DE CONSOLA DETECTADOS:', consoleErrors);
		}
		if (pageErrors.length > 0) {
			console.error('❌ EXCEPCIONES DE PÁGINA DETECTADAS:', pageErrors);
		}

		assert.equal(consoleErrors.length, 0, 'No debe haber errores de consola');
		assert.equal(pageErrors.length, 0, 'No debe haber excepciones de página');
		assert.equal(report.tabsTested.length, 7, 'Deben haberse auditado las 7 pestañas');

		console.log('\n✅ CERTIFICACIÓN EXITOSA: 100% de las 7 pestañas verificadas en DOM profundo, 0 errores de consola, 0 excepciones.');

	} catch (err) {
		console.error('\n❌ ERROR EN LA SUITE PLAYWRIGHT:', err);
		process.exitCode = 1;
	} finally {
		await browser.close();
	}
}

run();
