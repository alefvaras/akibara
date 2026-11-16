#!/usr/bin/env node

/**
 * gsc-index-monitor.mjs
 *
 * Monitor seguro y de SOLO LECTURA del estado de indexación real en Google Search Console.
 * Utiliza la URL Inspection API oficial de Google para verificar si las páginas clave del
 * catálogo (series, categorías, productos) están indexadas (verdict: PASS), su última fecha
 * de rastreo por Googlebot y la coincidencia canónica.
 *
 * No envía notificaciones a la Indexing API ni consume cuotas de mutación.
 * 100% compatible con las políticas y cuotas de Google Search Console (2.000 req/día).
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT_DIR = path.resolve(__dirname, '..');
const CATALOG_FILE = path.join(ROOT_DIR, 'output/seo-growth-20261026/qa-integral/catalogue-current-full.json');
const CATALOG_CACHE_FILE = path.join(ROOT_DIR, 'tools/catalog-urls-cache.json');
const REPORTS_DIR = path.join(ROOT_DIR, '.local-reports');
const STATE_FILE = path.join(ROOT_DIR, '.local-reports/gsc-monitor-cursor.json');
const INSPECTOR_SCRIPT = path.join(ROOT_DIR, 'ops/inspect-gsc-index-status.mjs');

fs.mkdirSync(REPORTS_DIR, { recursive: true });

function parseArgs() {
	const args = { limit: 25, offset: null };
	for (const arg of process.argv.slice(2)) {
		if (arg.startsWith('--limit=')) args.limit = parseInt(arg.split('=')[1], 10) || 25;
		if (arg.startsWith('--offset=')) args.offset = parseInt(arg.split('=')[1], 10);
	}
	return args;
}

function loadCursor() {
	if (fs.existsSync(STATE_FILE)) {
		try {
			const data = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
			return typeof data.cursor === 'number' ? data.cursor : 0;
		} catch {
			return 0;
		}
	}
	return 0;
}

function saveCursor(cursor) {
	fs.writeFileSync(STATE_FILE, JSON.stringify({ cursor, updated_at: new Date().toISOString() }, null, 2));
}

function getSeriesUrls() {
	if (fs.existsSync(CATALOG_FILE)) {
		try {
			const catalog = JSON.parse(fs.readFileSync(CATALOG_FILE, 'utf8'));
			const series = Array.isArray(catalog.series) ? catalog.series : [];
			const urls = series.map(s => s.url).filter(Boolean);
			if (urls.length > 0) return urls;
		} catch {
			// continuar a caché
		}
	}
	if (fs.existsSync(CATALOG_CACHE_FILE)) {
		try {
			const cache = JSON.parse(fs.readFileSync(CATALOG_CACHE_FILE, 'utf8'));
			if (Array.isArray(cache.urls) && cache.urls.length > 0) {
				return cache.urls;
			}
		} catch {
			// continuar a fallback
		}
	}
	// Fallback lista esencial
	return [
		'https://akibara.cl/',
		'https://akibara.cl/manga/',
		'https://akibara.cl/comics/',
		'https://akibara.cl/serie/jujutsu-kaisen/',
		'https://akibara.cl/serie/dan-da-dan/',
		'https://akibara.cl/serie/blue-lock/',
		'https://akibara.cl/serie/frieren/',
		'https://akibara.cl/serie/skip-and-loafer/',
		'https://akibara.cl/serie/maximum-berserk/',
		'https://akibara.cl/serie/chainsaw-man/'
	];
}

function main() {
	const args = parseArgs();
	const allUrls = getSeriesUrls();
	const total = allUrls.length;

	let cursor = args.offset !== null ? args.offset : loadCursor();
	if (cursor >= total) cursor = 0;

	const batch = [];
	for (let i = 0; i < args.limit && batch.length < total; i++) {
		const idx = (cursor + i) % total;
		batch.push(allUrls[idx]);
	}

	const nextCursor = (cursor + batch.length) % total;
	saveCursor(nextCursor);

	console.log(`🔍 [GSC Index Monitor] Inspeccionando ${batch.length} URLs (cohorte ${cursor + 1} a ${cursor + batch.length} de ${total})...`);

	const today = new Date().toISOString().split('T')[0];
	const tempInput = path.join(REPORTS_DIR, `temp-gsc-input-${Date.now()}.json`);
	const reportOutput = path.join(REPORTS_DIR, `gsc-daily-index-status-${today}.json`);

	fs.writeFileSync(tempInput, JSON.stringify(batch));

	try {
		const res = spawnSync(
			process.execPath,
			[INSPECTOR_SCRIPT, '--urls', tempInput, '--output', reportOutput, '--delay-ms', '200'],
			{ stdio: 'inherit' }
		);

		if (res.status === 0) {
			console.log(`\n✅ [GSC Index Monitor] Reporte diario generado en: ${reportOutput}`);
			console.log(`📌 Siguiente cohorte continuará en el índice ${nextCursor + 1}.\n`);
		} else {
			console.error(`⚠️ [GSC Index Monitor] Proceso finalizó con advertencias (código ${res.status}).`);
		}
	} finally {
		if (fs.existsSync(tempInput)) fs.unlinkSync(tempInput);
	}
}

main();
