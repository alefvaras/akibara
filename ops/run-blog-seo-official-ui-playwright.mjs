#!/usr/bin/env node

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	flattenManifestItems,
	normalizeEditorialValue,
	sha256,
	validateManifest,
} from './lib/blog-seo-official-ui-manifest.mjs';
import {
	validateBlogSeoMediaTransaction,
} from './lib/blog-seo-media-transaction.mjs';
import {
	ensureWordPressBlockEditorField,
} from './lib/wordpress-block-editor-official-ui.mjs';

function fail(message) {
	throw new Error(`Blog SEO official UI runner failed: ${message}`);
}

function optionValue(name, fallback = undefined) {
	const index = process.argv.indexOf(name);
	return index === -1 ? fallback : process.argv[index + 1];
}

function hasOption(name) {
	return process.argv.includes(name);
}

function validateBaseUrl(value, manifest) {
	let parsed;
	try {
		parsed = new URL(value);
	} catch {
		fail(`invalid --base-url ${value}`);
	}
	if (parsed.origin !== value || value !== manifest.target_origin) {
		fail('--base-url must equal the reviewed manifest target_origin');
	}
	if (
		manifest.environment === 'docker'
		&& !['localhost', '127.0.0.1'].includes(parsed.hostname)
	) {
		fail('Docker manifest may run only against localhost');
	}
	if (
		manifest.environment === 'production'
		&& !['akibara.cl', 'www.akibara.cl'].includes(parsed.hostname)
	) {
		fail('production manifest requires the Akibara production hostname');
	}
	return parsed.origin;
}

function validateSession(value) {
	if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/u.test(value ?? '')) {
		fail('unsafe or missing --session');
	}
	return value;
}

function isInside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== '' && !relative.startsWith(`..${path.sep}`)
		&& relative !== '..' && !path.isAbsolute(relative);
}

function validateLocalCheckpointPath(value) {
	const checkpoint = path.resolve(value ?? '');
	const repositoryRoot = path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
	const allowedRoots = [
		path.join(repositoryRoot, '.local-reports'),
		path.join(repositoryRoot, '.local-checkpoints'),
	];
	if (!allowedRoots.some((root) => isInside(checkpoint, root))) {
		fail(
			'checkpoint must remain under local .local-reports or .local-checkpoints'
		);
	}
	let current = checkpoint;
	while (current !== repositoryRoot && current !== path.dirname(current)) {
		if (fs.existsSync(current) && fs.lstatSync(current).isSymbolicLink()) {
			fail('checkpoint path may not traverse symlinks');
		}
		current = path.dirname(current);
	}
	if (fs.existsSync(checkpoint)) {
		const stats = fs.lstatSync(checkpoint);
		if (!stats.isFile() || stats.isSymbolicLink()) {
			fail('checkpoint must be a regular non-symlink file');
		}
		if ((stats.mode & 0o777) !== 0o600) {
			fail('existing checkpoint mode must be exactly 0600');
		}
	}
	return checkpoint;
}

function writeAtomic0600(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
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
			created_at: new Date().toISOString(),
			updated_at: new Date().toISOString(),
			events: {},
		};
	}
	const checkpoint = JSON.parse(fs.readFileSync(file, 'utf8'));
	if (
		checkpoint?.schema_version !== 1
		|| checkpoint.manifest_sha256 !== manifest.manifest_sha256
		|| checkpoint.environment !== manifest.environment
		|| checkpoint.target_origin !== manifest.target_origin
		|| !checkpoint.events
		|| typeof checkpoint.events !== 'object'
	) {
		fail('checkpoint belongs to another manifest, origin or environment');
	}
	return checkpoint;
}

function loadTransaction(file, manifest) {
	const transactionPath = validateLocalCheckpointPath(file);
	if (!fs.existsSync(transactionPath)) {
		fail('--transaction-manifest must be an existing local file');
	}
	const transaction = validateBlogSeoMediaTransaction(
		JSON.parse(fs.readFileSync(transactionPath, 'utf8'))
	);
	if (
		transaction.environment !== manifest.environment
		|| transaction.target_origin !== manifest.target_origin
		|| transaction.sources.seo_manifest_sha256
			!== manifest.manifest_sha256
	) {
		fail('transaction does not bind this SEO manifest and environment');
	}
	if (
		optionValue('--confirm-transaction-sha256')
			!== transaction.transaction_sha256
	) {
		fail(
			'transactional mode requires exact '
			+ '--confirm-transaction-sha256'
		);
	}
	return transaction;
}

function parseCliJson(stdout, stderr) {
	let outer;
	try {
		outer = JSON.parse(stdout);
	} catch {
		fail(`Playwright CLI did not return JSON: ${(stderr || stdout).slice(0, 500)}`);
	}
	if (typeof outer.result !== 'string') {
		fail(`Playwright CLI returned no result: ${stdout.slice(0, 500)}`);
	}
	try {
		return JSON.parse(outer.result);
	} catch {
		fail(`Playwright result was not JSON: ${outer.result.slice(0, 500)}`);
	}
}

function runPlaywrightFunction(session, functionSource, runtimeDir) {
	const functionPath = path.join(
		runtimeDir,
		`browser-function-${process.pid}-${Date.now()}.js`
	);
	fs.writeFileSync(functionPath, `${functionSource}\n`, {
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
			cwd: runtimeDir,
			encoding: 'utf8',
			maxBuffer: 32 * 1024 * 1024,
			timeout: 420_000,
		}
	);
	fs.rmSync(functionPath, { force: true });
	if (command.error || command.status !== 0) {
		fail(
			`Playwright CLI failed (${command.status ?? 'spawn'}): `
			+ `${(command.stderr || command.stdout || command.error?.message)
				.slice(0, 1000)}`
		);
	}
	return parseCliJson(command.stdout, command.stderr);
}

function acceptPendingBeforeUnload(session, runtimeDir) {
	spawnSync(
		'npx',
		[
			'--yes',
			'--package',
			'@playwright/cli',
			'playwright-cli',
			`--session=${session}`,
			'dialog-accept',
		],
		{
			cwd: runtimeDir,
			encoding: 'utf8',
			maxBuffer: 1024 * 1024,
			timeout: 30_000,
		}
	);
}

export function postEditorBrowserFunction({ baseUrl, post, phase }) {
	if (![
		'preflight',
		'apply',
		'apply_deferred_score',
		'verify_score',
		'rollback',
	].includes(phase)) {
		fail(`unsupported post phase ${phase}`);
	}
	return `async page => {
		/* AKB_BLOG_SEO_POST_UI */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const post = ${JSON.stringify(post)};
		const phase = ${JSON.stringify(phase)};
		const expectedPostStatus = post.post_status || 'publish';
		const ensureWordPressBlockEditorField = ${
			ensureWordPressBlockEditorField.toString()
		};
		const target = baseUrl + post.edit_path;
		page.setDefaultNavigationTimeout(75000);
		await page.setViewportSize({ width: 1440, height: 1000 });

		async function navigate() {
			for (let attempt = 1; attempt <= 2; attempt += 1) {
				try {
					await page.goto(target, {
						waitUntil: 'domcontentloaded',
						timeout: 75000,
					});
					break;
				} catch (error) {
					if (attempt === 2 || !/Timeout/iu.test(String(error))) {
						throw error;
					}
					await page.waitForTimeout(1500);
				}
			}
			if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
				throw new Error('AUTH_REQUIRED');
			}
			if (
				page.url() !== target
				&& !page.url().startsWith(target + '#')
			) {
				throw new Error('POST_EDITOR_PATH_ESCAPE');
			}
			await assertEditorStatus();
		}

		async function oneVisible(selectors, code) {
			const candidates = page.locator(selectors);
			const visible = [];
			for (let index = 0; index < await candidates.count(); index += 1) {
				const locator = candidates.nth(index);
				if (await locator.isVisible()) visible.push(locator);
			}
			if (visible.length !== 1) {
				throw new Error(code + ':' + visible.length);
			}
			return visible[0];
		}

		async function assertEditorStatus() {
			const primaryActionSelector = [
				'.editor-post-publish-button:visible',
				'.editor-post-publish-button__button:visible',
			].join(', ');
			await page.locator(primaryActionSelector)
				.waitFor({ state: 'visible', timeout: 30000 });
			const primary = await oneVisible(
				primaryActionSelector,
				'POST_PRIMARY_BUTTON_MISSING_OR_AMBIGUOUS'
			);
			const text = String(await primary.textContent());
			if (
				(expectedPostStatus === 'publish'
					&& !/Guardar|Save|Actualizar|Update/iu.test(text))
				|| (expectedPostStatus === 'draft'
					&& !/Publicar|Publish/iu.test(text))
			) {
				throw new Error('POST_STATUS_UI_MISMATCH');
			}
		}

		async function openRankMathGeneral() {
			let surface = page.locator(
				'.rank-math-sidebar-panel:visible, '
				+ '#rank_math_metabox .rank-math-tabs:visible'
			);
			if (await surface.count() === 0) {
				const toggles = page.locator([
					'button[aria-label="Rank Math" i]:visible',
					'button[aria-controls="rank-math:seo-by-rank-math-sidebar"]:visible',
				].join(', '));
				if (await toggles.count() !== 1) {
					throw new Error('RANK_MATH_TOGGLE_MISSING_OR_AMBIGUOUS');
				}
				await toggles.click();
				await page.waitForTimeout(300);
				surface = page.locator(
					'.rank-math-sidebar-panel:visible, '
					+ '#rank_math_metabox .rank-math-tabs:visible'
				);
			}
			if (await surface.count() !== 1) {
				throw new Error('RANK_MATH_SURFACE_MISSING_OR_AMBIGUOUS');
			}
			const generalTabs = surface.locator([
				'.rank-math-general-tab',
				'[role="tab"]',
			].join(', ')).filter({ hasText: /General/iu });
			if (await generalTabs.count() === 1
				&& await generalTabs.getAttribute('aria-selected') !== 'true') {
				await generalTabs.click();
			} else if (await generalTabs.count() > 1) {
				throw new Error('RANK_MATH_GENERAL_TAB_AMBIGUOUS');
			}
			await surface.locator('.rank-math-focus-keyword')
				.waitFor({ state: 'visible', timeout: 15000 });
			await surface.locator('.rank-math-edit-snippet')
				.waitFor({ state: 'visible', timeout: 15000 });
			return surface;
		}

		async function readFocusKeywords(surface) {
			const panel = surface.locator('.rank-math-focus-keyword');
			if (await panel.count() !== 1) {
				throw new Error('RANK_MATH_FOCUS_PANEL_MISSING');
			}
			return (await panel.locator('.tagify__tag-text').allTextContents())
				.map(value => value.trim())
				.filter(Boolean)
				.join(',');
		}

		async function writeFocusKeywords(surface, value) {
			const panel = surface.locator('.rank-math-focus-keyword');
			const remove = panel.locator('.tagify__tag__removeBtn');
			while (await remove.count() > 0) {
				await remove.last().click();
				await page.waitForTimeout(100);
			}
			const input = await oneVisible(
				'.rank-math-focus-keyword input.tagify__input, '
				+ '.rank-math-focus-keyword [contenteditable="true"]',
				'RANK_MATH_FOCUS_INPUT_MISSING_OR_AMBIGUOUS'
			);
			for (const keyword of value.split(',')) {
				await input.fill(keyword);
				await input.press('Enter');
				await page.waitForTimeout(150);
			}
			const persistedInUi = await readFocusKeywords(surface);
			if (persistedInUi !== value) {
				throw new Error('RANK_MATH_FOCUS_UI_WRITE_MISMATCH');
			}
		}

		async function snippetFields(surface) {
			const editSnippet = surface.locator('.rank-math-edit-snippet');
			if (await editSnippet.count() !== 1) {
				throw new Error('RANK_MATH_SNIPPET_CONTROL_MISSING');
			}
			let title = page.locator('#rank-math-editor-title:visible');
			if (await title.count() === 0) {
				await editSnippet.click();
				await page.locator('#rank-math-editor-title:visible')
					.waitFor({ state: 'visible', timeout: 15000 });
				title = page.locator('#rank-math-editor-title:visible');
			}
			const description = page.locator(
				'#rank-math-editor-description:visible'
			);
			if (await title.count() !== 1 || await description.count() !== 1) {
				throw new Error('RANK_MATH_SNIPPET_FIELDS_MISSING_OR_AMBIGUOUS');
			}
			return { title, description };
		}

		async function closeSnippetIfOpen() {
			const dialog = page.locator(
				'.rank-math-modal[role="dialog"]:visible, '
				+ '.rank-math-modal-overlay [role="dialog"]:visible'
			);
			if (await dialog.count() === 0) return;
			if (await dialog.count() !== 1) {
				throw new Error('RANK_MATH_SNIPPET_DIALOG_AMBIGUOUS');
			}
			const close = dialog.locator([
				'button[aria-label="Cerrar"]:visible',
				'button[aria-label="Close"]:visible',
				'.components-modal__header button:visible',
			].join(', '));
			if (await close.count() !== 1) {
				throw new Error('RANK_MATH_SNIPPET_CLOSE_MISSING');
			}
			await close.click();
			await dialog.waitFor({ state: 'hidden', timeout: 10000 });
		}

		async function titleField() {
			const selectors = [
				'.editor-post-title__input textarea',
				'textarea[aria-label="Añadir título"]',
				'textarea[aria-label="Add title"]',
				'input.editor-post-title__input',
				'textarea.editor-post-title__input',
				'.editor-post-title__input[contenteditable="true"]',
			];
			const roots = [page];
			const canvas = page.locator('iframe[name="editor-canvas"]:visible');
			if (await canvas.count() > 1) {
				throw new Error('POST_EDITOR_CANVAS_AMBIGUOUS');
			}
			if (await canvas.count() === 1) {
				roots.push(canvas.contentFrame());
			}
			for (let poll = 0; poll < 60; poll += 1) {
				const visible = [];
				for (const root of roots) {
					const candidates = root.locator(selectors.join(', '));
					for (let index = 0; index < await candidates.count(); index += 1) {
						const locator = candidates.nth(index);
						if (await locator.isVisible()) visible.push(locator);
					}
				}
				if (visible.length === 1) return visible[0];
				if (visible.length > 1) {
					throw new Error('POST_TITLE_MISSING_OR_AMBIGUOUS:' + visible.length);
				}
				await page.waitForTimeout(500);
			}
			throw new Error('POST_TITLE_MISSING_OR_AMBIGUOUS:0');
		}

		async function controlValue(control) {
			return control.evaluate(element => (
				'string' === typeof element.value
					? element.value
					: element.textContent ?? ''
			));
		}

		async function wordpressField(field) {
			if (field === 'post_title') return titleField();
			return ensureWordPressBlockEditorField(page, {
				field,
				key: 'post:' + post.post_id + ':' + field,
			});
		}

		async function wordpressValue(field, locator) {
			const value = field === 'post_title'
				? await controlValue(locator)
				: await locator.inputValue();
			return value.replace(/\\r\\n?/gu, '\\n');
		}

		async function readState(writeTargetSide = null) {
			const wordpressValues = {};
			for (const field of [
				'post_title',
				'post_content',
				'post_excerpt',
			]) {
				const locator = await wordpressField(field);
				wordpressValues[field] = await wordpressValue(field, locator);
			}
			if (writeTargetSide !== null) {
				for (const field of [
					'post_title',
					'post_content',
					'post_excerpt',
				]) {
					/*
					 * Gutenberg remounts sibling controls after every fill.
					 * Reacquire and revalidate immediately before each write.
					 */
					const locator = await wordpressField(field);
					const current = await wordpressValue(field, locator);
					if (
						current !== post.fields[field].before
						&& current !== post.fields[field].after
					) {
						throw new Error('POST_FIELD_DRIFT_DURING_APPLY');
					}
					if (current !== post.fields[field][writeTargetSide]) {
						await locator.fill(post.fields[field][writeTargetSide]);
						await page.waitForTimeout(100);
					}
				}
			}
			const surface = await openRankMathGeneral();
			const focus = await readFocusKeywords(surface);
			const snippet = await snippetFields(surface);
			const rankMathValues = {
				rank_math_focus_keyword: focus.replace(/\\r\\n?/gu, '\\n'),
				rank_math_title: (await snippet.title.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
				rank_math_description: (await snippet.description.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
			};
			if (writeTargetSide !== null) {
				/*
				 * snippetFields opens a modal which intercepts the focus-keyword
				 * controls. Close it, then reacquire Rank Math before writing.
				 */
				await closeSnippetIfOpen();
				const writeSurface = await openRankMathGeneral();
				const liveFocus = await readFocusKeywords(writeSurface);
				if (
					liveFocus !== post.fields.rank_math_focus_keyword.before
					&& liveFocus !== post.fields.rank_math_focus_keyword.after
				) {
					throw new Error('POST_FIELD_DRIFT_DURING_APPLY');
				}
				if (
					liveFocus
						!== post.fields.rank_math_focus_keyword[writeTargetSide]
				) {
					await writeFocusKeywords(
						writeSurface,
						post.fields.rank_math_focus_keyword[writeTargetSide]
					);
				}
				for (const field of [
					'rank_math_title',
					'rank_math_description',
				]) {
					const liveSnippet = await snippetFields(writeSurface);
					const locator = field === 'rank_math_title'
						? liveSnippet.title
						: liveSnippet.description;
					const current = (await locator.inputValue())
						.replace(/\\r\\n?/gu, '\\n');
					if (
						current !== post.fields[field].before
						&& current !== post.fields[field].after
					) {
						throw new Error('POST_FIELD_DRIFT_DURING_APPLY');
					}
					if (current !== post.fields[field][writeTargetSide]) {
						await locator.fill(post.fields[field][writeTargetSide]);
						await page.waitForTimeout(100);
					}
				}
			}
			return {
				values: {
					...wordpressValues,
					...rankMathValues,
				},
			};
		}

		async function stableRankMathScore() {
			let previous = null;
			let stableSamples = 0;
			let stableSince = null;
			const samples = [];
			const minimumSettleMs = 6000;
			for (let attempt = 0; attempt < 80; attempt += 1) {
				const busy = await page.locator([
					'.components-spinner:visible',
					'.rank-math-loading:visible',
					'.rank-math-loader:visible',
					'.is-loading:visible',
				].join(', ')).count();
				const candidates = page.locator(
					'button[aria-label="Rank Math" i]:visible'
				);
				const scores = [];
				for (let index = 0; index < await candidates.count(); index += 1) {
					const candidate = candidates.nth(index);
					const text = [
						await candidate.textContent(),
						await candidate.getAttribute('aria-label'),
					].filter(Boolean).join(' ').replace(/\\s+/gu, ' ').trim();
					const match = /(?:^|\\D)(100|[0-9]{1,2})(?:\\s*\\/\\s*100)?(?:\\D|$)/u
						.exec(text);
					if (match) scores.push(Number(match[1]));
				}
				const unique = [...new Set(scores)];
				if (busy === 0 && unique.length === 1) {
					const score = unique[0];
					const sampledAt = Date.now();
					samples.push(score);
					if (score === previous) {
						stableSamples += 1;
					} else {
						stableSamples = 1;
						stableSince = sampledAt;
					}
					previous = score;
					const settledMs = stableSince === null
						? 0
						: sampledAt - stableSince;
					if (
						stableSamples >= 4
						&& settledMs >= minimumSettleMs
					) {
						return {
							score,
							samples: samples.slice(-12),
							settled_ms: settledMs,
						};
					}
				} else {
					stableSamples = 0;
					previous = null;
					stableSince = null;
				}
				await page.waitForTimeout(750);
			}
			throw new Error('RANK_MATH_SCORE_NOT_STABLE');
		}

		await navigate();
		let state = await readState();
		const records = Object.entries(state.values).map(([field, current]) => ({
			key: 'post:' + post.post_id + ':' + field,
			current,
		}));
		const drift = records.some(record => {
			const field = record.key.split(':').at(-1);
			const contract = post.fields[field];
			return record.current !== contract.before
				&& record.current !== contract.after;
		});
		if (drift) {
			return { records, blocked: 'POST_FIELD_DRIFT', score: null };
		}
		const actionable = records.filter(record => {
			const field = record.key.split(':').at(-1);
			return (
				['apply', 'apply_deferred_score'].includes(phase)
				&& record.current === post.fields[field].before
			) || (
				phase === 'rollback'
				&& record.current === post.fields[field].after
			);
		});
		if (actionable.length > 0) {
			await closeSnippetIfOpen();
			await navigate();
			state = await readState(
				phase === 'rollback' ? 'before' : 'after'
			);
			await closeSnippetIfOpen();
			const saveSelector = expectedPostStatus === 'draft'
				? '.editor-post-save-draft:visible'
				: [
					'.editor-post-publish-button:visible',
					'.editor-post-publish-button__button:visible',
				].join(', ');
			const save = await oneVisible(
				saveSelector,
				expectedPostStatus === 'draft'
					? 'POST_SAVE_DRAFT_BUTTON_MISSING_OR_AMBIGUOUS'
					: 'POST_UPDATE_BUTTON_MISSING_OR_AMBIGUOUS'
			);
			const saveText = String(await save.textContent());
			if (
				(expectedPostStatus === 'draft'
					&& !/Guardar borrador|Save draft/iu.test(saveText))
				|| (expectedPostStatus === 'publish'
					&& !/Guardar|Save|Actualizar|Update/iu.test(saveText))
			) {
				throw new Error('POST_SAVE_CONTROL_STATUS_MISMATCH');
			}
			await save.click();
			const saveDomSelector = saveSelector.replaceAll(':visible', '');
			await page.waitForFunction(
				(selector) => {
					const button = document.querySelector(selector);
					return button
						&& !button.classList.contains('is-busy')
						&& button.getAttribute('aria-disabled') !== 'true';
				},
				saveDomSelector,
				{ timeout: 45000 }
			);
			await navigate();
			state = await readState();
			for (const record of records) {
				const field = record.key.split(':').at(-1);
				record.current = state.values[field];
			}
		}
		await closeSnippetIfOpen();
		const allBefore = records.every(record => {
			const field = record.key.split(':').at(-1);
			return record.current === post.fields[field].before;
		});
		const allAfter = records.every(record => {
			const field = record.key.split(':').at(-1);
			return record.current === post.fields[field].after;
		});
		if (
			['apply', 'apply_deferred_score'].includes(phase)
			&& !allAfter
		) {
			return {
				records,
				blocked: 'POST_APPLY_VERIFY_FAILED',
				score: null,
			};
		}
		if (phase === 'rollback' && !allBefore) {
			return {
				records,
				blocked: 'ROLLBACK_FIELD_VERIFY_FAILED',
				score: null,
			};
		}
		if (phase === 'verify_score' && !allAfter) {
			return {
				records,
				blocked: 'POST_NOT_AT_REVIEWED_AFTER',
				score: null,
			};
		}
		const enforceScore = (
			phase === 'verify_score'
			|| phase === 'apply'
			|| (phase === 'preflight' && allAfter)
		);
		const stableScore = enforceScore
			? await stableRankMathScore()
			: null;
		if (
			enforceScore
			&& allAfter
			&& stableScore.score < post.minimum_rank_math_score
		) {
			return {
				records,
				blocked: 'RANK_MATH_SCORE_BELOW_TARGET',
				score: stableScore,
			};
		}
		return { records, blocked: null, score: stableScore };
	}`;
}

export function mediaAltBrowserFunction({ baseUrl, media, phase }) {
	if (![
		'preflight',
		'apply',
		'apply_deferred_score',
		'verify_score',
		'rollback',
	].includes(phase)) {
		fail(`unsupported media phase ${phase}`);
	}
	return `async page => {
		/* AKB_BLOG_SEO_MEDIA_UI */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const media = ${JSON.stringify(media)};
		const phase = ${JSON.stringify(phase)};
		const target = baseUrl + media.edit_path;
		page.setDefaultNavigationTimeout(75000);
		async function navigate() {
			await page.goto(target, {
				waitUntil: 'domcontentloaded',
				timeout: 75000,
			});
			if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
				throw new Error('AUTH_REQUIRED');
			}
			if (page.url() !== target && !page.url().startsWith(target + '#')) {
				throw new Error('MEDIA_EDITOR_PATH_ESCAPE');
			}
		}
		async function read() {
			const alt = page.locator('#attachment_alt:visible');
			if (await alt.count() !== 1) {
				throw new Error('MEDIA_ALT_FIELD_MISSING_OR_AMBIGUOUS');
			}
			const pageText = String(await page.locator('#post-body').innerText())
				.replace(/\\s+/gu, ' ');
			const source = page.locator(
				'#attachment_url, input[name="attachment_url"]'
			);
			const sourceValue = await source.count() === 1
				? await source.inputValue()
				: pageText;
			if (!String(sourceValue).includes(media.expected_filename)) {
				throw new Error('FEATURED_MEDIA_FILENAME_MISMATCH');
			}
			return {
				field: alt,
				current: (await alt.inputValue()).replace(/\\r\\n?/gu, '\\n'),
			};
		}
		await navigate();
		let state = await read();
		const record = {
			key: 'attachment:' + media.attachment_id + ':alt',
			current: state.current,
		};
		if (
			record.current !== media.before
			&& record.current !== media.after
		) {
			return { records: [record], blocked: 'MEDIA_ALT_DRIFT' };
		}
		const writeTargetSide = phase === 'rollback'
			? 'before'
			: ['apply', 'apply_deferred_score'].includes(phase)
				? 'after'
				: null;
		if (
			writeTargetSide !== null
			&& record.current !== media[writeTargetSide]
		) {
			await state.field.fill(media[writeTargetSide]);
			const save = page.locator('#publish:visible');
			if (await save.count() !== 1) {
				throw new Error('MEDIA_UPDATE_BUTTON_MISSING_OR_AMBIGUOUS');
			}
			await Promise.all([
				page.waitForNavigation({
					waitUntil: 'domcontentloaded',
					timeout: 75000,
				}),
				save.click(),
			]);
			await navigate();
			state = await read();
			record.current = state.current;
		}
		if (
			writeTargetSide !== null
			&& record.current !== media[writeTargetSide]
		) {
			return {
				records: [record],
				blocked: phase === 'rollback'
					? 'MEDIA_ALT_ROLLBACK_VERIFY_FAILED'
					: 'MEDIA_ALT_APPLY_VERIFY_FAILED',
			};
		}
		if (phase === 'verify_score' && record.current !== media.after) {
			return {
				records: [record],
				blocked: 'MEDIA_ALT_NOT_AT_REVIEWED_AFTER',
			};
		}
		return { records: [record], blocked: null };
	}`;
}

function assertBrowserFunctionSyntax(source, label) {
	try {
		Function(`"use strict"; return (${source});`);
	} catch (error) {
		fail(`${label} browser function is invalid: ${error.message}`);
	}
	if (
		/fetch\s*\(|wp-json|admin-ajax\.php|update_post_meta|update_option|\\$wpdb/iu
			.test(source)
	) {
		fail(`${label} browser function contains a prohibited direct channel`);
	}
}

function recordResult({
	checkpoint,
	itemsByKey,
	result,
	phase,
	score = null,
}) {
	for (const record of result.records ?? []) {
		const item = itemsByKey.get(record.key);
		if (!item) fail(`browser returned unknown key ${record.key}`);
		const current = normalizeEditorialValue(record.current);
		const observedSha256 = sha256(current);
		let status = 'drift';
		if (observedSha256 === item.after_sha256) {
			status = {
				apply: 'applied',
				apply_deferred_score: 'staged_before_media',
				verify_score: 'verified_after',
			}[phase] ?? 'already_after';
		} else if (observedSha256 === item.before_sha256) {
			status = phase === 'rollback'
				? 'rolled_back'
				: 'verified_before';
		}
		checkpoint.events[record.key] ??= {};
		checkpoint.events[record.key][phase] = {
			status,
			observed_sha256: observedSha256,
			expected_before_sha256: item.before_sha256,
			expected_after_sha256: item.after_sha256,
			observed_at: new Date().toISOString(),
			...(score ? { stable_rank_math_score: score } : {}),
		};
	}
	checkpoint.updated_at = new Date().toISOString();
}

function runPhase({
	phase,
	manifest,
	baseUrl,
	session,
	runtimeDir,
	checkpoint,
	checkpointPath,
}) {
	const items = flattenManifestItems(manifest);
	const itemsByKey = new Map(items.map((item) => [item.key, item]));
	const seen = new Set();
	const runPosts = () => {
		const posts = phase === 'rollback'
			? [...manifest.posts].reverse()
			: manifest.posts;
		for (const post of posts) {
			acceptPendingBeforeUnload(session, runtimeDir);
			const result = runPlaywrightFunction(
				session,
				postEditorBrowserFunction({ baseUrl, post, phase }),
				runtimeDir
			);
			recordResult({
				checkpoint,
				itemsByKey,
				result,
				phase,
				score: result.score,
			});
			for (const record of result.records ?? []) seen.add(record.key);
			writeAtomic0600(checkpointPath, checkpoint);
			if (result.blocked) {
				fail(`${result.blocked} at post ${post.post_id}`);
			}
		}
	};
	const runMedia = () => {
		const posts = phase === 'rollback'
			? [...manifest.posts].reverse()
			: manifest.posts;
		for (const post of posts) {
			if (!post.featured_image_alt) continue;
			acceptPendingBeforeUnload(session, runtimeDir);
			const result = runPlaywrightFunction(
				session,
				mediaAltBrowserFunction({
					baseUrl,
					media: post.featured_image_alt,
					phase,
				}),
				runtimeDir
			);
			recordResult({ checkpoint, itemsByKey, result, phase });
			for (const record of result.records ?? []) seen.add(record.key);
			writeAtomic0600(checkpointPath, checkpoint);
			if (result.blocked) {
				fail(`${result.blocked} at attachment ${
					post.featured_image_alt.attachment_id
				}`);
			}
		}
	};
	if (['apply', 'apply_deferred_score', 'verify_score'].includes(phase)) {
		runMedia();
		runPosts();
	} else if (phase === 'rollback') {
		runPosts();
		runMedia();
	} else {
		runPosts();
		runMedia();
	}
	const missing = items.filter((item) => !seen.has(item.key));
	if (missing.length > 0) {
		fail(`${missing.length} planned UI fields were not observed`);
	}
	const drift = items.filter(
		(item) => checkpoint.events[item.key]?.[phase]?.status === 'drift'
	);
	if (drift.length > 0) {
		fail(`${drift.length} UI fields drifted from both reviewed hashes`);
	}
}

function usage() {
	process.stdout.write(`Usage:
  node ops/run-blog-seo-official-ui-playwright.mjs \\
    --manifest FILE --validate-only

  node ops/run-blog-seo-official-ui-playwright.mjs \\
    --manifest FILE --base-url ORIGIN --session SESSION \\
    --checkpoint .local-reports/blog-seo/ENV/checkpoint.json

  node ops/run-blog-seo-official-ui-playwright.mjs \\
    --manifest FILE --base-url ORIGIN --session SESSION \\
    --checkpoint .local-reports/blog-seo/ENV/checkpoint.json \\
    --apply --confirm-manifest-sha256 SHA256

  node ops/run-blog-seo-official-ui-playwright.mjs \\
    --manifest FILE --transaction-manifest FILE \\
    --base-url ORIGIN --session SESSION --checkpoint FILE \\
    --apply-deferred-score|--verify-score|--rollback \\
    --confirm-manifest-sha256 SHA256 \\
    --confirm-transaction-sha256 TRANSACTION_SHA256
`);
}

async function main() {
	if (hasOption('--help') || hasOption('-h')) {
		usage();
		return;
	}
	const manifestOption = optionValue('--manifest');
	if (!manifestOption) fail('--manifest is required');
	const manifestPath = path.resolve(manifestOption);
	const manifest = validateManifest(
		JSON.parse(fs.readFileSync(manifestPath, 'utf8'))
	);
	const validateOnly = hasOption('--validate-only');
	const apply = hasOption('--apply');
	const applyDeferredScore = hasOption('--apply-deferred-score');
	const verifyScore = hasOption('--verify-score');
	const rollback = hasOption('--rollback');
	const operationalModes = [
		apply,
		applyDeferredScore,
		verifyScore,
		rollback,
	].filter(Boolean).length;
	if (
		operationalModes > 1
		|| (validateOnly && operationalModes > 0)
	) {
		fail('runner modes are mutually exclusive');
	}
	if (
		(apply || applyDeferredScore || rollback)
		&& manifest.environment !== 'production'
	) {
		fail(
			'official UI mutation modes are production-only; '
			+ 'Docker local is the read-only preflight environment'
		);
	}
	assertBrowserFunctionSyntax(
		postEditorBrowserFunction({
			baseUrl: manifest.target_origin,
			post: manifest.posts[0],
			phase: 'preflight',
		}),
		'post editor'
	);
	const mediaSample = manifest.posts.find((post) => post.featured_image_alt);
	if (mediaSample) {
		assertBrowserFunctionSyntax(
			mediaAltBrowserFunction({
				baseUrl: manifest.target_origin,
				media: mediaSample.featured_image_alt,
				phase: 'preflight',
			}),
			'media editor'
		);
	}
	if (validateOnly) {
		console.log(JSON.stringify({
			result: 'PASS',
			mode: 'validate-only',
			environment: manifest.environment,
			target_origin: manifest.target_origin,
			manifest_sha256: manifest.manifest_sha256,
			posts: manifest.posts.length,
			items: flattenManifestItems(manifest).length,
		}, null, 2));
		return;
	}

	const baseUrl = validateBaseUrl(
		optionValue('--base-url'),
		manifest
	);
	const session = validateSession(optionValue('--session'));
	const checkpointPath = validateLocalCheckpointPath(
		optionValue('--checkpoint')
	);
	const transactional = applyDeferredScore || verifyScore || rollback;
	let transaction = null;
	if (transactional) {
		transaction = loadTransaction(
			optionValue('--transaction-manifest'),
			manifest
		);
	}
	if (
		(apply || applyDeferredScore || rollback)
		&& optionValue('--confirm-manifest-sha256')
			!== manifest.manifest_sha256
	) {
		fail(
			'mutating mode requires --confirm-manifest-sha256 '
			+ 'equal to the reviewed manifest'
		);
	}
	const checkpoint = loadCheckpoint(checkpointPath, manifest);
	const runtimeDir = fs.mkdtempSync(
		path.join(os.tmpdir(), 'akb-blog-seo-official-ui-')
	);
	fs.chmodSync(runtimeDir, 0o700);
	try {
		if (rollback) {
			runPhase({
				phase: 'rollback',
				manifest,
				baseUrl,
				session,
				runtimeDir,
				checkpoint,
				checkpointPath,
			});
		} else {
			runPhase({
				phase: verifyScore ? 'verify_score' : 'preflight',
				manifest,
				baseUrl,
				session,
				runtimeDir,
				checkpoint,
				checkpointPath,
			});
			if (apply || applyDeferredScore) {
				runPhase({
					phase: apply
						? 'apply'
						: 'apply_deferred_score',
					manifest,
					baseUrl,
					session,
					runtimeDir,
					checkpoint,
					checkpointPath,
				});
			}
		}
	} finally {
		fs.rmSync(runtimeDir, { recursive: true, force: true });
	}
	const statuses = {};
	const outputPhase = rollback
		? 'rollback'
		: verifyScore
			? 'verify_score'
			: applyDeferredScore
				? 'apply_deferred_score'
				: apply
					? 'apply'
					: 'preflight';
	for (const event of Object.values(checkpoint.events)) {
		const status = event[outputPhase]?.status ?? 'missing';
		statuses[status] = (statuses[status] ?? 0) + 1;
	}
	console.log(JSON.stringify({
		result: 'PASS',
		mode: outputPhase,
		environment: manifest.environment,
		manifest_sha256: manifest.manifest_sha256,
		...(transaction ? {
			transaction_sha256: transaction.transaction_sha256,
		} : {}),
		checkpoint: checkpointPath,
		status_counts: statuses,
	}, null, 2));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	main().catch((error) => {
		process.stderr.write(`${error.message}\n`);
		process.exitCode = 1;
	});
}
