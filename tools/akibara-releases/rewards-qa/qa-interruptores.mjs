/**
 * ¿Los interruptores del panel APAGAN y ENCIENDEN de verdad?
 *
 * Prueba de dos lados, que es la fuerte: con el interruptor ENCENDIDO la cosa
 * tiene que verse en la tienda, y con el interruptor APAGADO tiene que
 * desaparecer. Un módulo que se ve siempre pasa la prueba de «se ve» y falla
 * ésta: el dueño cree que lo apagó y el cliente lo sigue viendo.
 *
 * Deja cada interruptor como estaba, incluso si falla. Sólo contra el espejo.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
if (!/localhost|127\.0\.0\.1/.test(BASE)) throw new Error('Sólo contra el espejo.');
const SOLO = process.env.AKB_SOLO ? process.env.AKB_SOLO.split(',') : null;

const CASOS = [
  { id: 'announcement', tab: 'announcement', campo: 'akibara_announcement_enabled',
    url: '/', marca: /akb-ann-bar/, que: 'la barra de anuncios' },
  { id: 'home_series', tab: 'home_series', campo: 'akibara_home_series_enabled',
    url: '/', marca: /Series más buscadas|akb-home-series/, que: 'el bloque de series de la portada' },
  { id: 'home_hero', tab: 'home_hero', campo: 'slides[0][is_active]',
    url: '/', marca: /Encuentra tu próxima historia/, que: 'la primera diapositiva del slider' },
  { id: 'native_cart', tab: 'native_cart', campo: 'akibara_native_cart_enabled',
    url: '/', marca: /id="akb-native-cart-drawer"/, que: 'el carrito nativo' },
  // El /carrito/ del sitio es de BLOQUES (`wp-block-woocommerce-cart`): la barra
  // clásica `akb-fsp-wrap` no vive ahí, y buscarla ahí me dio un falso positivo.
  // El interruptor se comprueba donde el módulo sí pinta: el payload del drawer.
  { id: 'free_shipping', tab: 'free_shipping_progress', campo: 'akibara_fsp_enabled',
    url: '/', marca: /showFreeShippingProgress"?\s*:\s*"?(true|1)"?|akb-fsp-wrap/, que: 'el avance de envío gratis' },
  { id: 'back_in_stock', tab: 'back_in_stock', campo: 'akb_bis_capture_enabled',
    urlDinamica: 'producto_outofstock', marca: /akb-bis/, que: 'la lista de espera del tomo agotado' },
  { id: 'address', tab: 'address', campo: 'akibara_address_autocomplete_enabled',
    urlDinamica: 'checkout', marca: /maps\.googleapis|akb-address-autocomplete|address-autocomplete/, que: 'el autocompletado de dirección' },
];

const nav = await chromium.launch();
const ctx = await nav.newContext({ viewport: { width: 1440, height: 1100 } });
const page = await ctx.newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', process.env.AKB_USER || 'qa.owner');
await page.fill('#user_pass', process.env.AKB_PASS || 'AkbQaOwner2026!');
await page.click('#wp-submit');
await page.waitForSelector('#wpadminbar', { timeout: 60000 });
console.log('· sesión iniciada\n');

const urls = {};
await page.goto(`${BASE}/wp-admin/edit.php?post_type=product&post_status=publish&stock_status=outofstock`, { waitUntil: 'load' }).catch(() => {});
urls.producto_outofstock = await page.evaluate(() => {
  const a = document.querySelector('#the-list .row-actions a[href^="http"]:not([href*="wp-admin"])');
  return a ? a.href.split('?')[0] : null;
});
await page.goto(`${BASE}/wp-admin/term.php?taxonomy=product_series&tag_ID=386`, { waitUntil: 'load' }).catch(() => {});
urls.serie_386 = await page.evaluate(() => {
  const a = [...document.querySelectorAll('a')].find((x) => /ver|view/i.test(x.textContent) && /^http/.test(x.href) && !/wp-admin/.test(x.href));
  return a ? a.href.split('?')[0] : null;
});
{
  const p = await ctx.newPage();
  await p.goto(`${BASE}/`, { waitUntil: 'load' }).catch(() => {});
  urls.checkout = await p.evaluate(() => {
    const a = [...document.querySelectorAll('a[href]')].find((x) => /checkout|finalizar|pagar/i.test(x.href));
    return a ? a.href.split('?')[0] : null;
  });
  await p.close();
  if (!urls.checkout) urls.checkout = `${BASE}/checkout/`;
}
console.log('· páginas de prueba:', JSON.stringify(urls), '\n');

const ver = async (url, marca) => {
  const p = await ctx.newPage();
  const r = await p.goto(url, { waitUntil: 'load' }).catch(() => null);
  const html = r ? await p.evaluate(() => document.documentElement.outerHTML) : '';
  await p.close();
  return { visible: marca.test(html), http: r ? r.status() : 0 };
};

const hallazgos = [];
for (const c of (SOLO ? CASOS.filter((x) => SOLO.includes(x.id)) : CASOS)) {
  const url = c.urlDinamica ? urls[c.urlDinamica] : BASE + c.url;
  process.stdout.write(`── ${c.id.padEnd(16)} `);
  if (!url) { console.log('SALTADO (no encontré la página de prueba)'); continue; }

  const panel = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${c.tab}`;
  const sel = `input[type="checkbox"][name="${c.campo.replace(/([[\]])/g, '\\$1')}"]`;
  let estadoOriginal = null;
  try {
    await page.goto(panel, { waitUntil: 'load' });
    await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
    const loc = page.locator(sel).first();
    if (!(await loc.count())) { console.log(`✗ no existe el control ${c.campo}`); hallazgos.push({ id: c.id, txt: `No existe el control ${c.campo} en ${c.tab}` }); continue; }
    estadoOriginal = await loc.isChecked();

    const poner = async (on) => {
      await page.goto(panel, { waitUntil: 'load' });
      await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
      const l = page.locator(sel).first();
      await l.scrollIntoViewIfNeeded();
      if (on) await l.check({ force: true }); else await l.uncheck({ force: true });
      await page.locator('form button[name="akibara_panel_save"]').first().click({ timeout: 8000 });
      await page.waitForLoadState('load').catch(() => {});
      await page.waitForTimeout(1200);
    };

    await poner(true);
    const encendido = await ver(url, c.marca);
    await poner(false);
    const apagado = await ver(url, c.marca);

    if (encendido.visible && !apagado.visible) console.log(`OK   ${c.que}: se ve encendido y desaparece apagado`);
    else if (!encendido.visible && !apagado.visible) {
      console.log(`✗✗   ${c.que}: NO SE VE ni encendido (HTTP ${encendido.http})`);
      hallazgos.push({ id: c.id, txt: `${c.que} no aparece en ${url} ni con el interruptor encendido (HTTP ${encendido.http})` });
    } else if (encendido.visible && apagado.visible) {
      console.log(`✗✗   ${c.que}: SIGUE VIÉNDOSE con el interruptor APAGADO`);
      hallazgos.push({ id: c.id, txt: `${c.que} sigue apareciendo en ${url} con el interruptor apagado — el dueño cree que lo apagó` });
    } else {
      console.log(`✗    ${c.que}: invertido (no se ve encendido y sí apagado)`);
      hallazgos.push({ id: c.id, txt: `${c.que}: comportamiento invertido en ${url}` });
    }
  } catch (e) {
    console.log('✗  error:', String(e).slice(0, 110));
    hallazgos.push({ id: c.id, txt: 'No se pudo completar: ' + String(e).slice(0, 110) });
  } finally {
    if (estadoOriginal !== null) {
      try {
        await page.goto(panel, { waitUntil: 'load' });
        await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
        const l = page.locator(sel).first();
        await l.scrollIntoViewIfNeeded();
        if (estadoOriginal) await l.check({ force: true }); else await l.uncheck({ force: true });
        await page.locator('form button[name="akibara_panel_save"]').first().click({ timeout: 8000 });
        await page.waitForLoadState('load').catch(() => {});
        await page.waitForTimeout(800);
      } catch { console.log(`   ⚠ NO PUDE RESTAURAR ${c.campo} → debía quedar ${estadoOriginal ? 'ENCENDIDO' : 'APAGADO'}`); }
    }
  }
}

console.log('\n═══ RESUMEN ═══');
if (!hallazgos.length) console.log('Todos los interruptores encienden y apagan de verdad.');
for (const h of hallazgos) console.log(` ${h.id}: ${h.txt}`);
await nav.close();
