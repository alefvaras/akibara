// QA VIVO del carrusel de reseñas (akibara-rewards / Reviews/Carousel.php).
// Mide la superficie SERVIDA y la INTERACCIÓN real. No confía en el CSS.
import { chromium } from 'playwright';

const OUT = '/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/d5a6502d-96ec-4b2f-bfd8-ffb8f9cd378c/scratchpad/carrusel';
const URL = 'http://localhost:8080/';
const etiqueta = process.argv[2] || 'run';
const soloMovil = process.argv[3] === 'movil';

const r = { etiqueta, desktop: null, movil: null };

async function medir(page, ancho) {
  return await page.evaluate(() => {
    const pagina = {
      docScrollW: document.documentElement.scrollWidth,
      docClientW: document.documentElement.clientWidth,
      desbordaH: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      // el culpable más ancho del body (para atribuir el desborde)
      culpable: (() => {
        const w = document.documentElement.clientWidth;
        let peor = null;
        for (const el of document.querySelectorAll('body *')) {
          const b = el.getBoundingClientRect();
          const der = b.right + window.scrollX;
          if (der > w + 1 && (!peor || der > peor.der)) {
            peor = { der: Math.round(der), tag: el.tagName, cls: (el.className || '').toString().slice(0, 80) };
          }
        }
        return peor;
      })(),
    };

    const sec = document.querySelector('.akb-rw-carousel');
    if (!sec) return { presente: false, pagina };

    const track = sec.querySelector('.akb-rw-track');
    const cards = [...sec.querySelectorAll('.akb-rw-card')];
    const h2 = sec.querySelector('h2');

    const cs = getComputedStyle(track);

    return {
      presente: true,
      titulo: h2 ? h2.textContent : null,
      nCards: cards.length,
      track: {
        scrollWidth: track.scrollWidth,
        clientWidth: track.clientWidth,
        desbordaH: track.scrollWidth > track.clientWidth + 1,
        overflowX: cs.overflowX,
        scrollSnapType: cs.scrollSnapType,
        gap: cs.gap,
      },
      cards: cards.map((c, i) => {
        const b = c.getBoundingClientRect();
        const img = c.querySelector('img.akb-rw-photo');
        const txt = c.querySelector('.akb-rw-text');
        const meta = c.querySelector('.akb-rw-meta');
        const link = c.querySelector('.akb-rw-product');
        const ib = img ? img.getBoundingClientRect() : null;
        return {
          i,
          alto: Math.round(b.height),
          ancho: Math.round(b.width),
          top: Math.round(b.top),
          conFoto: !!img,
          foto: img ? {
            boxW: Math.round(ib.width),
            boxH: Math.round(ib.height),
            naturalW: img.naturalWidth,
            naturalH: img.naturalHeight,
            complete: img.complete,
            currentSrc: img.currentSrc,
            objectFit: getComputedStyle(img).objectFit,
            alt: img.getAttribute('alt'),
          } : null,
          // ¿el texto se corta? scrollHeight > clientHeight con overflow oculto
          texto: txt ? {
            clientH: txt.clientHeight, scrollH: txt.scrollHeight,
            cortado: txt.scrollHeight > txt.clientHeight + 1,
            len: txt.textContent.length,
          } : null,
          autor: meta ? {
            texto: meta.textContent,
            clientW: meta.clientWidth, scrollW: meta.scrollWidth,
            desborda: meta.scrollWidth > meta.clientWidth + 1,
          } : null,
          producto: link ? {
            texto: link.textContent.trim(),
            href: link.getAttribute('href'),
            clientW: link.clientWidth, scrollW: link.scrollWidth,
            desborda: link.scrollWidth > link.clientWidth + 1,
          } : null,
          // ¿la card desborda su propia caja?
          desbordaCard: c.scrollHeight > c.clientHeight + 1 || c.scrollWidth > c.clientWidth + 1,
        };
      }),
      pagina,
    };
  });
}

async function interaccion(page) {
  return await page.evaluate(async () => {
    const track = document.querySelector('.akb-rw-track');
    if (!track) return { ok: false, motivo: 'sin track' };
    const antes = track.scrollLeft;
    const max = track.scrollWidth - track.clientWidth;
    track.scrollLeft = max; // scroll real, no CSS
    await new Promise((res) => setTimeout(res, 350));
    const despues = track.scrollLeft;
    track.scrollLeft = 0;
    await new Promise((res) => setTimeout(res, 250));
    const vuelta = track.scrollLeft;
    return {
      ok: despues > antes,
      antes, despues, vuelta, maxScroll: max,
      // ¿hay flechas/botones dentro del carrusel?
      botones: [...document.querySelectorAll('.akb-rw-carousel button, .akb-rw-carousel [role="button"]')].length,
      scripts: [...document.querySelectorAll('.akb-rw-carousel script')].length,
    };
  });
}

const browser = await chromium.launch();

if (!soloMovil) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 950 } });
  const page = await ctx.newPage();
  await page.goto(URL, { waitUntil: 'networkidle' });
  const sec = await page.$('.akb-rw-carousel');
  if (sec) {
    await sec.scrollIntoViewIfNeeded();
    await page.waitForTimeout(600);
  }
  r.desktop = await medir(page, 1440);
  if (r.desktop.presente) {
    r.desktop.interaccion = await interaccion(page);
    await sec.screenshot({ path: `${OUT}/${etiqueta}-desktop-1440.png` });
  } else {
    await page.screenshot({ path: `${OUT}/${etiqueta}-desktop-1440.png` });
  }
  await ctx.close();
}

{
  const ctx = await browser.newContext({
    viewport: { width: 375, height: 812 },
    isMobile: true, hasTouch: true, deviceScaleFactor: 2,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
  });
  const page = await ctx.newPage();
  await page.goto(URL, { waitUntil: 'networkidle' });
  const sec = await page.$('.akb-rw-carousel');
  if (sec) {
    await sec.scrollIntoViewIfNeeded();
    await page.waitForTimeout(600);
  }
  r.movil = await medir(page, 375);
  if (r.movil.presente) {
    r.movil.interaccion = await interaccion(page);
    await sec.screenshot({ path: `${OUT}/${etiqueta}-movil-375.png` });
  } else {
    await page.screenshot({ path: `${OUT}/${etiqueta}-movil-375.png` });
  }
  await ctx.close();
}

await browser.close();
console.log(JSON.stringify(r, null, 2));
