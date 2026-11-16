import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function check() {
	const browser = await chromium.launch({ executablePath: CHROME_PATH, headless: true });
	const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
	await page.goto('https://akibara.cl/ivrea/', { waitUntil: 'domcontentloaded' });
	const data = await page.evaluate(() => {
		const heroTitle = document.querySelector('.akb-ed-hero__title, .akb-ed-hero h1, .akb-ed-hero h2, [class*="hero"]');
		const allHeadings = Array.from(document.querySelectorAll('h1, h2, h3')).map(h => ({
			tag: h.tagName,
			cls: h.className,
			text: h.textContent.trim().slice(0, 50),
			rect: { left: Math.round(h.getBoundingClientRect().left), top: Math.round(h.getBoundingClientRect().top) }
		}));
		return { allHeadings, hero: heroTitle ? heroTitle.outerHTML.slice(0, 150) : null };
	});
	console.log('Headings on /ivrea/:', JSON.stringify(data, null, 2));
	await browser.close();
}

check();
