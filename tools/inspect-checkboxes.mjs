import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function check() {
	const browser = await chromium.launch({ executablePath: CHROME_PATH, headless: true });
	const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
	await page.goto('https://akibara.cl/checkout/', { waitUntil: 'networkidle' });
	const checkboxes = await page.evaluate(() => {
		const cbs = Array.from(document.querySelectorAll('input[type="checkbox"]'));
		return cbs.map(c => {
			const label = c.closest('label') || document.querySelector(`label[for="${c.id}"]`);
			return {
				id: c.id,
				name: c.name,
				checked: c.checked,
				label: label ? label.textContent.trim() : null
			};
		});
	});
	console.log('Checkboxes in checkout:', JSON.stringify(checkboxes, null, 2));
	await browser.close();
}

check();
