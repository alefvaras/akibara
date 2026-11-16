#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import test from 'node:test';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	buildTemporaryDraftPlan,
	calculateTemporaryDraftPlanSha256,
	TEMPORARY_DRAFT_CONTRACT,
	validateTemporaryDraftPlan,
	sha256,
} from './lib/blog-seo-temporary-draft-official-ui.mjs';
import {
	assertTemporaryDraftBrowserFunctionSafety,
	buildTemporaryDraftPlanFromFiles,
	extractUnsafeOfficialUiStatus,
	PLAYWRIGHT_CLI_PACKAGE,
	readWebpDimensions,
	temporaryDraftBrowserFunction,
} from './run-blog-seo-temporary-draft-official-ui-playwright.mjs';

const opsDirectory = path.dirname(fileURLToPath(import.meta.url));
const repository = path.dirname(opsDirectory);
const runner = path.join(
	opsDirectory,
	'run-blog-seo-temporary-draft-official-ui-playwright.mjs'
);
const productionSourcePath = path.join(
	repository,
	'.local-reports',
	'seo-blog-score-audit',
	'production-source.json'
);
const desiredStatePath = path.join(
	repository,
	'.local-reports',
	'seo-blog-score-audit',
	'batch-b-desired.json'
);
const imagePath = path.join(
	repository,
	'output',
	'imagegen',
	'blog-seo-20260726',
	'preventa-manga-chile-akibara.webp'
);

function loadReviewedInputs() {
	const productionBytes = fs.readFileSync(productionSourcePath);
	const desiredBytes = fs.readFileSync(desiredStatePath);
	const imageBytes = fs.readFileSync(imagePath);
	const dimensions = readWebpDimensions(imageBytes);
	return {
		production: JSON.parse(productionBytes.toString('utf8')),
		desired: JSON.parse(desiredBytes.toString('utf8')),
		image: {
			relative_local_file:
				'output/imagegen/blog-seo-20260726/'
				+ 'preventa-manga-chile-akibara.webp',
			expected_filename: path.basename(imagePath),
			sha256: sha256(imageBytes),
			bytes: imageBytes.length,
			width: dimensions.width,
			height: dimensions.height,
		},
		productionSha256: sha256(productionBytes),
		desiredSha256: sha256(desiredBytes),
	};
}

function reviewedPlan() {
	const inputs = loadReviewedInputs();
	return buildTemporaryDraftPlan({
		productionSource: inputs.production,
		productionSourceSha256: inputs.productionSha256,
		desiredState: inputs.desired,
		desiredStateSha256: inputs.desiredSha256,
		image: inputs.image,
	});
}

function createFakeNpx(binDirectory) {
	const fake = `#!/usr/bin/env node
const fs = require('node:fs');
const args = process.argv.slice(2);
if (
	!args.includes('--package')
	|| !args.includes(${JSON.stringify(PLAYWRIGHT_CLI_PACKAGE)})
) process.exit(89);
const filenameArg = args.find(value => value.startsWith('--filename='));
if (!filenameArg) process.exit(90);
const functionPath = filenameArg.slice('--filename='.length);
const source = fs.readFileSync(functionPath, 'utf8');
const functionFileMode = fs.statSync(functionPath).mode & 0o777;
const match = /const plan = (\\{[\\s\\S]*\\});\\n\\t\\tconst absoluteImagePath/u
	.exec(source);
const imageMatch = /const absoluteImagePath = ("[^"]+");/u.exec(source);
const modeMatch =
	/const mode = ("(?:preflight|allocate|full|recover|recover-intent)");/u
		.exec(source);
const allocatedMatch =
	/const allocatedLocalPostId = (null|[0-9]+);/u.exec(source);
const uploadFilenameMatch =
	/const runtimeUploadFilename = ("[^"]+");/u.exec(source);
const nonceMatch = /const runNonce = ("[a-f0-9]{24}");/u.exec(source);
if (
	!match
	|| !imageMatch
	|| !modeMatch
	|| !allocatedMatch
	|| !uploadFilenameMatch
	|| !nonceMatch
) process.exit(91);
const plan = JSON.parse(match[1]);
const runtimeImage = JSON.parse(imageMatch[1]);
const browserMode = JSON.parse(modeMatch[1]);
const allocatedLocalPostId = JSON.parse(allocatedMatch[1]);
const runtimeUploadFilename = JSON.parse(uploadFilenameMatch[1]);
const runNonce = JSON.parse(nonceMatch[1]);
const crypto = require('node:crypto');
if (
	!source.includes('AKB_BLOG_SEO_TEMPORARY_DRAFT_OFFICIAL_UI')
	|| !source.includes("await page.route('**/*'")
	|| !source.includes('cleanupPostByUi')
	|| !source.includes('cleanupMediaByUi')
	|| source.includes('fetch(')
) process.exit(92);
fs.appendFileSync(
	process.env.AKB_TEMP_DRAFT_FAKE_LOG,
	JSON.stringify({
		functionPath,
		runtimeDirectory: process.cwd(),
		runtimeImage,
		imageMode: fs.statSync(runtimeImage).mode & 0o777,
		imageSha256: crypto.createHash('sha256')
			.update(fs.readFileSync(runtimeImage)).digest('hex'),
		playwrightPackage: ${JSON.stringify(PLAYWRIGHT_CLI_PACKAGE)},
		functionFileMode,
		browserMode,
		allocatedLocalPostId,
		runtimeUploadFilename,
		runNonce,
		plan_sha256: plan.plan_sha256,
	}) + '\\n',
	{ mode: 0o600 }
);
if (
	process.env.AKB_TEMP_DRAFT_FAKE_FAIL_FULL === '1'
	&& browserMode === 'full'
) {
	process.stderr.write('simulated main browser failure');
	process.exit(73);
}
if (
	process.env.AKB_TEMP_DRAFT_FAKE_FAIL_ALLOCATE === '1'
	&& browserMode === 'allocate'
) {
	process.stderr.write('simulated allocation browser failure');
	process.exit(74);
}
if (browserMode === 'preflight') {
	process.stdout.write(JSON.stringify({
		result: JSON.stringify({
			status: 'preflight_complete',
			plan_sha256: plan.plan_sha256,
			auto_draft_ids: [120, 240],
		}),
	}));
	process.exit(0);
}
if (browserMode === 'allocate') {
	process.stdout.write(JSON.stringify({
		result: JSON.stringify({
			status: 'allocated_draft',
			plan_sha256: plan.plan_sha256,
			local_post_id: 35001,
			post_status: 'draft',
		}),
	}));
	process.exit(0);
}
if (browserMode === 'recover-intent') {
	process.stdout.write(JSON.stringify({
		result: JSON.stringify({
			status: 'intent_recovery_verified',
			plan_sha256: plan.plan_sha256,
			post_cleanup: { status: 'already_absent' },
			media_cleanup: { status: 'already_absent' },
			remaining_route_violations: [],
		}),
	}));
	process.exit(0);
}
if (browserMode === 'recover') {
	process.stdout.write(JSON.stringify({
		result: JSON.stringify({
			status: 'recovery_verified',
			plan_sha256: plan.plan_sha256,
			local_post_id: allocatedLocalPostId,
			post_cleanup: { status: 'already_absent' },
			media_cleanup: { status: 'already_absent' },
			remaining_route_violations: [],
		}),
	}));
	process.exit(0);
}
const result = {
	status: 'validated_and_cleaned',
	plan_sha256: plan.plan_sha256,
	local_post_id: 35001,
	uploaded_attachment_id: 45001,
	forbidden_production_attachment_id:
		plan.source.forbidden_production_attachment_id,
	baseline_score: { score: 18, samples: [18, 18, 18, 18] },
	persisted_score: { score: 84, samples: [84, 84, 84, 84] },
	persisted: {
		slug: plan.desired.slug,
		post_status: 'draft',
		author: {
			id: plan.author.expected_user_id,
			display: plan.author.expected_display_name,
		},
		featured_image: {
			attachment_id: 45001,
			filename: runtimeUploadFilename,
			alt: plan.desired.featured_image_alt,
		},
	},
	cleanup: {
		draft_permanently_deleted_by_ui: true,
		attachment_permanently_deleted_by_ui: true,
		remaining_route_violations: [],
	},
};
process.stdout.write(JSON.stringify({ result: JSON.stringify(result) }));
`;
	const file = path.join(binDirectory, 'npx');
	fs.writeFileSync(file, fake, { mode: 0o700 });
}

function runnerArgs(mode, extra = []) {
	return [
		runner,
		mode,
		'--production-source',
		productionSourcePath,
		'--desired',
		desiredStatePath,
		'--image',
		imagePath,
		'--base-url',
		TEMPORARY_DRAFT_CONTRACT.targetOrigin,
		...extra,
	];
}

test('reviewed plan is deterministic, exact and tamper evident', () => {
	const first = reviewedPlan();
	const second = reviewedPlan();
	assert.equal(first.plan_sha256, second.plan_sha256);
	assert.equal(
		first.plan_sha256,
		calculateTemporaryDraftPlanSha256(first)
	);
	assert.equal(
		first.source.production_post_id,
		TEMPORARY_DRAFT_CONTRACT.productionPostId
	);
	assert.equal(
		first.source.forbidden_production_attachment_id,
		TEMPORARY_DRAFT_CONTRACT.forbiddenProductionAttachmentId
	);
	assert.equal(
		first.desired.minimum_rank_math_score,
		TEMPORARY_DRAFT_CONTRACT.minimumRankMathScore
	);
	assert.equal(
		first.image.sha256,
		TEMPORARY_DRAFT_CONTRACT.expectedImageSha256
	);
	const tampered = structuredClone(first);
	tampered.desired.post_title += ' alterado';
	assert.throws(
		() => validateTemporaryDraftPlan(tampered),
		/plan hash mismatch/u
	);
});

test('source identity and reviewed image hash fail closed', () => {
	const inputs = loadReviewedInputs();
	const wrongAttachment = structuredClone(inputs.production);
	wrongAttachment.posts.find(({ id }) => id === 24977).thumbnail_id = 25000;
	assert.throws(
		() => buildTemporaryDraftPlan({
			productionSource: wrongAttachment,
			productionSourceSha256: inputs.productionSha256,
			desiredState: inputs.desired,
			desiredStateSha256: inputs.desiredSha256,
			image: inputs.image,
		}),
		/thumbnail/u
	);
	const wrongImage = {
		...inputs.image,
		sha256: '0'.repeat(64),
	};
	assert.throws(
		() => buildTemporaryDraftPlan({
			productionSource: inputs.production,
			productionSourceSha256: inputs.productionSha256,
			desiredState: inputs.desired,
			desiredStateSha256: inputs.desiredSha256,
			image: wrongImage,
		}),
		/replacement image/u
	);
});

test('WEBP parser confirms the reviewed image and rejects invalid bytes', () => {
	const dimensions = readWebpDimensions(fs.readFileSync(imagePath));
	assert.deepEqual(dimensions, { width: 1280, height: 720 });
	assert.throws(
		() => readWebpDimensions(Buffer.from('not a webp')),
		/valid RIFF WEBP/u
	);
});

test('route status allowlist rejects every non-draft post state and encoding', () => {
	for (const status of ['publish', 'future', 'private', 'pending']) {
		assert.equal(
			extractUnsafeOfficialUiStatus(
				JSON.stringify({ status })
			),
			status
		);
		assert.equal(
			extractUnsafeOfficialUiStatus(`post_status=${status}`),
			status
		);
		assert.equal(
			extractUnsafeOfficialUiStatus(
				encodeURIComponent(`status=${status}`)
			),
			status
		);
		assert.equal(
			extractUnsafeOfficialUiStatus(`?status=${status}`),
			status
		);
	}
	for (const status of ['draft', 'auto-draft', 'trash', 'inherit']) {
		assert.equal(
			extractUnsafeOfficialUiStatus(
				JSON.stringify({ status })
			),
			null
		);
	}
	assert.equal(
		extractUnsafeOfficialUiStatus(
			'{"\\u0073tatus":"publish"}',
			'application/json'
		),
		'publish'
	);
	assert.equal(
		extractUnsafeOfficialUiStatus(
			'{"payload":{"post_status":"future"}}',
			'application/merge-patch+json'
		),
		'future'
	);
	assert.equal(
		extractUnsafeOfficialUiStatus(
			'post%5Bstatus%5D=private',
			'application/x-www-form-urlencoded'
		),
		'private'
	);
	assert.equal(
		extractUnsafeOfficialUiStatus(
			'{broken',
			'application/json'
		),
		'unparseable-json'
	);
	assert.equal(extractUnsafeOfficialUiStatus('unrelated=1'), null);
});

test('generated function is draft-only, local-only and cleanup-complete', () => {
	const plan = reviewedPlan();
	const source = temporaryDraftBrowserFunction({
		baseUrl: TEMPORARY_DRAFT_CONTRACT.targetOrigin,
		plan,
		absoluteImagePath: imagePath,
	});
	assert.equal(
		assertTemporaryDraftBrowserFunctionSafety(source),
		source
	);
	assert.doesNotThrow(() => Function(`return (${source});`));
	assert.doesNotMatch(
		source,
		/fetch\s*\(|page\.request\b|admin-ajax\.php|update_post_meta|\$wpdb/iu
	);
	assert.doesNotMatch(
		source,
		/\.editor-post-publish-button[^;]{0,300}\.click\s*\(/iu
	);
	assert.match(source, /requestUrl\.origin !== baseUrl \|\| unsafeStatus/u);
	assert.match(source, /button\.editor-post-save-draft:visible/u);
	assert.match(source, /button\.editor-post-saved-state\.is-saved/u);
	assert.match(source, /NEW_EDITOR_AUTO_DRAFT_IDENTITY_MISSING/u);
	assert.match(source, /POST_STATUS_REMAINED_AUTO_DRAFT/u);
	assert.match(source, /editor-post-author__panel-toggle:visible/u);
	assert.match(source, /\.post-author-selector select:visible/u);
	assert.match(source, /stableSamples >= 4/u);
	assert.match(source, /finally \{/u);
	assert.match(source, /cleanupPostByUi/u);
	assert.match(source, /cleanupMediaByUi/u);
	assert.match(source, /postIdsWithExactSlug\('trash'\)/u);
	assert.match(source, /POST_LIST_PAGINATION_LIMIT/u);
	assert.match(source, /CLEANUP_POST_STILL_PRESENT/u);
	assert.match(source, /CLEANUP_MEDIA_STILL_PRESENT/u);
	assert.match(
		source,
		new RegExp(TEMPORARY_DRAFT_CONTRACT.expectedImageFilename, 'u')
	);
});

test('validate-only performs zero browser calls', () => {
	const fixture = fs.mkdtempSync(
		path.join(repository, '.local-reports/temp-draft-validate-test-')
	);
	const bin = path.join(fixture, 'bin');
	const log = path.join(fixture, 'calls.jsonl');
	fs.mkdirSync(bin);
	createFakeNpx(bin);
	try {
		const result = spawnSync(
			process.execPath,
			runnerArgs('--validate-only'),
			{
				encoding: 'utf8',
				env: {
					...process.env,
					PATH: `${bin}${path.delimiter}${process.env.PATH}`,
					AKB_TEMP_DRAFT_TEST_MODE: '1',
					AKB_TEMP_DRAFT_TEST_NPX_CLI: path.join(bin, 'npx'),
					AKB_TEMP_DRAFT_FAKE_LOG: log,
				},
			}
		);
		assert.equal(result.status, 0, result.stderr);
		const output = JSON.parse(result.stdout);
		assert.equal(output.result, 'PASS');
		assert.equal(output.mode, 'validate-only');
		assert.equal(output.browser_writes_executed, 0);
		assert.equal(output.plan_sha256, reviewedPlan().plan_sha256);
		assert.equal(fs.existsSync(log), false);
	} finally {
		fs.rmSync(fixture, { recursive: true, force: true });
	}
});

test('apply requires exact hash before npx and writes 0600 local evidence', () => {
	const fixture = fs.mkdtempSync(
		path.join(repository, '.local-reports/temp-draft-apply-test-')
	);
	const bin = path.join(fixture, 'bin');
	const log = path.join(fixture, 'calls.jsonl');
	const report = path.join(fixture, 'evidence.json');
	fs.mkdirSync(bin);
	createFakeNpx(bin);
	const plan = buildTemporaryDraftPlanFromFiles({
		productionSourcePath,
		desiredStatePath,
		imagePath,
		repository,
	}).plan;
	const invoke = (extra, env = {}) => spawnSync(
		process.execPath,
		runnerArgs('--apply', [
			'--session',
			'akb-seo-temporary-draft-docker',
			'--report',
			report,
			...extra,
		]),
		{
			encoding: 'utf8',
			env: {
				...process.env,
				...env,
				PATH: `${bin}${path.delimiter}${process.env.PATH}`,
				AKB_TEMP_DRAFT_TEST_MODE: '1',
				AKB_TEMP_DRAFT_TEST_NPX_CLI: path.join(bin, 'npx'),
				AKB_TEMP_DRAFT_FAKE_LOG: log,
			},
		}
	);
	try {
		const missingHash = invoke([]);
		assert.notEqual(missingHash.status, 0);
		assert.match(missingHash.stderr, /confirm-plan-sha256/u);
		assert.equal(fs.existsSync(log), false);
		assert.equal(fs.existsSync(report), false);

		const applied = invoke([
			'--confirm-plan-sha256',
			plan.plan_sha256,
		]);
		assert.equal(applied.status, 0, applied.stderr);
		const output = JSON.parse(applied.stdout);
		assert.equal(output.result, 'PASS');
		assert.equal(output.score, 84);
		assert.equal(
			output.cleanup.draft_permanently_deleted_by_ui,
			true
		);
		assert.equal(
			output.cleanup.attachment_permanently_deleted_by_ui,
			true
		);
		assert.equal(fs.statSync(report).mode & 0o777, 0o600);
		const evidence = JSON.parse(fs.readFileSync(report, 'utf8'));
		assert.equal(evidence.environment, 'docker');
		assert.equal(evidence.plan_sha256, plan.plan_sha256);
		const calls = fs.readFileSync(log, 'utf8')
			.trim().split('\n').map((line) => JSON.parse(line));
		assert.equal(calls.length, 4);
		assert.deepEqual(
			calls.map(({ browserMode }) => browserMode),
			['preflight', 'allocate', 'full', 'recover']
		);
		assert.equal(
			new Set(calls.map(({ runNonce }) => runNonce)).size,
			1
		);
		assert.ok(calls.every(({ runtimeUploadFilename, runNonce }) => (
			runtimeUploadFilename
				=== `preventa-manga-chile-akibara--akb-${runNonce}.webp`
		)));
		assert.ok(calls.every(({ functionFileMode }) => (
			functionFileMode === 0o600
		)));
		assert.ok(calls.every(({ plan_sha256: hash }) => (
			hash === plan.plan_sha256
		)));
		assert.ok(calls.every(({ playwrightPackage }) => (
			playwrightPackage === PLAYWRIGHT_CLI_PACKAGE
		)));
		assert.ok(calls.every(({ imageMode }) => imageMode === 0o400));
		assert.equal(
			new Set(calls.map(({ imageSha256 }) => imageSha256)).size,
			1
		);
		assert.equal(
			calls[0].imageSha256,
			TEMPORARY_DRAFT_CONTRACT.expectedImageSha256
		);
		assert.ok(calls.every(({ functionPath }) => (
			!fs.existsSync(functionPath)
		)));
		assert.ok(calls.every(({ runtimeImage }) => (
			!fs.existsSync(runtimeImage)
		)));
		assert.ok(calls.every(({ runtimeDirectory }) => (
			!fs.existsSync(runtimeDirectory)
		)));
		assert.equal(fs.existsSync(`${report}.recovery.json`), false);
	} finally {
		fs.rmSync(fixture, { recursive: true, force: true });
	}
});

test('wrong origin and browser failure leave no evidence report', () => {
	const fixture = fs.mkdtempSync(
		path.join(repository, '.local-reports/temp-draft-failure-test-')
	);
	const bin = path.join(fixture, 'bin');
	const log = path.join(fixture, 'calls.jsonl');
	const report = path.join(fixture, 'evidence.json');
	fs.mkdirSync(bin);
	createFakeNpx(bin);
	const plan = reviewedPlan();
	try {
		const wrongOriginArgs = [
			runner,
			'--apply',
			'--production-source',
			productionSourcePath,
			'--desired',
			desiredStatePath,
			'--image',
			imagePath,
			'--base-url',
			'https://akibara.cl',
			'--session',
			'akb-seo-temporary-draft-docker',
			'--report',
			report,
			'--confirm-plan-sha256',
			plan.plan_sha256,
		];
		const wrongOrigin = spawnSync(process.execPath, wrongOriginArgs, {
			encoding: 'utf8',
			env: {
				...process.env,
				PATH: `${bin}${path.delimiter}${process.env.PATH}`,
				AKB_TEMP_DRAFT_TEST_MODE: '1',
				AKB_TEMP_DRAFT_TEST_NPX_CLI: path.join(bin, 'npx'),
				AKB_TEMP_DRAFT_FAKE_LOG: log,
			},
		});
		assert.notEqual(wrongOrigin.status, 0);
		assert.match(wrongOrigin.stderr, /must be exactly/u);
		assert.equal(fs.existsSync(log), false);

		const browserFailure = spawnSync(
			process.execPath,
			runnerArgs('--apply', [
				'--session',
				'akb-seo-temporary-draft-docker',
				'--report',
				report,
				'--confirm-plan-sha256',
				plan.plan_sha256,
			]),
			{
				encoding: 'utf8',
				env: {
					...process.env,
					PATH: `${bin}${path.delimiter}${process.env.PATH}`,
					AKB_TEMP_DRAFT_TEST_MODE: '1',
					AKB_TEMP_DRAFT_TEST_NPX_CLI: path.join(bin, 'npx'),
					AKB_TEMP_DRAFT_FAKE_LOG: log,
					AKB_TEMP_DRAFT_FAKE_FAIL_FULL: '1',
				},
			}
		);
		assert.notEqual(browserFailure.status, 0);
		assert.match(browserFailure.stderr, /simulated main browser failure/u);
		assert.equal(fs.existsSync(report), false);
		assert.equal(fs.existsSync(`${report}.recovery.json`), false);
		const failureCalls = fs.readFileSync(log, 'utf8')
			.trim().split('\n').map((line) => JSON.parse(line));
		assert.deepEqual(
			failureCalls.map(({ browserMode }) => browserMode),
			['preflight', 'allocate', 'full', 'recover']
		);
	} finally {
		fs.rmSync(fixture, { recursive: true, force: true });
	}
});

test('allocation and journal phase failures recover without residue', () => {
	const fixture = fs.mkdtempSync(
		path.join(repository, '.local-reports/temp-draft-allocation-test-')
	);
	const bin = path.join(fixture, 'bin');
	const log = path.join(fixture, 'calls.jsonl');
	const report = path.join(fixture, 'evidence.json');
	fs.mkdirSync(bin);
	createFakeNpx(bin);
	const plan = reviewedPlan();
	const invoke = (env) => spawnSync(
		process.execPath,
		runnerArgs('--apply', [
			'--session',
			'akb-seo-temporary-draft-docker',
			'--report',
			report,
			'--confirm-plan-sha256',
			plan.plan_sha256,
		]),
		{
			encoding: 'utf8',
			env: {
				...process.env,
				...env,
				AKB_TEMP_DRAFT_TEST_MODE: '1',
				AKB_TEMP_DRAFT_TEST_NPX_CLI: path.join(bin, 'npx'),
				AKB_TEMP_DRAFT_FAKE_LOG: log,
			},
		}
	);
	const modes = () => fs.readFileSync(log, 'utf8')
		.trim().split('\n').map((line) => JSON.parse(line).browserMode);
	try {
		const intentWriteFailure = invoke({
			AKB_TEMP_DRAFT_TEST_FAIL_INTENT_WRITE: '1',
		});
		assert.notEqual(intentWriteFailure.status, 0);
		assert.match(intentWriteFailure.stderr, /intent journal write/u);
		assert.deepEqual(modes(), ['preflight']);
		assert.equal(fs.existsSync(`${report}.recovery.json`), false);
		assert.equal(fs.existsSync(report), false);
		fs.rmSync(log);

		const allocationFailure = invoke({
			AKB_TEMP_DRAFT_FAKE_FAIL_ALLOCATE: '1',
		});
		assert.notEqual(allocationFailure.status, 0);
		assert.match(allocationFailure.stderr, /simulated allocation/u);
		assert.deepEqual(
			modes(),
			['preflight', 'allocate', 'recover-intent']
		);
		assert.equal(fs.existsSync(`${report}.recovery.json`), false);
		assert.equal(fs.existsSync(report), false);
		fs.rmSync(log);

		const promotionFailure = invoke({
			AKB_TEMP_DRAFT_TEST_FAIL_JOURNAL_PROMOTION: '1',
		});
		assert.notEqual(promotionFailure.status, 0);
		assert.match(promotionFailure.stderr, /phase-change failure/u);
		assert.deepEqual(modes(), ['preflight', 'allocate', 'recover']);
		assert.equal(fs.existsSync(`${report}.recovery.json`), false);
		assert.equal(fs.existsSync(report), false);
	} finally {
		fs.rmSync(fixture, { recursive: true, force: true });
	}
});

test('a valid 0600 journal is recovered before a new allocation', () => {
	const fixture = fs.mkdtempSync(
		path.join(repository, '.local-reports/temp-draft-journal-test-')
	);
	const bin = path.join(fixture, 'bin');
	const log = path.join(fixture, 'calls.jsonl');
	const report = path.join(fixture, 'evidence.json');
	const journal = `${report}.recovery.json`;
	const session = 'akb-seo-temporary-draft-docker';
	fs.mkdirSync(bin);
	createFakeNpx(bin);
	const plan = reviewedPlan();
	const staleRunNonce = 'a'.repeat(24);
	fs.writeFileSync(journal, `${JSON.stringify({
		schema_version: 2,
		journal_type: 'akibara_blog_seo_temporary_draft_recovery',
		phase: 'allocated',
		plan_sha256: plan.plan_sha256,
		target_origin: TEMPORARY_DRAFT_CONTRACT.targetOrigin,
		session,
		run_nonce: staleRunNonce,
		runtime_upload_filename:
			`preventa-manga-chile-akibara--akb-${staleRunNonce}.webp`,
		allocation_started_at: new Date().toISOString(),
		preexisting_auto_draft_ids: [120, 240],
		local_post_id: 36002,
	}, null, 2)}\n`, { mode: 0o600 });
	try {
		const result = spawnSync(
			process.execPath,
			runnerArgs('--apply', [
				'--session',
				session,
				'--report',
				report,
				'--confirm-plan-sha256',
				plan.plan_sha256,
			]),
			{
				encoding: 'utf8',
				env: {
					...process.env,
					AKB_TEMP_DRAFT_TEST_MODE: '1',
					AKB_TEMP_DRAFT_TEST_NPX_CLI: path.join(bin, 'npx'),
					AKB_TEMP_DRAFT_FAKE_LOG: log,
				},
			}
		);
		assert.equal(result.status, 0, result.stderr);
		assert.equal(fs.existsSync(journal), false);
		const calls = fs.readFileSync(log, 'utf8')
			.trim().split('\n').map((line) => JSON.parse(line));
		assert.deepEqual(
			calls.map(({ browserMode }) => browserMode),
			['recover', 'preflight', 'allocate', 'full', 'recover']
		);
		assert.equal(calls[0].allocatedLocalPostId, 36002);
		assert.equal(calls[1].allocatedLocalPostId, null);
		assert.equal(calls[2].allocatedLocalPostId, null);
		assert.equal(calls[3].allocatedLocalPostId, 35001);
	} finally {
		fs.rmSync(fixture, { recursive: true, force: true });
	}
});

test('an interrupted allocating journal is recovered by intent first', () => {
	const fixture = fs.mkdtempSync(
		path.join(repository, '.local-reports/temp-draft-intent-test-')
	);
	const bin = path.join(fixture, 'bin');
	const log = path.join(fixture, 'calls.jsonl');
	const report = path.join(fixture, 'evidence.json');
	const journal = `${report}.recovery.json`;
	const session = 'akb-seo-temporary-draft-docker';
	const runNonce = 'b'.repeat(24);
	fs.mkdirSync(bin);
	createFakeNpx(bin);
	const plan = reviewedPlan();
	fs.writeFileSync(journal, `${JSON.stringify({
		schema_version: 2,
		journal_type: 'akibara_blog_seo_temporary_draft_recovery',
		phase: 'allocating',
		plan_sha256: plan.plan_sha256,
		target_origin: TEMPORARY_DRAFT_CONTRACT.targetOrigin,
		session,
		run_nonce: runNonce,
		runtime_upload_filename:
			`preventa-manga-chile-akibara--akb-${runNonce}.webp`,
		allocation_started_at: new Date().toISOString(),
		preexisting_auto_draft_ids: [120, 240],
		local_post_id: null,
	}, null, 2)}\n`, { mode: 0o600 });
	try {
		const result = spawnSync(
			process.execPath,
			runnerArgs('--apply', [
				'--session',
				session,
				'--report',
				report,
				'--confirm-plan-sha256',
				plan.plan_sha256,
			]),
			{
				encoding: 'utf8',
				env: {
					...process.env,
					AKB_TEMP_DRAFT_TEST_MODE: '1',
					AKB_TEMP_DRAFT_TEST_NPX_CLI: path.join(bin, 'npx'),
					AKB_TEMP_DRAFT_FAKE_LOG: log,
				},
			}
		);
		assert.equal(result.status, 0, result.stderr);
		assert.equal(fs.existsSync(journal), false);
		const calls = fs.readFileSync(log, 'utf8')
			.trim().split('\n').map((line) => JSON.parse(line));
		assert.deepEqual(
			calls.map(({ browserMode }) => browserMode),
			['recover-intent', 'preflight', 'allocate', 'full', 'recover']
		);
		assert.equal(calls[0].allocatedLocalPostId, null);
		assert.equal(calls[0].runNonce, runNonce);
	} finally {
		fs.rmSync(fixture, { recursive: true, force: true });
	}
});

test('image path may not traverse a symlink or reuse WordPress uploads', () => {
	const fixture = fs.mkdtempSync(
		path.join(repository, '.local-reports/temp-draft-image-test-')
	);
	const symlink = path.join(
		fixture,
		'preventa-manga-chile-akibara.webp'
	);
	fs.symlinkSync(imagePath, symlink);
	try {
		assert.throws(
			() => buildTemporaryDraftPlanFromFiles({
				productionSourcePath,
				desiredStatePath,
				imagePath: symlink,
				repository,
			}),
			/symbolic links/u
		);
		assert.throws(
			() => buildTemporaryDraftPlanFromFiles({
				productionSourcePath,
				desiredStatePath,
				imagePath: path.join(
					repository,
					'public_html',
					'wp-content',
					'uploads',
					'2026',
					'05',
					'24977_new.jpg'
				),
				repository,
			}),
			/WordPress uploads|filename|WEBP/u
		);
	} finally {
		fs.rmSync(fixture, { recursive: true, force: true });
	}
});
