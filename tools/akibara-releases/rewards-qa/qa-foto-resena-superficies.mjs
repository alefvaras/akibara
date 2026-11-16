// QA Playwright — la foto de la reseña se ve en la ficha del tomo Y en
// moderación (decisión del dueño 2026-09-13), con sus casos de borde.
//
// Uso:
//   AKB_SIEMBRA='<json de sembrar-fotos-resena.php>' node qa-foto-resena-superficies.mjs
// Variables: AKB_BASE (defecto http://localhost:8080), AKB_USER / AKB_PASS
// (administrador del espejo; en producción NO se usa login: sólo la ficha).
//
// Sale con código 1 si falla cualquier comprobación.
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const SIEMBRA = JSON.parse(process.env.AKB_SIEMBRA || '{}');
const SOLO_FICHA = process.env.AKB_SOLO_FICHA === '1';
const UA = 'Mozilla/5.0 (AkibaraQA) Playwright';
const nombreFichero = (u) => (u ? u.split('/').pop().replace(/-\d+x\d+(?=\.\w+$)/, '') : null);
const FOTOS = [nombreFichero(SIEMBRA.adjunto_large)?.replace(/\.\w+$/, '')];

if (!SIEMBRA.producto_url) {
  console.error('Falta AKB_SIEMBRA con producto_url (salida de sembrar-fotos-resena.php).');
  process.exit(2);
}

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato)}`}`);
};

const nav = await chromium.launch();

// ── Ficha del tomo, como cliente invitado, en escritorio y móvil ──────────
for (const [nombre, vp] of [['escritorio', { width: 1440, height: 950 }], ['movil', { width: 375, height: 812 }]]) {
  console.log(`Ficha · ${nombre}`);
  const ctx = await nav.newContext({ viewport: vp, deviceScaleFactor: 2, userAgent: UA });
  const page = await ctx.newPage();
  const rotas = [];
  const ajenas = [];
  const erroresJs = [];
  page.on('response', (r) => {
    if (r.status() < 400) return;
    const linea = `${r.status()} ${r.url()}`;
    // Sólo cuenta como fallo la foto de la reseña; otro asset roto se avisa
    // aparte (el espejo no trae todos los tamaños de uploads de producción).
    if (FOTOS.some((f) => f && r.url().includes(f))) rotas.push(linea); else ajenas.push(linea);
  });
  page.on('requestfailed', (q) => { if (FOTOS.some((f) => f && q.url().includes(f))) rotas.push(`falló ${q.url()} (${q.failure()?.errorText})`); });
  page.on('pageerror', (e) => erroresJs.push(String(e).slice(0, 160)));

  const url = new URL(SIEMBRA.producto_url);
  const resp = await page.goto(`${BASE}${url.pathname}`, { waitUntil: 'networkidle', timeout: 90000 });
  ok(resp && resp.status() === 200, 'la ficha responde 200', resp && resp.status());

  // Abrir la pestaña de reseñas si está plegada.
  const tab = page.locator('a[href="#tab-reviews"], #tab-title-reviews a').first();
  if (await tab.count()) { await tab.click().catch(() => {}); }
  await page.waitForTimeout(500);

  const datos = await page.evaluate(async () => {
    const porMarca = (marca) => [...document.querySelectorAll('.comment_container, li.review, li.comment, .comment-text')]
      .find((n) => n.textContent.includes(marca))?.closest('li') || null;
    const caso = async (marca) => {
      const li = porMarca(marca);
      if (!li) return { existe: false };
      const fig = li.querySelector('figure.akb-rw-foto');
      const img = fig?.querySelector('img');
      if (img) {
        img.scrollIntoView({ block: 'center' });
        img.loading = 'eager';
        const src = img.getAttribute('data-src');
        if (src && !img.src.endsWith(src)) img.src = src; // lazyload de LiteSpeed
        await new Promise((r) => { if (img.complete && img.naturalWidth) r(); else { img.onload = r; img.onerror = r; setTimeout(r, 8000); } });
      }
      const caja = img?.getBoundingClientRect();
      const liCaja = li.getBoundingClientRect();
      return {
        existe: true,
        figuras: li.querySelectorAll('figure.akb-rw-foto').length,
        enlace: !!fig?.querySelector('a'),
        cargada: !!img && img.complete && img.naturalWidth > 0,
        src: img ? (img.currentSrc || img.src) : null,
        alt: img?.getAttribute('alt') ?? null,
        ancho: caja ? Math.round(caja.width) : null,
        alto: caja ? Math.round(caja.height) : null,
        sobresale: caja ? Math.round(caja.right - liCaja.right) : null,
        ordenTextoAntes: fig ? !!(li.querySelector('.description, .comment-text p')?.compareDocumentPosition(fig) & Node.DOCUMENT_POSITION_FOLLOWING) : null,
      };
    };
    return {
      local: await caso('QA-LOCAL'),
      remota: await caso('QA-REMOTA'),
      huerfana: await caso('QA-HUERFANA'),
      sinFoto: await caso('QA-SIN-FOTO'),
      pendienteVisible: document.body.innerText.includes('QA-PENDIENTE'),
      desbordeHorizontal: document.documentElement.scrollWidth - window.innerWidth,
      jsonLdConFoto: [...document.querySelectorAll('script[type="application/ld+json"]')].some((s) => s.textContent.includes('akb-rw-foto')),
    };
  });

  ok(datos.local.existe, 'la reseña con foto local aparece en la ficha', datos.local);
  ok(datos.local.figuras === 1, 'lleva exactamente una figura (sin doble pintado)', datos.local.figuras);
  ok(datos.local.cargada, 'la foto local carga de verdad (naturalWidth > 0)', datos.local);
  ok(!datos.local.enlace, 'en la ficha la foto NO enlaza a la biblioteca', datos.local.enlace);
  ok((datos.local.alt || '').includes('Foto de la reseña de QA Foto Reseña'), 'texto alternativo con el autor', datos.local.alt);
  ok(datos.local.ancho > 0 && datos.local.ancho <= 240, 'ancho de lectura acotado a 240px', datos.local.ancho);
  ok(datos.local.sobresale <= 0, 'la foto no se sale de la reseña', datos.local.sobresale);
  ok(datos.local.ordenTextoAntes !== false, 'la foto va después del texto', datos.local.ordenTextoAntes);

  ok(datos.remota.figuras === 1, 'la foto remota se pinta', datos.remota);
  ok(BASE.startsWith('https://') ? (datos.remota.src || '').startsWith('https://') : true, 'en un sitio https la remota se sirve por https', datos.remota.src);
  ok(datos.remota.cargada, 'la foto remota carga', datos.remota);

  ok(datos.huerfana.existe && datos.huerfana.figuras === 0, 'meta huérfana: sin figura ni hueco', datos.huerfana);
  ok(datos.sinFoto.existe && datos.sinFoto.figuras === 0, 'reseña sin foto: igual que antes', datos.sinFoto);
  ok(!datos.pendienteVisible, 'la reseña pendiente NO se ve en la ficha', datos.pendienteVisible);
  ok(datos.desbordeHorizontal <= 0, 'la ficha no desborda en horizontal', datos.desbordeHorizontal);
  ok(!datos.jsonLdConFoto, 'la figura no se cuela en el JSON-LD', datos.jsonLdConFoto);
  ok(rotas.length === 0, 'ninguna foto de reseña da 4xx/5xx ni falla la descarga', rotas);
  ok(erroresJs.length === 0, 'sin excepciones de JavaScript', erroresJs);
  if (ajenas.length) console.log(`  aviso  recursos ajenos a la reseña con error (no cuentan): ${ajenas.join(' · ')}`);

  await page.screenshot({ path: `/tmp/qa-foto-resena-ficha-${nombre}.png`, fullPage: false });
  await ctx.close();
}

// ── Comentario del blog: la meta de foto no pinta nada ────────────────────
if (SIEMBRA.blog_url) {
  console.log('Blog · comentario con meta de foto');
  const ctx = await nav.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA });
  const page = await ctx.newPage();
  await page.goto(`${BASE}${new URL(SIEMBRA.blog_url).pathname}`, { waitUntil: 'networkidle', timeout: 90000 });
  const blog = await page.evaluate(() => ({
    visto: document.body.innerText.includes('QA-BLOG'),
    figuras: document.querySelectorAll('figure.akb-rw-foto').length,
  }));
  ok(!blog.visto || blog.figuras === 0, 'un comentario del blog no lleva la figura', blog);
  await ctx.close();
}

// ── Moderación, con sesión de administrador (sólo espejo) ─────────────────
if (!SOLO_FICHA) {
  console.log('Moderación · escritorio del dueño');
  const ctx = await nav.newContext({ viewport: { width: 1440, height: 950 }, userAgent: UA });
  const page = await ctx.newPage();
  const erroresConsola = [];
  page.on('pageerror', (e) => erroresConsola.push(String(e).slice(0, 160)));
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load', timeout: 90000 });
  await page.fill('#user_login', process.env.AKB_USER || 'qa.owner');
  await page.fill('#user_pass', process.env.AKB_PASS || 'AkbQaOwner2026!');
  await Promise.all([page.waitForURL(/\/wp-admin\//, { timeout: 90000 }).catch(() => {}), page.click('#wp-submit')]);
  await page.waitForLoadState('load');
  ok(await page.locator('#wpadminbar').count() > 0, 'sesión de administrador abierta', page.url());

  // WooCommerce 11 excluye las reseñas de Comentarios (ReviewsCommentsOverrides):
  // la pantalla donde el dueño aprueba es Productos › Reseñas.
  await page.goto(`${BASE}/wp-admin/edit-comments.php?comment_status=moderated`, { waitUntil: 'load', timeout: 90000 });
  ok(!(await page.evaluate(() => document.body.innerText.includes('QA-PENDIENTE'))), 'Comentarios no lista reseñas (WooCommerce las lleva a Productos › Reseñas)');

  for (const [pantalla, ruta] of [
    ['Productos › Reseñas (pendientes)', '/wp-admin/edit.php?post_type=product&page=product-reviews&comment_status=moderated'],
    ['Productos › Reseñas (todas)', '/wp-admin/edit.php?post_type=product&page=product-reviews'],
  ]) {
    const resp = await page.goto(`${BASE}${ruta}`, { waitUntil: 'load', timeout: 90000 });
    ok(resp && resp.status() === 200, `${pantalla} responde 200`, resp && resp.status());
    const fila = await page.evaluate(async (idPendiente) => {
      const tr = document.querySelector(`#comment-${idPendiente}`) || [...document.querySelectorAll('tr')].find((t) => t.textContent.includes('QA-PENDIENTE'));
      if (!tr) return { existe: false };
      const a = tr.querySelector('figure.akb-rw-foto a');
      const img = tr.querySelector('figure.akb-rw-foto img');
      if (img) await new Promise((r) => { if (img.complete && img.naturalWidth) r(); else { img.onload = r; img.onerror = r; setTimeout(r, 8000); } });
      const caja = img?.getBoundingClientRect();
      return {
        existe: true,
        figuras: tr.querySelectorAll('figure.akb-rw-foto').length,
        href: a?.getAttribute('href') ?? null,
        target: a?.getAttribute('target') ?? null,
        rel: a?.getAttribute('rel') ?? null,
        src: img?.getAttribute('src') ?? null,
        cargada: !!img && img.complete && img.naturalWidth > 0,
        ancho: caja ? Math.round(caja.width) : null,
      };
    }, SIEMBRA.ids?.pendiente);
    ok(fila.existe, `${pantalla}: la reseña pendiente está en la lista`, fila);
    ok(fila.figuras === 1, `${pantalla}: exactamente una miniatura`, fila.figuras);
    ok(fila.cargada, `${pantalla}: la miniatura carga`, fila);
    ok(fila.ancho > 0 && fila.ancho <= 96, `${pantalla}: miniatura de 96px como máximo`, fila.ancho);
    ok(fila.href === SIEMBRA.adjunto_large, `${pantalla}: enlaza a la foto completa`, { href: fila.href, esperado: SIEMBRA.adjunto_large });
    ok(fila.target === '_blank' && (fila.rel || '').includes('noopener'), `${pantalla}: abre en pestaña nueva con noopener`, fila);
    await page.screenshot({ path: `/tmp/qa-foto-resena-moderacion-${pantalla.replace(/\W+/g, '-')}.png` });
  }
  // El aviso tras crear una reseña tiene que llevar a donde la reseña SÍ está.
  await page.goto(`${BASE}/wp-admin/edit-comments.php?page=akb-rewards-new-review&akb_rw_created=${SIEMBRA.ids?.local}`, { waitUntil: 'load', timeout: 90000 });
  const enlaceAviso = page.locator('.notice-success a').first();
  ok(await enlaceAviso.count() === 1, 'el aviso de reseña creada trae un enlace');
  if (await enlaceAviso.count()) {
    await Promise.all([page.waitForLoadState('load'), enlaceAviso.click()]);
    ok(await page.evaluate(() => document.body.innerText.includes('QA-LOCAL')), 'el enlace del aviso lleva a una lista donde está la reseña', page.url());
  }

  ok(erroresConsola.length === 0, 'moderación sin errores de JavaScript', erroresConsola);
  await ctx.close();
}

await nav.close();

if (fallos) {
  console.error(`FAIL: ${fallos} comprobación(es) rotas`);
  process.exit(1);
}
console.log('PASS: la foto de la reseña se ve donde decidió el dueño y los bordes no pintan nada');
