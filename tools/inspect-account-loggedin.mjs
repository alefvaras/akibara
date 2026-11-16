import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import { execSync } from 'node:child_process';

async function run() {
    const raw = execSync(`docker exec akibara-cron-1 wp eval '
        $akb_login = "qa_e2e_puntos";
        $akb_user = get_user_by("login", $akb_login);
        $akb_uid = (int) $akb_user->ID;
        $akb_exp = time() + 2 * 3600;
        $akb_token = WP_Session_Tokens::get_instance($akb_uid)->create($akb_exp);
        echo json_encode([
            "name" => LOGGED_IN_COOKIE,
            "value" => wp_generate_auth_cookie($akb_uid, $akb_exp, "logged_in", $akb_token),
            "domain" => "localhost",
            "path" => "/"
        ]);
    '`, { encoding: 'utf8' });

    const cookie = JSON.parse(raw);
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1280, height: 900 }
    });
    await context.addCookies([cookie]);

    const page = await context.newPage();
    console.log('Navigating to http://localhost:8080/mi-cuenta/ ...');
    await page.goto('http://localhost:8080/mi-cuenta/', { waitUntil: 'networkidle' });

    const navItems = await page.locator('.woocommerce-MyAccount-navigation-link a').allInnerTexts();
    console.log('Nav items:', navItems);

    await page.screenshot({ path: 'output/playwright/account-loggedin-desktop.png', fullPage: true });

    // Orders page
    console.log('Navigating to http://localhost:8080/mi-cuenta/orders/ ...');
    await page.goto('http://localhost:8080/mi-cuenta/orders/', { waitUntil: 'networkidle' });
    await page.screenshot({ path: 'output/playwright/account-orders-desktop.png', fullPage: true });

    // Mobile viewport
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('http://localhost:8080/mi-cuenta/', { waitUntil: 'networkidle' });
    await page.screenshot({ path: 'output/playwright/account-mobile.png', fullPage: true });

    await browser.close();
    console.log('Screenshots saved.');
}

run().catch(console.error);
