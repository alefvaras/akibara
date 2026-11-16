import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOTS_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/search-improvements-prod-final';
fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });

const PROD_URL = 'https://akibara.cl';

async function verifyProduction() {
	console.log('🚀 Iniciando verificación de Akibara Search en PRODUCCIÓN (https://akibara.cl)...');

	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const context = await browser.newContext({
		viewport: { width: 1440, height: 950 },
		userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'
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
		// -------------------------------------------------------------------------
		// 1. Faceta País e Inferencia en Producción (Mejora D)
		// -------------------------------------------------------------------------
		console.log('\n--- 1. Faceta País en Producción (akibara.cl/tienda/) ---');
		await page.goto(`${PROD_URL}/tienda/`, { waitUntil: 'networkidle', timeout: 30000 });

		const paisFacet = page.locator('.akb-facet[data-facet="pais"]');
		assert((await paisFacet.count()) > 0, 'La faceta País de Origen está visible en producción');

		if ((await paisFacet.count()) > 0) {
			const paisToggle = paisFacet.locator('[data-akb-facet-toggle]');
			if ((await paisToggle.getAttribute('aria-expanded')) !== 'true') {
				await paisToggle.click();
				await page.waitForTimeout(300);
			}

			const argBadge = await paisFacet.locator('.akb-facet-item:has(input[value="argentina"]) .akb-facet-count').innerText();
			const espBadge = await paisFacet.locator('.akb-facet-item:has(input[value="espana"]) .akb-facet-count').innerText();
			console.log(`  ℹ Conteos en Producción: Argentina=${argBadge} | España=${espBadge}`);

			const numArg = parseInt(argBadge.replace(/\D/g, ''), 10);
			const numEsp = parseInt(espBadge.replace(/\D/g, ''), 10);
			assert(numArg >= 1400, `Argentina tiene cobertura inferida completa (${numArg} >= 1.400)`);
			assert(numEsp >= 350, `España tiene cobertura inferida completa (${numEsp} >= 350)`);
		}

		await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '01-prod-pais-counts.png') });
		console.log('  📸 Screenshot guardada: 01-prod-pais-counts.png');

		// -------------------------------------------------------------------------
		// 2. Preservación de Estado de Facetas y Foco (Mejora A)
		// -------------------------------------------------------------------------
		console.log('\n--- 2. Preservación de Búsqueda Rápida en Autor en Producción ---');
		const autorFacet = page.locator('.akb-facet[data-facet="autor"]');
		if ((await autorFacet.count()) > 0) {
			const autorToggle = autorFacet.locator('[data-akb-facet-toggle]');
			if ((await autorToggle.getAttribute('aria-expanded')) !== 'true') {
				await autorToggle.click();
				await page.waitForTimeout(300);
			}

			const autorSearch = autorFacet.locator('input.akb-facet-search');
			await autorSearch.waitFor({ state: 'visible', timeout: 5000 });
			await autorSearch.click();
			await autorSearch.fill('oda');
			await page.waitForTimeout(300);
			console.log('  ℹ Escrito "oda" en el buscador de autor');

			// Seleccionar la opción de Eiichiro Oda
			const odaItem = autorFacet.locator('.akb-facet-item:has-text("Eiichiro Oda") input[type="checkbox"]').first();
			if ((await odaItem.count()) > 0) {
				console.log('  ℹ Marcando filtro de Eiichiro Oda...');
				await Promise.all([
					page.waitForResponse(res => res.url().includes('_autor=') && res.status() === 200),
					odaItem.click()
				]);
				await page.waitForTimeout(800);

				const restoredAutor = page.locator('.akb-facet[data-facet="autor"] input.akb-facet-search');
				const valAfter = await restoredAutor.inputValue();
				assert(valAfter === 'oda', `El texto "oda" se mantuvo en producción tras el swap (obtenido: "${valAfter}")`);

				const activeEl = await page.evaluate(() => document.activeElement ? `${document.activeElement.tagName}[${document.activeElement.className || ''}]` : 'none');
				console.log(`  ℹ Foco accesible en producción: ${activeEl}`);
				assert(activeEl !== 'BODY[]', 'El foco accesible no se cayó a <body>');
			}
		}

		await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '02-prod-facet-state-preserved.png') });
		console.log('  📸 Screenshot guardada: 02-prod-facet-state-preserved.png');

		// -------------------------------------------------------------------------
		// 3. Ordenamiento por Precio Efectivo ASC en Producción (Mejora C)
		// -------------------------------------------------------------------------
		console.log('\n--- 3. Ordenamiento por Precio Efectivo ASC (akibara.cl/tienda/?orderby=price) ---');
		await page.goto(`${PROD_URL}/tienda/?orderby=price`, { waitUntil: 'networkidle', timeout: 30000 });

		const productsAsc = await page.$$eval('ul.products li.product', cards => {
			return cards.map(c => {
				const title = c.querySelector('.woocommerce-loop-product__title')?.innerText?.trim() || '';
				const insAmount = c.querySelector('.price ins .amount')?.innerText;
				const directAmount = c.querySelector('.price .amount')?.innerText;
				const raw = insAmount || directAmount || '';
				return { title, priceText: raw, price: parseInt(raw.replace(/\D/g, ''), 10) };
			});
		});

		console.log(`  ℹ Total productos obtenidos en página 1 ASC: ${productsAsc.length}`);
		assert(productsAsc.length > 0, 'Hay productos renderizados en la tienda');

		const pricesAsc = productsAsc.slice(0, 16).map(p => p.price).filter(p => !isNaN(p));
		console.log(`  ℹ Precios efectivos ASC: ${pricesAsc.slice(0, 8).join(', ')}`);

		let isAsc = true;
		for (let i = 1; i < pricesAsc.length; i++) {
			if (pricesAsc[i] < pricesAsc[i - 1]) {
				isAsc = false;
				console.error(`  ✗ Discrepancia ASC: ${productsAsc[i].title} ($${pricesAsc[i]}) < ${productsAsc[i-1].title} ($${pricesAsc[i-1]})`);
				break;
			}
		}
		assert(isAsc, 'El catálogo en producción está ordenado ascendentemente por precio efectivo');

		await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '03-prod-price-asc.png') });
		console.log('  📸 Screenshot guardada: 03-prod-price-asc.png');

		// -------------------------------------------------------------------------
		// 4. Ordenamiento por Precio Efectivo DESC en Producción
		// -------------------------------------------------------------------------
		console.log('\n--- 4. Ordenamiento por Precio Efectivo DESC (akibara.cl/tienda/?orderby=price-desc) ---');
		await page.goto(`${PROD_URL}/tienda/?orderby=price-desc`, { waitUntil: 'networkidle', timeout: 30000 });

		const productsDesc = await page.$$eval('ul.products li.product', cards => {
			return cards.map(c => {
				const title = c.querySelector('.woocommerce-loop-product__title')?.innerText?.trim() || '';
				const insAmount = c.querySelector('.price ins .amount')?.innerText;
				const directAmount = c.querySelector('.price .amount')?.innerText;
				const raw = insAmount || directAmount || '';
				return { title, priceText: raw, price: parseInt(raw.replace(/\D/g, ''), 10) };
			});
		});

		const pricesDesc = productsDesc.slice(0, 16).map(p => p.price).filter(p => !isNaN(p));
		console.log(`  ℹ Precios efectivos DESC: ${pricesDesc.slice(0, 8).join(', ')}`);

		let isDesc = true;
		for (let i = 1; i < pricesDesc.length; i++) {
			if (pricesDesc[i] > pricesDesc[i - 1]) {
				isDesc = false;
				console.error(`  ✗ Discrepancia DESC: ${productsDesc[i].title} ($${pricesDesc[i]}) > ${productsDesc[i-1].title} ($${pricesDesc[i-1]})`);
				break;
			}
		}
		assert(isDesc, 'El catálogo en producción está ordenado descendentemente por precio efectivo');

		await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '04-prod-price-desc.png') });
		console.log('  📸 Screenshot guardada: 04-prod-price-desc.png');

	} catch (err) {
		console.error('Error fatal durante verificación en producción:', err);
		failures++;
	} finally {
		await browser.close();
		console.log('\n======================================================');
		if (failures === 0) {
			console.log('🎉 VERIFICACIÓN EN PRODUCCIÓN: 100% OK (0 fallos).');
		} else {
			console.error(`❌ Se detectaron ${failures} fallos en producción.`);
			process.exit(1);
		}
	}
}

verifyProduction();
