// Prueba HONESTA del scroll horizontal de PÁGINA: intenta arrastrar la
// ventana a la derecha y mide si window.scrollX se mueve de verdad.
import { chromium } from 'playwright';

const ancho = Number(process.argv[2] || 375);
const browser = await chromium.launch();
const ctx = await browser.newContext({
  viewport: { width: ancho, height: 812 },
  isMobile: ancho < 768, hasTouch: ancho < 768, deviceScaleFactor: 2,
});
const page = await ctx.newPage();
await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
await page.waitForTimeout(400);

const r = await page.evaluate(async () => {
  const de = document.documentElement;
  const antes = { scrollX: window.scrollX, scrollW: de.scrollWidth, clientW: de.clientWidth };
  const estilos = {
    htmlOverflowX: getComputedStyle(de).overflowX,
    bodyOverflowX: getComputedStyle(document.body).overflowX,
    bodyScrollW: document.body.scrollWidth,
    bodyClientW: document.body.clientWidth,
  };
  // intento REAL de scrollear la página a la derecha
  window.scrollTo(9999, window.scrollY);
  await new Promise((r) => setTimeout(r, 300));
  const movida = window.scrollX;
  window.scrollTo(0, window.scrollY);
  return { antes, estilos, scrollXTrasIntento: movida, sePuedeScrollear: movida > 0 };
});

// segunda medición: con el carrusel fuera del DOM (quirúrgico, solo en el
// navegador) para atribuir el desborde sin tocar la base de datos
const sinCarrusel = await page.evaluate(async () => {
  const sec = document.querySelector('.akb-rw-carousel');
  if (!sec) return { habia: false };
  const padre = sec.parentNode; const ref = sec.nextSibling;
  sec.remove();
  await new Promise((r) => setTimeout(r, 250));
  const de = document.documentElement;
  window.scrollTo(9999, window.scrollY);
  await new Promise((r) => setTimeout(r, 250));
  const out = { habia: true, scrollW: de.scrollWidth, clientW: de.clientWidth, scrollXTrasIntento: window.scrollX };
  window.scrollTo(0, window.scrollY);
  padre.insertBefore(sec, ref);
  return out;
});

console.log(JSON.stringify({ ancho, ...r, sinCarrusel }, null, 2));
await browser.close();
