#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';

const args = process.argv.slice(2);
const live = args.includes('--live');
const expectIndex = args.indexOf('--expect');
const expectation = expectIndex >= 0 ? args[expectIndex + 1] : 'before';
const manifestArg = args.find((arg, index) =>
	!arg.startsWith('--') && (expectIndex < 0 || index !== expectIndex + 1))
	?? 'ops/seo-editorial-shipping-quality-manifest.json';
const manifestPath = resolve(process.cwd(), manifestArg);
const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const errors = [];

const sha256 = (value) => createHash('sha256').update(value, 'utf8').digest('hex');
const normalizeUiLineEndings = (value) => value.replace(/\r\n?/g, '\n');
const stripHtml = (value) => value
	.replace(/<!--[\s\S]*?-->/g, ' ')
	.replace(/<[^>]+>/g, ' ')
	.replace(/&nbsp;/gi, ' ')
	.replace(/&amp;/gi, '&')
	.replace(/&#(?:x([0-9a-f]+)|([0-9]+));/gi, (_match, hex, dec) =>
		String.fromCodePoint(Number.parseInt(hex ?? dec, hex ? 16 : 10)))
	.replace(/\s+/g, ' ')
	.trim();

const allowedFields = new Set(['post_content', 'post_excerpt', 'description']);
const allowedObjectTypes = new Set(['post', 'term_taxonomy']);
const voidTags = new Set([
	'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
	'meta', 'param', 'source', 'track', 'wbr',
]);

function validateHtmlFragment(key, value) {
	const rawAmpersand = /&(?!(?:[a-z][a-z0-9]+|#\d+|#x[0-9a-f]+);)/i.exec(value);
	if (rawAmpersand) {
		errors.push(`${key}: proposed_after contains an unescaped ampersand at offset ${rawAmpersand.index}`);
	}

	const stack = [];
	const tagPattern = /<\/?([a-z][a-z0-9:-]*)\b[^>]*>/gi;
	for (const match of value.matchAll(tagPattern)) {
		const full = match[0];
		const tag = match[1].toLowerCase();
		if (full.startsWith('</')) {
			const open = stack.pop();
			if (open !== tag) {
				errors.push(`${key}: proposed_after has mismatched HTML tags (${open ?? 'none'} -> ${tag})`);
				return;
			}
		} else if (!full.endsWith('/>') && !voidTags.has(tag)) {
			stack.push(tag);
		}
	}
	if (stack.length > 0) {
		errors.push(`${key}: proposed_after has unclosed HTML tags (${stack.join(', ')})`);
	}
}

if (manifest.schema_version !== 1) {
	errors.push('schema_version must be 1');
}
if (manifest.environment !== 'docker-local-snapshot') {
	errors.push('environment must be docker-local-snapshot');
}
if (manifest.rank_math_excluded !== true) {
	errors.push('rank_math_excluded must be true');
}
if (!Array.isArray(manifest.entries) || manifest.entries.length === 0) {
	errors.push('entries must be a non-empty array');
}
if (!['before', 'applied', 'either'].includes(expectation)) {
	errors.push('--expect must be before, applied or either');
}

const keys = new Set();
const replacementByPostId = new Map();
for (const entry of manifest.entries ?? []) {
	const key = entry.key ?? '(missing key)';
	if (keys.has(key)) {
		errors.push(`${key}: duplicate key`);
	}
	keys.add(key);

	if (!allowedObjectTypes.has(entry.object_type)) {
		errors.push(`${key}: unsupported object_type`);
	}
	if (!Number.isInteger(entry.object_id) || entry.object_id <= 0) {
		errors.push(`${key}: object_id must be a positive integer`);
	}
	if (!allowedFields.has(entry.field)) {
		errors.push(`${key}: unsupported field`);
	}
	if (entry.field.includes('rank_math') || key.includes('rank_math')) {
		errors.push(`${key}: Rank Math data is forbidden in this manifest`);
	}
	if (entry.application_lane !== 'official_ui') {
		errors.push(`${key}: application_lane must be official_ui`);
	}
	if (typeof entry.apply_via !== 'string' || entry.apply_via.length < 20) {
		errors.push(`${key}: apply_via must name the official UI path`);
	}
	if (typeof entry.expected_before !== 'string' || typeof entry.proposed_after !== 'string') {
		errors.push(`${key}: expected_before and proposed_after must be strings`);
		continue;
	}
	if (entry.expected_before === entry.proposed_after) {
		errors.push(`${key}: proposal is identical to current text`);
	}
	if (sha256(entry.expected_before) !== entry.expected_before_sha256) {
		errors.push(`${key}: expected_before_sha256 does not match expected_before`);
	}
	if (sha256(entry.proposed_after) !== entry.proposed_after_sha256) {
		errors.push(`${key}: proposed_after_sha256 does not match proposed_after`);
	}
	if (!Array.isArray(entry.issue_codes) || entry.issue_codes.length === 0) {
		errors.push(`${key}: issue_codes must be a non-empty array`);
	}
	if (typeof entry.problem_text_exact !== 'string'
		|| !entry.expected_before.includes(entry.problem_text_exact)) {
		errors.push(`${key}: problem_text_exact must be an exact substring of expected_before`);
	}
	if (/todo\s+chile|todo\s+el\s+pa[ií]s|todas\s+las\s+regiones/i.test(entry.proposed_after)) {
		errors.push(`${key}: proposal restores an unsupported absolute shipping claim`);
	}

	validateHtmlFragment(key, entry.proposed_after);

	const visibleChars = stripHtml(entry.proposed_after).length;
	if (visibleChars !== entry.proposed_visible_chars) {
		errors.push(`${key}: proposed_visible_chars mismatch (${entry.proposed_visible_chars} != ${visibleChars})`);
	}
	if (entry.field === 'post_excerpt' && visibleChars > manifest.policy.max_product_excerpt_visible_chars) {
		errors.push(`${key}: proposed product excerpt is too long (${visibleChars})`);
	}
	if (entry.object_type === 'post' && entry.field === 'post_excerpt') {
		replacementByPostId.set(entry.object_id, entry.proposed_after);
	}
}

if (live && errors.length === 0) {
	const selects = manifest.entries.map((entry) => {
		if (entry.object_type === 'post') {
			return `SELECT '${entry.key}' AS manifest_key, REPLACE(TO_BASE64(${entry.field}),CHAR(10),'') AS encoded_value FROM wp_posts WHERE ID=${entry.object_id}`;
		}
		return `SELECT '${entry.key}' AS manifest_key, REPLACE(TO_BASE64(description),CHAR(10),'') AS encoded_value FROM wp_term_taxonomy WHERE term_taxonomy_id=${entry.object_id}`;
	});
	const sql = `${selects.join('\nUNION ALL\n')};`;
	const query = spawnSync(
		'docker',
		[
			'compose', 'exec', '-T', 'db',
			'mariadb', '-uroot', '-proot_local', 'akibara',
			'--batch', '--raw', '--skip-column-names',
			'-e', sql,
		],
		{ cwd: process.cwd(), encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 },
	);
	if (query.status !== 0) {
		errors.push(`live Docker query failed: ${query.stderr.trim()}`);
	} else {
		const observed = new Map();
		for (const line of query.stdout.trim().split('\n').filter(Boolean)) {
			const tab = line.indexOf('\t');
			const key = line.slice(0, tab);
			const encoded = line.slice(tab + 1).replace(/\s+/g, '');
			observed.set(key, Buffer.from(encoded, 'base64').toString('utf8'));
		}
		for (const entry of manifest.entries) {
			const current = observed.get(entry.key);
			if (current === undefined) {
				errors.push(`${entry.key}: object/field missing from Docker`);
				continue;
			}

			// Native HTML forms submit textarea line endings as CRLF while the
			// reviewed manifest is stored with LF. Treat only that transport
			// normalization as equivalent; every other byte remains guarded.
			const normalizedCurrent = normalizeUiLineEndings(current);
			const isBefore = sha256(normalizedCurrent)
				=== sha256(normalizeUiLineEndings(entry.expected_before));
			const isApplied = sha256(normalizedCurrent)
				=== sha256(normalizeUiLineEndings(entry.proposed_after));
			if (expectation === 'before' && !isBefore) {
				errors.push(`${entry.key}: Docker content is not the reviewed before state`);
			} else if (expectation === 'applied' && !isApplied) {
				errors.push(`${entry.key}: Docker content is not the reviewed applied state`);
			} else if (expectation === 'either' && !isBefore && !isApplied) {
				errors.push(`${entry.key}: Docker content drifted outside the reviewed before/applied states`);
			}
		}
	}

	const excerptQuery = spawnSync(
		'docker',
		[
			'compose', 'exec', '-T', 'db',
			'mariadb', '-uroot', '-proot_local', 'akibara',
			'--batch', '--raw', '--skip-column-names',
			'-e',
			"SELECT ID,REPLACE(TO_BASE64(post_excerpt),CHAR(10),'') FROM wp_posts WHERE post_type='product' AND post_status='publish' AND post_excerpt<>'';",
		],
		{ cwd: process.cwd(), encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 },
	);
	if (excerptQuery.status !== 0) {
		errors.push(`live excerpt duplicate query failed: ${excerptQuery.stderr.trim()}`);
	} else {
		const currentGroups = new Map();
		const proposedGroups = new Map();
		for (const line of excerptQuery.stdout.trim().split('\n').filter(Boolean)) {
			const tab = line.indexOf('\t');
			const postId = Number.parseInt(line.slice(0, tab), 10);
			const current = Buffer.from(line.slice(tab + 1).replace(/\s+/g, ''), 'base64').toString('utf8');
			const currentHash = sha256(current);
			const proposedHash = sha256(replacementByPostId.get(postId) ?? current);
			currentGroups.set(currentHash, [...(currentGroups.get(currentHash) ?? []), postId]);
			proposedGroups.set(proposedHash, [...(proposedGroups.get(proposedHash) ?? []), postId]);
		}
		for (const [hash, ids] of proposedGroups) {
			if (ids.length < 2) {
				continue;
			}
			const currentIds = currentGroups.get(hash) ?? [];
			if (ids.join(',') !== currentIds.join(',')) {
				errors.push(`proposals would create an excerpt duplicate group: ${ids.join(', ')}`);
			}
		}
	}
}

if (errors.length > 0) {
	console.error(`FAIL: editorial shipping quality manifest (${errors.length} issue(s))`);
	for (const error of errors) {
		console.error(`- ${error}`);
	}
	process.exit(1);
}

console.log(
	`PASS: ${manifest.entries.length} fail-closed editorial proposals validated`
	+ (live ? ` against read-only Docker data (${expectation})` : ''),
);
