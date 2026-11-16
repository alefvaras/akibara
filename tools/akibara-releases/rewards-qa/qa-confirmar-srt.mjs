// Confirma que el <span class="screen-reader-text"> de cada card es el que
// arrastra el scroll horizontal de página, y prueba el arreglo.
import { chromium } from 'playwright';

const ancho = Number(process.argv[2] || 375);
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: ancho, height: 812 } });
const page = await ctx.newPage();
await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
await page.waitForTimeout(300);

const estilo = await page.evaluate(() => {
  const s = document.querySelector('.akb-rw-carousel .screen-reader-text');
  const cs = getComputedStyle(s);
  const b = s.getBoundingClientRect();
  const card = s.closest('.akb-rw-card');
  return {
    css: { position: cs.position, clip: cs.clip, clipPath: cs.clipPath, width: cs.width, height: cs.height, left: cs.left, top: cs.top, overflow: cs.overflow },
    rect: { left: Math.round(b.left + window.scrollX), right: Math.round(b.right + window.scrollX), w: Math.round(b.width) },
    cardPosition: getComputedStyle(card).position,
    trackPosition: getComputedStyle(document.querySelector('.akb-rw-track')).position,
    // ¿cuál es su bloque contenedor? el ancestro posicionado más cercano
    ancestroPosicionado: (() => {
      let p = s.parentElement, cadena = [];
      while (p) {
        const pos = getComputedStyle(p).position;
        cadena.push(`${p.tagName}.${(p.className||'').toString().split(' ')[0]}=${pos}`);
        if (pos !== 'static') return { encontrado: `${p.tagName}.${(p.className||'').toString().split(' ')[0]}`, cadena: cadena.join(' < ') };
        p = p.parentElement;
      }
      return { encontrado: 'NINGUNO -> bloque contenedor inicial (ICB)', cadena: cadena.slice(0,8).join(' < ') };
    })(),
  };
});

const pruebas = [
  ['base', ''],
  ['ocultar los screen-reader-text', '.akb-rw-carousel .screen-reader-text{display:none !important}'],
  ['ARREGLO A: card position:relative', '.akb-rw-carousel .akb-rw-card{position:relative !important}'],
  ['ARREGLO B: track position:relative', '.akb-rw-carousel .akb-rw-track{position:relative !important}'],
];

const res = [];
for (const [nombre, css] of pruebas) {
  const r = await page.evaluate(async ({ css }) => {
    document.getElementById('qa-parche')?.remove();
    if (css) { const s = document.createElement('style'); s.id='qa-parche'; s.textContent=css; document.head.appendChild(s); }
    await new Promise((r) => setTimeout(r, 200));
    window.scrollTo(9999, 0);
    await new Promise((r) => setTimeout(r, 250));
    const sx = window.scrollX;
    window.scrollTo(0, 0);
    const t = document.querySelector('.akb-rw-track');
    return { scrollXPagina: sx, docScrollW: document.documentElement.scrollWidth, trackScrollW: t.scrollWidth, trackClientW: t.clientWidth };
  }, { css });
  res.push({ nombre, ...r });
}

console.log(`viewport=${ancho}`);
console.log(JSON.stringify(estilo, null, 2));
console.log('');
for (const r of res) {
  console.log(`${(r.scrollXPagina === 0 ? 'SIN SCROLL PAGINA' : 'SCROLL PAGINA ' + r.scrollXPagina).padEnd(22)} | docSW=${String(r.docScrollW).padStart(5)} | track ${r.trackClientW}/${r.trackScrollW} (sigue deslizando: ${r.trackScrollW > r.trackClientW}) | ${r.nombre}`);
}
await browser.close();
