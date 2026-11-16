#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { EXPECTED_TARGET } from './compare-rankmath-redirections-snapshot.mjs';
import {
	reviewedMalformedReason,
	rowPayloadSha256,
	visibleRowPayload,
} from './rankmath-redirections-row-fingerprint.mjs';

function fail(message) {
	throw new Error(`Rank Math official UI runner failed: ${message}`);
}

function option(name, fallback = undefined) {
	const index = process.argv.indexOf(name);
	return index === -1 ? fallback : process.argv[index + 1];
}

function has(name) {
	return process.argv.includes(name);
}

function sha256(value) {
	return crypto.createHash('sha256').update(String(value), 'utf8').digest('hex');
}

function writeAtomic(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, { mode: 0o600 });
	fs.renameSync(temporary, file);
}

function validatePlan(plan) {
	const policy = plan?.application_policy;
	if (
			plan?.schema_version !== 3
		|| plan?.plan_type !== 'akibara_rankmath_redirections_official_ui'
		|| !['docker', 'production'].includes(plan.environment)
		|| policy?.official_administration_ui_only !== true
		|| policy?.global_inventory_before_write !== true
		|| policy?.global_inventory_before_each_phase !== true
		|| policy?.row_payload_revalidation_immediately_before_click !== true
		|| policy?.reviewed_malformed_actions !== 'trash_only'
		|| policy?.reviewed_malformed_identity !== 'visible_payload_sha256'
		|| policy?.direct_rest_writes !== 0
		|| policy?.direct_ajax_writes !== 0
		|| policy?.direct_sql_writes !== 0
		|| policy?.direct_wp_cli_writes !== 0
		|| policy?.logical_identity_uses_ids !== false
		|| policy?.source_ignore_contract !== 'literal_case_only'
		|| policy?.legacy_ignore_values_normalized_via_official_checkbox !== true
		|| policy?.commercial_history_mutations !== 0
		|| plan?.counts?.total !== EXPECTED_TARGET.total
		|| plan?.counts?.status?.active !== EXPECTED_TARGET.status.active
		|| plan?.counts?.matching?.exact !== EXPECTED_TARGET.matching.exact
		|| plan?.counts?.matching?.regex !== EXPECTED_TARGET.matching.regex
		|| plan?.counts?.ignore?.case !== EXPECTED_TARGET.ignore.case
		|| Object.keys(plan?.counts?.ignore ?? {}).length !== Object.keys(EXPECTED_TARGET.ignore).length
		|| plan?.counts?.type?.['301'] !== EXPECTED_TARGET.type['301']
		|| plan?.counts?.type?.['410'] !== EXPECTED_TARGET.type['410']
		|| !Array.isArray(plan.rules)
		|| plan.rules.length !== EXPECTED_TARGET.total
		|| plan.rules_sha256 !== sha256(JSON.stringify(plan.rules))
		|| !Array.isArray(plan.reviewed_malformed)
		|| plan.reviewed_malformed_sha256 !== sha256(JSON.stringify(plan.reviewed_malformed))
	) {
		fail('plan integrity or UI-only policy assertions failed');
	}
	for (const row of plan.reviewed_malformed) {
		if (
			row?.kind !== 'reviewed_malformed'
			|| row?.action !== 'trash'
			|| row?.reason !== 'reviewed_missing_sources_mi_cuenta'
			|| ![1, 2].includes(row?.expected_count)
			|| row.payload_sha256 !== rowPayloadSha256(row.payload)
			|| reviewedMalformedReason(row.payload) !== row.reason
		) {
			fail('reviewed malformed allowlist contains an unsafe row');
		}
	}
	const keys = new Set();
	for (const rule of plan.rules) {
		const semantic = {
			source: rule.source,
			matching: rule.matching,
			ignore: rule.ignore,
			destination: rule.destination,
			type: rule.type,
			status: rule.status,
		};
		if (
			keys.has(rule.logical_key)
			|| rule.logical_key !== logicalKey(rule.source, rule.matching)
			|| rule.semantic_sha256 !== sha256(JSON.stringify(semantic))
			|| !Object.prototype.hasOwnProperty.call(EXPECTED_TARGET.ignore, rule.ignore)
			|| rule.status !== 'active'
		) {
			fail(`invalid or duplicate target ${rule.logical_key}`);
		}
		keys.add(rule.logical_key);
	}
	const calculated = sha256(JSON.stringify({
		schema_version: plan.schema_version,
		plan_type: plan.plan_type,
		environment: plan.environment,
		application_policy: plan.application_policy,
		allowed_admin_surfaces: plan.allowed_admin_surfaces,
		sources: plan.sources,
		expected: plan.expected,
		counts: plan.counts,
		rules_sha256: plan.rules_sha256,
		reviewed_malformed_sha256: plan.reviewed_malformed_sha256,
	}));
	if (calculated !== plan.plan_sha256) {
		fail('plan_sha256 mismatch');
	}
}

function canonicalExactSource(value) {
	return String(value ?? '').trim().replace(/^\/+|\/+$/gu, '').replace(/\/+/gu, '/');
}

function canonicalSource(value, matching) {
	const source = matching === 'exact'
		? canonicalExactSource(value)
		: String(value ?? '').trim();
	if (!source) {
		fail('empty source');
	}
	return source;
}

function logicalKey(source, matching) {
	return `${matching}|${canonicalSource(source, matching).toLowerCase()}`;
}

function normalizeObservedIgnore(value) {
	const ignore = String(value ?? '');
	if (!['', 'case', 'exact'].includes(ignore)) {
		fail(`unsupported observed ignore mode ${JSON.stringify(value)}`);
	}
	return ignore;
}

function canonicalDestination(value) {
	const destination = String(value ?? '').trim();
	if (!/^https?:\/\//iu.test(destination)) {
		return destination;
	}
	const url = new URL(destination);
	if (!['akibara.cl', 'www.akibara.cl', 'localhost', '127.0.0.1', 'wordpress']
		.includes(url.hostname.toLowerCase())) {
		return destination;
	}
	return `${url.pathname || '/'}${url.search}${url.hash}`;
}

function validateBaseUrl(baseUrl, environment) {
	const parsed = new URL(baseUrl);
	if (!['http:', 'https:'].includes(parsed.protocol)) {
		fail('base URL must use http or https');
	}
	const local = ['localhost', '127.0.0.1', '::1'].includes(parsed.hostname);
	if (environment === 'docker' && !local) {
		fail('Docker plan may run only against localhost');
	}
	if (environment !== 'docker' && local) {
		fail(`${environment} plan may not run against localhost`);
	}
	if (environment === 'production' && !['akibara.cl', 'www.akibara.cl'].includes(parsed.hostname)) {
		fail('production plan requires the Akibara production hostname');
	}
	return parsed.origin;
}

function loadCheckpoint(file, plan) {
	if (!fs.existsSync(file)) {
		return {
			schema_version: 1,
			plan_sha256: plan.plan_sha256,
			environment: plan.environment,
			created_at: new Date().toISOString(),
			updated_at: new Date().toISOString(),
			preflight: null,
			events: {},
			commercial_before: null,
			commercial_after: null,
			monitor_404: null,
		};
	}
	const checkpoint = JSON.parse(fs.readFileSync(file, 'utf8'));
	if (
		checkpoint?.schema_version !== 1
		|| checkpoint.plan_sha256 !== plan.plan_sha256
		|| checkpoint.environment !== plan.environment
		|| typeof checkpoint.events !== 'object'
	) {
		fail('checkpoint belongs to another plan or environment');
	}
	return checkpoint;
}

function parseCliJson(stdout, stderr) {
	let outer;
	try {
		outer = JSON.parse(stdout);
	} catch {
		fail(`Playwright CLI did not return JSON: ${(stderr || stdout).slice(0, 600)}`);
	}
	if (outer?.isError === true || typeof outer?.error === 'string') {
		fail(`Playwright browser function failed: ${String(outer.error ?? 'unknown error').slice(0, 1400)}`);
	}
	if (typeof outer?.result !== 'string') {
		fail(`Playwright CLI returned no serialized result: ${stdout.slice(0, 600)}`);
	}
	try {
		return JSON.parse(outer.result);
	} catch {
		fail(`Playwright result was not JSON: ${String(outer.result).slice(0, 600)}`);
	}
}

function runBrowserFunction(session, source, runtimeDir) {
	const functionPath = path.join(runtimeDir, `browser-function-${process.pid}-${Date.now()}.js`);
	fs.writeFileSync(functionPath, `${source}\n`, { mode: 0o600 });
	const command = spawnSync(
		'npx',
		[
			'--yes', '--package', '@playwright/cli', 'playwright-cli',
			`--session=${session}`, '--json', 'run-code', `--filename=${functionPath}`,
		],
		{ cwd: runtimeDir, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, timeout: 180_000 }
	);
	fs.unlinkSync(functionPath);
	if (command.error || command.status !== 0) {
		fail(`Playwright CLI failed: ${(command.stderr || command.stdout || command.error?.message).slice(0, 1200)}`);
	}
	return parseCliJson(command.stdout, command.stderr);
}

function inventoryPageFunction({ baseUrl, status, pageNumber }) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		const status = ${JSON.stringify(status)};
		const pageNumber = ${pageNumber};
		await page.setViewportSize({ width: 1440, height: 1000 });
		await page.goto(baseUrl + '/wp-admin/admin.php?page=rank-math-redirections&status='
			+ encodeURIComponent(status) + '&paged=' + pageNumber, { waitUntil: 'domcontentloaded' });
		if (/wp-login\\.php/u.test(page.url())) throw new Error('AUTH_REQUIRED');
		if (!/page=rank-math-redirections/u.test(page.url())) throw new Error('SURFACE_ESCAPE');
		const rows = await page.locator('tbody tr').evaluateAll((elements, currentStatus) =>
			elements.filter(row => row.querySelector('input[name="redirection[]"]')).map((row, index) => {
				const checkbox = row.querySelector('input[name="redirection[]"]');
					const edit = row.querySelector('.rank-math-redirection-edit');
					let data = null;
					if (edit) {
						try { data = JSON.parse(edit.getAttribute('data-redirection') || 'null'); } catch {}
					}
					if (!data || typeof data !== 'object') {
						throw new Error('DATA_REDIRECTION_MISSING');
					}
					return {
						transient_row_id: checkbox.value,
						dom_index: index,
						sources: data.sources,
						destination: data.url_to ?? '',
						type: String(data.header_code ?? ''),
						status: currentStatus === 'trashed'
							? 'trashed'
							: (data.status ?? (row.classList.contains('rank-math-redirection-deactivated') ? 'inactive' : 'active')),
					};
			}), status);
		const next = page.locator('.tablenav.top a.next-page');
		return {
			rows,
			next: await next.count() === 1,
			view_counts: await page.locator('ul.subsubsub').count() === 1
				? (await page.locator('ul.subsubsub').innerText()).trim() : '',
		};
	}`;
}

function commercialInventoryFunction({ baseUrl }) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		await page.goto(baseUrl + '/wp-admin/admin.php?page=wc-orders', { waitUntil: 'domcontentloaded' });
		if (/wp-login\\.php/u.test(page.url())) throw new Error('AUTH_REQUIRED');
		if (!/page=wc-orders/u.test(page.url())) throw new Error('COMMERCIAL_SURFACE_MISSING');
		return {
			status_counts: await page.locator('ul.subsubsub').count() === 1
				? (await page.locator('ul.subsubsub').innerText()).replace(/\\s+/gu, ' ').trim() : '',
			pagination: await page.locator('.tablenav.top .tablenav-pages').count() === 1
				? (await page.locator('.tablenav.top .tablenav-pages').innerText()).replace(/\\s+/gu, ' ').trim() : '',
		};
	}`;
}

function mutationFunction({ baseUrl, action }) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		const action = ${JSON.stringify(action)};
		const listUrl = baseUrl + '/wp-admin/admin.php?page=rank-math-redirections&status='
			+ encodeURIComponent(action.list_status || 'all') + '&paged=' + action.page;
		await page.goto(listUrl, { waitUntil: 'domcontentloaded' });
		if (/wp-login\\.php/u.test(page.url())) throw new Error('AUTH_REQUIRED');
		if (!/page=rank-math-redirections/u.test(page.url())) throw new Error('SURFACE_ESCAPE');
		let row = null;
		if (action.kind !== 'create') {
			const matches = await page.locator('tbody tr').evaluateAll((elements, expected) => {
				const payload = row => {
					const edit = row.querySelector('.rank-math-redirection-edit');
					let data = null;
						if (edit) {
							try { data = JSON.parse(edit.getAttribute('data-redirection') || 'null'); } catch {}
						}
						if (!data || typeof data !== 'object') {
							throw new Error('DATA_REDIRECTION_MISSING');
						}
						return {
							sources: Array.isArray(data.sources) ? data.sources.map(source => ({
								pattern: String(source?.pattern ?? ''),
								comparison: String(source?.comparison ?? 'exact').toLowerCase(),
								ignore: String(source?.ignore ?? ''),
							})) : data.sources,
							destination: String(data.url_to ?? ''),
							type: String(data.header_code ?? ''),
							status: String(expected.list_status === 'trashed'
								? 'trashed'
								: (data.status ?? (row.classList.contains('rank-math-redirection-deactivated') ? 'inactive' : 'active'))).toLowerCase(),
						};
				};
				return elements.map((element, index) => ({ index, payload: payload(element) }))
					.filter(candidate => JSON.stringify(candidate.payload) === JSON.stringify(expected.payload));
			}, { payload: action.expected_payload, list_status: action.list_status });
			if (matches.length !== action.expected_payload_count) throw new Error('ROW_PAYLOAD_MULTIPLICITY_DRIFT');
			const selected = matches[action.payload_ordinal];
			if (!selected) throw new Error('ROW_PAYLOAD_ORDINAL_MISSING');
			row = page.locator('tbody tr').nth(selected.index);
			// WordPress keeps row actions far off-canvas until the row is
			// hovered. Reveal the official controls before requiring visibility.
			await row.hover();
		}
		if (action.kind === 'trash') {
			const control = row.locator('.rank-math-redirection-action[data-action="trash"]');
			if (await control.count() !== 1) throw new Error('OFFICIAL_TRASH_CONTROL_MISSING');
			await control.click();
			await row.waitFor({ state: 'detached', timeout: 15000 });
			return { result: 'clicked_official_trash' };
		}
		if (action.kind === 'edit') {
			const edit = row.locator('.rank-math-redirection-edit');
			if (await edit.count() !== 1) throw new Error('OFFICIAL_EDIT_CONTROL_MISSING');
			await edit.click();
		} else if (action.kind === 'create') {
			const add = page.locator('.rank-math-add-new-redirection');
			if (await add.count() !== 1) throw new Error('OFFICIAL_ADD_CONTROL_MISSING');
			await add.click();
		} else {
			throw new Error('UNSUPPORTED_ACTION');
		}
		const form = page.locator('.rank-math-editcreate-form.is-open');
		await form.waitFor({ state: 'visible' });
		const pattern = form.locator('.field-id-sources input[type="text"]').first();
			const comparison = form.locator('.field-id-sources select').first();
			await pattern.fill(action.target.source);
			await comparison.selectOption(action.target.matching);
			const ignoreCase = form.locator('.field-id-sources input[type="checkbox"]');
			if (await ignoreCase.count() !== 1) throw new Error('IGNORE_CASE_CONTROL_AMBIGUOUS');
			if (action.target.ignore === 'case') {
				// Toggle through false even when a legacy truthy "exact" value made
				// the checkbox look checked. This forces Rank Math to persist "case".
				await ignoreCase.setChecked(false);
				await ignoreCase.setChecked(true);
			} else {
				await ignoreCase.setChecked(false);
			}
			if (await ignoreCase.isChecked() !== (action.target.ignore === 'case')) {
				throw new Error('IGNORE_CASE_CONTROL_DRIFT');
			}
			await form.locator('.field-id-header_code button[value="' + action.target.type + '"]').click();
		const destination = form.locator('.field-id-url_to input[type="text"]');
		if (await destination.count() === 1) await destination.fill(action.target.destination);
		await form.locator('.field-id-status button[value="active"]').click();
		const save = form.getByRole('button', { name: /Add Redirection|Añadir redirección|Update Redirection|Actualizar redirección/i });
		if (await save.count() !== 1) throw new Error('OFFICIAL_SAVE_CONTROL_MISSING');
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			save.click(),
		]);
		return { result: 'clicked_official_' + action.kind };
	}`;
}

function trashBatchFunction({ baseUrl, listStatus, pageNumber, actions }) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		const listStatus = ${JSON.stringify(listStatus)};
		const pageNumber = ${pageNumber};
		const actions = ${JSON.stringify(actions)};
		await page.goto(baseUrl + '/wp-admin/admin.php?page=rank-math-redirections&status='
			+ encodeURIComponent(listStatus) + '&paged=' + pageNumber, { waitUntil: 'domcontentloaded' });
		if (/wp-login\\.php/u.test(page.url())) throw new Error('AUTH_REQUIRED');
		if (!/page=rank-math-redirections/u.test(page.url())) throw new Error('SURFACE_ESCAPE');
		const clicked = [];
		for (let actionIndex = 0; actionIndex < actions.length; actionIndex += 1) {
			const action = actions[actionIndex];
			const matches = await page.locator('tbody tr').evaluateAll((elements, expected) => {
				const payload = row => {
					const edit = row.querySelector('.rank-math-redirection-edit');
					let data = null;
						if (edit) {
							try { data = JSON.parse(edit.getAttribute('data-redirection') || 'null'); } catch {}
						}
						if (!data || typeof data !== 'object') {
							throw new Error('DATA_REDIRECTION_MISSING');
						}
						return {
							sources: Array.isArray(data.sources) ? data.sources.map(source => ({
								pattern: String(source?.pattern ?? ''),
								comparison: String(source?.comparison ?? 'exact').toLowerCase(),
								ignore: String(source?.ignore ?? ''),
							})) : data.sources,
							destination: String(data.url_to ?? ''),
							type: String(data.header_code ?? ''),
							status: String(expected.list_status === 'trashed'
								? 'trashed'
								: (data.status ?? (row.classList.contains('rank-math-redirection-deactivated') ? 'inactive' : 'active'))).toLowerCase(),
						};
				};
				return elements.map((element, index) => ({ index, payload: payload(element) }))
					.filter(candidate => JSON.stringify(candidate.payload) === JSON.stringify(expected.payload));
			}, { payload: action.expected_payload, list_status: listStatus });
			const expectedRemaining = actions.slice(actionIndex)
				.filter(candidate =>
					JSON.stringify(candidate.expected_payload) === JSON.stringify(action.expected_payload))
				.length + action.page_payload_preserve_count;
			if (matches.length !== expectedRemaining) throw new Error('ROW_PAYLOAD_MULTIPLICITY_DRIFT');
			const row = page.locator('tbody tr').nth(matches.at(-1).index);
			const control = row.locator('.rank-math-redirection-action[data-action="trash"]');
			if (await control.count() !== 1) throw new Error('OFFICIAL_TRASH_CONTROL_MISSING');
			await control.click();
			await row.waitFor({ state: 'detached', timeout: 15000 });
			clicked.push(action.payload_sha256);
		}
		return { result: 'clicked_official_trash_batch', clicked };
	}`;
}

function emptyTrashFunction({ baseUrl }) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		await page.goto(baseUrl + '/wp-admin/admin.php?page=rank-math-redirections&status=trashed',
			{ waitUntil: 'domcontentloaded' });
		if (/wp-login\\.php/u.test(page.url())) throw new Error('AUTH_REQUIRED');
		const empty = page.locator('input[name="delete_all"]');
		if (await empty.count() === 0) return { result: 'already_empty' };
		if (await empty.count() !== 2 && await empty.count() !== 1) throw new Error('EMPTY_TRASH_CONTROL_AMBIGUOUS');
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			empty.first().click(),
		]);
		return { result: 'clicked_official_empty_trash' };
	}`;
}

function monitor404PageFunction({ baseUrl, pageNumber }) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		const pageNumber = ${pageNumber};
		await page.goto(baseUrl + '/wp-admin/admin.php?page=rank-math-404-monitor&paged='
			+ pageNumber,
			{ waitUntil: 'domcontentloaded' });
		if (/wp-login\\.php/u.test(page.url())) throw new Error('AUTH_REQUIRED');
		if (!/page=rank-math-404-monitor/u.test(page.url())) throw new Error('SURFACE_ESCAPE');
		const rows = await page.locator('tbody tr input[name="log[]"]').count();
		const next = page.locator('.tablenav.top a.next-page');
		const cardinality = page.locator('.tablenav.top .displaying-num');
		return {
			page: pageNumber,
			rows,
			next: await next.count() === 1,
			cardinality_text: await cardinality.count() === 1
				? String(await cardinality.textContent()).replace(/\\s+/gu, ' ').trim()
				: '',
		};
	}`;
}

function clear404Function({ baseUrl }) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		await page.goto(baseUrl + '/wp-admin/admin.php?page=rank-math-404-monitor',
			{ waitUntil: 'domcontentloaded' });
		if (/wp-login\\.php/u.test(page.url())) throw new Error('AUTH_REQUIRED');
		if (!/page=rank-math-404-monitor/u.test(page.url())) throw new Error('SURFACE_ESCAPE');
		const clear = page.locator('.rank-math-clear-logs');
		const clearCount = await clear.count();
		if (clearCount < 1 || clearCount > 2) throw new Error('CLEAR_404_CONTROL_AMBIGUOUS');
		page.once('dialog', dialog => dialog.accept());
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			clear.first().click(),
		]);
		return { action: 'clicked_official_clear_log' };
	}`;
}

function parse404CardinalityText(text) {
	const digits = String(text ?? '').replace(/\D/gu, '');
	return digits === '' ? null : Number(digits);
}

function assert404InventoryConsistency(inventory) {
	if (
		!inventory
		|| !Array.isArray(inventory.page_rows)
		|| !Number.isSafeInteger(inventory.total_rows)
		|| inventory.total_rows < 0
		|| inventory.pages !== inventory.page_rows.length
		|| inventory.page_rows.reduce((sum, count) => sum + count, 0)
			!== inventory.total_rows
		|| inventory.page_rows.some((count, index) =>
			!Number.isSafeInteger(count)
			|| count < 0
			|| (index > 0 && count === 0))
	) {
		fail('404 Monitor pagination inventory is internally inconsistent');
	}
	if (
		inventory.declared_total !== null
		&& inventory.declared_total !== inventory.total_rows
	) {
		fail(
			`404 Monitor declares ${inventory.declared_total} rows, `
			+ `but pagination exposed ${inventory.total_rows}`
		);
	}
	if (inventory.total_rows === 0 && inventory.pages !== 1) {
		fail('empty 404 Monitor unexpectedly exposes multiple pages');
	}
}

function selfTest404Inventory() {
	const good = {
		pages: 3,
		page_rows: [100, 100, 42],
		total_rows: 242,
		declared_total: parse404CardinalityText('242 elementos'),
	};
	assert404InventoryConsistency(good);
	let mismatchFailed = false;
	try {
		assert404InventoryConsistency({
			...good,
			declared_total: 243,
		});
	} catch (error) {
		mismatchFailed = /declares 243 rows/u.test(error.message);
	}
	if (!mismatchFailed) {
		fail('synthetic multi-page 404 mismatch did not fail closed');
	}
	const empty = {
		pages: 1,
		page_rows: [0],
		total_rows: 0,
		declared_total: null,
	};
	assert404InventoryConsistency(empty);
	return {
		multi_page_total: good.total_rows,
		mismatch_fail_closed: mismatchFailed,
		empty_inventory_valid: true,
	};
}

function assertGeneratedFunction(source, label) {
	try {
		Function(`"use strict"; return (${source});`);
	} catch (error) {
		fail(`${label} browser function syntax: ${error.message}`);
	}
	if (/fetch\s*\(|wp-json|admin-ajax\\.php|XMLHttpRequest|apiFetch|\\$\\.ajax|jQuery\\.ajax/iu.test(source)) {
		fail(`${label} contains a prohibited direct write channel`);
	}
}

function reviewedPlanMatch(plan, payload) {
	return plan.reviewed_malformed.find((reviewed) => {
		if (JSON.stringify(reviewed.payload) === JSON.stringify(payload)) {
			return true;
		}
		return payload.status === 'trashed'
			&& reviewed.payload.status === 'active'
			&& JSON.stringify({
				...reviewed.payload,
				sources: [],
				status: 'trashed',
			}) === JSON.stringify(payload);
	});
}

function normalizeInventory(rows, plan) {
	const normalized = rows.map((row, index) => {
		const payload = visibleRowPayload(row);
		const payloadSha256 = rowPayloadSha256(payload);
		if (!Array.isArray(row.sources) || row.sources.length !== 1) {
			const reviewed = reviewedPlanMatch(plan, payload);
			if (reviewed) {
				return {
					...row,
					row_kind: 'reviewed_malformed',
					logical_key: `reviewed_malformed|${reviewed.payload_sha256}`,
					payload,
					payload_sha256: payloadSha256,
					reviewed_payload_sha256: reviewed.payload_sha256,
				};
			}
			fail(`UI row ${index + 1} must contain exactly one source`);
		}
		const matching = String(row.sources[0].comparison ?? 'exact').toLowerCase();
		if (!['exact', 'regex'].includes(matching)) {
			fail(`unsupported UI matching mode ${matching}`);
			}
			const source = canonicalSource(row.sources[0].pattern, matching);
			const ignore = normalizeObservedIgnore(row.sources[0].ignore);
			return {
			...row,
			row_kind: 'valid',
				source,
				matching,
				logical_key: logicalKey(source, matching),
				ignore,
				destination: canonicalDestination(row.destination),
			type: String(row.type),
			status: String(row.status).toLowerCase(),
			payload,
			payload_sha256: payloadSha256,
		};
	});
	for (const reviewed of plan.reviewed_malformed) {
		const count = normalized.filter((row) =>
			row.row_kind === 'reviewed_malformed'
			&& row.reviewed_payload_sha256 === reviewed.payload_sha256).length;
		if (count > reviewed.expected_count) {
			fail(`reviewed malformed payload multiplicity is ${count}, expected at most ${reviewed.expected_count}`);
		}
	}
	return normalized;
}

function differenceCount(target, remote) {
	return ['ignore', 'destination', 'type', 'status']
		.filter((field) => target[field] !== remote[field]).length;
}

function buildActions(plan, inventory) {
	const targetByKey = new Map(plan.rules.map((row) => [row.logical_key, row]));
	const valid = inventory.filter((row) => row.row_kind === 'valid');
	const remoteByKey = Map.groupBy(valid, (row) => row.logical_key);
	const actions = [];
	for (const target of plan.rules) {
		const candidates = [...(remoteByKey.get(target.logical_key) ?? [])];
		const editable = candidates.filter((row) => row.status !== 'trashed')
			.sort((left, right) => differenceCount(target, left) - differenceCount(target, right)
				|| Number(right.status === 'active') - Number(left.status === 'active')
				|| left.dom_index - right.dom_index);
		const keeper = editable.shift();
		if (!keeper) {
			actions.push({ kind: 'create', logical_key: target.logical_key, target });
		} else if (differenceCount(target, keeper) > 0) {
			actions.push({ kind: 'edit', logical_key: target.logical_key, target, evidence: keeper });
		}
		for (const duplicate of candidates.filter((candidate) => candidate !== keeper)) {
			actions.push({ kind: 'trash', logical_key: target.logical_key, target, evidence: duplicate });
		}
	}
	for (const remote of valid) {
		if (!targetByKey.has(remote.logical_key)) {
			actions.push({ kind: 'trash', logical_key: remote.logical_key, evidence: remote });
		}
	}
	for (const remote of inventory.filter((row) => row.row_kind === 'reviewed_malformed')) {
		actions.push({
			kind: 'trash',
			action_class: 'reviewed_malformed',
			logical_key: remote.logical_key,
			evidence: remote,
		});
	}
	return actions.sort((left, right) => left.logical_key.localeCompare(right.logical_key)
		|| left.kind.localeCompare(right.kind));
}

function assertFinal(plan, inventory) {
	const live = inventory.filter((row) => row.status !== 'trashed');
	const targetByKey = new Map(plan.rules.map((row) => [row.logical_key, row]));
	if (
		live.some((row) => row.row_kind !== 'valid')
		|| live.length !== EXPECTED_TARGET.total
		|| new Set(live.map((row) => row.logical_key)).size !== EXPECTED_TARGET.total
	) {
		fail(
			`final UI cardinality is ${live.length}, ` +
			`expected ${EXPECTED_TARGET.total} unique rules`
		);
	}
	for (const row of live) {
		const target = targetByKey.get(row.logical_key);
		if (!target || differenceCount(target, row) !== 0) {
			fail(`final UI drift at ${row.logical_key}`);
		}
	}
	if (inventory.some((row) => row.status === 'trashed')) {
		fail('trash is not empty after reconciliation');
	}
}

function selfTestReconciliation(plan) {
	const inventory = plan.rules.map((rule, index) => ({
		...rule,
		row_kind: 'valid',
		transient_row_id: String(8000 + index),
		dom_index: index,
		list_status: 'all',
		page: Math.floor(index / 100) + 1,
	}));
	const removed = inventory.shift();
	inventory[1] = { ...inventory[1], destination: '/synthetic-drift/' };
	inventory[2] = { ...inventory[2], ignore: 'exact' };
	inventory.push({
		...inventory[3],
		transient_row_id: '99991',
		dom_index: inventory.length,
	});
	inventory.push({
		row_kind: 'valid',
		logical_key: 'exact|synthetic-obsolete',
		source: 'synthetic-obsolete',
		matching: 'exact',
		destination: '/obsolete/',
		type: '301',
		status: 'active',
		transient_row_id: '99992',
		dom_index: inventory.length + 1,
		list_status: 'all',
		page: 2,
	});
	const actions = buildActions(plan, inventory);
	const ignoreDriftDetected = actions.some((action) =>
		action.kind === 'edit'
		&& action.evidence?.ignore === 'exact'
		&& action.target?.ignore === 'case');
	const counts = Object.fromEntries(
		['create', 'edit', 'trash'].map((kind) => [
			kind,
			actions.filter((action) => action.kind === kind).length,
		])
	);
	if (
			counts.create !== 1
			|| counts.edit !== 2
			|| counts.trash !== 2
			|| !ignoreDriftDetected
		|| !actions.some((action) =>
			action.kind === 'create' && action.logical_key === removed.logical_key)
	) {
		fail(`synthetic reconciliation self-test failed: ${JSON.stringify(counts)}`);
	}
	const resumedInventory = inventory.filter((row) => row.transient_row_id !== '99992');
	const resumedActions = buildActions(plan, resumedInventory);
	if (
		resumedActions.some((action) => action.logical_key === 'exact|synthetic-obsolete')
		|| resumedActions.length !== actions.length - 1
	) {
		fail('synthetic interruption/resumption self-test failed');
	}
	let driftFailClosed = false;
	try {
		normalizeInventory([{
			sources: [
				{ pattern: 'unexpected-one', comparison: 'exact' },
				{ pattern: 'unexpected-two', comparison: 'exact' },
			],
			destination: '/unexpected/',
			type: '301',
			status: 'active',
		}], plan);
	} catch (error) {
		driftFailClosed = /must contain exactly one source/u.test(error.message);
	}
	if (!driftFailClosed) {
		fail('synthetic payload drift did not fail closed');
	}
	let reviewedTransition = true;
	for (const reviewed of plan.reviewed_malformed) {
		const activeRows = Array.from({ length: reviewed.expected_count }, () => ({
			...reviewed.payload,
		}));
		const trashedRows = Array.from({ length: reviewed.expected_count }, () => ({
			...reviewed.payload,
			sources: [],
			status: 'trashed',
		}));
		reviewedTransition &&= normalizeInventory(activeRows, plan).every((row) =>
			row.row_kind === 'reviewed_malformed');
		reviewedTransition &&= normalizeInventory(trashedRows, plan).every((row) =>
			row.row_kind === 'reviewed_malformed' && row.status === 'trashed');
		let multiplicityFailed = false;
		try {
			normalizeInventory([...activeRows, activeRows[0]], plan);
		} catch (error) {
			multiplicityFailed = /multiplicity/u.test(error.message);
		}
		reviewedTransition &&= multiplicityFailed;
	}
	if (!reviewedTransition) {
		fail('reviewed malformed transition or multiplicity self-test failed');
	}
	return {
			...counts,
			resumed_actions: resumedActions.length,
			drift_fail_closed: driftFailClosed,
			ignore_drift_detected: ignoreDriftDetected,
			reviewed_malformed_transition: reviewedTransition,
	};
}

const planOption = option('--plan');
if (!planOption) fail('--plan is required');
const plan = JSON.parse(fs.readFileSync(path.resolve(planOption), 'utf8'));
validatePlan(plan);
const selfTestCounts = selfTestReconciliation(plan);
const selfTest404 = selfTest404Inventory();

const sampleFunctions = [
	['inventory', inventoryPageFunction({ baseUrl: 'http://localhost:8080', status: 'all', pageNumber: 1 })],
	['commercial', commercialInventoryFunction({ baseUrl: 'http://localhost:8080' })],
	['mutation', mutationFunction({
		baseUrl: 'http://localhost:8080',
		action: { kind: 'create', list_status: 'all', page: 1, target: plan.rules[0] },
	})],
	['trash-batch', trashBatchFunction({
		baseUrl: 'http://localhost:8080',
		listStatus: 'all',
		pageNumber: 1,
		actions: [],
	})],
	['empty-trash', emptyTrashFunction({ baseUrl: 'http://localhost:8080' })],
	['inventory-404', monitor404PageFunction({
		baseUrl: 'http://localhost:8080',
		pageNumber: 1,
	})],
	['clear-404', clear404Function({ baseUrl: 'http://localhost:8080' })],
];
for (const [label, source] of sampleFunctions) assertGeneratedFunction(source, label);
const mutationSource = sampleFunctions.find(([label]) => label === 'mutation')?.[1] ?? '';
const ignoreCheckboxGuarded = mutationSource.includes('IGNORE_CASE_CONTROL_AMBIGUOUS')
	&& mutationSource.includes('setChecked(false)')
	&& mutationSource.includes('setChecked(true)')
	&& mutationSource.includes('IGNORE_CASE_CONTROL_DRIFT');
if (!ignoreCheckboxGuarded) {
	fail('generated mutation does not control ignore-case fail-closed');
}

if (has('--validate-only')) {
	console.log(JSON.stringify({
		result: 'PASS',
		mode: 'validate-only',
		environment: plan.environment,
		plan_sha256: plan.plan_sha256,
		rules: plan.rules.length,
			generated_browser_functions: sampleFunctions.map(([name]) => name),
			ignore_checkbox_guarded: ignoreCheckboxGuarded,
		synthetic_reconciliation: selfTestCounts,
		synthetic_404_inventory: selfTest404,
	}, null, 2));
	process.exit(0);
}

const baseUrlOption = option('--base-url');
const session = option('--session');
const checkpointOption = option('--checkpoint');
if (!baseUrlOption || !session || !checkpointOption) {
	fail('--base-url, --session and --checkpoint are required for browser work');
}
const baseUrl = validateBaseUrl(baseUrlOption, plan.environment);
const apply = has('--apply');
if (apply && plan.environment !== 'production') {
	fail('--apply is available only for production plans');
}
if (apply && option('--confirm-plan-sha256') !== plan.plan_sha256) {
	fail('--apply requires --confirm-plan-sha256 equal to the reviewed plan SHA-256');
}

const checkpointPath = path.resolve(checkpointOption);
const checkpoint = loadCheckpoint(checkpointPath, plan);
const runtimeDir = fs.mkdtempSync(path.join(os.tmpdir(), 'akb-rankmath-ui-'));

function collectInventory() {
	const rows = [];
	let views = '';
	for (const status of ['all', 'trashed']) {
		let pageNumber = 1;
		let next = true;
		while (next) {
			if (pageNumber > 100) fail(`pagination guard exceeded for ${status}`);
			const result = runBrowserFunction(
				session,
				inventoryPageFunction({ baseUrl, status, pageNumber }),
				runtimeDir
			);
			rows.push(...result.rows.map((row) => ({ ...row, list_status: status, page: pageNumber })));
			views ||= result.view_counts;
			next = result.next;
			pageNumber += 1;
		}
	}
	return { rows: normalizeInventory(rows, plan), views };
}

function collect404Inventory() {
	const pageRows = [];
	let pageNumber = 1;
	let next = true;
	let cardinalityText = '';
	while (next) {
		if (pageNumber > 100) fail('pagination guard exceeded for 404 Monitor');
		const result = runBrowserFunction(
			session,
			monitor404PageFunction({ baseUrl, pageNumber }),
			runtimeDir
		);
		if (
			result.page !== pageNumber
			|| !Number.isSafeInteger(result.rows)
			|| result.rows < 0
			|| typeof result.next !== 'boolean'
		) {
			fail(`404 Monitor page ${pageNumber} returned an invalid inventory`);
		}
		pageRows.push(result.rows);
		if (pageNumber === 1) {
			cardinalityText = String(result.cardinality_text ?? '');
		} else if (
			cardinalityText
			&& result.cardinality_text
			&& String(result.cardinality_text) !== cardinalityText
		) {
			fail('404 Monitor cardinality changed while paginating');
		}
		next = result.next;
		pageNumber += 1;
	}
	const inventory = {
		pages: pageRows.length,
		page_rows: pageRows,
		total_rows: pageRows.reduce((sum, count) => sum + count, 0),
		cardinality_text: cardinalityText,
		declared_total: parse404CardinalityText(cardinalityText),
	};
	assert404InventoryConsistency(inventory);
	return inventory;
}

function payloadCoordinates(inventory, evidence) {
	const matches = inventory
		.filter((row) =>
			row.list_status === evidence.list_status
			&& row.page === evidence.page
			&& JSON.stringify(row.payload) === JSON.stringify(evidence.payload))
		.sort((left, right) => left.dom_index - right.dom_index);
	const ordinal = matches.indexOf(evidence);
	if (ordinal === -1) {
		fail('fresh inventory lost the selected payload evidence');
	}
	return {
		expected_payload_count: matches.length,
		payload_ordinal: ordinal,
	};
}

try {
	/*
	 * Global, full inventory is deliberately performed on every invocation and
	 * before every possible write. A stale checkpoint can never authorize a write.
	 */
	const commercialBefore = runBrowserFunction(
		session, commercialInventoryFunction({ baseUrl }), runtimeDir
	);
	const before = collectInventory();
	const monitor404Before = collect404Inventory();
	const actions = buildActions(plan, before.rows);
	checkpoint.preflight = {
		at: new Date().toISOString(),
		inventory_rows: before.rows.length,
		live_rows: before.rows.filter((row) => row.status !== 'trashed').length,
		trash_rows: before.rows.filter((row) => row.status === 'trashed').length,
		view_counts: before.views,
		actions: actions.map((action) => ({
			kind: action.kind,
			logical_key: action.logical_key,
			target_sha256: action.target?.semantic_sha256 ?? null,
			action_class: action.action_class ?? 'standard',
			observed_sha256: action.evidence?.payload_sha256 ?? null,
		})),
		actions_sha256: sha256(JSON.stringify(actions.map((action) => ({
			kind: action.kind,
			logical_key: action.logical_key,
			target: action.target?.semantic_sha256 ?? null,
		})))),
		monitor_404_total_rows: monitor404Before.total_rows,
		monitor_404_pages: monitor404Before.pages,
	};
	checkpoint.commercial_before = commercialBefore;
	checkpoint.updated_at = new Date().toISOString();
	writeAtomic(checkpointPath, checkpoint);

	if (apply) {
		/*
		 * Every phase starts with a full inventory. Row mutations then revalidate
		 * the exact visible payload on its known page immediately before clicking.
		 * This avoids the former actions × global-inventory complexity without
		 * allowing stale IDs or checkpoints to authorize a write.
		 */
		let phaseInventory = collectInventory();
		let phaseActions = buildActions(plan, phaseInventory.rows);
		for (const action of phaseActions.filter((candidate) => candidate.kind === 'edit')) {
			const coordinates = payloadCoordinates(phaseInventory.rows, action.evidence);
			runBrowserFunction(session, mutationFunction({
				baseUrl,
				action: {
					...action,
					list_status: action.evidence.list_status,
					page: action.evidence.page,
					expected_payload: action.evidence.payload,
					...coordinates,
				},
			}), runtimeDir);
			checkpoint.events[`edit:${action.logical_key}`] = {
				status: 'official_ui_clicked_after_payload_revalidation',
				payload_sha256: action.evidence.payload_sha256,
				at: new Date().toISOString(),
			};
			writeAtomic(checkpointPath, checkpoint);
		}

		let creationGuard = 0;
		while (true) {
			phaseInventory = collectInventory();
			phaseActions = buildActions(plan, phaseInventory.rows);
			const create = phaseActions.find((action) => action.kind === 'create');
			if (!create) break;
			creationGuard += 1;
			if (creationGuard > plan.rules.length) fail('create phase guard exceeded');
			runBrowserFunction(session, mutationFunction({
				baseUrl,
				action: { ...create, list_status: 'all', page: 1 },
			}), runtimeDir);
			checkpoint.events[`create:${create.logical_key}`] = {
				status: 'official_ui_clicked_after_global_revalidation',
				at: new Date().toISOString(),
			};
			writeAtomic(checkpointPath, checkpoint);
		}

		phaseInventory = collectInventory();
		phaseActions = buildActions(plan, phaseInventory.rows);
		if (phaseActions.some((action) => ['create', 'edit'].includes(action.kind))) {
			fail('new create/edit drift appeared before trash phase; resume from a fresh inventory');
		}
		const moveToTrash = phaseActions
			.filter((action) => action.kind === 'trash' && action.evidence.status !== 'trashed');
		for (const action of moveToTrash) {
			const samePayloadRows = phaseInventory.rows.filter((row) =>
				row.list_status === action.evidence.list_status
				&& row.page === action.evidence.page
				&& JSON.stringify(row.payload) === JSON.stringify(action.evidence.payload));
			const samePayloadTrashActions = moveToTrash.filter((candidate) =>
				candidate.evidence.list_status === action.evidence.list_status
				&& candidate.evidence.page === action.evidence.page
				&& JSON.stringify(candidate.evidence.payload) === JSON.stringify(action.evidence.payload));
			action.page_payload_preserve_count = samePayloadRows.length - samePayloadTrashActions.length;
			if (action.page_payload_preserve_count < 0) {
				fail('trash payload accounting underflow');
			}
		}
		const trashGroups = Map.groupBy(
			moveToTrash,
			(action) => `${action.evidence.list_status}|${action.evidence.page}`
		);
		const sortedGroups = [...trashGroups.entries()].sort(([left], [right]) => {
			const [leftStatus, leftPage] = left.split('|');
			const [rightStatus, rightPage] = right.split('|');
			return leftStatus.localeCompare(rightStatus) || Number(rightPage) - Number(leftPage);
		});
		for (const [, group] of sortedGroups) {
			const ordered = group.sort((left, right) =>
				right.evidence.dom_index - left.evidence.dom_index);
			const listStatus = ordered[0].evidence.list_status;
			const pageNumber = ordered[0].evidence.page;
			runBrowserFunction(session, trashBatchFunction({
				baseUrl,
				listStatus,
				pageNumber,
				actions: ordered.map((action) => ({
					logical_key: action.logical_key,
					action_class: action.action_class ?? 'standard',
					expected_payload: action.evidence.payload,
					payload_sha256: action.evidence.payload_sha256,
					page_payload_preserve_count: action.page_payload_preserve_count,
				})),
			}), runtimeDir);
			for (const action of ordered) {
				checkpoint.events[`trash:${action.logical_key}`] = {
					status: 'official_ui_clicked_after_payload_revalidation',
					action_class: action.action_class ?? 'standard',
					payload_sha256: action.evidence.payload_sha256,
					at: new Date().toISOString(),
				};
			}
			writeAtomic(checkpointPath, checkpoint);
		}

		const afterMoves = collectInventory();
		const afterMoveActions = buildActions(plan, afterMoves.rows);
		const trashRows = afterMoves.rows.filter((row) => row.status === 'trashed');
		const guardedTrash = afterMoveActions.filter((action) =>
			action.kind === 'trash' && action.evidence?.status === 'trashed');
		if (
			afterMoveActions.some((action) =>
				action.kind !== 'trash' || action.evidence?.status !== 'trashed')
			|| guardedTrash.length !== trashRows.length
		) {
			fail('trash contents are not the exact fingerprint-reviewed reconciliation set');
		}
		if (afterMoves.rows.some((row) => row.status === 'trashed')) {
			runBrowserFunction(session, emptyTrashFunction({ baseUrl }), runtimeDir);
		}
		const afterRules = collectInventory();
		assertFinal(plan, afterRules.rows);
		let monitor404Action = { action: 'already_empty' };
		if (monitor404Before.total_rows > 0) {
			monitor404Action = runBrowserFunction(
				session, clear404Function({ baseUrl }), runtimeDir
			);
		}
		const monitor404After = collect404Inventory();
		if (monitor404After.total_rows !== 0) {
			fail(
				`404 Monitor still exposes ${monitor404After.total_rows} rows `
				+ 'after the official clear action'
			);
		}
		checkpoint.monitor_404 = {
			before: monitor404Before,
			after: monitor404After,
			action: monitor404Action.action,
		};
		const afterMonitor = collectInventory();
		assertFinal(plan, afterMonitor.rows);
		checkpoint.commercial_after = runBrowserFunction(
			session, commercialInventoryFunction({ baseUrl }), runtimeDir
		);
		if (JSON.stringify(checkpoint.commercial_after) !== JSON.stringify(commercialBefore)) {
			fail('commercial history UI fingerprint changed during reconciliation');
		}
	} else {
		checkpoint.monitor_404 = {
			before: monitor404Before,
			after: monitor404Before,
			action: 'none',
		};
		checkpoint.commercial_after = commercialBefore;
	}

	checkpoint.updated_at = new Date().toISOString();
	writeAtomic(checkpointPath, checkpoint);
	console.log(JSON.stringify({
		result: 'PASS',
		mode: apply ? 'apply' : 'preflight',
		environment: plan.environment,
		plan_sha256: plan.plan_sha256,
		inventory_rows: checkpoint.preflight.inventory_rows,
		actions: checkpoint.preflight.actions.length,
		monitor_404: checkpoint.monitor_404,
		commercial_ui_summary_unchanged:
			JSON.stringify(checkpoint.commercial_before) === JSON.stringify(checkpoint.commercial_after),
	}, null, 2));
} finally {
	fs.rmSync(runtimeDir, { recursive: true, force: true });
}
