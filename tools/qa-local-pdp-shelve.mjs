import playwright from './akibara-e2e/node_modules/playwright/index.js';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const pw = playwright.default || playwright;
const { chromium } = pw;

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOT_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/local-pdp-shelve';
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

async function runTest() {
	console.log('--- Probando Acción Discreta en PDP en Espejo Local ---');

	// 1. Obtener cookie de sesión de usuario autenticado qa_e2e_puntos
	const authRaw = execSync(`docker exec akibara-cron-1 wp eval '
		$u = get_user_by("login", "qa_e2e_puntos");
		if (!$u) {
			$uid = wp_create_user("qa_e2e_puntos", "testpass123", "qa_puntos@akibara.local");
			$u = get_user_by("id", $uid);
		}
		$exp = time() + 7200;
		$tok = WP_Session_Tokens::get_instance($u->ID)->create($exp);
		echo json_encode([
			"name" => LOGGED_IN_COOKIE,
			"value" => wp_generate_auth_cookie($u->ID, $exp, "logged_in", $tok),
			"domain" => "localhost",
			"path" => "/"
		]);
	'`, { encoding: 'utf8' }).trim();

	const authCookie = JSON.parse(authRaw);

	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	try {
		const context = await browser.newContext({
			viewport: { width: 1280, height: 800 }
		});
		await context.addCookies([authCookie]);
		const page = await context.newPage();

		// Navegar a un tomo que NO está en su colección (ej. Chainsaw Man o Jujutsu Kaisen)
		console.log('1. Navegando a PDP como usuario autenticado...');
		await page.goto('http://localhost:8080/producto/chainsaw-man-17-ivrea-argentina/', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1000);

		// Verificar que el placeholder de estantería está presente
		const shelveAction = page.locator('.akb-pdp-shelve-action');
		const isVisible = await shelveAction.isVisible();
		console.log(`  ✓ Botón discreto de estantería visible: ${isVisible}`);
		if (isVisible) {
			const text = await shelveAction.innerText();
			console.log(`  ✓ Texto de la acción discreta: "${text.trim()}"`);
		}

		await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01-pdp-shelve-action-visible.png') });
		console.log('  📸 Evidencia tomada: 01-pdp-shelve-action-visible.png');

		// 2. Hacer clic en "Márcalo en tu estantería"
		const markBtn = page.locator('.akb-pdp-shelve-btn');
		if (await markBtn.isVisible()) {
			console.log('2. Haciendo clic en "Márcalo en tu estantería"...');
			await markBtn.click();
			await page.waitForTimeout(1500);

			// Verificar que el banner superior apareció
			const pdpBanner = page.locator('.akb-pdp-collection-banner');
			const bannerVisible = await pdpBanner.isVisible();
			const bannerText = await pdpBanner.innerText();
			console.log(`  ✓ Banner superior visible tras marcar: ${bannerVisible}`);
			console.log(`  ✓ Texto en banner: "${bannerText.replace(/\n/g, ' ')}"`);

			// Verificar que el botón discreto inferior ahora está oculto
			const shelveHidden = await shelveAction.isHidden();
			console.log(`  ✓ Acción inferior oculta tras marcar: ${shelveHidden}`);

			await page.screenshot({ path: path.join(SCREENSHOT_DIR, '02-pdp-marked-banner-visible.png') });
			console.log('  📸 Evidencia tomada: 02-pdp-marked-banner-visible.png');

			// 3. Probar reversión haciendo clic en "✕ Quitar de mi estantería"
			const unmarkBtn = page.locator('.akb-pdp-unmark-btn');
			if (await unmarkBtn.isVisible()) {
				console.log('3. Haciendo clic en "✕ Quitar de mi estantería"...');
				await unmarkBtn.click();
				await page.waitForTimeout(1500);

				const bannerReverted = await pdpBanner.isHidden();
				const shelveReverted = await shelveAction.isVisible();
				console.log(`  ✓ Banner superior oculto tras desmarcar: ${bannerReverted}`);
				console.log(`  ✓ Acción inferior vuelve a ser visible: ${shelveReverted}`);

				await page.screenshot({ path: path.join(SCREENSHOT_DIR, '03-pdp-unmarked-reverted.png') });
				console.log('  📸 Evidencia tomada: 03-pdp-unmarked-reverted.png');
			}
		}

		console.log('\n--- PRUEBA LOCAL COMPLETADA CON ÉXITO ---');
	} finally {
		await browser.close();
	}
}

runTest().catch(err => {
	console.error('Error en prueba local:', err);
	process.exit(1);
});
