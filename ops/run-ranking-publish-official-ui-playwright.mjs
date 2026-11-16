#!/usr/bin/env node

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
	ensureWordPressBlockEditorField,
} from './lib/wordpress-block-editor-official-ui.mjs';

export const RANKING_PUBLISH_PLAYWRIGHT_PACKAGE = '@playwright/cli@0.1.17';
export const RANKING_PUBLISH_PREFLIGHT_MAX_AGE_MS = 4 * 60 * 60 * 1000;

function fail(message) {
	throw new Error(`Ranking publish official UI runner failed: ${message}`);
}

function usage() {
	process.stdout.write(`Usage:
  node ops/run-ranking-publish-official-ui-playwright.mjs \\
    --manifest FILE --validate-only

  node ops/run-ranking-publish-official-ui-playwright.mjs \\
    --manifest FILE --base-url https://akibara.cl --session SESSION \\
    --checkpoint .local-checkpoints/.../ranking-publish.json

  node ops/run-ranking-publish-official-ui-playwright.mjs \\
    --manifest FILE --base-url https://akibara.cl --session SESSION \\
    --checkpoint .local-checkpoints/.../ranking-publish.json \\
    --apply --confirm-manifest-sha256 SHA256

The default operational mode is read-only preflight. Apply is production-only,
requires a recent matching preflight and publishes post 27460 through the
official Gutenberg controls. It never fills content, SEO or media fields.
`);
}

function parseArgs(argv) {
	const parsed = {
		manifest: '',
		baseUrl: '',
		session: '',
		checkpoint: '',
		confirmManifestSha256: '',
		validateOnly: false,
		apply: false,
	};
	const values = {
		'--manifest': 'manifest',
		'--base-url': 'baseUrl',
		'--session': 'session',
		'--checkpoint': 'checkpoint',
		'--confirm-manifest-sha256': 'confirmManifestSha256',
	};
	const flags = {
		'--validate-only': 'validateOnly',
		'--apply': 'apply',
	};
	for (let index = 0; index < argv.length; index += 1) {
		const argument = argv[index];
		if (argument === '--help' || argument === '-h') {
			usage();
			process.exit(0);
		}
		if (flags[argument]) {
			if (parsed[flags[argument]]) fail(`duplicate option ${argument}`);
			parsed[flags[argument]] = true;
			continue;
		}
		const key = values[argument];
		if (!key) fail(`unknown argument ${argument}`);
		if (parsed[key]) fail(`duplicate option ${argument}`);
		index += 1;
		if (index >= argv.length || argv[index].startsWith('--')) {
			fail(`${argument} requires a value`);
		}
		parsed[key] = argv[index];
	}
	if (!parsed.manifest) fail('--manifest is required');
	if (parsed.validateOnly && parsed.apply) {
		fail('--validate-only and --apply are mutually exclusive');
	}
	return parsed;
}

function repositoryRoot() {
	return path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
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

function validateLocalFile(value, {
	label,
	allowedRoots,
	mustExist,
	require0600 = false,
}) {
	if (!value) fail(`missing ${label}`);
	const file = path.resolve(value);
	const root = allowedRoots.find((candidate) => isInside(file, candidate));
	if (!root) {
		fail(`${label} is outside its allowed local roots`);
	}
	assertNoSymlinkTraversal(file, root, label);
	if (mustExist) {
		if (!fs.existsSync(file)) fail(`${label} does not exist`);
		const stats = fs.lstatSync(file);
		if (!stats.isFile() || stats.isSymbolicLink()) {
			fail(`${label} must be a regular non-symlink file`);
		}
		if (require0600 && (stats.mode & 0o777) !== 0o600) {
			fail(`${label} mode must be exactly 0600`);
		}
	}
	return file;
}

function loadManifest(value) {
	const repository = repositoryRoot();
	const reportsRoot = path.join(repository, '.local-reports');
	const file = validateLocalFile(value, {
		label: '--manifest',
		allowedRoots: [reportsRoot],
		mustExist: true,
		require0600: true,
	});
	const manifestBytes = fs.readFileSync(file);
	let manifest;
	try {
		manifest = validateRankingPublishManifest(
			JSON.parse(manifestBytes.toString('utf8')),
		);
	} catch (error) {
		fail(`manifest is invalid: ${error.message}`);
	}
	const sidecar = validateLocalFile(`${file}.sha256`, {
		label: 'manifest hash sidecar',
		allowedRoots: [reportsRoot],
		mustExist: true,
		require0600: true,
	});
	const expectedSidecar = `${rankingPublishSha256(manifestBytes)}  ${
		path.basename(file)
	}\n`;
	if (fs.readFileSync(sidecar, 'utf8') !== expectedSidecar) {
		fail('manifest hash sidecar does not match the reviewed manifest');
	}
	return { file, manifest };
}

function validateBaseUrl(value, manifest) {
	if (
		value !== 'https://akibara.cl'
		|| value !== manifest.target_origin
	) {
		fail('--base-url must be exactly the reviewed production origin');
	}
	return value;
}

function validateSession(value) {
	if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/u.test(value ?? '')) {
		fail('unsafe or missing --session');
	}
	return value;
}

function validateCheckpointPath(value) {
	const repository = repositoryRoot();
	const roots = [
		path.join(repository, '.local-reports'),
		path.join(repository, '.local-checkpoints'),
	];
	const file = validateLocalFile(value, {
		label: '--checkpoint',
		allowedRoots: roots,
		mustExist: false,
	});
	if (fs.existsSync(file)) {
		const stats = fs.lstatSync(file);
		if (!stats.isFile() || stats.isSymbolicLink()) {
			fail('--checkpoint must be a regular non-symlink file');
		}
		if ((stats.mode & 0o777) !== 0o600) {
			fail('existing checkpoint mode must be exactly 0600');
		}
	}
	return file;
}

function newCheckpoint(manifest) {
	const now = new Date().toISOString();
	return {
		schema_version: 1,
		checkpoint_type: 'akibara_ranking_publish_official_ui',
		manifest_sha256: manifest.manifest_sha256,
		environment: manifest.environment,
		target_origin: manifest.target_origin,
		post_id: manifest.post.post_id,
		created_at: now,
		updated_at: now,
		preflight: null,
		apply: null,
	};
}

function loadCheckpoint(file, manifest) {
	if (!fs.existsSync(file)) return newCheckpoint(manifest);
	let checkpoint;
	try {
		checkpoint = JSON.parse(fs.readFileSync(file, 'utf8'));
	} catch (error) {
		fail(`checkpoint is invalid JSON: ${error.message}`);
	}
	if (
		checkpoint?.schema_version !== 1
		|| checkpoint.checkpoint_type
			!== 'akibara_ranking_publish_official_ui'
		|| checkpoint.manifest_sha256 !== manifest.manifest_sha256
		|| checkpoint.environment !== manifest.environment
		|| checkpoint.target_origin !== manifest.target_origin
		|| checkpoint.post_id !== manifest.post.post_id
		|| !Object.hasOwn(checkpoint, 'preflight')
		|| !Object.hasOwn(checkpoint, 'apply')
	) {
		fail('checkpoint belongs to another manifest, post or environment');
	}
	return checkpoint;
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

function parseCliJson(stdout, stderr) {
	let outer;
	try {
		outer = JSON.parse(stdout);
	} catch {
		fail(
			`Playwright CLI did not return JSON: `
			+ `${String(stderr || stdout).slice(0, 300)}`,
		);
	}
	if (typeof outer?.result !== 'string') {
		fail('Playwright CLI returned no serialized result');
	}
	try {
		return JSON.parse(outer.result);
	} catch {
		fail('Playwright browser function returned invalid JSON');
	}
}

function runPlaywrightFunction(session, source, runtimeDirectory) {
	const functionPath = path.join(
		runtimeDirectory,
		`ranking-publish-${process.pid}-${Date.now()}.js`,
	);
	fs.writeFileSync(functionPath, `${source}\n`, {
		mode: 0o600,
		flag: 'wx',
	});
	let command;
	try {
		command = spawnSync(
			'npx',
			[
				'--yes',
				'--package',
				RANKING_PUBLISH_PLAYWRIGHT_PACKAGE,
				'playwright-cli',
				`--session=${session}`,
				'--json',
				'run-code',
				`--filename=${functionPath}`,
			],
			{
				cwd: runtimeDirectory,
				encoding: 'utf8',
				maxBuffer: 8 * 1024 * 1024,
				timeout: 240_000,
				shell: false,
				windowsHide: true,
			},
		);
	} finally {
		fs.rmSync(functionPath, { force: true });
	}
	if (command.error || command.status !== 0) {
		fail(
			`Playwright CLI failed (${command.status ?? 'spawn'}): `
			+ `${String(command.stderr || command.stdout || command.error?.message)
				.slice(0, 500)}`,
		);
	}
	return parseCliJson(command.stdout, command.stderr);
}

export function rankingPublishBrowserFunction({
	baseUrl,
	manifest,
	phase,
	expectedFingerprintSha256 = null,
}) {
	if (!['preflight', 'apply'].includes(phase)) {
		fail(`unsupported browser phase ${phase}`);
	}
	if (
		phase === 'apply'
		&& !/^[a-f0-9]{64}$/u.test(expectedFingerprintSha256 ?? '')
	) {
		fail('apply browser phase requires a preflight fingerprint');
	}
	return `async page => {
		/* AKB_RANKING_PUBLISH_OFFICIAL_UI */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const manifest = ${JSON.stringify(manifest)};
		const phase = ${JSON.stringify(phase)};
		const expectedFingerprintSha256 = ${JSON.stringify(
			expectedFingerprintSha256,
		)};
		const ensureWordPressBlockEditorField = ${
			ensureWordPressBlockEditorField.toString()
		};
		const editUrl = baseUrl + manifest.post.edit_path;
		const mutationRequests = [];
		const routeViolations = [];
		let acceptedResponseStatus = null;
		let baselineFingerprint = null;
		page.setDefaultNavigationTimeout(75000);
		await page.setViewportSize({ width: 1440, height: 1000 });

		function cleanError(error) {
			return String(error?.message ?? error ?? 'UNKNOWN')
				.replace(/[^A-Za-z0-9_.:-]/gu, '_')
				.slice(0, 240);
		}

		async function sha256Text(value) {
			const bytes = new TextEncoder().encode(String(value));
			const digest = await crypto.subtle.digest('SHA-256', bytes);
			return [...new Uint8Array(digest)]
				.map((byte) => byte.toString(16).padStart(2, '0'))
				.join('');
		}

		async function navigateEditor() {
			await page.goto(editUrl, {
				waitUntil: 'domcontentloaded',
				timeout: 75000,
			});
			if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
				throw new Error('AUTH_REQUIRED');
			}
			const current = new URL(page.url());
			if (
				current.origin !== baseUrl
				|| current.pathname !== '/wp-admin/post.php'
				|| current.searchParams.get('post')
					!== String(manifest.post.post_id)
				|| current.searchParams.get('action') !== 'edit'
			) {
				throw new Error('POST_EDITOR_PATH_ESCAPE');
			}
			await page.locator('#post_ID').waitFor({
				state: 'attached',
				timeout: 30000,
			});
		}

		async function oneVisible(selectors, code) {
			const candidates = page.locator(selectors);
			const visible = [];
			for (let index = 0; index < await candidates.count(); index += 1) {
				const candidate = candidates.nth(index);
				if (await candidate.isVisible()) visible.push(candidate);
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
						const candidate = candidates.nth(index);
						if (await candidate.isVisible()) visible.push(candidate);
					}
				}
				if (visible.length === 1) return visible[0];
				if (visible.length > 1) {
					throw new Error(
						'POST_TITLE_MISSING_OR_AMBIGUOUS:' + visible.length,
					);
				}
				await page.waitForTimeout(500);
			}
			throw new Error('POST_TITLE_MISSING_OR_AMBIGUOUS:0');
		}

		async function controlValue(control) {
			return control.evaluate((element) => (
				typeof element.value === 'string'
					? element.value
					: element.textContent ?? ''
			));
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

		async function readPostStatus() {
			const sidebar = await openDocumentSettings();
			const status = sidebar.locator(
				'button.editor-post-status__toggle:visible',
			);
			if (await status.count() !== 1) {
				throw new Error('POST_STATUS_TOGGLE_MISSING_OR_AMBIGUOUS');
			}
			const label = String(await status.textContent() ?? '')
				.replace(/\\s+/gu, ' ')
				.trim();
			if (/^(?:Borrador|Draft)$/iu.test(label)) return 'draft';
			if (/^(?:Publicad[oa]|Published)$/iu.test(label)) return 'publish';
			throw new Error('POST_STATUS_UNRECOGNIZED:' + label);
		}

		async function readSlug() {
			let input = page.locator('.editor-post-url__input input:visible');
			if (await input.count() === 0) {
				const toggle = page.locator(
					'button.editor-post-url__panel-toggle:visible',
				);
				if (await toggle.count() !== 1) {
					throw new Error('POST_SLUG_TOGGLE_MISSING_OR_AMBIGUOUS');
				}
				await toggle.click({ trial: true });
				await toggle.click();
				input = page.locator('.editor-post-url__input input:visible');
				await input.waitFor({ state: 'visible', timeout: 15000 });
			}
			if (await input.count() !== 1) {
				throw new Error('POST_SLUG_INPUT_MISSING_OR_AMBIGUOUS');
			}
			return input.inputValue();
		}

		async function openRankMathGeneral() {
			let surface = page.locator(
				'.rank-math-sidebar-panel:visible, '
					+ '#rank_math_metabox .rank-math-tabs:visible',
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
						+ '#rank_math_metabox .rank-math-tabs:visible',
				);
			}
			if (await surface.count() !== 1) {
				throw new Error('RANK_MATH_SURFACE_MISSING_OR_AMBIGUOUS');
			}
			await surface.locator('.rank-math-focus-keyword')
				.waitFor({ state: 'visible', timeout: 15000 });
			return surface;
		}

		async function closeRankMathSnippet() {
			const dialog = page.locator(
				'.rank-math-modal[role="dialog"]:visible, '
					+ '.rank-math-modal-overlay [role="dialog"]:visible',
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

		async function readRankMath() {
			const surface = await openRankMathGeneral();
			const focusPanel = surface.locator('.rank-math-focus-keyword');
			if (await focusPanel.count() !== 1) {
				throw new Error('RANK_MATH_FOCUS_PANEL_MISSING');
			}
			const focus = (await focusPanel.locator(
				'.tagify__tag-text',
			).allTextContents()).map((value) => value.trim())
				.filter(Boolean).join(',');
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
				'#rank-math-editor-description:visible',
			);
			if (await title.count() !== 1 || await description.count() !== 1) {
				throw new Error('RANK_MATH_FIELDS_MISSING_OR_AMBIGUOUS');
			}
			const values = {
				rank_math_focus_keyword: focus.replace(/\\r\\n?/gu, '\\n'),
				rank_math_title: (await title.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
				rank_math_description: (await description.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
			};
			await closeRankMathSnippet();
			return values;
		}

		async function readFeaturedImage() {
			await openDocumentSettings();
			const panel = page.locator('.editor-post-featured-image:visible');
			await panel.waitFor({ state: 'visible', timeout: 15000 });
			if (await panel.count() !== 1) {
				throw new Error('FEATURED_IMAGE_PANEL_MISSING_OR_AMBIGUOUS');
			}
			let action = panel.locator([
				'button[aria-label*="Reemplazar" i]:visible',
				'button[aria-label*="Replace" i]:visible',
			].join(', '));
			if (await action.count() === 0) {
				action = panel.locator('button:visible').filter({
					hasText: /Reemplazar|Replace/iu,
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
			const selected = modal.locator('li.attachment.selected:visible');
			await selected.first().waitFor({ state: 'visible', timeout: 30000 });
			if (await selected.count() !== 1) {
				throw new Error('SELECTED_MEDIA_MISSING_OR_AMBIGUOUS');
			}
			const attachmentId = Number(await selected.getAttribute('data-id'));
			const details = modal.locator(
				'.attachment-details:visible, .attachment-info:visible',
			);
			const urlControl = details.locator('input[data-setting="url"]');
			const altControl = details.locator(
				'textarea[data-setting="alt"], input[data-setting="alt"]',
			);
			if (
				!Number.isSafeInteger(attachmentId)
				|| attachmentId < 1
				|| await urlControl.count() !== 1
				|| await altControl.count() !== 1
			) {
				throw new Error('SELECTED_MEDIA_DETAILS_INCOMPLETE');
			}
			const filename = decodeURIComponent(
				new URL(await urlControl.inputValue(), baseUrl)
					.pathname.split('/').at(-1),
			);
			const featured = {
				attachment_id: attachmentId,
				filename,
				alt: await altControl.inputValue(),
			};
			const close = modal.locator('.media-modal-close:visible');
			if (await close.count() !== 1) {
				throw new Error('MEDIA_MODAL_CLOSE_MISSING');
			}
			await close.click();
			await modal.waitFor({ state: 'hidden', timeout: 10000 });
			return featured;
		}

		async function protectedState() {
			const title = await titleField();
			const content = await ensureWordPressBlockEditorField(page, {
				field: 'post_content',
				key: 'ranking-publish:post_content',
			});
			const excerpt = await ensureWordPressBlockEditorField(page, {
				field: 'post_excerpt',
				key: 'ranking-publish:post_excerpt',
			});
			const wordpress = {
				post_title: (await controlValue(title)).replace(/\\r\\n?/gu, '\\n'),
				post_content: (await content.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
				post_excerpt: (await excerpt.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
			};
			const rankMath = await readRankMath();
			const featuredImage = await readFeaturedImage();
			const values = {
				...wordpress,
				...rankMath,
				featured_image: featuredImage,
			};
			const fieldSha256 = {};
			for (const [key, value] of Object.entries(values)) {
				fieldSha256[key] = await sha256Text(
					typeof value === 'string' ? value : JSON.stringify(value),
				);
			}
			return {
				values,
				field_sha256: fieldSha256,
				fingerprint_sha256: await sha256Text(JSON.stringify(values)),
			};
		}

		async function inspect(expectedStatus) {
			const id = page.locator('#post_ID');
			if (
				await id.count() !== 1
				|| Number(await id.inputValue()) !== manifest.post.post_id
			) {
				throw new Error('POST_IDENTITY_MISMATCH');
			}
			const slug = await readSlug();
			if (slug !== manifest.post.slug) {
				throw new Error('POST_SLUG_MISMATCH:' + slug);
			}
			const postStatus = await readPostStatus();
			if (postStatus !== expectedStatus) {
				throw new Error(
					'POST_STATUS_MISMATCH:' + postStatus + ':' + expectedStatus,
				);
			}
			const protectedFields = await protectedState();
			return {
				post_id: manifest.post.post_id,
				slug,
				post_status: postStatus,
				...protectedFields,
			};
		}

		function payloadScalar(value) {
			if (
				value
				&& typeof value === 'object'
				&& typeof value.raw === 'string'
			) return value.raw;
			return value;
		}

		function validatePublishPayload(payload, baseline) {
			if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
				throw new Error('PUBLISH_PAYLOAD_NOT_OBJECT');
			}
			if (payload.status !== 'publish') {
				throw new Error('PUBLISH_PAYLOAD_STATUS_MISMATCH');
			}
			if (
				Object.hasOwn(payload, 'id')
				&& Number(payload.id) !== manifest.post.post_id
			) throw new Error('PUBLISH_PAYLOAD_ID_MISMATCH');
			if (
				Object.hasOwn(payload, 'slug')
				&& payload.slug !== manifest.post.slug
			) throw new Error('PUBLISH_PAYLOAD_SLUG_MISMATCH');
			for (const field of ['title', 'content', 'excerpt']) {
				if (!Object.hasOwn(payload, field)) continue;
				const key = 'post_' + field;
				if (payloadScalar(payload[field]) !== baseline.values[key]) {
					throw new Error('PUBLISH_PAYLOAD_PROTECTED_MISMATCH:' + field);
				}
			}
			if (
				Object.hasOwn(payload, 'featured_media')
				&& Number(payload.featured_media)
					!== baseline.values.featured_image.attachment_id
			) throw new Error('PUBLISH_PAYLOAD_FEATURED_MEDIA_MISMATCH');
			if (Object.hasOwn(payload, 'meta')) {
				if (
					!payload.meta
					|| typeof payload.meta !== 'object'
					|| Array.isArray(payload.meta)
				) throw new Error('PUBLISH_PAYLOAD_META_INVALID');
				for (const key of [
					'rank_math_focus_keyword',
					'rank_math_title',
					'rank_math_description',
				]) {
					if (
						Object.hasOwn(payload.meta, key)
						&& payload.meta[key] !== baseline.values[key]
					) throw new Error('PUBLISH_PAYLOAD_SEO_MISMATCH:' + key);
				}
			}
		}

		try {
			await navigateEditor();
			const before = await inspect('draft');
			baselineFingerprint = before.fingerprint_sha256;
			if (phase === 'preflight') {
				return {
					status: 'preflight_ready',
					post_id: before.post_id,
					slug: before.slug,
					post_status: before.post_status,
					editorial_fingerprint_sha256: before.fingerprint_sha256,
					protected_field_sha256: before.field_sha256,
					route_mutation_requests: 0,
				};
			}
			if (before.fingerprint_sha256 !== expectedFingerprintSha256) {
				throw new Error('PREFLIGHT_FINGERPRINT_DRIFT');
			}

			let acceptedWrites = 0;
			const guard = async (route) => {
				const request = route.request();
				const method = request.method().toUpperCase();
				if (!['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
					await route.continue();
					return;
				}
				const target = new URL(request.url());
				const expectedPath = '/wp-json/wp/v2/posts/'
					+ manifest.post.post_id;
				if (
					target.origin !== baseUrl
					|| target.pathname !== expectedPath
					|| !['POST', 'PUT', 'PATCH'].includes(method)
					|| acceptedWrites !== 0
				) {
					routeViolations.push(
						acceptedWrites !== 0
							? 'MULTIPLE_OR_UNRELATED_MUTATION_REQUEST'
							: 'UNRELATED_MUTATION_REQUEST',
					);
					await route.abort();
					return;
				}
				let payload;
				try {
					payload = JSON.parse(request.postData() ?? '');
					validatePublishPayload(payload, before);
				} catch (error) {
					routeViolations.push(cleanError(error));
					await route.abort();
					return;
				}
				acceptedWrites += 1;
				mutationRequests.push({
					method,
					path: target.pathname,
					status: payload.status,
					body_sha256: await sha256Text(request.postData() ?? ''),
				});
				await route.continue();
			};
			const responseListener = (response) => {
				const request = response.request();
				const target = new URL(response.url());
				if (
					target.origin === baseUrl
					&& target.pathname
						=== '/wp-json/wp/v2/posts/' + manifest.post.post_id
					&& ['POST', 'PUT', 'PATCH'].includes(
						request.method().toUpperCase(),
					)
				) acceptedResponseStatus = response.status();
			};
			await page.route('**/*', guard);
			page.on('response', responseListener);

			const publish = await oneVisible(
				'.editor-post-publish-button:visible',
				'PUBLISH_BUTTON_MISSING_OR_AMBIGUOUS',
			);
			const publishLabel = String(await publish.textContent() ?? '')
				.replace(/\\s+/gu, ' ').trim();
			if (!/^(?:Publicar|Publish)(?:…|\\.\\.\\.)?$/iu.test(publishLabel)) {
				throw new Error('PUBLISH_BUTTON_LABEL_MISMATCH:' + publishLabel);
			}
			if (!await publish.isEnabled()) {
				throw new Error('PUBLISH_BUTTON_DISABLED');
			}
			await publish.click({ trial: true });
			await publish.click();

			let confirmationClicked = false;
			for (let attempt = 0; attempt < 60; attempt += 1) {
				if (routeViolations.length > 0 || acceptedWrites === 1) break;
				const confirm = page.locator([
					'.editor-post-publish-panel__header-publish-button:visible',
					'.post-publish-panel__header-publish-button:visible',
				].join(', '));
				if (await confirm.count() > 1) {
					throw new Error('PUBLISH_CONFIRMATION_AMBIGUOUS');
				}
				if (await confirm.count() === 1) {
					const label = String(await confirm.textContent() ?? '')
						.replace(/\\s+/gu, ' ').trim();
					if (!/^(?:Publicar|Publish)$/iu.test(label)) {
						throw new Error('PUBLISH_CONFIRMATION_LABEL_MISMATCH:' + label);
					}
					if (!await confirm.isEnabled()) {
						throw new Error('PUBLISH_CONFIRMATION_DISABLED');
					}
					await confirm.click({ trial: true });
					await confirm.click();
					confirmationClicked = true;
					break;
				}
				await page.waitForTimeout(250);
			}
			for (let attempt = 0; attempt < 180; attempt += 1) {
				if (
					routeViolations.length > 0
					|| (
						acceptedWrites === 1
						&& acceptedResponseStatus !== null
					)
				) break;
				await page.waitForTimeout(250);
			}
			await page.unroute('**/*', guard);
			page.off('response', responseListener);
			if (routeViolations.length > 0) {
				throw new Error('ROUTE_POLICY_BLOCKED:' + routeViolations.join(','));
			}
			if (acceptedWrites !== 1 || mutationRequests.length !== 1) {
				throw new Error('EXACTLY_ONE_PUBLISH_REQUEST_REQUIRED');
			}
			if (
				!Number.isInteger(acceptedResponseStatus)
				|| acceptedResponseStatus < 200
				|| acceptedResponseStatus > 299
			) {
				throw new Error(
					'PUBLISH_RESPONSE_REJECTED:' + acceptedResponseStatus,
				);
			}

			await navigateEditor();
			const after = await inspect('publish');
			if (after.fingerprint_sha256 !== before.fingerprint_sha256) {
				throw new Error('PROTECTED_FIELDS_CHANGED_AFTER_PUBLISH');
			}

			const publicPage = await page.context().newPage();
			let publicVerification;
			try {
				const response = await publicPage.goto(manifest.post.public_url, {
					waitUntil: 'domcontentloaded',
					timeout: 75000,
				});
				const finalUrl = publicPage.url().split('#', 1)[0];
				const canonical = publicPage.locator('link[rel="canonical"]');
				const canonicalUrl = await canonical.count() === 1
					? await canonical.getAttribute('href')
					: null;
				const bodyClass = String(
					await publicPage.locator('body').getAttribute('class') ?? '',
				);
				publicVerification = {
					url: finalUrl,
					http_status: response?.status() ?? null,
					canonical_url: canonicalUrl,
					post_id_body_class: new RegExp(
						'(?:^|\\\\s)postid-' + manifest.post.post_id
							+ '(?:\\\\s|$)',
					).test(bodyClass),
				};
			} finally {
				await publicPage.close();
			}
			if (
				publicVerification.url !== manifest.post.public_url
				|| !Number.isInteger(publicVerification.http_status)
				|| publicVerification.http_status < 200
				|| publicVerification.http_status > 299
				|| publicVerification.canonical_url !== manifest.post.public_url
				|| publicVerification.post_id_body_class !== true
			) throw new Error('PUBLIC_POST_VERIFICATION_FAILED');

			return {
				status: 'published_verified',
				post_id: after.post_id,
				slug: after.slug,
				before_status: before.post_status,
				after_status: after.post_status,
				editorial_fingerprint_sha256: after.fingerprint_sha256,
				protected_field_sha256: after.field_sha256,
				protected_fields_unchanged: true,
				confirmation_clicked: confirmationClicked,
				mutation_requests: mutationRequests,
				publish_response_status: acceptedResponseStatus,
				public_verification: publicVerification,
			};
		} catch (error) {
			return {
				status: 'blocked',
				phase,
				error_code: cleanError(error),
				post_id: manifest.post.post_id,
				editorial_fingerprint_sha256: baselineFingerprint,
				mutation_requests: mutationRequests,
				route_violations: routeViolations,
				publish_response_status: acceptedResponseStatus,
			};
		}
	}`;
}

export function assertRankingPublishBrowserFunctionSafety(source) {
	try {
		Function(`"use strict"; return (${source});`);
	} catch (error) {
		fail(`browser function is invalid: ${error.message}`);
	}
	if (!source.includes('AKB_RANKING_PUBLISH_OFFICIAL_UI')) {
		fail('browser function marker is missing');
	}
	if (
		/fetch\s*\(|XMLHttpRequest|page\.request|context\.request|request\.(?:post|put|patch|delete)\s*\(|admin-ajax\.php|update_post_meta|update_option|\$wpdb|wp-cli|setInputFiles\s*\(|\.fill\s*\(/iu
			.test(source)
	) {
		fail('browser function contains a prohibited direct or editorial write');
	}
	if (
		!source.includes("await page.route('**/*', guard)")
		|| !source.includes('.editor-post-publish-button:visible')
		|| !source.includes('PUBLISH_PAYLOAD_PROTECTED_MISMATCH')
		|| !source.includes('PROTECTED_FIELDS_CHANGED_AFTER_PUBLISH')
	) {
		fail('browser function is missing its publish-only route guard');
	}
}

function validatePreflightResult(result, manifest) {
	if (
		result?.status !== 'preflight_ready'
		|| result.post_id !== manifest.post.post_id
		|| result.slug !== manifest.post.slug
		|| result.post_status !== manifest.post.before_status
		|| !/^[a-f0-9]{64}$/u.test(
			result.editorial_fingerprint_sha256 ?? '',
		)
		|| !result.protected_field_sha256
		|| typeof result.protected_field_sha256 !== 'object'
		|| result.route_mutation_requests !== 0
	) {
		fail(`preflight result rejected (${result?.error_code ?? 'invalid'})`);
	}
	const expectedFields = [
		'featured_image',
		'post_content',
		'post_excerpt',
		'post_title',
		'rank_math_description',
		'rank_math_focus_keyword',
		'rank_math_title',
	];
	if (
		Object.keys(result.protected_field_sha256).sort().join(',')
			!== expectedFields.join(',')
		|| Object.values(result.protected_field_sha256)
			.some((value) => !/^[a-f0-9]{64}$/u.test(value ?? ''))
	) {
		fail('preflight protected field hashes are incomplete');
	}
}

function validateApplyResult(result, manifest, expectedFingerprint) {
	const request = result?.mutation_requests?.[0];
	const publicVerification = result?.public_verification;
	if (
		result?.status !== 'published_verified'
		|| result.post_id !== manifest.post.post_id
		|| result.slug !== manifest.post.slug
		|| result.before_status !== manifest.post.before_status
		|| result.after_status !== manifest.post.after_status
		|| result.editorial_fingerprint_sha256 !== expectedFingerprint
		|| result.protected_fields_unchanged !== true
		|| result.mutation_requests?.length !== 1
		|| request.method !== 'POST'
		|| request.path
			!== `/wp-json/wp/v2/posts/${manifest.post.post_id}`
		|| request.status !== 'publish'
		|| !/^[a-f0-9]{64}$/u.test(request.body_sha256 ?? '')
		|| !Number.isInteger(result.publish_response_status)
		|| result.publish_response_status < 200
		|| result.publish_response_status > 299
		|| publicVerification?.url !== manifest.post.public_url
		|| publicVerification?.canonical_url !== manifest.post.public_url
		|| publicVerification?.post_id_body_class !== true
		|| !Number.isInteger(publicVerification.http_status)
		|| publicVerification.http_status < 200
		|| publicVerification.http_status > 299
	) {
		fail(`apply result rejected (${result?.error_code ?? 'invalid'})`);
	}
}

function assertRecentPreflight(checkpoint) {
	const preflight = checkpoint.preflight;
	const observed = Date.parse(preflight?.observed_at ?? '');
	const age = Date.now() - observed;
	if (
		preflight?.status !== 'preflight_ready'
		|| !Number.isFinite(observed)
		|| age < 0
		|| age > RANKING_PUBLISH_PREFLIGHT_MAX_AGE_MS
		|| !/^[a-f0-9]{64}$/u.test(
			preflight.editorial_fingerprint_sha256 ?? '',
		)
	) {
		fail('apply requires a successful recent matching preflight');
	}
	return preflight.editorial_fingerprint_sha256;
}

async function main() {
	const args = parseArgs(process.argv.slice(2));
	const { manifest } = loadManifest(args.manifest);
	const preflightSource = rankingPublishBrowserFunction({
		baseUrl: manifest.target_origin,
		manifest,
		phase: 'preflight',
	});
	assertRankingPublishBrowserFunctionSafety(preflightSource);
	if (args.validateOnly) {
		process.stdout.write(`${JSON.stringify({
			result: 'PASS',
			mode: 'validate-only',
			environment: manifest.environment,
			target_origin: manifest.target_origin,
			manifest_sha256: manifest.manifest_sha256,
			post_id: manifest.post.post_id,
			transition:
				`${manifest.post.before_status}->${manifest.post.after_status}`,
		}, null, 2)}\n`);
		return;
	}
	const baseUrl = validateBaseUrl(args.baseUrl, manifest);
	const session = validateSession(args.session);
	const checkpointPath = validateCheckpointPath(args.checkpoint);
	const checkpoint = loadCheckpoint(checkpointPath, manifest);
	let expectedFingerprint = null;
	if (args.apply) {
		if (args.confirmManifestSha256 !== manifest.manifest_sha256) {
			fail(
				'apply requires --confirm-manifest-sha256 equal to the '
				+ 'reviewed manifest',
			);
		}
		if (checkpoint.apply?.status === 'published_verified') {
			fail('checkpoint already records a verified publication');
		}
		expectedFingerprint = assertRecentPreflight(checkpoint);
	}
	const source = args.apply
		? rankingPublishBrowserFunction({
			baseUrl,
			manifest,
			phase: 'apply',
			expectedFingerprintSha256: expectedFingerprint,
		})
		: preflightSource;
	assertRankingPublishBrowserFunctionSafety(source);
	const runtimeDirectory = fs.mkdtempSync(
		path.join(os.tmpdir(), 'akb-ranking-publish-official-ui-'),
	);
	fs.chmodSync(runtimeDirectory, 0o700);
	let result;
	try {
		result = runPlaywrightFunction(session, source, runtimeDirectory);
	} finally {
		fs.rmSync(runtimeDirectory, { recursive: true, force: true });
	}
	const observedAt = new Date().toISOString();
	if (args.apply) {
		checkpoint.apply = { ...result, observed_at: observedAt };
		checkpoint.updated_at = observedAt;
		writeAtomic0600(checkpointPath, checkpoint);
		validateApplyResult(result, manifest, expectedFingerprint);
	} else {
		validatePreflightResult(result, manifest);
		checkpoint.preflight = { ...result, observed_at: observedAt };
		checkpoint.apply = null;
		checkpoint.updated_at = observedAt;
		writeAtomic0600(checkpointPath, checkpoint);
	}
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		mode: args.apply ? 'apply' : 'preflight',
		environment: manifest.environment,
		manifest_sha256: manifest.manifest_sha256,
		post_id: manifest.post.post_id,
		status: result.status,
		checkpoint: checkpointPath,
		...(args.apply ? {
			public_url: result.public_verification.url,
			protected_fields_unchanged: result.protected_fields_unchanged,
		} : {
			editorial_fingerprint_sha256:
				result.editorial_fingerprint_sha256,
		}),
	}, null, 2)}\n`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	main().catch((error) => {
		process.stderr.write(`${error.message}\n`);
		process.exitCode = 1;
	});
}
