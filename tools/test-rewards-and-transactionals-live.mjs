import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function audit() {
	console.log('=== INICIANDO AUDITORÍA FORENSE SIN INFERENCIAS: REWARDS Y TRANSACCIONALES (https://akibara.cl) ===');
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const context = await browser.newContext({
		viewport: { width: 1440, height: 900 },
		userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Akibara-QA-Rewards-Transaccional'
	});

	const page = await context.newPage();
	const report = {};

	// 1. /akibara-puntos/
	console.log('1. Auditando /akibara-puntos/...');
	const resPuntos = await page.goto('https://akibara.cl/akibara-puntos/', { waitUntil: 'domcontentloaded' });
	report.puntosPage = await page.evaluate((status) => {
		const h1 = document.querySelector('h1');
		const details = document.querySelector('details');
		const tables = Array.from(document.querySelectorAll('table')).length;
		const text = document.body.innerText;
		return {
			httpStatus: status,
			h1Text: h1 ? h1.textContent.trim() : null,
			hasDetails: !!details,
			tablesCount: tables,
			mentions2PuntosPor100: text.includes('2 puntos por cada $100'),
			mentionsCaducidad12Meses: text.includes('12 meses') || text.includes('365 días')
		};
	}, resPuntos.status());
	console.log('Puntos page:', JSON.stringify(report.puntosPage, null, 2));

	// 2. /programa-referidos/
	console.log('2. Auditando /programa-referidos/...');
	const resRef = await page.goto('https://akibara.cl/programa-referidos/', { waitUntil: 'domcontentloaded' });
	report.referidosPage = await page.evaluate((status) => {
		const h1 = document.querySelector('h1');
		const text = document.body.innerText;
		return {
			httpStatus: status,
			h1Text: h1 ? h1.textContent.trim() : null,
			has500Puntos: text.includes('500 puntos') || text.includes('500'),
			hasLoginPrompt: text.includes('Inicia sesión') || text.includes('Crear cuenta')
		};
	}, resRef.status());
	console.log('Referidos page:', JSON.stringify(report.referidosPage, null, 2));

	// 3. /carrito/ con producto para ver aviso de rewards
	console.log('3. Auditando /carrito/ con producto...');
	await page.goto('https://akibara.cl/chainsaw-man-1-ivrea-argentina/', { waitUntil: 'networkidle' });
	await page.evaluate(() => {
		const atc = document.querySelector('form.cart button.single_add_to_cart_button');
		if (atc) atc.click();
	});
	await page.waitForTimeout(2000);

	const resCart = await page.goto('https://akibara.cl/carrito/', { waitUntil: 'networkidle' });
	report.cartPage = await page.evaluate((status) => {
		const guestHook = document.querySelector('.akb-rw-guest-hook');
		const actionBtn = document.querySelector('.akb-rw-guest-hook__action');
		const orderSummary = document.querySelector('.wc-block-components-order-summary');
		return {
			httpStatus: status,
			hasGuestHook: !!guestHook,
			actionBtn: actionBtn ? {
				tagName: actionBtn.tagName,
				text: actionBtn.textContent.trim(),
				href: actionBtn.getAttribute('href'),
				bg: getComputedStyle(actionBtn).backgroundColor,
				color: getComputedStyle(actionBtn).color
			} : null,
			hasOrderSummary: !!orderSummary
		};
	}, resCart.status());
	console.log('Cart page:', JSON.stringify(report.cartPage, null, 2));

	// 4. /checkout/ transaccional en vivo
	console.log('4. Auditando /checkout/ transaccional en vivo...');
	const resCheckout = await page.goto('https://akibara.cl/checkout/', { waitUntil: 'networkidle' });
	report.checkoutPage = await page.evaluate(async (status) => {
		// Contact fields
		const emailInput = document.getElementById('email');
		const rutInput = document.getElementById('contact-akibara-rut');
		const createAccountCheckbox = document.getElementById('checkbox-control-0');

		// Rewards Hook
		const guestHook = document.querySelector('.akb-rw-guest-hook');
		const guestBtn = document.querySelector('.akb-rw-guest-hook__action');

		// Click guestBtn and observe if checkbox gets checked
		let checkboxCheckedBefore = createAccountCheckbox ? createAccountCheckbox.checked : null;
		if (guestBtn) {
			guestBtn.click();
		}
		await new Promise(r => setTimeout(r, 600));
		let checkboxCheckedAfter = createAccountCheckbox ? createAccountCheckbox.checked : null;
		let guestBtnTextAfter = guestBtn ? guestBtn.textContent.trim() : null;

		// Shipping options (Enviar vs Retiro)
		const shippingOptions = Array.from(document.querySelectorAll('.wc-block-checkout__shipping-method-option'));
		const pickupOption = shippingOptions.find(o => o.textContent.includes('Retiro'));
		const deliveryOption = shippingOptions.find(o => o.textContent.includes('Enviar'));

		// Payment options
		const paymentOptions = Array.from(document.querySelectorAll('.wc-block-components-radio-control-accordion-option')).map(p => ({
			id: p.id || p.className,
			text: p.textContent.trim().slice(0, 40)
		}));

		// Place order button
		const placeOrderBtn = document.querySelector('.wc-block-components-checkout-place-order-button');

		return {
			httpStatus: status,
			contactFields: {
				hasEmail: !!emailInput,
				hasRut: !!rutInput,
				hasCreateAccountCheckbox: !!createAccountCheckbox
			},
			guestHookInteraction: {
				hasGuestHook: !!guestHook,
				guestBtnInitialText: guestBtn ? guestBtn.textContent.trim() : null,
				checkboxCheckedBefore,
				checkboxCheckedAfter,
				guestBtnTextAfter
			},
			shippingOptionsCount: shippingOptions.length,
			paymentOptionsCount: paymentOptions.length,
			hasPlaceOrderBtn: !!placeOrderBtn
		};
	}, resCheckout.status());
	console.log('Checkout page:', JSON.stringify(report.checkoutPage, null, 2));

	await browser.close();
	console.log('=== AUDITORÍA FORENSE COMPLETADA ===');
}

audit().catch(err => {
	console.error('Audit failed:', err);
	process.exit(1);
});
