// Gate de correos — render de cada correo desde la API de Mailpit a 375/600/900.
// Mide overflow, cortes, CTA táctiles, paleta computada, peso, enlaces y alt.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const MAILPIT = 'http://localhost:8025';
const OUT = process.env.GATE3_OUT;
const ANCHOS = [375, 600, 900];

const INVENTARIO = JSON.parse(fs.readFileSync(process.env.GATE3_INV, 'utf8'));

const j = async (u) => (await fetch(MAILPIT + u)).json();
const t = async (u) => (await fetch(MAILPIT + u)).text();

function analizaFuente(html) {
  const styleTags = (html.match(/<style[\s>]/gi) || []).length;
  const styleAttrs = (html.match(/\sstyle="/gi) || []).length;
  const bytes = Buffer.byteLength(html, 'utf8');
  const enStyleTag = [...html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/gi)]
    .map((m) => m[1]).join('');
  return {
    bytes,
    kb: +(bytes / 1024).toFixed(1),
    supera102kb: bytes > 102 * 1024,
    styleTags,
    styleAttrs,
    bytesEnStyleTag: Buffer.byteLength(enStyleTag, 'utf8'),
    soloMediaQueriesEnStyle: enStyleTag.trim().length > 0
      && enStyleTag.replace(/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/g, '').trim().length === 0,
  };
}

const rgbHex = (rgb) => {
  const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(rgb || '');
  if (!m) return rgb;
  return '#' + [1, 2, 3].map((i) => (+m[i]).toString(16).padStart(2, '0')).join('').toUpperCase();
};

const medir = async (page, ancho) => page.evaluate((ancho) => {
  const abs = (h) => /^https?:\/\//i.test(h);
  const de = document.documentElement;
  const desbordes = [];
  for (const el of document.querySelectorAll('*')) {
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    if (r.right > ancho + 1 || r.left < -1) {
      desbordes.push({
        tag: el.tagName.toLowerCase(),
        id: el.id || '',
        cls: (el.className && el.className.toString().slice(0, 40)) || '',
        left: Math.round(r.left), right: Math.round(r.right), w: Math.round(r.width),
        txt: (el.textContent || '').trim().slice(0, 40),
      });
    }
  }
  const enlaces = [...document.querySelectorAll('a[href]')].map((a) => {
    const cs = getComputedStyle(a);
    const r = a.getBoundingClientRect();
    return {
      href: a.getAttribute('href'),
      absoluto: abs(a.getAttribute('href') || ''),
      texto: (a.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60),
      bg: cs.backgroundColor,
      color: cs.color,
      display: cs.display,
      w: Math.round(r.width), h: Math.round(r.height),
      padding: cs.padding,
      fontFamily: cs.fontFamily,
      fontSize: cs.fontSize,
    };
  });
  const imgs = [...document.querySelectorAll('img')].map((i) => ({
    src: i.getAttribute('src'),
    alt: i.getAttribute('alt'),
    tieneAlt: i.hasAttribute('alt'),
    altVacio: (i.getAttribute('alt') || '') === '',
    wAttr: i.getAttribute('width'), hAttr: i.getAttribute('height'),
    natural: i.naturalWidth + 'x' + i.naturalHeight,
    cargada: i.complete && i.naturalWidth > 0,
    render: Math.round(i.getBoundingClientRect().width) + 'x' + Math.round(i.getBoundingClientRect().height),
  }));
  const body = getComputedStyle(document.body);
  const cont = document.querySelector('#template_card, #wrapper, table');
  const fuentes = [...new Set([...document.querySelectorAll('p,td,h1,h2,a,div')]
    .map((e) => getComputedStyle(e).fontFamily).filter(Boolean))];
  return {
    scrollWidth: de.scrollWidth,
    clientWidth: de.clientWidth,
    scrollHeight: de.scrollHeight,
    overflowH: de.scrollWidth - ancho,
    desbordes: desbordes.slice(0, 12),
    nDesbordes: desbordes.length,
    enlaces,
    imgs,
    bodyBg: body.backgroundColor,
    bodyFont: body.fontFamily,
    anchoContenedor: cont ? Math.round(cont.getBoundingClientRect().width) : null,
    fuentes,
  };
}, ancho);

const browser = await chromium.launch();
const informe = [];

for (const item of INVENTARIO) {
  const msg = await j(`/api/v1/message/${item.id}`);
  const raw = await t(`/api/v1/message/${item.id}/raw`);
  const headers = await j(`/api/v1/message/${item.id}/headers`);
  const html = msg.HTML || '';
  const fuente = analizaFuente(html);

  // ¿Hay parte text/plain REAL en el MIME?
  const ctPrincipal = (raw.match(/^Content-Type:\s*(.+)$/im) || [])[1] || '';
  const partesPlain = (raw.match(/Content-Type:\s*text\/plain/gi) || []).length;

  const reg = {
    n: item.n, nombre: item.nombre, id: item.id,
    asunto: msg.Subject,
    para: (msg.To || []).map((x) => x.Address).join(','),
    contentTypePrincipal: ctPrincipal.trim(),
    multipart: /multipart\/alternative/i.test(ctPrincipal),
    partesTextPlain: partesPlain,
    plainDerivadoMailpitLen: (msg.Text || '').length,
    listUnsubscribe: headers['List-Unsubscribe'] || null,
    listUnsubscribePost: headers['List-Unsubscribe-Post'] || null,
    fuente,
    anchos: {},
  };

  const ctx = await browser.newContext({ deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  for (const ancho of ANCHOS) {
    await page.setViewportSize({ width: ancho, height: 900 });
    await page.setContent(html, { waitUntil: 'load' });
    await page.waitForTimeout(400);
    const m = await medir(page, ancho);
    m.enlaces = m.enlaces.map((e) => ({ ...e, bgHex: rgbHex(e.bg), colorHex: rgbHex(e.color) }));
    m.bodyBgHex = rgbHex(m.bodyBg);
    reg.anchos[ancho] = m;
    const png = path.join(OUT, `correo-${String(item.n).padStart(2, '0')}-${item.slug}-${ancho}.png`);
    await page.screenshot({ path: png, fullPage: true });
  }
  await ctx.close();
  informe.push(reg);
  console.log(`[${item.n}] ${item.nombre} — ${msg.Subject}`);
}

await browser.close();
fs.writeFileSync(path.join(OUT, 'informe-correos.json'), JSON.stringify(informe, null, 2));
console.log('\nOK — informe en', path.join(OUT, 'informe-correos.json'));
