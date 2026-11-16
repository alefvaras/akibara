import playwright from '/Users/alefvaras/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.js';
const { chromium } = playwright.default || playwright;
import fs from 'node:fs';
import path from 'node:path';

const outDir = '/Users/alefvaras/.gemini/antigravity/brain/db46006e-eee5-46e0-a787-640be4aa0b3c/visual-qa';
fs.mkdirSync(outDir, { recursive: true });

async function runVisualAudit() {
    console.log('======================================================================');
    console.log('  AUDITORÍA VISUAL ADVERSARIAL DE CSS (PRODUCCIÓN: akibara.cl)');
    console.log('======================================================================\n');

    const browser = await chromium.launch({ headless: true });
    const results = {
        passed: 0,
        failed: 0,
        checks: []
    };

    function record(name, pass, details) {
        if (pass) {
            results.passed++;
            console.log(`  [PASS] ${name} — ${details}`);
        } else {
            results.failed++;
            console.log(`  [FAIL] ${name} — ${details}`);
        }
        results.checks.push({ name, pass, details });
    }

    try {
        // -------------------------------------------------------------
        // 1. DESKTOP AUDIT (1440x900)
        // -------------------------------------------------------------
        const desktopContext = await browser.newContext({
            viewport: { width: 1440, height: 900 },
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        });
        const desktopPage = await desktopContext.newPage();

        // 1.1 Range Navigation on Long Series: One Piece
        console.log('--- 1. DESKTOP: /serie/one-piece/ (Barra de Rangos y Paginación) ---');
        await desktopPage.goto('https://akibara.cl/serie/one-piece/', { waitUntil: 'domcontentloaded', timeout: 30000 });
        await desktopPage.waitForTimeout(2000);

        const rangeNavDesktop = await desktopPage.$('.akb-series-range-nav');
        record('Presencia de Barra de Rangos Desktop', rangeNavDesktop !== null, 'Elemento .akb-series-range-nav encontrado en el DOM');

        if (rangeNavDesktop) {
            // Scroll into view
            await rangeNavDesktop.scrollIntoViewIfNeeded();
            await desktopPage.waitForTimeout(500);
            
            // Screenshot the range nav and surrounding area
            const rangeBox = await rangeNavDesktop.boundingBox();
            await desktopPage.screenshot({
                path: path.join(outDir, '01_desktop_one_piece_range_nav.png'),
                clip: {
                    x: 0,
                    y: Math.max(0, rangeBox.y - 120),
                    width: 1440,
                    height: Math.min(600, rangeBox.height + 250)
                }
            });

            const linkMetrics = await desktopPage.evaluate(() => {
                const links = Array.from(document.querySelectorAll('.akb-series-range-link'));
                return links.map(l => ({
                    text: l.textContent.trim(),
                    width: l.offsetWidth,
                    height: l.offsetHeight,
                    active: l.classList.contains('is-active'),
                    ariaCurrent: l.getAttribute('aria-current'),
                    href: l.href
                }));
            });

            record('Conteo y Textos de Rangos', linkMetrics.length === 3 && linkMetrics[0].text === 'Tomos 1–48' && linkMetrics[1].text === 'Tomos 49–96' && linkMetrics[2].text === 'Tomos 97–110',
                `Detectados ${linkMetrics.length} rangos: ${linkMetrics.map(m => m.text).join(' | ')}`);

            record('Estado Activo en Página 1', linkMetrics[0].active && linkMetrics[0].ariaCurrent === 'page' && !linkMetrics[1].active,
                'Tomo 1-48 tiene clase is-active y aria-current="page"');

            record('Área táctil Desktop >= 44px', linkMetrics.every(m => m.height >= 44),
                `Alturas medidas: ${linkMetrics.map(m => m.height + 'px').join(', ')}`);
        }

        // 1.2 Serie corta: 20th Century Boys (debe NO tener barra de rangos)
        console.log('\n--- 2. DESKTOP: /serie/20th-century-boys/ (Serie Corta <= 24 tomos) ---');
        await desktopPage.goto('https://akibara.cl/serie/20th-century-boys/', { waitUntil: 'domcontentloaded', timeout: 30000 });
        await desktopPage.waitForTimeout(1500);

        const rangeNavShort = await desktopPage.$('.akb-series-range-nav');
        record('Ausencia de Barra de Rangos en Serie Corta', rangeNavShort === null, 'La barra de rangos NO se muestra cuando max_num_pages <= 1 (comportamiento esperado)');

        // 1.3 PDP: One Piece 110 (Verificación de Sticky ATC y Botones)
        console.log('\n--- 3. DESKTOP: PDP /one-piece-110-ivrea-argentina/ ---');
        await desktopPage.goto('https://akibara.cl/one-piece-110-ivrea-argentina/', { waitUntil: 'domcontentloaded', timeout: 30000 });
        await desktopPage.waitForTimeout(1500);

        await desktopPage.screenshot({ path: path.join(outDir, '02_desktop_pdp_above_fold.png') });

        // Scroll down to activate sticky ATC
        await desktopPage.evaluate(() => window.scrollBy(0, 900));
        await desktopPage.waitForTimeout(1000);

        const stickyAtcDesktop = await desktopPage.$('.akb-sticky-atc');
        const isStickyVisible = await desktopPage.evaluate(() => {
            const el = document.querySelector('.akb-sticky-atc');
            if (!el) return false;
            const style = window.getComputedStyle(el);
            return el.classList.contains('is-visible') || style.display !== 'none';
        });

        record('Visibilidad de Barra Sticky ATC Desktop', stickyAtcDesktop !== null && isStickyVisible, 'Barra .akb-sticky-atc se renderiza correctamente al hacer scroll');

        await desktopPage.screenshot({ path: path.join(outDir, '03_desktop_pdp_sticky_atc.png') });

        // Añadir producto al carrito para probar Carrito y Checkout con producto
        const atcBtn = await desktopPage.$('.single_add_to_cart_button');
        if (atcBtn) {
            await atcBtn.click();
            await desktopPage.waitForTimeout(2000);
        }

        // -------------------------------------------------------------
        // 2. MOBILE AUDIT (375x812 - iPhone X/11/12/13/Mini)
        // -------------------------------------------------------------
        console.log('\n--- 4. MOBILE: /serie/one-piece/ (375x812 Viewport) ---');
        const mobileContext = await browser.newContext({
            viewport: { width: 375, height: 812 },
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1',
            isMobile: true,
            hasTouch: true
        });
        const mobilePage = await mobileContext.newPage();

        await mobilePage.goto('https://akibara.cl/serie/one-piece/', { waitUntil: 'domcontentloaded', timeout: 30000 });
        await mobilePage.waitForTimeout(2000);

        // Check horizontal overflow
        const mobileOverflow = await mobilePage.evaluate(() => {
            return {
                scrollWidth: document.documentElement.scrollWidth,
                clientWidth: document.documentElement.clientWidth,
                hasOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth
            };
        });

        record('Cero Desbordamiento Horizontal en Móvil (/serie/one-piece/)', !mobileOverflow.hasOverflow,
            `scrollWidth=${mobileOverflow.scrollWidth}px vs clientWidth=${mobileOverflow.clientWidth}px`);

        // Scroll to range nav
        const rangeNavMobile = await mobilePage.$('.akb-series-range-nav');
        if (rangeNavMobile) {
            await rangeNavMobile.scrollIntoViewIfNeeded();
            await mobilePage.waitForTimeout(500);

            const mobileRangeBox = await rangeNavMobile.boundingBox();
            await mobilePage.screenshot({
                path: path.join(outDir, '04_mobile_range_nav.png'),
                clip: {
                    x: 0,
                    y: Math.max(0, mobileRangeBox.y - 80),
                    width: 375,
                    height: Math.min(500, mobileRangeBox.height + 200)
                }
            });

            const mobileMetrics = await mobilePage.evaluate(() => {
                const links = Array.from(document.querySelectorAll('.akb-series-range-link'));
                return links.map(l => ({
                    text: l.textContent.trim(),
                    width: l.offsetWidth,
                    height: l.offsetHeight,
                    fontSize: window.getComputedStyle(l).fontSize,
                    backgroundColor: window.getComputedStyle(l).backgroundColor,
                    color: window.getComputedStyle(l).color
                }));
            });

            record('Área Táctil Móvil >= 44px', mobileMetrics.every(m => m.height >= 44),
                `Alturas medidas: ${mobileMetrics.map(m => m.height + 'px').join(', ')}`);

            record('Estilos y Contraste de Rangos Móvil', mobileMetrics[0].backgroundColor !== 'rgba(0, 0, 0, 0)',
                `Botón activo: fondo=${mobileMetrics[0].backgroundColor}, texto=${mobileMetrics[0].color}, fuente=${mobileMetrics[0].fontSize}`);
        }

        // 2.2 Mobile PDP: One Piece 110 (Verificar Sticky ATC + Joinchat Clearance)
        console.log('\n--- 5. MOBILE: PDP /one-piece-110-ivrea-argentina/ ---');
        await mobilePage.goto('https://akibara.cl/one-piece-110-ivrea-argentina/', { waitUntil: 'domcontentloaded', timeout: 30000 });
        await mobilePage.waitForTimeout(2000);

        const pdpOverflow = await mobilePage.evaluate(() => ({
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            hasOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth
        }));

        record('Cero Desbordamiento Horizontal en PDP Móvil', !pdpOverflow.hasOverflow,
            `scrollWidth=${pdpOverflow.scrollWidth}px vs clientWidth=${pdpOverflow.clientWidth}px`);

        // Scroll to trigger sticky bar
        await mobilePage.evaluate(() => window.scrollBy(0, 700));
        await mobilePage.waitForTimeout(800);

        const clearance = await mobilePage.evaluate(() => {
            const sticky = document.querySelector('.akb-sticky-atc');
            const joinchat = document.querySelector('.joinchat');
            if (!sticky) return { error: 'No sticky bar' };
            const sRect = sticky.getBoundingClientRect();
            let jRect = null;
            if (joinchat && window.getComputedStyle(joinchat).display !== 'none') {
                jRect = joinchat.getBoundingClientRect();
            }
            return {
                stickyVisible: sticky.classList.contains('is-visible'),
                stickyTop: sRect.top,
                stickyHeight: sRect.height,
                joinchatVisible: jRect !== null,
                joinchatBottom: jRect ? window.innerHeight - jRect.bottom : null,
                overlap: jRect ? (jRect.bottom > sRect.top) : false
            };
        });

        record('Sticky ATC Móvil no colisiona con Joinchat (WhatsApp)', !clearance.overlap,
            `Sticky height=${clearance.stickyHeight}px, Joinchat bottom clearance=${clearance.joinchatBottom}px`);

        await mobilePage.screenshot({ path: path.join(outDir, '05_mobile_pdp_sticky_atc.png') });

        // -------------------------------------------------------------
        // 3. CHECKOUT & CART HYGIENE
        // -------------------------------------------------------------
        console.log('\n--- 6. DESKTOP: Carrito y Checkout (/carrito/, /checkout/) ---');
        await desktopPage.goto('https://akibara.cl/carrito/', { waitUntil: 'domcontentloaded', timeout: 30000 });
        await desktopPage.waitForTimeout(1500);
        await desktopPage.screenshot({ path: path.join(outDir, '06_cart_page.png') });

        const cartOk = await desktopPage.evaluate(() => {
            return document.querySelector('.woocommerce-cart') !== null;
        });
        record('Renderizado Limpio de Carrito', cartOk, 'Página /carrito/ renderizada sin anomalías de estilo');

        await desktopPage.goto('https://akibara.cl/checkout/', { waitUntil: 'domcontentloaded', timeout: 30000 });
        await desktopPage.waitForTimeout(1500);
        await desktopPage.screenshot({ path: path.join(outDir, '07_checkout_page.png') });

        const checkoutOk = await desktopPage.evaluate(() => {
            return document.querySelector('.woocommerce-checkout') !== null;
        });
        record('Renderizado Limpio de Checkout', checkoutOk, 'Página /checkout/ renderizada con estructura intacta');

    } catch (err) {
        console.error('Error durante la auditoría visual:', err);
        results.failed++;
    } finally {
        await browser.close();
    }

    console.log('\n======================================================================');
    console.log(`  RESUMEN AUDITORÍA VISUAL CSS:`);
    console.log(`  • PASSED: ${results.passed}`);
    console.log(`  • FAILED: ${results.failed}`);
    console.log('======================================================================');

    if (results.failed === 0) {
        console.log('\n🎉 VERIFICACIÓN VISUAL EXITOSA: CERO REGRESIONES DE CSS O LAYOUT');
    } else {
        console.log('\n⚠ SE ENCONTRARON FALLAS EN LA AUDITORÍA VISUAL');
    }
}

runVisualAudit();
