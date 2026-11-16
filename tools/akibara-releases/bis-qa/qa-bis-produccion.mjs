// QA integral de «Avísame cuando llegue» en PRODUCCIÓN, superficie pública.
//
// Se prueba lo que ve el cliente, con gestos reales y en el navegador de verdad:
// que el formulario esté donde debe en una ficha agotada, que NO aparezca en una
// disponible, que sea usable con el pulgar en móvil, que se pueda operar sólo con
// teclado, y que la consola no escupa errores.
//
// Marca todas las peticiones con AkibaraQA para que el qlog no las confunda con
// clientes reales.
//
//   node tools/qa-bis-produccion.mjs

import { chromium } from 'playwright';

const UA_QA = 'AkibaraQA/1.0 (Playwright; QA de release business 1.114.2)';

const AGOTADAS = [
  ['Hunter X Hunter 01', 'https://akibara.cl/hunter-x-hunter-1-ivrea-argentina/'],
  ['Nana 01', 'https://akibara.cl/nana-1-ivrea-argentina/'],
  ['Frieren 09', 'https://akibara.cl/frieren-9-ivrea-argentina/'],
];
const DISPONIBLE = ['Dan Da Dan 20', 'https://akibara.cl/dan-da-dan-20-ivrea-argentina/'];

let fallos = 0;
let corridas = 0;
const marcar = (caso, ok, detalle = '') => {
  corridas++;
  if (!ok) fallos++;
  console.log(`  ${ok ? 'OK   ' : 'FALLA'} ${caso}${detalle ? ` — ${detalle}` : ''}`);
};

const browser = await chromium.launch();

async function nuevaPagina(movil = false) {
  const ctx = await browser.newContext({
    userAgent: UA_QA,
    ...(movil
      ? { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }
      : { viewport: { width: 1440, height: 900 } }),
  });
  const page = await ctx.newPage();
  const errores = [];
  page.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });
  page.on('pageerror', (e) => errores.push(`pageerror: ${e.message}`));
  return { ctx, page, errores };
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('\n══ 1 · El formulario está donde el cliente lo necesita (escritorio)');

for (const [nombre, url] of AGOTADAS) {
  const { ctx, page, errores } = await nuevaPagina(false);
  const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
  marcar(`${nombre}: la ficha carga`, resp.status() === 200, `HTTP ${resp.status()}`);

  const form = await page.$('.akb-bis, form[class*="akb-bis"], [class*="akb-bis__form"]');
  marcar(`${nombre}: aparece el formulario de aviso`, !!form);

  if (form) {
    const email = await page.$('.akb-bis input[type="email"], form[class*="akb-bis"] input[type="email"]');
    const boton = await page.$('.akb-bis button, .akb-bis__submit, form[class*="akb-bis"] button');
    marcar(`${nombre}: tiene campo de email`, !!email);
    marcar(`${nombre}: tiene botón de envío`, !!boton);

    // El campo debe estar etiquetado para lector de pantalla.
    if (email) {
      const etiqueta = await email.evaluate((el) => {
        const id = el.id;
        const lab = id ? document.querySelector(`label[for="${CSS.escape(id)}"]`) : null;
        return (lab && lab.textContent.trim()) || el.getAttribute('aria-label') || el.getAttribute('placeholder') || '';
      });
      marcar(`${nombre}: el campo está etiquetado`, etiqueta.length > 0, etiqueta.slice(0, 46));
    }
  }

  marcar(`${nombre}: consola sin errores`, errores.length === 0, errores.slice(0, 2).join(' | '));
  await ctx.close();
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('\n══ 2 · En una ficha DISPONIBLE no debe aparecer');
{
  const [nombre, url] = DISPONIBLE;
  const { ctx, page } = await nuevaPagina(false);
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
  const form = await page.$('.akb-bis, form[class*="akb-bis"]');
  const visible = form ? await form.isVisible() : false;
  marcar(`${nombre}: NO ofrece «avísame» estando disponible`, !visible);
  const carrito = await page.$('button[name="add-to-cart"], .single_add_to_cart_button, form.cart button');
  marcar(`${nombre}: sí ofrece comprar`, !!carrito);
  await ctx.close();
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('\n══ 3 · Móvil: el pulgar llega y nada desborda');
{
  const [nombre, url] = AGOTADAS[0];
  const { ctx, page, errores } = await nuevaPagina(true);
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });

  const desborde = await page.evaluate(() =>
    document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  marcar(`${nombre} (móvil): la página no se va de lado`, !desborde);

  const form = await page.$('.akb-bis, form[class*="akb-bis"]');
  if (form) {
    await form.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);

    const medidas = await page.evaluate(() => {
      const raiz = document.querySelector('.akb-bis, form[class*="akb-bis"]');
      const input = raiz.querySelector('input[type="email"]');
      const btn = raiz.querySelector('button');
      const caja = (el) => { const b = el.getBoundingClientRect(); return { w: Math.round(b.width), h: Math.round(b.height), x: Math.round(b.x), der: Math.round(b.right) }; };
      return {
        input: input ? caja(input) : null,
        boton: btn ? caja(btn) : null,
        ancho: document.documentElement.clientWidth,
      };
    });

    if (medidas.boton) {
      marcar(`${nombre} (móvil): el botón alcanza el mínimo táctil de 44px`, medidas.boton.h >= 44, `${medidas.boton.w}x${medidas.boton.h}`);
      marcar(`${nombre} (móvil): el botón cabe en pantalla`, medidas.boton.der <= medidas.ancho, `derecha ${medidas.boton.der} de ${medidas.ancho}`);
    }
    if (medidas.input) {
      marcar(`${nombre} (móvil): el campo alcanza el mínimo táctil`, medidas.input.h >= 44, `${medidas.input.w}x${medidas.input.h}`);
    }

    // Escribir de verdad con el teclado móvil, sin enviar.
    const input = await page.$('.akb-bis input[type="email"], form[class*="akb-bis"] input[type="email"]');
    if (input) {
      await input.tap();
      await input.type('qa.playwright@akibara.cl', { delay: 20 });
      const valor = await input.inputValue();
      marcar(`${nombre} (móvil): se puede escribir el correo`, valor === 'qa.playwright@akibara.cl', valor);
      const tipo = await input.getAttribute('type');
      marcar(`${nombre} (móvil): teclado de email (type=email)`, tipo === 'email', `type=${tipo}`);
    }
  }
  marcar(`${nombre} (móvil): consola sin errores`, errores.length === 0, errores.slice(0, 2).join(' | '));
  await ctx.close();
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('\n══ 4 · Sólo con teclado (accesibilidad)');
{
  const [nombre, url] = AGOTADAS[0];
  const { ctx, page } = await nuevaPagina(false);
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });

  const input = await page.$('.akb-bis input[type="email"], form[class*="akb-bis"] input[type="email"]');
  if (input) {
    await input.focus();
    const enfocado = await page.evaluate(() => document.activeElement?.type === 'email');
    marcar(`${nombre}: el campo se puede enfocar`, enfocado);

    // El anillo de foco debe verse: se mide DESPUÉS de que asienten transiciones.
    await page.waitForTimeout(350);
    const foco = await page.evaluate(() => {
      const el = document.activeElement;
      const cs = getComputedStyle(el);
      return { outline: cs.outlineStyle, ancho: cs.outlineWidth, sombra: cs.boxShadow };
    });
    const seVe = (foco.outline !== 'none' && parseFloat(foco.ancho) > 0) || (foco.sombra && foco.sombra !== 'none');
    marcar(`${nombre}: el foco se ve`, seVe, `outline:${foco.outline} ${foco.ancho} · shadow:${String(foco.sombra).slice(0, 30)}`);

    // Tab debe llevar al botón, no perderse.
    await page.keyboard.press('Tab');
    const siguiente = await page.evaluate(() => {
      const el = document.activeElement;
      return { etiqueta: el.tagName, texto: (el.textContent || el.value || '').trim().slice(0, 30) };
    });
    marcar(`${nombre}: Tab lleva al botón de envío`, siguiente.etiqueta === 'BUTTON' || siguiente.etiqueta === 'INPUT', `${siguiente.etiqueta} «${siguiente.texto}»`);
  }
  await ctx.close();
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('\n══ 5 · Rutas críticas de la tienda (que el release no rompió nada)');
{
  const { ctx, page } = await nuevaPagina(false);
  for (const [nombre, ruta] of [
    ['portada', '/'],
    ['tienda', '/tienda/'],
    ['series', '/series/'],
    ['carrito', '/carrito/'],
    ['checkout', '/checkout/'],
    ['mi cuenta', '/mi-cuenta/'],
    ['a pedido', '/a-pedido/'],
  ]) {
    try {
      const r = await page.goto(`https://akibara.cl${ruta}`, { waitUntil: 'domcontentloaded', timeout: 45000 });
      const titulo = await page.title();
      marcar(`${nombre} responde`, r.status() === 200, `HTTP ${r.status()} · «${titulo.slice(0, 44)}»`);
    } catch (e) {
      marcar(`${nombre} responde`, false, e.message.slice(0, 60));
    }
  }
  await ctx.close();
}

await browser.close();
console.log(`\n${fallos === 0 ? 'TODO EN VERDE' : `${fallos} FALLOS`}  ${corridas - fallos}/${corridas} comprobaciones\n`);
process.exit(fallos === 0 ? 0 : 1);
