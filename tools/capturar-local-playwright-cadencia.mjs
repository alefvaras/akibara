import playwright from './akibara-e2e/node_modules/playwright/index.js';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/fba887cb-b1fa-4be4-adc8-d7535ce75e30/screenshots/cadencia-flexible';
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

async function capture() {
	console.log('📸 Iniciando captura de evidencias visuales en LOCAL...');

	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1440,900']
	});

	const adminCookies = getLocalAdminCookies();
	const adminCtx = await browser.newContext({
		viewport: { width: 1920, height: 1080 }
	});
	await adminCtx.addCookies(adminCookies);
	const adminPage = await adminCtx.newPage();

	// 1. CortesPanel completo
	console.log('1. Capturando CortesPanel...');
	await adminPage.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-panel&tab=preventa&subtab=cortes', {
		waitUntil: 'networkidle'
	});
	const table = adminPage.locator('.akb-cortes-tabla');
	await table.scrollIntoViewIfNeeded();
	const shot01 = path.join(SCREENSHOT_DIR, '01-local-cortes-panel-quincenal-bimestral.png');
	await table.screenshot({ path: shot01 });
	console.log(`✓ Guardado: ${shot01}`);

	// 2. Term Edit Argentina (Quincenal con días 5, 20)
	console.log('2. Capturando edición de término Argentina (Quincenal)...');
	await adminPage.goto('http://localhost:8080/wp-admin/term.php?taxonomy=pa_pais&tag_ID=98&post_type=product', {
		waitUntil: 'networkidle'
	});
	await adminPage.locator('#akb_tanda_dia').scrollIntoViewIfNeeded();
	const shot02 = path.join(SCREENSHOT_DIR, '02-local-term-edit-argentina-quincenal.png');
	await adminPage.screenshot({ path: shot02 });
	console.log(`✓ Guardado: ${shot02}`);

	// 3. Simular selección de Personalizada para mostrar el campo limpio y no sobrecargado
	console.log('3. Capturando demostración de opción Personalizada (UI limpia y compacta)...');
	await adminPage.selectOption('#akb_tanda_frecuencia', 'personalizada');
	await adminPage.fill('#akb_tanda_meses', '3, 6, 10, 11');
	const shot03 = path.join(SCREENSHOT_DIR, '03-local-term-edit-personalizada-ui-limpia.png');
	await adminPage.screenshot({ path: shot03 });
	console.log(`✓ Guardado: ${shot03}`);

	await adminCtx.close();

	// 4. Experiencia del Cliente en Tienda (PDP Argentina con corte Quincenal)
	console.log('4. Capturando PDP Argentina (Quincenal)...');
	const guestCtx = await browser.newContext({
		viewport: { width: 1440, height: 900 }
	});
	const guestPage = await guestCtx.newPage();

	await guestPage.goto('http://localhost:8080/rurouni-kenshin-edicion-kanzenban-08-ivrea-argentina/', {
		waitUntil: 'networkidle'
	});
	await guestPage.locator('.akb-eta').scrollIntoViewIfNeeded();
	const shot04 = path.join(SCREENSHOT_DIR, '04-local-customer-pdp-argentina-quincenal.png');
	await guestPage.screenshot({ path: shot04 });
	console.log(`✓ Guardado: ${shot04}`);

	// 5. Experiencia del Cliente en Tienda (PDP España con corte Bimestral)
	console.log('5. Capturando PDP España (Bimestral)...');
	await guestPage.goto('http://localhost:8080/el-verano-que-hikaru-murio-8-milky-way/', {
		waitUntil: 'networkidle'
	});
	await guestPage.locator('.akb-eta').scrollIntoViewIfNeeded();
	const shot05 = path.join(SCREENSHOT_DIR, '05-local-customer-pdp-espana-bimestral.png');
	await guestPage.screenshot({ path: shot05 });
	console.log(`✓ Guardado: ${shot05}`);

	// 6. Carrito con producto en preventa y fecha calculada
	console.log('6. Capturando Carrito de Compras...');
	const addBtn = guestPage.locator('.single_add_to_cart_button');
	if (await addBtn.count() > 0) {
		await addBtn.click();
		await guestPage.waitForTimeout(3000);
	}
	await guestPage.goto('http://localhost:8080/carrito/', { waitUntil: 'networkidle' });
	await guestPage.waitForSelector('.cart_item, .woocommerce-cart-form', { timeout: 10000 }).catch(() => {});
	await guestPage.waitForTimeout(2000);
	const shot06 = path.join(SCREENSHOT_DIR, '06-local-customer-cart-promise.png');
	await guestPage.screenshot({ path: shot06 });
	console.log(`✓ Guardado: ${shot06}`);

	await guestCtx.close();
	await browser.close();

	console.log('\n🎉 Todas las capturas fueron tomadas con éxito en LOCAL.');
}

capture().catch(err => {
	console.error('Error durante la captura:', err);
	process.exit(1);
});
