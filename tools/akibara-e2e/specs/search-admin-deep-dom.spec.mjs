// @ts-check
/**
 * Test E2E de Playwright: DOM Profundo de Search Admin (Akibara Search).
 *
 * Valida de forma exhaustiva las 7 subpestañas del panel administrativo:
 *   - Índice (sub=indice)
 *   - Búsquedas (sub=busquedas)
 *   - Facetas (sub=facetas)
 *   - Precios (sub=precios)
 *   - Sinónimos (sub=sinonimos)
 *   - Palabras ignoradas (sub=stopwords)
 *   - Ajustes (sub=ajustes)
 *
 * Certifica que no existan errores de consola ni excepciones de página,
 * y verifica todos los elementos interactivos y estructurales.
 *
 * @package Akibara\E2E
 */

import { spawnSync, execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

function getAdminCookies() {
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
			'uid' => $u->ID,
			'token' => $tok,
			'cookies' => [
				['name' => AUTH_COOKIE, 'value' => $auth, 'path' => '/'],
				['name' => LOGGED_IN_COOKIE, 'value' => $logged, 'path' => '/']
			]
		]);
	`;
	const p = spawnSync(
		'docker',
		['exec', '-i', 'akibara-cron-1', 'wp', 'eval-file', '-'],
		{ input: php, encoding: 'utf8', timeout: 60_000 }
	);
	if (p.status !== 0) {
		throw new Error(`Failed to get admin cookies: ${p.stderr || p.error}`);
	}
	return JSON.parse(p.stdout.trim());
}

test.describe('Buscador: DOM profundo de Search Admin (8 subpestañas)', () => {
	test.describe.configure({ mode: 'serial' });
	test.skip(soloLectura, 'necesita sesión de administrador local (espejo)');

	/** @type {{uid:number, token:string, cookies:Array<{name:string,value:string,path:string}>}} */
	let sesion;
	/** @type {Array<string>} */
	const consoleErrors = [];
	/** @type {Array<string>} */
	const pageErrors = [];

	test.beforeAll(({}, info) => {
		if ('escritorio' !== info.project.name) {
			return;
		}
		sesion = getAdminCookies();
		expect(sesion.token, 'Se debe generar un token de sesión de admin').toBeTruthy();
	});

	test.afterAll(() => {
		if (sesion?.token && sesion?.uid) {
			try {
				spawnSync(
					'docker',
					['exec', '-i', 'akibara-cron-1', 'wp', 'eval', `WP_Session_Tokens::get_instance(${sesion.uid})->destroy(${JSON.stringify(sesion.token)});`],
					{ timeout: 30_000, encoding: 'utf8' }
				);
			} catch {
				// No bloquear si el token ya expiró
			}
		}
	});

	test.beforeEach(async ({ context, page, baseURL }, info) => {
		test.skip('escritorio' !== info.project.name, 'el panel de administración se audita en proyecto de escritorio');
		if (!sesion) {
			sesion = getAdminCookies();
		}
		const hostname = new URL(String(baseURL)).hostname;
		await context.addCookies(
			sesion.cookies.map(c => ({ ...c, domain: hostname, path: '/' }))
		);

		page.on('console', msg => {
			if (msg.type() === 'error') {
				consoleErrors.push(`[Console Error] ${msg.text()}`);
			}
		});

		page.on('pageerror', err => {
			pageErrors.push(`[Page Error] ${err.message}`);
		});
	});

	test('1. Shell general: Header FacetWP, gema, versión, reindex y subnav con 8 pestañas', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=akibara-search', { waitUntil: 'networkidle' });

		// Panel wrap
		const panelWrap = page.locator('.wrap.akb-fwp .akb-fwp-panel');
		await expect(panelWrap).toBeVisible();

		// Header y Brand
		const head = page.locator('.akb-fwp-head');
		await expect(head).toBeVisible();

		const brandH1 = head.locator('.akb-fwp-brand h1');
		await expect(brandH1).toHaveText('Buscador');

		const brandGem = head.locator('.akb-fwp-brand .akb-fwp-gem');
		await expect(brandGem).toBeVisible();

		const brandVer = head.locator('.akb-fwp-brand .akb-fwp-ver');
		await expect(brandVer).toHaveText(/^v\d+\.\d+\.\d+$/);

		// Botón Reconstruir índice del header
		const reindexBtn = head.locator('form button.akb-fwp-reindex');
		await expect(reindexBtn).toBeVisible();
		await expect(reindexBtn).toHaveText('Reconstruir índice');

		// Subnav con 7 pestañas
		const navWrapper = page.locator('nav.nav-tab-wrapper[aria-label="Secciones del buscador"]');
		await expect(navWrapper).toBeVisible();

		const navLinks = navWrapper.locator('a.nav-tab');
		await expect(navLinks).toHaveCount(7);

		const expectedTabs = [
			{ slug: 'indice', label: /Índice/i },
			{ slug: 'busquedas', label: /Búsquedas/i },
			{ slug: 'facetas', label: /Facetas/i },
			{ slug: 'precios', label: /Precios/i },
			{ slug: 'sinonimos', label: /Sinónimos/i },
			{ slug: 'stopwords', label: /Palabras ignoradas/i },
			{ slug: 'ajustes', label: /Ajustes/i }
		];

		for (let i = 0; i < expectedTabs.length; i++) {
			const tabLink = navLinks.nth(i);
			await expect(tabLink).toContainText(expectedTabs[i].label);
			await expect(tabLink).toHaveAttribute('href', new RegExp(`sub=${expectedTabs[i].slug}`));
		}
	});

	test('2. Pestaña Índice: banda de salud, servicios, estado, mantenimiento y diagnóstico', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=akibara-search&sub=indice', { waitUntil: 'networkidle' });

		const activeTab = page.locator('nav.nav-tab-wrapper a.nav-tab-active');
		await expect(activeTab).toContainText('Índice');

		// Banda de salud
		const healthBand = page.locator('.akb-health-band[data-akb-search-status]');
		await expect(healthBand).toBeVisible();

		const healthVerdict = healthBand.locator('.akb-health-verdict strong');
		await expect(healthVerdict).toBeVisible();
		await expect(healthVerdict).not.toBeEmpty();

		const healthDot = healthBand.locator('.akb-health-dot');
		await expect(healthDot).toBeVisible();

		const docCountParagraph = healthBand.locator('p').nth(1);
		await expect(docCountParagraph).toContainText(/productos indexados|Reconstruyendo/i);

		// Servicios
		const servicesHeading = page.locator('.akb-search-admin h2:has-text("Servicios")');
		await expect(servicesHeading).toBeVisible();

		// Tablas dentro de akb-search-admin
		const tables = page.locator('.akb-search-admin table.widefat');
		expect(await tables.count()).toBeGreaterThanOrEqual(2);

		// Mantenimiento (Rebuild & Reconcile)
		const maintenanceHeading = page.locator('h2#akb-search-maintenance');
		await expect(maintenanceHeading).toBeVisible();

		const bodyRebuildForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_rebuild"])');
		await expect(bodyRebuildForm).toBeVisible();

		const bodyReconcileForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_reconcile_run"])');
		await expect(bodyReconcileForm).toBeVisible();

		// Diagnóstico colapsable
		const diagSection = page.locator('details.akb-search-diagnostics');
		await expect(diagSection).toBeVisible();
		const diagSummary = diagSection.locator('summary');
		await expect(diagSummary).toContainText(/Diagnóstico técnico/i);
	});

	test('3. Pestaña Búsquedas: encabezado, top búsquedas y oportunidades sin resultados', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=akibara-search&sub=busquedas', { waitUntil: 'networkidle' });

		const activeTab = page.locator('nav.nav-tab-wrapper a.nav-tab-active');
		await expect(activeTab).toContainText('Búsquedas');

		const searchHeading = page.locator('.akb-search-admin h2');
		await expect(searchHeading.first()).toBeVisible();

		const topHeading = page.locator('h3:has-text("Top búsquedas")');
		const emptyLogMsg = page.locator('p.description:has-text("Sin búsquedas registradas todavía")');
		const isTopVisible = await topHeading.isVisible();
		const isEmptyVisible = await emptyLogMsg.isVisible();
		expect(isTopVisible || isEmptyVisible).toBe(true);
	});

	test('4. Pestaña Facetas: grilla de taxonomías, controles UI, fieldset y guardado', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=akibara-search&sub=facetas', { waitUntil: 'networkidle' });

		const activeTab = page.locator('nav.nav-tab-wrapper a.nav-tab-active');
		await expect(activeTab).toContainText('Facetas');

		const facetasTitle = page.locator('h2:has-text("Facetas del catálogo")');
		await expect(facetasTitle).toBeVisible();

		const facetsForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_save_facets"])');
		await expect(facetsForm).toBeVisible();

		const facetsTable = page.locator('table.akb-facets-table');
		await expect(facetsTable).toBeVisible();

		const facetRows = facetsTable.locator('tbody tr[data-akb-facet-row]');
		expect(await facetRows.count()).toBeGreaterThanOrEqual(3);

		const firstRow = facetRows.first();
		await expect(firstRow.locator('button[data-akb-move="up"]')).toBeVisible();
		await expect(firstRow.locator('button[data-akb-move="down"]')).toBeVisible();
		await expect(firstRow.locator('input[name*="[label]"]')).toBeVisible();
		await expect(firstRow.locator('select.akb-facet-ui')).toBeVisible();
		await expect(firstRow.locator('input[type="checkbox"][name*="[visible]"]')).toBeVisible();

		const newFacetFieldset = page.locator('fieldset.akb-facets-new');
		await expect(newFacetFieldset).toBeVisible();

		const saveFacetsBtn = page.getByRole('button', { name: 'Guardar facetas' });
		await expect(saveFacetsBtn).toBeVisible();
	});

	test('6. Pestaña Precios: badge de modo, tabla de tramos, inputs y botones de recálculo', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=akibara-search&sub=precios', { waitUntil: 'networkidle' });

		const activeTab = page.locator('nav.nav-tab-wrapper a.nav-tab-active');
		await expect(activeTab).toContainText('Precios');

		const preciosTitle = page.locator('h2:has-text("Rangos de precio")');
		await expect(preciosTitle).toBeVisible();

		const modeBadge = page.locator('h2 .akb-mode-badge');
		await expect(modeBadge).toBeVisible();
		await expect(modeBadge).toContainText(/Automático|Manual/);

		const preciosTable = page.locator('table.akb-precios-table');
		await expect(preciosTable).toBeVisible();

		const minInputs = preciosTable.locator('input[name="precio_min[]"]');
		const maxInputs = preciosTable.locator('input[name="precio_max[]"]');
		expect(await minInputs.count()).toBeGreaterThanOrEqual(2);
		expect(await maxInputs.count()).toBeGreaterThanOrEqual(2);

		const savePreciosBtn = page.getByRole('button', { name: 'Guardar rangos' });
		await expect(savePreciosBtn).toBeVisible();

		const recalcBtn = page.getByRole('button', { name: /Recalcular desde el catálogo/ });
		await expect(recalcBtn).toBeVisible();
	});

	test('7. Pestaña Sinónimos: textarea editable con reglas y botón de guardado', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=akibara-search&sub=sinonimos', { waitUntil: 'networkidle' });

		const activeTab = page.locator('nav.nav-tab-wrapper a.nav-tab-active');
		await expect(activeTab).toContainText('Sinónimos');

		const sinonimosTitle = page.locator('h2:has-text("Sinónimos")');
		await expect(sinonimosTitle).toBeVisible();

		const sinonimosForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_save_synonyms"])');
		await expect(sinonimosForm).toBeVisible();

		const sinonimosTextarea = sinonimosForm.locator('textarea[name="synonyms"]');
		await expect(sinonimosTextarea).toBeVisible();
		await expect(sinonimosTextarea).toBeEditable();
		const val = await sinonimosTextarea.inputValue();
		expect(val.length).toBeGreaterThan(0);

		const saveSinonimosBtn = page.getByRole('button', { name: 'Guardar sinónimos' });
		await expect(saveSinonimosBtn).toBeVisible();
	});

	test('8. Pestaña Palabras Ignoradas: textarea editable con stopwords y guardado', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=akibara-search&sub=stopwords', { waitUntil: 'networkidle' });

		const activeTab = page.locator('nav.nav-tab-wrapper a.nav-tab-active');
		await expect(activeTab).toContainText('Palabras ignoradas');

		const stopwordsTitle = page.locator('h2:has-text("Palabras ignoradas")');
		await expect(stopwordsTitle).toBeVisible();

		const stopwordsForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_save_stopwords"])');
		await expect(stopwordsForm).toBeVisible();

		const stopwordsTextarea = stopwordsForm.locator('textarea[name="stopwords"]');
		await expect(stopwordsTextarea).toBeVisible();
		await expect(stopwordsTextarea).toBeEditable();
		const val = await stopwordsTextarea.inputValue();
		expect(val.length).toBeGreaterThan(0);

		const saveStopwordsBtn = page.getByRole('button', { name: 'Guardar palabras ignoradas' });
		await expect(saveStopwordsBtn).toBeVisible();
	});

	test('9. Pestaña Ajustes: 3 grupos de toggles, inputs comerciales y guardado', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=akibara-search&sub=ajustes', { waitUntil: 'networkidle' });

		const activeTab = page.locator('nav.nav-tab-wrapper a.nav-tab-active');
		await expect(activeTab).toContainText('Ajustes');

		const ajustesTitle = page.locator('h2:has-text("Ajustes del buscador")');
		await expect(ajustesTitle).toBeVisible();

		const ajustesForm = page.locator('.akb-fwp-body form:has(input[name="action"][value="akb_search_save_settings"])');
		await expect(ajustesForm).toBeVisible();

		// Grupo 1: Toggles del motor
		const fuzzyToggle = ajustesForm.locator('input[name="toggle_fuzzy"], #akb-toggle-fuzzy');
		expect(await fuzzyToggle.count()).toBeGreaterThan(0);
		const skuToggle = ajustesForm.locator('input[name="toggle_sku_fastpath"], #akb-toggle-sku_fastpath');
		expect(await skuToggle.count()).toBeGreaterThan(0);
		const novedadToggle = ajustesForm.locator('input[name="toggle_novedad"], #akb-toggle-novedad');
		expect(await novedadToggle.count()).toBeGreaterThan(0);

		// Grupo 2: Servicios de la tienda
		const serviciosTitle = ajustesForm.locator('h3:has-text("Servicios de la tienda")');
		await expect(serviciosTitle).toBeVisible();
		const indexingToggle = ajustesForm.locator('input[name="toggle_indexing"], #akb-toggle-indexing');
		expect(await indexingToggle.count()).toBeGreaterThan(0);
		const suggestToggle = ajustesForm.locator('input[name="toggle_suggest"], #akb-toggle-suggest');
		expect(await suggestToggle.count()).toBeGreaterThan(0);
		const popularToggle = ajustesForm.locator('input[name="toggle_popular"], #akb-toggle-popular');
		expect(await popularToggle.count()).toBeGreaterThan(0);
		const facetsToggle = ajustesForm.locator('input[name="toggle_facets"], #akb-toggle-facets');
		expect(await facetsToggle.count()).toBeGreaterThan(0);

		// Grupo 3: Mensajes al cliente
		const mensajesTitle = ajustesForm.locator('h3:has-text("Mensajes al cliente")');
		await expect(mensajesTitle).toBeVisible();
		const catalogInput = ajustesForm.locator('input[name="texto_empty_hint_catalog"], #akb-text-empty_hint_catalog');
		await expect(catalogInput).toBeVisible();
		const filtersInput = ajustesForm.locator('input[name="texto_empty_hint_filters"], #akb-text-empty_hint_filters');
		await expect(filtersInput).toBeVisible();
		const suggestInput = ajustesForm.locator('input[name="texto_empty_hint_suggest"], #akb-text-empty_hint_suggest');
		await expect(suggestInput).toBeVisible();
		const emptySuggestionsToggle = ajustesForm.locator('input[name="toggle_empty_suggestions"], #akb-toggle-empty_suggestions');
		expect(await emptySuggestionsToggle.count()).toBeGreaterThan(0);
		const tailWordsTextarea = ajustesForm.locator('textarea[name="palabras_empty_tail_words"], #akb-words-empty_tail_words');
		await expect(tailWordsTextarea).toBeVisible();
		const relaxedInput = ajustesForm.locator('input[name="texto_relaxed_notice"], #akb-text-relaxed_notice');
		await expect(relaxedInput).toBeVisible();
		const networkInput = ajustesForm.locator('input[name="texto_network_notice"], #akb-text-network_notice');
		await expect(networkInput).toBeVisible();

		const saveAjustesBtn = page.getByRole('button', { name: 'Guardar ajustes' });
		await expect(saveAjustesBtn).toBeVisible();

		// Verificación global de errores
		expect(consoleErrors, `Se detectaron errores de consola: ${consoleErrors.join(' | ')}`).toHaveLength(0);
		expect(pageErrors, `Se detectaron excepciones de página: ${pageErrors.join(' | ')}`).toHaveLength(0);
	});
});
