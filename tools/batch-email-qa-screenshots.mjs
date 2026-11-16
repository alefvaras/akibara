import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'fs';
import path from 'path';

const pw = playwright.default || playwright;
const SOURCE_DIR = '/tmp/all-rendered-emails';
const OUTPUT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/16292e4b-646c-4752-83e8-fd2278327e63/screenshots/email-matrix';

if (!fs.existsSync(OUTPUT_DIR)) {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

async function run() {
    const files = fs.readdirSync(SOURCE_DIR).filter(f => f.endsWith('.html'));
    console.log(`Found ${files.length} HTML email files to render...`);

    const browser = await pw.chromium.launch({
        executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        headless: true
    });

    // 1. Light Mode Context (Mobile iPhone 14 Pro 390x844)
    const ctxLight = await browser.newContext({
        colorScheme: 'light',
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2
    });
    const pageLight = await ctxLight.newPage();

    // 2. Dark Mode Context (Mobile iPhone 14 Pro 390x844)
    const ctxDark = await browser.newContext({
        colorScheme: 'dark',
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2
    });
    const pageDark = await ctxDark.newPage();

    for (const file of files) {
        const baseName = file.replace('.html', '');
        const filePath = path.join(SOURCE_DIR, file);

        // Render Light
        await pageLight.goto(`file://${filePath}`, { waitUntil: 'networkidle' });
        const lightShot = path.join(OUTPUT_DIR, `${baseName}-light.png`);
        await pageLight.screenshot({ path: lightShot, fullPage: true });

        // Render Dark
        await pageDark.goto(`file://${filePath}`, { waitUntil: 'networkidle' });
        const darkShot = path.join(OUTPUT_DIR, `${baseName}-dark.png`);
        await pageDark.screenshot({ path: darkShot, fullPage: true });

        console.log(`Rendered: ${baseName} (light + dark)`);
    }

    await browser.close();
    console.log(`All ${files.length * 2} screenshots saved to ${OUTPUT_DIR}`);
}

run().catch(console.error);
