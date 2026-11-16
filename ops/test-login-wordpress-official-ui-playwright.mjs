#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const subject = path.join(scriptDirectory, 'login-wordpress-official-ui-playwright.mjs');
const fixtureRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'akibara-login-tests-'));
const binDirectory = path.join(fixtureRoot, 'bin');
const observationFile = path.join(fixtureRoot, 'cli-observations.jsonl');
fs.mkdirSync(binDirectory, { recursive: true });

const username = 'akb_ui_production_20260725081530_abcdef123456';
const password = '1'.repeat(64);
const token = '2'.repeat(64);
const localUsername = 'akb_ui_local_20260725081530_123456abcdef';
const credentialsPath = path.join(fixtureRoot, 'production.credentials');
const credentialsText = [
	'AKB_UI_ADMIN_SCHEMA=1',
	'AKB_UI_ADMIN_ENV=production',
	'AKB_UI_ADMIN_HOST=deployer@akibara.test',
	'AKB_UI_ADMIN_WEBROOT=/home/u888022333/domains/akibara.cl/public_html',
	'AKB_UI_ADMIN_BASE_URL=https://akibara.cl',
	`AKB_UI_ADMIN_USERNAME=${username}`,
	`AKB_UI_ADMIN_PASSWORD=${password}`,
	`AKB_UI_ADMIN_TOKEN=${token}`,
	'AKB_UI_ADMIN_CREATED_AT_UTC=20260725T081530Z',
	'',
].join('\n');
fs.writeFileSync(credentialsPath, credentialsText, { mode: 0o600 });

const fakeNpx = `#!/usr/bin/env node
const fs = require('node:fs');
const args = process.argv.slice(2);
const filenameArg = args.find(value => value.startsWith('--filename='));
if (!filenameArg) process.exit(91);
const functionPath = filenameArg.slice('--filename='.length);
const mode = fs.statSync(functionPath).mode & 0o777;
const source = fs.readFileSync(functionPath, 'utf8');
const observation = {
	args,
	functionPath,
	mode,
	sourceHasPassword: source.includes(process.env.FAKE_EXPECTED_PASSWORD),
	sourceHasUsername: source.includes(process.env.FAKE_EXPECTED_USERNAME),
};
fs.appendFileSync(process.env.FAKE_CLI_OBSERVATIONS, JSON.stringify(observation) + '\\n', { mode: 0o600 });
if (process.env.FAKE_CLI_MODE === 'failure') {
	process.stderr.write('simulated secret: ' + process.env.FAKE_EXPECTED_PASSWORD + '\\n');
	process.exit(9);
}
if (process.env.FAKE_CLI_MODE === 'malformed') {
	process.stdout.write('not-json');
	process.exit(0);
}
const identity = process.env.FAKE_CLI_MODE === 'identity-mismatch'
	? 'unexpected_identity'
	: process.env.FAKE_EXPECTED_USERNAME;
const result = {
	status: process.env.FAKE_CLI_MODE === 'challenge' ? 'FAIL' : 'PASS',
	identity,
	origin: process.env.FAKE_EXPECTED_ORIGIN,
	surface: '/wp-admin/profile.php',
};
process.stdout.write(JSON.stringify({ result: JSON.stringify(result) }));
`;
const fakeNpxPath = path.join(binDirectory, 'npx');
fs.writeFileSync(fakeNpxPath, fakeNpx, { mode: 0o700 });

function invoke({
	mode = 'pass',
	baseUrl = 'https://akibara.cl',
	file = credentialsPath,
	expectedUsername = username,
	expectedPassword = password,
} = {}) {
	return spawnSync(
		process.execPath,
		[
			subject,
			'--base-url', baseUrl,
			'--session', 'akb-rankmath-production',
			'--credentials', file,
		],
		{
			encoding: 'utf8',
			env: {
				...process.env,
				PATH: `${binDirectory}${path.delimiter}${process.env.PATH}`,
				FAKE_CLI_MODE: mode,
				FAKE_CLI_OBSERVATIONS: observationFile,
				FAKE_EXPECTED_USERNAME: expectedUsername,
				FAKE_EXPECTED_PASSWORD: expectedPassword,
				FAKE_EXPECTED_ORIGIN: baseUrl,
			},
		},
	);
}

function observations() {
	if (!fs.existsSync(observationFile)) return [];
	return fs.readFileSync(observationFile, 'utf8')
		.trim()
		.split('\n')
		.filter(Boolean)
		.map(line => JSON.parse(line));
}

function assertLatestFunctionCleaned(expectedPassword = password) {
	const latest = observations().at(-1);
	assert.ok(latest);
	assert.equal(latest.mode, 0o600);
	assert.equal(latest.sourceHasPassword, true);
	assert.equal(latest.sourceHasUsername, true);
	assert.equal(fs.existsSync(latest.functionPath), false);
	assert.equal(fs.existsSync(path.dirname(latest.functionPath)), false);
	assert.equal(latest.args.some(argument => argument.includes(expectedPassword)), false);
	assert.equal(latest.args.some(argument => argument.includes(token)), false);
}

try {
	const success = invoke();
	assert.equal(success.status, 0, success.stderr);
	assert.match(success.stdout, /^PASS: WordPress UI session authenticated/u);
	assert.equal(success.stdout.includes(password), false);
	assert.equal(success.stderr.includes(password), false);
	assertLatestFunctionCleaned();

	for (const mode of ['failure', 'malformed', 'identity-mismatch', 'challenge']) {
		const result = invoke({ mode });
		assert.notEqual(result.status, 0, `${mode} unexpectedly succeeded`);
		assert.equal(result.stdout.includes(password), false);
		assert.equal(result.stderr.includes(password), false);
		assertLatestFunctionCleaned();
	}

	const callsBeforeMismatch = observations().length;
	const mismatch = invoke({ baseUrl: 'https://www.akibara.cl' });
	assert.notEqual(mismatch.status, 0);
	assert.match(mismatch.stderr, /does not match --base-url/u);
	assert.equal(observations().length, callsBeforeMismatch);

	const retiredCredentialsPath = path.join(fixtureRoot, 'retired.credentials');
	fs.writeFileSync(
		retiredCredentialsPath,
		credentialsText
			.replace('AKB_UI_ADMIN_ENV=production', 'AKB_UI_ADMIN_ENV=qa')
			.replace(
				'AKB_UI_ADMIN_USERNAME=akb_ui_production_',
				'AKB_UI_ADMIN_USERNAME=akb_ui_qa_',
			),
		{ mode: 0o600 },
	);
	const callsBeforeRetired = observations().length;
	const retired = invoke({ file: retiredCredentialsPath });
	assert.notEqual(retired.status, 0);
	assert.match(retired.stderr, /Invalid credentials environment/u);
	assert.equal(observations().length, callsBeforeRetired);

	const localCredentialsPath = path.join(fixtureRoot, 'local.credentials');
	const localCredentialsText = [
		'AKB_UI_ADMIN_SCHEMA=2',
		'AKB_UI_ADMIN_ENV=local',
		'AKB_UI_ADMIN_HOST=docker-compose:cli',
		'AKB_UI_ADMIN_WEBROOT=/var/www/html',
		'AKB_UI_ADMIN_BASE_URL=http://localhost:8080',
		`AKB_UI_ADMIN_USERNAME=${localUsername}`,
		`AKB_UI_ADMIN_PASSWORD=${password}`,
		`AKB_UI_ADMIN_TOKEN=${token}`,
		'AKB_UI_ADMIN_CREATED_AT_UTC=20260725T081530Z',
		'',
	].join('\n');
	fs.writeFileSync(localCredentialsPath, localCredentialsText, { mode: 0o600 });
	const localSuccess = invoke({
		baseUrl: 'http://localhost:8080',
		file: localCredentialsPath,
		expectedUsername: localUsername,
	});
	assert.equal(localSuccess.status, 0, localSuccess.stderr);
	assert.match(localSuccess.stdout, /^PASS: WordPress UI session authenticated/u);
	assert.equal(localSuccess.stdout.includes(password), false);
	assert.equal(localSuccess.stderr.includes(password), false);
	assertLatestFunctionCleaned();

	const callsBeforeUnsafeLocal = observations().length;
	const unsafeLocal = invoke({
		baseUrl: 'http://127.0.0.1:8080',
		file: localCredentialsPath,
		expectedUsername: localUsername,
	});
	assert.notEqual(unsafeLocal.status, 0);
	assert.match(unsafeLocal.stderr, /must be exactly http:\/\/localhost:8080/u);
	assert.equal(observations().length, callsBeforeUnsafeLocal);

	const crossedLocalPath = path.join(fixtureRoot, 'crossed-local.credentials');
	fs.writeFileSync(
		crossedLocalPath,
		localCredentialsText.replace(
			'AKB_UI_ADMIN_HOST=docker-compose:cli',
			'AKB_UI_ADMIN_HOST=deployer@retired.invalid',
		),
		{ mode: 0o600 },
	);
	const crossedLocal = invoke({
		baseUrl: 'http://localhost:8080',
		file: crossedLocalPath,
		expectedUsername: localUsername,
	});
	assert.notEqual(crossedLocal.status, 0);
	assert.match(crossedLocal.stderr, /Unsafe local credentials Compose source/u);

	const badModePath = path.join(fixtureRoot, 'bad-mode.credentials');
	fs.copyFileSync(credentialsPath, badModePath);
	fs.chmodSync(badModePath, 0o644);
	const callsBeforeMode = observations().length;
	const badMode = invoke({ file: badModePath });
	assert.notEqual(badMode.status, 0);
	assert.match(badMode.stderr, /mode must be exactly 0600/u);
	assert.equal(observations().length, callsBeforeMode);

	const symlinkPath = path.join(fixtureRoot, 'symlink.credentials');
	fs.symlinkSync(credentialsPath, symlinkPath);
	const symlink = invoke({ file: symlinkPath });
	assert.notEqual(symlink.status, 0);
	assert.match(symlink.stderr, /non-symlink/u);

	const source = fs.readFileSync(subject, 'utf8');
	for (const requiredGuard of [
		'AUTH_CHALLENGE_DETECTED',
		'LOGIN_REJECTED',
		'AUTH_ORIGIN_ESCAPE',
		'AUTH_IDENTITY_MISMATCH',
		'PROFILE_IDENTITY_MISSING',
		'--filename=',
		'page.waitForNavigation({',
		"encodeURIComponent(profileUrl)",
	]) {
		assert.ok(source.includes(requiredGuard), `missing guard: ${requiredGuard}`);
	}
	assert.equal(
		source.includes("page.waitForLoadState('domcontentloaded'),\n\t\tsubmit.click()"),
		false,
	);
	assert.equal(/spawnSync\([^)]*password/su.test(source), false);

	process.stdout.write(
		'PASS: production/local WordPress UI login, retired-environment rejection, decoy CLI, mismatch guards, and temp cleanup\n',
	);
} finally {
	fs.rmSync(fixtureRoot, { recursive: true, force: true });
}
