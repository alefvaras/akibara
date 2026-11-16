#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const manifest = JSON.parse(
	fs.readFileSync(
		path.join(scriptDir, 'seo-editorial-curated-excerpts-quality-manifest.json'),
		'utf8',
	),
);
const migrationSource = fs.readFileSync(
	path.join(scriptDir, 'apply-seo-content-quality-data.php'),
	'utf8',
);
const curatedStart = migrationSource.indexOf("'curated_product_excerpts' => array(");
const curatedEnd = migrationSource.indexOf("'legal_copy'", curatedStart);
const curatedSource = migrationSource.slice(curatedStart, curatedEnd);
const expectedIds = [
	15406, 15409, 15412, 15415, 15428,
	15431, 15434, 15437, 15440, 15443,
];

function fail(message) {
	throw new Error(`Curated product excerpt manifest failed: ${message}`);
}

function sha256(value) {
	return crypto.createHash('sha256').update(String(value), 'utf8').digest('hex');
}

function phpSingleQuoted(value) {
	return String(value)
		.replaceAll('\\', '\\\\')
		.replaceAll("'", "\\'");
}

if (
	manifest.schema_version !== 1
	|| manifest.environment !== 'docker-local-snapshot'
	|| manifest.rank_math_excluded !== true
	|| manifest.policy?.official_ui_first !== true
	|| manifest.policy?.no_direct_database_writes !== true
	|| manifest.policy?.no_remote_csv_imports !== true
	|| manifest.policy?.preserve_product_identity !== true
	|| manifest.policy?.unique_product_specific_copy !== true
	|| manifest.audit_summary?.entries !== expectedIds.length
	|| manifest.audit_summary?.unique_targets !== expectedIds.length
	|| !Array.isArray(manifest.entries)
	|| manifest.entries.length !== expectedIds.length
	|| curatedStart < 0
	|| curatedEnd <= curatedStart
) {
	fail('metadata, policy or source boundary drifted');
}

const seenIds = new Set();
const targets = new Set();
for (const entry of manifest.entries) {
	if (
		!expectedIds.includes(entry.object_id)
		|| seenIds.has(entry.object_id)
		|| entry.key !== `product:${entry.object_id}:post_excerpt`
		|| entry.object_type !== 'product'
		|| entry.field !== 'post_excerpt'
		|| entry.post_status !== 'publish'
		|| entry.content_disposition !== 'valid_published_content_keep'
		|| entry.application_lane !== 'official_ui'
		|| !entry.apply_via?.startsWith('WooCommerce > Productos >')
		|| sha256(entry.expected_before) !== entry.expected_before_sha256
		|| sha256(entry.proposed_after) !== entry.proposed_after_sha256
		|| entry.expected_before === entry.proposed_after
		|| entry.proposed_after.length > 180
		|| !entry.proposed_after.startsWith(entry.title)
		|| !entry.proposed_after.includes('despacho según cobertura')
		|| /env[ií]o\s+a\s+todo\s+Chile/iu.test(entry.proposed_after)
		|| !curatedSource.includes(`'post_id'               => ${entry.object_id},`)
		|| !curatedSource.includes(
			`'expected_before'       => '${phpSingleQuoted(entry.expected_before)}',`,
		)
		|| !curatedSource.includes(
			`'desired'               => '${phpSingleQuoted(entry.proposed_after)}',`,
		)
	) {
		fail(`identity, hash, UX copy or migration parity failed for ${entry.key}`);
	}
	seenIds.add(entry.object_id);
	targets.add(entry.proposed_after);
}

if (
	JSON.stringify([...seenIds].sort((left, right) => left - right))
		!== JSON.stringify(expectedIds)
	|| targets.size !== expectedIds.length
) {
	fail('exact product inventory or unique-target invariant failed');
}

console.log(
	`PASS: ${expectedIds.length} unique product-specific excerpts, `
	+ 'official UI only, hash-pinned and migration-parity checked',
);
