/**
 * ¿Cuánto texto cabe DE VERDAD en la barra de anuncios en móvil?
 *
 * El dueño reportó (2026-09-02, captura) que el mensaje nuevo se corta:
 * «RETIRO SIN COSTO EN METRO SAN MIGUEL, CON DÍA Y HORA COORDIN…». Se mide el
 * ancho real del contenedor y se calcula cuántos caracteres entran con la
 * tipografía servida, en vez de adivinar.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'https://akibara.cl';
const b = await chromium.launch();

for (const [nombre, viewport] of Object.entries({
  'móvil 360': { width: 360, height: 800 },
  'móvil 375': { width: 375, height: 812 },
  'móvil 414': { width: 414, height: 896 },
})) {
  const ctx = await b.newContext({ userAgent: 'Mozilla/5.0 AkibaraQA/1.0 banner', viewport, isMobile: true, hasTouch: true });
  const p = await ctx.newPage();
  await p.goto(BASE, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1200);

  const datos = await p.evaluate(() => {
    const msgs = Array.from(document.querySelectorAll('.akb-ann-msg'));
    if (!msgs.length) return null;
    const inner = msgs.map(m => m.querySelector('.akb-ann-msg-inner') || m);
    return inner.map(el => {
      const cs = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      const padre = el.closest('.akb-ann-msg')?.getBoundingClientRect();
      return {
        texto: (el.textContent || '').trim(),
        chars: (el.textContent || '').trim().length,
        anchoTexto: Math.round(el.scrollWidth),
        anchoCaja: Math.round(padre ? padre.width : r.width),
        seCorta: el.scrollWidth > Math.ceil(padre ? padre.width : r.width) + 1,
        fuente: `${cs.fontSize} ${cs.fontFamily.split(',')[0]} ${cs.letterSpacing}`,
      };
    });
  });

  console.log(`\n== ${nombre} (${viewport.width}px)`);
  if (!datos) { console.log('   (no encontré la barra)'); continue; }
  for (const d of datos) {
    const porChar = d.chars ? d.anchoTexto / d.chars : 0;
    const caben = porChar ? Math.floor(d.anchoCaja / porChar) : 0;
    console.log(`   "${d.texto.slice(0, 70)}"`);
    console.log(`      ${d.chars} chars · texto ${d.anchoTexto}px · caja ${d.anchoCaja}px · ${d.seCorta ? '⚠ SE CORTA' : 'entra'} · caben ~${caben} chars`);
  }
  await ctx.close();
}
await b.close();
