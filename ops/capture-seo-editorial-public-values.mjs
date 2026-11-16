#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(scriptDir);
const environments = Object.freeze({
	production: {
		site_url: 'https://akibara.cl',
		wp_path: '/home/u888022333/domains/akibara.cl/public_html',
	},
});
const manifestFiles = [
	'seo-editorial-curated-excerpts-quality-manifest.json',
	'seo-editorial-shipping-quality-manifest.json',
	'seo-editorial-followup-quality-manifest.json',
	'seo-editorial-orthography-quality-manifest.json',
];
const publicPostTypes = new Set(['page', 'post', 'product']);
const publicTaxonomies = new Set([
	'category',
	'pa_autor',
	'pa_genero',
	'pa_serie',
	'product_brand',
	'product_cat',
]);
const allowedFields = new Set(['post_content', 'post_excerpt', 'description']);

function optionValue(name, fallback = undefined) {
	const index = process.argv.indexOf(name);
	return index === -1 ? fallback : process.argv[index + 1];
}

function fail(message) {
	throw new Error(`SEO editorial public capture failed: ${message}`);
}

function sha256(value) {
	return crypto.createHash('sha256').update(String(value), 'utf8').digest('hex');
}

function sqlString(value) {
	return `'${String(value).replaceAll("'", "''")}'`;
}

function loadEntries() {
	const entries = [];
	const keys = new Set();

	for (const file of manifestFiles) {
		const manifest = JSON.parse(fs.readFileSync(path.join(scriptDir, file), 'utf8'));
		if (
			manifest.schema_version !== 1
			|| manifest.environment !== 'docker-local-snapshot'
			|| manifest.rank_math_excluded !== true
			|| !Array.isArray(manifest.entries)
		) {
			fail(`unsupported reviewed manifest structure in ${file}`);
		}
		for (const entry of manifest.entries) {
			if (
				!/^[a-z_]+:\d+:[a-z_]+$/u.test(entry.key)
				|| !Number.isSafeInteger(entry.object_id)
				|| entry.object_id <= 0
				|| !allowedFields.has(entry.field)
				|| keys.has(entry.key)
			) {
				fail(`unsafe or duplicate entry ${entry.key} in ${file}`);
			}
			if (
				entry.object_type === 'term_taxonomy'
				&& entry.field !== 'description'
			) {
				fail(`unsupported term field in ${entry.key}`);
			}
			if (
				entry.object_type !== 'term_taxonomy'
				&& !['post', 'product'].includes(entry.object_type)
			) {
				fail(`unsupported object type in ${entry.key}`);
			}
			keys.add(entry.key);
			entries.push({
				...entry,
				source_manifest: file,
			});
		}
	}

	return entries.sort((left, right) => left.key.localeCompare(right.key));
}

function wpCommand(environment, sshHost, args, input = undefined) {
	const config = environments[environment];
	const remoteCommand = [
		'wp',
		`--path=${config.wp_path}`,
		...args,
	].map((part) => `'${part.replaceAll("'", "'\\''")}'`).join(' ');
	const result = spawnSync(
		'ssh',
		['-o', 'BatchMode=yes', sshHost, remoteCommand],
		{
			cwd: repoRoot,
			encoding: 'utf8',
			input,
			maxBuffer: 64 * 1024 * 1024,
		},
	);
	if (result.status !== 0) {
		fail(`${environment} WP-CLI command failed: ${result.stderr.trim()}`);
	}
	return result.stdout;
}

function buildSql(entries, prefix) {
	if (!/^[A-Za-z0-9_]+$/u.test(prefix)) {
		fail(`unsafe WordPress table prefix ${prefix}`);
	}

	const selects = entries.map((entry) => {
		const columns = [
			`${sqlString(entry.key)} AS manifest_key`,
			entry.object_type === 'term_taxonomy'
				? "'term_taxonomy' AS storage"
				: "'posts' AS storage",
			`${entry.object_id} AS expected_object_id`,
			sqlString(entry.field),
		];
		if (entry.object_type === 'term_taxonomy') {
			return `SELECT ${columns.join(', ')}, tt.term_taxonomy_id, tt.taxonomy, REPLACE(TO_BASE64(tt.description), CHAR(10), '')`
				+ ` FROM ${prefix}term_taxonomy AS tt`
				+ ` WHERE tt.term_taxonomy_id=${entry.object_id}`
				+ ` AND tt.taxonomy IN (${[...publicTaxonomies].map(sqlString).join(', ')})`;
		}
		return `SELECT ${columns.join(', ')}, p.ID, p.post_type, REPLACE(TO_BASE64(p.${entry.field}), CHAR(10), '')`
			+ ` FROM ${prefix}posts AS p`
			+ ` WHERE p.ID=${entry.object_id}`
			+ " AND p.post_status='publish'"
			+ ` AND p.post_type IN (${[...publicPostTypes].map(sqlString).join(', ')})`;
	});

	return [
		'START TRANSACTION READ ONLY;',
		selects.join('\nUNION ALL\n'),
		';',
		'COMMIT;',
	].join('\n');
}

function parseRows(output, entries) {
	const expected = new Map(entries.map((entry) => [entry.key, entry]));
	const rows = output
		.split('\n')
		.filter((line) => line !== '')
		.map((line) => {
			const columns = line.split('\t');
			if (columns.length !== 7) {
				fail(`unexpected SELECT column count ${columns.length}`);
			}
			const [key, storage, expectedId, field, observedId, publicType, encoded] = columns;
			const entry = expected.get(key);
			if (
				!entry
				|| storage !== (entry.object_type === 'term_taxonomy' ? 'term_taxonomy' : 'posts')
				|| Number(expectedId) !== entry.object_id
				|| Number(observedId) !== entry.object_id
				|| field !== entry.field
			) {
				fail(`unexpected row identity for ${key}`);
			}
			const value = Buffer.from(encoded.replace(/\s+/gu, ''), 'base64').toString('utf8');
			return {
				key,
				storage,
				object_id: entry.object_id,
				object_type: entry.object_type,
				public_type: publicType,
				field,
				value_sha256: sha256(value),
				value,
			};
		})
		.sort((left, right) => left.key.localeCompare(right.key));

	if (rows.length !== entries.length) {
		const captured = new Set(rows.map((row) => row.key));
		const missing = entries.filter((entry) => !captured.has(entry.key)).map((entry) => entry.key);
		fail(`expected ${entries.length} public fields, captured ${rows.length}; missing: ${missing.join(', ')}`);
	}
	if (new Set(rows.map((row) => row.key)).size !== rows.length) {
		fail('duplicate public field rows');
	}
	return rows;
}

function writeNewFile(outputPath, content) {
	if (fs.existsSync(outputPath)) {
		if (fs.readFileSync(outputPath, 'utf8') === content) {
			return;
		}
		fail(`refusing to overwrite different local report ${path.relative(repoRoot, outputPath)}`);
	}
	fs.mkdirSync(path.dirname(outputPath), { recursive: true });
	fs.writeFileSync(outputPath, content, { flag: 'wx' });
}

const environment = optionValue('--environment');
const outputPath = path.resolve(optionValue('--output', ''));
const sshHost = optionValue('--ssh-host', 'akibara');
if (environment !== 'production') {
	fail('--environment must be production');
}
if (!optionValue('--output')) {
	fail('--output is required so reviewed reports are never overwritten implicitly');
}
if (!/^[A-Za-z0-9_.-]+$/u.test(sshHost)) {
	fail('unsafe --ssh-host');
}

const entries = loadEntries();
const prefix = wpCommand(environment, sshHost, ['db', 'prefix']).trim();
const sql = buildSql(entries, prefix);
const output = wpCommand(
	environment,
	sshHost,
	['db', 'query', '--batch', '--raw', '--skip-column-names'],
	sql,
);
const rows = parseRows(output, entries);
const report = {
	schema_version: 1,
	environment,
	site_url: environments[environment].site_url,
	captured_at: new Date().toISOString(),
	source: 'Remote WP-CLI db query; fixed SELECT inside READ ONLY transaction',
	database_mutations: 0,
	pii_fields_captured: [],
	manifest_files: manifestFiles,
	row_count: rows.length,
	rows_sha256: sha256(JSON.stringify(rows)),
	rows,
};
writeNewFile(outputPath, `${JSON.stringify(report, null, 2)}\n`);
console.log(JSON.stringify({
	result: 'PASS',
	environment,
	output: path.relative(repoRoot, outputPath),
	row_count: rows.length,
	rows_sha256: report.rows_sha256,
	database_mutations: 0,
	pii_fields_captured: 0,
}, null, 2));
