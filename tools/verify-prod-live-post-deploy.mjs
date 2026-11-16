import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;
const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE_URL = 'https://akibara.cl';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/16292e4b-646c-4752-83e8-fd2278327e63/screenshots';

async function main() {
	const browser = await chromium.launch({ headless: true, executablePath: CHROME_PATH });
	const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
	const page = await context.newPage();

	// 1. MINI-CART SKELETON LOADER EN PROD
	console.log('[PROD TEST 1] Verifying Mini-Cart Skeleton Loader on live production...');
	await page.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1000);

	const skeletonInfo = await page.evaluate(() => {
		const loading = document.querySelector('.akb-cart-loading');
		const items = document.querySelectorAll('.akb-cart-skeleton-item');
		return {
			loadingExists: !!loading,
			itemsCount: items.length
		};
	});
	console.log('[PROD DATA] Skeleton DOM in live prod:', skeletonInfo);

	await page.evaluate(() => {
		const drawer = document.querySelector('.akb-cart-drawer');
		if (drawer) {
			drawer.classList.add('akb-open');
			drawer.style.visibility = 'visible';
			drawer.style.transform = 'translateX(0)';
		}
	});
	await page.waitForTimeout(800);
	await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'prod-minicart-skeleton-live.png') });
	console.log('[SAVED] prod-minicart-skeleton-live.png');

	// 2. CHECKOUT RETIRO SAN MIGUEL MICRO-COPY EN PROD
	console.log('[PROD TEST 2] Adding product to cart for checkout...');
	await page.goto(`${BASE_URL}/gachiakuta-10-ivrea-argentina/`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1000);
	const addBtn = await page.$('button.single_add_to_cart_button, .cart button[type="submit"]');
	if (addBtn) {
		await addBtn.click();
		await page.waitForTimeout(2000);
	}

	console.log('[PROD TEST 3] Navigating to /finalizar-compra/ ...');
	await page.goto(`${BASE_URL}/finalizar-compra/`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(3500);

	const checkoutInfo = await page.evaluate(() => {
		const radio = document.querySelector('input[value="local_pickup:70"]');
		const option = radio?.closest('.wc-block-components-radio-control__option');
		const promise = option?.querySelector('.akb-shipping-promise');
		return {
			radioExists: !!radio,
			promiseText: promise?.innerText?.trim() || null,
			optionFullText: option?.innerText?.trim() || null
		};
	});
	console.log('[PROD DATA] Checkout San Miguel in live prod:', checkoutInfo);

	await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'prod-checkout-san-miguel-coordinacion-live.png') });
	console.log('[SAVED] prod-checkout-san-miguel-coordinacion-live.png');

	await browser.close();
}

main().catch(err => {
	console.error(err);
	process.exit(1);
});
