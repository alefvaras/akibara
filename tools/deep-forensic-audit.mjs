import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function runAudit() {
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const context = await browser.newContext({
		viewport: { width: 1440, height: 900 },
		userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Akibara-QA-Deep'
	});

	const page = await context.newPage();
	const report = {};

	console.log('--- 1. AUDIT H1s ON LANDINGS ---');
	const h1Urls = [
		'https://akibara.cl/ivrea/',
		'https://akibara.cl/panini/',
		'https://akibara.cl/programa-referidos/',
		'https://akibara.cl/mi-cuenta/'
	];
	report.h1Check = {};
	for (const url of h1Urls) {
		await page.goto(url, { waitUntil: 'domcontentloaded' });
		const h1Info = await page.evaluate(() => {
			const h1s = Array.from(document.querySelectorAll('h1'));
			return h1s.map(h => ({
				tag: h.tagName,
				cls: h.className,
				text: h.textContent.trim().slice(0, 40),
				visible: getComputedStyle(h).display !== 'none' && getComputedStyle(h).visibility !== 'hidden',
				rect: {
					left: Math.round(h.getBoundingClientRect().left),
					top: Math.round(h.getBoundingClientRect().top)
				}
			}));
		});
		report.h1Check[url] = h1Info;
		console.log(`H1s at ${url}:`, JSON.stringify(h1Info));
	}

	console.log('--- 2. BROKEN IMAGES CHECK ---');
	const imgUrls = [
		'https://akibara.cl/wp-content/uploads/2026/09/final-principal-mobile-v2.avif',
		'https://akibara.cl/wp-content/uploads/2026/05/bubble_wood_front.png',
		'https://akibara.cl/wp-content/plugins/flowpaymentfl/images/custom-logo-small.png'
	];
	report.imgCheck = {};
	for (const url of imgUrls) {
		const res = await page.request.get(url);
		report.imgCheck[url] = { status: res.status(), ok: res.ok() };
		console.log(`Image ${url} -> status ${res.status()}`);
	}

	console.log('--- 3. PDP PACK & STICKY ATC IN PRODUCTION ---');
	await page.goto('https://akibara.cl/chainsaw-man-1-ivrea-argentina/', { waitUntil: 'networkidle' });
	const pdpInfo = await page.evaluate(async () => {
		// check if sticky-atc.min.js is loaded
		const scripts = Array.from(document.querySelectorAll('script')).map(s => s.src);
		const stickyScript = scripts.find(s => s.includes('sticky-atc'));

		// check initial builder state
		const builderWrap = document.querySelector('.akb-sb-builder__grid-wrap');
		const wrapHeight = builderWrap ? builderWrap.getBoundingClientRect().height : 0;
		const wrapMaxHeight = builderWrap ? getComputedStyle(builderWrap).maxHeight : 'none';

		// Click on shortcut last5
		const last5Btn = document.querySelector('[data-akb-sb-shortcut="last5"]');
		if (last5Btn) last5Btn.click();
		await new Promise(r => setTimeout(r, 400));

		const badge = document.getElementById('akb-sticky-atc-badge');
		const subtext = document.getElementById('akb-sticky-atc-subtext');
		const bar = document.getElementById('akb-sticky-atc');

		return {
			stickyScript,
			wrapHeight,
			wrapMaxHeight,
			isPackMode: bar ? bar.classList.contains('is-pack-mode') : false,
			badgeHidden: badge ? badge.hidden : null,
			badgeText: badge ? badge.textContent.trim() : null,
			subtext: subtext ? subtext.textContent.trim() : null
		};
	});
	report.pdpCheck = pdpInfo;
	console.log('PDP Check:', JSON.stringify(pdpInfo, null, 2));

	console.log('--- 4. CHECKOUT AUDIT ---');
	// Add an item to cart first so checkout has contents
	await page.goto('https://akibara.cl/chainsaw-man-1-ivrea-argentina/', { waitUntil: 'networkidle' });
	await page.evaluate(() => {
		const btn = document.querySelector('form.cart button.single_add_to_cart_button');
		if (btn) btn.click();
	});
	await page.waitForTimeout(2000);

	await page.goto('https://akibara.cl/checkout/', { waitUntil: 'networkidle' });
	const checkoutInfo = await page.evaluate(async () => {
		window.scrollTo(0, 500);
		await new Promise(r => setTimeout(r, 400));

		const sidebar = document.querySelector('.woocommerce-checkout .wc-block-components-sidebar');
		const sRect = sidebar ? sidebar.getBoundingClientRect() : null;
		const sPos = sidebar ? getComputedStyle(sidebar).position : null;
		const sTop = sidebar ? getComputedStyle(sidebar).top : null;

		// Check payment options
		const paymentLabels = Array.from(document.querySelectorAll('.woocommerce-checkout .wc-block-components-radio-control-accordion-option'));
		const flowImg = document.querySelector('img[src*="flow"], img[alt*="Flow"]');
		const flowImgComputed = flowImg ? {
			display: getComputedStyle(flowImg).display,
			naturalWidth: flowImg.naturalWidth,
			src: flowImg.src
		} : null;

		// Check create account button vs place order
		const guestBtn = document.querySelector('.akb-rw-guest-hook__action');
		const placeOrderBtn = document.querySelector('.wc-block-components-checkout-place-order-button');

		return {
			sidebar: { sRect, sPos, sTop },
			flowImg: flowImgComputed,
			guestBtn: guestBtn ? {
				bg: getComputedStyle(guestBtn).backgroundColor,
				color: getComputedStyle(guestBtn).color,
				border: getComputedStyle(guestBtn).border
			} : null,
			placeOrderBtn: placeOrderBtn ? {
				bg: getComputedStyle(placeOrderBtn).backgroundColor,
				color: getComputedStyle(placeOrderBtn).color
			} : null
		};
	});
	report.checkoutCheck = checkoutInfo;
	console.log('Checkout Check:', JSON.stringify(checkoutInfo, null, 2));

	console.log('--- 5. BLOG FRIEREN CATEGORY CHECK ---');
	await page.goto('https://akibara.cl/category/guias/', { waitUntil: 'networkidle' });
	const guiasInfo = await page.evaluate(() => {
		const posts = Array.from(document.querySelectorAll('.akb-blog-card__title a')).map(a => ({
			title: a.textContent.trim(),
			href: a.href
		}));
		return posts;
	});
	report.guiasPosts = guiasInfo;
	console.log('Posts in /category/guias/:', JSON.stringify(guiasInfo.slice(0, 5), null, 2));

	await browser.close();
	console.log('FINISHED FORENSIC AUDIT');
}

runAudit().catch(err => {
	console.error('Audit failed:', err);
	process.exit(1);
});
