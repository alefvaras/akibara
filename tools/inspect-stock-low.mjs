import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;

async function run() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.goto('http://localhost:8080/producto/rurouni-kenshin-edicion-kanzenban-10-ivrea-argentina/', { waitUntil: 'networkidle' });
    const stock = await page.$('.woocommerce div.product p.stock.akb-stock-low');
    if (stock) {
        await stock.screenshot({ path: '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/identity-inspection-v3/10_stock_low_badge.png' });
        console.log('Stock low badge captured!');
    } else {
        console.log('Stock element not found, taking full summary screenshot');
        const summary = await page.$('.entry-summary');
        if (summary) {
            await summary.screenshot({ path: '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/identity-inspection-v3/10_pdp_summary.png' });
        }
    }
    await page.screenshot({ path: '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/identity-inspection-v3/11_pdp_low_stock_page.png' });
    await browser.close();
}
run();
