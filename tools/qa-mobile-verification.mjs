import playwright from './akibara-releases/rewards-qa/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const ARTIFACT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/16292e4b-646c-4752-83e8-fd2278327e63/screenshots/post-hygiene-qa';

async function runMobile(baseUrl, envLabel) {
  console.log(`📱 Auditando Móvil (iPhone 14 Pro: 393x852) en ${envLabel}...`);
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 393, height: 852 },
    isMobile: true,
    hasTouch: true,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'
  });
  const page = await context.newPage();

  // 1. Mobile Home
  await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: path.join(ARTIFACT_DIR, `${envLabel}-mobile-01-home.png`) });

  // 2. Mobile PDP + Pack
  await page.goto(`${baseUrl}/dan-da-dan-23-ivrea-argentina/`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);

  // Click stock chip
  const stockChip = page.locator('button[data-akb-sb-shortcut="stock"]');
  if (await stockChip.count() > 0) {
    await stockChip.scrollIntoViewIfNeeded();
    await stockChip.click();
    await page.waitForTimeout(500);
  }

  // Scroll to show sticky ATC and WhatsApp
  await page.evaluate(() => window.scrollBy(0, 700));
  await page.waitForTimeout(800);
  await page.screenshot({ path: path.join(ARTIFACT_DIR, `${envLabel}-mobile-02-pdp-sticky-pack.png`) });

  // 3. Mobile Cart Drawer
  await page.evaluate(() => {
    if (window.akbCart && typeof window.akbCart.open === 'function') {
      window.akbCart.open();
    }
  });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: path.join(ARTIFACT_DIR, `${envLabel}-mobile-03-cart-drawer.png`) });

  await browser.close();
  console.log(`   ✅ Móvil ${envLabel} completado exitosamente.`);
}

(async () => {
  await runMobile('http://localhost:8080', 'LOCAL');
  await runMobile('https://akibara.cl', 'PRODUCCION');
  console.log('🎉 Pruebas móviles completadas.');
})();
