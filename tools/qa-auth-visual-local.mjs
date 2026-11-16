import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import { execSync } from 'node:child_process';
import path from 'node:path';

const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/auth-qa';

async function main() {
    console.log('🚀 Iniciando suite exhaustiva de QA Visual y Funcional (Local Mirror)...');

    const browser = await chromium.launch({ headless: true });
    
    // =========================================================================
    // PARTE 1: Visitante No Autenticado (Muro de Acceso / form-login.php)
    // =========================================================================
    console.log('\n--- PARTE 1: Muro de Acceso (Logged Out) ---');
    const guestContext = await browser.newContext({
        viewport: { width: 1280, height: 800 }
    });
    const guestPage = await guestContext.newPage();

    // 1.1 Muro de Acceso por defecto (Desktop)
    console.log('Navegando a http://localhost:8080/mi-cuenta/ ...');
    await guestPage.goto('http://localhost:8080/mi-cuenta/', { waitUntil: 'networkidle' });
    
    // Verificaciones de elementos clave
    const googleBtn = await guestPage.locator('.wp_google_login__button').first();
    const hasGoogle = await googleBtn.isVisible();
    console.log('✓ Botón de Google presente:', hasGoogle);

    const loginSummary = await guestPage.locator('details.akb-account-disclosure summary').first();
    const registerSummary = await guestPage.locator('details.akb-account-disclosure summary').nth(1);
    
    await guestPage.screenshot({ path: path.join(SCREENSHOT_DIR, '01-desktop-access-wall-default.png'), fullPage: true });
    console.log('✓ Guardado: 01-desktop-access-wall-default.png');

    // 1.2 Abrir acordeón Iniciar Sesión
    await loginSummary.click();
    await guestPage.waitForTimeout(400);
    const loginNonce = await guestPage.locator('input[name="woocommerce-login-nonce"]').count();
    console.log('✓ Nonce de login presente:', loginNonce > 0);
    await guestPage.screenshot({ path: path.join(SCREENSHOT_DIR, '02-desktop-login-accordion-open.png'), fullPage: true });
    console.log('✓ Guardado: 02-desktop-login-accordion-open.png');

    // 1.3 Abrir acordeón Crear Cuenta
    await registerSummary.click();
    await guestPage.waitForTimeout(400);
    const registerNonce = await guestPage.locator('input[name="woocommerce-register-nonce"]').count();
    const birthdayDia = await guestPage.locator('#akb_rw_birthday_dia').count();
    const birthdayMes = await guestPage.locator('#akb_rw_birthday_mes').count();
    const privacyText = await guestPage.locator('.woocommerce-privacy-policy-text').count();
    console.log('✓ Nonce de registro presente:', registerNonce > 0);
    console.log('✓ Selectores de cumpleaños (día/mes) presentes:', birthdayDia > 0 && birthdayMes > 0);
    console.log('✓ Texto de política de privacidad presente:', privacyText > 0);
    await guestPage.screenshot({ path: path.join(SCREENSHOT_DIR, '03-desktop-register-accordion-with-birthday.png'), fullPage: true });
    console.log('✓ Guardado: 03-desktop-register-accordion-with-birthday.png');

    // 1.4 Contexto de retorno con producto en la URL
    console.log('Probando retorno contextual con producto...');
    const targetUrl = 'http://localhost:8080/qa-esp191-tomo-de-prueba/';
    await guestPage.goto(`http://localhost:8080/mi-cuenta/?redirect_to=${encodeURIComponent(targetUrl)}`, { waitUntil: 'networkidle' });
    const returnNotice = await guestPage.locator('.akb-account-auth__context');
    const returnText = await returnNotice.innerText().catch(() => '');
    console.log('✓ Texto de retorno contextual:', returnText);
    await guestPage.screenshot({ path: path.join(SCREENSHOT_DIR, '04-desktop-return-context-notice.png'), fullPage: true });
    console.log('✓ Guardado: 04-desktop-return-context-notice.png');

    // 1.5 Muro en Mobile (390x844)
    console.log('Probando Muro en Mobile (390x844)...');
    const mobileContext = await browser.newContext({
        viewport: { width: 390, height: 844 }
    });
    const mobilePage = await mobileContext.newPage();
    await mobilePage.goto('http://localhost:8080/mi-cuenta/', { waitUntil: 'networkidle' });
    await mobilePage.screenshot({ path: path.join(SCREENSHOT_DIR, '05-mobile-access-wall-default.png'), fullPage: true });
    console.log('✓ Guardado: 05-mobile-access-wall-default.png');

    const mobileRegisterSummary = await mobilePage.locator('details.akb-account-disclosure summary').nth(1);
    await mobileRegisterSummary.click();
    await mobilePage.waitForTimeout(400);
    await mobilePage.screenshot({ path: path.join(SCREENSHOT_DIR, '06-mobile-register-accordion-open.png'), fullPage: true });
    console.log('✓ Guardado: 06-mobile-register-accordion-open.png');

    // =========================================================================
    // PARTE 2: Checkout con Bloques y Botón de Google
    // =========================================================================
    console.log('\n--- PARTE 2: Checkout de Bloques (/finalizar-compra/) ---');
    // Agregar un producto al carrito
    await guestPage.goto('http://localhost:8080/?add-to-cart=30730', { waitUntil: 'networkidle' });
    await guestPage.goto('http://localhost:8080/finalizar-compra/', { waitUntil: 'networkidle' });
    await guestPage.screenshot({ path: path.join(SCREENSHOT_DIR, '07-checkout-blocks-google-button.png'), fullPage: true });
    console.log('✓ Guardado: 07-checkout-blocks-google-button.png');

    // =========================================================================
    // PARTE 3: Cliente Autenticado y Banner de Cumpleaños
    // =========================================================================
    console.log('\n--- PARTE 3: Cliente Autenticado y Banner de Cumpleaños ---');
    
    // 3.1 Garantizar que el usuario qa_e2e_puntos NO tenga cumpleaños registrado
    execSync(`docker exec akibara-cron-1 wp eval '
        $u = get_user_by("login", "qa_e2e_puntos");
        delete_user_meta($u->ID, "_akb_rewards_birthday_md");
        echo "cleared";
    '`);

    // Generar cookie de sesión autenticada
    const authRaw = execSync(`docker exec akibara-cron-1 wp eval '
        $u = get_user_by("login", "qa_e2e_puntos");
        $exp = time() + 7200;
        $tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
        echo json_encode([
            "name" => LOGGED_IN_COOKIE,
            "value" => wp_generate_auth_cookie($u->ID, $exp, "logged_in", $tok),
            "domain" => "localhost",
            "path" => "/"
        ]);
    '`, { encoding: 'utf8' });

    const authCookie = JSON.parse(authRaw);
    const authContext = await browser.newContext({
        viewport: { width: 1280, height: 800 }
    });
    await authContext.addCookies([authCookie]);
    const authPage = await authContext.newPage();

    // 3.2 Visitar Dashboard SIN cumpleaños registrado -> BANNER DEBE VERSE
    console.log('Visitando Dashboard de Mi Cuenta SIN cumpleaños...');
    await authPage.goto('http://localhost:8080/mi-cuenta/', { waitUntil: 'networkidle' });
    
    const banner = await authPage.locator('.akb-dashboard__birthday-banner');
    const bannerCount = await banner.count();
    console.log('✓ Banner de cumpleaños visible:', bannerCount === 1);
    const bannerText = bannerCount === 1 ? await banner.innerText() : '';
    console.log('✓ Contenido del banner:', bannerText.replace(/\\n/g, ' '));

    await authPage.screenshot({ path: path.join(SCREENSHOT_DIR, '08-desktop-dashboard-birthday-banner-visible.png'), fullPage: true });
    console.log('✓ Guardado: 08-desktop-dashboard-birthday-banner-visible.png');

    // Vista móvil del dashboard con banner
    await authPage.setViewportSize({ width: 390, height: 844 });
    await authPage.screenshot({ path: path.join(SCREENSHOT_DIR, '09-mobile-dashboard-birthday-banner-visible.png'), fullPage: true });
    console.log('✓ Guardado: 09-mobile-dashboard-birthday-banner-visible.png');

    // 3.3 Ir a Registrar Fecha (edit-account)
    await authPage.setViewportSize({ width: 1280, height: 800 });
    const ctaBtn = banner.locator('a.button');
    const ctaHref = await ctaBtn.getAttribute('href');
    console.log('✓ CTA apunta a:', ctaHref);
    await authPage.goto(ctaHref, { waitUntil: 'networkidle' });
    await authPage.screenshot({ path: path.join(SCREENSHOT_DIR, '10-desktop-edit-account-birthday-field.png'), fullPage: true });
    console.log('✓ Guardado: 10-desktop-edit-account-birthday-field.png');

    // 3.4 Simular que el cliente guarda su fecha de cumpleaños (ej. 14 de marzo)
    console.log('Guardando cumpleaños del cliente (14 de marzo -> "03-14")...');
    execSync(`docker exec akibara-cron-1 wp eval '
        $u = get_user_by("login", "qa_e2e_puntos");
        update_user_meta($u->ID, "_akb_rewards_birthday_md", "03-14");
        echo "saved";
    '`);

    // 3.5 Re-visitar Dashboard CON cumpleaños registrado -> BANNER DEBE DESAPARECER
    console.log('Re-visitando Dashboard CON cumpleaños registrado...');
    await authPage.goto('http://localhost:8080/mi-cuenta/', { waitUntil: 'networkidle' });
    const bannerAfter = await authPage.locator('.akb-dashboard__birthday-banner').count();
    console.log('✓ Banner de cumpleaños desapareció correctamente (count === 0):', bannerAfter === 0);
    await authPage.screenshot({ path: path.join(SCREENSHOT_DIR, '11-desktop-dashboard-birthday-banner-hidden.png'), fullPage: true });
    console.log('✓ Guardado: 11-desktop-dashboard-birthday-banner-hidden.png');

    // Limpieza de prueba
    execSync(`docker exec akibara-cron-1 wp eval '
        $u = get_user_by("login", "qa_e2e_puntos");
        delete_user_meta($u->ID, "_akb_rewards_birthday_md");
    '`);

    await browser.close();
    console.log('\n🎉 ¡Suite visual completada con éxito! Todas las capturas guardadas en:', SCREENSHOT_DIR);
}

main().catch(err => {
    console.error('❌ Error en suite visual:', err);
    process.exit(1);
});
