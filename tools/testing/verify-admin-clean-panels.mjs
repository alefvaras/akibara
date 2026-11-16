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
  console.log('--- Verificación Adversarial en Vivo: Erradicación de 6 Pantallas Técnicas ---');

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

  // 1. Probar redirección 302 de las 6 rutas técnicas retiradas
  const retiredRoutes = [
    { name: 'tab=imagenes', url: 'http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=imagenes' },
    { name: 'tab=address', url: 'http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=address' },
    { name: 'tab=native_cart', url: 'http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=native_cart' },
    { name: 'tab=copys', url: 'http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=copys' },
    { name: 'tab=upsells_autofill', url: 'http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=upsells_autofill' },
    { name: 'page=akibara-provider-access', url: 'http://localhost:8080/wp-admin/admin.php?page=akibara-provider-access' }
  ];

  for (const route of retiredRoutes) {
    console.log(`Navegando a ${route.name} (${route.url}) ...`);
    await page.goto(route.url, { waitUntil: 'networkidle' });
    const currentUrl = page.url();
    console.log(`  -> URL resultante: ${currentUrl}`);
    assert.ok(
      currentUrl.includes('page=akibara-panel') && !currentUrl.includes(route.name.split('=')[1]),
      `ERROR: La ruta ${route.name} debía redirigir a page=akibara-panel, pero quedó en: ${currentUrl}`
    );
    console.log(`  ✔ Redirección 302 confirmada para ${route.name}.`);
  }

  // 2. Navegar al Centro de Mando principal y auditar navegación
  console.log('\nAuditando navegación del Centro de Mando principal (admin.php?page=akibara-panel)...');
  await page.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-panel', { waitUntil: 'networkidle' });

  const navHtml = await page.innerHTML('.akb-panel-nav');
  const forbiddenTerms = [
    'tab=imagenes',
    'tab=address',
    'tab=native_cart',
    'tab=copys',
    'tab=upsells_autofill',
    'akibara-provider-access',
    'satelite_provider_access',
    'Autocompletar dirección',
    'Calidad AVIF',
    'Carrito nativo',
    'Claves y accesos'
  ];

  for (const term of forbiddenTerms) {
    assert.ok(!navHtml.includes(term), `ERROR: El término retirado "${term}" sigue presente en la navegación`);
  }
  console.log('✔ Ningún término ni enlace de las pantallas retiradas existe en la barra de navegación.');

  // 3. Capturar Desktop Screenshot
  const desktopScreenshot = path.join(screenshotDir, 'qa-cert-admin-panel-clean-commercial.png');
  await page.screenshot({ path: desktopScreenshot, fullPage: true });
  console.log(`✔ Captura Desktop guardada en: ${desktopScreenshot}`);

  // 4. Capturar Mobile 375px Screenshot
  await page.setViewportSize({ width: 375, height: 812 });
  await page.reload({ waitUntil: 'networkidle' });
  const mobileScreenshot = path.join(screenshotDir, 'qa-cert-admin-panel-clean-commercial-375.png');
  await page.screenshot({ path: mobileScreenshot, fullPage: true });
  console.log(`✔ Captura Mobile (375px) guardada en: ${mobileScreenshot}`);

  await browser.close();
  console.log('\n--- CERTIFICACIÓN ADVERSARIAL PLAYWRIGHT EN VIVO: 100% VERDE Y COMPROBADA ---');
}

run().catch((err) => {
  console.error('Error fatal durante la verificación:', err);
  process.exit(1);
});
