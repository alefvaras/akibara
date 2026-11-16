import playwright from './akibara-e2e/node_modules/playwright/index.js';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

(async () => {
	const browser = await chromium.launch({ executablePath: CHROME_PATH, headless: true });

	// 1. Check Search Modal (predictive state)
	const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
	await page.goto('https://akibara.cl/');
	const searchBtn = await page.$('.akb-search-trigger, [data-akb-search-trigger], button[aria-label*="Buscar"]');
	if (searchBtn) {
		await searchBtn.click();
		await page.waitForTimeout(600);
		const input = await page.$('input[type="search"], .akb-search-modal input, #akb-search-input');
		if (input) {
			await input.fill('One Piece');
			await page.waitForTimeout(1000);
		}
	}
	const searchResults = await page.evaluate(() => {
		const items = document.querySelectorAll('.akb-search-results__item, .akb-suggest__item, .akb-search-result, [data-akb-suggest-item], .akb-suggest-item');
		return Array.from(items).slice(0, 5).map(el => el.innerText.trim().replace(/\s+/g, ' '));
	});
	console.log('Search Suggestions:', JSON.stringify(searchResults));

	// 2. Check /serie/gachiakuta/ rules block
	await page.goto('https://akibara.cl/serie/gachiakuta/');
	const seriesRules = await page.evaluate(() => {
		const r = document.querySelector('.akb-sb-builder__rules, .akb-series-bundle__rules, .akb-series-header__rules, [class*="rules"]');
		return r ? { tag: r.tagName, class: r.className, text: r.innerText.trim().slice(0, 150) } : null;
	});
	console.log('Series Rules Block:', JSON.stringify(seriesRules));

	// 3. Check /manga/ active filter pills
	await page.goto('https://akibara.cl/manga/');
	const filterPills = await page.evaluate(() => {
		const pills = document.querySelectorAll('.akb-filter-pills, .akb-active-filters, .woocommerce-active-filters, [class*="active-filter"], .akb-chip');
		return Array.from(pills).map(p => p.innerText.trim());
	});
	console.log('Catalog Filter Pills:', JSON.stringify(filterPills));

	// 4. Check PDP Gachiakuta 10 ETA and pack builder
	await page.goto('https://akibara.cl/gachiakuta-10-ivrea-argentina/');
	const pdpInfo = await page.evaluate(() => {
		const eta = document.querySelector('.akb-eta');
		const pack = document.querySelector('.akb-sb-builder');
		const rules = document.querySelector('.akb-sb-builder__rules');
		const title = document.querySelector('.product_title');
		return {
			title: title ? title.innerText.trim() : null,
			eta: eta ? eta.innerText.trim().replace(/\s+/g, ' ') : null,
			packExists: !!pack,
			rulesTag: rules ? rules.tagName : null,
			rulesOpen: rules ? rules.open : null
		};
	});
	console.log('PDP Gachiakuta 10 Info:', JSON.stringify(pdpInfo));

	await browser.close();
})();
