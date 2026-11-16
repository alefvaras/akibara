import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import assert from 'node:assert';
import fs from 'node:fs';

async function verifyProduction() {
    console.log('=== VERIFYING DISTRITO MANGA ULTRA LIVE ON PRODUCTION (https://akibara.cl) ===\n');
    const browser = await chromium.launch({ headless: true });

    // Output directory for certified screenshots
    const outDir = '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/distrito-manga-ultra-certified';
    fs.mkdirSync(outDir, { recursive: true });

    // 1. Tienda on Desktop (1440x900)
    console.log('1. Checking Tienda / Catálogo on https://akibara.cl/tienda/...');
    const deskContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await deskContext.newPage();
    await page.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    const cardStyles = await page.evaluate(() => {
        const card = document.querySelector('ul.products li.product');
        if (!card) return null;
        const cs = window.getComputedStyle(card);
        const btn = card.querySelector('.button');
        const bcs = btn ? window.getComputedStyle(btn) : null;
        const vol = card.querySelector('.akb-vol');
        const vcs = vol ? window.getComputedStyle(vol) : null;
        return {
            cardBg: cs.backgroundColor,
            cardBorder: cs.border,
            cardShadow: cs.boxShadow,
            btnClipPath: bcs ? bcs.clipPath : null,
            btnTransform: bcs ? bcs.transform : null,
            btnFilter: bcs ? bcs.filter : null,
            volBg: vcs ? vcs.backgroundColor : null,
            volColor: vcs ? vcs.color : null,
            volTransform: vcs ? vcs.transform : null
        };
    });

    console.log('Live Production Computed Card Styles:\n', JSON.stringify(cardStyles, null, 2));
    assert.ok(cardStyles.cardShadow.includes('rgb(0, 0, 0)') || cardStyles.cardShadow.includes('rgba(0, 0, 0)'), 'Card must have solid shadow on prod');
    assert.ok(cardStyles.volBg.includes('230, 0, 18') || cardStyles.volBg.includes('rgb(230, 0, 18)'), 'Vol tag must have red background on prod');

    await page.screenshot({ path: `${outDir}/01_prod_tienda_desktop.png` });
    console.log(`Saved ${outDir}/01_prod_tienda_desktop.png`);

    // 2. Mobile Tienda (390x844 - iPhone 14)
    console.log('\n2. Checking Mobile Tienda on https://akibara.cl/tienda/...');
    const mobContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mobPage = await mobContext.newPage();
    await mobPage.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
    await mobPage.waitForTimeout(2000);

    const hasHorizontalOverflow = await mobPage.evaluate(() => {
        return document.documentElement.scrollWidth > document.documentElement.clientWidth;
    });
    console.log('Live Mobile horizontal overflow detected:', hasHorizontalOverflow);
    assert.strictEqual(hasHorizontalOverflow, false, 'Mobile prod must not have horizontal scrollbar');

    await mobPage.screenshot({ path: `${outDir}/02_prod_tienda_mobile.png` });
    console.log(`Saved ${outDir}/02_prod_tienda_mobile.png`);

    // 3. PDP on Desktop
    console.log('\n3. Checking Live Product PDP on https://akibara.cl/product/one-piece-110-ivrea-argentina/ (or first card link)...');
    const pdpUrl = await page.evaluate(() => {
        const link = document.querySelector('ul.products li.product a.woocommerce-LoopProduct-link');
        return link ? link.href : 'https://akibara.cl/product/one-piece-110-ivrea-argentina/';
    });
    console.log(`Navigating to PDP: ${pdpUrl}`);
    await page.goto(pdpUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const pdpStyles = await page.evaluate(() => {
        const titleVol = document.querySelector('.product_title.entry-title .akb-vol');
        const vcs = titleVol ? window.getComputedStyle(titleVol) : null;
        const btn = document.querySelector('.single_add_to_cart_button');
        const bcs = btn ? window.getComputedStyle(btn) : null;
        return {
            titleVolBg: vcs ? vcs.backgroundColor : null,
            titleVolColor: vcs ? vcs.color : null,
            titleVolTransform: vcs ? vcs.transform : null,
            btnClipPath: bcs ? bcs.clipPath : null,
            btnTransform: bcs ? bcs.transform : null
        };
    });

    console.log('Live Production PDP Styles:\n', JSON.stringify(pdpStyles, null, 2));
    assert.ok(pdpStyles.titleVolBg.includes('230, 0, 18'), 'PDP Vol tag must have red background');

    await page.screenshot({ path: `${outDir}/03_prod_pdp_desktop.png` });
    console.log(`Saved ${outDir}/03_prod_pdp_desktop.png`);

    // 4. Home Page Brands Marquee on Desktop
    console.log('\n4. Checking Live Home Page Marquee on https://akibara.cl/...');
    await page.goto('https://akibara.cl/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await page.evaluate(() => window.scrollBy(0, 900));
    await page.waitForTimeout(1000);

    await page.screenshot({ path: `${outDir}/04_prod_home_scrolled.png` });
    console.log(`Saved ${outDir}/04_prod_home_scrolled.png`);

    await browser.close();
    console.log('\n=== LIVE PRODUCTION MEASUREMENT AND CERTIFICATION 100% COMPLETE ===');
}

verifyProduction().catch(err => {
    console.error('Production Verification Failed:', err);
    process.exit(1);
});
