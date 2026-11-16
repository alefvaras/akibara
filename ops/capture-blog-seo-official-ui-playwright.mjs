#!/usr/bin/env node

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	normalizeEditorialValue,
	REQUIRED_FIELDS,
	sha256,
} from './lib/blog-seo-official-ui-manifest.mjs';
import {
	ensureWordPressBlockEditorField,
} from './lib/wordpress-block-editor-official-ui.mjs';

const DEFERRED_DOCKER_CONTENT_KEY =
	'que-es-una-preventa-de-manga-y-por-que-deberias-aprovecharlas-en-chile';

function fail(message) {
	throw new Error(`Blog SEO official UI capture failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function isInside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== '' && relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function localOutput(value) {
	if (!value) fail('missing --output');
	const output = path.resolve(value);
	const repository = path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
	const allowed = ['.local-reports', '.local-checkpoints']
		.map((name) => path.join(repository, name));
	if (!allowed.some((root) => isInside(output, root))) {
		fail('output must remain under .local-reports or .local-checkpoints');
	}
	let current = output;
	while (current !== repository && current !== path.dirname(current)) {
		if (fs.existsSync(current) && fs.lstatSync(current).isSymbolicLink()) {
			fail('output path may not traverse symlinks');
		}
		current = path.dirname(current);
	}
	if (fs.existsSync(output)) fail('refusing to overwrite existing output');
	return output;
}

function exactKeys(value, allowed, label) {
	const unexpected = Object.keys(value ?? {})
		.filter((key) => !allowed.includes(key));
	if (unexpected.length > 0) {
		fail(`${label} has unexpected keys: ${unexpected.join(', ')}`);
	}
}

function validatedOrigin(baseUrl, environment) {
	let parsed;
	try {
		parsed = new URL(baseUrl);
	} catch {
		fail(`invalid origin ${baseUrl}`);
	}
	if (parsed.origin !== baseUrl) fail('--base-url must be an origin');
	if (
		environment === 'docker'
		&& !['localhost', '127.0.0.1'].includes(parsed.hostname)
	) {
		fail('Docker capture may run only against localhost');
	}
	if (
		environment === 'production'
		&& !['akibara.cl', 'www.akibara.cl'].includes(parsed.hostname)
	) {
		fail('production capture requires the Akibara hostname');
	}
	return parsed.origin;
}

function validatedSession(value) {
	if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/u.test(value ?? '')) {
		fail('unsafe or missing --session');
	}
	return value;
}

function validateReview(review, environment, baseUrl) {
	exactKeys(
		review,
		[
			'schema_version',
			'review_type',
			'environment',
			'target_origin',
			'reviewed',
			'inventory_post_count',
			'capture_scope',
			'posts',
			'deferred_content_keys',
			'validation_artifact',
		],
		'review'
	);
	const deferred = review?.deferred_content_keys ?? [];
	const inventoryPostCount = review?.inventory_post_count ?? 25;
	const captureScope = review?.capture_scope
		?? 'content_seo_and_featured_image_alt';
	const expectedPosts = inventoryPostCount - deferred.length;
	if (
		review?.schema_version !== 1
		|| review?.review_type !== 'akibara_blog_seo_ui_capture_review'
		|| review.environment !== environment
		|| review.target_origin !== baseUrl
		|| review.reviewed !== true
		|| !Number.isSafeInteger(inventoryPostCount)
		|| inventoryPostCount < 1
		|| inventoryPostCount > 500
		|| ![
			'content_and_seo',
			'content_seo_and_featured_image_alt',
		].includes(captureScope)
		|| !Array.isArray(review.posts)
		|| review.posts.length !== expectedPosts
	) {
		fail('review must be the approved inventory for this origin');
	}
	if (
		environment === 'production'
		&& (
			deferred.length !== 0
			|| review.validation_artifact !== undefined
		)
	) {
		fail('production review may not defer any content key');
	}
	if (
		environment === 'docker'
		&& deferred.length > 0
		&& (
			deferred.length !== 1
			|| deferred[0] !== DEFERRED_DOCKER_CONTENT_KEY
			|| !/^[a-f0-9]{64}$/u.test(review.validation_artifact ?? '')
		)
	) {
		fail('Docker may defer only the reviewed 24977 content key');
	}
	const keys = new Set();
	const postIds = new Set();
	for (const post of review.posts) {
		exactKeys(
			post,
			[
				'content_key',
				'post_id',
				'post_status',
				'public_url',
				'featured_image',
			],
			'review post'
		);
		if (captureScope === 'content_seo_and_featured_image_alt') {
			exactKeys(
				post.featured_image,
				['attachment_id', 'filename'],
				'review featured image'
			);
		}
		let publicUrl;
		try {
			publicUrl = new URL(post.public_url);
		} catch {
			fail(`invalid reviewed public URL for ${post.content_key}`);
		}
		if (
			typeof post.content_key !== 'string'
			|| !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(post.content_key)
			|| keys.has(post.content_key)
			|| !Number.isSafeInteger(post.post_id)
			|| post.post_id < 1
			|| postIds.has(post.post_id)
			|| !['publish', 'draft'].includes(post.post_status ?? 'publish')
			|| publicUrl.origin !== baseUrl
			|| (
				captureScope === 'content_seo_and_featured_image_alt'
				&& (
					!Number.isSafeInteger(post.featured_image?.attachment_id)
					|| post.featured_image.attachment_id < 1
					|| typeof post.featured_image.filename !== 'string'
					|| !/^[^/\\]+$/u.test(post.featured_image.filename)
				)
			)
		) {
			fail(`invalid reviewed identity for ${post.content_key ?? '(missing)'}`);
		}
		keys.add(post.content_key);
		postIds.add(post.post_id);
	}
	return review;
}

function validateDeferredArtifact({
	review,
	artifactValue,
	environment,
	baseUrl,
	repository,
}) {
	const deferred = review?.deferred_content_keys ?? [];
	if (deferred.length === 0) {
		if (artifactValue || review.validation_artifact !== undefined) {
			fail('validation artifact is forbidden without a deferred key');
		}
		return null;
	}
	if (environment !== 'docker' || !artifactValue) {
		fail('deferred Docker review requires --validation-artifact');
	}
	const artifact = path.resolve(artifactValue);
	const allowedRoot = path.join(repository, '.local-reports');
	if (
		!isInside(artifact, allowedRoot)
		|| !fs.existsSync(artifact)
		|| !fs.lstatSync(artifact).isFile()
		|| fs.lstatSync(artifact).isSymbolicLink()
	) {
		fail('validation artifact must be a regular local report');
	}
	const raw = fs.readFileSync(artifact);
	const artifactSha256 = sha256(raw.toString('utf8'));
	if (artifactSha256 !== review.validation_artifact) {
		fail('validation artifact SHA-256 does not match the reviewed hash');
	}
	let evidence;
	try {
		evidence = JSON.parse(raw.toString('utf8'));
	} catch {
		fail('validation artifact is not valid JSON');
	}
	const result = evidence?.result;
	const cleanup = result?.cleanup;
	const policy = evidence?.policy;
	if (
		evidence?.schema_version !== 1
		|| evidence?.report_type
			!== 'akibara_blog_seo_temporary_docker_draft_official_ui_evidence'
		|| evidence?.environment !== 'docker'
		|| evidence?.target_origin !== baseUrl
		|| !/^[a-f0-9]{64}$/u.test(evidence?.plan_sha256 ?? '')
		|| result?.status !== 'validated_and_cleaned'
		|| result?.plan_sha256 !== evidence.plan_sha256
		|| result?.persisted?.slug !== DEFERRED_DOCKER_CONTENT_KEY
		|| !Number.isFinite(result?.persisted_score?.score)
		|| result.persisted_score.score < 82
		|| cleanup?.draft_permanently_deleted_by_ui !== true
		|| cleanup?.attachment_permanently_deleted_by_ui !== true
		|| !Array.isArray(cleanup?.remaining_route_violations)
		|| cleanup.remaining_route_violations.length !== 0
		|| policy?.wordpress_and_rank_math_ui_only !== true
		|| policy?.draft_only !== true
		|| policy?.publish_requests !== 0
		|| policy?.production_writes !== 0
		|| policy?.cleanup_by_official_ui !== true
		|| policy?.cleanup_required_even_after_failure !== true
		|| policy?.remote_artifacts !== 0
	) {
		fail('validation artifact does not prove score and complete UI cleanup');
	}
	return {
		content_key: DEFERRED_DOCKER_CONTENT_KEY,
		artifact_sha256: artifactSha256,
		report_type: evidence.report_type,
		plan_sha256: evidence.plan_sha256,
		stable_rank_math_score: result.persisted_score.score,
		cleanup_result: 'PASS',
	};
}

function parseCliJson(stdout, stderr) {
	let outer;
	try {
		outer = JSON.parse(stdout);
	} catch {
		fail(`Playwright CLI did not return JSON: ${(stderr || stdout).slice(0, 500)}`);
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
		fail(`Playwright result was not JSON: ${String(outer.result).slice(0, 500)}`);
	}
}

function runPlaywright(session, source, runtimeDirectory) {
	const file = path.join(
		runtimeDirectory,
		`capture-function-${process.pid}-${Date.now()}.js`
	);
	fs.writeFileSync(file, `${source}\n`, { flag: 'wx', mode: 0o600 });
	try {
		for (let attempt = 1; attempt <= 2; attempt += 1) {
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
					`--filename=${file}`,
				],
				{
					cwd: runtimeDirectory,
					encoding: 'utf8',
					maxBuffer: 32 * 1024 * 1024,
					timeout: 420_000,
				}
			);
			if (command.error || command.status !== 0) {
				fail(
					`Playwright CLI failed (${command.status ?? 'spawn'}): `
					+ `${(
						command.stderr
						|| command.stdout
						|| command.error?.message
					).slice(0, 1000)}`
				);
			}
			let outer;
			try {
				outer = JSON.parse(command.stdout);
			} catch {
				return parseCliJson(command.stdout, command.stderr);
			}
			const missingTransportResult = (
				outer?.isError !== true
				&& typeof outer?.error !== 'string'
				&& typeof outer?.result !== 'string'
			);
			if (missingTransportResult && attempt < 2) {
				continue;
			}
			return parseCliJson(command.stdout, command.stderr);
		}
		fail('Playwright read-only capture exhausted its retry');
	} finally {
		fs.rmSync(file, { force: true });
	}
}

function acceptPendingBeforeUnload(session, runtimeDirectory) {
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
			cwd: runtimeDirectory,
			encoding: 'utf8',
			maxBuffer: 1024 * 1024,
			timeout: 30_000,
		}
	);
}

export function capturePostBrowserFunction({ baseUrl, post }) {
	return `async page => {
		/* AKB_BLOG_SEO_CAPTURE_POST_UI_READ_ONLY */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const post = ${JSON.stringify(post)};
		const expectedPostStatus = post.post_status || 'publish';
		const ensureWordPressBlockEditorField = ${
			ensureWordPressBlockEditorField.toString()
		};
		const target = baseUrl + '/wp-admin/post.php?post='
			+ post.post_id + '&action=edit';
		page.setDefaultNavigationTimeout(75000);
		await page.setViewportSize({ width: 1440, height: 1000 });
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

		async function oneVisible(selectors, code) {
			const candidates = page.locator(selectors);
			const visible = [];
			for (let index = 0; index < await candidates.count(); index += 1) {
				const locator = candidates.nth(index);
				if (await locator.isVisible()) visible.push(locator);
			}
			if (visible.length !== 1) throw new Error(code + ':' + visible.length);
			return visible[0];
		}

		async function titleField() {
			const titleSelectors = [
				'.editor-post-title__input textarea',
				'textarea[aria-label="Escribe un título"]',
				'input[aria-label="Escribe un título"]',
				'textarea[aria-label="Añadir título"]',
				'textarea[aria-label="Add title"]',
				'input.editor-post-title__input',
				'textarea.editor-post-title__input',
				'.editor-post-title__input[contenteditable="true"]',
			];
			for (let poll = 0; poll < 60; poll += 1) {
				const topLevel = page.locator(titleSelectors.join(', '));
				const topVisible = [];
				for (let index = 0; index < await topLevel.count(); index += 1) {
					const locator = topLevel.nth(index);
					if (await locator.isVisible()) topVisible.push(locator);
				}
				if (topVisible.length === 1) return topVisible[0];
				if (topVisible.length > 1) {
					throw new Error('POST_TITLE_MISSING_OR_AMBIGUOUS:' + topVisible.length);
				}
				const canvas = page.locator('iframe[name="editor-canvas"]:visible');
				if (await canvas.count() > 1) {
					throw new Error('POST_EDITOR_CANVAS_AMBIGUOUS');
				}
				if (await canvas.count() === 1) {
					const candidates = canvas.contentFrame()
						.locator(titleSelectors.join(', '));
					const visible = [];
					for (let index = 0; index < await candidates.count(); index += 1) {
						const locator = candidates.nth(index);
						if (await locator.isVisible()) visible.push(locator);
					}
					if (visible.length === 1) return visible[0];
					if (visible.length > 1) {
						throw new Error('POST_TITLE_MISSING_OR_AMBIGUOUS:' + visible.length);
					}
				}
				await page.waitForTimeout(500);
			}
			throw new Error('POST_TITLE_MISSING_OR_AMBIGUOUS:0');
		}

		async function openRankMathGeneral() {
			let surface = page.locator(
				'.rank-math-sidebar-panel:visible, '
				+ '#rank_math_metabox .rank-math-tabs:visible'
			);
			if (await surface.count() === 0) {
				const toggle = page.locator([
					'button[aria-label="Rank Math" i]:visible',
					'button[aria-controls="rank-math:seo-by-rank-math-sidebar"]:visible',
				].join(', '));
				if (await toggle.count() !== 1) {
					throw new Error('RANK_MATH_TOGGLE_MISSING_OR_AMBIGUOUS');
				}
				await toggle.click();
				await page.waitForTimeout(300);
				surface = page.locator(
					'.rank-math-sidebar-panel:visible, '
					+ '#rank_math_metabox .rank-math-tabs:visible'
				);
			}
			if (await surface.count() !== 1) {
				throw new Error('RANK_MATH_SURFACE_MISSING_OR_AMBIGUOUS');
			}
			const general = surface.locator(
				'.rank-math-general-tab, [role="tab"]'
			).filter({ hasText: /General/iu });
			if (
				await general.count() === 1
				&& await general.getAttribute('aria-selected') !== 'true'
			) {
				await general.click();
			} else if (await general.count() > 1) {
				throw new Error('RANK_MATH_GENERAL_TAB_AMBIGUOUS');
			}
			await surface.locator('.rank-math-focus-keyword')
				.waitFor({ state: 'visible', timeout: 15000 });
			await surface.locator('.rank-math-edit-snippet')
				.waitFor({ state: 'visible', timeout: 15000 });
			return surface;
		}

		async function snippetFields(surface) {
			const edit = surface.locator('.rank-math-edit-snippet');
			if (await edit.count() !== 1) {
				throw new Error('RANK_MATH_SNIPPET_CONTROL_MISSING');
			}
			let title = page.locator('#rank-math-editor-title:visible');
			if (await title.count() === 0) {
				await edit.click();
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

		const primaryActionSelector = [
			'.editor-post-publish-button:visible',
			'.editor-post-publish-button__button:visible',
		].join(', ');
		await page.locator(primaryActionSelector)
			.waitFor({ state: 'visible', timeout: 30000 });
		const update = await oneVisible(
			primaryActionSelector,
			'POST_UPDATE_BUTTON_MISSING_OR_AMBIGUOUS'
		);
		const updateText = String(await update.textContent());
		if (
			(expectedPostStatus === 'publish'
				&& !/Guardar|Save|Actualizar|Update/iu.test(updateText))
			|| (expectedPostStatus === 'draft'
				&& !/Publicar|Publish/iu.test(updateText))
		) {
			throw new Error('POST_STATUS_UI_MISMATCH');
		}
		const title = await titleField();
		const content = await ensureWordPressBlockEditorField(page, {
			field: 'post_content',
			key: 'capture:' + post.content_key + ':post_content',
		});
		const excerpt = await ensureWordPressBlockEditorField(page, {
			field: 'post_excerpt',
			key: 'capture:' + post.content_key + ':post_excerpt',
		});
		const controlValue = async control => control.evaluate(element => (
			typeof element.value === 'string'
				? element.value
				: element.textContent ?? ''
		));
		const wordpressValues = {
			post_title: (await controlValue(title)).replace(/\\r\\n?/gu, '\\n'),
			post_content: (await content.inputValue()).replace(/\\r\\n?/gu, '\\n'),
			post_excerpt: (await excerpt.inputValue()).replace(/\\r\\n?/gu, '\\n'),
		};
		const surface = await openRankMathGeneral();
		const focusPanel = surface.locator('.rank-math-focus-keyword');
		if (await focusPanel.count() !== 1) {
			throw new Error('RANK_MATH_FOCUS_PANEL_MISSING');
		}
		const focus = (await focusPanel.locator('.tagify__tag-text')
			.allTextContents()).map(value => value.trim()).filter(Boolean).join(',');
		const snippet = await snippetFields(surface);
		const captured = {
			fields: {
				...wordpressValues,
				rank_math_focus_keyword: focus.replace(/\\r\\n?/gu, '\\n'),
				rank_math_title: (await snippet.title.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
				rank_math_description: (await snippet.description.inputValue())
					.replace(/\\r\\n?/gu, '\\n'),
			},
		};
		let unexpectedDialog = '';
		const discardReadOnlyEditorState = async dialog => {
			if (dialog.type() !== 'beforeunload') {
				unexpectedDialog = dialog.type();
				await dialog.dismiss();
				return;
			}
			await dialog.accept();
		};
		page.on('dialog', discardReadOnlyEditorState);
		try {
			await page.goto(baseUrl + '/wp-admin/profile.php', {
				waitUntil: 'domcontentloaded',
				timeout: 75000,
			});
		} finally {
			page.off('dialog', discardReadOnlyEditorState);
		}
		if (
			unexpectedDialog
			|| (
				page.url() !== baseUrl + '/wp-admin/profile.php'
				&& !page.url().startsWith(baseUrl + '/wp-admin/profile.php#')
			)
		) {
			throw new Error(
				'READ_ONLY_EDITOR_STATE_NOT_DISCARDED:'
				+ (unexpectedDialog || page.url())
			);
		}
		return captured;
	}`;
}

export function captureMediaBrowserFunction({ baseUrl, post }) {
	return `async page => {
		/* AKB_BLOG_SEO_CAPTURE_MEDIA_UI_READ_ONLY */
		const baseUrl = ${JSON.stringify(baseUrl)};
		const post = ${JSON.stringify(post)};
		const media = post.featured_image;
		const target = baseUrl + '/wp-admin/post.php?post='
			+ media.attachment_id + '&action=edit';
		page.setDefaultNavigationTimeout(75000);
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
		const alt = page.locator('#attachment_alt:visible');
		const source = page.locator(
			'#attachment_url, input[name="attachment_url"]'
		);
		await alt.waitFor({ state: 'visible', timeout: 15000 });
		await source.waitFor({ state: 'visible', timeout: 15000 });
		if (await alt.count() !== 1 || await source.count() !== 1) {
			throw new Error('MEDIA_READ_FIELDS_MISSING_OR_AMBIGUOUS');
		}
		const sourceUrl = await source.inputValue();
		const filename = decodeURIComponent(
			sourceUrl.split(/[?#]/u)[0].split('/').at(-1)
		);
		if (filename !== media.filename) {
			throw new Error(
				'FEATURED_MEDIA_FILENAME_MISMATCH:'
					+ filename + ':' + media.filename
			);
		}
		const captured = {
			attachment_id: media.attachment_id,
			filename,
			alt: (await alt.inputValue()).replace(/\\r\\n?/gu, '\\n'),
		};
		let unexpectedDialog = '';
		const discardReadOnlyEditorState = async dialog => {
			if (dialog.type() !== 'beforeunload') {
				unexpectedDialog = dialog.type();
				await dialog.dismiss();
				return;
			}
			await dialog.accept();
		};
		page.on('dialog', discardReadOnlyEditorState);
		try {
			await page.goto(baseUrl + '/wp-admin/profile.php', {
				waitUntil: 'domcontentloaded',
				timeout: 75000,
			});
		} finally {
			page.off('dialog', discardReadOnlyEditorState);
		}
		if (
			unexpectedDialog
			|| (
				page.url() !== baseUrl + '/wp-admin/profile.php'
				&& !page.url().startsWith(baseUrl + '/wp-admin/profile.php#')
			)
		) {
			throw new Error(
				'READ_ONLY_MEDIA_STATE_NOT_DISCARDED:'
				+ (unexpectedDialog || page.url())
			);
		}
		return captured;
	}`;
}

function writeAtomic(file, value) {
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
	const environment = option('--environment');
	if (!['docker', 'production'].includes(environment)) {
		fail('--environment must be docker or production');
	}
	const baseUrl = validatedOrigin(option('--base-url'), environment);
	const session = validatedSession(option('--session'));
	const reviewPathValue = option('--review');
	if (!reviewPathValue) fail('missing --review');
	const reviewPath = path.resolve(reviewPathValue);
	if (
		!fs.existsSync(reviewPath)
		|| !fs.lstatSync(reviewPath).isFile()
		|| fs.lstatSync(reviewPath).isSymbolicLink()
	) {
		fail('review must be a regular local file');
	}
	const reviewRaw = fs.readFileSync(reviewPath, 'utf8');
	const reviewJson = JSON.parse(reviewRaw);
	const repository = path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
	const review = validateReview(reviewJson, environment, baseUrl);
	const deferredValidation = validateDeferredArtifact({
		review,
		artifactValue: option('--validation-artifact'),
		environment,
		baseUrl,
		repository,
	});
	const output = localOutput(option('--output'));
	const runtimeDirectory = fs.mkdtempSync(
		path.join(os.tmpdir(), 'akb-blog-seo-ui-capture-')
	);
	fs.chmodSync(runtimeDirectory, 0o700);
	const posts = [];
	const captureScope = review.capture_scope
		?? 'content_seo_and_featured_image_alt';
	try {
		for (const post of review.posts) {
			let capturedPost;
			let capturedMedia;
			try {
				acceptPendingBeforeUnload(session, runtimeDirectory);
				capturedPost = runPlaywright(
					session,
					capturePostBrowserFunction({ baseUrl, post }),
					runtimeDirectory
				);
			} catch (error) {
				fail(`${post.content_key} post capture: ${error.message}`);
			}
			if (captureScope === 'content_seo_and_featured_image_alt') {
				try {
					acceptPendingBeforeUnload(session, runtimeDirectory);
					capturedMedia = runPlaywright(
						session,
						captureMediaBrowserFunction({ baseUrl, post }),
						runtimeDirectory
					);
				} catch (error) {
					fail(`${post.content_key} media capture: ${error.message}`);
				}
			}
			if (
				!capturedPost?.fields
				|| REQUIRED_FIELDS.some(
					(field) => typeof capturedPost.fields[field] !== 'string'
				)
				|| (
					captureScope === 'content_seo_and_featured_image_alt'
					&& (
						capturedMedia?.attachment_id
							!== post.featured_image.attachment_id
						|| capturedMedia?.filename !== post.featured_image.filename
						|| typeof capturedMedia?.alt !== 'string'
					)
				)
			) {
				fail(`incomplete UI capture for ${post.content_key}`);
			}
			posts.push({
				content_key: post.content_key,
				post_id: post.post_id,
				post_status: post.post_status ?? 'publish',
				public_url: post.public_url,
				fields: Object.fromEntries(REQUIRED_FIELDS.map((field) => [
					field,
					normalizeEditorialValue(capturedPost.fields[field]),
				])),
				...(captureScope === 'content_seo_and_featured_image_alt' ? {
					featured_image: {
					attachment_id: capturedMedia.attachment_id,
					filename: capturedMedia.filename,
					alt: normalizeEditorialValue(capturedMedia.alt),
				},
				} : {}),
			});
		}
	} finally {
		fs.rmSync(runtimeDirectory, { recursive: true, force: true });
	}
	const capture = {
		schema_version: 1,
		capture_type: 'akibara_blog_seo_official_ui_read_only',
		capture_tool: 'akibara-playwright-official-ui-v1',
		environment,
		target_origin: baseUrl,
		capture_policy: 'official_wordpress_rankmath_ui_read_only',
		capture_scope: captureScope,
		direct_writes: 0,
		inventory_post_count: review.inventory_post_count ?? 25,
		review_sha256: sha256(reviewRaw),
		captured_at: new Date().toISOString(),
		posts,
		...(deferredValidation ? {
			deferred_content_keys: [DEFERRED_DOCKER_CONTENT_KEY],
			validation_artifact: deferredValidation,
		} : {}),
	};
	writeAtomic(output, capture);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		environment,
		posts: posts.length,
		items_read: posts.length * (
			captureScope === 'content_seo_and_featured_image_alt' ? 7 : 6
		),
		featured_image_alt_reads:
			captureScope === 'content_seo_and_featured_image_alt'
				? posts.length
				: 0,
		review_sha256: capture.review_sha256,
		output,
	}, null, 2)}\n`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	main().catch((error) => {
		process.stderr.write(`${error.message}\n`);
		process.exitCode = 1;
	});
}
