#!/usr/bin/env node

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	sha256,
} from './lib/blog-seo-official-ui-manifest.mjs';
import {
	normalizeRobotsValue,
	validateRobotsManifest,
} from './lib/rankmath-robots-official-ui-manifest.mjs';

function fail(message) {
	throw new Error(`Rank Math robots official UI runner failed: ${message}`);
}

function value(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function has(name) {
	return process.argv.includes(name);
}

function localCheckpoint(input) {
	const file = path.resolve(input ?? '');
	const repository = path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
	const roots = ['.local-reports', '.local-checkpoints']
		.map((name) => path.join(repository, name));
	const inside = roots.some((root) => {
		const relative = path.relative(root, file);
		return relative && !relative.startsWith('..') && !path.isAbsolute(relative);
	});
	if (!inside) fail('checkpoint must remain in a local checkpoint directory');
	let cursor = file;
	while (cursor !== repository && cursor !== path.dirname(cursor)) {
		if (fs.existsSync(cursor) && fs.lstatSync(cursor).isSymbolicLink()) {
			fail('checkpoint path may not traverse symlinks');
		}
		cursor = path.dirname(cursor);
	}
	if (fs.existsSync(file)) {
		const stats = fs.lstatSync(file);
		if (
			!stats.isFile()
			|| stats.isSymbolicLink()
			|| (stats.mode & 0o777) !== 0o600
		) {
			fail('existing checkpoint must be a regular 0600 file');
		}
	}
	return file;
}

function writeCheckpoint(file, checkpoint) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(checkpoint, null, 2)}\n`, {
		mode: 0o600,
		flag: 'wx',
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function loadCheckpoint(file, manifest) {
	if (!fs.existsSync(file)) {
		return {
			schema_version: 1,
			manifest_sha256: manifest.manifest_sha256,
			environment: manifest.environment,
			target_origin: manifest.target_origin,
			events: {},
			created_at: new Date().toISOString(),
			updated_at: new Date().toISOString(),
		};
	}
	const checkpoint = JSON.parse(fs.readFileSync(file, 'utf8'));
	if (
		checkpoint.schema_version !== 1
		|| checkpoint.manifest_sha256 !== manifest.manifest_sha256
		|| checkpoint.environment !== manifest.environment
		|| checkpoint.target_origin !== manifest.target_origin
		|| typeof checkpoint.events !== 'object'
	) {
		fail('checkpoint belongs to another manifest or environment');
	}
	return checkpoint;
}

export function parseRobotsPlaywrightResult(stdout, stderr = '') {
	let outer;
	try {
		outer = JSON.parse(stdout);
	} catch {
		fail(`Playwright CLI returned malformed JSON: ${(stderr || stdout).slice(0, 500)}`);
	}
	if (outer?.isError === true || typeof outer?.error === 'string') {
		fail(
			`Playwright browser function failed: ${
				String(outer.error ?? 'unknown browser error').slice(0, 1000)
			}`
		);
	}
	if (typeof outer?.result !== 'string') {
		fail(`Playwright CLI returned no result: ${stdout.slice(0, 1000)}`);
	}
	try {
		return JSON.parse(outer.result);
	} catch {
		fail(`Playwright browser result was malformed: ${outer.result.slice(0, 500)}`);
	}
}

function runBrowser(session, source, runtime) {
	const script = path.join(runtime, `robots-${process.pid}-${Date.now()}.js`);
	fs.writeFileSync(script, `${source}\n`, { mode: 0o600, flag: 'wx' });
	const result = spawnSync(
		'npx',
		[
			'--yes',
			'--package',
			'@playwright/cli',
			'playwright-cli',
			`--session=${session}`,
			'--json',
			'run-code',
			`--filename=${script}`,
		],
		{
			cwd: runtime,
			encoding: 'utf8',
			timeout: 180_000,
			maxBuffer: 8 * 1024 * 1024,
		}
	);
	fs.rmSync(script, { force: true });
	if (result.error || result.status !== 0) {
		fail(
			`Playwright CLI failed: ${
				result.stderr || result.stdout || result.error?.message
			}`.slice(0, 1000)
		);
	}
	return parseRobotsPlaywrightResult(result.stdout, result.stderr);
}

export function robotsBrowserFunction({ baseUrl, manifest, phase }) {
	return `async page => {
		/* AKB_RANKMATH_ROBOTS_UI */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const manifest = ${JSON.stringify(manifest)};
		const phase = ${JSON.stringify(phase)};
		const target = baseUrl + manifest.edit_path;
		page.setDefaultNavigationTimeout(75000);
		async function navigate() {
			await page.goto(target, {
				waitUntil: 'domcontentloaded',
				timeout: 75000,
			});
			if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
				throw new Error('AUTH_REQUIRED');
			}
			if (
				!/[?&]page=rank-math-options-general(?:&|$)/u.test(page.url())
				|| !/[?&]view=robots(?:&|$)/u.test(page.url())
			) {
				throw new Error('RANK_MATH_ROBOTS_PATH_ESCAPE');
			}
		}
		async function read() {
			const field = page.locator('#robots_txt_content');
			if (await field.count() !== 1 || !await field.isVisible()) {
				throw new Error('RANK_MATH_ROBOTS_FIELD_MISSING');
			}
			const notice = String(await page.locator(
				'.rank-math-notice, .notice'
			).allTextContents()).replace(/\\s+/gu, ' ').trim();
			const locked = await field.isDisabled()
				|| await field.getAttribute('readonly') !== null;
			if (locked) {
				if (
					/robots\\.txt file is present in the root folder/iu.test(notice)
					|| /contents are locked/iu.test(notice)
					|| /archivo robots\\.txt.+carpeta ra[ií]z/iu.test(notice)
					|| /contenidos? est[aá]n bloqueados?/iu.test(notice)
				) {
					return {
						field,
						current: (await field.inputValue()).replace(/\\r\\n?/gu, '\\n'),
						blocked: 'PHYSICAL_ROBOTS_TXT_BLOCKS_RANK_MATH_UI',
					};
				}
				return {
					field,
					current: (await field.inputValue()).replace(/\\r\\n?/gu, '\\n'),
					blocked: 'RANK_MATH_ROBOTS_EDITOR_READONLY',
				};
			}
			return {
				field,
				current: (await field.inputValue()).replace(/\\r\\n?/gu, '\\n'),
				blocked: null,
			};
		}
		await navigate();
		let state = await read();
		const record = {
			key: 'rankmath:robots_txt_content',
			current: state.current,
		};
		if (state.blocked) {
			return { records: [record], blocked: state.blocked };
		}
		const contract = manifest.robots_txt_content;
		if (
			record.current !== contract.before
			&& record.current !== contract.after
		) {
			return { records: [record], blocked: 'ROBOTS_CONTENT_DRIFT' };
		}
		if (phase === 'apply' && record.current === contract.before) {
			await state.field.fill(contract.after);
			const save = page.locator(
				'button.rank-math-button.button-primary:visible'
			);
			if (await save.count() !== 1) {
				throw new Error('RANK_MATH_ROBOTS_SAVE_MISSING');
			}
			const [response] = await Promise.all([
				page.waitForResponse((candidate) => {
					const endpoint = baseUrl
						+ '/wp-json/rankmath/v1/updateSettings';
					const responseUrl = candidate.url();
					return candidate.request().method() === 'POST'
						&& (
							responseUrl === endpoint
							|| responseUrl.startsWith(endpoint + '?')
						);
				}, {
					timeout: 75000,
				}),
				save.click(),
			]);
			if (!response.ok()) {
				throw new Error(
					'RANK_MATH_ROBOTS_SAVE_HTTP_' + response.status()
				);
			}
			await navigate();
			state = await read();
			if (state.blocked) {
				return { records: [record], blocked: state.blocked };
			}
			record.current = state.current;
		}
		return { records: [record], blocked: null };
	}`;
}

function assertBrowserSource(source) {
	Function(`"use strict"; return (${source});`);
	if (
		/fetch\s*\(|(?:page|context)\.request\b|wp\.apiFetch\b|update_option\s*\(|\\$wpdb\s*->/iu
			.test(source)
	) {
		fail('browser function contains a prohibited direct channel');
	}
}

async function main() {
	const manifestPath = value('--manifest');
	if (!manifestPath) fail('--manifest is required');
	const manifest = validateRobotsManifest(
		JSON.parse(fs.readFileSync(path.resolve(manifestPath), 'utf8'))
	);
	const apply = has('--apply');
	const validateOnly = has('--validate-only');
	if (apply && validateOnly) fail('--apply conflicts with --validate-only');
	if (apply && manifest.environment !== 'production') {
		fail('--apply is available only for production manifests');
	}
	const source = robotsBrowserFunction({
		baseUrl: manifest.target_origin,
		manifest,
		phase: 'preflight',
	});
	assertBrowserSource(source);
	if (validateOnly) {
		console.log(JSON.stringify({
			result: 'PASS',
			mode: 'validate-only',
			environment: manifest.environment,
			manifest_sha256: manifest.manifest_sha256,
		}, null, 2));
		return;
	}
	if (value('--base-url') !== manifest.target_origin) {
		fail('--base-url must equal manifest target_origin');
	}
	const session = value('--session');
	if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/u.test(session ?? '')) {
		fail('unsafe or missing --session');
	}
	if (
		apply
		&& value('--confirm-manifest-sha256') !== manifest.manifest_sha256
	) {
		fail('--apply requires the reviewed --confirm-manifest-sha256');
	}
	const checkpointPath = localCheckpoint(value('--checkpoint'));
	const checkpoint = loadCheckpoint(checkpointPath, manifest);
	const runtime = fs.mkdtempSync(path.join(os.tmpdir(), 'akb-robots-ui-'));
	fs.chmodSync(runtime, 0o700);
	try {
		for (const phase of apply ? ['preflight', 'apply'] : ['preflight']) {
			const result = runBrowser(
				session,
				robotsBrowserFunction({
					baseUrl: manifest.target_origin,
					manifest,
					phase,
				}),
				runtime
			);
			const current = normalizeRobotsValue(result.records?.[0]?.current ?? '');
			const observed = sha256(current);
			const contract = manifest.robots_txt_content;
			let status = 'drift';
			if (observed === contract.after_sha256) {
				status = phase === 'apply' ? 'applied' : 'already_after';
			} else if (observed === contract.before_sha256) {
				status = 'verified_before';
			}
			checkpoint.events[phase] = {
				status,
				observed_sha256: observed,
				observed_at: new Date().toISOString(),
				blocked: result.blocked ?? null,
			};
			checkpoint.updated_at = new Date().toISOString();
			writeCheckpoint(checkpointPath, checkpoint);
			if (result.blocked) fail(result.blocked);
			if (status === 'drift') fail('robots content drifted from both hashes');
		}
	} finally {
		fs.rmSync(runtime, { recursive: true, force: true });
	}
	console.log(JSON.stringify({
		result: 'PASS',
		mode: apply ? 'apply' : 'preflight',
		environment: manifest.environment,
		manifest_sha256: manifest.manifest_sha256,
		checkpoint: checkpointPath,
		status: checkpoint.events[apply ? 'apply' : 'preflight'].status,
	}, null, 2));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	main().catch((error) => {
		process.stderr.write(`${error.message}\n`);
		process.exitCode = 1;
	});
}
