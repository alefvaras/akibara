import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';

const OUT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/audit-production';
fs.mkdirSync(OUT_DIR, { recursive: true });

async function runAudit() {
    const browser = await chromium.launch({ headless: true });

    // ─────────────────────────────────────────────────────────────
    // ITERACIÓN 1: Desktop (1440x900) - Header Search Focus & Suggest
    // ─────────────────────────────────────────────────────────────
    console.log('--- ITERACIÓN 1: Desktop Search Focus ---');
    {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await context.newPage();
        await page.goto('https://akibara.cl/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        // Click search input
        const searchInput = page.locator('#akb-search-input');
        await searchInput.click();
        await page.waitForTimeout(500);

        // Capture search bar focused
        await page.screenshot({ path: `${OUT_DIR}/iter1_desktop_search_focus_empty.png` });

        // Type query to open suggestions
        await searchInput.fill('bleach');
        await page.waitForTimeout(1200);
        await page.screenshot({ path: `${OUT_DIR}/iter1_desktop_search_suggest_dropdown.png` });

        // Tab focus into suggestions
        await page.keyboard.press('ArrowDown');
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${OUT_DIR}/iter1_desktop_search_suggest_arrow_focus.png` });

        await context.close();
    }

    // ─────────────────────────────────────────────────────────────
    // ITERACIÓN 2: Desktop (1280x800) - Tienda, Checkbox Focus & QuickView
    // ─────────────────────────────────────────────────────────────
    console.log('--- ITERACIÓN 2: Desktop Tienda & QuickView ---');
    {
        const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
        const page = await context.newPage();
        await page.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        // Focus first checkbox in sidebar
        const cb = page.locator('.akb-facet-item input[type="checkbox"]').first();
        if (await cb.count() > 0) {
            await cb.focus();
            await page.waitForTimeout(400);
            await page.screenshot({ path: `${OUT_DIR}/iter2_desktop_checkbox_focus.png` });
        }

        // Click QuickView on first product
        const qvBtn = page.locator('.akb-quick-view-btn').first();
        if (await qvBtn.count() > 0) {
            await qvBtn.click();
            await page.waitForTimeout(800);
            await page.screenshot({ path: `${OUT_DIR}/iter2_desktop_quickview_modal.png` });
        }

        await context.close();
    }

    // ─────────────────────────────────────────────────────────────
    // ITERACIÓN 3: Tablet (768x1024) - iPad Portrait QuickView & Layout
    // ─────────────────────────────────────────────────────────────
    console.log('--- ITERACIÓN 3: Tablet Catalog & QuickView ---');
    {
        const context = await browser.newContext({ viewport: { width: 768, height: 1024 } });
        const page = await context.newPage();
        await page.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        await page.screenshot({ path: `${OUT_DIR}/iter3_tablet_catalog_grid.png` });

        const qvBtn = page.locator('.akb-quick-view-btn').first();
        if (await qvBtn.count() > 0) {
            await qvBtn.click();
            await page.waitForTimeout(800);
            await page.screenshot({ path: `${OUT_DIR}/iter3_tablet_quickview_modal.png` });
        }

        await context.close();
    }

    // ─────────────────────────────────────────────────────────────
    // ITERACIÓN 4: Mobile (390x844) - Search Drawer & Filter Drawer
    // ─────────────────────────────────────────────────────────────
    console.log('--- ITERACIÓN 4: Mobile Search Drawer & Filter Drawer ---');
    {
        const context = await browser.newContext({
            viewport: { width: 390, height: 844 },
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148'
        });
        const page = await context.newPage();
        await page.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        // Open mobile search drawer via bottom-nav search icon or header search
        const mobileSearchBtn = page.locator('.bottom-nav__item--search, [data-drawer-trigger="search"], .header-icon--search').first();
        if (await mobileSearchBtn.count() > 0) {
            await mobileSearchBtn.click();
            await page.waitForTimeout(600);
            await page.screenshot({ path: `${OUT_DIR}/iter4_mobile_search_drawer.png` });
            // Close search drawer
            const closeSearch = page.locator('.akb-search-cancel, .mobile-drawer__close').first();
            if (await closeSearch.count() > 0) await closeSearch.click();
            await page.waitForTimeout(400);
        }

        // Open mobile filter drawer
        const filterBtn = page.locator('.akb-filter-toggle, [data-akb-drawer-open="filters"], button:has-text("Filtrar")').first();
        if (await filterBtn.count() > 0) {
            await filterBtn.click();
            await page.waitForTimeout(600);
            await page.screenshot({ path: `${OUT_DIR}/iter4_mobile_filter_drawer_opened.png` });
        }

        await context.close();
    }

    // ─────────────────────────────────────────────────────────────
    // ITERACIÓN 5: Mobile (390x844) - PDP Box Alignment & Cart Drawer
    // ─────────────────────────────────────────────────────────────
    console.log('--- ITERACIÓN 5: Mobile PDP Alignment & Cart ---');
    {
        const context = await browser.newContext({
            viewport: { width: 390, height: 844 },
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148'
        });
        const page = await context.newPage();
        await page.goto('https://akibara.cl/producto/bleach-remix-16/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        // Capture PDP box header alignment
        await page.screenshot({ path: `${OUT_DIR}/iter5_mobile_bleach16_box_alignment.png` });

        // Add to cart
        const atcBtn = page.locator('button.single_add_to_cart_button').first();
        if (await atcBtn.count() > 0) {
            await atcBtn.click();
            await page.waitForTimeout(1500);
            await page.screenshot({ path: `${OUT_DIR}/iter5_mobile_cart_drawer_opened.png` });
        }

        await context.close();
    }

    await browser.close();
    console.log('Audit completed successfully. All screenshots saved.');
}

runAudit().catch(err => {
    console.error('Audit failed:', err);
    process.exit(1);
});
