import { chromium } from 'playwright';
const BASE = 'http://localhost:8080';
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', 'ale.fvaras');
await page.fill('#user_pass', 'QaAdmin!2026');
await Promise.all([page.waitForLoadState('load'), page.click('#wp-submit')]);
await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel`, { waitUntil: 'load' });
const links = await page.locator('a[href*="page=akibara-panel"][href*="tab="]').all();
const seen = new Set();
const out = [];
for (const l of links) {
  const href = await l.getAttribute('href');
  const text = (await l.innerText()).trim();
  const m = href.match(/tab=([a-zA-Z0-9_\-]+)/);
  if (m && !seen.has(m[1])) {
    seen.add(m[1]);
    out.push({ tab: m[1], text });
  }
}
console.log(JSON.stringify(out, null, 2));
await browser.close();
