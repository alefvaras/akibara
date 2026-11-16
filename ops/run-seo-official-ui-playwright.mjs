#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import {
	ensureWordPressBlockEditorField,
} from './lib/wordpress-block-editor-official-ui.mjs';

function fail(message) {
	throw new Error(`SEO official UI runner failed: ${message}`);
}

function optionValue(name, fallback = undefined) {
	const index = process.argv.indexOf(name);
	return index === -1 ? fallback : process.argv[index + 1];
}

function hasOption(name) {
	return process.argv.includes(name);
}

function sha256(value) {
	return crypto.createHash('sha256').update(String(value), 'utf8').digest('hex');
}

function normalizeLineEndings(value) {
	return String(value).replace(/\r\n?/gu, '\n');
}

function writeAtomic(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		mode: 0o600,
	});
	fs.renameSync(temporary, file);
}

function validatePlan(plan) {
	if (
		plan?.schema_version !== 1
		|| plan?.plan_type !== 'akibara_seo_official_ui'
		|| !['docker', 'production'].includes(plan.environment)
		|| plan?.application_policy?.official_administration_ui_only !== true
		|| plan?.application_policy?.direct_api_writes !== 0
		|| plan?.application_policy?.direct_sql_writes !== 0
		|| plan?.application_policy?.direct_meta_writes !== 0
		|| plan?.application_policy?.remote_import_files !== 0
		|| plan?.counts?.total !== 963
		|| plan?.counts?.rankmath !== 871
		|| plan?.counts?.editorial !== 92
		|| !Array.isArray(plan.items)
		|| plan.items.length !== 963
		|| plan.items_sha256 !== sha256(JSON.stringify(plan.items))
	) {
		fail('plan integrity or official-UI policy assertions failed');
	}
	const calculatedPlanSha = sha256(JSON.stringify({
		schema_version: plan.schema_version,
		environment: plan.environment,
		application_policy: plan.application_policy,
		sources: plan.sources,
		counts: plan.counts,
		items_sha256: plan.items_sha256,
	}));
	if (plan.plan_sha256 !== calculatedPlanSha) {
		fail('plan_sha256 mismatch');
	}
	const keys = new Set();
	for (const item of plan.items) {
		if (
			keys.has(item.key)
			|| !/^[a-f0-9]{64}$/u.test(item.before_sha256)
			|| !/^[a-f0-9]{64}$/u.test(item.after_sha256)
			|| item.before_sha256 !== sha256(normalizeLineEndings(item.before))
			|| item.after_sha256 !== sha256(normalizeLineEndings(item.after))
			|| item.before === item.after
		) {
			fail(`invalid or duplicate item ${item.key}`);
		}
		keys.add(item.key);
	}
}

function validateBaseUrl(baseUrl, environment) {
	let parsed;
	try {
		parsed = new URL(baseUrl);
	} catch {
		fail(`invalid --base-url ${baseUrl}`);
	}
	if (!['http:', 'https:'].includes(parsed.protocol)) {
		fail('base URL must use http or https');
	}
	if (
		environment === 'docker'
		&& !['localhost', '127.0.0.1', '::1'].includes(parsed.hostname)
	) {
		fail('Docker plan may run only against localhost');
	}
	if (
		environment !== 'docker'
		&& ['localhost', '127.0.0.1', '::1'].includes(parsed.hostname)
	) {
		fail(`${environment} plan may not run against localhost`);
	}
	if (
		environment === 'production'
		&& !['akibara.cl', 'www.akibara.cl'].includes(parsed.hostname)
	) {
		fail('production plan requires the Akibara production hostname');
	}
	return parsed.origin;
}

function loadCheckpoint(checkpointPath, plan) {
	if (!fs.existsSync(checkpointPath)) {
		return {
			schema_version: 1,
			plan_sha256: plan.plan_sha256,
			environment: plan.environment,
			created_at: new Date().toISOString(),
			updated_at: new Date().toISOString(),
			events: {},
		};
	}
	const checkpoint = JSON.parse(fs.readFileSync(checkpointPath, 'utf8'));
	if (
		checkpoint?.schema_version !== 1
		|| checkpoint.plan_sha256 !== plan.plan_sha256
		|| checkpoint.environment !== plan.environment
		|| !checkpoint.events
		|| typeof checkpoint.events !== 'object'
	) {
		fail('checkpoint belongs to another plan or environment');
	}
	return checkpoint;
}

function recordObservations(checkpoint, itemsByKey, observations, phase) {
	for (const observation of observations) {
		const item = itemsByKey.get(observation.key);
		if (!item) {
			fail(`browser returned unknown key ${observation.key}`);
		}
		const current = normalizeLineEndings(observation.current);
		const currentSha256 = sha256(current);
		let status = 'drift';
		if (currentSha256 === item.after_sha256) {
			status = phase === 'apply' ? 'applied' : 'already_after';
		} else if (currentSha256 === item.before_sha256) {
			status = 'verified_before';
		}
		checkpoint.events[item.key] = {
			status,
			phase,
			lane: item.lane,
			observed_sha256: currentSha256,
			expected_before_sha256: item.before_sha256,
			expected_after_sha256: item.after_sha256,
			observed_at: new Date().toISOString(),
		};
	}
	checkpoint.updated_at = new Date().toISOString();
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
	fs.mkdirSync(runtimeDir, { recursive: true });
	const functionPath = path.join(
		runtimeDir,
		`browser-function-${process.pid}-${Date.now()}.js`
	);
	fs.writeFileSync(functionPath, `${functionSource}\n`, { mode: 0o600 });
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
			/*
			 * A listing function can retry initial navigation, restore the stable
			 * sort after Screen Options, and navigate again after a save. Keep the
			 * host timeout above that complete fail-closed window so the CLI is not
			 * killed after the UI persisted a change but before verification.
			 */
			timeout: 540_000,
		}
	);
	fs.unlinkSync(functionPath);
	if (command.error || command.status !== 0) {
		fail(
			`Playwright CLI failed (${command.status ?? 'spawn'}): `
			+ `${(command.stderr || command.stdout || command.error?.message).slice(0, 1000)}`
		);
	}
	return parseCliJson(command.stdout, command.stderr);
}

function acceptPendingWordPressBeforeUnload(session, runtimeDir) {
	/*
	 * WordPress' classic editor can leave its standard beforeunload dialog
	 * armed after switching a TinyMCE field to the official Code tab, even
	 * when the prior runner call already reopened and verified the persisted
	 * value. Clear only that browser-owned modal before navigating to the next
	 * editorial object. The CLI is intentionally a no-op when no modal exists.
	 */
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

function rankMathPageFunction({ baseUrl, listPath, pageNumber, items, phase, prepareUi }) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		const listPath = ${JSON.stringify(listPath)};
		const pageNumber = ${pageNumber};
		const phase = ${JSON.stringify(phase)};
		const prepareUi = ${JSON.stringify(prepareUi)};
		const items = ${JSON.stringify(items)};
		if (!listPath.startsWith('/wp-admin/')) {
			throw new Error('RANKMATH_ADMIN_PATH_INVALID');
		}
		await page.setViewportSize({ width: 1440, height: 1000 });
		page.setDefaultNavigationTimeout(75000);
		async function navigate(target) {
			for (let attempt = 1; attempt <= 2; attempt += 1) {
				try {
					await page.goto(target, {
						waitUntil: 'domcontentloaded',
						timeout: 75000,
					});
					return;
				} catch (error) {
					if (attempt === 2 || !/Timeout/iu.test(String(error))) {
						throw error;
					}
					await page.waitForTimeout(1500);
				}
			}
		}
			const separator = listPath.includes('?') ? '&' : '?';
			const target = baseUrl + listPath + separator
				+ 'paged=' + encodeURIComponent(String(pageNumber))
				+ '&orderby=ID&order=asc';
			await navigate(target);
			if (/wp-login\\.php/u.test(page.url())) {
				throw new Error('AUTH_REQUIRED');
			}
			const cardinality = page.locator('.tablenav.top .displaying-num');
			if (await cardinality.count() !== 1) {
				throw new Error('LIST_CARDINALITY_CONTROL_MISSING');
			}
			const cardinalityText = String(await cardinality.textContent())
				.trim()
				.replace(/\\s+/gu, ' ');
			const visibleIds = await page.locator('#the-list tr[id^="post-"]')
				.evaluateAll(rows => rows.map(row => {
					const match = /^post-(\\d+)$/u.exec(row.id);
					return match ? Number(match[1]) : null;
				}));
			if (
				visibleIds.length === 0
				|| visibleIds.some(id => !Number.isSafeInteger(id) || id < 1)
				|| visibleIds.some((id, index) => index > 0 && id <= visibleIds[index - 1])
			) {
				throw new Error('LIST_ID_ORDER_NOT_STABLE');
			}

			if (prepareUi) {
			const toggle = page.locator('#rank_math_description-hide');
			const perPage = page.locator('#screen-options-wrap input[type="number"]');
			if (await toggle.count() === 1 || await perPage.count() === 1) {
				const panel = page.locator('#screen-options-wrap');
				if (!await panel.isVisible()) {
					const wooScreenOptions = page.locator(
						'button[aria-label="Screen options"], '
						+ 'button[aria-label="Opciones de pantalla"]'
					).first();
					const showSettings = page.locator('#show-settings-link');
					if (await wooScreenOptions.count() === 1
						&& await wooScreenOptions.isVisible()) {
						await wooScreenOptions.click();
					} else if (await showSettings.count() === 1) {
						/*
						 * WordPress 7 may render this official control inside
						 * a clipped screen-meta wrapper. The WooCommerce shell
						 * above is preferred whenever it is available.
						 */
						await showSettings.click({ force: true });
					} else {
						throw new Error('SCREEN_OPTIONS_CONTROL_MISSING');
					}
					await panel.waitFor({ state: 'visible' });
				}
				if (await toggle.count() === 1 && !await toggle.isChecked()) {
					await toggle.check();
				}
				if (await perPage.count() === 1 && await perPage.inputValue() !== '100') {
					await perPage.fill('100');
					await Promise.all([
						page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
						page.locator('#screen-options-apply').click(),
					]);
					}
				}
			}
			if (
				!/[?&]orderby=ID(?:&|$)/u.test(page.url())
				|| !/[?&]order=asc(?:&|$)/u.test(page.url())
			) {
				await navigate(target);
			}

			const found = [];
		const actionable = [];
		for (const item of items) {
			const row = page.locator(item.row_selector);
			if (await row.count() !== 1) {
				continue;
			}
			const field = row.locator(item.field_selector);
			if (await field.count() !== 1) {
				throw new Error('RANKMATH_FIELD_MISSING:' + item.key);
			}
			const current = (await field.inputValue()).replace(/\\r\\n?/gu, '\\n');
			found.push({ key: item.key, current });
			if (phase === 'apply' && current === item.before) {
				actionable.push({ item, field });
			}
		}

			if (phase === 'apply' && actionable.length > 0) {
			if (found.some(record => {
				const item = items.find(candidate => candidate.key === record.key);
				return record.current !== item.before && record.current !== item.after;
			})) {
				return { found, next_href: null, blocked: 'DRIFT_ON_PAGE' };
			}
			const edit = page.locator('#rank_math_description .dashicons-edit');
			if (await edit.count() !== 1) {
				throw new Error('RANKMATH_BULK_EDIT_CONTROL_MISSING');
			}
			await edit.click();
			for (const { item, field } of actionable) {
				await field.fill(item.after);
			}
			const saveAll = page.locator(
				'#rank_math_description .rank-math-column-save-all'
			);
			if (await saveAll.count() !== 1) {
				throw new Error('RANKMATH_SAVE_ALL_MISSING');
			}
			await saveAll.click();
				await page.waitForFunction(
					() => document.querySelectorAll(
						'td.column-rank_math_description.dirty'
					).length === 0,
					null,
					{ timeout: 30000 }
				);
				/*
				 * The current textarea contains the filled value even if the
				 * asynchronous save failed. Re-open the official listing and read
				 * fresh controls before recording an item as applied.
				 */
				await navigate(target);
				if (/wp-login\\.php/u.test(page.url())) {
					throw new Error('AUTH_REQUIRED_AFTER_RANKMATH_SAVE');
				}
				for (const record of found) {
					const item = items.find(candidate => candidate.key === record.key);
					const persistedRow = page.locator(item.row_selector);
					if (await persistedRow.count() !== 1) {
						throw new Error('RANKMATH_ROW_MISSING_AFTER_SAVE:' + item.key);
					}
					const persistedField = persistedRow.locator(item.field_selector);
					if (await persistedField.count() !== 1) {
						throw new Error('RANKMATH_FIELD_MISSING_AFTER_SAVE:' + item.key);
					}
					record.current = (await persistedField.inputValue())
						.replace(/\\r\\n?/gu, '\\n');
				}
			}

		const next = page.locator('.tablenav.top a.next-page');
		const nextHref = await next.count() === 1 ? await next.getAttribute('href') : null;
			return {
				found,
				next_href: nextHref,
				blocked: null,
				cardinality_text: cardinalityText,
				first_visible_id: visibleIds[0],
				last_visible_id: visibleIds[visibleIds.length - 1],
			};
	}`;
}

function editorialObjectFunction({ baseUrl, items, phase }) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		const phase = ${JSON.stringify(phase)};
		const items = ${JSON.stringify(items)};
		const ensureWordPressBlockEditorField = ${
			ensureWordPressBlockEditorField.toString()
		};
			const uiPath = items[0].ui_path;
		if (!uiPath.startsWith('/wp-admin/')) {
			throw new Error('EDITORIAL_ADMIN_PATH_INVALID');
		}
			await page.setViewportSize({ width: 1440, height: 1000 });
			page.setDefaultNavigationTimeout(75000);
			async function navigate(target) {
				for (let attempt = 1; attempt <= 2; attempt += 1) {
					try {
						await page.goto(target, {
							waitUntil: 'domcontentloaded',
							timeout: 75000,
						});
						return;
					} catch (error) {
						if (attempt === 2 || !/Timeout/iu.test(String(error))) {
							throw error;
						}
						await page.waitForTimeout(1500);
					}
				}
			}
			const target = baseUrl + uiPath;
			await navigate(target);
			if (/wp-login\\.php/u.test(page.url())) {
				throw new Error('AUTH_REQUIRED');
			}

		async function fieldFor(item) {
			if (item.lane === 'wordpress_block_editor') {
				return ensureWordPressBlockEditorField(page, item);
			}
			const field = page.locator(item.field_selector);
			if (await field.count() !== 1) {
				throw new Error('EDITORIAL_FIELD_MISSING:' + item.key);
			}
			if (
				item.lane === 'product_classic_editor'
				&& !await field.isVisible()
			) {
				if (!/^#[A-Za-z][A-Za-z0-9_-]*$/u.test(item.field_selector)) {
					throw new Error('CLASSIC_EDITOR_FIELD_SELECTOR_INVALID:' + item.key);
				}
				const codeTab = page.locator(item.field_selector + '-html');
				if (await codeTab.count() !== 1 || !await codeTab.isVisible()) {
					throw new Error('CLASSIC_EDITOR_CODE_TAB_MISSING:' + item.key);
				}
				await codeTab.click();
				await field.waitFor({ state: 'visible' });
			}
			return field;
		}

		const records = [];
		const fields = new Map();
		for (const item of items) {
			const field = await fieldFor(item);
			fields.set(item.key, field);
			records.push({
				key: item.key,
				current: (await field.inputValue()).replace(/\\r\\n?/gu, '\\n'),
			});
		}
		const drift = records.some(record => {
			const item = items.find(candidate => candidate.key === record.key);
			return record.current !== item.before && record.current !== item.after;
		});
		if (phase === 'apply' && drift) {
			return { records, blocked: 'DRIFT_ON_OBJECT' };
		}

		const actionable = records.filter(record => {
			const item = items.find(candidate => candidate.key === record.key);
			return phase === 'apply' && record.current === item.before;
		});
		if (actionable.length > 0) {
			for (const record of actionable) {
				const item = items.find(candidate => candidate.key === record.key);
				await fields.get(record.key).fill(item.after);
			}
			if (items[0].lane === 'wordpress_block_editor') {
				const save = page.locator('.editor-post-publish-button');
				if (await save.count() !== 1) {
					throw new Error('BLOCK_EDITOR_SAVE_MISSING');
				}
				await save.click();
				await page.waitForFunction(
					() => {
						const button = document.querySelector('.editor-post-publish-button');
						return button && !button.classList.contains('is-busy');
					},
					null,
					{ timeout: 30000 }
				);
			} else {
				const saveSelector = items[0].lane === 'term_editor'
					? 'input[type="submit"][value="Actualizar"], input[type="submit"][value="Update"]'
					: '#publish';
				const save = page.locator(saveSelector);
				if (await save.count() !== 1) {
					throw new Error('CLASSIC_EDITOR_SAVE_MISSING');
				}
				const [saveResponse] = await Promise.all([
					page.waitForResponse(
						response =>
							response.request().method() === 'POST'
							&& response.request().isNavigationRequest()
							&& (
								response.url().startsWith(baseUrl + '/wp-admin/post.php')
								|| response.url().startsWith(baseUrl + '/wp-admin/term.php')
								|| response.url().startsWith(baseUrl + '/wp-admin/edit-tags.php')
							),
						{ timeout: 75000 },
					),
					save.click(),
				]);
				const saveStatus = saveResponse.status();
				const redirectLocation = saveResponse.headers()['location'] ?? '';
				const acceptedRedirect = [302, 303].includes(saveStatus)
					&& (
						redirectLocation.startsWith('/wp-admin/')
						|| redirectLocation.startsWith(baseUrl + '/wp-admin/')
					);
				if (!saveResponse.ok() && !acceptedRedirect) {
					throw new Error(
						'CLASSIC_EDITOR_SAVE_HTTP_' + saveStatus,
					);
				}
				await page.waitForLoadState('domcontentloaded', { timeout: 75000 });
			}
				await navigate(target);
				if (/wp-login\\.php/u.test(page.url())) {
					throw new Error('AUTH_REQUIRED_AFTER_EDITORIAL_SAVE');
				}
				for (const record of records) {
				const item = items.find(candidate => candidate.key === record.key);
				const field = await fieldFor(item);
				record.current = (await field.inputValue()).replace(/\\r\\n?/gu, '\\n');
			}
		}
		return { records, blocked: null };
	}`;
}

function rankMathTermFunction({ baseUrl, items, phase }) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		const phase = ${JSON.stringify(phase)};
		const items = ${JSON.stringify(items)};
			const uiPath = items[0].ui_path;
		if (!uiPath.startsWith('/wp-admin/term.php?')) {
			throw new Error('RANKMATH_TERM_ADMIN_PATH_INVALID');
		}
			await page.setViewportSize({ width: 1440, height: 1000 });
			page.setDefaultNavigationTimeout(75000);
			async function navigate(target) {
				for (let attempt = 1; attempt <= 2; attempt += 1) {
					try {
						await page.goto(target, {
							waitUntil: 'domcontentloaded',
							timeout: 75000,
						});
						return;
					} catch (error) {
						if (attempt === 2 || !/Timeout/iu.test(String(error))) {
							throw error;
						}
						await page.waitForTimeout(1500);
					}
				}
			}
			const target = baseUrl + uiPath;
			await navigate(target);
			if (/wp-login\\.php/u.test(page.url())) {
				throw new Error('AUTH_REQUIRED');
			}

		async function descriptionField() {
			const editSnippet = page.locator('.rank-math-edit-snippet');
			await editSnippet.waitFor({ state: 'visible', timeout: 30000 });
			if (await editSnippet.count() !== 1) {
				throw new Error('RANKMATH_TERM_SNIPPET_CONTROL_MISSING');
			}
			const field = page.locator('#rank-math-editor-description');
			if (await field.count() !== 1 || !await field.isVisible()) {
				await editSnippet.click();
				await field.waitFor({ state: 'visible' });
			}
			return field;
		}

		const records = [];
		const field = await descriptionField();
		for (const item of items) {
			records.push({
				key: item.key,
				current: (await field.inputValue()).replace(/\\r\\n?/gu, '\\n'),
			});
		}
		const drift = records.some(record => {
			const item = items.find(candidate => candidate.key === record.key);
			return record.current !== item.before && record.current !== item.after;
		});
		if (phase === 'apply' && drift) {
			return { records, blocked: 'DRIFT_ON_RANKMATH_TERM' };
		}

		const actionable = records.filter(record => {
			const item = items.find(candidate => candidate.key === record.key);
			return phase === 'apply' && record.current === item.before;
		});
		if (actionable.length > 0) {
			if (items.length !== 1 || actionable.length !== 1) {
				throw new Error('RANKMATH_TERM_GROUP_INVALID');
			}
			await field.fill(items[0].after);
			const snippetDialog = page.getByRole('dialog');
			const closeSnippet = snippetDialog.getByRole('button', {
				name: 'Cerrar',
				exact: true,
			});
			if (
				await snippetDialog.count() !== 1
				|| await closeSnippet.count() !== 1
			) {
				throw new Error('RANKMATH_TERM_SNIPPET_CLOSE_MISSING');
			}
			await closeSnippet.click();
			await snippetDialog.waitFor({ state: 'hidden' });
			const save = page.locator(
				'.edit-tag-actions input[type="submit"].button-primary',
			);
			if (await save.count() !== 1) {
				throw new Error('RANKMATH_TERM_SAVE_MISSING');
			}
				await Promise.all([
					page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
					save.click(),
				]);
				await navigate(target);
				if (/wp-login\\.php/u.test(page.url())) {
					throw new Error('AUTH_REQUIRED_AFTER_RANKMATH_TERM_SAVE');
				}
				const reloadedField = await descriptionField();
			records[0].current = (await reloadedField.inputValue())
				.replace(/\\r\\n?/gu, '\\n');
		}
		return { records, blocked: null };
	}`;
}

function assertBrowserFunctionSyntax(source, label) {
	try {
		// Parse only. The generated function is not invoked during local validation.
		Function(`"use strict"; return (${source});`);
	} catch (error) {
		fail(`${label} browser function is invalid: ${error.message}`);
	}
	if (
		/fetch\(|wp-json|admin-ajax\.php|update_post_meta|update_term_meta/iu
			.test(source)
	) {
		fail(`${label} browser function contains a prohibited direct channel`);
	}
	if (/\bnew\s+URL\s*\(/u.test(source)) {
		fail(`${label} browser function depends on unavailable Playwright CLI globals`);
	}
}

function filterItems(plan, lane, limit) {
	let items = plan.items;
	if (lane === 'rankmath') {
		items = items.filter((item) => item.lane.startsWith('rankmath_'));
	} else if (lane === 'rankmath-inline') {
		items = items.filter(
			(item) => item.lane === 'rankmath_inline_description'
		);
	} else if (lane === 'rankmath-term') {
		items = items.filter(
			(item) => item.lane === 'rankmath_term_description'
		);
	} else if (lane === 'editorial') {
		items = items.filter((item) => !item.lane.startsWith('rankmath_'));
	} else if (lane !== 'all') {
		fail(
			'--lane must be all, rankmath, rankmath-inline, '
			+ 'rankmath-term or editorial'
		);
	}
	if (limit !== null) {
		items = items.slice(0, limit);
	}
	return items;
}

function groupBy(items, keyForItem) {
	const groups = new Map();
	for (const item of items) {
		const key = keyForItem(item);
		if (!groups.has(key)) {
			groups.set(key, []);
		}
		groups.get(key).push(item);
	}
	return groups;
}

function runPhase({
	phase,
	items,
	baseUrl,
	session,
	runtimeDir,
	checkpoint,
	checkpointPath,
}) {
	const itemsByKey = new Map(items.map((item) => [item.key, item]));
	const seen = new Set();
	const rankMathGroups = groupBy(
		items.filter((item) => item.lane === 'rankmath_inline_description'),
		(item) => item.list_path
	);
	for (const [listPath, routeItems] of rankMathGroups) {
		let pageNumber = 1;
		let hasNext = true;
		let cardinalityText = null;
		let lastVisibleId = null;
		while (hasNext) {
			if (pageNumber > 250) {
				fail(`pagination guard exceeded for ${listPath}`);
			}
			const result = runPlaywrightFunction(
				session,
				rankMathPageFunction({
					baseUrl,
					listPath,
					pageNumber,
					items: routeItems,
					phase,
					prepareUi: pageNumber === 1,
				}),
				runtimeDir
			);
			if (result.blocked) {
				fail(`${result.blocked} at ${listPath} page ${pageNumber}`);
			}
			if (
				typeof result.cardinality_text !== 'string'
				|| result.cardinality_text.length === 0
			) {
				fail(`listing cardinality unavailable for ${listPath} page ${pageNumber}`);
			}
			if (
				!Number.isSafeInteger(result.first_visible_id)
				|| !Number.isSafeInteger(result.last_visible_id)
				|| result.first_visible_id > result.last_visible_id
				|| (lastVisibleId !== null && result.first_visible_id <= lastVisibleId)
			) {
				fail(`listing ID order changed for ${listPath} page ${pageNumber}`);
			}
			lastVisibleId = result.last_visible_id;
			if (cardinalityText === null) {
				cardinalityText = result.cardinality_text;
				const preflightCardinality = checkpoint.collections
					?.preflight?.[listPath]?.cardinality_text;
				if (
					phase === 'apply'
					&& typeof preflightCardinality === 'string'
					&& preflightCardinality !== cardinalityText
				) {
					fail(
						`listing cardinality changed after preflight for ${listPath}: `
						+ `${preflightCardinality} -> ${cardinalityText}`
					);
				}
			} else if (result.cardinality_text !== cardinalityText) {
				fail(
					`listing cardinality changed during ${phase} for ${listPath}: `
					+ `${cardinalityText} -> ${result.cardinality_text}`
				);
			}
			recordObservations(
				checkpoint,
				itemsByKey,
				result.found,
				phase
			);
			for (const record of result.found) {
				seen.add(record.key);
			}
			writeAtomic(checkpointPath, checkpoint);
			hasNext = Boolean(result.next_href);
			pageNumber += 1;
		}
		checkpoint.collections ??= {};
		checkpoint.collections[phase] ??= {};
		checkpoint.collections[phase][listPath] = {
			cardinality_text: cardinalityText,
			pages_scanned: pageNumber - 1,
			observed_items: routeItems.filter((item) => seen.has(item.key)).length,
			observed_at: new Date().toISOString(),
		};
		writeAtomic(checkpointPath, checkpoint);
	}

	const rankMathTermGroups = groupBy(
		items.filter((item) => item.lane === 'rankmath_term_description'),
		(item) => item.ui_path
	);
	for (const objectItems of rankMathTermGroups.values()) {
		const result = runPlaywrightFunction(
			session,
			rankMathTermFunction({
				baseUrl,
				items: objectItems,
				phase,
			}),
			runtimeDir
		);
		if (result.blocked) {
			fail(`${result.blocked} at ${objectItems[0].ui_path}`);
		}
		recordObservations(
			checkpoint,
			itemsByKey,
			result.records,
			phase
		);
		for (const record of result.records) {
			seen.add(record.key);
		}
		writeAtomic(checkpointPath, checkpoint);
	}

	const editorialOnlyGroups = groupBy(
		items.filter((item) => !item.lane.startsWith('rankmath_')),
		(item) => item.ui_path
	);
	for (const objectItems of editorialOnlyGroups.values()) {
		acceptPendingWordPressBeforeUnload(session, runtimeDir);
		const result = runPlaywrightFunction(
			session,
			editorialObjectFunction({
				baseUrl,
				items: objectItems,
				phase,
			}),
			runtimeDir
		);
		if (result.blocked) {
			fail(`${result.blocked} at ${objectItems[0].ui_path}`);
		}
		recordObservations(
			checkpoint,
			itemsByKey,
			result.records,
			phase
		);
		for (const record of result.records) {
			seen.add(record.key);
		}
		writeAtomic(checkpointPath, checkpoint);
	}

	const missing = items.filter((item) => !seen.has(item.key));
	if (missing.length > 0) {
		for (const item of missing) {
			checkpoint.events[item.key] = {
				status: 'missing',
				phase,
				lane: item.lane,
				observed_at: new Date().toISOString(),
			};
		}
		writeAtomic(checkpointPath, checkpoint);
			fail(
				`${missing.length} planned UI fields were not found; `
				+ 'freeze catalog mutations and repeat the complete preflight'
			);
	}
	const drift = items.filter(
		(item) => checkpoint.events[item.key]?.status === 'drift'
	);
	if (drift.length > 0) {
		fail(`${drift.length} fields drifted from both reviewed hashes`);
	}
}

const planOption = optionValue('--plan');
if (!planOption) {
	fail('--plan is required');
}
const planPath = path.resolve(planOption);
const plan = JSON.parse(fs.readFileSync(planPath, 'utf8'));
validatePlan(plan);

const validateOnly = hasOption('--validate-only');
const apply = hasOption('--apply');
const lane = optionValue('--lane', 'all');
const limitOption = optionValue('--limit');
const limit = limitOption === undefined ? null : Number(limitOption);
if (
	limit !== null
	&& (!Number.isSafeInteger(limit) || limit <= 0)
) {
	fail('--limit must be a positive integer');
}
if (validateOnly && apply) {
	fail('--validate-only cannot be combined with --apply');
}
if (apply && limit !== null) {
	fail('--apply cannot be combined with --limit; partial writes must use an explicit lane');
}
if (apply && plan.environment !== 'production') {
	fail('--apply is available only for production plans');
}
const selectedItems = filterItems(plan, lane, limit);
if (validateOnly) {
	const rankMathSample = selectedItems.find(
		(item) => item.lane === 'rankmath_inline_description'
	) ?? plan.items.find((item) => item.lane === 'rankmath_inline_description');
	assertBrowserFunctionSyntax(
		rankMathPageFunction({
			baseUrl: 'http://localhost:8080',
			listPath: rankMathSample.list_path,
			pageNumber: 1,
			items: [rankMathSample],
			phase: 'preflight',
			prepareUi: true,
		}),
		'Rank Math'
	);
	const rankMathTermSample = plan.items.find(
		(item) => item.lane === 'rankmath_term_description'
	);
	assertBrowserFunctionSyntax(
		rankMathTermFunction({
			baseUrl: 'http://localhost:8080',
			items: [rankMathTermSample],
			phase: 'preflight',
		}),
		'Rank Math term'
	);
	for (const editorialLane of [
		'product_classic_editor',
		'wordpress_block_editor',
		'term_editor',
	]) {
		const sample = plan.items.find((item) => item.lane === editorialLane);
		assertBrowserFunctionSyntax(
			editorialObjectFunction({
				baseUrl: 'http://localhost:8080',
				items: [sample],
				phase: 'preflight',
			}),
			editorialLane
		);
	}
	console.log(JSON.stringify({
		result: 'PASS',
		mode: 'validate-only',
		environment: plan.environment,
		plan_sha256: plan.plan_sha256,
		selected_items: selectedItems.length,
		counts: plan.counts,
	}, null, 2));
	process.exit(0);
}

const baseUrlOption = optionValue('--base-url');
const session = optionValue('--session');
const checkpointOption = optionValue('--checkpoint');
if (!baseUrlOption || !session || !checkpointOption) {
	fail('--base-url, --session and --checkpoint are required for browser work');
}
const baseUrl = validateBaseUrl(baseUrlOption, plan.environment);
if (
	apply
	&& optionValue('--confirm-plan-sha256') !== plan.plan_sha256
) {
	fail(
		'--apply requires --confirm-plan-sha256 equal to the reviewed plan SHA-256'
	);
}

const checkpointPath = path.resolve(checkpointOption);
const checkpoint = loadCheckpoint(checkpointPath, plan);
const runtimeDir = fs.mkdtempSync(
	path.join(os.tmpdir(), 'akb-seo-official-ui-')
);
try {
	runPhase({
		phase: 'preflight',
		items: selectedItems,
		baseUrl,
		session,
		runtimeDir,
		checkpoint,
		checkpointPath,
	});
	if (apply) {
		runPhase({
			phase: 'apply',
			items: selectedItems,
			baseUrl,
			session,
			runtimeDir,
			checkpoint,
			checkpointPath,
		});
	}
} finally {
	for (const entry of fs.readdirSync(runtimeDir)) {
		fs.unlinkSync(path.join(runtimeDir, entry));
	}
	fs.rmdirSync(runtimeDir);
}

const statusCounts = Object.values(checkpoint.events).reduce((counts, event) => {
	counts[event.status] = (counts[event.status] ?? 0) + 1;
	return counts;
}, {});
console.log(JSON.stringify({
	result: 'PASS',
	mode: apply ? 'apply' : 'preflight',
	environment: plan.environment,
	plan_sha256: plan.plan_sha256,
	selected_items: selectedItems.length,
	checkpoint: checkpointPath,
	status_counts: statusCounts,
}, null, 2));
