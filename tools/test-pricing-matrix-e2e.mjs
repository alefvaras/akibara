import playwright from './akibara-e2e/node_modules/playwright/index.js';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/d7ba4691-692a-4aed-96f1-081f9c1f8fd4/screenshots_qa/pricing-matrix';

function getLocalAdminCookies() {
	const php = `<?php
		$u = get_user_by('id', 1);
		if (!$u) {
			$users = get_users(['role' => 'administrator', 'number' => 1]);
			$u = $users[0] ?? null;
		}
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
		console.warn('Warning: Could not get local admin cookies:', p.stderr);
		return [];
	}
	try {
		const out = p.stdout.trim();
		const jsonStart = out.indexOf('[');
		return JSON.parse(jsonStart !== -1 ? out.slice(jsonStart) : out);
	} catch (e) {
		console.warn('Warning: parsing admin cookies failed:', p.stdout);
		return [];
	}
}

async function runTests() {
	console.log('🚀 Iniciando batería exhaustiva de pruebas E2E e interacciones UI/UX en Local Docker...');
	const cookies = getLocalAdminCookies();
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const context = await browser.newContext({
		viewport: { width: 1440, height: 900 }
	});
	if (cookies.length > 0) {
		await context.addCookies(cookies);
	}

	const page = await context.newPage();
	const errors = [];
	page.on('console', msg => {
		if (msg.type() === 'error') errors.push(msg.text());
	});

	// TEST 1: Verificar que ningún botón azul o elemento desborde la tarjeta o tabla en Tab 2 (Series)
	console.log('\n--- TEST 1: Verificación de Desbordes / Overflows en Tab Series ---');
	await page.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-pricing-matrix&tab=series', { waitUntil: 'networkidle' });

	const overflowCheck = await page.evaluate(() => {
		const card = document.querySelector('.akb-pm-card');
		const cardRect = card.getBoundingClientRect();
		const buttons = Array.from(document.querySelectorAll('.akb-pm-btn-level'));
		const overflowing = [];

		buttons.forEach((btn, idx) => {
			const btnRect = btn.getBoundingClientRect();
			if (btnRect.right > cardRect.right + 2) {
				overflowing.push({
					index: idx,
					btnRight: btnRect.right,
					cardRight: cardRect.right,
					diff: btnRect.right - cardRect.right
				});
			}
		});

		return {
			cardWidth: cardRect.width,
			totalButtons: buttons.length,
			overflowingCount: overflowing.length,
			overflowingDetails: overflowing
		};
	});

	console.log('Resultado Test 1 (Desborde):', overflowCheck);
	if (overflowCheck.overflowingCount > 0) {
		throw new Error(`¡FALLO!: ${overflowCheck.overflowingCount} botones aún se desbordan de la tarjeta!`);
	}
	console.log('✅ TEST 1 APROBADO: 0 botones desbordados. Todos están 100% dentro de la tarjeta y la tabla.');

	// TEST 2: Clic en Chip de Escala Detectada rellena el input objetivo
	console.log('\n--- TEST 2: Interacción Click-to-Fill en Chip de Escala ---');
	const firstChip = page.locator('.akb-pm-scale-chip').first();
	const chipPrice = await firstChip.getAttribute('data-price');
	const inputId = await firstChip.getAttribute('data-input-id');
	console.log(`Haciendo clic en chip con precio $${chipPrice} para input #${inputId}...`);
	await firstChip.click();
	const inputValue = await page.locator(`#${inputId}`).inputValue();
	console.log(`Valor en input #${inputId} tras clic: ${inputValue}`);
	if (inputValue !== chipPrice) {
		throw new Error(`¡FALLO!: El input no se actualizó con el precio del chip. Esperado: ${chipPrice}, Obtenido: ${inputValue}`);
	}
	console.log('✅ TEST 2 APROBADO: Clic en chip actualiza el input objetivo de inmediato.');

	// TEST 3: Filtrado en tiempo real en la tabla de Series
	console.log('\n--- TEST 3: Filtrado interactivo en tabla de Series ---');
	const searchInput = page.locator('#akb-series-search');
	await searchInput.fill('Dorohedoro');
	await page.waitForTimeout(100);

	const visibleRows = await page.evaluate(() => {
		const rows = Array.from(document.querySelectorAll('tbody tr.akb-series-searchable-row'));
		return rows.filter(r => r.style.display !== 'none').length;
	});
	console.log(`Filas visibles con búsqueda 'Dorohedoro': ${visibleRows}`);
	if (visibleRows !== 1) {
		throw new Error(`¡FALLO!: Se esperaba exactamente 1 fila visible para 'Dorohedoro', pero hay ${visibleRows}`);
	}
	await searchInput.fill(''); // Reset filter
	console.log('✅ TEST 3 APROBADO: Filtro en vivo funciona y aísla exactamente la serie buscada.');

	// TEST 4: Simulación Dry-Run desde la interfaz web (Tab Series)
	console.log('\n--- TEST 4: Ejecución Dry-Run desde formulario de fila ---');
	const firstDryBtn = page.locator('.akb-pm-level-form button[name="dry_run"]').first();
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'networkidle' }),
		firstDryBtn.click()
	]);

	const noticeText = await page.locator('.akb-pm-wrap .notice.notice-info').innerText();
	console.log('Aviso post Dry-Run:', noticeText);
	if (!noticeText.includes('[SIMULACIÓN DRY-RUN]')) {
		throw new Error('¡FALLO!: La respuesta no indicó [SIMULACIÓN DRY-RUN]');
	}
	console.log('✅ TEST 4 APROBADO: Dry-Run ejecutado exitosamente con mensaje informativo de blindaje.');

	// TEST 5: Calculadora CLP en vivo en Tab Escalas
	console.log('\n--- TEST 5: Calculadora CLP interactiva en Tab Escalas ---');
	await page.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-pricing-matrix&tab=scales', { waitUntil: 'networkidle' });

	const firstForm = page.locator('.akb-scale-row-form').first();
	const regInput = firstForm.locator('.akb-calc-reg');
	const saleInput = firstForm.locator('.akb-calc-sale');

	// Modificar el precio regular a 20.000 (con 5% round_100 debería ser 19.000)
	await regInput.fill('20000');
	await regInput.dispatchEvent('input');
	await page.waitForTimeout(100);

	const calculatedSale = await saleInput.inputValue();
	console.log(`Precio A Pedido calculado en vivo para $20.000: $${calculatedSale}`);
	if (calculatedSale !== '19000') {
		throw new Error(`¡FALLO!: Calculadora CLP errónea. Esperado: 19000, Obtenido: ${calculatedSale}`);
	}
	console.log('✅ TEST 5 APROBADO: Calculadora CLP en vivo recalcula al vuelo sin recargar la página.');

	// TEST 6: Selección de checkboxes en Tab Rescate
	console.log('\n--- TEST 6: Selección y contador interactivo en Tab Rescate ---');
	await page.goto('http://localhost:8080/wp-admin/admin.php?page=akibara-pricing-matrix&tab=rescue', { waitUntil: 'networkidle' });

	const selectAll = page.locator('#akb-rescue-select-all');
	await selectAll.click();
	await page.waitForTimeout(100);

	const selectedCount = await page.locator('#akb-rescue-selected-count').innerText();
	console.log(`Tomos seleccionados tras marcar 'Seleccionar todos': ${selectedCount}`);
	if (parseInt(selectedCount, 10) <= 0) {
		throw new Error('¡FALLO!: El contador de tomos seleccionados no aumentó.');
	}
	await selectAll.click(); // Uncheck
	const uncheckCount = await page.locator('#akb-rescue-selected-count').innerText();
	if (parseInt(uncheckCount, 10) !== 0) {
		throw new Error('¡FALLO!: El contador no volvió a 0 tras desmarcar.');
	}
	console.log('✅ TEST 6 APROBADO: Selector múltiple y contador dinámico de rescate funcionan al 100%.');

	await browser.close();
	console.log('\n🎉 ¡TODAS LAS PRUEBAS E2E E INTERACCIONES PASARON CON ÉXITO (6/6)!');
}

runTests().catch(err => {
	console.error('Error durante pruebas:', err);
	process.exit(1);
});
