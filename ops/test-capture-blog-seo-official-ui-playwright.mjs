#!/usr/bin/env node

import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import test from 'node:test';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	captureMediaBrowserFunction,
	capturePostBrowserFunction,
} from './capture-blog-seo-official-ui-playwright.mjs';

const ops = path.dirname(fileURLToPath(import.meta.url));
const root = path.dirname(ops);
const capture = path.join(
	ops,
	'capture-blog-seo-official-ui-playwright.mjs'
);

function reviewPost(index) {
	return {
		content_key: `entrada-seo-${index}`,
		post_id: 22000 + index,
		public_url: `http://localhost:8080/entrada-seo-${index}/`,
		featured_image: {
			attachment_id: 32000 + index,
			filename: `entrada-seo-${index}.webp`,
		},
	};
}

test('capture browser functions are read-only and exclude Content AI', () => {
	const post = reviewPost(1);
	const sources = [
		capturePostBrowserFunction({
			baseUrl: 'http://localhost:8080',
			post,
		}),
		captureMediaBrowserFunction({
			baseUrl: 'http://localhost:8080',
			post,
		}),
	];
	for (const source of sources) {
		assert.doesNotThrow(() => Function(`return (${source});`));
		assert.doesNotMatch(
			source,
			/fetch\s*\(|wp-json|admin-ajax|update_post_meta|update_option|\$wpdb/iu
		);
		assert.doesNotMatch(source, /\.fill\s*\(|\.press\s*\(/u);
	}
	assert.match(
		sources[0],
		/aria-controls="rank-math:seo-by-rank-math-sidebar"/u
	);
	assert.doesNotMatch(sources[0], /aria-controls\*=/u);
	assert.doesNotMatch(
		sources[0],
		/rank-math-content-ai:seo-by-rank-math-content-ai-sidebar/u
	);
	assert.doesNotMatch(
		sources[1],
		/\bnew URL\s*\(/u,
		'browser CLI functions cannot rely on the Node URL global'
	);
	assert.match(
		sources[1],
		/await alt\.waitFor\(\{ state: 'visible', timeout: 15000 \}\)/u
	);
	assert.match(
		sources[1],
		/await source\.waitFor\(\{ state: 'visible', timeout: 15000 \}\)/u
	);
	assert.match(sources[1], /dialog\.type\(\) !== 'beforeunload'/u);
	assert.match(
		sources[1],
		/page\.goto\(baseUrl \+ '\/wp-admin\/profile\.php'/u
	);
	const wordpressRead = sources[0].indexOf(
		'post_excerpt: (await excerpt.inputValue())'
	);
	const rankMathOpen = sources[0].indexOf(
		'const surface = await openRankMathGeneral()',
		wordpressRead
	);
	assert.ok(
		wordpressRead > -1 && rankMathOpen > wordpressRead,
		'capture must read the excerpt before Rank Math unmounts Settings'
	);
	assert.match(sources[0], /\.editor-post-title__input textarea/u);
	assert.match(sources[0], /iframe\[name="editor-canvas"\]/u);
	assert.match(sources[0], /canvas\.contentFrame\(\)/u);
	assert.doesNotMatch(sources[0], /'\.editor-post-title__input',/u);
	assert.match(sources[0], /poll < 60/u);
	assert.match(
		sources[0],
		/\.editor-post-publish-button__button:visible/u
	);
	assert.match(sources[0], /Guardar\|Save\|Actualizar\|Update/u);
	assert.match(sources[0], /Publicar\|Publish/u);
	assert.match(sources[0], /POST_STATUS_UI_MISMATCH/u);
	assert.match(sources[0], /dialog\.type\(\) !== 'beforeunload'/u);
	assert.match(
		sources[0],
		/page\.goto\(baseUrl \+ '\/wp-admin\/profile\.php'/u
	);
});

test('capture reads all 25 reviewed posts and media to a local 0600 source', () => {
	const fixture = fs.mkdtempSync(
		path.join(root, '.local-reports/blog-seo-capture-test-')
	);
	const bin = path.join(fixture, 'bin');
	const reviewPath = path.join(fixture, 'review.json');
	const output = path.join(fixture, 'source.json');
	fs.mkdirSync(bin);
	fs.writeFileSync(
		reviewPath,
		JSON.stringify({
			schema_version: 1,
			review_type: 'akibara_blog_seo_ui_capture_review',
			environment: 'docker',
			target_origin: 'http://localhost:8080',
			reviewed: true,
			posts: Array.from({ length: 25 }, (_, index) => reviewPost(index)),
		}),
		{ mode: 0o600 }
	);
const fakeNpx = `#!/usr/bin/env node
const fs = require('node:fs');
const arg = process.argv.find(value => value.startsWith('--filename='));
if (!arg) process.exit(0);
const transportMarker = require('node:path').join(
	__dirname,
	'transport-retry-seen'
);
if (!fs.existsSync(transportMarker)) {
	fs.writeFileSync(transportMarker, '1');
	process.stdout.write('{}');
	process.exit(0);
}
const source = fs.readFileSync(arg.slice('--filename='.length), 'utf8');
const match = /const post = (\\{[\\s\\S]*?\\});\\n/u.exec(source);
if (!match) process.exit(82);
const post = JSON.parse(match[1]);
let result;
if (source.includes('AKB_BLOG_SEO_CAPTURE_POST_UI_READ_ONLY')) {
	result = { fields: {
		post_title: 'Título ' + post.content_key,
		post_content: '<!-- wp:paragraph --><p>Contenido.</p><!-- /wp:paragraph -->',
		post_excerpt: 'Extracto',
		rank_math_focus_keyword: 'palabra clave',
		rank_math_title: 'Título SEO | Akibara',
		rank_math_description: 'Descripción SEO de captura oficial.',
	} };
} else if (source.includes('AKB_BLOG_SEO_CAPTURE_MEDIA_UI_READ_ONLY')) {
	result = {
		attachment_id: post.featured_image.attachment_id,
		filename: post.featured_image.filename,
		alt: 'Alt ' + post.content_key,
	};
} else process.exit(83);
process.stdout.write(JSON.stringify({ result: JSON.stringify(result) }));
`;
	fs.writeFileSync(path.join(bin, 'npx'), fakeNpx, { mode: 0o700 });
	try {
		const command = spawnSync(
			process.execPath,
			[
				capture,
				'--review', reviewPath,
				'--environment', 'docker',
				'--base-url', 'http://localhost:8080',
				'--session', 'akb-blog-seo-docker-capture',
				'--output', output,
			],
			{
				encoding: 'utf8',
				env: {
					...process.env,
					PATH: `${bin}${path.delimiter}${process.env.PATH}`,
				},
			}
		);
		assert.equal(command.status, 0, command.stderr);
		const source = JSON.parse(fs.readFileSync(output, 'utf8'));
		assert.equal(source.posts.length, 25);
		assert.equal(source.direct_writes, 0);
		assert.equal(
			source.capture_tool,
			'akibara-playwright-official-ui-v1'
		);
		assert.match(source.review_sha256, /^[a-f0-9]{64}$/u);
		assert.equal(fs.statSync(output).mode & 0o777, 0o600);

		const deferredKey =
			'que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile';
		const artifactPath = path.join(
			fixture,
			'seo_content_batch_b-24977-temporary-draft-evidence.json'
		);
		const deferredReviewPath = path.join(fixture, 'deferred-review.json');
		const deferredOutput = path.join(fixture, 'deferred-source.json');
		const evidence = {
			schema_version: 1,
			report_type:
				'akibara_blog_seo_temporary_docker_draft_official_ui_evidence',
			environment: 'docker',
			target_origin: 'http://localhost:8080',
			plan_sha256: 'c'.repeat(64),
			completed_at: '2026-07-26T12:00:00.000Z',
			result: {
				status: 'validated_and_cleaned',
				plan_sha256: 'c'.repeat(64),
				persisted_score: {
					score: 84,
					samples: [84, 84, 84, 84],
				},
				persisted: { slug: deferredKey },
				cleanup: {
					draft_permanently_deleted_by_ui: true,
					attachment_permanently_deleted_by_ui: true,
					remaining_route_violations: [],
				},
			},
			policy: {
				wordpress_and_rank_math_ui_only: true,
				draft_only: true,
				publish_requests: 0,
				production_writes: 0,
				cleanup_by_official_ui: true,
				cleanup_required_even_after_failure: true,
				remote_artifacts: 0,
			},
		};
		const evidenceRaw = `${JSON.stringify(evidence, null, 2)}\n`;
		fs.writeFileSync(artifactPath, evidenceRaw, { mode: 0o600 });
		const artifactSha256 = crypto.createHash('sha256')
			.update(evidenceRaw).digest('hex');
		fs.writeFileSync(
			deferredReviewPath,
			JSON.stringify({
				schema_version: 1,
				review_type: 'akibara_blog_seo_ui_capture_review',
				environment: 'docker',
				target_origin: 'http://localhost:8080',
				reviewed: true,
				posts: Array.from(
					{ length: 24 },
					(_, index) => reviewPost(index)
				),
				deferred_content_keys: [deferredKey],
				validation_artifact: artifactSha256,
			}),
			{ mode: 0o600 }
		);
		const deferredCommand = spawnSync(
			process.execPath,
			[
				capture,
				'--review', deferredReviewPath,
				'--validation-artifact', artifactPath,
				'--environment', 'docker',
				'--base-url', 'http://localhost:8080',
				'--session', 'akb-blog-seo-docker-capture',
				'--output', deferredOutput,
			],
			{
				encoding: 'utf8',
				env: {
					...process.env,
					PATH: `${bin}${path.delimiter}${process.env.PATH}`,
				},
			}
		);
		assert.equal(deferredCommand.status, 0, deferredCommand.stderr);
		const deferredSource = JSON.parse(
			fs.readFileSync(deferredOutput, 'utf8')
		);
		assert.equal(deferredSource.posts.length, 24);
		assert.deepEqual(deferredSource.deferred_content_keys, [deferredKey]);
		assert.equal(
			deferredSource.validation_artifact.artifact_sha256,
			artifactSha256
		);
		assert.equal(
			deferredSource.validation_artifact.stable_rank_math_score,
			84
		);

		const tamperedEvidence = structuredClone(evidence);
		tamperedEvidence.result.persisted_score.score = 81;
		const tamperedRaw = `${JSON.stringify(tamperedEvidence, null, 2)}\n`;
		fs.writeFileSync(artifactPath, tamperedRaw, { mode: 0o600 });
		const tamperedSha256 = crypto.createHash('sha256')
			.update(tamperedRaw).digest('hex');
		fs.writeFileSync(
			deferredReviewPath,
			JSON.stringify({
				schema_version: 1,
				review_type: 'akibara_blog_seo_ui_capture_review',
				environment: 'docker',
				target_origin: 'http://localhost:8080',
				reviewed: true,
				posts: Array.from(
					{ length: 24 },
					(_, index) => reviewPost(index)
				),
				deferred_content_keys: [deferredKey],
				validation_artifact: tamperedSha256,
			}),
			{ mode: 0o600 }
		);
		const tamperedOutput = path.join(fixture, 'tampered-source.json');
		const tamperedCommand = spawnSync(
			process.execPath,
			[
				capture,
				'--review', deferredReviewPath,
				'--validation-artifact', artifactPath,
				'--environment', 'docker',
				'--base-url', 'http://localhost:8080',
				'--session', 'akb-blog-seo-docker-capture',
				'--output', tamperedOutput,
			],
			{
				encoding: 'utf8',
				env: {
					...process.env,
					PATH: `${bin}${path.delimiter}${process.env.PATH}`,
				},
			}
		);
		assert.notEqual(tamperedCommand.status, 0);
		assert.match(tamperedCommand.stderr, /score and complete UI cleanup/u);
		assert.equal(fs.existsSync(tamperedOutput), false);
	} finally {
		fs.rmSync(fixture, { recursive: true, force: true });
	}
});

test('capture surfaces Playwright browser errors instead of undefined JSON', () => {
	const fixture = fs.mkdtempSync(
		path.join(root, '.local-reports/blog-seo-capture-error-test-')
	);
	const bin = path.join(fixture, 'bin');
	const reviewPath = path.join(fixture, 'review.json');
	const output = path.join(fixture, 'source.json');
	fs.mkdirSync(bin);
	fs.writeFileSync(
		reviewPath,
		JSON.stringify({
			schema_version: 1,
			review_type: 'akibara_blog_seo_ui_capture_review',
			environment: 'docker',
			target_origin: 'http://localhost:8080',
			reviewed: true,
			posts: Array.from({ length: 25 }, (_, index) => reviewPost(index)),
		}),
		{ mode: 0o600 }
	);
	fs.writeFileSync(
		path.join(bin, 'npx'),
		`#!/usr/bin/env node
process.stdout.write(JSON.stringify({
	isError: true,
	error: 'Error: RANK_MATH_TOGGLE_MISSING_OR_AMBIGUOUS',
}));
`,
		{ mode: 0o700 }
	);
	try {
		const command = spawnSync(
			process.execPath,
			[
				capture,
				'--review', reviewPath,
				'--environment', 'docker',
				'--base-url', 'http://localhost:8080',
				'--session', 'akb-blog-seo-docker-capture',
				'--output', output,
			],
			{
				encoding: 'utf8',
				env: {
					...process.env,
					PATH: `${bin}${path.delimiter}${process.env.PATH}`,
				},
			}
		);
		assert.notEqual(command.status, 0);
		assert.match(
			command.stderr,
			/Playwright browser function failed: Error: RANK_MATH_TOGGLE_MISSING_OR_AMBIGUOUS/u
		);
		assert.equal(fs.existsSync(output), false);
	} finally {
		fs.rmSync(fixture, { recursive: true, force: true });
	}
});
