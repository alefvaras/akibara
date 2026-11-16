import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const BASE_URL = process.env.AKB_AUDIT_URL || 'http://localhost:8080';
const OUT_DIR = process.env.AKB_AUDIT_OUT_DIR || '/Users/alefvaras/.gemini/antigravity/brain/988426a1-f93d-4b93-b671-33dc191df82d/audit-5-iterations';

fs.mkdirSync(OUT_DIR, { recursive: true });

const VIEWPORTS = {
  desktop: { width: 1280, height: 900 },
  tablet: { width: 768, height: 1024 },
  mobile: { width: 390, height: 844 }
};

const results = {
  iterations: [],
  issuesFound: [],
  metrics: {
    totalScreenshots: 0,
    yellowPricesFound: 0,
    rotatedStampsFound: 0,
    overflowsFound: 0
  }
};

async function inspectPage(page, contextName, viewportName) {
  return await page.evaluate(({ contextName, viewportName }) => {
    const docWidth = document.documentElement.clientWidth;
    const scrollWidth = document.documentElement.scrollWidth;
    const hasHorizontalOverflow = scrollWidth > docWidth + 1;

    // Check for yellow colors in prices
    const priceElements = Array.from(document.querySelectorAll('.price, .price *, .akb-sticky-atc__price, .akb-sticky-atc__price *, .cart-subtotal .amount, .order-total .amount, .akb-cart-item-price, .akb-cs-price'));
    const yellowElements = [];
    for (const el of priceElements) {
      if (!el.offsetParent && el.tagName !== 'INS' && el.tagName !== 'DEL') continue;
      const color = window.getComputedStyle(el).color;
      // Yellow colors: rgb(250, 204, 21) = #facc15, rgb(255, 215, 0) = #ffd700, rgb(234, 179, 8) = #eab308
      if (color.includes('250, 204') || color.includes('255, 215') || color.includes('234, 179')) {
        yellowElements.push({
          tag: el.tagName,
          class: el.className,
          text: el.innerText.trim(),
          color: color
        });
      }
    }

    // Check for rotated red stamps in ins
    const insElements = Array.from(document.querySelectorAll('.price ins'));
    const stampElements = [];
    for (const el of insElements) {
      const style = window.getComputedStyle(el);
      const bg = style.backgroundColor;
      const transform = style.transform;
      if ((bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') || (transform !== 'none' && !transform.includes('matrix(1, 0, 0, 1'))) {
        stampElements.push({
          class: el.className,
          text: el.innerText.trim(),
          bg,
          transform
        });
      }
    }

    // Count volume badges
    const volCount = document.querySelectorAll('.akb-vol').length;

    return {
      hasHorizontalOverflow,
      scrollWidth,
      docWidth,
      yellowElements,
      stampElements,
      volCount
    };
  }, { contextName, viewportName });
}

(async () => {
  console.log(`Starting 5 Visual Audit Iterations against ${BASE_URL}...`);

  const browser = await chromium.launch({
    headless: true,
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
  });

  // ----------------------------------------------------
  // ITERATION 1: Catálogos & Loops (Home, Tienda)
  // ----------------------------------------------------
  console.log('\n--- ITERATION 1: Catálogos & Loops (Home, Tienda) ---');
  for (const [vpName, vp] of Object.entries(VIEWPORTS)) {
    const page = await browser.newPage({ viewport: vp });
    try {
      await page.goto(`${BASE_URL}/tienda/`, { waitUntil: 'domcontentloaded', timeout: 15000 });
      await page.waitForTimeout(1000);

      const inspection = await inspectPage(page, 'Catálogo Tienda', vpName);
      const file = `iter1_catalogo_${vpName}.png`;
      await page.screenshot({ path: path.join(OUT_DIR, file), fullPage: false });
      results.metrics.totalScreenshots++;

      if (inspection.yellowElements.length > 0) results.metrics.yellowPricesFound += inspection.yellowElements.length;
      if (inspection.stampElements.length > 0) results.metrics.rotatedStampsFound += inspection.stampElements.length;
      if (inspection.hasHorizontalOverflow) results.metrics.overflowsFound++;

      console.log(`[Iter 1] Catálogo ${vpName}: Overflow=${inspection.hasHorizontalOverflow}, YellowPrices=${inspection.yellowElements.length}, Stamps=${inspection.stampElements.length}, Vols=${inspection.volCount}`);
    } catch (err) {
      console.error(`Error in Iteration 1 ${vpName}:`, err.message);
    } finally {
      await page.close();
    }
  }

  // ----------------------------------------------------
  // ITERATION 2: Fichas de Producto (PDP) & Hover Cards
  // ----------------------------------------------------
  console.log('\n--- ITERATION 2: Fichas de Producto (PDP) & Hover Cards ---');
  // First, find a valid product URL
  const discoverPage = await browser.newPage();
  await discoverPage.goto(`${BASE_URL}/tienda/`, { waitUntil: 'domcontentloaded' });
  const productUrl = await discoverPage.evaluate(() => {
    const link = document.querySelector('.woocommerce-loop-product__link');
    return link ? link.href : null;
  });
  await discoverPage.close();

  console.log(`Using product PDP: ${productUrl}`);

  for (const [vpName, vp] of Object.entries(VIEWPORTS)) {
    const page = await browser.newPage({ viewport: vp });
    try {
      await page.goto(productUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
      await page.waitForTimeout(1000);

      const inspection = await inspectPage(page, 'PDP', vpName);
      const file = `iter2_pdp_${vpName}.png`;
      await page.screenshot({ path: path.join(OUT_DIR, file), fullPage: false });
      results.metrics.totalScreenshots++;

      if (inspection.yellowElements.length > 0) results.metrics.yellowPricesFound += inspection.yellowElements.length;
      if (inspection.hasHorizontalOverflow) results.metrics.overflowsFound++;

      console.log(`[Iter 2] PDP ${vpName}: Overflow=${inspection.hasHorizontalOverflow}, YellowPrices=${inspection.yellowElements.length}, Vols=${inspection.volCount}`);
    } catch (err) {
      console.error(`Error in Iteration 2 ${vpName}:`, err.message);
    } finally {
      await page.close();
    }
  }

  // Desktop Card Hover Capture
  const hoverPage = await browser.newPage({ viewport: VIEWPORTS.desktop });
  try {
    await hoverPage.goto(`${BASE_URL}/tienda/`, { waitUntil: 'domcontentloaded' });
    await hoverPage.waitForTimeout(800);
    const firstCard = await hoverPage.$('ul.products li.product');
    if (firstCard) {
      await firstCard.hover();
      await hoverPage.waitForTimeout(500);
      await firstCard.screenshot({ path: path.join(OUT_DIR, 'iter2_card_hover_desktop.png') });
      results.metrics.totalScreenshots++;
      console.log('[Iter 2] Card hover captured on desktop.');
    }
  } finally {
    await hoverPage.close();
  }

  // ----------------------------------------------------
  // ITERATION 3: Sticky Add to Cart (Mobile, Tablet, Desktop)
  // ----------------------------------------------------
  console.log('\n--- ITERATION 3: Sticky Add to Cart ---');
  for (const [vpName, vp] of Object.entries(VIEWPORTS)) {
    const page = await browser.newPage({ viewport: vp });
    try {
      await page.goto(productUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
      await page.waitForTimeout(800);
      // Scroll down 700px to trigger sticky atc
      await page.evaluate(() => window.scrollTo(0, 900));
      await page.waitForTimeout(600);

      const file = `iter3_sticky_atc_${vpName}.png`;
      await page.screenshot({ path: path.join(OUT_DIR, file), fullPage: false });
      results.metrics.totalScreenshots++;

      const inspection = await inspectPage(page, 'Sticky ATC', vpName);
      console.log(`[Iter 3] Sticky ATC ${vpName}: YellowPrices=${inspection.yellowElements.length}`);
    } catch (err) {
      console.error(`Error in Iteration 3 ${vpName}:`, err.message);
    } finally {
      await page.close();
    }
  }

  // ----------------------------------------------------
  // ITERATION 4: Quick View & Cart Drawer
  // ----------------------------------------------------
  console.log('\n--- ITERATION 4: Quick View & Cart Drawer ---');
  const drawerPage = await browser.newPage({ viewport: VIEWPORTS.mobile });
  try {
    await drawerPage.goto(productUrl, { waitUntil: 'domcontentloaded' });
    await drawerPage.waitForTimeout(800);

    // Click single add to cart
    const atcBtn = await drawerPage.$('.single_add_to_cart_button');
    if (atcBtn) {
      await atcBtn.click();
      await drawerPage.waitForTimeout(2000);
    }

    // Capture drawer open
    const fileDrawer = `iter4_drawer_mobile.png`;
    await drawerPage.screenshot({ path: path.join(OUT_DIR, fileDrawer), fullPage: false });
    results.metrics.totalScreenshots++;

    const inspection = await inspectPage(drawerPage, 'Cart Drawer', 'mobile');
    console.log(`[Iter 4] Cart Drawer Mobile: YellowPrices=${inspection.yellowElements.length}, Vols=${inspection.volCount}`);
  } catch (err) {
    console.error(`Error in Iteration 4:`, err.message);
  } finally {
    await drawerPage.close();
  }

  // ----------------------------------------------------
  // ITERATION 5: Carrito & Checkout & Mi Cuenta
  // ----------------------------------------------------
  console.log('\n--- ITERATION 5: Carrito, Checkout & Mi Cuenta ---');
  for (const pageName of ['carrito', 'checkout', 'mi-cuenta']) {
    for (const [vpName, vp] of [['mobile', VIEWPORTS.mobile], ['desktop', VIEWPORTS.desktop]]) {
      const page = await browser.newPage({ viewport: vp });
      try {
        await page.goto(`${BASE_URL}/${pageName}/`, { waitUntil: 'domcontentloaded', timeout: 15000 });
        await page.waitForTimeout(800);

        const inspection = await inspectPage(page, pageName, vpName);
        const file = `iter5_${pageName}_${vpName}.png`;
        await page.screenshot({ path: path.join(OUT_DIR, file), fullPage: false });
        results.metrics.totalScreenshots++;

        if (inspection.yellowElements.length > 0) results.metrics.yellowPricesFound += inspection.yellowElements.length;
        if (inspection.hasHorizontalOverflow) results.metrics.overflowsFound++;

        console.log(`[Iter 5] /${pageName}/ ${vpName}: Overflow=${inspection.hasHorizontalOverflow}, YellowPrices=${inspection.yellowElements.length}`);
      } catch (err) {
        console.error(`Error in Iteration 5 ${pageName} ${vpName}:`, err.message);
      } finally {
        await page.close();
      }
    }
  }

  fs.writeFileSync(path.join(OUT_DIR, 'audit-results.json'), JSON.stringify(results, null, 2));
  console.log('\nAll 5 iterations completed successfully!');
  console.log(`Summary: ${results.metrics.totalScreenshots} screenshots taken. Yellow prices: ${results.metrics.yellowPricesFound}. Rotated stamps: ${results.metrics.rotatedStampsFound}. Overflows: ${results.metrics.overflowsFound}.`);
  await browser.close();
})();
