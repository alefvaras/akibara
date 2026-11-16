import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOTS_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/search-improvements-prod';

fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });

async function run() {
	console.log('Iniciando prueba visual de Search en Producción (https://akibara.cl/)...');

	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const context = await browser.newContext({
		viewport: { width: 1400, height: 900 }
	});

	const page = await context.newPage();

	try {
		await page.goto('https://akibara.cl/', { waitUntil: 'networkidle', timeout: 30000 });

		console.log('Buscando campo de búsqueda...');
		const searchInput = page.locator('input[type="search"]').first();
		await searchInput.waitFor({ state: 'visible', timeout: 10000 });

		await searchInput.click();
		await searchInput.fill('chainsaw 20');

		console.log('Esperando dropdown de sugerencias...');
		const panel = page.locator('#akb-suggest-panel, .akb-suggest-panel').first();
		await panel.waitFor({ state: 'visible', timeout: 10000 });
		await page.waitForTimeout(1000); // estabilizar rendering

		await page.screenshot({
			path: path.join(SCREENSHOTS_DIR, '01-prod-search-dropdown-dual-sections.png')
		});
		console.log('Screenshot 01 guardada: dropdown dual sections (Chainsaw Man 20 con badge a pedido y Reservar)');

		console.log('Buscando tomo sin stock o banner para abrir modal...');
		const triggerBtn = page.locator('.akb-suggest-request-trigger, .akb-suggest-request-btn').first();
		if (await triggerBtn.isVisible()) {
			await triggerBtn.click();
			await page.waitForTimeout(600);

			const modal = page.locator('#akb-request-modal');
			await modal.waitFor({ state: 'visible', timeout: 5000 });

			await page.screenshot({
				path: path.join(SCREENSHOTS_DIR, '02-prod-search-modal-request.png')
			});
			console.log('Screenshot 02 guardada: modal de solicitud rápida');
		} else {
			console.log('Trigger no visible, probando búsqueda vacía inuyasha...');
			await searchInput.fill('inuyasha kanzenban');
			await page.waitForTimeout(1000);
			const emptyBtn = page.locator('.akb-suggest-request-btn').first();
			if (await emptyBtn.isVisible()) {
				await emptyBtn.click();
				await page.waitForTimeout(600);
				await page.screenshot({
					path: path.join(SCREENSHOTS_DIR, '02-prod-search-modal-request.png')
				});
				console.log('Screenshot 02 guardada vía empty state');
			}
		}

	} catch (err) {
		console.error('Error durante la prueba:', err);
	} finally {
		await browser.close();
		console.log('Navegador cerrado.');
	}
}

run();
