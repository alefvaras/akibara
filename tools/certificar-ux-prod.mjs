import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/prod-ux-fix';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

async function run() {
	console.log('--- CERTIFICANDO FIX VISUAL EN PRODUCCIÓN VIVA (https://akibara.cl) ---');
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	try {
		// 1. DESKTOP 1366px
		console.log('\n[1/2] Capturando A Silent Voice en Desktop (1366px)...');
		const deskCtx = await browser.newContext({
			viewport: { width: 1366, height: 768 },
			userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 AkibaraQA/UXFix'
		});
		const deskPage = await deskCtx.newPage();
		await deskPage.goto('https://akibara.cl/serie/a-silent-voice/#completar-coleccion', { waitUntil: 'networkidle' });
		await deskPage.waitForTimeout(1500);

		// Abrir panel de selección si no está expandido
		await deskPage.evaluate(() => {
			const startBtn = document.querySelector('[data-akb-sb-landing-start]');
			if (startBtn && startBtn.getAttribute('aria-expanded') !== 'true') {
				startBtn.click();
			}
		});
		await deskPage.waitForTimeout(800);

		// Obtener medidas reales en el DOM de producción
		const deskMetrics = await deskPage.evaluate(() => {
			const cards = document.querySelectorAll('ul.products.akb-sb-selection-grid li.product');
			const c1 = cards[0];
			const c2 = cards[1];
			const img1 = c1 ? c1.querySelector('.akb-product-cover-wrap img') : null;
			const badge1 = c1 ? c1.querySelector('.akb-badge') : null;
			const badge2 = c2 ? c2.querySelector('.akb-badge') : null;
			return {
				totalCards: cards.length,
				img1Width: img1 ? img1.getBoundingClientRect().width : null,
				img1Height: img1 ? img1.getBoundingClientRect().height : null,
				badge1Text: badge1 ? badge1.textContent.trim() : null,
				badge1Width: badge1 ? badge1.getBoundingClientRect().width : null,
				badge2Text: badge2 ? badge2.textContent.trim() : null,
				badge2Width: badge2 ? badge2.getBoundingClientRect().width : null
			};
		});
		console.log('Métricas Desktop en producción:', deskMetrics);

		const deskCards = deskPage.locator('ul.products.akb-sb-selection-grid');
		await deskCards.scrollIntoViewIfNeeded();
		await deskPage.screenshot({ path: path.join(SCREENSHOT_DIR, '01-prod-desktop-silent-voice-fix.png') });
		console.log('✓ Captura 01 guardada: 01-prod-desktop-silent-voice-fix.png');

		// 2. MOBILE 390px (iPhone 14)
		console.log('\n[2/2] Capturando A Silent Voice en Mobile (390px)...');
		const mobCtx = await browser.newContext({
			viewport: { width: 390, height: 844 },
			isMobile: true,
			hasTouch: true,
			userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1'
		});
		const mobPage = await mobCtx.newPage();
		await mobPage.goto('https://akibara.cl/serie/a-silent-voice/#completar-coleccion', { waitUntil: 'networkidle' });
		await mobPage.waitForTimeout(1500);

		await mobPage.evaluate(() => {
			const startBtn = document.querySelector('[data-akb-sb-landing-start]');
			if (startBtn && startBtn.getAttribute('aria-expanded') !== 'true') {
				startBtn.click();
			}
		});
		await mobPage.waitForTimeout(800);

		const mobMetrics = await mobPage.evaluate(() => {
			const cards = document.querySelectorAll('ul.products.akb-sb-selection-grid li.product');
			const c1 = cards[0];
			const img1 = c1 ? c1.querySelector('.akb-product-cover-wrap img') : null;
			const badge1 = c1 ? c1.querySelector('.akb-badge') : null;
			return {
				img1Width: img1 ? img1.getBoundingClientRect().width : null,
				img1Height: img1 ? img1.getBoundingClientRect().height : null,
				badge1Text: badge1 ? badge1.textContent.trim() : null,
				badge1Width: badge1 ? badge1.getBoundingClientRect().width : null
			};
		});
		console.log('Métricas Mobile en producción:', mobMetrics);

		const mobCards = mobPage.locator('ul.products.akb-sb-selection-grid');
		await mobCards.scrollIntoViewIfNeeded();
		await mobPage.screenshot({ path: path.join(SCREENSHOT_DIR, '02-prod-mobile-silent-voice-fix.png') });
		console.log('✓ Captura 02 guardada: 02-prod-mobile-silent-voice-fix.png');

	} catch (err) {
		console.error('Error durante la certificación:', err);
	} finally {
		await browser.close();
		console.log('--- CERTIFICACIÓN PRODUCCIÓN FINALIZADA ---');
	}
}

run();
