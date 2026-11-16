#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import test from 'node:test';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	calculateManifestSha256,
	finalizeManifest,
	flattenManifestItems,
	sha256,
	validateManifest,
} from './lib/blog-seo-official-ui-manifest.mjs';
import {
	mediaAltBrowserFunction,
	postEditorBrowserFunction,
} from './run-blog-seo-official-ui-playwright.mjs';

const opsDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.dirname(opsDirectory);
const runner = path.join(
	opsDirectory,
	'run-blog-seo-official-ui-playwright.mjs'
);
const generator = path.join(
	opsDirectory,
	'generate-blog-seo-official-ui-manifest.mjs'
);

function draft(environment = 'docker') {
	const targetOrigin = environment === 'docker'
		? 'http://localhost:8080'
		: 'https://akibara.cl';
	return {
		schema_version: 1,
		manifest_type: 'akibara_blog_seo_official_ui',
		environment,
		target_origin: targetOrigin,
		application_policy: {
			official_wordpress_ui_only: true,
			official_rank_math_ui_only: true,
			direct_rest_writes: 0,
			direct_sql_writes: 0,
			direct_wp_cli_writes: 0,
			direct_meta_writes: 0,
			remote_artifacts: 0,
		},
		posts: [{
			post_id: 22638,
			post_type: 'post',
			edit_path: '/wp-admin/post.php?post=22638&action=edit',
			public_url: `${targetOrigin}/blog/jujutsu-kiasen/`,
			minimum_rank_math_score: 82,
			fields: {
				post_title: {
					before: 'Título anterior',
					after: 'Jujutsu Kaisen: dónde continuar después del anime',
				},
				post_content: {
					before: '<!-- wp:paragraph --><p>Anterior.</p><!-- /wp:paragraph -->',
					after: '<!-- wp:paragraph --><p>Contenido editorial optimizado y útil para lectores.</p><!-- /wp:paragraph -->',
				},
				post_excerpt: {
					before: 'Extracto anterior.',
					after: 'Descubre en qué tomo continuar Jujutsu Kaisen después del anime.',
				},
				rank_math_focus_keyword: {
					before: 'jujutsu kaisen',
					after: 'en qué tomo continuar Jujutsu Kaisen,jujutsu kaisen manga',
				},
				rank_math_title: {
					before: 'Jujutsu Kaisen',
					after: 'En qué tomo continuar Jujutsu Kaisen | Akibara',
				},
				rank_math_description: {
					before: 'Una descripción anterior suficientemente extensa para el contrato del ejemplo de SEO de Akibara.',
					after: 'Descubre en qué tomo continuar Jujutsu Kaisen tras el anime, qué capítulos leer y dónde comprar el manga con envío a todo Chile.',
				},
			},
			featured_image_alt: {
				attachment_id: 25101,
				edit_path: '/wp-admin/post.php?post=25101&action=edit',
				expected_filename: 'jujutsu-kiasen.webp',
				before: 'Portada',
				after: 'Jujutsu Kaisen: guía para continuar el manga después del anime',
			},
		}],
	};
}

function createFakeNpx(binDirectory) {
	const fake = `#!/usr/bin/env node
const fs = require('node:fs');
const args = process.argv.slice(2);
if (args.includes('dialog-accept')) process.exit(0);
const filenameArg = args.find(value => value.startsWith('--filename='));
if (!filenameArg) process.exit(90);
const functionPath = filenameArg.slice('--filename='.length);
const source = fs.readFileSync(functionPath, 'utf8');
const mode = fs.statSync(functionPath).mode & 0o777;
let kind = '';
let payload = null;
let phase = '';
if (source.includes('AKB_BLOG_SEO_POST_UI')) {
	kind = 'post';
	const match = /const post = (\\{[\\s\\S]*?\\});\\n\\t\\tconst phase = ("[^"]+");/u.exec(source);
	if (!match) process.exit(91);
	payload = JSON.parse(match[1]);
	phase = JSON.parse(match[2]);
} else if (source.includes('AKB_BLOG_SEO_MEDIA_UI')) {
	kind = 'media';
	const match = /const media = (\\{[\\s\\S]*?\\});\\n\\t\\tconst phase = ("[^"]+");/u.exec(source);
	if (!match) process.exit(92);
	payload = JSON.parse(match[1]);
	phase = JSON.parse(match[2]);
} else {
	process.exit(93);
}
fs.appendFileSync(
	process.env.AKB_FAKE_LOG,
	JSON.stringify({ kind, phase, mode, functionPath }) + '\\n',
	{ mode: 0o600 }
);
let records = [];
if (kind === 'post') {
	records = Object.entries(payload.fields).map(([field, contract], index) => ({
		key: 'post:' + payload.post_id + ':' + field,
		current: process.env.AKB_FAKE_DRIFT === '1'
			&& phase === 'preflight' && index === 0
				? 'external drift'
				: contract[phase === 'apply' ? 'after' : 'before'],
	}));
} else {
	records = [{
		key: 'attachment:' + payload.attachment_id + ':alt',
		current: payload[phase === 'apply' ? 'after' : 'before'],
	}];
}
const result = {
	records,
	blocked: process.env.AKB_FAKE_DRIFT === '1'
		&& phase === 'preflight' && kind === 'post'
			? 'POST_FIELD_DRIFT'
			: null,
	...(kind === 'post' ? {
		score: { score: phase === 'apply' ? 84 : 16, samples: [84, 84, 84, 84] },
	} : {}),
};
process.stdout.write(JSON.stringify({ result: JSON.stringify(result) }));
`;
	const file = path.join(binDirectory, 'npx');
	fs.writeFileSync(file, fake, { mode: 0o700 });
}

test('manifest hashing is deterministic and tampering is rejected', () => {
	const first = finalizeManifest(draft());
	const second = finalizeManifest(JSON.parse(JSON.stringify(draft())));
	assert.equal(first.manifest_sha256, second.manifest_sha256);
	assert.equal(first.manifest_sha256, calculateManifestSha256(first));
	assert.equal(
		first.posts[0].fields.post_content.after_sha256,
		sha256(first.posts[0].fields.post_content.after)
	);
	const tampered = structuredClone(first);
	tampered.posts[0].fields.post_title.after += ' modificado';
	assert.throws(() => validateManifest(tampered), /hash mismatch/u);

	const productionWithDeferred = draft('production');
	productionWithDeferred.sources = {
		desired_sha256: 'a'.repeat(64),
		production_source_sha256: 'b'.repeat(64),
		environment_source_sha256: 'c'.repeat(64),
		production_capture_policy:
			'official_wordpress_rankmath_ui_read_only',
		environment_capture_policy:
			'official_wordpress_rankmath_ui_read_only',
		deferred_content_keys: [
			'que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile',
		],
		deferred_validation_artifact_sha256: 'd'.repeat(64),
		deferred_validation_plan_sha256: 'e'.repeat(64),
		deferred_validation_score: 84,
		deferred_cleanup_result: 'PASS',
	};
	assert.throws(
		() => finalizeManifest(productionWithDeferred),
		/production manifest may not contain deferred validation/u
	);
});

test('generated browser functions are official-UI only and score-stable', () => {
		const manifest = finalizeManifest(draft('production'));
	const postSource = postEditorBrowserFunction({
		baseUrl: manifest.target_origin,
		post: manifest.posts[0],
		phase: 'preflight',
	});
	const mediaSource = mediaAltBrowserFunction({
		baseUrl: manifest.target_origin,
		media: manifest.posts[0].featured_image_alt,
		phase: 'preflight',
	});
	for (const source of [postSource, mediaSource]) {
		assert.doesNotMatch(
			source,
			/fetch\s*\(|wp-json|admin-ajax\.php|update_post_meta|update_option|\$wpdb/iu
		);
		assert.doesNotThrow(() => Function(`return (${source});`));
	}
	assert.match(postSource, /stableSamples >= 4/u);
	assert.match(postSource, /const minimumSettleMs = 6000/u);
	assert.match(
		postSource,
		/settledMs >= minimumSettleMs/u,
		'the first briefly stable score must not win before Rank Math settles'
	);
	assert.match(postSource, /attempt < 80/u);
	assert.match(postSource, /RANK_MATH_SCORE_NOT_STABLE/u);
	assert.match(postSource, /RANK_MATH_SCORE_BELOW_TARGET/u);
	const scoreCandidateBlock = /const candidates = page\.locator\(([\s\S]*?)\);\n\s*const scores/u
		.exec(postSource)?.[1] ?? '';
	assert.match(
		scoreCandidateBlock,
		/button\[aria-label="Rank Math" i\]:visible/u
	);
	assert.doesNotMatch(
		scoreCandidateBlock,
		/\.rank-math-toolbar-score|\.rank-math-score|content-ai-score/u
	);
	assert.match(
		postSource,
		/await candidate\.textContent\(\),\s*await candidate\.getAttribute\('aria-label'\)/u,
		'score must read visible 77 / 100 before the non-numeric aria label'
	);
	assert.match(postSource, /POST_STATUS_UI_MISMATCH/u);
	assert.match(postSource, /POST_SAVE_CONTROL_STATUS_MISMATCH/u);
	assert.match(mediaSource, /FEATURED_MEDIA_FILENAME_MISMATCH/u);
	const toggleBlock = /const toggles = page\.locator\(\[([\s\S]*?)\]\.join/u
		.exec(postSource)?.[1] ?? '';
	assert.match(toggleBlock, /button\[aria-label="Rank Math" i\]/u);
	assert.match(
		toggleBlock,
		/button\[aria-controls="rank-math:seo-by-rank-math-sidebar"\]/u
	);
	assert.doesNotMatch(
		toggleBlock,
		/\.rank-math-toolbar-score|\.rank-math-score|aria-controls\*=/u,
		'the actionable toggle must not match score children or Content AI'
	);
	assert.doesNotMatch(
		toggleBlock,
		/rank-math-content-ai:seo-by-rank-math-content-ai-sidebar/u
	);
	assert.match(
		postSource,
		/surface\.locator\('\.rank-math-focus-keyword'\)[\s\S]*?waitFor\(\{ state: 'visible', timeout: 15000 \}\)/u
	);
	assert.match(
		postSource,
		/surface\.locator\('\.rank-math-edit-snippet'\)[\s\S]*?waitFor\(\{ state: 'visible', timeout: 15000 \}\)/u
	);
	const wordpressRead = postSource.indexOf(
		'wordpressValues[field] = await wordpressValue(field, locator)'
	);
	const rankMathOpen = postSource.indexOf(
		'const surface = await openRankMathGeneral()',
		wordpressRead
	);
	assert.ok(
		wordpressRead > -1 && rankMathOpen > wordpressRead,
		'WordPress sidebar values must be materialized before Rank Math unmounts it'
	);
	assert.doesNotMatch(postSource, /state\.fields/u);
	assert.doesNotMatch(postSource, /wordpressFields/u);
	assert.equal(
		(postSource.match(
			/const locator = await wordpressField\(field\);/gu
		) ?? []).length,
		2,
		'each write pass must reacquire locators after Gutenberg remounts controls'
	);
	assert.match(
		postSource,
		/const current = await wordpressValue\(field, locator\);[\s\S]*?await locator\.fill\(post\.fields\[field\]\[writeTargetSide\]\)/u
	);
	const closeBeforeFocus = postSource.indexOf(
		'await closeSnippetIfOpen();',
		postSource.indexOf('if (writeReviewedBeforeValues)')
	);
	const writeFocus = postSource.indexOf(
		'await writeFocusKeywords(',
		closeBeforeFocus
	);
	assert.ok(
		closeBeforeFocus > -1 && writeFocus > closeBeforeFocus,
		'Rank Math snippet modal must close before focus keyword interaction'
	);
	assert.match(
		postSource,
		/await closeSnippetIfOpen\(\);\s*const writeSurface = await openRankMathGeneral\(\);/u
	);
	assert.match(
		postSource,
		/state = await readState\(\s*phase === 'rollback' \? 'before' : 'after'\s*\);\s*await closeSnippetIfOpen\(\);\s*const saveSelector =[\s\S]*?const save = await oneVisible/u,
		'snippet modal must be hidden before resolving and clicking Save'
	);
	assert.match(postSource, /saveSelector\.replaceAll\(':visible', ''\)/u);
	assert.doesNotMatch(postSource, /saveSelector\.replace\(':visible', ''\)/u);
	const closeAfterWrite = postSource.indexOf(
		'await closeSnippetIfOpen();',
		postSource.indexOf('state = await readState(true);')
	);
	const saveClick = postSource.indexOf('await save.click();', closeAfterWrite);
	assert.ok(
		closeAfterWrite > -1 && saveClick > closeAfterWrite,
		'Save must be clicked only after the snippet modal is closed'
	);
	assert.doesNotMatch(postSource, /force:\s*true/u);
	assert.match(postSource, /\.editor-post-title__input textarea/u);
	assert.match(postSource, /iframe\[name="editor-canvas"\]/u);
	assert.match(postSource, /canvas\.contentFrame\(\)/u);
	assert.doesNotMatch(
		postSource,
		/'\.editor-post-title__input',/u,
		'the WordPress 7 title wrapper must never be read as the title value'
	);
	assert.match(
		postSource,
		/const roots = \[page\]/u
	);
	assert.match(postSource, /Guardar\|Save\|Actualizar\|Update/u);
	assert.match(postSource, /Publicar\|Publish/u);
	assert.match(postSource, /expectedPostStatus === 'draft'[\s\S]*?\.editor-post-save-draft:visible/u);
	const deferredSource = postEditorBrowserFunction({
		baseUrl: manifest.target_origin,
		post: manifest.posts[0],
		phase: 'apply_deferred_score',
	});
	const verifySource = postEditorBrowserFunction({
		baseUrl: manifest.target_origin,
		post: manifest.posts[0],
		phase: 'verify_score',
	});
	const rollbackSource = postEditorBrowserFunction({
		baseUrl: manifest.target_origin,
		post: manifest.posts[0],
		phase: 'rollback',
	});
	assert.match(deferredSource, /const phase = "apply_deferred_score"/u);
	assert.match(
		deferredSource,
		/phase === 'verify_score'[\s\S]*?phase === 'apply'[\s\S]*?phase === 'preflight'/u
	);
	assert.doesNotMatch(
		deferredSource,
		/phase === 'apply_deferred_score'\s*\|\|[\s\S]*?stableRankMathScore/u,
		'staged content must not execute the final score gate'
	);
	assert.match(verifySource, /POST_NOT_AT_REVIEWED_AFTER/u);
	assert.match(verifySource, /RANK_MATH_SCORE_BELOW_TARGET/u);
	assert.match(rollbackSource, /ROLLBACK_FIELD_VERIFY_FAILED/u);
	assert.match(
		rollbackSource,
		/phase === 'rollback' \? 'before' : 'after'/u
	);
});

test('draft posts save through Gutenberg without clicking Publish', () => {
	const manifest = finalizeManifest({
		...draft('production'),
		posts: [{
			...draft('production').posts[0],
			post_status: 'draft',
		}],
	});
	const source = postEditorBrowserFunction({
		baseUrl: manifest.target_origin,
		post: manifest.posts[0],
		phase: 'apply',
	});
	assert.match(source, /expectedPostStatus === 'draft'/u);
	assert.match(source, /\.editor-post-save-draft:visible/u);
	assert.match(source, /Guardar borrador\|Save draft/u);
	assert.match(source, /POST_STATUS_UI_MISMATCH/u);
});

test('apply requires reviewed SHA, preflights all items and writes 0600 checkpoint', () => {
	const fixtureRoot = fs.mkdtempSync(
		path.join(repositoryRoot, '.local-reports/blog-seo-runner-test-')
	);
	const binDirectory = path.join(fixtureRoot, 'bin');
	const manifestPath = path.join(fixtureRoot, 'manifest.json');
	const checkpointPath = path.join(fixtureRoot, 'checkpoint.json');
	const logPath = path.join(fixtureRoot, 'calls.jsonl');
	fs.mkdirSync(binDirectory);
	createFakeNpx(binDirectory);
	const manifest = finalizeManifest(draft('production'));
	fs.writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`, {
		mode: 0o600,
	});
	const invoke = (extra, env = {}) => spawnSync(
		process.execPath,
		[
			runner,
			'--manifest', manifestPath,
			'--base-url', manifest.target_origin,
			'--session', 'akb-blog-seo-production',
			'--checkpoint', checkpointPath,
			...extra,
		],
		{
			encoding: 'utf8',
			env: {
				...process.env,
				...env,
				PATH: `${binDirectory}${path.delimiter}${process.env.PATH}`,
				AKB_FAKE_LOG: logPath,
			},
		}
	);
	try {
		const missingConfirmation = invoke(['--apply']);
		assert.notEqual(missingConfirmation.status, 0);
		assert.match(missingConfirmation.stderr, /confirm-manifest-sha256/u);
		assert.equal(fs.existsSync(logPath), false);

		const applied = invoke([
			'--apply',
			'--confirm-manifest-sha256',
			manifest.manifest_sha256,
		]);
		assert.equal(applied.status, 0, applied.stderr);
		const output = JSON.parse(applied.stdout);
		assert.equal(output.result, 'PASS');
		assert.equal(output.status_counts.applied, 7);
		assert.equal(fs.statSync(checkpointPath).mode & 0o777, 0o600);
		const calls = fs.readFileSync(logPath, 'utf8')
			.trim().split('\n').map((line) => JSON.parse(line));
		assert.deepEqual(
			calls.map(({ kind, phase }) => `${phase}:${kind}`),
			['preflight:post', 'preflight:media', 'apply:media', 'apply:post']
		);
		assert.ok(calls.every(({ mode }) => mode === 0o600));
		assert.ok(calls.every(({ functionPath }) => !fs.existsSync(functionPath)));

		fs.writeFileSync(logPath, '', { mode: 0o600 });
		const driftedCheckpoint = path.join(fixtureRoot, 'drift-checkpoint.json');
		const drift = spawnSync(
			process.execPath,
			[
				runner,
				'--manifest', manifestPath,
				'--base-url', manifest.target_origin,
				'--session', 'akb-blog-seo-production',
				'--checkpoint', driftedCheckpoint,
				'--apply',
				'--confirm-manifest-sha256',
				manifest.manifest_sha256,
			],
			{
				encoding: 'utf8',
				env: {
					...process.env,
					PATH: `${binDirectory}${path.delimiter}${process.env.PATH}`,
					AKB_FAKE_LOG: logPath,
					AKB_FAKE_DRIFT: '1',
				},
			}
		);
		assert.notEqual(drift.status, 0);
		assert.match(drift.stderr, /POST_FIELD_DRIFT/u);
		const driftCalls = fs.readFileSync(logPath, 'utf8')
			.trim().split('\n').filter(Boolean).map((line) => JSON.parse(line));
		assert.deepEqual(
			driftCalls.map(({ phase }) => phase),
			['preflight']
		);
	} finally {
		fs.rmSync(fixtureRoot, { recursive: true, force: true });
	}
});

test('Docker mutation modes fail before browser work', () => {
	const fixtureRoot = fs.mkdtempSync(
		path.join(repositoryRoot, '.local-reports/blog-seo-read-only-test-')
	);
	const manifest = finalizeManifest(draft('docker'));
	const manifestPath = path.join(fixtureRoot, 'manifest.json');
	const checkpointPath = path.join(fixtureRoot, 'checkpoint.json');
	fs.writeFileSync(manifestPath, JSON.stringify(manifest), { mode: 0o600 });
	try {
		const result = spawnSync(
			process.execPath,
			[
				runner,
				'--manifest', manifestPath,
				'--base-url', manifest.target_origin,
				'--session', 'akb-blog-seo-docker',
				'--checkpoint', checkpointPath,
				'--apply',
				'--confirm-manifest-sha256',
				manifest.manifest_sha256,
			],
			{ encoding: 'utf8' }
		);
		assert.notEqual(result.status, 0);
		assert.match(result.stderr, /production-only/u);
		assert.equal(fs.existsSync(checkpointPath), false);
	} finally {
		fs.rmSync(fixtureRoot, { recursive: true, force: true });
	}
});

test('environment and checkpoint guards fail before browser work', () => {
	const fixtureRoot = fs.mkdtempSync(
		path.join(repositoryRoot, '.local-reports/blog-seo-guards-test-')
	);
	const manifest = finalizeManifest(draft('production'));
	const manifestPath = path.join(fixtureRoot, 'manifest.json');
	fs.writeFileSync(manifestPath, JSON.stringify(manifest), { mode: 0o600 });
	try {
		const wrongOrigin = spawnSync(
			process.execPath,
			[
				runner,
				'--manifest', manifestPath,
				'--base-url', 'http://localhost:8080',
				'--session', 'akb-blog-seo-production',
				'--checkpoint', path.join(fixtureRoot, 'checkpoint.json'),
			],
			{ encoding: 'utf8' }
		);
		assert.notEqual(wrongOrigin.status, 0);
		assert.match(wrongOrigin.stderr, /target_origin/u);
		const remoteCheckpoint = spawnSync(
			process.execPath,
			[
				runner,
				'--manifest', manifestPath,
				'--base-url', manifest.target_origin,
				'--session', 'akb-blog-seo-production',
				'--checkpoint', '/tmp/not-akibara-local.json',
			],
			{ encoding: 'utf8' }
		);
		assert.notEqual(remoteCheckpoint.status, 0);
		assert.match(remoteCheckpoint.stderr, /local \.local-reports/u);
	} finally {
		fs.rmSync(fixtureRoot, { recursive: true, force: true });
	}
});

test('generator binds both UI sources and fails closed when Docker lacks 24977', () => {
	const fixtureRoot = fs.mkdtempSync(
		path.join(repositoryRoot, '.local-reports/blog-seo-generator-test-')
	);
	const desiredPath = path.join(fixtureRoot, 'desired.json');
	const productionPath = path.join(fixtureRoot, 'production-source.json');
	const dockerPath = path.join(fixtureRoot, 'docker-source.json');
	const outputPath = path.join(fixtureRoot, 'manifest.json');
	const deferredOutputPath = path.join(
		fixtureRoot,
		'deferred-manifest.json'
	);
	const deferredKey =
		'que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile';
	const desiredPosts = [];
	const sourcePosts = [];
	for (let index = 0; index < 25; index += 1) {
		const contentKey = index === 24 ? deferredKey : `post-${23000 + index}`;
		const postId = index === 24 ? 24977 : 23000 + index;
		const afterFields = {
			post_title: `Título SEO ${index}`,
			post_content: `<!-- wp:paragraph --><p>Contenido SEO útil ${index}.</p><!-- /wp:paragraph -->`,
			post_excerpt: `Extracto SEO útil ${index}.`,
			rank_math_focus_keyword: `palabra clave ${index}`,
			rank_math_title: `Palabra clave ${index} y guía completa | Akibara`,
			rank_math_description:
				`Descubre la guía completa número ${index} con información útil, recomendaciones claras y disponibilidad para lectores de manga en Chile.`,
		};
		desiredPosts.push({
			content_key: contentKey,
			minimum_rank_math_score: 82,
			fields: afterFields,
			featured_image_alt: `Imagen destacada optimizada ${index}`,
		});
		sourcePosts.push({
			content_key: contentKey,
			post_id: postId,
			public_url: `https://akibara.cl/blog/${contentKey}/`,
			fields: Object.fromEntries(
				Object.keys(afterFields).map((field) => [
					field,
					`Valor anterior ${field} ${index}`,
				])
			),
			featured_image: {
				attachment_id: 30000 + index,
				filename: `${contentKey}.webp`,
				alt: `Alt anterior ${index}`,
			},
		});
	}
	const desired = {
		schema_version: 1,
		desired_type: 'akibara_blog_seo_desired',
		posts: desiredPosts,
	};
	const source = (environment, targetOrigin, posts) => ({
		schema_version: 1,
		capture_type: 'akibara_blog_seo_official_ui_read_only',
		capture_tool: 'akibara-playwright-official-ui-v1',
		environment,
		target_origin: targetOrigin,
		capture_policy: 'official_wordpress_rankmath_ui_read_only',
		direct_writes: 0,
		review_sha256: 'a'.repeat(64),
		captured_at: '2026-07-26T12:00:00.000Z',
		posts,
	});
	try {
		fs.writeFileSync(desiredPath, JSON.stringify(desired), { mode: 0o600 });
		fs.writeFileSync(
			productionPath,
			JSON.stringify(source(
				'production',
				'https://akibara.cl',
				sourcePosts
			)),
			{ mode: 0o600 }
		);
		const dockerPosts = sourcePosts.slice(0, -1).map((post) => ({
			...post,
			public_url: post.public_url.replace(
				'https://akibara.cl',
				'http://localhost:8080'
			),
		}));
		fs.writeFileSync(
			dockerPath,
			JSON.stringify(source(
				'docker',
				'http://localhost:8080',
				dockerPosts
			)),
			{ mode: 0o600 }
		);
		const missing = spawnSync(
			process.execPath,
			[
				generator,
				'--desired', desiredPath,
				'--production-source', productionPath,
				'--environment-source', dockerPath,
				'--environment', 'docker',
				'--output', outputPath,
			],
			{ encoding: 'utf8' }
		);
		assert.notEqual(missing.status, 0);
		assert.match(
			missing.stderr,
			/docker source post count does not match its deferred contract/u
		);
		assert.equal(fs.existsSync(outputPath), false);

		const deferredEvidence = {
			content_key: deferredKey,
			artifact_sha256: 'b'.repeat(64),
			report_type:
				'akibara_blog_seo_temporary_docker_draft_official_ui_evidence',
			plan_sha256: 'c'.repeat(64),
			stable_rank_math_score: 84,
			cleanup_result: 'PASS',
		};
		fs.writeFileSync(
			dockerPath,
			JSON.stringify({
				...source(
					'docker',
					'http://localhost:8080',
					dockerPosts
				),
				deferred_content_keys: [deferredKey],
				validation_artifact: deferredEvidence,
			}),
			{ mode: 0o600 }
		);
		const deferred = spawnSync(
			process.execPath,
			[
				generator,
				'--desired', desiredPath,
				'--production-source', productionPath,
				'--environment-source', dockerPath,
				'--environment', 'docker',
				'--output', deferredOutputPath,
			],
			{ encoding: 'utf8' }
		);
		assert.equal(deferred.status, 0, deferred.stderr);
		const deferredManifest = validateManifest(
			JSON.parse(fs.readFileSync(deferredOutputPath, 'utf8'))
		);
		assert.equal(deferredManifest.posts.length, 24);
		assert.deepEqual(
			deferredManifest.sources.deferred_content_keys,
			[deferredKey]
		);
		assert.equal(deferredManifest.sources.deferred_validation_score, 84);

		const tamperedPath = path.join(fixtureRoot, 'tampered-manifest.json');
		fs.writeFileSync(
			dockerPath,
			JSON.stringify({
				...source(
					'docker',
					'http://localhost:8080',
					dockerPosts
				),
				deferred_content_keys: [deferredKey],
				validation_artifact: {
					...deferredEvidence,
					stable_rank_math_score: 81,
				},
			}),
			{ mode: 0o600 }
		);
		const tampered = spawnSync(
			process.execPath,
			[
				generator,
				'--desired', desiredPath,
				'--production-source', productionPath,
				'--environment-source', dockerPath,
				'--environment', 'docker',
				'--output', tamperedPath,
			],
			{ encoding: 'utf8' }
		);
		assert.notEqual(tampered.status, 0);
		assert.match(tampered.stderr, /deferred validation/u);
		assert.equal(fs.existsSync(tamperedPath), false);

		const restored = {
			...sourcePosts.at(-1),
			post_id: 34977,
			public_url: `http://localhost:8080/blog/${deferredKey}/`,
			featured_image: {
				...sourcePosts.at(-1).featured_image,
				attachment_id: 44977,
			},
		};
		dockerPosts.push(restored);
		fs.writeFileSync(
			dockerPath,
			JSON.stringify(source(
				'docker',
				'http://localhost:8080',
				dockerPosts
			)),
			{ mode: 0o600 }
		);
		const generated = spawnSync(
			process.execPath,
			[
				generator,
				'--desired', desiredPath,
				'--production-source', productionPath,
				'--environment-source', dockerPath,
				'--environment', 'docker',
				'--output', outputPath,
			],
			{ encoding: 'utf8' }
		);
		assert.equal(generated.status, 0, generated.stderr);
		const manifest = validateManifest(
			JSON.parse(fs.readFileSync(outputPath, 'utf8'))
		);
		assert.equal(manifest.posts.length, 25);
		assert.equal(manifest.posts.at(-1).post_id, 34977);
		assert.equal(
			manifest.posts.at(-1).featured_image_alt.attachment_id,
			44977
		);
		assert.equal(fs.statSync(outputPath).mode & 0o777, 0o600);
	} finally {
		fs.rmSync(fixtureRoot, { recursive: true, force: true });
	}
});

test('generator accepts a 36-post content-only contract with zero media mutations', () => {
	const fixtureRoot = fs.mkdtempSync(
		path.join(repositoryRoot, '.local-reports/blog-seo-content-only-test-')
	);
	const desiredPath = path.join(fixtureRoot, 'desired.json');
	const productionPath = path.join(fixtureRoot, 'production-source.json');
	const dockerPath = path.join(fixtureRoot, 'docker-source.json');
	const outputPath = path.join(fixtureRoot, 'manifest.json');
	const posts = Array.from({ length: 36 }, (_, index) => {
		const contentKey = `entrada-editorial-${index + 1}`;
		const fields = {
			post_title: `Entrada editorial ${index + 1}`,
			post_content:
				`<!-- wp:paragraph --><p>Contenido revisado ${index + 1}.</p><!-- /wp:paragraph -->`,
			post_excerpt: `Extracto revisado ${index + 1}.`,
			rank_math_focus_keyword: `entrada editorial ${index + 1}`,
			rank_math_title: `Entrada editorial ${index + 1} | Akibara`,
			rank_math_description:
				`Guía editorial revisada número ${index + 1} con datos claros, fuentes verificadas y recomendaciones útiles para lectores de manga en Chile.`,
		};
		return { contentKey, fields };
	});
	const desired = {
		schema_version: 1,
		desired_type: 'akibara_blog_seo_desired',
		inventory_post_count: 36,
		posts: posts.map(({ contentKey, fields }) => ({
			content_key: contentKey,
			minimum_rank_math_score: 82,
			fields,
		})),
	};
	const capture = (environment, targetOrigin) => ({
		schema_version: 1,
		capture_type: 'akibara_blog_seo_official_ui_read_only',
		capture_tool: 'akibara-playwright-official-ui-v1',
		environment,
		target_origin: targetOrigin,
		capture_policy: 'official_wordpress_rankmath_ui_read_only',
		capture_scope: 'content_and_seo',
		direct_writes: 0,
		inventory_post_count: 36,
		review_sha256: 'a'.repeat(64),
		captured_at: '2026-08-18T12:00:00.000Z',
		posts: posts.map(({ contentKey, fields }, index) => ({
			content_key: contentKey,
			post_id: 30000 + index,
			public_url: `${targetOrigin}/blog/${contentKey}/`,
			fields: Object.fromEntries(
				Object.keys(fields).map((field) => [
					field,
					`Valor anterior ${field} ${index + 1}`,
				])
			),
		})),
	});
	try {
		fs.writeFileSync(desiredPath, JSON.stringify(desired), { mode: 0o600 });
		fs.writeFileSync(
			productionPath,
			JSON.stringify(capture('production', 'https://akibara.cl')),
			{ mode: 0o600 }
		);
		fs.writeFileSync(
			dockerPath,
			JSON.stringify(capture('docker', 'http://localhost:8080')),
			{ mode: 0o600 }
		);
		const generated = spawnSync(
			process.execPath,
			[
				generator,
				'--desired', desiredPath,
				'--production-source', productionPath,
				'--environment-source', dockerPath,
				'--environment', 'docker',
				'--skip-featured-image-alt',
				'--output', outputPath,
			],
			{ encoding: 'utf8' }
		);
		assert.equal(generated.status, 0, generated.stderr);
		const manifest = validateManifest(
			JSON.parse(fs.readFileSync(outputPath, 'utf8'))
		);
		assert.equal(manifest.posts.length, 36);
		assert.equal(
			manifest.posts.some((post) => post.featured_image_alt),
			false
		);
		assert.equal(flattenManifestItems(manifest).length, 216);
	} finally {
		fs.rmSync(fixtureRoot, { recursive: true, force: true });
	}
});
