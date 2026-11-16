import { chromium } from 'playwright';
const BASE = 'http://localhost:8080';
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', 'ale.fvaras');
await page.fill('#user_pass', 'QaAdmin!2026');
await Promise.all([page.waitForLoadState('load'), page.click('#wp-submit')]);
await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=dashboard`, { waitUntil: 'load' });
const link = page.locator('a', { hasText: 'Pedidos y preventa' }).first();
const count = await link.count();
const href = count ? await link.getAttribute('href') : null;
console.log('count', count, 'href', href);
if (href) {
  await page.goto(href.startsWith('http') ? href : BASE + href, { waitUntil: 'load' });
  console.log('url-tras-click', page.url());
  console.log('h1/encabezado', (await page.locator('h1, h2').first().innerText().catch(() => '')).trim());
}
await browser.close();
