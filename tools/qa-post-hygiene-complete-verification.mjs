import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ARTIFACT_DIR = process.env.AKB_QA_ARTIFACT_DIR || path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../output/post-hygiene-qa');

const REQUIRED_CHECKS = [
  'home', 'brandsMarquee', 'liveSearch', 'pdp', 'seriesBundlesExpand',
  'seriesBundlesChip', 'stickyAtcSync', 'whatsAppClearance', 'cartDrawer', 'brandsDirectory'
];

export function smokePassed(results) {
  return REQUIRED_CHECKS.every(check => results[check] === true || (check === 'whatsAppClearance' && results.env === 'LOCAL' && results[check] === null))
    && Array.isArray(results.errors) && results.errors.length === 0;
}

export function stickySnapshotMatches(snapshot) {
  const normalize = value => String(value || '').replace(/\s+/g, ' ').trim();
  return snapshot.selected >= 2 && snapshot.packMode === true && snapshot.visible === true
    && normalize(snapshot.title).includes(`(${snapshot.selected} tomos)`)
    && normalize(snapshot.button).includes(`(${snapshot.selected})`)
    && normalize(snapshot.expectedPrice).length > 0
    && normalize(snapshot.price) === normalize(snapshot.expectedPrice);
}

export function whatsappClearanceObservation(rectangles) {
  if (!rectangles.whatsapp) return null;
  return rectanglesClear(rectangles.whatsapp, rectangles.sticky);
}

export function rectanglesClear(a, b) {
  return Boolean(a && b) && (a.right <= b.left || b.right <= a.left || a.bottom <= b.top || b.bottom <= a.top);
}

export async function runTestSuite(baseUrl, envLabel, sampleProductSlug) {
  console.log(`\n======================================================`);
  console.log(`🚀 INICIANDO AUDITORÍA INTEGRAL DE VERIFICACIÓN: ${envLabel}`);
  console.log(`   URL Base: ${baseUrl}`);
  console.log(`======================================================`);

  fs.mkdirSync(ARTIFACT_DIR, { recursive: true });
  const { default: playwright } = await import('./akibara-releases/rewards-qa/node_modules/playwright/index.js');
  const browser = await playwright.chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const results = {
    env: envLabel,
    home: false,
    brandsMarquee: false,
    liveSearch: false,
    pdp: false,
    seriesBundlesExpand: false,
    seriesBundlesChip: false,
    stickyAtcSync: false,
    whatsAppClearance: false,
    cartDrawer: false,
    brandsDirectory: false,
    errors: []
  };

  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 AkibaraQA'
  });

  const page = await context.newPage();

  page.on('console', msg => {
    if (msg.type() === 'error') {
      const text = msg.text();
      results.errors.push(`[Console Error]: ${text}`);
    }
  });

  page.on('pageerror', err => {
    results.errors.push(`[Page Error]: ${err.message}`);
  });

  try {
    // 1. PORTADA Y CARRUSEL DE MARCAS
    console.log(`\n[1/5] Auditando Portada y Carrusel de Marcas en ${envLabel}...`);
    const homeResponse = await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    if (!homeResponse?.ok()) throw new Error(`Portada HTTP ${homeResponse?.status()}`);
    await page.waitForSelector('.site-header, .akb-hero', { state: 'visible' });

    const heroVisible = await page.isVisible('.site-header, .akb-hero');
    const marqueeVisible = await page.isVisible('.akb-brands-home');
    const marqueeItemsCount = await page.locator('.akb-brands-home__item, .akb-brands-home__link').count();

    console.log(`   - Encabezado/Hero visible: ${heroVisible}`);
    console.log(`   - Carrusel de Marcas visible: ${marqueeVisible} (${marqueeItemsCount} marcas encontradas)`);

    const pauseBtn = page.locator('.akb-brands-home__toggle, .akb-brands-marquee-toggle');
    if (await pauseBtn.count() > 0) {
      console.log(`   - Botón de accesibilidad Pausa/Reanudar presente y operativo.`);
    }
    results.brandsMarquee = marqueeVisible && marqueeItemsCount > 0;
    results.home = heroVisible;
    await page.screenshot({ path: path.join(ARTIFACT_DIR, `${envLabel}-01-home.png`) });

    // 2. BUSCADOR EN VIVO (LIVE SEARCH / SUGGEST)
    console.log(`\n[2/5] Auditando Buscador en Vivo (Live Search / Autocomplete) en ${envLabel}...`);
    const searchInput = page.locator('#akb-search-input, input[name="s"], .akb-search-input').first();
    if (await searchInput.count() > 0) {
      await searchInput.scrollIntoViewIfNeeded();
      await searchInput.click();
      await searchInput.fill('Dan Da Dan');
      await page.waitForSelector('.akb-suggest-panel .akb-suggest-item', { state: 'visible' });

      const panelVisible = await page.isVisible('.akb-suggest-panel');
      const suggestItems = await page.locator('.akb-suggest-item').count();
      const suggestSubheads = await page.locator('.akb-suggest-subhead').count();

      console.log(`   - Panel de sugerencias abierto: ${panelVisible}`);
      console.log(`   - Resultados sugeridos: ${suggestItems}`);
      console.log(`   - Subencabezados de disponibilidad: ${suggestSubheads}`);

      if (panelVisible && suggestItems > 0) {
        results.liveSearch = true;
      }
      await page.screenshot({ path: path.join(ARTIFACT_DIR, `${envLabel}-02-live-search.png`) });
    }

    // 3. FICHA DE PRODUCTO (PDP), ARMADOR DE PACKS Y STICKY ATC
    console.log(`\n[3/5] Auditando Ficha de Producto y Armador de Packs en ${envLabel}...`);
    const pdpUrl = `${baseUrl}/${sampleProductSlug}/`;
    const pdpResponse = await page.goto(pdpUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    if (!pdpResponse?.ok()) throw new Error(`PDP HTTP ${pdpResponse?.status()}`);
    await page.waitForSelector('h1.product_title, h1.entry-title', { state: 'visible' });

    const pdpTitle = await page.textContent('h1.product_title, h1.entry-title').catch(() => '');
    console.log(`   - Título PDP: ${pdpTitle.trim()}`);
    results.pdp = !!pdpTitle;

    // Verificar widget del armador de packs (.akb-sb-builder)
    const sbBuilder = page.locator('.akb-sb-builder');
    const hasBuilder = await sbBuilder.count() > 0;
    console.log(`   - Armador de Packs (.akb-sb-builder) presente: ${hasBuilder}`);

    if (hasBuilder) {
      // 3.1 Probar expansión controlada de cuadrícula a 2 filas
      const expandBtn = page.locator('button.akb-sb-builder__expand-btn, button[data-akb-sb-expand-btn]');
      const gridWrap = page.locator('.akb-sb-builder__grid-wrap');

      if (await expandBtn.count() > 0) {
        const isCollapsedInitially = !(await gridWrap.evaluate(el => el.classList.contains('is-expanded')));
        console.log(`   - Estado inicial colapsado a 2 filas: ${isCollapsedInitially}`);

        await expandBtn.scrollIntoViewIfNeeded();
        await expandBtn.click();
        await page.waitForFunction(() => document.querySelector('.akb-sb-builder__grid-wrap')?.classList.contains('is-expanded'));

        const isExpanded = await gridWrap.evaluate(el => el.classList.contains('is-expanded'));
        const ariaExp = (await expandBtn.getAttribute('aria-expanded')) === 'true';
        console.log(`   - Expansión tras clic [ ▾ Ver los X tomos ]: ${isExpanded} (aria-expanded: ${ariaExp})`);
        results.seriesBundlesExpand = isCollapsedInitially && isExpanded && ariaExp;

        // Colapsar nuevamente
        await expandBtn.click();
        await page.waitForFunction(() => !document.querySelector('.akb-sb-builder__grid-wrap')?.classList.contains('is-expanded'));
        results.seriesBundlesExpand &&= !(await gridWrap.evaluate(el => el.classList.contains('is-expanded')))
          && (await expandBtn.getAttribute('aria-expanded')) === 'false';
      }

      // 3.2 Probar chip de selección rápida
      const stockChip = page.locator('button[data-akb-sb-shortcut="stock"]');
      if (await stockChip.count() > 0) {
        await stockChip.scrollIntoViewIfNeeded();
        await stockChip.click();
        await page.waitForFunction(() => document.querySelectorAll('[data-akb-sb-builder] [data-akb-sb-vol]:checked').length >= 2);

        const selectedCount = await page.locator('.akb-sb-builder__card.is-selected').count();
        console.log(`   - Clic en [+ Seleccionar en stock] -> Tomos marcados: ${selectedCount}`);
        results.seriesBundlesChip = selectedCount > 0;
      }

      // 3.3 Verificar barra inferior Sticky ATC sincronizada
      const stickyAtc = page.locator('#akb-sticky-atc');
      if (await stickyAtc.count() > 0) {
        // Scroll hacia abajo para activar la barra sticky
        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
        await page.waitForSelector('#akb-sticky-atc.is-visible', { state: 'visible' });

        await page.waitForFunction(() => document.querySelector('#akb-sticky-atc')?.classList.contains('is-pack-mode'), null, { timeout: 5000 });
        const snapshot = await page.evaluate(() => ({
          selected: document.querySelectorAll('[data-akb-sb-builder] [data-akb-sb-vol]:checked').length,
          packMode: document.querySelector('#akb-sticky-atc')?.classList.contains('is-pack-mode'),
          visible: document.querySelector('#akb-sticky-atc')?.classList.contains('is-visible'),
          title: document.querySelector('.akb-sticky-atc__title')?.textContent,
          button: document.querySelector('#akb-sticky-atc-btn')?.textContent,
          price: document.querySelector('.akb-sticky-atc__price')?.textContent,
          expectedPrice: document.querySelector('[data-akb-sb-total]')?.getAttribute('data-akb-sb-total-label')
        }));
        results.stickyAtcSync = stickySnapshotMatches(snapshot);
        console.log(`   - Sticky: selección, título, CTA y precio coinciden: ${results.stickyAtcSync}`);
      }

      // 3.4 Verificar WhatsApp clearance
      const joinchat = page.locator('#joinchat, .joinchat');
      if (await joinchat.count() > 0) {
        if (envLabel === 'PRODUCCION') {
          await page.waitForFunction(() => {
            const widget = document.querySelector('#joinchat, .joinchat');
            const style = widget && getComputedStyle(widget);
            const box = widget?.getBoundingClientRect();
            return Boolean(box?.width && box?.height && style?.display !== 'none' && style?.visibility !== 'hidden' && Number(style?.opacity) > 0);
          }, null, { timeout: 15000 }).catch(() => {});
        }
        const rectangles = await page.evaluate(() => {
          const box = selector => {
            const element = document.querySelector(selector);
            if (!element || !element.getClientRects().length) return null;
            const style = getComputedStyle(element);
            const { top, right, bottom, left, width, height } = element.getBoundingClientRect();
            if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0 || width <= 0 || height <= 0) return null;
            return { top, right, bottom, left };
          };
          return { whatsapp: box('#joinchat, .joinchat'), sticky: box('#akb-sticky-atc') };
        });
        results.whatsAppClearance = whatsappClearanceObservation(rectangles);
        console.log(results.whatsAppClearance === null ? '   - WhatsApp no visible; geometría no ejercitada.' : `   - WhatsApp y barra sticky sin solape: ${results.whatsAppClearance}`);
      } else {
        results.whatsAppClearance = null;
        console.log('   - WhatsApp ausente; geometría no ejercitada en este entorno.');
      }

      await page.screenshot({ path: path.join(ARTIFACT_DIR, `${envLabel}-03-pdp-pack.png`) });
    }

    // 4. CAJÓN DE CARRITO (NATIVECART) Y BARRA DE ENVÍO GRATIS (FSP)
    console.log(`\n[4/5] Auditando Cajón de Carrito (NativeCart) y Barra de Envío Gratis en ${envLabel}...`);
    // Abrir drawer llamando a window.akbCart.open()
    await page.evaluate(() => {
      if (window.akbCart && typeof window.akbCart.open === 'function') {
        window.akbCart.open();
      } else {
        const trigger = document.querySelector('.akb-cart-trigger, a[href*="cart"]');
        if (trigger) trigger.click();
      }
    });
    await page.waitForSelector('.akb-cart-drawer[aria-hidden="false"]', { state: 'visible' });

    const drawerVisible = await page.isVisible('.akb-cart-drawer[aria-hidden="false"]');
    console.log(`   - Cajón de carrito abierto: ${drawerVisible}`);

    const fspBar = page.locator('.akb-fsp-wrap');
    const fspCount = await fspBar.count();
    console.log(`   - Barra de progreso de envío gratis (.akb-fsp-wrap): ${fspCount > 0 ? 'Presente; comportamiento no ejercitado' : 'No presente en estado actual'}`);
    
    if (fspCount > 0) {
      const fspText = await fspBar.first().textContent();
      console.log(`   - Mensaje de envío gratis: ${fspText.replace(/\s+/g, ' ').trim().slice(0, 70)}...`);
    }
    results.cartDrawer = drawerVisible;
    await page.screenshot({ path: path.join(ARTIFACT_DIR, `${envLabel}-04-cart-drawer.png`) });

    // Cerrar drawer
    await page.evaluate(() => {
      if (window.akbCart && typeof window.akbCart.close === 'function') {
        window.akbCart.close();
      }
    });
    await page.waitForFunction(() => document.querySelector('.akb-cart-drawer')?.getAttribute('aria-hidden') === 'true');

    // 5. DIRECTORIO DE MARCAS / EDITORIALES
    console.log(`\n[5/5] Auditando Directorio de Editoriales (/editoriales/) en ${envLabel}...`);
    const brandsResponse = await page.goto(`${baseUrl}/editoriales/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    if (!brandsResponse?.ok()) throw new Error(`Editoriales HTTP ${brandsResponse?.status()}`);
    await page.waitForSelector('.akb-brand-card', { state: 'visible' });

    const brandsCount = await page.locator('.akb-brand-card').count();
    const logosCount = await page.locator('.akb-brand-card__logo img, .akb-brand-card__logo svg').count();

    console.log(`   - Tarjetas de editorial encontradas: ${brandsCount}`);
    console.log(`   - Logos SVG/IMG renderizados: ${logosCount}`);

    results.brandsDirectory = brandsCount > 0 && logosCount > 0;
    await page.screenshot({ path: path.join(ARTIFACT_DIR, `${envLabel}-05-editoriales.png`) });

  } catch (err) {
    console.error(`   ❌ Error durante el flujo de auditoría:`, err.message);
    results.errors.push(err.message);
  } finally {
    await browser.close();
  }

  console.log(`\n--- RESUMEN DE PRUEBAS EN ${envLabel} ---`);
  console.log(`Portada:                    ${results.home ? '✅ OK' : '❌ FAIL'}`);
  console.log(`Carrusel de Marcas:         ${results.brandsMarquee ? '✅ OK' : '❌ FAIL'}`);
  console.log(`Búsqueda en Vivo:           ${results.liveSearch ? '✅ OK' : '❌ FAIL'}`);
  console.log(`Ficha de Producto (PDP):    ${results.pdp ? '✅ OK' : '❌ FAIL'}`);
  console.log(`Expansión de Cuadrícula:    ${results.seriesBundlesExpand ? '✅ OK' : '❌ FAIL'}`);
  console.log(`Chips de Selección:         ${results.seriesBundlesChip ? '✅ OK' : '❌ FAIL'}`);
  console.log(`Sincronización Sticky ATC:  ${results.stickyAtcSync ? '✅ OK' : '❌ FAIL'}`);
  console.log(`Clearance WhatsApp:         ${results.whatsAppClearance === null ? 'NO EJERCITADO (no visible)' : results.whatsAppClearance ? '✅ OK' : '❌ FAIL'}`);
  console.log(`Cajón Carrito (FSP no certificado):  ${results.cartDrawer ? '✅ OK' : '❌ FAIL'}`);
  console.log(`Directorio de Editoriales:  ${results.brandsDirectory ? '✅ OK' : '❌ FAIL'}`);
  console.log(`Errores de Página/Consola:  ${results.errors.length === 0 ? '✅ 0 ERRORES' : '⚠️ ' + results.errors.length + ' errores'}`);

  return results;
}

export async function main(args = process.argv.slice(2)) {
  const environment = args.find(arg => arg.startsWith('--env='))?.slice(6) || 'local';
  if (!['local', 'production', 'both'].includes(environment) || args.some(arg => !arg.startsWith('--env='))) {
    throw new Error('Uso: node tools/qa-post-hygiene-complete-verification.mjs [--env=local|production|both]');
  }
  console.log('Iniciando comprobaciones smoke Playwright Post-Limpieza...');
  
  const measurements = [];
  if (environment === 'local' || environment === 'both') {
    measurements.push(await runTestSuite('http://localhost:8080', 'LOCAL', 'dan-da-dan-23-ivrea-argentina'));
  }
  if (environment === 'production' || environment === 'both') {
    measurements.push(await runTestSuite('https://akibara.cl', 'PRODUCCION', 'dan-da-dan-23-ivrea-argentina'));
  }
  console.log('\n======================================================');
  console.log('🏁 RESULTADOS CONSOLIDADOS (LOCAL VS PRODUCCIÓN)');
  console.log('======================================================');
  console.table(measurements.map(result => ({ env: result.env, ...Object.fromEntries(REQUIRED_CHECKS.map(check => [check, result[check]])), errors: result.errors.length })));
  fs.mkdirSync(ARTIFACT_DIR, { recursive: true });
  fs.writeFileSync(path.join(ARTIFACT_DIR, 'results.json'), JSON.stringify(measurements, null, 2));
  const allPassed = measurements.length > 0 && measurements.every(smokePassed);
  console.log(allPassed
    ? 'PASS: comprobaciones smoke ejercitadas. Este resultado no certifica todos los flujos de la tienda.'
    : 'FAIL: hay comprobaciones fallidas, no ejercitadas o errores de página/consola.');
  return allPassed ? 0 : 1;
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  main().then(code => { process.exitCode = code; }).catch(error => {
    console.error(error.message);
    process.exitCode = 1;
  });
}
