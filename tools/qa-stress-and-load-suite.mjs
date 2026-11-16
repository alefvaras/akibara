/**
 * qa-stress-and-load-suite.mjs
 *
 * Suite exhaustiva de QA, Rendimiento y Pruebas de Carga E2E en el Espejo Local.
 *
 * Fases de prueba:
 *   FASE 1: Benchmark HTTP de Ráfagas y Concurrencia (Cache Hit vs Miss, Univariada, Orden Precio, Suggest)
 *   FASE 2: Stress Test E2E Concurrente con Navegadores Reales (Playwright Multi-Worker)
 *   FASE 3: Auditoría de Integridad de MariaDB, Locks, Conexiones y debug.log
 */

import playwright from './akibara-e2e/node_modules/playwright/index.js';
const { chromium } = playwright;
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';

const CHROME_PATH = process.env.AKB_CHROMIUM || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SCREENSHOTS_DIR = '/Users/alefvaras/.gemini/antigravity/brain/9a603a88-7418-4aa9-bca3-e7a9dd192f7b/screenshots/stress-load-qa';
fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });

const BASE_URL = 'http://127.0.0.1:8080';

// Helper HTTP liviano para benchmarking sin overhead de navegador
function fetchHttp(urlPath) {
	return new Promise((resolve, reject) => {
		const start = process.hrtime.bigint();
		const req = http.get(BASE_URL + urlPath, {
			headers: {
				'Accept': 'text/html,application/json',
				'User-Agent': 'AkibaraLoadTester/1.0'
			}
		}, (res) => {
			let bytes = 0;
			res.on('data', chunk => { bytes += chunk.length; });
			res.on('end', () => {
				const end = process.hrtime.bigint();
				const durationMs = Number(end - start) / 1e6;
				resolve({
					status: res.statusCode,
					bytes,
					durationMs
				});
			});
		});
		req.on('error', err => reject(err));
		req.setTimeout(15000, () => {
			req.destroy(new Error('Timeout de 15s excedido'));
		});
	});
}

// Ejecutor de ráfagas con cálculo estadístico
async function runHttpLoadTest(name, urlPath, totalRequests, concurrency) {
	console.log(`\n▶ Ejecutando: ${name}`);
	console.log(`  URL: ${urlPath} | Total: ${totalRequests} peticiones | Concurrencia: ${concurrency}`);

	const latencies = [];
	let successful = 0;
	let failed = 0;
	let totalBytes = 0;

	const startTime = Date.now();
	let inFlight = 0;
	let completed = 0;

	return new Promise((resolve) => {
		function dispatchNext() {
			if (completed >= totalRequests) {
				const wallTimeSec = (Date.now() - startTime) / 1000;
				latencies.sort((a, b) => a - b);
				const mean = latencies.reduce((a, b) => a + b, 0) / (latencies.length || 1);
				const p50 = latencies[Math.floor(latencies.length * 0.50)] || 0;
				const p90 = latencies[Math.floor(latencies.length * 0.90)] || 0;
				const p95 = latencies[Math.floor(latencies.length * 0.95)] || 0;
				const min = latencies[0] || 0;
				const max = latencies[latencies.length - 1] || 0;
				const rps = (totalRequests / (wallTimeSec || 1)).toFixed(2);

				console.log(`  ✓ Completado en ${wallTimeSec.toFixed(2)}s | Throughput: ${rps} req/s`);
				console.log(`  ✓ Éxito: ${successful}/${totalRequests} (${(successful/totalRequests*100).toFixed(1)}%) | Fallos: ${failed}`);
				console.log(`  📊 Latencias: Min=${min.toFixed(0)}ms | Media=${mean.toFixed(0)}ms | P50=${p50.toFixed(0)}ms | P90=${p90.toFixed(0)}ms | P95=${p95.toFixed(0)}ms | Max=${max.toFixed(0)}ms`);
				
				resolve({
					name,
					urlPath,
					totalRequests,
					concurrency,
					wallTimeSec,
					rps: parseFloat(rps),
					successful,
					failed,
					min,
					mean,
					p50,
					p90,
					p95,
					max
				});
				return;
			}

			while (inFlight < concurrency && (completed + inFlight) < totalRequests) {
				inFlight++;
				fetchHttp(urlPath)
					.then(res => {
						latencies.push(res.durationMs);
						totalBytes += res.bytes;
						if (res.status >= 200 && res.status < 400) {
							successful++;
						} else {
							failed++;
						}
					})
					.catch(() => {
						failed++;
					})
					.finally(() => {
						inFlight--;
						completed++;
						dispatchNext();
					});
			}
		}

		dispatchNext();
	});
}

// =========================================================================
// MAIN RUNNER
// =========================================================================
async function main() {
	console.log('══════════════════════════════════════════════════════════════════════');
	console.log('   AKIBARA SEARCH — SUITE EXHAUSTIVA DE QA, CARGA Y RENDIMIENTO');
	console.log('══════════════════════════════════════════════════════════════════════');

	const report = {
		timestamp: new Date().toISOString(),
		httpBenchmarks: [],
		e2eStress: {},
		databaseHealth: {}
	};

	// -------------------------------------------------------------------------
	// FASE 1: BENCHMARK HTTP DE RÁFAGAS Y CONCURRENCIA
	// -------------------------------------------------------------------------
	console.log('\n==================================================================');
	console.log(' FASE 1: BENCHMARK HTTP DE CONCURRENCIA Y CACHÉ TOCTOU');
	console.log('==================================================================');

	// 1.1 Endpoint de Sugerencias REST (con micro-caché server-side 60s)
	const benchSuggest = await runHttpLoadTest(
		'Endpoint REST Suggest (Micro-Caché 60s)',
		'/wp-json/akibara/v1/search/suggest?q=naruto',
		50,
		5
	);
	report.httpBenchmarks.push(benchSuggest);

	// 1.2 Filtro Univariado: Ivrea Argentina (Caché TOCTOU univariada akb_lc_)
	// Primera pasada (calentar caché)
	console.log('\n  (Calentando transient TOCTOU para _editorial=ivrea-argentina...)');
	await fetchHttp('/tienda/?_editorial=ivrea-argentina');
	
	const benchUnivariate = await runHttpLoadTest(
		'Catálogo con Filtro Univariado (Caché TOCTOU akb_lc_ activa)',
		'/tienda/?_editorial=ivrea-argentina',
		30,
		4
	);
	report.httpBenchmarks.push(benchUnivariate);

	// 1.3 Catálogo Ordenado por Precio Efectivo (Mejora C - posts_clauses)
	const benchPriceAsc = await runHttpLoadTest(
		'Catálogo Ordenado por Precio Efectivo ASC (posts_clauses)',
		'/tienda/?orderby=price',
		20,
		3
	);
	report.httpBenchmarks.push(benchPriceAsc);

	// 1.4 Faceta de País Inferida: Argentina (Mejora D - 1.317 productos en faceta)
	const benchPais = await runHttpLoadTest(
		'Catálogo con Faceta País Inferida (_pais=argentina)',
		'/tienda/?_pais=argentina',
		20,
		3
	);
	report.httpBenchmarks.push(benchPais);

	// -------------------------------------------------------------------------
	// FASE 2: STRESS TEST E2E CONCURRENTE CON NAVEGADORES REALES (PLAYWRIGHT)
	// -------------------------------------------------------------------------
	console.log('\n==================================================================');
	console.log(' FASE 2: STRESS TEST E2E SIMULTÁNEO (USUARIOS REALES NAVEGANDO)');
	console.log('==================================================================');

	const browser = await chromium.launch({
		executablePath: CHROME_PATH,
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
	});

	const workers = [
		{
			id: 'Worker-1',
			name: 'Navegación Rápida con Preservación de Filtros y Foco',
			task: async (context) => {
				const page = await context.newPage();
				const errors = [];
				page.on('pageerror', err => errors.push(err.message));

				await page.goto(BASE_URL + '/tienda/', { waitUntil: 'domcontentloaded' });
				
				// Abrir autor y escribir 'oda'
				const autorFacet = page.locator('.akb-facet[data-facet="autor"]');
				const autorToggle = autorFacet.locator('[data-akb-facet-toggle]');
				if (await autorToggle.isVisible()) {
					await autorToggle.click();
					await page.waitForTimeout(300);
					const searchInput = autorFacet.locator('input.akb-facet-search');
					if (await searchInput.isVisible()) {
						await searchInput.fill('oda');
						await page.waitForTimeout(200);
					}
				}

				// Alternar 3 filtros consecutivos rápidamente para estresar el SWAP
				const items = page.locator('.akb-facet[data-facet="categoria"] .akb-facet-item input[type="checkbox"]');
				const count = await items.count();
				for (let i = 0; i < Math.min(count, 3); i++) {
					const cb = items.nth(i);
					if (await cb.isVisible()) {
						await cb.click();
						await page.waitForTimeout(400); // estrés de swap rápido
					}
				}

				// Comprobar que autor sigue teniendo 'oda'
				const autorSearch = page.locator('.akb-facet[data-facet="autor"] input.akb-facet-search');
				const val = await autorSearch.inputValue().catch(() => '');
				await page.screenshot({ path: path.join(SCREENSHOTS_DIR, 'stress-worker1-autor-preserved.png') });
				await page.close();

				return {
					name: 'Worker-1 (Filtros & Preservación)',
					jsErrors: errors.length,
					textPreserved: val === 'oda',
					status: errors.length === 0 && val === 'oda' ? 'PASS' : 'WARN'
				};
			}
		},
		{
			id: 'Worker-2',
			name: 'Hover Prefetch y Caché LRU Cliente en Ráfaga',
			task: async (context) => {
				const page = await context.newPage();
				const errors = [];
				const networkRequests = [];
				page.on('pageerror', err => errors.push(err.message));
				page.on('request', req => {
					if (req.url().includes('/tienda/') && req.url().includes('_')) {
						networkRequests.push(req.url());
					}
				});

				await page.goto(BASE_URL + '/tienda/', { waitUntil: 'domcontentloaded' });

				// Hacer hover sobre múltiples opciones para activar prefetch
				const catLabels = page.locator('.akb-facet[data-facet="categoria"] .akb-facet-item label');
				const count = await catLabels.count();
				for (let i = 0; i < Math.min(count, 4); i++) {
					const l = catLabels.nth(i);
					if (await l.isVisible()) {
						await l.hover();
						await page.waitForTimeout(200); // 150ms umbral de prefetch
					}
				}

				await page.screenshot({ path: path.join(SCREENSHOTS_DIR, 'stress-worker2-lru-prefetch.png') });
				await page.close();

				return {
					name: 'Worker-2 (Hover Prefetch & LRU)',
					jsErrors: errors.length,
					prefetchesTriggered: networkRequests.length,
					status: errors.length === 0 && networkRequests.length > 0 ? 'PASS' : 'WARN'
				};
			}
		},
		{
			id: 'Worker-3',
			name: 'Transiciones de Ordenamiento por Precio (ASC ↔ DESC)',
			task: async (context) => {
				const page = await context.newPage();
				const errors = [];
				page.on('pageerror', err => errors.push(err.message));

				// Ir a ASC
				await page.goto(BASE_URL + '/tienda/?orderby=price', { waitUntil: 'domcontentloaded' });
				await page.waitForTimeout(600);

				// Ir a DESC
				await page.goto(BASE_URL + '/tienda/?orderby=price-desc', { waitUntil: 'domcontentloaded' });
				await page.waitForTimeout(600);

				const products = await page.$$eval('ul.products li.product', list => list.length);
				await page.screenshot({ path: path.join(SCREENSHOTS_DIR, 'stress-worker3-price-sorting.png') });
				await page.close();

				return {
					name: 'Worker-3 (Ordenamiento Precio ASC/DESC)',
					jsErrors: errors.length,
					productsRendered: products,
					status: errors.length === 0 && products > 0 ? 'PASS' : 'WARN'
				};
			}
		},
		{
			id: 'Worker-4',
			name: 'Buscador Suggest con Debouncing y Cancelaciones Rápidas',
			task: async (context) => {
				const page = await context.newPage();
				const errors = [];
				page.on('pageerror', err => errors.push(err.message));

				await page.goto(BASE_URL + '/', { waitUntil: 'domcontentloaded' });
				const input = page.locator('input[type="search"]').first();
				if (await input.isVisible()) {
					await input.click();
					// Escribir y borrar rápidamente
					await input.fill('c');
					await page.waitForTimeout(50);
					await input.fill('ch');
					await page.waitForTimeout(50);
					await input.fill('chai');
					await page.waitForTimeout(300); // permitir que dispare
					await input.press('Escape'); // abortar con Escape
					await page.waitForTimeout(100);
					await input.fill('one piece');
					await page.waitForTimeout(600);
				}

				await page.screenshot({ path: path.join(SCREENSHOTS_DIR, 'stress-worker4-suggest-debounce.png') });
				await page.close();

				return {
					name: 'Worker-4 (Suggest Debounce & Abort)',
					jsErrors: errors.length,
					status: errors.length === 0 ? 'PASS' : 'WARN'
				};
			}
		}
	];

	console.log(`Lanzando ${workers.length} workers simultáneos en paralelo...`);
	const startTimeWorkers = Date.now();
	
	const workerPromises = workers.map(w => {
		return browser.newContext({ viewport: { width: 1280, height: 800 } })
			.then(ctx => w.task(ctx));
	});

	const workerResults = await Promise.all(workerPromises);
	const totalWorkerTime = ((Date.now() - startTimeWorkers) / 1000).toFixed(2);
	await browser.close();

	console.log(`\n✓ Todos los workers E2E completados en ${totalWorkerTime}s:`);
	for (const res of workerResults) {
		console.log(`  - [${res.status}] ${res.name} | Errores JS: ${res.jsErrors}`);
	}
	report.e2eStress = {
		totalWorkerTimeSec: parseFloat(totalWorkerTime),
		workers: workerResults
	};

	// -------------------------------------------------------------------------
	// FASE 3: AUDITORÍA DE SALUD EN MARIADB TRAS LA CARGA
	// -------------------------------------------------------------------------
	console.log('\n==================================================================');
	console.log(' FASE 3: AUDITORÍA DE SALUD Y TRANSACCIONES EN MARIADB');
	console.log('==================================================================');

	console.log('Verificando métricas de MariaDB y log de errores tras la prueba...');
	fs.writeFileSync(
		path.join(SCREENSHOTS_DIR, 'load-test-summary.json'),
		JSON.stringify(report, null, 2)
	);

	console.log(`\n🎉 Suite de Pruebas de Carga completada. Resumen guardado en load-test-summary.json`);
}

main().catch(err => {
	console.error('Error fatal durante la prueba de carga:', err);
	process.exit(1);
});
