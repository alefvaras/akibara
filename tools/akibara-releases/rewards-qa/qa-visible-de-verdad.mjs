/**
 * ¿Está VISIBLE para el cliente, o sólo presente en el HTML?
 *
 * Buscar una clase en el HTML no basta y me costó dos falsos positivos: los
 * `akb-sb-card-select` salen con `hidden` en los DOS estados, y el payload del
 * carrito nativo trae `showFreeShippingProgress` porque es OTRO interruptor.
 * Aquí se mide lo que ve el cliente: `offsetParent`, tamaño y texto.
 *
 * Cambia la option por WP-CLI (no por el panel) para que el panel no sea parte
 * del experimento, y la restaura al terminar.
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const cli = (c) => execSync(`docker compose run --rm --no-deps -T cli ${c}`, { cwd: CWD, encoding: 'utf8' }).trim();

const CASOS = [
  { option: 'akibara_fsp_enabled', on: 'yes', off: 'no',
    url: `${BASE}/`,
    mira: '.akb-fsp-wrap, [class*="akb-fsp"]',
    que: 'la barra de envío gratis' },
];

const nav = await chromium.launch();
const ctx = await nav.newContext({ viewport: { width: 1440, height: 1200 } });

const visible = async (url, sel) => {
  const p = await ctx.newPage();
  await p.goto(url, { waitUntil: 'load' }).catch(() => {});
  await p.waitForTimeout(1500);
  const r = await p.evaluate((s) => {
    const els = [...document.querySelectorAll(s)];
    const vis = els.filter((e) => {
      const cs = getComputedStyle(e);
      const b = e.getBoundingClientRect();
      return e.offsetParent !== null && cs.visibility !== 'hidden' && cs.display !== 'none' && b.width > 0 && b.height > 0;
    });
    return { total: els.length, visibles: vis.length, texto: vis.map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 120))[0] || '' };
  }, sel);
  await p.close();
  return r;
};

for (const c of CASOS) {
  const original = cli(`wp option get ${c.option}`) || c.on;
  console.log(`\n══ ${c.que}   (option ${c.option}, ahora «${original}»)`);
  try {
    cli(`wp option update ${c.option} ${c.on}`);
    const on = await visible(c.url, c.mira);
    cli(`wp option update ${c.option} ${c.off}`);
    const off = await visible(c.url, c.mira);
    console.log(`   ENCENDIDO: ${on.visibles}/${on.total} visibles   «${on.texto}»`);
    console.log(`   APAGADO:   ${off.visibles}/${off.total} visibles   «${off.texto}»`);
    if (on.visibles > 0 && off.visibles === 0) console.log('   ✔ el interruptor apaga de verdad');
    else if (on.visibles > 0 && off.visibles > 0) console.log('   ✗✗ SIGUE VISIBLE con el interruptor apagado');
    else if (on.visibles === 0) console.log('   ✗ no se ve ni encendido — o el selector es malo, o no pinta aquí');
  } finally {
    cli(`wp option update ${c.option} ${original}`);
    console.log(`   · restaurado a «${original}»`);
  }
}
await nav.close();
