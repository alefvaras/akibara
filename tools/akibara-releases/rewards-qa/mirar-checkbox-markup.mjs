/** ¿Las casillas del panel llevan el hidden «0» de compañía? Solo lee. */
import { chromium } from 'playwright';
const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const TABS = (process.env.AKB_TABS || 'home_hero,native_cart,free_shipping_progress,announcement,back_in_stock').split(',');
const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1440, height: 1200 } })).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', process.env.AKB_USER || 'qa.owner');
await page.fill('#user_pass', process.env.AKB_PASS || 'AkbQaOwner2026!');
await Promise.all([page.waitForLoadState('load').catch(() => {}), page.click('#wp-submit')]);
await page.waitForTimeout(2500);
await page.waitForSelector('#wpadminbar', { timeout: 180000 });

for (const tab of TABS) {
  await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${tab}`, { waitUntil: 'load' });
  await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
  const r = await page.evaluate(() => {
    const nombres = [...new Set([...document.querySelectorAll('input[type=checkbox]')].map((e) => e.name).filter(Boolean))];
    let conHidden = 0; const ejemplos = [];
    for (const n of nombres) {
      const els = [...document.getElementsByName(n)];
      const hay = els.some((e) => e.type === 'hidden');
      if (hay) { conHidden++; if (ejemplos.length < 3) ejemplos.push(n); }
    }
    return { casillas: nombres.length, conHidden, ejemplos };
  });
  console.log(`── ${tab}: ${r.casillas} casillas · con hidden de compañía: ${r.conHidden}${r.ejemplos.length ? '  (' + r.ejemplos.join(', ') + ')' : ''}`);
}
await nav.close();
