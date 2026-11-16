import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;
const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE_URL = 'http://localhost:8080';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/16292e4b-646c-4752-83e8-fd2278327e63/screenshots';

async function main() {
	const browser = await chromium.launch({ headless: true, executablePath: CHROME_PATH });
	const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

	console.log('[STEP 1] Navigating to product in local Docker...');
	await page.goto(`${BASE_URL}/shop/`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1000);

	const firstProduct = await page.$('.products .product a.woocommerce-LoopProduct-link');
	if (firstProduct) {
		await firstProduct.click();
		await page.waitForTimeout(1200);
	}

	console.log('[STEP 2] Adding to cart...');
	const addBtn = await page.$('button.single_add_to_cart_button, .cart button[type="submit"]');
	if (addBtn) {
		await addBtn.click();
		await page.waitForTimeout(2000);
	}

	console.log('[STEP 3] Opening /finalizar-compra/ ...');
	await page.goto(`${BASE_URL}/finalizar-compra/`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(3000);

	const pickupInfo = await page.evaluate(() => {
		const radios = Array.from(document.querySelectorAll('input[type="radio"]')).map(r => ({
			id: r.id,
			value: r.value,
			checked: r.checked,
			parentText: r.closest('.wc-block-components-radio-control__option')?.innerText || r.parentElement?.innerText,
			promiseText: r.closest('.wc-block-components-radio-control__option')?.querySelector('.akb-shipping-promise')?.innerText || null
		}));
		const rateNote = document.querySelector('.akb-pickup-promise-label, .akb-shipping-promise')?.innerText;
		return { radios, rateNote };
	});

	console.log('[DATA] Pickup info:', JSON.stringify(pickupInfo, null, 2));

	const shotPath = path.join(SCREENSHOT_DIR, 'local-checkout-san-miguel-coordinacion.png');
	await page.screenshot({ path: shotPath });
	console.log('[SAVED] local-checkout-san-miguel-coordinacion.png');

	await browser.close();
}

main().catch(err => {
	console.error(err);
	process.exit(1);
});
