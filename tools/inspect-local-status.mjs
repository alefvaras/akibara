import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;

async function run() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

    console.log('--- 1. Catalog Page ---');
    await page.goto('http://localhost:8080/tienda/', { waitUntil: 'networkidle' });
    const button = page.locator('ul.products li.product .button').first();
    if (await button.count() > 0) {
        const rest = await button.evaluate(el => {
            const cs = window.getComputedStyle(el);
            return {
                tag: el.tagName,
                classes: el.className,
                clipPath: cs.clipPath,
                boxShadow: cs.boxShadow,
                filter: cs.filter,
                transform: cs.transform,
                border: cs.border,
                borderRadius: cs.borderRadius,
                background: cs.backgroundColor
            };
        });
        console.log('Catalog button resting:', JSON.stringify(rest, null, 2));

        // Hover
        await button.hover();
        await page.waitForTimeout(200);
        const hover = await button.evaluate(el => {
            const cs = window.getComputedStyle(el);
            return {
                clipPath: cs.clipPath,
                boxShadow: cs.boxShadow,
                filter: cs.filter,
                transform: cs.transform,
                background: cs.backgroundColor
            };
        });
        console.log('Catalog button hover:', JSON.stringify(hover, null, 2));
    }

    console.log('\n--- 2. Checkout Page ---');
    await page.goto('http://localhost:8080/checkout/', { waitUntil: 'networkidle' });
    const checkoutInfo = await page.evaluate(() => {
        const isBlock = !!document.querySelector('.wp-block-woocommerce-checkout');
        const formCheckout = !!document.querySelector('form.woocommerce-checkout');
        const orderReview = !!document.querySelector('#order_review');
        const payment = !!document.querySelector('#payment');
        const placeOrder = !!document.querySelector('#place_order');
        return { isBlock, formCheckout, orderReview, payment, placeOrder };
    });
    console.log('Checkout structure:', JSON.stringify(checkoutInfo, null, 2));

    console.log('\n--- 3. My Account Page ---');
    await page.goto('http://localhost:8080/mi-cuenta/', { waitUntil: 'networkidle' });
    const accountInfo = await page.evaluate(() => {
        const nav = !!document.querySelector('.woocommerce-MyAccount-navigation');
        const navItems = Array.from(document.querySelectorAll('.woocommerce-MyAccount-navigation-link a')).map(a => a.textContent.trim());
        const content = !!document.querySelector('.woocommerce-MyAccount-content');
        return { nav, navItems, content };
    });
    console.log('Account structure:', JSON.stringify(accountInfo, null, 2));

    await browser.close();
}

run().catch(console.error);
