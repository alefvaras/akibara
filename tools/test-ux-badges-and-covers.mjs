import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/ux-badges';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

async function run() {
	console.log('--- Verificando Badges A pedido y Agotado en Local ---');
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	try {
		// 1. Desktop 1366px
		const deskCtx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
		const deskPage = await deskCtx.newPage();
		await deskPage.goto('http://localhost:8080/serie/beastars/#completar-coleccion', { waitUntil: 'networkidle' });
		await deskPage.waitForTimeout(1000);

		// Abrir panel
		await deskPage.evaluate(() => {
			const startBtn = document.querySelector('[data-akb-sb-landing-start]');
			if (startBtn && startBtn.getAttribute('aria-expanded') !== 'true') startBtn.click();
		});
		await deskPage.waitForTimeout(500);

		// Inyectar badge A pedido en la card 1 y Agotado en la card 2
		await deskPage.evaluate(() => {
			const cards = document.querySelectorAll('ul.products.akb-sb-selection-grid li.product');
			if (cards[0]) {
				const wrap = cards[0].querySelector('.akb-product-cover-wrap');
				if (wrap && !wrap.querySelector('.akb-badge')) {
					const b = document.createElement('span');
					b.className = 'akb-badge akb-badge--in_stock';
					b.textContent = 'Disponible';
					wrap.appendChild(b);
				}
			}
			if (cards[1]) {
				const wrap = cards[1].querySelector('.akb-product-cover-wrap');
				if (wrap && !wrap.querySelector('.akb-badge')) {
					const b = document.createElement('span');
					b.className = 'akb-badge akb-badge--out_of_stock';
					b.textContent = 'Agotado';
					wrap.appendChild(b);
				}
			}
		});
		await deskPage.waitForTimeout(300);

		const cardsArea = deskPage.locator('ul.products.akb-sb-selection-grid');
		await deskPage.screenshot({ path: path.join(SCREENSHOT_DIR, '03-local-desktop-with-badges.png') });
		console.log('✓ Captura desktop con badges guardada.');

		// 2. Mobile 390px
		const mobCtx = await browser.newContext({
			viewport: { width: 390, height: 844 },
			isMobile: true,
			hasTouch: true
		});
		const mobPage = await mobCtx.newPage();
		await mobPage.goto('http://localhost:8080/serie/beastars/#completar-coleccion', { waitUntil: 'networkidle' });
		await mobPage.waitForTimeout(1000);

		await mobPage.evaluate(() => {
			const startBtn = document.querySelector('[data-akb-sb-landing-start]');
			if (startBtn && startBtn.getAttribute('aria-expanded') !== 'true') startBtn.click();
		});
		await mobPage.waitForTimeout(500);

		await mobPage.evaluate(() => {
			const cards = document.querySelectorAll('ul.products.akb-sb-selection-grid li.product');
			if (cards[0]) {
				const wrap = cards[0].querySelector('.akb-product-cover-wrap');
				if (wrap && !wrap.querySelector('.akb-badge')) {
					const b = document.createElement('span');
					b.className = 'akb-badge akb-badge--in_stock';
					b.textContent = 'Disponible';
					wrap.appendChild(b);
				}
			}
			if (cards[1]) {
				const wrap = cards[1].querySelector('.akb-product-cover-wrap');
				if (wrap && !wrap.querySelector('.akb-badge')) {
					const b = document.createElement('span');
					b.className = 'akb-badge akb-badge--out_of_stock';
					b.textContent = 'Agotado';
					wrap.appendChild(b);
				}
			}
		});
		await mobPage.waitForTimeout(300);

		await mobPage.screenshot({ path: path.join(SCREENSHOT_DIR, '04-local-mobile-with-badges.png') });
		console.log('✓ Captura mobile con badges guardada.');

	} catch (e) {
		console.error('Error:', e);
	} finally {
		await browser.close();
		console.log('--- Fin ---');
	}
}

run();
