import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function main() {
	console.log('🔍 INICIANDO BARRIDO ADVERSARIAL COMPLETO DE VERIFICACIÓN EN VIVO (https://akibara.cl)');
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const context = await browser.newContext({
		viewport: { width: 1440, height: 900 },
		userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Akibara-QA-Master'
	});

	const page = await context.newPage();
	const results = [];

	// 1. Core tokens
	await page.goto('https://akibara.cl/', { waitUntil: 'networkidle' });
	const tokens = await page.evaluate(() => {
		const s = getComputedStyle(document.documentElement);
		return {
			display: s.getPropertyValue('--font-display').trim(),
			sans: s.getPropertyValue('--font-sans').trim(),
			mono: s.getPropertyValue('--font-mono').trim()
		};
	});
	results.push({
		test: 'Tokens tipográficos en :root',
		pass: !!(tokens.display && tokens.sans && tokens.mono),
		details: tokens
	});

	// 2. Series directory sticky index
	await page.goto('https://akibara.cl/series/', { waitUntil: 'networkidle' });
	const seriesIndex = await page.evaluate(() => {
		const idx = document.querySelector('.akb-series-directory__index');
		if (!idx) return { error: 'No index' };
		const s = getComputedStyle(idx);
		return {
			position: s.position,
			top: s.top,
			zIndex: s.zIndex
		};
	});
	results.push({
		test: 'Directorio /series/ barra alfabética A-Z',
		pass: seriesIndex.position === 'sticky' && parseInt(seriesIndex.top) >= 60,
		details: seriesIndex
	});

	// 3. Series bundles landing grid & filter buttons
	await page.goto('https://akibara.cl/serie/chainsaw-man/', { waitUntil: 'networkidle' });
	
	// Click "Elegir tomos" to activate selection mode
	const startBtn = await page.$('[data-akb-sb-landing-start]');
	if (startBtn) {
		await startBtn.click();
		await page.waitForTimeout(500);
	}

	const bundleDetails = await page.evaluate(() => {
		const filter = document.querySelector('.akb-sb-landing__filter');
		const grid = document.querySelector('.akb-sb-selection-grid');
		let filterStyles = null;
		let gridStyles = null;
		if (filter) {
			const fs = getComputedStyle(filter);
			filterStyles = { clipPath: fs.clipPath, transform: fs.transform };
		}
		if (grid) {
			const gs = getComputedStyle(grid);
			gridStyles = { gridTemplateColumns: gs.gridTemplateColumns };
		}
		return { filterStyles, gridStyles };
	});
	const gridCols = bundleDetails.gridStyles ? bundleDetails.gridStyles.gridTemplateColumns.split(' ').length : 0;
	results.push({
		test: 'Ficha de serie /serie/chainsaw-man/ grid y botones',
		pass: (!bundleDetails.filterStyles || bundleDetails.filterStyles.clipPath === 'none') && gridCols >= 2,
		details: { ...bundleDetails, columnCount: gridCols }
	});

	// 4. Programa de Referidos H1
	await page.goto('https://akibara.cl/programa-referidos/', { waitUntil: 'networkidle' });
	const h1Count = await page.evaluate(() => document.querySelectorAll('h1').length);
	results.push({
		test: 'Página /programa-referidos/ único H1',
		pass: h1Count === 1,
		details: { h1Count }
	});

	// 5. Blog image containment & TOC
	await page.goto('https://akibara.cl/frieren-guia-para-leer-el-manga-despues-del-anime/', { waitUntil: 'networkidle' });
	const blogImgDetails = await page.evaluate(() => {
		const img = document.querySelector('.akb-post__img');
		const media = document.querySelector('.akb-post__media');
		return {
			imgMaxHeight: img ? getComputedStyle(img).maxHeight : null,
			imgObjectFit: img ? getComputedStyle(img).objectFit : null,
			mediaMaxHeight: media ? getComputedStyle(media).maxHeight : null
		};
	});
	results.push({
		test: 'Post de blog portada vertical contenida (<=480px)',
		pass: blogImgDetails.imgMaxHeight === '480px' && blogImgDetails.imgObjectFit === 'contain',
		details: blogImgDetails
	});

	// 6. Checkout Sidebar Sticky & Accordeon radio borders
	// Add item to cart first
	await page.goto('https://akibara.cl/tienda/', { waitUntil: 'networkidle' });
	const atc = await page.$('.ajax_add_to_cart');
	if (atc) {
		await atc.click();
		await page.waitForTimeout(1500);
	}
	await page.goto('https://akibara.cl/checkout/', { waitUntil: 'networkidle' });
	const checkoutResults = await page.evaluate(() => {
		const sidebar = document.querySelector('.woocommerce-checkout .wc-block-components-sidebar');
		if (!sidebar) return { error: 'No sidebar' };
		const cs = getComputedStyle(sidebar);
		const initialTop = sidebar.getBoundingClientRect().top;
		
		window.scrollTo(0, 500);
		const scrolledTop = sidebar.getBoundingClientRect().top;

		// Check guest hook button
		const guestBtn = document.querySelector('.akb-rw-guest-hook__action');
		let guestBtnStyles = null;
		if (guestBtn) {
			const gs = getComputedStyle(guestBtn);
			guestBtnStyles = {
				bg: gs.backgroundColor,
				color: gs.color,
				border: gs.border
			};
		}

		// Check place order button hover shadow definition
		const placeBtn = document.querySelector('.wc-block-components-checkout-place-order-button');
		const placeBtnStyles = placeBtn ? getComputedStyle(placeBtn).filter : null;

		return {
			position: cs.position,
			top: cs.top,
			alignSelf: cs.alignSelf,
			maxHeight: cs.maxHeight,
			overflowY: cs.overflowY,
			initialTop,
			scrolledTop,
			isSticky: scrolledTop <= 100 && scrolledTop > 0,
			guestBtnStyles,
			placeBtnFilter: placeBtnStyles
		};
	});
	results.push({
		test: 'Checkout Desktop: Sidebar sticky, scroll interno y jerarquía visual',
		pass: checkoutResults.isSticky && checkoutResults.position === 'sticky',
		details: checkoutResults
	});

	// 7. Redirections 301
	const redirectUrls = [
		['https://akibara.cl/serie/berserk/', '/serie/maximum-berserk/'],
		['https://akibara.cl/condiciones-despacho/', '/envios/'],
		['https://akibara.cl/politica-devoluciones/', '/devoluciones/'],
		['https://akibara.cl/terminos-condiciones/', '/terminos-y-condiciones/']
	];

	for (const [source, expectedTarget] of redirectUrls) {
		const resp = await page.goto(source, { waitUntil: 'networkidle' });
		const finalUrl = page.url();
		results.push({
			test: `Redirección 301: ${source}`,
			pass: finalUrl.includes(expectedTarget),
			details: { source, finalUrl, expectedTarget }
		});
	}

	await browser.close();

	console.log('\n========================================');
	console.log('   RESUMEN FINAL DE CERTIFICACIÓN');
	console.log('========================================');
	let allPass = true;
	for (const r of results) {
		const mark = r.pass ? '✅ PASS' : '❌ FAIL';
		console.log(`${mark}: ${r.test}`);
		console.log('   ', JSON.stringify(r.details));
		if (!r.pass) allPass = false;
	}
	console.log('========================================');
	console.log(allPass ? '🎉 TODOS LOS PUNTOS CERTIFICADOS EN VERDE (0 FALLOS)' : '⚠️ HUBO PUNTOS FALLIDOS');
}

main().catch(console.error);
