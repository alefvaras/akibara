import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function check() {
	const browser = await chromium.launch({ executablePath: CHROME_PATH, headless: true });
	const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
	await page.goto('https://akibara.cl/ivrea/', { waitUntil: 'domcontentloaded' });
	const data = await page.evaluate(() => {
		const el = document.querySelector('h1.entry-title');
		let cur = el;
		const chain = [];
		while (cur && cur.tagName !== 'BODY') {
			const cs = getComputedStyle(cur);
			chain.push({
				tag: cur.tagName,
				id: cur.id,
				cls: cur.className,
				rect: cur.getBoundingClientRect(),
				padding: cs.padding,
				margin: cs.margin,
				maxWidth: cs.maxWidth
			});
			cur = cur.parentElement;
		}
		return chain;
	});
	console.log('Hierarchy of h1 on /ivrea/:', JSON.stringify(data, null, 2));
	await browser.close();
}

check();
