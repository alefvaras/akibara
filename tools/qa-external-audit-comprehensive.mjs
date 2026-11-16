import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/16292e4b-646c-4752-83e8-fd2278327e63/screenshots';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

const BASE_URL = process.env.AKB_BASE_URL || 'https://akibara.cl';

async function safeCapture(page, name, action) {
	console.log(`[CAPTURE] Running step: ${name}...`);
	try {
		if (action) {
			await action();
		}
		const filePath = path.join(SCREENSHOT_DIR, `${name}.png`);
		await page.screenshot({ path: filePath, fullPage: false });
		console.log(`[PASS] Saved: ${name}.png`);
	} catch (err) {
		console.error(`[WARN] Step ${name} had an error: ${err.message}`);
		try {
			const filePath = path.join(SCREENSHOT_DIR, `${name}.png`);
			await page.screenshot({ path: filePath, fullPage: false });
			console.log(`[FALLBACK] Saved state for ${name}.png despite error`);
		} catch (e) {}
	}
}

async function runAudit() {
	console.log(`=== Starting Comprehensive Playwright Visual Audit on ${BASE_URL} ===`);
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
	});

	// Contexto Mobile (iPhone 14 / Safari / 390x844)
	const mobileContext = await browser.newContext({
		viewport: { width: 390, height: 844 },
		userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1',
		deviceScaleFactor: 2,
		isMobile: true,
		hasTouch: true,
	});

	// Contexto Desktop (1440x900)
	const desktopContext = await browser.newContext({
		viewport: { width: 1440, height: 900 },
		userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
		deviceScaleFactor: 2,
	});

	const mobilePage = await mobileContext.newPage();
	const desktopPage = await desktopContext.newPage();

	try {
		// 1. Mobile Header & Bottom Bar
		await safeCapture(mobilePage, '01-mobile-header-and-bottom-bar', async () => {
			await mobilePage.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
			await mobilePage.waitForTimeout(1000);
		});

		// 2. Buscador Predictivo (Cmd+K)
		await safeCapture(desktopPage, '02-desktop-search-predictive', async () => {
			await desktopPage.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
			await desktopPage.waitForTimeout(800);
			const btn = await desktopPage.$('.akb-search-trigger, [data-akb-search-trigger], button[aria-label*="Buscar"]');
			if (btn) {
				await btn.click();
				await desktopPage.waitForTimeout(500);
				const input = await desktopPage.$('input[type="search"], .akb-search-modal input, #akb-search-input');
				if (input) {
					await input.fill('One Piece');
					await desktopPage.waitForTimeout(1200);
				}
			}
		});

		// 3. Portada: Secciones, Slogan y Novedades
		await safeCapture(desktopPage, '03-home-sections-and-slogan', async () => {
			await desktopPage.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
			await desktopPage.waitForTimeout(1000);
			await desktopPage.evaluate(() => window.scrollBy(0, 500));
			await desktopPage.waitForTimeout(600);
		});

		// 4. Tarjetas en /a-pedido/ Desktop
		await safeCapture(desktopPage, '04-apedido-card-pricing-and-buttons', async () => {
			await desktopPage.goto(`${BASE_URL}/a-pedido/`, { waitUntil: 'domcontentloaded' });
			await desktopPage.waitForTimeout(1000);
			await desktopPage.evaluate(() => window.scrollBy(0, 400));
			await desktopPage.waitForTimeout(600);
		});

		// 4-b. Tarjetas en /a-pedido/ Mobile (densidad)
		await safeCapture(mobilePage, '04-b-apedido-mobile-card-density', async () => {
			await mobilePage.goto(`${BASE_URL}/a-pedido/`, { waitUntil: 'domcontentloaded' });
			await mobilePage.waitForTimeout(1000);
			await mobilePage.evaluate(() => window.scrollBy(0, 420));
			await mobilePage.waitForTimeout(600);
		});

		// 5. Página de Serie: /serie/gachiakuta/
		await safeCapture(desktopPage, '05-series-page-bundle-and-rules', async () => {
			await desktopPage.goto(`${BASE_URL}/serie/gachiakuta/`, { waitUntil: 'domcontentloaded' });
			await desktopPage.waitForTimeout(1200);
			await desktopPage.evaluate(() => window.scrollBy(0, 300));
			await desktopPage.waitForTimeout(600);
		});

		// 6. Quick View en Mobile (abrir modal)
		await safeCapture(mobilePage, '06-mobile-quickview-interaction', async () => {
			await mobilePage.goto(`${BASE_URL}/manga/`, { waitUntil: 'domcontentloaded' });
			await mobilePage.waitForTimeout(1200);
			await mobilePage.evaluate(() => window.scrollBy(0, 400));
			const trigger = await mobilePage.$('.akb-qv-trigger');
			if (trigger) {
				await trigger.click({ force: true }).catch(() => {});
				await mobilePage.waitForTimeout(1000);
			}
		});

		// 7. Filtros facetados en /manga/ Desktop
		await safeCapture(desktopPage, '07-catalog-filters-and-pills', async () => {
			await desktopPage.goto(`${BASE_URL}/manga/`, { waitUntil: 'domcontentloaded' });
			await desktopPage.waitForTimeout(1200);
		});

		// 8. Cabecera móvil de /manga/ (Clamping de texto SEO)
		await safeCapture(mobilePage, '08-mobile-catalog-header-clamping', async () => {
			await mobilePage.goto(`${BASE_URL}/manga/`, { waitUntil: 'domcontentloaded' });
			await mobilePage.waitForTimeout(1000);
		});

		// 9. PDP Gachiakuta 10 (ETA y CTA)
		await safeCapture(desktopPage, '09-pdp-gachiakuta-eta-and-cta', async () => {
			await desktopPage.goto(`${BASE_URL}/gachiakuta-10-ivrea-argentina/`, { waitUntil: 'domcontentloaded' });
			await desktopPage.waitForTimeout(1200);
			await desktopPage.evaluate(() => window.scrollBy(0, 200));
			await desktopPage.waitForTimeout(500);
		});

		// 10. PDP Módulo «Arma tu pack y ahorra»
		await safeCapture(desktopPage, '10-pdp-armatupack-details', async () => {
			await desktopPage.evaluate(() => {
				const pack = document.querySelector('.akb-sb-builder, .akb-series-bundle');
				if (pack) pack.scrollIntoView();
				else window.scrollBy(0, 500);
			});
			await desktopPage.waitForTimeout(600);
		});

		// 11. Mini-Cart: Barra de Progreso de Envío Gratis
		await safeCapture(desktopPage, '11-minicart-free-shipping-progress', async () => {
			const addBtn = await desktopPage.$('button.single_add_to_cart_button, .cart button[type="submit"]');
			if (addBtn) {
				await addBtn.click();
				await desktopPage.waitForTimeout(2000);
			}
		});

		// 12. Checkout: Métodos de Envío y Retiro San Miguel
		await safeCapture(desktopPage, '12-checkout-shipping-options-san-miguel', async () => {
			await desktopPage.goto(`${BASE_URL}/finalizar-compra/`, { waitUntil: 'domcontentloaded' });
			await desktopPage.waitForTimeout(2500);
		});

		// 13. Nosotros: Contadores de Catálogo
		await safeCapture(desktopPage, '13-nosotros-catalog-counters', async () => {
			await desktopPage.goto(`${BASE_URL}/nosotros/`, { waitUntil: 'domcontentloaded' });
			await desktopPage.waitForTimeout(1000);
			await desktopPage.evaluate(() => window.scrollBy(0, 400));
			await desktopPage.waitForTimeout(500);
		});

		// 14. Menú de Editoriales en Desktop
		await safeCapture(desktopPage, '14-editoriales-menu-consistency', async () => {
			await desktopPage.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
			await desktopPage.waitForTimeout(800);
			const menu = await desktopPage.$('nav a[href*="manga"], .menu-item-has-children, #menu-item-24357');
			if (menu) {
				await menu.hover().catch(() => {});
				await desktopPage.waitForTimeout(600);
			}
		});

		// 15. PDP: Sección de Reseñas «Voces de Akibara»
		await safeCapture(desktopPage, '15-pdp-reviews-voces-de-akibara', async () => {
			await desktopPage.goto(`${BASE_URL}/gachiakuta-10-ivrea-argentina/`, { waitUntil: 'domcontentloaded' });
			await desktopPage.waitForTimeout(1000);
			await desktopPage.evaluate(() => {
				const r = document.querySelector('#reviews, .woocommerce-Reviews, .akb-reviews');
				if (r) r.scrollIntoView();
				else window.scrollBy(0, 1800);
			});
			await desktopPage.waitForTimeout(600);
		});

		// 16. Blog: Artículo y Guía de Lectura
		await safeCapture(desktopPage, '16-blog-reading-guide-product-links', async () => {
			await desktopPage.goto(`${BASE_URL}/blog/`, { waitUntil: 'domcontentloaded' });
			await desktopPage.waitForTimeout(1000);
			const link = await desktopPage.$('.akb-blog-card a, article a, h2 a');
			if (link) {
				await link.click();
				await desktopPage.waitForTimeout(1200);
				await desktopPage.evaluate(() => window.scrollBy(0, 600));
				await desktopPage.waitForTimeout(500);
			}
		});

		console.log('=== All 16 visual captures finished! ===');
	} finally {
		await browser.close();
	}
}

runAudit().catch(err => {
	console.error('Fatal execution error:', err);
	process.exit(1);
});
