import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;

async function testKinetic() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

    console.log('Navigating to http://localhost:8080/tienda/ ...');
    await page.goto('http://localhost:8080/tienda/', { waitUntil: 'networkidle' });

    const firstProductBtn = page.locator('ul.products li.product .button').first();
    const btnBox = await firstProductBtn.boundingBox();
    console.log('Button bounding box:', btnBox);

    // Initial styles
    const restingStyles = await firstProductBtn.evaluate(el => {
        const cs = window.getComputedStyle(el);
        return {
            border: cs.border,
            boxShadow: cs.boxShadow,
            transform: cs.transform
        };
    });
    console.log('Resting button styles:', restingStyles);

    // Hover
    await firstProductBtn.hover();
    await page.waitForTimeout(200);
    const hoverStyles = await firstProductBtn.evaluate(el => {
        const cs = window.getComputedStyle(el);
        return {
            border: cs.border,
            boxShadow: cs.boxShadow,
            transform: cs.transform
        };
    });
    console.log('Hover button styles:', hoverStyles);

    // Mousedown (active press)
    await page.mouse.move(btnBox.x + btnBox.width / 2, btnBox.y + btnBox.height / 2);
    await page.mouse.down();
    await page.waitForTimeout(100);
    const activeStyles = await firstProductBtn.evaluate(el => {
        const cs = window.getComputedStyle(el);
        return {
            border: cs.border,
            boxShadow: cs.boxShadow,
            transform: cs.transform
        };
    });
    console.log('Active (pressed) button styles:', activeStyles);
    await page.mouse.up();

    // Check cart bump on add to cart
    console.log('Testing cart punch feedback on add to cart...');
    await firstProductBtn.click();
    
    // Wait for .header-icon--cart to receive .is-bumped or trigger animation
    let bumped = false;
    for (let i = 0; i < 20; i++) {
        await page.waitForTimeout(150);
        const hasBump = await page.evaluate(() => {
            const el = document.querySelector('.header-icon--cart');
            return el?.classList.contains('is-bumped');
        });
        if (hasBump) {
            bumped = true;
            console.log('Cart header icon received .is-bumped at check', i);
            break;
        }
    }
    console.log('Cart bump triggered successfully:', bumped);

    await browser.close();
}

testKinetic().catch(err => {
    console.error(err);
    process.exit(1);
});
