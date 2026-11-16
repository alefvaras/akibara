import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';

const OUT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/audit-production-certified';
fs.mkdirSync(OUT_DIR, { recursive: true });

async function verifyProduction() {
    const browser = await chromium.launch({ headless: true });

    // 1. Production PDP Points Notice
    console.log('Verifying Production PDP points notice...');
    {
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        await page.goto('https://akibara.cl/producto/bleach-remix-16/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);

        const pdpPoints = await page.evaluate(() => {
            const p = document.querySelector('.akb-rw-pdp-points');
            const star = p ? p.querySelector('span') : null;
            return {
                text: p ? p.innerText : null,
                color: p ? window.getComputedStyle(p).color : null,
                starColor: star ? window.getComputedStyle(star).color : null
            };
        });
        console.log('Production PDP Points:', pdpPoints);
        await page.screenshot({ path: `${OUT_DIR}/prod_pdp_points_certified.png` });
        await page.close();
    }

    // 2. Production Home Hero Progress Fill
    console.log('Verifying Production Home Hero progress fill...');
    {
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        await page.goto('https://akibara.cl/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);

        const heroFillBg = await page.evaluate(() => {
            const fill = document.querySelector('.akb-hero__seg-fill');
            return fill ? window.getComputedStyle(fill).backgroundColor : null;
        });
        console.log('Production Hero Fill Bg:', heroFillBg);
        await page.screenshot({ path: `${OUT_DIR}/prod_hero_fill_certified.png` });
        await page.close();
    }

    // 3. Production Empty Search State
    console.log('Verifying Production Empty Search State...');
    {
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        await page.goto('https://akibara.cl/?s=xyznotfoundquery99', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);

        const emptyInfo = await page.evaluate(() => {
            const h1 = document.querySelector('.entry-title');
            const article = document.querySelector('.inside-article');
            const h1Rect = h1 ? h1.getBoundingClientRect() : null;
            const cs = article ? window.getComputedStyle(article) : null;
            return {
                h1Left: h1Rect ? h1Rect.left : null,
                articlePadding: cs ? cs.padding : null,
                articleMaxWidth: cs ? cs.maxWidth : null,
                hasOverflow: document.documentElement.scrollWidth > window.innerWidth
            };
        });
        console.log('Production Empty Search Info:', emptyInfo);
        await page.screenshot({ path: `${OUT_DIR}/prod_empty_search_certified.png` });
        await page.close();
    }

    // 4. Mobile Home Hero (390px)
    console.log('Verifying Mobile Home Hero...');
    {
        const page = await browser.newPage({
            viewport: { width: 390, height: 844 },
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148'
        });
        await page.goto('https://akibara.cl/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await page.screenshot({ path: `${OUT_DIR}/prod_mob_hero_certified.png` });
        await page.close();
    }

    // 5. Mobile PDP Bleach 16 (390px)
    console.log('Verifying Mobile PDP Bleach 16...');
    {
        const page = await browser.newPage({
            viewport: { width: 390, height: 844 },
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148'
        });
        await page.goto('https://akibara.cl/producto/bleach-remix-16/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await page.screenshot({ path: `${OUT_DIR}/prod_mob_pdp_points_certified.png` });
        await page.close();
    }

    await browser.close();
    console.log('All production verifications completed successfully!');
}

verifyProduction().catch(err => {
    console.error(err);
    process.exit(1);
});
