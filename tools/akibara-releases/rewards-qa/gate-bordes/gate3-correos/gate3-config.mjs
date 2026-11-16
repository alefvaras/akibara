// Configurabilidad EN VIVO: se opera la PANTALLA REAL del admin, como el dueño.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const OUT = process.env.GATE3_OUT;
const BASE = 'http://localhost:8080';
const paso = process.env.GATE3_PASO;

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
const inf = { paso };

await page.goto(BASE + '/wp-login.php', { waitUntil: 'load' });
await page.fill('#user_login', 'qa_gate3_admin');
await page.fill('#user_pass', 'Gate3-QA-2026!');
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);
inf.admin = page.url();

const guardar = async (sel) => {
  await Promise.all([page.waitForNavigation({ waitUntil: 'load', timeout: 60000 }), page.click(sel)]);
};

if (paso === 'asunto-serie') {
  const url = BASE + '/wp-admin/admin.php?page=wc-settings&tab=email&section=akibaraseriesfollowemail';
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForTimeout(600);
  inf.pantalla = await page.evaluate(() => ({
    titulo: document.querySelector('h2,h1')?.textContent.trim(),
    campos: [...document.querySelectorAll('input[type=text],textarea,select,input[type=checkbox]')]
      .map((e) => ({ id: e.id, name: e.name, tipo: e.type, valor: e.value, ph: e.placeholder || '' }))
      .filter((f) => /woocommerce_akb/.test(f.name || '')),
  }));
  await page.screenshot({ path: path.join(OUT, 'config-asunto-serie-antes.png'), fullPage: true });
  await page.fill('#woocommerce_akb_sf_series_subject', 'GATE3 · {serie} tomo {tomo} en {site_title}');
  await guardar('button[name=save], .woocommerce-save-button');
  inf.trasGuardar = await page.evaluate(() => document.querySelector('#woocommerce_akb_sf_series_subject')?.value);
  await page.screenshot({ path: path.join(OUT, 'config-asunto-serie-despues.png'), fullPage: true });
}

if (paso === 'monto-cumple') {
  const url = BASE + '/wp-admin/admin.php?page=akb-rewards-settings';
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForTimeout(600);
  inf.pantalla = await page.evaluate(() => ({
    titulo: document.querySelector('h1')?.textContent.trim(),
    cumple: document.querySelector('[name=akb_rewards_birthday_points]')?.value,
    interruptorCumple: (() => { const e = document.querySelector('[name=akb_rewards_birthday_enabled]'); return e ? { tipo: e.type, checked: e.checked, valor: e.value } : null; })(),
    inviteStatusOpciones: [...(document.querySelector('[name=akb_rewards_invite_status]')?.options || [])].map((o) => o.value + ' :: ' + o.text),
    inviteStatusValor: document.querySelector('[name=akb_rewards_invite_status]')?.value,
  }));
  await page.screenshot({ path: path.join(OUT, 'config-puntos-resenas.png'), fullPage: true });
  await page.fill('[name=akb_rewards_birthday_points]', '777');
  await guardar('#submit, input[type=submit], button[type=submit]');
  inf.trasGuardar = await page.evaluate(() => document.querySelector('[name=akb_rewards_birthday_points]')?.value);
}

if (paso === 'apagar-cumple' || paso === 'encender-cumple') {
  const url = BASE + '/wp-admin/admin.php?page=akb-rewards-settings';
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForTimeout(600);
  const quiero = paso === 'encender-cumple';
  const estado = await page.evaluate((q) => {
    const e = document.querySelector('[name=akb_rewards_birthday_enabled]');
    if (!e) return { error: 'sin interruptor' };
    const antes = e.checked;
    if (e.checked !== q) e.click();
    return { antes, despues: e.checked };
  }, quiero);
  inf.interruptor = estado;
  await guardar('#submit, input[type=submit], button[type=submit]');
  inf.trasGuardar = await page.evaluate(() => document.querySelector('[name=akb_rewards_birthday_enabled]')?.checked);
  await page.screenshot({ path: path.join(OUT, `config-${paso}.png`), fullPage: true });
}

if (paso === 'estado-invitacion') {
  const url = BASE + '/wp-admin/admin.php?page=akb-rewards-settings';
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForTimeout(600);
  inf.select = await page.evaluate(() => {
    const s = document.querySelector('[name=akb_rewards_invite_status]');
    if (!s) return { error: 'no existe el select' };
    return {
      etiqueta: s.closest('tr')?.querySelector('th,label')?.textContent.trim(),
      valor: s.value,
      opciones: [...s.options].map((o) => ({ v: o.value, t: o.text })),
    };
  });
  const destino = process.env.GATE3_ESTADO || 'processing';
  await page.selectOption('[name=akb_rewards_invite_status]', destino);
  await guardar('#submit, input[type=submit], button[type=submit]');
  inf.trasGuardar = await page.evaluate(() => document.querySelector('[name=akb_rewards_invite_status]')?.value);
  await page.screenshot({ path: path.join(OUT, `config-estado-invitacion-${destino}.png`), fullPage: true });
}

if (paso === 'ventana-despacho') {
  const url = BASE + '/wp-admin/admin.php?page=akibara-panel&tab=copys';
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForTimeout(600);
  inf.encontrada = await page.evaluate(() => !!document.querySelector('#akb_copy_promise_ready_window'));
  inf.valorAntes = await page.evaluate(() => document.querySelector('#akb_copy_promise_ready_window')?.value);
  inf.phAntes = await page.evaluate(() => document.querySelector('#akb_copy_promise_ready_window')?.placeholder);
  if (inf.encontrada) {
    await page.fill('#akb_copy_promise_ready_window', '4-6 días hábiles GATE3');
    await guardar('button[name=akibara_panel_save], input[name=akibara_panel_save], p.submit button, p.submit input[type=submit]');
    inf.trasGuardar = await page.evaluate(() => document.querySelector('#akb_copy_promise_ready_window')?.value);
  }
  await page.screenshot({ path: path.join(OUT, 'config-ventana-despacho.png'), fullPage: true });
}

if (paso === 'aviso-santiago') {
  const url = BASE + '/wp-admin/admin.php?page=akibara-panel&tab=native_cart';
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForTimeout(600);
  inf.tabs = await page.evaluate(() => [...document.querySelectorAll('nav.nav-tab-wrapper a, .nav-tab-wrapper a')].map((a) => a.textContent.trim() + ' => ' + a.getAttribute('href')));
  inf.encontrada = await page.evaluate(() => !!document.querySelector('#akibara_blocks_shipping_hint_text'));
  inf.valorAntes = await page.evaluate(() => document.querySelector('#akibara_blocks_shipping_hint_text')?.value);
  if (inf.encontrada) {
    await page.fill('#akibara_blocks_shipping_hint_text', 'GATE3: ¿Fuera de Santiago? Ingresa tu dirección para ver el despacho.');
    await guardar('button[name=akibara_panel_save], input[name=akibara_panel_save], p.submit button, p.submit input[type=submit]');
    inf.trasGuardar = await page.evaluate(() => document.querySelector('#akibara_blocks_shipping_hint_text')?.value);
  }
  await page.screenshot({ path: path.join(OUT, 'config-aviso-santiago.png'), fullPage: true });
}

fs.writeFileSync(path.join(OUT, `informe-config-${paso}.json`), JSON.stringify(inf, null, 2));
console.log(JSON.stringify(inf, null, 2));
await browser.close();
