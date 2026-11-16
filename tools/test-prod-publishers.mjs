import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOTS_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/publishers-prod';

fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });

async function run() {
	console.log('Iniciando prueba visual en Producción (https://akibara.cl/)...');

	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const page = await browser.newPage({
		viewport: { width: 1280, height: 900 }
	});

	// 1. Home — Sección "De dónde vienen tus tomos"
	console.log('Navegando al Home...');
	await page.goto('https://akibara.cl/', { waitUntil: 'networkidle' });
	const homeSection = await page.$('.akb-brands-home');
	if (homeSection) {
		await homeSection.scrollIntoViewIfNeeded();
		await page.waitForTimeout(600);

		// Pausar marquesina para capturar estado limpio y permitir hovers precisos
		const pauseBtn = await page.$('.akb-brands-home__pause');
		if (pauseBtn) {
			await pauseBtn.click({ force: true });
			await page.waitForTimeout(300);
		}

		await homeSection.screenshot({ path: path.join(SCREENSHOTS_DIR, '01-prod-home-reposo.png') });
		console.log('Captura 1: 01-prod-home-reposo.png');

		// Hover Ovni Press
		const ovniItem = await page.$('.akb-brands-home__item[data-brand="ovni-press"]');
		if (ovniItem) {
			await ovniItem.hover({ force: true });
			await page.waitForTimeout(400);
			await homeSection.screenshot({ path: path.join(SCREENSHOTS_DIR, '02-prod-home-hover-ovni.png') });
			console.log('Captura 2: 02-prod-home-hover-ovni.png');
		}

		// Hover Ivrea
		const ivreaItem = await page.$('.akb-brands-home__item[data-brand="ivrea-argentina"]');
		if (ivreaItem) {
			await ivreaItem.hover({ force: true });
			await page.waitForTimeout(400);
			await homeSection.screenshot({ path: path.join(SCREENSHOTS_DIR, '03-prod-home-hover-ivrea.png') });
			console.log('Captura 3: 03-prod-home-hover-ivrea.png');
		}

		// Hover Panini
		const paniniItem = await page.$('.akb-brands-home__item[data-brand="panini-argentina"]');
		if (paniniItem) {
			await paniniItem.hover({ force: true });
			await page.waitForTimeout(400);
			await homeSection.screenshot({ path: path.join(SCREENSHOTS_DIR, '04-prod-home-hover-panini.png') });
			console.log('Captura 4: 04-prod-home-hover-panini.png');
		}

		// Hover Planeta Cómic
		const planetaItem = await page.$('.akb-brands-home__item[data-brand="planeta-espana"]');
		if (planetaItem) {
			await planetaItem.hover({ force: true });
			await page.waitForTimeout(400);
			await homeSection.screenshot({ path: path.join(SCREENSHOTS_DIR, '05-prod-home-hover-planeta.png') });
			console.log('Captura 5: 05-prod-home-hover-planeta.png');
		}
	}

	// 2. /editoriales/
	console.log('Navegando a /editoriales/...');
	await page.goto('https://akibara.cl/editoriales/', { waitUntil: 'networkidle' });
	const gridDir = await page.$('.akb-brands-directory');
	if (gridDir) {
		await gridDir.scrollIntoViewIfNeeded();
		await page.waitForTimeout(600);
		await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '06-prod-editoriales-directory.png') });
		console.log('Captura 6: 06-prod-editoriales-directory.png');

		const ovniCard = await page.$('.akb-brand-card[data-akb-brand-slug="ovni-press"] .akb-brand-card__link');
		if (ovniCard) {
			await ovniCard.hover({ force: true });
			await page.waitForTimeout(400);
			await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '07-prod-editoriales-hover-ovni.png') });
			console.log('Captura 7: 07-prod-editoriales-hover-ovni.png');
		}
	}

	await browser.close();
	console.log('Todas las pruebas visuales en Producción se completaron exitosamente.');
}

run().catch((err) => {
	console.error('Error en prueba visual en producción:', err);
	process.exit(1);
});
