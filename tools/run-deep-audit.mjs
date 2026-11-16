import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';

const OUT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/audit-deep-overnight';
fs.mkdirSync(OUT_DIR, { recursive: true });

const findings = [];

async function auditPage(page, label, checks = {}) {
    console.log(`Auditing: ${label}...`);
    
    // Check 1: Any yellow color in text, borders, or backgrounds
    const yellowElements = await page.evaluate(() => {
        const matches = [];
        const all = document.querySelectorAll('*');
        for (const el of all) {
            // ignore script, style, svg paths
            if (['SCRIPT', 'STYLE', 'SVG', 'PATH'].includes(el.tagName)) continue;
            const cs = window.getComputedStyle(el);
            const isYellow = (val) => {
                if (!val) return false;
                // rgb(255, 215, 0) #FFD700 or rgb(255, 212, 1) #ffd401 or rgb(255, 214, 10)
                const m = val.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
                if (m) {
                    const [_, r, g, b] = m.map(Number);
                    // yellow-ish: high red, high green, low blue
                    if (r > 230 && g > 180 && b < 50) return true;
                }
                return false;
            };

            if (isYellow(cs.color) || isYellow(cs.backgroundColor) || isYellow(cs.borderColor) || isYellow(cs.outlineColor)) {
                // Ignore star ratings if they are intended to be gold, but record them
                const text = el.innerText?.slice(0, 30) || '';
                matches.push({
                    tag: el.tagName,
                    class: el.className,
                    id: el.id,
                    text: text.trim(),
                    color: cs.color,
                    bg: cs.backgroundColor,
                    border: cs.borderColor,
                    outline: cs.outlineColor
                });
            }
        }
        return matches.slice(0, 15);
    });

    if (yellowElements.length > 0) {
        findings.push({ page: label, type: 'yellow_elements', details: yellowElements });
    }

    // Check 2: Horizontal scroll / overflow
    const hasHorizontalOverflow = await page.evaluate(() => {
        return document.documentElement.scrollWidth > window.innerWidth;
    });
    if (hasHorizontalOverflow) {
        findings.push({ page: label, type: 'horizontal_overflow', details: 'Page horizontally scrolls past viewport width' });
    }
}

async function run() {
    const browser = await chromium.launch({ headless: true });

    // ─────────────────────────────────────────────────────────────
    // 1. DESKTOP AUDIT (1440x900)
    // ─────────────────────────────────────────────────────────────
    console.log('=== DESKTOP AUDIT (1440x900) ===');
    {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await context.newPage();

        // 1.1 Home
        await page.goto('https://akibara.cl/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Desktop Home');
        await page.screenshot({ path: `${OUT_DIR}/desk_1_home_hero.png` });
        await page.evaluate(() => window.scrollTo(0, 800));
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT_DIR}/desk_1_home_products.png` });
        await page.evaluate(() => window.scrollTo(0, 1800));
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT_DIR}/desk_1_home_benefits_footer.png` });

        // 1.2 Tienda
        await page.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Desktop Tienda');
        await page.screenshot({ path: `${OUT_DIR}/desk_2_tienda_grid.png` });

        // Hover over second card
        const card2 = page.locator('ul.products li.product').nth(1);
        if (await card2.count() > 0) {
            await card2.hover();
            await page.waitForTimeout(400);
            await page.screenshot({ path: `${OUT_DIR}/desk_2_tienda_card_hover.png` });
        }

        // 1.3 Ficha de producto Bleach Remix 16
        await page.goto('https://akibara.cl/producto/bleach-remix-16/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Desktop PDP Bleach 16');
        await page.screenshot({ path: `${OUT_DIR}/desk_3_pdp_bleach16_top.png` });
        await page.evaluate(() => window.scrollTo(0, 600));
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT_DIR}/desk_3_pdp_bleach16_pack_builder.png` });

        // 1.4 Ficha de serie Bleach Remix
        await page.goto('https://akibara.cl/serie/bleach-remix/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Desktop Serie Bleach Remix');
        await page.screenshot({ path: `${OUT_DIR}/desk_4_serie_bleach_landing.png` });

        // 1.5 Búsqueda sin resultados
        await page.goto('https://akibara.cl/?s=xyznotfoundquery99', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Desktop Search Empty State');
        await page.screenshot({ path: `${OUT_DIR}/desk_5_search_empty_state.png` });

        // 1.6 Carrito
        await page.goto('https://akibara.cl/carrito/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Desktop Carrito');
        await page.screenshot({ path: `${OUT_DIR}/desk_6_carrito_page.png` });

        // 1.7 Checkout
        await page.goto('https://akibara.cl/finalizar-compra/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Desktop Checkout');
        await page.screenshot({ path: `${OUT_DIR}/desk_7_checkout_page.png` });

        await context.close();
    }

    // ─────────────────────────────────────────────────────────────
    // 2. TABLET AUDIT (768x1024)
    // ─────────────────────────────────────────────────────────────
    console.log('=== TABLET AUDIT (768x1024) ===');
    {
        const context = await browser.newContext({ viewport: { width: 768, height: 1024 } });
        const page = await context.newPage();

        await page.goto('https://akibara.cl/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Tablet Home');
        await page.screenshot({ path: `${OUT_DIR}/tab_1_home.png` });

        await page.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Tablet Tienda');
        await page.screenshot({ path: `${OUT_DIR}/tab_2_tienda.png` });

        await page.goto('https://akibara.cl/producto/bleach-remix-16/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Tablet PDP');
        await page.screenshot({ path: `${OUT_DIR}/tab_3_pdp.png` });

        await context.close();
    }

    // ─────────────────────────────────────────────────────────────
    // 3. MOBILE AUDIT (390x844 - iPhone)
    // ─────────────────────────────────────────────────────────────
    console.log('=== MOBILE AUDIT (390x844) ===');
    {
        const context = await browser.newContext({
            viewport: { width: 390, height: 844 },
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148'
        });
        const page = await context.newPage();

        // 3.1 Mobile Home
        await page.goto('https://akibara.cl/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Mobile Home');
        await page.screenshot({ path: `${OUT_DIR}/mob_1_home_hero.png` });

        // Scroll down to products
        await page.evaluate(() => window.scrollTo(0, 500));
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT_DIR}/mob_1_home_products.png` });

        // 3.2 Mobile Tienda
        await page.goto('https://akibara.cl/tienda/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Mobile Tienda');
        await page.screenshot({ path: `${OUT_DIR}/mob_2_tienda_grid.png` });

        // Open Filter Drawer and test each section
        const filterBtn = page.locator('.akb-filter-toggle, [data-akb-drawer-open="filters"], button:has-text("Filtrar")').first();
        if (await filterBtn.count() > 0) {
            await filterBtn.click();
            await page.waitForTimeout(700);
            await page.screenshot({ path: `${OUT_DIR}/mob_2_filter_drawer.png` });
            
            // Check if bottom nav is hidden
            const bottomNavVisible = await page.locator('.bottom-nav').isVisible();
            console.log('Mobile bottomNavVisible with drawer open:', bottomNavVisible);
            
            // Close filter drawer
            const closeBtn = page.locator('.akb-facets-close, [data-akb-drawer-close]').first();
            if (await closeBtn.count() > 0) await closeBtn.click();
            await page.waitForTimeout(400);
        }

        // 3.3 Mobile PDP
        await page.goto('https://akibara.cl/producto/bleach-remix-16/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Mobile PDP Bleach 16');
        await page.screenshot({ path: `${OUT_DIR}/mob_3_pdp_top.png` });

        // Scroll to trigger sticky ATC
        await page.evaluate(() => window.scrollTo(0, 600));
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT_DIR}/mob_3_pdp_sticky_atc.png` });

        // Scroll to Pack Builder
        await page.evaluate(() => window.scrollTo(0, 1100));
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT_DIR}/mob_3_pdp_pack_builder.png` });

        // 3.4 Mobile Serie Landing
        await page.goto('https://akibara.cl/serie/bleach-remix/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await auditPage(page, 'Mobile Serie Bleach');
        await page.screenshot({ path: `${OUT_DIR}/mob_4_serie_landing.png` });

        // 3.5 Mobile Search Sheet
        await page.goto('https://akibara.cl/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);
        const searchTrigger = page.locator('.bottom-nav__item--search, [data-drawer-trigger="search"]').first();
        if (await searchTrigger.count() > 0) {
            await searchTrigger.click();
            await page.waitForTimeout(600);
            await page.screenshot({ path: `${OUT_DIR}/mob_5_search_sheet_open.png` });

            const mobileInput = page.locator('.mobile-drawer__search input[type="search"]').first();
            if (await mobileInput.count() > 0) {
                await mobileInput.fill('naruto');
                await page.waitForTimeout(1200);
                await page.screenshot({ path: `${OUT_DIR}/mob_5_search_sheet_suggestions.png` });
            }
        }

        await context.close();
    }

    await browser.close();

    fs.writeFileSync(`${OUT_DIR}/deep_audit_findings.json`, JSON.stringify(findings, null, 2));
    console.log('=== DEEP OVERNIGHT AUDIT COMPLETE ===');
    console.log('Findings:', findings);
}

run().catch(err => {
    console.error('Audit failed:', err);
    process.exit(1);
});
