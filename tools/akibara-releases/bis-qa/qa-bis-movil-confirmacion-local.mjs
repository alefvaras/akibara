// ¿El cliente MÓVIL ve la confirmación después de pulsar «Avisarme»?
//
// El formulario se oculta al enviarse y el mensaje ocupa su lugar. En una
// pantalla de 375px, con el pulgar sobre el botón, hay que comprobar que el
// texto quede DENTRO del viewport y no arriba, fuera de vista.
//
//   node tools/akibara-releases/bis-qa/qa-bis-movil-confirmacion-local.mjs

import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const REPO  = '/Users/alefvaras/Documents/akibara';
const PDP   = 'http://localhost:8080/product/claymore-27-panini-argentina/';
const UA_QA = 'AkibaraQA/1.0 (Playwright; QA móvil BIS local, business 1.114.2)';
const EMAIL = `qa.bis.movil.${Date.now().toString(36)}@example.com`;

const wp = (args) => execFileSync('docker', ['compose', '--profile', 'tools', 'run', '--rm', '-T', 'cli', 'wp', ...args],
  { cwd: REPO, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 32 * 1024 * 1024 }).trim();
const sql = (q) => wp(['db', 'query', q]).split('\n').map((l) => l.trim()).filter(Boolean);

const browser = await chromium.launch();
const ctx = await browser.newContext({
  userAgent: UA_QA, viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2,
});
const page = await ctx.newPage();
let idSembrado = 0;

try {
  await page.goto(PDP, { waitUntil: 'domcontentloaded' });
  await page.locator('#akb-bis-form').scrollIntoViewIfNeeded();

  const cajaBoton = await page.locator('.akb-bis__submit').boundingBox();
  const cajaInput = await page.locator('#akb-bis-email').boundingBox();
  console.log(`  botón «Avisarme»: ${Math.round(cajaBoton.width)}×${Math.round(cajaBoton.height)} px`);
  console.log(`  input email     : ${Math.round(cajaInput.width)}×${Math.round(cajaInput.height)} px`);
  console.log(`  ${cajaBoton.height >= 44 && cajaInput.height >= 44 ? 'OK   ' : 'FALLA'} objetivos táctiles ≥ 44 px de alto`);

  await page.locator('#akb-bis-email').tap();
  await page.locator('#akb-bis-email').type(EMAIL, { delay: 20 });
  await page.locator('.akb-bis__submit').tap();
  await page.locator('#akb-bis-message').waitFor({ state: 'visible', timeout: 15000 });

  // getBoundingClientRect() es RELATIVO AL VIEWPORT y no deja lugar a dudas
  // (boundingBox() de Playwright y window.scrollY no se pueden mezclar: medir
  // desde una sola superficie es como se fabrican los falsos positivos).
  const medida = await page.evaluate(() => {
    const el = document.getElementById('akb-bis-message');
    const r = el.getBoundingClientRect();
    return {
      top: r.top, bottom: r.bottom, alto: r.height,
      viewport: window.innerHeight, scrollY: window.scrollY,
      visible: r.top >= 0 && r.bottom <= window.innerHeight,
      enfocado: document.activeElement === el,
      texto: (el.textContent || '').trim(),
    };
  });
  console.log(`  mensaje: «${medida.texto}»`);
  console.log(`  rect viewport: top=${Math.round(medida.top)}, bottom=${Math.round(medida.bottom)}, innerHeight=${medida.viewport} (scrollY=${Math.round(medida.scrollY)})`);
  console.log(`  ${medida.visible ? 'OK   ' : 'FALLA'} la confirmación queda a la vista sin tener que buscarla`);
  console.log(`  ${medida.enfocado ? 'OK   ' : 'FALLA'} el foco aterriza en la confirmación (lectores de pantalla)`);

  const f = sql(`SELECT id FROM wp_akb_bis_subscribers WHERE email='${EMAIL}' ORDER BY id DESC LIMIT 1`);
  if (f.length > 1) idSembrado = parseInt(f[1], 10);
  await page.screenshot({ path: '/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/d5a6502d-96ec-4b2f-bfd8-ffb8f9cd378c/scratchpad/bis-shots/09-movil-confirmacion.png' });
} finally {
  if (idSembrado) {
    wp(['db', 'query', `DELETE FROM wp_akb_bis_subscribers WHERE id=${idSembrado}`]);
    const quedan = parseInt(sql(`SELECT COUNT(*) FROM wp_akb_bis_subscribers WHERE id=${idSembrado}`)[1], 10);
    console.log(`\n  fila ${idSembrado} borrada por id → ${quedan === 0 ? 'RESTAURADO' : 'REVISAR'}`);
  }
  wp(['db', 'query', "DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_akb\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_akb\\_rl\\_%'"]);
  await browser.close();
}
process.exit(0);
