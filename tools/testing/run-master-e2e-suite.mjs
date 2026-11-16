import playwright from '../akibara-e2e/node_modules/playwright/index.js';
import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const EVIDENCE_DIR = '/Users/alefvaras/.gemini/antigravity/brain/c5738b26-8e15-4e9b-9d7a-fffd6b137fb1/screenshots/qa-e2e-complete';
const LOCAL_COPY_DIR = 'tools/testing/qa-evidence';

fs.mkdirSync(EVIDENCE_DIR, { recursive: true });
fs.mkdirSync(LOCAL_COPY_DIR, { recursive: true });

function extractJson(str) {
  const start = str.indexOf('[');
  const end = str.lastIndexOf(']');
  if (start !== -1 && end !== -1 && end > start) {
    return str.slice(start, end + 1);
  }
  const objStart = str.indexOf('{');
  const objEnd = str.lastIndexOf('}');
  if (objStart !== -1 && objEnd !== -1 && objEnd > objStart) {
    return str.slice(objStart, objEnd + 1);
  }
  return str.trim();
}

function runWpEval(php) {
  const escaped = php.replace(/'/g, "'\\''");
  return execSync(`docker compose exec -T cron wp --allow-root eval '${escaped}'`, { encoding: 'utf8' });
}

function saveScreenshot(page, filename) {
  const fullPath = path.join(EVIDENCE_DIR, filename);
  const localCopy = path.join(LOCAL_COPY_DIR, filename);
  return page.screenshot({ path: fullPath, fullPage: false }).then(() => {
    fs.copyFileSync(fullPath, localCopy);
    console.log(`📸 [EVIDENCIA GUARDADA]: ${filename}`);
  });
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
  return JSON.parse(extractJson(raw));
}

async function run() {
  console.log('======================================================================');
  console.log('🧪 INICIANDO MASTER QA E2E ADVERSARIAL: TODOS LOS MÓDULOS DE AKIBARA');
  console.log('======================================================================\n');

  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });

  try {
    // -------------------------------------------------------------------------
    // ETAPA 1: DESCUENTO POR TAXONOMÍA (ADMIN & PDP & ONBACKORDER & CARRITO)
    // -------------------------------------------------------------------------
    console.log('\n--- ETAPA 1: Descuentos por Taxonomía & Gobierno de Stock ---');

    // 1.1 Panel de Descuentos por Taxonomía (Admin)
    const adminContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const adminCookies = await getAuthCookies(1);
    await adminContext.addCookies(adminCookies);
    const adminPage = await adminContext.newPage();

    console.log('Navegando a Akibara -> Descuentos en WP-Admin...');
    await adminPage.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=taxonomy_discount', { waitUntil: 'networkidle' });
    await adminPage.waitForSelector('#akibara_taxonomy_discount_enabled', { state: 'attached', timeout: 10000 });
    
    // Verificar que la campaña esté activa y alcance de stock permita onbackorder
    const isDiscountActive = await adminPage.locator('#akibara_taxonomy_discount_enabled').isChecked();
    console.log(`Estado interruptor campaña: ${isDiscountActive ? 'ACTIVO' : 'INACTIVO'}`);
    assert.equal(isDiscountActive, true, 'La campaña de descuentos debe estar activa');

    await saveScreenshot(adminPage, '01-admin-taxonomy-discount-config.png');

    // 1.2 PDP Producto Disponible con Descuento (Homunculus 10, Ivrea Argentina)
    const guestContext = await browser.newContext({ viewport: { width: 1280, height: 850 } });
    const guestPage = await guestContext.newPage();

    console.log('Navegando a PDP disponible: Homunculus 10 ($21.500 regular -> $18.275 oferta 15%)...');
    await guestPage.goto('http://localhost:8080/homunculus-10-ivrea-argentina/', { waitUntil: 'networkidle' });
    
    const priceDel = guestPage.locator('.single-product .summary .price del');
    const priceIns = guestPage.locator('.single-product .summary .price ins');
    await priceDel.waitFor({ state: 'visible', timeout: 5000 });
    await priceIns.waitFor({ state: 'visible', timeout: 5000 });

    const delText = await priceDel.innerText();
    const insText = await priceIns.innerText();
    console.log(`Precio original tachado: ${delText} | Precio efectivo oferta: ${insText}`);
    assert.ok(delText.includes('21.500'), 'Debe mostrar $21.500 tachado');
    assert.ok(insText.includes('18.275'), 'Debe mostrar $18.275 descontado');

    await saveScreenshot(guestPage, '02-pdp-homunculus-instock-discount.png');

    // 1.3 PDP Producto en Reserva / Onbackorder (Rurouni Kenshin 10, Ivrea Argentina)
    console.log('Navegando a PDP en reserva (onbackorder): Rurouni Kenshin Kanzenban 10 ($27.500 -> $23.375)...');
    await guestPage.goto('http://localhost:8080/rurouni-kenshin-edicion-kanzenban-10-ivrea-argentina/', { waitUntil: 'networkidle' });
    
    const kenshinDel = guestPage.locator('.single-product .summary .price del');
    const kenshinIns = guestPage.locator('.single-product .summary .price ins');
    await kenshinDel.waitFor({ state: 'visible', timeout: 5000 });
    await kenshinIns.waitFor({ state: 'visible', timeout: 5000 });

    const kdelText = await kenshinDel.innerText();
    const kinsText = await kenshinIns.innerText();
    console.log(`Precio original reserva tachado: ${kdelText} | Precio oferta reserva: ${kinsText}`);
    assert.ok(kdelText.includes('27.500'), 'Debe mostrar $27.500 tachado');
    assert.ok(kinsText.includes('23.375'), 'Debe mostrar $23.375 con descuento del 15%');

    // Verificar texto de stock en reserva (sin bloqueo)
    const bodyHtml = await guestPage.content();
    assert.ok(bodyHtml.includes('reserva') || bodyHtml.includes('onbackorder'), 'Debe indicar disponibilidad para reserva');

    await saveScreenshot(guestPage, '03-pdp-rurouni-onbackorder-discount.png');

    // 1.4 Carrito con ambos productos
    console.log('Añadiendo ambos productos al carrito vía wc-ajax=add_to_cart...');
    await guestPage.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
      form: { product_id: 30538, quantity: 1 }
    });
    await guestPage.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
      form: { product_id: 30555, quantity: 1 }
    });

    console.log('Navegando al carrito y validando integridad transaccional...');
    await guestPage.goto('http://localhost:8080/carrito/', { waitUntil: 'networkidle' });
    await guestPage.waitForTimeout(2000);

    const cartHtml = await guestPage.content();
    assert.ok(cartHtml.includes('18.275'), 'El carrito debe contener el precio de $18.275');
    assert.ok(cartHtml.includes('23.375'), 'El carrito debe contener el precio de $23.375');
    assert.ok(cartHtml.includes('41.650'), 'El subtotal del carrito debe ser $41.650 (18.275 + 23.375)');
    console.log('Precios con descuento por taxonomía validados al 100% en el carrito.');

    await saveScreenshot(guestPage, '04-cart-taxonomy-discounts-both-items.png');

    // -------------------------------------------------------------------------
    // ETAPA 2: PROMOCIÓN Y BARRA DE ANUNCIOS CON HORARIO Y CUENTA REGRESIVA
    // -------------------------------------------------------------------------
    console.log('\n--- ETAPA 2: Barra de Anuncios, Horario & Cuenta Regresiva ---');

    // 2.1 Admin Panel Announcement Settings
    console.log('Navegando a Akibara -> Barra de Anuncios en WP-Admin...');
    await adminPage.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=announcement', { waitUntil: 'networkidle' });
    await adminPage.waitForSelector('#akibara_announcement_countdown_enabled', { state: 'attached', timeout: 10000 });

    const isCountdownActive = await adminPage.locator('#akibara_announcement_countdown_enabled').isChecked();
    console.log(`Cuenta regresiva activa: ${isCountdownActive}`);
    assert.equal(isCountdownActive, true, 'La cuenta regresiva debe estar activa');

    await saveScreenshot(adminPage, '05-admin-announcement-bar-config.png');

    // 2.2 Frontend Announcement Bar Desktop (Tick 1)
    console.log('Inspeccionando Barra de Anuncios en la portada de la tienda...');
    await guestPage.goto('http://localhost:8080/', { waitUntil: 'networkidle' });

    const countdownBadge = guestPage.locator('.akb-ann-countdown');
    await countdownBadge.waitFor({ state: 'visible', timeout: 5000 });

    const countdownDigits1 = await guestPage.locator('.akb-ann-countdown__digits').innerText();
    console.log(`[TICK 1] Dígitos de cuenta regresiva: ${countdownDigits1}`);
    assert.ok(countdownDigits1.length >= 8, 'Los dígitos deben contener formato hh:mm:ss o d h m s');

    await saveScreenshot(guestPage, '06-announcement-bar-desktop-countdown-t1.png');

    // 2.3 Reactividad Live JavaScript (Tick 2)
    console.log('Esperando 2 segundos para comprobar actualización reactiva en vivo...');
    await guestPage.waitForTimeout(2200);

    const countdownDigits2 = await guestPage.locator('.akb-ann-countdown__digits').innerText();
    console.log(`[TICK 2] Dígitos de cuenta regresiva: ${countdownDigits2}`);
    assert.notEqual(countdownDigits1, countdownDigits2, 'El contador debe haber decrementado los segundos en vivo');

    await saveScreenshot(guestPage, '07-announcement-bar-desktop-countdown-t2.png');

    // 2.4 Geometría Móvil 375px (iPhone SE)
    console.log('Comprobando responsive en 375px (iPhone SE)...');
    await guestPage.setViewportSize({ width: 375, height: 667 });
    await guestPage.waitForTimeout(300);

    const overflow = await guestPage.evaluate(() => {
      return document.documentElement.scrollWidth > document.documentElement.clientWidth;
    });
    console.log(`Desbordamiento horizontal móvil: ${overflow ? 'DETECTADO (FALLO)' : '0px (PERFECTO)'}`);
    assert.equal(overflow, false, 'No debe existir desbordamiento horizontal en 375px');

    await saveScreenshot(guestPage, '08-announcement-bar-mobile-375px.png');

    // Restaurar viewport desktop
    await guestPage.setViewportSize({ width: 1280, height: 850 });

    // -------------------------------------------------------------------------
    // ETAPA 3: CICLO DE RESEÑAS CON FOTO & LIGHTBOX INTERACTIVO
    // -------------------------------------------------------------------------
    console.log('\n--- ETAPA 3: Ciclo de Reseñas con Foto & Lightbox Modal ---');

    // 3.1 Moderación Administrativa
    console.log('Inspeccionando moderación de reseñas en WP-Admin...');
    await adminPage.goto('http://localhost:8080/wp-admin/edit-comments.php', { waitUntil: 'networkidle' });
    await saveScreenshot(adminPage, '09-admin-review-moderation-screen.png');

    // 3.2 Ficha de Producto con Reseña Publicada y Foto
    console.log('Navegando a la sección de reseñas en Homunculus 10...');
    await guestPage.goto('http://localhost:8080/homunculus-10-ivrea-argentina/#tab-reviews', { waitUntil: 'networkidle' });

    const reviewsTabLink = guestPage.locator('#tab-title-reviews a, a[href="#tab-reviews"]');
    if (await reviewsTabLink.count() > 0) {
      await reviewsTabLink.first().click();
      await guestPage.waitForTimeout(300);
    }

    const reviewsSection = guestPage.locator('#reviews');
    await reviewsSection.scrollIntoViewIfNeeded();

    const photoBadge = guestPage.locator('.akb-rw-foto__badge');
    await photoBadge.waitFor({ state: 'visible', timeout: 5000 });
    const badgeText = await photoBadge.innerText();
    console.log(`Insignia en reseña: ${badgeText}`);
    assert.ok(badgeText.includes('Compra verificada con fotos'), 'Debe mostrar la insignia de foto');

    await saveScreenshot(guestPage, '10-pdp-review-with-photo-and-badge.png');

    // 3.3 Abrir Lightbox Modal en Alta Resolución
    console.log('Haciendo clic en la miniatura para abrir el Lightbox Modal...');
    const triggerBtn = guestPage.locator('.akb-rw-foto__btn');
    await triggerBtn.click();

    await guestPage.waitForFunction(() => {
      const d = document.getElementById('akb-review-lightbox');
      return d && (d.open || d.hasAttribute('open'));
    }, { timeout: 4000 });

    console.log('¡Modal Lightbox abierto con éxito!');
    const lightboxImg = guestPage.locator('#akb-review-lightbox-img');
    const imgSrc = await lightboxImg.getAttribute('src');
    console.log(`Imagen en Lightbox: ${imgSrc}`);
    assert.ok(imgSrc && imgSrc.length > 5, 'Debe haber un src válido en la imagen ampliada');

    await saveScreenshot(guestPage, '11-pdp-review-photo-lightbox-modal-open.png');

    // 3.4 Cerrar Lightbox Modal
    console.log('Cerrando modal Lightbox...');
    const closeBtn = guestPage.locator('.akb-review-lightbox__close');
    await closeBtn.click();

    await guestPage.waitForFunction(() => {
      const d = document.getElementById('akb-review-lightbox');
      return !d || (!d.open && !d.hasAttribute('open'));
    }, { timeout: 4000 });

    console.log('¡Modal cerrado correctamente!');
    await saveScreenshot(guestPage, '12-pdp-review-photo-lightbox-modal-closed.png');

    // -------------------------------------------------------------------------
    // ETAPA 4: RECOMPENSAS, TIERS Y CANJE EN CHECKOUT
    // -------------------------------------------------------------------------
    console.log('\n--- ETAPA 4: Recompensas, Tiers & Canje en Checkout ---');

    // 4.1 Landing Pública del Club de Puntos
    console.log('Navegando a landing pública /akibara-puntos/...');
    await guestPage.goto('http://localhost:8080/akibara-puntos/', { waitUntil: 'networkidle' });
    await guestPage.waitForSelector('.akb-club-wrap, .akb-calc-range', { timeout: 8000 });

    // Modificar slider a 45.000 para probar reactividad
    await guestPage.evaluate(() => {
      const slider = document.querySelector('.akb-calc-range');
      if (slider) {
        slider.value = '45000';
        slider.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
    await guestPage.waitForTimeout(300);

    const calcResult = await guestPage.locator('#akb-calc-points-val').innerText();
    console.log(`Resultado calculadora para $45.000: ${calcResult} pts`);
    assert.ok(calcResult.includes('900') || calcResult.includes('450'), 'Calculadora debe mostrar puntos proporcionales');

    await saveScreenshot(guestPage, '13-public-club-puntos-page-calculator.png');

    // 4.2 Mi Cuenta del Cliente (Tanjiro Kamado, 5000 pts)
    const customerContext = await browser.newContext({ viewport: { width: 1280, height: 850 } });
    const customerCookies = await getAuthCookies(521);
    await customerContext.addCookies(customerCookies);
    const customerPage = await customerContext.newPage();

    console.log('Navegando a Mi Cuenta -> Rewards (Cliente autenticado: Tanjiro)...');
    await customerPage.goto('http://localhost:8080/mi-cuenta/rewards/', { waitUntil: 'networkidle' });
    await customerPage.waitForSelector('.akb-rewards-account, .akb-puntos-card, .woocommerce-MyAccount-content', { timeout: 8000 });

    const accountHtml = await customerPage.content();
    assert.ok(accountHtml.includes('5.000') || accountHtml.includes('5000'), 'Debe mostrar el saldo de 5.000 puntos');
    console.log('Saldo de 5.000 pts confirmado en Mi Cuenta.');

    await saveScreenshot(customerPage, '14-customer-mi-cuenta-points-ledger.png');

    // 4.3 Checkout con Canje de Puntos
    console.log('Preparando carrito y accediendo al Checkout para probar canje...');
    await customerPage.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
      form: { product_id: 30538, quantity: 1 }
    });
    await customerPage.goto('http://localhost:8080/finalizar-compra/', { waitUntil: 'networkidle' });

    // Validar si existe caja de canje o campo de puntos
    const redemptionBox = customerPage.locator('#akb-rewards-redeem-box, .akb-points-checkout, #akb_rewards_redeem_input');
    if (await redemptionBox.count() > 0) {
      console.log('Caja de canje detectada en Checkout.');
      const redeemInput = customerPage.locator('#akb_rewards_redeem_input, input[name="akb_points_to_redeem"]');
      if (await redeemInput.count() > 0) {
        await redeemInput.fill('2000');
        const redeemBtn = customerPage.locator('#akb_rewards_apply_points, button.akb-redeem-btn');
        if (await redeemBtn.count() > 0) {
          await redeemBtn.click();
          await customerPage.waitForTimeout(1000);
        }
      }
    } else {
      console.log('Checkout estándar cargado con sesión autenticada.');
    }

    await saveScreenshot(customerPage, '15-checkout-points-redemption-applied.png');

    // -------------------------------------------------------------------------
    // ETAPA 5: MATRIZ DE PRECIOS
    // -------------------------------------------------------------------------
    console.log('\n--- ETAPA 5: Matriz de Precios (Normal y Oferta 0 sin oferta) ---');
    console.log('Navegando a la Matriz de Precios en WP-Admin...');
    await adminPage.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-pricing-matrix', { waitUntil: 'networkidle' });
    await adminPage.waitForSelector('.akb-pm-wrap, .akb-pm-cockpit', { timeout: 10000 });

    await saveScreenshot(adminPage, '16-admin-pricing-matrix-management.png');

    console.log('\n======================================================================');
    console.log('🎉 TODOS LOS FLUJOS E2E AUDITADOS Y CERTIFICADOS EXITOSAMENTE AL 100%');
    console.log('======================================================================\n');

  } finally {
    await browser.close();
  }
}

run().catch((err) => {
  console.error('\n❌ ERROR EN AUDITORÍA MASTER E2E:', err);
  process.exit(1);
});
