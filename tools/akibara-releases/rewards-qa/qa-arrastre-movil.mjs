// Arrastre táctil REAL en móvil usando CDP Input.synthesizeScrollGesture,
// que sí atraviesa el compositor (los TouchEvent sintéticos no lo hacen).
import { chromium } from 'playwright';

const browser = await chromium.launch();
// Ancho de móvil pero SIN isMobile: la emulación móvil de Chrome desalinea
// el espacio de coordenadas de CDP (window.innerWidth reportaba 1500 con
// viewport 375). El CSS del carrusel depende solo del ancho, así que a
// 375px se ejerce exactamente la misma regla; el gesto sigue siendo 'touch'.
const ctx = await browser.newContext({
  viewport: { width: 375, height: 812 }, hasTouch: true,
});
const page = await ctx.newPage();
const cdp = await ctx.newCDPSession(page);
await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
const sec = await page.$('.akb-rw-carousel');
await sec.scrollIntoViewIfNeeded();
await page.waitForTimeout(600);

// centra la pista en la ventana y toma un punto SEGURO dentro del viewport
await page.evaluate(() => {
  const t = document.querySelector('.akb-rw-track');
  const b = t.getBoundingClientRect();
  window.scrollBy(0, b.top - (window.innerHeight / 2 - b.height / 2));
});
await page.waitForTimeout(500);

const caja = await page.$eval('.akb-rw-track', (t) => {
  const b = t.getBoundingClientRect();
  const cx = Math.min(Math.max(Math.round(b.x + b.width / 2), 10), window.innerWidth - 10);
  const cy = Math.min(Math.max(Math.round(b.y + b.height / 2), 10), window.innerHeight - 10);
  return { cx, cy, rect: { x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) }, vp: { w: window.innerWidth, h: window.innerHeight } };
});
console.error('punto de gesto:', JSON.stringify(caja));

const leer = () => page.$eval('.akb-rw-track', (t) => ({ sl: Math.round(t.scrollLeft), max: t.scrollWidth - t.clientWidth }));
const antes = await leer();

// gesto táctil real: deslizar 300px hacia la izquierda
await cdp.send('Input.synthesizeScrollGesture', {
  x: caja.cx, y: caja.cy,
  xDistance: -300, yDistance: 0,
  gestureSourceType: 'touch', speed: 800,
});
await page.waitForTimeout(1200);
const trasArrastre = await leer();

// segundo deslizamiento
await cdp.send('Input.synthesizeScrollGesture', {
  x: caja.cx, y: caja.cy, xDistance: -600, yDistance: 0, gestureSourceType: 'touch', speed: 800,
});
await page.waitForTimeout(1200);
const trasSegundo = await leer();

// ¿el snap dejó una card alineada?
const snap = await page.evaluate(() => {
  const t = document.querySelector('.akb-rw-track');
  const offs = [...t.querySelectorAll('.akb-rw-card')].map((c) => Math.round(c.offsetLeft - t.offsetLeft));
  const sl = Math.round(t.scrollLeft);
  const cerca = offs.reduce((a, b) => (Math.abs(b - sl) < Math.abs(a - sl) ? b : a));
  return { sl, offs, cerca, desalineacion: Math.abs(sl - cerca) };
});

// ¿arrastrar la pista mueve la PÁGINA en vertical por error?
const scrollYAntes = await page.evaluate(() => window.scrollY);
await cdp.send('Input.synthesizeScrollGesture', { x: caja.cx, y: caja.cy, xDistance: -200, yDistance: 0, gestureSourceType: 'touch', speed: 800 });
await page.waitForTimeout(800);
const scrollYDespues = await page.evaluate(() => window.scrollY);

console.log(JSON.stringify({
  antes, trasArrastre, trasSegundo,
  arrastreFunciona: trasArrastre.sl > antes.sl,
  segundoFunciona: trasSegundo.sl > trasArrastre.sl,
  snap,
  paginaNoSaltaEnVertical: scrollYAntes === scrollYDespues,
  scrollYAntes, scrollYDespues,
}, null, 2));

await page.screenshot({ path: '/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/d5a6502d-96ec-4b2f-bfd8-ffb8f9cd378c/scratchpad/carrusel/caso8-movil-tras-arrastre.png' });
await browser.close();
