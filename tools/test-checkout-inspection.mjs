import playwright from './akibara-e2e/node_modules/playwright/index.js';

const pw = playwright.default || playwright;
const { chromium } = pw;
const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE_URL = process.env.AKB_BASE_URL || 'https://akibara.cl';

async function main() {
	const browser = await chromium.launch({ headless: true, executablePath: CHROME_PATH });
	const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
	const page = await context.newPage();

	await page.goto(`${BASE_URL}/gachiakuta-10-ivrea-argentina/`, { waitUntil: 'domcontentloaded' });
	const addBtn = await page.$('button.single_add_to_cart_button, .cart button[type="submit"]');
	if (addBtn) {
		await addBtn.click();
		await page.waitForTimeout(2000);
	}

	await page.goto(`${BASE_URL}/finalizar-compra/`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(3000);

	const info = await page.evaluate(() => {
		const methods = Array.from(document.querySelectorAll('ul#shipping_method li, .wc-block-components-shipping-rates-control, .woocommerce-shipping-methods li')).map(li => ({
			html: li.innerHTML,
			text: li.innerText.trim()
		}));
		const tableText = document.querySelector('.woocommerce-checkout-review-order-table, .wc-block-checkout')?.innerText;
		return { methods, tableText };
	});

	console.log('METHODS FOUND:', JSON.stringify(info.methods, null, 2));
	console.log('TABLE / CHECKOUT TEXT:\n', info.tableText);

	await browser.close();
}

main().catch(err => {
	console.error(err);
	process.exit(1);
});
