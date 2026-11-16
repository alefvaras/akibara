import playwright from '../akibara-e2e/node_modules/playwright/index.js';
import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const BASE_URL = 'http://localhost:8080';
const CONVERSATION_SCREENSHOT_DIR =
  '/Users/alefvaras/.gemini/antigravity/brain/660f589a-8fda-4b08-825d-e550e3aa72d6/screenshots/owner-comparison';
const LOCAL_ARTIFACTS_DIR = '.local-quality/owner-comparison';

fs.mkdirSync(CONVERSATION_SCREENSHOT_DIR, { recursive: true });
fs.mkdirSync(LOCAL_ARTIFACTS_DIR, { recursive: true });

function extractJson(str) {
  const arrStart = str.indexOf('[');
  const arrEnd = str.lastIndexOf(']');
  const objStart = str.indexOf('{');
  const objEnd = str.lastIndexOf('}');

  if (objStart !== -1 && (arrStart === -1 || objStart < arrStart) && objEnd > objStart) {
    return str.slice(objStart, objEnd + 1);
  }
  if (arrStart !== -1 && arrEnd > arrStart) {
    return str.slice(arrStart, arrEnd + 1);
  }
  return str.trim();
}

function wpEval(php) {
  const escaped = php.replace(/'/g, "'\\''");
  return execSync(`docker compose exec -T cron wp --allow-root eval '${escaped}'`, {
    encoding: 'utf8',
  });
}

function captureRestorePoint() {
  const raw = wpEval(`
    global $wpdb;
    $opt_keys = [
      'akibara_taxonomy_discount_enabled',
      'akibara_taxonomy_discount_from',
      'akibara_taxonomy_discount_to',
      'akibara_taxonomy_discount_apply_to',
      'akibara_taxonomy_discount_sale_policy',
      'akibara_taxonomy_discount_stacks_coupons',
      'akb_discount_max_pct',
      'akb_sale_min_badge_pct',
      'akb_pricing_matrix_rules',
      'akb_pricing_matrix_last_result',
    ];
    $sentinel = '__AKB_MISSING_OPT__';
    $opts = [];
    foreach ($opt_keys as $k) {
      $v = get_option($k, $sentinel);
      $opts[$k] = ($v === $sentinel) ? null : ['value' => $v];
    }
    $termmeta = $wpdb->get_results(
      "SELECT tm.term_id, tm.meta_key, tm.meta_value, t.name, tt.taxonomy
       FROM {$wpdb->termmeta} tm
       INNER JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
       INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
       WHERE tm.meta_key = '_akb_taxonomy_discount_pct'
       ORDER BY tm.term_id ASC",
      ARRAY_A
    );
    $products = [];
    foreach ([33094, 32869, 32871] as $pid) {
      $p = wc_get_product($pid);
      $products[(string)$pid] = [
        'regular_price' => $p->get_regular_price(),
        'sale_price' => $p->get_sale_price(),
        'price' => $p->get_price(),
      ];
    }
    $on_sale_count = (int) $wpdb->get_var(
      "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_sale_price' AND meta_value != '' AND CAST(meta_value AS UNSIGNED) > 0"
    );
    echo json_encode([
      'options' => $opts,
      'termmeta' => $termmeta,
      'products' => $products,
      'on_sale_count' => $on_sale_count,
    ]);
  `);
  return JSON.parse(extractJson(raw));
}

function restoreBaseline(restore) {
  const b64 = Buffer.from(JSON.stringify(restore), 'utf8').toString('base64');
  wpEval(`
    global $wpdb;
    $restore = json_decode(base64_decode('${b64}'), true);
    foreach ($restore['options'] as $k => $entry) {
      if ($entry === null) {
        delete_option($k);
      } else {
        update_option($k, $entry['value']);
      }
    }
    $existing_tids = $wpdb->get_col(
      "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_akb_taxonomy_discount_pct'"
    );
    foreach ($existing_tids as $tid) {
      delete_term_meta((int)$tid, '_akb_taxonomy_discount_pct');
    }
    if (!empty($existing_tids) && function_exists('clean_term_cache')) {
      clean_term_cache(array_map('intval', $existing_tids), '', true);
    }
    foreach ($restore['termmeta'] as $tm) {
      $tid = (int)$tm['term_id'];
      if (function_exists('clean_term_cache')) {
        clean_term_cache([$tid], '', true);
      }
      update_term_meta($tid, '_akb_taxonomy_discount_pct', $tm['meta_value']);
    }
    foreach ($restore['products'] as $pid_str => $pdata) {
      $p = wc_get_product((int)$pid_str);
      if ($p) {
        $p->set_regular_price((string)$pdata['regular_price']);
        $p->set_sale_price((string)$pdata['sale_price']);
        $p->save();
      }
    }
    if (function_exists('wc_delete_product_transients')) {
      foreach (array_keys($restore['products']) as $pid_str) {
        wc_delete_product_transients((int)$pid_str);
      }
    }
    wp_cache_flush();
  `);
}

function setManualSaleOnProduct(productId, regularPrice, salePrice) {
  wpEval(`
    $p = wc_get_product(${productId});
    $p->set_regular_price('${regularPrice}');
    $p->set_sale_price('${salePrice}');
    $p->save();
    if (function_exists('wc_delete_product_transients')) {
      wc_delete_product_transients(${productId});
    }
    wp_cache_flush();
  `);
}

async function getAdminCookies(userId = 1) {
  const raw = wpEval(`
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
        "path" => "/wp-admin"
      ]
    ]);
  `);
  return JSON.parse(extractJson(raw));
}

async function saveShot(page, name) {
  const dest = path.join(CONVERSATION_SCREENSHOT_DIR, name);
  await page.screenshot({ path: dest, fullPage: false });
  return dest;
}

function parseClp(text) {
  const digits = String(text || '').replace(/[^0-9]/g, '');
  return digits ? Number.parseInt(digits, 10) : 0;
}

async function readGuestPdpPrice(guestPage, url) {
  const cacheBustUrl = url + (url.includes('?') ? '&' : '?') + '_akb_qa=' + Date.now();
  await guestPage.goto(cacheBustUrl, { waitUntil: 'networkidle' });

  return guestPage.evaluate(() => {
    const priceRoot = document.querySelector('.single-product .summary .price');
    const delEl = priceRoot?.querySelector('del .woocommerce-Price-amount');
    const insEl = priceRoot?.querySelector('ins .woocommerce-Price-amount');
    const singleEl = priceRoot?.querySelector(':scope > .woocommerce-Price-amount');
    const badgeEl = document.querySelector('.akb-discount-badge, .onsale, .akb-pdp-discount-badge');
    return {
      rawText: priceRoot?.textContent?.replace(/\s+/g, ' ').trim() || '',
      delText: delEl?.textContent?.trim() || null,
      insText: insEl?.textContent?.trim() || null,
      singleText: singleEl?.textContent?.trim() || null,
      badgeText: badgeEl?.textContent?.trim() || null,
      overflowPx: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
  });
}

async function saveDiscountTab(adminPage, configureFn) {
  await adminPage.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-panel&tab=taxonomy_discount`, {
    waitUntil: 'networkidle',
  });
  await adminPage.waitForSelector('.akb-panel-form', { timeout: 10000 });
  await configureFn(adminPage);

  // Trigger change/input so dirty state & sticky save bar appear if needed, then click Save
  const submitBtn = adminPage.locator('.akb-panel-form button[name="akibara_panel_save"]').first();
  await Promise.all([
    adminPage.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
    submitBtn.click(),
  ]);
  const noticeText = await adminPage.locator('.notice, .updated, .akb-notice').allInnerTexts();
  return noticeText.join(' | ');
}

async function run() {
  console.log('========================================================================');
  console.log('🧪 PLAYWRIGHT DUEÑO & CLIENTE — COMPARATIVA AKIBARA-BUSINESS 1.124.1 -> 1.124.2');
  console.log('========================================================================\n');

  const restorePoint = captureRestorePoint();
  console.log('📌 Punto de restauración capturado:');
  console.log(`   - on_sale_count inicial: ${restorePoint.on_sale_count}`);
  console.log(`   - termmeta descuentos:   ${restorePoint.termmeta.length} fila(s)`);
  console.log(`   - One Piece 110 (33094): regular=${restorePoint.products['33094'].regular_price}, sale="${restorePoint.products['33094'].sale_price}"`);
  console.log(`   - Diarios Boticaria 1-2: regular=${restorePoint.products['32869'].regular_price} / ${restorePoint.products['32871'].regular_price}\n`);

  const browser = await chromium.launch({
    headless: true,
    executablePath:
      process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });

  const report = {
    version: '1.124.2',
    target: BASE_URL,
    ownerDiscountUx: {},
    policyComparison: [],
    pricingMatrixComparison: {},
    mobileGeometry: {},
    restoreVerification: {},
    screenshots: [],
  };

  try {
    const adminContext = await browser.newContext({ viewport: { width: 1440, height: 920 } });
    await adminContext.addCookies(await getAdminCookies(1));
    const adminPage = await adminContext.newPage();
    adminPage.on('dialog', async (dialog) => {
      await dialog.accept();
    });

    const guestContext = await browser.newContext({ viewport: { width: 1280, height: 850 } });
    const guestPage = await guestContext.newPage();

    // =========================================================================
    // FASE 1: FLUJO DEL DUEÑO EN DESCUENTOS (UX 1.124.2 + EJEMPLO + BOTÓN NUEVO)
    // =========================================================================
    console.log('--- FASE 1: Panel Dueño → Descuentos por Taxonomía (UX 1.124.2) ---');
    const t0Discount = Date.now();
    await adminPage.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-panel&tab=taxonomy_discount`, {
      waitUntil: 'networkidle',
    });
    const discountLoadMs = Date.now() - t0Discount;
    await adminPage.waitForSelector('.akb-panel-form', { timeout: 10000 });

    // Abrir acordeón General y verificar copy realista de OPTION_SALE_POLICY
    const generalAccordion = adminPage.locator('details#akb-accordion-general, details.akb-accordion').first();
    await generalAccordion.evaluate((el) => {
      el.open = true;
    });
    const generalText = await generalAccordion.innerText();
    assert.ok(generalText.includes('$10.000'), 'Descripción debe usar ejemplo $10.000');
    assert.ok(generalText.includes('$9.000 (10%)'), 'Descripción debe usar rebaja manual $9.000 (10%)');
    assert.ok(generalText.includes('tope de tienda en 15%'), 'Descripción debe mencionar tope real del 15%');
    assert.ok(generalText.includes('$8.550'), 'Descripción debe mostrar ejemplo 5% sobre $9.000 -> $8.550');
    assert.ok(generalText.includes('se detiene en $8.500'), 'Descripción debe documentar detención en $8.500');
    console.log('✅ Copy de política manual verificado con límites reales (15%, $8.550 / tope $8.500).');

    // Abrir acordeón Editoriales y probar filtro + contador + botón Poner visibles en 0%
    const brandBar = adminPage.locator('.akb-tax-filter-bar[data-accordion="product_brand"]');
    const brandAccordion = brandBar.locator('xpath=ancestor::details[1]');
    await brandAccordion.evaluate((el) => {
      el.open = true;
    });

    const initialCounter = await adminPage.locator('#akb-filter-counter-product_brand').innerText();
    assert.ok(initialCounter.includes('con descuento'), `Contador debe indicar "(N con descuento)", obtuvo: ${initialCounter}`);

    await adminPage.fill('#akb-filter-search-product_brand', 'Ivrea Argentina');
    await adminPage.waitForTimeout(100);
    const filteredCounter = await adminPage.locator('#akb-filter-counter-product_brand').innerText();
    assert.ok(filteredCounter.startsWith('1 visibles de '), `Esperaba 1 visible para Ivrea Argentina, obtuvo: ${filteredCounter}`);

    const clearBtn = brandBar.locator('.akb-filter-clear-visible');
    assert.equal(await clearBtn.isVisible(), true, 'Botón "Poner visibles en 0%" debe estar visible cuando hay visibles > 0%');

    const s1 = await saveShot(adminPage, '01-owner-descuentos-filtro-y-limpiar-visibles.png');
    report.screenshots.push(s1);

    // Probar clic en "Poner visibles en 0%" (sin guardar en BD, solo UX interactiva)
    await clearBtn.click();
    await adminPage.waitForTimeout(100);
    const ivreaAfterClear = await adminPage.locator('#akibara_taxonomy_discount_pct_230').inputValue();
    assert.equal(ivreaAfterClear, '', 'El clic en "Poner visibles en 0%" debe vaciar el porcentaje visible');
    const clearBtnHiddenAfter = await clearBtn.isHidden();
    assert.equal(clearBtnHiddenAfter, true, 'Botón "Poner visibles en 0%" debe ocultarse al quedar 0 activos visibles');
    const stickyBarActive = await adminPage.locator('#akb-sticky-save-bar').evaluate((el) => el.classList.contains('is-active'));
    assert.equal(stickyBarActive, true, 'La barra flotante "#akb-sticky-save-bar" debe activarse al limpiar visibles');

    report.ownerDiscountUx = {
      loadMs: discountLoadMs,
      initialCounter,
      filteredCounter,
      clearBtnVisibleBeforeClick: true,
      clearBtnHiddenAfterClick: clearBtnHiddenAfter,
      stickyBarActivated: stickyBarActive,
    };
    console.log(`✅ Botón "Poner visibles en 0%" y contador enriquecido verificados (${initialCounter} -> ${filteredCounter}).\n`);

    // =========================================================================
    // FASE 2: COMPARATIVA EN VIVO DE LAS 4 POLÍTICAS (DUEÑO EN ADMIN -> CLIENTE EN PDP)
    // Producto: One Piece 110 (ID 33094), regular = $10.600, manual_sale temporal = $9.500 (~10.38% off)
    // Editorial: Ivrea Argentina (term_id = 230). Tope tienda = 15% ($9.010 piso).
    // =========================================================================
    console.log('--- FASE 2: Prueba Dueño -> Cliente de las 4 Políticas (One Piece 110: $10.600 / manual $9.500) ---');
    setManualSaleOnProduct(33094, '10600', '9500');

    const onePieceUrl = `${BASE_URL}/one-piece-110-ivrea-argentina/`;

    const scenarios = [
      {
        label: '1. skip_if_on_sale + regla 15% (tope tienda 15%)',
        policy: 'skip_if_on_sale',
        termPct: '15',
        expectedClientPrice: 9500,
        v11233Expected: 9500,
        improvementNote: 'En 1.124.0 retorna null en O(1) antes de resolver taxonomías',
      },
      {
        label: '2. greatest_wins + regla 15% (tope tienda 15%)',
        policy: 'greatest_wins',
        termPct: '15',
        expectedClientPrice: 9010,
        v11233Expected: 9010,
        improvementNote: '15% ($9.010) supera rebaja manual ($9.500) -> gana $9.010',
      },
      {
        label: '3. from_regular + regla 15% (tope tienda 15%)',
        policy: 'from_regular',
        termPct: '15',
        expectedClientPrice: 9010,
        v11233Expected: 9010,
        improvementNote: 'Descarta $9.500 y aplica 15% sobre $10.600 -> $9.010',
      },
      {
        label: '4a. stack_on_sale + regla 15% (tope tienda 15%)',
        policy: 'stack_on_sale',
        termPct: '15',
        expectedClientPrice: 9010,
        v11233Expected: 8075,
        improvementNote: 'FIX CRÍTICO: 1.123.3 daba $8.075 (23.8%, perforando tope 15%); 1.124.0 detiene en $9.010 (15%)',
      },
      {
        label: '4b. stack_on_sale + regla 5% (dentro de tope 15%)',
        policy: 'stack_on_sale',
        termPct: '5',
        expectedClientPrice: 9025,
        v11233Expected: 9025,
        improvementNote: 'Encadena 5% sobre $9.500 = $9.025 (14.86% < 15%, permitido)',
      },
    ];

    for (const sc of scenarios) {
      await saveDiscountTab(adminPage, async (page) => {
        const gAcc = page.locator('details#akb-accordion-general, details.akb-accordion').first();
        await gAcc.evaluate((el) => {
          el.open = true;
        });
        const enabledBox = page.locator('#akibara_taxonomy_discount_enabled');
        if (!(await enabledBox.isChecked())) {
          await enabledBox.check();
        }
        await page.fill('#akibara_taxonomy_discount_from', '');
        await page.fill('#akibara_taxonomy_discount_to', '');
        await page.locator(`input[name="akibara_taxonomy_discount_apply_to"][value="instock_only"]`).check();
        await page.locator(`input[name="akibara_taxonomy_discount_sale_policy"][value="${sc.policy}"]`).check();

        const bAcc = page.locator('.akb-tax-filter-bar[data-accordion="product_brand"]').locator('xpath=ancestor::details[1]');
        await bAcc.evaluate((el) => {
          el.open = true;
        });
        await page.fill('#akibara_taxonomy_discount_pct_230', sc.termPct);
      });

      const pdp = await readGuestPdpPrice(guestPage, onePieceUrl);
      const effectivePrice = pdp.insText ? parseClp(pdp.insText) : parseClp(pdp.singleText);
      const regularSeen = pdp.delText ? parseClp(pdp.delText) : effectivePrice;

      assert.equal(regularSeen, 10600, `${sc.label}: Regular visto debe ser $10.600, obtuvo ${pdp.rawText}`);
      assert.equal(
        effectivePrice,
        sc.expectedClientPrice,
        `${sc.label}: Cliente debe ver $${sc.expectedClientPrice}, obtuvo ${pdp.rawText}`
      );

      console.log(
        `✅ ${sc.label}: Cliente ve $${effectivePrice.toLocaleString('es-CL')} (1.123.3 daba $${sc.v11233Expected.toLocaleString('es-CL')})`
      );

      if (sc.policy === 'stack_on_sale' && sc.termPct === '15') {
        const sStack = await saveShot(guestPage, '02-client-pdp-stack-on-sale-clamped-15pct.png');
        report.screenshots.push(sStack);
      }

      report.policyComparison.push({
        scenario: sc.label,
        v1_123_3: sc.v11233Expected,
        v1_124_0: effectivePrice,
        pdpRawText: pdp.rawText,
        note: sc.improvementNote,
      });
    }

    // Limpiar One Piece 110 y apagar campaña inmediatamente después de la fase 2
    restoreBaseline(restorePoint);
    console.log('✅ One Piece 110 y campaña Descuentos restaurados tras Fase 2.\n');

    // =========================================================================
    // FASE 3: FLUJO DEL DUEÑO EN MATRIZ DE PRECIOS (1.124.0) + CLIENTE EN PDP
    // Escala: Distrito Manga — $24.990 (2 tomos: 32869 y 32871)
    // =========================================================================
    console.log('--- FASE 3: Panel Dueño → Matriz de Precios (Escala Distrito Manga $24.990) ---');
    const t0Matrix = Date.now();
    await adminPage.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-pricing-matrix`, {
      waitUntil: 'networkidle',
    });
    const matrixLoadMs = Date.now() - t0Matrix;
    await adminPage.waitForSelector('#akb-scales-search', { timeout: 10000 });

    // Filtrar por "distrito"
    await adminPage.fill('#akb-scales-search', 'distrito');
    await adminPage.waitForTimeout(100);

    const rowLocator = adminPage
      .locator('tr.akb-scale-searchable-row')
      .filter({ has: adminPage.locator('input[name="editorial_slug"][value="distrito-manga"]') })
      .filter({ has: adminPage.locator('input[name="scale_regular"][value="24990"]') });

    assert.equal(await rowLocator.count(), 1, 'Debe existir exactamente 1 fila Distrito Manga $24.990');

    // Verificar accesibilidad WCAG de cabeceras ordenables (.akb-sortable) y toggle asc/desc con click + Enter
    const sortableHeaders = adminPage.locator('#akb-pricing-scales-table th.akb-sortable');
    const sortableCount = await sortableHeaders.count();
    assert.ok(sortableCount >= 6, 'Tabla debe tener al menos 6 cabeceras ordenables .akb-sortable');
    for (let i = 0; i < sortableCount; i += 1) {
      assert.equal(await sortableHeaders.nth(i).getAttribute('tabindex'), '0', 'Cabecera ordenable debe ser enfocable con teclado');
      assert.equal(await sortableHeaders.nth(i).getAttribute('aria-sort'), 'none', 'Cabecera ordenable debe iniciar con aria-sort="none"');
    }
    const firstSortable = sortableHeaders.first();
    await firstSortable.click();
    assert.equal(await firstSortable.getAttribute('aria-sort'), 'ascending');
    await firstSortable.focus();
    await firstSortable.press('Enter');
    assert.equal(await firstSortable.getAttribute('aria-sort'), 'descending');
    assert.equal(await sortableHeaders.nth(1).getAttribute('aria-sort'), 'none');

    // Verificar 3 botones rápidos .akb-pm-quick-step
    const quickSteps = rowLocator.locator('.akb-pm-quick-step');
    assert.equal(await quickSteps.count(), 3, 'La fila debe tener 3 botones rápidos (-$500, +$500, +$1.000)');

    const regInput = rowLocator.locator('input.akb-calc-reg');
    const saleInput = rowLocator.locator('input.akb-calc-sale');
    assert.equal(await regInput.inputValue(), '24990');
    assert.equal(await saleInput.inputValue(), '0');

    // Probar clics rápidos: +$500 -> +$1.000 -> -$500 => $25.990
    await rowLocator.locator('.akb-pm-quick-step[data-step="500"]').click();
    assert.equal(await regInput.inputValue(), '25490', '+$500 debe llevar 24990 -> 25490');
    await rowLocator.locator('.akb-pm-quick-step[data-step="1000"]').click();
    assert.equal(await regInput.inputValue(), '26490', '+$1.000 debe llevar 25490 -> 26490');
    await rowLocator.locator('.akb-pm-quick-step[data-step="-500"]').click();
    assert.equal(await regInput.inputValue(), '25990', '-$500 debe llevar 26490 -> 25990');

    const sMatrix = await saveShot(adminPage, '03-owner-matriz-botones-rapidos.png');
    report.screenshots.push(sMatrix);

    // Simular (Dry-Run) a $25.990
    await Promise.all([
      adminPage.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
      rowLocator.locator('button.akb-pm-btn-dry').click(),
    ]);
    const proposalBanner = adminPage.locator('.akb-proposal-banner');
    await proposalBanner.waitFor({ state: 'visible', timeout: 10000 });
    const bannerText = await proposalBanner.innerText();
    assert.ok(bannerText.includes('$24.990'), 'Banner Dry-Run debe mostrar Precio Regular Base $24.990');
    assert.ok(bannerText.includes('$25.990'), 'Banner Dry-Run debe mostrar Nuevo Regular $25.990');
    assert.ok(bannerText.includes('Sin oferta (0)'), 'Banner Dry-Run debe mostrar Sin oferta (0)');
    assert.equal(await adminPage.locator('#akb-proposal-apply-count').innerText(), '2');

    const sDryRun = await saveShot(adminPage, '04-owner-matriz-simulacion-dry-run.png');
    report.screenshots.push(sDryRun);
    console.log('✅ Botones rápidos (-$500/+$500/+$1.000) y simulación Dry-Run ($24.990 -> $25.990, 2 tomos) OK.');

    // Descartar propuesta inicial
    await Promise.all([
      adminPage.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
      proposalBanner.locator('button:has-text("Descartar propuesta")').click(),
    ]);
    assert.equal(await adminPage.locator('.akb-proposal-banner').count(), 0, 'Descartar propuesta debe retirar el banner');

    // Simular (Dry-Run) a $25.490 para comprobar también limpieza reactiva del banner al aplicar por AJAX
    await adminPage.fill('#akb-scales-search', 'distrito');
    await adminPage.waitForTimeout(100);
    const secondSimRow = adminPage
      .locator('tr.akb-scale-searchable-row')
      .filter({ has: adminPage.locator('input[name="editorial_slug"][value="distrito-manga"]') })
      .filter({ has: adminPage.locator('input[name="scale_regular"][value="24990"]') });
    await secondSimRow.locator('.akb-pm-quick-step[data-step="500"]').click();
    assert.equal(await secondSimRow.locator('input.akb-calc-reg').inputValue(), '25490');
    await Promise.all([
      adminPage.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }),
      secondSimRow.locator('button.akb-pm-btn-dry').click(),
    ]);
    await adminPage.locator('.akb-proposal-banner').waitFor({ state: 'visible', timeout: 10000 });

    // Filtrar otra vez, verificar marcador draft y aplicar por AJAX a $25.490 sin descartar manualmente el banner
    await adminPage.fill('#akb-scales-search', 'distrito');
    await adminPage.waitForTimeout(100);
    const ajaxRow = adminPage
      .locator('tr.akb-scale-searchable-row')
      .filter({ has: adminPage.locator('input[name="editorial_slug"][value="distrito-manga"]') })
      .filter({ has: adminPage.locator('input[name="scale_regular"][value="24990"]') });

    assert.equal(await ajaxRow.evaluate((el) => el.classList.contains('akb-row-draft-active')), true, 'Fila simulada debe tener akb-row-draft-active');
    assert.equal(await ajaxRow.locator('.akb-pm-draft-badge').count(), 1, 'Fila simulada debe mostrar .akb-pm-draft-badge');

    await ajaxRow.locator('input.akb-calc-reg').fill('25490');
    assert.equal(await ajaxRow.locator('input.akb-calc-reg').inputValue(), '25490');

    const t0Ajax = Date.now();
    await ajaxRow.locator('button.akb-pm-btn-level').click();
    await adminPage.waitForSelector('.akb-pm-ajax-toast.is-visible', { timeout: 10000 });
    const ajaxApplyMs = Date.now() - t0Ajax;
    const toastLocator = adminPage.locator('.akb-pm-ajax-toast').last();
    assert.equal(await toastLocator.getAttribute('role'), 'status', 'Toast AJAX debe tener role="status"');
    assert.equal(await toastLocator.getAttribute('aria-live'), 'polite', 'Toast AJAX debe tener aria-live="polite"');
    const toastMsg = await toastLocator.innerText();

    // Banner Dry-Run y marca draft deben desaparecer del DOM sin recargar página
    assert.equal(await adminPage.locator('.akb-proposal-banner').count(), 0, 'Banner Dry-Run debe desaparecer tras guardar por AJAX');

    // Verificar que la fila quedó reactivamente en $25.490, Sin oferta, sin badge draft, data-has-difference="0" y scale_regular="25490"
    const updatedRow = adminPage
      .locator('tr.akb-scale-searchable-row')
      .filter({ has: adminPage.locator('input[name="editorial_slug"][value="distrito-manga"]') })
      .filter({ has: adminPage.locator('input[name="scale_regular"][value="25490"]') });
    assert.equal(await updatedRow.count(), 1, 'Tras AJAX la fila debe actualizar su hidden scale_regular a 25490');
    assert.equal(await updatedRow.evaluate((el) => el.classList.contains('akb-row-draft-active')), false, 'Clase akb-row-draft-active debe limpiarse tras guardar por AJAX');
    assert.equal(await updatedRow.locator('.akb-pm-draft-badge').count(), 0, 'Badge .akb-pm-draft-badge debe limpiarse tras guardar por AJAX');
    assert.equal(await updatedRow.locator('.akb-pm-col-price strong').innerText(), '$25.490');
    assert.equal(await updatedRow.locator('.akb-pm-sale-indicator').innerText(), 'Sin oferta');
    assert.equal(await updatedRow.getAttribute('data-has-difference'), '0');

    const sAjax = await saveShot(adminPage, '05-owner-matriz-ajax-aplicado.png');
    report.screenshots.push(sAjax);

    // Verificar como Cliente invitado en el PDP que subió a $25.490 sin tachado
    const boticariaUrl = `${BASE_URL}/los-diarios-de-la-boticaria-la-novela-original-1/`;
    const pdpAfterAjax = await readGuestPdpPrice(guestPage, boticariaUrl);
    assert.equal(parseClp(pdpAfterAjax.singleText), 25490, `Cliente debe ver $25.490 sin oferta, obtuvo: ${pdpAfterAjax.rawText}`);
    assert.equal(pdpAfterAjax.delText, null, 'No debe existir precio tachado porque manual_sale = 0');
    console.log(`✅ Guardado AJAX a $25.490 en ${ajaxApplyMs}ms (${toastMsg}) y verificado en tienda cliente ($25.490).`);

    // Encadenar segunda actualización AJAX SIN recargar página (usando -$500 -> $24.990) para demostrar fluidez de escala encadenada
    await updatedRow.locator('.akb-pm-quick-step[data-step="-500"]').click();
    assert.equal(await updatedRow.locator('input.akb-calc-reg').inputValue(), '24990');
    await updatedRow.locator('button.akb-pm-btn-level').click();
    await adminPage.waitForSelector('.akb-pm-ajax-toast.is-visible', { timeout: 10000 });
    await adminPage.waitForTimeout(300);

    const pdpRestored = await readGuestPdpPrice(guestPage, boticariaUrl);
    assert.equal(parseClp(pdpRestored.singleText), 24990, `Cliente debe volver a ver $24.990, obtuvo: ${pdpRestored.rawText}`);
    console.log('✅ Segunda actualización AJAX encadenada (sin recargar!) restauró la escala a $24.990 y cliente ve $24.990.');

    report.pricingMatrixComparison = {
      adminLoadMs: matrixLoadMs,
      ajaxApplyMs,
      toastMessage: toastMsg,
      quickStepsCount: 3,
      dryRunVerified: true,
      clientPriceAfterApply: parseClp(pdpAfterAjax.singleText),
      clientPriceAfterRestore: parseClp(pdpRestored.singleText),
    };

    // =========================================================================
    // FASE 4: GEOMETRÍA MÓVIL (375x812 — 0 DESBORDAMIENTO)
    // =========================================================================
    console.log('\n--- FASE 4: Auditoría de Geometría Móvil (375x812) ---');
    const mobileAdminContext = await browser.newContext({ viewport: { width: 375, height: 812 } });
    await mobileAdminContext.addCookies(await getAdminCookies(1));
    const mAdminPage = await mobileAdminContext.newPage();

    await mAdminPage.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-panel&tab=taxonomy_discount`, { waitUntil: 'networkidle' });
    const mobileDiscountCheck = await mAdminPage.evaluate(() => {
      const rulesTable = document.querySelector('.akb-active-rules');
      const rulesRows = document.querySelectorAll('.akb-active-rules tbody tr');
      return {
        overflowPx: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        hasActiveRulesTable: Boolean(rulesTable && rulesRows.length > 0),
        theadDisplay: rulesTable ? getComputedStyle(rulesTable.querySelector('thead')).display : null,
      };
    });
    assert.equal(mobileDiscountCheck.hasActiveRulesTable, true, 'La tabla .akb-active-rules debe estar presente y visible durante la prueba móvil');
    assert.equal(mobileDiscountCheck.theadDisplay, 'none', 'El thead de .akb-active-rules debe ocultarse en móvil 375px');
    const overflowDescuentos = mobileDiscountCheck.overflowPx;

    await mAdminPage.goto(`${BASE_URL}/wp-admin/admin.php?page=akibara-pricing-matrix`, { waitUntil: 'networkidle' });
    const overflowMatriz = await mAdminPage.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth
    );

    const mobileGuestContext = await browser.newContext({ viewport: { width: 375, height: 812 } });
    const mGuestPage = await mobileGuestContext.newPage();
    await mGuestPage.goto(onePieceUrl, { waitUntil: 'networkidle' });
    const overflowPdp = await mGuestPage.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth
    );

    assert.ok(overflowDescuentos <= 0, `Overflow en Descuentos móvil: ${overflowDescuentos}px`);
    assert.ok(overflowMatriz <= 0, `Overflow en Matriz móvil: ${overflowMatriz}px`);
    assert.ok(overflowPdp <= 0, `Overflow en PDP móvil: ${overflowPdp}px`);

    report.mobileGeometry = {
      descuentosAdminOverflowPx: overflowDescuentos,
      matrizAdminOverflowPx: overflowMatriz,
      pdpClientOverflowPx: overflowPdp,
    };
    console.log(
      `✅ Geometría móvil 375px: Descuentos=${overflowDescuentos}px, Matriz=${overflowMatriz}px, PDP=${overflowPdp}px.`
    );

    await mobileAdminContext.close();
    await mobileGuestContext.close();
    await adminContext.close();
    await guestContext.close();
  } finally {
    // Restauración determinística estricta pase lo que pase
    restoreBaseline(restorePoint);
    const postRestore = captureRestorePoint();
    const normalizeTermMeta = (rows) =>
      rows.map(({ term_id, meta_key, meta_value, name, taxonomy }) => ({
        term_id: String(term_id),
        meta_key: String(meta_key),
        meta_value: String(meta_value),
        name: String(name),
        taxonomy: String(taxonomy),
      }));

    assert.equal(postRestore.on_sale_count, restorePoint.on_sale_count, 'on_sale_count debe coincidir con punto de restauración');
    assert.deepEqual(
      normalizeTermMeta(postRestore.termmeta),
      normalizeTermMeta(restorePoint.termmeta),
      'termmeta de descuentos debe coincidir con el punto de restauración'
    );
    assert.equal(postRestore.products['33094'].regular_price, restorePoint.products['33094'].regular_price);
    assert.equal(postRestore.products['33094'].sale_price, restorePoint.products['33094'].sale_price);
    assert.equal(postRestore.products['32869'].regular_price, restorePoint.products['32869'].regular_price);
    assert.equal(postRestore.products['32871'].regular_price, restorePoint.products['32871'].regular_price);

    report.restoreVerification = {
      onSaleBefore: restorePoint.on_sale_count,
      onSaleAfter: postRestore.on_sale_count,
      termMetaCountAfter: postRestore.termmeta.length,
      matchesRestorePoint: true,
    };

    fs.writeFileSync(
      path.join(LOCAL_ARTIFACTS_DIR, 'owner-comparison-report.json'),
      JSON.stringify(report, null, 2),
      'utf8'
    );
    await browser.close();
  }

  console.log('\n========================================================================');
  console.log('🏁 PRUEBA E2E DUEÑO & CLIENTE COMPLETADA 100% VERDE');
  console.log(`   Reporte JSON: ${path.join(LOCAL_ARTIFACTS_DIR, 'owner-comparison-report.json')}`);
  console.log(`   Capturas PNG: ${CONVERSATION_SCREENSHOT_DIR}`);
  console.log('========================================================================');
}

run().catch((err) => {
  console.error('❌ ERROR EN SUITE PLAYWRIGHT DUEÑO & CLIENTE:', err);
  process.exit(1);
});
