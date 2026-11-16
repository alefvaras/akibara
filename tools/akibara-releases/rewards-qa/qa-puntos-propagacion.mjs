/**
 * La premisa del proyecto: lo que el dueño escribe en el panel es lo que el
 * cliente lee en la ficha. Aquí se comprueba con la regla que más dinero mueve:
 * los puntos que se ganan por compra.
 *
 * Cambia el valor DESDE LA INTERFAZ, mira la superficie SERVIDA (no la base) y
 * restaura siempre. Sólo espejo.
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const AJUSTES = `${BASE}/wp-admin/admin.php?page=akb-rewards-settings`;
const CAMPO = 'akb_rewards_earn_points';

const cli = (c) => execSync(`docker compose run --rm --no-deps -T cli ${c}`, { cwd: CWD, encoding: 'utf8' });
const enBase = () => {
  const s = cli(`wp eval 'echo "<<<", var_export(get_option("${CAMPO}"), true), ">>>";'`);
  const m = s.match(/<<<([\s\S]*?)>>>/); return m ? m[1].trim().replace(/'/g, '') : '(?)';
};
/** Una ficha de producto con precio conocido, para leer la promesa servida. */
const fichaUrl = () => {
  const s = cli(`wp eval '$p = wc_get_products(array("limit"=>1,"status"=>"publish","stock_status"=>"instock")); echo "<<<", $p ? get_permalink($p[0]->get_id()) . "|" . $p[0]->get_price() : "", ">>>";'`);
  const m = s.match(/<<<([\s\S]*?)>>>/); return m ? m[1].trim() : '';
};

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', 'qa.owner');
await page.fill('#user_pass', 'AkbQaOwner2026!');
await page.click('#wp-submit');
await page.waitForSelector('#wpadminbar', { timeout: 60000 });

const [url, precio] = fichaUrl().split('|');
console.log('· ficha de prueba:', url, '· precio', precio);

const promesaServida = async () => {
  const p = await (await nav.newContext()).newPage();
  await p.goto(`${url}?nc=${Date.now()}`, { waitUntil: 'load' });
  const txt = await p.evaluate(() => {
    const t = document.body.innerText.replace(/\s+/g, ' ');
    // OJO: `[^.]` rompe con separador de miles («1.080»). Un 1.080 hizo que
    // este test reportara «no se encontró la promesa» y estuve a punto de
    // levantar un falso positivo. Se acota por longitud, no por punto.
    const m = t.match(/[Gg]ana\s+[\d.,]+\s+[Pp]untos[^\n]{0,24}/);
    return m ? m[0].trim() : '(no se encontró la promesa)';
  });
  await p.close();
  return txt;
};

const escribir = async (valor) => {
  await page.goto(AJUSTES, { waitUntil: 'load' });
  await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
  const campo = page.locator(`#${CAMPO}`);
  await campo.fill(String(valor));
  const nativo = await page.evaluate((id) => {
    const e = document.getElementById(id);
    return e ? { valido: e.checkValidity(), mensaje: e.validationMessage } : null;
  }, CAMPO);
  if (nativo && !nativo.valido) { return { bloqueadoPorNavegador: nativo.mensaje }; }
  await page.locator('input[name=submit], form button[type=submit]').first().click();
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForTimeout(1500);
  const aviso = await page.evaluate(() => [...document.querySelectorAll('.notice, .updated, .error')]
    .map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter((t) => /guardad|error|no se/i.test(t)).join(' | ').slice(0, 120));
  return { aviso };
};

const original = enBase();
console.log('· valor original:', original);
console.log('· promesa ANTES :', await promesaServida());

try {
  console.log('\n-- cambio a 5 puntos (valor razonable) --');
  console.log('  ', JSON.stringify(await escribir(5)));
  console.log('   en base:', enBase());
  const despues = await promesaServida();
  console.log('   promesa DESPUÉS:', despues);
  // 21.600 / 100 × 5 = 1.080 puntos. Se comprueba el NÚMERO esperado, no el
  // valor del ajuste: es lo que de verdad lee el cliente.
  const esperado = Math.floor((Number(precio) / 100) * 5);
  const coherente = despues.replace(/[.,]/g, '').includes(String(esperado));
  console.log(`   esperado en la ficha: ${esperado} puntos`);
  console.log(coherente
    ? '\n  OK  la ficha refleja lo que el dueño configuró'
    : `\n  ✗   LA FICHA NO REFLEJA EL CAMBIO: sigue diciendo «${despues}»`);
} finally {
  console.log('\n-- restaurando --');
  await escribir(original);
  console.log('   en base:', enBase(), '· promesa:', await promesaServida());
  await nav.close();
}
