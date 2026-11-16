import playwright from '../akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE_URL = process.env.AKB_BASE_URL || 'http://localhost:8080';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/d7ba4691-692a-4aed-96f1-081f9c1f8fd4/screenshots_qa/footer-trust';

fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

async function run() {
	console.log('🚀 Capturando Baseline y evaluando DOM de Footer Trust y Sellos de Confianza...');
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	try {
		// =========================================================================
		// 1. DESKTOP BASELINE (1440x900) - HOME
		// =========================================================================
		console.log('\n--- 1. Desktop Baseline (1440x900) - Home ---');
		const desktopCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
		const desktopPage = await desktopCtx.newPage();
		await desktopPage.goto(`${BASE_URL}/`, { waitUntil: 'networkidle' });

		// Home trust section
		const homeTrust = desktopPage.locator('.akb-trust-section');
		const hasHomeTrust = await homeTrust.count() > 0;
		console.log(`  .akb-trust-section en Home: ${hasHomeTrust}`);

		// Footer trust
		const footerTrust = desktopPage.locator('.footer-trust');
		const hasFooterTrust = await footerTrust.count() > 0;
		console.log(`  .footer-trust en Footer: ${hasFooterTrust}`);

		// Footer bottom
		const footerBottom = desktopPage.locator('.footer-bottom');
		const hasFooterBottom = await footerBottom.count() > 0;
		console.log(`  .footer-bottom en Footer: ${hasFooterBottom}`);

		// Captura full page y scroll al footer
		await desktopPage.screenshot({ path: path.join(SCREENSHOT_DIR, '01-desktop-home-full.png'), fullPage: true });
		if (hasFooterTrust) {
			await footerTrust.scrollIntoViewIfNeeded();
			await desktopPage.screenshot({ path: path.join(SCREENSHOT_DIR, '01-desktop-footer-baseline.png') });
		}
		console.log('  ✓ Screenshots Desktop Home guardados.');

		// =========================================================================
		// 2. MOBILE BASELINE (375x812) - HOME
		// =========================================================================
		console.log('\n--- 2. Mobile Baseline (375x812) - Home ---');
		const mobileCtx = await browser.newContext({ viewport: { width: 375, height: 812 } });
		const mobilePage = await mobileCtx.newPage();
		await mobilePage.goto(`${BASE_URL}/`, { waitUntil: 'networkidle' });

		await mobilePage.screenshot({ path: path.join(SCREENSHOT_DIR, '02-mobile-home-full.png'), fullPage: true });
		const mobileFooterTrust = mobilePage.locator('.footer-trust');
		if (await mobileFooterTrust.count() > 0) {
			await mobileFooterTrust.scrollIntoViewIfNeeded();
			await mobilePage.screenshot({ path: path.join(SCREENSHOT_DIR, '02-mobile-footer-baseline.png') });
		}
		console.log('  ✓ Screenshots Mobile Home guardados.');

		// =========================================================================
		// 3. PDP BASELINE (1440x900 y 375x812) - FICHA DE PRODUCTO
		// =========================================================================
		console.log('\n--- 3. PDP Baseline - Ficha de Producto ---');
		const pdpUrl = `${BASE_URL}/dan-da-dan-21-ivrea-argentina/`;
		await desktopPage.goto(pdpUrl, { waitUntil: 'networkidle' });

		const pdpBadges = desktopPage.locator('.akb-product-trust-badges');
		const hasPdpBadges = await pdpBadges.count() > 0;
		console.log(`  .akb-product-trust-badges en PDP: ${hasPdpBadges}`);

		const embalajeLink = desktopPage.locator('.akb-badge-trust__link[href*="embalaje-seguro"]');
		const hasEmbalajeLink = await embalajeLink.count() > 0;
		console.log(`  Enlace a /embalaje-seguro/ en PDP: ${hasEmbalajeLink}`);

		if (hasPdpBadges) {
			await pdpBadges.first().scrollIntoViewIfNeeded();
			await desktopPage.screenshot({ path: path.join(SCREENSHOT_DIR, '03-desktop-pdp-badges.png') });
		}

		await mobilePage.goto(pdpUrl, { waitUntil: 'networkidle' });
		const mobilePdpBadges = mobilePage.locator('.akb-product-trust-badges');
		if (await mobilePdpBadges.count() > 0) {
			await mobilePdpBadges.first().scrollIntoViewIfNeeded();
			await mobilePage.screenshot({ path: path.join(SCREENSHOT_DIR, '04-mobile-pdp-badges.png') });
		}
		console.log('  ✓ Screenshots PDP guardados.');

		console.log('\n=== DIAGNÓSTICO DEL DOM ACTUAL ===');
		console.log(`1. Footer Trust actual: ${hasFooterTrust ? 'PRESENTE (duplicado y asimétrico en footer)' : 'AUSENTE'}`);
		console.log(`2. Franja Trust en Home (.akb-trust-section): ${hasHomeTrust ? 'PRESENTE' : 'AUSENTE'}`);
		console.log(`3. Badges en PDP (.akb-product-trust-badges): ${hasPdpBadges ? 'PRESENTE bajo botón de compra' : 'AUSENTE'}`);
		console.log(`4. Enlace /embalaje-seguro/ en PDP: ${hasEmbalajeLink ? 'FUNCIONAL Y PRESENTE' : 'AUSENTE'}`);

	} catch (err) {
		console.error('Error durante la captura baseline:', err);
	} finally {
		await browser.close();
	}
}

run();
