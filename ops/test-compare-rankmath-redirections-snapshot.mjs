#!/usr/bin/env node

import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
	EXPECTED_TARGET,
	canonicalExactSource,
	compareSnapshot,
	logicalKey,
	parseCsv,
	validateTarget,
} from './compare-rankmath-redirections-snapshot.mjs';
import {
	rowPayloadSha256,
	visibleRowPayload,
} from './rankmath-redirections-row-fingerprint.mjs';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const targetPath = path.join(scriptDir, 'rankmath-redirections-ui-final.csv');
const cliPath = path.join(scriptDir, 'compare-rankmath-redirections-snapshot.mjs');
const targetCsv = fs.readFileSync(targetPath, 'utf8');
const targetRows = parseCsv(targetCsv);

function hash(file) {
	return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

function remoteFromTarget(row, index) {
	const destination = row.destination
		? `https://akibara.cl${row.destination}`
		: '';
	return {
		id: `REMOTE-${10000 - index}`,
		sources: [{
			pattern: row.matching === 'exact' ? `/${row.source}/` : ` ${row.source} `,
			comparison: row.matching.toUpperCase(),
			ignore: row.ignore,
		}],
		url_to: destination,
		header_code: Number(row.type),
		status: row.status.toUpperCase(),
	};
}

test(`target manifest passes the fixed ${EXPECTED_TARGET.total}-rule gate`, () => {
	const comparison = compareSnapshot({
		targetCsv,
		snapshot: targetRows.map(remoteFromTarget),
	});
	assert.equal(comparison.result, 'PASS');
	assert.deepEqual(comparison.counts, {
		keep: EXPECTED_TARGET.total,
		edit: 0,
		create: 0,
		delete: 0,
	});
	assert.equal(comparison.target.validation.pass, true);
	assert.equal(comparison.projected.pass, true);
	assert.deepEqual(comparison.target.validation.assertions.find(
		(assertion) => assertion.name === 'ignore distribution'
	)?.actual, { ...EXPECTED_TARGET.ignore });
	assert.deepEqual(comparison.target.validation.assertions.find(
		(assertion) => assertion.name === 'forbidden retired logical keys'
	)?.actual, []);
});

test('legacy or empty remote ignore values are explicit edits to case', () => {
	for (const observed of ['exact', '', undefined]) {
		const snapshot = targetRows.map(remoteFromTarget);
		if (observed === undefined) {
			delete snapshot[0].sources[0].ignore;
		} else {
			snapshot[0].sources[0].ignore = observed;
		}
			const comparison = compareSnapshot({ targetCsv, snapshot });
			assert.equal(comparison.result, 'DRIFT');
			assert.equal(comparison.counts.edit, 1);
			assert.equal(comparison.counts.keep, EXPECTED_TARGET.total - 1);
		assert.deepEqual(
			comparison.actions.edit[0].differences,
			[{ field: 'ignore', observed: observed ?? '', expected: 'case' }]
		);
		assert.equal(comparison.actions.edit[0].observed_ignore, observed ?? '');
	}
});

test('target and observed ignore contracts fail closed on unsupported values', () => {
	const invalidTarget = targetCsv.replace(/,case,/, ',exact,');
	assert.throws(
		() => compareSnapshot({ targetCsv: invalidTarget, snapshot: targetRows.map(remoteFromTarget) }),
		/Unsupported target ignore mode/
	);

	const snapshot = targetRows.map(remoteFromTarget);
	snapshot[0].sources[0].ignore = 'yes';
	const comparison = compareSnapshot({ targetCsv, snapshot });
	assert.equal(comparison.result, 'FAIL');
	assert.equal(comparison.remote.malformed_rows.length, 1);
	assert.match(comparison.remote.malformed_rows[0].reason, /Unsupported observed ignore mode/);
});

test('row payload fingerprints distinguish empty, legacy exact, and case ignore', () => {
	const row = {
		sources: [{ pattern: 'ruta', comparison: 'exact' }],
		url_to: '/destino/',
		header_code: '301',
		status: 'active',
	};
	const empty = visibleRowPayload(row);
	const legacy = visibleRowPayload({
		...row,
		sources: [{ ...row.sources[0], ignore: 'exact' }],
	});
	const supported = visibleRowPayload({
		...row,
		sources: [{ ...row.sources[0], ignore: 'case' }],
	});
	assert.equal(empty.sources[0].ignore, '');
	assert.equal(legacy.sources[0].ignore, 'exact');
	assert.equal(supported.sources[0].ignore, 'case');
	assert.equal(new Set([
		rowPayloadSha256(empty),
		rowPayloadSha256(legacy),
		rowPayloadSha256(supported),
	]).size, 3);
});

test('matching is independent of IDs and exact slash representation', () => {
	assert.equal(canonicalExactSource('///Serie//ChainsawMan///'), 'Serie/ChainsawMan');
	assert.equal(logicalKey('/SERIE/chainsawman/', 'EXACT'), 'exact|serie/chainsawman');
	const snapshot = targetRows.map(remoteFromTarget).reverse();
	snapshot.forEach((row, index) => {
		row.id = index % 2 === 0 ? `arbitrary-${index}` : 999999 - index;
	});
	const comparison = compareSnapshot({ targetCsv, snapshot });
	assert.equal(comparison.result, 'PASS');
	assert.equal(comparison.counts.keep, EXPECTED_TARGET.total);
});

test('target validation explicitly rejects retired gift card logical keys', () => {
	const normalized = targetRows.map((row, index) => ({
		target_row: index + 2,
		source: row.source,
		matching: row.matching,
		logical_key: logicalKey(row.source, row.matching),
		ignore: row.ignore,
		destination: row.destination,
		type: row.type,
		status: row.status,
	}));
	for (const source of [
		'gift-card',
		'product-category/gift-card',
		'product/gift-card',
		'serie/giftcard',
	]) {
		const validation = validateTarget([
			...normalized,
			{
				target_row: normalized.length + 2,
				source,
				matching: 'exact',
				logical_key: logicalKey(source, 'exact'),
				ignore: 'case',
				destination: '',
				type: '410',
				status: 'active',
			},
		]);
		const assertion = validation.assertions.find(
			(candidate) => candidate.name === 'forbidden retired logical keys'
		);
		assert.equal(assertion.pass, false);
		assert.deepEqual(assertion.actual, [`exact|${source}`]);
	}
});

test('reports edit/create/delete/duplicates while preserving regex and terminal semantics', () => {
	const snapshot = targetRows.map(remoteFromTarget);
	const missingIndex = snapshot.findIndex((row) => (
		logicalKey(row.sources[0].pattern, row.sources[0].comparison)
		=== 'exact|packs'
	));
	const [missing] = snapshot.splice(missingIndex, 1);
	assert.equal(logicalKey(
		missing.sources[0].pattern,
		missing.sources[0].comparison
	), 'exact|packs');

	const regex = snapshot.find((row) => row.sources[0].comparison.toLowerCase() === 'regex');
	assert.equal(regex.sources[0].pattern.trim(), '^mis-reservas/?$');
	assert.equal(regex.header_code, 410);
	assert.equal(regex.url_to, '');

	const edit = snapshot.find((row) => (
		logicalKey(row.sources[0].pattern, row.sources[0].comparison) === 'exact|wishlist'
	));
	edit.url_to = 'https://akibara.cl/tienda/';
	edit.status = 'inactive';

	const duplicateSource = snapshot.find((row) => (
		logicalKey(row.sources[0].pattern, row.sources[0].comparison) ===
		'exact|autor/tatsukia-fujimoto'
	));
	snapshot.push({
		...structuredClone(duplicateSource),
		id: 'duplicate-with-unrelated-id',
		sources: [{ pattern: 'autor//tatsukia-fujimoto/', comparison: 'exact' }],
	});
	snapshot.push({
		id: 'extra',
		sources: [{ pattern: 'legacy/not-approved', comparison: 'exact' }],
		url_to: '/catalogo/',
		header_code: '301',
		status: 'active',
	});

	const comparison = compareSnapshot({ targetCsv, snapshot });
	assert.equal(comparison.result, 'DRIFT');
	assert.deepEqual(comparison.counts, {
		keep: EXPECTED_TARGET.total - 2,
		edit: 1,
		create: 1,
		delete: 2,
	});
	assert.equal(comparison.duplicates.length, 1);
	assert.equal(comparison.actions.create[0].logical_key, 'exact|packs');
	assert.deepEqual(
		comparison.actions.edit[0].differences.map((difference) => difference.field).sort(),
		['destination', 'status']
	);
	assert.ok(comparison.actions.delete.some((row) => row.reason === 'duplicate_logical_key'));
	assert.ok(comparison.actions.delete.some((row) => row.reason === 'not_in_target_manifest'));
	assert.equal(
		comparison.actions.keep.some((row) => row.logical_key === 'regex|^mis-reservas/?$'),
		true
	);
});

test('a stale terminal 410 destination is an edit, not a malformed row', () => {
	const snapshot = targetRows.map(remoteFromTarget);
	const terminal = snapshot.find((row) => (
		logicalKey(row.sources[0].pattern, row.sources[0].comparison) ===
		'exact|rastrear'
	));
	terminal.url_to = '/tienda/';
	terminal.header_code = 301;
	const comparison = compareSnapshot({ targetCsv, snapshot });
	assert.equal(comparison.result, 'DRIFT');
	assert.equal(comparison.remote.malformed_rows.length, 0);
	assert.equal(comparison.counts.edit, 1);
	assert.equal(comparison.actions.edit[0].logical_key, 'exact|rastrear');
	assert.deepEqual(
		comparison.actions.edit[0].differences.map((difference) => difference.field).sort(),
		['destination', 'type']
	);
});

test('multi-source remote rows fail safely instead of emitting ambiguous UI actions', () => {
	const snapshot = targetRows.map(remoteFromTarget);
	snapshot[0].sources.push({ pattern: 'another-source', comparison: 'exact' });
	const comparison = compareSnapshot({ targetCsv, snapshot });
	assert.equal(comparison.result, 'FAIL');
	assert.equal(comparison.remote.malformed_rows.length, 1);
	assert.match(comparison.remote.malformed_rows[0].reason, /exactly one source/);
});

test('the two exact reviewed source-less payloads become trash-only actions without ID identity', () => {
	const reviewedRows = [
		{
			id: 'arbitrary-transient-a',
			sources: false,
			url_to: 'http://localhost:8080/mi-cuenta/',
			header_code: '301',
			status: 'active',
			created: '2026-04-28 03:24:44',
			updated: '2026-04-28 03:24:44',
			last_accessed: '0000-00-00 00:00:00',
			hits: '0',
		},
		{
			id: 'arbitrary-transient-b',
			sources: false,
			url_to: 'http://localhost:8080/mi-cuenta/',
			header_code: '301',
			status: 'active',
			created: '2026-05-04 01:25:12',
			updated: '2026-05-04 01:25:12',
			last_accessed: '0000-00-00 00:00:00',
			hits: '0',
		},
	];
	const comparison = compareSnapshot({
		targetCsv,
		snapshot: [...targetRows.map(remoteFromTarget), ...reviewedRows],
	});
	assert.equal(comparison.result, 'DRIFT');
	assert.equal(comparison.remote.malformed_rows.length, 0);
	assert.equal(comparison.remote.reviewed_malformed_rows.length, 2);
	assert.equal(comparison.counts.delete, 2);
	assert.equal(comparison.actions.delete.every((row) =>
		row.reason === 'reviewed_malformed'
		&& row.logical_key === `reviewed_malformed|${row.payload_sha256}`
		&& /^[a-f0-9]{64}$/u.test(row.payload_sha256)), true);
	assert.equal(comparison.actions.delete.some((row) =>
		row.logical_key.includes('223') || row.logical_key.includes('228')), false);
});

test('any drift in the reviewed visible malformed payload fails closed', () => {
	const snapshot = targetRows.map(remoteFromTarget);
	snapshot.push({
		id: '223',
		sources: false,
		url_to: 'http://localhost:8080/mi-cuenta/',
		header_code: '301',
		status: 'active',
		created: '2026-04-28 03:24:44',
		updated: '2026-04-28 03:24:44',
		last_accessed: '0000-00-00 00:00:00',
		hits: '0',
	});
	snapshot.at(-1).url_to = 'http://localhost:8080/otra-ruta/';
	const comparison = compareSnapshot({ targetCsv, snapshot });
	assert.equal(comparison.result, 'FAIL');
	assert.equal(comparison.remote.reviewed_malformed_rows.length, 0);
	assert.equal(comparison.remote.malformed_rows.length, 1);
});

test('a third copy of the reviewed malformed payload fails multiplicity closed', () => {
	const reviewed = {
		sources: false,
		url_to: 'http://localhost:8080/mi-cuenta/',
		header_code: '301',
		status: 'active',
		created: '2026-04-28 03:24:44',
		updated: '2026-04-28 03:24:44',
		last_accessed: '0000-00-00 00:00:00',
		hits: '0',
	};
	const comparison = compareSnapshot({
		targetCsv,
		snapshot: [
			...targetRows.map(remoteFromTarget),
			{ ...reviewed, id: 1 },
			{ ...reviewed, id: 2 },
			{ ...reviewed, id: 3 },
		],
	});
	assert.equal(comparison.result, 'FAIL');
	assert.equal(comparison.remote.reviewed_malformed_rows.length, 0);
	assert.equal(comparison.remote.malformed_rows.length, 3);
});

test('target validation rejects duplicate logical keys', () => {
	const normalized = targetRows.map((row, index) => ({
		target_row: index + 2,
		source: row.source,
		matching: row.matching,
		logical_key: logicalKey(row.source, row.matching),
		ignore: row.ignore,
		destination: row.destination,
		type: row.type,
		status: row.status,
	}));
	normalized[1] = { ...normalized[0], target_row: 3 };
	const validation = validateTarget(normalized);
	assert.equal(validation.pass, false);
	assert.equal(
		validation.assertions.find((assertion) => assertion.name === 'duplicate logical keys').pass,
		false
	);
});

test('CLI writes only a new local report and preserves the target artifact', () => {
	const temporaryRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'akibara-rankmath-compare-'));
	try {
		const snapshotPath = path.join(temporaryRoot, 'fresh.json');
		const outputDir = path.join(temporaryRoot, 'report');
		fs.writeFileSync(
			snapshotPath,
			`${JSON.stringify(targetRows.map(remoteFromTarget), null, 2)}\n`
		);
		const before = hash(targetPath);
		const result = spawnSync(process.execPath, [
			cliPath,
			'--snapshot', snapshotPath,
			'--target', targetPath,
			'--output-dir', outputDir,
			'--expect', 'clean',
		], { encoding: 'utf8' });
		assert.equal(result.status, 0, result.stderr || result.stdout);
		assert.equal(hash(targetPath), before);
		for (const file of [
			'comparison.json', 'summary.md', 'keep.csv', 'edit.csv',
			'create.csv', 'delete.csv', 'duplicates.csv',
		]) {
			assert.equal(fs.existsSync(path.join(outputDir, file)), true, file);
		}
		const report = JSON.parse(fs.readFileSync(path.join(outputDir, 'comparison.json')));
		assert.equal(report.result, 'PASS');

		const overwrite = spawnSync(process.execPath, [
			cliPath,
			'--snapshot', snapshotPath,
			'--target', targetPath,
			'--output-dir', outputDir,
		], { encoding: 'utf8' });
		assert.equal(overwrite.status, 2);
		assert.match(overwrite.stderr, /Refusing to overwrite/);
	} finally {
		fs.rmSync(temporaryRoot, { recursive: true, force: true });
	}
});

test('CLI refuses every output directory inside ops', () => {
	const temporaryRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'akibara-rankmath-input-'));
	const unsafeOutput = path.join(scriptDir, '.forbidden-comparison-report');
	try {
		const snapshotPath = path.join(temporaryRoot, 'fresh.json');
		fs.writeFileSync(snapshotPath, JSON.stringify(targetRows.map(remoteFromTarget)));
		const result = spawnSync(process.execPath, [
			cliPath,
			'--snapshot', snapshotPath,
			'--target', targetPath,
			'--output-dir', unsafeOutput,
		], { encoding: 'utf8' });
		assert.equal(result.status, 2);
		assert.match(result.stderr, /Refusing to write reports inside ops/);
		assert.equal(fs.existsSync(unsafeOutput), false);
	} finally {
		fs.rmSync(temporaryRoot, { recursive: true, force: true });
	}
});

test('CLI cannot bypass the ops guard through a symbolic link', () => {
	const temporaryRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'akibara-rankmath-symlink-'));
	try {
		const snapshotPath = path.join(temporaryRoot, 'fresh.json');
		const opsAlias = path.join(temporaryRoot, 'ops-alias');
		const unsafeOutput = path.join(opsAlias, '.forbidden-via-symlink');
		fs.writeFileSync(snapshotPath, JSON.stringify(targetRows.map(remoteFromTarget)));
		fs.symlinkSync(scriptDir, opsAlias, 'dir');
		const result = spawnSync(process.execPath, [
			cliPath,
			'--snapshot', snapshotPath,
			'--target', targetPath,
			'--output-dir', unsafeOutput,
		], { encoding: 'utf8' });
		assert.equal(result.status, 2);
		assert.match(result.stderr, /Refusing to write reports inside ops/);
		assert.equal(fs.existsSync(path.join(scriptDir, '.forbidden-via-symlink')), false);
	} finally {
		fs.rmSync(temporaryRoot, { recursive: true, force: true });
	}
});
