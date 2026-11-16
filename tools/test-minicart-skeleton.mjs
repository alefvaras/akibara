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

	console.log('[STEP 1] Navigating to local home...');
	await page.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1000);

	console.log('[STEP 2] Inspecting initial cart drawer skeleton DOM...');
	const skeletonInfo = await page.evaluate(() => {
		const loading = document.querySelector('.akb-cart-loading');
		const items = document.querySelectorAll('.akb-cart-skeleton-item');
		const thumbs = document.querySelectorAll('.akb-cart-skeleton-thumb');
		const lines = document.querySelectorAll('.akb-cart-skeleton-line');
		return {
			loadingExists: !!loading,
			itemsCount: items.length,
			thumbsCount: thumbs.length,
			linesCount: lines.length,
			loadingHtml: loading ? loading.outerHTML : null
		};
	});
	console.log('[DATA] Skeleton DOM:', JSON.stringify(skeletonInfo, null, 2));

	console.log('[STEP 3] Opening drawer to trigger .akb-open skeleton view...');
	await page.evaluate(() => {
		const drawer = document.querySelector('.akb-cart-drawer');
		if (drawer) {
			drawer.classList.add('akb-open');
			drawer.style.visibility = 'visible';
			drawer.style.transform = 'translateX(0)';
		}
	});
	await page.waitForTimeout(600);

	const shotPath = path.join(SCREENSHOT_DIR, 'local-minicart-skeleton-loader.png');
	await page.screenshot({ path: shotPath });
	console.log('[SAVED] local-minicart-skeleton-loader.png');

	await browser.close();
}

main().catch(err => {
	console.error(err);
	process.exit(1);
});
