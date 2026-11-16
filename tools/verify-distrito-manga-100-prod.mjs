import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const ARTIFACT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/distrito-manga-100-certified';
fs.mkdirSync(ARTIFACT_DIR, { recursive: true });

async function verifyProduction() {
    console.log('=== STARTING PRODUCTION 100% DISTRITO MANGA CERTIFICATION (akibara.cl) ===');
    const browser = await chromium.launch({ headless: true });
    
    // 1. Desktop Tienda & Button Physics
    const deskContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await deskContext.newPage();
    
    console.log('Testing Tienda on https://akibara.cl/tienda/ ...');
    await page.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    
    const btn = page.locator('ul.products li.product .button').first();
    const btnBox = await btn.boundingBox();
    
    const restingStyles = await btn.evaluate(el => {
        const cs = window.getComputedStyle(el);
        return {
            border: cs.border,
            boxShadow: cs.boxShadow,
            transform: cs.transform
        };
    });
    console.log('Production resting button styles:', restingStyles);

    await btn.hover();
    await page.waitForTimeout(200);
    const hoverStyles = await btn.evaluate(el => {
        const cs = window.getComputedStyle(el);
        return {
            border: cs.border,
            boxShadow: cs.boxShadow,
            transform: cs.transform
        };
    });
    console.log('Production hover button styles:', hoverStyles);

    await page.mouse.move(btnBox.x + btnBox.width / 2, btnBox.y + btnBox.height / 2);
    await page.mouse.down();
    await page.waitForTimeout(100);
    const activeStyles = await btn.evaluate(el => {
        const cs = window.getComputedStyle(el);
        return {
            border: cs.border,
            boxShadow: cs.boxShadow,
            transform: cs.transform
        };
    });
    console.log('Production active button styles:', activeStyles);
    await page.mouse.up();

    // Search focus ring verification (ensure NO yellow)
    const searchInput = page.locator('.akibara-search__input').first();
    await searchInput.focus();
    await page.waitForTimeout(200);
    const searchFocus = await searchInput.evaluate(el => {
        const cs = window.getComputedStyle(el);
        return {
            outline: cs.outline,
            boxShadow: cs.boxShadow,
            borderColor: cs.borderColor
        };
    });
    console.log('Production search focus styles:', searchFocus);
    
    const hasYellow = searchFocus.boxShadow.includes('255, 215, 0') || searchFocus.outline.includes('255, 215, 0');
    console.log('Search focus ring has yellow:', hasYellow);

    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'prod_100_tienda_desktop.png') });

    // 2. Mobile Viewport & Cart Punch Verification
    console.log('\nTesting Mobile on https://akibara.cl/ (390x844)...');
    const mobContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mobPage = await mobContext.newPage();
    await mobPage.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
    await mobPage.waitForTimeout(2500);

    const mobBtn = mobPage.locator('ul.products li.product .button').first();
    await mobBtn.click();
    let mobBumped = false;
    for (let i = 0; i < 20; i++) {
        await mobPage.waitForTimeout(150);
        const has = await mobPage.evaluate(() => document.querySelector('.bottom-nav__item--cart')?.classList.contains('is-bumped'));
        if (has) {
            mobBumped = true;
            break;
        }
    }
    console.log('Production mobile cart icon punch triggered:', mobBumped);
    await mobPage.screenshot({ path: path.join(ARTIFACT_DIR, 'prod_100_mobile_bump.png') });

    // 3. Checkout Page Verification
    console.log('\nTesting Checkout on https://akibara.cl/checkout/ ...');
    await mobPage.goto('https://akibara.cl/checkout/', { waitUntil: 'domcontentloaded' });
    await mobPage.waitForTimeout(2500);

    const checkoutSummary = await mobPage.evaluate(() => {
        const h1 = document.querySelector('h1')?.innerText;
        const placeOrderBtn = document.querySelector('.wc-block-components-checkout-place-order-button, #place_order');
        const poStyle = placeOrderBtn ? window.getComputedStyle(placeOrderBtn) : null;
        const guestHookBtn = document.querySelector('.akb-rw-guest-hook__action');
        const ghStyle = guestHookBtn ? window.getComputedStyle(guestHookBtn) : null;
        return {
            h1,
            placeOrderFont: poStyle?.fontFamily,
            placeOrderBg: poStyle?.backgroundColor,
            guestHookText: guestHookBtn?.innerText,
            guestHookBg: ghStyle?.backgroundColor,
            guestHookColor: ghStyle?.color,
            guestHookFont: ghStyle?.fontFamily
        };
    });
    console.log('Production checkout evaluation:', JSON.stringify(checkoutSummary, null, 2));
    await mobPage.screenshot({ path: path.join(ARTIFACT_DIR, 'prod_100_checkout_mobile.png'), fullPage: true });

    // Desktop Checkout
    await page.goto('https://akibara.cl/checkout/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'prod_100_checkout_desktop.png'), fullPage: true });

    // 4. My Account Verification
    console.log('\nTesting My Account on https://akibara.cl/mi-cuenta/ ...');
    await page.goto('https://akibara.cl/mi-cuenta/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'prod_100_account_desktop.png'), fullPage: true });

    await browser.close();
    console.log('\n=== ALL PRODUCTION MEASUREMENTS COMPLETE & SAVED TO ARTIFACTS ===');
}

verifyProduction().catch(err => {
    console.error('Certification failed:', err);
    process.exit(1);
});
