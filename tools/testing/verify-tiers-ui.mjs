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

  // Generar cookie de sesión autenticada para Tanjiro (ID 521)
  const authRaw = exec(`docker exec akibara-cron-1 wp eval '
      $u = get_user_by("id", 521);
      $exp = time() + 7200;
      $tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
      echo json_encode([
          "name" => LOGGED_IN_COOKIE,
          "value" => wp_generate_auth_cookie($u->ID, $exp, "logged_in", $tok),
          "domain" => "localhost",
          "path" => "/"
      ]);
  '`, { encoding: 'utf8' });

  const authCookie = JSON.parse(authRaw);
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 }
  });
  await context.addCookies([authCookie]);

  const page = await context.newPage();

  console.log('Navegando a Mi Cuenta > Puntos Rewards: http://localhost:8080/mi-cuenta/rewards/');
  await page.goto('http://localhost:8080/mi-cuenta/rewards/', { waitUntil: 'networkidle' });

  const tierCard = page.locator('.akb-rw-tier-card');
  if (await tierCard.count() > 0) {
    console.log('Tarjeta de nivel encontrada en Mi Cuenta.');
    await tierCard.scrollIntoViewIfNeeded();
  }

  await page.screenshot({
    path: `${screenshotDir}/tier-02-mi-cuenta-progreso-nivel.png`,
    fullPage: false
  });
  console.log('Captura guardada: tier-02-mi-cuenta-progreso-nivel.png');

  await browser.close();
}

run().catch((err) => {
  console.error(err);
  process.exit(1);
});
