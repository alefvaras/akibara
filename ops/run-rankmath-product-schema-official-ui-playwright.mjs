#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';

function fail(message) {
	throw new Error(`Rank Math product schema UI gate failed: ${message}`);
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

function validateBaseUrl(rawBaseUrl, environment) {
	let parsed;
	try {
		parsed = new URL(rawBaseUrl);
	} catch {
		fail(`invalid --base-url ${rawBaseUrl}`);
	}
	if (!['http:', 'https:'].includes(parsed.protocol)) {
		fail('base URL must use HTTP or HTTPS');
	}
	const isLocal = ['localhost', '127.0.0.1', '::1'].includes(parsed.hostname);
	if (environment === 'docker' && !isLocal) {
		fail('Docker gate may run only against localhost');
	}
	if (environment !== 'docker' && isLocal) {
		fail(`${environment} gate may not run against localhost`);
	}
	if (
		environment === 'production'
		&& !['akibara.cl', 'www.akibara.cl'].includes(parsed.hostname)
	) {
		fail('production gate requires the Akibara production hostname');
	}
	return parsed.origin;
}

function parseCliJson(stdout, stderr) {
	let outer;
	try {
		outer = JSON.parse(stdout);
	} catch {
		fail(`Playwright CLI did not return JSON: ${(stderr || stdout).slice(0, 800)}`);
	}
	if (typeof outer.result !== 'string') {
		fail(`Playwright CLI returned no serialized result: ${stdout.slice(0, 800)}`);
	}
	try {
		return JSON.parse(outer.result);
	} catch {
		fail(`Playwright result was not JSON: ${outer.result.slice(0, 800)}`);
	}
}

function runBrowserFunction(session, source, runtimeDir) {
	fs.mkdirSync(runtimeDir, { recursive: true });
	const functionPath = path.join(
		runtimeDir,
		`rankmath-product-schema-${process.pid}-${Date.now()}.js`
	);
	fs.writeFileSync(functionPath, `${source}\n`, { mode: 0o600 });
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
			maxBuffer: 16 * 1024 * 1024,
			/*
			 * The global phase navigates before and after its optional save. Each
			 * navigation has two bounded attempts, so the host must outlive the
			 * complete persistence-verification window.
			 */
			timeout: 420_000,
		}
	);
	fs.unlinkSync(functionPath);
	if (command.error || command.status !== 0) {
		fail(
			`Playwright CLI failed (${command.status ?? 'spawn'}): `
			+ `${(command.stderr || command.stdout || command.error?.message).slice(0, 1400)}`
		);
	}
	return parseCliJson(command.stdout, command.stderr);
}

function globalSchemaFunction({ baseUrl, saveGlobal }) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		const saveGlobal = ${JSON.stringify(saveGlobal)};
			const target = baseUrl
				+ '/wp-admin/admin.php?page=rank-math-options-titles&view=post-type-product';
			await page.setViewportSize({ width: 1440, height: 1000 });
			page.setDefaultNavigationTimeout(75000);
			async function navigate(destination) {
				for (let attempt = 1; attempt <= 2; attempt += 1) {
					try {
						await page.goto(destination, {
							waitUntil: 'domcontentloaded',
							timeout: 75000,
						});
						return;
					} catch (error) {
						if (attempt === 2 || !/Timeout/iu.test(String(error))) throw error;
						await page.waitForTimeout(1500);
					}
				}
			}
			await navigate(target);
		if (/\\/wp-login\\.php/u.test(page.url())) throw new Error('AUTH_REQUIRED');
		if (!/page=rank-math-options-titles/u.test(page.url())
			|| !/view=post-type-product/u.test(page.url())) {
			throw new Error('RANK_MATH_TITLES_SURFACE_ESCAPE');
		}

		const productTab = page.getByRole('tab', { name: /Productos$/u });
		if (await productTab.count() !== 1) throw new Error('PRODUCTS_TAB_AMBIGUOUS');
		if (await productTab.getAttribute('aria-selected') !== 'true') {
			throw new Error('PRODUCTS_TAB_NOT_SELECTED');
		}
		if (await page.getByText('Tipo de Schema', { exact: true }).count() !== 1) {
			throw new Error('SCHEMA_TYPE_LABEL_MISSING');
		}
		const woo = page.getByRole('radio', {
			name: /^(Producto de WooCommerce|WooCommerce Product)$/u,
		});
		const none = page.getByRole('radio', { name: /^(Ninguno|None)$/u });
		const save = page.getByRole('button', {
			name: /^(Guardar cambios|Save Changes)$/u,
		});
		if (await woo.count() !== 1 || await none.count() !== 1 || await save.count() !== 1) {
			throw new Error('GLOBAL_SCHEMA_CONTROLS_AMBIGUOUS');
		}
		if (!await woo.isChecked()) throw new Error('GLOBAL_SCHEMA_NOT_WOOCOMMERCE_PRODUCT');

		let saved = false;
		if (saveGlobal) {
			/*
			 * Clicking the already-selected radio does not dirty Rank Math's
			 * React form. Toggle only in browser memory, restore the required
			 * value, then save once. A crash before Save writes nothing.
			 */
			await none.click();
			await woo.click();
				if (!await woo.isChecked() || await none.isChecked()) {
					throw new Error('GLOBAL_SCHEMA_RESTORE_FAILED');
				}
				if (await save.isDisabled()) throw new Error('SAVE_CHANGES_NOT_ENABLED');
				await save.click();
				const saveHandle = await save.elementHandle();
				if (!saveHandle) throw new Error('SAVE_CHANGES_CONTROL_DETACHED');
				await page.waitForFunction(
					button => button.disabled,
					saveHandle,
					{ timeout: 30000 }
				);

				await navigate(target);
			if (/\\/wp-login\\.php/u.test(page.url())) throw new Error('AUTH_REQUIRED_AFTER_SAVE');
			const persisted = page.getByRole('radio', {
				name: /^(Producto de WooCommerce|WooCommerce Product)$/u,
			});
			if (await persisted.count() !== 1 || !await persisted.isChecked()) {
				throw new Error('GLOBAL_SCHEMA_DID_NOT_PERSIST');
			}
			if (!await page.getByRole('button', {
				name: /^(Guardar cambios|Save Changes)$/u,
			}).isDisabled()) {
				throw new Error('GLOBAL_FORM_REMAINS_DIRTY');
			}
			saved = true;
		}

		return {
			status: 'PASS',
			surface: page.url().startsWith(baseUrl)
				? page.url().slice(baseUrl.length)
				: page.url(),
			product_tab_selected: true,
			schema_label: (await woo.getAttribute('aria-label'))
				|| (await woo.locator('xpath=following-sibling::*').first().textContent())
				|| 'Producto de WooCommerce',
			checked: true,
			saved,
		};
	}`;
}

function productSchemaFunction({
	baseUrl,
	productId,
	productTitle,
	expectedSchemaTitle,
	expectedShortcutLength,
}) {
	return `async page => {
		const baseUrl = ${JSON.stringify(baseUrl)};
		const productId = ${JSON.stringify(productId)};
		const productTitle = ${JSON.stringify(productTitle)};
		const expectedSchemaTitle = ${JSON.stringify(expectedSchemaTitle)};
		const expectedShortcutLength = ${JSON.stringify(expectedShortcutLength)};
			const target = baseUrl + '/wp-admin/post.php?post='
				+ encodeURIComponent(String(productId)) + '&action=edit';
			await page.setViewportSize({ width: 1440, height: 1000 });
			page.setDefaultNavigationTimeout(75000);
			async function navigate(destination) {
				for (let attempt = 1; attempt <= 2; attempt += 1) {
					try {
						await page.goto(destination, {
							waitUntil: 'domcontentloaded',
							timeout: 75000,
						});
						return;
					} catch (error) {
						if (attempt === 2 || !/Timeout/iu.test(String(error))) throw error;
						await page.waitForTimeout(1500);
					}
				}
			}
			await navigate(target);
		if (/\\/wp-login\\.php/u.test(page.url())) throw new Error('AUTH_REQUIRED');
		const currentUrl = page.url();
		const productQuery = new RegExp('[?&]post=' + String(productId) + '(?:&|$)', 'u');
		if (!/\\/wp-admin\\/post\\.php/u.test(currentUrl)
			|| !productQuery.test(currentUrl)
			|| !/[?&]action=edit(?:&|$)/u.test(currentUrl)) {
			throw new Error('PRODUCT_EDIT_SURFACE_ESCAPE');
		}

		const title = page.locator('#title');
		if (await title.count() !== 1 || await title.inputValue() !== productTitle) {
			throw new Error('PRODUCT_IDENTITY_MISMATCH');
			}
			const schemaTab = page.locator(
				'button[role="tab"].rank-math-schema-tab'
					+ '[id$="-schema"][aria-controls$="-schema-view"]'
			);
			await schemaTab.first().waitFor({ state: 'visible', timeout: 30_000 });
			if (
				await schemaTab.count() !== 1
				|| (await schemaTab.first().innerText()).trim() !== 'Schema'
			) {
				throw new Error('SCHEMA_TAB_AMBIGUOUS');
			}
			await schemaTab.click();
		const schemaInUse = page.getByRole('heading', {
			name: 'Schema en uso',
			exact: true,
		});
			if (await schemaInUse.count() !== 1) {
				throw new Error('SCHEMA_IN_USE_PANEL_MISSING');
			}
			await schemaInUse.waitFor({ state: 'visible', timeout: 15_000 });

		const schemas = await page.evaluate(() => window.rankMath?.schemas ?? null);
		if (!schemas || typeof schemas !== 'object' || Array.isArray(schemas)) {
			throw new Error('RANK_MATH_SCHEMA_STATE_MISSING');
		}
		const entries = Object.entries(schemas);
		if (entries.length !== 1) throw new Error('SCHEMA_COUNT_NOT_ONE');
		const [schemaKey, schema] = entries[0];
		if (!/^schema-\\d+$/u.test(schemaKey)) throw new Error('SCHEMA_KEY_INVALID');
		if (
			schema?.['@type'] !== 'WooCommerceProduct'
			|| schema?.metadata?.type !== 'template'
			|| String(schema?.metadata?.isPrimary) !== '1'
			|| schema?.metadata?.title !== expectedSchemaTitle
		) {
			throw new Error('WOOCOMMERCE_PRODUCT_SCHEMA_CONTRACT_MISMATCH');
		}
		const shortcut = String(schema?.metadata?.shortcode ?? '');
		if (shortcut.length !== expectedShortcutLength) {
			throw new Error('SHORTCUT_LENGTH_MISMATCH');
		}
		if (
			expectedShortcutLength === 38
			&& !/^s-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/u
				.test(shortcut)
		) {
			throw new Error('HISTORICAL_SHORTCUT_UUID_INVALID');
		}
			const schemaTitles = page.getByText(expectedSchemaTitle, { exact: true });
			let visibleSchemaTitles = 0;
			for (let index = 0; index < await schemaTitles.count(); index += 1) {
				if (await schemaTitles.nth(index).isVisible()) visibleSchemaTitles += 1;
			}
			if (visibleSchemaTitles !== 1) {
				throw new Error('SCHEMA_CARD_TITLE_MISSING_OR_AMBIGUOUS');
			}

		return {
			status: 'PASS',
			product_id: productId,
			product_title: productTitle,
			schema_key: schemaKey,
			schema_type: schema['@type'],
			schema_title: schema.metadata.title,
			primary: String(schema.metadata.isPrimary),
			shortcut,
			shortcut_length: shortcut.length,
			read_only: true,
		};
	}`;
}

function assertBrowserFunctionSyntax(source, label) {
	try {
		// Parse only. Generated functions are never invoked by --validate-only.
		Function(`"use strict"; return (${source});`);
	} catch (error) {
		fail(`${label} browser function is invalid: ${error.message}`);
	}
}

const environment = option('--environment');
if (!['docker', 'production'].includes(environment)) {
	fail('--environment must be docker or production');
}
const baseUrl = validateBaseUrl(option('--base-url'), environment);
const session = option('--session');
if (!session || !/^[a-zA-Z0-9._-]+$/u.test(session)) fail('invalid --session');
const productId = Number.parseInt(option('--product-id', '21761'), 10);
const productTitle = option('--product-title', 'Akira 06');
const expectedSchemaTitle = option('--expected-schema-title', 'WooCommerce Product');
const expectedShortcutLength = Number.parseInt(option('--expected-shortcut-length', '38'), 10);
const saveGlobal = has('--save-global');
const resume = has('--resume');
const validateOnly = has('--validate-only');
if (!Number.isSafeInteger(productId) || productId < 1) fail('invalid --product-id');
if (!productTitle || !expectedSchemaTitle) fail('product/schema title may not be empty');
if (!Number.isSafeInteger(expectedShortcutLength) || expectedShortcutLength < 3) {
	fail('invalid --expected-shortcut-length');
}
if (saveGlobal && environment !== 'production') {
	fail('--save-global is available only in production');
}

const configuration = {
	schema_version: 1,
	environment,
	base_url: baseUrl,
	product_id: productId,
	product_title: productTitle,
	expected_schema_title: expectedSchemaTitle,
	expected_shortcut_length: expectedShortcutLength,
	save_global: saveGlobal,
	official_ui_only: true,
	product_schema_write: false,
};
const configurationSha256 = sha256(JSON.stringify(configuration));
const checkpointPath = path.resolve(
	option(
		'--checkpoint',
		path.join(
			os.tmpdir(),
			`akibara-rankmath-product-schema-${environment}-${configurationSha256.slice(0, 12)}.json`
		)
	)
);
let checkpoint = {
	schema_version: 1,
	configuration_sha256: configurationSha256,
	configuration,
	created_at: new Date().toISOString(),
	updated_at: new Date().toISOString(),
	phases: {},
};
if (fs.existsSync(checkpointPath)) {
	const existing = JSON.parse(fs.readFileSync(checkpointPath, 'utf8'));
	if (
		existing?.schema_version !== 1
		|| existing.configuration_sha256 !== configurationSha256
		|| typeof existing.phases !== 'object'
	) {
		fail('checkpoint belongs to another configuration');
	}
	checkpoint = existing;
}

if (validateOnly) {
	assertBrowserFunctionSyntax(
		globalSchemaFunction({ baseUrl, saveGlobal }),
		'global schema'
	);
	assertBrowserFunctionSyntax(
		productSchemaFunction({
			baseUrl,
			productId,
			productTitle,
			expectedSchemaTitle,
			expectedShortcutLength,
		}),
		'product schema'
	);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		validate_only: true,
		configuration_sha256: configurationSha256,
		checkpoint: checkpointPath,
		policy: {
			official_ui_only: true,
				product_schema_write: false,
				direct_api_writes: 0,
				direct_sql_writes: 0,
				resume_revalidates_ui: true,
			},
	}, null, 2)}\n`);
	process.exit(0);
}

const runtimeDir = fs.mkdtempSync(path.join(os.tmpdir(), 'akb-rankmath-schema-ui-'));
try {
	const globalAlreadyPassed = checkpoint.phases.global?.status === 'PASS';
	const skipRepeatedGlobalSave = (
		resume
		&& globalAlreadyPassed
		&& checkpoint.phases.global?.saved === true
	);
	const globalResult = runBrowserFunction(
		session,
		globalSchemaFunction({
			baseUrl,
			saveGlobal: saveGlobal && !skipRepeatedGlobalSave,
		}),
		runtimeDir
	);
	globalResult.revalidated = Boolean(resume && globalAlreadyPassed);
	globalResult.save_skipped_on_resume = skipRepeatedGlobalSave;
	checkpoint.phases.global = globalResult;
	checkpoint.updated_at = new Date().toISOString();
	writeAtomic(checkpointPath, checkpoint);

	const productAlreadyPassed = checkpoint.phases.product?.status === 'PASS';
	const productResult = runBrowserFunction(
		session,
		productSchemaFunction({
			baseUrl,
			productId,
			productTitle,
			expectedSchemaTitle,
			expectedShortcutLength,
		}),
		runtimeDir
	);
	productResult.revalidated = Boolean(resume && productAlreadyPassed);
	checkpoint.phases.product = productResult;
	checkpoint.updated_at = new Date().toISOString();
	writeAtomic(checkpointPath, checkpoint);
} finally {
	fs.rmSync(runtimeDir, { recursive: true, force: true });
}

process.stdout.write(`${JSON.stringify({
	result: 'PASS',
	environment,
	base_url: baseUrl,
	checkpoint: checkpointPath,
	resume,
	global: checkpoint.phases.global,
	product: checkpoint.phases.product,
}, null, 2)}\n`);
