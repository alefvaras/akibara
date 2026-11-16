#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const reviewedManifestFiles = [
	'seo-editorial-curated-excerpts-quality-manifest.json',
	'seo-editorial-shipping-quality-manifest.json',
	'seo-editorial-followup-quality-manifest.json',
	'seo-editorial-orthography-quality-manifest.json',
];
const expectedFieldCount = 92;
const reviewedProductExcerptApplyVia =
	'WooCommerce > Productos > Editar producto > Descripción corta del producto > Actualizar';

function optionValue(name, fallback = undefined) {
	const index = process.argv.indexOf(name);
	return index === -1 ? fallback : process.argv[index + 1];
}

function sha256(value) {
	return crypto.createHash('sha256').update(String(value), 'utf8').digest('hex');
}

function normalizeLineEndings(value) {
	return String(value).replace(/\r\n?/gu, '\n');
}

function normalizeOfficialUiValue(entry, value) {
	const normalized = normalizeLineEndings(value);
	if (
		entry?.object_type === 'term_taxonomy'
		&& entry?.field === 'description'
		&& normalized.startsWith('\n')
	) {
		return normalized.slice(1);
	}
	return normalized;
}

function fail(message) {
	throw new Error(`SEO editorial environment UI validation failed: ${message}`);
}

function decodeHtmlEntities(value) {
	return value
		.replaceAll('&amp;', '&')
		.replaceAll('&#038;', '&')
		.replaceAll('&#38;', '&');
}

function urls(value) {
	const attributeUrls = [...value.matchAll(/\b(?:href|src)=["']([^"']+)["']/giu)]
		.map((match) => decodeHtmlEntities(match[1]));
	const plainUrls = [...value.matchAll(/https?:\/\/[^\s"'<>]+/giu)]
		.map((match) => decodeHtmlEntities(match[0].replace(/[.,;:!?]+$/gu, '')));
	return [...new Set([...attributeUrls, ...plainUrls])].sort();
}

function htmlIsBalanced(value) {
	const voidTags = new Set([
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
		'meta', 'param', 'source', 'track', 'wbr',
	]);
	const stack = [];
	for (const match of value.matchAll(/<\/?([a-z][a-z0-9:-]*)\b[^>]*>/giu)) {
		const full = match[0];
		const tag = match[1].toLowerCase();
		if (full.startsWith('</')) {
			if (stack.pop() !== tag) {
				return false;
			}
		} else if (!full.endsWith('/>') && !voidTags.has(tag)) {
			stack.push(tag);
		}
	}
	return stack.length === 0;
}

function visibleText(value) {
	return String(value)
		.replace(/<!--[\s\S]*?-->/gu, ' ')
		.replace(/<[^>]+>/gu, ' ')
		.replaceAll('&nbsp;', ' ')
		.replaceAll('&amp;', '&')
		.replace(/\s+/gu, ' ')
		.trim();
}

function normalizedIdentityTokens(value) {
	return visibleText(value)
		.normalize('NFD')
		.replace(/\p{M}/gu, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/gu, ' ')
		.trim()
		.split(/\s+/gu)
		.filter(Boolean)
		.map((token) => /^\d+$/u.test(token) ? String(Number(token)) : token)
		.filter((token) => !['n', 'no', 'tomo'].includes(token));
}

function containsIdentity(value, title) {
	const targetTokens = new Set(normalizedIdentityTokens(value));
	const titleTokens = normalizedIdentityTokens(title);
	return titleTokens.length > 0 && titleTokens.every((token) => targetTokens.has(token));
}

function reviewedTargets() {
	const targets = new Map();
	for (const file of reviewedManifestFiles) {
		const manifest = JSON.parse(fs.readFileSync(path.join(scriptDir, file), 'utf8'));
		for (const entry of manifest.entries ?? []) {
			if (targets.has(entry.key)) {
				fail(`duplicate reviewed target ${entry.key}`);
			}
			const reviewedProposed = normalizeLineEndings(entry.proposed_after);
			if (sha256(reviewedProposed) !== entry.proposed_after_sha256) {
				fail(`reviewed target integrity failed for ${entry.key}`);
			}
			targets.set(entry.key, {
				...entry,
				proposed_after: normalizeOfficialUiValue(entry, reviewedProposed),
				source_manifest: file,
			});
		}
	}
	return targets;
}

const snapshotPath = path.resolve(optionValue('--snapshot', ''));
const reportPath = path.resolve(optionValue('--report', ''));
const uiPath = path.resolve(optionValue('--ui-manifest', ''));
const expectedSemantic = Number(optionValue('--expect-semantic', '50'));
if (!optionValue('--snapshot') || !optionValue('--report') || !optionValue('--ui-manifest')) {
	fail('--snapshot, --report and --ui-manifest are required');
}
if (!Number.isSafeInteger(expectedSemantic) || expectedSemantic < 0) {
	fail('--expect-semantic must be a non-negative integer');
}

const snapshotRaw = fs.readFileSync(snapshotPath, 'utf8');
const snapshot = JSON.parse(snapshotRaw);
const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
const ui = JSON.parse(fs.readFileSync(uiPath, 'utf8'));
const expectedSiteUrls = Object.freeze({
	'docker-local': 'http://localhost:8080',
	production: 'https://akibara.cl',
});
if (
	snapshot.schema_version !== 1
	|| !Object.hasOwn(expectedSiteUrls, snapshot.environment)
	|| snapshot.site_url !== expectedSiteUrls[snapshot.environment]
	|| snapshot.database_mutations !== 0
	|| snapshot.pii_fields_captured?.length !== 0
	|| snapshot.row_count !== expectedFieldCount
	|| snapshot.rows_sha256 !== sha256(JSON.stringify(snapshot.rows))
) {
	fail('snapshot integrity or privacy assertions failed');
}
if (
	report.result !== 'PASS'
	|| report.environment !== snapshot.environment
	|| report.source_snapshot_sha256 !== sha256(snapshotRaw)
		|| report.counts.total !== expectedFieldCount
		|| report.counts.pending !== expectedFieldCount
	|| report.counts.applied !== 0
	|| report.counts.drift !== 0
	|| report.counts.content_divergent !== 0
	|| report.counts.semantic_reviewed !== expectedSemantic
	|| report.assertions.remote_database_mutations !== 0
	|| Object.entries(report.assertions)
		.filter(([name]) => name !== 'remote_database_mutations')
		.some(([, passed]) => passed !== true)
) {
	fail('analysis report is incomplete or not fail-closed');
}
if (
	ui.result !== 'PASS'
	|| ui.complete !== true
	|| ui.fail_closed !== true
	|| ui.blocked_count !== 0
		|| ui.entry_count !== expectedFieldCount
	|| ui.database_mutations !== 0
	|| ui.application_lane !== 'official_ui'
	|| ui.entries_sha256 !== sha256(JSON.stringify(ui.entries))
) {
	fail('UI artifact metadata or integrity failed');
}

const snapshotByKey = new Map(snapshot.rows.map((row) => [row.key, row]));
const reviewedByKey = reviewedTargets();
const keys = new Set();
const strategyCounts = {};
for (const entry of ui.entries) {
	if (keys.has(entry.key)) {
		fail(`duplicate UI entry ${entry.key}`);
	}
	keys.add(entry.key);
	const source = snapshotByKey.get(entry.key);
	const reviewed = reviewedByKey.get(entry.key);
	const current = normalizeOfficialUiValue(reviewed, source?.value ?? '');
	const expectedSerialization =
		reviewed?.object_type === 'term_taxonomy' && reviewed?.field === 'description'
			? 'html_textarea_initial_lf_normalized'
			: 'identity';
	if (
		!source
		|| !reviewed
		|| entry.application_lane !== 'official_ui'
		|| typeof entry.apply_via !== 'string'
		|| entry.apply_via.length < 20
		|| entry.field.includes('rank_math')
		|| entry.observed_before !== current
		|| entry.observed_sha256 !== sha256(current)
		|| entry.proposed_sha256 !== sha256(entry.proposed_after)
		|| entry.official_ui_serialization !== expectedSerialization
		|| entry.observed_before === entry.proposed_after
		|| JSON.stringify(urls(current)) !== JSON.stringify(urls(entry.proposed_after))
		|| !htmlIsBalanced(entry.proposed_after)
		|| (
			entry.field === 'post_excerpt'
			&& visibleText(entry.proposed_after).length > 320
		)
		|| /&(?!(?:[a-z][a-z0-9]+|#\d+|#x[0-9a-f]+);)/iu.test(entry.proposed_after)
		|| /env[ií]os?\s+(?:rápidos?\s+|seguros?\s+)*a\s+(?:todo\s+Chile|todo\s+el\s+pa[ií]s|todas\s+las\s+regiones)/iu.test(entry.proposed_after)
	) {
		fail(`entry preservation or identity failed for ${entry.key}`);
	}

	if (entry.source_manifest === 'seo-editorial-curated-excerpts-quality-manifest.json') {
		if (
			entry.state !== 'pending_exact'
			|| entry.object_type !== 'product'
			|| entry.field !== 'post_excerpt'
			|| !containsIdentity(entry.proposed_after, reviewed.title)
			|| entry.proposed_after !== reviewed.proposed_after
			|| entry.proposed_sha256 !== reviewed.proposed_after_sha256
			|| visibleText(entry.proposed_after).length > 180
			|| !entry.proposed_after.includes('despacho según cobertura')
		) {
			fail(`curated product excerpt validation failed for ${entry.key}`);
		}
	}

	if (entry.state !== 'pending_semantic_reviewed') {
		continue;
	}
	const review = entry.semantic_review;
	if (
		review?.review_version !== 2
		|| review.review_status !== 'approved_exact_remote_source'
		|| review.source_sha256 !== entry.observed_sha256
		|| review.url_destinations_preserved !== true
		|| review.html_balanced !== true
	) {
		fail(`semantic review evidence failed for ${entry.key}`);
	}
	strategyCounts[review.strategy] = (strategyCounts[review.strategy] ?? 0) + 1;
		if (review.strategy === 'reviewed_product_specific_condensation_exact_reviewed_html') {
		if (
			review.exact_reviewed_target_used !== true
			|| review.semantic_scope_exact !== true
			|| review.reviewed_target_sha256 !== reviewed.proposed_after_sha256
			|| entry.reviewed_after_sha256 !== reviewed.proposed_after_sha256
			|| entry.proposed_sha256 !== reviewed.proposed_after_sha256
			|| entry.proposed_after !== reviewed.proposed_after
			|| entry.exact_reviewed_target_preserved !== true
			|| reviewed.source_manifest !== 'seo-editorial-shipping-quality-manifest.json'
			|| reviewed.object_type !== 'post'
			|| reviewed.field !== 'post_excerpt'
			|| reviewed.key !== `post:${reviewed.object_id}:post_excerpt`
			|| JSON.stringify([...reviewed.issue_codes].sort())
				!== JSON.stringify(['low_information_density', 'overlong_product_excerpt'])
			|| reviewed.application_lane !== 'official_ui'
			|| reviewed.apply_via !== reviewedProductExcerptApplyVia
			|| entry.object_type !== 'post'
			|| entry.field !== 'post_excerpt'
			|| entry.key !== `post:${entry.object_id}:post_excerpt`
			|| JSON.stringify([...entry.issue_codes].sort())
				!== JSON.stringify(['low_information_density', 'overlong_product_excerpt'])
			|| entry.apply_via !== reviewedProductExcerptApplyVia
			|| !htmlIsBalanced(current)
			|| !containsIdentity(current, reviewed.title)
			|| !containsIdentity(entry.proposed_after, reviewed.title)
			|| visibleText(entry.proposed_after).length > 320
			|| visibleText(entry.proposed_after).length >= visibleText(current).length
			|| review.checks?.some((check) => check.passed !== true)
		) {
				fail(`semantic condensation failed for ${entry.key}`);
			}
		}
		if (
			review.strategy
			=== 'reviewed_exact_curated_product_excerpt_from_pinned_legacy_source'
			&& (
				entry.key !== 'product:15418:post_excerpt'
				|| review.exact_reviewed_target_used !== true
				|| review.reviewed_target_sha256 !== reviewed.proposed_after_sha256
				|| entry.reviewed_after_sha256 !== reviewed.proposed_after_sha256
				|| entry.proposed_sha256 !== reviewed.proposed_after_sha256
				|| entry.proposed_after !== reviewed.proposed_after
				|| entry.exact_reviewed_target_preserved !== true
				|| !containsIdentity(entry.proposed_after, reviewed.title)
				|| visibleText(entry.proposed_after).length > 320
				|| review.checks?.some((check) => check.passed !== true)
			)
		) {
			fail(`pinned curated product excerpt failed for ${entry.key}`);
		}
	}

if (keys.size !== expectedFieldCount || keys.size !== snapshotByKey.size) {
	fail(`UI artifact does not cover the exact ${expectedFieldCount}-field snapshot`);
}
if (expectedSemantic === 50) {
	const expectedStrategies = {
		reviewed_exact_curated_product_excerpt_from_pinned_legacy_source: 1,
		reviewed_exact_sentence_join_and_shipping_copy: 5,
		reviewed_product_specific_condensation_exact_reviewed_html: 42,
		taxonomy_shipping_geography_exact_replacements: 1,
		terms_shipping_geography_exact_replacements: 1,
	};
	if (
		Object.keys(strategyCounts).length !== Object.keys(expectedStrategies).length
		|| Object.entries(expectedStrategies).some(
			([strategy, count]) => strategyCounts[strategy] !== count,
		)
	) {
		fail(`semantic strategy coverage mismatch: ${JSON.stringify(strategyCounts)}`);
	}
}

console.log(JSON.stringify({
	result: 'PASS',
	environment: snapshot.environment,
	fields: expectedFieldCount,
	pending: expectedFieldCount,
	applied: 0,
	drift: 0,
	semantic_reviewed: expectedSemantic,
	urls_preserved: true,
	html_balanced: true,
	database_mutations: 0,
	pii_fields_captured: 0,
	strategy_counts: strategyCounts,
}, null, 2));
