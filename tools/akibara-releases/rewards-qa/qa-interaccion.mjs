// Caso INTERACCIÓN: no basta que el CSS diga overflow:auto. Se prueba con
// gestos reales: arrastre táctil, rueda del ratón y navegación por teclado.
import { chromium } from 'playwright';

const browser = await chromium.launch();
const out = {};

// ---------- MÓVIL: arrastre táctil real ----------
{
  const ctx = await browser.newContext({
    viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2,
  });
  const page = await ctx.newPage();
  await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
  const sec = await page.$('.akb-rw-carousel');
  await sec.scrollIntoViewIfNeeded();
  await page.waitForTimeout(500);

  const caja = await page.$eval('.akb-rw-track', (t) => {
    const b = t.getBoundingClientRect();
    return { x: b.x, y: b.y, w: b.width, h: b.height };
  });
  const antes = await page.$eval('.akb-rw-track', (t) => t.scrollLeft);

  // arrastre de derecha a izquierda dentro de la pista
  const cy = caja.y + caja.h / 2;
  await page.touchscreen.tap(caja.x + caja.w / 2, cy);
  await page.evaluate(async ({ x, y }) => {
    const t = document.querySelector('.akb-rw-track');
    const disparar = (tipo, cx) => {
      const touch = new Touch({ identifier: 1, target: t, clientX: cx, clientY: y });
      t.dispatchEvent(new TouchEvent(tipo, { touches: tipo === 'touchend' ? [] : [touch], targetTouches: tipo === 'touchend' ? [] : [touch], changedTouches: [touch], bubbles: true, cancelable: true }));
    };
    disparar('touchstart', x + 300);
    for (let i = 300; i >= 0; i -= 30) { disparar('touchmove', x + i); await new Promise((r) => setTimeout(r, 16)); }
    disparar('touchend', x);
  }, { x: caja.x, y: cy });
  await page.waitForTimeout(500);
  const trasArrastre = await page.$eval('.akb-rw-track', (t) => t.scrollLeft);

  // rueda horizontal
  await page.mouse.move(caja.x + caja.w / 2, cy);
  await page.mouse.wheel(400, 0);
  await page.waitForTimeout(500);
  const trasRueda = await page.$eval('.akb-rw-track', (t) => t.scrollLeft);

  out.movil = {
    antes, trasArrastre, trasRueda,
    arrastreFunciona: trasArrastre > antes,
    ruedaFunciona: trasRueda > trasArrastre,
    maxScroll: await page.$eval('.akb-rw-track', (t) => t.scrollWidth - t.clientWidth),
  };
  await ctx.close();
}

// ---------- ESCRITORIO: teclado + snap ----------
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 950 } });
  const page = await ctx.newPage();
  await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
  const sec = await page.$('.akb-rw-carousel');
  await sec.scrollIntoViewIfNeeded();
  await page.waitForTimeout(500);

  // rueda horizontal sobre la pista
  const caja = await page.$eval('.akb-rw-track', (t) => { const b = t.getBoundingClientRect(); return { x: b.x, y: b.y, w: b.width, h: b.height }; });
  await page.mouse.move(caja.x + caja.w / 2, caja.y + caja.h / 2);
  await page.mouse.wheel(600, 0);
  await page.waitForTimeout(500);
  const trasRueda = await page.$eval('.akb-rw-track', (t) => t.scrollLeft);

  // teclado: ¿se puede llegar a los enlaces de las cards tabulando?
  await page.evaluate(() => { document.querySelector('.akb-rw-track').scrollLeft = 0; document.querySelector('.akb-rw-carousel h2').scrollIntoView(); });
  await page.waitForTimeout(300);
  const teclado = await page.evaluate(async () => {
    const enlaces = [...document.querySelectorAll('.akb-rw-card .akb-rw-product')];
    const track = document.querySelector('.akb-rw-track');
    // enfocar el ÚLTIMO enlace: el navegador debería desplazar la pista sola
    const antes = track.scrollLeft;
    enlaces[enlaces.length - 1].focus();
    await new Promise((r) => setTimeout(r, 400));
    const activo = document.activeElement;
    return {
      nEnlacesFocuseables: enlaces.length,
      scrollAntes: antes,
      scrollDespues: track.scrollLeft,
      laPistaSiguioAlFoco: track.scrollLeft > antes,
      focoCorrecto: activo === enlaces[enlaces.length - 1],
      // ¿la pista misma es alcanzable por teclado? (Chrome la hace focuseable)
      trackTabIndex: track.tabIndex,
    };
  });

  // snap: ¿queda alineada una card tras scrollear a mano?
  const snap = await page.evaluate(async () => {
    const track = document.querySelector('.akb-rw-track');
    track.scrollLeft = 0;
    await new Promise((r) => setTimeout(r, 200));
    track.scrollTo({ left: 500, behavior: 'smooth' });
    await new Promise((r) => setTimeout(r, 900));
    const sl = track.scrollLeft;
    const cards = [...track.querySelectorAll('.akb-rw-card')];
    const offsets = cards.map((c) => Math.round(c.offsetLeft - track.offsetLeft));
    const masCercano = offsets.reduce((a, b) => (Math.abs(b - sl) < Math.abs(a - sl) ? b : a));
    return { scrollLeftFinal: Math.round(sl), offsetsCards: offsets, offsetMasCercano: masCercano, desalineacionPx: Math.abs(Math.round(sl) - masCercano) };
  });

  out.desktop = { trasRueda, ruedaFunciona: trasRueda > 0, teclado, snap };
  await ctx.close();
}

await browser.close();
console.log(JSON.stringify(out, null, 2));
