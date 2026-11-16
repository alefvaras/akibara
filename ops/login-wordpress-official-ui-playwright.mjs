#!/usr/bin/env node

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';

function fail(message) {
	throw new Error(message);
}

function usage() {
	process.stdout.write(`Usage:
  node ops/login-wordpress-official-ui-playwright.mjs \\
    --base-url https://akibara.cl \\
    --session SESSION \\
    --credentials FILE

The existing Playwright CLI session is authenticated through the official
WordPress wp-login.php UI. The credentials file must have mode 0600 and match
--base-url exactly. Remote schema-1 credentials are production-only and retain
their path-free HTTPS contract; Docker-local schema-2 credentials are fixed to
http://localhost:8080. Passwords are never placed in process arguments or
output.
`);
}

function parseArgs(argv) {
	const parsed = {
		baseUrl: '',
		session: '',
		credentials: '',
	};
	for (let index = 0; index < argv.length; index += 1) {
		const argument = argv[index];
		if (argument === '--help' || argument === '-h') {
			usage();
			process.exit(0);
		}
		const mapping = {
			'--base-url': 'baseUrl',
			'--session': 'session',
			'--credentials': 'credentials',
		};
		const key = mapping[argument];
		if (!key) fail(`Unknown argument: ${argument}`);
		if (parsed[key]) fail(`Duplicate argument: ${argument}`);
		index += 1;
		if (index >= argv.length || argv[index].startsWith('--')) {
			fail(`${argument} requires a value`);
		}
		parsed[key] = argv[index];
	}
	for (const [name, value] of Object.entries(parsed)) {
		if (!value) fail(`Missing required argument: ${name}`);
	}
	return parsed;
}

function validateBaseUrl(value, environment = 'remote') {
	if (environment === 'local') {
		if (value !== 'http://localhost:8080') {
			fail('Docker-local base URL must be exactly http://localhost:8080');
		}
		return value;
	}
	if (!/^https:\/\/[A-Za-z0-9.-]+$/u.test(value)) {
		fail('Base URL must be a path-free HTTPS origin');
	}
	const parsed = new URL(value);
	if (
		parsed.origin !== value
		|| parsed.username
		|| parsed.password
		|| parsed.port
		|| parsed.pathname !== '/'
		|| parsed.search
		|| parsed.hash
	) {
		fail('Base URL must be a path-free HTTPS origin');
	}
	return value;
}

function validateSession(value) {
	if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/u.test(value)) {
		fail('Unsafe Playwright session name');
	}
	return value;
}

function assertMode0600(filePath) {
	const stats = fs.lstatSync(filePath);
	if (!stats.isFile() || stats.isSymbolicLink()) {
		fail('Credentials must be a regular non-symlink file');
	}
	if ((stats.mode & 0o777) !== 0o600) {
		fail('Credentials file mode must be exactly 0600');
	}
}

function parseCredentials(filePath) {
	assertMode0600(filePath);
	const allowed = new Set([
		'AKB_UI_ADMIN_SCHEMA',
		'AKB_UI_ADMIN_ENV',
		'AKB_UI_ADMIN_HOST',
		'AKB_UI_ADMIN_WEBROOT',
		'AKB_UI_ADMIN_BASE_URL',
		'AKB_UI_ADMIN_USERNAME',
		'AKB_UI_ADMIN_PASSWORD',
		'AKB_UI_ADMIN_TOKEN',
		'AKB_UI_ADMIN_CREATED_AT_UTC',
	]);
	const values = new Map();
	const text = fs.readFileSync(filePath, 'utf8');
	const lines = text.split('\n');
	for (const [index, line] of lines.entries()) {
		if (!line && index === lines.length - 1) continue;
		const separator = line.indexOf('=');
		if (separator <= 0) fail(`Invalid credentials line ${index + 1}`);
		const key = line.slice(0, separator);
		const value = line.slice(separator + 1);
		if (!allowed.has(key)) fail(`Unknown credentials key at line ${index + 1}`);
		if (values.has(key)) fail(`Duplicate credentials key: ${key}`);
		values.set(key, value);
	}
	for (const key of allowed) {
		if (!values.get(key)) fail(`Missing credentials key: ${key}`);
	}

	const credentials = Object.fromEntries(values);
	const schema = credentials.AKB_UI_ADMIN_SCHEMA;
	const environment = credentials.AKB_UI_ADMIN_ENV;
	if (schema === '1') {
		if (environment !== 'production') {
			fail('Invalid credentials environment');
		}
		if (
			!/^[A-Za-z0-9][A-Za-z0-9._-]*@[A-Za-z0-9][A-Za-z0-9.-]*$/u
				.test(credentials.AKB_UI_ADMIN_HOST)
		) {
			fail('Unsafe credentials host');
		}
		if (
			!/^\/home\/[A-Za-z0-9._-]+(?:\/domains\/[A-Za-z0-9.-]+)?\/public_html$/u
				.test(credentials.AKB_UI_ADMIN_WEBROOT)
		) {
			fail('Unsafe credentials webroot');
		}
		validateBaseUrl(credentials.AKB_UI_ADMIN_BASE_URL, environment);
		if (
			!/^akb_ui_production_[0-9]{14}_[a-f0-9]{12}$/u
				.test(credentials.AKB_UI_ADMIN_USERNAME)
		) {
			fail('Temporary username marker rejected');
		}
	} else if (schema === '2') {
		if (environment !== 'local') fail('Invalid local credentials environment');
		if (credentials.AKB_UI_ADMIN_HOST !== 'docker-compose:cli') {
			fail('Unsafe local credentials Compose source');
		}
		if (credentials.AKB_UI_ADMIN_WEBROOT !== '/var/www/html') {
			fail('Unsafe local credentials webroot');
		}
		validateBaseUrl(credentials.AKB_UI_ADMIN_BASE_URL, environment);
		if (
			!/^akb_ui_local_[0-9]{14}_[a-f0-9]{12}$/u
				.test(credentials.AKB_UI_ADMIN_USERNAME)
		) {
			fail('Temporary username marker rejected');
		}
	} else {
		fail('Unsupported credentials schema');
	}
	if (!/^[a-f0-9]{64}$/u.test(credentials.AKB_UI_ADMIN_PASSWORD)) {
		fail('Password encoding rejected');
	}
	if (!/^[a-f0-9]{64}$/u.test(credentials.AKB_UI_ADMIN_TOKEN)) {
		fail('Marker token encoding rejected');
	}
	if (!/^[0-9]{8}T[0-9]{6}Z$/u.test(credentials.AKB_UI_ADMIN_CREATED_AT_UTC)) {
		fail('Creation timestamp rejected');
	}
	const stamp = credentials.AKB_UI_ADMIN_CREATED_AT_UTC.replace('T', '').slice(0, -1);
	const prefix = `akb_ui_${credentials.AKB_UI_ADMIN_ENV}_${stamp}_`;
	if (!credentials.AKB_UI_ADMIN_USERNAME.startsWith(prefix)) {
		fail('Temporary username is not bound to its environment and timestamp');
	}
	return credentials;
}

function browserFunctionSource({ baseUrl, username, password }) {
	return `async page => {
	const baseUrl = ${JSON.stringify(baseUrl)};
	const expectedUsername = ${JSON.stringify(username)};
	const password = ${JSON.stringify(password)};
	const expectedOrigin = baseUrl;
	const profileUrl = baseUrl + '/wp-admin/profile.php';
	const loginUrl = baseUrl + '/wp-login.php?redirect_to='
		+ encodeURIComponent(profileUrl);

	const assertOrigin = () => {
		const currentUrl = page.url();
		if (currentUrl !== expectedOrigin && !currentUrl.startsWith(expectedOrigin + '/')) {
			throw new Error('AUTH_ORIGIN_ESCAPE');
		}
	};
	const currentPath = () => page.url()
		.slice(expectedOrigin.length)
		.split(/[?#]/u, 1)[0];
	const challengePresent = async () => {
		const challengeSelectors = [
			'input[autocomplete="one-time-code"]',
			'input[name*="otp" i]',
			'input[name*="2fa" i]',
			'input[name*="two_factor" i]',
			'iframe[src*="captcha" i]',
			'.g-recaptcha',
			'[data-sitekey]',
		];
		for (const selector of challengeSelectors) {
			if (await page.locator(selector).count()) return true;
		}
		const bodyText = await page.locator('body').innerText().catch(() => '');
		return /(?:two[- ]factor|2fa|verification code|c[oó]digo de verificaci[oó]n|captcha)/iu
			.test(bodyText);
	};
	const failIfChallengeOrError = async () => {
		if (await challengePresent()) throw new Error('AUTH_CHALLENGE_DETECTED');
		if (await page.locator('#login_error, .login-error').count()) {
			throw new Error('LOGIN_REJECTED');
		}
	};

	await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
	assertOrigin();
	if (!/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
		throw new Error('LOGIN_SURFACE_REDIRECTED');
	}
	await failIfChallengeOrError();

	const userField = page.locator('#user_login');
	const passwordField = page.locator('#user_pass');
	const submit = page.locator('#wp-submit');
	if (
		await userField.count() !== 1
		|| await passwordField.count() !== 1
		|| await submit.count() !== 1
	) {
		throw new Error('LOGIN_CONTROLS_AMBIGUOUS');
	}
	await userField.fill(expectedUsername);
	await passwordField.fill(password);
	await Promise.all([
		page.waitForNavigation({
			waitUntil: 'domcontentloaded',
			timeout: 45000,
		}),
		submit.click(),
	]);
	assertOrigin();
	await failIfChallengeOrError();
	if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
		throw new Error('LOGIN_DID_NOT_COMPLETE');
	}
	if (!/\\/wp-admin(?:\\/|$)/u.test(currentPath())) {
		throw new Error('ADMIN_SURFACE_NOT_REACHED');
	}

	await page.goto(profileUrl, {
		waitUntil: 'domcontentloaded',
		timeout: 45000,
	});
	assertOrigin();
	await failIfChallengeOrError();
	if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
		throw new Error('AUTH_NOT_PERSISTED');
	}
	if (currentPath() !== '/wp-admin/profile.php') {
		throw new Error('PROFILE_SURFACE_NOT_REACHED');
	}
	const profileLogin = page.locator('#user_login');
	if (await profileLogin.count() !== 1) {
		throw new Error('PROFILE_IDENTITY_MISSING');
	}
	if (await profileLogin.inputValue() !== expectedUsername) {
		throw new Error('AUTH_IDENTITY_MISMATCH');
	}
	return {
		status: 'PASS',
		identity: expectedUsername,
		origin: expectedOrigin,
		surface: '/wp-admin/profile.php',
	};
}`;
}

function parseCliResult(stdout) {
	let outer;
	try {
		outer = JSON.parse(stdout);
	} catch {
		fail('Playwright CLI returned invalid JSON');
	}
	if (typeof outer?.result !== 'string') {
		const keys = outer && typeof outer === 'object'
			? Object.keys(outer).sort().join(',')
			: typeof outer;
		const errorCode = typeof outer?.error === 'string'
			? outer.error.replace(/[^A-Za-z0-9_.:-]/gu, '_').slice(0, 160)
			: '';
		fail(
			`Playwright CLI returned no serialized result (keys=${keys || 'none'}`
			+ `${errorCode ? `, error=${errorCode}` : ''})`,
		);
	}
	let result;
	try {
		result = JSON.parse(outer.result);
	} catch {
		fail('Playwright browser function returned invalid JSON');
	}
	return result;
}

function runLogin({ baseUrl, session, credentials }) {
	const runtimeDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'akibara-wp-login-'));
	fs.chmodSync(runtimeDirectory, 0o700);
	const functionPath = path.join(runtimeDirectory, 'browser-function.js');
	let cleaned = false;
	const cleanup = () => {
		if (cleaned) return;
		cleaned = true;
		try {
			if (fs.existsSync(functionPath)) fs.unlinkSync(functionPath);
		} finally {
			if (fs.existsSync(runtimeDirectory)) fs.rmdirSync(runtimeDirectory);
		}
	};
	const signalCleanup = (code) => {
		cleanup();
		process.exit(code);
	};
	const onSigint = () => signalCleanup(130);
	const onSigterm = () => signalCleanup(143);
	process.once('exit', cleanup);
	process.once('SIGINT', onSigint);
	process.once('SIGTERM', onSigterm);

	try {
		fs.writeFileSync(
			functionPath,
			`${browserFunctionSource({
				baseUrl,
				username: credentials.AKB_UI_ADMIN_USERNAME,
				password: credentials.AKB_UI_ADMIN_PASSWORD,
			})}\n`,
			{ mode: 0o600, flag: 'wx' },
		);
		if ((fs.statSync(functionPath).mode & 0o777) !== 0o600) {
			fail('Temporary browser function mode is not 0600');
		}
		const command = spawnSync(
			'npx',
			[
				'--yes',
				'--package',
				'@playwright/cli',
				'playwright-cli',
				`--session=${session}`,
				'--json',
				'run-code',
				`--filename=${functionPath}`,
			],
			{
				encoding: 'utf8',
				maxBuffer: 2 * 1024 * 1024,
				timeout: 120_000,
				shell: false,
				windowsHide: true,
			},
		);
		if (command.error || command.status !== 0) {
			fail(`Playwright CLI login failed (${command.status ?? 'spawn'})`);
		}
		const result = parseCliResult(command.stdout);
		if (
			result?.status !== 'PASS'
			|| result.identity !== credentials.AKB_UI_ADMIN_USERNAME
			|| result.origin !== baseUrl
			|| result.surface !== '/wp-admin/profile.php'
		) {
			fail('Playwright login result failed identity or surface verification');
		}
	} finally {
		process.removeListener('SIGINT', onSigint);
		process.removeListener('SIGTERM', onSigterm);
		process.removeListener('exit', cleanup);
		cleanup();
	}
}

try {
	const args = parseArgs(process.argv.slice(2));
	const session = validateSession(args.session);
	const credentialsPath = path.resolve(args.credentials);
	const credentials = parseCredentials(credentialsPath);
	const baseUrl = validateBaseUrl(args.baseUrl, credentials.AKB_UI_ADMIN_ENV);
	if (credentials.AKB_UI_ADMIN_BASE_URL !== baseUrl) {
		fail('Credentials base URL does not match --base-url');
	}
	runLogin({ baseUrl, session, credentials });
	process.stdout.write('PASS: WordPress UI session authenticated with exact temporary identity\n');
} catch (error) {
	process.stderr.write(`ERROR: ${error.message}\n`);
	process.exitCode = 1;
}
