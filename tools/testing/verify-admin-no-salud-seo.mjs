import playwright from '../akibara-e2e/node_modules/playwright/index.js';
import { execSync as exec } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';
import assert from 'node:assert';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const screenshotDir = '/Users/alefvaras/.gemini/antigravity/brain/c5738b26-8e15-4e9b-9d7a-fffd6b137fb1/screenshots/qa-cert-final';
if (!existsSync(screenshotDir)) {
  mkdirSync(screenshotDir, { recursive: true });
}

async function run() {
  console.log('--- Verificación en Vivo (Docker Local): Retiro de tab=salud_seo ---');

  // Generar cookie de sesión admin (User 1)
  const authRaw = exec(`docker compose exec -T cron wp eval '
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

  const adminCookieRaw = exec(`docker compose exec -T cron wp eval '
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
    viewport: { width: 1440, height: 900 }
  });

  await context.addCookies([
    {
      name: authCookie.name,
      value: authCookie.value,
      domain: 'localhost',
      path: '/'
    },
    {
      name: adminCookie.name,
      value: adminCookie.value,
      domain: 'localhost',
      path: '/wp-admin'
    }
  ]);

  const page = await context.newPage();

  // 1. Probar redirección 302 de la ruta retirada tab=salud_seo
  console.log('1. Navegando a http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=salud_seo ...');
  const response = await page.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=salud_seo', {
    waitUntil: 'networkidle'
  });

  const currentUrl = page.url();
  console.log(`URL actual tras navegación: ${currentUrl}`);

  assert.ok(
    currentUrl.includes('page=akibara-panel') && !currentUrl.includes('tab=salud_seo'),
    `ERROR: La URL debía redirigir fuera de tab=salud_seo, pero quedó en: ${currentUrl}`
  );
  console.log('✔ Redirección transparente confirmada (tab=salud_seo -> page=akibara-panel).');

  // 2. Verificar ausencia de "Salud SEO" y "Sistema" en la navegación del panel
  const navText = await page.textContent('.akb-panel-nav, .akb-panel-group-sistema, .akb-panel-layout');
  assert.ok(!navText.includes('Salud SEO'), 'ERROR: "Salud SEO" sigue visible en el panel');
  assert.ok(!navText.includes('Sistema'), 'ERROR: El grupo "Sistema" sigue visible cuando está vacío');
  console.log('✔ Ausencia total de "Salud SEO" y grupo "Sistema" confirmada en el DOM.');

  // 3. Capturar Screenshot de certificación
  const screenshotPath = path.join(screenshotDir, 'qa-cert-admin-panel-no-salud-seo.png');
  await page.screenshot({ path: screenshotPath, fullPage: true });
  console.log(`✔ Captura guardada en: ${screenshotPath}`);

  await browser.close();
  console.log('--- CERTIFICACIÓN 100% VERDE: UI Salud SEO erradicada y backend en código ---');
}

run().catch(err => {
  console.error(err);
  process.exit(1);
});
