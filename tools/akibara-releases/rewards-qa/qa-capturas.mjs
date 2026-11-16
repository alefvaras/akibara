// Capturas fiables del carrusel. Evita isMobile (desalinea coordenadas en
// esta versión de Chrome) y usa capturas de VIEWPORT, no de elemento.
import { chromium } from 'playwright';

const OUT = '/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/d5a6502d-96ec-4b2f-bfd8-ffb8f9cd378c/scratchpad/carrusel';
const browser = await chromium.launch();

async function abrir(w, h) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: true, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
  await page.evaluate(() => {
    const s = document.querySelector('.akb-rw-carousel');
    const b = s.getBoundingClientRect();
    // 130px de margen: la cabecera pegajosa mide ~104 en escritorio y ~73 en móvil
    window.scrollBy(0, b.top - 130);
  });
  await page.waitForTimeout(800);
  return { ctx, page };
}

// 1) Carrusel en escritorio 1440
{
  const { ctx, page } = await abrir(1440, 900);
  await page.screenshot({ path: `${OUT}/01-carrusel-escritorio-1440.png` });
  await ctx.close();
}

// 2) Carrusel en móvil 375
{
  const { ctx, page } = await abrir(375, 812);
  await page.screenshot({ path: `${OUT}/02-carrusel-movil-375.png` });
  // 2b) tras arrastrar hasta la card con foto
  await page.evaluate(() => { document.querySelector('.akb-rw-track').scrollLeft = 1113; });
  await page.waitForTimeout(600);
  await page.screenshot({ path: `${OUT}/03-carrusel-movil-375-card-con-foto.png` });
  await ctx.close();
}

// 3) DEFECTO: scroll horizontal de página en móvil — se ve el hueco en blanco
{
  const { ctx, page } = await abrir(375, 812);
  await page.evaluate(() => window.scrollTo(600, window.scrollY));
  await page.waitForTimeout(600);
  const sx = await page.evaluate(() => window.scrollX);
  await page.screenshot({ path: `${OUT}/04-DEFECTO-scroll-horizontal-movil.png` });
  console.log('captura 04: la página se desplazó', sx, 'px a la derecha (debería ser 0)');
  await ctx.close();
}

// 4) DEFECTO: hueco vertical de las cards sin foto (se marca en rojo)
{
  const { ctx, page } = await abrir(1440, 900);
  await page.evaluate(() => {
    for (const c of document.querySelectorAll('.akb-rw-card')) {
      const hijos = [...c.children].filter((h) => h.getBoundingClientRect().height > 0);
      const ult = hijos[hijos.length - 1].getBoundingClientRect();
      const cb = c.getBoundingClientRect();
      const alto = Math.round(cb.bottom - ult.bottom);
      if (alto < 40) continue;
      const marca = document.createElement('div');
      marca.style.cssText = `position:absolute;left:${cb.left + window.scrollX}px;top:${ult.bottom + window.scrollY}px;width:${cb.width}px;height:${alto}px;background:rgba(255,0,0,.28);outline:1px dashed #f00;z-index:9999;color:#fff;font:700 12px system-ui;display:flex;align-items:center;justify-content:center`;
      marca.textContent = `${alto}px vacíos`;
      document.body.appendChild(marca);
    }
  });
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${OUT}/05-DEFECTO-hueco-cards-sin-foto.png` });
  await ctx.close();
}

await browser.close();
console.log('capturas listas');
