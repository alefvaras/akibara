import playwright from './akibara-e2e/node_modules/playwright/index.js';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/63c8dce7-b5cd-4c14-857e-5828428ab145/screenshots_qa';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

const SSH_HOST = 'akibara';
const REMOTE_WP_LOAD = 'domains/akibara.cl/public_html/wp-load.php';

function runRemoteWp(evalCode) {
	const res = spawnSync(
		'ssh',
		[SSH_HOST, `php -r 'require_once "${REMOTE_WP_LOAD}"; eval(file_get_contents("php://stdin"));'`],
		{ input: evalCode, encoding: 'utf8' }
	);
	if (res.status !== 0) {
		throw new Error(`Remote PHP failed: ${res.stderr || res.stdout}`);
	}
	return (res.stdout || '').trim();
}

async function auditRescueTab() {
	console.log('🚀 Iniciando auditoría interactiva de la Pestaña de Rescate en Producción (akibara.cl)...');

	// 1. Generar cookies de sesión para User ID 1 (ale.fvaras)
	console.log('1. Creando sesión admin temporal vía SSH...');
	const sessionDataRaw = runRemoteWp(`
		$u = get_user_by("id", 1);
		$exp = time() + 1800;
		$tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
		$auth = wp_generate_auth_cookie($u->ID, $exp, "secure_auth", $tok);
		$logged = wp_generate_auth_cookie($u->ID, $exp, "logged_in", $tok);
		echo json_encode([
			"token" => $tok,
			"cookies" => [
				[
					"name" => LOGGED_IN_COOKIE,
					"value" => $logged,
					"domain" => "akibara.cl",
					"path" => "/",
					"secure" => true,
					"httpOnly" => true
				],
				[
					"name" => SECURE_AUTH_COOKIE,
					"value" => $auth,
					"domain" => "akibara.cl",
					"path" => "/wp-admin",
					"secure" => true,
					"httpOnly" => true
				],
				[
					"name" => SECURE_AUTH_COOKIE,
					"value" => $auth,
					"domain" => "akibara.cl",
					"path" => "/",
					"secure" => true,
					"httpOnly" => true
				]
			]
		]);
	`);

	const sessionData = JSON.parse(sessionDataRaw);
	const sessionToken = sessionData.token;
	console.log('   Sesión admin generada con éxito.');

	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const jsErrors = [];
	const jsWarnings = [];

	try {
		const context = await browser.newContext({
			viewport: { width: 1440, height: 960 },
			userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 AkibaraQA/AuditBot'
		});

		await context.addCookies(sessionData.cookies);
		const page = await context.newPage();

		page.on('console', msg => {
			if (msg.type() === 'error') {
				console.error('   [BROWSER ERROR]:', msg.text());
				jsErrors.push(msg.text());
			} else if (msg.type() === 'warn') {
				jsWarnings.push(msg.text());
			}
		});

		page.on('pageerror', err => {
			console.error('   [PAGE ERROR]:', err.message);
			jsErrors.push(err.message);
		});

		// 2. Navegar a la pestaña de rescate
		console.log('\n2. Navegando a https://akibara.cl/wp-admin/admin.php?page=akibara-pricing-matrix&tab=rescue...');
		const navResp = await page.goto('https://akibara.cl/wp-admin/admin.php?page=akibara-pricing-matrix&tab=rescue', {
			waitUntil: 'networkidle',
			timeout: 30000
		});
		console.log(`   HTTP Status: ${navResp.status()}`);

		// Verificar que no fuimos redirigidos a wp-login.php
		const currentUrl = page.url();
		console.log(`   URL actual: ${currentUrl}`);
		if (currentUrl.includes('wp-login.php')) {
			throw new Error('Fallo de autenticación: se redirigió a wp-login.php');
		}

		await page.waitForSelector('.akb-pm-card', { timeout: 10000 });
		const pageTitle = await page.locator('.akb-pm-card__title').first().innerText();
		console.log(`   Título de tarjeta: "${pageTitle}"`);
		if (!pageTitle.includes('Rescate Manual')) {
			throw new Error(`Título no esperado: ${pageTitle}`);
		}

		await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'prod-rescue-01-initial.png'), fullPage: false });
		console.log('   📸 Screenshot inicial guardado: prod-rescue-01-initial.png');

		// 3. Verificar existencia de los elementos requeridos
		console.log('\n3. Verificando componentes interactivos de % de reserva y redondeo...');

		const pills = await page.locator('.akb-rescue-pill').all();
		console.log(`   Pastillas de % detectadas: ${pills.length}`);
		if (pills.length !== 5) {
			throw new Error(`Se esperaban 5 pastillas, pero hay ${pills.length}`);
		}

		const pillValues = [];
		for (const p of pills) {
			const text = (await p.innerText()).trim();
			const pct = await p.getAttribute('data-pct');
			pillValues.push({ text, pct });
		}
		console.log('   Pastillas:', JSON.stringify(pillValues));

		const customPctInput = page.locator('#akb-rescue-custom-pct');
		const roundingSelect = page.locator('#akb-rescue-rounding');
		const headerPct = page.locator('#akb-rescue-header-pct');
		const applyBtn = page.locator('#akb-rescue-btn-apply');

		if (!(await customPctInput.isVisible())) throw new Error('#akb-rescue-custom-pct no está visible');
		if (!(await roundingSelect.isVisible())) throw new Error('#akb-rescue-rounding no está visible');
		if (!(await headerPct.isVisible())) throw new Error('#akb-rescue-header-pct no está visible');
		if (!(await applyBtn.isVisible())) throw new Error('#akb-rescue-btn-apply no está visible');

		console.log(`   Valor inicial customPct: ${await customPctInput.inputValue()}%`);
		console.log(`   Valor inicial rounding: ${await roundingSelect.inputValue()}`);
		console.log(`   Header % inicial: ${await headerPct.innerText()}`);
		console.log(`   Texto botón aplicar inicial: ${await applyBtn.innerText()}`);

		// 4. Probar interactividad de cada pastilla
		console.log('\n4. Probando interactividad de las pastillas (5%, 8%, 10%, 12%, 15%)...');
		const testPcts = ['8', '10', '12', '15', '5'];
		for (const targetPct of testPcts) {
			const pill = page.locator(`.akb-rescue-pill[data-pct="${targetPct}"]`);
			await pill.click();
			await page.waitForTimeout(100);

			const currentVal = await customPctInput.inputValue();
			const headerVal = await headerPct.innerText();
			const btnVal = await applyBtn.innerText();
			const isActive = await pill.evaluate(el => el.classList.contains('is-active'));

			console.log(`   [Click ${targetPct}%] -> input=${currentVal}%, header=${headerVal}, btn="${btnVal}", isActive=${isActive}`);

			if (currentVal !== targetPct) throw new Error(`Input no coincide tras clic en ${targetPct}%`);
			if (!headerVal.includes(targetPct)) throw new Error(`Header % no coincide tras clic en ${targetPct}%`);
			if (!isActive) throw new Error(`Pastilla ${targetPct}% no tiene clase is-active tras clic`);
		}

		// 5. Probar input numérico libre
		console.log('\n5. Probando input numérico libre (ej: 7% y 10%)...');
		await customPctInput.fill('7');
		await customPctInput.dispatchEvent('input');
		await page.waitForTimeout(100);

		let activePills = await page.locator('.akb-rescue-pill.is-active').count();
		console.log(`   Con 7% libre -> pastillas activas: ${activePills} (esperado 0)`);
		if (activePills !== 0) throw new Error('Ninguna pastilla estándar debería estar activa con 7%');

		const headerVal7 = await headerPct.innerText();
		console.log(`   Header % con 7%: ${headerVal7}`);
		if (!headerVal7.includes('7%')) throw new Error('Header % no se actualizó con 7%');

		// Ahora volver a 10% mediante input manual
		await customPctInput.fill('10');
		await customPctInput.dispatchEvent('input');
		await page.waitForTimeout(100);

		const pill10Active = await page.locator('.akb-rescue-pill[data-pct="10"]').evaluate(el => el.classList.contains('is-active'));
		console.log(`   Con 10% manual -> pastilla 10% reactivada: ${pill10Active} (esperado true)`);
		if (!pill10Active) throw new Error('La pastilla 10% debería reactivarse automáticamente al escribir 10');

		// 6. Probar selector de redondeo
		console.log('\n6. Probando selector de redondeo (round_100, round_50, floor_100)...');
		await roundingSelect.selectOption('round_50');
		await page.waitForTimeout(100);
		let btnText = await applyBtn.innerText();
		console.log(`   Modo round_50 -> botón: "${btnText}"`);
		if (!btnText.includes('round_50')) throw new Error('Botón no refleja modo round_50');

		await roundingSelect.selectOption('floor_100');
		await page.waitForTimeout(100);
		btnText = await applyBtn.innerText();
		console.log(`   Modo floor_100 -> botón: "${btnText}"`);
		if (!btnText.includes('floor_100')) throw new Error('Botón no refleja modo floor_100');

		await roundingSelect.selectOption('round_100');
		await page.waitForTimeout(100);

		// 7. Probar toggle de edición manual individual ✏️
		console.log('\n7. Probando toggle de edición manual individual ✏️ y recálculo específico...');
		const firstRow = page.locator('tr.akb-rescue-searchable-row').first();
		const rowCount = await page.locator('tr.akb-rescue-searchable-row').count();
		console.log(`   Total filas de tomos agotados en tabla: ${rowCount}`);

		if (rowCount > 0) {
			const regPriceAttr = await firstRow.getAttribute('data-regular');
			const regPrice = parseInt(regPriceAttr, 10);
			const titleText = (await firstRow.locator('td').nth(2).innerText()).trim();
			console.log(`   Fila 1: "${titleText}" (Precio Regular: $${regPrice})`);

			const toggleBtn = firstRow.locator('.akb-rescue-toggle-manual-btn');
			const manualInput = firstRow.locator('.akb-rescue-individual-input');
			const salePriceStrong = firstRow.locator('.akb-rescue-sale-price');

			// Verificar estado inicial: input manual oculto
			const isHiddenInitially = await manualInput.evaluate(el => el.style.display === 'none' || getComputedStyle(el).display === 'none');
			console.log(`   Estado inicial input manual: oculto=${isHiddenInitially}`);
			if (!isHiddenInitially) throw new Error('Input manual individual debería estar oculto inicialmente');

			// Clic en ✏️ para abrir input
			console.log('   Haciendo clic en botón ✏️...');
			await toggleBtn.click();
			await page.waitForTimeout(100);

			const isVisibleAfterClick = await manualInput.evaluate(el => el.style.display !== 'none');
			console.log(`   Tras clic en ✏️: visible=${isVisibleAfterClick}`);
			if (!isVisibleAfterClick) throw new Error('Input manual no se hizo visible tras clic en ✏️');

			// Escribir un precio manual personalizado: $14.500
			console.log('   Asignando precio manual específico $14500...');
			await manualInput.fill('14500');
			await manualInput.dispatchEvent('input');
			await page.waitForTimeout(100);

			const manualPriceText = await salePriceStrong.innerText();
			const manualColor = await salePriceStrong.evaluate(el => getComputedStyle(el).color);
			console.log(`   Precio visualizado: "${manualPriceText}", Color: ${manualColor}`);

			if (!manualPriceText.includes('14.500')) {
				throw new Error(`Precio visualizado "${manualPriceText}" no refleja el manual $14.500`);
			}
			// Color debe ser ámbar (#b45309 -> rgb(180, 83, 9))
			if (!manualColor.includes('180, 83, 9')) {
				console.warn(`   Advertencia: Color no es exactamente rgb(180, 83, 9): ${manualColor}`);
			}

			await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'prod-rescue-02-manual-override.png'), fullPage: false });
			console.log('   📸 Screenshot con precio manual guardado: prod-rescue-02-manual-override.png');

			// Cambiar la pastilla global a 15% y verificar que la fila manual NO cambia
			console.log('   Cambiando pastilla global a 15% para comprobar blindaje de fila individual...');
			await page.locator('.akb-rescue-pill[data-pct="15"]').click();
			await page.waitForTimeout(100);

			const priceAfterPillChange = await salePriceStrong.innerText();
			console.log(`   Precio fila 1 tras cambio global a 15%: "${priceAfterPillChange}"`);
			if (!priceAfterPillChange.includes('14.500')) {
				throw new Error('El precio manual individual fue sobreescrito por el cambio global de pastilla!');
			}
			console.log('   ✅ Blindaje confirmado: La fila manual mantuvo $14.500 intacto.');

			// Cerrar edición manual (segundo clic en ✏️)
			console.log('   Cerrando edición manual con segundo clic en ✏️...');
			await toggleBtn.click();
			await page.waitForTimeout(100);

			const isHiddenAfterSecondClick = await manualInput.evaluate(el => el.style.display === 'none');
			const revertedPriceText = await salePriceStrong.innerText();
			const revertedColor = await salePriceStrong.evaluate(el => getComputedStyle(el).color);
			console.log(`   Tras segundo clic: oculto=${isHiddenAfterSecondClick}, precio revertido="${revertedPriceText}", color=${revertedColor}`);

			if (!isHiddenAfterSecondClick) throw new Error('Input manual no se ocultó tras segundo clic');
			// Con 15% round_100, el precio revertido debe ser Math.round(regPrice * 0.85 / 100) * 100
			const expectedReverted = Math.round((regPrice * 0.85) / 100) * 100;
			if (!revertedPriceText.replace(/[^0-9]/g, '').includes(String(expectedReverted))) {
				throw new Error(`Precio no revirtió a la fórmula global. Esperado: ${expectedReverted}, Obtenido: ${revertedPriceText}`);
			}
			console.log('   ✅ Reversión confirmada: El precio volvió inmediatamente al cálculo estándar.');
		}

		// 8. Reajustar todo a la posición neutra inicial (5% round_100)
		await page.locator('.akb-rescue-pill[data-pct="5"]').click();
		await page.waitForTimeout(100);
		await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'prod-rescue-03-restored.png'), fullPage: false });
		console.log('   📸 Screenshot final guardado: prod-rescue-03-restored.png');

		// 9. Verificar reporte de errores JS
		console.log('\n8. Auditoría de Consola JavaScript...');
		console.log(`   Errores JS registrados: ${jsErrors.length}`);
		if (jsErrors.length > 0) {
			console.error('   Lista de errores:', jsErrors);
			throw new Error(`Se detectaron ${jsErrors.length} errores JavaScript en producción.`);
		}
		console.log('   ✅ 0 ERRORES JAVASCRIPT EN CONSOLA.');

	} finally {
		await browser.close();
		// Destruir sesión admin en producción
		console.log('\n🧹 Destruyendo sesión admin de producción...');
		runRemoteWp(`
			$u = get_user_by("id", 1);
			if ($u) {
				WP_Session_Tokens::get_instance(1)->destroy('${sessionToken}');
			}
		`);
		console.log('   ✓ Sesión destruida limpiamente.');
	}

	console.log('\n🎉 AUDITORÍA DE LA PESTAÑA DE RESCATE EN PRODUCCIÓN COMPLETADA CON ÉXITO AL 100%!');
}

auditRescueTab().catch(err => {
	console.error('❌ Fallo en auditoría:', err);
	process.exit(1);
});
