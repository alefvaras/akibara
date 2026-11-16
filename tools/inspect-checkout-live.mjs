import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;

async function run() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1280, height: 900 }
    });
    const page = await context.newPage();

    console.log('Fetching an in-stock simple product...');
    const res = await page.request.get('http://localhost:8080/wp-json/wc/store/v1/products?stock_status=instock&type=simple&per_page=10');
    const products = await res.json();
    const product = products.find(p => p.is_purchasable && p.is_in_stock);
    if (!product) {
        console.error('No instock product found');
        await browser.close();
        return;
    }
    console.log('Adding product:', product.name, product.permalink);

    await page.goto(product.permalink, { waitUntil: 'networkidle' });
    const atcBtn = page.locator('.single_add_to_cart_button').first();
    await atcBtn.click();
    console.log('Waiting for added state...');
    await page.waitForTimeout(2000);

    console.log('Navigating to checkout...');
    await page.goto('http://localhost:8080/checkout/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);

    const checkoutData = await page.evaluate(() => {
        const h1 = document.querySelector('h1')?.innerText || '';
        const isBlockCheckout = !!document.querySelector('.wp-block-woocommerce-checkout');
        const isClassicCheckout = !!document.querySelector('form.woocommerce-checkout');
        const orderSummary = document.querySelector('.wc-block-components-order-summary, #order_review')?.className || 'not found';
        const placeOrderBtn = document.querySelector('.wc-block-components-checkout-place-order-button, #place_order');
        const placeOrderText = placeOrderBtn?.innerText || 'not found';
        const placeOrderTag = placeOrderBtn?.tagName || '';
        const inputs = Array.from(document.querySelectorAll('input:not([type="hidden"])')).map(i => ({ id: i.id, name: i.name, type: i.type }));
        return { h1, isBlockCheckout, isClassicCheckout, orderSummary, placeOrderTag, placeOrderText, inputCount: inputs.length, sampleInputs: inputs.slice(0, 5) };
    });

    console.log('Checkout data:', JSON.stringify(checkoutData, null, 2));

    await page.screenshot({ path: 'output/playwright/live-checkout-local.png', fullPage: true });
    console.log('Screenshot saved to output/playwright/live-checkout-local.png');

    await browser.close();
}

run().catch(console.error);
