import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'fs';
import path from 'path';

const pw = playwright.default || playwright;
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/16292e4b-646c-4752-83e8-fd2278327e63/screenshots/email-qa';

async function main() {
    let html = fs.readFileSync('/tmp/prod-28560.html', 'utf8');

    // 1. Strip background-image white tile that trapped the card
    html = html.replace(/background-image:\s*url\([^)]*akb-hdr-white\.png[^)]*\);?/gi, '');
    html = html.replace(/background-image:\s*url\([^)]*akb-btn-red\.png[^)]*\);?/gi, 'background-image: linear-gradient(#e60012, #e60012);');

    // 2. Inject Dark Mode CSS in head before </head>
    const darkCSS = `
<style type="text/css">
@media (prefers-color-scheme: dark) {
    body, #outer_wrapper { background-color: #0d0d0d !important; }
    #template_container, #body_content, #body_content_inner_cell { background-color: #1a1a1c !important; }
    #body_content_inner, #body_content_inner p, #body_content_inner td, #body_content_inner th, #body_content_inner li, #body_content_inner address {
        color: #e5e5ea !important;
        -webkit-text-fill-color: #e5e5ea !important;
    }
    #body_content_inner h2, #body_content_inner h3, #body_content_inner strong, #body_content_inner b {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }
    #body_content_inner .email-order-details thead th {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        border-bottom: 2px solid #E60012 !important;
    }
    #body_content_inner .email-order-details td,
    #body_content_inner .email-order-details th {
        border-color: #2c2c2e !important;
    }
    #body_content_inner .order-totals-total th,
    #body_content_inner .order-totals-total td {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }
    #body_content_inner .order-totals-total .amount,
    #body_content_inner .order-totals-total .amount * {
        color: #ff4d5a !important;
        -webkit-text-fill-color: #ff4d5a !important;
    }
    #body_content_inner .order-totals-total .includes_tax,
    #body_content_inner .order-totals-total .includes_tax * {
        color: #a1a1aa !important;
        -webkit-text-fill-color: #a1a1aa !important;
    }
    #body_content_inner a {
        color: #ff4d5a !important;
        -webkit-text-fill-color: #ff4d5a !important;
    }
    #header_wrapper h1 {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }
}
</style>
`;
    html = html.replace('</head>', darkCSS + '</head>');

    fs.writeFileSync('/tmp/fixed-test.html', html);

    const browser = await pw.chromium.launch({
        executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        headless: true
    });

    // Render Light
    const ctxLight = await browser.newContext({
        colorScheme: 'light',
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2
    });
    const pageLight = await ctxLight.newPage();
    await pageLight.goto('file:///tmp/fixed-test.html', { waitUntil: 'networkidle' });
    await pageLight.screenshot({ path: path.join(SCREENSHOT_DIR, 'fixed-light.png'), fullPage: true });

    // Render Dark
    const ctxDark = await browser.newContext({
        colorScheme: 'dark',
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2
    });
    const pageDark = await ctxDark.newPage();
    await pageDark.goto('file:///tmp/fixed-test.html', { waitUntil: 'networkidle' });
    await pageDark.screenshot({ path: path.join(SCREENSHOT_DIR, 'fixed-dark.png'), fullPage: true });

    await browser.close();
    console.log('SUCCESS! Rendered fixed-light.png and fixed-dark.png');
}

main().catch(console.error);
