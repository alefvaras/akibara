#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const args = process.argv.slice(2);
const live = args.includes('--live');
const expectation = args.find((arg) => arg.startsWith('--expect='))
	?.slice('--expect='.length) ?? 'before';
const manifestArg = args.find((arg) => !arg.startsWith('--'))
	?? 'ops/seo-editorial-orthography-quality-manifest.json';
const repoRoot = resolve(import.meta.dirname, '..');
const manifest = JSON.parse(readFileSync(resolve(repoRoot, manifestArg), 'utf8'));
const errors = [];

const sha256 = (value) => createHash('sha256').update(value, 'utf8').digest('hex');
const normalizeUiLineEndings = (value) => value.replace(/\r\n?/g, '\n');

function escapeRegExp(value) {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function substitutionPattern(substitution) {
	const escaped = escapeRegExp(substitution.before);
	if (substitution.match_mode === 'whole_word') {
		return new RegExp(`(?<![\\p{L}])${escaped}(?![\\p{L}])`, 'gu');
	}
	return new RegExp(escaped, 'gu');
}

const exactCount = (value, substitution) =>
	[...value.matchAll(substitutionPattern(substitution))].length;

if (manifest.schema_version !== 1) {
	errors.push('schema_version must be 1');
}
if (!['before', 'applied', 'either'].includes(expectation)) {
	errors.push('--expect must be before, applied or either');
}
if (manifest.environment !== 'docker-local-snapshot') {
	errors.push('environment must be docker-local-snapshot');
}
if (manifest.rank_math_excluded !== true) {
	errors.push('rank_math_excluded must be true');
}
if (manifest.policy?.official_ui_first !== true
	|| manifest.policy?.no_direct_database_writes !== true) {
	errors.push('manifest must remain official-UI-only and read-only');
}
if (!Array.isArray(manifest.entries) || manifest.entries.length !== 1) {
	errors.push('manifest must contain exactly one entry');
}

for (const entry of manifest.entries ?? []) {
	const key = entry.key ?? '(missing key)';
	if (entry.object_id !== 23327
		|| entry.object_type !== 'post'
		|| entry.field !== 'post_content') {
		errors.push(`${key}: only post 23327 post_content is allowed`);
	}
	if (entry.post_status !== 'publish'
		|| entry.content_disposition !== 'valid_published_content_keep') {
		errors.push(`${key}: valid published content must be kept`);
	}
	if (entry.application_lane !== 'official_ui'
		|| !entry.apply_via?.startsWith('WordPress > Entradas >')) {
		errors.push(`${key}: official WordPress editor path is required`);
	}
	if (typeof entry.expected_before !== 'string'
		|| typeof entry.proposed_after !== 'string') {
		errors.push(`${key}: expected_before and proposed_after must be strings`);
		continue;
	}
	if (sha256(entry.expected_before) !== entry.expected_before_sha256) {
		errors.push(`${key}: expected_before_sha256 mismatch`);
	}
	if (sha256(entry.proposed_after) !== entry.proposed_after_sha256) {
		errors.push(`${key}: proposed_after_sha256 mismatch`);
	}
	if (!Array.isArray(entry.substitutions) || entry.substitutions.length === 0) {
		errors.push(`${key}: substitutions must be a non-empty array`);
		continue;
	}

	let transformed = entry.expected_before;
	let substitutionTotal = 0;
	for (const [index, substitution] of entry.substitutions.entries()) {
		if (typeof substitution.before !== 'string'
			|| typeof substitution.after !== 'string'
			|| !Number.isInteger(substitution.expected_count)
			|| substitution.expected_count <= 0
			|| !['whole_word', 'literal'].includes(substitution.match_mode)) {
			errors.push(`${key}: invalid substitution ${index}`);
			continue;
		}
		const observed = exactCount(transformed, substitution);
		if (observed !== substitution.expected_count) {
			errors.push(
				`${key}: substitution ${JSON.stringify(substitution.before)} `
				+ `expected ${substitution.expected_count}, observed ${observed}`,
			);
			continue;
		}
		transformed = transformed.replace(
			substitutionPattern(substitution),
			substitution.after,
		);
		substitutionTotal += substitution.expected_count;
	}
	if (transformed !== entry.proposed_after) {
		errors.push(`${key}: substitutions do not reproduce proposed_after exactly`);
	}
	if (substitutionTotal !== manifest.audit_summary?.total_exact_substitutions) {
		errors.push(`${key}: total_exact_substitutions mismatch`);
	}
	if (entry.expected_before.split('?').length - 1 !== 2
		|| entry.proposed_after.split('?').length - 1 !== 2
		|| entry.question_marks_preserved !== true) {
		errors.push(`${key}: valid closing question marks must be preserved`);
	}
	if (/�|Ã[\u0080-\u00bf]|Â[\u0080-\u00bf]|â€|ðŸ/u.test(
		`${entry.expected_before}${entry.proposed_after}`,
	)) {
		errors.push(`${key}: replacement character or mojibake marker observed`);
	}
	if (/\brank_math\b/i.test(JSON.stringify(entry))) {
		errors.push(`${key}: Rank Math data is forbidden`);
	}
}

if (live && errors.length === 0) {
	const entry = manifest.entries[0];
	const sql = [
		'SELECT REPLACE(TO_BASE64(post_content),CHAR(10),\'\')',
		'FROM wp_posts',
		`WHERE ID=${entry.object_id}`,
		'AND post_type=\'post\'',
		'AND post_status=\'publish\';',
	].join(' ');
	const query = spawnSync(
		'docker',
		[
			'compose', 'exec', '-T', 'db',
			'mariadb', '-uroot', '-proot_local', 'akibara',
			'--batch', '--raw', '--skip-column-names',
			'-e', sql,
		],
		{ cwd: repoRoot, encoding: 'utf8', maxBuffer: 2 * 1024 * 1024 },
	);
	if (query.status !== 0) {
		errors.push(`live Docker query failed: ${query.stderr.trim()}`);
	} else {
		const encoded = query.stdout.trim().replace(/\s+/g, '');
		const observed = Buffer.from(encoded, 'base64').toString('utf8');
		const normalizedObserved = normalizeUiLineEndings(observed);
		const isBefore = encoded
			&& sha256(normalizedObserved)
				=== sha256(normalizeUiLineEndings(entry.expected_before));
		const isApplied = encoded
			&& sha256(normalizedObserved)
				=== sha256(normalizeUiLineEndings(entry.proposed_after));
		if (expectation === 'before' && !isBefore) {
			errors.push(`${entry.key}: Docker content is not the reviewed before state`);
		} else if (expectation === 'applied' && !isApplied) {
			errors.push(`${entry.key}: Docker content is not the reviewed applied state`);
		} else if (expectation === 'either' && !isBefore && !isApplied) {
			errors.push(`${entry.key}: Docker content drifted outside reviewed states`);
		}
	}
}

if (errors.length > 0) {
	console.error(`FAIL: editorial orthography quality manifest (${errors.length} issue(s))`);
	for (const error of errors) {
		console.error(`- ${error}`);
	}
	process.exit(1);
}

console.log(
	`PASS: ${manifest.entries.length} fail-closed orthography proposal validated`
	+ (live ? ` against read-only Docker data (${expectation})` : ''),
);
