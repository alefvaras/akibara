import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOTS_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/human-qa';
fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });

async function humanJourney() {
	console.log('🧑‍💻 Iniciando QA Visual Humano Exhaustivo en Akibara (http://localhost:8080)...');

	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	// =========================================================================
	// PARTE 1: NAVEGACIÓN DESKTOP (1440 x 900)
	// =========================================================================
	console.log('\n--- PARTE 1: Experiencia de Compra en Escritorio (Desktop) ---');
	const desktopContext = await browser.newContext({
		viewport: { width: 1440, height: 900 },
		userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'
	});
	const page = await desktopContext.newPage();

	// 1.1 Portada y Buscador Principal
	console.log('1.1 El cliente entra a la portada http://localhost:8080/ ...');
	await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
	await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '01-desktop-home.png') });
	console.log('  📸 Guardado: 01-desktop-home.png');

	// 1.2 Tipeo humano en el buscador principal
	console.log('1.2 El cliente hace clic en el buscador y tipea "chainsaw"...');
	const searchInput = page.locator('input[type="search"]').first();
	await searchInput.click();
	await page.waitForTimeout(200);
	
	// Tipear como humano con pequeñas pausas
	for (const char of 'chainsaw') {
		await searchInput.press(char);
		await page.waitForTimeout(80);
	}
	await page.waitForTimeout(800); // esperar que aparezca el panel de sugerencias

	await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '02-desktop-search-dropdown-chainsaw.png') });
	console.log('  📸 Guardado: 02-desktop-search-dropdown-chainsaw.png');

	// 1.3 Presionar Escape (comportamiento accesible)
	console.log('1.3 El cliente presiona Escape para cerrar el desplegable...');
	await searchInput.press('Escape');
	await page.waitForTimeout(400);

	// 1.4 Navegar al catálogo completo (/tienda/)
	console.log('1.4 El cliente hace clic en la Tienda (/tienda/)...');
	await page.goto('http://localhost:8080/tienda/', { waitUntil: 'networkidle' });
	await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '03-desktop-shop-landing.png') });
	console.log('  📸 Guardado: 03-desktop-shop-landing.png');

	// 1.5 Inspeccionar el Sidebar de Filtros: Acordeón "País de Origen"
	console.log('1.5 El cliente expande el filtro "País de Origen" para ver las opciones...');
	const paisFacet = page.locator('.akb-facet[data-facet="pais"]');
	const paisToggle = paisFacet.locator('[data-akb-facet-toggle]');
	const isPaisOpen = await paisToggle.getAttribute('aria-expanded');
	if (isPaisOpen !== 'true') {
		await paisToggle.click();
		await page.waitForTimeout(350);
	}
	await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '04-desktop-facet-pais-expanded.png') });
	console.log('  📸 Guardado: 04-desktop-facet-pais-expanded.png');

	// 1.6 El cliente hace click en "Argentina (1.317)"
	console.log('1.6 El cliente marca la casilla "Argentina"...');
	const argCheckbox = paisFacet.locator('input[value="argentina"]');
	
	// Esperar respuesta PJAX
	await Promise.all([
		page.waitForResponse(res => res.url().includes('_pais=argentina') && res.status() === 200),
		argCheckbox.click()
	]);
	await page.waitForTimeout(600); // estabilizar rendering
	await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '05-desktop-filter-argentina-applied.png') });
	console.log('  📸 Guardado: 05-desktop-filter-argentina-applied.png');

	// 1.7 Búsqueda Rápida dentro de una faceta larga (Autor)
	console.log('1.7 El cliente abre "Autor" y usa el buscador rápido interno para tipear "oda"...');
	const autorFacet = page.locator('.akb-facet[data-facet="autor"]');
	const autorToggle = autorFacet.locator('[data-akb-facet-toggle]');
	if ((await autorToggle.getAttribute('aria-expanded')) !== 'true') {
		await autorToggle.click();
		await page.waitForTimeout(350);
	}

	const autorSearch = autorFacet.locator('input.akb-facet-search, [data-akb-facet-search]');
	await autorSearch.click();
	for (const char of 'oda') {
		await autorSearch.press(char);
		await page.waitForTimeout(90);
	}
	await page.waitForTimeout(400);

	await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '06-desktop-autor-search-oda-typed.png') });
	console.log('  📸 Guardado: 06-desktop-autor-search-oda-typed.png');

	// 1.8 El cliente selecciona a Eiichiro Oda mientras mantiene el filtro de Argentina
	console.log('1.8 El cliente selecciona a Eiichiro Oda...');
	const odaCheckbox = autorFacet.locator('.akb-facet-item:has-text("Eiichiro Oda") input[type="checkbox"]').first();
	
	if (await odaCheckbox.count() > 0) {
		await Promise.all([
			page.waitForResponse(res => res.url().includes('_autor=') && res.status() === 200),
			odaCheckbox.click()
		]);
		await page.waitForTimeout(600);
	}

	await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '07-desktop-autor-oda-filtered-and-preserved.png') });
	console.log('  📸 Guardado: 07-desktop-autor-oda-filtered-and-preserved.png');

	// 1.9 El cliente ordena por "Precio: de menor a mayor"
	console.log('1.9 El cliente ordena el catálogo por precio ascendente (bajo a alto)...');
	await page.goto('http://localhost:8080/tienda/?orderby=price', { waitUntil: 'networkidle' });
	await page.waitForTimeout(600);

	await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '08-desktop-catalog-orderby-price-asc.png') });
	console.log('  📸 Guardado: 08-desktop-catalog-orderby-price-asc.png');

	// 1.10 El cliente ordena por "Precio: de mayor a menor"
	console.log('1.10 El cliente ordena el catálogo por precio descendente (alto a bajo)...');
	await page.goto('http://localhost:8080/tienda/?orderby=price-desc', { waitUntil: 'networkidle' });
	await page.waitForTimeout(600);

	await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '09-desktop-catalog-orderby-price-desc.png') });
	console.log('  📸 Guardado: 09-desktop-catalog-orderby-price-desc.png');

	await desktopContext.close();

	// =========================================================================
	// PARTE 2: NAVEGACIÓN MÓVIL (iPhone 14/15/16 - 390 x 844)
	// =========================================================================
	console.log('\n--- PARTE 2: Experiencia Móvil de Usuario (Smartphone) ---');
	const mobileContext = await browser.newContext({
		viewport: { width: 390, height: 844 },
		isMobile: true,
		hasTouch: true,
		userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'
	});
	const mobilePage = await mobileContext.newPage();

	// 2.1 Tienda en Móvil
	console.log('2.1 El cliente entra a la tienda desde el celular...');
	await mobilePage.goto('http://localhost:8080/tienda/', { waitUntil: 'networkidle' });
	await mobilePage.screenshot({ path: path.join(SCREENSHOTS_DIR, '10-mobile-shop-view.png') });
	console.log('  📸 Guardado: 10-mobile-shop-view.png');

	// 2.2 Abrir el Drawer de Filtros Móvil
	console.log('2.2 El cliente toca el botón "Filtros" para abrir el drawer...');
	const drawerBtn = mobilePage.locator('[data-akb-drawer-open], .akb-shop-toolbar button:has-text("Filtros")').first();
	if (await drawerBtn.isVisible()) {
		await drawerBtn.click();
		await mobilePage.waitForTimeout(500);

		await mobilePage.screenshot({ path: path.join(SCREENSHOTS_DIR, '11-mobile-drawer-opened.png') });
		console.log('  📸 Guardado: 11-mobile-drawer-opened.png');

		// 2.3 Marcar una opción dentro del drawer
		console.log('2.3 El cliente selecciona un filtro dentro del drawer...');
		const catCheck = mobilePage.locator('.akb-facet[data-facet="categoria"] .akb-facet-item input[type="checkbox"]').first();
		if (await catCheck.isVisible()) {
			await catCheck.click();
			await mobilePage.waitForTimeout(600);
			await mobilePage.screenshot({ path: path.join(SCREENSHOTS_DIR, '12-mobile-drawer-option-selected.png') });
			console.log('  📸 Guardado: 12-mobile-drawer-option-selected.png');
		}

		// 2.4 Tocar el botón "Ver productos" del drawer para aplicar
		const applyBtn = mobilePage.locator('.akb-facets-apply, [data-akb-apply]').first();
		if (await applyBtn.isVisible()) {
			await applyBtn.click();
			await mobilePage.waitForTimeout(800);
			await mobilePage.screenshot({ path: path.join(SCREENSHOTS_DIR, '13-mobile-catalog-filtered.png') });
			console.log('  📸 Guardado: 13-mobile-catalog-filtered.png');
		}
	}

	await mobileContext.close();
	await browser.close();

	console.log('\n🎉 Recorrido Humano Visual Completado con Éxito (13 capturas de alta resolución).');
}

humanJourney().catch(err => {
	console.error('Error durante el recorrido humano:', err);
	process.exit(1);
});
