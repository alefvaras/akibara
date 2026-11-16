import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;
const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE_URL = process.env.AKB_BASE_URL || 'https://akibara.cl';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/16292e4b-646c-4752-83e8-fd2278327e63/screenshots';

async function testProduct(page, url, label) {
	console.log(`[TEST] Checking PDP: ${label} (${url})...`);
	await page.goto(url, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1500);

	const navInfo = await page.evaluate(() => {
		const nav = document.querySelector('.akb-series-nav');
		if (!nav) return { exists: false };
		const prev = nav.querySelector('.akb-series-nav__link--prev');
		const next = nav.querySelector('.akb-series-nav__link--next');
		const series = nav.querySelector('.akb-series-nav__link--series');
		return {
			exists: true,
			prev: prev ? { href: prev.href, text: prev.innerText.trim().replace(/\s+/g, ' ') } : null,
			next: next ? { href: next.href, text: next.innerText.trim().replace(/\s+/g, ' ') } : null,
			series: series ? { href: series.href, text: series.innerText.trim().replace(/\s+/g, ' ') } : null
		};
	});

	console.log(`[DATA] ${label}:`, JSON.stringify(navInfo, null, 2));
	const shotPath = path.join(SCREENSHOT_DIR, `pdp-nav-${label}.png`);
	await page.screenshot({ path: shotPath });
	console.log(`[SAVED] pdp-nav-${label}.png`);
	return navInfo;
}

async function main() {
	const browser = await chromium.launch({ headless: true, executablePath: CHROME_PATH });
	const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

	// 1. Middle volume: Gachiakuta 10
	await testProduct(page, `${BASE_URL}/gachiakuta-10-ivrea-argentina/`, 'middle-volume-gachiakuta-10');

	// 2. First volume: Gachiakuta 1
	await testProduct(page, `${BASE_URL}/gachiakuta-01-ivrea-argentina/`, 'first-volume-gachiakuta-01');

	// 3. Final volume: Prison School 28
	await testProduct(page, `${BASE_URL}/prison-school-28-ivrea-espana/`, 'final-volume-prison-school-28');

	await browser.close();
}

main().catch(err => {
	console.error(err);
	process.exit(1);
});
