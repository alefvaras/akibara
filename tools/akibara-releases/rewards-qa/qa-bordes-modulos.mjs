/**
 * QA de CASOS DE BORDE, pestaña por pestaña, como los provoca una persona.
 *
 * Lo que busca no son funciones rotas sino promesas rotas: un valor imposible
 * que se guarda igual, una fecha invertida que nadie avisa, un texto larguísimo
 * que rompe la tienda, un aviso de «guardado» encima de algo que no se guardó.
 *
 * Sólo espejo. Restaura SIEMPRE lo que toca (finally), aunque falle.
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
if (!/localhost|127\.0\.0\.1/.test(BASE)) throw new Error('Sólo contra el espejo.');
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const CWD = '/Users/alefvaras/Documents/akibara';
const TAB = (t) => `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${t}`;

const cli = (cmd) => execSync(`docker compose run --rm --no-deps -T cli ${cmd}`, { cwd: CWD, encoding: 'utf8' });
const leerOpcion = (n) => {
  const s = cli(`wp eval 'echo "<<<" . var_export(get_option("${n}"), true) . ">>>";'`);
  const m = s.match(/<<<([\s\S]*?)>>>/);
  return m ? m[1] : '(?)';
};

const hallazgos = [];
const anotar = (mod, gravedad, titulo, detalle) => {
  hallazgos.push({ mod, gravedad, titulo, detalle });
  console.log(`  ${gravedad === 'alta' ? '✗✗' : '✗ '} [${mod}] ${titulo}\n     ${detalle}`);
};
const ok = (mod, t) => console.log(`  OK  [${mod}] ${t}`);

const navegador = await chromium.launch();
const contexto = await navegador.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await contexto.newPage();

async function abrir(tab) {
  await page.goto(TAB(tab), { waitUntil: 'load' });
  await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
}
async function guardar() {
  const b = page.locator('form button[name="akibara_panel_save"]').first();
  try { await b.scrollIntoViewIfNeeded(); await b.click({ timeout: 5000 }); }
  catch { await b.focus(); await page.keyboard.press('Enter'); }
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForTimeout(1200);
}
const avisos = () => page.evaluate(() => [...document.querySelectorAll('.notice, .updated, .error')]
  .map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter((t) => /guardad|error|no se|corrig|inválid|no válid/i.test(t)).slice(0, 3));
const valor = (id) => page.evaluate((i) => {
  const e = document.getElementById(i);
  return e ? (e.type === 'checkbox' ? e.checked : e.value) : '(no existe)';
}, id);

await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);
console.log('· sesión iniciada\n');

const restaurar = [];

try {
  // ───────────────────────── DESCUENTOS ─────────────────────────
  console.log('── Descuentos: casos de borde ──');
  const previo = {
    from: leerOpcion('akibara_taxonomy_discount_from'),
    to: leerOpcion('akibara_taxonomy_discount_to'),
  };
  restaurar.push(() => cli(`wp option update akibara_taxonomy_discount_from '${previo.from === 'false' ? '' : previo.from.replace(/'/g, '')}'`));

  // 1) Porcentaje por encima del tope de la tienda.
  await abrir('taxonomy_discount');
  const campo = page.locator('#akibara_taxonomy_discount_pct_230');
  if (await campo.count()) {
    const antes = await valor('akibara_taxonomy_discount_pct_230');
    await campo.fill('99');
    await guardar();
    await abrir('taxonomy_discount');
    const despues = await valor('akibara_taxonomy_discount_pct_230');
    const av = await avisos();
    if (String(despues) === '99') {
      anotar('Descuentos', 'alta', 'Acepta un 99% por encima del tope de la tienda (15%)',
        `El campo guarda 99 y ningún aviso lo corrige. Avisos: ${JSON.stringify(av).slice(0, 120)}`);
    } else if (!av.some((t) => /tope|máximo|corrig|15/i.test(t))) {
      anotar('Descuentos', 'media', 'Recorta el porcentaje al tope SIN decírselo al dueño',
        `Escribió 99, quedó ${despues}. El aviso no menciona el recorte: ${JSON.stringify(av).slice(0, 120)}`);
    } else ok('Descuentos', 'un porcentaje por encima del tope se corrige y se avisa');
    // dejar como estaba
    await abrir('taxonomy_discount');
    await page.locator('#akibara_taxonomy_discount_pct_230').fill(String(antes || ''));
    await guardar();
  }

  // 2) Vigencia invertida: desde > hasta.
  await abrir('taxonomy_discount');
  const sw = page.locator('#akibara_taxonomy_discount_enabled');
  if ((await sw.count()) && !(await sw.isChecked())) { await sw.focus(); await page.keyboard.press('Space'); }
  await page.locator('#akibara_taxonomy_discount_from').fill('2026-12-31');
  await page.locator('#akibara_taxonomy_discount_to').fill('2026-01-01');
  await guardar();
  const avInv = await avisos();
  const guardadoFrom = leerOpcion('akibara_taxonomy_discount_from');
  if (/2026-12-31/.test(guardadoFrom) && !avInv.some((t) => /fecha|vigencia|invert|posterior|anterior/i.test(t))) {
    anotar('Descuentos', 'alta', 'Acepta una vigencia imposible (desde 31-dic > hasta 1-ene) sin avisar',
      `Se guardó tal cual y el panel dice «guardado». Con esa ventana el descuento no se aplica nunca y nada lo explica. Avisos: ${JSON.stringify(avInv).slice(0, 140)}`);
  } else ok('Descuentos', 'la vigencia invertida se rechaza o se avisa');

  // ───────────────────────── BARRA DE ANUNCIOS ─────────────────────────
  console.log('\n── Barra de anuncios: casos de borde ──');
  await abrir('announcement');
  const promo = page.locator('#akibara_announcement_promo_text');
  if (await promo.count()) {
    const antesPromo = await valor('akibara_announcement_promo_text');
    restaurar.push(async () => { await abrir('announcement'); await page.locator('#akibara_announcement_promo_text').fill(String(antesPromo || '')); await guardar(); });

    // Texto larguísimo: el límite de ~40 caracteres es sólo CSS (puntos suspensivos).
    const largo = 'PROMOCIÓN ESPECIALÍSIMA DE FIN DE SEMANA CON UN TEXTO ABSURDAMENTE LARGO QUE NADIE PUSO A PRUEBA JAMÁS ' + 'x'.repeat(200);
    await promo.fill(largo);
    await guardar();
    const avLargo = await avisos();
    if (!avLargo.some((t) => /largo|caracteres|recort|límite/i.test(t))) {
      anotar('Barra de anuncios', 'media', 'Acepta un texto de 300+ caracteres sin avisar del límite',
        'La ayuda del campo promete «~40 caracteres por línea», pero ni recorta ni avisa: en móvil el mensaje se corta con puntos suspensivos y el dueño no se entera.');
    } else ok('Barra de anuncios', 'avisa del límite de longitud');

    // Etiquetas no permitidas: debe limpiarlas, no guardarlas.
    await abrir('announcement');
    await page.locator('#akibara_announcement_promo_text').fill('<script>alert(1)</script><b>Oferta</b> <a href="/x" onclick="alert(2)">ver</a>');
    await guardar();
    const guardadoPromo = leerOpcion('akibara_announcement_promo_text');
    if (/<script|onclick/i.test(guardadoPromo)) {
      anotar('Barra de anuncios', 'alta', 'Guarda etiquetas peligrosas en el mensaje',
        `Quedó en la base: ${guardadoPromo.slice(0, 120)}`);
    } else ok('Barra de anuncios', 'limpia las etiquetas no permitidas al guardar');
  }

  // ───────────────────────── PREVENTA ─────────────────────────
  console.log('\n── Preventa: qué ve el dueño ──');
  for (const t of ['preventa', 'preventas', 'order_preventa']) {
    await abrir(t);
    const info = await page.evaluate(() => ({
      titulo: (document.querySelector('h1, h2') || {}).textContent?.replace(/\s+/g, ' ').trim().slice(0, 70) || '',
      campos: document.querySelectorAll('input:not([type=hidden]), select, textarea').length,
      guardar: !!document.querySelector('form button[name="akibara_panel_save"]'),
      subpestanas: [...document.querySelectorAll('a[href*="subtab="]')].map((a) => new URL(a.href).searchParams.get('subtab')),
      vacio: document.body.innerText.replace(/\s+/g, ' ').trim().length < 400,
    }));
    console.log(`  ${t}: ${JSON.stringify(info)}`);
    if (info.campos > 0 && !info.guardar) {
      anotar('Preventa', 'alta', `La pestaña «${t}» muestra ${info.campos} campos pero no tiene botón de guardar`,
        'El dueño puede escribir y perder lo escrito sin manera de conservarlo.');
    }
  }
} finally {
  for (const f of restaurar) { try { await f(); } catch (e) { console.log('  (no se pudo restaurar:', String(e).slice(0, 80), ')'); } }
  await navegador.close();
}

console.log('\n===== HALLAZGOS =====');
console.log(JSON.stringify(hallazgos, null, 2));
