import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const AUDIT_OUT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/196cbaee-01a2-4cd1-9f5d-95164cd1bcf8/audit_report';
fs.mkdirSync(AUDIT_OUT_DIR, { recursive: true });

const TARGETS = [
  { name: 'local', baseUrl: 'http://localhost:8080' },
  { name: 'prod', baseUrl: 'https://akibara.cl' }
];

const VIEWPORTS = [
  { name: 'mobile_se', width: 375, height: 667, isMobile: true },
  { name: 'mobile_iphone14', width: 390, height: 844, isMobile: true },
  { name: 'tablet_ipad_air', width: 820, height: 1180, isMobile: false },
  { name: 'desktop_1280', width: 1280, height: 800, isMobile: false },
  { name: 'desktop_1440', width: 1440, height: 900, isMobile: false }
];

const PAGES_CONFIG = [
  { id: 'home', path: '/' },
  { id: 'tienda', path: '/tienda/' },
  { id: 'pdp', path: '/rurouni-kenshin-edicion-kanzenban-10-ivrea-argentina/', prodPath: '/one-piece-110-ivrea-argentina/' },
  { id: 'series', path: '/series/' },
  { id: 'cart', path: '/carrito/' },
  { id: 'checkout', path: '/finalizar-compra/' },
  { id: 'account', path: '/mi-cuenta/' },
  { id: 'account_orders', path: '/mi-cuenta/orders/' },
  { id: 'account_points', path: '/mi-cuenta/puntos-akibara/' },
  { id: 'not_found_404', path: '/pagina-no-existente-404-audit/' },
  { id: 'search_empty', path: '/?s=xyznonexistenttermquery12345' }
];

async function runAudit() {
  console.log('=== STARTING EXHAUSTIVE DISTRITO MANGA & CÓMICS AUDIT ===');
  const browser = await chromium.launch({ headless: true });
  const auditResults = {
    timestamp: new Date().toISOString(),
    environments: {},
    summary: {
      totalChecks: 0,
      passedChecks: 0,
      failedChecks: 0,
      issues: []
    }
  };

  for (const target of TARGETS) {
    console.log(`\n==================================================`);
    console.log(`AUDITING ENVIRONMENT: ${target.name.toUpperCase()} (${target.baseUrl})`);
    console.log(`==================================================`);
    auditResults.environments[target.name] = {
      baseUrl: target.baseUrl,
      pages: {},
      kinetics: {},
      yellowFocusSweep: [],
      typographySweep: []
    };

    // Test a standard desktop context first for typography and kinetics
    const deskContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const deskPage = await deskContext.newPage();

    // 1. KINETIC BUTTON TEST ON TIENDA
    console.log(`[${target.name}] Testing Kinetic Button Physics on Tienda...`);
    try {
      await deskPage.goto(`${target.baseUrl}/tienda/`, { waitUntil: 'domcontentloaded', timeout: 20000 });
      await deskPage.waitForTimeout(2000);

      const btn = deskPage.locator('ul.products li.product .button, ul.products li.product a.add_to_cart_button, .button').first();
      if (await btn.count() > 0) {
        const btnBox = await btn.boundingBox();
        const restingStyles = await btn.evaluate(el => {
          const cs = window.getComputedStyle(el);
          return {
            fontFamily: cs.fontFamily,
            fontSize: cs.fontSize,
            fontWeight: cs.fontWeight,
            textTransform: cs.textTransform,
            backgroundColor: cs.backgroundColor,
            color: cs.color,
            border: cs.border,
            borderWidth: cs.borderWidth,
            borderStyle: cs.borderStyle,
            borderColor: cs.borderColor,
            boxShadow: cs.boxShadow,
            transform: cs.transform
          };
        });

        await btn.hover();
        await deskPage.waitForTimeout(200);
        const hoverStyles = await btn.evaluate(el => {
          const cs = window.getComputedStyle(el);
          return {
            boxShadow: cs.boxShadow,
            transform: cs.transform
          };
        });

        if (btnBox) {
          await deskPage.mouse.move(btnBox.x + btnBox.width / 2, btnBox.y + btnBox.height / 2);
          await deskPage.mouse.down();
          await deskPage.waitForTimeout(100);
        }
        const activeStyles = await btn.evaluate(el => {
          const cs = window.getComputedStyle(el);
          return {
            boxShadow: cs.boxShadow,
            transform: cs.transform
          };
        });
        await deskPage.mouse.up();

        auditResults.environments[target.name].kinetics = {
          resting: restingStyles,
          hover: hoverStyles,
          active: activeStyles
        };
        console.log(`[${target.name}] Kinetic resting:`, restingStyles.boxShadow, 'border:', restingStyles.border);
        console.log(`[${target.name}] Kinetic hover:`, hoverStyles.boxShadow, 'transform:', hoverStyles.transform);
        console.log(`[${target.name}] Kinetic active:`, activeStyles.boxShadow, 'transform:', activeStyles.transform);
      }
    } catch (err) {
      console.error(`[${target.name}] Error checking kinetics:`, err.message);
    }

    // 2. SEARCH FOCUS RING & YELLOW CHECK
    console.log(`[${target.name}] Testing Focus Rings (Yellow check) on Header Search...`);
    try {
      const searchInput = deskPage.locator('.akibara-search__input, input[type="search"]').first();
      if (await searchInput.count() > 0) {
        await searchInput.focus();
        await deskPage.waitForTimeout(200);
        const searchFocus = await searchInput.evaluate(el => {
          const cs = window.getComputedStyle(el);
          return {
            outline: cs.outline,
            outlineColor: cs.outlineColor,
            boxShadow: cs.boxShadow,
            borderColor: cs.borderColor
          };
        });
        const hasYellow = JSON.stringify(searchFocus).includes('255, 215') || 
                          JSON.stringify(searchFocus).includes('250, 204') || 
                          JSON.stringify(searchFocus).includes('234, 179') ||
                          JSON.stringify(searchFocus).includes('ffd700');
        auditResults.environments[target.name].searchFocus = {
          styles: searchFocus,
          hasYellow
        };
        console.log(`[${target.name}] Search focus styles:`, searchFocus, 'Yellow found:', hasYellow);
      }
    } catch (err) {
      console.error(`[${target.name}] Error checking search focus:`, err.message);
    }

    await deskPage.close();
    await deskContext.close();

    // 3. VIEWPORT & PAGE SWEEPS
    for (const pageConfig of PAGES_CONFIG) {
      const pageUrlPath = (target.name === 'prod' && pageConfig.prodPath) ? pageConfig.prodPath : pageConfig.path;
      const fullUrl = `${target.baseUrl}${pageUrlPath}`;
      console.log(`\n--- [${target.name}] Inspecting Page: ${pageConfig.id} (${fullUrl}) ---`);

      auditResults.environments[target.name].pages[pageConfig.id] = {
        url: fullUrl,
        viewports: {}
      };

      for (const vp of VIEWPORTS) {
        const vpContext = await browser.newContext({
          viewport: { width: vp.width, height: vp.height },
          isMobile: vp.isMobile,
          hasTouch: vp.isMobile
        });
        const page = await vpContext.newPage();

        try {
          const res = await page.goto(fullUrl, { waitUntil: 'domcontentloaded', timeout: 25000 });
          await page.waitForTimeout(1500);

          const status = res ? res.status() : null;
          const title = await page.title();

          // Detailed DOM inspection:
          const domAnalysis = await page.evaluate(() => {
            const docWidth = document.documentElement.clientWidth;
            const scrollWidth = document.documentElement.scrollWidth;
            const horizontalOverflow = scrollWidth > docWidth + 2;

            // 1. Heading Typography & Bebas Neue usage
            const headings = Array.from(document.querySelectorAll('h1, h2, h3, .section-title, .product_title, .entry-title, .cart-title, .wc-block-checkout__title, .akibara-section-heading, .woocommerce-MyAccount-navigation-link a')).map(el => {
              const cs = window.getComputedStyle(el);
              return {
                tag: el.tagName,
                class: el.className ? el.className.toString().slice(0, 50) : '',
                text: el.innerText ? el.innerText.trim().slice(0, 40) : '',
                fontFamily: cs.fontFamily,
                textTransform: cs.textTransform,
                fontWeight: cs.fontWeight,
                fontSize: cs.fontSize,
                color: cs.color
              };
            });

            // 2. Yellow Check on non-gold tier / non-rating elements
            const yellowElements = [];
            const allElements = document.querySelectorAll('body *');
            const isYellowRgb = (str) => {
              if (!str) return false;
              const m = str.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
              if (m) {
                const r = Number(m[1]), g = Number(m[2]), b = Number(m[3]);
                // yellow: high red, high green, low blue
                return r > 210 && g > 170 && b < 60;
              }
              return false;
            };

            for (const el of allElements) {
              if (['SCRIPT', 'STYLE', 'SVG', 'PATH', 'NOSCRIPT'].includes(el.tagName)) continue;
              // Check if inside star rating or gold tier badge intentionally
              const isStarRating = el.closest('.star-rating, .rating, .akb-rating, .star, .woocommerce-product-rating');
              const isGoldTier = el.closest('.tier-gold, .badge-gold, .puntos-gold, .akb-tier--oro');
              if (isStarRating || isGoldTier) continue;

              const cs = window.getComputedStyle(el);
              const colorYellow = isYellowRgb(cs.color);
              const bgYellow = isYellowRgb(cs.backgroundColor);
              const borderYellow = isYellowRgb(cs.borderColor);
              const outlineYellow = isYellowRgb(cs.outlineColor);
              const shadowYellow = isYellowRgb(cs.boxShadow);

              if (colorYellow || bgYellow || borderYellow || outlineYellow || shadowYellow) {
                yellowElements.push({
                  tag: el.tagName,
                  class: el.className ? el.className.toString().slice(0, 50) : '',
                  text: el.innerText ? el.innerText.trim().slice(0, 30) : '',
                  color: cs.color,
                  bg: cs.backgroundColor,
                  border: cs.borderColor,
                  outline: cs.outlineColor,
                  shadow: cs.boxShadow
                });
              }
            }

            // 3. Border & Manga Viñeta Styling
            const cards = Array.from(document.querySelectorAll('.product, .entry-content, .woocommerce-card, .widget, .akibara-card, .drawer, .site-header, .site-footer')).slice(0, 5).map(el => {
              const cs = window.getComputedStyle(el);
              return {
                tag: el.tagName,
                class: el.className ? el.className.toString().slice(0, 50) : '',
                border: cs.border,
                boxShadow: cs.boxShadow
              };
            });

            // 4. Mobile elements check
            const mobileBottomNav = !!document.querySelector('.bottom-nav, .akb-bottom-nav, .mobile-nav, .akibara-mobile-nav');
            const stickyAtc = !!document.querySelector('.akb-sticky-atc, .sticky-atc, .single_add_to_cart_button');
            const headerSearch = !!document.querySelector('.akibara-search, .search-modal, .search-drawer');

            return {
              docWidth,
              scrollWidth,
              horizontalOverflow,
              headingsCount: headings.length,
              headingsSample: headings.slice(0, 8),
              yellowFound: yellowElements.length,
              yellowSample: yellowElements.slice(0, 5),
              cardsSample: cards,
              mobileFeatures: {
                hasMobileBottomNav: mobileBottomNav,
                hasStickyAtc: stickyAtc,
                hasHeaderSearch: headerSearch
              }
            };
          });

          // Screenshot for key viewports
          const screenshotName = `${target.name}_${pageConfig.id}_${vp.name}.png`;
          const screenshotPath = path.join(AUDIT_OUT_DIR, screenshotName);
          await page.screenshot({ path: screenshotPath, fullPage: false });

          auditResults.environments[target.name].pages[pageConfig.id].viewports[vp.name] = {
            status,
            title,
            screenshot: screenshotName,
            domAnalysis
          };

          if (domAnalysis.horizontalOverflow) {
            console.log(`[!] OVERFLOW DETECTED: ${pageConfig.id} on ${vp.name} (${domAnalysis.scrollWidth}px > ${domAnalysis.docWidth}px)`);
            auditResults.summary.issues.push({
              env: target.name,
              page: pageConfig.id,
              viewport: vp.name,
              type: 'horizontal_overflow',
              detail: `scrollWidth ${domAnalysis.scrollWidth}px > docWidth ${domAnalysis.docWidth}px`
            });
          }

          if (domAnalysis.yellowFound > 0) {
            console.log(`[!] YELLOW RESIDUE DETECTED: ${pageConfig.id} on ${vp.name} (${domAnalysis.yellowFound} elements)`);
            auditResults.summary.issues.push({
              env: target.name,
              page: pageConfig.id,
              viewport: vp.name,
              type: 'unwanted_yellow',
              detail: domAnalysis.yellowSample
            });
          }

        } catch (err) {
          console.error(`Error auditing ${pageConfig.id} on ${vp.name}:`, err.message);
          auditResults.environments[target.name].pages[pageConfig.id].viewports[vp.name] = {
            error: err.message
          };
        } finally {
          await page.close();
          await vpContext.close();
        }
      }
    }
  }

  // 4. CART DRAWER & SLIDE-OVER INTERACTION AUDIT
  console.log(`\n=== AUDITING CART DRAWER INTERACTION ON DESKTOP & MOBILE ===`);
  for (const target of TARGETS) {
    try {
      const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
      await page.goto(`${target.baseUrl}/tienda/`, { waitUntil: 'domcontentloaded', timeout: 20000 });
      await page.waitForTimeout(2000);

      // Try triggering cart drawer / opening cart
      const cartIcon = page.locator('.header-cart, .bottom-nav__item--cart, .cart-contents, a[href*="carrito"]').first();
      if (await cartIcon.count() > 0) {
        await cartIcon.click();
        await page.waitForTimeout(1000);
        const drawerOpen = await page.evaluate(() => {
          const drawer = document.querySelector('.akb-cart-drawer, .cart-drawer, .mini-cart-drawer, .woocommerce-mini-cart');
          if (!drawer) return { exists: false };
          const cs = window.getComputedStyle(drawer);
          return {
            exists: true,
            display: cs.display,
            visibility: cs.visibility,
            opacity: cs.opacity,
            transform: cs.transform,
            border: cs.border,
            boxShadow: cs.boxShadow,
            headerFont: window.getComputedStyle(drawer.querySelector('h2, h3, .drawer-title') || drawer).fontFamily
          };
        });
        console.log(`[${target.name}] Cart drawer state:`, drawerOpen);
        await page.screenshot({ path: path.join(AUDIT_OUT_DIR, `${target.name}_cart_drawer_interaction.png`) });
      }
      await page.close();
    } catch (e) {
      console.log(`[${target.name}] Cart drawer test note:`, e.message);
    }
  }

  await browser.close();

  // Save JSON report
  fs.writeFileSync(path.join(AUDIT_OUT_DIR, 'comprehensive_audit_results.json'), JSON.stringify(auditResults, null, 2));
  console.log(`\n=== AUDIT FINISHED. Results written to ${AUDIT_OUT_DIR}/comprehensive_audit_results.json ===`);
}

runAudit().catch(err => {
  console.error('Fatal audit failure:', err);
  process.exit(1);
});
