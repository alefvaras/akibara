import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function main() {
	console.log('🚀 Iniciando auditoría visual y computada en vivo con Chromium real...');
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const context = await browser.newContext({
		viewport: { width: 1280, height: 900 },
		userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Akibara-Live-Auditor'
	});

	const page = await context.newPage();

	console.log('\n--- 1. VARIABLES TIPOGRÁFICAS EN :ROOT (https://akibara.cl) ---');
	await page.goto('https://akibara.cl/', { waitUntil: 'networkidle' });

	const fontVars = await page.evaluate(() => {
		const cs = getComputedStyle(document.documentElement);
		return {
			fontDisplay: cs.getPropertyValue('--font-display').trim(),
			fontSans: cs.getPropertyValue('--font-sans').trim(),
			fontMono: cs.getPropertyValue('--font-mono').trim(),
			fontHeading: cs.getPropertyValue('--font-heading').trim(),
			fontBody: cs.getPropertyValue('--font-body').trim()
		};
	});
	console.log('Variables computadas:', fontVars);
	if (fontVars.fontDisplay && fontVars.fontSans && fontVars.fontMono) {
		console.log('✓ PASS: --font-display, --font-sans y --font-mono están definidas en :root');
	} else {
		console.error('✗ FAIL: Faltan variables tipográficas en :root');
	}

	console.log('\n--- 2. PROGRAMA DE REFERIDOS (H1 EN DOM) ---');
	await page.goto('https://akibara.cl/programa-referidos/', { waitUntil: 'networkidle' });
	const h1Info = await page.evaluate(() => {
		const h1s = Array.from(document.querySelectorAll('h1'));
		return {
			count: h1s.length,
			texts: h1s.map(h => ({ tag: h.tagName, class: h.className, text: h.innerText.trim() }))
		};
	});
	console.log('H1s encontrados:', h1Info);
	if (h1Info.count === 1) {
		console.log('✓ PASS: Exactamente 1 H1 en /programa-referidos/');
	} else {
		console.error(`✗ FAIL: Se encontraron ${h1Info.count} H1 en /programa-referidos/`);
	}

	console.log('\n--- 3. CHECKOUT EN VIVO: STICKY SIDEBAR Y CONTROLES ---');
	// Añadir un producto al carrito para tener checkout con items
	console.log('Añadiendo tomo al carrito...');
	await page.goto('https://akibara.cl/tienda/', { waitUntil: 'networkidle' });
	
	const addBtn = await page.$('.ajax_add_to_cart');
	if (addBtn) {
		await addBtn.click();
		await page.waitForTimeout(1500);
	}

	await page.goto('https://akibara.cl/checkout/', { waitUntil: 'networkidle' });
	await page.waitForTimeout(1000);

	const checkoutCheck = await page.evaluate(() => {
		const sidebar = document.querySelector('.woocommerce-checkout .wc-block-components-sidebar');
		if (!sidebar) {
			return { error: 'No se encontró .wc-block-components-sidebar en checkout' };
		}

		const cs = getComputedStyle(sidebar);
		const rectBefore = sidebar.getBoundingClientRect();

		// Verificar ancestros para detectar si alguno tiene overflow:hidden que impida sticky
		const ancestors = [];
		let el = sidebar.parentElement;
		while (el && el !== document.body) {
			const ecs = getComputedStyle(el);
			if (ecs.overflow !== 'visible' || ecs.overflowX !== 'visible' || ecs.overflowY !== 'visible') {
				ancestors.push({ tag: el.tagName, class: el.className, overflow: ecs.overflow, overflowX: ecs.overflowX, overflowY: ecs.overflowY });
			}
			el = el.parentElement;
		}

		return {
			position: cs.position,
			top: cs.top,
			alignSelf: cs.alignSelf,
			maxHeight: cs.maxHeight,
			overflowY: cs.overflowY,
			rectBefore: { top: rectBefore.top, bottom: rectBefore.bottom, height: rectBefore.height },
			blockingAncestors: ancestors
		};
	});
	console.log('Detalle sidebar inicial:', checkoutCheck);

	// Probar scroll a 400px
	await page.evaluate(() => window.scrollTo(0, 400));
	await page.waitForTimeout(500);

	const scrollCheck = await page.evaluate(() => {
		const sidebar = document.querySelector('.woocommerce-checkout .wc-block-components-sidebar');
		if (!sidebar) return null;
		const rectAfter = sidebar.getBoundingClientRect();
		return {
			scrollY: window.scrollY,
			rectAfter: { top: rectAfter.top, bottom: rectAfter.bottom, height: rectAfter.height },
			isStickyWorking: rectAfter.top <= 100 // si sticky funciona con top: ~84px, rectAfter.top debe rondar 84px y NO bajar a negativo
		};
	});
	console.log('Detalle tras scroll a 400px:', scrollCheck);

	if (scrollCheck && scrollCheck.isStickyWorking) {
		console.log(`✓ PASS: Sidebar es verdaderamente STICKY en desktop (top a ${scrollCheck.rectAfter.top}px con scrollY=${scrollCheck.scrollY}px)`);
	} else {
		console.error('✗ FAIL: Sidebar NO quedó sticky');
	}

	// Verificar acordeones en checkout
	const accordionCheck = await page.evaluate(() => {
		const nestedOptions = Array.from(document.querySelectorAll('.woocommerce-checkout .wc-block-components-radio-control-accordion-option > .wc-block-components-radio-control__option'));
		return nestedOptions.map(opt => {
			const cs = getComputedStyle(opt);
			return {
				border: cs.border,
				boxShadow: cs.boxShadow,
				background: cs.backgroundColor
			};
		});
	});
	console.log('Estilos computados de opciones de radio hijas de acordeón (deben ser transparentes/sin borde):', accordionCheck.slice(0, 2));

	console.log('\n--- 4. CATEGORÍA GUÍAS Y BLOG ---');
	await page.goto('https://akibara.cl/category/guias/', { waitUntil: 'networkidle' });
	const guiasCheck = await page.evaluate(() => {
		const cards = Array.from(document.querySelectorAll('.akb-blog-card'));
		const hasFrieren = cards.some(c => c.innerText.includes('Frieren tomo 7'));
		return {
			cardCount: cards.length,
			hasFrieren
		};
	});
	console.log('Guías de lectura:', guiasCheck);
	if (guiasCheck.hasFrieren) {
		console.log('✓ PASS: Post de Frieren tomo 7 visible en /category/guias/');
	} else {
		console.error('✗ FAIL: Post de Frieren tomo 7 no encontrado en /category/guias/');
	}

	await browser.close();
	console.log('\n🏁 Auditoría en vivo finalizada.');
}

main().catch(console.error);
