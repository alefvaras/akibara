import playwright from '../akibara-e2e/node_modules/playwright/index.js';
import { execSync as exec } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';

const pw = playwright.default || playwright;
const { chromium } = pw;

const screenshotDir = '/Users/alefvaras/.gemini/antigravity/brain/c5738b26-8e15-4e9b-9d7a-fffd6b137fb1/screenshots';
if (!existsSync(screenshotDir)) {
  mkdirSync(screenshotDir, { recursive: true });
}

function getProductState(productId) {
  const raw = exec(`docker exec akibara-cron-1 wp eval '
    $p = wc_get_product(${productId});
    echo json_encode([
      "id" => $p->get_id(),
      "name" => $p->get_name(),
      "regular_price" => $p->get_regular_price(),
      "sale_price" => $p->get_sale_price(),
      "price" => $p->get_price()
    ]);
  '`, { encoding: 'utf8' });
  return JSON.parse(raw.trim());
}

async function run() {
  console.log('🚀 Iniciando verificación de Matriz de Precios (Normal y Oferta 0 = Sin Oferta)...');

  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });

  // 1. Obtener cookies de autenticación de admin
  const authRaw = exec(`docker exec akibara-cron-1 wp eval '
      $u = get_user_by("id", 1);
      $exp = time() + 7200;
      $tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
      echo json_encode([
          "name" => LOGGED_IN_COOKIE,
          "value" => wp_generate_auth_cookie($u->ID, $exp, "logged_in", $tok),
          "domain" => "localhost",
          "path" => "/"
      ]);
  '`, { encoding: 'utf8' });

  const adminCookieRaw = exec(`docker exec akibara-cron-1 wp eval '
      $u = get_user_by("id", 1);
      $exp = time() + 7200;
      $tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
      echo json_encode([
          "name" => AUTH_COOKIE,
          "value" => wp_generate_auth_cookie($u->ID, $exp, "auth", $tok),
          "domain" => "localhost",
          "path" => "/"
      ]);
  '`, { encoding: 'utf8' });

  const authCookie = JSON.parse(authRaw);
  const adminCookie = JSON.parse(adminCookieRaw);

  const context = await browser.newContext({
    viewport: { width: 1440, height: 1100 }
  });
  await context.addCookies([authCookie, adminCookie]);

  const page = await context.newPage();

  // 2. Navegar a la página de Matriz de Precios
  console.log('📍 Navegando a http://localhost:8080/wp-admin/admin.php?page=akibara-pricing-matrix ...');
  await page.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-pricing-matrix', { waitUntil: 'networkidle' });

  await page.waitForSelector('#akb-pricing-scales-table');
  console.log('✓ Tabla de escalas cargada.');

  // Captura 1: Vista general con nuevos campos
  await page.screenshot({
    path: `${screenshotDir}/pricing-matrix-01-initial-view.png`,
    fullPage: false
  });
  console.log('📸 Captura 1 guardada: pricing-matrix-01-initial-view.png');

  // 3. Filtrar por Distrito Manga para focalizar la fila de prueba ($24.990)
  const searchInput = page.locator('#akb-scales-search');
  await searchInput.fill('distrito-manga');
  await page.waitForTimeout(300);

  // Ubicar la fila de escala 24990
  const rowSelector = 'tr.akb-scale-searchable-row:has-text("24.990")';
  const targetRow = page.locator(rowSelector).first();
  await targetRow.waitFor({ state: 'visible' });

  // 4. Modificar Precio Normal (24990) y fijar Oferta en $21.990
  console.log('✍️ Modificando valores: Regular $24.990 | Oferta $21.990 ...');
  const regInput = targetRow.locator('input[name="new_regular"]');
  const saleInput = targetRow.locator('input[name="manual_sale"]');

  await regInput.fill('24990');
  await saleInput.fill('21990');

  // 5. Clic en "Simular" (Dry-Run)
  console.log('🔍 Ejecutando Simulación (Dry-Run)...');
  const dryBtn = targetRow.locator('button[name="dry_run"]');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    dryBtn.click()
  ]);

  // Verificar banner de propuesta simulada
  const proposalBanner = page.locator('.akb-proposal-banner');
  await proposalBanner.waitFor({ state: 'visible' });
  const proposalText = await proposalBanner.innerText();
  console.log('✓ Banner de propuesta visible.');
  if (proposalText.includes('21.990')) {
    console.log('✓ Propuesta incluye correctamente el precio oferta $21.990');
  } else {
    throw new Error('El banner de propuesta no mostró el precio oferta $21.990');
  }

  // Captura 2: Propuesta simulada con tabla de tomos y ofertas
  await page.screenshot({
    path: `${screenshotDir}/pricing-matrix-02-dry-run-proposal.png`,
    fullPage: false
  });
  console.log('📸 Captura 2 guardada: pricing-matrix-02-dry-run-proposal.png');

  // 6. Confirmar y Aplicar en vivo
  console.log('⚡ Confirmando y aplicando propuesta a catálogo...');
  const confirmBtn = page.locator('.akb-btn-apply-submit');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    confirmBtn.click()
  ]);

  // Verificar en base de datos WooCommerce que los productos tienen regular $24990 y oferta $21990
  const p1_afterSale = getProductState(32869);
  const p2_afterSale = getProductState(32871);
  console.log(`Estado tras aplicar oferta:`, {
    p1: { regular: p1_afterSale.regular_price, sale: p1_afterSale.sale_price, price: p1_afterSale.price },
    p2: { regular: p2_afterSale.regular_price, sale: p2_afterSale.sale_price, price: p2_afterSale.price }
  });

  if (p1_afterSale.sale_price !== '21990' || p1_afterSale.price !== '21990') {
    throw new Error(`Producto 32869 no tiene sale_price = 21990 (obtenido: ${p1_afterSale.sale_price})`);
  }
  if (p2_afterSale.sale_price !== '21990' || p2_afterSale.price !== '21990') {
    throw new Error(`Producto 32871 no tiene sale_price = 21990 (obtenido: ${p2_afterSale.sale_price})`);
  }
  console.log('✓ Ambos tomos quedaron con regular $24.990 y oferta activa $21.990 en WooCommerce.');

  // 7. AHORA PROBAR LA REGLA CLAVE: Ingresar "0" en Precio Oferta -> Debe quedar SIN OFERTA
  console.log('🎯 Probando regla clave: fijar Precio Oferta en "0" para dejar SIN OFERTA...');
  const searchInputUpdated = page.locator('#akb-scales-search');
  await searchInputUpdated.fill('distrito-manga');
  await page.waitForTimeout(500);

  const targetRowUpdated = page.locator(rowSelector).first();
  await targetRowUpdated.waitFor({ state: 'visible' });

  // Verificar que la columna "Precio Actual" muestra la oferta
  const priceColText = await targetRowUpdated.locator('.akb-pm-col-price').innerText();
  console.log('Texto actual de la celda de precio:', priceColText.replace(/\n/g, ' '));

  const saleInputUpdated = targetRowUpdated.locator('input[name="manual_sale"]');
  await saleInputUpdated.fill('0'); // <--- 0 = SIN OFERTA

  // Clic en Aplicar (rápido vía AJAX)
  const applyBtn = targetRowUpdated.locator('.akb-pm-btn-level');
  console.log('⚡ Guardando vía AJAX...');
  await applyBtn.click();

  // Esperar a que el toast de éxito aparezca o a que la fila cambie
  await page.waitForSelector('.akb-pm-ajax-toast', { state: 'attached', timeout: 8000 });
  console.log('✓ Toast de guardado AJAX recibido.');

  // Esperar un momento a que la celda se actualice
  await page.waitForTimeout(800);

  // Captura 3: UI con la oferta removida ("Sin oferta") y campo en 0
  await page.screenshot({
    path: `${screenshotDir}/pricing-matrix-03-sale-zero-no-offer.png`,
    fullPage: false
  });
  console.log('📸 Captura 3 guardada: pricing-matrix-03-sale-zero-no-offer.png');

  // 8. Verificar en WooCommerce que la oferta fue completamente eliminada
  const p1_final = getProductState(32869);
  const p2_final = getProductState(32871);
  console.log(`Estado final en BD WooCommerce:`, {
    p1: { regular: p1_final.regular_price, sale: p1_final.sale_price, price: p1_final.price },
    p2: { regular: p2_final.regular_price, sale: p2_final.sale_price, price: p2_final.price }
  });

  if (p1_final.sale_price !== '' || p1_final.price !== '24990') {
    throw new Error(`Producto 32869 no quedó sin oferta! sale_price: "${p1_final.sale_price}", price: "${p1_final.price}"`);
  }
  if (p2_final.sale_price !== '' || p2_final.price !== '24990') {
    throw new Error(`Producto 32871 no quedó sin oferta! sale_price: "${p2_final.sale_price}", price: "${p2_final.price}"`);
  }

  console.log('🎉 CERTIFICADO EXITOSAMENTE:');
  console.log('  1. Precio Normal y Precio Oferta editables simultáneamente.');
  console.log('  2. Dry-Run refleja exactamente regular y oferta.');
  console.log('  3. Guardado fija regular y oferta en WooCommerce.');
  console.log('  4. Ingresar 0 elimina completamente la oferta (_sale_price = "") dejando el producto sin oferta a precio normal.');

  await browser.close();
}

run().catch(err => {
  console.error('❌ Error en prueba Playwright:', err);
  process.exit(1);
});
