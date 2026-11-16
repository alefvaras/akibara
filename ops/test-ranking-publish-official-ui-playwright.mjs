#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	rankingPublishSha256,
	validateRankingPublishManifest,
} from './lib/ranking-publish-official-ui-manifest.mjs';
import {
	assertRankingPublishBrowserFunctionSafety,
	rankingPublishBrowserFunction,
	RANKING_PUBLISH_PLAYWRIGHT_PACKAGE,
} from './run-ranking-publish-official-ui-playwright.mjs';

const opsDirectory = path.dirname(fileURLToPath(import.meta.url));
const repository = path.dirname(opsDirectory);
const runner = path.join(
	opsDirectory,
	'run-ranking-publish-official-ui-playwright.mjs',
);
const generator = path.join(
	opsDirectory,
	'generate-ranking-publish-official-ui-manifest.mjs',
);
const auditedDirectory = path.join(
	repository,
	'.local-reports',
	'blog-audit-20260818',
);
const auditedManifestPath = path.join(
	auditedDirectory,
	'ranking-publish-official-ui-manifest.json',
);
const productionSnapshotPath = path.join(
	auditedDirectory,
	'production-snapshot.json',
);
const productionStructurePath = path.join(
	auditedDirectory,
	'production-structure.json',
);
const testRoot = path.join(
	repository,
	'.local-reports',
	`.ranking-publish-tests-${process.pid}-${Date.now()}`,
);
const binDirectory = path.join(testRoot, 'bin');
const logPath = path.join(testRoot, 'fake-npx.jsonl');
const manifestPath = path.join(testRoot, 'manifest.json');
const checkpointPath = path.join(testRoot, 'checkpoint.json');

fs.mkdirSync(binDirectory, { recursive: true, mode: 0o700 });
fs.copyFileSync(auditedManifestPath, manifestPath);
fs.copyFileSync(`${auditedManifestPath}.sha256`, `${manifestPath}.sha256`);
fs.chmodSync(manifestPath, 0o600);
fs.chmodSync(`${manifestPath}.sha256`, 0o600);
const manifest = validateRankingPublishManifest(
	JSON.parse(fs.readFileSync(manifestPath, 'utf8')),
);
fs.writeFileSync(
	`${manifestPath}.sha256`,
	`${rankingPublishSha256(fs.readFileSync(manifestPath))}  ${
		path.basename(manifestPath)
	}\n`,
	{ mode: 0o600 },
);

const fakeNpx = `#!/usr/bin/env node
const crypto = require('node:crypto');
const fs = require('node:fs');
const args = process.argv.slice(2);
if (
	!args.includes('--package')
	|| !args.includes(${JSON.stringify(RANKING_PUBLISH_PLAYWRIGHT_PACKAGE)})
	|| !args.includes('playwright-cli')
	|| !args.includes('--json')
	|| !args.includes('run-code')
) process.exit(88);
const filenameArgument = args.find(value => value.startsWith('--filename='));
if (!filenameArgument) process.exit(89);
const functionPath = filenameArgument.slice('--filename='.length);
const source = fs.readFileSync(functionPath, 'utf8');
const mode = fs.statSync(functionPath).mode & 0o777;
const manifestMatch = /const manifest = (\\{[\\s\\S]*?\\});\\n\\t\\tconst phase/u.exec(source);
const phaseMatch = /const phase = ("(?:preflight|apply)");/u.exec(source);
const fingerprintMatch = /const expectedFingerprintSha256 = (null|"[a-f0-9]{64}");/u.exec(source);
if (!manifestMatch || !phaseMatch || !fingerprintMatch) process.exit(90);
const manifest = JSON.parse(manifestMatch[1]);
const phase = JSON.parse(phaseMatch[1]);
const expectedFingerprint = JSON.parse(fingerprintMatch[1]);
const unsafe = /fetch\\s*\\(|page\\.request|request\\.(?:post|put|patch|delete)\\s*\\(|setInputFiles\\s*\\(|\\.fill\\s*\\(/iu.test(source);
if (
	mode !== 0o600
	|| unsafe
	|| !source.includes('AKB_RANKING_PUBLISH_OFFICIAL_UI')
	|| !source.includes("await page.route('**/*', guard)")
	|| !source.includes('PROTECTED_FIELDS_CHANGED_AFTER_PUBLISH')
) process.exit(91);
fs.appendFileSync(process.env.AKB_RANKING_FAKE_LOG, JSON.stringify({
	args,
	functionPath,
	mode,
	runtimeDirectory: process.cwd(),
	phase,
	expectedFingerprint,
}) + '\\n', { mode: 0o600 });
if (process.env.AKB_RANKING_FAKE_MODE === 'cli-fail') {
	process.stderr.write('simulated Playwright failure');
	process.exit(72);
}
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const fields = {
	featured_image: hash('featured_image'),
	post_content: hash('post_content'),
	post_excerpt: hash('post_excerpt'),
	post_title: hash('post_title'),
	rank_math_description: hash('rank_math_description'),
	rank_math_focus_keyword: hash('rank_math_focus_keyword'),
	rank_math_title: hash('rank_math_title'),
};
const fingerprint = phase === 'apply'
	? expectedFingerprint
	: 'a'.repeat(64);
let result;
if (process.env.AKB_RANKING_FAKE_MODE === 'blocked') {
	result = {
		status: 'blocked',
		phase,
		error_code: 'SIMULATED_BLOCK',
		post_id: manifest.post.post_id,
		editorial_fingerprint_sha256: fingerprint,
		mutation_requests: [],
		route_violations: ['SIMULATED_BLOCK'],
		publish_response_status: null,
	};
} else if (phase === 'preflight') {
	result = {
		status: 'preflight_ready',
		post_id: manifest.post.post_id,
		slug: manifest.post.slug,
		post_status: 'draft',
		editorial_fingerprint_sha256: fingerprint,
		protected_field_sha256: fields,
		route_mutation_requests: 0,
	};
} else {
	result = {
		status: 'published_verified',
		post_id: manifest.post.post_id,
		slug: manifest.post.slug,
		before_status: 'draft',
		after_status: 'publish',
		editorial_fingerprint_sha256: fingerprint,
		protected_field_sha256: fields,
		protected_fields_unchanged: true,
		confirmation_clicked: true,
		mutation_requests: [{
			method: 'POST',
			path: '/wp-json/wp/v2/posts/' + manifest.post.post_id,
			status: 'publish',
			body_sha256: hash('publish-body'),
		}],
		publish_response_status: 200,
		public_verification: {
			url: manifest.post.public_url,
			http_status: 200,
			canonical_url: manifest.post.public_url,
			post_id_body_class: true,
		},
	};
}
process.stdout.write(JSON.stringify({ result: JSON.stringify(result) }));
`;
const fakeNpxPath = path.join(binDirectory, 'npx');
fs.writeFileSync(fakeNpxPath, fakeNpx, { mode: 0o700 });

function invoke(extra, {
	mode = 'pass',
	manifestFile = manifestPath,
	checkpointFile = checkpointPath,
} = {}) {
	return spawnSync(
		process.execPath,
		[
			runner,
			'--manifest', manifestFile,
			...extra.map((value) => (
				value === '__CHECKPOINT__' ? checkpointFile : value
			)),
		],
		{
			encoding: 'utf8',
			env: {
				...process.env,
				PATH: `${binDirectory}${path.delimiter}${process.env.PATH}`,
				AKB_RANKING_FAKE_LOG: logPath,
				AKB_RANKING_FAKE_MODE: mode,
			},
		},
	);
}

function operationalArgs(extra = []) {
	return [
		'--base-url', 'https://akibara.cl',
		'--session', 'akb-ranking-production',
		'--checkpoint', '__CHECKPOINT__',
		...extra,
	];
}

function calls() {
	if (!fs.existsSync(logPath)) return [];
	return fs.readFileSync(logPath, 'utf8').trim().split('\n')
		.filter(Boolean).map((line) => JSON.parse(line));
}

function assertLatestFunctionCleaned() {
	const latest = calls().at(-1);
	assert.ok(latest);
	assert.equal(latest.mode, 0o600);
	assert.equal(fs.existsSync(latest.functionPath), false);
	assert.equal(fs.existsSync(latest.runtimeDirectory), false);
}

try {
	const preflightSource = rankingPublishBrowserFunction({
		baseUrl: manifest.target_origin,
		manifest,
		phase: 'preflight',
	});
	assert.doesNotThrow(
		() => assertRankingPublishBrowserFunctionSafety(preflightSource),
	);
	assert.equal(/\.fill\s*\(/u.test(preflightSource), false);
	assert.equal(/setInputFiles\s*\(/u.test(preflightSource), false);
	assert.equal(/fetch\s*\(/u.test(preflightSource), false);
	assert.match(preflightSource, /page\.route\('\*\*\/\*', guard\)/u);
	assert.match(preflightSource, /PUBLISH_PAYLOAD_PROTECTED_MISMATCH/u);
	assert.match(preflightSource, /iframe\[name="editor-canvas"\]/u);
	assert.match(preflightSource, /canvas\.contentFrame\(\)/u);

	const callsBeforeValidate = calls().length;
	const validate = invoke(['--validate-only']);
	assert.equal(validate.status, 0, validate.stderr);
	assert.match(validate.stdout, /"mode": "validate-only"/u);
	assert.equal(calls().length, callsBeforeValidate);

	const generatedPath = path.join(testRoot, 'generated.json');
	const generated = spawnSync(
		process.execPath,
		[
			generator,
			'--production-snapshot', productionSnapshotPath,
			'--production-structure', productionStructurePath,
			'--output', generatedPath,
		],
		{ encoding: 'utf8' },
	);
	assert.equal(generated.status, 0, generated.stderr);
	assert.equal(fs.statSync(generatedPath).mode & 0o777, 0o600);
	assert.equal(fs.statSync(`${generatedPath}.sha256`).mode & 0o777, 0o600);
	assert.deepEqual(
		JSON.parse(fs.readFileSync(generatedPath, 'utf8')),
		manifest,
	);
	const duplicateGenerate = spawnSync(
		process.execPath,
		[
			generator,
			'--production-snapshot', productionSnapshotPath,
			'--production-structure', productionStructurePath,
			'--output', generatedPath,
		],
		{ encoding: 'utf8' },
	);
	assert.notEqual(duplicateGenerate.status, 0);
	assert.match(duplicateGenerate.stderr, /already exists/u);

	const preflight = invoke(operationalArgs());
	assert.equal(preflight.status, 0, preflight.stderr);
	assert.match(preflight.stdout, /"mode": "preflight"/u);
	assert.equal(fs.statSync(checkpointPath).mode & 0o777, 0o600);
	let checkpoint = JSON.parse(fs.readFileSync(checkpointPath, 'utf8'));
	assert.equal(checkpoint.preflight.status, 'preflight_ready');
	assert.equal(checkpoint.preflight.post_status, 'draft');
	assert.equal(checkpoint.preflight.editorial_fingerprint_sha256, 'a'.repeat(64));
	assert.equal(checkpoint.apply, null);
	assert.equal(calls().at(-1).phase, 'preflight');
	assertLatestFunctionCleaned();

	const callsBeforeMissingConfirm = calls().length;
	const missingConfirm = invoke(operationalArgs(['--apply']));
	assert.notEqual(missingConfirm.status, 0);
	assert.match(missingConfirm.stderr, /confirm-manifest-sha256/u);
	assert.equal(calls().length, callsBeforeMissingConfirm);

	const wrongConfirm = invoke(operationalArgs([
		'--apply',
		'--confirm-manifest-sha256', 'f'.repeat(64),
	]));
	assert.notEqual(wrongConfirm.status, 0);
	assert.match(wrongConfirm.stderr, /confirm-manifest-sha256/u);
	assert.equal(calls().length, callsBeforeMissingConfirm);

	const apply = invoke(operationalArgs([
		'--apply',
		'--confirm-manifest-sha256', manifest.manifest_sha256,
	]));
	assert.equal(apply.status, 0, apply.stderr);
	assert.match(apply.stdout, /"status": "published_verified"/u);
	checkpoint = JSON.parse(fs.readFileSync(checkpointPath, 'utf8'));
	assert.equal(checkpoint.apply.status, 'published_verified');
	assert.equal(checkpoint.apply.protected_fields_unchanged, true);
	assert.equal(checkpoint.apply.mutation_requests.length, 1);
	assert.equal(checkpoint.apply.public_verification.http_status, 200);
	assert.equal(calls().at(-1).phase, 'apply');
	assert.equal(calls().at(-1).expectedFingerprint, 'a'.repeat(64));
	assertLatestFunctionCleaned();

	const callsBeforeRepeat = calls().length;
	const repeatedApply = invoke(operationalArgs([
		'--apply',
		'--confirm-manifest-sha256', manifest.manifest_sha256,
	]));
	assert.notEqual(repeatedApply.status, 0);
	assert.match(repeatedApply.stderr, /already records a verified publication/u);
	assert.equal(calls().length, callsBeforeRepeat);

	const missingCheckpoint = path.join(testRoot, 'missing-checkpoint.json');
	const noPreflight = invoke(operationalArgs([
		'--apply',
		'--confirm-manifest-sha256', manifest.manifest_sha256,
	]), { checkpointFile: missingCheckpoint });
	assert.notEqual(noPreflight.status, 0);
	assert.match(noPreflight.stderr, /successful recent matching preflight/u);
	assert.equal(fs.existsSync(missingCheckpoint), false);

	const blockedCheckpoint = path.join(testRoot, 'blocked-checkpoint.json');
	const blockedPreflight = invoke(operationalArgs(), {
		checkpointFile: blockedCheckpoint,
	});
	assert.equal(blockedPreflight.status, 0, blockedPreflight.stderr);
	const blockedApply = invoke(operationalArgs([
		'--apply',
		'--confirm-manifest-sha256', manifest.manifest_sha256,
	]), {
		mode: 'blocked',
		checkpointFile: blockedCheckpoint,
	});
	assert.notEqual(blockedApply.status, 0);
	assert.match(blockedApply.stderr, /apply result rejected \(SIMULATED_BLOCK\)/u);
	const blockedReceipt = JSON.parse(
		fs.readFileSync(blockedCheckpoint, 'utf8'),
	);
	assert.equal(blockedReceipt.apply.status, 'blocked');
	assert.equal(blockedReceipt.apply.error_code, 'SIMULATED_BLOCK');
	assert.equal(fs.statSync(blockedCheckpoint).mode & 0o777, 0o600);

	const staleCheckpoint = path.join(testRoot, 'stale-checkpoint.json');
	const stalePreflight = invoke(operationalArgs(), {
		checkpointFile: staleCheckpoint,
	});
	assert.equal(stalePreflight.status, 0, stalePreflight.stderr);
	const stale = JSON.parse(fs.readFileSync(staleCheckpoint, 'utf8'));
	stale.preflight.observed_at = '2020-01-01T00:00:00.000Z';
	fs.writeFileSync(
		staleCheckpoint,
		`${JSON.stringify(stale, null, 2)}\n`,
		{ mode: 0o600 },
	);
	const staleApply = invoke(operationalArgs([
		'--apply',
		'--confirm-manifest-sha256', manifest.manifest_sha256,
	]), { checkpointFile: staleCheckpoint });
	assert.notEqual(staleApply.status, 0);
	assert.match(staleApply.stderr, /successful recent matching preflight/u);

	const cliFailCheckpoint = path.join(testRoot, 'cli-fail-checkpoint.json');
	const callsBeforeCliFailure = calls().length;
	const cliFailure = invoke(operationalArgs(), {
		mode: 'cli-fail',
		checkpointFile: cliFailCheckpoint,
	});
	assert.notEqual(cliFailure.status, 0);
	assert.match(cliFailure.stderr, /Playwright CLI failed/u);
	assert.equal(calls().length, callsBeforeCliFailure + 1);
	assertLatestFunctionCleaned();
	assert.equal(fs.existsSync(cliFailCheckpoint), false);

	const tamperedManifestPath = path.join(testRoot, 'tampered.json');
	const tampered = structuredClone(manifest);
	tampered.post.slug = 'otro-slug';
	fs.writeFileSync(
		tamperedManifestPath,
		`${JSON.stringify(tampered, null, 2)}\n`,
		{ mode: 0o600 },
	);
	fs.writeFileSync(
		`${tamperedManifestPath}.sha256`,
		`${rankingPublishSha256(fs.readFileSync(tamperedManifestPath))}  ${
			path.basename(tamperedManifestPath)
		}\n`,
		{ mode: 0o600 },
	);
	const callsBeforeTamper = calls().length;
	const tamperResult = invoke(['--validate-only'], {
		manifestFile: tamperedManifestPath,
	});
	assert.notEqual(tamperResult.status, 0);
	assert.match(tamperResult.stderr, /manifest is invalid/u);
	assert.equal(calls().length, callsBeforeTamper);

	const badModeManifest = path.join(testRoot, 'bad-mode.json');
	fs.copyFileSync(manifestPath, badModeManifest);
	fs.copyFileSync(`${manifestPath}.sha256`, `${badModeManifest}.sha256`);
	fs.writeFileSync(
		`${badModeManifest}.sha256`,
		`${rankingPublishSha256(fs.readFileSync(badModeManifest))}  ${
			path.basename(badModeManifest)
		}\n`,
		{ mode: 0o600 },
	);
	fs.chmodSync(badModeManifest, 0o644);
	const badModeResult = invoke(['--validate-only'], {
		manifestFile: badModeManifest,
	});
	assert.notEqual(badModeResult.status, 0);
	assert.match(badModeResult.stderr, /mode must be exactly 0600/u);

	const unsafeOrigin = invoke([
		'--base-url', 'http://localhost:8080',
		'--session', 'akb-ranking-production',
		'--checkpoint', '__CHECKPOINT__',
	]);
	assert.notEqual(unsafeOrigin.status, 0);
	assert.match(unsafeOrigin.stderr, /reviewed production origin/u);

	const unsafeCheckpoint = invoke([
		'--base-url', 'https://akibara.cl',
		'--session', 'akb-ranking-production',
		'--checkpoint', path.join(os.tmpdir(), 'ranking-publish.json'),
	]);
	assert.notEqual(unsafeCheckpoint.status, 0);
	assert.match(unsafeCheckpoint.stderr, /outside its allowed local roots/u);

	process.stdout.write('PASS: ranking publication official-UI manifest and runner tests\n');
} finally {
	fs.rmSync(testRoot, { recursive: true, force: true });
}
