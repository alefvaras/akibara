import playwright from '../akibara-e2e/node_modules/playwright/index.js';
import { execSync as exec } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';

const pw = playwright.default || playwright;
const { chromium } = pw;

const screenshotDir = '/Users/alefvaras/.gemini/antigravity/brain/c5738b26-8e15-4e9b-9d7a-fffd6b137fb1/screenshots/qa-cert-final';
if (!existsSync(screenshotDir)) {
  mkdirSync(screenshotDir, { recursive: true });
}

async function run() {
  console.log('--- Verificación en Vivo (Docker Local): Retiro de tab=catalog_order ---');

  // Generar cookie de sesión admin (User 1)
  const authRaw = exec(`docker exec akibara-cron-1 wp eval '
      $u = get_user_by("id", 1);
      $exp = time() + 7200;
      $tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
      echo json_encode([
          "name" => LOGGED_IN_COOKIE,
          "value" => wp_generate_auth_cookie($u->ID, $exp, "logged_in", $tok),
          "domain" => "localhost",
          "path" => "/"
      ]);
  '`, { encoding: 'utf8' });

  const adminCookieRaw = exec(`docker exec akibara-cron-1 wp eval '
      $u = get_user_by("id", 1);
      $exp = time() + 7200;
      $tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
      echo json_encode([
          "name" => AUTH_COOKIE,
          "value" => wp_generate_auth_cookie($u->ID, $exp, "auth", $tok),
          "domain" => "localhost",
          "path" => "/"
      ]);
  '`, { encoding: 'utf8' });

  const authCookie = JSON.parse(authRaw);
  const adminCookie = JSON.parse(adminCookieRaw);

  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });

  const context = await browser.newContext({
    viewport: { width: 1440, height: 1200 }
  });
  await context.addCookies([authCookie, adminCookie]);

  const page = await context.newPage();

  // Test 1: Navegar directamente a la ruta retirada tab=catalog_order
  console.log('1. Probando navegación directa a tab=catalog_order...');
  const response = await page.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=catalog_order', {
    waitUntil: 'networkidle'
  });

  const finalUrl = page.url();
  console.log(`   URL resultante: ${finalUrl}`);
  if (finalUrl === 'http://localhost:8080/wp-admin/admin.php?page=akibara-panel') {
    console.log('   ✔ Redirección 302 exitosa a admin.php?page=akibara-panel');
  } else {
    console.error(`   ❌ Falló la redirección: esperado http://localhost:8080/wp-admin/admin.php?page=akibara-panel, obtenido ${finalUrl}`);
    process.exit(1);
  }

  // Test 2: Comprobar el menú lateral del Centro de Mando
  console.log('2. Auditando menú lateral del Centro de Mando...');
  const vitrinaTabCount = await page.locator('a[href*="tab=catalog_order"]').count();
  console.log(`   Enlaces a tab=catalog_order encontrados en el DOM: ${vitrinaTabCount}`);
  if (vitrinaTabCount === 0) {
    console.log('   ✔ Cero rastros de tab=catalog_order en el Centro de Mando.');
  } else {
    console.error('   ❌ Se encontró enlace a tab=catalog_order en el DOM.');
    process.exit(1);
  }

  // Captura de pantalla para certificación visual
  const screenshotPath = `${screenshotDir}/qa-cert-admin-panel-no-vitrina.png`;
  await page.screenshot({ path: screenshotPath, fullPage: false });
  console.log(`   ✔ Captura guardada en: ${screenshotPath}`);

  await browser.close();
  console.log('--- Verificación 100% Exitosa ---');
}

run().catch(err => {
  console.error('Error durante la verificación:', err);
  process.exit(1);
});
