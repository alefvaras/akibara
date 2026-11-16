import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';

async function run() {
    fs.mkdirSync('output/playwright', { recursive: true });
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1280, height: 800 }
    });
    const page = await context.newPage();

    console.log('Navigating to local mirror http://localhost:8080/tienda/...');
    await page.goto('http://localhost:8080/tienda/', { waitUntil: 'networkidle' });

    // Focus desktop search input
    console.log('Focusing search input...');
    const searchInput = page.locator('#akb-search-input');
    await searchInput.focus();
    await page.waitForTimeout(500);

    // Check search box styles
    const searchBox = page.locator('.akibara-search');
    const searchBoxShadow = await searchBox.evaluate(el => window.getComputedStyle(el).boxShadow);
    const searchOutline = await searchInput.evaluate(el => window.getComputedStyle(el).outline);
    console.log('Search box boxShadow:', searchBoxShadow);
    console.log('Search input outline:', searchOutline);

    await page.screenshot({ path: 'output/playwright/local-search-focus.png' });

    // Tab into elements
    console.log('Tabbing through elements...');
    await page.keyboard.press('Tab');
    await page.waitForTimeout(300);
    await page.screenshot({ path: 'output/playwright/local-tab-focus.png' });

    // Focus checkbox in sidebar
    const firstCheckbox = page.locator('.akb-facet-item input[type="checkbox"]').first();
    if (await firstCheckbox.count() > 0) {
        await firstCheckbox.focus();
        await page.waitForTimeout(300);
        const cbOutline = await firstCheckbox.evaluate(el => window.getComputedStyle(el).outline);
        console.log('Checkbox focus outline:', cbOutline);
        await page.screenshot({ path: 'output/playwright/local-checkbox-focus.png' });
    }

    await browser.close();
    console.log('Local mirror focus QA completed successfully.');
}

run().catch(err => {
    console.error(err);
    process.exit(1);
});
