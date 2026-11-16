/**
 * Bordes de TEXTO del panel (pedidos por dev): `%`, emoji, comillas
 * tipográficas y 10.000 caracteres. Guarda, recarga, relee, y comprueba que la
 * página pública que usa ese texto NO se rompe. Restaura al terminar.
 *
 * Trabaja sobre `copys` para no rozar las options del descuento, que dev está
 * usando en el espejo.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const CAMPO = process.env.AKB_CAMPO || 'akb_copy_footer_support_hours';
const PUBLICA = process.env.AKB_PUBLICA || '/';

const CASOS = [
  { et: 'porcentaje suelto', v: 'Atención 100% de lunes a viernes' },
  { et: 'printf mal formado', v: 'Horario %s y %d y %%' },
  { et: 'emoji', v: 'Atención 🕐 lunes a viernes 🎌' },
  { et: 'comillas tipográficas', v: 'Horario «de 9 a 18» — “sin” cortes' },
  { et: 'diez mil caracteres', v: 'A'.repeat(10000) },
  { et: 'html y script', v: 'Horario <b>9 a 18</b><script>alert(1)</script>' },
];

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', process.env.AKB_USER || 'qa.owner');
await page.fill('#user_pass', process.env.AKB_PASS || 'AkbQaOwner2026!');
await Promise.all([page.waitForLoadState('load').catch(() => {}), page.click('#wp-submit')]);
await page.waitForTimeout(2500);
await page.waitForSelector('#wpadminbar', { timeout: 180000 });

const URL = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=copys`;
const ir = async () => { for (let i = 0; i < 3; i++) { try { await page.goto(URL, { waitUntil: 'load' }); return; } catch { await page.waitForTimeout(1500); } } };
const leer = () => page.evaluate((n) => { const e = document.getElementsByName(n)[0]; return e ? String(e.value ?? '') : '(ausente)'; }, CAMPO);
const poner = (v) => page.evaluate(([n, val]) => {
  const e = document.getElementsByName(n)[0];
  const proto = e.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, 'value').set.call(e, val);
  e.dispatchEvent(new Event('input', { bubbles: true }));
  e.dispatchEvent(new Event('change', { bubbles: true }));
}, [CAMPO, v]);
const guardar = async () => {
  await page.evaluate((n) => {
    const f = document.getElementsByName(n)[0].closest('form');
    (f.querySelector('button[name="akibara_panel_save"]') || f.querySelector('button[type=submit]')).click();
  }, CAMPO);
  await page.waitForLoadState('load').catch(() => {});
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1500);
};
const publica = async () => {
  const r = await page.request.get(`${BASE}${PUBLICA}?qa=${Date.now()}`);
  const html = await r.text();
  return { http: r.status(), bytes: html.length, fatal: /Fatal error|error crítico|critical error/i.test(html), script: /<script>alert\(1\)<\/script>/.test(html) };
};

await ir();
const original = await leer();
console.log(`campo ${CAMPO} · original «${original.slice(0, 60)}»\n`);

for (const c of CASOS) {
  await ir(); await poner(c.v); await guardar(); await ir();
  const leido = await leer();
  const p = await publica();
  const igual = leido === c.v;
  const recortado = leido.length !== c.v.length;
  console.log(`${p.fatal ? '✗✗' : '  '} ${c.et.padEnd(24)} guardado=${igual ? 'idéntico' : (recortado ? `recortado ${c.v.length}→${leido.length}` : 'distinto')}  público http=${p.http} ${p.bytes}B fatal=${p.fatal} scriptCrudo=${p.script}`);
}

await ir(); await poner(original); await guardar(); await ir();
const fin = await leer();
const pf = await publica();
console.log(`\nrestaurado: ${fin === original ? 'sí' : 'NO — quedó «' + fin.slice(0, 40) + '»'} · público http=${pf.http} fatal=${pf.fatal}`);
await nav.close();
