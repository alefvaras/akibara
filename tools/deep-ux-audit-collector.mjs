import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;
const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE_URL = 'https://akibara.cl';

async function main() {
	const browser = await chromium.launch({ headless: true, executablePath: CHROME_PATH });
	
	// Mobile 390x844
	const mobileContext = await browser.newContext({
		viewport: { width: 390, height: 844 },
		userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15'
	});
	const mobilePage = await mobileContext.newPage();

	// Desktop 1280x900
	const desktopContext = await browser.newContext({
		viewport: { width: 1280, height: 900 }
	});
	const desktopPage = await desktopContext.newPage();

	const report = {};

	// 1. Home Mobile & Desktop
	await mobilePage.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
	report.homeMobile = await mobilePage.evaluate(() => {
		const h1 = document.querySelector('h1');
		const badges = Array.from(document.querySelectorAll('.akb-trust-badge, .akb-pillar, .trust-badge'));
		const reviews = document.querySelectorAll('.akb-review-card, .cr-review-card, .review');
		return {
			h1Text: h1?.innerText?.trim(),
			h1FontSize: h1 ? window.getComputedStyle(h1).fontSize : null,
			h1LineHeight: h1 ? window.getComputedStyle(h1).lineHeight : null,
			trustBadgesCount: badges.length,
			reviewsCount: reviews.length
		};
	});

	// 2. Catalog Mobile
	await mobilePage.goto(`${BASE_URL}/manga/`, { waitUntil: 'domcontentloaded' });
	report.catalogMobile = await mobilePage.evaluate(() => {
		const cards = Array.from(document.querySelectorAll('ul.products li.product')).slice(0, 4);
		return cards.map(c => {
			const qv = c.querySelector('.akb-qv-trigger, .quick-view');
			const title = c.querySelector('.woocommerce-loop-product__title');
			const price = c.querySelector('.price');
			const addBtn = c.querySelector('a.button, button.button');
			return {
				hasQuickView: !!qv,
				qvDisplay: qv ? window.getComputedStyle(qv).display : null,
				titleFontSize: title ? window.getComputedStyle(title).fontSize : null,
				priceFontSize: price ? window.getComputedStyle(price).fontSize : null,
				btnHeight: addBtn ? addBtn.getBoundingClientRect().height : 0
			};
		});
	});

	// 3. Series Page Chainsaw Man Mobile & Desktop
	await mobilePage.goto(`${BASE_URL}/serie/chainsaw-man/`, { waitUntil: 'domcontentloaded' });
	report.seriesMobile = await mobilePage.evaluate(() => {
		const bundleBox = document.querySelector('.akb-bundle-box, .akb-series-bundles, .akb-pack-box');
		const bundleNotice = document.querySelector('.akb-bundle-notice, .akb-byp-notice');
		const tomos = document.querySelectorAll('ul.products li.product');
		const specs = document.querySelector('.akb-series-meta, .akb-series-header__meta');
		return {
			hasBundleBox: !!bundleBox,
			bundleNoticeText: bundleNotice?.innerText?.trim()?.slice(0, 150),
			tomosCount: tomos.length,
			specsText: specs?.innerText?.trim()?.slice(0, 200)
		};
	});

	// 4. PDP Gachiakuta 10 Mobile
	await mobilePage.goto(`${BASE_URL}/gachiakuta-10-ivrea-argentina/`, { waitUntil: 'domcontentloaded' });
	report.pdpGachiakutaMobile = await mobilePage.evaluate(() => {
		const deliveryBox = document.querySelector('.akb-estimated-delivery, .akb-delivery-estimate');
		const specsGrid = document.querySelector('.akb-pdp-specs-grid, .akb-product-specs');
		const priceDel = document.querySelector('.summary del .woocommerce-Price-amount');
		const priceIns = document.querySelector('.summary ins .woocommerce-Price-amount, .summary .price > .woocommerce-Price-amount');
		const nav = document.querySelector('.akb-series-nav');
		return {
			deliveryBoxText: deliveryBox?.innerText?.trim()?.slice(0, 150),
			specsCount: specsGrid ? specsGrid.querySelectorAll('.akb-pdp-specs-row, tr, div').length : 0,
			regularPrice: priceDel?.innerText?.trim(),
			activePrice: priceIns?.innerText?.trim(),
			hasSeriesNav: !!nav
		};
	});

	// 5. Mini-Cart with product
	await mobilePage.click('button.single_add_to_cart_button');
	await mobilePage.waitForTimeout(2000);
	report.miniCartMobile = await mobilePage.evaluate(() => {
		const drawer = document.querySelector('.akb-cart-drawer');
		const progress = document.querySelector('.akb-fsp-wrap, .akb-fsp-progress-bar');
		const progressText = document.querySelector('.akb-fsp-title, .akb-fsp-heading')?.innerText?.trim();
		const items = document.querySelectorAll('.akb-cart-item');
		const statusNotice = document.querySelector('.akb-cart-status')?.innerText?.trim();
		const checkoutBtn = document.querySelector('.akb-cart-checkout, a[href*="checkout"], a[href*="finalizar-compra"]');
		return {
			drawerOpen: drawer?.classList.contains('akb-open'),
			hasProgress: !!progress,
			progressText,
			itemCount: items.length,
			statusNotice,
			checkoutBtnText: checkoutBtn?.innerText?.trim()
		};
	});

	// 6. Checkout Mobile
	await mobilePage.goto(`${BASE_URL}/finalizar-compra/`, { waitUntil: 'domcontentloaded' });
	await mobilePage.waitForTimeout(2500);
	report.checkoutMobile = await mobilePage.evaluate(() => {
		const inputs = Array.from(document.querySelectorAll('input, select')).map(i => ({
			id: i.id,
			name: i.name,
			type: i.type,
			required: i.required || i.hasAttribute('aria-required')
		}));
		const rutField = document.querySelector('#billing_rut, #rut, [name*="rut"]');
		const phoneField = document.querySelector('#billing_phone, #phone, [name*="phone"]');
		return {
			totalInputs: inputs.length,
			hasRutField: !!rutField,
			hasPhoneField: !!phoneField
		};
	});

	console.log('DEEP UX AUDIT REPORT:', JSON.stringify(report, null, 2));

	await browser.close();
}

main().catch(err => {
	console.error(err);
	process.exit(1);
});
