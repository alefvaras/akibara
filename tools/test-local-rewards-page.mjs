import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;
import path from 'node:path';
import fs from 'node:fs';

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const screenshotDir = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/rewards-ux';
fs.mkdirSync(screenshotDir, { recursive: true });

async function run() {
  const browser = await chromium.launch({
    executablePath: CHROME_PATH,
    headless: true
  });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();

  console.log('Navegando a local: http://localhost:8080/akibara-puntos/');
  await page.goto('http://localhost:8080/akibara-puntos/', { waitUntil: 'networkidle' });

  // 1. Hero y 3 pasos
  await page.screenshot({ path: path.join(screenshotDir, '01-local-hero-and-steps.png'), fullPage: false });

  // 2. Scroll a "Todas las formas de ganar" y "Ejemplo real"
  const exampleBox = await page.$('.akb-pts-box-example');
  if (exampleBox) {
    await exampleBox.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(screenshotDir, '02-local-earning-and-example.png'), fullPage: false });
  }

  // 3. Scroll a "Lo que tienes que saber" (acordeones)
  const rules = await page.$('.akb-pts-rules');
  if (rules) {
    await rules.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(screenshotDir, '03-local-accordions-closed.png'), fullPage: false });

    // Abrir el segundo y tercer acordeón para verificar interactividad
    const details = await page.$$('.akb-pts-accordion');
    if (details.length >= 2) {
      await details[1].click(); // abrir acordeón 2
      await page.waitForTimeout(300);
      await page.screenshot({ path: path.join(screenshotDir, '04-local-accordions-opened.png'), fullPage: false });
    }
  }

  // 4. Full page
  await page.screenshot({ path: path.join(screenshotDir, '05-local-fullpage.png'), fullPage: true });

  console.log('Capturas locales guardadas exitosamente en:', screenshotDir);
  await browser.close();
}

run().catch(err => {
  console.error('Error en test local:', err);
  process.exit(1);
});
