import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function check() {
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true
	});
	const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
	await page.goto('https://akibara.cl/ivrea/', { waitUntil: 'domcontentloaded' });
	const data = await page.evaluate(() => {
		const h1 = document.querySelector('h1');
		if (!h1) return null;
		const cs = getComputedStyle(h1);
		const parent = h1.parentElement;
		const parentCs = getComputedStyle(parent);
		return {
			h1Tag: h1.outerHTML.slice(0, 150),
			h1Text: h1.textContent.trim(),
			rect: h1.getBoundingClientRect(),
			margin: cs.margin,
			padding: cs.padding,
			color: cs.color,
			fontSize: cs.fontSize,
			parentTag: parent.outerHTML.slice(0, 150),
			parentRect: parent.getBoundingClientRect(),
			grandParent: parent.parentElement ? parent.parentElement.outerHTML.slice(0, 150) : null
		};
	});
	console.log('IVREA H1 DOM:', JSON.stringify(data, null, 2));
	await browser.close();
}

check();
