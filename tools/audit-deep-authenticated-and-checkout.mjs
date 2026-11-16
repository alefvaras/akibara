import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const AUDIT_OUT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/196cbaee-01a2-4cd1-9f5d-95164cd1bcf8/audit_report';
fs.mkdirSync(AUDIT_OUT_DIR, { recursive: true });

async function runDeepAudit() {
  console.log('=== RUNNING AUTHENTICATED & POPULATED CHECKOUT DEEP AUDIT ===');
  const browser = await chromium.launch({ headless: true });

  // 1. Get auth cookie for local
  const authCookie = {
    name: "wordpress_logged_in_37d007a56d816107ce5b52c10342db37",
    value: "qa_e2e_puntos|1792339690|mAeePIf9ls0uZnRcFtBVasNkRtXtfFxOArnuUtyygey|5a854a5d4f46d763afe6262771384df2af2f86dd53955576c2cc5f09ee34d982",
    domain: "localhost",
    path: "/"
  };

  const results = {
    accountLoggedIn: {},
    checkoutPopulated: { local: {}, prod: {} },
    pdpDeep: { local: {}, prod: {} },
    seriesDeep: { local: {}, prod: {} }
  };

  // ─────────────────────────────────────────────────────────────
  // A. LOCAL LOGGED-IN ACCOUNT INSPECTION
  // ─────────────────────────────────────────────────────────────
  console.log('\n--- 1. AUDITING LOGGED-IN ACCOUNT ON LOCAL ---');
  const accContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await accContext.addCookies([authCookie]);
  const accPage = await accContext.newPage();

  const accountEndpoints = [
    { id: 'dashboard', url: 'http://localhost:8080/mi-cuenta/' },
    { id: 'orders', url: 'http://localhost:8080/mi-cuenta/orders/' },
    { id: 'points', url: 'http://localhost:8080/mi-cuenta/puntos-akibara/' }
  ];

  for (const ep of accountEndpoints) {
    await accPage.goto(ep.url, { waitUntil: 'networkidle' });
    await accPage.waitForTimeout(1000);

    const epDetails = await accPage.evaluate(() => {
      const tabs = Array.from(document.querySelectorAll('.woocommerce-MyAccount-navigation-link a')).map(a => {
        const cs = window.getComputedStyle(a);
        return {
          text: a.innerText.trim(),
          fontFamily: cs.fontFamily,
          textTransform: cs.textTransform,
          fontSize: cs.fontSize,
          color: cs.color,
          bg: cs.backgroundColor,
          border: cs.border,
          isActive: a.parentElement.classList.contains('is-active')
        };
      });

      const headings = Array.from(document.querySelectorAll('h1, h2, h3, h4, .entry-title')).map(h => {
        const cs = window.getComputedStyle(h);
        return {
          text: h.innerText.trim(),
          tag: h.tagName,
          fontFamily: cs.fontFamily,
          textTransform: cs.textTransform,
          color: cs.color
        };
      });

      const tables = Array.from(document.querySelectorAll('table')).map(t => {
        const cs = window.getComputedStyle(t);
        const headers = Array.from(t.querySelectorAll('th')).map(th => {
          const sc = window.getComputedStyle(th);
          return { text: th.innerText.trim(), font: sc.fontFamily, uppercase: sc.textTransform };
        });
        return { border: cs.border, headers };
      });

      const yellowFound = [];
      document.querySelectorAll('*').forEach(el => {
        if (['SCRIPT', 'STYLE', 'SVG', 'PATH'].includes(el.tagName)) return;
        if (el.closest('.star-rating, .rating, .puntos-gold, .tier-gold')) return;
        const cs = window.getComputedStyle(el);
        const isY = s => s && (s.includes('255, 215') || s.includes('250, 204') || s.includes('234, 179') || s.includes('ffd700'));
        if (isY(cs.color) || isY(cs.backgroundColor) || isY(cs.borderColor) || isY(cs.outlineColor)) {
          yellowFound.push({ tag: el.tagName, class: el.className, text: el.innerText?.slice(0, 40), color: cs.color, bg: cs.backgroundColor, border: cs.borderColor });
        }
      });

      return { tabs, headings, tables, yellowFound };
    });

    await accPage.screenshot({ path: path.join(AUDIT_OUT_DIR, `local_account_loggedin_${ep.id}.png`), fullPage: true });
    results.accountLoggedIn[ep.id] = epDetails;
    console.log(`Account [${ep.id}] tabs count:`, epDetails.tabs.length, 'yellow elements:', epDetails.yellowFound.length);
  }
  await accPage.close();
  await accContext.close();

  // ─────────────────────────────────────────────────────────────
  // B. POPULATED CHECKOUT ON LOCAL & PROD
  // ─────────────────────────────────────────────────────────────
  for (const env of [{ name: 'local', url: 'http://localhost:8080' }, { name: 'prod', url: 'https://akibara.cl' }]) {
    console.log(`\n--- 2. AUDITING POPULATED CHECKOUT ON ${env.name.toUpperCase()} ---`);
    const checkContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const checkPage = await checkContext.newPage();

    // 1. Add item to cart
    console.log(`[${env.name}] Fetching in-stock product...`);
    let productUrl = null;
    try {
      const res = await checkPage.request.get(`${env.url}/wp-json/wc/store/v1/products?stock_status=instock&per_page=5`);
      const products = await res.json();
      if (products && products.length > 0) {
        productUrl = products[0].permalink;
      }
    } catch (e) {
      console.log('REST error, fallback to catalog');
    }

    if (!productUrl) {
      await checkPage.goto(`${env.url}/tienda/`, { waitUntil: 'domcontentloaded' });
      productUrl = await checkPage.evaluate(() => document.querySelector('ul.products li.product a')?.href);
    }

    console.log(`[${env.name}] Navigating to product:`, productUrl);
    await checkPage.goto(productUrl, { waitUntil: 'domcontentloaded' });
    await checkPage.waitForTimeout(1000);

    const atc = checkPage.locator('.single_add_to_cart_button, ul.products li.product .button').first();
    if (await atc.count() > 0) {
      await atc.click();
      await checkPage.waitForTimeout(2500);
    }

    // Now navigate to checkout
    console.log(`[${env.name}] Navigating to /checkout/ ...`);
    await checkPage.goto(`${env.url}/checkout/`, { waitUntil: 'domcontentloaded' });
    await checkPage.waitForTimeout(3000);

    // Test different viewports on populated checkout:
    const checkoutVps = [
      { name: 'desktop_1280', width: 1280, height: 800 },
      { name: 'tablet_820', width: 820, height: 1180 },
      { name: 'mobile_390', width: 390, height: 844 },
      { name: 'mobile_375', width: 375, height: 667 }
    ];

    for (const vp of checkoutVps) {
      await checkPage.setViewportSize({ width: vp.width, height: vp.height });
      await checkPage.waitForTimeout(1000);

      const inspection = await checkPage.evaluate(() => {
        const isBlockCheckout = !!document.querySelector('.wp-block-woocommerce-checkout');
        const isClassicCheckout = !!document.querySelector('form.woocommerce-checkout');

        // Step titles & headings
        const stepTitles = Array.from(document.querySelectorAll('.wc-block-checkout__title, .wc-block-components-title, h1, h2, h3, .step-title')).map(el => {
          const cs = window.getComputedStyle(el);
          return {
            text: el.innerText.trim(),
            tag: el.tagName,
            fontFamily: cs.fontFamily,
            textTransform: cs.textTransform
          };
        });

        // Place order button
        const poBtn = document.querySelector('.wc-block-components-checkout-place-order-button, #place_order');
        let poStyles = null;
        if (poBtn) {
          const cs = window.getComputedStyle(poBtn);
          poStyles = {
            text: poBtn.innerText.trim(),
            fontFamily: cs.fontFamily,
            textTransform: cs.textTransform,
            border: cs.border,
            boxShadow: cs.boxShadow,
            bg: cs.backgroundColor,
            color: cs.color
          };
        }

        // Terms and conditions link
        const tcLink = document.querySelector('.woocommerce-terms-and-conditions-link, a[href*="terminos"]');
        let tcStyles = null;
        if (tcLink) {
          const cs = window.getComputedStyle(tcLink);
          tcStyles = {
            text: tcLink.innerText.trim(),
            color: cs.color,
            isYellow: cs.color.includes('255, 215') || cs.color.includes('250, 204') || cs.color.includes('234, 179')
          };
        }

        // Guest hook
        const guestHook = document.querySelector('.akb-rw-guest-hook, .akb-rw-guest-hook__action');
        let ghStyles = null;
        if (guestHook) {
          const cs = window.getComputedStyle(guestHook);
          ghStyles = {
            text: guestHook.innerText.trim(),
            fontFamily: cs.fontFamily,
            color: cs.color,
            bg: cs.backgroundColor,
            border: cs.border
          };
        }

        // Yellow sweep on checkout
        const yellowElements = [];
        document.querySelectorAll('*').forEach(el => {
          if (['SCRIPT', 'STYLE', 'SVG', 'PATH'].includes(el.tagName)) return;
          const cs = window.getComputedStyle(el);
          const isY = s => s && (s.includes('255, 215') || s.includes('250, 204') || s.includes('234, 179') || s.includes('ffd700'));
          if (isY(cs.color) || isY(cs.backgroundColor) || isY(cs.borderColor) || isY(cs.outlineColor)) {
            yellowElements.push({ tag: el.tagName, class: el.className, text: el.innerText?.slice(0, 40), color: cs.color, bg: cs.backgroundColor, border: cs.borderColor });
          }
        });

        // Horizontal overflow
        const docWidth = document.documentElement.clientWidth;
        const scrollWidth = document.documentElement.scrollWidth;
        const horizontalOverflow = scrollWidth > docWidth + 2;

        return {
          isBlockCheckout,
          isClassicCheckout,
          stepTitles,
          poStyles,
          tcStyles,
          ghStyles,
          yellowElements,
          horizontalOverflow,
          docWidth,
          scrollWidth
        };
      });

      const shotPath = path.join(AUDIT_OUT_DIR, `${env.name}_checkout_populated_${vp.name}.png`);
      await checkPage.screenshot({ path: shotPath, fullPage: true });
      results.checkoutPopulated[env.name][vp.name] = inspection;

      console.log(`[${env.name} - ${vp.name}] Place Order font:`, inspection.poStyles?.fontFamily, 'T&C yellow:', inspection.tcStyles?.isYellow, 'Yellow elements:', inspection.yellowElements.length, 'Overflow:', inspection.horizontalOverflow);
    }

    await checkPage.close();
    await checkContext.close();
  }

  // ─────────────────────────────────────────────────────────────
  // C. PDP DEEP AUDIT (STICKY ATC, REVIEWS, SPEEDLINES)
  // ─────────────────────────────────────────────────────────────
  for (const env of [{ name: 'local', url: 'http://localhost:8080/rurouni-kenshin-edicion-kanzenban-10-ivrea-argentina/' }, { name: 'prod', url: 'https://akibara.cl/one-piece-110-ivrea-argentina/' }]) {
    console.log(`\n--- 3. AUDITING PDP ON ${env.name.toUpperCase()} ---`);
    const pdpContext = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
    const pdpPage = await pdpContext.newPage();
    await pdpPage.goto(env.url, { waitUntil: 'domcontentloaded' });
    await pdpPage.waitForTimeout(2000);

    // Scroll down to trigger sticky ATC
    await pdpPage.evaluate(() => window.scrollTo(0, 1200));
    await pdpPage.waitForTimeout(1000);

    const pdpMobileAnalysis = await pdpPage.evaluate(() => {
      const stickyAtc = document.querySelector('.akb-sticky-atc, .single-sticky-add-to-cart, .akb-sticky-bar');
      let stickyInfo = null;
      if (stickyAtc) {
        const cs = window.getComputedStyle(stickyAtc);
        const btn = stickyAtc.querySelector('button, .button, a');
        const btnCs = btn ? window.getComputedStyle(btn) : null;
        stickyInfo = {
          display: cs.display,
          visibility: cs.visibility,
          bottom: cs.bottom,
          border: cs.border,
          boxShadow: cs.boxShadow,
          btnFont: btnCs?.fontFamily,
          btnUppercase: btnCs?.textTransform,
          btnBg: btnCs?.backgroundColor
        };
      }

      const bottomNav = document.querySelector('.bottom-nav, .akb-bottom-nav');
      let navInfo = null;
      if (bottomNav) {
        const cs = window.getComputedStyle(bottomNav);
        navInfo = {
          display: cs.display,
          height: cs.height,
          zIndex: cs.zIndex
        };
      }

      return { stickyInfo, navInfo };
    });

    console.log(`[${env.name}] PDP sticky ATC info:`, pdpMobileAnalysis.stickyInfo);
    console.log(`[${env.name}] PDP bottom nav info:`, pdpMobileAnalysis.navInfo);

    await pdpPage.screenshot({ path: path.join(AUDIT_OUT_DIR, `${env.name}_pdp_mobile_sticky.png`) });
    results.pdpDeep[env.name] = pdpMobileAnalysis;

    await pdpPage.close();
    await pdpContext.close();
  }

  // ─────────────────────────────────────────────────────────────
  // D. SERIES / PACK BUILDER DEEP AUDIT
  // ─────────────────────────────────────────────────────────────
  for (const env of [{ name: 'local', url: 'http://localhost:8080/series/' }, { name: 'prod', url: 'https://akibara.cl/series/' }]) {
    console.log(`\n--- 4. AUDITING SERIES DIRECTORY ON ${env.name.toUpperCase()} ---`);
    const sPage = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await sPage.goto(env.url, { waitUntil: 'domcontentloaded' });
    await sPage.waitForTimeout(2000);

    const seriesAnalysis = await sPage.evaluate(() => {
      const alphabet = Array.from(document.querySelectorAll('.akb-series-directory__letter')).map(l => {
        const cs = window.getComputedStyle(l);
        return {
          char: l.innerText.trim(),
          font: cs.fontFamily,
          color: cs.color,
          bg: cs.backgroundColor
        };
      });

      const chips = Array.from(document.querySelectorAll('.akb-series-directory__chip')).map(c => {
        const cs = window.getComputedStyle(c);
        return {
          text: c.innerText.trim(),
          isActive: c.classList.contains('is-active'),
          color: cs.color,
          bg: cs.backgroundColor,
          border: cs.borderColor
        };
      });

      const badges = Array.from(document.querySelectorAll('.akb-series-directory__estado')).map(b => {
        const cs = window.getComputedStyle(b);
        return {
          text: b.innerText.trim(),
          className: b.className,
          color: cs.color,
          border: cs.borderColor
        };
      });

      return { alphabetCount: alphabet.length, chipsCount: chips.length, badgesCount: badges.length, sampleBadges: badges.slice(0, 5) };
    });

    console.log(`[${env.name}] Series directory badges sample:`, seriesAnalysis.sampleBadges);
    results.seriesDeep[env.name] = seriesAnalysis;
    await sPage.close();
  }

  await browser.close();

  fs.writeFileSync(path.join(AUDIT_OUT_DIR, 'deep_audit_results.json'), JSON.stringify(results, null, 2));
  console.log(`\n=== DEEP AUDIT COMPLETE. Saved to ${AUDIT_OUT_DIR}/deep_audit_results.json ===`);
}

runDeepAudit().catch(e => {
  console.error('Deep audit failed:', e);
  process.exit(1);
});
