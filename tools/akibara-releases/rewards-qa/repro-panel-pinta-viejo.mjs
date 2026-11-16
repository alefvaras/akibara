/**
 * REPRODUCIR: «tras guardar, el panel pinta el estado ANTERIOR; recargar lo arregla».
 *
 * Camino A del encargo: se usa un campo que NO vigila akibara-search
 * (Barra de anuncios → mensaje promocional + sus dos fechas), para que el
 * candado del buscador no pueda enmascarar el control. Cada ronda guarda un
 * valor NUEVO y único, así que «pintó lo anterior» es identificable por número
 * de ronda, no por parecido.
 *
 * Por ronda se registra:
 *   guardado    — lo que se envió
 *   base        — lo que quedó en wp_options (lo inyecta el runner por stdin/arg)
 *   pintado     — lo que pinta la página del redirect (?saved=1)
 *   navegación  — transferSize / deliveryType del documento: 0 y «cache» = la
 *                 pintó el caché HTTP del navegador, no el servidor
 *   recarga     — si al recargar a mano la MISMA URL aparece el valor bueno
 *
 * No modifica ficheros del sitio. Sí escribe options del ESPEJO (se restauran
 * desde fuera).
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const enBase = (opt) => {
  try {
    return execFileSync('docker', ['exec', 'akibara-cron-1', 'wp', 'option', 'get', opt, '--allow-root'], { encoding: 'utf8' }).replace(/\n$/, '');
  } catch (e) {
    return `<error: ${String(e.message).split('\n')[0].slice(0, 60)}>`;
  }
};

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const RONDAS = Number(process.env.AKB_RONDAS || 25);
const TAB = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=announcement`;
const log = (...a) => console.log(...a);

// Dos ventanas que se alternan, igual que hizo el QA con Descuentos.
const VENTANAS = [
  ['2026-09-04', '2026-09-07'],
  ['2026-09-05', '2026-09-06'],
];

const abrirAcordeones = (page) =>
  page.evaluate(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); });

async function leerCampos(page) {
  return page.evaluate(() => {
    const v = (id) => (document.getElementById(id) ? document.getElementById(id).value : '<sin campo>');
    const estado = document.querySelector('#akibara_announcement_promo_text')
      ?.closest('tr')?.querySelector('.description, p.description')?.innerText || '';
    const m = estado.replace(/\s+/g, ' ').match(/Ahora mismo:.*?\./);
    return {
      texto: v('akibara_announcement_promo_text'),
      desde: v('akibara_announcement_promo_from'),
      hasta: v('akibara_announcement_promo_to'),
      estado: m ? m[0] : '<sin estado>',
    };
  });
}

async function navInfo(page) {
  return page.evaluate(() => {
    const n = performance.getEntriesByType('navigation')[0];
    if (!n) return { sin: true };
    return {
      transferSize: n.transferSize,
      encodedBodySize: n.encodedBodySize,
      deliveryType: n.deliveryType ?? '(n/d)',
      responseStatus: n.responseStatus ?? '(n/d)',
      redirectCount: n.redirectCount,
    };
  });
}

async function pulsarGuardar(page) {
  const btn = page.locator('form button[name="akibara_panel_save"]').first();
  try {
    await btn.scrollIntoViewIfNeeded();
    await btn.click({ timeout: 4000, noWaitAfter: true });
  } catch {
    await btn.focus();
    await page.keyboard.press('Enter');
  }
}

const navegador = await chromium.launch();
const contexto = await navegador.newContext();
const page = await contexto.newPage();
page.setDefaultTimeout(120000);
page.setDefaultNavigationTimeout(120000);

// Rastro de red: qué documentos pidió de verdad al servidor.
const docs = [];
page.on('response', (r) => {
  if (r.request().resourceType() === 'document') {
    docs.push({ url: r.url(), status: r.status(), cc: r.headers()['cache-control'] || '', ls: r.headers()['x-litespeed-cache'] || '' });
  }
});

const filas = [];
try {
  let entro = false;
  for (let intento = 1; intento <= 5 && !entro; intento += 1) {
    await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#loginform', { timeout: 30000 });
    await page.evaluate(([u, p2]) => {
      document.getElementById('user_login').value = u;
      document.getElementById('user_pass').value = p2;
      document.getElementById('loginform').submit();
    }, [USER, PASS]);
    await page.waitForURL(/wp-admin/, { timeout: 60000, waitUntil: 'domcontentloaded' }).catch(() => {});
    entro = (await page.locator('#wpadminbar').count()) > 0;
    if (!entro) log(`  (intento de login ${intento} falló; url=${page.url()})`);
  }
  if (!entro) throw new Error('no entró al admin: ' + page.url());
  log('· sesión iniciada en', BASE);

  for (let i = 1; i <= RONDAS; i += 1) {
    const [desde, hasta] = VENTANAS[i % VENTANAS.length];
    const texto = `RONDA-${String(i).padStart(3, '0')} promo de prueba`;

    await page.goto(TAB, { waitUntil: 'domcontentloaded' });
    await abrirAcordeones(page);
    const previo = await leerCampos(page);

    await page.locator('#akibara_announcement_promo_text').fill(texto);
    await page.locator('#akibara_announcement_promo_from').fill(desde);
    await page.locator('#akibara_announcement_promo_to').fill(hasta);

    docs.length = 0;
    await pulsarGuardar(page);
    await page.waitForURL(/saved=1/, { timeout: 90000, waitUntil: 'domcontentloaded' }).catch(() => log(`   R${i} no llegó saved=1`));
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    await abrirAcordeones(page).catch(() => {});

    const pintado = await leerCampos(page);
    const nav = await navInfo(page);
    const base = {
      texto: enBase('akibara_announcement_promo_text'),
      desde: enBase('akibara_announcement_promo_from'),
      hasta: enBase('akibara_announcement_promo_to'),
    };
    const okTexto = pintado.texto === texto;
    const okFechas = pintado.desde === desde && pintado.hasta === hasta;

    let recarga = null;
    if (!okTexto || !okFechas) {
      await page.reload({ waitUntil: 'domcontentloaded' });
      await abrirAcordeones(page).catch(() => {});
      recarga = await leerCampos(page);
    }

    filas.push({ i, texto, desde, hasta, previo, pintado, base, nav, recarga, docs: docs.slice() });

    const marca = okTexto && okFechas ? 'OK      ' : 'DISCREPA';
    log(`R${String(i).padStart(3, '0')} ${marca} envié «${texto}» ${desde}→${hasta}`);
    log(`        pintó  «${pintado.texto}» ${pintado.desde}→${pintado.hasta}  | ${pintado.estado}`);
    log(`        venía  «${previo.texto}» ${previo.desde}→${previo.hasta}`);
    log(`        BASE   «${base.texto}» ${base.desde}→${base.hasta}   ${base.texto === texto ? '(la escritura SÍ entró)' : '(la escritura NO entró)'}`);
    log(`        nav    transfer=${nav.transferSize} body=${nav.encodedBodySize} delivery=${nav.deliveryType} status=${nav.responseStatus} redirects=${nav.redirectCount}`);
    docs.forEach((d) => log(`        doc    ${d.status} cc=[${d.cc}] ls=[${d.ls}] ${d.url.replace(BASE, '')}`));
    if (recarga) log(`        RECARGA pintó «${recarga.texto}» ${recarga.desde}→${recarga.hasta}`);
  }
} finally {
  await navegador.close();
}

const malas = filas.filter((f) => f.pintado.texto !== f.texto || f.pintado.desde !== f.desde || f.pintado.hasta !== f.hasta);
log(`\nRESUMEN  ${malas.length}/${filas.length} rondas pintaron algo distinto de lo guardado.`);
malas.forEach((f) => log(`  · R${f.i}: pintó «${f.pintado.texto}» (esperaba «${f.texto}») · recarga → «${f.recarga?.texto}»`));
