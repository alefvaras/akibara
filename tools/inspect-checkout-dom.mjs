import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function check() {
	const browser = await chromium.launch({ executablePath: CHROME_PATH, headless: true });
	const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
	await page.goto('https://akibara.cl/checkout/', { waitUntil: 'networkidle' });
	const text = await page.evaluate(() => {
		const emptyNotice = document.querySelector('.wc-block-checkout-empty, .wc-empty-cart-message, [class*="empty"]');
		const inputs = Array.from(document.querySelectorAll('input')).map(i => ({ id: i.id, type: i.type, name: i.name }));
		return {
			url: window.location.href,
			emptyNotice: emptyNotice ? emptyNotice.textContent.trim() : null,
			bodyText: document.body.innerText.slice(0, 300),
			inputs
		};
	});
	console.log('Checkout status:', JSON.stringify(text, null, 2));
	await browser.close();
}

check();
