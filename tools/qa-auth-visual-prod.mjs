import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright;
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const outDir = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/auth-qa-prod';
fs.mkdirSync(outDir, { recursive: true });

// 1. Get auth cookie from prod via SSH
const cookieOutput = execSync(`ssh -o BatchMode=yes akibara 'wp --path=/home/u888022333/domains/akibara.cl/public_html eval "\\$exp = time() + 3600; echo LOGGED_IN_COOKIE . \\":\\" . wp_generate_auth_cookie( 1, \\$exp, \\"logged_in\\" );"'`).toString().trim();

const [cookieName, cookieVal] = cookieOutput.split(':');
console.log(`Generated prod auth cookie: ${cookieName} = ${cookieVal.substring(0, 20)}...`);

async function run() {
  const browser = await chromium.launch({ headless: true });

  // --- GUEST VIEW (DESKTOP) ---
  const guestCtx = await browser.newContext({
    viewport: { width: 1280, height: 800 }
  });
  const page = await guestCtx.newPage();

  console.log('Navigating to guest /mi-cuenta/...');
  await page.goto('https://akibara.cl/mi-cuenta/', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: path.join(outDir, '01-prod-desktop-access-wall-default.png'), fullPage: true });

  // Open login accordion
  const loginSummary = page.locator('summary:has-text("Ya tengo cuenta")');
  if (await loginSummary.count() > 0) {
    await loginSummary.click();
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(outDir, '02-prod-desktop-login-accordion-open.png'), fullPage: true });
  }

  // Open register accordion
  const registerSummary = page.locator('summary:has-text("Crear cuenta")');
  if (await registerSummary.count() > 0) {
    await registerSummary.click();
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(outDir, '03-prod-desktop-register-accordion-with-birthday.png'), fullPage: true });
  }

  // Context notice
  console.log('Navigating to product redirect_to on prod...');
  await page.goto('https://akibara.cl/mi-cuenta/?redirect_to=https%3A%2F%2Fakibara.cl%2Fbakemonogatari-22-milky-way%2F', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: path.join(outDir, '04-prod-desktop-return-context-notice.png'), fullPage: true });
  await guestCtx.close();

  // --- GUEST VIEW (MOBILE) ---
  const mobileCtx = await browser.newContext({
    viewport: { width: 390, height: 844 },
    isMobile: true
  });
  const mobilePage = await mobileCtx.newPage();
  await mobilePage.goto('https://akibara.cl/mi-cuenta/', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await mobilePage.waitForTimeout(1500);
  await mobilePage.screenshot({ path: path.join(outDir, '05-prod-mobile-access-wall-default.png'), fullPage: true });

  const mobileRegisterSummary = mobilePage.locator('summary:has-text("Crear cuenta")');
  if (await mobileRegisterSummary.count() > 0) {
    await mobileRegisterSummary.click();
    await mobilePage.waitForTimeout(600);
    await mobilePage.screenshot({ path: path.join(outDir, '06-prod-mobile-register-accordion-open.png'), fullPage: true });
  }
  await mobileCtx.close();

  // --- AUTHENTICATED AS ALEJANDRO (ale.fvaras) ---
  console.log('Opening authenticated session for Alejandro...');
  const authCtx = await browser.newContext({
    viewport: { width: 1280, height: 800 }
  });
  await authCtx.addCookies([
    {
      name: cookieName,
      value: cookieVal,
      domain: 'akibara.cl',
      path: '/'
    }
  ]);

  const authPage = await authCtx.newPage();
  await authPage.goto('https://akibara.cl/mi-cuenta/', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await authPage.waitForTimeout(1500);
  await authPage.screenshot({ path: path.join(outDir, '07-prod-desktop-owner-dashboard-birthday-banner.png'), fullPage: true });

  // Mobile authenticated
  const authMobileCtx = await browser.newContext({
    viewport: { width: 390, height: 844 },
    isMobile: true
  });
  await authMobileCtx.addCookies([
    {
      name: cookieName,
      value: cookieVal,
      domain: 'akibara.cl',
      path: '/'
    }
  ]);
  const authMobilePage = await authMobileCtx.newPage();
  await authMobilePage.goto('https://akibara.cl/mi-cuenta/', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await authMobilePage.waitForTimeout(1500);
  await authMobilePage.screenshot({ path: path.join(outDir, '08-prod-mobile-owner-dashboard-birthday-banner.png'), fullPage: true });

  // Edit account page
  await authPage.goto('https://akibara.cl/mi-cuenta/edit-account/', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await authPage.waitForTimeout(1500);
  await authPage.screenshot({ path: path.join(outDir, '09-prod-desktop-owner-edit-account.png'), fullPage: true });

  await authCtx.close();
  await authMobileCtx.close();
  await browser.close();

  console.log('Production visual QA complete! Screenshots saved in:', outDir);
}

run().catch(err => {
  console.error('Error during production visual QA:', err);
  process.exit(1);
});
