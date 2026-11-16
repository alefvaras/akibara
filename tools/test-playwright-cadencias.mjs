import playwright from './akibara-e2e/node_modules/playwright/index.js';
import { execSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/fba887cb-b1fa-4be4-adc8-d7535ce75e30/screenshots/playwright-cadencia';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

function getLocalAdminCookies() {
	const php = `<?php
		$u = get_user_by('id', 1);
		$exp = time() + 7200;
		$tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
		$auth = wp_generate_auth_cookie($u->ID, $exp, 'auth', $tok);
		$logged = wp_generate_auth_cookie($u->ID, $exp, 'logged_in', $tok);
		echo json_encode([
			['name' => AUTH_COOKIE, 'value' => $auth, 'domain' => 'localhost', 'path' => '/'],
			['name' => LOGGED_IN_COOKIE, 'value' => $logged, 'domain' => 'localhost', 'path' => '/']
		]);
	`;
	const p = spawnSync(
		'docker',
		['exec', '-i', 'akibara-cron-1', 'wp', 'eval-file', '-'],
		{ input: php, encoding: 'utf8' }
	);
	if (p.status !== 0) {
		throw new Error(`Failed to get local admin cookies: ${p.stderr}`);
	}
	return JSON.parse(p.stdout.trim());
}

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

async function runPlaywrightCadenciaTests() {
	console.log('🚀 INICIANDO BATERÍA PLAYWRIGHT: CADENCIAS PREVENTA Y A PEDIDO');
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
		// BLOQUE 1: ESPEJO (LOCAL DOCKER) - PANEL DEL DUEÑO
		// =========================================================================
		console.log('--- [1/4] LOCAL: Panel de Control del Dueño (Cortes & Términos) ---');
		const localCookies = getLocalAdminCookies();
		const localAdminCtx = await browser.newContext({
			viewport: { width: 1440, height: 900 }
		});
		await localAdminCtx.addCookies(localCookies);
		const localAdminPage = await localAdminCtx.newPage();

		// 1.1 Cortes Panel en Akibara Panel -> Preventa -> Cortes
		await localAdminPage.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=preventa&subtab=cortes', {
			waitUntil: 'networkidle'
		});
		const localHasFreqTh = await localAdminPage.locator('th:has-text("Frecuencia")').count();
		assert('Local CortesPanel contiene cabecera Frecuencia', localHasFreqTh > 0);

		const localEsFreqVal = await localAdminPage.locator('#akb-cortes-frecuencia-154').inputValue();
		assert(`Local España (term 154) tiene bimestral_impar seleccionado (valor actual: ${localEsFreqVal})`, localEsFreqVal === 'bimestral_impar');

		const localArFreqVal = await localAdminPage.locator('#akb-cortes-frecuencia-98').inputValue();
		assert(`Local Argentina (term 98) tiene mensual seleccionado (valor actual: ${localArFreqVal})`, localArFreqVal === 'mensual');

		const shotLocalAdmin1 = path.join(SCREENSHOT_DIR, '01-local-admin-cortes-panel.png');
		await localAdminPage.screenshot({ path: shotLocalAdmin1, fullPage: true });
		console.log(`  📸 Captura: 01-local-admin-cortes-panel.png`);

		// 1.2 Formulario de edición de término país (España 154)
		await localAdminPage.goto('http://localhost:8080/wp-admin/term.php?taxonomy=pa_pais&tag_ID=154&post_type=product', {
			waitUntil: 'networkidle'
		});
		const localTermSelect = await localAdminPage.locator('#akb_tanda_frecuencia').inputValue();
		assert(`Local Edición Término España tiene bimestral_impar (valor: ${localTermSelect})`, localTermSelect === 'bimestral_impar');

		const shotLocalAdmin2 = path.join(SCREENSHOT_DIR, '02-local-admin-pais-term-spain.png');
		await localAdminPage.screenshot({ path: shotLocalAdmin2 });
		console.log(`  📸 Captura: 02-local-admin-pais-term-spain.png`);

		await localAdminCtx.close();

		// =========================================================================
		// BLOQUE 2: ESPEJO (LOCAL DOCKER) - CLIENTE (STOREFRONT PDP)
		// =========================================================================
		console.log('\n--- [2/4] LOCAL: Experiencia de Compra del Cliente (PDPs) ---');
		const localGuestCtx = await browser.newContext({
			viewport: { width: 1440, height: 900 }
		});
		const localGuestPage = await localGuestCtx.newPage();

		// 2.1 PDP España (El verano que Hikaru murió 8 - term 154 España)
		await localGuestPage.goto('http://localhost:8080/el-verano-que-hikaru-murio-8-milky-way/', {
			waitUntil: 'networkidle'
		});
		const localEsBanner = localGuestPage.locator('.akb-eta');
		assert('Local PDP España muestra banner akb-eta', await localEsBanner.count() > 0);

		const localEsVal = await localGuestPage.locator('.akb-eta__value').textContent();
		console.log(`  ℹ Local España ETA texto: "${localEsVal.trim()}"`);
		assert('Local España ETA fecha contiene nov/dic', /nov|dic/i.test(localEsVal));

		const localEsNota = await localGuestPage.locator('.akb-eta__nota').textContent();
		console.log(`  ℹ Local España Nota explicativa: "${localEsNota.trim()}"`);
		assert('Local España Nota menciona corte 5 de noviembre', /5 de noviembre/i.test(localEsNota));
		assert('Local España Nota menciona 4–5 semanas', /4[–-]5 semanas/i.test(localEsNota));

		const shotLocalPdp1 = path.join(SCREENSHOT_DIR, '03-local-customer-pdp-spain.png');
		await localGuestPage.screenshot({ path: shotLocalPdp1 });
		console.log(`  📸 Captura: 03-local-customer-pdp-spain.png`);

		// 2.2 PDP Argentina (Rurouni Kenshin 08 - term 98 Argentina)
		await localGuestPage.goto('http://localhost:8080/rurouni-kenshin-edicion-kanzenban-08-ivrea-argentina/', {
			waitUntil: 'networkidle'
		});
		const localArBanner = localGuestPage.locator('.akb-eta');
		assert('Local PDP Argentina muestra banner akb-eta', await localArBanner.count() > 0);

		const localArVal = await localGuestPage.locator('.akb-eta__value').textContent();
		console.log(`  ℹ Local Argentina ETA texto: "${localArVal.trim()}"`);
		assert('Local Argentina ETA fecha contiene dic', /dic/i.test(localArVal));

		const localArNota = await localGuestPage.locator('.akb-eta__nota').textContent();
		console.log(`  ℹ Local Argentina Nota explicativa: "${localArNota.trim()}"`);
		assert('Local Argentina Nota menciona corte 20 de noviembre', /20 de noviembre/i.test(localArNota));
		assert('Local Argentina Nota menciona ~3 semanas', /3 semanas/i.test(localArNota));

		const shotLocalPdp2 = path.join(SCREENSHOT_DIR, '04-local-customer-pdp-argentina.png');
		await localGuestPage.screenshot({ path: shotLocalPdp2 });
		console.log(`  📸 Captura: 04-local-customer-pdp-argentina.png`);

		await localGuestCtx.close();

		// =========================================================================
		// BLOQUE 3: PRODUCCIÓN (AKIBARA.CL) - PANEL DEL DUEÑO
		// =========================================================================
		console.log('\n--- [3/4] PRODUCCIÓN: Panel de Control del Dueño en Vivo ---');
		const prodCookies = getProdAdminCookies();
		const prodAdminCtx = await browser.newContext({
			viewport: { width: 1440, height: 900 }
		});
		await prodAdminCtx.addCookies(prodCookies);
		const prodAdminPage = await prodAdminCtx.newPage();

		// 3.1 Cortes Panel en Akibara Panel -> Preventa -> Cortes (Producción)
		await prodAdminPage.goto('https://akibara.cl/wp-admin/admin.php?page=akibara-panel&tab=preventa&subtab=cortes', {
			waitUntil: 'networkidle'
		});
		const prodHasFreqTh = await prodAdminPage.locator('th:has-text("Frecuencia")').count();
		assert('Producción CortesPanel contiene cabecera Frecuencia', prodHasFreqTh > 0);

		const prodEsFreqVal = await prodAdminPage.locator('#akb-cortes-frecuencia-154').inputValue();
		assert(`Producción España (term 154) tiene bimestral_impar seleccionado (valor: ${prodEsFreqVal})`, prodEsFreqVal === 'bimestral_impar');

		const prodArFreqVal = await prodAdminPage.locator('#akb-cortes-frecuencia-98').inputValue();
		assert(`Producción Argentina (term 98) tiene mensual seleccionado (valor: ${prodArFreqVal})`, prodArFreqVal === 'mensual');

		const shotProdAdmin1 = path.join(SCREENSHOT_DIR, '05-prod-admin-cortes-panel.png');
		await prodAdminPage.screenshot({ path: shotProdAdmin1, fullPage: true });
		console.log(`  📸 Captura: 05-prod-admin-cortes-panel.png`);

		// 3.2 Formulario de edición de término país en Producción (España 154)
		await prodAdminPage.goto('https://akibara.cl/wp-admin/term.php?taxonomy=pa_pais&tag_ID=154&post_type=product', {
			waitUntil: 'networkidle'
		});
		const prodTermSelect = await prodAdminPage.locator('#akb_tanda_frecuencia').inputValue();
		assert(`Producción Edición Término España tiene bimestral_impar (valor: ${prodTermSelect})`, prodTermSelect === 'bimestral_impar');

		const shotProdAdmin2 = path.join(SCREENSHOT_DIR, '06-prod-admin-pais-term-spain.png');
		await prodAdminPage.screenshot({ path: shotProdAdmin2 });
		console.log(`  📸 Captura: 06-prod-admin-pais-term-spain.png`);

		await prodAdminCtx.close();

		// =========================================================================
		// BLOQUE 4: PRODUCCIÓN (AKIBARA.CL) - CLIENTE (STOREFRONT PDP & CARRITO)
		// =========================================================================
		console.log('\n--- [4/4] PRODUCCIÓN: Experiencia de Compra del Cliente en Vivo ---');
		const prodGuestCtx = await browser.newContext({
			viewport: { width: 1440, height: 900 }
		});
		const prodGuestPage = await prodGuestCtx.newPage();

		// 4.1 PDP España en Vivo: Skip and Loafer 13 (Milky Way España)
		console.log('4.1 Visitando ficha Skip and Loafer 13 (España)...');
		await prodGuestPage.goto('https://akibara.cl/skip-and-loafer-13-milky-way/', {
			waitUntil: 'networkidle'
		});
		const prodEsBanner = prodGuestPage.locator('.akb-eta');
		assert('Producción PDP España muestra banner akb-eta', await prodEsBanner.count() > 0);

		const prodEsVal = await prodGuestPage.locator('.akb-eta__value').textContent();
		console.log(`  ℹ Producción España ETA: "${prodEsVal.trim()}"`);
		assert('Producción España ETA es "30 nov – 10 dic"', prodEsVal.trim().includes('30 nov – 10 dic') || prodEsVal.trim().includes('30 nov - 10 dic'));

		const prodEsNota = await prodGuestPage.locator('.akb-eta__nota').textContent();
		console.log(`  ℹ Producción España Nota: "${prodEsNota.trim()}"`);
		assert('Producción España Nota indica cierre 5 de noviembre', /cierra el 5 de noviembre/i.test(prodEsNota));
		assert('Producción España Nota indica 4–5 semanas', /4[–-]5 semanas/i.test(prodEsNota));

		const shotProdPdp1 = path.join(SCREENSHOT_DIR, '07-prod-customer-pdp-spain.png');
		await prodGuestPage.screenshot({ path: shotProdPdp1 });
		console.log(`  📸 Captura: 07-prod-customer-pdp-spain.png`);

		// 4.2 PDP Argentina en Vivo: Solanin (Ivrea Argentina)
		console.log('4.2 Visitando ficha Solanin (Argentina)...');
		await prodGuestPage.goto('https://akibara.cl/solanin-ivrea-argentina/', {
			waitUntil: 'networkidle'
		});
		const prodArBanner = prodGuestPage.locator('.akb-eta');
		assert('Producción PDP Argentina muestra banner akb-eta', await prodArBanner.count() > 0);

		const prodArVal = await prodGuestPage.locator('.akb-eta__value').textContent();
		console.log(`  ℹ Producción Argentina ETA: "${prodArVal.trim()}"`);
		assert('Producción Argentina ETA es "8 – 14 dic"', prodArVal.trim().includes('8 – 14 dic') || prodArVal.trim().includes('8 - 14 dic'));

		const prodArNota = await prodGuestPage.locator('.akb-eta__nota').textContent();
		console.log(`  ℹ Producción Argentina Nota: "${prodArNota.trim()}"`);
		assert('Producción Argentina Nota indica cierre 20 de noviembre', /cierra el 20 de noviembre/i.test(prodArNota));
		assert('Producción Argentina Nota indica ~3 semanas', /3 semanas/i.test(prodArNota));

		const shotProdPdp2 = path.join(SCREENSHOT_DIR, '08-prod-customer-pdp-argentina.png');
		await prodGuestPage.screenshot({ path: shotProdPdp2 });
		console.log(`  📸 Captura: 08-prod-customer-pdp-argentina.png`);

		// 4.3 Añadir al carrito para verificar el banner y feedback kinético sin romper el checkout
		console.log('4.3 Verificando botón de reserva y cajón del carrito en producción...');
		const addBtn = prodGuestPage.locator('button.single_add_to_cart_button');
		await addBtn.scrollIntoViewIfNeeded();
		await addBtn.click();
		await prodGuestPage.waitForTimeout(1500);

		// Abrir o verificar carrito / drawer
		const shotProdCart = path.join(SCREENSHOT_DIR, '09-prod-cart-interaction.png');
		await prodGuestPage.screenshot({ path: shotProdCart });
		console.log(`  📸 Captura: 09-prod-cart-interaction.png`);

		await prodGuestCtx.close();

		console.log('\n=============================================================');
		console.log(`🎉 BATERÍA PLAYWRIGHT FINALIZADA: ${passCount} PASADAS, ${failCount} FALLIDAS`);
		console.log('=============================================================');

		if (failCount > 0) {
			process.exit(1);
		}
	} finally {
		await browser.close();
	}
}

runPlaywrightCadenciaTests().catch((err) => {
	console.error('Fatal Playwright Error:', err);
	process.exit(1);
});
