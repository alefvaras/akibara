/**
 * qa-accesibilidad-panel.mjs — barre las 19 pestañas del panel Akibara y lista
 * los controles de formulario (input/select/textarea) visibles sin etiqueta
 * accesible: sin label[for], sin aria-label, sin aria-labelledby, sin <label>
 * envolvente, sin title. Abre los <details> cerrados antes de medir.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const TABS = [
  'dashboard','preventa','preventas','back_in_stock','series_follow',
  'free_shipping_progress','native_cart','upsells_autofill','catalog_order',
  'taxonomy_discount','series_bundles','home_hero','home_series',
  'announcement','address','brand_editions','copys','salud_seo'
];

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();

await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', 'ale.fvaras');
await page.fill('#user_pass', 'QaAdmin!2026');
await Promise.all([page.waitForLoadState('load'), page.click('#wp-submit')]);

const resultados = {};

for (const tab of TABS) {
  await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${tab}`, { waitUntil: 'load' });
  // Abrir todos los <details> cerrados
  await page.evaluate(() => {
    document.querySelectorAll('details:not([open])').forEach((d) => { d.open = true; });
  });
  await page.waitForTimeout(100);

  const sinEtiqueta = await page.evaluate(() => {
    function esVisible(el) {
      const r = el.getBoundingClientRect();
      const style = window.getComputedStyle(el);
      return style.display !== 'none' && style.visibility !== 'hidden' && el.offsetParent !== null;
    }
    function tieneEtiquetaAccesible(el) {
      const ariaLabel = el.getAttribute('aria-label');
      if (ariaLabel && ariaLabel.trim()) return true;
      const labelledby = el.getAttribute('aria-labelledby');
      if (labelledby) {
        const ids = labelledby.split(/\s+/).filter(Boolean);
        if (ids.some((id) => {
          const ref = document.getElementById(id);
          return ref && ref.textContent.trim();
        })) return true;
      }
      const title = el.getAttribute('title');
      if (title && title.trim()) return true;
      if (el.id) {
        const lbl = document.querySelector('label[for="' + CSS.escape(el.id) + '"]');
        if (lbl && lbl.textContent.trim()) return true;
      }
      let p = el.parentElement;
      while (p) {
        if (p.tagName === 'LABEL' && p.textContent.trim()) return true;
        p = p.parentElement;
      }
      return false;
    }
    const nodos = Array.from(document.querySelectorAll('input:not([type=hidden]), select, textarea'));
    const sin = [];
    for (const el of nodos) {
      if (!esVisible(el)) continue;
      if (tieneEtiquetaAccesible(el)) continue;
      sin.push({
        tag: el.tagName.toLowerCase(),
        type: el.getAttribute('type') || '',
        id: el.id || '',
        name: el.getAttribute('name') || '',
      });
    }
    return sin;
  });

  resultados[tab] = sinEtiqueta;
  console.log('##', tab, JSON.stringify(sinEtiqueta));
}

await browser.close();
console.log('===RESUMEN JSON===');
console.log(JSON.stringify(resultados, null, 2));
