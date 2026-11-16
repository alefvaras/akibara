import { chromium } from './akibara-e2e/node_modules/playwright/index.mjs';
import fs from 'node:fs';
import path from 'node:path';

const TARGET_URL = process.env.AKB_TEST_URL || 'https://akibara.cl';
const SCREENSHOT_DIR = path.resolve('/Users/alefvaras/.gemini/antigravity/brain/16292e4b-646c-4752-83e8-fd2278327e63/screenshots/mobile-audit');

if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

const VIEWPORTS = [
  { name: 'Android_360', width: 360, height: 780, desc: 'Samsung Galaxy S20 / A-series (360px)' },
  { name: 'iPhone_SE_375', width: 375, height: 667, desc: 'iPhone SE / iPhone 8 (375px)' },
  { name: 'iPhone_14_390', width: 390, height: 844, desc: 'iPhone 12/13/14/15 standard (390px)' },
  { name: 'Pixel_412', width: 412, height: 915, desc: 'Google Pixel 7 / Galaxy S22 Ultra (412px)' }
];

async function auditMobile() {
  console.log(`\n======================================================`);
  console.log(`AUDITORÍA PROFUNDA MOBILE: RESPONSIVE & CRO MATRIX`);
  console.log(`Target: ${TARGET_URL}`);
  console.log(`======================================================\n`);

  const executablePath = process.env.AKB_CHROMIUM || `${process.env.HOME}/Library/Caches/ms-playwright/chromium_headless_shell-1237/chrome-headless-shell-mac-arm64/chrome-headless-shell`;
  const browser = await chromium.launch({
    executablePath,
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const matrixResults = [];

  for (const vp of VIEWPORTS) {
    console.log(`\n▶ [${vp.name}] Testeando viewport: ${vp.width}x${vp.height} (${vp.desc})`);
    const context = await browser.newContext({
      viewport: { width: vp.width, height: vp.height },
      hasTouch: true,
      isMobile: true,
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 AkibaraQA/mobile'
    });

    const page = await context.newPage();

    try {
      // 1. Chainsaw Man 06 (PDP con 23 tomos en constructor)
      await page.goto(`${TARGET_URL}/chainsaw-man-6-ivrea-argentina/`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1000);

      // Desplazamiento hacia el constructor de packs
      await page.evaluate(() => window.scrollBy(0, 1800));
      await page.waitForTimeout(600);

      // Medir estado individual
      const mIndividual = await page.evaluate(() => {
        const bar = document.getElementById('akb-sticky-atc');
        const nav = document.getElementById('bottom-nav');
        const joinchat = document.querySelector('.joinchat');
        const builderAddBtn = document.querySelector('[data-akb-sb-builder] [data-akb-sb-add]');
        const barRect = bar ? bar.getBoundingClientRect() : null;
        const navRect = nav ? nav.getBoundingClientRect() : null;
        const jcRect = joinchat ? joinchat.getBoundingClientRect() : null;

        return {
          hasHorizontalOverflow: document.documentElement.scrollWidth > window.innerWidth,
          barVisible: bar ? bar.classList.contains('is-visible') : false,
          barHeight: barRect ? barRect.height : 0,
          barBottom: bar ? getComputedStyle(bar).bottom : '',
          navHeight: navRect ? navRect.height : 0,
          joinchatBottom: joinchat ? getComputedStyle(joinchat).bottom : '',
          builderAddHiddenInMobile: builderAddBtn ? getComputedStyle(builderAddBtn).display === 'none' : true
        };
      });

      console.log(`  - Modo Individual medido:`, mIndividual);
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, `${vp.name}-1-individual.png`) });

      // 2. Marcar 5 tomos en el pack (desbloquear 8% dto)
      await page.evaluate(() => {
        const checks = Array.from(document.querySelectorAll('[data-akb-sb-vol]'))
          .filter(c => !c.disabled && c.getAttribute('data-akb-sb-preventa') !== '1');
        for (let i = 0; i < 5 && i < checks.length; i++) {
          checks[i].checked = true;
        }
        if (checks.length > 0) {
          checks[0].dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
      await page.waitForTimeout(600);

      const mPack5 = await page.evaluate(() => {
        const bar = document.getElementById('akb-sticky-atc');
        const btn = document.getElementById('akb-sticky-atc-btn');
        const resetBtn = document.getElementById('akb-sticky-atc-reset');
        const badgeEl = document.getElementById('akb-sticky-atc-badge');
        const subtextEl = document.getElementById('akb-sticky-atc-subtext');
        const progressEl = document.getElementById('akb-sticky-atc-progress');
        const joinchat = document.querySelector('.joinchat');
        const barRect = bar ? bar.getBoundingClientRect() : null;
        const jcRect = joinchat ? joinchat.getBoundingClientRect() : null;
        const btnRect = btn ? btn.getBoundingClientRect() : null;

        // Verificar colisión entre Joinchat y el botón de compra
        let collision = false;
        if (jcRect && btnRect) {
          collision = !(
            jcRect.right < btnRect.left ||
            jcRect.left > btnRect.right ||
            jcRect.bottom < btnRect.top ||
            jcRect.top > btnRect.bottom
          );
        }

        return {
          isPackMode: bar ? bar.classList.contains('is-pack-mode') : false,
          barHeight: barRect ? barRect.height : 0,
          btnText: btn ? btn.textContent.trim() : '',
          badgeText: badgeEl ? badgeEl.textContent.trim() : '',
          subtext: subtextEl ? subtextEl.textContent.trim() : '',
          progressWidth: progressEl ? progressEl.style.width : '',
          progressColor: progressEl ? progressEl.style.backgroundColor : '',
          joinchatBottom: joinchat ? getComputedStyle(joinchat).bottom : '',
          joinchatCollidesWithBtn: collision,
          resetVisible: resetBtn ? !resetBtn.hidden : false,
          hasHorizontalOverflow: document.documentElement.scrollWidth > window.innerWidth
        };
      });

      console.log(`  - Pack 5 tomos medido:`, mPack5);
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, `${vp.name}-2-pack5.png`) });

      // 3. Probar clic en Reset [✕]
      await page.evaluate(() => {
        const reset = document.getElementById('akb-sticky-atc-reset');
        if (reset) reset.click();
      });
      await page.waitForTimeout(500);

      const mReset = await page.evaluate(() => {
        const bar = document.getElementById('akb-sticky-atc');
        const reset = document.getElementById('akb-sticky-atc-reset');
        const checks = document.querySelectorAll('[data-akb-sb-vol]:checked').length;
        const btn = document.getElementById('akb-sticky-atc-btn');
        return {
          checkedCount: checks,
          isPackMode: bar ? bar.classList.contains('is-pack-mode') : false,
          resetHidden: reset ? reset.hidden : true,
          btnText: btn ? btn.textContent.trim() : ''
        };
      });

      console.log(`  - Reset tras clic:`, mReset);
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, `${vp.name}-3-reset.png`) });

      // 4. Serie Larga: One Piece 109 (110 tomos)
      await page.goto(`${TARGET_URL}/one-piece-109-ivrea-argentina/`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1000);
      await page.evaluate(() => window.scrollBy(0, 1800));
      await page.waitForTimeout(600);

      // Selección en One Piece
      await page.evaluate(() => {
        const checks = Array.from(document.querySelectorAll('[data-akb-sb-vol]')).filter(c => !c.disabled);
        for (let i = 0; i < 3 && i < checks.length; i++) checks[i].checked = true;
        if (checks.length > 0) checks[0].dispatchEvent(new Event('change', { bubbles: true }));
      });
      await page.waitForTimeout(500);

      const mOnePiece = await page.evaluate(() => {
        const bar = document.getElementById('akb-sticky-atc');
        const btn = document.getElementById('akb-sticky-atc-btn');
        const rangeWrap = document.querySelector('.akb-sb-builder__tools, [data-akb-sb-tools]');
        const barRect = bar ? bar.getBoundingClientRect() : null;

        return {
          barHeight: barRect ? barRect.height : 0,
          isPackMode: bar ? bar.classList.contains('is-pack-mode') : false,
          btnText: btn ? btn.textContent.trim() : '',
          hasHorizontalOverflow: document.documentElement.scrollWidth > window.innerWidth,
          hasRangeTools: !!rangeWrap
        };
      });

      console.log(`  - One Piece medido:`, mOnePiece);
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, `${vp.name}-4-onepiece.png`) });

      // Evaluaciones de pase por viewport
      const heightOk = mPack5.barHeight > 0 && mPack5.barHeight <= 64;
      const noOverflow = !mIndividual.hasHorizontalOverflow && !mPack5.hasHorizontalOverflow && !mOnePiece.hasHorizontalOverflow;
      const noCollision = !mPack5.joinchatCollidesWithBtn;
      const builderBtnHidden = mIndividual.builderAddHiddenInMobile;
      const resetOk = mReset.checkedCount === 0 && !mReset.isPackMode;
      const onePieceOk = mOnePiece.isPackMode && !mOnePiece.hasHorizontalOverflow;

      const vpPass = heightOk && noOverflow && noCollision && builderBtnHidden && resetOk && onePieceOk;

      matrixResults.push({
        viewport: vp.name,
        desc: vp.desc,
        width: vp.width,
        height: vp.height,
        barHeight: mPack5.barHeight,
        heightOk,
        noOverflow,
        noCollision,
        builderBtnHidden,
        resetOk,
        onePieceOk,
        pass: vpPass
      });

    } catch (e) {
      console.error(`Error en viewport ${vp.name}:`, e);
      matrixResults.push({
        viewport: vp.name,
        desc: vp.desc,
        pass: false,
        error: e.message
      });
    } finally {
      await context.close();
    }
  }

  await browser.close();

  console.log(`\n======================================================`);
  console.log(`RESUMEN DE AUDITORÍA MOBILE EN PRODUCCIÓN VIVA`);
  console.log(`======================================================`);
  let passedCount = 0;
  for (const r of matrixResults) {
    console.log(`[${r.pass ? 'PASS' : 'FAIL'}] ${r.viewport} (${r.desc})`);
    console.log(`    - Alto barra: ${r.barHeight}px (Límite <= 64px: ${r.heightOk ? 'OK' : 'FAIL'})`);
    console.log(`    - Cero desborde horizontal: ${r.noOverflow ? 'OK' : 'FAIL'}`);
    console.log(`    - Cero colisión Joinchat WhatsApp: ${r.noCollision ? 'OK' : 'FAIL'}`);
    console.log(`    - Botón duplicado oculto en móvil: ${r.builderBtnHidden ? 'OK' : 'FAIL'}`);
    console.log(`    - Reset [✕] funcional: ${r.resetOk ? 'OK' : 'FAIL'}`);
    console.log(`    - One Piece serie larga: ${r.onePieceOk ? 'OK' : 'FAIL'}`);
    if (r.pass) passedCount++;
  }

  console.log(`\nTotal: ${passedCount}/${matrixResults.length} viewports aprobados.`);
  if (passedCount !== matrixResults.length) {
    process.exit(1);
  }
}

auditMobile();
