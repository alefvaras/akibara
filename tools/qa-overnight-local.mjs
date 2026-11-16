import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';

async function run() {
    fs.mkdirSync('output/playwright/overnight-qa', { recursive: true });
    const browser = await chromium.launch({ headless: true });

    // 1. Check local empty search
    {
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
        await page.goto('http://localhost:8080/?s=xyznotfoundquery99', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

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
        console.log('Local empty search info:', emptyInfo);
        await page.screenshot({ path: 'output/playwright/overnight-qa/local_empty_search_fixed.png' });
        await page.close();
    }

    // 2. Check local home hero progress fill
    {
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
        await page.goto('http://localhost:8080/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        const heroFillBg = await page.evaluate(() => {
            const fill = document.querySelector('.akb-hero__seg-fill');
            return fill ? window.getComputedStyle(fill).backgroundColor : null;
        });
        console.log('Local hero fill bg:', heroFillBg);
        await page.screenshot({ path: 'output/playwright/overnight-qa/local_hero_fill_fixed.png' });
        await page.close();
    }

    // 3. Check local PDP points notice
    {
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
        await page.goto('http://localhost:8080/producto/bleach-remix-16/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        const pdpPoints = await page.evaluate(() => {
            const p = document.querySelector('.akb-rw-pdp-points');
            const star = p ? p.querySelector('span') : null;
            return {
                text: p ? p.innerText : null,
                color: p ? window.getComputedStyle(p).color : null,
                starColor: star ? window.getComputedStyle(star).color : null
            };
        });
        console.log('Local PDP points notice info:', pdpPoints);
        await page.screenshot({ path: 'output/playwright/overnight-qa/local_pdp_points_fixed.png' });
        await page.close();
    }

    await browser.close();
    console.log('Local tests finished successfully.');
}

run().catch(err => {
    console.error(err);
    process.exit(1);
});
