import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOTS_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/publishers-refactor';

fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });

async function run() {
	console.log('Iniciando prueba visual en Local Docker (http://localhost:8080/)...');

	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox']
	});

	const page = await browser.newPage({
		viewport: { width: 1280, height: 800 }
	});

	// 1. Home - sección de editoriales en reposo
	await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
	const homeSection = await page.$('.akb-brands-home');
	if (homeSection) {
		await homeSection.scrollIntoViewIfNeeded();
		await page.waitForTimeout(500);
		await homeSection.screenshot({ path: path.join(SCREENSHOTS_DIR, '01-local-home-reposo.png') });
		console.log('Captura 1 guardada: 01-local-home-reposo.png');

		// Pausar marquesina con el botón accesible o pasar force: true
		const pauseBtn = await page.$('.akb-brands-home__pause');
		if (pauseBtn) {
			await pauseBtn.click({ force: true });
			await page.waitForTimeout(200);
		}

		// Hover en Ovni Press
		const ovniItem = await page.$('.akb-brands-home__item[data-brand="ovni-press"]');
		if (ovniItem) {
			await ovniItem.hover({ force: true });
			await page.waitForTimeout(400);
			await homeSection.screenshot({ path: path.join(SCREENSHOTS_DIR, '02-local-home-hover-ovni.png') });
			console.log('Captura 2 guardada: 02-local-home-hover-ovni.png');
		}

		// Hover en Ivrea
		const ivreaItem = await page.$('.akb-brands-home__item[data-brand="ivrea-argentina"]');
		if (ivreaItem) {
			await ivreaItem.hover({ force: true });
			await page.waitForTimeout(400);
			await homeSection.screenshot({ path: path.join(SCREENSHOTS_DIR, '03-local-home-hover-ivrea.png') });
			console.log('Captura 3 guardada: 03-local-home-hover-ivrea.png');
		}
	}

	// 2. /editoriales/
	await page.goto('http://localhost:8080/editoriales/', { waitUntil: 'networkidle' });
	const gridDir = await page.$('.akb-brands-directory');
	if (gridDir) {
		await gridDir.scrollIntoViewIfNeeded();
		await page.waitForTimeout(500);
		await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '04-local-editoriales-directory.png') });
		console.log('Captura 4 guardada: 04-local-editoriales-directory.png');

		const ovniCard = await page.$('.akb-brand-card[data-akb-brand-slug="ovni-press"] .akb-brand-card__link');
		if (ovniCard) {
			await ovniCard.hover({ force: true });
			await page.waitForTimeout(400);
			await page.screenshot({ path: path.join(SCREENSHOTS_DIR, '05-local-editoriales-hover-ovni.png') });
			console.log('Captura 5 guardada: 05-local-editoriales-hover-ovni.png');
		}
	}

	await browser.close();
	console.log('Prueba visual local terminada exitosamente.');
}

run().catch((err) => {
	console.error('Error en prueba visual:', err);
	process.exit(1);
});
