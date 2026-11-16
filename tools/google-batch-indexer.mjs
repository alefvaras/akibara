#!/usr/bin/env node
/**
 * Retired catalogue Indexing API sender. Read-only legacy notification report.
 *
 * Google limits Indexing API to JobPosting or BroadcastEvent in VideoObject:
 * https://developers.google.com/search/apis/indexing-api/v3/using-api
 * Akibara products, series, brands and hubs are not eligible. A legacy HTTP 200
 * receipt never established index coverage. Preserve the historical state;
 * use sitemaps for discovery and Search Console URL Inspection for index state.
 *
 * This module has no credential, OAuth, network, SSH or state-writing path.
 * `--status` prints only the local historical notification count.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const STATE_FILE = path.join(path.dirname(fileURLToPath(import.meta.url)), 'indexing-state.json');
const RETIRED_MESSAGE = 'Envío de catálogo deshabilitado: Google Indexing API no admite productos, series, marcas ni hubs. Usa sitemaps y URL Inspection de Search Console. --status sólo lee las notificaciones históricas; no mide indexación.';

export function loadState(stateFile = STATE_FILE) {
  if (!fs.existsSync(stateFile)) return { submitted: {}, last_run: null };
  const state = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
  if (!state || typeof state !== 'object' || Array.isArray(state) ||
      !state.submitted || typeof state.submitted !== 'object' || Array.isArray(state.submitted)) {
    throw new Error('Historial de notificaciones inválido; se conserva sin modificar.');
  }
  return { submitted: state.submitted, last_run: state.last_run || null };
}

export function notificationReport(state) {
  return {
    sender_status: 'retired_for_catalogue',
    historical_notification_records: Object.keys(state.submitted).length,
    last_legacy_run: state.last_run,
    index_status: 'not_measured',
    explanation: 'Registros históricos de notificaciones; HTTP 200 no demuestra indexación.'
  };
}

export function run(args = process.argv.slice(2), stateFile = STATE_FILE) {
  if (!args.includes('--status') || args.some(arg => !['--status', '--json'].includes(arg))) {
    throw new Error(RETIRED_MESSAGE);
  }
  const report = notificationReport(loadState(stateFile));
  console.log(JSON.stringify(report, null, 2));
  return report;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  try {
    run();
  } catch (error) {
    console.error(`[Google catalogue sender retirado] ${error.message}`);
    process.exitCode = 1;
  }
}
