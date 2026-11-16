/** Valida `barrido-en-pagina.js` contra el ESPEJO antes de apuntarlo a producción. */
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const TABS = (process.env.AKB_TABS || 'announcement,address').split(',');
const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', process.env.AKB_USER || 'qa.owner');
await page.fill('#user_pass', process.env.AKB_PASS || 'AkbQaOwner2026!');
await Promise.all([page.waitForLoadState('load').catch(() => {}), page.click('#wp-submit')]);
await page.waitForTimeout(2500);
await page.waitForSelector('#wpadminbar', { timeout: 180000 });
await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=announcement`, { waitUntil: 'load' });

await page.evaluate(readFileSync(new URL('./barrido-en-pagina.js', import.meta.url), 'utf8'));
for (const tab of TABS) {
  const r = await page.evaluate((t) => window.akbBarrer(t, { max: 999 }), tab);
  console.log(`── ${tab}: probados=${r.probados} saltados=${r.saltados} fallos=${r.fallos ? r.fallos.length : '?'}${r.error ? ' ERROR: ' + r.error : ''}`);
  for (const f of (r.fallos || [])) console.log(`   ✗✗ ${f.name} [${f.tipo}]: ${f.que}`);
}
await nav.close();
