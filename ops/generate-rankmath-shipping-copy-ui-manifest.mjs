#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import {
	buildManifest,
	buildSourceSnapshot,
	manifestCsv,
	readLiveScopedRows,
} from './rankmath-shipping-copy-manifest-lib.mjs';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(scriptDir);

function optionValue(name, fallback) {
	const index = process.argv.indexOf(name);
	return index === -1 ? fallback : process.argv[index + 1];
}

const refreshSource = process.argv.includes('--refresh-source');
const checkOnly = process.argv.includes('--check');
const sourcePath = path.resolve(
	optionValue('--source', path.join(scriptDir, 'rankmath-shipping-copy-ui-source.json'))
);
const outputDir = path.resolve(optionValue('--output-dir', scriptDir));
const manifestJsonPath = path.join(outputDir, 'rankmath-shipping-copy-ui-manifest.json');
const manifestCsvPath = path.join(outputDir, 'rankmath-shipping-copy-ui-manifest.csv');

if (refreshSource && checkOnly) {
	throw new Error('--refresh-source and --check are mutually exclusive');
}

let snapshot;
if (refreshSource) {
	const liveRows = readLiveScopedRows({ repoRoot });
	snapshot = buildSourceSnapshot(
		liveRows,
		new Date().toISOString().slice(0, 10)
	);
	if (snapshot.rows.length === 0) {
		throw new Error('Refusing to replace the reviewed source snapshot with an empty capture');
	}
	if (fs.existsSync(sourcePath)) {
		const existingSnapshot = JSON.parse(fs.readFileSync(sourcePath, 'utf8'));
		if (existingSnapshot.metadata.rows_sha256 !== snapshot.metadata.rows_sha256) {
			throw new Error(
				'Refusing to overwrite a different reviewed source snapshot; use a new --source path'
			);
		}
	}
	fs.writeFileSync(sourcePath, `${JSON.stringify(snapshot, null, 2)}\n`);
} else {
	snapshot = JSON.parse(fs.readFileSync(sourcePath, 'utf8'));
}

const manifest = buildManifest(snapshot);
const jsonOutput = `${JSON.stringify(manifest, null, 2)}\n`;
const csvOutput = manifestCsv(manifest);

if (checkOnly) {
	const comparisons = [
		[manifestJsonPath, jsonOutput],
		[manifestCsvPath, csvOutput],
	];
	const drift = comparisons
		.filter(([file, expected]) => !fs.existsSync(file) || fs.readFileSync(file, 'utf8') !== expected)
		.map(([file]) => path.relative(repoRoot, file));

	if (drift.length > 0) {
		throw new Error(`Generated manifest drift: ${drift.join(', ')}`);
	}
} else {
	fs.mkdirSync(outputDir, { recursive: true });
	fs.writeFileSync(manifestJsonPath, jsonOutput);
	fs.writeFileSync(manifestCsvPath, csvOutput);
}

console.log(JSON.stringify({
	result: 'PASS',
	mode: checkOnly ? 'check' : 'write',
	source: path.relative(repoRoot, sourcePath),
	output_json: path.relative(repoRoot, manifestJsonPath),
	output_csv: path.relative(repoRoot, manifestCsvPath),
	row_count: manifest.metadata.row_count,
	replacement_count: manifest.metadata.replacement_count,
	max_before_length: manifest.metadata.max_before_length,
	max_after_length: manifest.metadata.max_after_length,
	counts: manifest.metadata.counts,
	source_rows_sha256: manifest.metadata.source_rows_sha256,
	manifest_rows_sha256: manifest.metadata.rows_sha256,
}, null, 2));
