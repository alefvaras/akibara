// Caso FOTO: ¿se sirve la imagen? ¿está deformada? ¿la card con foto rompe
// la altura de la fila? Compara alturas reales y mide el hueco sobrante.
import { chromium } from 'playwright';

const browser = await chromium.launch();
const res = {};

for (const [nombre, vp] of [['desktop', { width: 1440, height: 950 }], ['movil', { width: 375, height: 812 }]]) {
  const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const fallos = [];
  page.on('response', (r) => { if (r.url().includes('/uploads/') && r.status() >= 400) fallos.push(`${r.status()} ${r.url()}`); });
  await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
  const sec = await page.$('.akb-rw-carousel');
  await sec.scrollIntoViewIfNeeded();
  await page.waitForTimeout(700);

  res[nombre] = await page.evaluate(() => {
    const cards = [...document.querySelectorAll('.akb-rw-card')];
    const conFoto = cards.filter((c) => c.querySelector('img.akb-rw-photo'));
    const sinFoto = cards.filter((c) => !c.querySelector('img.akb-rw-photo'));
    const alturaDe = (c) => Math.round(c.getBoundingClientRect().height);

    // hueco: distancia entre el fondo del último hijo con contenido y el borde de la card
    const hueco = (c) => {
      const cb = c.getBoundingClientRect();
      const hijos = [...c.children].filter((h) => h.getBoundingClientRect().height > 0);
      if (!hijos.length) return null;
      const ultimo = hijos[hijos.length - 1].getBoundingClientRect();
      return Math.round(cb.bottom - ultimo.bottom);
    };

    const img = conFoto.length ? conFoto[0].querySelector('img.akb-rw-photo') : null;
    const ib = img ? img.getBoundingClientRect() : null;

    return {
      nCards: cards.length,
      nConFoto: conFoto.length,
      nSinFoto: sinFoto.length,
      alturaConFoto: conFoto.map(alturaDe),
      alturaSinFoto: sinFoto.map(alturaDe),
      huecoConFoto: conFoto.map(hueco),
      huecoSinFoto: sinFoto.map(hueco),
      alturaFilaUniforme: new Set(cards.map(alturaDe)).size === 1,
      foto: img ? {
        cargada: img.complete && img.naturalWidth > 0,
        naturalW: img.naturalWidth, naturalH: img.naturalHeight,
        boxW: Math.round(ib.width), boxH: Math.round(ib.height),
        aspectoNatural: +(img.naturalWidth / img.naturalHeight).toFixed(3),
        aspectoCaja: +(ib.width / ib.height).toFixed(3),
        objectFit: getComputedStyle(img).objectFit,
        currentSrc: img.currentSrc.split('/').pop(),
        alt: img.getAttribute('alt'),
        loading: img.getAttribute('loading'),
        // ¿desborda la card?
        sobresale: Math.round(ib.right - conFoto[0].getBoundingClientRect().right),
      } : null,
    };
  });
  res[nombre].peticionesFallidas = fallos;
  await ctx.close();
}

await browser.close();
console.log(JSON.stringify(res, null, 2));
