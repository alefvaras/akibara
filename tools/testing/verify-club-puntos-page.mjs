import playwright from '../akibara-e2e/node_modules/playwright/index.js';
import { execSync as exec } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';

const pw = playwright.default || playwright;
const { chromium } = pw;

const screenshotDir = '/Users/alefvaras/.gemini/antigravity/brain/c5738b26-8e15-4e9b-9d7a-fffd6b137fb1/screenshots';
if (!existsSync(screenshotDir)) {
  mkdirSync(screenshotDir, { recursive: true });
}

async function run() {
  console.log('🚀 Iniciando verificación de la página pública del Club de Puntos (/akibara-puntos/)...');

  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  // 1. Vista Desktop (1440 x 1200)
  const page = await browser.newPage({ viewport: { width: 1440, height: 1200 } });
  console.log('📍 Navegando a http://localhost:8080/akibara-puntos/ ...');
  await page.goto('http://localhost:8080/akibara-puntos/', { waitUntil: 'networkidle' });

  // Verificar elementos esenciales
  await page.waitForSelector('.akb-club-wrap');
  console.log('✓ Contenedor principal .akb-club-wrap renderizado.');

  const heroTitle = await page.locator('.akb-club-hero__title').innerText();
  console.log('✓ Hero Title:', heroTitle.replace(/\n/g, ' '));

  // Verificar calculadora interactiva
  const calcBox = page.locator('#akb-club-calc');
  await calcBox.waitFor({ state: 'visible' });
  console.log('✓ Calculadora interactiva presente.');

  // Captura 1: Desktop Completa
  await page.screenshot({
    path: `${screenshotDir}/club-puntos-01-desktop.png`,
    fullPage: true
  });
  console.log('📸 Captura 1 guardada: club-puntos-01-desktop.png');

  // 2. Interacción con la Calculadora Slider
  const slider = page.locator('.akb-calc-range');
  const display = page.locator('#akb-calc-display');
  const ptsVal = page.locator('#akb-calc-points-val');

  console.log('Simulación inicial:', {
    monto: await display.innerText(),
    puntos: await ptsVal.innerText()
  });

  // Mover slider a $75.000
  await slider.fill('75000');
  await slider.dispatchEvent('input');
  await page.waitForTimeout(200);

  const display75k = await display.innerText();
  const pts75k = await ptsVal.innerText();
  console.log('Tras mover slider a $75.000:', { monto: display75k, puntos: pts75k });

  if (!display75k.includes('75.000')) {
    throw new Error(`El slider no actualizó el display a $75.000 (actual: ${display75k})`);
  }
  console.log('✓ Slider interactivo responde al 100% en tiempo real.');

  // 3. Probar Dinamismo Absoluto ante cambios de configuración
  console.log('⚙️ Probando dinamismo: cambiando cumpleaños a 2.500 pts en opciones de WP...');
  const origBday = exec(`docker exec akibara-cron-1 wp option get akb_rewards_birthday_points`, { encoding: 'utf8' }).trim();
  
  try {
    exec(`docker exec akibara-cron-1 wp option update akb_rewards_birthday_points 2500`);
    
    // Recargar página
    await page.reload({ waitUntil: 'networkidle' });
    
    // Buscar la card de cumpleaños
    const bdayCardText = await page.locator('.akb-way-card:has-text("cumpleaños")').innerText();
    console.log('Texto de card de cumpleaños tras update:', bdayCardText.replace(/\n/g, ' '));

    if (!bdayCardText.includes('2.500 pts') && !bdayCardText.includes('2.500')) {
      throw new Error(`La página no reflejó el cambio dinámico de cumpleaños a 2.500 pts: ${bdayCardText}`);
    }
    console.log('✓ La página del Club de Puntos se actualizó en vivo reflejando los 2.500 pts sin tocar código!');

    // Captura 2: Foco en Calculadora y Formas de Ganar Dinámicas
    await page.screenshot({
      path: `${screenshotDir}/club-puntos-02-dynamic-reflection.png`,
      clip: { x: 200, y: 350, width: 1040, height: 750 }
    });
    console.log('📸 Captura 2 guardada: club-puntos-02-dynamic-reflection.png');
  } finally {
    // Restaurar valor original
    exec(`docker exec akibara-cron-1 wp option update akb_rewards_birthday_points ${origBday || 1000}`);
    console.log(`✓ Valor de cumpleaños restaurado a ${origBday || 1000}.`);
  }

  // 4. Verificación Responsive en Móvil (iPhone 375px)
  console.log('📱 Verificando geometría móvil en iPhone 375px...');
  const mobilePage = await browser.newPage({ viewport: { width: 375, height: 812 } });
  await mobilePage.goto('http://localhost:8080/akibara-puntos/', { waitUntil: 'networkidle' });

  // Verificar cero desbordamiento horizontal
  const isOverflowing = await mobilePage.evaluate(() => {
    return document.documentElement.scrollWidth > document.documentElement.clientWidth;
  });

  if (isOverflowing) {
    const scrollW = await mobilePage.evaluate(() => document.documentElement.scrollWidth);
    throw new Error(`Desbordamiento horizontal detectado en 375px: scrollWidth=${scrollW} > 375`);
  }
  console.log('✓ Cero desbordamiento horizontal en 375px (scrollWidth <= clientWidth).');

  // Captura 3: Móvil completa
  await mobilePage.screenshot({
    path: `${screenshotDir}/club-puntos-03-mobile.png`,
    fullPage: true
  });
  console.log('📸 Captura 3 guardada: club-puntos-03-mobile.png');

  await browser.close();
  console.log('🎉 CERTIFICACIÓN EXITOSA: La página del Club de Puntos es amigable, interactiva y 100% dinámica.');
}

run().catch(err => {
  console.error('❌ Error en verificación:', err);
  process.exit(1);
});
