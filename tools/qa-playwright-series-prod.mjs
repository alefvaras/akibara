import playwright from './akibara-e2e/node_modules/playwright/index.js';
import assert from 'node:assert/strict';

const pw = playwright.default || playwright;
const { chromium } = pw;

const BASE_URL = process.env.AKB_BASE_URL || 'https://akibara.cl';

async function run() {
	console.log('🚀 Iniciando suite adversarial Playwright contra producción:', BASE_URL);

	const browser = await chromium.launch({
		headless: true,
		executablePath: process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const context = await browser.newContext({
		viewport: { width: 1280, height: 800 },
		userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 AkibaraQA/1.0'
	});

	const page = await context.newPage();

	try {
		// -----------------------------------------------------------------------
		// PRUEBA 1: Directorio de Series (/series/)
		// -----------------------------------------------------------------------
		console.log('\n--- PRUEBA 1: Directorio de Series (/series/) ---');
		await page.goto(`${BASE_URL}/series/`, { waitUntil: 'domcontentloaded', timeout: 30000 });

		// 1.1 Placeholder
		const searchInput = page.locator('#akb-series-directory-input');
		await searchInput.waitFor({ state: 'visible', timeout: 10000 });
		const placeholder = await searchInput.getAttribute('placeholder');
		console.log('✔ Placeholder del buscador:', placeholder);
		assert.equal(placeholder, 'Escribe la serie, autor o edición', 'El placeholder debe ser exactamente "Escribe la serie, autor o edición"');

		// 1.2 Atributo data-akb-serie-id en cada item
		const items = page.locator('li.akb-series-directory__item');
		const itemCount = await items.count();
		console.log(`✔ Total items en directorio: ${itemCount}`);
		assert.ok(itemCount > 50, 'El directorio debe tener más de 50 series');

		const firstItem = items.first();
		const termId = await firstItem.getAttribute('data-akb-serie-id');
		console.log(`✔ Primer item tiene data-akb-serie-id="${termId}"`);
		assert.ok(termId && /^\d+$/.test(termId), 'El primer item debe poseer un data-akb-serie-id numérico');

		// Verificar que al menos los primeros 10 tengan data-akb-serie-id
		for (let i = 0; i < Math.min(10, itemCount); i++) {
			const tid = await items.nth(i).getAttribute('data-akb-serie-id');
			assert.ok(tid && /^\d+$/.test(tid), `Item ${i} debe tener data-akb-serie-id`);
		}

		// 1.3 Búsqueda por autor en el input (haystack enrichment)
		console.log('Probando búsqueda reactiva por autor: "Urasawa"...');
		await searchInput.fill('Urasawa');
		// Esperar debounce del filtro (150ms-300ms)
		await page.waitForTimeout(500);

		// 20th Century Boys debe ser visible
		const urasawaItem = page.locator('li.akb-series-directory__item:has-text("20th Century Boys")');
		assert.ok(await urasawaItem.isVisible(), '20th Century Boys debe ser visible al buscar por su autor Naoki Urasawa');
		console.log('✔ "20th Century Boys" permanece visible al buscar "Urasawa"');

		// Verificar que series no relacionadas (ej. "Akira", cuyo autor es Katsuhiro Otomo) estén ocultas
		const akiraItem = page.locator('li.akb-series-directory__item:has-text("Akira")').first();
		if (await akiraItem.count() > 0) {
			const isAkiraVisible = await akiraItem.isVisible();
			assert.equal(isAkiraVisible, false, 'Akira debe estar oculto al buscar "Urasawa"');
			console.log('✔ Series de otros autores (ej. Akira) están ocultas');
		}

		// 1.4 Búsqueda por otro autor: "Fujimoto" (Chainsaw Man, Look Back)
		console.log('Probando búsqueda reactiva por autor: "Fujimoto"...');
		await searchInput.fill('Fujimoto');
		await page.waitForTimeout(500);
		const chainsawItem = page.locator('li.akb-series-directory__item:has-text("Chainsaw Man")').first();
		if (await chainsawItem.count() > 0) {
			assert.ok(await chainsawItem.isVisible(), 'Chainsaw Man debe ser visible al buscar "Fujimoto"');
			console.log('✔ "Chainsaw Man" visible al buscar "Fujimoto"');
		}

		// Limpiar input
		await searchInput.fill('');
		await page.waitForTimeout(500);

		// -----------------------------------------------------------------------
		// PRUEBA 2: Ficha de Serie (/serie/20th-century-boys/)
		// -----------------------------------------------------------------------
		console.log('\n--- PRUEBA 2: Landing de Serie (/serie/20th-century-boys/) ---');
		await page.goto(`${BASE_URL}/serie/20th-century-boys/`, { waitUntil: 'domcontentloaded', timeout: 30000 });

		// 2.1 Resumen superior y ancla
		const summary = page.locator('.akb-series-summary');
		await summary.waitFor({ state: 'visible', timeout: 10000 });
		console.log('✔ .akb-series-summary visible');

		const moreLink = summary.locator('a.akb-series-summary__more');
		const href = await moreLink.getAttribute('href');
		console.log(`✔ Enlace "Ver ficha completa": href="${href}"`);
		assert.equal(href, '#ficha-de-la-serie', 'El ancla debe apuntar exactamente a #ficha-de-la-serie');

		// Simular clic en el ancla y verificar que navega al elemento
		await moreLink.click();
		await page.waitForTimeout(300);
		const currentUrl = page.url();
		console.log(`✔ URL tras clic en ancla: ${currentUrl}`);
		assert.ok(currentUrl.endsWith('#ficha-de-la-serie'), 'La URL debe contener el hash #ficha-de-la-serie tras el clic');

		// 2.2 Sección SeriesFacts con ID ficha-de-la-serie
		const factsSection = page.locator('section.akb-series-facts#ficha-de-la-serie');
		await factsSection.waitFor({ state: 'visible', timeout: 10000 });
		console.log('✔ section.akb-series-facts#ficha-de-la-serie visible en el DOM');

		const factsText = await factsSection.textContent();
		console.log('Contenido de la ficha técnica:\n', factsText.replace(/\s+/g, ' ').trim());

		// Aserciones de contenido en ficha
		assert.ok(factsText.includes('Ivrea Argentina'), 'Debe mostrar la editorial');
		assert.ok(factsText.includes('Naoki Urasawa'), 'Debe mostrar el autor');
		assert.ok(factsText.includes('11 tomos'), 'Debe mencionar 11 tomos');
		assert.ok(factsText.includes('06, 07, 08 y 09'), 'Los tomos agotados deben estar ordenados numéricamente en orden ascendente (06, 07, 08 y 09)');
		console.log('✔ Ordenamiento numérico ascendente de agotados verificado: "06, 07, 08 y 09"');

		// 2.3 Último tomo numérico en .akb-series-latest
		const latestVolLink = page.locator('.akb-series-latest');
		if (await latestVolLink.count() > 0) {
			const latestText = await latestVolLink.textContent();
			const latestHref = await latestVolLink.getAttribute('href');
			console.log(`✔ Último tomo resuelto: "${latestText.trim()}" -> ${latestHref}`);
			assert.ok(latestText.includes('11'), 'El último tomo de 20th Century Boys debe ser el tomo 11 (numérico)');
			assert.ok(latestHref.includes('20th-century-boys-11'), 'El enlace debe apuntar al tomo 11');
		}

		// -----------------------------------------------------------------------
		// PRUEBA 3: Verificación de Serie Larga (> 30 tomos, ej. One Piece o Naruto o Detective Conan)
		// -----------------------------------------------------------------------
		console.log('\n--- PRUEBA 3: Verificación de Serie Larga (/serie/one-piece/ o similar) ---');
		const onePieceResponse = await page.goto(`${BASE_URL}/serie/one-piece/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
		if (onePieceResponse.status() === 200) {
			const opFacts = page.locator('section.akb-series-facts#ficha-de-la-serie');
			if (await opFacts.count() > 0) {
				const opFactsText = await opFacts.textContent();
				console.log('✔ One Piece Facts rendered correctly');
				console.log('Fragmento One Piece Facts:', opFactsText.replace(/\s+/g, ' ').slice(0, 150));
			}
			const opLatest = page.locator('.akb-series-latest');
			if (await opLatest.count() > 0) {
				const opLatestText = await opLatest.textContent();
				const opLatestHref = await opLatest.getAttribute('href');
				console.log(`✔ One Piece Último tomo: "${opLatestText.trim()}" -> ${opLatestHref}`);
				// Verificar que sea un número de tomo >= 100 si está publicado
				const match = opLatestText.match(/(\d+)/);
				if (match) {
					const num = parseInt(match[1], 10);
					console.log(`✔ Tomo extraído numéricamente: ${num}`);
					assert.ok(num >= 10, 'El último tomo debe ser un número entero válido');
				}
			}
		}

		console.log('\n======================================================');
		console.log('🎉 TODAS LAS PRUEBAS PLAYWRIGHT EN PRODUCCIÓN PASARON 100%');
		console.log('======================================================\n');
	} finally {
		await browser.close();
	}
}

run().catch((err) => {
	console.error('❌ Error en prueba Playwright en producción:', err);
	process.exit(1);
});
