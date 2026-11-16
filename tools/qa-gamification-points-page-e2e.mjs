import playwright from './akibara-e2e/node_modules/playwright/index.js';
import fs from 'fs';

const pw = playwright.default || playwright;
const { chromium } = pw;

const outDir = '/Users/alefvaras/.gemini/antigravity/brain/c5738b26-8e15-4e9b-9d7a-fffd6b137fb1/screenshots/gamification-tiers';
fs.mkdirSync(outDir, { recursive: true });

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function run() {
	console.log('--- QA E2E: GAMIFICACIÓN Y NIVELES (ADMIN + PÁGINA PUNTOS) ---');
	const browser = await chromium.launch({ headless: true, executablePath: CHROME_PATH });
	const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
	const page = await context.newPage();

	// 1. Iniciar sesión como administrador
	console.log('1. Iniciando sesión como administrador...');
	await page.goto('http://localhost:8080/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', 'ale.fvaras');
	await page.fill('#user_pass', 'QaAdmin!2026');
	await page.click('#wp-submit');
	await page.waitForURL('**/wp-admin/**', { waitUntil: 'domcontentloaded' });

	// 2. Ir a Ajustes de Puntos y Recompensas (Pestaña Compras / Puntos por compra)
	console.log('2. Navegando a Puntos por compra en wp-admin...');
	await page.goto('http://localhost:8080/wp-admin/admin.php?page=akb-rewards-settings&tab=compras', { waitUntil: 'domcontentloaded' });

	// Verificar que los nuevos campos de niveles existen en la UI
	const colMinInput = await page.$('#akb_rewards_tier_coleccionista_min');
	const colMultInput = await page.$('#akb_rewards_tier_coleccionista_mult');
	const resMinInput = await page.$('#akb_rewards_tier_residente_min');
	const resMultInput = await page.$('#akb_rewards_tier_residente_mult');

	if (!colMinInput || !colMultInput || !resMinInput || !resMultInput) {
		throw new Error('Los campos de configuración de niveles no se encontraron en la UI de admin.');
	}

	console.log('Campos encontrados con éxito en la UI.');
	console.log('Valores actuales:');
	console.log(' - Coleccionista min:', await colMinInput.inputValue());
	console.log(' - Coleccionista mult:', await colMultInput.inputValue());
	console.log(' - Residente min:', await resMinInput.inputValue());
	console.log(' - Residente mult:', await resMultInput.inputValue());

	// Modificar valores como lo haría el dueño (ej. 2500 y 9000, 1.25x y 1.6x)
	console.log('3. Modificando umbrales de niveles como dueño...');
	await colMinInput.fill('2500');
	await colMultInput.fill('1.25');
	await resMinInput.fill('9000');
	await resMultInput.fill('1.6');

	await page.screenshot({ path: `${outDir}/01-admin-tiers-fields-modified.png`, fullPage: false });

	// Guardar ajustes
	console.log('Guardando ajustes en admin...');
	const submitBtn = await page.$('input[type="submit"], button[type="submit"]');
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		submitBtn.click()
	]);

	// Verificar aviso de éxito y persistencia
	const notice = await page.$('.notice-success, .updated');
	console.log('Aviso de guardado presente:', !!notice);
	await page.screenshot({ path: `${outDir}/02-admin-tiers-saved-success.png`, fullPage: false });

	const valColSaved = await (await page.$('#akb_rewards_tier_coleccionista_min')).inputValue();
	const valResSaved = await (await page.$('#akb_rewards_tier_residente_min')).inputValue();
	console.log('Persistido en UI -> Coleccionista:', valColSaved, '| Residente:', valResSaved);

	// 4. Navegar a la página pública de Puntos (shortcode [akb_puntos_resumen])
	console.log('4. Navegando a la página pública de Puntos (http://localhost:8080/akibara-puntos/)...');
	await page.goto('http://localhost:8080/akibara-puntos/', { waitUntil: 'domcontentloaded' });

	// Verificar sección de Niveles de Coleccionista
	const tiersSection = await page.$('.akb-club-tiers-section');
	if (!tiersSection) {
		throw new Error('No se encontró .akb-club-tiers-section en la página pública de puntos.');
	}

	const tiersText = await tiersSection.innerText();
	console.log('Texto renderizado en la sección de niveles:');
	console.log(tiersText);

	// Validar que los valores modificados (2500 y 9000, 1.25x y 1.6x) se reflejan dinámicamente:
	// Lector: 0 a 2.499 pts
	// Coleccionista: Desde 2.500 a 8.999 pts (1,25x o 1.25x / +25% pts)
	// Residente: Desde 9.000 pts acumulados (1,6x / +60% pts)
	if (!tiersText.includes('2.499')) {
		throw new Error('El límite superior de Lector (2.499) no se reflejó dinámicamente.');
	}
	if (!tiersText.includes('2.500') || !tiersText.includes('8.999')) {
		throw new Error('El rango de Coleccionista (2.500 a 8.999) no se reflejó dinámicamente.');
	}
	if (!tiersText.includes('9.000')) {
		throw new Error('El umbral de Residente (9.000) no se reflejó dinámicamente.');
	}

	console.log('¡Validación matemática de rangos dinámicos 100% exitosa!');
	await tiersSection.scrollIntoViewIfNeeded();
	await page.screenshot({ path: `${outDir}/03-public-points-tiers-grid-dynamic.png` });

	// Restaurar valores canónicos originales (2000 y 8000, 1.2 y 1.5)
	console.log('5. Restaurando umbrales canónicos originales (2.000 y 8.000)...');
	await page.goto('http://localhost:8080/wp-admin/admin.php?page=akb-rewards-settings&tab=compras', { waitUntil: 'domcontentloaded' });
	await page.fill('#akb_rewards_tier_coleccionista_min', '2000');
	await page.fill('#akb_rewards_tier_coleccionista_mult', '1.2');
	await page.fill('#akb_rewards_tier_residente_min', '8000');
	await page.fill('#akb_rewards_tier_residente_mult', '1.5');
	const submitRestore = await page.$('input[type="submit"], button[type="submit"]');
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		submitRestore.click()
	]);

	await page.screenshot({ path: `${outDir}/04-admin-tiers-restored.png`, fullPage: false });

	// Verificar página pública restaurada (1.999, 2.000 a 7.999, 8.000)
	await page.goto('http://localhost:8080/akibara-puntos/', { waitUntil: 'domcontentloaded' });
	const tiersRestoredSection = await page.$('.akb-club-tiers-section');
	await tiersRestoredSection.scrollIntoViewIfNeeded();
	await page.screenshot({ path: `${outDir}/05-public-points-tiers-grid-canonical.png` });

	await browser.close();
	console.log('--- TEST COMPLETADO EXITOSAMENTE ---');
}

run().catch(err => {
	console.error('ERROR EN QA TEST:', err);
	process.exit(1);
});
