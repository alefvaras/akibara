import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/prod-finetuning';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

async function run() {
	console.log('Capturando flujo paso a paso en producción viva...');
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	try {
		const context = await browser.newContext({
			viewport: { width: 1366, height: 768 },
			userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 AkibaraQA/StepByStep'
		});
		const page = await context.newPage();

		// PASO 1: A Silent Voice (6 tomos en catálogo) con tope único de 8%
		console.log('Paso 1: A Silent Voice...');
		await page.goto('https://akibara.cl/serie/a-silent-voice/#completar-coleccion', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1000);
		// Scroll hacia el título de completar colección
		const landingHead = page.locator('.akb-sb-landing__head');
		await landingHead.scrollIntoViewIfNeeded();
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'paso-1-a-silent-voice-tope-8.png') });
		console.log('✓ Paso 1 guardado.');

		// PASO 2: The Walking Dead (Omnibus / Deluxe) con tope único de 8%
		console.log('Paso 2: The Walking Dead...');
		await page.goto('https://akibara.cl/serie/the-walking-dead/#completar-coleccion', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1000);
		const twdHead = page.locator('.akb-sb-landing__head');
		await twdHead.scrollIntoViewIfNeeded();
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'paso-2-the-walking-dead-tope-8.png') });
		console.log('✓ Paso 2 guardado.');

		// PASO 3: Bakemonogatari - Selección de 5 tomos a pedido con desglose honesto de ahorro
		console.log('Paso 3: Bakemonogatari con 5 tomos...');
		await page.goto('https://akibara.cl/serie/bakemonogatari/#completar-coleccion', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1500);

		// Seleccionar 5 tomos
		await page.evaluate(() => {
			const checks = Array.from(document.querySelectorAll('[data-akb-sb-landing-vol]:not(:disabled)')).slice(0, 5);
			for (const chk of checks) {
				if (!chk.checked) chk.click();
			}
		});
		await page.waitForTimeout(1000);
		// Hacer scroll al panel de resumen
		const summary = page.locator('[data-akb-sb-landing-summary]');
		await summary.scrollIntoViewIfNeeded();
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'paso-3-bakemonogatari-ahorro-transparente.png') });
		console.log('✓ Paso 3 guardado.');

		// PASO 4: Drawer del Carrito SOLO A PEDIDO (Abre el drawer y muestra el total y aviso de tanda SIN aviso de mezcla)
		console.log('Paso 4: Carrito Solo A Pedido en Drawer...');
		const addBtn = page.locator('[data-akb-sb-landing-add]');
		await addBtn.click();
		await page.waitForTimeout(2500);

		// Forzar apertura del drawer si no se abrió automáticamente
		await page.evaluate(() => {
			const drawer = document.querySelector('#akb-native-cart-drawer');
			const overlay = document.querySelector('.akb-cart-overlay');
			if (drawer && overlay) {
				drawer.classList.add('akb-open');
				overlay.classList.add('akb-open');
				drawer.setAttribute('aria-hidden', 'false');
			}
		});
		await page.waitForTimeout(1000);
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'paso-4-drawer-solo-a-pedido.png') });
		console.log('✓ Paso 4 guardado.');

		// PASO 5: Carrito Mixto (Sumamos un tomo instock físico para activar el Candado Logístico)
		console.log('Paso 5: Carrito Mixto con Candado Logístico...');
		await page.goto('https://akibara.cl/tienda/?filter_stock=instock', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1500);

		// Agregar primer tomo instock
		await page.evaluate(() => {
			const btn = document.querySelector('a.ajax_add_to_cart, .add_to_cart_button');
			if (btn) btn.click();
		});
		await page.waitForTimeout(2500);

		// Abrir drawer
		await page.evaluate(() => {
			const drawer = document.querySelector('#akb-native-cart-drawer');
			const overlay = document.querySelector('.akb-cart-overlay');
			if (drawer && overlay) {
				drawer.classList.add('akb-open');
				overlay.classList.add('akb-open');
				drawer.setAttribute('aria-hidden', 'false');
			}
		});
		await page.waitForTimeout(1000);
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'paso-5-drawer-mixto-candado.png') });
		console.log('✓ Paso 5 guardado.');

	} catch (e) {
		console.error('Error durante la captura:', e);
	} finally {
		await browser.close();
		console.log('--- Proceso terminado ---');
	}
}

run();
