import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright.default || playwright;
import fs from 'node:fs';

async function run() {
    const browser = await chromium.launch({ headless: true });
    const outDir = '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/identity-inspection';
    fs.mkdirSync(outDir, { recursive: true });

    // Desktop
    const desk = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await desk.newPage();

    // 1. Home
    console.log('Navigating to Home https://akibara.cl...');
    await page.goto('https://akibara.cl', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await page.screenshot({ path: `${outDir}/01_home_above_fold.png` });
    
    await page.evaluate(() => window.scrollBy(0, 750));
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${outDir}/02_home_manga_carousel.png` });
    
    await page.evaluate(() => window.scrollBy(0, 950));
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${outDir}/03_home_brands_street.png` });

    // 2. Tienda / Catálogo
    console.log('Navigating to Tienda https://akibara.cl/tienda/...');
    await page.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await page.screenshot({ path: `${outDir}/04_tienda_grid.png` });

    // 3. PDP
    console.log('Navigating to PDP...');
    const firstProduct = await page.$('ul.products li.product a.woocommerce-LoopProduct-link');
    if (firstProduct) {
        await firstProduct.click();
        await page.waitForLoadState('domcontentloaded');
        await page.waitForTimeout(2500);
        await page.screenshot({ path: `${outDir}/05_pdp_desktop.png` });

        // Add to cart to open cart drawer
        const atcBtn = await page.$('.single_add_to_cart_button');
        if (atcBtn) {
            await atcBtn.click();
            await page.waitForTimeout(2500);
            await page.screenshot({ path: `${outDir}/06_cart_drawer.png` });
        }
    }

    // 4. Mobile 390px
    console.log('Checking Mobile 390px...');
    const mob = await browser.newContext({ viewport: { width: 390, height: 844 }, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1' });
    const mobPage = await mob.newPage();
    await mobPage.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
    await mobPage.waitForTimeout(2500);
    await mobPage.screenshot({ path: `${outDir}/07_tienda_mobile.png` });

    await browser.close();
    console.log('Inspection screenshots saved in:', outDir);
}

run().catch(console.error);
