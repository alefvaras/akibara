import { chromium } from 'playwright';

const LOCAL_BASE = 'http://localhost:8080';
const PROD_BASE = 'https://akibara.cl';

const SERIE_PATH = '/serie/los-diarios-de-la-boticaria-maomao-resolviendo-los-misterios-imperiales/';
const PDP_PATH = '/los-diarios-de-la-boticaria-maomao-resolviendo-los-misterios-imperiales-1-ivrea-espana/';

async function auditEnvironment(browser, envName, baseUrl) {
  console.log(`\n========================================`);
  console.log(`AUDITING ENVIRONMENT: ${envName} (${baseUrl})`);
  console.log(`========================================`);

  const page = await browser.newPage();
  const results = {
    env: envName,
    serie: {},
    pdp: {},
    errors: [],
  };

  page.on('console', msg => {
    if (msg.type() === 'error') {
      results.errors.push(`[Console Error] ${msg.text()}`);
    }
  });

  page.on('pageerror', err => {
    results.errors.push(`[Page Error] ${err.message}`);
  });

  // 1. AUDIT SERIE ARCHIVE
  const serieUrl = `${baseUrl}${SERIE_PATH}`;
  console.log(`Navigating to Serie Archive: ${serieUrl}`);
  const respSerie = await page.goto(serieUrl, { waitUntil: 'domcontentloaded' });
  results.serie.httpStatus = respSerie.status();

  // Title
  results.serie.title = await page.locator('h1.akb-archive-hero__title').textContent();

  // Description and Rule 17 specs
  const introText = await page.locator('#akb-series-intro-816, #akb-series-intro-682, .akb-archive-hero__desc').textContent();
  results.serie.introText = introText.trim();
  results.serie.hasAutorOriginal = /Autor original:\s*Natsu Hyuuga/i.test(introText);
  results.serie.hasDibujo = /Dibujo:\s*Minoji Kurata/i.test(introText);
  results.serie.hasDemografia = /Demograf[ií]a:\s*Seinen/i.test(introText);
  results.serie.hasEstadoJapon = /Estado en Jap[oó]n:\s*En curso\s*\(21 tomos publicados\)/i.test(introText);
  results.serie.hasFormatoEdicion = /Formato de edici[oó]n:\s*Tomo doble 2 en 1/i.test(introText);
  results.serie.hasEstadoEspana = /Estado en Espa[nñ]a:\s*En curso\s*\(1 tomo publicado\)/i.test(introText);
  results.serie.hasProximoTomo = /Pr[oó]ximo tomo:\s*Tomo #2 anunciado para diciembre/i.test(introText);

  // Facts Section
  const factsLocator = page.locator('#ficha-de-la-serie');
  results.serie.factsVisible = await factsLocator.isVisible();
  if (results.serie.factsVisible) {
    const factsText = await factsLocator.textContent();
    results.serie.factsText = factsText;
    results.serie.factsEdicion = /Edici[oó]n Ivrea Espa[nñ]a.*En publicaci[oó]n.*1 tomo previsto/is.test(factsText);
    results.serie.factsDisponible = /Disponible.*0 tomos/is.test(factsText);
    results.serie.factsAPedido = /A pedido.*1 tomo/is.test(factsText);
    results.serie.factsAutores = /Minoji Kurata.*Natsu Hyuuga/is.test(factsText);
    results.serie.factsEditorial = /Ivrea Espa[nñ]a/is.test(factsText);
  }

  // 2. AUDIT PDP (TOMO 01)
  const pdpUrl = `${baseUrl}${PDP_PATH}`;
  console.log(`Navigating to PDP: ${pdpUrl}`);
  const respPdp = await page.goto(pdpUrl, { waitUntil: 'domcontentloaded' });
  results.pdp.httpStatus = respPdp.status();

  results.pdp.title = await page.locator('h1.product_title').textContent();
  results.pdp.badge = await page.locator('.summary .akb-badge').textContent();

  // Price checks
  const priceLocator = page.locator('.summary p.price');
  results.pdp.priceText = await priceLocator.textContent();
  const delText = await priceLocator.locator('del').textContent().catch(() => '');
  const insText = await priceLocator.locator('ins').textContent().catch(() => '');
  results.pdp.delPrice = delText.trim();
  results.pdp.insPrice = insText.trim();
  results.pdp.hasRegularPrice = delText.includes('24.700');
  results.pdp.hasSalePrice = insText.includes('23.465');

  // Series navigation in summary
  const seriesNavLocator = page.locator('.akb-series-nav');
  results.pdp.seriesNavVisible = await seriesNavLocator.isVisible();
  results.pdp.seriesNavText = await seriesNavLocator.textContent();
  results.pdp.seriesNavLink = await seriesNavLocator.locator('a.akb-series-nav__link--series').getAttribute('href');

  // Short description
  const shortDescLocator = page.locator('.woocommerce-product-details__short-description');
  results.pdp.shortDescVisible = await shortDescLocator.isVisible();
  results.pdp.shortDescHtml = await shortDescLocator.innerHTML();
  results.pdp.shortDescStartsStrong = results.pdp.shortDescHtml.trim().startsWith('<p><strong>Los Diarios de la Boticaria: Maomao Resolviendo los Misterios Imperiales 01</strong>');

  // Specs grid
  const specsLocator = page.locator('.akb-pdp-specs-grid');
  results.pdp.specsVisible = await specsLocator.isVisible();
  if (results.pdp.specsVisible) {
    const specsText = await specsLocator.textContent();
    results.pdp.specsText = specsText;
    results.pdp.hasAutorSpec = specsText.includes('Minoji Kurata') && specsText.includes('Natsu Hyuuga');
    results.pdp.hasEditorialSpec = specsText.includes('Ivrea España');
    results.pdp.hasSerieSpec = specsText.includes('Los Diarios de la Boticaria');
    results.pdp.hasGenerosSpec = specsText.includes('Drama') && specsText.includes('Histórico') && specsText.includes('Misterio');
    results.pdp.hasAPedidoSpec = specsText.includes('A pedido') && specsText.includes('Lo pedimos a la editorial por ti');
  }

  // Cover image
  const imgLocator = page.locator('.woocommerce-product-gallery__image img').first();
  results.pdp.imgSrc = await imgLocator.getAttribute('src');
  results.pdp.imgAlt = await imgLocator.getAttribute('alt');
  results.pdp.imgSizes = await imgLocator.getAttribute('sizes');
  results.pdp.imgWidth = await imgLocator.getAttribute('width');
  results.pdp.imgHeight = await imgLocator.getAttribute('height');

  // External link and forbidden editorial checks
  const pageHtml = await page.content();
  results.pdp.hasForbiddenEditorial = /Norma Editorial/i.test(pageHtml);
  
  // Find all links in the main content to verify zero external editorial links
  const contentLinks = await page.locator('.entry-content a').evaluateAll(links => 
    links.map(a => a.href).filter(href => href.includes('editorialivrea') || href.includes('panini') || href.includes('normaeditorial') || href.includes('planetadelibros'))
  );
  results.pdp.forbiddenExternalLinks = contentLinks;

  await page.close();
  return results;
}

(async () => {
  const executablePath = `${process.env.HOME}/Library/Caches/ms-playwright/chromium_headless_shell-1237/chrome-headless-shell-mac-arm64/chrome-headless-shell`;
  const browser = await chromium.launch({
    executablePath,
    headless: true,
  });
  try {
    const localResults = await auditEnvironment(browser, 'LOCAL DOCKER', LOCAL_BASE);
    const prodResults = await auditEnvironment(browser, 'PRODUCTION LIVE', PROD_BASE);

    console.log('\n========================================');
    console.log('FINAL AUDIT JSON SUMMARY:');
    console.log('========================================');
    console.log(JSON.stringify({ local: localResults, prod: prodResults }, null, 2));
  } catch (err) {
    console.error('Audit execution error:', err);
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();
