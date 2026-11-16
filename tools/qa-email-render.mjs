import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'fs';
import path from 'path';

const pw = playwright.default || playwright;
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/16292e4b-646c-4752-83e8-fd2278327e63/screenshots/email-qa';

if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

async function renderHtml(filePath, name) {
    const browser = await pw.chromium.launch({
        executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        headless: true
    });

    // 1. Light Mode Viewport (Mobile 390x844)
    const contextLight = await browser.newContext({
        colorScheme: 'light',
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2
    });
    const pageLight = await contextLight.newPage();
    await pageLight.goto(`file://${filePath}`, { waitUntil: 'networkidle' });
    const shotLight = path.join(SCREENSHOT_DIR, `${name}-light.png`);
    await pageLight.screenshot({ path: shotLight, fullPage: true });
    console.log(`Saved: ${shotLight}`);

    // 2. Dark Mode Viewport (Mobile 390x844)
    const contextDark = await browser.newContext({
        colorScheme: 'dark',
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2
    });
    const pageDark = await contextDark.newPage();
    await pageDark.goto(`file://${filePath}`, { waitUntil: 'networkidle' });
    const shotDark = path.join(SCREENSHOT_DIR, `${name}-dark.png`);
    await pageDark.screenshot({ path: shotDark, fullPage: true });
    console.log(`Saved: ${shotDark}`);

    await browser.close();
}

async function main() {
    const targetFile = process.argv[2] || '/tmp/prod-28560.html';
    const name = process.argv[3] || 'prod-current';
    console.log(`Rendering ${targetFile} as ${name}...`);
    await renderHtml(targetFile, name);
}

main().catch(console.error);
