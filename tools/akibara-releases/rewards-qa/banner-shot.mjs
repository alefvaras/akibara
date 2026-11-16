import { chromium } from 'playwright';
const b = await chromium.launch();
const ctx = await b.newContext({ userAgent: 'Mozilla/5.0 AkibaraQA/1.0 banner', viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true });
const p = await ctx.newPage();
await p.goto('https://akibara.cl', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1500);
const bar = p.locator('.akb-announcement-bar, [class*="akb-ann"]').first();
await bar.screenshot({ path: 'banner-mensaje-1.png' }).catch(() => p.screenshot({ path: 'banner-mensaje-1.png', clip: { x: 0, y: 0, width: 360, height: 60 } }));
// El segundo mensaje entra con el botón «siguiente».
const next = p.locator('[class*="ann"] button, .akb-ann-next').first();
if (await next.count()) { await next.click(); await p.waitForTimeout(900); }
await bar.screenshot({ path: 'banner-mensaje-2.png' }).catch(() => p.screenshot({ path: 'banner-mensaje-2.png', clip: { x: 0, y: 0, width: 360, height: 60 } }));
console.log('capturas listas');
await b.close();
