import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOTS_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/search-improvements-local';

fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });

async function run() {
	console.log('🚀 Iniciando suite de pruebas E2E en local (http://localhost:8080)...');

	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const context = await browser.newContext({
		viewport: { width: 1400, height: 950 }
	});

	const page = await context.newPage();

	let failures = 0;
	function assert(cond, msg) {
		if (cond) {
			console.log(`  ✓ PASS: ${msg}`);
		} else {
			console.error(`  ✗ FAIL: ${msg}`);
			failures++;
		}
	}

	try {
		// =========================================================================
		// PRUEBA 1: Inferencia de País (Mejora D) en la UI
		// =========================================================================
		console.log('\n--- PRUEBA 1: Faceta País e Inferencia Determinista ---');
		await page.goto('http://localhost:8080/tienda/', { waitUntil: 'networkidle', timeout: 30000 });

		const paisFacet = page.locator('.akb-facet[data-facet="pais"]');
		const paisCount = await paisFacet.count();
		assert(paisCount > 0, 'La faceta de País de Origen está visible en el catálogo');

		if (paisCount > 0) {
			// Si está colapsada, abrirla
			const paisToggle = paisFacet.locator('[data-akb-facet-toggle]');
			const isExpanded = await paisToggle.getAttribute('aria-expanded');
			if (isExpanded !== 'true') {
				await paisToggle.click();
				await page.waitForTimeout(300);
			}

			const argItem = paisFacet.locator('.akb-facet-item input[value="argentina"]');
			const espItem = paisFacet.locator('.akb-facet-item input[value="espana"]');
			
			const hasArg = (await argItem.count()) > 0;
			const hasEsp = (await espItem.count()) > 0;
			assert(hasArg && hasEsp, 'Argentina y España aparecen en la lista de opciones de país');

			const argBadge = await paisFacet.locator('.akb-facet-item:has(input[value="argentina"]) .akb-facet-count').innerText();
			const espBadge = await paisFacet.locator('.akb-facet-item:has(input[value="espana"]) .akb-facet-count').innerText();
			console.log(`  ℹ Conteo Argentina: ${argBadge} | Conteo España: ${espBadge}`);
			const numArg = parseInt(argBadge.replace(/\D/g, ''), 10);
			const numEsp = parseInt(espBadge.replace(/\D/g, ''), 10);
			assert(numArg > 1000, `Conteo de Argentina (${numArg}) refleja inferencia masiva (> 1000)`);
			assert(numEsp > 250, `Conteo de España (${numEsp}) refleja inferencia (> 250)`);
		}

		await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '01-local-pais-facet-counts.png'), fullPage: false });

		// =========================================================================
		// PRUEBA 2: Frontend UX - Preservación de Estado de Facetas (Mejora A)
		// =========================================================================
		console.log('\n--- PRUEBA 2: Preservación de Búsqueda Rápida, Foco y Scroll (Mejora A) ---');
		
		// Desplegar la faceta 'autor' para acceder a su buscador rápido
		const autorFacet = page.locator('.akb-facet[data-facet="autor"]');
		if ((await autorFacet.count()) > 0) {
			const autorToggle = autorFacet.locator('[data-akb-facet-toggle]');
			if ((await autorToggle.getAttribute('aria-expanded')) !== 'true') {
				await autorToggle.click();
				await page.waitForTimeout(400);
			}

			const autorSearchInput = autorFacet.locator('input.akb-facet-search, [data-akb-facet-search]');
			await autorSearchInput.waitFor({ state: 'visible', timeout: 5000 });
			assert((await autorSearchInput.isVisible()), 'Buscador rápido de autor visible tras expandir');

			// Escribir "oda" en el buscador rápido
			await autorSearchInput.click();
			await autorSearchInput.fill('oda');
			await page.waitForTimeout(300);
			console.log('  ℹ Escrito "oda" en el buscador rápido de autor');

			// Seleccionar un checkbox de categoría (que está abierta) para disparar swap PJAX
			const catItem = page.locator('.akb-facet[data-facet="categoria"] .akb-facet-item input[type="checkbox"]').first();
			const catVal = await catItem.getAttribute('value');
			console.log(`  ℹ Marcando filtro de categoría: ${catVal}...`);

			const [response] = await Promise.all([
				page.waitForResponse(res => res.url().includes('_categoria=') && res.status() === 200),
				catItem.click()
			]);

			console.log(`  ℹ Respuesta PJAX recibida con HTTP ${response.status()}`);
			await page.waitForTimeout(800); // estabilizar snapshot restore

			// Verificar que el input de autor restauró "oda"
			const restoredAutorSearch = page.locator('.akb-facet[data-facet="autor"] input.akb-facet-search, .akb-facet[data-facet="autor"] [data-akb-facet-search]');
			const restoredVal = await restoredAutorSearch.inputValue();
			assert(restoredVal === 'oda', `Texto en buscador de autor preservado: esperado "oda", obtenido "${restoredVal}"`);

			// Verificar que el foco activo no se cayó a <body>
			const activeTag = await page.evaluate(() => document.activeElement ? `${document.activeElement.tagName}[${document.activeElement.className || ''}]` : 'none');
			console.log(`  ℹ Elemento con foco accesible tras swap: ${activeTag}`);
			assert(activeTag !== 'BODY[]', `El foco accesible NO se cayó a <body> (está en: ${activeTag})`);

			await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '02-local-facet-state-preserved.png'), fullPage: false });
		}

		// =========================================================================
		// PRUEBA 3: Caché LRU en Cliente y Hover Prefetch (Mejora A)
		// =========================================================================
		console.log('\n--- PRUEBA 3: Caché LRU en Cliente y Hover Prefetch ---');
		const requestsLogged = [];
		page.on('request', req => {
			if (req.url().includes('/tienda/') && (req.url().includes('_') || req.url().includes('orderby='))) {
				requestsLogged.push(req.url());
			}
		});

		// Hover sobre una opción de categoría visible
		const visibleItem = page.locator('.akb-facet[data-facet="categoria"] .akb-facet-item label').nth(1);
		await visibleItem.hover();
		await page.waitForTimeout(300); // 150ms prefetch trigger

		console.log(`  ℹ Peticiones registradas tras hover (>150ms): ${requestsLogged.length}`);
		assert(requestsLogged.length > 0, 'Speculative hover prefetch disparó petición HTTP de precarga');

		// =========================================================================
		// PRUEBA 4: Orden por Precio Efectivo (Mejora C)
		// =========================================================================
		console.log('\n--- PRUEBA 4: Ordenamiento por Precio Efectivo (Mejora C) ---');
		await page.goto('http://localhost:8080/tienda/?orderby=price', { waitUntil: 'networkidle', timeout: 30000 });

		const productsAsc = await page.$$eval('ul.products li.product', cards => {
			return cards.map(c => {
				const title = c.querySelector('.woocommerce-loop-product__title')?.innerText?.trim() || '';
				const insAmount = c.querySelector('.price ins .amount')?.innerText;
				const directAmount = c.querySelector('.price .amount')?.innerText;
				const raw = insAmount || directAmount || '';
				return { title, priceText: raw, price: parseInt(raw.replace(/\D/g, ''), 10) };
			});
		});

		console.log(`  ℹ Encontrados ${productsAsc.length} productos en catálogo (orderby=price ASC)`);
		assert(productsAsc.length > 0, 'Hay productos renderizados en la tienda');

		const prices = productsAsc.slice(0, 16).map(p => p.price).filter(p => !isNaN(p));
		console.log(`  ℹ Muestra de precios efectivos ASC: ${prices.slice(0, 10).join(', ')}`);
		console.log(`  ℹ Detalle primeros 3: ${productsAsc.slice(0, 3).map(p => `${p.title} (${p.priceText})`).join(' | ')}`);

		let isAscending = true;
		for (let i = 1; i < prices.length; i++) {
			if (prices[i] < prices[i - 1]) {
				isAscending = false;
				console.error(`  ✗ Discrepancia: ${productsAsc[i].title} ($${prices[i]}) < ${productsAsc[i-1].title} ($${prices[i-1]})`);
				break;
			}
		}
		assert(isAscending, 'Los productos están ordenados ascendentemente por precio efectivo');
		await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '03-local-price-asc.png'), fullPage: false });

		// Probar DESC
		await page.goto('http://localhost:8080/tienda/?orderby=price-desc', { waitUntil: 'networkidle', timeout: 30000 });
		const productsDesc = await page.$$eval('ul.products li.product', cards => {
			return cards.map(c => {
				const title = c.querySelector('.woocommerce-loop-product__title')?.innerText?.trim() || '';
				const insAmount = c.querySelector('.price ins .amount')?.innerText;
				const directAmount = c.querySelector('.price .amount')?.innerText;
				const raw = insAmount || directAmount || '';
				return { title, priceText: raw, price: parseInt(raw.replace(/\D/g, ''), 10) };
			});
		});

		const descPrices = productsDesc.slice(0, 16).map(p => p.price).filter(p => !isNaN(p));
		console.log(`  ℹ Muestra de precios efectivos DESC: ${descPrices.slice(0, 10).join(', ')}`);

		let isDescending = true;
		for (let i = 1; i < descPrices.length; i++) {
			if (descPrices[i] > descPrices[i - 1]) {
				isDescending = false;
				console.error(`  ✗ Discrepancia: ${productsDesc[i].title} ($${descPrices[i]}) > ${productsDesc[i-1].title} ($${descPrices[i-1]})`);
				break;
			}
		}
		assert(isDescending, 'Los productos están ordenados descendentemente por precio efectivo');
		await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '04-local-price-desc.png'), fullPage: false });

	} catch (err) {
		console.error('Error durante la ejecución:', err);
		failures++;
	} finally {
		await browser.close();
		console.log('\n--- RESUMEN FINAL DE PRUEBAS LOCALES ---');
		if (failures === 0) {
			console.log('🎉 TODAS LAS PRUEBAS EN LOCAL PASARON CON ÉXITO (0 fallos).');
		} else {
			console.error(`❌ Se registraron ${failures} fallos en las pruebas.`);
			process.exit(1);
		}
	}
}

run();
