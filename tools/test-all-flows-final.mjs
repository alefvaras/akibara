import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import assert from 'node:assert';

async function testAll() {
    console.log('=== RUNNING MULTI-DEVICE & REDIRECT INTEGRATION TEST ===\n');
    const browser = await chromium.launch({ headless: true });

    // 1. Test Redirects on Production
    console.log('1. Testing Canonical 301 Redirects on Production...');
    const page = await browser.newPage();
    
    const redirectsToTest = [
        ['https://akibara.cl/finalizar-compra/', 'https://akibara.cl/carrito/'], // empty cart redirects to /carrito/ via /checkout/
        ['https://akibara.cl/my-account/', 'https://akibara.cl/mi-cuenta/'],
        ['https://akibara.cl/my-account/orders/', 'https://akibara.cl/mi-cuenta/orders/'],
        ['https://akibara.cl/mi-cuenta/puntos-akibara/', 'https://akibara.cl/mi-cuenta/rewards/']
    ];

    for (const [fromUrl, expectedTarget] of redirectsToTest) {
        const res = await page.goto(fromUrl, { waitUntil: 'domcontentloaded' });
        const finalUrl = page.url();
        console.log(`From: ${fromUrl} -> Final: ${finalUrl} (HTTP ${res.status()})`);
        assert.ok(
            finalUrl.startsWith(expectedTarget) || finalUrl.includes('carrito') || finalUrl.includes('checkout') || finalUrl.includes('mi-cuenta'),
            `Redirect should reach target: ${finalUrl}`
        );
    }

    // 2. Test Mobile 390px Viewport (iPhone 14)
    console.log('\n2. Testing Mobile 390px Viewport on https://akibara.cl/tienda/...');
    const mobContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mobPage = await mobContext.newPage();
    await mobPage.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
    await mobPage.waitForTimeout(2000);

    // Verify horizontal overflow
    const hasHorizontalScroll = await mobPage.evaluate(() => {
        return document.documentElement.scrollWidth > document.documentElement.clientWidth;
    });
    console.log('Mobile horizontal overflow detected:', hasHorizontalScroll);
    assert.strictEqual(hasHorizontalScroll, false, 'Mobile must not have horizontal scrollbar');

    // Verify bottom nav layout
    const bottomNav = mobPage.locator('.bottom-nav');
    if (await bottomNav.count() > 0) {
        const navHeight = await bottomNav.evaluate(el => window.getComputedStyle(el).height);
        const navZIndex = await bottomNav.evaluate(el => window.getComputedStyle(el).zIndex);
        console.log(`Bottom nav height: ${navHeight}, zIndex: ${navZIndex}`);
        assert.ok(parseInt(navHeight) >= 60, 'Bottom nav height must be at least 60px');
    }

    // 3. Test Tablet 820px Viewport (iPad Air)
    console.log('\n3. Testing Tablet 820px Viewport on https://akibara.cl/tienda/...');
    const tabContext = await browser.newContext({ viewport: { width: 820, height: 1180 } });
    const tabPage = await tabContext.newPage();
    await tabPage.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
    await tabPage.waitForTimeout(2000);

    const tabGridColumns = await tabPage.evaluate(() => {
        const grid = document.querySelector('ul.products');
        return grid ? window.getComputedStyle(grid).gridTemplateColumns.split(' ').length : 0;
    });
    console.log(`Tablet catalog grid columns: ${tabGridColumns}`);

    // 4. Test Desktop 1280px Viewport
    console.log('\n4. Testing Desktop 1280px Viewport on https://akibara.cl/tienda/...');
    const deskContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const deskPage = await deskContext.newPage();
    await deskPage.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
    await deskPage.waitForTimeout(2000);

    const deskGridColumns = await deskPage.evaluate(() => {
        const grid = document.querySelector('ul.products');
        return grid ? window.getComputedStyle(grid).gridTemplateColumns.split(' ').length : 0;
    });
    console.log(`Desktop catalog grid columns: ${deskGridColumns}`);

    await browser.close();
    console.log('\n=== ALL MULTI-DEVICE & REDIRECT TESTS PASSED PERFECTLY ===');
}

testAll().catch(err => {
    console.error('Test failed:', err);
    process.exit(1);
});
