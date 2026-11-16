import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/prod-finetuning';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

async function run() {
	console.log('--- INICIANDO CERTIFICACIÓN VISUAL EN PRODUCCIÓN VIVA (https://akibara.cl) ---');
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	try {
		const context = await browser.newContext({
			viewport: { width: 1366, height: 768 },
			userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 AkibaraQA/FineTuning'
		});
		const page = await context.newPage();

		// 1. A Silent Voice
		console.log('\n[1/6] Capturando A Silent Voice (6 tomos)...');
		await page.goto('https://akibara.cl/serie/a-silent-voice/#completar-coleccion', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1000);
		const asvInfo = await page.evaluate(() => {
			const landing = document.querySelector('[data-akb-sb-landing]');
			return landing ? landing.getAttribute('data-tiers') : null;
		});
		console.log('A Silent Voice tiers:', asvInfo);
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01-prod-a-silent-voice-max-tier-8.png') });

		// 2. Dai Dark
		console.log('\n[2/6] Capturando Dai Dark (6 tomos)...');
		await page.goto('https://akibara.cl/serie/dai-dark/#completar-coleccion', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1000);
		const ddInfo = await page.evaluate(() => {
			const landing = document.querySelector('[data-akb-sb-landing]');
			return landing ? landing.getAttribute('data-tiers') : null;
		});
		console.log('Dai Dark tiers:', ddInfo);
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '02-prod-dai-dark-max-tier-8.png') });

		// 3. The Walking Dead
		console.log('\n[3/6] Capturando The Walking Dead (tope 8%)...');
		await page.goto('https://akibara.cl/serie/the-walking-dead/#completar-coleccion', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1000);
		const twdInfo = await page.evaluate(() => {
			const landing = document.querySelector('[data-akb-sb-landing]');
			return landing ? landing.getAttribute('data-tiers') : null;
		});
		console.log('The Walking Dead tiers:', twdInfo);
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '03-prod-the-walking-dead-deluxe-8.png') });

		// 4. Bakemonogatari (5 tomos a pedido, ahorro transparente)
		console.log('\n[4/6] Capturando Bakemonogatari (5 tomos con desglose)...');
		await page.goto('https://akibara.cl/serie/bakemonogatari/#completar-coleccion', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1000);

		const bakemonoSummary = await page.evaluate(() => {
			const checks = Array.from(document.querySelectorAll('[data-akb-sb-landing-vol]:not(:disabled)')).slice(0, 5);
			for (const chk of checks) {
				if (!chk.checked) chk.click();
			}
			const countEl = document.querySelector('[data-akb-sb-landing-count]');
			const totalEl = document.querySelector('[data-akb-sb-landing-total]');
			const discEl = document.querySelector('[data-akb-sb-landing-discount]');
			return {
				count: countEl ? countEl.textContent : '',
				total: totalEl ? totalEl.textContent : '',
				discount: discEl ? discEl.textContent : ''
			};
		});
		console.log('Bakemonogatari desglose:', bakemonoSummary);
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '04-prod-bakemonogatari-ahorro-transparente.png') });

		// 5. Drawer Solo A Pedido
		console.log('\n[5/6] Capturando Drawer con solo A Pedido...');
		const addBtn = page.locator('[data-akb-sb-landing-add]');
		await addBtn.click();
		await page.waitForTimeout(2000);

		// Abrir drawer
		await page.evaluate(() => {
			const trigger = document.querySelector('.akb-cart-trigger');
			if (trigger) trigger.click();
		});
		await page.waitForTimeout(1500);
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '05-prod-cart-drawer-solo-a-pedido.png') });

		// 6. Carrito Mixto (Instock + A Pedido)
		console.log('\n[6/6] Capturando Drawer Mixto con Candado Logístico...');
		await page.goto('https://akibara.cl/tienda/?filter_stock=instock', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1500);

		await page.evaluate(() => {
			const firstAdd = document.querySelector('a.ajax_add_to_cart, button.ajax_add_to_cart, .add_to_cart_button');
			if (firstAdd) firstAdd.click();
		});
		await page.waitForTimeout(2500);

		await page.evaluate(() => {
			const trigger = document.querySelector('.akb-cart-trigger');
			if (trigger) trigger.click();
		});
		await page.waitForTimeout(1500);
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '06-prod-cart-drawer-mixto-candado.png') });

		console.log('✓ Todas las capturas generadas con éxito en:', SCREENSHOT_DIR);

	} catch (e) {
		console.error('Error durante la ejecución:', e);
	} finally {
		await browser.close();
		console.log('--- FINALIZADO ---');
	}
}

run();
