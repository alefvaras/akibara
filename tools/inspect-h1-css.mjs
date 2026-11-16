import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function check() {
	const browser = await playwright.chromium.launch({ executablePath: CHROME_PATH, headless: true });
	const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
	await page.goto('https://akibara.cl/ivrea/', { waitUntil: 'domcontentloaded' });
	const data = await page.evaluate(() => {
		const el = document.querySelector('h1.entry-title');
		const cs = getComputedStyle(el);
		return {
			letterSpacing: cs.letterSpacing,
			textIndent: cs.textIndent,
			fontFamily: cs.fontFamily,
			transform: cs.transform,
			display: cs.display,
			position: cs.position
		};
	});
	console.log('H1 CSS details:', JSON.stringify(data, null, 2));
	await browser.close();
}

check();
