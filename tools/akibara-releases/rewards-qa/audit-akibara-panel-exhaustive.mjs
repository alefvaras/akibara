// @ts-check
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';

const TABS = [
	{ id: 'dashboard', label: 'Dashboard / Centro de Mando', isAction: true },
	{ id: 'home_hero', label: 'Slider de Inicio', isAction: true },
	{ id: 'home_series', label: 'Series en la portada', isAction: false, testField: 'akibara_home_series_ids' },
	{ id: 'catalog_order', label: 'Vitrina', isAction: false, testField: 'akibara_series_page_size' },
	{ id: 'announcement', label: 'Barra de anuncios', isAction: false, testField: 'akibara_announcement_enabled' },
	{ id: 'back_in_stock', label: 'Lista de espera', isAction: false, testField: 'akibara_bis_enabled' },
	{ id: 'series_follow', label: 'Sigue esta serie', isAction: false, testField: 'akibara_series_follow_enabled' },
	{ id: 'free_shipping_progress', label: 'Envío gratis', isAction: false, testField: 'akibara_fsp_enabled' },
	{ id: 'native_cart', label: 'Carrito', isAction: false, testField: 'akibara_native_cart_enabled' },
	{ id: 'upsells_autofill', label: 'Recomendaciones', isAction: false, testField: 'akibara_upsells_enabled' },
	{ id: 'taxonomy_discount', label: 'Descuentos', isAction: false, testField: 'akibara_taxonomy_discount_enabled' },
	{ id: 'address', label: 'Autocompletar dirección', isAction: false, testField: 'akibara_address_autocomplete_enabled' },
	{ id: 'imagenes', label: 'Imágenes', isAction: false, testField: 'akibara_image_quality_avif' },
	{ id: 'velocidad', label: 'Velocidad', isAction: false, testField: 'akibara_cache_warmer_enabled' },
	{ id: 'copys', label: 'Textos', isAction: false, testField: 'akibara_copys_enabled' },
	{ id: 'salud_seo', label: 'Salud SEO', isAction: false, testField: 'akibara_llms_manifest_enabled' },
];

const SIDEBAR_PAGES = [
	{ id: 'rewards', label: 'Puntos y Reseñas', url: `${BASE}/wp-admin/admin.php?page=akb-rewards-settings` },
	{ id: 'search', label: 'Buscador', url: `${BASE}/wp-admin/admin.php?page=akibara-search` },
	{ id: 'pricing_matrix', label: 'Matriz de Precios', url: `${BASE}/wp-admin/admin.php?page=akibara-pricing-matrix` },
	{ id: 'access', label: 'Claves y accesos', url: `${BASE}/wp-admin/admin.php?page=akibara-provider-access` },
];

console.log('=== INICIANDO AUDITORÍA FORENSE EN VIVO: AKIBARA PANEL (LOCAL DOCKER) ===\n');

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();

const consoleErrors = [];
page.on('console', (msg) => {
	if (msg.type() === 'error') consoleErrors.push(msg.text().slice(0, 160));
});
page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${String(err).slice(0, 160)}`));

// 1. Login
console.log('[AUTH] Iniciando sesión como qa.owner...');
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await page.click('#wp-submit');
await page.waitForTimeout(1500);
if (!page.url().includes('wp-admin')) {
	await page.goto(`${BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' });
}
console.log('  ✔ Autenticación exitosa. URL actual:', page.url());

const auditResults = [];

// 2. Iterar sobre cada pestaña de akibara-panel
for (const tab of TABS) {
	console.log(`\n[TAB] Auditando pestaña: ${tab.label} (tab=${tab.id})...`);
	consoleErrors.length = 0;
	const start = Date.now();
	const url = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${tab.id}`;
	const resp = await page.goto(url, { waitUntil: 'load' });
	const duration = Date.now() - start;

	const status = resp ? resp.status() : 0;
	const bodyText = await page.evaluate(() => document.body ? document.body.innerText : '');
	const html = await page.content();

	const phpErrors = [];
	const fatalMatch = /Fatal error|Parse error|Warning:|Notice:|Deprecated:|Ha habido un error crítico/i.exec(bodyText)
		|| /Fatal error|Parse error/i.exec(html);
	if (fatalMatch) {
		phpErrors.push(fatalMatch[0]);
	}

	// Extraer detalles del DOM
	const domDetails = await page.evaluate(() => {
		const titleEl = document.querySelector('#akb-panel-title, h1, h2');
		const title = titleEl ? titleEl.textContent?.trim() : '';
		const inputs = Array.from(document.querySelectorAll('.akb-panel-content input, .akb-panel-content select, .akb-panel-content textarea, .akb-panel-content button'));
		const forms = Array.from(document.querySelectorAll('.akb-panel-content form'));
		const tables = Array.from(document.querySelectorAll('.akb-panel-content table'));
		const cards = Array.from(document.querySelectorAll('.akb-dashboard-card, .akb-card, .akb-spec-panel'));
		const buttons = inputs.filter(i => i.tagName === 'BUTTON' || (i.tagName === 'INPUT' && (i.getAttribute('type') === 'submit' || i.getAttribute('type') === 'button')));
		const navLinks = Array.from(document.querySelectorAll('.akb-panel-nav__link'));

		// Nombres de los campos WC o campos de formulario
		const fieldNames = inputs.map(i => i.getAttribute('name') || i.getAttribute('id') || i.className).filter(Boolean);

		return {
			title,
			controlsCount: inputs.length,
			formsCount: forms.length,
			tablesCount: tables.length,
			cardsCount: cards.length,
			buttonsCount: buttons.length,
			navLinksCount: navLinks.length,
			sampleFields: fieldNames.slice(0, 10),
		};
	});

	console.log(`  ✔ Status: ${status} | Tiempo: ${duration}ms | Controles: ${domDetails.controlsCount} | Título: «${domDetails.title}»`);
	if (phpErrors.length) console.warn('  ⚠️ ERRORES PHP DETECTADOS:', phpErrors);
	if (consoleErrors.length) console.warn('  ⚠️ ERRORES DE CONSOLA:', consoleErrors);

	auditResults.push({
		tab: tab.id,
		label: tab.label,
		isAction: tab.isAction,
		url,
		status,
		duration,
		phpErrors,
		consoleErrors: [...consoleErrors],
		domDetails,
	});
}

// 3. Iterar sobre pantallas satélite de Akibara
console.log('\n=== AUDITANDO PANTALLAS SATÉLITE EN MENÚ AKIBARA ===');
for (const sat of SIDEBAR_PAGES) {
	console.log(`\n[SATÉLITE] Auditando: ${sat.label} (${sat.url})...`);
	consoleErrors.length = 0;
	const start = Date.now();
	const resp = await page.goto(sat.url, { waitUntil: 'load' });
	const duration = Date.now() - start;

	const status = resp ? resp.status() : 0;
	const bodyText = await page.evaluate(() => document.body ? document.body.innerText : '');
	const html = await page.content();

	const phpErrors = [];
	const fatalMatch = /Fatal error|Parse error|Warning:|Notice:|Deprecated:|Ha habido un error crítico/i.exec(bodyText)
		|| /Fatal error|Parse error/i.exec(html);
	if (fatalMatch) phpErrors.push(fatalMatch[0]);

	const domDetails = await page.evaluate(() => {
		const titleEl = document.querySelector('h1, h2');
		const title = titleEl ? titleEl.textContent?.trim() : '';
		const inputs = Array.from(document.querySelectorAll('#wpbody-content input, #wpbody-content select, #wpbody-content textarea, #wpbody-content button'));
		return {
			title,
			controlsCount: inputs.length,
		};
	});

	console.log(`  ✔ Status: ${status} | Tiempo: ${duration}ms | Controles: ${domDetails.controlsCount} | Título: «${domDetails.title}»`);
	if (phpErrors.length) console.warn('  ⚠️ ERRORES PHP DETECTADOS:', phpErrors);

	auditResults.push({
		tab: sat.id,
		label: sat.label,
		isAction: true,
		url: sat.url,
		status,
		duration,
		phpErrors,
		consoleErrors: [...consoleErrors],
		domDetails,
	});
}

// 4. Guardar resultados para el reporte
const outputPath = path.join(import.meta.dirname, 'audit-akibara-panel-results.json');
fs.writeFileSync(outputPath, JSON.stringify(auditResults, null, 2));
console.log(`\n✔ Auditoría de superficies completada. Resultados guardados en: ${outputPath}`);

await browser.close();
