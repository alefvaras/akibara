import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const outDir = '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/identity-inspection-v3';
fs.mkdirSync(outDir, { recursive: true });

async function run() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 }
    });
    const page = await context.newPage();

    console.log('1. Checking Home Desktop...');
    await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
    await page.screenshot({ path: path.join(outDir, '01_home_desktop.png') });

    console.log('2. Checking Header Search Terminal & Logo zoom...');
    const header = await page.$('.header-main');
    if (header) {
        await header.screenshot({ path: path.join(outDir, '02_header_terminal_and_logo.png') });
    }

    console.log('3. Checking Tienda / Catalog Grid with Facets & Badges...');
    await page.goto('http://localhost:8080/tienda/', { waitUntil: 'networkidle' });
    await page.screenshot({ path: path.join(outDir, '03_tienda_desktop.png') });

    const sidebar = await page.$('.akb-facets');
    if (sidebar) {
        await sidebar.screenshot({ path: path.join(outDir, '04_catalog_facets_numbered.png') });
    }

    const firstProduct = await page.$('ul.products li.product');
    if (firstProduct) {
        await firstProduct.screenshot({ path: path.join(outDir, '05_product_card_badges.png') });
    }

    console.log('4. Checking Product Detail Page (PDP)...');
    // find a product link
    const productLink = await page.$eval('ul.products li.product a.woocommerce-LoopProduct-link', el => el.href).catch(() => null);
    if (productLink) {
        await page.goto(productLink, { waitUntil: 'networkidle' });
        await page.screenshot({ path: path.join(outDir, '06_pdp_desktop.png') });

        const trustBadges = await page.$('.akb-product-trust-badges');
        if (trustBadges) {
            await trustBadges.screenshot({ path: path.join(outDir, '07_pdp_trust_badges.png') });
        }
    }

    console.log('5. Checking Mobile Viewport...');
    const mobileContext = await browser.newContext({
        viewport: { width: 390, height: 844 },
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1'
    });
    const mobilePage = await mobileContext.newPage();
    await mobilePage.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
    await mobilePage.screenshot({ path: path.join(outDir, '08_mobile_home.png') });

    await mobilePage.goto('http://localhost:8080/tienda/', { waitUntil: 'networkidle' });
    await mobilePage.screenshot({ path: path.join(outDir, '09_mobile_tienda.png') });

    await browser.close();
    console.log('All screenshots captured successfully in:', outDir);
}

run().catch(err => {
    console.error(err);
    process.exit(1);
});
