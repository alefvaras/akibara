#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import {
	classifyPair,
	expectedAuthorTerms,
	validateAuthorTermManifest,
} from './lib/author-term-slug-official-ui-manifest.mjs';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(scriptDirectory);
const runner = path.join(
	scriptDirectory,
	'run-author-term-slug-official-ui-playwright.mjs',
);
const manifestPath = path.join(
	scriptDirectory,
	'author-term-slug-official-ui-manifest.json',
);
const reportRoot = path.join(
	repoRoot,
	'.local-reports',
	'author-term-slug-official-ui',
);
fs.mkdirSync(reportRoot, { recursive: true, mode: 0o700 });
const testRoot = fs.mkdtempSync(path.join(reportRoot, '.test-author-term-'));
const fixtureRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'akibara-author-term-test-'));
const binDirectory = path.join(fixtureRoot, 'bin');
const observationFile = path.join(fixtureRoot, 'observations.jsonl');
const stateFile = path.join(fixtureRoot, 'state.json');
fs.mkdirSync(binDirectory, { recursive: true });

const fakeNpx = `#!/usr/bin/env node
const fs = require('node:fs');
const args = process.argv.slice(2);
const filenameArg = args.find(value => value.startsWith('--filename='));
if (!filenameArg) process.exit(91);
const source = fs.readFileSync(filenameArg.slice('--filename='.length), 'utf8');
const operation = source.includes("'AUTHOR_TERM_APPLY'")
	? 'apply'
	: source.includes("'AUTHOR_TERM_OBSERVE'")
		? 'observe'
		: 'unknown';
fs.appendFileSync(
	process.env.FAKE_OBSERVATIONS,
	JSON.stringify({ operation, args, function_mode: fs.statSync(filenameArg.slice('--filename='.length)).mode & 0o777 }) + '\\n',
	{ mode: 0o600 }
);
const baseMatch = /const baseUrl = ("(?:[^"\\\\]|\\\\.)*");/u.exec(source);
if (!baseMatch) process.exit(92);
const baseUrl = JSON.parse(baseMatch[1]);
const reviewed = [
	{
		term_id: 236,
		before: { name: 'Tatsukia Fujimoto', slug: 'tatsukia-fujimoto' },
		after: { name: 'Tatsuki Fujimoto', slug: 'tatsuki-fujimoto' },
	},
	{
		term_id: 275,
		before: { name: 'Tappei Nagatsuk', slug: 'tappei-nagatsuk' },
		after: { name: 'Tappei Nagatsuki', slug: 'tappei-nagatsuki' },
	},
	{
		term_id: 456,
		before: { name: 'Masamure Shirow', slug: 'masamure-shirow' },
		after: { name: 'Masamune Shirow', slug: 'masamune-shirow' },
	},
];
const state = fs.existsSync(process.env.FAKE_STATE_FILE)
	? JSON.parse(fs.readFileSync(process.env.FAKE_STATE_FILE, 'utf8'))
	: {};
function publicRecord(slug, status, canonical) {
	const target = baseUrl + '/autor/' + slug + '/';
	return {
		target,
		status,
		effective_url: target,
		canonical_count: canonical ? 1 : 0,
		canonical: canonical ? target : null,
		robots: process.env.FAKE_NOINDEX === '1' ? ['noindex, follow'] : ['index, follow'],
	};
}
if (operation === 'observe') {
	const override = process.env.FAKE_OVERRIDE || '';
	let records = reviewed.map((term, index) => {
		const phase = state[String(term.term_id)] || 'before';
		let current = phase === 'after' ? term.after : term.before;
		if (override === 'drift' && index === 0) {
			current = { name: 'Unexpected Author', slug: 'unexpected-author' };
		}
		return {
			term_id: term.term_id,
			current,
			current_public: publicRecord(current.slug, 200, true),
			desired_public: publicRecord(
				term.after.slug,
				current.slug === term.after.slug ? 200 : 404,
				current.slug === term.after.slug
			),
		};
	});
	if (override === 'missing') records = records.slice(0, 2);
	if (override === 'bad-public') {
		records[0].current_public.canonical = baseUrl + '/wrong/';
	}
	process.stdout.write(JSON.stringify({
		result: JSON.stringify({ records }),
	}));
	process.exit(0);
}
if (operation === 'apply') {
	const termMatch = /const term = (\\{.*\\});\\n\\t\\tvoid operation;/u.exec(source);
	if (!termMatch) process.exit(93);
	const item = JSON.parse(termMatch[1]);
	const reviewedTerm = reviewed.find(term => term.term_id === item.term_id);
	if (!reviewedTerm) process.exit(94);
	const initial = state[String(item.term_id)] || 'before';
	state[String(item.term_id)] = 'after';
	fs.writeFileSync(process.env.FAKE_STATE_FILE, JSON.stringify(state), { mode: 0o600 });
	process.stdout.write(JSON.stringify({
		result: JSON.stringify({
			blocked: null,
			term_id: item.term_id,
			current: reviewedTerm.after,
			desired_public: publicRecord(reviewedTerm.after.slug, 200, true),
			write_performed: initial !== 'after',
		}),
	}));
	process.exit(0);
}
process.exit(95);
`;
const fakeNpxPath = path.join(binDirectory, 'npx');
fs.writeFileSync(fakeNpxPath, fakeNpx, { mode: 0o700 });

function checkpointPath(label) {
	const directory = path.join(testRoot, label);
	fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
	return path.join(directory, 'checkpoint.json');
}

function invoke({
	phase,
	checkpoint,
	environment = 'production',
	baseUrl = 'https://akibara.cl',
	manifest = manifestPath,
	override = '',
	state = stateFile,
	noindex = false,
} = {}) {
	const args = [runner, '--manifest', manifest];
	if (phase === 'validate-only') {
		args.push('--validate-only');
	} else {
		args.push(
			'--environment', environment,
			'--base-url', baseUrl,
			'--session', 'akb-author-term-test',
			'--checkpoint', checkpoint,
			`--${phase}`,
		);
	}
	return spawnSync(process.execPath, args, {
		encoding: 'utf8',
		env: {
			...process.env,
			PATH: `${binDirectory}${path.delimiter}${process.env.PATH}`,
			FAKE_OBSERVATIONS: observationFile,
			FAKE_STATE_FILE: state,
			FAKE_OVERRIDE: override,
			FAKE_NOINDEX: noindex ? '1' : '0',
		},
	});
}

function observations() {
	if (!fs.existsSync(observationFile)) return [];
	return fs.readFileSync(observationFile, 'utf8')
		.trim()
		.split('\n')
		.filter(Boolean)
		.map(line => JSON.parse(line));
}

try {
	const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
	assert.equal(validateAuthorTermManifest(manifest), manifest);
	const expected = expectedAuthorTerms();
	assert.equal(expected.length, 3);
	assert.equal(classifyPair(expected[0], expected[0].before), 'before');
	assert.equal(classifyPair(expected[0], expected[0].after), 'after');
	assert.equal(
		classifyPair(expected[0], { name: 'Drift', slug: expected[0].after.slug }),
		'drift',
	);

	const validateOnly = invoke({ phase: 'validate-only' });
	assert.equal(validateOnly.status, 0, validateOnly.stderr);
	assert.match(validateOnly.stdout, /^PASS: exact 3-term manifest/u);
	assert.equal(observations().length, 0);

	const crossedEnvironment = invoke({
		phase: 'preflight',
		checkpoint: checkpointPath('crossed-environment'),
		environment: 'production',
		baseUrl: 'https://retired.invalid',
		noindex: false,
	});
	assert.notEqual(crossedEnvironment.status, 0);
	assert.match(crossedEnvironment.stderr, /base URL must be exactly/u);
	assert.equal(observations().length, 0);

	const retiredEnvironment = invoke({
		phase: 'preflight',
		checkpoint: checkpointPath('retired-environment'),
		environment: 'qa',
		baseUrl: 'https://akibara.cl',
	});
	assert.notEqual(retiredEnvironment.status, 0);
	assert.match(retiredEnvironment.stderr, /environment must be docker or production/u);
	assert.equal(observations().length, 0);

	const mainCheckpoint = checkpointPath('main');
	const preflight = invoke({
		phase: 'preflight',
		checkpoint: mainCheckpoint,
	});
	assert.equal(preflight.status, 0, preflight.stderr);
	assert.match(preflight.stdout, /preflight 3\/3; no writes/u);
	assert.equal(fs.statSync(mainCheckpoint).mode & 0o777, 0o600);
	let checkpoint = JSON.parse(fs.readFileSync(mainCheckpoint, 'utf8'));
	assert.equal(checkpoint.status, 'preflight_pass');
	assert.deepEqual(
		Object.values(checkpoint.events).map(event => event.state),
		['before', 'before', 'before'],
	);
	assert.deepEqual(
		observations().map(item => item.operation),
		['observe'],
	);

	const apply = invoke({
		phase: 'apply',
		checkpoint: mainCheckpoint,
	});
	assert.equal(apply.status, 0, apply.stderr);
	assert.match(apply.stdout, /applied and verified 3\/3/u);
	checkpoint = JSON.parse(fs.readFileSync(mainCheckpoint, 'utf8'));
	assert.equal(checkpoint.status, 'apply_pass');
	assert.deepEqual(
		Object.values(checkpoint.events).map(event => event.state),
		['after', 'after', 'after'],
	);
	assert.deepEqual(
		observations().map(item => item.operation),
		['observe', 'observe', 'apply', 'apply', 'apply', 'observe'],
	);
	assert.ok(observations().every(item => item.function_mode === 0o600));

	const verify = invoke({
		phase: 'verify',
		checkpoint: checkpointPath('verify'),
	});
	assert.equal(verify.status, 0, verify.stderr);
	assert.match(verify.stdout, /verified after 3\/3/u);

	const missingCheckpoint = checkpointPath('missing');
	fs.rmSync(missingCheckpoint, { force: true });
	const callsBeforeMissingCheckpoint = observations().length;
	const missingApply = invoke({
		phase: 'apply',
		checkpoint: missingCheckpoint,
	});
	assert.notEqual(missingApply.status, 0);
	assert.match(missingApply.stderr, /requires a completed preflight/u);
	assert.equal(observations().length, callsBeforeMissingCheckpoint);

	const driftState = path.join(fixtureRoot, 'drift-state.json');
	const driftCheckpoint = checkpointPath('drift');
	const drift = invoke({
		phase: 'preflight',
		checkpoint: driftCheckpoint,
		override: 'drift',
		state: driftState,
	});
	assert.notEqual(drift.status, 0);
	assert.match(drift.stderr, /disallowed state drift/u);
	assert.equal(fs.existsSync(driftCheckpoint), false);

	const missingTermCheckpoint = checkpointPath('missing-term');
	const missingTerm = invoke({
		phase: 'preflight',
		checkpoint: missingTermCheckpoint,
		override: 'missing',
		state: path.join(fixtureRoot, 'missing-term-state.json'),
	});
	assert.notEqual(missingTerm.status, 0);
	assert.match(missingTerm.stderr, /exactly three term observations/u);
	assert.equal(fs.existsSync(missingTermCheckpoint), false);

	const badPublicCheckpoint = checkpointPath('bad-public');
	const badPublic = invoke({
		phase: 'preflight',
		checkpoint: badPublicCheckpoint,
		override: 'bad-public',
		state: path.join(fixtureRoot, 'bad-public-state.json'),
	});
	assert.notEqual(badPublic.status, 0);
	assert.match(badPublic.stderr, /unique self-canonical/u);
	assert.equal(fs.existsSync(badPublicCheckpoint), false);

	const guardState = path.join(fixtureRoot, 'guard-state.json');
	const guardCheckpoint = checkpointPath('guard');
	const guardPreflight = invoke({
		phase: 'preflight',
		checkpoint: guardCheckpoint,
		state: guardState,
	});
	assert.equal(guardPreflight.status, 0, guardPreflight.stderr);
	const callsBeforeGuard = observations().length;
	const guardDrift = invoke({
		phase: 'apply',
		checkpoint: guardCheckpoint,
		override: 'drift',
		state: guardState,
	});
	assert.notEqual(guardDrift.status, 0);
	assert.match(guardDrift.stderr, /disallowed state drift/u);
	assert.deepEqual(
		observations().slice(callsBeforeGuard).map(item => item.operation),
		['observe'],
	);
	assert.equal(fs.existsSync(guardState), false);

	const staleState = path.join(fixtureRoot, 'stale-state.json');
	const staleCheckpoint = checkpointPath('stale');
	const stalePreflight = invoke({
		phase: 'preflight',
		checkpoint: staleCheckpoint,
		state: staleState,
	});
	assert.equal(stalePreflight.status, 0, stalePreflight.stderr);
	const staleData = JSON.parse(fs.readFileSync(staleCheckpoint, 'utf8'));
	staleData.updated_at = '2020-01-01T00:00:00.000Z';
	fs.writeFileSync(
		staleCheckpoint,
		`${JSON.stringify(staleData, null, 2)}\n`,
		{ mode: 0o600 },
	);
	const callsBeforeStale = observations().length;
	const staleApply = invoke({
		phase: 'apply',
		checkpoint: staleCheckpoint,
		state: staleState,
	});
	assert.notEqual(staleApply.status, 0);
	assert.match(staleApply.stderr, /checkpoint is stale/u);
	assert.equal(observations().length, callsBeforeStale);

	const badManifestPath = path.join(testRoot, 'bad-manifest.json');
	const badManifest = structuredClone(manifest);
	badManifest.application_policy.taxonomy = 'product_cat';
	fs.writeFileSync(
		badManifestPath,
		`${JSON.stringify(badManifest, null, 2)}\n`,
		{ mode: 0o600 },
	);
	const badManifestResult = invoke({
		phase: 'validate-only',
		manifest: badManifestPath,
	});
	assert.notEqual(badManifestResult.status, 0);
	assert.match(badManifestResult.stderr, /application_policy differs/u);

	const dockerState = path.join(fixtureRoot, 'docker-state.json');
	fs.writeFileSync(
		dockerState,
		JSON.stringify({ 236: 'after', 275: 'after', 456: 'after' }),
		{ mode: 0o600 },
	);
	const dockerPreflight = invoke({
		phase: 'preflight',
		checkpoint: checkpointPath('docker'),
		environment: 'docker',
		baseUrl: 'http://localhost:8080',
		state: dockerState,
		noindex: false,
	});
	assert.equal(dockerPreflight.status, 0, dockerPreflight.stderr);
	const callsBeforeDockerApply = observations().length;
	const dockerApply = invoke({
		phase: 'apply',
		checkpoint: checkpointPath('docker'),
		environment: 'docker',
		baseUrl: 'http://localhost:8080',
		state: dockerState,
	});
	assert.notEqual(dockerApply.status, 0);
	assert.match(dockerApply.stderr, /--apply is available only for production/u);
	assert.equal(observations().length, callsBeforeDockerApply);

	process.stdout.write(
		'PASS: 3-term contract, production-only apply, Docker preflight, retired-environment rejection, drift, missing, canonical, stale and no-write guards\n',
	);
} finally {
	fs.rmSync(testRoot, { recursive: true, force: true });
	fs.rmSync(fixtureRoot, { recursive: true, force: true });
}
