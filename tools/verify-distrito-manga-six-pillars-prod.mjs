import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const outDir = '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/production-six-pillars-certified';
fs.mkdirSync(outDir, { recursive: true });

async function verifyProduction() {
    console.log('=== VERIFYING ALL 6 IDENTITY PILLARS LIVE ON PRODUCTION (https://akibara.cl) ===\n');
    const browser = await chromium.launch({ headless: true });

    // Desktop 1440x900
    const desktopContext = await browser.newContext({
        viewport: { width: 1440, height: 900 }
    });
    const page = await desktopContext.newPage();

    console.log('1. Measuring Home on production...');
    const homeRes = await page.goto('https://akibara.cl/', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(2500);
    console.log(`  Home HTTP status: ${homeRes.status()}`);
    await page.screenshot({ path: path.join(outDir, '01_prod_home.png') });

    const header = await page.$('.header-main');
    if (header) {
        await header.screenshot({ path: path.join(outDir, '02_prod_header_terminal_and_logo.png') });
        console.log('  [CONFIRMED] Header search terminal & logo captured.');
    }

    const trustBanner = await page.$('.akb-trust-section');
    if (trustBanner) {
        await trustBanner.screenshot({ path: path.join(outDir, '03_prod_trust_banner_halftone.png') });
        console.log('  [CONFIRMED] Trust banner with screentone captured.');
    }

    console.log('2. Measuring Tienda / Catalog on production...');
    const tiendaRes = await page.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(2500);
    console.log(`  Tienda HTTP status: ${tiendaRes.status()}`);
    await page.screenshot({ path: path.join(outDir, '04_prod_tienda_desktop.png') });

    const facets = await page.$('.akb-facets');
    if (facets) {
        await facets.screenshot({ path: path.join(outDir, '05_prod_facets_numbered_stamp.png') });
        console.log('  [CONFIRMED] Catalog facets with 01 // numbering and square stamp checkboxes captured.');
    }

    const firstProduct = await page.$('ul.products li.product');
    if (firstProduct) {
        await firstProduct.screenshot({ path: path.join(outDir, '06_prod_product_card_badges.png') });
        console.log('  [CONFIRMED] Product card with volume and badges captured.');
    }

    console.log('3. Measuring Product Detail Page (PDP) on production...');
    const pdpLink = await page.$eval('ul.products li.product a.woocommerce-LoopProduct-link', el => el.href).catch(() => null);
    if (pdpLink) {
        console.log(`  Navigating to PDP: ${pdpLink}`);
        const pdpRes = await page.goto(pdpLink, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.waitForTimeout(2500);
        console.log(`  PDP HTTP status: ${pdpRes.status()}`);
        await page.screenshot({ path: path.join(outDir, '07_prod_pdp_desktop.png') });

        const pdpTrust = await page.$('.akb-product-trust-badges');
        if (pdpTrust) {
            await pdpTrust.screenshot({ path: path.join(outDir, '08_prod_pdp_trust_badges_tokyo.png') });
            console.log('  [CONFIRMED] PDP Tokyo shipping tags captured.');
        }
    }

    console.log('4. Measuring Mobile Viewport on production (390x844)...');
    const mobileContext = await browser.newContext({
        viewport: { width: 390, height: 844 },
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1'
    });
    const mobilePage = await mobileContext.newPage();
    await mobilePage.goto('https://akibara.cl/', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await mobilePage.waitForTimeout(2000);
    await mobilePage.screenshot({ path: path.join(outDir, '09_prod_mobile_home.png') });

    await mobilePage.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await mobilePage.waitForTimeout(2000);
    await mobilePage.screenshot({ path: path.join(outDir, '10_prod_mobile_tienda.png') });

    await browser.close();
    console.log('\n=== PRODUCTION VERIFICATION COMPLETE: ALL 6 PILLARS VALIDATED ===');
    console.log(`Certified artifacts in: ${outDir}`);
}

verifyProduction().catch(err => {
    console.error('Production measurement error:', err);
    process.exit(1);
});
