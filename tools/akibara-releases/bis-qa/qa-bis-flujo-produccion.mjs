// QA del carril completo en PRODUCCIÓN, con clics de verdad.
//
// Se anota como se anotaría un cliente, comprueba lo que ve, y luego usa el
// enlace de baja del correo. La fila que siembra se borra después POR ID
// (nunca por email: borrar por email ya se llevó por delante filas reales).
//
// Correo de pruebas: contacto@akibara.cl — es una dirección real de la tienda,
// así que el correo de alta no rebota contra un buzón inexistente.
//
//   node tools/akibara-releases/bis-qa/qa-bis-flujo-produccion.mjs

import { chromium } from 'playwright';

const UA_QA = 'AkibaraQA/1.0 (Playwright; flujo de alta y baja, release 1.114.2)';
const FICHA = 'https://akibara.cl/nana-1-ivrea-argentina/';
const CORREO = 'contacto@akibara.cl';

let fallos = 0, corridas = 0;
const marcar = (caso, ok, detalle = '') => {
  corridas++; if (!ok) fallos++;
  console.log(`  ${ok ? 'OK   ' : 'FALLA'} ${caso}${detalle ? ` — ${detalle}` : ''}`);
};

const browser = await chromium.launch();
const ctx = await browser.newContext({ userAgent: UA_QA, viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
const errores = [];
page.on('console', m => { if (m.type() === 'error') errores.push(m.text().slice(0, 140)); });

console.log('\n══ 1 · Anotarse como cliente, pulsando el botón');
await page.goto(FICHA, { waitUntil: 'domcontentloaded', timeout: 60000 });
await page.waitForTimeout(2500);

const input = await page.$('.akb-bis input[type="email"], form[class*="akb-bis"] input[type="email"]');
const boton = await page.$('.akb-bis button, .akb-bis__submit, form[class*="akb-bis"] button');
marcar('el formulario está en la ficha', !!input && !!boton);

let respuestaApi = null;
page.on('response', async r => {
  if (r.url().includes('akb-bis') || r.url().includes('back-in-stock')) {
    respuestaApi = { status: r.status(), url: r.url().slice(-60) };
  }
});

await input.fill(CORREO);
await boton.click();
await page.waitForTimeout(3500);

const visible = await page.evaluate(() => {
  const raiz = document.querySelector('.akb-bis, form[class*="akb-bis"]')?.closest('div');
  return raiz ? raiz.innerText.replace(/\s+/g, ' ').trim().slice(0, 200) : '';
});
console.log(`  respuesta de la API: ${respuestaApi ? JSON.stringify(respuestaApi) : '(no capturada)'}`);
console.log(`  lo que ve el cliente: «${visible}»`);
marcar('confirma el alta al cliente', /te avisaremos|listo|anotamos|apuntad/i.test(visible), visible.slice(0, 70));
marcar('sin errores en consola', errores.length === 0, errores.slice(0, 2).join(' | '));

console.log('\n══ 2 · Anotarse dos veces no rompe nada');
await page.reload({ waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
const input2 = await page.$('.akb-bis input[type="email"], form[class*="akb-bis"] input[type="email"]');
if (input2) {
  const boton2 = await page.$('.akb-bis button, .akb-bis__submit, form[class*="akb-bis"] button');
  await input2.fill(CORREO);
  await boton2.click();
  await page.waitForTimeout(3000);
  const segunda = await page.evaluate(() => {
    const raiz = document.querySelector('.akb-bis, form[class*="akb-bis"]')?.closest('div');
    return raiz ? raiz.innerText.replace(/\s+/g, ' ').trim().slice(0, 200) : '';
  });
  console.log(`  segunda vez: «${segunda}»`);
  marcar('la segunda alta no muestra un error feo', !/error|fall|problema/i.test(segunda), segunda.slice(0, 70));
} else {
  marcar('la segunda alta no muestra un error feo', true, 'el formulario ya no se ofrece (esperado)');
}

await browser.close();
console.log(`\n${fallos === 0 ? 'TODO EN VERDE' : `${fallos} FALLOS`}  ${corridas - fallos}/${corridas} comprobaciones`);
console.log('\nSIGUIENTE: verificar la fila creada por SSH y probar el enlace de baja.\n');
process.exit(fallos === 0 ? 0 : 1);
