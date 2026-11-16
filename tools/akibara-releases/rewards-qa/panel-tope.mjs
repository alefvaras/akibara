import { chromium } from 'playwright';
const BASE = 'http://localhost:8080';
const ADMIN = { user: 'ale.fvaras', pass: 'QaAdmin!2026' };
const AJUSTES = `${BASE}/wp-admin/admin.php?page=akb-rewards-settings`;

const b = await chromium.launch();
const ctx = await b.newContext({ userAgent: 'Mozilla/5.0 AkibaraQA/1.0 panel-tope' });
const p = await ctx.newPage();

await p.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await p.fill('#user_login', ADMIN.user);
await p.fill('#user_pass', ADMIN.pass);
await p.click('#wp-submit');
await p.waitForLoadState('domcontentloaded');

await p.goto(AJUSTES, { waitUntil: 'domcontentloaded' });
console.log('pantalla:', (await p.locator('h1').first().innerText()).trim());

for (const name of ['akb_rewards_redeem_min_points', 'akb_rewards_redeem_max_pct', 'akb_rewards_redeem_max_amount']) {
  const el = p.locator(`[name="${name}"]`);
  if (!await el.count()) { console.log(`  ${name}: NO APARECE`); continue; }
  const [val, min, max, type] = await Promise.all([el.inputValue(), el.getAttribute('min'), el.getAttribute('max'), el.getAttribute('type')]);
  const id = await el.getAttribute('id');
  let label = '', desc = '';
  try { label = (await p.locator(`label[for="${id}"]`).first().innerText()).trim(); } catch {}
  try { desc = (await el.locator('xpath=following-sibling::p[1]').first().innerText()).trim(); } catch {}
  console.log(`  ${name}: valor=${val} type=${type} min=${min} max=${max}`);
  console.log(`      etiqueta: "${label}"`);
  if (desc) console.log(`      ayuda:    "${desc.slice(0, 160)}"`);
}

await p.fill('[name="akb_rewards_redeem_max_amount"]', '3000');
await p.locator('#submit, input[type=submit]').first().click();
await p.waitForLoadState('domcontentloaded');
console.log('tras guardar 3000 ->', await p.locator('[name="akb_rewards_redeem_max_amount"]').inputValue());

await p.goto(`${BASE}/akibara-puntos/`, { waitUntil: 'domcontentloaded' });
const txt = await p.locator('body').innerText();
console.log('página pública:', (txt.split('\n').find(l => l.includes('pagar con puntos')) || '(no encontrada)').trim());

await p.goto(AJUSTES, { waitUntil: 'domcontentloaded' });
await p.fill('[name="akb_rewards_redeem_max_amount"]', '5000');
await p.locator('#submit, input[type=submit]').first().click();
await p.waitForLoadState('domcontentloaded');
console.log('restaurado a:', await p.locator('[name="akb_rewards_redeem_max_amount"]').inputValue());
await p.locator('[name="akb_rewards_redeem_max_amount"]').scrollIntoViewIfNeeded();
await p.screenshot({ path: 'panel-tope-canje.png' });
await b.close();
