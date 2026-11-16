import { chromium } from 'playwright';
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 AkibaraQA';
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA });
const page = await ctx.newPage();
await page.goto('https://akibara.cl/', { waitUntil: 'load' });
const openBtn = page.locator('button[aria-label*="Buscar" i], a[aria-label*="Buscar" i], .akb-search-toggle, .search-toggle').first();
if (await openBtn.count()) { await openBtn.click(); }
const input = page.locator('input[type=search], input[name=s]').first();
await input.click();
await input.fill('chainsaw');
await page.waitForTimeout(1200);
const html = await page.evaluate(() => {
  const panel = document.querySelector('[class*=suggest], [class*=search-results], [role=listbox]');
  return panel ? panel.outerHTML.slice(0, 3000) : 'no panel encontrado';
});
console.log(html);
const texts = await page.evaluate(() => {
  const nodes = document.querySelectorAll('[class*=suggest] a, [class*=search-results] a, [role=option]');
  return Array.from(nodes).map(n => n.textContent.trim().replace(/\s+/g,' ')).filter(Boolean).slice(0, 20);
});
console.log(JSON.stringify(texts, null, 2));
await browser.close();
