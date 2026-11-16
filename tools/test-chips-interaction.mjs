import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;
const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE_URL = process.env.AKB_BASE_URL || 'https://akibara.cl';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/16292e4b-646c-4752-83e8-fd2278327e63/screenshots';

async function main() {
	const browser = await chromium.launch({ headless: true, executablePath: CHROME_PATH });
	const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

	console.log('[TEST 1] Loading /manga/?_editorial=ivrea-argentina...');
	await page.goto(`${BASE_URL}/manga/?_editorial=ivrea-argentina`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1500);

	await page.screenshot({ path: path.join(SCREENSHOT_DIR, '07-a-catalog-chips-single-filter.png') });
	console.log('[SAVED] 07-a-catalog-chips-single-filter.png');

	const chipSelector = '.akb-chip[data-facet="editorial"]';
	const chipBtn = await page.$(chipSelector);
	console.log('Chip editorial button found:', !!chipBtn);

	if (chipBtn) {
		console.log('[TEST 2] Clicking chip to dismiss filter...');
		await chipBtn.click();
		await page.waitForTimeout(2000);
		console.log('URL after dismiss:', page.url());
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '07-b-catalog-chips-after-dismiss.png') });
		console.log('[SAVED] 07-b-catalog-chips-after-dismiss.png');
	}

	console.log('[TEST 3] Loading /manga/?_editorial=ivrea-argentina&_demografia=seinen...');
	await page.goto(`${BASE_URL}/manga/?_editorial=ivrea-argentina&_demografia=seinen`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1500);
	await page.screenshot({ path: path.join(SCREENSHOT_DIR, '07-c-catalog-chips-multiple-filters.png') });
	console.log('[SAVED] 07-c-catalog-chips-multiple-filters.png');

	const clearBtn = await page.$('.akb-chip--clear');
	console.log('Clear all button found:', !!clearBtn);
	if (clearBtn) {
		console.log('[TEST 4] Clicking "Limpiar todo"...');
		await clearBtn.click();
		await page.waitForTimeout(2000);
		console.log('URL after clear all:', page.url());
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '07-d-catalog-chips-after-clear-all.png') });
		console.log('[SAVED] 07-d-catalog-chips-after-clear-all.png');
	}

	await browser.close();
}

main().catch(err => {
	console.error(err);
	process.exit(1);
});
