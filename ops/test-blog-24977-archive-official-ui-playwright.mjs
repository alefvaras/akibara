#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	blog24977ArchiveSha256,
	buildBlog24977ArchiveManifest,
	validateBlog24977ArchiveManifest,
} from './lib/blog-24977-archive-official-ui-manifest.mjs';
import {
	assertBlog24977ArchiveBrowserFunctionSafety,
	blog24977ArchiveBrowserFunction,
	BLOG_24977_ARCHIVE_PLAYWRIGHT_PACKAGE,
} from './run-blog-24977-archive-official-ui-playwright.mjs';

const opsDirectory = path.dirname(fileURLToPath(import.meta.url));
const repository = path.dirname(opsDirectory);
const runner = path.join(
	opsDirectory,
	'run-blog-24977-archive-official-ui-playwright.mjs',
);
const generator = path.join(
	opsDirectory,
	'generate-blog-24977-archive-official-ui-manifest.mjs',
);
const auditedDirectory = path.join(
	repository,
	'.local-reports',
	'blog-audit-20260818',
);
const auditedManifestPath = path.join(
	auditedDirectory,
	'blog-24977-archive-official-ui-manifest.json',
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
	`.blog-24977-archive-tests-${process.pid}-${Date.now()}`,
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
const manifest = validateBlog24977ArchiveManifest(
	JSON.parse(fs.readFileSync(manifestPath, 'utf8')),
);
fs.writeFileSync(
	`${manifestPath}.sha256`,
	`${blog24977ArchiveSha256(fs.readFileSync(manifestPath))}  ${
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
  || !args.includes(${JSON.stringify(BLOG_24977_ARCHIVE_PLAYWRIGHT_PACKAGE)})
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
const unsafe = /fetch\\s*\\(|page\\.request|context\\.request|request\\.(?:post|put|patch|delete)\\s*\\(|setInputFiles\\s*\\(|\\.fill\\s*\\(|wp\\.data\\.dispatch/iu.test(source);
if (
  mode !== 0o600
  || unsafe
  || !source.includes('AKB_BLOG_24977_ARCHIVE_OFFICIAL_UI')
  || !source.includes("await page.route('**/*', guard)")
  || !source.includes('button.editor-post-switch-to-draft:visible')
  || !source.includes('PROTECTED_FIELDS_CHANGED_AFTER_ARCHIVE')
  || !source.includes('SOURCE_REDIRECT_VERIFICATION_FAILED')
  || !source.includes('SITEMAP_AFTER_VERIFICATION_FAILED')
  || !source.includes('DESTINATION_INDEXABILITY_FAILED')
) process.exit(91);
fs.appendFileSync(process.env.AKB_24977_FAKE_LOG, JSON.stringify({
  args,
  functionPath,
  mode,
  runtimeDirectory: process.cwd(),
  phase,
  expectedFingerprint,
}) + '\\n', { mode: 0o600 });
if (process.env.AKB_24977_FAKE_MODE === 'cli-fail') {
  process.stderr.write('simulated Playwright failure');
  process.exit(72);
}
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const fields = Object.fromEntries([
  'all_meta',
  'editor_attributes',
  'featured_image',
  'post_content',
  'post_excerpt',
  'post_title',
  'rank_math_description',
  'rank_math_focus_keyword',
  'rank_math_meta',
  'rank_math_title',
].map(key => [key, hash(key)]));
const fingerprint = phase === 'apply'
  ? expectedFingerprint
  : 'a'.repeat(64);
const publicVerification = sourcePresent => ({
  redirect: {
    source_url: manifest.redirect.source_url,
    initial_status: 301,
    location_url: manifest.redirect.destination_url,
    x_redirect_by: 'Rank Math',
    final_url: manifest.redirect.destination_url,
    final_status: 200,
  },
  destination: {
    url: manifest.redirect.destination_url,
    http_status: 200,
    canonical_url: manifest.redirect.destination_url,
    canonical_count: 1,
    robots: 'index, follow',
    x_robots_tag: '',
    indexable: true,
    post_id_body_class: true,
  },
  sitemap: {
    url: manifest.verification.post_sitemap_url,
    http_status: 200,
    content_type: 'application/xml; charset=UTF-8',
    source_url_present: sourcePresent,
    destination_url_present: true,
    body_sha256: hash('sitemap-' + sourcePresent),
  },
});
let result;
if (process.env.AKB_24977_FAKE_MODE === 'blocked') {
  result = {
    status: 'blocked',
    phase,
    error_code: 'SIMULATED_BLOCK',
    post_id: manifest.post.post_id,
    editorial_fingerprint_sha256: fingerprint,
    mutation_requests: [],
    route_violations: ['SIMULATED_BLOCK'],
    archive_response_status: null,
  };
} else if (phase === 'preflight') {
  result = {
    status: 'preflight_ready',
    post_id: manifest.post.post_id,
    slug: manifest.post.slug,
    post_status: 'publish',
    editorial_fingerprint_sha256: fingerprint,
    protected_field_sha256: fields,
    public_verification: publicVerification(true),
    route_mutation_requests: 0,
  };
} else {
  result = {
    status: 'archived_verified',
    post_id: manifest.post.post_id,
    slug: manifest.post.slug,
    before_status: 'publish',
    after_status: 'draft',
    editorial_fingerprint_sha256: fingerprint,
    protected_field_sha256: fields,
    protected_fields_unchanged: true,
    confirmation_clicked: true,
    mutation_requests: [{
      method: 'POST',
      path: '/wp-json/wp/v2/posts/' + manifest.post.post_id,
      status: 'draft',
      body_sha256: hash('draft-body'),
    }],
    archive_response_status: 200,
    public_verification: publicVerification(false),
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
				AKB_24977_FAKE_LOG: logPath,
				AKB_24977_FAKE_MODE: mode,
			},
		},
	);
}

function operationalArgs(extra = []) {
	return [
		'--base-url', 'https://akibara.cl',
		'--session', 'akb-blog-24977-production',
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
	const preflightSource = blog24977ArchiveBrowserFunction({
		baseUrl: manifest.target_origin,
		manifest,
		phase: 'preflight',
	});
	assert.doesNotThrow(
		() => assertBlog24977ArchiveBrowserFunctionSafety(preflightSource),
	);
	assert.equal(/\.fill\s*\(/u.test(preflightSource), false);
	assert.equal(/setInputFiles\s*\(/u.test(preflightSource), false);
	assert.equal(/fetch\s*\(/u.test(preflightSource), false);
	assert.equal(/wp\.data\.dispatch/iu.test(preflightSource), false);
	assert.match(preflightSource, /page\.route\('\*\*\/\*', guard\)/u);
	assert.match(preflightSource, /ARCHIVE_PAYLOAD_META_MISMATCH/u);
	assert.match(preflightSource, /SOURCE_REDIRECT_VERIFICATION_FAILED/u);
	assert.match(preflightSource, /SITEMAP_AFTER_VERIFICATION_FAILED/u);
	assert.match(preflightSource, /DESTINATION_INDEXABILITY_FAILED/u);
	assert.match(preflightSource, /editor-post-switch-to-draft/u);

	const callsBeforeValidate = calls().length;
	const validate = invoke(['--validate-only']);
	assert.equal(validate.status, 0, validate.stderr);
	assert.match(validate.stdout, /"mode": "validate-only"/u);
	assert.match(validate.stdout, /"redirect_rule_id": 588/u);
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

	const snapshot = JSON.parse(fs.readFileSync(productionSnapshotPath, 'utf8'));
	const structure = JSON.parse(fs.readFileSync(productionStructurePath, 'utf8'));
	assert.doesNotThrow(() => buildBlog24977ArchiveManifest({
		productionSnapshot: snapshot,
		productionSnapshotSha256: blog24977ArchiveSha256(
			fs.readFileSync(productionSnapshotPath),
		),
		productionStructure: structure,
		productionStructureSha256: blog24977ArchiveSha256(
			fs.readFileSync(productionStructurePath),
		),
	}));
	const wrongSource = structuredClone(snapshot);
	wrongSource.posts.find(({ post_id: id }) => id === 24977).post_status = 'draft';
	assert.throws(() => buildBlog24977ArchiveManifest({
		productionSnapshot: wrongSource,
		productionSnapshotSha256: 'b'.repeat(64),
		productionStructure: structure,
		productionStructureSha256: 'c'.repeat(64),
	}), /reviewed consolidation/u);
	const wrongDestination = structuredClone(structure);
	wrongDestination.posts.find(({ post_id: id }) => id === 23334).post_status = 'draft';
	assert.throws(() => buildBlog24977ArchiveManifest({
		productionSnapshot: snapshot,
		productionSnapshotSha256: 'b'.repeat(64),
		productionStructure: wrongDestination,
		productionStructureSha256: 'c'.repeat(64),
	}), /reviewed consolidation/u);

	const preflight = invoke(operationalArgs());
	assert.equal(preflight.status, 0, preflight.stderr);
	assert.match(preflight.stdout, /"mode": "preflight"/u);
	assert.equal(fs.statSync(checkpointPath).mode & 0o777, 0o600);
	let checkpoint = JSON.parse(fs.readFileSync(checkpointPath, 'utf8'));
	assert.equal(checkpoint.preflight.status, 'preflight_ready');
	assert.equal(checkpoint.preflight.post_status, 'publish');
	assert.equal(
		checkpoint.preflight.public_verification.redirect.initial_status,
		301,
	);
	assert.equal(
		checkpoint.preflight.public_verification.sitemap.source_url_present,
		true,
	);
	assert.equal(
		checkpoint.preflight.public_verification.destination.indexable,
		true,
	);
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
	assert.match(apply.stdout, /"status": "archived_verified"/u);
	checkpoint = JSON.parse(fs.readFileSync(checkpointPath, 'utf8'));
	assert.equal(checkpoint.apply.status, 'archived_verified');
	assert.equal(checkpoint.apply.protected_fields_unchanged, true);
	assert.equal(checkpoint.apply.mutation_requests.length, 1);
	assert.equal(checkpoint.apply.mutation_requests[0].status, 'draft');
	assert.equal(
		checkpoint.apply.public_verification.redirect.initial_status,
		301,
	);
	assert.equal(
		checkpoint.apply.public_verification.sitemap.source_url_present,
		false,
	);
	assert.equal(
		checkpoint.apply.public_verification.sitemap.destination_url_present,
		true,
	);
	assert.equal(
		checkpoint.apply.public_verification.destination.http_status,
		200,
	);
	assert.equal(calls().at(-1).phase, 'apply');
	assert.equal(calls().at(-1).expectedFingerprint, 'a'.repeat(64));
	assertLatestFunctionCleaned();

	const callsBeforeRepeat = calls().length;
	const repeatedApply = invoke(operationalArgs([
		'--apply',
		'--confirm-manifest-sha256', manifest.manifest_sha256,
	]));
	assert.notEqual(repeatedApply.status, 0);
	assert.match(repeatedApply.stderr, /already records a verified archive/u);
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

	const wrongModePath = path.join(testRoot, 'wrong-mode.json');
	fs.copyFileSync(manifestPath, wrongModePath);
	fs.copyFileSync(`${manifestPath}.sha256`, `${wrongModePath}.sha256`);
	fs.chmodSync(wrongModePath, 0o644);
	fs.chmodSync(`${wrongModePath}.sha256`, 0o600);
	const wrongMode = invoke(['--validate-only'], {
		manifestFile: wrongModePath,
	});
	assert.notEqual(wrongMode.status, 0);
	assert.match(wrongMode.stderr, /mode must be exactly 0600/u);

	const tamperedPath = path.join(testRoot, 'tampered.json');
	const tampered = structuredClone(manifest);
	tampered.redirect.rule_id = 589;
	fs.writeFileSync(
		tamperedPath,
		`${JSON.stringify(tampered, null, 2)}\n`,
		{ mode: 0o600 },
	);
	fs.writeFileSync(
		`${tamperedPath}.sha256`,
		`${blog24977ArchiveSha256(fs.readFileSync(tamperedPath))}  ${
			path.basename(tamperedPath)
		}\n`,
		{ mode: 0o600 },
	);
	const tamperedResult = invoke(['--validate-only'], {
		manifestFile: tamperedPath,
	});
	assert.notEqual(tamperedResult.status, 0);
	assert.match(tamperedResult.stderr, /manifest is invalid/u);

	assert.throws(
		() => assertBlog24977ArchiveBrowserFunctionSafety(
			preflightSource.replace(
				'/* AKB_BLOG_24977_ARCHIVE_OFFICIAL_UI */',
				'fetch("/wp-json/wp/v2/posts/24977", { method: "POST" });',
			),
		),
		/prohibited direct or editorial write/u,
	);
	assert.throws(
		() => assertBlog24977ArchiveBrowserFunctionSafety(
			preflightSource.replace(
				'/* AKB_BLOG_24977_ARCHIVE_OFFICIAL_UI */',
				'wp.data.dispatch("core/editor").editPost({status:"draft"});',
			),
		),
		/prohibited direct or editorial write/u,
	);

	process.stdout.write('blog 24977 archive official UI tests: PASS\n');
} finally {
	fs.rmSync(testRoot, { recursive: true, force: true });
}
