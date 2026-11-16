import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/3afc5775-c9fc-4401-b76c-8365bed70db3/screenshots';

fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

async function runAudit() {
  console.log('🚀 Iniciando Auditoría QA Playwright Devir & Boticaria...');
  const browser = await chromium.launch({
    executablePath: CHROME_PATH,
    headless: true
  });

  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 1
  });
  const page = await context.newPage();

  const auditData = {
    home: {},
    brandPage: {},
    seriesPage: {},
    products: []
  };

  // ==========================================
  // 1. HOME (http://localhost:8080/)
  // ==========================================
  console.log('--- 1. Auditando Home ---');
  const homeResp = await page.goto('http://localhost:8080/', { waitUntil: 'domcontentloaded' });
  auditData.home.httpStatus = homeResp.status();

  // Disable CSS animations for stable screenshots
  await page.addStyleTag({
    content: `
      *, *::before, *::after {
        animation-duration: 0s !important;
        animation-play-state: paused !important;
        transition-duration: 0s !important;
      }
    `
  });

  // Pause GSAP animations
  await page.evaluate(() => {
    if (window.gsap) {
      window.gsap.globalTimeline.pause();
    }
  });

  // Scroll to brand carousel
  const carousel = await page.$('.akb-brands-home');
  if (carousel) {
    await carousel.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
  }

  const devirSlide = await page.$('.akb-brands-home__slide:has([data-brand="devir"])');
  auditData.home.devirSlidePresent = !!devirSlide;

  if (devirSlide) {
    // Scroll element into view horizontally inside carousel
    await page.evaluate((el) => {
      el.scrollIntoView({ inline: 'center', block: 'center', behavior: 'instant' });
    }, devirSlide);
    await page.waitForTimeout(400);

    const item = await devirSlide.$('.akb-brands-home__item');
    auditData.home.href = await item.getAttribute('href');

    const logoSvg = await devirSlide.$('svg.akb-publisher-logo--devir');
    auditData.home.logoSvgPresent = !!logoSvg;

    if (logoSvg) {
      const useEl = await logoSvg.$('use');
      auditData.home.useHref = useEl ? await useEl.getAttribute('href') : null;
      const box = await logoSvg.boundingBox();
      auditData.home.svgBoundingBox = box;
      auditData.home.svgVisible = box ? (box.width > 0 && box.height > 0) : false;
    }

    const countryBadge = await devirSlide.$('.akb-brands-home__country');
    auditData.home.countryText = countryBadge ? (await countryBadge.textContent()).trim() : null;

    const countEl = await devirSlide.$('.akb-brands-home__count');
    auditData.home.countText = countEl ? (await countEl.textContent()).trim() : null;

    // Screenshot of carousel
    const cBox = await carousel.boundingBox();
    if (cBox && cBox.width > 0 && cBox.height > 0) {
      await page.screenshot({
        path: path.join(SCREENSHOT_DIR, '01_home_brands_carousel.png'),
        clip: cBox
      }).catch(e => console.warn('Warning capturing carousel:', e.message));
    }

    const sBox = await devirSlide.boundingBox();
    if (sBox && sBox.width > 0 && sBox.height > 0 && sBox.x >= 0 && (sBox.x + sBox.width) <= 1440) {
      await page.screenshot({
        path: path.join(SCREENSHOT_DIR, '01_home_devir_brand_card.png'),
        clip: sBox
      }).catch(e => console.warn('Warning capturing slide:', e.message));
    } else {
      console.log('devirSlide bounding box outside viewport, capturing carousel viewport instead');
    }
  }

  // ==========================================
  // 2. BRAND PAGE (http://localhost:8080/marca/devir/)
  // ==========================================
  console.log('--- 2. Auditando Página de Marca: /marca/devir/ ---');
  const brandResp = await page.goto('http://localhost:8080/marca/devir/', { waitUntil: 'domcontentloaded' });
  auditData.brandPage.httpStatus = brandResp.status();

  const brandTitle = await page.textContent('h1.woocommerce-products-header__title, h1.page-title, h1');
  auditData.brandPage.title = brandTitle ? brandTitle.trim() : null;

  const productCards = await page.$$('.woocommerce ul.products li.product');
  auditData.brandPage.productCount = productCards.length;

  // Audit sample of product cards
  const cardsSample = [];
  for (let i = 0; i < productCards.length; i++) {
    const card = productCards[i];
    const cardTitle = await card.$eval('.woocommerce-loop-product__title, .woocommerce-loop-product__title a, h2', el => el.textContent.trim()).catch(() => null);
    const eyebrow = await card.$eval('.akb-editorial-eyebrow, .akb-card__eyebrow, .product-eyebrow', el => el.textContent.trim()).catch(() => null);
    const regularPrice = await card.$eval('del .woocommerce-Price-amount', el => el.textContent.trim()).catch(() => null);
    const salePrice = await card.$eval('ins .woocommerce-Price-amount, .price > .woocommerce-Price-amount', el => el.textContent.trim()).catch(() => null);
    const badge = await card.$eval('.akb-badge, .onsale', el => el.textContent.trim()).catch(() => null);

    cardsSample.push({
      index: i + 1,
      title: cardTitle,
      eyebrow,
      regularPrice,
      salePrice,
      badge
    });
  }
  auditData.brandPage.cards = cardsSample;

  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '02_marca_devir_full.png'), fullPage: true, animations: 'disabled' });

  // ==========================================
  // 3. SERIES PAGE (http://localhost:8080/serie/los-diarios-de-la-boticaria-devir/)
  // ==========================================
  console.log('--- 3. Auditando Página de Serie: /serie/los-diarios-de-la-boticaria-devir/ ---');
  const seriesResp = await page.goto('http://localhost:8080/serie/los-diarios-de-la-boticaria-devir/', { waitUntil: 'domcontentloaded' });
  auditData.seriesPage.httpStatus = seriesResp.status();

  const seriesTitle = await page.textContent('h1.woocommerce-products-header__title, h1.page-title, h1');
  auditData.seriesPage.title = seriesTitle ? seriesTitle.trim() : null;

  const seriesDescHtml = await page.$eval('.akb-archive-hero__desc, [data-akb-archive-intro], .term-description', el => el.innerHTML).catch(() => null);
  const seriesDescText = await page.$eval('.akb-archive-hero__desc, [data-akb-archive-intro], .term-description', el => el.textContent.trim()).catch(() => null);
  auditData.seriesPage.descHtml = seriesDescHtml;
  auditData.seriesPage.descText = seriesDescText;

  // Also extract series facts summary
  const factsRows = await page.$$eval('.akb-series-facts__item', items => {
    return items.map(it => {
      const label = it.querySelector('.akb-series-facts__label')?.textContent.trim() || '';
      const val = it.querySelector('.akb-series-facts__value')?.textContent.trim() || '';
      return { label, val };
    });
  }).catch(() => []);
  auditData.seriesPage.facts = factsRows;

  const seriesProducts = await page.$$('.woocommerce ul.products li.product');
  auditData.seriesPage.productCount = seriesProducts.length;

  const seriesVolumeOrder = [];
  for (let i = 0; i < seriesProducts.length; i++) {
    const card = seriesProducts[i];
    const title = await card.$eval('.woocommerce-loop-product__title, .woocommerce-loop-product__title a, h2', el => el.textContent.trim()).catch(() => 'N/A');
    seriesVolumeOrder.push(title);
  }
  auditData.seriesPage.volumeOrder = seriesVolumeOrder;

  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '03_serie_boticaria_full.png'), fullPage: true, animations: 'disabled' });

  // ==========================================
  // 4. INDIVIDUAL PRODUCT PAGES (Vol 01, Vol 08, Vol 16)
  // ==========================================
  const volumesToAudit = [
    { num: 1, slug: 'los-diarios-de-la-boticaria-1-devir' },
    { num: 8, slug: 'los-diarios-de-la-boticaria-8-devir' },
    { num: 16, slug: 'los-diarios-de-la-boticaria-16-devir' }
  ];

  for (const vol of volumesToAudit) {
    console.log(`--- 4. Auditando Ficha de Producto: Vol ${vol.num} (${vol.slug}) ---`);
    const pdpUrl = `http://localhost:8080/${vol.slug}/`;
    const pdpResp = await page.goto(pdpUrl, { waitUntil: 'domcontentloaded' });

    const pData = {
      volume: vol.num,
      slug: vol.slug,
      url: pdpUrl,
      httpStatus: pdpResp.status()
    };

    pData.h1 = await page.$eval('h1.product_title', el => el.textContent.trim()).catch(() => null);
    
    // Price checks
    pData.regularPriceDel = await page.$eval('.single-product .summary .price del', el => el.textContent.trim()).catch(() => null);
    pData.salePriceIns = await page.$eval('.single-product .summary .price ins', el => el.textContent.trim()).catch(() => null);
    pData.reservationPriceScreenReader = await page.$eval('.akb-price--preventa .screen-reader-text', el => el.textContent.trim()).catch(() => null);
    pData.reservationAlzaNotice = await page.$eval('.akb-price--preventa .akb-preventa-alza', el => el.textContent.trim()).catch(() => null);
    pData.badgeSingle = await page.$eval('.akb-badge-single .akb-badge', el => el.textContent.trim()).catch(() => null);
    pData.addToCartButtonText = await page.$eval('.single_add_to_cart_button', el => el.textContent.trim()).catch(() => null);
    pData.etaValue = await page.$eval('.akb-eta__value', el => el.textContent.trim()).catch(() => null);
    pData.etaNota = await page.$eval('.akb-eta__nota', el => el.textContent.trim()).catch(() => null);

    // Dimensions & Weight
    pData.weight = await page.$eval('.woocommerce-product-attributes-item--weight .woocommerce-product-attributes-item__value', el => el.textContent.trim()).catch(() => null);
    pData.dimensions = await page.$eval('.woocommerce-product-attributes-item--dimensions .woocommerce-product-attributes-item__value', el => el.textContent.trim()).catch(() => null);

    // Responsive AVIF image
    const imgEl = await page.$('.woocommerce-product-gallery__image img, .wp-post-image');
    if (imgEl) {
      pData.imgSrc = await imgEl.getAttribute('src');
      pData.imgAlt = await imgEl.getAttribute('alt');
      pData.imgWidth = await imgEl.getAttribute('width');
      pData.imgHeight = await imgEl.getAttribute('height');
      pData.imgSizes = await imgEl.getAttribute('sizes');
      pData.imgSrcset = await imgEl.getAttribute('srcset');
      pData.imgCurrentSrc = await imgEl.evaluate(el => el.currentSrc);
      pData.imgNaturalWidth = await imgEl.evaluate(el => el.naturalWidth);
      pData.imgNaturalHeight = await imgEl.evaluate(el => el.naturalHeight);
    }

    // SKU
    pData.sku = await page.$eval('.sku_wrapper .sku', el => el.textContent.trim()).catch(() => null);

    // Short description
    pData.shortDesc = await page.$eval('.woocommerce-product-details__short-description', el => el.innerHTML.trim()).catch(() => null);
    pData.shortDescText = await page.$eval('.woocommerce-product-details__short-description', el => el.textContent.trim()).catch(() => null);
    pData.shortDescLength = pData.shortDescText ? pData.shortDescText.length : 0;
    pData.shortDescStartsWithBold = pData.shortDesc ? pData.shortDesc.startsWith('<strong>') : false;

    // Specs Grid
    pData.specsGridPresent = !!(await page.$('.akb-pdp-specs-grid'));
    if (pData.specsGridPresent) {
      pData.specsGridHtml = await page.$eval('.akb-pdp-specs-grid', el => el.outerHTML);
    }

    // SEO Links
    pData.seoLinksPresent = !!(await page.$('.akb-pdp-seo-links'));
    if (pData.seoLinksPresent) {
      pData.seoLinksHtml = await page.$eval('.akb-pdp-seo-links', el => el.outerHTML);
    }

    // Check absence of "Norma Editorial" and external links in whole body
    const bodyHtml = await page.content();
    pData.mentionsNorma = /Norma\s*Editorial/i.test(bodyHtml);
    
    // Check external links inside post content
    const descTabContent = await page.$eval('#tab-description, .woocommerce-Tabs-panel--description, .woocommerce-product-details__short-description', el => el.innerHTML).catch(() => '');
    const externalLinksMatches = descTabContent.match(/href=["']https?:\/\/(?!localhost|akibara\.cl)[^"']+/gi);
    pData.externalLinks = externalLinksMatches || [];

    // Upsells
    const upsellSection = await page.$('.up-sells, section.upsells');
    pData.upsellSectionPresent = !!upsellSection;
    if (upsellSection) {
      const upsellTitle = await upsellSection.$eval('h2', el => el.textContent.trim()).catch(() => null);
      pData.upsellTitle = upsellTitle;
      const upsellItems = await upsellSection.$$('ul.products li.product');
      pData.upsellCount = upsellItems.length;
      pData.upsellProducts = [];
      for (const item of upsellItems) {
        const uTitle = await item.$eval('.woocommerce-loop-product__title, .woocommerce-loop-product__title a, h2', el => el.textContent.trim()).catch(() => 'N/A');
        pData.upsellProducts.push(uTitle);
      }
    }

    // Related products
    const relatedSection = await page.$('.related.products, section.related');
    pData.relatedSectionPresent = !!relatedSection;
    if (relatedSection) {
      const relItems = await relatedSection.$$('ul.products li.product');
      pData.relatedCount = relItems.length;
    }

    // Take screenshots
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, `04_pdp_vol_${vol.num}_full.png`), fullPage: true, animations: 'disabled' });

    auditData.products.push(pData);
  }

  await browser.close();

  fs.writeFileSync(
    '/Users/alefvaras/.gemini/antigravity/brain/3afc5775-c9fc-4401-b76c-8365bed70db3/playwright_audit_results.json',
    JSON.stringify(auditData, null, 2)
  );
  console.log('✅ Auditoría Playwright completada con éxito.');
}

runAudit().catch(err => {
  console.error('❌ Error en auditoría:', err);
  process.exit(1);
});
