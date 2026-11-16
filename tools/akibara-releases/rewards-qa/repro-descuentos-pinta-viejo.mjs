/**
 * Camino B del encargo: el MISMO experimento, pero en la pestaña Descuentos,
 * que es donde el QA vio el fallo en producción.
 *
 * Primero comprueba si la escritura ENTRA (la corrida anterior falló por un
 * punto ciego: si la base no cambia, «pinta la base» y «pinta lo anterior» son
 * el mismo valor y el control no puede fallar). Cada ronda alterna la ventana,
 * y se compara lo pintado por la tarjeta ESTADO contra lo que quedó en
 * wp_options.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const RONDAS = Number(process.env.AKB_RONDAS || 12);
const TAB = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=taxonomy_discount`;
const log = (...a) => console.log(...a);

const VENTANAS = [
  ['2026-09-04', '2026-09-07'],
  ['2026-09-05', '2026-09-06'],
];

const enBase = (opt) => {
  try {
    return execFileSync('docker', ['exec', 'akibara-cron-1', 'wp', 'option', 'get', opt, '--allow-root'], { encoding: 'utf8' }).replace(/\n$/, '');
  } catch (e) {
    return `<error: ${String(e.message).split('\n')[0].slice(0, 60)}>`;
  }
};

const abrirAcordeones = (page) =>
  page.evaluate(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); });

async function encender(page) {
  const box = page.locator('#akibara_taxonomy_discount_enabled');
  await box.waitFor({ state: 'visible', timeout: 30000 });
  if (await box.isChecked()) return;
  await box.focus();
  await page.keyboard.press('Space');
}

async function leer(page) {
  return page.evaluate(() => {
    const v = (id) => (document.getElementById(id) ? document.getElementById(id).value : '<sin campo>');
    let vig = '<sin tarjeta>';
    document.querySelectorAll('.akb-status__meta span').forEach((s) => {
      if (s.innerText.includes('Vigencia:')) vig = s.innerText.replace(/\s+/g, ' ').replace('Vigencia:', '').trim();
    });
    const avisos = [...document.querySelectorAll('.notice, .akb-notice')]
      .map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 160));
    return { desde: v('akibara_taxonomy_discount_from'), hasta: v('akibara_taxonomy_discount_to'), vigencia: vig, avisos };
  });
}

async function pulsarGuardar(page) {
  const btn = page.locator('form button[name="akibara_panel_save"]').first();
  try {
    await btn.scrollIntoViewIfNeeded();
    await btn.click({ timeout: 6000, noWaitAfter: true });
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

let entraLaEscritura = 0;
let pintaViejo = 0;
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
  }
  if (!entro) throw new Error('no entró al admin: ' + page.url());
  log('· sesión iniciada en', BASE);

  for (let i = 1; i <= RONDAS; i += 1) {
    const [desde, hasta] = VENTANAS[i % VENTANAS.length];
    await page.goto(TAB, { waitUntil: 'domcontentloaded' });
    await abrirAcordeones(page);
    await encender(page);
    const previo = await leer(page);

    await page.locator('#akibara_taxonomy_discount_from').fill(desde);
    await page.locator('#akibara_taxonomy_discount_to').fill(hasta);

    await pulsarGuardar(page);
    await page.waitForURL(/saved=1/, { timeout: 90000, waitUntil: 'domcontentloaded' }).catch(() => log(`   R${i} no llegó saved=1`));
    await abrirAcordeones(page).catch(() => {});

    const pintado = await leer(page);
    const base = { desde: enBase('akibara_taxonomy_discount_from'), hasta: enBase('akibara_taxonomy_discount_to') };
    const entro2 = base.desde === desde && base.hasta === hasta;
    if (entro2) entraLaEscritura += 1;
    const esperado = `${desde} → ${hasta}`;
    const okPintado = pintado.vigencia === esperado && pintado.desde === desde && pintado.hasta === hasta;
    if (entro2 && !okPintado) pintaViejo += 1;

    let recarga = null;
    if (!okPintado) {
      await page.reload({ waitUntil: 'domcontentloaded' });
      await abrirAcordeones(page).catch(() => {});
      recarga = await leer(page);
    }

    log(`R${String(i).padStart(3, '0')} envié ${desde}→${hasta}   (venía ${previo.desde}→${previo.hasta}, tarjeta «${previo.vigencia}»)`);
    log(`        BASE    ${base.desde}→${base.hasta}   ${entro2 ? 'LA ESCRITURA ENTRÓ' : '*** LA ESCRITURA NO ENTRÓ ***'}`);
    log(`        pintado campos ${pintado.desde}→${pintado.hasta} · tarjeta «${pintado.vigencia}»   ${okPintado ? 'OK' : 'DISCREPA'}`);
    pintado.avisos.forEach((a) => log(`        aviso   ${a}`));
    if (recarga) log(`        RECARGA campos ${recarga.desde}→${recarga.hasta} · tarjeta «${recarga.vigencia}»`);
  }
} finally {
  await navegador.close();
}

log(`\nRESUMEN  escrituras que entraron: ${entraLaEscritura}/${RONDAS}   ·   rondas que pintaron lo anterior teniendo la base nueva: ${pintaViejo}`);
