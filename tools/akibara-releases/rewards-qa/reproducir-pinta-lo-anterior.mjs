/**
 * ¿Se puede REPRODUCIR que la pantalla del redirect pinte el estado ANTERIOR?
 *
 * Punto ciego del intento previo: en el espejo las escrituras de la ventana de
 * descuento pueden rechazarse (deuda del buscador), y entonces «pinta la base»
 * y «pinta lo anterior» valen lo mismo: el control no puede fallar.
 *
 * Aquí cada ronda:
 *   1. lee lo pintado ANTES,
 *   2. guarda un valor NUEVO Y DISTINTO,
 *   3. espera de verdad con waitForURL(/saved=1/),
 *   4. lee lo pintado en la página del redirect,
 *   5. lee la BASE por wp-cli (docker exec),
 *   6. recarga a mano la MISMA URL y vuelve a leer.
 *
 * Si la base NO cambió, la ronda se marca RECHAZADA y no se concluye nada de
 * ella. Sólo las rondas con la base cambiada son control válido.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const RONDAS = Number(process.env.AKB_RONDAS || 20);
const CAMPANA = process.env.AKB_CAMPANA || 'announcement';
const RUIDO = process.env.AKB_RUIDO === '1'; // peticiones en paralelo al mismo admin

const log = (...a) => console.log(...a);

function db(...opciones) {
  const cmd = opciones.map((o) => `wp --allow-root option get ${o} 2>/dev/null || echo ''`).join('; ');
  const out = execFileSync('docker', ['exec', 'akibara-cron-1', 'sh', '-lc', `cd /var/www/html && ${cmd}`], {
    encoding: 'utf8',
  });
  const lineas = out.split('\n');
  return opciones.map((_, i) => (lineas[i] ?? '').trim());
}

const CAMPANAS = {
  announcement: {
    tab: 'announcement',
    // dos fechas escritas por el MISMO woocommerce_update_options, igual que la
    // ventana de descuento, pero en una pestaña que akibara-search no vigila.
    campos: ['#akibara_announcement_promo_from', '#akibara_announcement_promo_to'],
    options: ['akibara_announcement_promo_from', 'akibara_announcement_promo_to'],
    valores: (i) => {
      const d = String((i % 27) + 1).padStart(2, '0');
      const h = String(((i + 3) % 27) + 1).padStart(2, '0');
      return [`2026-03-${d}`, `2026-04-${h}`];
    },
  },
  // mismo fenómeno, valores ALTERNADOS como en producción
  announcement_alterna: {
    tab: 'announcement',
    campos: ['#akibara_announcement_promo_from', '#akibara_announcement_promo_to'],
    options: ['akibara_announcement_promo_from', 'akibara_announcement_promo_to'],
    valores: (i) => (i % 2 === 0 ? ['2026-09-04', '2026-09-07'] : ['2026-09-05', '2026-09-06']),
  },
  taxonomy_discount: {
    tab: 'taxonomy_discount',
    campos: ['#akibara_taxonomy_discount_from', '#akibara_taxonomy_discount_to'],
    options: ['akibara_taxonomy_discount_from', 'akibara_taxonomy_discount_to'],
    valores: (i) => (i % 2 === 0 ? ['2026-09-04', '2026-09-07'] : ['2026-09-05', '2026-09-06']),
  },
  taxonomy_discount_unico: {
    tab: 'taxonomy_discount',
    campos: ['#akibara_taxonomy_discount_from', '#akibara_taxonomy_discount_to'],
    options: ['akibara_taxonomy_discount_from', 'akibara_taxonomy_discount_to'],
    valores: (i) => {
      const d = String((i % 27) + 1).padStart(2, '0');
      const h = String(((i + 3) % 27) + 1).padStart(2, '0');
      return [`2026-05-${d}`, `2026-06-${h}`];
    },
  },
};

const plan = CAMPANAS[CAMPANA];
if (!plan) throw new Error(`campaña desconocida: ${CAMPANA}`);
const TAB = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${plan.tab}`;

const abrir = (page) =>
  page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));

async function leerCampos(page) {
  const out = [];
  for (const sel of plan.campos) {
    out.push(await page.locator(sel).first().inputValue().catch(() => '<ilegible>'));
  }
  return out;
}

async function leerVigencia(page) {
  try {
    const t = await page.locator('.akb-status__meta span', { hasText: 'Vigencia:' })
      .first().innerText({ timeout: 1500 });
    return t.replace(/\s+/g, ' ').replace('Vigencia:', '').trim();
  } catch { return null; }
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
const contexto = await navegador.newContext({ userAgent: 'AkibaraQA reproducir-pinta-lo-anterior' });
contexto.setDefaultNavigationTimeout(180000);
contexto.setDefaultTimeout(45000);
const page = await contexto.newPage();
let ruidoPage = null;

const resumen = { rondas: 0, validas: 0, rechazadas: 0, pintaAnterior: 0, recargaArregla: 0 };

try {
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass', PASS);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin/, { timeout: 180000, waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  if (!(await page.locator('#wpadminbar').count())) throw new Error('no entró al admin: ' + page.url());
  log(`· sesión iniciada · campaña=${CAMPANA} · rondas=${RONDAS} · ruido=${RUIDO}`);

  if (RUIDO) {
    ruidoPage = await contexto.newPage();
    (async () => {
      // hostiga el mismo admin en paralelo para ensanchar cualquier carrera
      // eslint-disable-next-line no-constant-condition
      while (true) {
        await ruidoPage.goto(TAB, { waitUntil: 'domcontentloaded' }).catch(() => {});
      }
    })();
  }

  for (let i = 0; i < RONDAS; i += 1) {
    resumen.rondas += 1;
    const [v1, v2] = plan.valores(i);

    await page.goto(TAB, { waitUntil: 'domcontentloaded' });
    await abrir(page);

    if (plan.tab === 'taxonomy_discount') {
      const box = page.locator('#akibara_taxonomy_discount_enabled');
      if ((await box.count()) && !(await box.isChecked())) {
        await box.focus(); await page.keyboard.press('Space');
      }
    }

    const dbAntes = db(...plan.options);
    const pintadoAntes = await leerCampos(page);
    const vigAntes = await leerVigencia(page);

    await page.locator(plan.campos[0]).fill(v1);
    await page.locator(plan.campos[1]).fill(v2);

    const t0 = Date.now();
    await pulsarGuardar(page);
    let llegoSaved = true;
    await page.waitForURL(/saved=1/, { timeout: 120000, waitUntil: 'domcontentloaded' }).catch(() => { llegoSaved = false; });
    const ms = Date.now() - t0;

    const pintadoTras = await leerCampos(page);
    const vigTras = await leerVigencia(page);
    const dbTras = db(...plan.options);

    const esperado = [v1, v2];
    const escribio = dbTras.join('|') === esperado.join('|');
    const baseCambio = dbTras.join('|') !== dbAntes.join('|');
    const pintoBien = pintadoTras.join('|') === dbTras.join('|');
    const pintoAnterior = pintadoTras.join('|') === dbAntes.join('|') && !pintoBien;

    // recarga a mano de la MISMA url
    await page.reload({ waitUntil: 'domcontentloaded' });
    await abrir(page);
    const pintadoRecarga = await leerCampos(page);
    const vigRecarga = await leerVigencia(page);
    const recargaArregla = !pintoBien && pintadoRecarga.join('|') === dbTras.join('|');

    const avisos = await page.$$eval('.notice-error, .akb-notice--error', (els) =>
      els.map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 160))).catch(() => []);

    if (!escribio && !baseCambio) {
      resumen.rechazadas += 1;
      log(`R${i + 1} RECHAZADA  pedí ${esperado.join('→')} · base sigue ${dbTras.join('→')}` +
          (avisos.length ? ` · ${avisos[0]}` : ''));
      continue;
    }
    resumen.validas += 1;
    if (pintoAnterior) resumen.pintaAnterior += 1;
    if (recargaArregla) resumen.recargaArregla += 1;

    const marca = pintoBien ? 'OK      ' : (pintoAnterior ? 'ANTERIOR' : 'RARO    ');
    log(
      `R${i + 1} ${marca} saved=${llegoSaved} ${ms}ms · pedí ${esperado.join('→')} · base ${dbTras.join('→')}` +
      ` · pintó ${pintadoTras.join('→')} · recarga ${pintadoRecarga.join('→')}` +
      (vigTras ? ` · vigencia «${vigTras}»/recarga «${vigRecarga}» (antes «${vigAntes}»)` : '') +
      ` · antes-base ${dbAntes.join('→')} antes-pintado ${pintadoAntes.join('→')}`
    );
  }
} finally {
  await navegador.close();
}

log(`\nRESUMEN ${CAMPANA}: ${resumen.rondas} rondas · ${resumen.validas} válidas · ` +
    `${resumen.rechazadas} rechazadas (base no cambió) · ${resumen.pintaAnterior} pintaron el ANTERIOR · ` +
    `${resumen.recargaArregla} de esas las arregló la recarga`);
