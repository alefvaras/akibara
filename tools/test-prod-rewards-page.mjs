import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;
import path from 'node:path';
import fs from 'node:fs';

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const screenshotDir = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/rewards-prod';
fs.mkdirSync(screenshotDir, { recursive: true });

async function run() {
  const browser = await chromium.launch({
    executablePath: CHROME_PATH,
    headless: true
  });
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
  });
  const page = await context.newPage();

  console.log('Navegando a Producción: https://akibara.cl/akibara-puntos/');
  await page.goto('https://akibara.cl/akibara-puntos/', { waitUntil: 'networkidle', timeout: 30000 });

  // 1. Hero y 3 Pasos
  await page.screenshot({ path: path.join(screenshotDir, '01-prod-hero-and-steps.png'), fullPage: false });

  // 2. Scroll a "Todas las formas de ganar" y "Ejemplo real"
  const exampleBox = await page.$('.akb-pts-box-example');
  if (exampleBox) {
    await exampleBox.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(screenshotDir, '02-prod-earning-and-example.png'), fullPage: false });
  }

  // 3. Scroll a "Lo que tienes que saber" (acordeones)
  const rules = await page.$('.akb-pts-rules');
  if (rules) {
    await rules.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(screenshotDir, '03-prod-accordions-closed.png'), fullPage: false });

    // Interactuar abriendo el segundo acordeón ("Bono de bienvenida, reseñas...")
    const accordions = await page.$$('.akb-pts-accordion summary');
    if (accordions.length >= 2) {
      await accordions[1].click();
      await page.waitForTimeout(300);
      await page.screenshot({ path: path.join(screenshotDir, '04-prod-accordions-opened.png'), fullPage: false });
    }
  }

  // 4. Captura full page
  await page.screenshot({ path: path.join(screenshotDir, '05-prod-fullpage.png'), fullPage: true });

  // 5. Auditoría DOM en producción
  console.log('--- AUDITORÍA DOM EN PRODUCCIÓN ---');
  const heroText = await page.$eval('.akb-pts-hero', el => el.innerText);
  console.log('[HERO]:', heroText.replace(/\n+/g, ' '));

  const stepsText = await page.$eval('.akb-pts-steps', el => el.innerText);
  console.log('[PASOS]:', stepsText.replace(/\n+/g, ' '));

  const tableRows = await page.$$eval('.akb-pts-tablewrap table tr', rows => rows.map(r => r.innerText.replace(/\t+/g, ' -> ')));
  console.log('[TABLA FORMAS DE GANAR]:', tableRows);

  const exampleText = await page.$eval('.akb-pts-box-example', el => el.innerText);
  console.log('[EJEMPLO REAL]:', exampleText.replace(/\n+/g, ' '));

  const accordionsTitles = await page.$$eval('.akb-pts-accordion summary', els => els.map(e => e.innerText));
  console.log('[ACORDEONES TITULOS]:', accordionsTitles);

  const fullText = await page.$eval('.akb-pts-wrap', el => el.textContent);
  const count17Ago = (fullText.match(/17 de agosto de 2026/g) || []).length;
  console.log('[MENCIONES "17 de agosto de 2026"]:', count17Ago);

  await browser.close();
  console.log('Validación en producción completada exitosamente.');
}

run().catch(err => {
  console.error('Error en test de producción:', err);
  process.exit(1);
});
