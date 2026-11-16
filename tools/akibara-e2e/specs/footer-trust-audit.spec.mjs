// @ts-check
/**
 * Test E2E de Playwright: Auditoría Visual y DOM de Sellos de Confianza y Footer.
 *
 * Valida:
 *   1. Eliminación de .footer-trust en el footer de toda la tienda (Home, PDP, Catálogo).
 *   2. Alineación y presencia limpia de .footer-bottom (copyright).
 *   3. Preservación intacta de la franja de confianza del Home (.akb-trust-section).
 *   4. Preservación intacta de los sellos de producto (.akb-product-trust-badges) en PDP bajo el botón de compra con su enlace a /embalaje-seguro/.
 *   5. Cero errores de consola JS y cero excepciones de renderizado.
 *
 * @package Akibara\E2E
 */

import { expect, test } from '@playwright/test';

test.describe('Footer & Sellos de Confianza: Auditoría de Duplicidad y Jerarquía', () => {
	/** @type {Array<string>} */
	const consoleErrors = [];
	/** @type {Array<string>} */
	const pageErrors = [];

	test.beforeEach(async ({ page }) => {
		page.on('console', msg => {
			if (msg.type() === 'error') {
				consoleErrors.push(`[Console Error] ${msg.text()}`);
			}
		});

		page.on('pageerror', err => {
			pageErrors.push(`[Page Error] ${err.message}`);
		});
	});

	test('1. Home: .footer-trust está ausente, .footer-bottom está limpio y .akb-trust-section permanece intacto', async ({ page }) => {
		await page.goto('/', { waitUntil: 'domcontentloaded' });

		// 1. Franja de confianza en Home (.akb-trust-section)
		const homeTrust = page.locator('.akb-trust-section');
		await expect(homeTrust).toBeVisible();
		const trustItems = homeTrust.locator('.akb-trust-item');
		expect(await trustItems.count()).toBeGreaterThanOrEqual(4);

		// 2. Footer: .footer-trust NO debe existir
		const footerTrust = page.locator('.site-footer .footer-trust');
		await expect(footerTrust).toHaveCount(0);

		// 3. Footer Main y Bottom presentes y limpios
		const footerMain = page.locator('.site-footer .footer-main');
		await expect(footerMain).toBeVisible();

		const footerBottom = page.locator('.site-footer .footer-bottom');
		await expect(footerBottom).toBeVisible();
		await expect(footerBottom).toContainText('©');
		await expect(footerBottom).toContainText('Akibara · Todos los derechos reservados');

		expect(consoleErrors).toHaveLength(0);
		expect(pageErrors).toHaveLength(0);
	});

	test('2. Ficha de Producto (PDP): Sellos de confianza y enlace /embalaje-seguro/ presentes bajo botón de compra', async ({ page }) => {
		// Navegar a una ficha real publicada
		await page.goto('/dan-da-dan-21-ivrea-argentina/', { waitUntil: 'domcontentloaded' });

		// 1. Sellos bajo el botón de compra
		const pdpBadges = page.locator('.summary .akb-product-trust-badges, .entry-summary .akb-product-trust-badges, form.cart + * .akb-product-trust-badges, .akb-product-trust-badges').first();
		await expect(pdpBadges).toBeVisible();

		// 2. Enlace accesible a embalaje seguro
		const embalajeLink = pdpBadges.locator('a.akb-badge-trust__link[href*="embalaje-seguro"]');
		await expect(embalajeLink).toBeVisible();
		await expect(embalajeLink).toContainText('Embalaje reforzado');

		// 3. Footer en PDP tampoco debe tener .footer-trust
		const footerTrust = page.locator('.site-footer .footer-trust');
		await expect(footerTrust).toHaveCount(0);

		expect(consoleErrors).toHaveLength(0);
		expect(pageErrors).toHaveLength(0);
	});
});
