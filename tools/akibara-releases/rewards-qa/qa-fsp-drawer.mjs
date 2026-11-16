/** ¿Dónde se pinta REALMENTE la barra de progreso de envío gratis? */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
const BASE='http://localhost:8080', CWD='/Users/alefvaras/Documents/akibara';
const cli=(c)=>execSync(`docker compose run --rm --no-deps -T cli ${c}`,{cwd:CWD,encoding:'utf8'}).trim().split('\n').pop();
const id = cli(`wp db query "SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_stock_status' AND m.meta_value='instock' WHERE p.post_type='product' AND p.post_status='publish' LIMIT 1;" --skip-column-names`);
const orig = cli('wp option get akibara_fsp_threshold') || '0';
cli('wp option update akibara_fsp_threshold 45000');
const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport:{width:1440,height:1100} })).newPage();
await page.goto(`${BASE}/?add-to-cart=${id}`, { waitUntil:'load' });
for (const donde of ['/', '/carrito/', `/?p=${id}`]) {
  await page.goto(BASE+donde, { waitUntil:'load' }).catch(()=>{});
  await page.waitForTimeout(2000);
  // abrir el drawer si hay activador
  const t = page.locator('.akb-cart-trigger, [data-akb-cart-trigger], a[href*="carrito"]').first();
  if (await t.count()) { await t.click({ timeout: 4000 }).catch(()=>{}); await page.waitForTimeout(2500); }
  const r = await page.evaluate(() => {
    const els=[...document.querySelectorAll('[class*="akb-fsp"]')];
    const vis=els.filter(e=>{const b=e.getBoundingClientRect();return b.width>0&&b.height>0;});
    return { total: els.length, visibles: vis.length, texto: vis.map(e=>e.innerText.replace(/\s+/g,' ').trim()).filter(Boolean)[0]||'',
             montos: [...new Set((document.body.innerText.match(/\$ ?[\d.]{4,}/g)||[]))].slice(0,5) };
  });
  console.log(`${donde.padEnd(12)} akb-fsp: ${r.visibles}/${r.total} visibles  «${r.texto.slice(0,90)}»  montos: ${r.montos.join(' ')}`);
}
cli(`wp option update akibara_fsp_threshold ${orig}`);
console.log('· restaurado umbral a', orig);
await nav.close();
