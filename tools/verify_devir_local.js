const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  
  // 1. Home
  await page.goto('http://localhost:8080/');
  const devirLogo = await page.$('.akb-brands-home__slide .akb-publisher-logo--devir');
  const devirBadge = await page.$('.akb-brands-home__slide .akb-brands-home__country:has-text("ES")');
  console.log('Home devir logo present:', !!devirLogo);
  console.log('Home devir badge present:', !!devirBadge);
  
  // 2. Brand Page
  await page.goto('http://localhost:8080/marca/devir/');
  const brandProducts = await page.$$('.woocommerce ul.products li.product');
  console.log('Brand page products count:', brandProducts.length);
  
  // 3. Series Page
  await page.goto('http://localhost:8080/serie/los-diarios-de-la-boticaria-devir/');
  const seriesProducts = await page.$$('.woocommerce ul.products li.product');
  console.log('Series page products count:', seriesProducts.length);
  
  // 4. Product Page
  await page.goto('http://localhost:8080/los-diarios-de-la-boticaria-1-devir/');
  const title = await page.textContent('h1.product_title');
  const price = await page.textContent('.single-product .summary .price');
  const weight = await page.textContent('.woocommerce-product-attributes-item--weight .woocommerce-product-attributes-item__value');
  const dims = await page.textContent('.woocommerce-product-attributes-item--dimensions .woocommerce-product-attributes-item__value');
  const specs = await page.$('.akb-pdp-specs-grid');
  const seoLinks = await page.$('.akb-pdp-seo-links');
  const upsells = await page.$$('.up-sells ul.products li.product');
  
  console.log('PDP Title:', title ? title.trim() : 'N/A');
  console.log('PDP Price:', price ? price.replace(/\s+/g, ' ').trim() : 'N/A');
  console.log('PDP Weight:', weight ? weight.trim() : 'N/A');
  console.log('PDP Dims:', dims ? dims.trim() : 'N/A');
  console.log('PDP Specs Grid present:', !!specs);
  console.log('PDP SEO Links present:', !!seoLinks);
  console.log('PDP Upsells count:', upsells.length);
  
  await browser.close();
})();
