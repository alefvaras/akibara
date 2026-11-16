#!/usr/bin/env node

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';

import {
	buildTemporaryDraftPlan,
	TEMPORARY_DRAFT_CONTRACT,
	validateTemporaryDraftPlan,
	sha256,
} from './lib/blog-seo-temporary-draft-official-ui.mjs';
import {
	ensureWordPressBlockEditorField,
} from './lib/wordpress-block-editor-official-ui.mjs';

export const PLAYWRIGHT_CLI_PACKAGE = '@playwright/cli@0.1.17';

export function extractUnsafeOfficialUiStatus(body, contentType = '') {
	const rawBody = String(body ?? '');
	if (rawBody === '') return null;
	const statusValues = [];
	const collectObjectStatuses = (value, seen = new Set()) => {
		if (
			value === null
			|| typeof value !== 'object'
			|| seen.has(value)
		) {
			return;
		}
		seen.add(value);
		for (const [key, child] of Object.entries(value)) {
			if (/^(?:status|post_status)$/iu.test(key)) {
				statusValues.push(String(child).trim().toLowerCase());
			}
			collectObjectStatuses(child, seen);
		}
	};
	const normalizedType = String(contentType)
		.split(';', 1)[0].trim().toLowerCase();
	const trimmed = rawBody.trim();
	if (
		normalizedType === 'application/json'
		|| normalizedType.endsWith('+json')
		|| /^(?:\{|\[)/u.test(trimmed)
	) {
		try {
			collectObjectStatuses(JSON.parse(rawBody));
		} catch {
			return 'unparseable-json';
		}
	} else if (normalizedType === 'multipart/form-data') {
		for (const match of rawBody.matchAll(
			/name="(?:status|post_status|[^"]+\[(?:status|post_status)\])"(?:\r?\n[^\r\n]*)*\r?\n\r?\n([^\r\n]+)/giu
		)) {
			statusValues.push(String(match[1]).trim().toLowerCase());
		}
	} else {
		const collectForm = (value) => {
			const params = new URLSearchParams(
				String(value).replace(/^[?&]/u, '')
			);
			for (const [key, item] of params.entries()) {
				if (
					/^(?:status|post_status|.+\[(?:status|post_status)\])$/iu
						.test(key)
				) {
					statusValues.push(String(item).trim().toLowerCase());
				}
			}
		};
		collectForm(rawBody);
		try {
			const decoded = decodeURIComponent(
				rawBody.replace(/\+/gu, ' ')
			);
			if (decoded !== rawBody) collectForm(decoded);
		} catch {
			return 'unparseable-form';
		}
	}
	const allowedStatuses = new Set([
		'draft',
		'auto-draft',
		'trash',
		'inherit',
	]);
	return statusValues.find((status) => !allowedStatuses.has(status)) ?? null;
}

function fail(message) {
	throw new Error(`Temporary blog draft UI runner failed: ${message}`);
}

function option(name, fallback = undefined) {
	const indexes = process.argv
		.map((value, index) => value === name ? index : -1)
		.filter((index) => index !== -1);
	if (indexes.length > 1) fail(`duplicate option ${name}`);
	const index = indexes[0] ?? -1;
	return index === -1 ? fallback : process.argv[index + 1];
}

function hasOption(name) {
	return process.argv.includes(name);
}

function repositoryRoot() {
	return path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
}

function isInside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== ''
		&& relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function assertNoSymlinkTraversal(file, root, label) {
	let current = file;
	while (current !== root && current !== path.dirname(current)) {
		if (
			fs.existsSync(current)
			&& fs.lstatSync(current).isSymbolicLink()
		) {
			fail(`${label} may not traverse symbolic links`);
		}
		current = path.dirname(current);
	}
}

function validateRegularLocalFile(value, {
	label,
	allowedRoot,
}) {
	if (!value) fail(`missing ${label}`);
	const file = path.resolve(value);
	const root = path.resolve(allowedRoot);
	if (!isInside(file, root)) {
		fail(`${label} must remain inside ${root}`);
	}
	assertNoSymlinkTraversal(file, root, label);
	if (!fs.existsSync(file)) fail(`${label} does not exist`);
	const stats = fs.lstatSync(file);
	if (!stats.isFile() || stats.isSymbolicLink()) {
		fail(`${label} must be a regular non-symlink file`);
	}
	return { file, stats };
}

function validateJsonInput(value, label, repository) {
	const { file } = validateRegularLocalFile(value, {
		label,
		allowedRoot: path.join(repository, '.local-reports'),
	});
	const bytes = fs.readFileSync(file);
	let parsed;
	try {
		parsed = JSON.parse(bytes.toString('utf8'));
	} catch (error) {
		fail(`${label} is not valid JSON: ${error.message}`);
	}
	return {
		file,
		parsed,
		sha256: sha256(bytes),
	};
}

export function readWebpDimensions(buffer) {
	if (
		!Buffer.isBuffer(buffer)
		|| buffer.length < 30
		|| buffer.toString('ascii', 0, 4) !== 'RIFF'
		|| buffer.toString('ascii', 8, 12) !== 'WEBP'
	) {
		fail('image is not a valid RIFF WEBP file');
	}
	let offset = 12;
	while (offset + 8 <= buffer.length) {
		const type = buffer.toString('ascii', offset, offset + 4);
		const length = buffer.readUInt32LE(offset + 4);
		const data = offset + 8;
		if (data + length > buffer.length) {
			fail('image contains a truncated WEBP chunk');
		}
		if (type === 'VP8X') {
			if (length < 10) fail('image VP8X chunk is incomplete');
			return {
				width: 1 + buffer.readUIntLE(data + 4, 3),
				height: 1 + buffer.readUIntLE(data + 7, 3),
			};
		}
		if (type === 'VP8 ') {
			if (
				length < 10
				|| buffer[data + 3] !== 0x9d
				|| buffer[data + 4] !== 0x01
				|| buffer[data + 5] !== 0x2a
			) {
				fail('image VP8 frame header is invalid');
			}
			return {
				width: buffer.readUInt16LE(data + 6) & 0x3fff,
				height: buffer.readUInt16LE(data + 8) & 0x3fff,
			};
		}
		if (type === 'VP8L') {
			if (length < 5 || buffer[data] !== 0x2f) {
				fail('image VP8L frame header is invalid');
			}
			const bits = buffer.readUInt32LE(data + 1);
			return {
				width: 1 + (bits & 0x3fff),
				height: 1 + ((bits >>> 14) & 0x3fff),
			};
		}
		offset = data + length + (length % 2);
	}
	fail('image has no supported WEBP dimensions chunk');
}

function validateImageInput(value, repository) {
	const { file, stats } = validateRegularLocalFile(value, {
		label: '--image',
		allowedRoot: repository,
	});
	const uploadsRoot = path.join(
		repository,
		'public_html',
		'wp-content',
		'uploads'
	);
	if (isInside(file, uploadsRoot)) {
		fail('--image must not reuse a WordPress uploads artifact');
	}
	const bytes = fs.readFileSync(file);
	const dimensions = readWebpDimensions(bytes);
	const relative = path.relative(repository, file).split(path.sep).join('/');
	return {
		absoluteFile: file,
		contract: {
			relative_local_file: relative,
			expected_filename: path.basename(file),
			sha256: sha256(bytes),
			bytes: stats.size,
			width: dimensions.width,
			height: dimensions.height,
		},
	};
}

function validateBaseUrl(value) {
	if (value !== TEMPORARY_DRAFT_CONTRACT.targetOrigin) {
		fail(
			`--base-url must be exactly ${
				TEMPORARY_DRAFT_CONTRACT.targetOrigin
			}`
		);
	}
	const parsed = new URL(value);
	if (
		parsed.protocol !== 'http:'
		|| parsed.hostname !== 'localhost'
		|| parsed.port !== '8080'
		|| parsed.pathname !== '/'
	) {
		fail('--base-url is not the reviewed Docker origin');
	}
	return parsed.origin;
}

function validateSession(value) {
	if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/u.test(value ?? '')) {
		fail('unsafe or missing --session');
	}
	return value;
}

function validateReportPath(value, repository) {
	if (!value) fail('missing --report');
	const file = path.resolve(value);
	const allowedRoot = path.join(repository, '.local-reports');
	if (!isInside(file, allowedRoot)) {
		fail('--report must remain under local .local-reports');
	}
	assertNoSymlinkTraversal(file, allowedRoot, '--report');
	if (fs.existsSync(file)) {
		fail('--report already exists; refusing to overwrite evidence');
	}
	return file;
}

function writeJson0600(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		flag: 'wx',
		mode: 0o600,
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function replaceJson0600(file, value) {
	if (!fs.existsSync(file)) {
		fail('recovery journal disappeared before atomic phase change');
	}
	const current = fs.lstatSync(file);
	if (
		!current.isFile()
		|| current.isSymbolicLink()
		|| (current.mode & 0o777) !== 0o600
	) {
		fail('recovery journal is not a regular 0600 file');
	}
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		flag: 'wx',
		mode: 0o600,
	});
	try {
		fs.renameSync(temporary, file);
		fs.chmodSync(file, 0o600);
	} finally {
		fs.rmSync(temporary, { force: true });
	}
}

function createRunIdentity() {
	const runNonce = randomBytes(12).toString('hex');
	return {
		runNonce,
		runtimeUploadFilename:
			`preventa-manga-chile-akibara--akb-${runNonce}.webp`,
		allocationStartedAt: new Date().toISOString(),
	};
}

function parsePlaywrightResult(stdout, stderr) {
	let outer;
	try {
		outer = JSON.parse(stdout);
	} catch {
		fail(
			`Playwright CLI did not return JSON: ${
				(stderr || stdout).slice(0, 700)
			}`
		);
	}
	if (typeof outer.result !== 'string') {
		fail('Playwright CLI returned no serialized result');
	}
	try {
		return JSON.parse(outer.result);
	} catch {
		fail(`Playwright result was not JSON: ${outer.result.slice(0, 700)}`);
	}
}

function resolveNpxCli(repository) {
	if (process.env.AKB_TEMP_DRAFT_TEST_MODE === '1') {
		const { file } = validateRegularLocalFile(
			process.env.AKB_TEMP_DRAFT_TEST_NPX_CLI,
			{
				label: 'AKB_TEMP_DRAFT_TEST_NPX_CLI',
				allowedRoot: path.join(repository, '.local-reports'),
			}
		);
		return file;
	}
	const sibling = path.join(path.dirname(process.execPath), 'npx');
	if (!fs.existsSync(sibling)) {
		fail('trusted npx sibling is missing from the active Node installation');
	}
	const resolved = fs.realpathSync(sibling);
	const stats = fs.lstatSync(resolved);
	if (
		!stats.isFile()
		|| stats.isSymbolicLink()
		|| path.basename(resolved) !== 'npx-cli.js'
	) {
		fail('trusted npx CLI resolution is invalid');
	}
	return resolved;
}

function runPlaywrightFunction(session, functionSource, runtimeDirectory) {
	const browserFunction = path.join(
		runtimeDirectory,
		`temporary-draft-browser-function-${process.pid}-${Date.now()}.js`
	);
	fs.writeFileSync(browserFunction, `${functionSource}\n`, {
		flag: 'wx',
		mode: 0o600,
	});
	let result;
	try {
		result = spawnSync(
			process.execPath,
			[
				resolveNpxCli(repositoryRoot()),
				'--yes',
				'--package',
				PLAYWRIGHT_CLI_PACKAGE,
				'playwright-cli',
				`--session=${session}`,
				'--json',
				'run-code',
				`--filename=${browserFunction}`,
			],
			{
				cwd: runtimeDirectory,
				encoding: 'utf8',
				maxBuffer: 8 * 1024 * 1024,
				timeout: 20 * 60 * 1000,
			}
		);
	} finally {
		fs.rmSync(browserFunction, { force: true });
	}
	if (result.error || result.status !== 0) {
		fail(
			`Playwright failed (${result.status ?? 'spawn'}): ${
				(result.stderr || result.stdout || result.error?.message)
					.slice(0, 1800)
			}`
		);
	}
	return parsePlaywrightResult(result.stdout, result.stderr);
}

export function temporaryDraftBrowserFunction({
	baseUrl,
	plan,
	absoluteImagePath,
	mode = 'full',
	allocatedLocalPostId = null,
	runNonce = null,
	runtimeUploadFilename = null,
	preexistingAutoDraftIds = [],
	allocationStartedAt = null,
}) {
	return `async page => {
		/* AKB_BLOG_SEO_TEMPORARY_DRAFT_OFFICIAL_UI */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const plan = ${JSON.stringify(plan)};
		const absoluteImagePath = ${JSON.stringify(absoluteImagePath)};
		const mode = ${JSON.stringify(mode)};
		const allocatedLocalPostId = ${JSON.stringify(allocatedLocalPostId)};
		const runNonce = ${JSON.stringify(runNonce)};
		const runtimeUploadFilename = ${
			JSON.stringify(runtimeUploadFilename)
		};
		const preexistingAutoDraftIds = ${
			JSON.stringify(preexistingAutoDraftIds)
		};
		const allocationStartedAt = ${JSON.stringify(allocationStartedAt)};
		const recoveryMarkerTitle = runNonce
			? 'AKB SEO TEMP ' + runNonce
			: null;
		const recoveryMarkerSlug = runNonce
			? 'akb-seo-temp-' + runNonce
			: null;
		const ensureWordPressBlockEditorField = ${
			ensureWordPressBlockEditorField.toString()
		};
		const extractUnsafeOfficialUiStatus = ${
			extractUnsafeOfficialUiStatus.toString()
		};
		const routeViolations = [];
		const cleanupErrors = [];
		const recoveryMode = mode === 'recover';
		let localPostId = allocatedLocalPostId;
		let localPostCapturedFromNewEditor =
			Number.isSafeInteger(allocatedLocalPostId);
		let uploadedAttachmentId = null;
		let uploadStarted = recoveryMode;
		let primaryError = null;
		let baselineScore = null;
		let persistedScore = null;
		let persistedState = null;
		page.setDefaultNavigationTimeout(75000);
		page.setDefaultTimeout(30000);
		await page.setViewportSize({ width: 1440, height: 1000 });

		function localUrl(pathname) {
			const target = new URL(pathname, baseUrl);
			if (target.origin !== baseUrl) {
				throw new Error('LOCAL_TARGET_ORIGIN_ESCAPE');
			}
			return target.href;
		}

		function assertLocalPage(code) {
			const current = new URL(page.url());
			if (current.origin !== baseUrl) {
				throw new Error(code + ':ORIGIN_ESCAPE');
			}
			if (/\\/wp-login\\.php(?:$|[?#])/u.test(current.href)) {
				throw new Error('AUTH_REQUIRED');
			}
		}

		async function assertNoRouteViolations() {
			if (routeViolations.length > 0) {
				throw new Error(
					'BLOCKED_MUTATING_REQUEST:' + routeViolations.join('|')
				);
			}
		}

		await page.route('**/*', async route => {
			const request = route.request();
			const method = request.method().toUpperCase();
			if (['GET', 'HEAD', 'OPTIONS'].includes(method)) {
				await route.continue();
				return;
			}
			const requestUrl = new URL(request.url());
			const body = String(request.postData() ?? '');
			const contentType = request.headers()['content-type'] ?? '';
			const unsafeStatus = extractUnsafeOfficialUiStatus(
				requestUrl.search,
				'application/x-www-form-urlencoded'
			) ?? extractUnsafeOfficialUiStatus(body, contentType);
			if (requestUrl.origin !== baseUrl || unsafeStatus) {
				routeViolations.push(
					method + ':' + requestUrl.origin
					+ (
						unsafeStatus
							? ':UNSAFE_STATUS:' + unsafeStatus
							: ':FOREIGN_ORIGIN'
					)
				);
				await route.abort('blockedbyclient');
				return;
			}
			await route.continue();
		});

		async function navigate(pathname, code = 'ADMIN_PATH_ESCAPE') {
			const target = localUrl(pathname);
			await page.goto(target, {
				waitUntil: 'domcontentloaded',
				timeout: 75000,
			});
			assertLocalPage(code);
			await assertNoRouteViolations();
			return target;
		}

		async function withAcceptedBrowserDialog(action) {
			const handler = async dialog => {
				await dialog.accept();
			};
			page.on('dialog', handler);
			try {
				await action();
				await page.waitForTimeout(300);
			} finally {
				page.off('dialog', handler);
			}
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

		async function titleField() {
			const selectors = [
				'.editor-post-title__input textarea',
				'textarea[aria-label="Añadir título"]',
				'textarea[aria-label="Add title"]',
				'input.editor-post-title__input',
				'textarea.editor-post-title__input',
				'.editor-post-title__input[contenteditable="true"]',
			];
			await page.locator(
				selectors.map(selector => selector + ':visible').join(', ')
			).first().waitFor({ state: 'visible', timeout: 30000 });
			return oneVisible(
				selectors.join(', '),
				'POST_TITLE_MISSING_OR_AMBIGUOUS'
			);
		}

		async function controlValue(control) {
			return control.evaluate(element => (
				typeof element.value === 'string'
					? element.value
					: element.textContent ?? ''
			));
		}

		async function saveDraft() {
			const save = await oneVisible(
				'button.editor-post-save-draft:visible',
				'DRAFT_SAVE_BUTTON_MISSING_OR_AMBIGUOUS'
			);
			if (
				!/Guardar\\s+borrador|Save\\s+draft/iu
					.test(String(await save.textContent() ?? ''))
				|| !await save.isEnabled()
			) {
				throw new Error('DRAFT_SAVE_BUTTON_NOT_ACTIONABLE');
			}
			await save.click({ trial: true });
			await save.click();
			await page.waitForFunction(
				() => {
					const saving = document.querySelector(
						'button.editor-post-saved-state.is-saving, '
							+ 'button.editor-post-saved-state.is-autosaving'
					);
					const saved = document.querySelector(
						'button.editor-post-saved-state.is-saved'
					);
					return !saving && Boolean(saved);
				},
				null,
				{ timeout: 45000 }
			);
			await page.waitForTimeout(500);
			assertLocalPage('DRAFT_SAVE_PATH_ESCAPE');
			await assertNoRouteViolations();
			const match = /\\/wp-admin\\/post\\.php\\?post=([0-9]+)&action=edit/u
				.exec(page.url());
			if (!match) {
				throw new Error('DRAFT_EDIT_URL_MISSING');
			}
			const postId = Number(match[1]);
			if (
				!Number.isSafeInteger(postId)
				|| postId < 1
				|| postId === plan.source.production_post_id
				|| postId === plan.source.forbidden_production_attachment_id
			) {
				throw new Error('DRAFT_LOCAL_ID_INVALID_OR_FORBIDDEN');
			}
			if (localPostId !== null && localPostId !== postId) {
				throw new Error('DRAFT_LOCAL_ID_CHANGED');
			}
			localPostId = postId;
			await assertDraftStatus({ allowAutoDraft: false });
			return postId;
		}

		async function writeWordPressFields(values, keyPrefix) {
			const title = await titleField();
			await title.fill(values.post_title);
			const content = await ensureWordPressBlockEditorField(page, {
				field: 'post_content',
				key: keyPrefix + ':post_content',
			});
			await content.fill(values.post_content);
			const excerpt = await ensureWordPressBlockEditorField(page, {
				field: 'post_excerpt',
				key: keyPrefix + ':post_excerpt',
			});
			await excerpt.fill(values.post_excerpt);
		}

		async function readWordPressFields(keyPrefix) {
			const title = await titleField();
			const content = await ensureWordPressBlockEditorField(page, {
				field: 'post_content',
				key: keyPrefix + ':post_content',
			});
			const excerpt = await ensureWordPressBlockEditorField(page, {
				field: 'post_excerpt',
				key: keyPrefix + ':post_excerpt',
			});
			return {
				post_title: (await controlValue(title)).replace(/\\r\\n?/gu, '\\n'),
				post_content: (await content.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
				post_excerpt: (await excerpt.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
			};
		}

		async function slugField() {
			let input = page.locator(
				'.editor-post-url__input input:visible'
			);
			if (await input.count() === 0) {
				const toggle = page.locator(
					'button.editor-post-url__panel-toggle:visible'
				);
				if (await toggle.count() !== 1) {
					throw new Error('POST_SLUG_TOGGLE_MISSING_OR_AMBIGUOUS');
				}
				await toggle.click({ trial: true });
				await toggle.click();
				input = page.locator(
					'.editor-post-url__input input:visible'
				);
				await input.waitFor({ state: 'visible', timeout: 15000 });
			}
			if (await input.count() !== 1) {
				throw new Error('POST_SLUG_INPUT_MISSING_OR_AMBIGUOUS');
			}
			return input;
		}

		async function setSlug(value) {
			const input = await slugField();
			await input.fill(value);
			await input.blur();
			await page.waitForTimeout(300);
			const verified = await slugField();
			if ((await verified.inputValue()) !== value) {
				throw new Error('POST_SLUG_UI_WRITE_MISMATCH');
			}
		}

		async function readSlug() {
			return (await slugField()).inputValue();
		}

		async function openDocumentSettings() {
			let sidebar = page.locator([
				'.editor-post-settings-sidebar:visible',
				'.edit-post-sidebar:visible',
			].join(', '));
			if (await sidebar.count() === 0) {
				const toggles = page.locator([
					'button[aria-label="Ajustes"]:visible',
					'button[aria-label="Settings"]:visible',
					'button[aria-controls="edit-post:document"]:visible',
				].join(', '));
				if (await toggles.count() !== 1) {
					throw new Error('WORDPRESS_SETTINGS_TOGGLE_MISSING');
				}
				await toggles.click({ trial: true });
				await toggles.click();
				sidebar = page.locator([
					'.editor-post-settings-sidebar:visible',
					'.edit-post-sidebar:visible',
				].join(', '));
				await sidebar.first().waitFor({
					state: 'visible',
					timeout: 15000,
				});
			}
			if (await sidebar.count() !== 1) {
				throw new Error('WORDPRESS_SETTINGS_SIDEBAR_AMBIGUOUS');
			}
			return sidebar;
		}

		async function assertDraftStatus({ allowAutoDraft }) {
			const sidebar = await openDocumentSettings();
			const status = sidebar.locator(
				'button.editor-post-status__toggle:visible'
			);
			if (await status.count() !== 1) {
				throw new Error('POST_STATUS_TOGGLE_MISSING_OR_AMBIGUOUS');
			}
			const label = String(await status.textContent() ?? '')
				.replace(/\\s+/gu, ' ')
				.trim();
			if (!/^(?:Borrador|Draft)$/iu.test(label)) {
				throw new Error('POST_STATUS_NOT_DRAFT:' + label);
			}
			const autoDraft = await page.locator(
				'#auto_draft[value="1"]'
			).count();
			if (!allowAutoDraft && autoDraft !== 0) {
				throw new Error('POST_STATUS_REMAINED_AUTO_DRAFT');
			}
			return autoDraft === 1 ? 'auto-draft' : 'draft';
		}

		async function captureNewEditorPostId() {
			const id = page.locator('#post_ID');
			const autoDraft = page.locator('#auto_draft[value="1"]');
			if (
				await id.count() !== 1
				|| await autoDraft.count() !== 1
			) {
				throw new Error('NEW_EDITOR_AUTO_DRAFT_IDENTITY_MISSING');
			}
			const postId = Number(await id.inputValue());
			if (
				!Number.isSafeInteger(postId)
				|| postId < 1
				|| postId === plan.source.production_post_id
				|| postId === plan.source.forbidden_production_attachment_id
			) {
				throw new Error('NEW_EDITOR_AUTO_DRAFT_ID_FORBIDDEN');
			}
			localPostId = postId;
			localPostCapturedFromNewEditor = true;
			await assertDraftStatus({ allowAutoDraft: true });
			return postId;
		}

		async function readAuthor() {
			const sidebar = await openDocumentSettings();
			const toggle = sidebar.locator(
				'button.editor-post-author__panel-toggle:visible'
			);
			if (await toggle.count() !== 1) {
				throw new Error('POST_AUTHOR_TOGGLE_MISSING_OR_AMBIGUOUS');
			}
			const toggleIdentity = [
				await toggle.getAttribute('aria-label'),
				await toggle.textContent(),
			].filter(Boolean).join(' ').replace(/\\s+/gu, ' ').trim();
			if (
				!toggleIdentity.includes(plan.author.expected_display_name)
			) {
				throw new Error('POST_AUTHOR_DISPLAY_MISMATCH');
			}
			await toggle.click({ trial: true });
			await toggle.click();
			const dialog = page.locator(
				'.editor-post-author__panel-dialog:visible'
			);
			await dialog.waitFor({ state: 'visible', timeout: 15000 });
			if (await dialog.count() !== 1) {
				throw new Error('POST_AUTHOR_DIALOG_MISSING_OR_AMBIGUOUS');
			}
			const select = dialog.locator(
				'.post-author-selector select:visible'
			);
			if (await select.count() !== 1) {
				throw new Error(
					'POST_AUTHOR_NATIVE_SELECT_REQUIRED_TO_PROVE_ID'
				);
			}
			const id = Number(await select.inputValue());
			const display = String(
				await select.locator('option:checked').textContent() ?? ''
			).trim();
			await toggle.click();
			await dialog.waitFor({ state: 'hidden', timeout: 10000 });
			if (!Number.isSafeInteger(id) || id < 1 || !display) {
				throw new Error('POST_AUTHOR_SELECTION_INCOMPLETE');
			}
			return { id, display };
		}

		async function assertExpectedAuthor() {
			const author = await readAuthor();
			if (
				author.id !== plan.author.expected_user_id
				|| author.display !== plan.author.expected_display_name
			) {
				throw new Error(
					'POST_AUTHOR_MISMATCH:' + JSON.stringify(author)
				);
			}
			return author;
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
				await toggles.click({ trial: true });
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
			if (
				await generalTabs.count() === 1
				&& await generalTabs.getAttribute('aria-selected') !== 'true'
			) {
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
			if (value === '') return;
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
			if (await readFocusKeywords(surface) !== value) {
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
				title = page.locator('#rank-math-editor-title:visible');
				await title.waitFor({ state: 'visible', timeout: 15000 });
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

		async function writeRankMathFields(values) {
			let surface = await openRankMathGeneral();
			await writeFocusKeywords(
				surface,
				values.rank_math_focus_keyword
			);
			let snippet = await snippetFields(surface);
			await snippet.title.fill(values.rank_math_title);
			surface = await openRankMathGeneral();
			snippet = await snippetFields(surface);
			await snippet.description.fill(values.rank_math_description);
			await closeSnippetIfOpen();
		}

		async function readRankMathFields() {
			const surface = await openRankMathGeneral();
			const focus = await readFocusKeywords(surface);
			const snippet = await snippetFields(surface);
			const values = {
				rank_math_focus_keyword: focus.replace(/\\r\\n?/gu, '\\n'),
				rank_math_title: (await snippet.title.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
				rank_math_description: (await snippet.description.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
			};
			await closeSnippetIfOpen();
			return values;
		}

		async function stableRankMathScore() {
			let previous = null;
			let stableSamples = 0;
			const samples = [];
			for (let attempt = 0; attempt < 56; attempt += 1) {
				const busy = await page.locator([
					'.components-spinner:visible',
					'.rank-math-loading:visible',
					'.rank-math-loader:visible',
					'.is-loading:visible',
				].join(', ')).count();
				const candidates = page.locator([
					'.rank-math-score:visible',
					'.rank-math-toolbar-score:visible',
					'button[aria-label*="Rank Math" i]:visible',
				].join(', '));
				const scores = [];
				for (
					let index = 0;
					index < await candidates.count();
					index += 1
				) {
					const text = String(
						await candidates.nth(index).getAttribute('aria-label')
							?? await candidates.nth(index).textContent()
							?? ''
					).replace(/\\s+/gu, ' ').trim();
					const match = /(?:^|\\D)(100|[0-9]{1,2})(?:\\s*\\/\\s*100)?(?:\\D|$)/u
						.exec(text);
					if (match) scores.push(Number(match[1]));
				}
				const unique = [...new Set(scores)];
				if (busy === 0 && unique.length === 1) {
					const score = unique[0];
					samples.push(score);
					stableSamples = score === previous ? stableSamples + 1 : 1;
					previous = score;
					if (stableSamples >= 4) {
						return { score, samples: samples.slice(-8) };
					}
				} else {
					stableSamples = 0;
					previous = null;
				}
				await page.waitForTimeout(750);
			}
			throw new Error('RANK_MATH_SCORE_NOT_STABLE');
		}

		async function featuredPanel() {
			let panel = page.locator('.editor-post-featured-image:visible');
			if (await panel.count() === 0) {
				await openDocumentSettings();
				panel = page.locator('.editor-post-featured-image:visible');
				await panel.waitFor({ state: 'visible', timeout: 15000 });
			}
			if (await panel.count() !== 1) {
				throw new Error('FEATURED_IMAGE_PANEL_MISSING_OR_AMBIGUOUS');
			}
			return panel;
		}

		async function openFeaturedLibrary() {
			const panel = await featuredPanel();
			let action = panel.locator([
				'button[aria-label*="Reemplazar" i]:visible',
				'button[aria-label*="Replace" i]:visible',
			].join(', '));
			if (await action.count() === 0) {
				action = panel.locator('button:visible').filter({
					hasText: /Reemplazar|Replace|Establecer|Set featured/iu,
				});
			}
			if (await action.count() !== 1) {
				throw new Error('FEATURED_IMAGE_ACTION_MISSING_OR_AMBIGUOUS');
			}
			await action.click({ trial: true });
			await action.click();
			let modal = page.locator('.media-modal:visible');
			if (await modal.count() === 0) {
				const library = page.getByRole('menuitem').filter({
					hasText: /Biblioteca de medios|Media Library/iu,
				});
				if (await library.count() !== 1) {
					throw new Error('MEDIA_LIBRARY_MENU_ACTION_MISSING');
				}
				await library.click();
				modal = page.locator('.media-modal:visible');
			}
			await modal.waitFor({ state: 'visible', timeout: 20000 });
			if (await modal.count() !== 1) {
				throw new Error('MEDIA_MODAL_MISSING_OR_AMBIGUOUS');
			}
			return modal;
		}

		async function selectedMedia(modal) {
			const selected = modal.locator('li.attachment.selected:visible');
			await selected.first().waitFor({ state: 'visible', timeout: 30000 });
			if (await selected.count() !== 1) {
				throw new Error('SELECTED_MEDIA_MISSING_OR_AMBIGUOUS');
			}
			const attachmentId = Number(await selected.getAttribute('data-id'));
			const details = modal.locator(
				'.attachment-details:visible, .attachment-info:visible'
			);
			const urlControl = details.locator('input[data-setting="url"]');
			const strongFilename = details.locator('.filename strong');
			const plainFilename = details.locator('.filename');
			let filename = '';
			if (await urlControl.count() === 1) {
				filename = await urlControl.inputValue();
			} else if (await strongFilename.count() === 1) {
				filename = String(
					await strongFilename.textContent() ?? ''
				).trim();
			} else if (await plainFilename.count() === 1) {
				filename = String(await plainFilename.textContent() ?? '')
					.replace(
						/^(?:Nombre del archivo|File name):?\\s*/iu,
						''
					)
					.trim();
			}
			if (filename.includes('/')) {
				filename = decodeURIComponent(
					new URL(filename, baseUrl).pathname.split('/').at(-1)
				);
			}
			const altControl = details.locator(
				'textarea[data-setting="alt"], input[data-setting="alt"]'
			);
			if (
				!Number.isSafeInteger(attachmentId)
				|| attachmentId < 1
				|| !filename
				|| await altControl.count() !== 1
			) {
				throw new Error('SELECTED_MEDIA_DETAILS_INCOMPLETE');
			}
			return {
				attachment_id: attachmentId,
				filename,
				alt: await altControl.inputValue(),
				altControl,
			};
		}

		async function closeMediaModal(modal) {
			const close = modal.locator('.media-modal-close:visible');
			if (await close.count() !== 1) {
				throw new Error('MEDIA_MODAL_CLOSE_MISSING');
			}
			await close.click();
			await modal.waitFor({ state: 'hidden', timeout: 10000 });
		}

		async function uploadFeaturedImage() {
			const modal = await openFeaturedLibrary();
			const uploadTab = modal.locator(
				'[role="tab"], .media-menu-item'
			).filter({
				hasText: /Subir archivos|Upload files/iu,
			});
			if (await uploadTab.count() !== 1) {
				throw new Error('MEDIA_UPLOAD_TAB_MISSING');
			}
			await uploadTab.click();
			const fileInput = modal.locator('input[type="file"]');
			if (await fileInput.count() !== 1) {
				throw new Error('MEDIA_FILE_INPUT_MISSING');
			}
			uploadStarted = true;
			await fileInput.setInputFiles(absoluteImagePath);
			const media = await selectedMedia(modal);
			uploadedAttachmentId = media.attachment_id;
			if (
				media.attachment_id
					=== plan.source.forbidden_production_attachment_id
				|| media.filename !== runtimeUploadFilename
			) {
				throw new Error(
					'UPLOADED_MEDIA_ID_OR_FILENAME_INVALID:'
						+ JSON.stringify(media)
				);
			}
			await media.altControl.fill(plan.desired.featured_image_alt);
			await media.altControl.blur();
			const select = modal.locator('.media-button-select:visible').filter({
				hasText: /Establecer la imagen destacada|Set featured image/iu,
			});
			if (await select.count() !== 1 || !await select.isEnabled()) {
				throw new Error('SET_FEATURED_IMAGE_ACTION_MISSING');
			}
			await select.click();
			await modal.waitFor({ state: 'hidden', timeout: 20000 });
			await assertNoRouteViolations();
			return media;
		}

		async function readFeaturedImage() {
			const modal = await openFeaturedLibrary();
			const media = await selectedMedia(modal);
			await closeMediaModal(modal);
			return {
				attachment_id: media.attachment_id,
				filename: media.filename,
				alt: media.alt,
			};
		}

		async function exactAttachmentIdsByFilename(filename) {
			let pathname =
				'/wp-admin/upload.php?mode=list&s='
				+ encodeURIComponent(filename);
			const exact = [];
			const seen = new Set();
			for (let pageNumber = 1; pageNumber <= 50; pageNumber += 1) {
				await navigate(pathname, 'MEDIA_LIST_PATH_ESCAPE');
				const rows = page.locator('#the-list tr[id^="post-"]');
				if (await rows.count() > 100) {
					throw new Error('MEDIA_SEARCH_PAGE_TOO_BROAD');
				}
				const candidates = [];
				for (
					let index = 0;
					index < await rows.count();
					index += 1
				) {
					const id = Number(
						String(
							await rows.nth(index).getAttribute('id') ?? ''
						).replace(/^post-/u, '')
					);
					if (
						Number.isSafeInteger(id)
						&& id > 0
						&& !seen.has(id)
					) {
						seen.add(id);
						candidates.push(id);
					}
				}
				const next = page.locator(
					'.tablenav.top a.next-page:visible'
				);
				if (await next.count() > 1) {
					throw new Error('MEDIA_LIST_NEXT_PAGE_AMBIGUOUS');
				}
				const nextHref = await next.count() === 1
					? await next.getAttribute('href')
					: null;
				for (const id of candidates) {
					await navigate(
						'/wp-admin/post.php?post=' + id + '&action=edit',
						'MEDIA_EDITOR_PATH_ESCAPE'
					);
					const source = page.locator([
						'#attachment_url',
						'input[name="attachment_url"]',
					].join(', '));
					if (await source.count() !== 1) {
						throw new Error('MEDIA_SOURCE_FIELD_MISSING:' + id);
					}
					const current = decodeURIComponent(
						new URL(await source.inputValue(), baseUrl)
							.pathname.split('/').at(-1)
					);
					if (current === filename) exact.push(id);
				}
				if (!nextHref) return exact;
				const target = new URL(nextHref, baseUrl);
				if (
					target.origin !== baseUrl
					|| target.pathname !== '/wp-admin/upload.php'
				) {
					throw new Error('MEDIA_LIST_NEXT_PAGE_PATH_ESCAPE');
				}
				pathname = target.pathname + target.search;
			}
			throw new Error('MEDIA_LIST_PAGINATION_LIMIT');
		}

		async function postIdsWithExactSlug(postStatus) {
			let pathname = '/wp-admin/edit.php?post_type=post&post_status='
				+ encodeURIComponent(postStatus);
			const matches = [];
			for (let pageNumber = 1; pageNumber <= 50; pageNumber += 1) {
				await navigate(pathname, 'POST_LIST_PATH_ESCAPE');
				const rows = page.locator('#the-list tr[id^="post-"]');
				for (let index = 0; index < await rows.count(); index += 1) {
					const row = rows.nth(index);
					const quick = row.locator(
						'button.editinline, a.editinline'
					);
					if (await quick.count() !== 1) {
						throw new Error('POST_QUICK_EDIT_MISSING');
					}
					await quick.click();
					const rowId = String(
						await row.getAttribute('id') ?? ''
					).replace(/^post-/u, '');
					const inline = page.locator(
						'#edit-' + rowId + ':visible'
					);
					await inline.waitFor({
						state: 'visible',
						timeout: 10000,
					});
					const slug = inline.locator('input[name="post_name"]');
					if (await slug.count() !== 1) {
						throw new Error('POST_QUICK_EDIT_SLUG_MISSING');
					}
					const value = await slug.inputValue();
					const cancel = inline.locator('button.cancel:visible');
					if (await cancel.count() !== 1) {
						throw new Error('POST_QUICK_EDIT_CANCEL_MISSING');
					}
					await cancel.click();
					if (value === plan.source.slug) {
						matches.push(Number(rowId));
					}
				}
				const next = page.locator(
					'.tablenav.top a.next-page:visible'
				);
				if (await next.count() === 0) return matches;
				if (await next.count() !== 1) {
					throw new Error('POST_LIST_NEXT_PAGE_AMBIGUOUS');
				}
				const href = await next.getAttribute('href');
				if (!href) {
					throw new Error('POST_LIST_NEXT_PAGE_HREF_MISSING');
				}
				const target = new URL(href, baseUrl);
				if (
					target.origin !== baseUrl
					|| target.pathname !== '/wp-admin/edit.php'
				) {
					throw new Error('POST_LIST_NEXT_PAGE_PATH_ESCAPE');
				}
				pathname = target.pathname + target.search;
			}
			throw new Error('POST_LIST_PAGINATION_LIMIT');
		}

		async function postIdsByStatus(postStatus) {
			let pathname = '/wp-admin/edit.php?post_type=post&post_status='
				+ encodeURIComponent(postStatus);
			const ids = [];
			for (let pageNumber = 1; pageNumber <= 50; pageNumber += 1) {
				await navigate(pathname, 'POST_STATUS_LIST_PATH_ESCAPE');
				const rows = page.locator('#the-list tr[id^="post-"]');
				for (let index = 0; index < await rows.count(); index += 1) {
					const value = Number(
						String(await rows.nth(index).getAttribute('id') ?? '')
							.replace(/^post-/u, '')
					);
					if (!Number.isSafeInteger(value) || value < 1) {
						throw new Error('POST_STATUS_LIST_ID_INVALID');
					}
					ids.push(value);
				}
				const next = page.locator(
					'.tablenav.top a.next-page:visible'
				);
				if (await next.count() === 0) {
					return [...new Set(ids)].sort((a, b) => a - b);
				}
				if (await next.count() !== 1) {
					throw new Error(
						'POST_STATUS_LIST_NEXT_PAGE_AMBIGUOUS'
					);
				}
				const href = await next.getAttribute('href');
				if (!href) {
					throw new Error(
						'POST_STATUS_LIST_NEXT_PAGE_HREF_MISSING'
					);
				}
				const target = new URL(href, baseUrl);
				if (
					target.origin !== baseUrl
					|| target.pathname !== '/wp-admin/edit.php'
				) {
					throw new Error('POST_STATUS_LIST_NEXT_PAGE_PATH_ESCAPE');
				}
				pathname = target.pathname + target.search;
			}
			throw new Error('POST_STATUS_LIST_PAGINATION_LIMIT');
		}

		async function postIdsWithExactTitle(postStatus, expectedTitle) {
			let pathname = '/wp-admin/edit.php?post_type=post&post_status='
				+ encodeURIComponent(postStatus);
			const ids = [];
			for (let pageNumber = 1; pageNumber <= 50; pageNumber += 1) {
				await navigate(pathname, 'POST_TITLE_LIST_PATH_ESCAPE');
				const rows = page.locator('#the-list tr[id^="post-"]');
				for (let index = 0; index < await rows.count(); index += 1) {
					const row = rows.nth(index);
					const title = row.locator('.row-title');
					if (await title.count() !== 1) {
						throw new Error('POST_TITLE_LIST_VALUE_MISSING');
					}
					if (
						String(await title.textContent() ?? '').trim()
							=== expectedTitle
					) {
						const value = Number(
							String(await row.getAttribute('id') ?? '')
								.replace(/^post-/u, '')
						);
						if (!Number.isSafeInteger(value) || value < 1) {
							throw new Error('POST_TITLE_LIST_ID_INVALID');
						}
						ids.push(value);
					}
				}
				const next = page.locator(
					'.tablenav.top a.next-page:visible'
				);
				if (await next.count() === 0) {
					return [...new Set(ids)].sort((a, b) => a - b);
				}
				if (await next.count() !== 1) {
					throw new Error(
						'POST_TITLE_LIST_NEXT_PAGE_AMBIGUOUS'
					);
				}
				const href = await next.getAttribute('href');
				if (!href) {
					throw new Error(
						'POST_TITLE_LIST_NEXT_PAGE_HREF_MISSING'
					);
				}
				const target = new URL(href, baseUrl);
				if (
					target.origin !== baseUrl
					|| target.pathname !== '/wp-admin/edit.php'
				) {
					throw new Error('POST_TITLE_LIST_NEXT_PAGE_PATH_ESCAPE');
				}
				pathname = target.pathname + target.search;
			}
			throw new Error('POST_TITLE_LIST_PAGINATION_LIMIT');
		}

		async function assertNoExistingSlug() {
			const active = await postIdsWithExactSlug('all');
			const trashed = await postIdsWithExactSlug('trash');
			const matches = [...new Set([...active, ...trashed])];
			if (matches.length !== 0) {
				throw new Error(
					'PREEXISTING_LOCAL_POST_WITH_REVIEWED_SLUG:'
						+ matches.join(',')
				);
			}
		}

		async function postListPageContainingId(postStatus, postId) {
			let pathname = '/wp-admin/edit.php?post_type=post&post_status='
				+ encodeURIComponent(postStatus);
			for (let pageNumber = 1; pageNumber <= 50; pageNumber += 1) {
				await navigate(pathname, 'POST_ID_LIST_PATH_ESCAPE');
				if (await page.locator('#post-' + postId).count() === 1) {
					const current = new URL(page.url());
					return current.pathname + current.search;
				}
				const next = page.locator(
					'.tablenav.top a.next-page:visible'
				);
				if (await next.count() === 0) return null;
				if (await next.count() !== 1) {
					throw new Error('POST_ID_LIST_NEXT_PAGE_AMBIGUOUS');
				}
				const href = await next.getAttribute('href');
				if (!href) {
					throw new Error('POST_ID_LIST_NEXT_PAGE_HREF_MISSING');
				}
				const target = new URL(href, baseUrl);
				if (
					target.origin !== baseUrl
					|| target.pathname !== '/wp-admin/edit.php'
				) {
					throw new Error('POST_ID_LIST_NEXT_PAGE_PATH_ESCAPE');
				}
				pathname = target.pathname + target.search;
			}
			throw new Error('POST_ID_LIST_PAGINATION_LIMIT');
		}

		async function deleteTrashedPostByIdIfPresent() {
			const pagePath = await postListPageContainingId(
				'trash',
				localPostId
			);
			if (!pagePath) return false;
			const row = page.locator('#post-' + localPostId);
			if (await row.count() !== 1) {
				throw new Error('CLEANUP_TRASHED_POST_ROW_MISSING');
			}
			const rowText = String(await row.textContent() ?? '');
			if (
				!rowText.includes(plan.source.post_title)
				&& !rowText.includes(plan.desired.post_title)
				&& !rowText.includes(recoveryMarkerTitle)
			) {
				throw new Error('CLEANUP_TRASHED_POST_IDENTITY_MISMATCH');
			}
			const author = row.locator('.author.column-author a');
			if (await author.count() !== 1) {
				throw new Error('CLEANUP_TRASHED_POST_AUTHOR_MISSING');
			}
			const authorUrl = new URL(await author.getAttribute('href'), baseUrl);
			if (
				Number(authorUrl.searchParams.get('author'))
					!== plan.author.expected_user_id
			) {
				throw new Error('CLEANUP_TRASHED_POST_AUTHOR_MISMATCH');
			}
			const remove = row.locator('a.submitdelete');
			if (await remove.count() !== 1) {
				throw new Error('CLEANUP_DELETE_POST_PERMANENTLY_MISSING');
			}
			await withAcceptedBrowserDialog(async () => {
				await remove.click();
			});
			await page.waitForTimeout(700);
			await assertNoRouteViolations();
			if (
				await postListPageContainingId('trash', localPostId)
					!== null
			) {
				throw new Error('CLEANUP_POST_STILL_PRESENT');
			}
			return true;
		}

		async function readPersistedState() {
			const wordpress = await readWordPressFields(
				'temporary:' + localPostId
			);
			const rankMath = await readRankMathFields();
			const author = await assertExpectedAuthor();
			const slug = await readSlug();
			const postStatus = await assertDraftStatus({
				allowAutoDraft: false,
			});
			const media = await readFeaturedImage();
			return {
				...wordpress,
				...rankMath,
				slug,
				post_status: postStatus,
				author,
				featured_image: media,
			};
		}

		function assertDesiredState(state) {
			for (const field of [
				'post_title',
				'post_content',
				'post_excerpt',
				'rank_math_focus_keyword',
				'rank_math_title',
				'rank_math_description',
			]) {
				if (state[field] !== plan.desired[field]) {
					throw new Error('PERSISTED_FIELD_MISMATCH:' + field);
				}
			}
			if (
				state.slug !== plan.desired.slug
				|| state.post_status !== 'draft'
				|| state.author.id !== plan.author.expected_user_id
				|| state.author.display !== plan.author.expected_display_name
				|| state.featured_image.attachment_id !== uploadedAttachmentId
				|| state.featured_image.attachment_id
					=== plan.source.forbidden_production_attachment_id
				|| state.featured_image.filename
					!== runtimeUploadFilename
				|| state.featured_image.alt
					!== plan.desired.featured_image_alt
			) {
				throw new Error('PERSISTED_IDENTITY_OR_MEDIA_MISMATCH');
			}
		}

		async function cleanupPostByUi() {
			if (localPostId === null) return;
			if (
				localPostId === plan.source.production_post_id
				|| localPostId
					=== plan.source.forbidden_production_attachment_id
			) {
				throw new Error('CLEANUP_POST_ID_FORBIDDEN');
			}
			if (
				recoveryMode
				&& await deleteTrashedPostByIdIfPresent()
			) {
				return { status: 'deleted' };
			}
			await navigate(
				'/wp-admin/post.php?post=' + localPostId + '&action=edit',
				'CLEANUP_POST_EDITOR_PATH_ESCAPE'
			);
			const identity = page.locator('#post_ID');
			if (await identity.count() === 0) {
				const body = String(await page.locator('body').innerText())
					.replace(/\\s+/gu, ' ')
					.trim();
				if (
					recoveryMode
					&& /(?:elemento|art[ií]culo).{0,80}no existe|item.{0,80}doesn['’]t exist|perhaps it was deleted|quiz[aá]s fue eliminado/iu
						.test(body)
				) {
					return { status: 'already_absent' };
				}
				throw new Error('CLEANUP_POST_EDITOR_IDENTITY_MISSING');
			}
			if (
				await identity.count() !== 1
				|| Number(await identity.inputValue()) !== localPostId
			) {
				throw new Error('CLEANUP_POST_EDITOR_IDENTITY_MISMATCH');
			}
			await assertExpectedAuthor();
			if (await page.locator('#auto_draft[value="1"]').count() === 1) {
				const autoDraftTitle = await titleField();
				await autoDraftTitle.fill(plan.source.post_title);
				await saveDraft();
				await navigate(
					'/wp-admin/post.php?post='
						+ localPostId + '&action=edit',
					'CLEANUP_SAVED_DRAFT_PATH_ESCAPE'
				);
			}
			const title = await titleField();
			const currentTitle = (
				await controlValue(title)
			).replace(/\\r\\n?/gu, '\\n');
			if (
				currentTitle !== plan.source.post_title
				&& currentTitle !== plan.desired.post_title
				&& currentTitle !== recoveryMarkerTitle
				&& !(
					localPostCapturedFromNewEditor
					&& currentTitle === ''
				)
			) {
				throw new Error('CLEANUP_POST_TITLE_IDENTITY_MISMATCH');
			}
			const currentSlug = await readSlug();
			if (
				currentSlug !== plan.source.slug
				&& currentSlug !== plan.desired.slug
				&& currentSlug !== recoveryMarkerSlug
			) {
				throw new Error('CLEANUP_POST_SLUG_IDENTITY_MISMATCH');
			}
			const trash = page.locator(
				'button.editor-post-trash:visible'
			);
			if (await trash.count() !== 1) {
				throw new Error('CLEANUP_MOVE_TO_TRASH_MISSING');
			}
			await trash.click({ trial: true });
			await trash.click();
			const confirm = page.getByRole('button').filter({
				hasText: /^Mover a la papelera$|^Move to trash$/iu,
			});
			if (await confirm.count() === 1 && await confirm.isVisible()) {
				await confirm.click();
			}
			await page.waitForTimeout(1000);
			await assertNoRouteViolations();
			if (!await deleteTrashedPostByIdIfPresent()) {
				throw new Error('CLEANUP_TRASHED_POST_ROW_MISSING');
			}
			return { status: 'deleted' };
		}

		async function deleteAttachmentByUi(attachmentId) {
			if (
				!Number.isSafeInteger(attachmentId)
				|| attachmentId < 1
				|| attachmentId
					=== plan.source.forbidden_production_attachment_id
			) {
				throw new Error('CLEANUP_ATTACHMENT_ID_INVALID_OR_FORBIDDEN');
			}
			await navigate(
				'/wp-admin/post.php?post=' + attachmentId + '&action=edit',
				'CLEANUP_MEDIA_EDITOR_PATH_ESCAPE'
			);
			const source = page.locator([
				'#attachment_url',
				'input[name="attachment_url"]',
			].join(', '));
			if (await source.count() !== 1) {
				throw new Error('CLEANUP_MEDIA_SOURCE_MISSING');
			}
			const filename = decodeURIComponent(
				new URL(await source.inputValue(), baseUrl)
					.pathname.split('/').at(-1)
			);
			if (filename !== runtimeUploadFilename) {
				throw new Error('CLEANUP_MEDIA_FILENAME_IDENTITY_MISMATCH');
			}
			const remove = page.locator('#delete-action a.submitdelete:visible');
			if (await remove.count() !== 1) {
				throw new Error('CLEANUP_DELETE_MEDIA_PERMANENTLY_MISSING');
			}
			await withAcceptedBrowserDialog(async () => {
				await remove.click();
			});
			await page.waitForTimeout(700);
			await assertNoRouteViolations();
		}

		async function cleanupMediaByUi() {
			const ids = uploadedAttachmentId !== null && !recoveryMode
				? [uploadedAttachmentId]
				: (
					uploadStarted
						? await exactAttachmentIdsByFilename(
							runtimeUploadFilename
						)
						: []
				);
			if (
				uploadStarted
				&& (
					(!recoveryMode && ids.length !== 1)
					|| (recoveryMode && ids.length > 1)
				)
			) {
				throw new Error(
					'CLEANUP_MEDIA_RECOVERY_NOT_UNIQUE:' + ids.join(',')
				);
			}
			for (const id of ids) {
				await deleteAttachmentByUi(id);
			}
			if (uploadStarted) {
				const remaining = await exactAttachmentIdsByFilename(
					runtimeUploadFilename
				);
				if (remaining.length !== 0) {
					throw new Error(
						'CLEANUP_MEDIA_STILL_PRESENT:' + remaining.join(',')
					);
				}
			}
			return {
				status: ids.length === 0 ? 'already_absent' : 'deleted',
			};
		}

		async function recoverAllocationIntentByUi() {
			const started = Date.parse(allocationStartedAt);
			const age = Date.now() - started;
			if (
				!Number.isFinite(started)
				|| age < -5 * 60 * 1000
				|| age > 2 * 60 * 60 * 1000
			) {
				throw new Error('ALLOCATION_INTENT_AGE_OUTSIDE_WINDOW');
			}
			const baseline = new Set(preexistingAutoDraftIds);
			const autoDraftIds = (await postIdsByStatus('auto-draft'))
				.filter((id) => !baseline.has(id));
			const markerDraftIds = await postIdsWithExactTitle(
				'all',
				recoveryMarkerTitle
			);
			const candidates = [
				...new Set([...autoDraftIds, ...markerDraftIds]),
			];
			if (candidates.length === 0) {
				return {
					status: 'already_absent',
					candidate_count: 0,
				};
			}
			if (candidates.length !== 1) {
				throw new Error(
					'ALLOCATION_INTENT_CANDIDATE_NOT_UNIQUE:'
						+ candidates.join(',')
				);
			}
			localPostId = candidates[0];
			localPostCapturedFromNewEditor = true;
			await navigate(
				'/wp-admin/post.php?post=' + localPostId + '&action=edit',
				'ALLOCATION_INTENT_EDITOR_PATH_ESCAPE'
			);
			const identity = page.locator('#post_ID');
			if (
				await identity.count() !== 1
				|| Number(await identity.inputValue()) !== localPostId
			) {
				throw new Error('ALLOCATION_INTENT_IDENTITY_MISMATCH');
			}
			await assertExpectedAuthor();
			const currentTitle = (
				await controlValue(await titleField())
			).replace(/\\r\\n?/gu, '\\n');
			const isAutoDraft = await page.locator(
				'#auto_draft[value="1"]'
			).count() === 1;
			if (
				currentTitle !== recoveryMarkerTitle
				&& !(isAutoDraft && currentTitle === '')
			) {
				throw new Error('ALLOCATION_INTENT_TITLE_MISMATCH');
			}
			await cleanupPostByUi();
			return {
				status: 'deleted',
				candidate_count: 1,
				local_post_id: localPostId,
			};
		}

		if (![
			'preflight',
			'allocate',
			'full',
			'recover',
			'recover-intent',
		].includes(mode)) {
			throw new Error('TEMPORARY_DRAFT_MODE_INVALID');
		}
		if (
			typeof runNonce !== 'string'
			|| !/^[a-f0-9]{24}$/u.test(runNonce)
			|| typeof runtimeUploadFilename !== 'string'
			|| !/^preventa-manga-chile-akibara--akb-[a-f0-9]{24}\\.webp$/u
				.test(runtimeUploadFilename)
			|| !runtimeUploadFilename.includes(runNonce)
			|| !Number.isFinite(Date.parse(allocationStartedAt))
			|| Date.now() - Date.parse(allocationStartedAt)
				< -5 * 60 * 1000
			|| Date.now() - Date.parse(allocationStartedAt)
				> 2 * 60 * 60 * 1000
			|| !Array.isArray(preexistingAutoDraftIds)
			|| preexistingAutoDraftIds.some((id, index, all) => (
				!Number.isSafeInteger(id)
				|| id < 1
				|| index > 0 && all[index - 1] >= id
			))
		) {
			throw new Error('TEMPORARY_DRAFT_RUN_IDENTITY_INVALID');
		}
		if (mode === 'preflight') {
			await assertNoExistingSlug();
			const preexistingMedia = await exactAttachmentIdsByFilename(
				runtimeUploadFilename
			);
			if (preexistingMedia.length !== 0) {
				throw new Error(
					'PREEXISTING_LOCAL_REPLACEMENT_MEDIA:'
						+ preexistingMedia.join(',')
				);
			}
			return {
				status: 'preflight_complete',
				plan_sha256: plan.plan_sha256,
				auto_draft_ids: await postIdsByStatus('auto-draft'),
			};
		}
		if (mode === 'recover-intent') {
			const postCleanup = await recoverAllocationIntentByUi();
			const mediaIds = await exactAttachmentIdsByFilename(
				runtimeUploadFilename
			);
			if (mediaIds.length !== 0) {
				throw new Error(
					'ALLOCATION_INTENT_UNEXPECTED_MEDIA:'
						+ mediaIds.join(',')
				);
			}
			await assertNoRouteViolations();
			return {
				status: 'intent_recovery_verified',
				plan_sha256: plan.plan_sha256,
				post_cleanup: postCleanup,
				media_cleanup: { status: 'already_absent' },
				remaining_route_violations: routeViolations,
			};
		}
		if (mode === 'allocate') {
			try {
				await assertNoExistingSlug();
				const currentAutoDraftIds = await postIdsByStatus(
					'auto-draft'
				);
				if (
					JSON.stringify(currentAutoDraftIds)
						!== JSON.stringify(preexistingAutoDraftIds)
				) {
					throw new Error(
						'AUTO_DRAFT_BASELINE_CHANGED_BEFORE_ALLOCATION'
					);
				}
				const preexistingMedia = await exactAttachmentIdsByFilename(
					runtimeUploadFilename
				);
				if (preexistingMedia.length !== 0) {
					throw new Error(
						'PREEXISTING_LOCAL_REPLACEMENT_MEDIA:'
							+ preexistingMedia.join(',')
					);
				}
				await navigate(
					'/wp-admin/post-new.php?post_type=post',
					'NEW_POST_PATH_ESCAPE'
				);
				await captureNewEditorPostId();
				const markerTitle = await titleField();
				await markerTitle.fill(recoveryMarkerTitle);
				await saveDraft();
				return {
					status: 'allocated_draft',
					plan_sha256: plan.plan_sha256,
					local_post_id: localPostId,
					post_status: 'draft',
				};
			} catch (error) {
				if (localPostId !== null) {
					try {
						await cleanupPostByUi();
					} catch (cleanupError) {
						throw new Error(
							'ALLOCATE_AND_CLEANUP_FAILED:'
								+ JSON.stringify({
									primary: String(error.message ?? error),
									cleanup: String(
										cleanupError.message ?? cleanupError
									),
									local_post_id: localPostId,
								})
						);
					}
				}
				throw error;
			}
		}
		if (
			!Number.isSafeInteger(localPostId)
			|| localPostId < 1
			|| localPostId === plan.source.production_post_id
			|| localPostId
				=== plan.source.forbidden_production_attachment_id
		) {
			throw new Error('ALLOCATED_LOCAL_POST_ID_INVALID_OR_FORBIDDEN');
		}
		if (mode === 'recover') {
			let postCleanup = null;
			let mediaCleanup = null;
			try {
				postCleanup = await cleanupPostByUi();
			} catch (error) {
				cleanupErrors.push('POST:' + String(error.message ?? error));
			}
			try {
				mediaCleanup = await cleanupMediaByUi();
			} catch (error) {
				cleanupErrors.push('MEDIA:' + String(error.message ?? error));
			}
			try {
				await assertNoRouteViolations();
			} catch (error) {
				cleanupErrors.push('ROUTE:' + String(error.message ?? error));
			}
			if (cleanupErrors.length > 0) {
				throw new Error(
					'TEMPORARY_DRAFT_RECOVERY_FAILED:'
						+ JSON.stringify({
							cleanup: cleanupErrors,
							local_post_id: localPostId,
						})
				);
			}
			return {
				status: 'recovery_verified',
				plan_sha256: plan.plan_sha256,
				local_post_id: localPostId,
				post_cleanup: postCleanup,
				media_cleanup: mediaCleanup,
				remaining_route_violations: routeViolations,
			};
		}

		try {
			await navigate(
				'/wp-admin/post.php?post=' + localPostId + '&action=edit',
				'ALLOCATED_POST_EDITOR_PATH_ESCAPE'
			);
			const allocatedIdentity = page.locator('#post_ID');
			if (
				await allocatedIdentity.count() !== 1
				|| Number(await allocatedIdentity.inputValue()) !== localPostId
			) {
				throw new Error('ALLOCATED_POST_EDITOR_IDENTITY_MISMATCH');
			}
			await assertDraftStatus({ allowAutoDraft: true });
			const allocatedTitle = (
				await controlValue(await titleField())
			).replace(/\\r\\n?/gu, '\\n');
			if (allocatedTitle !== recoveryMarkerTitle) {
				throw new Error('ALLOCATED_POST_MARKER_MISMATCH');
			}
			await assertExpectedAuthor();
			await writeWordPressFields(plan.source, 'source:24977');
			await saveDraft();
			await setSlug(plan.source.slug);
			await writeRankMathFields(plan.source);
			await saveDraft();
			baselineScore = await stableRankMathScore();

			await writeWordPressFields(
				plan.desired,
				'desired:' + localPostId
			);
			await setSlug(plan.desired.slug);
			await assertExpectedAuthor();
			await writeRankMathFields(plan.desired);
			await uploadFeaturedImage();
			await saveDraft();

			await navigate(
				'/wp-admin/post.php?post=' + localPostId + '&action=edit',
				'PERSISTENCE_RELOAD_PATH_ESCAPE'
			);
			persistedState = await readPersistedState();
			assertDesiredState(persistedState);
			persistedScore = await stableRankMathScore();
			if (
				persistedScore.score
					< plan.desired.minimum_rank_math_score
			) {
				throw new Error(
					'RANK_MATH_SCORE_BELOW_TARGET:'
						+ persistedScore.score
				);
			}
			await assertNoRouteViolations();
		} catch (error) {
			primaryError = error;
		} finally {
			try {
				await cleanupPostByUi();
			} catch (error) {
				cleanupErrors.push('POST:' + String(error.message ?? error));
			}
			try {
				await cleanupMediaByUi();
			} catch (error) {
				cleanupErrors.push('MEDIA:' + String(error.message ?? error));
			}
			try {
				await assertNoRouteViolations();
			} catch (error) {
				cleanupErrors.push('ROUTE:' + String(error.message ?? error));
			}
		}

		if (primaryError || cleanupErrors.length > 0) {
			throw new Error(
				'TEMPORARY_DRAFT_FLOW_FAILED:'
					+ JSON.stringify({
						primary: primaryError
							? String(primaryError.message ?? primaryError)
							: null,
						cleanup: cleanupErrors,
						local_post_id: localPostId,
						uploaded_attachment_id: uploadedAttachmentId,
					})
			);
		}
		return {
			status: 'validated_and_cleaned',
			plan_sha256: plan.plan_sha256,
			local_post_id: localPostId,
			uploaded_attachment_id: uploadedAttachmentId,
			forbidden_production_attachment_id:
				plan.source.forbidden_production_attachment_id,
			baseline_score: baselineScore,
			persisted_score: persistedScore,
			persisted: {
				slug: persistedState.slug,
				post_status: persistedState.post_status,
				author: persistedState.author,
				featured_image: persistedState.featured_image,
			},
			cleanup: {
				draft_permanently_deleted_by_ui: true,
				attachment_permanently_deleted_by_ui: true,
				remaining_route_violations: routeViolations,
			},
		};
	}`;
}

export function assertTemporaryDraftBrowserFunctionSafety(source) {
	try {
		Function(`"use strict"; return (${source});`);
	} catch (error) {
		fail(`browser function syntax is invalid: ${error.message}`);
	}
	const prohibited = [
		/fetch\s*\(/iu,
		/page\.request\b/iu,
		/admin-ajax\.php/iu,
		/update_post_meta/iu,
		/update_option/iu,
		/\$wpdb/iu,
		/rank_math_seo_score/iu,
	];
	for (const expression of prohibited) {
		if (expression.test(source)) {
			fail(`browser function contains prohibited channel ${expression}`);
		}
	}
	for (const required of [
		'AKB_BLOG_SEO_TEMPORARY_DRAFT_OFFICIAL_UI',
		"await page.route('**/*'",
		'UNSAFE_STATUS:',
		'button.editor-post-save-draft:visible',
		'button.editor-post-saved-state.is-saved',
		'NEW_EDITOR_AUTO_DRAFT_IDENTITY_MISSING',
		'POST_STATUS_REMAINED_AUTO_DRAFT',
		'AUTO_DRAFT_BASELINE_CHANGED_BEFORE_ALLOCATION',
		'recoverAllocationIntentByUi',
		'runtimeUploadFilename',
		'unparseable-json',
		'finally {',
		'cleanupPostByUi',
		'cleanupMediaByUi',
		'button.editor-post-trash:visible',
		'#delete-action a.submitdelete:visible',
		'stableSamples >= 4',
		'RANK_MATH_SCORE_BELOW_TARGET',
	]) {
		if (!source.includes(required)) {
			fail(`browser function is missing safety marker ${required}`);
		}
	}
	if (
		/\.editor-post-publish-button[^;]{0,300}\.click\s*\(/iu
			.test(source)
	) {
		fail('browser function may not click the publish/update control');
	}
	return source;
}

export function buildTemporaryDraftPlanFromFiles({
	productionSourcePath,
	desiredStatePath,
	imagePath,
	repository = repositoryRoot(),
}) {
	const production = validateJsonInput(
		productionSourcePath,
		'--production-source',
		repository
	);
	const desired = validateJsonInput(
		desiredStatePath,
		'--desired',
		repository
	);
	const image = validateImageInput(imagePath, repository);
	return {
		plan: buildTemporaryDraftPlan({
			productionSource: production.parsed,
			productionSourceSha256: production.sha256,
			desiredState: desired.parsed,
			desiredStateSha256: desired.sha256,
			image: image.contract,
		}),
		absoluteImagePath: image.absoluteFile,
	};
}

function recoveryJournalPath(report) {
	return `${report}.recovery.json`;
}

function loadRecoveryJournal(file, {
	plan,
	baseUrl,
	session,
}) {
	if (!fs.existsSync(file)) return null;
	const stats = fs.lstatSync(file);
	if (
		!stats.isFile()
		|| stats.isSymbolicLink()
		|| (stats.mode & 0o777) !== 0o600
	) {
		fail('recovery journal must be a regular 0600 local file');
	}
	let journal;
	try {
		journal = JSON.parse(fs.readFileSync(file, 'utf8'));
	} catch (error) {
		fail(`recovery journal is invalid JSON: ${error.message}`);
	}
	const exactKeys = [
		'schema_version',
		'journal_type',
		'phase',
		'plan_sha256',
		'target_origin',
		'session',
		'run_nonce',
		'runtime_upload_filename',
		'allocation_started_at',
		'preexisting_auto_draft_ids',
		'local_post_id',
	];
	const started = Date.parse(journal?.allocation_started_at);
	const age = Date.now() - started;
	if (
		!journal
		|| typeof journal !== 'object'
		|| Array.isArray(journal)
		|| Object.keys(journal).length !== exactKeys.length
		|| exactKeys.some((key) => !(key in journal))
		|| journal.schema_version !== 2
		|| journal.journal_type
			!== 'akibara_blog_seo_temporary_draft_recovery'
		|| !['allocating', 'allocated'].includes(journal.phase)
		|| journal.plan_sha256 !== plan.plan_sha256
		|| journal.target_origin !== baseUrl
		|| journal.session !== session
		|| !/^[a-f0-9]{24}$/u.test(journal.run_nonce ?? '')
		|| journal.runtime_upload_filename
			!== `preventa-manga-chile-akibara--akb-${
				journal.run_nonce
			}.webp`
		|| !Number.isFinite(started)
		|| age < -5 * 60 * 1000
		|| age > 2 * 60 * 60 * 1000
		|| !Array.isArray(journal.preexisting_auto_draft_ids)
		|| journal.preexisting_auto_draft_ids.some((id, index, all) => (
			!Number.isSafeInteger(id)
			|| id < 1
			|| index > 0 && all[index - 1] >= id
		))
		|| (
			journal.phase === 'allocating'
				? journal.local_post_id !== null
				: (
					!Number.isSafeInteger(journal.local_post_id)
					|| journal.local_post_id < 1
					|| journal.local_post_id
						=== plan.source.production_post_id
					|| journal.local_post_id
						=== plan.source.forbidden_production_attachment_id
				)
		)
	) {
		fail('recovery journal identity does not match this reviewed run');
	}
	return journal;
}

function assertRecoveryResult(result, plan, localPostId) {
	if (
		result?.status !== 'recovery_verified'
		|| result.plan_sha256 !== plan.plan_sha256
		|| result.local_post_id !== localPostId
		|| result.remaining_route_violations?.length !== 0
		|| !['deleted', 'already_absent'].includes(
			result.post_cleanup?.status
		)
		|| !['deleted', 'already_absent'].includes(
			result.media_cleanup?.status
		)
	) {
		fail('external UI recovery did not prove a residue-free state');
	}
	return result;
}

function assertIntentRecoveryResult(result, plan) {
	if (
		result?.status !== 'intent_recovery_verified'
		|| result.plan_sha256 !== plan.plan_sha256
		|| result.remaining_route_violations?.length !== 0
		|| !['deleted', 'already_absent'].includes(
			result.post_cleanup?.status
		)
		|| result.media_cleanup?.status !== 'already_absent'
	) {
		fail('allocation-intent UI recovery did not prove a safe state');
	}
	return result;
}

async function main() {
	const repository = repositoryRoot();
	const validateOnly = hasOption('--validate-only');
	const apply = hasOption('--apply');
	if (validateOnly === apply) {
		fail('choose exactly one mode: --validate-only or --apply');
	}
	const { plan, absoluteImagePath } = buildTemporaryDraftPlanFromFiles({
		productionSourcePath: option('--production-source'),
		desiredStatePath: option('--desired'),
		imagePath: option('--image'),
		repository,
	});
	validateTemporaryDraftPlan(plan);
	const baseUrl = validateBaseUrl(
		option('--base-url', TEMPORARY_DRAFT_CONTRACT.targetOrigin)
	);
	const source = assertTemporaryDraftBrowserFunctionSafety(
		temporaryDraftBrowserFunction({
			baseUrl,
			plan,
			absoluteImagePath,
		})
	);
	if (validateOnly) {
		process.stdout.write(`${JSON.stringify({
			result: 'PASS',
			mode: 'validate-only',
			plan_sha256: plan.plan_sha256,
			production_post_id: plan.source.production_post_id,
			target_origin: plan.target_origin,
			minimum_rank_math_score:
				plan.desired.minimum_rank_math_score,
			image: plan.image,
			browser_function_safety: 'PASS',
			playwright_cli_package: PLAYWRIGHT_CLI_PACKAGE,
			browser_writes_executed: 0,
		}, null, 2)}\n`);
		return;
	}
	if (option('--confirm-plan-sha256') !== plan.plan_sha256) {
		fail('apply requires exact --confirm-plan-sha256');
	}
	const session = validateSession(option('--session'));
	const report = validateReportPath(option('--report'), repository);
	const recoveryJournal = recoveryJournalPath(report);
	const runtime = fs.mkdtempSync(
		path.join(os.tmpdir(), 'akb-blog-temp-draft-ui-')
	);
	fs.chmodSync(runtime, 0o700);
	let result;
	let externalRecovery;
	try {
		const prepareRuntimeImage = (filename) => {
			const runtimeImage = path.join(runtime, filename);
			fs.copyFileSync(
				absoluteImagePath,
				runtimeImage,
				fs.constants.COPYFILE_EXCL
			);
			fs.chmodSync(runtimeImage, 0o400);
			const runtimeImageBytes = fs.readFileSync(runtimeImage);
			const runtimeDimensions = readWebpDimensions(runtimeImageBytes);
			if (
				sha256(runtimeImageBytes) !== plan.image.sha256
				|| runtimeImageBytes.length !== plan.image.bytes
				|| runtimeDimensions.width !== plan.image.width
				|| runtimeDimensions.height !== plan.image.height
			) {
				fail('runtime image copy does not match the reviewed plan');
			}
			return runtimeImage;
		};
		const browserSource = (
			mode,
			identity,
			runtimeImage,
			allocatedLocalPostId = null
		) => (
			assertTemporaryDraftBrowserFunctionSafety(
			temporaryDraftBrowserFunction({
				baseUrl,
				plan,
				absoluteImagePath: runtimeImage,
				mode,
				allocatedLocalPostId,
				runNonce: identity.runNonce,
				runtimeUploadFilename: identity.runtimeUploadFilename,
				preexistingAutoDraftIds:
					identity.preexistingAutoDraftIds,
				allocationStartedAt: identity.allocationStartedAt,
			})
			)
		);
		const priorJournal = loadRecoveryJournal(recoveryJournal, {
			plan,
			baseUrl,
			session,
		});
		if (priorJournal) {
			const priorIdentity = {
				runNonce: priorJournal.run_nonce,
				runtimeUploadFilename:
					priorJournal.runtime_upload_filename,
				preexistingAutoDraftIds:
					priorJournal.preexisting_auto_draft_ids,
				allocationStartedAt:
					priorJournal.allocation_started_at,
			};
			const priorRuntimeImage = prepareRuntimeImage(
				priorIdentity.runtimeUploadFilename
			);
			const recovered = runPlaywrightFunction(
				session,
				browserSource(
					priorJournal.phase === 'allocating'
						? 'recover-intent'
						: 'recover',
					priorIdentity,
					priorRuntimeImage,
					priorJournal.local_post_id
				),
				runtime
			);
			if (priorJournal.phase === 'allocating') {
				assertIntentRecoveryResult(recovered, plan);
			} else {
				assertRecoveryResult(
					recovered,
					plan,
					priorJournal.local_post_id
				);
			}
			fs.rmSync(recoveryJournal);
			fs.rmSync(priorRuntimeImage);
		}

		const runIdentity = createRunIdentity();
		const runtimeImage = prepareRuntimeImage(
			runIdentity.runtimeUploadFilename
		);
		const preflight = runPlaywrightFunction(
			session,
			browserSource('preflight', {
				...runIdentity,
				preexistingAutoDraftIds: [],
			}, runtimeImage),
			runtime
		);
		if (
			preflight?.status !== 'preflight_complete'
			|| preflight.plan_sha256 !== plan.plan_sha256
			|| !Array.isArray(preflight.auto_draft_ids)
			|| preflight.auto_draft_ids.some((id, index, all) => (
				!Number.isSafeInteger(id)
				|| id < 1
				|| index > 0 && all[index - 1] >= id
			))
		) {
			fail('preflight did not return a safe auto-draft baseline');
		}
		runIdentity.preexistingAutoDraftIds = preflight.auto_draft_ids;
		const journalValue = (phase, localPostId) => ({
			schema_version: 2,
			journal_type: 'akibara_blog_seo_temporary_draft_recovery',
			phase,
			plan_sha256: plan.plan_sha256,
			target_origin: baseUrl,
			session,
			run_nonce: runIdentity.runNonce,
			runtime_upload_filename:
				runIdentity.runtimeUploadFilename,
			allocation_started_at:
				runIdentity.allocationStartedAt,
			preexisting_auto_draft_ids:
				runIdentity.preexistingAutoDraftIds,
			local_post_id: localPostId,
		});
		if (
			process.env.AKB_TEMP_DRAFT_TEST_MODE === '1'
			&& process.env.AKB_TEMP_DRAFT_TEST_FAIL_INTENT_WRITE === '1'
		) {
			fail('simulated allocation-intent journal write failure');
		}
		writeJson0600(
			recoveryJournal,
			journalValue('allocating', null)
		);

		let allocation;
		try {
			allocation = runPlaywrightFunction(
				session,
				browserSource(
					'allocate',
					runIdentity,
					runtimeImage
				),
				runtime
			);
		} catch (allocationError) {
			let recoveryError = null;
			try {
				const recovered = runPlaywrightFunction(
					session,
					browserSource(
						'recover-intent',
						runIdentity,
						runtimeImage
					),
					runtime
				);
				assertIntentRecoveryResult(recovered, plan);
				fs.rmSync(recoveryJournal);
			} catch (error) {
				recoveryError = error;
			}
			fail(
				'allocation or allocation-intent recovery failed: '
					+ JSON.stringify({
						allocation: String(
							allocationError.message ?? allocationError
						),
						recovery: recoveryError
							? String(recoveryError.message ?? recoveryError)
							: 'verified',
						recovery_journal: fs.existsSync(recoveryJournal)
							? recoveryJournal
							: null,
					})
			);
		}
		if (
			allocation?.status !== 'allocated_draft'
			|| allocation.plan_sha256 !== plan.plan_sha256
			|| !Number.isSafeInteger(allocation.local_post_id)
			|| allocation.local_post_id < 1
			|| allocation.local_post_id === plan.source.production_post_id
			|| allocation.local_post_id
				=== plan.source.forbidden_production_attachment_id
			|| allocation.post_status !== 'draft'
		) {
			let recoveryError = null;
			try {
				const recovered = runPlaywrightFunction(
					session,
					browserSource(
						'recover-intent',
						runIdentity,
						runtimeImage
					),
					runtime
				);
				assertIntentRecoveryResult(recovered, plan);
				fs.rmSync(recoveryJournal);
			} catch (error) {
				recoveryError = error;
			}
			fail(
				'allocation did not return the reviewed marked local draft: '
					+ (
						recoveryError
							? String(
								recoveryError.message ?? recoveryError
							)
							: 'intent recovery verified'
					)
			);
		}
		try {
			if (
				process.env.AKB_TEMP_DRAFT_TEST_MODE === '1'
				&& process.env.AKB_TEMP_DRAFT_TEST_FAIL_JOURNAL_PROMOTION
					=== '1'
			) {
				throw new Error(
					'simulated recovery journal phase-change failure'
				);
			}
			replaceJson0600(
				recoveryJournal,
				journalValue('allocated', allocation.local_post_id)
			);
		} catch (journalError) {
			let cleanupError = null;
			try {
				const recovered = runPlaywrightFunction(
					session,
					browserSource(
						'recover',
						runIdentity,
						runtimeImage,
						allocation.local_post_id
					),
					runtime
				);
				assertRecoveryResult(
					recovered,
					plan,
					allocation.local_post_id
				);
				fs.rmSync(recoveryJournal);
			} catch (error) {
				cleanupError = error;
			}
			fail(
				'recovery journal write failed after allocation: '
					+ JSON.stringify({
						journal: String(
							journalError.message ?? journalError
						),
						cleanup: cleanupError
							? String(cleanupError.message ?? cleanupError)
							: 'verified',
					})
			);
		}

		let primaryError = null;
		let recoveryError = null;
		try {
			result = runPlaywrightFunction(
				session,
				browserSource(
					'full',
					runIdentity,
					runtimeImage,
					allocation.local_post_id
				),
				runtime
			);
		} catch (error) {
			primaryError = error;
		} finally {
			try {
				externalRecovery = runPlaywrightFunction(
					session,
					browserSource(
						'recover',
						runIdentity,
						runtimeImage,
						allocation.local_post_id
					),
					runtime
				);
				assertRecoveryResult(
					externalRecovery,
					plan,
					allocation.local_post_id
				);
				fs.rmSync(recoveryJournal);
			} catch (error) {
				recoveryError = error;
			}
		}
		if (primaryError || recoveryError) {
			fail(
				'main flow or external recovery failed: '
					+ JSON.stringify({
						primary: primaryError
							? String(primaryError.message ?? primaryError)
							: null,
						recovery: recoveryError
							? String(recoveryError.message ?? recoveryError)
							: null,
						recovery_journal: fs.existsSync(recoveryJournal)
							? recoveryJournal
							: null,
					})
			);
		}
	} finally {
		fs.rmSync(runtime, { recursive: true, force: true });
	}
	if (
		result?.status !== 'validated_and_cleaned'
		|| result.plan_sha256 !== plan.plan_sha256
		|| result.forbidden_production_attachment_id
			!== TEMPORARY_DRAFT_CONTRACT.forbiddenProductionAttachmentId
		|| result.persisted_score?.score
			< plan.desired.minimum_rank_math_score
		|| result.persisted?.post_status !== 'draft'
		|| result.cleanup?.draft_permanently_deleted_by_ui !== true
		|| result.cleanup?.attachment_permanently_deleted_by_ui !== true
		|| result.cleanup?.remaining_route_violations?.length !== 0
	) {
		fail('browser result does not prove persistence, score and full cleanup');
	}
	const evidence = {
		schema_version: 1,
		report_type:
			'akibara_blog_seo_temporary_docker_draft_official_ui_evidence',
		environment: 'docker',
		target_origin: baseUrl,
		plan_sha256: plan.plan_sha256,
		completed_at: new Date().toISOString(),
		playwright_cli_package: PLAYWRIGHT_CLI_PACKAGE,
		external_recovery: externalRecovery,
		result,
		policy: plan.application_policy,
	};
	writeJson0600(report, evidence);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		mode: 'apply',
		plan_sha256: plan.plan_sha256,
		score: result.persisted_score.score,
		playwright_cli_package: PLAYWRIGHT_CLI_PACKAGE,
		cleanup: result.cleanup,
		report,
	}, null, 2)}\n`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	main().catch((error) => {
		process.stderr.write(`${error.message}\n`);
		process.exitCode = 1;
	});
}
