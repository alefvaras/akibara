import playwright from './akibara-e2e/node_modules/playwright/index.js';

const pw = playwright.default || playwright;
const { chromium } = pw;
const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE_URL = process.env.AKB_BASE_URL || 'https://akibara.cl';

async function main() {
	const browser = await chromium.launch({ headless: true, executablePath: CHROME_PATH });
	const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

	await page.goto(`${BASE_URL}/gachiakuta-10-ivrea-argentina/`);
	await page.click('button.single_add_to_cart_button');
	await page.waitForTimeout(1500);

	await page.goto(`${BASE_URL}/finalizar-compra/`);
	await page.waitForTimeout(3000);

	const info = await page.evaluate(() => {
		const radios = Array.from(document.querySelectorAll('input[type="radio"]')).map(r => ({
			name: r.name,
			value: r.value,
			id: r.id,
			checked: r.checked,
			parentText: r.closest('.wc-block-components-radio-control__option')?.innerText || r.parentElement?.innerText
		}));

		// Also check shipping packages or delivery section
		const deliverySection = document.querySelector('.wc-block-checkout__shipping-option, .wc-block-components-shipping-rates-control, [data-block-name*="shipping"]')?.outerHTML;

		return { radios, deliverySection: deliverySection?.slice(0, 2000) };
	});

	console.log('RADIOS:', JSON.stringify(info.radios, null, 2));
	console.log('DELIVERY SECTION SNIPPET:\n', info.deliverySection);

	await browser.close();
}

main().catch(err => {
	console.error(err);
	process.exit(1);
});
