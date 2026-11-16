#!/usr/bin/env node

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import {
	classifyPair,
	sha256,
	validateAuthorTermManifest,
} from './lib/author-term-slug-official-ui-manifest.mjs';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(scriptDirectory);
const checkpointRoot = path.join(
	repoRoot,
	'.local-reports',
	'author-term-slug-official-ui',
);
const preflightMaximumAgeMs = 30 * 60 * 1000;

function fail(message) {
	throw new Error(`Author term official UI runner failed: ${message}`);
}

function usage() {
	process.stdout.write(`Usage:
  node ops/run-author-term-slug-official-ui-playwright.mjs \\
    --manifest ops/author-term-slug-official-ui-manifest.json \\
    --environment docker|production \\
    --base-url URL \\
    --session SESSION \\
    --checkpoint .local-reports/author-term-slug-official-ui/RUN/checkpoint.json \\
    --preflight|--apply|--verify

  node ops/run-author-term-slug-official-ui-playwright.mjs \\
    --manifest ops/author-term-slug-official-ui-manifest.json \\
    --validate-only

Writes are performed only in production by submitting the official WordPress
term editor. Docker is limited to validation, preflight and verification.
The runner does not use REST, admin-ajax, SQL, WP-CLI writes, or remote files.
`);
}

function parseArguments(argv) {
	const parsed = {
		manifest: '',
		environment: '',
		baseUrl: '',
		session: '',
		checkpoint: '',
		phase: '',
	};
	const valueOptions = {
		'--manifest': 'manifest',
		'--environment': 'environment',
		'--base-url': 'baseUrl',
		'--session': 'session',
		'--checkpoint': 'checkpoint',
	};
	const phaseOptions = new Map([
		['--preflight', 'preflight'],
		['--apply', 'apply'],
		['--verify', 'verify'],
		['--validate-only', 'validate-only'],
	]);
	for (let index = 0; index < argv.length; index += 1) {
		const argument = argv[index];
		if (argument === '--help' || argument === '-h') {
			usage();
			process.exit(0);
		}
		if (phaseOptions.has(argument)) {
			if (parsed.phase) fail('exactly one phase is required');
			parsed.phase = phaseOptions.get(argument);
			continue;
		}
		const key = valueOptions[argument];
		if (!key) fail(`unknown argument ${argument}`);
		if (parsed[key]) fail(`duplicate argument ${argument}`);
		index += 1;
		if (index >= argv.length || argv[index].startsWith('--')) {
			fail(`${argument} requires a value`);
		}
		parsed[key] = argv[index];
	}
	if (!parsed.manifest) fail('--manifest is required');
	if (!parsed.phase) fail('one phase is required');
	if (parsed.phase !== 'validate-only') {
		for (const key of ['environment', 'baseUrl', 'session', 'checkpoint']) {
			if (!parsed[key]) fail(`--${key.replace(/[A-Z]/gu, letter => `-${letter.toLowerCase()}`)} is required`);
		}
	}
	return parsed;
}

function assertRegularNonSymlink(filePath, label) {
	const stats = fs.lstatSync(filePath);
	if (!stats.isFile() || stats.isSymbolicLink()) {
		fail(`${label} must be a regular non-symlink file`);
	}
}

function fileMode(filePath) {
	return fs.statSync(filePath).mode & 0o777;
}

function assertSafeCheckpointPath(inputPath) {
	const resolved = path.resolve(inputPath);
	const relative = path.relative(checkpointRoot, resolved);
	if (
		relative === ''
		|| relative.startsWith(`..${path.sep}`)
		|| relative === '..'
		|| path.isAbsolute(relative)
		|| path.basename(resolved) !== 'checkpoint.json'
	) {
		fail(`checkpoint must be a checkpoint.json below ${checkpointRoot}`);
	}
	fs.mkdirSync(path.dirname(resolved), { recursive: true, mode: 0o700 });
	let current = checkpointRoot;
	for (const component of relative.split(path.sep).slice(0, -1)) {
		current = path.join(current, component);
		const stats = fs.lstatSync(current);
		if (!stats.isDirectory() || stats.isSymbolicLink()) {
			fail('checkpoint parent contains a non-directory or symlink');
		}
	}
	return resolved;
}

function validateSession(value) {
	if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/u.test(value)) {
		fail('unsafe Playwright session name');
	}
	return value;
}

function validateEnvironment(manifest, environment, baseUrl) {
	const policy = manifest.environments[environment];
	if (!policy) fail('environment must be docker or production');
	if (baseUrl !== policy.base_url) {
		fail(`${environment} base URL must be exactly ${policy.base_url}`);
	}
	return policy;
}

function writeAtomic(filePath, value) {
	const temporary = `${filePath}.tmp-${process.pid}`;
	fs.writeFileSync(
		temporary,
		`${JSON.stringify(value, null, 2)}\n`,
		{ mode: 0o600, flag: 'wx' },
	);
	fs.renameSync(temporary, filePath);
	if (fileMode(filePath) !== 0o600) {
		fail('checkpoint mode is not 0600');
	}
}

function parseCliResult(stdout) {
	let outer;
	try {
		outer = JSON.parse(stdout);
	} catch {
		fail('Playwright CLI returned invalid JSON');
	}
	if (typeof outer?.result !== 'string') {
		fail('Playwright CLI returned no serialized result');
	}
	try {
		return JSON.parse(outer.result);
	} catch {
		fail('browser function returned invalid JSON');
	}
}

function runBrowserFunction(session, source) {
	const runtimeDirectory = fs.mkdtempSync(
		path.join(os.tmpdir(), 'akibara-author-term-ui-'),
	);
	fs.chmodSync(runtimeDirectory, 0o700);
	const functionPath = path.join(runtimeDirectory, 'browser-function.js');
	try {
		fs.writeFileSync(functionPath, `${source}\n`, {
			mode: 0o600,
			flag: 'wx',
		});
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
				maxBuffer: 8 * 1024 * 1024,
				timeout: 360_000,
				shell: false,
				windowsHide: true,
			},
		);
		if (command.error || command.status !== 0) {
			fail(`Playwright CLI failed (${command.status ?? 'spawn'})`);
		}
		return parseCliResult(command.stdout);
	} finally {
		fs.rmSync(runtimeDirectory, { recursive: true, force: true });
	}
}

function browserHelpersSource() {
	return `
		const assertOrigin = () => {
			const current = page.url();
			if (current !== baseUrl && !current.startsWith(baseUrl + '/')) {
				throw new Error('AUTHOR_TERM_ORIGIN_ESCAPE');
			}
		};
		const navigate = async target => {
			for (let attempt = 1; attempt <= 2; attempt += 1) {
				try {
					const response = await page.goto(target, {
						waitUntil: 'domcontentloaded',
						timeout: 75000,
					});
					assertOrigin();
					return response;
				} catch (error) {
					if (attempt === 2 || !/Timeout/iu.test(String(error))) {
						throw error;
					}
					await page.waitForTimeout(1250);
				}
			}
			throw new Error('AUTHOR_TERM_NAVIGATION_EXHAUSTED');
		};
		const inspectEditor = async term => {
			const target = baseUrl + term.ui_path;
			await navigate(target);
			if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
				throw new Error('AUTHOR_TERM_AUTH_REQUIRED');
			}
			if (!page.url().startsWith(target)) {
				throw new Error('AUTHOR_TERM_EDITOR_URL_MISMATCH:' + term.term_id);
			}
			const form = page.locator('form#edittag');
			const name = form.locator('#name');
			const slug = form.locator('#slug');
			const action = form.locator('input[name="action"]');
			const termId = form.locator('input[name="tag_ID"]');
			const taxonomy = form.locator('input[name="taxonomy"]');
			const save = form.locator('input[type="submit"].button-primary');
			if (
				await form.count() !== 1
				|| await name.count() !== 1
				|| await slug.count() !== 1
				|| await action.count() !== 1
				|| await termId.count() !== 1
				|| await taxonomy.count() !== 1
				|| await save.count() !== 1
				|| await action.inputValue() !== 'editedtag'
				|| await termId.inputValue() !== String(term.term_id)
				|| await taxonomy.inputValue() !== 'pa_autor'
			) {
				throw new Error('AUTHOR_TERM_EDITOR_CONTRACT_MISMATCH:' + term.term_id);
			}
			return {
				name: await name.inputValue(),
				slug: await slug.inputValue(),
			};
		};
		const inspectPublic = async slug => {
			const target = baseUrl + '/autor/' + slug + '/';
			const response = await navigate(target);
			const canonical = page.locator('link[rel="canonical"]');
			const robots = page.locator('meta[name="robots"]');
			return {
				target,
				status: response ? response.status() : 0,
				effective_url: page.url(),
				canonical_count: await canonical.count(),
				canonical: await canonical.count() === 1
					? await canonical.getAttribute('href')
					: null,
				robots: await robots.evaluateAll(elements =>
					elements.map(element => element.getAttribute('content') || '')
				),
			};
		};`;
}

function observeTermsFunction({ baseUrl, terms }) {
	return `async page => {
		const operation = 'AUTHOR_TERM_OBSERVE';
		const baseUrl = ${JSON.stringify(baseUrl)};
		const terms = ${JSON.stringify(terms)};
		void operation;
		await page.setViewportSize({ width: 1440, height: 1000 });
		page.setDefaultNavigationTimeout(75000);
		${browserHelpersSource()}
		const records = [];
		for (const term of terms) {
			const current = await inspectEditor(term);
			const currentPublic = await inspectPublic(current.slug);
			const desiredPublic = current.slug === term.after.slug
				? currentPublic
				: await inspectPublic(term.after.slug);
			records.push({
				term_id: term.term_id,
				current,
				current_public: currentPublic,
				desired_public: desiredPublic,
			});
		}
		return { records };
	}`;
}

function applyTermFunction({ baseUrl, term }) {
	return `async page => {
		const operation = 'AUTHOR_TERM_APPLY';
		const baseUrl = ${JSON.stringify(baseUrl)};
		const term = ${JSON.stringify(term)};
		void operation;
		await page.setViewportSize({ width: 1440, height: 1000 });
		page.setDefaultNavigationTimeout(75000);
		${browserHelpersSource()}
		const initial = await inspectEditor(term);
		const before = initial.name === term.before.name
			&& initial.slug === term.before.slug;
		const after = initial.name === term.after.name
			&& initial.slug === term.after.slug;
		if (!before && !after) {
			return {
				blocked: 'AUTHOR_TERM_DRIFT_BEFORE_SAVE',
				term_id: term.term_id,
				current: initial,
			};
		}
		let writePerformed = false;
		if (before) {
			const form = page.locator('form#edittag');
			await form.locator('#name').fill(term.after.name);
			await form.locator('#slug').fill(term.after.slug);
			const save = form.locator('input[type="submit"].button-primary');
			const [response] = await Promise.all([
				page.waitForResponse(candidate =>
					candidate.request().method() === 'POST'
					&& candidate.request().isNavigationRequest()
					&& candidate.url().startsWith(baseUrl + '/wp-admin/edit-tags.php'),
				{ timeout: 75000 }),
				save.click(),
			]);
			const location = response.headers()['location'] || '';
			const acceptedLocation = location.startsWith('/wp-admin/term.php')
				|| location.startsWith(baseUrl + '/wp-admin/term.php');
			if (![302, 303].includes(response.status()) || !acceptedLocation) {
				throw new Error(
					'AUTHOR_TERM_SAVE_RESPONSE_REJECTED:' + term.term_id
					+ ':' + response.status()
				);
			}
			await page.waitForLoadState('domcontentloaded', { timeout: 75000 });
			writePerformed = true;
		}
		const persisted = await inspectEditor(term);
		if (
			persisted.name !== term.after.name
			|| persisted.slug !== term.after.slug
		) {
			throw new Error('AUTHOR_TERM_SAVE_NOT_PERSISTED:' + term.term_id);
		}
		const desiredPublic = await inspectPublic(term.after.slug);
		return {
			blocked: null,
			term_id: term.term_id,
			current: persisted,
			desired_public: desiredPublic,
			write_performed: writePerformed,
		};
	}`;
}

function assertBrowserFunctionSyntax(source, label) {
	try {
		Function(`"use strict"; return (${source});`);
	} catch (error) {
		fail(`${label} browser function is invalid: ${error.message}`);
	}
	if (
		/fetch\s*\(|XMLHttpRequest|wp-json|admin-ajax|wp_update_term|wp\s+term|(?:INSERT|UPDATE|DELETE)\s+(?:INTO|wp_)/iu
			.test(source)
	) {
		fail(`${label} contains a prohibited direct-write channel`);
	}
}

function pairDigest(pair) {
	return sha256(JSON.stringify({ name: pair.name, slug: pair.slug }));
}

function validatePublicRecord(publicRecord, expectedUrl, {
	expectedStatus,
	requireCanonical,
	requireIndexable,
	label,
}) {
	if (
		!publicRecord
		|| publicRecord.target !== expectedUrl
		|| publicRecord.status !== expectedStatus
		|| publicRecord.effective_url !== expectedUrl
	) {
		fail(`${label} has unexpected public status or effective URL`);
	}
	if (
		requireCanonical
		&& (
			publicRecord.canonical_count !== 1
			|| publicRecord.canonical !== expectedUrl
		)
	) {
		fail(`${label} has no unique self-canonical`);
	}
	if (
		requireIndexable
		&& Array.isArray(publicRecord.robots)
		&& publicRecord.robots.some(value => /(?:^|,|\s)noindex(?:,|\s|$)/iu.test(value))
	) {
		fail(`${label} is noindex in an indexable environment`);
	}
}

function validateObservationRecords({
	records,
	manifest,
	environment,
	policy,
	requireAfter = false,
}) {
	if (!Array.isArray(records) || records.length !== manifest.terms.length) {
		const observed = Array.isArray(records)
			? `${records.length} records`
			: typeof records;
		fail(`browser did not return exactly three term observations (got ${observed})`);
	}
	const byId = new Map();
	for (const record of records) {
		if (
			!Number.isSafeInteger(record?.term_id)
			|| byId.has(record.term_id)
		) {
			fail('browser returned an invalid or duplicate term_id');
		}
		byId.set(record.term_id, record);
	}
	const events = {};
	for (const term of manifest.terms) {
		const record = byId.get(term.term_id);
		if (!record) fail(`term ${term.term_id} was not observed`);
		const state = classifyPair(term, record.current);
		if (
			state === 'drift'
			|| !policy.allowed_preflight_states.includes(state)
			|| (requireAfter && state !== 'after')
		) {
			fail(`term ${term.term_id} is in disallowed state ${state}`);
		}
		const currentUrl = `${policy.base_url}/autor/${record.current.slug}/`;
		validatePublicRecord(record.current_public, currentUrl, {
			expectedStatus: 200,
			requireCanonical: true,
			requireIndexable: policy.public_archives_indexable,
			label: `term ${term.term_id} current archive`,
		});
		const desiredUrl = `${policy.base_url}/autor/${term.after.slug}/`;
		validatePublicRecord(record.desired_public, desiredUrl, {
			expectedStatus: state === 'after' ? 200 : 404,
			requireCanonical: state === 'after',
			requireIndexable:
				state === 'after' && policy.public_archives_indexable,
			label: `term ${term.term_id} desired archive`,
		});
		events[String(term.term_id)] = {
			state,
			name_sha256: sha256(record.current.name),
			slug_sha256: sha256(record.current.slug),
			pair_sha256: pairDigest(record.current),
			relationship_count_expected: term.expected_relationship_count,
			observed_at: new Date().toISOString(),
		};
	}
	return events;
}

function observationRecords(result) {
	if (
		!result
		|| typeof result !== 'object'
		|| Array.isArray(result)
		|| !Object.hasOwn(result, 'records')
	) {
		const shape = result && typeof result === 'object'
			? Object.keys(result).sort().join(',') || 'empty-object'
			: typeof result;
		fail(`browser observation result has unexpected shape (${shape})`);
	}
	return result.records;
}

function checkpointBase({
	manifestSha256,
	environment,
	baseUrl,
	events,
	status,
}) {
	const now = new Date().toISOString();
	return {
		schema_version: 1,
		plan_type: 'akibara_author_term_slug_official_ui',
		environment,
		base_url: baseUrl,
		manifest_sha256: manifestSha256,
		status,
		created_at: now,
		updated_at: now,
		events,
	};
}

function loadApplyCheckpoint({
	checkpointPath,
	manifestSha256,
	environment,
	baseUrl,
	manifest,
}) {
	if (!fs.existsSync(checkpointPath)) {
		fail('apply requires a completed preflight checkpoint');
	}
	assertRegularNonSymlink(checkpointPath, 'checkpoint');
	if (fileMode(checkpointPath) !== 0o600) {
		fail('checkpoint mode must be exactly 0600');
	}
	const checkpoint = JSON.parse(fs.readFileSync(checkpointPath, 'utf8'));
	if (
		checkpoint?.schema_version !== 1
		|| checkpoint.plan_type !== 'akibara_author_term_slug_official_ui'
		|| checkpoint.environment !== environment
		|| checkpoint.base_url !== baseUrl
		|| checkpoint.manifest_sha256 !== manifestSha256
		|| checkpoint.status !== 'preflight_pass'
		|| !checkpoint.events
		|| typeof checkpoint.events !== 'object'
	) {
		fail('checkpoint is not a matching completed preflight');
	}
	const updatedAt = Date.parse(checkpoint.updated_at);
	if (
		!Number.isFinite(updatedAt)
		|| Date.now() - updatedAt < 0
		|| Date.now() - updatedAt > preflightMaximumAgeMs
	) {
		fail('preflight checkpoint is stale');
	}
	for (const term of manifest.terms) {
		const event = checkpoint.events[String(term.term_id)];
		if (
			!event
			|| !['before', 'after'].includes(event.state)
			|| !/^[a-f0-9]{64}$/u.test(event.pair_sha256)
		) {
			fail(`checkpoint event is invalid for term ${term.term_id}`);
		}
	}
	return checkpoint;
}

function compareApplyGuard(events, checkpoint, manifest) {
	for (const term of manifest.terms) {
		const key = String(term.term_id);
		if (
			events[key].state !== checkpoint.events[key].state
			|| events[key].pair_sha256 !== checkpoint.events[key].pair_sha256
		) {
			fail(`term ${term.term_id} changed after preflight; no writes started`);
		}
	}
}

function validateAppliedTerm(result, term, policy) {
	if (
		result?.blocked
		|| result?.term_id !== term.term_id
		|| classifyPair(term, result.current) !== 'after'
		|| typeof result.write_performed !== 'boolean'
	) {
		fail(`term ${term.term_id} did not reach its exact after state`);
	}
	const desiredUrl = `${policy.base_url}/autor/${term.after.slug}/`;
	validatePublicRecord(result.desired_public, desiredUrl, {
		expectedStatus: 200,
		requireCanonical: true,
		requireIndexable: policy.public_archives_indexable,
		label: `term ${term.term_id} applied archive`,
	});
	return {
		state: 'after',
		write_performed: result.write_performed,
		name_sha256: sha256(result.current.name),
		slug_sha256: sha256(result.current.slug),
		pair_sha256: pairDigest(result.current),
		relationship_count_expected: term.expected_relationship_count,
		observed_at: new Date().toISOString(),
	};
}

function main() {
	const args = parseArguments(process.argv.slice(2));
	const manifestPath = path.resolve(args.manifest);
	assertRegularNonSymlink(manifestPath, 'manifest');
	const manifestText = fs.readFileSync(manifestPath, 'utf8');
	const manifest = validateAuthorTermManifest(JSON.parse(manifestText));
	const manifestSha256 = sha256(manifestText);
	const observeDocker = observeTermsFunction({
		baseUrl: manifest.environments.docker.base_url,
		terms: manifest.terms,
	});
	const applyDocker = applyTermFunction({
		baseUrl: manifest.environments.docker.base_url,
		term: manifest.terms[0],
	});
	assertBrowserFunctionSyntax(observeDocker, 'observe');
	assertBrowserFunctionSyntax(applyDocker, 'apply');
	if (args.phase === 'validate-only') {
		process.stdout.write(
			`PASS: exact 3-term manifest and official-UI browser functions (${manifestSha256})\n`,
		);
		return;
	}

	const policy = validateEnvironment(
		manifest,
		args.environment,
		args.baseUrl,
	);
	if (args.phase === 'apply' && args.environment !== 'production') {
		fail('--apply is available only for production');
	}
	const session = validateSession(args.session);
	const checkpointPath = assertSafeCheckpointPath(args.checkpoint);
	const observe = () => runBrowserFunction(
		session,
		observeTermsFunction({
			baseUrl: args.baseUrl,
			terms: manifest.terms,
		}),
	);

	if (args.phase === 'preflight') {
		const result = observe();
		const events = validateObservationRecords({
			records: observationRecords(result),
			manifest,
			environment: args.environment,
			policy,
		});
		const checkpoint = checkpointBase({
			manifestSha256,
			environment: args.environment,
			baseUrl: args.baseUrl,
			events,
			status: 'preflight_pass',
		});
		writeAtomic(checkpointPath, checkpoint);
		process.stdout.write(
			`PASS: ${args.environment} author-term preflight 3/3; no writes\n`,
		);
		return;
	}

	if (args.phase === 'verify') {
		const result = observe();
		const events = validateObservationRecords({
			records: observationRecords(result),
			manifest,
			environment: args.environment,
			policy,
			requireAfter: true,
		});
		const checkpoint = checkpointBase({
			manifestSha256,
			environment: args.environment,
			baseUrl: args.baseUrl,
			events,
			status: 'verify_pass',
		});
		writeAtomic(checkpointPath, checkpoint);
		process.stdout.write(
			`PASS: ${args.environment} author terms verified after 3/3\n`,
		);
		return;
	}

	const checkpoint = loadApplyCheckpoint({
		checkpointPath,
		manifestSha256,
		environment: args.environment,
		baseUrl: args.baseUrl,
		manifest,
	});
	const guardResult = observe();
	const guardEvents = validateObservationRecords({
		records: observationRecords(guardResult),
		manifest,
		environment: args.environment,
		policy,
	});
	compareApplyGuard(guardEvents, checkpoint, manifest);
	checkpoint.status = 'applying';
	checkpoint.updated_at = new Date().toISOString();
	writeAtomic(checkpointPath, checkpoint);
	for (const term of manifest.terms) {
		const result = runBrowserFunction(
			session,
			applyTermFunction({ baseUrl: args.baseUrl, term }),
		);
		checkpoint.events[String(term.term_id)] = validateAppliedTerm(
			result,
			term,
			policy,
		);
		checkpoint.updated_at = new Date().toISOString();
		writeAtomic(checkpointPath, checkpoint);
	}
	const finalResult = observe();
	checkpoint.events = validateObservationRecords({
		records: observationRecords(finalResult),
		manifest,
		environment: args.environment,
		policy,
		requireAfter: true,
	});
	checkpoint.status = 'apply_pass';
	checkpoint.updated_at = new Date().toISOString();
	writeAtomic(checkpointPath, checkpoint);
	process.stdout.write(
		`PASS: ${args.environment} author terms applied and verified 3/3\n`,
	);
}

try {
	main();
} catch (error) {
	process.stderr.write(`ERROR: ${error.message}\n`);
	process.exitCode = 1;
}
