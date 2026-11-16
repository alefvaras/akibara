/**
 * La barra de envío gratis, con el carrito LLENO — que es donde vive.
 *
 * En la portada no está, y buscarla ahí me dio un falso positivo. Aquí se mete
 * un tomo al carrito de verdad, se mira el carrito y el drawer, y se comprueba
 * el interruptor por los dos lados: encendido tiene que verse, apagado tiene
 * que desaparecer. Además se comprueba que el UMBRAL configurado sea el que
 * aparece en el mensaje.
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const cli = (c) => execSync(`docker compose run --rm --no-deps -T cli ${c}`, { cwd: CWD, encoding: 'utf8' }).trim().split('\n').pop();

const idProducto = cli(`wp db query "SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_stock_status' AND m.meta_value='instock' WHERE p.post_type='product' AND p.post_status='publish' LIMIT 1;" --skip-column-names`);
console.log('· tomo de prueba:', idProducto);

const nav = await chromium.launch();
const ctx = await nav.newContext({ viewport: { width: 1440, height: 1200 } });
const page = await ctx.newPage();

// carrito con algo dentro
await page.goto(`${BASE}/?add-to-cart=${idProducto}`, { waitUntil: 'load' });
await page.goto(`${BASE}/carrito/`, { waitUntil: 'load' });
await page.waitForTimeout(2500);
const hayItems = await page.evaluate(() => /vaciar|eliminar|subtotal|total/i.test(document.body.innerText));
console.log('· carrito con contenido:', hayItems);

const mirar = async () => {
  const p = await ctx.newPage();
  await p.goto(`${BASE}/carrito/`, { waitUntil: 'load' });
  await p.waitForTimeout(2500);
  const r = await p.evaluate(() => {
    const els = [...document.querySelectorAll('[class*="akb-fsp"]')];
    const vis = els.filter((e) => { const b = e.getBoundingClientRect(); const cs = getComputedStyle(e); return b.width > 0 && b.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; });
    const txt = document.body.innerText;
    return {
      total: els.length, visibles: vis.length,
      texto: vis.map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean)[0] || '',
      mencionaEnvioGratis: /env[íi]o\s+gratis/i.test(txt),
      montos: [...new Set((txt.match(/\$\s?[\d.]{3,}/g) || []))].slice(0, 6),
    };
  });
  await p.close();
  return r;
};

const orig = cli('wp option get akibara_fsp_enabled') || 'yes';
const origUmbral = cli('wp option get akibara_fsp_threshold') || '0';
console.log(`· estado original: enabled=${orig} threshold=${origUmbral}\n`);

try {
  cli('wp option update akibara_fsp_enabled yes');
  cli('wp option update akibara_fsp_threshold 45000');
  const on = await mirar();
  console.log('ENCENDIDO (umbral 45.000):', JSON.stringify(on));

  cli('wp option update akibara_fsp_enabled no');
  const off = await mirar();
  console.log('APAGADO:                  ', JSON.stringify(off));

  console.log('\n═══');
  if (on.visibles > 0 && off.visibles === 0) console.log('OK  el interruptor de envío gratis apaga y enciende de verdad');
  else if (on.visibles === 0) console.log('✗✗  la barra NO se ve en el carrito ni con el interruptor encendido');
  else console.log('✗✗  la barra SIGUE VISIBLE con el interruptor apagado');
  if (on.visibles > 0 && !/45[.,]?000/.test(on.texto)) console.log(`✗   el umbral configurado (45.000) no aparece en el mensaje: «${on.texto}»`);
} finally {
  cli(`wp option update akibara_fsp_enabled ${orig}`);
  cli(`wp option update akibara_fsp_threshold ${origUmbral}`);
  console.log(`· restaurado: enabled=${orig} threshold=${origUmbral}`);
}
await nav.close();
