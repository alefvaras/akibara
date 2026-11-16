#!/usr/bin/env node

/**
 * Compare a fresh, read-only Rank Math redirections snapshot with the
 * UI-approved target manifest.
 *
 * The comparison is deliberately ID-independent: rows are matched only by the
 * normalized logical key `<matching>|<source>`. Remote IDs are retained solely
 * as operator evidence in the generated local report.
 *
 * This program never connects to WordPress and refuses to write reports below
 * ops/, so it cannot mutate Rank Math or overwrite the approved artifacts.
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import {
	reviewedMalformedReason,
	rowPayloadSha256,
	visibleRowPayload,
} from './rankmath-redirections-row-fingerprint.mjs';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const defaultTargetPath = path.join(scriptDir, 'rankmath-redirections-ui-final.csv');

export const EXPECTED_TARGET = Object.freeze({
	// 2026-07-27: 171 + 2 reglas vivas reincorporadas tras auditar hits reales
	// (RM-560 post renombrado de Jujutsu Kaisen, RM-233 One Piece 3-en-1 tomo 1).
	// Ambas viven en producción con ignore="" (sensible a mayúsculas); el
	// manifiesto las refleja tal cual para no fabricar un edit innecesario.
	// 2026-07-29: +2 altas (173 → 175). La migración masiva de slugs de serie
	// (~50 reglas `serie/<singuiones>` → `/serie/<con-guiones>/`) dejó fuera
	// `serie/jujutsukaisen`, que sigue devolviendo 404 con 97 impresiones en
	// posición 8,1 (GSC 90 días), y `serie/haikyuu-2`. Ambas son 301 exact/case,
	// coherentes con el resto del lote.
	// 2026-08-04: +42 altas (175 → 217). Normalización de la convención de slug
	// de producto `{serie}-{N}-{marca}`: 40 renombres (39 del lote + invencible-03)
	// más 2 altas previas de otras sesiones (maximum-berserk-20, prison-school-02).
	// Cada renombre exige su 301, así que el conjunto cerrado crece con el catálogo.
	// El modo `ignore` de las 42 se homogeneizó a `case` (estándar del carril);
	// siguen preservadas con ignore="" solo RM-560 y RM-233 por decisión del 27-jul.
	total: 217,
	status: Object.freeze({ active: 217 }),
	matching: Object.freeze({ exact: 216, regex: 1 }),
	ignore: Object.freeze({ case: 215, '': 2 }),
	type: Object.freeze({ 301: 211, 410: 6 }),
});

export const FORBIDDEN_TARGET_LOGICAL_KEYS = Object.freeze([
	'exact|gift-card',
	'exact|product-category/gift-card',
	'exact|product/gift-card',
	'exact|serie/giftcard',
]);

const defaultInternalHosts = Object.freeze([
	'akibara.cl',
	'www.akibara.cl',
	'localhost',
	'127.0.0.1',
	'wordpress',
]);

const reportFiles = Object.freeze([
	'comparison.json',
	'summary.md',
	'keep.csv',
	'edit.csv',
	'create.csv',
	'delete.csv',
	'duplicates.csv',
]);

function countBy(rows, key) {
	return Object.fromEntries(
		[...rows.reduce((counts, row) => {
			const value = String(row[key]);
			counts.set(value, (counts.get(value) ?? 0) + 1);
			return counts;
		}, new Map()).entries()].sort(([left], [right]) => left.localeCompare(right))
	);
}

export function parseCsv(text) {
	const rows = [];
	let row = [];
	let cell = '';
	let quoted = false;

	for (let index = 0; index < text.length; index += 1) {
		const character = text[index];
		if (quoted) {
			if (character === '"') {
				if (text[index + 1] === '"') {
					cell += '"';
					index += 1;
				} else {
					quoted = false;
				}
			} else {
				cell += character;
			}
			continue;
		}

		if (character === '"') {
			if (cell !== '') {
				throw new Error('Invalid CSV: quote must start an empty cell');
			}
			quoted = true;
		} else if (character === ',') {
			row.push(cell);
			cell = '';
		} else if (character === '\n') {
			if (cell.endsWith('\r')) {
				cell = cell.slice(0, -1);
			}
			row.push(cell);
			rows.push(row);
			row = [];
			cell = '';
		} else {
			cell += character;
		}
	}

	if (quoted) {
		throw new Error('Invalid CSV: unterminated quoted field');
	}
	if (cell !== '' || row.length > 0) {
		row.push(cell);
		rows.push(row);
	}
	while (rows.length > 0 && rows.at(-1).every((value) => value === '')) {
		rows.pop();
	}
	if (rows.length === 0) {
		throw new Error('Invalid CSV: empty document');
	}

	const [headers, ...values] = rows;
	if (new Set(headers).size !== headers.length || headers.some((header) => !header)) {
		throw new Error('Invalid CSV: headers must be non-empty and unique');
	}
	return values.map((cells, rowIndex) => {
		if (cells.length !== headers.length) {
			throw new Error(
				`Invalid CSV: row ${rowIndex + 2} has ${cells.length} cells; expected ${headers.length}`
			);
		}
		return Object.fromEntries(headers.map((header, index) => [header, cells[index]]));
	});
}

export function canonicalExactSource(value) {
	return String(value ?? '')
		.trim()
		.replace(/^\/+|\/+$/g, '')
		.replace(/\/+/g, '/');
}

export function normalizeMatching(value) {
	const matching = String(value ?? '').trim().toLowerCase();
	if (!['exact', 'regex'].includes(matching)) {
		throw new Error(`Unsupported matching mode: ${JSON.stringify(value)}`);
	}
	return matching;
}

export function canonicalSource(value, matching) {
	const normalizedMatching = normalizeMatching(matching);
	const source = normalizedMatching === 'exact'
		? canonicalExactSource(value)
		: String(value ?? '').trim();
	if (!source) {
		throw new Error('Source cannot be empty');
	}
	return source;
}

/**
 * Rank Math's supported CSV/UI contract uses only the literal `case` or an
 * empty value. Target manifests must not carry historical database artefacts
 * such as `exact`.
 */
export function normalizeTargetIgnore(value) {
	const ignore = String(value ?? '');
	if (!['', 'case'].includes(ignore)) {
		throw new Error(`Unsupported target ignore mode: ${JSON.stringify(value)}`);
	}
	return ignore;
}

/**
 * Remote snapshots can contain the historical invalid value `exact`. It is
 * retained as observed state so reconciliation emits an official-UI edit to
 * the reviewed target value instead of treating it as already normalized.
 */
export function normalizeObservedIgnore(value) {
	const ignore = String(value ?? '');
	if (!['', 'case', 'exact'].includes(ignore)) {
		throw new Error(`Unsupported observed ignore mode: ${JSON.stringify(value)}`);
	}
	return ignore;
}

export function logicalKey(source, matching) {
	const normalizedMatching = normalizeMatching(matching);
	return `${normalizedMatching}|${canonicalSource(source, normalizedMatching).toLowerCase()}`;
}

export function canonicalDestination(value, internalHosts = defaultInternalHosts) {
	const destination = String(value ?? '').trim();
	if (!/^https?:\/\//i.test(destination)) {
		return destination;
	}

	let url;
	try {
		url = new URL(destination);
	} catch {
		throw new Error(`Invalid absolute destination URL: ${destination}`);
	}
	const hosts = new Set(internalHosts.map((host) => String(host).toLowerCase()));
	if (!hosts.has(url.hostname.toLowerCase())) {
		return destination;
	}
	return `${url.pathname || '/'}${url.search}${url.hash}`;
}

function normalizeType(value) {
	const type = String(value ?? '').trim();
	if (!['301', '302', '307', '410', '451'].includes(type)) {
		throw new Error(`Unsupported redirect type: ${JSON.stringify(value)}`);
	}
	return type;
}

function normalizeStatus(value) {
	const status = String(value ?? '').trim().toLowerCase();
	if (!['active', 'inactive', 'trashed'].includes(status)) {
		throw new Error(`Unsupported redirect status: ${JSON.stringify(value)}`);
	}
	return status;
}

function normalizeTargetRow(row, index, internalHosts) {
	for (const column of ['source', 'matching', 'ignore', 'destination', 'type', 'status']) {
		if (!(column in row)) {
			throw new Error(`Target CSV is missing required column: ${column}`);
		}
	}
	const matching = normalizeMatching(row.matching);
	const source = canonicalSource(row.source, matching);
	return {
		target_row: index + 2,
		source,
		matching,
		logical_key: logicalKey(source, matching),
		ignore: normalizeTargetIgnore(row.ignore),
		destination: canonicalDestination(row.destination, internalHosts),
		type: normalizeType(row.type),
		status: normalizeStatus(row.status),
	};
}

function snapshotRows(snapshot) {
	if (Array.isArray(snapshot)) {
		return snapshot;
	}
	for (const key of ['rows', 'redirections', 'data']) {
		if (Array.isArray(snapshot?.[key])) {
			return snapshot[key];
		}
	}
	throw new Error('Snapshot JSON must be an array or contain rows/redirections/data array');
}

function parseSources(value) {
	if (Array.isArray(value)) {
		return value;
	}
	if (typeof value === 'string') {
		try {
			const parsed = JSON.parse(value);
			return Array.isArray(parsed) ? parsed : null;
		} catch {
			return null;
		}
	}
	return null;
}

function remoteEvidence(row, index) {
	return {
		remote_id: row?.id == null ? '' : String(row.id),
		remote_row: index + 1,
	};
}

function normalizeRemoteRows(snapshot, internalHosts) {
	const valid = [];
	const malformed = [];
	const reviewedMalformed = [];
	const rows = snapshotRows(snapshot);

	rows.forEach((row, index) => {
		const evidence = remoteEvidence(row, index);
		const visiblePayload = visibleRowPayload(row);
		const payloadSha256 = rowPayloadSha256(visiblePayload);
		try {
			if (!row || typeof row !== 'object' || Array.isArray(row)) {
				throw new Error('Remote row must be an object');
			}
			const sources = parseSources(row.sources);
			if (!sources || sources.length !== 1) {
				throw new Error(
					`Remote row must contain exactly one source; observed ${sources?.length ?? 'invalid'}`
				);
			}
			const sourceObject = sources[0];
			if (!sourceObject || typeof sourceObject !== 'object') {
				throw new Error('Remote source must be an object');
			}
			const matching = normalizeMatching(sourceObject.comparison ?? 'exact');
			const source = canonicalSource(sourceObject.pattern, matching);
			const ignore = normalizeObservedIgnore(sourceObject.ignore);
			valid.push({
				...evidence,
				source,
				matching,
				logical_key: logicalKey(source, matching),
				ignore,
				destination: canonicalDestination(row.url_to, internalHosts),
				type: normalizeType(row.header_code),
				status: normalizeStatus(row.status),
				observed_source: String(sourceObject.pattern ?? ''),
				observed_ignore: String(sourceObject.ignore ?? ''),
				observed_destination: String(row.url_to ?? ''),
			});
		} catch (error) {
			const reviewedReason = reviewedMalformedReason(visiblePayload);
			const target = reviewedReason ? reviewedMalformed : malformed;
			target.push({
				...evidence,
				reason: reviewedReason ?? error.message,
				payload: visiblePayload,
				payload_sha256: payloadSha256,
			});
		}
	});
	const reviewedFingerprints = new Set(reviewedMalformed.map((row) => row.payload_sha256));
	if (reviewedMalformed.length > 2 || reviewedFingerprints.size > 1) {
		malformed.push(...reviewedMalformed.map((row) => ({
			...row,
			reason: 'Reviewed malformed set must contain at most two identical visible payloads',
		})));
		reviewedMalformed.length = 0;
	}

	return { valid, malformed, reviewedMalformed, total: rows.length };
}

function differences(target, remote) {
	const fields = ['ignore', 'destination', 'type', 'status'];
	return fields
		.filter((field) => target[field] !== remote[field])
		.map((field) => ({
			field,
			observed: remote[field],
			expected: target[field],
		}));
}

function candidateOrder(target, left, right) {
	const leftDiffs = differences(target, left).length;
	const rightDiffs = differences(target, right).length;
	if (leftDiffs !== rightDiffs) {
		return leftDiffs - rightDiffs;
	}
	if ((left.status === 'active') !== (right.status === 'active')) {
		return left.status === 'active' ? -1 : 1;
	}
	return left.remote_row - right.remote_row;
}

function actionBase(target, remote) {
	return {
		logical_key: target.logical_key,
		source: target.source,
		matching: target.matching,
		ignore: target.ignore,
		destination: target.destination,
		type: target.type,
		status: target.status,
		remote_id: remote?.remote_id ?? '',
		remote_row: remote?.remote_row ?? '',
		observed_source: remote?.observed_source ?? '',
		observed_ignore: remote?.observed_ignore ?? '',
		observed_destination: remote?.observed_destination ?? '',
		observed_type: remote?.type ?? '',
		observed_status: remote?.status ?? '',
	};
}

function duplicateGroups(remoteByKey) {
	return [...remoteByKey.entries()]
		.filter(([, rows]) => rows.length > 1)
			.map(([key, rows]) => ({
				logical_key: key,
				remote_count: rows.length,
				remote_ids: rows.map((row) => row.remote_id).filter(Boolean),
				remote_rows: rows.map((row) => row.remote_row),
				sources: rows.map((row) => row.observed_source),
				ignore_modes: rows.map((row) => row.ignore),
			}))
		.sort((left, right) => left.logical_key.localeCompare(right.logical_key));
}

export function validateTarget(targetRows) {
	const assertions = [];
	const push = (name, expected, actual) => {
		// Serialización con claves ordenadas: dos distribuciones idénticas no
		// deben fallar por el orden de inserción de sus claves.
		const canonical = (value) =>
			JSON.stringify(value, (key, inner) =>
				inner && typeof inner === 'object' && !Array.isArray(inner)
					? Object.fromEntries(Object.entries(inner).sort(([a], [b]) => a.localeCompare(b)))
					: inner
			);
		assertions.push({ name, expected, actual, pass: canonical(expected) === canonical(actual) });
	};

	const groups = Map.groupBy(targetRows, (row) => row.logical_key);
	const duplicateKeys = [...groups.entries()]
		.filter(([, rows]) => rows.length > 1)
		.map(([key]) => key)
		.sort();
	push('total rows', EXPECTED_TARGET.total, targetRows.length);
	push('unique logical keys', EXPECTED_TARGET.total, groups.size);
	push('duplicate logical keys', [], duplicateKeys);
	push('status distribution', EXPECTED_TARGET.status, countBy(targetRows, 'status'));
	push('matching distribution', EXPECTED_TARGET.matching, countBy(targetRows, 'matching'));
	push('ignore distribution', EXPECTED_TARGET.ignore, countBy(targetRows, 'ignore'));
	push('type distribution', EXPECTED_TARGET.type, countBy(targetRows, 'type'));

	const regexRows = targetRows.filter((row) => row.matching === 'regex');
	push(
		'sole regex rule',
		[{ logical_key: 'regex|^mis-reservas/?$', ignore: 'case', destination: '', type: '410', status: 'active' }],
		regexRows.map(({ logical_key, ignore, destination, type, status }) => ({
			logical_key,
			ignore,
			destination,
			type,
			status,
		}))
	);

	const forbiddenRetiredKeys = targetRows
		.filter((row) => FORBIDDEN_TARGET_LOGICAL_KEYS.includes(row.logical_key))
		.map((row) => row.logical_key)
		.sort();
	push(
		'forbidden retired logical keys',
		[],
		forbiddenRetiredKeys
	);

	const invalidDestinations = targetRows
		.filter((row) => (
			(row.type === '301' && row.destination === '') ||
			(['410', '451'].includes(row.type) && row.destination !== '')
		))
		.map((row) => row.logical_key)
		.sort();
	push('destination semantics', [], invalidDestinations);

	return {
		pass: assertions.every((assertion) => assertion.pass),
		assertions,
	};
}

export function compareSnapshot({
	targetCsv,
	snapshot,
	internalHosts = defaultInternalHosts,
}) {
	const rawTargetRows = parseCsv(targetCsv);
	const targetRows = rawTargetRows.map((row, index) => normalizeTargetRow(row, index, internalHosts));
	const targetValidation = validateTarget(targetRows);
	const remote = normalizeRemoteRows(snapshot, internalHosts);

	const targetByKey = Map.groupBy(targetRows, (row) => row.logical_key);
	const remoteByKey = Map.groupBy(remote.valid, (row) => row.logical_key);
	const duplicates = duplicateGroups(remoteByKey);
	const actions = { keep: [], edit: [], create: [], delete: [] };
	const consumedRemoteRows = new Set();

	for (const target of targetRows) {
		const candidates = [...(remoteByKey.get(target.logical_key) ?? [])];
		const editable = candidates
			.filter((candidate) => candidate.status !== 'trashed')
			.sort((left, right) => candidateOrder(target, left, right));
		const keeper = editable[0];

		if (!keeper) {
			actions.create.push({
				...actionBase(target),
				reason: candidates.length > 0 ? 'only_trashed_remote_rows' : 'missing_remote_rule',
			});
		} else {
			consumedRemoteRows.add(keeper.remote_row);
			const diffs = differences(target, keeper);
			const action = {
				...actionBase(target, keeper),
				differences: diffs,
				reason: diffs.length === 0 ? 'semantic_match' : 'semantic_drift',
			};
			actions[diffs.length === 0 ? 'keep' : 'edit'].push(action);
		}

		for (const candidate of candidates) {
			if (candidate !== keeper) {
				consumedRemoteRows.add(candidate.remote_row);
				actions.delete.push({
					...actionBase(target, candidate),
					reason: candidate.status === 'trashed'
						? 'trashed_row_replaced_or_removed'
						: 'duplicate_logical_key',
				});
			}
		}
	}

	for (const remoteRow of remote.valid) {
		if (consumedRemoteRows.has(remoteRow.remote_row)) {
			continue;
		}
		if (!targetByKey.has(remoteRow.logical_key)) {
			consumedRemoteRows.add(remoteRow.remote_row);
			actions.delete.push({
				logical_key: remoteRow.logical_key,
				source: remoteRow.source,
				matching: remoteRow.matching,
				ignore: '',
				destination: '',
				type: '',
				status: '',
				remote_id: remoteRow.remote_id,
				remote_row: remoteRow.remote_row,
				observed_source: remoteRow.observed_source,
				observed_ignore: remoteRow.observed_ignore,
				observed_destination: remoteRow.observed_destination,
				observed_type: remoteRow.type,
				observed_status: remoteRow.status,
				reason: 'not_in_target_manifest',
			});
		}
	}
	for (const reviewed of remote.reviewedMalformed) {
		actions.delete.push({
			logical_key: `reviewed_malformed|${reviewed.payload_sha256}`,
			source: '',
			matching: '',
			ignore: '',
			destination: '',
			type: '',
			status: '',
			remote_id: reviewed.remote_id,
			remote_row: reviewed.remote_row,
			observed_source: '',
			observed_ignore: '',
			observed_destination: reviewed.payload.destination,
			observed_type: reviewed.payload.type,
			observed_status: reviewed.payload.status,
			payload: reviewed.payload,
			payload_sha256: reviewed.payload_sha256,
			reason: 'reviewed_malformed',
		});
	}

	const actionCounts = Object.fromEntries(
		Object.entries(actions).map(([name, rows]) => [name, rows.length])
	);
	const hasDrift = actionCounts.edit + actionCounts.create + actionCounts.delete > 0;
	const fatal = !targetValidation.pass || remote.malformed.length > 0;
	const result = fatal ? 'FAIL' : hasDrift ? 'DRIFT' : 'PASS';

	return {
		schema_version: 2,
		result,
		matching_strategy: 'normalized logical key; ignore is an explicit semantic field; IDs are evidence only',
		target: {
			rows: targetRows.length,
			unique_logical_keys: targetByKey.size,
			validation: targetValidation,
		},
		remote: {
			rows: remote.total,
			valid_rows: remote.valid.length,
			malformed_rows: remote.malformed,
			reviewed_malformed_rows: remote.reviewedMalformed,
			duplicate_logical_groups: duplicates.length,
		},
		projected: {
			rows_after_actions: targetRows.length,
			unique_logical_keys_after_actions: targetByKey.size,
			active_after_actions: targetRows.filter((row) => row.status === 'active').length,
			pass:
				targetValidation.pass &&
				targetRows.length === EXPECTED_TARGET.total &&
				targetByKey.size === EXPECTED_TARGET.total,
		},
		counts: actionCounts,
		actions,
		duplicates,
	};
}

function csvCell(value) {
	const string = Array.isArray(value) || (value && typeof value === 'object')
		? JSON.stringify(value)
		: String(value ?? '');
	return /[",\r\n]/.test(string) ? `"${string.replaceAll('"', '""')}"` : string;
}

function serializeCsv(rows, columns) {
	return `${[
		columns.join(','),
		...rows.map((row) => columns.map((column) => csvCell(row[column])).join(',')),
	].join('\n')}\n`;
}

function markdownSummary(report, inputs) {
	const assertions = report.target.validation.assertions
		.map((assertion) => (
			`- ${assertion.pass ? 'PASS' : 'FAIL'} — ${assertion.name}: ` +
			`${JSON.stringify(assertion.actual)} (esperado ${JSON.stringify(assertion.expected)})`
		))
		.join('\n');
	return `# Comparación Rank Math Redirections

- Resultado: **${report.result}**
- Snapshot: \`${inputs.snapshotPath}\`
- Manifiesto objetivo: \`${inputs.targetPath}\`
- Matching: clave lógica normalizada; los IDs se muestran solo como evidencia.
- Remoto: ${report.remote.rows} filas (${report.remote.valid_rows} válidas).
- Conservar: ${report.counts.keep}
- Editar: ${report.counts.edit}
- Crear: ${report.counts.create}
- Eliminar: ${report.counts.delete}
- Grupos duplicados: ${report.remote.duplicate_logical_groups}
- Filas remotas inválidas: ${report.remote.malformed_rows.length}
- Filas malformadas revisadas para papelera: ${report.remote.reviewed_malformed_rows.length}
- Proyección tras aplicar acciones: ${report.projected.rows_after_actions} filas / ${report.projected.unique_logical_keys_after_actions} claves únicas.

	## Gate objetivo ${EXPECTED_TARGET.total}

${assertions}

## Seguridad

Esta comparación es local y read-only respecto de WordPress. No usa IDs para
decidir equivalencias, no escribe base de datos y no modifica archivos de
\`ops/\`. Las acciones son un informe para ejecución posterior mediante la UI
oficial de Rank Math.
`;
}

function isWithin(parent, child) {
	const relative = path.relative(parent, child);
	return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
}

function canonicalProspectivePath(value) {
	let cursor = path.resolve(value);
	const missingSegments = [];
	while (!fs.existsSync(cursor)) {
		const parent = path.dirname(cursor);
		if (parent === cursor) {
			break;
		}
		missingSegments.unshift(path.basename(cursor));
		cursor = parent;
	}
	const existingBase = fs.realpathSync(cursor);
	return path.join(existingBase, ...missingSegments);
}

export function writeReport(report, {
	outputDir,
	snapshotPath,
	targetPath,
	workspace = process.cwd(),
}) {
	const resolvedOutput = path.resolve(outputDir);
	const canonicalOutput = canonicalProspectivePath(resolvedOutput);
	const canonicalOps = fs.realpathSync(path.resolve(workspace, 'ops'));
	if (isWithin(canonicalOps, canonicalOutput)) {
		throw new Error(`Refusing to write reports inside ops/: ${resolvedOutput}`);
	}
	if (fs.existsSync(resolvedOutput)) {
		const collisions = reportFiles.filter((file) => fs.existsSync(path.join(resolvedOutput, file)));
		if (collisions.length > 0) {
			throw new Error(
				`Refusing to overwrite existing local report files: ${collisions.join(', ')}`
			);
		}
	}
	fs.mkdirSync(resolvedOutput, { recursive: true });

	const actionColumns = [
		'logical_key', 'source', 'matching', 'ignore', 'destination', 'type', 'status',
		'remote_id', 'remote_row', 'observed_source', 'observed_ignore', 'observed_destination',
		'observed_type', 'observed_status', 'payload', 'payload_sha256',
		'differences', 'reason',
	];
	const duplicateColumns = [
		'logical_key', 'remote_count', 'remote_ids', 'remote_rows', 'sources', 'ignore_modes',
	];
	const writes = new Map([
		['comparison.json', `${JSON.stringify(report, null, 2)}\n`],
		['summary.md', markdownSummary(report, { snapshotPath, targetPath })],
		['keep.csv', serializeCsv(report.actions.keep, actionColumns)],
		['edit.csv', serializeCsv(report.actions.edit, actionColumns)],
		['create.csv', serializeCsv(report.actions.create, actionColumns)],
		['delete.csv', serializeCsv(report.actions.delete, actionColumns)],
		['duplicates.csv', serializeCsv(report.duplicates, duplicateColumns)],
	]);
	for (const [file, contents] of writes) {
		fs.writeFileSync(path.join(resolvedOutput, file), contents, { flag: 'wx' });
	}
	return [...writes.keys()].map((file) => path.join(resolvedOutput, file));
}

function usage() {
	return `Usage:
  node ops/compare-rankmath-redirections-snapshot.mjs \\
    --snapshot <fresh-remote.json> \\
    --output-dir <local-empty-directory> \\
    [--target ops/rankmath-redirections-ui-final.csv] \\
    [--internal-host <hostname>]... \\
    [--expect any|clean|drift]

Exit codes:
  0  valid report matching --expect
  1  clean/drift expectation mismatch
  2  invalid target, malformed snapshot, unsafe output, or usage error
`;
}

function parseArgs(argv) {
	const options = {
		targetPath: defaultTargetPath,
		expect: 'any',
		internalHosts: [...defaultInternalHosts],
	};
	for (let index = 0; index < argv.length; index += 1) {
		const argument = argv[index];
		if (argument === '--help' || argument === '-h') {
			options.help = true;
		} else if (argument === '--snapshot') {
			options.snapshotPath = argv[++index];
		} else if (argument === '--target') {
			options.targetPath = argv[++index];
		} else if (argument === '--output-dir') {
			options.outputDir = argv[++index];
		} else if (argument === '--expect') {
			options.expect = argv[++index];
		} else if (argument === '--internal-host') {
			options.internalHosts.push(argv[++index]);
		} else {
			throw new Error(`Unknown argument: ${argument}`);
		}
	}
	if (!options.help) {
		if (!options.snapshotPath || !options.outputDir) {
			throw new Error('--snapshot and --output-dir are required');
		}
		if (!['any', 'clean', 'drift'].includes(options.expect)) {
			throw new Error('--expect must be any, clean, or drift');
		}
		if (options.internalHosts.some((host) => !host)) {
			throw new Error('--internal-host requires a hostname');
		}
	}
	return options;
}

export function runCli(argv) {
	const options = parseArgs(argv);
	if (options.help) {
		process.stdout.write(usage());
		return 0;
	}
	const targetPath = path.resolve(options.targetPath);
	const snapshotPath = path.resolve(options.snapshotPath);
	const report = compareSnapshot({
		targetCsv: fs.readFileSync(targetPath, 'utf8'),
		snapshot: JSON.parse(fs.readFileSync(snapshotPath, 'utf8')),
		internalHosts: [...new Set(options.internalHosts)],
	});
	const files = writeReport(report, {
		outputDir: options.outputDir,
		snapshotPath,
		targetPath,
	});
	process.stdout.write(`${JSON.stringify({
		result: report.result,
		counts: report.counts,
		duplicates: report.duplicates.length,
		malformed: report.remote.malformed_rows.length,
		report_files: files,
	}, null, 2)}\n`);

	if (report.result === 'FAIL') {
		return 2;
	}
	if (options.expect === 'clean' && report.result !== 'PASS') {
		return 1;
	}
	if (options.expect === 'drift' && report.result !== 'DRIFT') {
		return 1;
	}
	return 0;
}

const isMain = process.argv[1] &&
	path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isMain) {
	try {
		process.exitCode = runCli(process.argv.slice(2));
	} catch (error) {
		process.stderr.write(`ERROR: ${error.message}\n\n${usage()}`);
		process.exitCode = 2;
	}
}
