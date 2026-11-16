import playwright from '../akibara-e2e/node_modules/playwright/index.js';
import assert from 'node:assert/strict';
import { existsSync, mkdirSync } from 'node:fs';

const pw = playwright.default || playwright;
const { chromium } = pw;

const screenshotDir = '/Users/alefvaras/.gemini/antigravity/brain/c5738b26-8e15-4e9b-9d7a-fffd6b137fb1/screenshots';
if (!existsSync(screenshotDir)) {
  mkdirSync(screenshotDir, { recursive: true });
}

async function run() {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });
  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 }
  });
  const page = await context.newPage();

  console.log('Navegando a PDP con reseña y foto: http://localhost:8080/homunculus-10-ivrea-argentina/');
  await page.goto('http://localhost:8080/homunculus-10-ivrea-argentina/', { waitUntil: 'networkidle' });

  // Activar la pestaña de valoraciones si existe pestaña de WooCommerce
  const reviewsTabLink = page.locator('#tab-title-reviews a, a[href="#tab-reviews"]');
  if (await reviewsTabLink.count() > 0) {
    console.log('Pestaña de valoraciones encontrada, haciendo clic para desplegar...');
    await reviewsTabLink.first().click();
    await page.waitForTimeout(300);
  }

  // Desplazarse a la sección de reseñas
  const reviewsSection = page.locator('#reviews');
  await reviewsSection.scrollIntoViewIfNeeded();

  // Verificar píldora "📸 Compra verificada con fotos"
  const badge = page.locator('.akb-rw-foto__badge');
  await badge.waitFor({ state: 'visible', timeout: 5000 });
  const badgeText = await badge.innerText();
  console.log('Insignia encontrada:', badgeText);
  assert.ok(badgeText.includes('Compra verificada con fotos'), 'Debe mostrar la insignia de fotos');

  // Tomar captura de la reseña en la ficha
  await page.screenshot({ path: `${screenshotDir}/lightbox-01-resena-con-foto-en-pdp.png` });
  console.log('Captura guardada: lightbox-01-resena-con-foto-en-pdp.png');

  // Verificar botón trigger
  const triggerBtn = page.locator('.akb-rw-foto__btn');
  await triggerBtn.waitFor({ state: 'visible' });

  // Dialog inicial debe estar cerrado
  const dialog = page.locator('#akb-review-lightbox');
  const isOpenBefore = await dialog.evaluate((el) => el.open || el.hasAttribute('open'));
  assert.equal(isOpenBefore, false, 'El lightbox debe iniciar cerrado');

  // Hacer clic en la foto
  console.log('Haciendo clic en la foto de la reseña...');
  await triggerBtn.click();

  // Esperar a que el dialog esté abierto
  await page.waitForFunction(() => {
    const d = document.getElementById('akb-review-lightbox');
    return d && (d.open || d.hasAttribute('open'));
  }, { timeout: 3000 });

  console.log('¡Lightbox abierto correctamente!');
  const lightboxImg = page.locator('#akb-review-lightbox-img');
  const imgSrc = await lightboxImg.getAttribute('src');
  console.log('Src de imagen ampliada:', imgSrc);
  assert.ok(imgSrc && imgSrc.length > 5, 'Debe haber un src cargado en la imagen');

  // Tomar captura del lightbox abierto
  await page.screenshot({ path: `${screenshotDir}/lightbox-02-modal-abierto-alta-resolucion.png` });
  console.log('Captura guardada: lightbox-02-modal-abierto-alta-resolucion.png');

  // Cerrar lightbox con tecla Escape
  console.log('Presionando Escape...');
  await page.keyboard.press('Escape');

  // Esperar a que se cierre
  await page.waitForFunction(() => {
    const d = document.getElementById('akb-review-lightbox');
    return !d || (!d.open && !d.hasAttribute('open'));
  }, { timeout: 3000 });
  console.log('¡Lightbox cerrado con Escape exitosamente!');

  // Abrir nuevamente y cerrar con el botón cerrar
  await triggerBtn.click();
  await page.waitForFunction(() => {
    const d = document.getElementById('akb-review-lightbox');
    return d && (d.open || d.hasAttribute('open'));
  });

  const closeBtn = page.locator('.akb-review-lightbox__close');
  await closeBtn.click();

  await page.waitForFunction(() => {
    const d = document.getElementById('akb-review-lightbox');
    return !d || (!d.open && !d.hasAttribute('open'));
  });
  console.log('¡Lightbox cerrado con botón de cerrar exitosamente!');

  await page.screenshot({ path: `${screenshotDir}/lightbox-03-modal-cerrado.png` });
  console.log('Captura guardada: lightbox-03-modal-cerrado.png');

  await browser.close();
  console.log('--- CERTIFICACIÓN E2E DE LIGHTBOX EXITOSA ---');
}

run().catch((err) => {
  console.error('Error en certificación E2E:', err);
  process.exit(1);
});
