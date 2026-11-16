#!/usr/bin/env node

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	inlineVisualSha256,
	validateInlineVisualManifest,
} from './lib/blog-inline-visuals-official-ui-manifest.mjs';
import {
	buildInlineVisualMarkup,
	escapeInlineVisualHtml,
	extractInlineVisualState,
	inlineVisualPlainText,
	insertInlineVisuals,
	normalizeInlineVisualText,
	resolveInlineVisualAnchor,
	stripInlineVisuals,
	topLevelGutenbergBlocks,
} from './lib/blog-inline-visuals-content.mjs';
import {
	ensureWordPressBlockEditorField,
} from './lib/wordpress-block-editor-official-ui.mjs';

const PLAYWRIGHT_CLI_PACKAGE = '@playwright/cli@0.1.17';

function fail(message) {
	throw new Error(`Inline visual official UI runner failed: ${message}`);
}

function option(name) {
	const indexes = process.argv
		.map((value, index) => value === name ? index : -1)
		.filter((index) => index !== -1);
	if (indexes.length > 1) fail(`duplicate option ${name}`);
	return indexes.length === 0 ? undefined : process.argv[indexes[0] + 1];
}

function repositoryRoot() {
	return path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
}

function inside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== ''
		&& relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function assertNoSymlinks(file, root, label) {
	let current = file;
	while (current !== root && current !== path.dirname(current)) {
		if (fs.existsSync(current) && fs.lstatSync(current).isSymbolicLink()) {
			fail(`${label} may not traverse symlinks`);
		}
		current = path.dirname(current);
	}
}

function localPath(value, roots, label, { mustExist = false } = {}) {
	if (!value) fail(`missing ${label}`);
	const file = path.resolve(value);
	const root = roots.find((candidate) => inside(file, candidate));
	if (!root) fail(`${label} must remain in an approved local directory`);
	assertNoSymlinks(file, root, label);
	if (mustExist) {
		if (
			!fs.existsSync(file)
			|| !fs.lstatSync(file).isFile()
			|| fs.lstatSync(file).isSymbolicLink()
		) {
			fail(`${label} must be a regular local file`);
		}
	}
	return file;
}

export function readWebpDimensions(buffer) {
	if (
		!Buffer.isBuffer(buffer)
		|| buffer.length < 30
		|| buffer.toString('ascii', 0, 4) !== 'RIFF'
		|| buffer.toString('ascii', 8, 12) !== 'WEBP'
	) {
		fail('asset is not a RIFF WebP file');
	}
	let offset = 12;
	while (offset + 8 <= buffer.length) {
		const type = buffer.toString('ascii', offset, offset + 4);
		const length = buffer.readUInt32LE(offset + 4);
		const start = offset + 8;
		if (start + length > buffer.length) break;
		if (type === 'VP8X' && length >= 10) {
			return {
				width: 1 + buffer.readUIntLE(start + 4, 3),
				height: 1 + buffer.readUIntLE(start + 7, 3),
			};
		}
		if (type === 'VP8 ' && length >= 10) {
			return {
				width: buffer.readUInt16LE(start + 6) & 0x3fff,
				height: buffer.readUInt16LE(start + 8) & 0x3fff,
			};
		}
		if (type === 'VP8L' && length >= 5) {
			const bits = buffer.readUInt32LE(start + 1);
			return {
				width: 1 + (bits & 0x3fff),
				height: 1 + ((bits >> 14) & 0x3fff),
			};
		}
		offset = start + length + (length % 2);
	}
	fail('asset WebP dimensions could not be decoded');
}

function validateLocalAssets(manifest, repository) {
	const files = new Map();
	for (const asset of manifest.assets) {
		const file = path.resolve(repository, asset.local_file);
		if (
			!inside(file, path.join(repository, 'output'))
			|| !fs.existsSync(file)
			|| !fs.lstatSync(file).isFile()
			|| fs.lstatSync(file).isSymbolicLink()
		) {
			fail(`unsafe or missing asset ${asset.expected_filename}`);
		}
		const bytes = fs.readFileSync(file);
		const dimensions = readWebpDimensions(bytes);
		if (
			bytes.length !== asset.bytes
			|| inlineVisualSha256(bytes) !== asset.sha256
			|| dimensions.width !== asset.width
			|| dimensions.height !== asset.height
		) {
			fail(`local asset contract drifted for ${asset.expected_filename}`);
		}
		files.set(asset.sha256, file);
	}
	if (files.size !== 32) fail('local preflight did not cover 32 assets');
	return files;
}

function writeAtomic0600(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}-${Date.now()}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		mode: 0o600,
		flag: 'wx',
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function replaceAtomic0600(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}-${Date.now()}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		mode: 0o600,
		flag: 'wx',
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function readCheckpoint(file, manifest, session) {
	if (!fs.existsSync(file)) return null;
	const stats = fs.lstatSync(file);
	if (
		!stats.isFile()
		|| stats.isSymbolicLink()
		|| (stats.mode & 0o777) !== 0o600
	) {
		fail('checkpoint must be a regular 0600 file');
	}
	let value;
	try {
		value = JSON.parse(fs.readFileSync(file, 'utf8'));
	} catch {
		fail('checkpoint is not valid JSON');
	}
	if (
		value?.schema_version !== 1
		|| value.checkpoint_type
			!== 'akibara_blog_inline_visuals_official_ui_transaction'
		|| value.manifest_sha256 !== manifest.manifest_sha256
		|| value.environment !== manifest.environment
		|| value.target_origin !== manifest.target_origin
		|| value.session !== session
		|| !Array.isArray(value.posts)
	) {
		fail('checkpoint identity does not match this transaction');
	}
	return value;
}

function resolveNpxCli(repository) {
	if (process.env.AKB_INLINE_VISUAL_TEST_MODE === '1') {
		return localPath(
			process.env.AKB_INLINE_VISUAL_TEST_NPX_CLI,
			[path.join(repository, '.local-reports')],
			'AKB_INLINE_VISUAL_TEST_NPX_CLI',
			{ mustExist: true }
		);
	}
	const sibling = path.join(path.dirname(process.execPath), 'npx');
	if (!fs.existsSync(sibling)) {
		fail('trusted npx sibling is missing from the active Node installation');
	}
	const resolved = fs.realpathSync(sibling);
	if (
		!fs.lstatSync(resolved).isFile()
		|| path.basename(resolved) !== 'npx-cli.js'
	) {
		fail('trusted npx CLI resolution is invalid');
	}
	return resolved;
}

export function parseInlineVisualPlaywrightResult(stdout, stderr = '') {
	let outer;
	try {
		outer = JSON.parse(stdout);
	} catch {
		fail(`Playwright result is invalid: ${(stderr || stdout).slice(0, 900)}`);
	}
	if (
		outer?.isError === true
		|| typeof outer?.error === 'string'
		|| typeof outer?.result !== 'string'
	) {
		fail(
			`Playwright browser function reported an error: ${
				String(outer?.error ?? outer?.result ?? 'missing result')
					.slice(0, 900)
			}`
		);
	}
	try {
		return JSON.parse(outer.result);
	} catch {
		fail(`Playwright result payload is invalid: ${outer.result.slice(0, 900)}`);
	}
}

function runBrowser(session, source, runtime) {
	const file = path.join(
		runtime,
		`inline-visual-${process.pid}-${Date.now()}.js`
	);
	fs.writeFileSync(file, `${source}\n`, { mode: 0o600, flag: 'wx' });
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
				`--filename=${file}`,
			],
			{
				cwd: runtime,
				encoding: 'utf8',
				maxBuffer: 32 * 1024 * 1024,
				timeout: 20 * 60 * 1000,
			}
		);
	} finally {
		fs.rmSync(file, { force: true });
	}
	if (result.error || result.status !== 0) {
		fail(
			`Playwright failed (${result.status ?? 'spawn'}): ${
				(result.stderr || result.stdout || result.error?.message)
					.slice(0, 1800)
			}`
		);
	}
	return parseInlineVisualPlaywrightResult(result.stdout, result.stderr);
}

function browserHelpersSource() {
	return [
		normalizeInlineVisualText,
		inlineVisualPlainText,
		topLevelGutenbergBlocks,
		resolveInlineVisualAnchor,
		escapeInlineVisualHtml,
		buildInlineVisualMarkup,
		insertInlineVisuals,
		extractInlineVisualState,
		stripInlineVisuals,
		ensureWordPressBlockEditorField,
	].map((helper) => helper.toString()).join('\n\n');
}

export function inlineVisualPreflightBrowserFunction({
	baseUrl,
	post,
	assets,
}) {
	return `async page => {
		/* AKB_BLOG_INLINE_VISUALS_OFFICIAL_UI_PREFLIGHT */
		${browserHelpersSource()}
		const baseUrl = ${JSON.stringify(baseUrl)};
		const post = ${JSON.stringify(post)};
		const assets = ${JSON.stringify(assets)};
		page.setDefaultNavigationTimeout(75000);
		await page.setViewportSize({ width: 1440, height: 1000 });
		const target = baseUrl + post.edit_path;
		await page.goto(target, { waitUntil: 'domcontentloaded' });
		if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
			throw new Error('AUTH_REQUIRED');
		}
		if (page.url() !== target && !page.url().startsWith(target + '#')) {
			throw new Error('POST_EDITOR_PATH_ESCAPE');
		}
		const update = page.locator('.editor-post-publish-button:visible');
		if (
			await update.count() !== 1
			|| !/Guardar|Save|Actualizar|Update/iu.test(
				String(await update.textContent())
			)
		) {
			throw new Error('POST_NOT_ALREADY_PUBLISHED');
		}
		const title = page.locator(
			'.editor-post-title__input, h1.wp-block-post-title'
		).first();
		if (
			await title.count() !== 1
			|| String(
				await title.inputValue().catch(
					async () => await title.textContent()
				)
			).trim() !== post.post_title
		) {
			throw new Error('POST_TITLE_IDENTITY_MISMATCH');
		}
		const field = await ensureWordPressBlockEditorField(page, {
			field: 'post_content',
			key: 'inline-visual-preflight:' + post.content_key,
		});
		const content = (await field.inputValue()).replace(/\\r\\n?/gu, '\\n');
		async function sha256(value) {
			const bytes = new TextEncoder().encode(value);
			const digest = await crypto.subtle.digest('SHA-256', bytes);
			return [...new Uint8Array(digest)]
				.map((byte) => byte.toString(16).padStart(2, '0')).join('');
		}
		const state = extractInlineVisualState(content, assets);
		const allAfter = assets.length > 0
			&& state.every((entry) => entry.present);
		const noneAfter = state.every((entry) => (
			entry.class_count === 0
			&& entry.filename_count === 0
			&& entry.alt_count === 0
			&& entry.caption_count === 0
		));
		if (allAfter) {
			const stripped = stripInlineVisuals(content, assets);
			if (await sha256(stripped) !== post.before_content_sha256) {
				throw new Error('ALREADY_AFTER_BASE_CONTENT_DRIFT');
			}
			return {
				status: 'already_after',
				content_sha256: await sha256(content),
				visuals: state,
			};
		}
		if (!noneAfter || await sha256(content) !== post.before_content_sha256) {
			throw new Error('POST_CONTENT_PREFLIGHT_DRIFT');
		}
		for (const asset of assets) {
			resolveInlineVisualAnchor(content, asset.anchor);
		}
		return {
			status: 'before',
			content,
			content_sha256: await sha256(content),
			visuals: state,
		};
	}`;
}

export function inlineVisualMediaProbeBrowserFunction({
	baseUrl,
	asset,
}) {
	return `async page => {
		/* AKB_BLOG_INLINE_VISUALS_MEDIA_PROBE_OFFICIAL_UI */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const asset = ${JSON.stringify(asset)};
		page.setDefaultNavigationTimeout(75000);
		const searchTerm = asset.expected_filename.replace(/\\.webp$/iu, '');
		const listPath = '/wp-admin/upload.php?mode=list&s='
			+ encodeURIComponent(searchTerm);
		await page.goto(baseUrl + listPath, { waitUntil: 'domcontentloaded' });
		if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
			throw new Error('AUTH_REQUIRED');
		}
		if (new URL(page.url()).origin !== baseUrl) {
			throw new Error('MEDIA_LIST_ORIGIN_ESCAPE');
		}
		const rows = page.locator('tr[id^="post-"]');
		const candidates = [];
		for (let index = 0; index < await rows.count(); index += 1) {
			const row = rows.nth(index);
			const edit = row.locator(
				'a[href*="post.php?post="][href*="action=edit"]'
			).first();
			if (await edit.count() !== 1) continue;
			const href = await edit.getAttribute('href');
			const parsed = new URL(href, baseUrl);
			const attachmentId = Number(parsed.searchParams.get('post'));
			if (Number.isSafeInteger(attachmentId) && attachmentId > 0) {
				candidates.push({ attachmentId, href: parsed.href });
			}
		}
		if (candidates.length === 0) return { status: 'absent' };
		const exact = [];
		for (const candidate of candidates) {
			await page.goto(candidate.href, { waitUntil: 'domcontentloaded' });
			if (new URL(page.url()).origin !== baseUrl) {
				throw new Error('MEDIA_EDITOR_ORIGIN_ESCAPE');
			}
			const url = page.locator('#attachment_url');
			const alt = page.locator('#attachment_alt');
			const caption = page.locator('#attachment_caption');
			if (
				await url.count() !== 1
				|| await alt.count() !== 1
				|| await caption.count() !== 1
			) {
				throw new Error('MEDIA_EDITOR_FIELDS_MISSING');
			}
			const mediaUrl = await url.inputValue();
			const filename = decodeURIComponent(
				new URL(mediaUrl).pathname.split('/').at(-1)
			);
			if (filename === asset.expected_filename) {
				exact.push({
					attachment_id: candidate.attachmentId,
					url: mediaUrl,
					filename,
					alt: await alt.inputValue(),
					caption: await caption.inputValue(),
				});
			}
		}
		if (exact.length > 1) throw new Error('MEDIA_FILENAME_AMBIGUOUS');
		return exact.length === 0
			? { status: 'absent' }
			: { status: 'present', media: exact[0] };
	}`;
}

export function inlineVisualUploadBrowserFunction({
	baseUrl,
	post,
	asset,
	absoluteLocalFile,
}) {
	return `async page => {
		/* AKB_BLOG_INLINE_VISUALS_MEDIA_UPLOAD_OFFICIAL_UI */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const post = ${JSON.stringify(post)};
		const asset = ${JSON.stringify(asset)};
		const absoluteLocalFile = ${JSON.stringify(absoluteLocalFile)};
		page.setDefaultNavigationTimeout(75000);
		await page.route('**/*', async route => {
			const request = route.request();
			const method = request.method().toUpperCase();
			const origin = new URL(request.url()).origin;
			if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && origin !== baseUrl) {
				await route.abort('blockedbyclient');
				return;
			}
			await route.continue();
		});
		const target = baseUrl + post.edit_path;
		await page.goto(target, { waitUntil: 'domcontentloaded' });
		if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
			throw new Error('AUTH_REQUIRED');
		}
		if (page.url() !== target && !page.url().startsWith(target + '#')) {
			throw new Error('POST_EDITOR_PATH_ESCAPE');
		}
		let panel = page.locator('.editor-post-featured-image:visible');
		if (await panel.count() === 0) {
			const settings = page.locator([
				'button[aria-label="Ajustes"]:visible',
				'button[aria-label="Settings"]:visible',
				'button[aria-controls="edit-post:document"]:visible',
			].join(', '));
			if (await settings.count() !== 1) {
				throw new Error('WORDPRESS_SETTINGS_TOGGLE_MISSING');
			}
			await settings.click();
			panel = page.locator('.editor-post-featured-image:visible');
			await panel.waitFor({ state: 'visible', timeout: 15000 });
		}
		if (await panel.count() !== 1) {
			throw new Error('FEATURED_IMAGE_PANEL_MISSING_OR_AMBIGUOUS');
		}
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
		const uploadTab = modal.locator(
			'[role="tab"], .media-menu-item'
		).filter({ hasText: /Subir archivos|Upload files/iu });
		if (await uploadTab.count() !== 1) {
			throw new Error('MEDIA_UPLOAD_TAB_MISSING');
		}
		await uploadTab.click();
		const input = modal.locator('input[type="file"]');
		if (await input.count() !== 1) {
			throw new Error('MEDIA_FILE_INPUT_MISSING');
		}
		await input.setInputFiles(absoluteLocalFile);
		const selected = modal.locator('li.attachment.selected:visible');
		await selected.waitFor({ state: 'visible', timeout: 60000 });
		if (await selected.count() !== 1) {
			throw new Error('UPLOADED_MEDIA_SELECTION_AMBIGUOUS');
		}
		const attachmentId = Number(await selected.getAttribute('data-id'));
		const details = modal.locator(
			'.attachment-details:visible, .attachment-info:visible'
		);
		const urlControl = details.locator('input[data-setting="url"]');
		if (
			!Number.isSafeInteger(attachmentId)
			|| attachmentId < 1
			|| await urlControl.count() !== 1
		) {
			throw new Error('UPLOADED_MEDIA_DETAILS_INCOMPLETE');
		}
		const mediaUrl = await urlControl.inputValue();
		const filename = decodeURIComponent(
			new URL(mediaUrl).pathname.split('/').at(-1)
		);
		if (
			filename !== asset.expected_filename
			|| !/\\.webp$/iu.test(new URL(mediaUrl).pathname)
		) {
			throw new Error('UPLOADED_FILENAME_MISMATCH:' + filename);
		}
		const close = modal.locator('.media-modal-close:visible');
		if (await close.count() !== 1) {
			throw new Error('MEDIA_MODAL_CLOSE_MISSING');
		}
		await close.click();
		await modal.waitFor({ state: 'hidden', timeout: 10000 });

		const editPath = '/wp-admin/post.php?post=' + attachmentId
			+ '&action=edit';
		await page.goto(baseUrl + editPath, { waitUntil: 'domcontentloaded' });
		if (new URL(page.url()).origin !== baseUrl) {
			throw new Error('MEDIA_EDITOR_ORIGIN_ESCAPE');
		}
		const alt = page.locator('#attachment_alt');
		const caption = page.locator('#attachment_caption');
		const url = page.locator('#attachment_url');
		const update = page.locator('#publish');
		if (
			await alt.count() !== 1
			|| await caption.count() !== 1
			|| await url.count() !== 1
			|| await update.count() !== 1
		) {
			throw new Error('MEDIA_EDITOR_FIELDS_MISSING');
		}
		if (await url.inputValue() !== mediaUrl) {
			throw new Error('MEDIA_URL_CHANGED_AFTER_UPLOAD');
		}
		await alt.fill(asset.alt);
		await caption.fill(asset.caption);
		await Promise.all([
			page.waitForNavigation({
				waitUntil: 'domcontentloaded',
				timeout: 75000,
			}),
			update.click(),
		]);
		if (
			await page.locator('#attachment_alt').inputValue() !== asset.alt
			|| await page.locator('#attachment_caption').inputValue()
				!== asset.caption
			|| await page.locator('#attachment_url').inputValue() !== mediaUrl
		) {
			throw new Error('MEDIA_METADATA_RELOAD_VERIFY_FAILED');
		}
		return {
			status: 'uploaded',
			attachment_id: attachmentId,
			url: mediaUrl,
			filename,
			alt: asset.alt,
			caption: asset.caption,
		};
	}`;
}

export function inlineVisualApplyPostBrowserFunction({
	baseUrl,
	post,
	assets,
	uploadedBySha256,
	beforeContent,
}) {
	return `async page => {
		/* AKB_BLOG_INLINE_VISUALS_GUTENBERG_APPLY_OFFICIAL_UI */
		${browserHelpersSource()}
		const baseUrl = ${JSON.stringify(baseUrl)};
		const post = ${JSON.stringify(post)};
		const assets = ${JSON.stringify(assets)};
		const uploadedBySha256 = ${JSON.stringify(uploadedBySha256)};
		const beforeContent = ${JSON.stringify(beforeContent)};
		page.setDefaultNavigationTimeout(75000);
		await page.route('**/*', async route => {
			const request = route.request();
			const method = request.method().toUpperCase();
			const origin = new URL(request.url()).origin;
			if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && origin !== baseUrl) {
				await route.abort('blockedbyclient');
				return;
			}
			await route.continue();
		});
		const target = baseUrl + post.edit_path;
		async function navigate() {
			await page.goto(target, { waitUntil: 'domcontentloaded' });
			if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
				throw new Error('AUTH_REQUIRED');
			}
			if (page.url() !== target && !page.url().startsWith(target + '#')) {
				throw new Error('POST_EDITOR_PATH_ESCAPE');
			}
		}
		async function contentField() {
			return await ensureWordPressBlockEditorField(page, {
				field: 'post_content',
				key: 'inline-visual-apply:' + post.content_key,
			});
		}
		async function sha256(value) {
			const bytes = new TextEncoder().encode(value);
			const digest = await crypto.subtle.digest('SHA-256', bytes);
			return [...new Uint8Array(digest)]
				.map((byte) => byte.toString(16).padStart(2, '0')).join('');
		}
		await navigate();
		let field = await contentField();
		const current = (await field.inputValue()).replace(/\\r\\n?/gu, '\\n');
		if (
			current !== beforeContent
			|| await sha256(current) !== post.before_content_sha256
		) {
			throw new Error('CONTENT_CHANGED_AFTER_GLOBAL_PREFLIGHT');
		}
		const after = insertInlineVisuals(
			current,
			assets,
			uploadedBySha256
		);
		await field.fill(after);
		const parsed = await page.evaluate(() => {
			const value = document.querySelector(
				'.editor-post-text-editor'
			)?.value ?? '';
			if (!window.wp?.blocks?.parse) return { available: false };
			const blocks = window.wp.blocks.parse(value);
			return {
				available: true,
				count: blocks.length,
				invalid: blocks.filter((block) => block.isValid === false).length,
			};
		});
		if (!parsed.available || parsed.count < 1 || parsed.invalid !== 0) {
			throw new Error('GUTENBERG_BLOCK_VALIDATION_FAILED');
		}
		const update = page.locator('.editor-post-publish-button:visible');
		if (await update.count() !== 1 || !await update.isEnabled()) {
			throw new Error('UPDATE_ACTION_MISSING_OR_DISABLED');
		}
		await update.click();
		await page.waitForFunction(
			() => {
				const button = document.querySelector(
					'.editor-post-publish-button'
				);
				return button && !button.classList.contains('is-busy');
			},
			null,
			{ timeout: 60000 }
		);
		await navigate();
		field = await contentField();
		const persisted = (await field.inputValue()).replace(/\\r\\n?/gu, '\\n');
		if (persisted !== after) {
			throw new Error('POST_CONTENT_RELOAD_VERIFY_FAILED');
		}
		const state = extractInlineVisualState(persisted, assets);
		if (!state.every((entry) => entry.present)) {
			throw new Error('POST_VISUAL_STATE_RELOAD_VERIFY_FAILED');
		}
		return {
			status: 'applied',
			after_content_sha256: await sha256(persisted),
			visuals: state,
		};
	}`;
}

export function inlineVisualRollbackPostBrowserFunction({
	baseUrl,
	post,
	assets,
	beforeContent,
}) {
	return `async page => {
		/* AKB_BLOG_INLINE_VISUALS_GUTENBERG_ROLLBACK_OFFICIAL_UI */
		${browserHelpersSource()}
		const baseUrl = ${JSON.stringify(baseUrl)};
		const post = ${JSON.stringify(post)};
		const assets = ${JSON.stringify(assets)};
		const beforeContent = ${JSON.stringify(beforeContent)};
		page.setDefaultNavigationTimeout(75000);
		await page.route('**/*', async route => {
			const request = route.request();
			const method = request.method().toUpperCase();
			const origin = new URL(request.url()).origin;
			if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && origin !== baseUrl) {
				await route.abort('blockedbyclient');
				return;
			}
			await route.continue();
		});
		const target = baseUrl + post.edit_path;
		async function navigate() {
			await page.goto(target, { waitUntil: 'domcontentloaded' });
			if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
				throw new Error('AUTH_REQUIRED');
			}
			if (page.url() !== target && !page.url().startsWith(target + '#')) {
				throw new Error('POST_EDITOR_PATH_ESCAPE');
			}
		}
		async function field() {
			return await ensureWordPressBlockEditorField(page, {
				field: 'post_content',
				key: 'inline-visual-rollback:' + post.content_key,
			});
		}
		await navigate();
		let control = await field();
		const current = (await control.inputValue()).replace(/\\r\\n?/gu, '\\n');
		if (current === beforeContent) {
			return { status: 'already_before' };
		}
		const state = extractInlineVisualState(current, assets);
		if (
			assets.length === 0
			|| !state.every((entry) => entry.present)
			|| stripInlineVisuals(current, assets) !== beforeContent
		) {
			throw new Error('ROLLBACK_CONTENT_DRIFT');
		}
		await control.fill(beforeContent);
		const update = page.locator('.editor-post-publish-button:visible');
		if (await update.count() !== 1 || !await update.isEnabled()) {
			throw new Error('ROLLBACK_UPDATE_ACTION_MISSING');
		}
		await update.click();
		await page.waitForFunction(
			() => {
				const button = document.querySelector(
					'.editor-post-publish-button'
				);
				return button && !button.classList.contains('is-busy');
			},
			null,
			{ timeout: 60000 }
		);
		await navigate();
		control = await field();
		const persisted = (await control.inputValue()).replace(/\\r\\n?/gu, '\\n');
		if (persisted !== beforeContent) {
			throw new Error('ROLLBACK_RELOAD_VERIFY_FAILED');
		}
		return { status: 'rolled_back' };
	}`;
}

export function inlineVisualDeleteMediaBrowserFunction({
	baseUrl,
	asset,
	media,
}) {
	return `async page => {
		/* AKB_BLOG_INLINE_VISUALS_MEDIA_DELETE_OFFICIAL_UI */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const asset = ${JSON.stringify(asset)};
		const media = ${JSON.stringify(media)};
		page.setDefaultNavigationTimeout(75000);
		await page.route('**/*', async route => {
			const request = route.request();
			const method = request.method().toUpperCase();
			const origin = new URL(request.url()).origin;
			if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && origin !== baseUrl) {
				await route.abort('blockedbyclient');
				return;
			}
			await route.continue();
		});
		const target = baseUrl + '/wp-admin/post.php?post='
			+ media.attachment_id + '&action=edit';
		await page.goto(target, { waitUntil: 'domcontentloaded' });
		if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
			throw new Error('AUTH_REQUIRED');
		}
		if (new URL(page.url()).origin !== baseUrl) {
			throw new Error('MEDIA_EDITOR_ORIGIN_ESCAPE');
		}
		const url = page.locator('#attachment_url');
		if (await url.count() === 0) return { status: 'already_absent' };
		if (await url.count() !== 1) {
			throw new Error('MEDIA_EDITOR_URL_AMBIGUOUS');
		}
		const currentUrl = await url.inputValue();
		const filename = decodeURIComponent(
			new URL(currentUrl).pathname.split('/').at(-1)
		);
		if (
			filename !== asset.expected_filename
			|| currentUrl !== media.url
		) {
			throw new Error('MEDIA_DELETE_IDENTITY_DRIFT');
		}
		const remove = page.locator('#delete-action a.submitdelete:visible');
		if (await remove.count() !== 1) {
			throw new Error('MEDIA_DELETE_ACTION_MISSING');
		}
		page.once('dialog', async dialog => await dialog.accept());
		await Promise.all([
			page.waitForNavigation({
				waitUntil: 'domcontentloaded',
				timeout: 75000,
			}),
			remove.click(),
		]);
		if (new URL(page.url()).origin !== baseUrl) {
			throw new Error('MEDIA_DELETE_REDIRECT_ORIGIN_ESCAPE');
		}
		await page.goto(target, { waitUntil: 'domcontentloaded' });
		if (await page.locator('#attachment_url').count() !== 0) {
			throw new Error('MEDIA_DELETE_RELOAD_VERIFY_FAILED');
		}
		return { status: 'deleted' };
	}`;
}

export function inlineVisualFrontendVerifyBrowserFunction({
	baseUrl,
	post,
	assets,
	uploadedBySha256,
}) {
	return `async page => {
		/* AKB_BLOG_INLINE_VISUALS_FRONTEND_VERIFY */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const post = ${JSON.stringify(post)};
		const assets = ${JSON.stringify(assets)};
		const uploadedBySha256 = ${JSON.stringify(uploadedBySha256)};
		page.setDefaultNavigationTimeout(75000);
		await page.goto(post.public_url, { waitUntil: 'domcontentloaded' });
		if (
			new URL(page.url()).origin !== baseUrl
			|| page.url() !== post.public_url
		) {
			throw new Error('PUBLIC_URL_IDENTITY_OR_REDIRECT_MISMATCH');
		}
		const results = [];
		for (const asset of assets) {
			const uploaded = uploadedBySha256[asset.sha256];
			const figure = page.locator('figure.' + asset.marker_class);
			if (await figure.count() !== 1) {
				throw new Error('FRONTEND_FIGURE_COUNT:' + asset.expected_filename);
			}
			const image = figure.locator('img');
			const caption = figure.locator('figcaption');
			if (await image.count() !== 1 || await caption.count() !== 1) {
				throw new Error('FRONTEND_VISUAL_STRUCTURE_INVALID');
			}
			const src = await image.getAttribute('src');
			const srcset = await image.getAttribute('srcset');
			const sizes = await image.getAttribute('sizes');
			const actual = {
				src,
				alt: await image.getAttribute('alt'),
				width: Number(await image.getAttribute('width')),
				height: Number(await image.getAttribute('height')),
				loading: await image.getAttribute('loading'),
				decoding: await image.getAttribute('decoding'),
				class_name: await image.getAttribute('class'),
				srcset,
				sizes,
				caption: String(await caption.textContent()).trim(),
			};
			if (
				src !== uploaded.url
				|| actual.alt !== asset.alt
				|| actual.width !== asset.width
				|| actual.height !== asset.height
				|| actual.loading !== 'lazy'
				|| actual.decoding !== 'async'
				|| !actual.class_name.split(/\\s+/u)
					.includes('wp-image-' + uploaded.attachment_id)
				|| !srcset
				|| !sizes
				|| !srcset.includes(asset.expected_filename.replace(/\\.webp$/u, ''))
				|| actual.caption !== asset.caption
			) {
				throw new Error(
					'FRONTEND_RESPONSIVE_MEDIA_VERIFY_FAILED:'
						+ asset.expected_filename
				);
			}
			results.push({ sha256: asset.sha256, ...actual });
		}
		return { status: 'verified', assets: results };
	}`;
}

export function assertInlineVisualBrowserFunctionSafety(source) {
	try {
		Function(`"use strict"; return (${source});`);
	} catch (error) {
		fail(`browser function syntax is invalid: ${error.message}`);
	}
	for (const expression of [
		/fetch\s*\(/iu,
		/page\.request\b/iu,
		/wp-json/iu,
		/admin-ajax\.php/iu,
		/update_post_meta/iu,
		/update_option/iu,
		/\$wpdb/iu,
	]) {
		if (expression.test(source)) {
			fail(`browser function contains prohibited channel ${expression}`);
		}
	}
	return source;
}

function groupedAssets(manifest) {
	const byPost = new Map(manifest.posts.map((post) => [post.post_id, []]));
	for (const asset of manifest.assets) {
		byPost.get(asset.post_id).push(asset);
	}
	for (const assets of byPost.values()) {
		assets.sort((left, right) => left.sequence - right.sequence);
	}
	return byPost;
}

function checkpointAsset(checkpoint, sha256) {
	for (const post of checkpoint.posts) {
		const asset = post.assets.find((entry) => entry.sha256 === sha256);
		if (asset) return asset;
	}
	fail(`checkpoint asset missing ${sha256}`);
}

function checkpointPost(checkpoint, postId) {
	const post = checkpoint.posts.find((entry) => entry.post_id === postId);
	if (!post) fail(`checkpoint post missing ${postId}`);
	return post;
}

function mediaContractFromProbe(asset, probe) {
	if (
		probe.status !== 'present'
		|| probe.media.filename !== asset.expected_filename
		|| probe.media.alt !== asset.alt
		|| probe.media.caption !== asset.caption
	) {
		fail(`media metadata drift for ${asset.expected_filename}`);
	}
	return probe.media;
}

function recoverInterruptedTransaction({
	checkpoint,
	checkpointFile,
	manifest,
	assetsByPost,
	session,
	runtime,
}) {
	if (
		!checkpoint
		|| !['applying', 'rolling_back'].includes(checkpoint.phase)
	) {
		return;
	}
	checkpoint.phase = 'rolling_back';
	replaceAtomic0600(checkpointFile, checkpoint);
	const errors = [];
	for (const postState of [...checkpoint.posts].reverse()) {
		if (!['saving', 'applied'].includes(postState.state)) continue;
		const post = manifest.posts.find(
			(entry) => entry.post_id === postState.post_id
		);
		const assets = assetsByPost.get(post.post_id);
		try {
			runBrowser(
				session,
				assertInlineVisualBrowserFunctionSafety(
					inlineVisualRollbackPostBrowserFunction({
						baseUrl: manifest.target_origin,
						post,
						assets,
						beforeContent: postState.before_content,
					})
				),
				runtime
			);
			postState.state = 'rolled_back';
			replaceAtomic0600(checkpointFile, checkpoint);
		} catch (error) {
			errors.push(`POST:${post.post_id}:${error.message}`);
		}
	}
	for (const asset of [...manifest.assets].reverse()) {
		const state = checkpointAsset(checkpoint, asset.sha256);
		if (
			state.created_by_transaction !== true
			|| !['uploading', 'uploaded', 'deleting'].includes(state.state)
		) {
			continue;
		}
		try {
			const probe = runBrowser(
				session,
				assertInlineVisualBrowserFunctionSafety(
					inlineVisualMediaProbeBrowserFunction({
						baseUrl: manifest.target_origin,
						asset,
					})
				),
				runtime
			);
			if (probe.status === 'absent') {
				state.state = 'deleted';
				state.media = null;
				replaceAtomic0600(checkpointFile, checkpoint);
				continue;
			}
			state.state = 'deleting';
			state.media = probe.media;
			replaceAtomic0600(checkpointFile, checkpoint);
			runBrowser(
				session,
				assertInlineVisualBrowserFunctionSafety(
					inlineVisualDeleteMediaBrowserFunction({
						baseUrl: manifest.target_origin,
						asset,
						media: probe.media,
					})
				),
				runtime
			);
			state.state = 'deleted';
			state.media = null;
			replaceAtomic0600(checkpointFile, checkpoint);
		} catch (error) {
			errors.push(`MEDIA:${asset.expected_filename}:${error.message}`);
		}
	}
	if (errors.length > 0) {
		checkpoint.recovery_errors = errors;
		replaceAtomic0600(checkpointFile, checkpoint);
		fail(`interrupted transaction recovery failed: ${errors.join(' | ')}`);
	}
	checkpoint.phase = 'rolled_back';
	checkpoint.rolled_back_at = new Date().toISOString();
	replaceAtomic0600(checkpointFile, checkpoint);
}

function globalPreflight({
	manifest,
	assetsByPost,
	session,
	runtime,
}) {
	const postResults = [];
	for (const post of manifest.posts) {
		const assets = assetsByPost.get(post.post_id);
		const result = runBrowser(
			session,
			assertInlineVisualBrowserFunctionSafety(
				inlineVisualPreflightBrowserFunction({
					baseUrl: manifest.target_origin,
					post,
					assets,
				})
			),
			runtime
		);
		if (!['before', 'already_after'].includes(result.status)) {
			fail(`post preflight did not resolve ${post.post_id}`);
		}
		postResults.push({ post, assets, result });
	}
	if (postResults.length !== 25) {
		fail('global preflight did not inspect all 25 posts');
	}
	const statusByPost = new Map(postResults.map((entry) => [
		entry.post.post_id,
		entry.result.status,
	]));
	const mediaResults = [];
	for (const asset of manifest.assets) {
		const result = runBrowser(
			session,
			assertInlineVisualBrowserFunctionSafety(
				inlineVisualMediaProbeBrowserFunction({
					baseUrl: manifest.target_origin,
					asset,
				})
			),
			runtime
		);
		const expectedPresent = statusByPost.get(asset.post_id)
			=== 'already_after';
		if (expectedPresent) {
			mediaContractFromProbe(asset, result);
		} else if (result.status !== 'absent') {
			fail(
				`filename already exists before upload: ${asset.expected_filename}`
			);
		}
		mediaResults.push({ asset, result });
	}
	if (mediaResults.length !== 32) {
		fail('global preflight did not inspect all 32 Media filenames');
	}
	return { postResults, mediaResults };
}

function mediaOnlyPreflight({
	manifest,
	session,
	runtime,
}) {
	const mediaResults = [];
	for (const asset of manifest.assets) {
		const result = runBrowser(
			session,
			assertInlineVisualBrowserFunctionSafety(
				inlineVisualMediaProbeBrowserFunction({
					baseUrl: manifest.target_origin,
					asset,
				})
			),
			runtime
		);
		if (result.status !== 'absent') {
			fail(
				`filename already exists before transaction: `
				+ asset.expected_filename
			);
		}
		mediaResults.push({
			sha256: asset.sha256,
			expected_filename: asset.expected_filename,
			status: result.status,
		});
	}
	if (mediaResults.length !== 32) {
		fail('media-only preflight did not inspect all 32 filenames');
	}
	return mediaResults;
}

function checkpointFromPreflight({
	manifest,
	session,
	preflight,
}) {
	const mediaBySha = new Map(preflight.mediaResults.map((entry) => [
		entry.asset.sha256,
		entry.result,
	]));
	return {
		schema_version: 1,
		checkpoint_type:
			'akibara_blog_inline_visuals_official_ui_transaction',
		manifest_sha256: manifest.manifest_sha256,
		environment: manifest.environment,
		target_origin: manifest.target_origin,
		session,
		phase: 'global_preflight_complete',
		created_at: new Date().toISOString(),
		global_preflight: {
			completed_at: new Date().toISOString(),
			posts: 25,
			assets: 32,
			writes_before_completion: 0,
		},
		posts: preflight.postResults.map(({ post, assets, result }) => ({
			post_id: post.post_id,
			content_key: post.content_key,
			state: result.status,
			before_content: result.status === 'before' ? result.content : null,
			before_content_sha256: post.before_content_sha256,
			after_content_sha256: result.status === 'already_after'
				? result.content_sha256
				: null,
			assets: assets.map((asset) => {
				const media = mediaBySha.get(asset.sha256);
				return {
					sha256: asset.sha256,
					expected_filename: asset.expected_filename,
					state: media.status === 'present'
						? 'preexisting'
						: 'absent',
					created_by_transaction: false,
					media: media.status === 'present' ? media.media : null,
				};
			}),
		})),
	};
}

async function main() {
	const repository = repositoryRoot();
	const manifestValue = option('--manifest');
	if (!manifestValue) fail('missing --manifest');
	const manifestFile = localPath(
		manifestValue,
		[
			path.join(repository, '.local-reports'),
			path.join(repository, '.local-checkpoints'),
		],
		'--manifest',
		{ mustExist: true }
	);
	let manifest;
	try {
		manifest = validateInlineVisualManifest(
			JSON.parse(fs.readFileSync(manifestFile, 'utf8'))
		);
	} catch (error) {
		fail(error.message);
	}
	const localFiles = validateLocalAssets(manifest, repository);
	const assetsByPost = groupedAssets(manifest);
	const samples = [
		inlineVisualPreflightBrowserFunction({
			baseUrl: manifest.target_origin,
			post: manifest.posts[0],
			assets: assetsByPost.get(manifest.posts[0].post_id),
		}),
		inlineVisualMediaProbeBrowserFunction({
			baseUrl: manifest.target_origin,
			asset: manifest.assets[0],
		}),
		inlineVisualUploadBrowserFunction({
			baseUrl: manifest.target_origin,
			post: manifest.posts.find(
				(post) => post.post_id === manifest.assets[0].post_id
			),
			asset: manifest.assets[0],
			absoluteLocalFile: localFiles.get(manifest.assets[0].sha256),
		}),
	];
	for (const source of samples) {
		assertInlineVisualBrowserFunctionSafety(source);
	}
	if (process.argv.includes('--validate-only')) {
		process.stdout.write(`${JSON.stringify({
			result: 'PASS',
			mode: 'validate-only',
			environment: manifest.environment,
			posts: manifest.posts.length,
			assets: manifest.assets.length,
			manifest_sha256: manifest.manifest_sha256,
		}, null, 2)}\n`);
		return;
	}
	if (option('--base-url') !== manifest.target_origin) {
		fail('--base-url must equal manifest.target_origin');
	}
	const session = option('--session');
	if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/u.test(session ?? '')) {
		fail('unsafe or missing --session');
	}
	const apply = process.argv.includes('--apply');
	const mediaOnly = process.argv.includes('--preflight-media-only');
	const rollbackTransaction =
		process.argv.includes('--rollback-transaction');
	if ([apply, mediaOnly, rollbackTransaction].filter(Boolean).length > 1) {
		fail('runner modes are mutually exclusive');
	}
	if (
		(apply || rollbackTransaction)
		&& manifest.environment !== 'production'
	) {
		fail(
			'official UI mutation modes are production-only; '
			+ 'Docker local is the read-only preflight environment'
		);
	}
	const checkpointFile = mediaOnly
		? null
		: localPath(
			option('--checkpoint'),
			[path.join(repository, '.local-checkpoints')],
			'--checkpoint'
		);
	const reportOption = rollbackTransaction
		? '--rollback-report'
		: '--report';
	const reportFile = localPath(
		option(reportOption),
		[path.join(repository, '.local-reports')],
		reportOption
	);
	if (fs.existsSync(reportFile)) fail('refusing to overwrite report');
	if (
		(apply || rollbackTransaction)
		&& option('--confirm-manifest-sha256') !== manifest.manifest_sha256
	) {
		fail('mutating mode requires the exact --confirm-manifest-sha256');
	}
	const runtime = fs.mkdtempSync(
		path.join(os.tmpdir(), 'akb-inline-visual-ui-')
	);
	fs.chmodSync(runtime, 0o700);
	if (mediaOnly) {
		try {
			const media = mediaOnlyPreflight({
				manifest,
				session,
				runtime,
			});
			writeAtomic0600(reportFile, {
				schema_version: 1,
				report_type:
					'akibara_blog_inline_visuals_media_only_preflight',
				status: 'preflight_pass',
				environment: manifest.environment,
				target_origin: manifest.target_origin,
				manifest_sha256: manifest.manifest_sha256,
				completed_at: new Date().toISOString(),
				writes: 0,
				assets: media,
			});
			process.stdout.write(`${JSON.stringify({
				result: 'PASS',
				mode: 'preflight-media-only',
				posts: 0,
				assets: 32,
				report: reportFile,
			}, null, 2)}\n`);
			return;
		} finally {
			fs.rmSync(runtime, { recursive: true, force: true });
		}
	}
	let checkpoint = readCheckpoint(checkpointFile, manifest, session);
	try {
		if (rollbackTransaction) {
			if (!checkpoint) {
				writeAtomic0600(reportFile, {
					schema_version: 1,
					report_type:
						'akibara_blog_inline_visuals_official_ui_evidence',
					status: 'no_checkpoint_no_writes',
					environment: manifest.environment,
					target_origin: manifest.target_origin,
					manifest_sha256: manifest.manifest_sha256,
					completed_at: new Date().toISOString(),
					rollback: 'PASS',
				});
			} else if (checkpoint.phase === 'rolled_back') {
				writeAtomic0600(reportFile, {
					schema_version: 1,
					report_type:
						'akibara_blog_inline_visuals_official_ui_evidence',
					status: 'already_rolled_back',
					environment: manifest.environment,
					target_origin: manifest.target_origin,
					manifest_sha256: manifest.manifest_sha256,
					completed_at: new Date().toISOString(),
					rollback: 'PASS',
				});
			} else if (checkpoint.phase === 'global_preflight_complete') {
				checkpoint.phase = 'rolled_back';
				checkpoint.rolled_back_at = new Date().toISOString();
				replaceAtomic0600(checkpointFile, checkpoint);
				writeAtomic0600(reportFile, {
					schema_version: 1,
					report_type:
						'akibara_blog_inline_visuals_official_ui_evidence',
					status: 'preflight_only_no_writes',
					environment: manifest.environment,
					target_origin: manifest.target_origin,
					manifest_sha256: manifest.manifest_sha256,
					completed_at: new Date().toISOString(),
					rollback: 'PASS',
				});
			} else {
				if (![
					'applying',
					'rolling_back',
					'complete',
				].includes(checkpoint.phase)) {
					fail(
						`checkpoint phase ${checkpoint.phase} `
						+ 'cannot be rolled back'
					);
				}
				checkpoint.phase = 'rolling_back';
				checkpoint.primary_error =
					'EXTERNAL_TRANSACTION_COORDINATOR_ROLLBACK';
				replaceAtomic0600(checkpointFile, checkpoint);
				recoverInterruptedTransaction({
					checkpoint,
					checkpointFile,
					manifest,
					assetsByPost,
					session,
					runtime,
				});
				writeAtomic0600(reportFile, {
					schema_version: 1,
					report_type:
						'akibara_blog_inline_visuals_official_ui_evidence',
					status: 'rolled_back_by_transaction_coordinator',
					environment: manifest.environment,
					target_origin: manifest.target_origin,
					manifest_sha256: manifest.manifest_sha256,
					completed_at: new Date().toISOString(),
					rollback: 'PASS',
				});
			}
			process.stdout.write(`${JSON.stringify({
				result: 'PASS',
				mode: 'rollback-transaction',
				posts: 25,
				assets: 32,
				checkpoint: checkpointFile,
				report: reportFile,
			}, null, 2)}\n`);
			return;
		}
		recoverInterruptedTransaction({
			checkpoint,
			checkpointFile,
			manifest,
			assetsByPost,
			session,
			runtime,
		});
		const preflight = globalPreflight({
			manifest,
			assetsByPost,
			session,
			runtime,
		});
		checkpoint = checkpointFromPreflight({
			manifest,
			session,
			preflight,
		});
		if (fs.existsSync(checkpointFile)) {
			replaceAtomic0600(checkpointFile, checkpoint);
		} else {
			writeAtomic0600(checkpointFile, checkpoint);
		}
		if (!apply) {
			const report = {
				schema_version: 1,
				report_type:
					'akibara_blog_inline_visuals_official_ui_evidence',
				status: 'preflight_pass',
				environment: manifest.environment,
				target_origin: manifest.target_origin,
				manifest_sha256: manifest.manifest_sha256,
				completed_at: new Date().toISOString(),
				global_preflight: checkpoint.global_preflight,
			};
			writeAtomic0600(reportFile, report);
			process.stdout.write(`${JSON.stringify({
				result: 'PASS',
				mode: 'preflight',
				posts: 25,
				assets: 32,
				checkpoint: checkpointFile,
				report: reportFile,
			}, null, 2)}\n`);
			return;
		}

		checkpoint.phase = 'applying';
		checkpoint.apply_started_at = new Date().toISOString();
		replaceAtomic0600(checkpointFile, checkpoint);
		try {
			for (const post of manifest.posts) {
				const postState = checkpointPost(checkpoint, post.post_id);
				const assets = assetsByPost.get(post.post_id);
				if (postState.state === 'already_after' || assets.length === 0) {
					continue;
				}
				const uploadedBySha256 = {};
				for (const asset of assets) {
					const state = checkpointAsset(checkpoint, asset.sha256);
					state.state = 'uploading';
					state.created_by_transaction = true;
					replaceAtomic0600(checkpointFile, checkpoint);
					const uploaded = runBrowser(
						session,
						assertInlineVisualBrowserFunctionSafety(
							inlineVisualUploadBrowserFunction({
								baseUrl: manifest.target_origin,
								post,
								asset,
								absoluteLocalFile: localFiles.get(asset.sha256),
							})
						),
						runtime
					);
					state.state = 'uploaded';
					state.media = {
						attachment_id: uploaded.attachment_id,
						url: uploaded.url,
						filename: uploaded.filename,
						alt: uploaded.alt,
						caption: uploaded.caption,
					};
					uploadedBySha256[asset.sha256] = state.media;
					replaceAtomic0600(checkpointFile, checkpoint);
				}
				postState.state = 'saving';
				replaceAtomic0600(checkpointFile, checkpoint);
				const applied = runBrowser(
					session,
					assertInlineVisualBrowserFunctionSafety(
						inlineVisualApplyPostBrowserFunction({
							baseUrl: manifest.target_origin,
							post,
							assets,
							uploadedBySha256,
							beforeContent: postState.before_content,
						})
					),
					runtime
				);
				postState.state = 'applied';
				postState.after_content_sha256 = applied.after_content_sha256;
				replaceAtomic0600(checkpointFile, checkpoint);
			}

			const frontend = [];
			for (const post of manifest.posts) {
				const assets = assetsByPost.get(post.post_id);
				if (assets.length === 0) continue;
				const uploadedBySha256 = Object.fromEntries(assets.map((asset) => [
					asset.sha256,
					checkpointAsset(checkpoint, asset.sha256).media,
				]));
				const verified = runBrowser(
					session,
					assertInlineVisualBrowserFunctionSafety(
						inlineVisualFrontendVerifyBrowserFunction({
							baseUrl: manifest.target_origin,
							post,
							assets,
							uploadedBySha256,
						})
					),
					runtime
				);
				frontend.push({ post_id: post.post_id, ...verified });
			}
			if (
				frontend.length !== 18
				|| frontend.reduce(
					(total, row) => total + row.assets.length,
					0
				) !== 32
			) {
				fail('frontend verification coverage is incomplete');
			}
			checkpoint.phase = 'complete';
			checkpoint.completed_at = new Date().toISOString();
			checkpoint.frontend_verified_posts = 18;
			checkpoint.frontend_verified_assets = 32;
			replaceAtomic0600(checkpointFile, checkpoint);
			writeAtomic0600(reportFile, {
				schema_version: 1,
				report_type:
					'akibara_blog_inline_visuals_official_ui_evidence',
				status: 'applied_and_verified',
				environment: manifest.environment,
				target_origin: manifest.target_origin,
				manifest_sha256: manifest.manifest_sha256,
				completed_at: checkpoint.completed_at,
				global_preflight: checkpoint.global_preflight,
				posts: 25,
				assets: 32,
				frontend,
			});
		} catch (primaryError) {
			checkpoint.phase = 'rolling_back';
			checkpoint.primary_error = String(
				primaryError.message ?? primaryError
			);
			replaceAtomic0600(checkpointFile, checkpoint);
			recoverInterruptedTransaction({
				checkpoint,
				checkpointFile,
				manifest,
				assetsByPost,
				session,
				runtime,
			});
			writeAtomic0600(reportFile, {
				schema_version: 1,
				report_type:
					'akibara_blog_inline_visuals_official_ui_evidence',
				status: 'rolled_back_after_failure',
				environment: manifest.environment,
				target_origin: manifest.target_origin,
				manifest_sha256: manifest.manifest_sha256,
				failed_at: new Date().toISOString(),
				primary_error: checkpoint.primary_error,
				rollback: 'PASS',
			});
			throw primaryError;
		}
		process.stdout.write(`${JSON.stringify({
			result: 'PASS',
			mode: 'apply',
			posts: 25,
			assets: 32,
			frontend_verified_posts: 18,
			checkpoint: checkpointFile,
			report: reportFile,
		}, null, 2)}\n`);
	} finally {
		fs.rmSync(runtime, { recursive: true, force: true });
	}
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	main().catch((error) => {
		process.stderr.write(`${error.message}\n`);
		process.exitCode = 1;
	});
}
