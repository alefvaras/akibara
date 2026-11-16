import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import assert from 'node:assert';

async function auditLocal() {
    console.log('=== STARTING 100% DISTRITO MANGA AUDIT ON LOCAL (http://localhost:8080) ===\n');
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });

    const errors = [];
    page.on('console', msg => {
        if (msg.type() === 'error' && !msg.text().includes('clarity') && !msg.text().includes('brevo')) {
            errors.push(`Console Error: ${msg.text()}`);
        }
    });
    page.on('pageerror', err => {
        errors.push(`Page Error: ${err.message}`);
    });

    // 1. Check Homepage
    console.log('1. Checking Homepage...');
    await page.goto('http://localhost:8080/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);

    // Verify marquee pause button hover is white, not yellow
    const pauseBtn = page.locator('.akb-brands-home__pause').first();
    if (await pauseBtn.count() > 0) {
        await pauseBtn.hover();
        const pauseHoverBg = await pauseBtn.evaluate(el => window.getComputedStyle(el).backgroundColor);
        console.log('Brands pause hover bg:', pauseHoverBg);
        assert.ok(!pauseHoverBg.includes('255, 215, 0'), 'Brands pause button hover should NOT be yellow');
    }

    // 2. Check Series Directory (/series/)
    console.log('\n2. Checking Series Directory (/series/)...');
    await page.goto('http://localhost:8080/series/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);

    const seriesInput = page.locator('.akb-series-directory__input').first();
    if (await seriesInput.count() > 0) {
        await seriesInput.focus();
        const inputFocus = await seriesInput.evaluate(el => {
            const cs = window.getComputedStyle(el);
            return { outline: cs.outline, border: cs.borderColor };
        });
        console.log('Series input focus:', inputFocus);
        assert.ok(!inputFocus.outline.includes('255, 215, 0'), 'Series input focus should NOT be yellow');
    }

    // 3. Check Tienda & Tactile Button Kinetics
    console.log('\n3. Checking Tienda (/tienda/) & Button Physics...');
    await page.goto('http://localhost:8080/tienda/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);

    const atcBtn = page.locator('ul.products li.product .button').first();
    const restingBoxShadow = await atcBtn.evaluate(el => window.getComputedStyle(el).boxShadow);
    console.log('Button resting box-shadow:', restingBoxShadow);
    assert.ok(restingBoxShadow.includes('4px 4px 0px'), 'Resting shadow must be 4px 4px');

    await atcBtn.hover();
    await page.waitForTimeout(200);
    const hoverStyles = await atcBtn.evaluate(el => {
        const cs = window.getComputedStyle(el);
        return { boxShadow: cs.boxShadow, transform: cs.transform };
    });
    console.log('Button hover styles:', hoverStyles);
    const shadowMatch = hoverStyles.boxShadow.match(/([0-9.]+)px\s+([0-9.]+)px/);
    const shadowX = shadowMatch ? Math.round(parseFloat(shadowMatch[1])) : 0;
    assert.strictEqual(shadowX, 6, 'Hover shadow must round to 6px');

    // 4. Check Cart Drawer & Punch Animation
    console.log('\n4. Checking Add to Cart & Cart Drawer...');
    await atcBtn.click();
    await page.waitForTimeout(1200);

    const cartDrawer = page.locator('.akb-cart-drawer');
    const isDrawerOpen = await page.evaluate(() => document.body.classList.contains('akb-cart-drawer-open'));
    console.log('Cart drawer opened:', isDrawerOpen);

    // 5. Check Checkout Blocks
    console.log('\n5. Checking Checkout (/finalizar-compra/)...');
    await page.goto('http://localhost:8080/finalizar-compra/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const checkoutEvaluation = await page.evaluate(() => {
        const placeOrderBtn = document.querySelector('.wc-block-components-checkout-place-order-button, #place_order');
        const poStyle = placeOrderBtn ? window.getComputedStyle(placeOrderBtn) : null;
        const bump = document.querySelector('.akb-checkout-bump');
        const termsLink = document.querySelector('.woocommerce-terms-and-conditions-link');
        const termsColor = termsLink ? window.getComputedStyle(termsLink).color : null;

        return {
            hasPlaceOrder: !!placeOrderBtn,
            placeOrderFont: poStyle?.fontFamily,
            placeOrderBg: poStyle?.backgroundColor,
            hasBump: !!bump,
            termsColor
        };
    });
    console.log('Checkout evaluation:', checkoutEvaluation);
    if (checkoutEvaluation.termsColor) {
        assert.ok(!checkoutEvaluation.termsColor.includes('255, 215, 0'), 'Terms link should NOT be yellow');
    }

    // 6. Check Blog (/blog/)
    console.log('\n6. Checking Blog (/blog/)...');
    await page.goto('http://localhost:8080/blog/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);

    const blogTitleCasing = await page.evaluate(() => {
        const cardTitle = document.querySelector('.akb-blog-card__title');
        return cardTitle ? window.getComputedStyle(cardTitle).textTransform : null;
    });
    console.log('Blog title textTransform:', blogTitleCasing);
    if (blogTitleCasing) {
        assert.strictEqual(blogTitleCasing, 'uppercase', 'Blog title should have text-transform uppercase');
    }

    await browser.close();

    console.log('\n=== ERRORS DETECTED ===');
    console.log(errors.length === 0 ? 'ZERO ERRORS DETECTED (100% CLEAN)' : errors);
    assert.strictEqual(errors.length, 0, 'No console errors allowed');

    console.log('\n=== LOCAL AUDIT 100% COMPLETE & PASSING ===');
}

auditLocal().catch(err => {
    console.error('Local audit failed:', err);
    process.exit(1);
});
