#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(scriptDir);
const manifestPath = path.join(
	scriptDir,
	'seo-editorial-shipping-quality-manifest.json'
);
const outputDir = path.join(
	repoRoot,
	'.local-reports',
	'seo-editorial-ui'
);

const outputFiles = {
	shortDescription: 'woocommerce-products-short-description.csv',
	description: 'woocommerce-products-description.csv',
	validation: 'woocommerce-products-import-validation.json',
};

const expectedCounts = {
	shortDescription: 61,
	description: 3,
};

function fail(message) {
	throw new Error(message);
}

export function sha256(value) {
	return crypto.createHash('sha256').update(value, 'utf8').digest('hex');
}

function assertPlainString(value, label) {
	if (typeof value !== 'string') {
		fail(`${label} must be a string`);
	}
	if (value.includes('\0')) {
		fail(`${label} contains a NUL byte`);
	}
}

function assertSha(value, label) {
	if (!/^[a-f0-9]{64}$/u.test(value)) {
		fail(`${label} must be a lowercase SHA-256 digest`);
	}
}

function assertSafeCsvContent(value, label) {
	assertPlainString(value, label);
	if (/^\s*[=+@-]/u.test(value)) {
		fail(`${label} begins with a spreadsheet-formula control character`);
	}
}

function assertEntryHash(entry, fieldName, hashFieldName) {
	assertPlainString(entry[fieldName], `${entry.key}.${fieldName}`);
	assertSha(entry[hashFieldName], `${entry.key}.${hashFieldName}`);
	const actual = sha256(entry[fieldName]);
	if (actual !== entry[hashFieldName]) {
		fail(
			`${entry.key}.${hashFieldName} mismatch: expected ${entry[hashFieldName]}, got ${actual}`
		);
	}
}

function assertNoRankMathEntries(manifest) {
	if (manifest.rank_math_excluded !== true) {
		fail('Manifest must explicitly declare rank_math_excluded=true');
	}
	if (manifest.application_policy?.rank_math_entries !== 0) {
		fail('Manifest must declare application_policy.rank_math_entries=0');
	}

	const rankMathEntry = manifest.entries.find((entry) => {
		const routingMetadata = [
			entry.key,
			entry.field,
			entry.application_lane,
			entry.apply_via,
		]
			.filter((value) => typeof value === 'string')
			.join(' ')
			.toLowerCase();
		return /rank[\s_-]*math/u.test(routingMetadata);
	});

	if (rankMathEntry) {
		fail(`Rank Math entry is not allowed: ${rankMathEntry.key}`);
	}
}

function isWooProductEntry(entry) {
	return (
		entry.object_type === 'post' &&
		entry.application_lane === 'official_ui' &&
		typeof entry.apply_via === 'string' &&
		entry.apply_via.startsWith('WooCommerce > Productos >')
	);
}

function normalizeEntry(entry, expectedField) {
	if (!isWooProductEntry(entry)) {
		fail(`${entry.key} is not routed through the official WooCommerce product UI`);
	}
	if (entry.field !== expectedField) {
		fail(`${entry.key} has unexpected field ${entry.field}`);
	}
	if (!Number.isSafeInteger(entry.object_id) || entry.object_id <= 0) {
		fail(`${entry.key}.object_id must be a positive safe integer`);
	}
	assertPlainString(entry.key, 'entry.key');
	assertEntryHash(entry, 'expected_before', 'expected_before_sha256');
	assertEntryHash(entry, 'proposed_after', 'proposed_after_sha256');
	assertSafeCsvContent(entry.proposed_after, `${entry.key}.proposed_after`);
	if (entry.expected_before === entry.proposed_after) {
		fail(`${entry.key} does not change its current value`);
	}

	return {
		id: entry.object_id,
		field: entry.field,
		key: entry.key,
		title: entry.title,
		expected_before_sha256: entry.expected_before_sha256,
		proposed_after_sha256: entry.proposed_after_sha256,
		value: entry.proposed_after,
	};
}

function assertUniqueRows(rows, label) {
	const ids = new Set();
	for (const row of rows) {
		if (ids.has(row.id)) {
			fail(`${label} contains duplicate product ID ${row.id}`);
		}
		ids.add(row.id);
	}
}

function csvCell(value) {
	const stringValue = String(value);
	return `"${stringValue.replaceAll('"', '""')}"`;
}

export function serializeCsv(header, rows) {
	const lines = [
		header.map(csvCell).join(','),
		...rows.map((row) => row.map(csvCell).join(',')),
	];
	return `${lines.join('\r\n')}\r\n`;
}

function aggregateRowsSha(rows, hashField) {
	const canonical = rows.map((row) => ({
		id: row.id,
		field: row.field,
		sha256: row[hashField],
	}));
	return sha256(JSON.stringify(canonical));
}

export function buildBundle(manifest, sourceText) {
	assertPlainString(sourceText, 'manifest source');
	if (!manifest || typeof manifest !== 'object' || Array.isArray(manifest)) {
		fail('Manifest root must be an object');
	}
	if (!Array.isArray(manifest.entries)) {
		fail('Manifest entries must be an array');
	}
	if (manifest.schema_version !== 1) {
		fail(`Unsupported manifest schema_version: ${manifest.schema_version}`);
	}
	assertNoRankMathEntries(manifest);

	const shortDescriptionRows = manifest.entries
		.filter((entry) => entry.field === 'post_excerpt')
		.map((entry) => normalizeEntry(entry, 'post_excerpt'))
		.sort((left, right) => left.id - right.id);
	const descriptionRows = manifest.entries
		.filter(
			(entry) =>
				entry.field === 'post_content' &&
				typeof entry.apply_via === 'string' &&
				entry.apply_via.startsWith('WooCommerce > Productos >')
		)
		.map((entry) => normalizeEntry(entry, 'post_content'))
		.sort((left, right) => left.id - right.id);

	if (shortDescriptionRows.length !== expectedCounts.shortDescription) {
		fail(
			`Expected ${expectedCounts.shortDescription} Short description rows, got ${shortDescriptionRows.length}`
		);
	}
	if (descriptionRows.length !== expectedCounts.description) {
		fail(
			`Expected ${expectedCounts.description} Description rows, got ${descriptionRows.length}`
		);
	}

	assertUniqueRows(shortDescriptionRows, 'Short description import');
	assertUniqueRows(descriptionRows, 'Description import');

	const allIds = new Map();
	for (const row of [...shortDescriptionRows, ...descriptionRows]) {
		if (allIds.has(row.id)) {
			fail(
				`Product ID ${row.id} appears in both ${allIds.get(row.id)} and ${row.field} imports`
			);
		}
		allIds.set(row.id, row.field);
	}

	const shortDescriptionCsv = serializeCsv(
		['ID', 'Short description'],
		shortDescriptionRows.map((row) => [row.id, row.value])
	);
	const descriptionCsv = serializeCsv(
		['ID', 'Description'],
		descriptionRows.map((row) => [row.id, row.value])
	);

	const validation = {
		schema_version: 1,
		purpose:
			'Fail-closed audit metadata for the two official WooCommerce product UI imports.',
		source: {
			path: 'ops/seo-editorial-shipping-quality-manifest.json',
			sha256: sha256(sourceText),
			schema_version: manifest.schema_version,
			rank_math_excluded: manifest.rank_math_excluded,
		},
		imports: [
			{
				file: outputFiles.shortDescription,
				header: ['ID', 'Short description'],
				row_count: shortDescriptionRows.length,
				csv_sha256: sha256(shortDescriptionCsv),
				expected_before_rows_sha256: aggregateRowsSha(
					shortDescriptionRows,
					'expected_before_sha256'
				),
				proposed_after_rows_sha256: aggregateRowsSha(
					shortDescriptionRows,
					'proposed_after_sha256'
				),
				rows: shortDescriptionRows.map((row) => ({
					id: row.id,
					field: row.field,
					key: row.key,
					expected_before_sha256: row.expected_before_sha256,
					proposed_after_sha256: row.proposed_after_sha256,
				})),
			},
			{
				file: outputFiles.description,
				header: ['ID', 'Description'],
				row_count: descriptionRows.length,
				csv_sha256: sha256(descriptionCsv),
				expected_before_rows_sha256: aggregateRowsSha(
					descriptionRows,
					'expected_before_sha256'
				),
				proposed_after_rows_sha256: aggregateRowsSha(
					descriptionRows,
					'proposed_after_sha256'
				),
				rows: descriptionRows.map((row) => ({
					id: row.id,
					field: row.field,
					key: row.key,
					expected_before_sha256: row.expected_before_sha256,
					proposed_after_sha256: row.proposed_after_sha256,
				})),
			},
		],
		guardrails: {
			official_woocommerce_ui_only: true,
			rank_math_columns: 0,
			empty_cross_lane_columns: 0,
			database_writes: 0,
			production_artifacts: 0,
		},
	};

	return {
		shortDescriptionCsv,
		descriptionCsv,
		validationJson: `${JSON.stringify(validation, null, 2)}\n`,
		validation,
	};
}

function readUtf8FileStrict(file) {
	const bytes = fs.readFileSync(file);
	let content;
	try {
		content = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
	} catch {
		fail(`${path.relative(repoRoot, file)} is not valid UTF-8`);
	}
	if (content.startsWith('\uFEFF')) {
		fail(`${path.relative(repoRoot, file)} must not contain a UTF-8 BOM`);
	}
	return content;
}

function atomicWrite(file, content) {
	const temporaryFile = `${file}.tmp-${process.pid}`;
	try {
		fs.writeFileSync(temporaryFile, content, {
			encoding: 'utf8',
			mode: 0o600,
		});
		fs.renameSync(temporaryFile, file);
		fs.chmodSync(file, 0o600);
	} finally {
		fs.rmSync(temporaryFile, { force: true });
	}
}

function expectedOutputs(bundle) {
	return [
		[
			path.join(outputDir, outputFiles.shortDescription),
			bundle.shortDescriptionCsv,
		],
		[path.join(outputDir, outputFiles.description), bundle.descriptionCsv],
		[path.join(outputDir, outputFiles.validation), bundle.validationJson],
	];
}

function runCli() {
	const allowedArguments = new Set(['--check']);
	const unknownArguments = process.argv
		.slice(2)
		.filter((argument) => !allowedArguments.has(argument));
	if (unknownArguments.length > 0) {
		fail(`Unknown argument(s): ${unknownArguments.join(', ')}`);
	}

	const checkOnly = process.argv.includes('--check');
	const sourceText = readUtf8FileStrict(manifestPath);
	const manifest = JSON.parse(sourceText);
	const bundle = buildBundle(manifest, sourceText);
	const outputs = expectedOutputs(bundle);

	if (checkOnly) {
		const drift = outputs
			.filter(
				([file, expected]) =>
					!fs.existsSync(file) ||
					readUtf8FileStrict(file) !== expected
			)
			.map(([file]) => path.relative(repoRoot, file));
		if (drift.length > 0) {
			fail(`Generated WooCommerce import drift: ${drift.join(', ')}`);
		}
	} else {
		fs.mkdirSync(outputDir, { recursive: true, mode: 0o700 });
		for (const [file, content] of outputs) {
			atomicWrite(file, content);
		}
	}

	console.log(
		JSON.stringify(
			{
				result: 'PASS',
				mode: checkOnly ? 'check' : 'write',
				source_manifest: path.relative(repoRoot, manifestPath),
				source_manifest_sha256: bundle.validation.source.sha256,
				output_directory: path.relative(repoRoot, outputDir),
				short_description_rows:
					bundle.validation.imports[0].row_count,
				description_rows: bundle.validation.imports[1].row_count,
				rank_math_columns: 0,
			},
			null,
			2
		)
	);
}

if (
	process.argv[1] &&
	path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
	runCli();
}
