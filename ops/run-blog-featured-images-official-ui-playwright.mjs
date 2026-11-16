#!/usr/bin/env node

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	featuredImageSha256,
	validateFeaturedImageManifest,
} from './lib/blog-featured-image-official-ui-manifest.mjs';

function fail(message) {
	throw new Error(`Featured image official UI runner failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function inside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== '' && relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function localReport(value, repository) {
	if (!value) fail('missing --report');
	const report = path.resolve(value);
	if (
		!['.local-reports', '.local-checkpoints']
			.some((name) => inside(report, path.join(repository, name)))
	) {
		fail('report must remain in a local report directory');
	}
	if (fs.existsSync(report)) fail('refusing to overwrite existing report');
	return report;
}

function validateFiles(manifest, repository) {
	for (const post of manifest.posts) {
		const file = path.resolve(repository, post.replacement.local_file);
		if (
			!inside(file, repository)
			|| !fs.existsSync(file)
			|| !fs.lstatSync(file).isFile()
			|| fs.lstatSync(file).isSymbolicLink()
		) {
			fail(`unsafe or missing local image for post ${post.post_id}`);
		}
		const bytes = fs.readFileSync(file);
		if (
			featuredImageSha256(bytes) !== post.replacement.sha256
			|| bytes.subarray(0, 4).toString('ascii') !== 'RIFF'
			|| bytes.subarray(8, 12).toString('ascii') !== 'WEBP'
		) {
			fail(`local WebP hash or signature mismatch for post ${post.post_id}`);
		}
		const dimensions = spawnSync(
			'sips',
			['-g', 'pixelWidth', '-g', 'pixelHeight', file],
			{ encoding: 'utf8', timeout: 30_000 }
		);
		if (
			dimensions.status !== 0
			|| !new RegExp(
				`pixelWidth:\\s*${post.replacement.width}(?:\\s|$)`,
				'u'
			).test(dimensions.stdout)
			|| !new RegExp(
				`pixelHeight:\\s*${post.replacement.height}(?:\\s|$)`,
				'u'
			).test(dimensions.stdout)
		) {
			fail(`local image dimensions mismatch for post ${post.post_id}`);
		}
		post.replacement.absolute_local_file = file;
	}
}

function parseResult(stdout, stderr) {
	let outer;
	try {
		outer = JSON.parse(stdout);
		return JSON.parse(outer.result);
	} catch {
		fail(`Playwright returned invalid JSON: ${(stderr || stdout).slice(0, 600)}`);
	}
}

function runBrowser(session, source, runtime) {
	const file = path.join(
		runtime,
		`featured-image-${process.pid}-${Date.now()}.js`
	);
	fs.writeFileSync(file, `${source}\n`, { flag: 'wx', mode: 0o600 });
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
			`--filename=${file}`,
		],
		{
			cwd: runtime,
			encoding: 'utf8',
			maxBuffer: 32 * 1024 * 1024,
			timeout: 420_000,
		}
	);
	fs.rmSync(file, { force: true });
	if (result.error || result.status !== 0) {
		fail(
			`Playwright failed (${result.status ?? 'spawn'}): `
			+ `${(result.stderr || result.stdout || result.error?.message)
				.slice(0, 1000)}`
		);
	}
	return parseResult(result.stdout, result.stderr);
}

export function featuredImageBrowserFunction({ baseUrl, post, phase }) {
	return `async page => {
		/* AKB_BLOG_FEATURED_IMAGE_OFFICIAL_UI */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const post = ${JSON.stringify(post)};
		const phase = ${JSON.stringify(phase)};
		const target = baseUrl + post.edit_path;
		page.setDefaultNavigationTimeout(75000);
		await page.setViewportSize({ width: 1440, height: 1000 });

		async function navigate() {
			await page.goto(target, {
				waitUntil: 'domcontentloaded',
				timeout: 75000,
			});
			if (/\\/wp-login\\.php(?:$|[?#])/u.test(page.url())) {
				throw new Error('AUTH_REQUIRED');
			}
			if (page.url() !== target && !page.url().startsWith(target + '#')) {
				throw new Error('POST_EDITOR_PATH_ESCAPE');
			}
			const update = page.locator('.editor-post-publish-button:visible');
			if (
				await update.count() !== 1
				|| !/Guardar|Save|Actualizar|Update/iu
					.test(String(await update.textContent()))
			) {
				throw new Error('POST_NOT_ALREADY_PUBLISHED');
			}
		}

		async function featuredPanel() {
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
			return panel;
		}

		async function openLibrary() {
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
				filename = String(await strongFilename.textContent() ?? '').trim();
			} else if (await plainFilename.count() === 1) {
				filename = String(await plainFilename.textContent() ?? '')
					.replace(/^(?:Nombre del archivo|File name):?\\s*/iu, '')
					.trim();
			}
			if (filename.includes('/')) {
				filename = decodeURIComponent(
					new URL(filename, baseUrl).pathname.split('/').at(-1)
				);
			}
			const alt = details.locator(
				'textarea[data-setting="alt"], input[data-setting="alt"]'
			);
			if (
				!Number.isSafeInteger(attachmentId)
				|| attachmentId < 1
				|| !filename
				|| await alt.count() !== 1
			) {
				throw new Error('SELECTED_MEDIA_DETAILS_INCOMPLETE');
			}
			return {
				attachment_id: attachmentId,
				filename,
				alt: await alt.inputValue(),
				altControl: alt,
			};
		}

		async function closeModal(modal) {
			const close = modal.locator('.media-modal-close:visible');
			if (await close.count() !== 1) {
				throw new Error('MEDIA_MODAL_CLOSE_MISSING');
			}
			await close.click();
			await modal.waitFor({ state: 'hidden', timeout: 10000 });
		}

		await navigate();
		let modal = await openLibrary();
		let current = await selectedMedia(modal);
		const isAfter = current.filename === post.replacement.expected_filename
			&& current.alt === post.replacement.alt;
		const isBefore = current.attachment_id === post.current.attachment_id
			&& current.filename === post.current.filename;
		if (!isAfter && !isBefore) {
			await closeModal(modal);
			return { status: 'drift', current };
		}
		if (phase !== 'apply' || isAfter) {
			await closeModal(modal);
			return { status: isAfter ? 'already_after' : 'before', current };
		}
		await closeModal(modal);
		modal = await openLibrary();
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
		await fileInput.setInputFiles(post.replacement.absolute_local_file);
		current = await selectedMedia(modal);
		if (current.filename !== post.replacement.expected_filename) {
			throw new Error(
				'UPLOADED_FILENAME_MISMATCH:' + current.filename
			);
		}
		await current.altControl.fill(post.replacement.alt);
		await current.altControl.blur();
		const select = modal.locator('.media-button-select:visible').filter({
			hasText: /Establecer la imagen destacada|Set featured image/iu,
		});
		if (await select.count() !== 1 || !await select.isEnabled()) {
			throw new Error('SET_FEATURED_IMAGE_ACTION_MISSING');
		}
		await select.click();
		await modal.waitFor({ state: 'hidden', timeout: 20000 });
		const update = page.locator('.editor-post-publish-button:visible');
		await update.click();
		await page.waitForTimeout(300);
		await page.waitForFunction(
			() => {
				const button = document.querySelector(
					'.editor-post-publish-button'
				);
				return button && !button.classList.contains('is-busy');
			},
			null,
			{ timeout: 45000 }
		);
		await navigate();
		modal = await openLibrary();
		const verified = await selectedMedia(modal);
		await closeModal(modal);
		if (
			verified.filename !== post.replacement.expected_filename
			|| verified.alt !== post.replacement.alt
		) {
			throw new Error('FEATURED_IMAGE_REOPEN_VERIFY_FAILED');
		}
		return { status: 'applied', current: verified };
	}`;
}

function writeReport(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		flag: 'wx',
		mode: 0o600,
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

async function main() {
	const repository = path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
	const manifestValue = option('--manifest');
	if (!manifestValue) fail('missing --manifest');
	const manifest = validateFeaturedImageManifest(
		JSON.parse(fs.readFileSync(path.resolve(manifestValue), 'utf8'))
	);
	const validateOnly = process.argv.includes('--validate-only');
	const apply = process.argv.includes('--apply');
	if (validateOnly && apply) {
		fail('--validate-only cannot be combined with --apply');
	}
	if (
		apply
		&& option('--confirm-manifest-sha256') !== manifest.manifest_sha256
	) {
		fail('apply requires exact --confirm-manifest-sha256');
	}
	validateFiles(manifest, repository);
	const sample = featuredImageBrowserFunction({
		baseUrl: manifest.target_origin,
		post: manifest.posts[0],
		phase: 'preflight',
	});
	try {
		Function(`"use strict"; return (${sample});`);
	} catch (error) {
		fail(`browser function syntax is invalid: ${error.message}`);
	}
	if (
		/fetch\s*\(|wp-json|admin-ajax|update_post_meta|update_option|\$wpdb/iu
			.test(sample)
	) {
		fail('browser function contains a prohibited direct channel');
	}
	if (validateOnly) {
		process.stdout.write(`${JSON.stringify({
			result: 'PASS',
			mode: 'validate-only',
			environment: manifest.environment,
			posts: manifest.posts.length,
			manifest_sha256: manifest.manifest_sha256,
		}, null, 2)}\n`);
		return;
	}
	const baseUrl = option('--base-url');
	if (baseUrl !== manifest.target_origin) {
		fail('--base-url must equal manifest target_origin');
	}
	const session = option('--session');
	if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/u.test(session ?? '')) {
		fail('unsafe or missing --session');
	}
	const report = localReport(option('--report'), repository);
	const runtime = fs.mkdtempSync(
		path.join(os.tmpdir(), 'akb-featured-image-ui-')
	);
	fs.chmodSync(runtime, 0o700);
	const results = [];
	try {
		for (const post of manifest.posts) {
			const result = runBrowser(
				session,
				featuredImageBrowserFunction({
					baseUrl,
					post,
					phase: 'preflight',
				}),
				runtime
			);
			if (result.status === 'drift') {
				fail(`preflight drift for post ${post.post_id}`);
			}
			results.push({ post_id: post.post_id, preflight: result });
		}
		if (apply) {
			for (const [index, post] of manifest.posts.entries()) {
				const result = runBrowser(
					session,
					featuredImageBrowserFunction({
						baseUrl,
						post,
						phase: 'apply',
					}),
					runtime
				);
				if (!['applied', 'already_after'].includes(result.status)) {
					fail(`apply did not persist post ${post.post_id}`);
				}
				results[index].apply = result;
			}
		}
	} finally {
		fs.rmSync(runtime, { recursive: true, force: true });
	}
	writeReport(report, {
		schema_version: 1,
		manifest_sha256: manifest.manifest_sha256,
		environment: manifest.environment,
		mode: apply ? 'apply' : 'preflight',
		completed_at: new Date().toISOString(),
		results,
	});
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		mode: apply ? 'apply' : 'preflight',
		posts: results.length,
		report,
	}, null, 2)}\n`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	main().catch((error) => {
		process.stderr.write(`${error.message}\n`);
		process.exitCode = 1;
	});
}
