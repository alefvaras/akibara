/** ¿La sub-pestaña Cortes se puede guardar? Inspección honesta de su formulario. */
import { chromium } from 'playwright';

const BASE = 'http://localhost:8080';
const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1440, height: 1400 } })).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', 'qa.owner');
await page.fill('#user_pass', 'AkbQaOwner2026!');
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);

await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=preventa&subtab=cortes`, { waitUntil: 'load' });
await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));

const info = await page.evaluate(() => ({
  formularios: [...document.querySelectorAll('form')].map((f) => ({
    id: f.id || '(sin id)',
    action: (f.getAttribute('action') || '(vacío)').slice(-60),
    method: f.method,
    campos: f.querySelectorAll('input:not([type=hidden]),select,textarea').length,
    hidden: [...f.querySelectorAll('input[type=hidden]')].map((h) => h.name).slice(0, 6),
    submits: [...f.querySelectorAll('button,input[type=submit]')]
      .map((b) => `${b.tagName}[type=${b.type}][name=${b.name || '-'}] "${(b.textContent || b.value || '').trim().slice(0, 30)}"`),
  })),
  todosLosBotones: [...document.querySelectorAll('.wrap button, .wrap input[type=submit], #wpbody button, #wpbody input[type=submit]')]
    .map((b) => `${b.tagName}[type=${b.type}][name=${b.name || '-'}] "${(b.textContent || b.value || '').trim().slice(0, 30)}"`),
  campoEjemplo: (() => {
    const e = document.getElementById('akb-cortes-dia-98');
    return e ? { name: e.name, enForm: e.closest('form') ? (e.closest('form').getAttribute('action') || '(vacío)').slice(-50) : 'FUERA DE FORM' } : null;
  })(),
}));
console.log(JSON.stringify(info, null, 2));
await page.screenshot({ path: 'preventa-cortes.png', fullPage: true });
await nav.close();
