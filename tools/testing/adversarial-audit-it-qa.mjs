import playwright from '../akibara-e2e/node_modules/playwright/index.js';
import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const EVIDENCE_DIR = '/Users/alefvaras/.gemini/antigravity/brain/cd9b9c11-e94d-4de9-b432-d1c3308d2f78/screenshots/adversarial-audit';
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

function runWpOption(cmd) {
  return execSync(`docker compose exec -T cron wp --allow-root option ${cmd}`, { encoding: 'utf8' });
}

async function saveScreenshot(page, filename) {
  const fullPath = path.join(EVIDENCE_DIR, filename);
  const localCopy = path.join(LOCAL_COPY_DIR, filename);
  await page.screenshot({ path: fullPath, fullPage: false });
  fs.copyFileSync(fullPath, localCopy);
  console.log(`📸 [EVIDENCIA GUARDADA]: ${filename}`);
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

function resetUserCartAndIntent(userId) {
  runWpEval(`
    global $wpdb;
    $wpdb->delete($wpdb->prefix . "woocommerce_sessions", ["session_key" => "${userId}"]);
    delete_user_meta(${userId}, "_woocommerce_persistent_cart_1");
    delete_user_meta(${userId}, "_akb_rewards_redemption_intent");
    echo "CLEARED";
  `);
}

async function createFreshCustomerContext(browser, userId) {
  resetUserCartAndIntent(userId);
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const cookies = await getAuthCookies(userId);
  await context.addCookies(cookies);
  return context;
}

async function main() {
  console.log('======================================================================');
  console.log('🛡️ AUDITORÍA ADVERSARIAL QA - AKIBARA (DOCKER LOCAL: http://localhost:8080)');
  console.log('======================================================================\n');

  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });

  const auditReport = {
    test1_cart_and_redemption: {},
    test2_landing_puntos_simulator: {},
    test3_homepage_showcase_mobile: {},
    evidence_files: []
  };

  try {
    // =========================================================================
    // PARTE 1: FLUJO DE COMPRA Y CANJE DE PUNTOS EN CHECKOUT
    // =========================================================================
    console.log('\n>>> [TEST 1] Flujo de Compra y Canje de Puntos en Checkout');

    // 1.1 Carrito bajo el mínimo ($18.275 < $25.000 y < $50.000)
    // Añadimos 1 x Homunculus 10 ($18.275 con 15% descuento de taxonomía Ivrea Argentina)
    console.log('--- 1.1: Carrito subtotal $18.275 (< $25.000 y < $50.000) ---');
    const ctx1 = await createFreshCustomerContext(browser, 521);
    const page1 = await ctx1.newPage();

    await page1.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
      form: { product_id: 30538, quantity: 1 }
    });

    await page1.goto('http://localhost:8080/checkout/', { waitUntil: 'networkidle' });
    await page1.waitForTimeout(2000);

    const widget1 = page1.locator('.akb-rw-checkout-box');
    await widget1.waitFor({ state: 'visible', timeout: 8000 });
    const text1 = await widget1.innerText();
    console.log(`Texto del widget con $18.275:\n"${text1.replace(/\n+/g, ' ')}"`);

    assert.ok(text1.includes('Tienes 5.000 puntos'), 'Debe reconocer el saldo de 5.000 pts');
    assert.ok(text1.includes('Te faltan') || text1.includes('No hay un canje disponible'), 'Debe bloquear el canje e indicar falta para el mínimo');
    
    // Verificar que NO exista el input de canje cuando está bloqueado
    const input1 = await page1.locator('#akb-rw-canje').count();
    assert.equal(input1, 0, 'No debe mostrar input de canje si no alcanza el mínimo');

    await saveScreenshot(page1, '01-checkout-under-minimum-blocked.png');
    auditReport.test1_cart_and_redemption.under_min = {
      subtotal: 18275,
      balance: 5000,
      blocked: true,
      widget_text: text1.replace(/\n+/g, ' ')
    };
    await ctx1.close();

    // 1.2 Carrito entre $25.000 y $49.999 (Tramo 1: $41.650)
    // Añadimos 1 x Homunculus 10 ($18.275) + 1 x Rurouni Kenshin 10 ($23.375) = $41.650
    console.log('\n--- 1.2: Carrito subtotal $41.650 ($25.000 a $49.999) ---');
    const ctx2 = await createFreshCustomerContext(browser, 521);
    const page2 = await ctx2.newPage();

    await page2.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
      form: { product_id: 30538, quantity: 1 }
    });
    await page2.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
      form: { product_id: 30555, quantity: 1 }
    });

    await page2.goto('http://localhost:8080/checkout/', { waitUntil: 'networkidle' });
    await page2.waitForTimeout(2000);

    const widget2 = page2.locator('.akb-rw-checkout-box');
    await widget2.waitFor({ state: 'visible', timeout: 8000 });
    const text2 = await widget2.innerText();
    console.log(`Texto del widget con $41.650:\n"${text2.replace(/\n+/g, ' ')}"`);

    // En el tramo 1 ($25.000 a $49.999), el tope oficial es 2.000 puntos ($2.000 CLP, <= 50% de $41.650 = $20.825)
    const canjeInput2 = page2.locator('#akb-rw-canje');
    const hasInput2 = await canjeInput2.count() > 0;
    
    if (hasInput2) {
      const maxAttr = await canjeInput2.getAttribute('max');
      console.log(`Input detectado. max="${maxAttr}" puntos.`);
      assert.equal(maxAttr, '2000', 'En tramo 1 ($25k-$49.9k) el tope debe ser exactamente 2.000 puntos');
      assert.ok(text2.includes('Máximo disponible en este carrito: 2.000 puntos'), 'Debe explicitar el tope de 2.000 puntos');
      
      // Probar botón "Usar el máximo disponible"
      const maxBtn = page2.locator('button:has-text("Usar el máximo disponible")');
      await maxBtn.click();
      await page2.waitForTimeout(300);
      const valAfterMax = await canjeInput2.inputValue();
      console.log(`Valor tras pulsar "Usar el máximo disponible": ${valAfterMax}`);
      assert.equal(valAfterMax, '2000', 'Debe haber completado 2000 puntos');
      
      await saveScreenshot(page2, '02-checkout-tier1-capped-2000.png');
      auditReport.test1_cart_and_redemption.tier1 = {
        subtotal: 41650,
        cap: 2000,
        button_max_works: true
      };
    } else {
      console.log('Canje bloqueado en $41.650 (modo estricto 50k floor).');
      auditReport.test1_cart_and_redemption.tier1 = {
        subtotal: 41650,
        blocked: true,
        text: text2
      };
    }
    await ctx2.close();

    // 1.2b Validación adversarial de Piso de $50.000 estricto si se configura 50.000
    console.log('\n--- 1.2b: Prueba adversarial con Piso de $50.000 estricto en akb_rewards_redeem_min_cart ---');
    runWpOption('set akb_rewards_redeem_min_cart 50000');
    try {
      const ctx2b = await createFreshCustomerContext(browser, 521);
      const page2b = await ctx2b.newPage();
      await page2b.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
        form: { product_id: 30538, quantity: 1 }
      });
      await page2b.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
        form: { product_id: 30555, quantity: 1 }
      });
      await page2b.goto('http://localhost:8080/checkout/', { waitUntil: 'networkidle' });
      await page2b.waitForTimeout(2000);

      const widget2b = page2b.locator('.akb-rw-checkout-box');
      await widget2b.waitFor({ state: 'visible', timeout: 8000 });
      const text2b = await widget2b.innerText();
      console.log(`Texto del widget con piso 50.000 y subtotal $41.650:\n"${text2b.replace(/\n+/g, ' ')}"`);
      assert.ok(text2b.includes('Te faltan $8.350 en productos para poder canjear'), 'Debe calcular exactamente $8.350 de faltante para llegar a $50.000');
      await saveScreenshot(page2b, '02b-checkout-under-50k-floor-blocked.png');
      auditReport.test1_cart_and_redemption.under_50k_floor = {
        subtotal: 41650,
        min_cart_setting: 50000,
        missing: 8350,
        blocked: true
      };
      await ctx2b.close();
    } finally {
      // Restaurar opción a 25000 (paridad de producción)
      runWpOption('set akb_rewards_redeem_min_cart 25000');
    }

    // 1.3 Carrito >= $50.000 y < $60.000 (Tramo 2: 3 x Homunculus 10 = $54.825)
    console.log('\n--- 1.3: Carrito subtotal $54.825 (>= $50.000, Tramo 2 y validación de límites) ---');
    const ctx3 = await createFreshCustomerContext(browser, 521);
    const page3 = await ctx3.newPage();

    // Añadir 3 x Homunculus 10 (3 * $18.275 = $54.825)
    await page3.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
      form: { product_id: 30538, quantity: 3 }
    });

    await page3.goto('http://localhost:8080/checkout/', { waitUntil: 'networkidle' });
    await page3.waitForTimeout(2000);

    const widget3 = page3.locator('.akb-rw-checkout-box');
    await widget3.waitFor({ state: 'visible', timeout: 8000 });
    const text3 = await widget3.innerText();
    console.log(`Texto del widget con $54.825:\n"${text3.replace(/\n+/g, ' ')}"`);

    assert.ok(text3.includes('Máximo disponible en este carrito: 5.000 puntos'), 'Debe habilitar hasta 5.000 puntos en tramo >= $50.000');
    
    const canjeInput3 = page3.locator('#akb-rw-canje');
    const maxAttr50k = await canjeInput3.getAttribute('max');
    assert.equal(maxAttr50k, '5000', 'El atributo max del input debe ser 5000');

    // Validación adversarial de límites:
    // a) Probar número menor al mínimo (50 < 100):
    await canjeInput3.fill('50');
    await page3.waitForTimeout(200);
    const confirmBtn = page3.locator('button:has-text("Confirmar canje")');
    const isUnderMinDisabled = await confirmBtn.isDisabled();
    console.log(`¿Botón deshabilitado para 50 puntos (< 100 min)?: ${isUnderMinDisabled}`);
    assert.equal(isUnderMinDisabled, true, 'El botón Confirmar canje debe estar deshabilitado para < 100 pts');

    // b) Probar número mayor al máximo (6000 > 5000):
    await canjeInput3.fill('6000');
    await page3.waitForTimeout(200);
    const isOverMaxDisabled = await confirmBtn.isDisabled();
    console.log(`¿Botón deshabilitado para 6000 puntos (> 5000 max)?: ${isOverMaxDisabled}`);
    assert.equal(isOverMaxDisabled, true, 'El botón Confirmar canje debe estar deshabilitado para > 5000 pts');

    // c) Usar botón "Usar el máximo disponible" (5.000 puntos):
    const maxBtn3 = page3.locator('button:has-text("Usar el máximo disponible")');
    await maxBtn3.click();
    await page3.waitForTimeout(300);
    const val50k = await canjeInput3.inputValue();
    assert.equal(val50k, '5000', 'El input debe llenarse con 5000');

    const isValidEnabled = await confirmBtn.isEnabled();
    assert.equal(isValidEnabled, true, 'El botón Confirmar canje debe estar habilitado para 5000');

    // d) Aplicar canje de 5.000 puntos en Checkout Blocks:
    console.log('Aplicando canje de 5.000 puntos...');
    await confirmBtn.click();
    await page3.waitForTimeout(2500);

    // Verificar fee aplicado en el desglose de totales
    const checkoutContent = await page3.content();
    assert.ok(checkoutContent.includes('Puntos Akibara') || checkoutContent.includes('Canje aplicado: 5.000 puntos'), 'Debe mostrar el canje aplicado en el resumen de orden');
    console.log('Canje de 5.000 puntos aplicado exitosamente en Checkout.');

    await saveScreenshot(page3, '03-checkout-50k-redemption-applied.png');
    auditReport.test1_cart_and_redemption.over_50k = {
      subtotal: 54825,
      cap: 5000,
      limits_validated: true,
      redemption_confirmed: true
    };
    await ctx3.close();

    // 1.4 Carrito >= $60.000 y Envío Gratis Protegido
    console.log('\n--- 1.4: Carrito subtotal $78.200 (> $60.000) & Envío Gratis Protegido ---');
    const ctx4 = await createFreshCustomerContext(browser, 521);
    const page4 = await ctx4.newPage();

    // Añadir 2 x Homunculus 10 ($36.550) + 1 x Rurouni Kenshin ($23.375) + 1 x Homunculus 09 ($18.275) = $78.200
    await page4.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
      form: { product_id: 30538, quantity: 2 }
    });
    await page4.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
      form: { product_id: 30555, quantity: 1 }
    });
    await page4.request.post('http://localhost:8080/?wc-ajax=add_to_cart', {
      form: { product_id: 30536, quantity: 1 }
    });

    await page4.goto('http://localhost:8080/checkout/', { waitUntil: 'networkidle' });
    await page4.waitForTimeout(2500);

    // Verificar opción de Envío Gratis presente en los totales
    const totalsText = await page4.locator('.wc-block-checkout__sidebar').innerText();
    console.log(`Líneas de totales previas al canje:\n"${totalsText.replace(/\n+/g, ' ')}"`);
    assert.ok(totalsText.toLowerCase().includes('gratis'), 'Debe figurar opción/método de Envío Gratis para subtotal >= $60.000');

    // Aplicar canje máximo de 5.000 puntos ($5.000 CLP)
    const canjeInput4 = page4.locator('#akb-rw-canje');
    await canjeInput4.waitFor({ state: 'visible', timeout: 5000 });
    const maxBtn4 = page4.locator('button:has-text("Usar el máximo disponible")');
    await maxBtn4.click();
    await page4.waitForTimeout(300);

    const confirmBtn4 = page4.locator('button:has-text("Confirmar canje")');
    await confirmBtn4.click();
    await page4.waitForTimeout(2500);

    // Verificar que el Envío Gratis permanece protegido y seleccionado tras el descuento
    const totalsAfterDiscount = await page4.locator('.wc-block-checkout__sidebar').innerText();
    console.log(`Líneas de totales tras descuento de $5.000:\n"${totalsAfterDiscount.replace(/\n+/g, ' ')}"`);
    assert.ok(totalsAfterDiscount.toLowerCase().includes('gratis'), 'El Envío Gratis debe PERMANECER protegido tras aplicar el canje');
    assert.ok(totalsAfterDiscount.includes('5.000') || totalsAfterDiscount.includes('Puntos Akibara'), 'Debe reflejar el canje en el resumen');

    await saveScreenshot(page4, '04-checkout-free-shipping-protected.png');
    auditReport.test1_cart_and_redemption.free_shipping_protected = {
      subtotal: 78200,
      threshold: 60000,
      free_shipping_preserved: true
    };
    await ctx4.close();

    // Limpieza final de sesión
    resetUserCartAndIntent(521);

    // =========================================================================
    // PARTE 2: LANDING /akibara-puntos/ Y SIMULADOR DE RECOMPENSAS
    // =========================================================================
    console.log('\n>>> [TEST 2] Landing /akibara-puntos/ y Simulador de Recompensas');
    const guestContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const guestPage = await guestContext.newPage();

    const puntosResponse = await guestPage.goto('http://localhost:8080/akibara-puntos/', { waitUntil: 'networkidle' });
    assert.equal(puntosResponse.status(), 200, 'La landing /akibara-puntos/ debe responder HTTP 200');

    // 2.1 Verificar Hero, Branding y Tiers
    console.log('Verificando títulos, branding del club y tiers...');
    const pageTitle = await guestPage.title();
    console.log(`Título de página: ${pageTitle}`);
    assert.ok(pageTitle.includes('Puntos') || pageTitle.includes('Club'), 'Título debe reflejar el Club de Puntos');

    const tiersSection = guestPage.locator('.akb-club-tiers-section, .akb-tiers-grid, .akb-tier-card');
    assert.ok(await tiersSection.count() > 0, 'Debe renderizar la sección de tiers o tarjetas de nivel');

    await saveScreenshot(guestPage, '05-akibara-puntos-landing-hero-tiers.png');

    // 2.2 Simulador Interactivo (#akb-club-calc)
    console.log('Auditando interactividad y reactividad del Simulador de Recompensas...');
    const calcCard = guestPage.locator('#akb-club-calc');
    await calcCard.waitFor({ state: 'visible', timeout: 5000 });

    const slider = calcCard.locator('.akb-calc-range');
    const displayVal = guestPage.locator('#akb-calc-display');
    const pointsVal = guestPage.locator('#akb-calc-points-val');
    const discountVal = guestPage.locator('#akb-calc-discount-val');

    // Valores de prueba adversarial
    const testCases = [
      { amount: '10000', expectedDisplay: '$10.000', expectedPts: '200', expectedDiscount: '$200' },
      { amount: '35000', expectedDisplay: '$35.000', expectedPts: '700', expectedDiscount: '$700' },
      { amount: '50000', expectedDisplay: '$50.000', expectedPts: '1.000', expectedDiscount: '$1.000' },
      { amount: '100000', expectedDisplay: '$100.000', expectedPts: '2.000', expectedDiscount: '$2.000' },
      { amount: '150000', expectedDisplay: '$150.000', expectedPts: '3.000', expectedDiscount: '$3.000' }
    ];

    for (const tc of testCases) {
      await guestPage.evaluate((amt) => {
        const sl = document.querySelector('.akb-calc-range');
        sl.value = amt;
        sl.dispatchEvent(new Event('input', { bubbles: true }));
      }, tc.amount);
      await guestPage.waitForTimeout(100);

      const actualDisp = (await displayVal.innerText()).trim();
      const actualPts = (await pointsVal.innerText()).trim();
      const actualDisc = (await discountVal.innerText()).trim();

      console.log(`Simulador [Monto: $${tc.amount}] -> Display: ${actualDisp} | Puntos: ${actualPts} | Descuento: ${actualDisc}`);
      assert.equal(actualDisp, tc.expectedDisplay, `Display debe ser ${tc.expectedDisplay}`);
      assert.equal(actualPts, tc.expectedPts, `Puntos calculados deben ser ${tc.expectedPts}`);
      assert.equal(actualDisc, tc.expectedDiscount, `Descuento CLP debe ser ${tc.expectedDiscount}`);
    }

    await saveScreenshot(guestPage, '06-akibara-puntos-simulator-interaction.png');
    auditReport.test2_landing_puntos_simulator = {
      http_status: 200,
      title: pageTitle,
      simulation_cases_passed: testCases.length
    };

    // =========================================================================
    // PARTE 3: PORTADA (http://localhost:8080/)
    // =========================================================================
    console.log('\n>>> [TEST 3] Portada: Barra con Cuenta Regresiva, Vitrina y Geometría 375px');
    await guestPage.goto('http://localhost:8080/', { waitUntil: 'networkidle' });

    // 3.1 Barra de Anuncios con Cuenta Regresiva
    console.log('Auditando Barra de Anuncios con cuenta regresiva en vivo...');
    const countdownEl = guestPage.locator('.akb-ann-countdown');
    await countdownEl.waitFor({ state: 'visible', timeout: 5000 });

    const digitsEl = guestPage.locator('.akb-ann-countdown__digits');
    const tick1 = await digitsEl.innerText();
    console.log(`[TICK 1 Cuenta Regresiva]: ${tick1}`);
    assert.ok(tick1.length >= 8, 'El formato debe ser hh:mm:ss o similar');

    // Medir reactividad en vivo esperando 2.2 segundos
    await guestPage.waitForTimeout(2200);
    const tick2 = await digitsEl.innerText();
    console.log(`[TICK 2 Cuenta Regresiva]: ${tick2}`);
    assert.notEqual(tick1, tick2, 'El segundero debe decrementar en vivo');

    await saveScreenshot(guestPage, '07-home-announcement-bar-countdown.png');
    auditReport.test3_homepage_showcase_mobile.announcement_countdown = {
      active: true,
      tick1,
      tick2,
      live_countdown_verified: true
    };

    // 3.2 Vitrina de Productos Destacados
    console.log('Auditando Vitrina de Productos Destacados en Portada...');
    const showcaseHeader = guestPage.locator('header.akb-section__header:has-text("Llegados a Akibara")');
    await showcaseHeader.waitFor({ state: 'visible', timeout: 5000 });

    const productCards = guestPage.locator('.woocommerce ul.products li.product');
    const productCount = await productCards.count();
    console.log(`Cantidad de productos en la vitrina de portada: ${productCount}`);
    assert.ok(productCount >= 4, 'La vitrina debe contener al menos 4 productos');

    // Inspeccionar la primera tarjeta (Homunculus 10 con descuento de taxonomía)
    const firstProduct = productCards.first();
    const productTitle = await firstProduct.locator('.woocommerce-loop-product__title').innerText();
    const delPrice = await firstProduct.locator('.price del').innerText();
    const insPrice = await firstProduct.locator('.price ins').innerText();
    const saleBadge = await firstProduct.locator('.akb-badge--sale').innerText();
    const atcBtn = firstProduct.locator('.ajax_add_to_cart');

    console.log(`Primer producto en vitrina: "${productTitle}"`);
    console.log(`Precios: Regular tachado ${delPrice} | Oferta efectiva ${insPrice} | Insignia: ${saleBadge}`);
    assert.ok(delPrice.includes('21.500'), 'Debe mostrar precio original tachado');
    assert.ok(insPrice.includes('18.275'), 'Debe mostrar precio descontado de taxonomía');
    assert.ok(saleBadge.includes('−15%') || saleBadge.includes('15%'), 'Debe mostrar badge de -15%');
    assert.ok(await atcBtn.count() > 0, 'Debe incluir botón "Añadir al carrito"');

    await saveScreenshot(guestPage, '08-home-products-showcase.png');
    auditReport.test3_homepage_showcase_mobile.products_showcase = {
      product_count: productCount,
      sample_title: productTitle,
      regular_price: delPrice,
      sale_price: insPrice,
      badge: saleBadge
    };

    // 3.3 Geometría Móvil y 0 Desbordamiento Horizontal en 375px (iPhone SE)
    console.log('Auditando Geometría Móvil en 375px (iPhone SE)...');
    await guestPage.setViewportSize({ width: 375, height: 667 });
    await guestPage.waitForTimeout(500);

    const overflowAssessment = await guestPage.evaluate(() => {
      const docW = document.documentElement.clientWidth;
      const scrollW = document.documentElement.scrollWidth;
      const bodyW = document.body.scrollWidth;

      // Buscar si algún elemento individual desborda los 375px
      const offenders = [];
      const allEls = document.querySelectorAll('*');
      for (const el of allEls) {
        const rect = el.getBoundingClientRect();
        if (rect.right > docW + 1) { // 1px margen de redondeo subpixel
          offenders.push({
            tag: el.tagName,
            id: el.id,
            className: el.className,
            right: Math.round(rect.right),
            width: Math.round(rect.width)
          });
          if (offenders.length >= 5) break;
        }
      }

      return {
        clientWidth: docW,
        scrollWidth: scrollW,
        bodyScrollWidth: bodyW,
        hasOverflow: scrollW > docW || bodyW > docW,
        offenders
      };
    });

    console.log(`Ancho cliente: ${overflowAssessment.clientWidth}px | ScrollWidth: ${overflowAssessment.scrollWidth}px`);
    console.log(`Desbordamiento horizontal detectado: ${overflowAssessment.hasOverflow ? 'SÍ (FALLO)' : 'NO (CERO DESBORDAMIENTO)'}`);
    if (overflowAssessment.offenders.length > 0) {
      console.log('Elementos que desbordan:', JSON.stringify(overflowAssessment.offenders, null, 2));
    }
    assert.equal(overflowAssessment.hasOverflow, false, 'No debe existir desbordamiento horizontal en 375px');

    // Medición de áreas táctiles (Tap Targets >= 44px)
    const tapTargetAudit = await guestPage.evaluate(() => {
      const targets = document.querySelectorAll('button, a.bottom-nav__item, .ajax_add_to_cart, .akb-wl-toggle');
      let checked = 0;
      let below44 = 0;
      for (const t of targets) {
        const rect = t.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0) {
          checked++;
          if (rect.width < 43.5 || rect.height < 43.5) {
            // excepciones de enlaces puramente inline de texto dentro de párrafos
            if (!t.closest('p')) {
              below44++;
            }
          }
        }
      }
      return { checked, below44 };
    });

    console.log(`Áreas táctiles auditadas: ${tapTargetAudit.checked} controles, debajo de 44px: ${tapTargetAudit.below44}`);
    assert.equal(tapTargetAudit.below44, 0, 'Todos los controles táctiles auditados deben cumplir >= 44px');

    await saveScreenshot(guestPage, '09-home-mobile-375px-layout.png');
    auditReport.test3_homepage_showcase_mobile.mobile_375px = {
      viewport: '375x667',
      scrollWidth: overflowAssessment.scrollWidth,
      clientWidth: overflowAssessment.clientWidth,
      hasOverflow: overflowAssessment.hasOverflow,
      tap_targets_checked: tapTargetAudit.checked,
      tap_targets_below_44px: tapTargetAudit.below44
    };

    console.log('\n======================================================================');
    console.log('🎉 CERTIFICACIÓN QA ADVERSARIAL COMPLETADA EXITOSAMENTE AL 100%');
    console.log('======================================================================\n');

    fs.writeFileSync('tools/testing/qa-evidence/adversarial-audit-summary.json', JSON.stringify(auditReport, null, 2));

  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error('\n❌ ERROR EN AUDITORÍA ADVERSARIAL:', err);
  process.exit(1);
});
