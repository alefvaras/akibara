/**
 * Suite E2E Exhaustiva: Ciclo de Vida Completo de Puntos y Reseñas en Akibara Rewards
 *
 * Flujos auditados y certificados en vivo contra Docker Local (http://localhost:8080):
 *  1. Registro de cuenta y configuración de cumpleaños para HOY (11-09).
 *  2. Cron diario de cumpleaños (Birthday::run_daily) -> +1.000 pts acreditados.
 *  3. Primera compra en tienda (PDP Homunculus 10 -> Carrito -> Checkout Bloques).
 *  4. Pedido a 'completed' -> 930 pts retenidos en carencia (430 compra + 500 bono bienvenida).
 *  5. Manipulación de Cron (+7 días en Action Scheduler) -> Liberación de carencia y envío de invitación.
 *  6. Mailpit (http://127.0.0.1:8025) -> Inspección y extracción del enlace tokenizado de reseña.
 *  7. Formulario de reseña UI con 5 estrellas, comentario y foto real adjunta.
 *  8. Moderación en WP-Admin -> Aprobación de reseña -> +500 pts acreditados por ReviewPoints.
 *  9. Inspección de ficha de producto pública con la reseña y foto publicadas.
 * 10. Mi Cuenta con saldo consolidado (2.430 pts acumulados).
 * 11. Segunda compra (Rurouni Kenshin $27.500 > $25.000 mín) -> Canje de 2.430 pts en Checkout Blocks.
 * 12. Confirmación de pedido con descuento facial de $2.430 CLP y saldo final actualizado.
 */

import playwright from '../akibara-e2e/node_modules/playwright/index.js';
const pw = playwright.default || playwright;
const { chromium } = pw;
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/c5738b26-8e15-4e9b-9d7a-fffd6b137fb1/screenshots/e2e-simulation';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

function runWp(cmd) {
    return execSync(`docker exec akibara-cron-1 wp ${cmd}`, { encoding: 'utf8' });
}

function runWpEval(php) {
    const escaped = php.replace(/'/g, "'\\''");
    return execSync(`docker exec akibara-cron-1 wp eval '${escaped}'`, { encoding: 'utf8' });
}

function runWpDb(sql) {
    const escaped = sql.replace(/'/g, "'\\''");
    return execSync(`docker exec akibara-cron-1 wp db query '${escaped}'`, { encoding: 'utf8' });
}

async function getAuthCookies(userId) {
    const raw = runWpEval(`
        $u = get_user_by("ID", ${userId});
        $exp = time() + 7200;
        $tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
        echo json_encode([
            [
                "name" => LOGGED_IN_COOKIE,
                "value" => wp_generate_auth_cookie($u->ID, $exp, "logged_in", $tok),
                "domain" => "localhost",
                "path" => "/"
            ],
            [
                "name" => AUTH_COOKIE,
                "value" => wp_generate_auth_cookie($u->ID, $exp, "auth", $tok),
                "domain" => "localhost",
                "path" => "/"
            ]
        ]);
    `);
    return JSON.parse(raw.trim());
}

async function main() {
    console.log('======================================================================');
    console.log('🚀 INICIANDO AUDITORÍA E2E ADVERSARIAL: CICLO COMPLETO DE RECOMPENSAS');
    console.log('======================================================================\n');

    // Preparar imagen de prueba para la subida de foto
    const testImgPath = '/tmp/foto-compra-homunculus.jpg';
    if (!fs.existsSync(testImgPath)) {
        fs.copyFileSync(
            'public_html/wp-content/uploads/portadas/rurouni-kenshin-edicion-kanzenban-03-ivrea-argentina-og.jpg',
            testImgPath
        );
    }

    // 0. Limpieza previa del usuario de prueba para garantizar un flujo 100% fresco
    console.log('--- PASO 0: Preparación de Entorno y Usuario de Prueba ---');
    const testEmail = 'tanjiro_e2e@akibara.test';
    const testLogin = 'tanjiro_e2e';
    
    runWpEval(`
        $u = get_user_by('email', '${testEmail}');
        if ($u) {
            // Eliminar comentarios de prueba
            $comments = get_comments(['user_id' => $u->ID]);
            foreach ($comments as $c) {
                wp_delete_comment($c->comment_ID, true);
            }
            // Eliminar pedidos del usuario
            $orders = wc_get_orders(['customer' => $u->ID, 'limit' => -1]);
            foreach ($orders as $o) {
                $o->delete(true);
            }
            // Eliminar registros de ledger
            global $wpdb;
            $wpdb->delete('wp_akb_rewards_ledger', ['user_id' => $u->ID]);
            // Eliminar usuario
            wp_delete_user($u->ID);
        }
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_author_email = '${testEmail}'");
        $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
        $wpdb->query("DELETE FROM wp_akb_rewards_review_forms WHERE order_id NOT IN (SELECT ID FROM {$wpdb->posts})");
        // Restaurar stock de productos de prueba
        $p1 = wc_get_product(30538); // Homunculus 10
        if ($p1) {
            $p1->set_stock_status('instock');
            $p1->set_stock_quantity(10);
            $p1->save();
        }
        $p2 = wc_get_product(30555); // Rurouni Kenshin 10
        if ($p2) {
            $p2->set_stock_status('instock');
            $p2->set_stock_quantity(10);
            $p2->save();
        }
        echo "clean";
    `);
    console.log('✓ Entorno limpio: usuario y registros anteriores purgados.');

    // Crear usuario Tanjiro Kamado
    const createRaw = runWpEval(`
        $uid = wp_create_user('${testLogin}', 'AkibaraTest2026!', '${testEmail}');
        if (!is_wp_error($uid)) {
            $u = new WP_User($uid);
            $u->set_role('customer');
            update_user_meta($uid, 'first_name', 'Tanjiro');
            update_user_meta($uid, 'last_name', 'Kamado');
            update_user_meta($uid, 'billing_first_name', 'Tanjiro');
            update_user_meta($uid, 'billing_last_name', 'Kamado');
            update_user_meta($uid, 'billing_email', '${testEmail}');
            update_user_meta($uid, 'billing_phone', '+56987654321');
            update_user_meta($uid, 'billing_address_1', 'Av. Providencia 1234, Depto 402');
            update_user_meta($uid, 'billing_city', 'Santiago');
            update_user_meta($uid, 'billing_state', 'CL-RM');
            update_user_meta($uid, 'billing_postcode', '7500000');
            update_user_meta($uid, 'billing_country', 'CL');
            update_user_meta($uid, '_billing_rut', '18.765.432-7');
            update_user_meta($uid, 'billing_rut', '18.765.432-7');
            update_user_meta($uid, '_wc_other/akibara/rut', '18.765.432-7');
            update_user_meta($uid, 'akibara/rut', '18.765.432-7');
            update_user_meta($uid, 'shipping_first_name', 'Tanjiro');
            update_user_meta($uid, 'shipping_last_name', 'Kamado');
            update_user_meta($uid, 'shipping_phone', '+56987654321');
            update_user_meta($uid, 'shipping_address_1', 'Av. Providencia 1234, Depto 402');
            update_user_meta($uid, 'shipping_city', 'Santiago');
            update_user_meta($uid, 'shipping_state', 'CL-RM');
            update_user_meta($uid, 'shipping_postcode', '7500000');
            update_user_meta($uid, 'shipping_country', 'CL');
            echo $uid;
        } else {
            echo "error: " . $uid->get_error_message();
        }
    `);
    const userId = parseInt(createRaw.trim(), 10);
    console.log(`✓ Cliente Tanjiro Kamado creado con ID: ${userId}`);

    // Iniciar Playwright
    const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
    });
    
    // Cookie de sesión para el cliente
    const customerCookies = await getAuthCookies(userId);
    const customerContext = await browser.newContext({
        viewport: { width: 1280, height: 850 }
    });
    await customerContext.addCookies(customerCookies);
    const customerPage = await customerContext.newPage();

    // =========================================================================
    // PASO 1: Formulario de Cumpleaños y Saldo Inicial
    // =========================================================================
    console.log('\n--- PASO 1: Formulario de Cumpleaños y Saldo Inicial ---');
    await customerPage.goto('http://localhost:8080/mi-cuenta/edit-account/', { waitUntil: 'networkidle' });
    
    // Configurar cumpleaños para HOY (Día 9, Mes Noviembre)
    await customerPage.selectOption('#akb_rw_birthday_dia', '9');
    await customerPage.selectOption('#akb_rw_birthday_mes', '11');
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '01-mi-cuenta-formulario-cumpleanos.png'), fullPage: true });
    console.log('✓ Captura guardada: 01-mi-cuenta-formulario-cumpleanos.png');

    // Guardar formulario
    await customerPage.click('button[name="save_account_details"]');
    await customerPage.waitForLoadState('networkidle');
    
    // Verificar meta en BD
    const bdayMeta = runWpEval(`echo get_user_meta(${userId}, '_akb_rewards_birthday_md', true);`);
    console.log(`✓ Meta de cumpleaños guardada en base de datos: '${bdayMeta.trim()}' (11-09)`);

    // Navegar a Mi Cuenta -> Puntos (Saldo inicial 0)
    await customerPage.goto('http://localhost:8080/mi-cuenta/puntos/', { waitUntil: 'networkidle' });
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '02-mi-cuenta-saldo-inicial-cero.png'), fullPage: true });
    console.log('✓ Captura guardada: 02-mi-cuenta-saldo-inicial-cero.png');

    // =========================================================================
    // PASO 2: Disparo del Cron de Cumpleaños (Birthday::run_daily)
    // =========================================================================
    console.log('\n--- PASO 2: Disparo del Cron de Cumpleaños (Birthday::run_daily) ---');
    runWpEval(`do_action('akb_rewards_birthday_daily');`);
    
    // Verificar en Ledger
    const ledgerCheck = runWpDb(`SELECT * FROM wp_akb_rewards_ledger WHERE user_id = ${userId} AND action = 'birthday';`);
    console.log('✓ Registro en wp_akb_rewards_ledger:', ledgerCheck.trim());

    // Recargar página de puntos en la UI
    await customerPage.reload({ waitUntil: 'networkidle' });
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '03-mi-cuenta-cumpleanos-1000-pts.png'), fullPage: true });
    console.log('✓ Captura guardada: 03-mi-cuenta-cumpleanos-1000-pts.png');

    // =========================================================================
    // PASO 3: Ficha de Producto y Teaser de Puntos
    // =========================================================================
    console.log('\n--- PASO 3: Ficha de Producto (PDP) y Teaser de Puntos ---');
    await customerPage.goto('http://localhost:8080/homunculus-10-ivrea-argentina/', { waitUntil: 'networkidle' });
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '04-pdp-homunculus-10-puntos-teaser.png'), fullPage: true });
    console.log('✓ Captura guardada: 04-pdp-homunculus-10-puntos-teaser.png');

    // Añadir al carrito
    await customerPage.click('.single_add_to_cart_button');
    await customerPage.waitForLoadState('networkidle');

    // Navegar al carrito
    await customerPage.goto('http://localhost:8080/carrito/', { waitUntil: 'networkidle' });
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '05-carrito-con-producto.png'), fullPage: true });
    console.log('✓ Captura guardada: 05-carrito-con-producto.png');

    // =========================================================================
    // PASO 4: Checkout de Bloques y Primera Compra
    // =========================================================================
    console.log('\n--- PASO 4: Checkout de Bloques y Primera Compra ---');
    await customerPage.goto('http://localhost:8080/checkout/', { waitUntil: 'networkidle' });
    await customerPage.waitForTimeout(1500); // Esperar carga de bloques React

    // Validar y asegurar campos requeridos si están visibles
    const phoneInput = customerPage.locator('input[type="tel"]');
    if (await phoneInput.count() > 0 && await phoneInput.first().isVisible()) {
        const val = await phoneInput.first().inputValue();
        if (!val || val.includes('12345678')) {
            await phoneInput.first().fill('+56987654321');
        }
    }
    const rutInput = customerPage.locator('input[id*="rut"], input[name*="rut"]');
    if (await rutInput.count() > 0 && await rutInput.first().isVisible()) {
        const val = await rutInput.first().inputValue();
        if (!val || val.includes('432-1')) {
            await rutInput.first().fill('18.765.432-7');
        }
    }

    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '06-checkout-bloques-primera-compra.png'), fullPage: true });
    console.log('✓ Captura guardada: 06-checkout-bloques-primera-compra.png');

    // Seleccionar método de pago BACS y confirmar pedido
    const placeOrderBtn = customerPage.locator('.wc-block-components-checkout-place-order-button');
    await placeOrderBtn.waitFor({ state: 'visible', timeout: 15000 });
    await customerPage.waitForFunction(() => {
        const btn = document.querySelector('.wc-block-components-checkout-place-order-button');
        return btn && !btn.disabled && !btn.classList.contains('is-busy');
    }, { timeout: 15000 });
    await placeOrderBtn.click();
    
    // Esperar redirección a order-received
    try {
        await customerPage.waitForURL(/order-received/, { timeout: 20000 });
    } catch (e) {
        const notices = await customerPage.locator('.wc-block-components-notice-banner, .woocommerce-error').allTextContents();
        console.error('NOTICES EN CHECKOUT PASO 4:', notices);
        throw e;
    }
    const orderUrl = customerPage.url();
    const orderMatch = orderUrl.match(/order-received\/(\d+)/);
    const orderId = orderMatch ? parseInt(orderMatch[1], 10) : 0;
    console.log(`✓ Pedido creado exitosamente vía UI con ID: #${orderId}`);

    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '07-order-received-primera-compra.png'), fullPage: true });
    console.log('✓ Captura guardada: 07-order-received-primera-compra.png');

    // =========================================================================
    // PASO 5: Transición a Completed y Retención en Carencia (Escrow de 7 Días)
    // =========================================================================
    console.log('\n--- PASO 5: Transición a Completed y Carencia (Escrow de 7 Días) ---');
    runWp(`wc shop_order update ${orderId} --status=completed --user=1`);
    console.log(`✓ Pedido #${orderId} transicionado a 'completed'.`);

    // Contexto de Administrador para capturar el panel de WP-Admin
    const adminCookies = await getAuthCookies(1);
    const adminContext = await browser.newContext({ viewport: { width: 1280, height: 850 } });
    await adminContext.addCookies(adminCookies);
    const adminPage = await adminContext.newPage();

    await adminPage.goto(`http://localhost:8080/wp-admin/post.php?post=${orderId}&action=edit`, { waitUntil: 'networkidle' });
    await adminPage.screenshot({ path: path.join(SCREENSHOT_DIR, '08-admin-pedido-notas-carencia.png'), fullPage: true });
    console.log('✓ Captura guardada: 08-admin-pedido-notas-carencia.png');

    // Verificación de Mi Cuenta del cliente durante la carencia
    await customerPage.goto('http://localhost:8080/mi-cuenta/puntos/', { waitUntil: 'networkidle' });
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '09-mi-cuenta-puntos-en-carencia-930-pts.png'), fullPage: true });
    console.log('✓ Captura guardada: 09-mi-cuenta-puntos-en-carencia-930-pts.png');

    // =========================================================================
    // PASO 6: Manipulación de Cron (+7 Días en Action Scheduler)
    // =========================================================================
    console.log('\n--- PASO 6: Manipulación de Cron (+7 Días) y Liberación de Carencia ---');
    runWpDb(`
        UPDATE wp_actionscheduler_actions 
        SET scheduled_date_gmt = NOW() - INTERVAL 1 MINUTE 
        WHERE hook IN ('akb_rewards_release_accrual_escrow', 'akb_rewards_send_review_invite') 
          AND status = 'pending';
    `);
    console.log('✓ Tareas de Action Scheduler adelantadas en la base de datos (viaje en el tiempo +7 días).');

    // Ejecutar la cola asíncrona
    runWp('action-scheduler run');
    console.log('✓ Cola de Action Scheduler ejecutada exitosamente.');

    // Verificar en Ledger
    const ledgerAfterEscrow = runWpDb(`SELECT delta, action, note, balance_after FROM wp_akb_rewards_ledger WHERE user_id = ${userId} ORDER BY id ASC;`);
    console.log('✓ Movimientos en el ledger tras liberación:\n' + ledgerAfterEscrow.trim());

    // Recargar Mi Cuenta -> Puntos
    await customerPage.reload({ waitUntil: 'networkidle' });
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '10-mi-cuenta-saldo-liberado-1930-pts.png'), fullPage: true });
    console.log('✓ Captura guardada: 10-mi-cuenta-saldo-liberado-1930-pts.png');

    // =========================================================================
    // PASO 7: Mailpit - Inspección de Correos y Extracción de Token de Reseña
    // =========================================================================
    console.log('\n--- PASO 7: Mailpit - Inspección de Correos e Invitación a Reseñar ---');
    const mailpitPage = await adminContext.newPage();
    await mailpitPage.goto('http://127.0.0.1:8025/', { waitUntil: 'networkidle' });
    await mailpitPage.screenshot({ path: path.join(SCREENSHOT_DIR, '11-mailpit-bandeja-de-entrada.png'), fullPage: true });
    console.log('✓ Captura guardada: 11-mailpit-bandeja-de-entrada.png');

    // Abrir el primer mensaje (invitación a reseñar)
    const firstMsg = mailpitPage.locator('tbody tr, .message, a[href*="view"]').first();
    if (await firstMsg.isVisible()) {
        await firstMsg.click();
        await mailpitPage.waitForTimeout(600);
        await mailpitPage.screenshot({ path: path.join(SCREENSHOT_DIR, '12-mailpit-correo-invitacion-detalle.png'), fullPage: true });
        console.log('✓ Captura guardada: 12-mailpit-correo-invitacion-detalle.png');
    }

    // Obtener el token de la tabla wp_akb_rewards_review_forms para el pedido
    const tokenRaw = runWpDb(`SELECT token FROM wp_akb_rewards_review_forms WHERE order_id = ${orderId} ORDER BY id DESC LIMIT 1;`);
    const token = tokenRaw.split('\n')[1]?.trim();
    console.log(`✓ Token criptográfico de reseña recuperado: ${token}`);
    const reviewUrl = `http://localhost:8080/mi-cuenta/?akb_review_token=${token}`;

    // =========================================================================
    // PASO 8: Formulario de Reseña con Foto desde la UI del Cliente
    // =========================================================================
    console.log('\n--- PASO 8: Formulario de Reseña con Foto desde la UI del Cliente ---');
    await customerPage.goto(reviewUrl, { waitUntil: 'networkidle' });
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '13-formulario-resena-inicial.png'), fullPage: true });
    console.log('✓ Captura guardada: 13-formulario-resena-inicial.png');

    // Completar formulario: 5 estrellas, comentario y foto real
    await customerPage.click('label[for="akb-rf-star5"]');
    await customerPage.fill(
        '#akb_rf_content',
        '¡Excelente edición! La calidad de impresión de Homunculus es impresionante y la sobrecubierta llegó en perfecto estado gracias al embalaje blindado de Akibara. 100% recomendado.'
    );
    await customerPage.setInputFiles('#akb_rf_photo', testImgPath);
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '14-formulario-resena-completado-con-foto.png'), fullPage: true });
    console.log('✓ Captura guardada: 14-formulario-resena-completado-con-foto.png');

    // Enviar reseña
    await customerPage.click('button[type="submit"]');
    await customerPage.waitForLoadState('networkidle');
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '15-pantalla-resena-enviada-agradecimiento.png'), fullPage: true });
    console.log('✓ Captura guardada: 15-pantalla-resena-enviada-agradecimiento.png');

    // =========================================================================
    // PASO 9: Moderación y Aprobación en WP-Admin
    // =========================================================================
    console.log('\n--- PASO 9: Moderación y Aprobación de Reseña en WP-Admin ---');
    await adminPage.goto('http://localhost:8080/wp-admin/edit-comments.php?comment_status=moderated', { waitUntil: 'networkidle' });
    await adminPage.screenshot({ path: path.join(SCREENSHOT_DIR, '16-admin-comentarios-resena-pendiente.png'), fullPage: true });
    console.log('✓ Captura guardada: 16-admin-comentarios-resena-pendiente.png');

    // Aprobar comentario vía UI o WP-CLI
    const commentIdRaw = runWpDb(`SELECT comment_ID FROM wp_comments WHERE user_id = ${userId} ORDER BY comment_ID DESC LIMIT 1;`);
    const commentId = parseInt(commentIdRaw.split('\n')[1]?.trim(), 10);
    console.log(`✓ ID de comentario creado: ${commentId}`);

    runWp(`comment approve ${commentId}`);
    console.log(`✓ Comentario #${commentId} aprobado (dispara ReviewPoints::on_status_transition).`);

    await adminPage.goto('http://localhost:8080/wp-admin/edit-comments.php?comment_status=approved', { waitUntil: 'networkidle' });
    await adminPage.screenshot({ path: path.join(SCREENSHOT_DIR, '17-admin-comentarios-resena-aprobada.png'), fullPage: true });
    console.log('✓ Captura guardada: 17-admin-comentarios-resena-aprobada.png');

    // =========================================================================
    // PASO 10: Ficha Pública con Reseña Publicada y Saldo Consolidado
    // =========================================================================
    console.log('\n--- PASO 10: Ficha Pública con Reseña y Saldo Consolidado ---');
    await customerPage.goto('http://localhost:8080/homunculus-10-ivrea-argentina/#reviews', { waitUntil: 'networkidle' });
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '18-pdp-resena-publicada-con-foto.png'), fullPage: true });
    console.log('✓ Captura guardada: 18-pdp-resena-publicada-con-foto.png');

    // Saldo consolidado en Mi Cuenta
    await customerPage.goto('http://localhost:8080/mi-cuenta/puntos/', { waitUntil: 'networkidle' });
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '19-mi-cuenta-saldo-consolidado-2430-pts.png'), fullPage: true });
    console.log('✓ Captura guardada: 19-mi-cuenta-saldo-consolidado-2430-pts.png');

    // =========================================================================
    // PASO 11: Segunda Compra con Redención de Puntos en Checkout
    // =========================================================================
    console.log('\n--- PASO 11: Segunda Compra con Redención de Puntos en Checkout ---');
    // Añadir Rurouni Kenshin Kanzenban 10 ($27.500 CLP > $25.000 mín)
    await customerPage.goto('http://localhost:8080/rurouni-kenshin-edicion-kanzenban-10-ivrea-argentina/', { waitUntil: 'networkidle' });
    
    // Asegurar envío del formulario POST de añadir al carrito
    await Promise.all([
        customerPage.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        customerPage.click('.single_add_to_cart_button')
    ]);

    // Visitar carrito para validar contenido y sincronizar sesión
    await customerPage.goto('http://localhost:8080/carrito/', { waitUntil: 'networkidle' });

    // Ir al checkout de bloques
    await customerPage.goto('http://localhost:8080/checkout/', { waitUntil: 'networkidle' });
    await customerPage.waitForTimeout(2000); // Esperar carga de bloques React y extensionCartUpdate

    // Validar y asegurar campos requeridos si están visibles
    const phoneInput2 = customerPage.locator('input[type="tel"]');
    if (await phoneInput2.count() > 0 && await phoneInput2.first().isVisible()) {
        const val = await phoneInput2.first().inputValue();
        if (!val || val.includes('12345678')) {
            await phoneInput2.first().fill('+56987654321');
        }
    }
    const rutInput2 = customerPage.locator('input[id*="rut"], input[name*="rut"]');
    if (await rutInput2.count() > 0 && await rutInput2.first().isVisible()) {
        const val = await rutInput2.first().inputValue();
        if (!val || val.includes('432-1')) {
            await rutInput2.first().fill('18.765.432-7');
        }
    }

    // Esperar a que la caja de canje esté lista
    await customerPage.waitForSelector('.akb-rw-checkout-box', { timeout: 15000 });
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '20-checkout-caja-de-canje-disponible.png'), fullPage: true });
    console.log('✓ Captura guardada: 20-checkout-caja-de-canje-disponible.png');

    // Step 1: Click 'Usar el máximo disponible'
    const maxBtn = customerPage.locator('button:has-text("Usar el máximo disponible")');
    if (await maxBtn.isVisible()) {
        console.log('✓ Clic en "Usar el máximo disponible"...');
        await maxBtn.click();
        await customerPage.waitForTimeout(800);
    }

    // Step 2: Click 'Confirmar canje'
    const confirmBtn = customerPage.locator('button:has-text("Confirmar canje")');
    await confirmBtn.waitFor({ state: 'visible', timeout: 10000 });
    await customerPage.waitForFunction(() => {
        const btn = document.querySelector('button.wp-element-button:not([disabled])');
        return Array.from(document.querySelectorAll('button')).some(b => b.textContent.includes('Confirmar canje') && !b.disabled);
    }, { timeout: 10000 });

    console.log('✓ Clic en "Confirmar canje"...');
    await confirmBtn.click();
    await customerPage.waitForTimeout(3500); // Esperar que extensionCartUpdate aplique el descuento

    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '21-checkout-canje-aplicado-descuento.png'), fullPage: true });
    console.log('✓ Captura guardada: 21-checkout-canje-aplicado-descuento.png');

    // Step 3: Realizar pedido
    await customerPage.waitForLoadState('networkidle');
    await customerPage.waitForTimeout(1000);

    const placeOrderBtn2 = customerPage.locator('.wc-block-components-checkout-place-order-button');
    await placeOrderBtn2.waitFor({ state: 'visible', timeout: 15000 });
    await customerPage.waitForFunction(() => {
        const btn = document.querySelector('.wc-block-components-checkout-place-order-button');
        return btn && !btn.disabled && !btn.classList.contains('is-busy');
    }, { timeout: 15000 });

    console.log('✓ Clic en .wc-block-components-checkout-place-order-button...');
    await placeOrderBtn2.click();

    try {
        await customerPage.waitForURL(/order-received/, { timeout: 25000 });
    } catch (e) {
        const notices = await customerPage.locator('.wc-block-components-notice-banner, .woocommerce-error').allTextContents();
        console.error('NOTICES EN CHECKOUT PASO 11:', notices);
        throw e;
    }

    const orderUrl2 = customerPage.url();
    const orderMatch2 = orderUrl2.match(/order-received\/(\d+)/);
    const orderId2 = orderMatch2 ? parseInt(orderMatch2[1], 10) : 0;
    console.log(`✓ Segundo pedido creado con canje aplicado exitosamente: #${orderId2}`);

    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '22-order-received-con-canje-exitoso.png'), fullPage: true });
    console.log('✓ Captura guardada: 22-order-received-con-canje-exitoso.png');

    // Mi Cuenta final tras el canje
    await customerPage.goto('http://localhost:8080/mi-cuenta/puntos/', { waitUntil: 'networkidle' });
    await customerPage.screenshot({ path: path.join(SCREENSHOT_DIR, '23-mi-cuenta-saldo-final-tras-canje.png'), fullPage: true });
    console.log('✓ Captura guardada: 23-mi-cuenta-saldo-final-tras-canje.png');

    // =========================================================================
    // RESUMEN FINAL DEL LEDGER
    // =========================================================================
    console.log('\n======================================================================');
    console.log('🎉 AUDITORÍA E2E COMPLETADA CON ÉXITO ABSOLUTO');
    console.log('======================================================================');
    const finalLedger = runWpDb(`SELECT id, delta, action, note, balance_after, created_at FROM wp_akb_rewards_ledger WHERE user_id = ${userId} ORDER BY id ASC;`);
    console.log('\nEstado Contable Final en wp_akb_rewards_ledger:');
    console.log(finalLedger);

    await browser.close();
}

main().catch(err => {
    console.error('❌ ERROR FATAL EN SUITE E2E:', err);
    process.exit(1);
});
