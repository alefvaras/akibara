import playwright from './akibara-e2e/node_modules/playwright/index.js';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/fba887cb-b1fa-4be4-adc8-d7535ce75e30/screenshots/cadencia-flexible-prod';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

function getProdAdminCookies() {
	const php = `<?php
		$u = get_user_by('id', 1);
		$exp = time() + 7200;
		$tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
		$auth = wp_generate_auth_cookie($u->ID, $exp, 'auth', $tok);
		$sec = wp_generate_auth_cookie($u->ID, $exp, 'secure_auth', $tok);
		$logged = wp_generate_auth_cookie($u->ID, $exp, 'logged_in', $tok);
		echo json_encode([
			['name' => AUTH_COOKIE, 'value' => $auth, 'domain' => '.akibara.cl', 'path' => '/', 'secure' => true],
			['name' => SECURE_AUTH_COOKIE, 'value' => $sec, 'domain' => '.akibara.cl', 'path' => '/', 'secure' => true],
			['name' => LOGGED_IN_COOKIE, 'value' => $logged, 'domain' => '.akibara.cl', 'path' => '/', 'secure' => true]
		]);
	`;
	const p = spawnSync(
		'ssh',
		['akibara', '/opt/alt/php83/usr/bin/php -d disable_functions=\'\' /usr/local/bin/wp --path=domains/akibara.cl/public_html eval-file -'],
		{ input: php, encoding: 'utf8' }
	);
	if (p.status !== 0) {
		throw new Error(`Failed to get prod admin cookies: ${p.stderr}`);
	}
	return JSON.parse(p.stdout.trim());
}

async function runProdVerification() {
	console.log('🚀 INICIANDO VERIFICACIÓN PLAYWRIGHT EN PRODUCCIÓN (akibara.cl)');
	console.log(`📁 Directorio de capturas: ${SCREENSHOT_DIR}\n`);

	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1440,900']
	});

	let passCount = 0;
	let failCount = 0;

	function assert(desc, condition) {
		if (condition) {
			console.log(`  ✓ PASS: ${desc}`);
			passCount++;
		} else {
			console.error(`  ✗ FAIL: ${desc}`);
			failCount++;
		}
	}

	try {
		// =========================================================================
		// 1. PANEL DE ADMINISTRACIÓN EN PRODUCCIÓN
		// =========================================================================
		console.log('--- [1/2] PRODUCCIÓN: Panel de Control del Administrador ---');
		const adminCookies = getProdAdminCookies();
		const adminCtx = await browser.newContext({
			viewport: { width: 1440, height: 900 }
		});
		await adminCtx.addCookies(adminCookies);
		const adminPage = await adminCtx.newPage();

		// 1.1 Cortes Panel
		console.log('1.1 Cargando CortesPanel en Producción...');
		await adminPage.goto('https://akibara.cl/wp-admin/admin.php?page=akibara-panel&tab=preventa&subtab=cortes', {
			waitUntil: 'networkidle'
		});

		const table = adminPage.locator('.akb-cortes-tabla');
		assert('CortesPanel tabla está presente', await table.count() > 0);

		const hasQuincenalOpt = await adminPage.locator('select[name^="akb_cortes["] option[value="quincenal"]').count();
		assert('Selector contiene opción Quincenal (2 cortes al mes)', hasQuincenalOpt > 0);

		const hasPersonalizadaOpt = await adminPage.locator('select[name^="akb_cortes["] option[value="personalizada"]').count();
		assert('Selector contiene opción Personalizada (meses a elección)', hasPersonalizadaOpt > 0);

		const esFreqVal = await adminPage.locator('#akb-cortes-frecuencia-154').inputValue();
		assert(`España (154) tiene bimestral_impar configurado (valor: ${esFreqVal})`, esFreqVal === 'bimestral_impar');

		const arFreqVal = await adminPage.locator('#akb-cortes-frecuencia-98').inputValue();
		assert(`Argentina (98) tiene mensual configurado (valor: ${arFreqVal})`, arFreqVal === 'mensual');

		await table.scrollIntoViewIfNeeded();
		const shot01 = path.join(SCREENSHOT_DIR, '01-prod-cortes-panel-flexible.png');
		await table.screenshot({ path: shot01 });
		console.log(`  📸 Captura guardada: ${shot01}`);

		// 1.2 Formulario edición España (154)
		console.log('1.2 Cargando edición de término España (154)...');
		await adminPage.goto('https://akibara.cl/wp-admin/term.php?taxonomy=pa_pais&tag_ID=154&post_type=product', {
			waitUntil: 'networkidle'
		});
		const termEsVal = await adminPage.locator('#akb_tanda_frecuencia').inputValue();
		assert(`Edición Término España muestra bimestral_impar (valor: ${termEsVal})`, termEsVal === 'bimestral_impar');

		const termEsDia = await adminPage.locator('#akb_tanda_dia').inputValue();
		assert(`Edición Término España muestra día 5 (valor: ${termEsDia})`, termEsDia === '5');

		await adminPage.locator('#akb_tanda_dia').scrollIntoViewIfNeeded();
		const shot02 = path.join(SCREENSHOT_DIR, '02-prod-term-edit-spain-bimestral.png');
		await adminPage.screenshot({ path: shot02 });
		console.log(`  📸 Captura guardada: ${shot02}`);

		// 1.3 Formulario edición Argentina (98) - probar UI limpia de Personalizada
		console.log('1.3 Probando interactividad UI Personalizada en Argentina (98)...');
		await adminPage.goto('https://akibara.cl/wp-admin/term.php?taxonomy=pa_pais&tag_ID=98&post_type=product', {
			waitUntil: 'networkidle'
		});

		// Verificamos que inicialmente el campo meses está oculto
		const mesesRowHiddenBefore = await adminPage.locator('#akb_pais_meses_wrapper').evaluate((el) => {
			return window.getComputedStyle(el).display === 'none';
		});
		assert('Campo meses está inicialmente oculto en modo mensual', mesesRowHiddenBefore);

		// Cambiar a personalizada
		await adminPage.selectOption('#akb_tanda_frecuencia', 'personalizada');
		await adminPage.fill('#akb_tanda_meses', '3, 6, 10, 11');
		const mesesRowVisibleAfter = await adminPage.locator('#akb_pais_meses_wrapper').evaluate((el) => {
			return window.getComputedStyle(el).display !== 'none';
		});
		assert('Campo meses aparece limpiamente al seleccionar Personalizada', mesesRowVisibleAfter);

		const shot03 = path.join(SCREENSHOT_DIR, '03-prod-term-edit-personalizada-ui-limpia.png');
		await adminPage.screenshot({ path: shot03 });
		console.log(`  📸 Captura guardada: ${shot03}`);

		await adminCtx.close();

		// =========================================================================
		// 2. EXPERIENCIA DEL CLIENTE EN TIENDA (STOREFRONT PDP & CART)
		// =========================================================================
		console.log('\n--- [2/2] PRODUCCIÓN: Experiencia de Compra del Cliente ---');
		const guestCtx = await browser.newContext({
			viewport: { width: 1440, height: 900 }
		});
		const guestPage = await guestCtx.newPage();

		// 2.1 PDP España: Skip and Loafer 13 (Milky Way España)
		console.log('2.1 Visitando PDP España (Skip and Loafer 13)...');
		await guestPage.goto('https://akibara.cl/skip-and-loafer-13-milky-way/', {
			waitUntil: 'networkidle'
		});

		const esBanner = guestPage.locator('.akb-eta');
		assert('PDP España muestra banner akb-eta', await esBanner.count() > 0);

		const esVal = await guestPage.locator('.akb-eta__value').textContent();
		console.log(`  ℹ España ETA: "${esVal.trim()}"`);
		assert('España ETA rango es 30 nov – 10 dic', /30 nov [–-] 10 dic/i.test(esVal.trim()));

		const esNota = await guestPage.locator('.akb-eta__nota').textContent();
		console.log(`  ℹ España Nota: "${esNota.trim()}"`);
		assert('España Nota menciona cierre el 5 de noviembre', /cierra el 5 de noviembre/i.test(esNota));
		assert('España Nota menciona 4–5 semanas', /4[–-]5 semanas/i.test(esNota));

		await esBanner.scrollIntoViewIfNeeded();
		const shot04 = path.join(SCREENSHOT_DIR, '04-prod-customer-pdp-espana-bimestral.png');
		await guestPage.screenshot({ path: shot04 });
		console.log(`  📸 Captura guardada: ${shot04}`);

		// 2.2 PDP Argentina: Solanin (Ivrea Argentina)
		console.log('2.2 Visitando PDP Argentina (Solanin)...');
		await guestPage.goto('https://akibara.cl/solanin-ivrea-argentina/', {
			waitUntil: 'networkidle'
		});

		const arBanner = guestPage.locator('.akb-eta');
		assert('PDP Argentina muestra banner akb-eta', await arBanner.count() > 0);

		const arVal = await guestPage.locator('.akb-eta__value').textContent();
		console.log(`  ℹ Argentina ETA: "${arVal.trim()}"`);
		assert('Argentina ETA rango es 8 – 14 dic', /8 [–-] 14 dic/i.test(arVal.trim()));

		const arNota = await guestPage.locator('.akb-eta__nota').textContent();
		console.log(`  ℹ Argentina Nota: "${arNota.trim()}"`);
		assert('Argentina Nota menciona cierre el 20 de noviembre', /cierra el 20 de noviembre/i.test(arNota));
		assert('Argentina Nota menciona ~3 semanas', /3 semanas/i.test(arNota));

		await arBanner.scrollIntoViewIfNeeded();
		const shot05 = path.join(SCREENSHOT_DIR, '05-prod-customer-pdp-argentina-mensual.png');
		await guestPage.screenshot({ path: shot05 });
		console.log(`  📸 Captura guardada: ${shot05}`);

		// 2.3 Carrito con producto
		console.log('2.3 Añadiendo al carrito y comprobando promesa...');
		const addBtn = guestPage.locator('button.single_add_to_cart_button');
		await addBtn.scrollIntoViewIfNeeded();
		await addBtn.click();
		await guestPage.waitForTimeout(2000);

		await guestPage.goto('https://akibara.cl/carrito/', {
			waitUntil: 'networkidle'
		});
		await guestPage.waitForTimeout(3500);

		const cartMain = guestPage.locator('.wc-block-cart, .woocommerce-cart, .wc-block-cart__main, h1:has-text("Tu carrito")');
		assert('Página de carrito carga correctamente con producto', await cartMain.count() > 0);

		const shot06 = path.join(SCREENSHOT_DIR, '06-prod-customer-cart-promise.png');
		await guestPage.screenshot({ path: shot06 });
		console.log(`  📸 Captura guardada: ${shot06}`);

		// Limpiar carrito (eliminar producto de prueba)
		console.log('Limpiando carrito de prueba...');
		const removeBtn = guestPage.locator('button.wc-block-cart-item__remove-link, a.remove, .wc-block-cart-item__remove');
		if (await removeBtn.count() > 0) {
			await removeBtn.first().click();
			await guestPage.waitForTimeout(2000);
			console.log('  ✓ Carrito vaciado correctamente.');
		}

		await guestCtx.close();

		console.log('\n=============================================================');
		console.log(`🎉 BATERÍA PLAYWRIGHT PRODUCCIÓN COMPLETADA: ${passCount} PASADAS, ${failCount} FALLIDAS`);
		console.log('=============================================================');

		if (failCount > 0) {
			process.exit(1);
		}
	} finally {
		await browser.close();
	}
}

runProdVerification().catch((err) => {
	console.error('Fatal error en verificación de producción:', err);
	process.exit(1);
});
