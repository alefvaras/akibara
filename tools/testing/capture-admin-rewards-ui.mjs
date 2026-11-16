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
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });

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

  // También se necesita la cookie SECURE_AUTH_COOKIE para /wp-admin
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

  const context = await browser.newContext({
    viewport: { width: 1440, height: 1200 }
  });
  await context.addCookies([authCookie, adminCookie]);

  const page = await context.newPage();

  console.log('Navegando a Admin Puntos y Reseñas: Tab Puntos...');
  await page.goto('http://localhost:8080/wp-admin/admin.php?page=akb-rewards-settings#tab-puntos', { waitUntil: 'networkidle' });

  await page.screenshot({
    path: `${screenshotDir}/admin-rewards-01-tab-puntos.png`,
    fullPage: true
  });
  console.log('Captura guardada: admin-rewards-01-tab-puntos.png');

  console.log('Navegando a Tab Reseñas...');
  // Hacer clic en la pestaña de Reseñas para activar el panel
  await page.click('a[data-tab="resenas"]');
  await page.waitForTimeout(500);

  await page.screenshot({
    path: `${screenshotDir}/admin-rewards-02-tab-resenas.png`,
    fullPage: true
  });
  console.log('Captura guardada: admin-rewards-02-tab-resenas.png');

  await browser.close();
}

run().catch((err) => {
  console.error(err);
  process.exit(1);
});
