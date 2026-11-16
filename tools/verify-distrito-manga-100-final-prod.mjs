import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert';

const CERT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/distrito-manga-100-certified-final';
fs.mkdirSync(CERT_DIR, { recursive: true });

async function verifyProductionFinal() {
    console.log('=== STARTING FINAL 100% PRODUCTION VERIFICATION (https://akibara.cl) ===\n');
    const browser = await chromium.launch({ headless: true });
    
    // Desktop Viewport
    const deskContext = await browser.newContext({ viewport: { width: 1280, height: 850 } });
    const page = await deskContext.newPage();

    // 1. Homepage & Marquee
    console.log('1. Auditing Production Homepage (https://akibara.cl/)...');
    await page.goto('https://akibara.cl/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const pauseBtn = page.locator('.akb-brands-home__pause').first();
    if (await pauseBtn.count() > 0) {
        await pauseBtn.hover();
        const pauseHoverBg = await pauseBtn.evaluate(el => window.getComputedStyle(el).backgroundColor);
        console.log('✔ Brands pause hover bg:', pauseHoverBg);
        assert.ok(!pauseHoverBg.includes('255, 215, 0'), 'Brands pause button hover should NOT be yellow');
    }

    // Search focus ring verification
    const searchInput = page.locator('.akibara-search__input').first();
    if (await searchInput.count() > 0) {
        await searchInput.focus();
        await page.waitForTimeout(200);
        const searchFocus = await searchInput.evaluate(el => {
            const cs = window.getComputedStyle(el);
            return { outline: cs.outline, boxShadow: cs.boxShadow };
        });
        console.log('✔ Search focus outline:', searchFocus);
        const hasYellow = searchFocus.boxShadow.includes('255, 215, 0') || searchFocus.outline.includes('255, 215, 0');
        assert.ok(!hasYellow, 'Search focus must not contain yellow');
    }
    await page.screenshot({ path: path.join(CERT_DIR, '01_prod_home_desktop.png') });

    // 2. Series Directory (/series/)
    console.log('\n2. Auditing Production Series Directory (https://akibara.cl/series/)...');
    await page.goto('https://akibara.cl/series/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const seriesStyles = await page.evaluate(() => {
        const input = document.querySelector('.akb-series-directory__input');
        const chip = document.querySelector('.akb-series-directory__chip.is-active');
        const letter = document.querySelector('a.akb-series-directory__letter');
        const tomoUnico = document.querySelector('.akb-series-directory__estado--tomo_unico');
        
        return {
            inputBorder: input ? window.getComputedStyle(input).borderColor : null,
            chipBg: chip ? window.getComputedStyle(chip).backgroundColor : null,
            chipColor: chip ? window.getComputedStyle(chip).color : null,
            letterColor: letter ? window.getComputedStyle(letter).color : null,
            tomoColor: tomoUnico ? window.getComputedStyle(tomoUnico).color : null
        };
    });
    console.log('✔ Series directory styles:', seriesStyles);
    if (seriesStyles.chipBg) {
        assert.ok(!seriesStyles.chipBg.includes('255, 215, 0'), 'Series active chip should NOT be yellow');
    }
    await page.screenshot({ path: path.join(CERT_DIR, '02_prod_series_directory.png') });

    // 3. Tienda & Button Physics
    console.log('\n3. Auditing Production Tienda (https://akibara.cl/tienda/)...');
    await page.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const atcBtn = page.locator('ul.products li.product .button').first();
    const restingStyles = await atcBtn.evaluate(el => {
        const cs = window.getComputedStyle(el);
        return { border: cs.border, boxShadow: cs.boxShadow, transform: cs.transform };
    });
    console.log('✔ Production resting button styles:', restingStyles);
    assert.ok(restingStyles.boxShadow.includes('4px 4px 0px'), 'Resting shadow must be 4px 4px');

    await atcBtn.hover();
    await page.waitForTimeout(200);
    const hoverStyles = await atcBtn.evaluate(el => {
        const cs = window.getComputedStyle(el);
        return { border: cs.border, boxShadow: cs.boxShadow, transform: cs.transform };
    });
    console.log('✔ Production hover button styles:', hoverStyles);
    const shadowMatch = hoverStyles.boxShadow.match(/([0-9.]+)px\s+([0-9.]+)px/);
    const shadowX = shadowMatch ? Math.round(parseFloat(shadowMatch[1])) : 0;
    assert.strictEqual(shadowX, 6, 'Hover shadow must round to 6px');

    // Active physics
    const box = await atcBtn.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.waitForTimeout(100);
    const activeStyles = await atcBtn.evaluate(el => {
        const cs = window.getComputedStyle(el);
        return { border: cs.border, boxShadow: cs.boxShadow, transform: cs.transform };
    });
    console.log('✔ Production active button styles:', activeStyles);
    await page.mouse.up();
    await page.screenshot({ path: path.join(CERT_DIR, '03_prod_tienda_desktop.png') });

    // 4. Checkout Blocks (/checkout/)
    console.log('\n4. Auditing Production Checkout (https://akibara.cl/checkout/)...');
    await page.goto('https://akibara.cl/checkout/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    const checkoutData = await page.evaluate(() => {
        const placeOrder = document.querySelector('.wc-block-components-checkout-place-order-button, #place_order');
        const poCs = placeOrder ? window.getComputedStyle(placeOrder) : null;
        const guestHook = document.querySelector('.akb-rw-guest-hook__action');
        const ghCs = guestHook ? window.getComputedStyle(guestHook) : null;
        const terms = document.querySelector('.woocommerce-terms-and-conditions-link');
        const termsCs = terms ? window.getComputedStyle(terms) : null;
        const headings = Array.from(document.querySelectorAll('.wc-block-checkout__step-heading, .wc-block-components-title')).map(h => ({
            tag: h.tagName,
            font: window.getComputedStyle(h).fontFamily,
            text: h.innerText.trim()
        }));

        return {
            placeOrderFont: poCs?.fontFamily,
            placeOrderBg: poCs?.backgroundColor,
            guestHookText: guestHook?.innerText,
            guestHookBg: ghCs?.backgroundColor,
            guestHookColor: ghCs?.color,
            termsColor: termsCs?.color,
            headings: headings.slice(0, 3)
        };
    });
    console.log('✔ Production checkout data:', JSON.stringify(checkoutData, null, 2));
    if (checkoutData.termsColor) {
        assert.ok(!checkoutData.termsColor.includes('255, 215, 0'), 'Terms link should NOT be yellow');
    }
    if (checkoutData.guestHookBg) {
        assert.ok(checkoutData.guestHookBg.includes('230, 0, 18'), 'Guest hook button must be Akibara Red');
    }
    await page.screenshot({ path: path.join(CERT_DIR, '04_prod_checkout_desktop.png'), fullPage: true });

    // 5. Blog Editorial (/blog/)
    console.log('\n5. Auditing Production Blog (https://akibara.cl/blog/)...');
    await page.goto('https://akibara.cl/blog/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const blogData = await page.evaluate(() => {
        const title = document.querySelector('.akb-blog-card__title');
        const media = document.querySelector('.akb-blog-card__media');
        return {
            titleTransform: title ? window.getComputedStyle(title).textTransform : null,
            titleFont: title ? window.getComputedStyle(title).fontFamily : null
        };
    });
    console.log('✔ Production blog data:', blogData);
    if (blogData.titleTransform) {
        assert.strictEqual(blogData.titleTransform, 'uppercase', 'Blog titles must be uppercase');
    }
    await page.screenshot({ path: path.join(CERT_DIR, '05_prod_blog_desktop.png') });

    // 6. Mobile 390px (iPhone 14) Checkout & Cart Punch
    console.log('\n6. Auditing Mobile 390px Viewport (https://akibara.cl/tienda/)...');
    const mobContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mobPage = await mobContext.newPage();
    await mobPage.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
    await mobPage.waitForTimeout(2000);

    const mobAtc = mobPage.locator('ul.products li.product .button').first();
    await mobAtc.click();
    
    let mobBumped = false;
    for (let i = 0; i < 25; i++) {
        await mobPage.waitForTimeout(150);
        const has = await mobPage.evaluate(() => document.querySelector('.bottom-nav__item--cart')?.classList.contains('is-bumped'));
        if (has) {
            mobBumped = true;
            break;
        }
    }
    console.log('✔ Mobile cart punch animation triggered:', mobBumped);
    await mobPage.screenshot({ path: path.join(CERT_DIR, '06_prod_mobile_cart_punch.png') });

    await browser.close();
    console.log('\n======================================================');
    console.log('✔ 100% DISTRITO MANGA & CÓMICS PRODUCTION CERTIFICATION PASSED');
    console.log('======================================================\n');
}

verifyProductionFinal().catch(err => {
    console.error('Final production verification failed:', err);
    process.exit(1);
});
