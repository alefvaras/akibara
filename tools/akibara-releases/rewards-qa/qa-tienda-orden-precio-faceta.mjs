/**
 * qa-tienda-orden-precio-faceta.mjs — PRODUCCIÓN, invitado. «Menor precio» +
 * faceta «Disponible»: el orden se conserva en la URL, el select y el precio.
 */
import { chromium } from 'playwright';
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 AkibaraQA';
function log(step, data) { console.log('##', step, JSON.stringify(data)); }

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA });
const page = await ctx.newPage();

await page.goto('https://akibara.cl/tienda/', { waitUntil: 'load' });
log('carga-inicial', { url: page.url() });

const select = page.locator('select[name=orderby]').first();
await Promise.all([
  page.waitForNavigation({ waitUntil: 'load' }),
  select.selectOption({ label: 'Menor precio' }),
]);
log('tras-menor-precio', { url: page.url(), selectValue: await select.inputValue() });

const primerPrecioAntes = await page.locator('.price').first().innerText().catch(() => '');
log('primer-precio-antes-de-filtrar', { primerPrecioAntes });

// Tildar la faceta "Disponible" (checkbox de filtro por stock)
const disponibleCheckbox = page.locator('input[type=checkbox]').filter({ hasText: '' });
const disponibleLabel = page.locator('label', { hasText: 'Disponible' }).first();
await disponibleLabel.scrollIntoViewIfNeeded().catch(() => {});
await Promise.all([
  page.waitForNavigation({ waitUntil: 'load' }).catch(() => null),
  disponibleLabel.click(),
]);
await page.waitForTimeout(500);
log('tras-disponible', { url: page.url() });

const selectValueTrasFaceta = await select.inputValue().catch(() => null);
const primerPrecio = await page.locator('.price').first().innerText().catch(() => '');
log('estado-tras-faceta', { selectValueTrasFaceta, primerPrecio });

// Quitar el chip / faceta activa
const chip = page.locator('button.akb-chip[data-facet=disponibilidad]').first();
const chipCount = await chip.count();
if (chipCount) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }).catch(() => null),
    chip.click(),
  ]);
  await page.waitForTimeout(500);
  log('tras-quitar-chip', { url: page.url(), selectValue: await select.inputValue().catch(() => null) });
} else {
  log('sin-chip-visible', { url: page.url() });
}

await browser.close();
