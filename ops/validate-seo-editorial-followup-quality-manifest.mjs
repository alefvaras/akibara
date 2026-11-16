#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const args = process.argv.slice(2);
const live = args.includes('--live');
const equalsExpectation = args.find((arg) => arg.startsWith('--expect='))
	?.slice('--expect='.length);
const expectationFlagIndex = args.indexOf('--expect');
const expectation = equalsExpectation
	?? (expectationFlagIndex >= 0 ? args[expectationFlagIndex + 1] : undefined)
	?? 'before';
const consumedExpectationValue = expectationFlagIndex >= 0
	? args[expectationFlagIndex + 1]
	: undefined;
const manifestArg = args.find((arg) =>
	!arg.startsWith('--')
	&& arg !== consumedExpectationValue
) ?? 'ops/seo-editorial-followup-quality-manifest.json';
const repoRoot = resolve(import.meta.dirname, '..');
const manifest = JSON.parse(readFileSync(resolve(repoRoot, manifestArg), 'utf8'));
const errors = [];

const allowedKeys = new Set([
	'product:15418:post_excerpt',
	'product:15418:post_content',
	'product:15421:post_content',
	'post:22607:post_content',
	'post:22610:post_content',
	'post:22638:post_excerpt',
	'post:22638:post_content',
	'post:23323:post_content',
	'post:23324:post_content',
	'post:23325:post_content',
	'post:23326:post_content',
	'post:23330:post_excerpt',
	'post:23330:post_content',
]);

const mobCanonicalCounts = new Map([
	['product:15418:post_excerpt', 1],
	['product:15418:post_content', 1],
	['product:15421:post_content', 3],
]);

const sha256 = (value) => createHash('sha256').update(value, 'utf8').digest('hex');
const normalizeUiLineEndings = (value) => value.replace(/\r\n?/g, '\n');

function escapeRegExp(value) {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function patternFor(substitution) {
	const escaped = escapeRegExp(substitution.before);
	if (substitution.match_mode === 'text_word') {
		return new RegExp(`(?<![\\p{L}])${escaped}(?![\\p{L}])`, 'gu');
	}
	return new RegExp(escaped, 'gu');
}

function transformTextNodes(value, callback) {
	return value
		.split(/(<[^>]*>)/g)
		.map((part, index) => (index % 2 === 0 ? callback(part) : part))
		.join('');
}

function countSubstitution(value, substitution) {
	const pattern = patternFor(substitution);
	if (substitution.match_mode === 'raw_literal') {
		return [...value.matchAll(pattern)].length;
	}
	let count = 0;
	transformTextNodes(value, (part) => {
		count += [...part.matchAll(pattern)].length;
		return part;
	});
	return count;
}

function applySubstitution(value, substitution) {
	const pattern = patternFor(substitution);
	if (substitution.match_mode === 'raw_literal') {
		return value.replace(pattern, substitution.after);
	}
	return transformTextNodes(value, (part) => part.replace(pattern, substitution.after));
}

function hrefs(value) {
	return [...value.matchAll(/\bhref="([^"]+)"/gu)].map((match) => match[1]);
}

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
	|| manifest.policy?.no_direct_database_writes !== true
	|| manifest.policy?.remove_only_verified_dead_historical_markup !== true
	|| manifest.policy?.preserve_urls_and_html_attributes !== true) {
	errors.push('manifest policy must remain UI-only, fail-closed and HTML-safe');
}
if (manifest.evidence?.mob_psycho_canonical_notation !== '???%'
	|| !manifest.evidence?.mob_psycho_reference?.startsWith('https://www.penguinrandomhouse.com/')) {
	errors.push('canonical Mob Psycho notation evidence is missing');
}
if (!Array.isArray(manifest.entries)
	|| manifest.entries.length !== allowedKeys.size
	|| manifest.audit_summary?.entries !== allowedKeys.size) {
	errors.push(`manifest must contain exactly ${allowedKeys.size} entries`);
}

let totalExactSubstitutions = 0;
const seenKeys = new Set();

for (const entry of manifest.entries ?? []) {
	const key = entry.key ?? '(missing key)';
	if (!allowedKeys.has(key)) {
		errors.push(`${key}: entry is outside the reviewed scope`);
	}
	if (seenKeys.has(key)) {
		errors.push(`${key}: duplicate entry`);
	}
	seenKeys.add(key);
	if (key !== `${entry.object_type}:${entry.object_id}:${entry.field}`) {
		errors.push(`${key}: key does not match object metadata`);
	}
	if (!['post', 'product'].includes(entry.object_type)
		|| !['post_content', 'post_excerpt'].includes(entry.field)
		|| entry.post_status !== 'publish'
		|| entry.content_disposition !== 'valid_published_content_keep') {
		errors.push(`${key}: only valid published post/product content may be proposed`);
	}
	if (entry.application_lane !== 'official_ui'
		|| !(entry.apply_via?.startsWith('WordPress > Entradas >')
			|| entry.apply_via?.startsWith('WooCommerce > Productos >'))) {
		errors.push(`${key}: official administration UI path is required`);
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
	for (const [index, substitution] of entry.substitutions.entries()) {
		if (typeof substitution.before !== 'string'
			|| typeof substitution.after !== 'string'
			|| substitution.before === substitution.after
			|| !Number.isInteger(substitution.expected_count)
			|| substitution.expected_count <= 0
			|| !['text_word', 'text_literal', 'raw_literal'].includes(
				substitution.match_mode,
			)) {
			errors.push(`${key}: invalid substitution ${index}`);
			continue;
		}
		const observed = countSubstitution(transformed, substitution);
		if (observed !== substitution.expected_count) {
			errors.push(
				`${key}: ${JSON.stringify(substitution.before)} expected `
				+ `${substitution.expected_count}, observed ${observed}`,
			);
			continue;
		}
		transformed = applySubstitution(transformed, substitution);
		totalExactSubstitutions += substitution.expected_count;
	}
	if (transformed !== entry.proposed_after) {
		errors.push(`${key}: substitutions do not reproduce proposed_after exactly`);
	}
	if (JSON.stringify(hrefs(entry.expected_before))
		!== JSON.stringify(hrefs(entry.proposed_after))) {
		errors.push(`${key}: href values changed`);
	}
	if (/�|Ã[\u0080-\u00bf]|Â[\u0080-\u00bf]|â€|ðŸ/u.test(
		`${entry.expected_before}${entry.proposed_after}`,
	)) {
		errors.push(`${key}: replacement character or mojibake marker observed`);
	}
	if (/\brank_math\b/iu.test(JSON.stringify(entry))) {
		errors.push(`${key}: Rank Math data is forbidden`);
	}

	if (mobCanonicalCounts.has(key)) {
		const canonicalCount = [...entry.proposed_after.matchAll(/\?\?\?%/gu)].length;
		if (canonicalCount !== mobCanonicalCounts.get(key)
			|| /(?<!\?)\?%(?!\?)/u.test(entry.proposed_after)) {
			errors.push(`${key}: canonical ???% notation is incomplete`);
		}
	}
	if (['post:22607:post_content', 'post:22610:post_content'].includes(key)
		&& entry.proposed_after.includes(
			'<span class="aki-page-section-accent"></span>',
		)) {
		errors.push(`${key}: verified dead historical span remains`);
	}
}

for (const key of allowedKeys) {
	if (!seenKeys.has(key)) {
		errors.push(`${key}: required entry missing`);
	}
}
if (totalExactSubstitutions !== manifest.audit_summary?.total_exact_substitutions) {
	errors.push(
		`total_exact_substitutions mismatch: expected `
		+ `${manifest.audit_summary?.total_exact_substitutions}, observed `
		+ `${totalExactSubstitutions}`,
	);
}

if (live && errors.length === 0) {
	for (const entry of manifest.entries) {
		const sqlField = entry.field === 'post_content' ? 'post_content' : 'post_excerpt';
		const sql = [
			`SELECT post_type,post_status,REPLACE(TO_BASE64(${sqlField}),CHAR(10),'')`,
			'FROM wp_posts',
			`WHERE ID=${entry.object_id};`,
		].join(' ');
		const query = spawnSync(
			'docker',
			[
				'compose', 'exec', '-T', 'db',
				'mariadb', '-uroot', '-proot_local', 'akibara',
				'--batch', '--raw', '--skip-column-names',
				'-e', sql,
			],
			{ cwd: repoRoot, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 },
		);
		if (query.status !== 0) {
			errors.push(`${entry.key}: live Docker query failed: ${query.stderr.trim()}`);
			continue;
		}
		const parts = query.stdout.trim().split('\t');
		if (parts.length !== 3) {
			errors.push(`${entry.key}: live Docker row shape drifted`);
			continue;
		}
		const [postType, postStatus, encoded] = parts;
		if (postType !== entry.object_type || postStatus !== entry.post_status || !encoded) {
			errors.push(`${entry.key}: live Docker object metadata drifted`);
			continue;
		}
		const observed = normalizeUiLineEndings(
			Buffer.from(encoded.replace(/\s+/g, ''), 'base64').toString('utf8'),
		);
		const isBefore = sha256(observed)
			=== sha256(normalizeUiLineEndings(entry.expected_before));
		const isApplied = sha256(observed)
			=== sha256(normalizeUiLineEndings(entry.proposed_after));
		if (expectation === 'before' && !isBefore) {
			errors.push(`${entry.key}: Docker value is not the reviewed before state`);
		} else if (expectation === 'applied' && !isApplied) {
			errors.push(`${entry.key}: Docker value is not the reviewed applied state`);
		} else if (expectation === 'either' && !isBefore && !isApplied) {
			errors.push(`${entry.key}: Docker value drifted outside reviewed states`);
		}
	}
}

if (errors.length > 0) {
	console.error(
		`FAIL: editorial follow-up quality manifest (${errors.length} issue(s))`,
	);
	for (const error of errors) {
		console.error(`- ${error}`);
	}
	process.exit(1);
}

console.log(
	`PASS: ${manifest.entries.length} fail-closed editorial follow-up proposals validated`
	+ (live ? ` against read-only Docker data (${expectation})` : ''),
);
