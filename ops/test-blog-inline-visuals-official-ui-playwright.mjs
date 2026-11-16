#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
	finalizeInlineVisualManifest,
	inlineVisualSha256,
	validateInlineVisualManifest,
} from './lib/blog-inline-visuals-official-ui-manifest.mjs';
import {
	extractInlineVisualState,
	inlineVisualPlainText,
	insertInlineVisuals,
	resolveInlineVisualAnchor,
	stripInlineVisuals,
	topLevelGutenbergBlocks,
} from './lib/blog-inline-visuals-content.mjs';
import {
	generateInlineVisualManifest,
} from './generate-blog-inline-visuals-official-ui-manifest.mjs';
import {
	finalizeManifest as finalizeSeoManifest,
	sha256 as seoSha256,
} from './lib/blog-seo-official-ui-manifest.mjs';
import {
	assertInlineVisualBrowserFunctionSafety,
	inlineVisualApplyPostBrowserFunction,
	inlineVisualDeleteMediaBrowserFunction,
	inlineVisualFrontendVerifyBrowserFunction,
	inlineVisualMediaProbeBrowserFunction,
	inlineVisualPreflightBrowserFunction,
	inlineVisualRollbackPostBrowserFunction,
	inlineVisualUploadBrowserFunction,
	parseInlineVisualPlaywrightResult,
	readWebpDimensions,
} from './run-blog-inline-visuals-official-ui-playwright.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const ASSETS_PATH = path.join(
	ROOT,
	'output/blog-seo-inline-visuals-20260726/manifest.json'
);
const DESIRED_PATH = path.join(
	ROOT,
	'.local-reports/seo-blog-score-audit/all-posts-desired.json'
);

function sources() {
	const assets = JSON.parse(fs.readFileSync(ASSETS_PATH, 'utf8'));
	const desired = JSON.parse(fs.readFileSync(DESIRED_PATH, 'utf8'));
	const desiredById = new Map(desired.posts.map((post) => [
		Number(post.production_post_id ?? post.id),
		post,
	]));
	const productionPosts = desired.posts.map((post) => {
		const id = Number(post.production_post_id ?? post.id);
		const fixtureContent = [
			'<!-- wp:paragraph {"className":"akb-respuesta-directa"} -->',
			`<p class="akb-respuesta-directa">Ancla editorial única para ${
				post.content_key
			}.</p>`,
			'<!-- /wp:paragraph -->',
			'',
			'<!-- wp:paragraph -->',
			`<p>Contenido de prueba completo para ${post.content_key}.</p>`,
			'<!-- /wp:paragraph -->',
		].join('\n');
		return {
			content_key: post.content_key,
			post_id: id,
			public_url: `https://akibara.cl/${post.content_key}/`,
			fields: {
				post_title: post.fields.post_title,
				post_content: fixtureContent,
			},
		};
	});
	const dockerPosts = productionPosts.map((post, index) => ({
		...post,
		post_id: 40000 + index,
		public_url: `http://localhost:8080/${post.content_key}/`,
	}));
	const sequenceByPost = new Map();
	const placements = assets.assets.map((asset) => {
		const post = desiredById.get(asset.post_id);
		const next = (sequenceByPost.get(asset.post_id) ?? 0) + 1;
		sequenceByPost.set(asset.post_id, next);
		return {
			production_post_id: asset.post_id,
			file: asset.file,
			content_key: post.content_key,
			sequence: next,
			anchor: {
				kind: 'gutenberg_block_text',
				value: `Ancla editorial única para ${post.content_key}.`,
				occurrence: 1,
				relation: 'after',
			},
		};
	});
	const assetsSha = inlineVisualSha256(fs.readFileSync(ASSETS_PATH));
	return {
		assets,
		assetsSha,
		placements: {
			schema_version: 1,
			placement_type:
				'akibara_blog_inline_visual_editorial_placements',
			reviewed: true,
			assets_manifest_sha256: assetsSha,
			entries: placements,
		},
		production: {
			schema_version: 1,
			capture_type: 'akibara_blog_seo_official_ui_read_only',
			capture_tool: 'akibara-playwright-official-ui-v1',
			capture_policy: 'official_wordpress_rankmath_ui_read_only',
			direct_writes: 0,
			environment: 'production',
			target_origin: 'https://akibara.cl',
			posts: productionPosts,
		},
		docker: {
			schema_version: 1,
			capture_type: 'akibara_blog_seo_official_ui_read_only',
			capture_tool: 'akibara-playwright-official-ui-v1',
			capture_policy: 'official_wordpress_rankmath_ui_read_only',
			direct_writes: 0,
			environment: 'docker',
			target_origin: 'http://localhost:8080',
			posts: dockerPosts,
		},
	};
}

function generatedManifest(environment = 'docker') {
	const fixture = sources();
	return generateInlineVisualManifest({
		assetsSource: fixture.assets,
		assetsSourceSha256: fixture.assetsSha,
		placements: fixture.placements,
		placementsSha256: 'b'.repeat(64),
		productionCapture: fixture.production,
		productionCaptureSha256: 'c'.repeat(64),
		environmentCapture: environment === 'docker'
			? fixture.docker
			: fixture.production,
		environmentCaptureSha256: 'd'.repeat(64),
		environment,
	});
}

function reviewedSeoManifest(
	fixture,
	environment = 'docker',
	{ revisedContent = false } = {}
) {
	const capture = environment === 'docker'
		? fixture.docker
		: fixture.production;
	return finalizeSeoManifest({
		schema_version: 1,
		manifest_type: 'akibara_blog_seo_official_ui',
		environment,
		target_origin: capture.target_origin,
		application_policy: {
			official_wordpress_ui_only: true,
			official_rank_math_ui_only: true,
			direct_rest_writes: 0,
			direct_sql_writes: 0,
			direct_wp_cli_writes: 0,
			direct_meta_writes: 0,
			remote_artifacts: 0,
		},
		posts: capture.posts.map((post, index) => {
			const revised = [
				post.fields.post_content,
				'',
				'<!-- wp:paragraph -->',
				`<p>Contenido SEO revisado ${index + 1} antes de publicar.</p>`,
				'<!-- /wp:paragraph -->',
			].join('\n');
			return {
				post_id: post.post_id,
				post_type: 'post',
				edit_path:
					`/wp-admin/post.php?post=${post.post_id}&action=edit`,
				public_url: post.public_url,
				minimum_rank_math_score: 82,
				fields: {
					post_title: {
						before: post.fields.post_title,
						after: `${post.fields.post_title} revisado`,
					},
					post_content: {
						before: post.fields.post_content,
						after: revisedContent
							? revised
							: post.fields.post_content,
					},
					post_excerpt: {
						before: `Extracto previo ${index + 1}.`,
						after:
							`Resumen editorial útil para lectores de manga `
							+ `en Chile, entrada ${index + 1}.`,
					},
					rank_math_focus_keyword: {
						before: `clave previa ${index + 1}`,
						after: `guía manga ${index + 1}`,
					},
					rank_math_title: {
						before: `SEO previo ${index + 1}`,
						after:
							`Guía manga ${index + 1} para lectores | Akibara`,
					},
					rank_math_description: {
						before:
							`Descripción previa ${index + 1} con suficiente `
							+ 'contenido para mantener el contrato de prueba.',
						after:
							`Descubre esta guía de manga ${index + 1} con `
							+ 'información clara, recomendaciones útiles y '
							+ 'contexto editorial para lectores en Chile.',
					},
				},
			};
		}),
	});
}

function generatedTransactionReadyManifest(
	environment = 'docker',
	{ revisedContent = true } = {}
) {
	const fixture = sources();
	const seo = reviewedSeoManifest(
		fixture,
		environment,
		{ revisedContent }
	);
	return {
		fixture,
		seo,
		inline: generateInlineVisualManifest({
			assetsSource: fixture.assets,
			assetsSourceSha256: fixture.assetsSha,
			placements: fixture.placements,
			placementsSha256: 'b'.repeat(64),
			productionCapture: fixture.production,
			productionCaptureSha256: 'c'.repeat(64),
			environmentCapture: environment === 'docker'
				? fixture.docker
				: fixture.production,
			environmentCaptureSha256: 'd'.repeat(64),
			environment,
			seoManifest: seo,
		}),
	};
}

test('generator produces a deterministic complete 25/32 manifest', () => {
	const first = generatedManifest();
	const second = generatedManifest();
	assert.equal(first.manifest_sha256, second.manifest_sha256);
	assert.equal(first.posts.length, 25);
	assert.equal(first.assets.length, 32);
	assert.equal(
		new Set(first.assets.map((asset) => asset.content_key)).size,
		18
	);
	assert.equal(
		first.posts.reduce(
			(total, post) => total + post.asset_sha256s.length,
			0
		),
		32
	);
});

test('transaction-ready generator binds media to reviewed SEO before writes', () => {
	const { fixture, seo, inline } = generatedTransactionReadyManifest();
	assert.equal(
		inline.sources.seo_manifest_sha256,
		seo.manifest_sha256
	);
	assert.equal(
		inline.sources.target_content_policy,
		'reviewed_seo_manifest_after_values'
	);
	for (const inlinePost of inline.posts) {
		const seoPost = seo.posts.find(
			(post) => post.post_id === inlinePost.post_id
		);
		const capturedPost = fixture.docker.posts.find(
			(post) => post.post_id === inlinePost.post_id
		);
		assert.equal(
			inlinePost.before_content_sha256,
			seoPost.fields.post_content.after_sha256
		);
		assert.equal(
			inlinePost.post_title,
			seoPost.fields.post_title.after
		);
		assert.notEqual(
			inlinePost.before_content_sha256,
			seoSha256(capturedPost.fields.post_content)
		);
	}
});

test('transaction-ready generator rejects identity and historical URL drift', () => {
	const fixture = sources();
	const seo = reviewedSeoManifest(fixture, 'docker');
	const driftedDraft = structuredClone(seo);
	driftedDraft.posts[0].public_url += 'otro-slug/';
	const driftedSeo = finalizeSeoManifest(driftedDraft);
	assert.throws(
		() => generateInlineVisualManifest({
			assetsSource: fixture.assets,
			assetsSourceSha256: fixture.assetsSha,
			placements: fixture.placements,
			placementsSha256: 'b'.repeat(64),
			productionCapture: fixture.production,
			productionCaptureSha256: 'c'.repeat(64),
			environmentCapture: fixture.docker,
			environmentCaptureSha256: 'd'.repeat(64),
			environment: 'docker',
			seoManifest: driftedSeo,
		}),
		/historical URL drift/u
	);
});

test('legacy inline manifests remain valid but partial SEO bindings fail', () => {
	const legacy = generatedManifest();
	assert.doesNotThrow(() => validateInlineVisualManifest(legacy));
	const partial = structuredClone(legacy);
	delete partial.manifest_sha256;
	partial.sources.seo_manifest_sha256 = 'a'.repeat(64);
	assert.throws(
		() => finalizeInlineVisualManifest(partial),
		/must include both hash and policy/u
	);
});

test('production manifest enforces exact Akibara origin and complete capture', () => {
	const production = generatedManifest('production');
	assert.equal(production.environment, 'production');
	assert.equal(production.target_origin, 'https://akibara.cl');
	const deferred = sources();
	deferred.production.deferred_content_keys = ['missing-post'];
	deferred.production.posts.pop();
	assert.throws(
		() => generateInlineVisualManifest({
			assetsSource: deferred.assets,
			assetsSourceSha256: deferred.assetsSha,
			placements: deferred.placements,
			placementsSha256: 'b'.repeat(64),
			productionCapture: deferred.production,
			productionCaptureSha256: 'c'.repeat(64),
			environmentCapture: deferred.production,
			environmentCaptureSha256: 'd'.repeat(64),
			environment: 'production',
		}),
		/complete 25-post official-UI capture/u
	);
});

test('manifest is tamper-evident and rejects incomplete corpus', () => {
	const manifest = generatedManifest();
	const tampered = structuredClone(manifest);
	tampered.assets.pop();
	assert.throws(
		() => validateInlineVisualManifest(tampered),
		/manifest hash is invalid/u
	);
	const incompleteDraft = structuredClone(manifest);
	delete incompleteDraft.manifest_sha256;
	incompleteDraft.corpus.asset_count = 31;
	assert.throws(
		() => finalizeInlineVisualManifest(incompleteDraft),
		/complete 25-post\/32-asset reviewed set/u
	);
});

test('native Gutenberg placement is ordered, reversible and idempotent', () => {
	const content = [
		'<!-- wp:heading -->',
		'<h2 class="wp-block-heading">Cómo elegir una edición</h2>',
		'<!-- /wp:heading -->',
		'',
		'<!-- wp:paragraph -->',
		'<p>Texto editorial posterior.</p>',
		'<!-- /wp:paragraph -->',
	].join('\n');
	const anchor = {
		kind: 'gutenberg_block_text',
		value: 'Cómo elegir una edición',
		occurrence: 1,
		relation: 'after',
	};
	const assets = [1, 2].map((sequence) => ({
		sha256: String(sequence).repeat(64),
		sequence,
		expected_filename: `visual-${sequence}.webp`,
		marker_class: `akb-inline-visual--${String(sequence).repeat(12)}`,
		alt: `Descripción editorial completa ${sequence}`,
		caption: `Pie editorial completo para el visual ${sequence}`,
		width: 1200,
		height: 675,
		anchor,
	}));
	const uploads = Object.fromEntries(assets.map((asset, index) => [
		asset.sha256,
		{
			attachment_id: 500 + index,
			url: `http://localhost:8080/wp-content/uploads/${asset.expected_filename}`,
		},
	]));
	const after = insertInlineVisuals(content, assets, uploads);
	assert.ok(after.indexOf(assets[0].marker_class) < after.indexOf(assets[1].marker_class));
	assert.match(after, /loading="lazy" decoding="async"/u);
	assert.match(after, /<!-- wp:html -->/u);
	assert.deepEqual(
		extractInlineVisualState(after, assets).map((entry) => entry.present),
		[true, true]
	);
	assert.equal(stripInlineVisuals(after, assets), content);
});

test('custom HTML placement remains inside its valid Gutenberg container', () => {
	const fragment = '<h2 id="punto-lectura">Punto de lectura</h2></div>';
	const content = [
		'<!-- wp:html -->',
		'<div class="aki-page"><div class="aki-page-section-header">',
		fragment,
		'<p>Explicación editorial.</p></div>',
		'<!-- /wp:html -->',
	].join('\n');
	const asset = {
		sha256: 'a'.repeat(64),
		sequence: 1,
		expected_filename: 'visual.webp',
		marker_class: `akb-inline-visual--${'a'.repeat(12)}`,
		alt: 'Descripción editorial completa del visual',
		caption: 'Pie editorial completo del visual',
		width: 1200,
		height: 675,
		anchor: {
			kind: 'custom_html_fragment',
			value: fragment,
			occurrence: 1,
			relation: 'after',
		},
	};
	const after = insertInlineVisuals(content, [asset], {
		[asset.sha256]: {
			attachment_id: 99,
			url: 'http://localhost:8080/uploads/visual.webp',
		},
	});
	assert.equal(topLevelGutenbergBlocks(after).length, 1);
	assert.equal(topLevelGutenbergBlocks(after)[0].name, 'html');
	assert.equal(stripInlineVisuals(after, [asset]), content);
});

test('anchors fail closed when ambiguous or outside a custom HTML block', () => {
	const repeated = [
		'<!-- wp:paragraph --><p>Misma ancla editorial</p><!-- /wp:paragraph -->',
		'<!-- wp:paragraph --><p>Misma ancla editorial</p><!-- /wp:paragraph -->',
	].join('\n');
	assert.throws(
		() => resolveInlineVisualAnchor(repeated, {
			kind: 'gutenberg_block_text',
			value: 'Misma ancla editorial',
			occurrence: 1,
			relation: 'after',
		}),
		/ANCHOR_MATCHES:2/u
	);
	assert.throws(
		() => resolveInlineVisualAnchor(
			'<!-- wp:paragraph --><p>Ancla externa</p><!-- /wp:paragraph -->',
			{
				kind: 'custom_html_fragment',
				value: '<p>Ancla externa</p>',
				occurrence: 1,
				relation: 'after',
			}
		),
		/OUTSIDE_CUSTOM_HTML/u
	);
});

test('browser functions compile and expose only official UI channels', () => {
	const manifest = generatedManifest();
	const asset = manifest.assets[0];
	const post = manifest.posts.find((entry) => entry.post_id === asset.post_id);
	const uploaded = {
		attachment_id: 600,
		url: `${manifest.target_origin}/wp-content/uploads/${asset.expected_filename}`,
		filename: asset.expected_filename,
		alt: asset.alt,
		caption: asset.caption,
	};
	const functions = [
		inlineVisualPreflightBrowserFunction({
			baseUrl: manifest.target_origin,
			post,
			assets: [asset],
		}),
		inlineVisualMediaProbeBrowserFunction({
			baseUrl: manifest.target_origin,
			asset,
		}),
		inlineVisualUploadBrowserFunction({
			baseUrl: manifest.target_origin,
			post,
			asset,
			absoluteLocalFile: `/local/${asset.expected_filename}`,
		}),
		inlineVisualApplyPostBrowserFunction({
			baseUrl: manifest.target_origin,
			post,
			assets: [asset],
			uploadedBySha256: { [asset.sha256]: uploaded },
			beforeContent: '<!-- wp:paragraph --><p>Contenido</p><!-- /wp:paragraph -->',
		}),
		inlineVisualRollbackPostBrowserFunction({
			baseUrl: manifest.target_origin,
			post,
			assets: [asset],
			beforeContent: '<!-- wp:paragraph --><p>Contenido</p><!-- /wp:paragraph -->',
		}),
		inlineVisualDeleteMediaBrowserFunction({
			baseUrl: manifest.target_origin,
			asset,
			media: uploaded,
		}),
		inlineVisualFrontendVerifyBrowserFunction({
			baseUrl: manifest.target_origin,
			post,
			assets: [asset],
			uploadedBySha256: { [asset.sha256]: uploaded },
		}),
	];
	for (const source of functions) {
		assert.doesNotThrow(() => assertInlineVisualBrowserFunctionSafety(source));
	}
	assert.match(functions[2], /setInputFiles/u);
	assert.match(functions[2], /#attachment_alt/u);
	assert.match(functions[2], /#attachment_caption/u);
	assert.match(functions[3], /GUTENBERG_BLOCK_VALIDATION_FAILED/u);
	assert.match(functions[4], /ROLLBACK_RELOAD_VERIFY_FAILED/u);
	assert.match(functions[5], /#delete-action a\.submitdelete:visible/u);
	assert.match(functions[6], /loading !== 'lazy'/u);
	assert.match(functions[6], /actual\.decoding !== 'async'/u);
	assert.match(functions[6], /srcset/u);
	assert.match(functions[6], /sizes/u);
});

test('Playwright exit-zero error envelope is rejected explicitly', () => {
	assert.throws(
		() => parseInlineVisualPlaywrightResult(JSON.stringify({
			isError: true,
			error: 'browser function exploded',
		})),
		/reported an error: browser function exploded/u
	);
	assert.throws(
		() => parseInlineVisualPlaywrightResult(JSON.stringify({
			isError: false,
		})),
		/reported an error: missing result/u
	);
	assert.deepEqual(
		parseInlineVisualPlaywrightResult(JSON.stringify({
			isError: false,
			result: JSON.stringify({ status: 'ok' }),
		})),
		{ status: 'ok' }
	);
});

test('runner records a 0600 intent before upload and has UI rollback recovery', () => {
	const source = fs.readFileSync(
		path.join(ROOT, 'ops/run-blog-inline-visuals-official-ui-playwright.mjs'),
		'utf8'
	);
	assert.match(source, /mode: 0o600/gu);
	assert.match(source, /state\.state = 'uploading';[\s\S]*replaceAtomic0600[\s\S]*inlineVisualUploadBrowserFunction/gu);
	assert.match(source, /phase = 'rolling_back'/gu);
	assert.match(source, /inlineVisualRollbackPostBrowserFunction/gu);
	assert.match(source, /inlineVisualDeleteMediaBrowserFunction/gu);
	assert.match(source, /filename already exists before upload/gu);
	assert.match(source, /const preflight = globalPreflight\(/gu);
	assert.match(
		source,
		/official UI mutation modes are production-only/u
	);
});

test('all 32 binary WebP files satisfy the final manifest contract', () => {
	const manifest = generatedManifest();
	for (const asset of manifest.assets) {
		const bytes = fs.readFileSync(path.join(ROOT, asset.local_file));
		assert.equal(bytes.length, asset.bytes);
		assert.equal(inlineVisualSha256(bytes), asset.sha256);
		assert.deepEqual(readWebpDimensions(bytes), {
			width: 1200,
			height: 675,
		});
	}
});

test('runner validate-only performs the real 25/32 local preflight', () => {
	const directory = path.join(
		ROOT,
		'.local-reports',
		`inline-visual-test-${process.pid}-${Date.now()}`
	);
	const manifestFile = path.join(directory, 'manifest.json');
	fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
	fs.writeFileSync(
		manifestFile,
		`${JSON.stringify(generatedManifest(), null, 2)}\n`,
		{ mode: 0o600, flag: 'wx' }
	);
	try {
		const result = spawnSync(
			process.execPath,
			[
				path.join(ROOT, 'ops/run-blog-inline-visuals-official-ui-playwright.mjs'),
				'--manifest',
				manifestFile,
				'--validate-only',
			],
			{ cwd: ROOT, encoding: 'utf8', timeout: 120_000 }
		);
		assert.equal(result.status, 0, result.stderr || result.stdout);
		const output = JSON.parse(result.stdout);
		assert.equal(output.result, 'PASS');
		assert.equal(output.posts, 25);
		assert.equal(output.assets, 32);
	} finally {
		fs.rmSync(directory, { recursive: true, force: true });
	}
});
