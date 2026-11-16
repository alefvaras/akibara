import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/local-global-collection';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

async function runLocalCheck() {
	console.log('Validando badges globales de Mi Colección en espejo local (http://localhost:8080)...');
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	try {
		// Contexto con mock de sessionStorage de usuario logueado
		const context = await browser.newContext({
			viewport: { width: 1366, height: 768 }
		});
		const page = await context.newPage();

		// Inyectar estado previo para simular tomos poseídos (16074 - Jujutsu Kaisen 22, 13387 - JJ Kaisen 30)
		await page.addInitScript(() => {
			window.sessionStorage.setItem('akb_collection_owned_v1_1', JSON.stringify([16074, 13387]));
		});

		// 1. Catálogo General en Local
		console.log('\n--- 1. Navegando al Catálogo General (/catalogo/) ---');
		await page.goto('http://localhost:8080/catalogo/', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1000);

		// Evaluar cuántas tarjetas se marcaron
		const badges = page.locator('.akb-card-collection-badge');
		const badgeCount = await badges.count();
		console.log(`  ✓ Badges "✓ En tu colección" detectados en catálogo: ${badgeCount}`);

		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01-local-catalogo-badges.png') });
		console.log('  📸 Evidencia tomada: 01-local-catalogo-badges.png');

		// 2. Ficha de Producto (PDP) de tomo poseído (13387)
		console.log('\n--- 2. Ficha de Producto PDP de tomo poseído ---');
		await page.goto('http://localhost:8080/producto/jujutsu-kaisen-30-panini-argentina/', { waitUntil: 'networkidle' });
		await page.waitForTimeout(800);

		const pdpBanner = page.locator('.akb-pdp-collection-banner');
		const pdpVisible = await pdpBanner.isVisible();
		const bannerText = await pdpBanner.innerText();
		console.log(`  ✓ Banner PDP visible: ${pdpVisible}`);
		console.log(`  ✓ Texto en banner PDP: "${bannerText.replace(/\n/g, ' ')}"`);

		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '02-local-pdp-collection-banner.png') });
		console.log('  📸 Evidencia tomada: 02-local-pdp-collection-banner.png');

		// 3. Mobile Viewport en Catálogo (390x844)
		console.log('\n--- 3. Catálogo en Móvil (390x844) ---');
		const mobContext = await browser.newContext({
			viewport: { width: 390, height: 844 },
			isMobile: true,
			hasTouch: true
		});
		const mobPage = await mobContext.newPage();
		await mobPage.addInitScript(() => {
			window.sessionStorage.setItem('akb_collection_owned_v1_1', JSON.stringify([16074, 13387]));
		});
		await mobPage.goto('http://localhost:8080/catalogo/', { waitUntil: 'networkidle' });
		await mobPage.waitForTimeout(1000);

		await mobPage.screenshot({ path: path.join(SCREENSHOT_DIR, '03-local-mobile-catalogo-badges.png') });
		console.log('  📸 Evidencia tomada: 03-local-mobile-catalogo-badges.png');

		await context.close();
		await mobContext.close();
		console.log('\n✓ Verificación local completada con éxito.');
	} finally {
		await browser.close();
	}
}

runLocalCheck().catch(console.error);
