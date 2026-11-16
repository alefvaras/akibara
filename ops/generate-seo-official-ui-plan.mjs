#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const defaultRankMathManifest = path.join(
	scriptDir,
	'rankmath-shipping-copy-ui-manifest.json'
);
const expectedEditorialCount = 92;

function fail(message) {
	throw new Error(`SEO official UI plan generation failed: ${message}`);
}

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

function readJson(file) {
	const raw = fs.readFileSync(file, 'utf8');
	return { raw, value: JSON.parse(raw) };
}

function assertSha(value, label) {
	if (!/^[a-f0-9]{64}$/u.test(value)) {
		fail(`${label} is not a lowercase SHA-256 digest`);
	}
}

function rankMathListPath(row) {
	if (row.scope === 'postmeta') {
		if (row.type === 'post') {
			return '/wp-admin/edit.php';
		}
		return `/wp-admin/edit.php?post_type=${encodeURIComponent(row.type)}`;
	}
	const productContext = (
		row.taxonomy === 'product_cat'
		|| row.taxonomy === 'product_brand'
		|| row.taxonomy.startsWith('pa_')
	);
	return (
		`/wp-admin/edit-tags.php?taxonomy=${encodeURIComponent(row.taxonomy)}`
		+ (productContext ? '&post_type=product' : '')
	);
}

function rankMathRowSelector(row) {
	const prefix = row.scope === 'termmeta' ? 'tag' : 'post';
	return `#${prefix}-${row.id}`;
}

function rankMathTermUiPath(row) {
	const productContext = (
		row.taxonomy === 'product_cat'
		|| row.taxonomy === 'product_brand'
		|| row.taxonomy.startsWith('pa_')
	);
	return (
		`/wp-admin/term.php?taxonomy=${encodeURIComponent(row.taxonomy)}`
		+ `&tag_ID=${row.id}`
		+ (productContext ? '&post_type=product' : '')
	);
}

function normalizeRankMathRows(manifest) {
	if (
		manifest?.metadata?.row_count !== 871
		|| manifest?.metadata?.assertions?.ui_only !== true
		|| !Array.isArray(manifest.rows)
		|| manifest.rows.length !== 871
	) {
		fail('Rank Math manifest is not the reviewed 871-row UI-only artifact');
	}

	return manifest.rows.map((row) => {
		const before = normalizeLineEndings(row.before);
		const after = normalizeLineEndings(row.after);
		if (
			row.meta_key !== 'rank_math_description'
			|| row.before_sha256 !== sha256(before)
			|| row.after_sha256 !== sha256(after)
			|| before === after
		) {
			fail(`Rank Math row ${row.manifest_id} failed integrity checks`);
		}
		if (row.scope === 'termmeta') {
			return {
				key: `rankmath:${row.manifest_id}`,
				lane: 'rankmath_term_description',
				object_id: row.id,
				object_type: row.type,
				taxonomy: row.taxonomy,
				label: row.label,
				ui_path: rankMathTermUiPath(row),
				field_selector: '#rank-math-editor-description',
				before_sha256: row.before_sha256,
				after_sha256: row.after_sha256,
				before,
				after,
			};
		}
		return {
			key: `rankmath:${row.manifest_id}`,
			lane: 'rankmath_inline_description',
			object_id: row.id,
			object_type: row.type,
			taxonomy: row.taxonomy || null,
			label: row.label,
			list_path: rankMathListPath(row),
			row_selector: rankMathRowSelector(row),
			field_selector:
				'td.column-rank_math_description textarea.rank-math-column-value[data-field="description"]',
			before_sha256: row.before_sha256,
			after_sha256: row.after_sha256,
			before,
			after,
		};
	});
}

function editorialLane(entry) {
	if (entry.object_type === 'term_taxonomy') {
		return 'term_editor';
	}
	if (entry.apply_via.startsWith('WooCommerce > Productos >')) {
		return 'product_classic_editor';
	}
	if (entry.apply_via.startsWith('WordPress >')) {
		return 'wordpress_block_editor';
	}
	fail(`cannot route editorial entry ${entry.key}`);
}

function editorialUiPath(entry, lane) {
	if (lane === 'term_editor') {
		return (
			`/wp-admin/term.php?taxonomy=product_cat`
			+ `&tag_ID=${entry.object_id}&post_type=product`
		);
	}
	return `/wp-admin/post.php?post=${entry.object_id}&action=edit`;
}

function normalizeEditorialRows(manifest) {
	if (
		manifest?.result !== 'PASS'
		|| manifest?.complete !== true
		|| manifest?.application_lane !== 'official_ui'
		|| manifest?.database_mutations !== 0
		|| manifest?.entry_count !== expectedEditorialCount
		|| !Array.isArray(manifest.entries)
		|| manifest.entries.length !== expectedEditorialCount
	) {
		fail(
			`editorial manifest is not a complete ${expectedEditorialCount}-field `
			+ 'official-UI artifact'
		);
	}

	return manifest.entries.map((entry) => {
		const before = normalizeLineEndings(entry.observed_before);
		const after = normalizeLineEndings(entry.proposed_after);
		const lane = editorialLane(entry);
		if (
			entry.observed_sha256 !== sha256(before)
			|| entry.proposed_sha256 !== sha256(after)
			|| before === after
			|| !['post_content', 'post_excerpt', 'description'].includes(entry.field)
		) {
			fail(`editorial row ${entry.key} failed integrity checks`);
		}
		return {
			key: `editorial:${entry.key}`,
			lane,
			object_id: entry.object_id,
			object_type: entry.object_type,
			title: entry.title,
			field: entry.field,
			ui_path: editorialUiPath(entry, lane),
			field_selector: {
				post_content: lane === 'product_classic_editor'
					? '#content'
					: '.editor-post-text-editor',
				post_excerpt: lane === 'product_classic_editor'
					? '#excerpt'
					: '.editor-post-excerpt__textarea textarea',
				description: 'textarea[name="description"]',
			}[entry.field],
			before_sha256: entry.observed_sha256,
			after_sha256: entry.proposed_sha256,
			before,
			after,
		};
	});
}

function countBy(items, property) {
	return Object.fromEntries(
		[...items.reduce((counts, item) => {
			const key = item[property] ?? '';
			counts.set(key, (counts.get(key) ?? 0) + 1);
			return counts;
		}, new Map()).entries()].sort(([left], [right]) => left.localeCompare(right))
	);
}

function writeAtomic(file, contents) {
	fs.mkdirSync(path.dirname(file), { recursive: true });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, contents, { mode: 0o600 });
	fs.renameSync(temporary, file);
}

const editorialPathOption = optionValue('--editorial-manifest');
const outputPathOption = optionValue('--output');
const rankMathPath = path.resolve(
	optionValue('--rankmath-manifest', defaultRankMathManifest)
);
if (!editorialPathOption || !outputPathOption) {
	fail('--editorial-manifest and --output are required');
}

const editorialPath = path.resolve(editorialPathOption);
const outputPath = path.resolve(outputPathOption);
const rankMathSource = readJson(rankMathPath);
const editorialSource = readJson(editorialPath);
const sourceEnvironment = editorialSource.value.environment;
const environment = optionValue('--environment', sourceEnvironment);
if (!['docker', 'production'].includes(environment)) {
	fail(`unsupported environment ${environment}`);
}
if (environment === 'docker' && sourceEnvironment !== 'production') {
	fail(
		'Docker must use the production-source editorial manifest because the '
		+ 'local database is a production clone; non-production absolute URLs are not valid '
		+ 'Docker evidence'
	);
}
if (environment !== 'docker' && environment !== sourceEnvironment) {
	fail(
		`--environment ${environment} does not match editorial source `
		+ `${sourceEnvironment}`
	);
}

const rankMathItems = normalizeRankMathRows(rankMathSource.value);
const editorialItems = normalizeEditorialRows(editorialSource.value);
const items = [...rankMathItems, ...editorialItems];
const keys = new Set(items.map((item) => item.key));
if (keys.size !== 871 + expectedEditorialCount) {
	fail(`expected ${871 + expectedEditorialCount} unique work items, got ${keys.size}`);
}

const canonicalItemsSha256 = sha256(JSON.stringify(items));
const plan = {
	schema_version: 1,
	plan_type: 'akibara_seo_official_ui',
	environment,
	generated_at: new Date().toISOString(),
	application_policy: {
		official_administration_ui_only: true,
		direct_api_writes: 0,
		direct_sql_writes: 0,
		direct_meta_writes: 0,
		remote_import_files: 0,
		woocommerce_csv_importer: 'rejected_remote_artifact_cleanup_not_automatic',
		fail_closed_on_hash_drift: true,
		checkpoint_location: 'local_only',
		docker_editorial_source:
			environment === 'docker' ? 'production_clone_only' : 'not_applicable',
	},
	sources: {
		rankmath_manifest: {
			path: path.relative(path.dirname(outputPath), rankMathPath),
			sha256: sha256(rankMathSource.raw),
		},
		editorial_manifest: {
			path: path.relative(path.dirname(outputPath), editorialPath),
			sha256: sha256(editorialSource.raw),
			source_environment: sourceEnvironment,
		},
	},
	counts: {
		total: items.length,
		rankmath: rankMathItems.length,
		editorial: editorialItems.length,
		by_lane: countBy(items, 'lane'),
		editorial_by_field: countBy(editorialItems, 'field'),
		rankmath_by_list_path: countBy(
			rankMathItems.filter((item) => item.list_path),
			'list_path'
		),
		rankmath_by_ui_path: countBy(
			rankMathItems.filter((item) => item.ui_path),
			'ui_path'
		),
	},
	items_sha256: canonicalItemsSha256,
	items,
};
plan.plan_sha256 = sha256(JSON.stringify({
	schema_version: plan.schema_version,
	environment: plan.environment,
	application_policy: plan.application_policy,
	sources: plan.sources,
	counts: plan.counts,
	items_sha256: plan.items_sha256,
}));

assertSha(plan.plan_sha256, 'plan.plan_sha256');
writeAtomic(outputPath, `${JSON.stringify(plan, null, 2)}\n`);
console.log(JSON.stringify({
	result: 'PASS',
	output: outputPath,
	environment,
	plan_sha256: plan.plan_sha256,
	total: plan.counts.total,
	rankmath: plan.counts.rankmath,
	editorial: plan.counts.editorial,
	by_lane: plan.counts.by_lane,
}, null, 2));
