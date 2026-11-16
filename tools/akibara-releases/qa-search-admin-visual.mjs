import playwright from '../akibara-e2e/node_modules/playwright/index.js';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/d7ba4691-692a-4aed-96f1-081f9c1f8fd4/screenshots_qa/search-admin';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

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
		return JSON.parse(p.stdout.trim());
	} catch (e) {
		console.warn('Warning: parsing admin cookies failed:', p.stdout);
		return [];
	}
}

async function run() {
	console.log('🚀 Iniciando inspección visual Playwright para SearchAdminSection (wp-admin)...');
	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const cookies = getLocalAdminCookies();
	const context = await browser.newContext({
		viewport: { width: 1440, height: 900 }
	});

	if (cookies.length > 0) {
		await context.addCookies(cookies);
	}

	const page = await context.newPage();

	const consoleErrors = [];
	const pageErrors = [];

	page.on('console', msg => {
		if (msg.type() === 'error') {
			consoleErrors.push(msg.text());
		}
	});

	page.on('pageerror', err => {
		pageErrors.push(err.message);
	});

	const tabs = [
		{ slug: 'indice', label: '01-tab-indice.png' },
		{ slug: 'busquedas', label: '02-tab-busquedas.png' },
		{ slug: 'encargos', label: '03-tab-encargos.png' },
		{ slug: 'facetas', label: '04-tab-facetas.png' },
		{ slug: 'precios', label: '05-tab-precios.png' },
		{ slug: 'sinonimos', label: '06-tab-sinonimos.png' },
		{ slug: 'stopwords', label: '07-tab-stopwords.png' },
		{ slug: 'ajustes', label: '08-tab-ajustes.png' }
	];

	try {
		for (const t of tabs) {
			const url = `http://localhost:8080/wp-admin/admin.php?page=akibara-search&sub=${t.slug}`;
			console.log(`Navegando a sub=${t.slug} (${url})...`);
			await page.goto(url, { waitUntil: 'networkidle' });
			await page.waitForTimeout(400);

			const mainLoaded = await page.locator('.akb-search-admin').isVisible().catch(() => false);
			console.log(`  ✓ Contenedor .akb-search-admin visible: ${mainLoaded}`);

			const shotPath = path.join(SCREENSHOT_DIR, t.label);
			await page.screenshot({ path: shotPath, fullPage: true });
			console.log(`  ✓ Screenshot guardado: ${shotPath}`);
		}

		console.log('\n=== REPORTE DE ERRORES DEL NAVEGADOR ===');
		if (consoleErrors.length === 0 && pageErrors.length === 0) {
			console.log('✓ CERO errores de consola JavaScript y cero excepciones en las 8 pestañas.');
		} else {
			console.warn(`✗ Errores de consola (${consoleErrors.length}):`, consoleErrors);
			console.warn(`✗ Excepciones de página (${pageErrors.length}):`, pageErrors);
		}

	} catch (err) {
		console.error('Error durante la simulación:', err);
		process.exitCode = 1;
	} finally {
		await browser.close();
		console.log('Pruebas visuales de SearchAdminSection finalizadas.');
	}
}

run();
