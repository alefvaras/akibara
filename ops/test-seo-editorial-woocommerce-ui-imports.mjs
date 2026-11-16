#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
	buildBundle,
	serializeCsv,
	sha256,
} from './generate-seo-editorial-woocommerce-ui-imports.mjs';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const manifestPath = path.join(
	scriptDir,
	'seo-editorial-shipping-quality-manifest.json'
);

function parseCsv(csv) {
	const rows = [];
	let row = [];
	let cell = '';
	let quoted = false;

	for (let index = 0; index < csv.length; index += 1) {
		const character = csv[index];
		if (quoted) {
			if (character === '"') {
				if (csv[index + 1] === '"') {
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
			assert.equal(cell, '', 'quote must begin at the start of a CSV cell');
			quoted = true;
		} else if (character === ',') {
			row.push(cell);
			cell = '';
		} else if (character === '\r' && csv[index + 1] === '\n') {
			row.push(cell);
			rows.push(row);
			row = [];
			cell = '';
			index += 1;
		} else {
			cell += character;
		}
	}

	assert.equal(quoted, false, 'CSV ended inside a quoted field');
	assert.deepEqual(row, [], 'CSV must end with CRLF');
	assert.equal(cell, '', 'CSV must not have data after its final CRLF');
	return rows;
}

function clone(value) {
	return structuredClone(value);
}

function assertFailsWith(manifest, sourceText, pattern) {
	assert.throws(() => buildBundle(manifest, sourceText), pattern);
}

const sourceText = fs.readFileSync(manifestPath, 'utf8');
const manifest = JSON.parse(sourceText);
const bundle = buildBundle(manifest, sourceText);
const shortRows = parseCsv(bundle.shortDescriptionCsv);
const descriptionRows = parseCsv(bundle.descriptionCsv);

assert.deepEqual(shortRows[0], ['ID', 'Short description']);
assert.deepEqual(descriptionRows[0], ['ID', 'Description']);
assert.equal(shortRows.length, 62);
assert.equal(descriptionRows.length, 4);
assert.ok(shortRows.slice(1).every((row) => row.length === 2));
assert.ok(descriptionRows.slice(1).every((row) => row.length === 2));
assert.equal(new Set(shortRows.slice(1).map((row) => row[0])).size, 61);
assert.equal(new Set(descriptionRows.slice(1).map((row) => row[0])).size, 3);
assert.equal(bundle.validation.source.sha256, sha256(sourceText));
assert.equal(bundle.validation.imports[0].row_count, 61);
assert.equal(bundle.validation.imports[1].row_count, 3);
assert.equal(bundle.validation.guardrails.rank_math_columns, 0);
assert.equal(bundle.validation.guardrails.empty_cross_lane_columns, 0);
assert.equal(
	bundle.validation.imports[0].csv_sha256,
	sha256(bundle.shortDescriptionCsv)
);
assert.equal(
	bundle.validation.imports[1].csv_sha256,
	sha256(bundle.descriptionCsv)
);

const proposedByIdAndField = new Map(
	manifest.entries
		.filter(
			(entry) =>
				entry.field === 'post_excerpt' ||
				(entry.field === 'post_content' &&
					entry.apply_via.startsWith('WooCommerce > Productos >'))
		)
		.map((entry) => [
			`${entry.object_id}:${entry.field}`,
			entry.proposed_after,
		])
);
for (const [id, value] of shortRows.slice(1)) {
	assert.equal(value, proposedByIdAndField.get(`${id}:post_excerpt`));
}
for (const [id, value] of descriptionRows.slice(1)) {
	assert.equal(value, proposedByIdAndField.get(`${id}:post_content`));
}

const reversedManifest = clone(manifest);
reversedManifest.entries.reverse();
const reversedSource = `${JSON.stringify(reversedManifest, null, 2)}\n`;
const reversedBundle = buildBundle(reversedManifest, reversedSource);
assert.equal(reversedBundle.shortDescriptionCsv, bundle.shortDescriptionCsv);
assert.equal(reversedBundle.descriptionCsv, bundle.descriptionCsv);

const duplicateId = clone(manifest);
const excerptEntries = duplicateId.entries.filter(
	(entry) => entry.field === 'post_excerpt'
);
excerptEntries[1].object_id = excerptEntries[0].object_id;
assertFailsWith(
	duplicateId,
	JSON.stringify(duplicateId),
	/duplicate product ID/u
);

const tamperedBefore = clone(manifest);
tamperedBefore.entries.find(
	(entry) => entry.field === 'post_excerpt'
).expected_before += ' tampered';
assertFailsWith(
	tamperedBefore,
	JSON.stringify(tamperedBefore),
	/expected_before_sha256 mismatch/u
);

const duplicateAcrossImports = clone(manifest);
duplicateAcrossImports.entries.find(
	(entry) =>
		entry.field === 'post_content' &&
		entry.apply_via.startsWith('WooCommerce > Productos >')
).object_id = manifest.entries.find(
	(entry) => entry.field === 'post_excerpt'
).object_id;
assertFailsWith(
	duplicateAcrossImports,
	JSON.stringify(duplicateAcrossImports),
	/appears in both post_excerpt and post_content imports/u
);

const tamperedAfter = clone(manifest);
tamperedAfter.entries.find(
	(entry) => entry.field === 'post_excerpt'
).proposed_after += ' tampered';
assertFailsWith(
	tamperedAfter,
	JSON.stringify(tamperedAfter),
	/proposed_after_sha256 mismatch/u
);

const rankMathEnabled = clone(manifest);
rankMathEnabled.rank_math_excluded = false;
assertFailsWith(
	rankMathEnabled,
	JSON.stringify(rankMathEnabled),
	/rank_math_excluded=true/u
);

const formulaPayload = clone(manifest);
const formulaEntry = formulaPayload.entries.find(
	(entry) => entry.field === 'post_excerpt'
);
formulaEntry.proposed_after = '=HYPERLINK("https://invalid.example")';
formulaEntry.proposed_after_sha256 = sha256(formulaEntry.proposed_after);
assertFailsWith(
	formulaPayload,
	JSON.stringify(formulaPayload),
	/spreadsheet-formula control character/u
);

assert.deepEqual(
	parseCsv(serializeCsv(['A', 'B'], [['1', 'línea 1,\n"línea 2"']])),
	[
		['A', 'B'],
		['1', 'línea 1,\n"línea 2"'],
	]
);

console.log(
	'PASS: WooCommerce editorial imports are deterministic, UTF-8/CSV safe, SHA-guarded and Rank Math-free'
);
