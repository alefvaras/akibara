/** ¿Cómo se guarda cada pestaña? Inventario de botones de envío. Solo lee. */
import { chromium } from 'playwright';
const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const TABS = (process.env.AKB_TABS || 'preventa,announcement,copys,home_hero,series_bundles,catalog_order,order_preventa,preventas,dashboard').split(',');
const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1440, height: 1200 } })).newPage();
await page.goto(`${BASE}/wp-login.php`);
await page.fill('#user_login', process.env.AKB_USER || 'qa.owner');
await page.fill('#user_pass', process.env.AKB_PASS || 'AkbQaOwner2026!');
await Promise.all([page.waitForLoadState('load').catch(()=>{}), page.click('#wp-submit')]);
await page.waitForTimeout(2500);
await page.waitForSelector('#wpadminbar', { timeout: 180000 });

for (const tab of TABS) {
  await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${tab}`, { waitUntil: 'load' });
  await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
  const info = await page.evaluate(() => {
    const bs = [...document.querySelectorAll('button, input[type=submit]')].map((b) => ({
      etiqueta: (b.textContent || b.value || '').trim().slice(0, 30),
      name: b.name || '',
      tipo: b.type || '',
      visible: !!(b.offsetParent || b.getClientRects().length),
      enSticky: !!b.closest('#akb-sticky-save-bar'),
      enForm: !!b.closest('form'),
    })).filter((b) => b.name || /guardar|save|aplicar/i.test(b.etiqueta));
    const sticky = document.querySelector('#akb-sticky-save-bar');
    return {
      botones: bs,
      haySticky: !!sticky,
      stickyVisible: !!(sticky && (sticky.offsetParent || sticky.getClientRects().length)),
      formas: document.querySelectorAll('form').length,
    };
  });
  console.log(`── ${tab}: formas=${info.formas} sticky=${info.haySticky}/${info.stickyVisible ? 'visible' : 'oculto'}`);
  for (const b of info.botones) console.log(`     [${b.name || '(sin name)'}] «${b.etiqueta}» tipo=${b.tipo} visible=${b.visible} sticky=${b.enSticky} enForm=${b.enForm}`);
  if (!info.botones.length) console.log('     (ningún botón de guardado)');
}
await nav.close();
