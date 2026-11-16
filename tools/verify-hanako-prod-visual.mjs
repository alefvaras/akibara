import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright.default || playwright;
import fs from 'node:fs';

const outDir = '/Users/alefvaras/.gemini/antigravity/brain/45593591-4eb2-4893-b932-5409716ac763/evidencias';
fs.mkdirSync(outDir, { recursive: true });

async function run() {
    console.log('Launching browser...');
    const browser = await chromium.launch({ headless: true });

    // Desktop Context
    const deskContext = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    });
    const deskPage = await deskContext.newPage();

    // Mobile Context
    const mobContext = await browser.newContext({
        viewport: { width: 390, height: 844 },
        isMobile: true,
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'
    });
    const mobPage = await mobContext.newPage();

    // 1. Serie Hanako-kun (Desktop)
    console.log('1. Checking https://akibara.cl/serie/hanako-kun/ (Desktop)...');
    await deskPage.goto('https://akibara.cl/serie/hanako-kun/', { waitUntil: 'domcontentloaded' });
    await deskPage.waitForTimeout(2500);
    await deskPage.screenshot({ path: `${outDir}/09_prod_serie_hanako_desktop_hero.png` });

    // Scroll down to grid
    await deskPage.evaluate(() => window.scrollBy(0, 650));
    await deskPage.waitForTimeout(1500);
    await deskPage.screenshot({ path: `${outDir}/10_prod_serie_hanako_desktop_grid.png` });

    // 2. Serie Hanako-kun (Mobile)
    console.log('2. Checking https://akibara.cl/serie/hanako-kun/ (Mobile)...');
    await mobPage.goto('https://akibara.cl/serie/hanako-kun/', { waitUntil: 'domcontentloaded' });
    await mobPage.waitForTimeout(2500);
    await mobPage.screenshot({ path: `${outDir}/11_prod_serie_hanako_mobile_hero.png` });

    await mobPage.evaluate(() => window.scrollBy(0, 500));
    await mobPage.waitForTimeout(1500);
    await mobPage.screenshot({ path: `${outDir}/12_prod_serie_hanako_mobile_grid.png` });

    // 3. Serie Después de clase (Desktop)
    console.log('3. Checking https://akibara.cl/serie/hanako-kun-despues-de-clase/ (Desktop)...');
    await deskPage.goto('https://akibara.cl/serie/hanako-kun-despues-de-clase/', { waitUntil: 'domcontentloaded' });
    await deskPage.waitForTimeout(2500);
    await deskPage.screenshot({ path: `${outDir}/13_prod_serie_despues_clase_desktop.png` });

    // 4. Serie Artbooks Illustrations (Desktop)
    console.log('4. Checking https://akibara.cl/serie/hanako-kun-aidairo-illustrations/ (Desktop)...');
    await deskPage.goto('https://akibara.cl/serie/hanako-kun-aidairo-illustrations/', { waitUntil: 'domcontentloaded' });
    await deskPage.waitForTimeout(2500);
    await deskPage.screenshot({ path: `${outDir}/14_prod_serie_illustrations_desktop.png` });

    // 5. PDP Hanako-kun 01 (Desktop)
    console.log('5. Checking https://akibara.cl/hanako-kun-1-ivrea-espana/ (Desktop)...');
    await deskPage.goto('https://akibara.cl/hanako-kun-1-ivrea-espana/', { waitUntil: 'domcontentloaded' });
    await deskPage.waitForTimeout(2500);
    await deskPage.screenshot({ path: `${outDir}/15_prod_pdp_hanako01_desktop.png` });

    // 6. PDP Hanako-kun 01 (Mobile)
    console.log('6. Checking https://akibara.cl/hanako-kun-1-ivrea-espana/ (Mobile)...');
    await mobPage.goto('https://akibara.cl/hanako-kun-1-ivrea-espana/', { waitUntil: 'domcontentloaded' });
    await mobPage.waitForTimeout(2500);
    await mobPage.screenshot({ path: `${outDir}/16_prod_pdp_hanako01_mobile.png` });

    // 7. Series Directory Search for Hanako
    console.log('7. Checking https://akibara.cl/series/?buscar=Hanako (Desktop)...');
    await deskPage.goto('https://akibara.cl/series/?buscar=Hanako', { waitUntil: 'domcontentloaded' });
    await deskPage.waitForTimeout(2500);
    await deskPage.screenshot({ path: `${outDir}/17_prod_series_finder_hanako.png` });

    // Verify key assertions
    const sortingText = await deskPage.evaluate(() => {
        const sel = document.querySelector('select.orderby');
        return sel ? sel.options[sel.selectedIndex]?.text : '';
    });
    console.log(`Active sort option: "${sortingText}"`);

    await browser.close();
    console.log('All visual verification captures complete!');
}

run().catch(err => {
    console.error('Error during visual verification:', err);
    process.exit(1);
});
