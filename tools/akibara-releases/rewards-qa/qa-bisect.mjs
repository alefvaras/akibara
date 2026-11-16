// Bisecta QUÉ propiedad del carrusel provoca el scroll horizontal de página.
// Aplica un parche CSS por vez y vuelve a medir documentElement.scrollWidth.
import { chromium } from 'playwright';

const ancho = Number(process.argv[2] || 375);
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: ancho, height: 812 } });
const page = await ctx.newPage();
await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
await page.waitForTimeout(300);

const pruebas = [
  ['(base, sin parche)', ''],
  ['track overflow-x:hidden', '.akb-rw-carousel .akb-rw-track{overflow-x:hidden !important}'],
  ['track sin scroll-snap', '.akb-rw-carousel .akb-rw-track{scroll-snap-type:none !important}'],
  ['cards sin scroll-snap-align', '.akb-rw-carousel .akb-rw-card{scroll-snap-align:none !important}'],
  ['section overflow:hidden', '.akb-rw-carousel{overflow:hidden !important}'],
  ['track max-width:100%', '.akb-rw-carousel .akb-rw-track{max-width:100% !important}'],
  ['track min-width:0', '.akb-rw-carousel .akb-rw-track{min-width:0 !important}'],
  ['cards flex-basis 200px', '.akb-rw-carousel .akb-rw-card{flex:0 0 200px !important}'],
  ['track display:block', '.akb-rw-carousel .akb-rw-track{display:block !important}'],
  ['track padding 0', '.akb-rw-carousel .akb-rw-track{padding:0 !important}'],
  ['track contain:paint', '.akb-rw-carousel .akb-rw-track{contain:paint !important}'],
];

const res = [];
for (const [nombre, css] of pruebas) {
  const r = await page.evaluate(async ({ css }) => {
    document.getElementById('qa-parche')?.remove();
    if (css) {
      const s = document.createElement('style');
      s.id = 'qa-parche'; s.textContent = css;
      document.head.appendChild(s);
    }
    await new Promise((r) => setTimeout(r, 200));
    const de = document.documentElement;
    window.scrollTo(9999, 0);
    await new Promise((r) => setTimeout(r, 200));
    const sx = window.scrollX;
    window.scrollTo(0, 0);
    const track = document.querySelector('.akb-rw-track');
    return {
      docScrollW: de.scrollWidth,
      clientW: de.clientWidth,
      scrollXReal: sx,
      trackClientW: track ? track.clientWidth : null,
      trackScrollW: track ? track.scrollWidth : null,
    };
  }, { css });
  res.push({ nombre, ...r });
}

console.log(`viewport=${ancho}`);
for (const r of res) {
  const veredicto = r.scrollXReal === 0 ? 'ARREGLA' : 'sigue mal';
  console.log(
    `${veredicto.padEnd(10)} | scrollX=${String(r.scrollXReal).padStart(5)} | docSW=${String(r.docScrollW).padStart(5)} | trackCW=${r.trackClientW} trackSW=${r.trackScrollW} | ${r.nombre}`
  );
}
await browser.close();
