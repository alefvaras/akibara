// Scrollea la página a la derecha y pregunta al navegador QUÉ hay pintado
// allí. Además lista posicionados/pseudo-elementos dentro del carrusel.
import { chromium } from 'playwright';

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
const page = await ctx.newPage();
await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
const sec = await page.$('.akb-rw-carousel');
await sec.scrollIntoViewIfNeeded();
await page.waitForTimeout(400);

const out = await page.evaluate(async () => {
  const res = {};

  // 1) posicionados dentro del carrusel
  const car = document.querySelector('.akb-rw-carousel');
  res.posicionados = [];
  for (const el of car.querySelectorAll('*')) {
    const cs = getComputedStyle(el);
    if (cs.position !== 'static') {
      res.posicionados.push({ tag: el.tagName, cls: (el.className || '').toString().slice(0, 50), position: cs.position });
    }
    for (const pe of ['::before', '::after']) {
      const p = getComputedStyle(el, pe);
      if (p.content && p.content !== 'none' && p.display !== 'none') {
        res.posicionados.push({
          tag: el.tagName + pe, cls: (el.className || '').toString().slice(0, 50),
          position: p.position, content: p.content.slice(0, 30), w: p.width, h: p.height, left: p.left, right: p.right,
        });
      }
    }
  }

  // 2) ¿qué está pintado a la derecha del viewport?
  const carTop = car.getBoundingClientRect().top + window.scrollY;
  window.scrollTo(0, carTop);
  await new Promise((r) => setTimeout(r, 250));
  window.scrollTo(1000, carTop);
  await new Promise((r) => setTimeout(r, 300));
  res.scrollXLogrado = window.scrollX;
  res.pintadoEnX = [];
  if (window.scrollX > 0) {
    for (const y of [50, 150, 250, 350, 450, 550, 650, 750]) {
      for (const x of [100, 300]) {
        const el = document.elementFromPoint(x, y);
        if (!el) continue;
        const cs = getComputedStyle(el);
        res.pintadoEnX.push({
          x, y, tag: el.tagName, cls: (el.className || '').toString().slice(0, 60),
          position: cs.position,
          dentroDelCarrusel: car.contains(el),
        });
      }
    }
  }
  window.scrollTo(0, 0);
  return res;
});

console.log(JSON.stringify(out, null, 2));
await browser.close();
