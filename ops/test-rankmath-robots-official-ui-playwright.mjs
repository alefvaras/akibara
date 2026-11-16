#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import test from 'node:test';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	finalizeRobotsManifest,
	validateRobotsManifest,
} from './lib/rankmath-robots-official-ui-manifest.mjs';
import {
	parseRobotsPlaywrightResult,
	robotsBrowserFunction,
} from './run-rankmath-robots-official-ui-playwright.mjs';

const ops = path.dirname(fileURLToPath(import.meta.url));
const repository = path.dirname(ops);
const runner = path.join(
	ops,
	'run-rankmath-robots-official-ui-playwright.mjs'
);
const generator = path.join(
	ops,
	'generate-rankmath-robots-official-ui-manifest.mjs'
);

function draft(environment = 'docker') {
	const origin = environment === 'docker'
		? 'http://localhost:8080'
		: 'https://akibara.cl';
	const searchHygiene = [
		'Disallow: /wp-admin/',
		'Allow: /wp-admin/admin-ajax.php',
		'Disallow: /xmlrpc.php',
		'Disallow: /wp-cron.php',
		'Disallow: /wp-json/',
		'Disallow: /trackback/',
		'Disallow: /carrito/',
		'Disallow: /checkout/',
		'Disallow: /mi-cuenta/',
		'Disallow: /?s=',
		'Disallow: /search/',
		'Disallow: /*/feed/',
		'Disallow: /feed/',
		'Disallow: /comments/feed/',
	];
	const storebotHygiene = [
		'Disallow: /wp-admin/',
		'Allow: /wp-admin/admin-ajax.php',
		'Disallow: /xmlrpc.php',
		'Disallow: /wp-cron.php',
		'Disallow: /wp-json/',
		'Allow: /wp-json/wc/store/',
		'Disallow: /trackback/',
		'Disallow: /mi-cuenta/',
		'Disallow: /?s=',
		'Disallow: /search/',
		'Disallow: /*/feed/',
		'Disallow: /feed/',
		'Disallow: /comments/feed/',
	];
	const group = (agents, signal, directives) => [
		...agents.map((agent) => `User-agent: ${agent}`),
		signal,
		...directives,
	];
	return {
		schema_version: 1,
		manifest_type: 'akibara_rankmath_robots_official_ui',
		environment,
		target_origin: origin,
		edit_path: '/wp-admin/admin.php?page=rank-math-options-general&view=robots',
		application_policy: {
			official_rank_math_ui_only: true,
			production_writes_only: true,
			direct_rest_writes: 0,
			direct_sql_writes: 0,
			direct_wp_cli_writes: 0,
			direct_option_writes: 0,
			remote_artifacts: 0,
		},
		robots_txt_content: {
			before: 'User-agent: *\nDisallow: /wp-admin/',
			after: [
				'# Akibara permite búsqueda, citas y uso dirigido de IA; bloquea por separado bots de entrenamiento.',
				'# Google-Extended se permite conscientemente: aporta grounding y también puede contribuir al entrenamiento futuro de Gemini.',
				...['GPTBot', 'CCBot', 'Bytespider', 'Applebot-Extended', 'meta-externalagent', 'Amazonbot', 'ClaudeBot']
					.flatMap((agent) => group(
						[agent],
						'Content-Signal: search=no, ai-input=no, ai-train=no',
						['Disallow: /']
					)),
				...group(
					['Google-Extended'],
					'Content-Signal: search=yes, ai-input=yes, ai-train=yes',
					['Allow: /']
				),
				...['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot']
					.flatMap((agent) => group(
						[agent],
						'Content-Signal: search=yes, ai-input=yes, ai-train=no',
						['Allow: /', ...searchHygiene]
					)),
				...group(
					['Storebot-Google'],
					'Content-Signal: search=yes, ai-input=yes, ai-train=no',
					['Allow: /', ...storebotHygiene]
				),
				...group(
					['*'],
					'Content-Signal: search=yes, ai-input=yes, ai-train=no',
					['Allow: /', ...searchHygiene]
				),
				`Sitemap: ${origin}/sitemap_index.xml`,
			].join('\n'),
		},
	};
}

function storebotOnlyDraft(environment = 'docker') {
	const value = draft(environment);
	const candidate = fs.readFileSync(path.join(
		repository,
		'ops/contracts/rankmath-robots-v14-storebot-only-candidate.txt'
	), 'utf8');
	value.robots_txt_content.after = environment === 'docker'
		? candidate.replaceAll('https://akibara.cl', 'http://localhost:8080')
		: candidate;
	return value;
}

function fakeNpx(bin) {
	const source = `#!/usr/bin/env node
const fs = require('node:fs');
const args = process.argv.slice(2);
const fileArg = args.find(value => value.startsWith('--filename='));
if (!fileArg) process.exit(90);
const file = fileArg.slice('--filename='.length);
const text = fs.readFileSync(file, 'utf8');
const match = /const manifest = (\\{[\\s\\S]*?\\});\\n\\t\\tconst phase = ("[^"]+");/u.exec(text);
if (!match) process.exit(91);
const manifest = JSON.parse(match[1]);
const phase = JSON.parse(match[2]);
fs.appendFileSync(process.env.AKB_ROBOTS_LOG, phase + '\\n', { mode: 0o600 });
const current = manifest.robots_txt_content[phase === 'apply' ? 'after' : 'before'];
const result = {
	records: [{ key: 'rankmath:robots_txt_content', current }],
	blocked: process.env.AKB_PHYSICAL_ROBOTS === '1'
		? 'PHYSICAL_ROBOTS_TXT_BLOCKS_RANK_MATH_UI'
		: null,
};
process.stdout.write(JSON.stringify({ result: JSON.stringify(result) }));
`;
	fs.writeFileSync(path.join(bin, 'npx'), source, { mode: 0o700 });
}

function replaceAgentDirective(content, agent, expected, replacement) {
	const lines = content.split('\n');
	const start = lines.indexOf(`User-agent: ${agent}`);
	assert.notEqual(start, -1, `missing ${agent} test group`);
	let end = lines.findIndex(
		(line, index) => index > start && line.startsWith('User-agent: ')
	);
	if (end === -1) end = lines.length;
	const directive = lines.findIndex(
		(line, index) => index > start && index < end && line === expected
	);
	assert.notEqual(directive, -1, `missing ${expected} in ${agent} test group`);
	if (replacement === null) lines.splice(directive, 1);
	else lines[directive] = replacement;
	return lines.join('\n');
}

test('robots manifest and browser source are deterministic and UI-only', () => {
	const first = finalizeRobotsManifest(draft());
	const second = finalizeRobotsManifest(structuredClone(draft()));
	assert.equal(first.manifest_sha256, second.manifest_sha256);
	assert.doesNotThrow(() => validateRobotsManifest(first));
	const tampered = structuredClone(first);
	tampered.robots_txt_content.after += '\nDisallow: /';
	assert.throws(() => validateRobotsManifest(tampered), /hashes|manifest_sha256/u);
	const litespeedBlocked = structuredClone(draft());
	litespeedBlocked.robots_txt_content.after +=
		'\nDisallow: /wp-content/litespeed/';
	assert.throws(
		() => finalizeRobotsManifest(litespeedBlocked),
		/must not contain Disallow: \/wp-content\/litespeed\//u
	);
	const blockedFacet = structuredClone(draft());
	blockedFacet.robots_txt_content.after +=
		'\nDisallow: /*?filter_*';
	assert.throws(
		() => finalizeRobotsManifest(blockedFacet),
		/must not contain Disallow: \/\*\?filter_\*/u
	);
	const blockedMailPoet = structuredClone(draft());
	blockedMailPoet.robots_txt_content.after +=
		'\nDisallow: /?mailpoet_page=';
	assert.throws(
		() => finalizeRobotsManifest(blockedMailPoet),
		/must not contain Disallow: \/\?mailpoet_page=/u
	);
	const requiredCrawlerPolicies = [
		['GPTBot', 'Disallow: /', 'Allow: /'],
		['CCBot', 'Disallow: /', 'Allow: /'],
		['Bytespider', 'Disallow: /', 'Allow: /'],
		['Applebot-Extended', 'Disallow: /', 'Allow: /'],
		['meta-externalagent', 'Disallow: /', 'Allow: /'],
		['Amazonbot', 'Disallow: /', 'Allow: /'],
		['ClaudeBot', 'Disallow: /', 'Allow: /'],
		['Google-Extended', 'Allow: /', 'Disallow: /'],
		['OAI-SearchBot', 'Allow: /', 'Disallow: /'],
		['ChatGPT-User', 'Allow: /', 'Disallow: /'],
		['Claude-SearchBot', 'Allow: /', 'Disallow: /'],
		['Claude-User', 'Allow: /', 'Disallow: /'],
		['PerplexityBot', 'Allow: /', 'Disallow: /'],
		['Storebot-Google', 'Allow: /', 'Disallow: /'],
	];
	for (
		const [agent, expectedDirective, rejectedDirective]
		of requiredCrawlerPolicies
	) {
		const invalidCrawler = structuredClone(draft());
		invalidCrawler.robots_txt_content.after = replaceAgentDirective(
			invalidCrawler.robots_txt_content.after,
			agent,
			expectedDirective,
			rejectedDirective
		);
		assert.throws(
			() => finalizeRobotsManifest(invalidCrawler),
			new RegExp(
				`robots ${agent} policy must contain exactly`,
				'u'
			)
		);
	}
	const trainingEnabledForSearchBot = structuredClone(draft());
	trainingEnabledForSearchBot.robots_txt_content.after = replaceAgentDirective(
		trainingEnabledForSearchBot.robots_txt_content.after,
		'OAI-SearchBot',
		'Content-Signal: search=yes, ai-input=yes, ai-train=no',
		'Content-Signal: search=yes, ai-input=yes, ai-train=yes'
	);
	assert.throws(
		() => finalizeRobotsManifest(trainingEnabledForSearchBot),
		/robots OAI-SearchBot policy must contain exactly Content-Signal/u
	);
	const searchBotWithoutHygiene = structuredClone(draft());
	searchBotWithoutHygiene.robots_txt_content.after = replaceAgentDirective(
		searchBotWithoutHygiene.robots_txt_content.after,
		'Claude-SearchBot',
		'Disallow: /wp-admin/',
		null
	);
	assert.throws(
		() => finalizeRobotsManifest(searchBotWithoutHygiene),
		/robots Claude-SearchBot policy is missing Disallow: \/wp-admin\//u
	);
	const storebotCheckoutBlocked = structuredClone(draft());
	storebotCheckoutBlocked.robots_txt_content.after = replaceAgentDirective(
		storebotCheckoutBlocked.robots_txt_content.after,
		'Storebot-Google',
		'Allow: /',
		'Allow: /\nDisallow: /checkout/'
	);
	assert.throws(
		() => finalizeRobotsManifest(storebotCheckoutBlocked),
		/robots Storebot-Google policy must not contain Disallow: \/checkout\//u
	);
	const storebotWithoutStoreApi = structuredClone(draft());
	storebotWithoutStoreApi.robots_txt_content.after = replaceAgentDirective(
		storebotWithoutStoreApi.robots_txt_content.after,
		'Storebot-Google',
		'Allow: /wp-json/wc/store/',
		null
	);
	assert.throws(
		() => finalizeRobotsManifest(storebotWithoutStoreApi),
		/robots Storebot-Google policy is missing Allow: \/wp-json\/wc\/store\//u
	);
	const missingGoogleTradeoff = structuredClone(draft());
	missingGoogleTradeoff.robots_txt_content.after =
		missingGoogleTradeoff.robots_txt_content.after.replace(
			'# Google-Extended se permite conscientemente: aporta grounding y también puede contribuir al entrenamiento futuro de Gemini.\n',
			''
		);
	assert.throws(
		() => finalizeRobotsManifest(missingGoogleTradeoff),
		/must explain the Google-Extended grounding and future Gemini training tradeoff/u
	);
	const source = robotsBrowserFunction({
		baseUrl: first.target_origin,
		manifest: first,
		phase: 'preflight',
	});
	assert.doesNotThrow(() => Function(`return (${source});`));
	assert.doesNotMatch(
		source,
		/fetch\s*\(|(?:page|context)\.request\b|wp\.apiFetch\b|update_option\s*\(|\$wpdb\s*->/iu
	);
	assert.match(source, /PHYSICAL_ROBOTS_TXT_BLOCKS_RANK_MATH_UI/u);
	assert.match(
		source,
		/button\.rank-math-button\.button-primary:visible/u
	);
	assert.match(
		source,
		/\/wp-json\/rankmath\/v1\/updateSettings/u
	);
	assert.doesNotMatch(source, /waitForNavigation/u);
	assert.throws(
		() => parseRobotsPlaywrightResult(JSON.stringify({
			isError: true,
			error: 'Error: RANK_MATH_ROBOTS_FIELD_MISSING',
		})),
		/Playwright browser function failed: Error: RANK_MATH_ROBOTS_FIELD_MISSING/u
	);
});

test('Storebot-only policy opens commerce without changing AI training', () => {
	const manifest = finalizeRobotsManifest(storebotOnlyDraft());
	assert.doesNotThrow(() => validateRobotsManifest(manifest));
	assert.match(
		manifest.robots_txt_content.after,
		/User-agent: Storebot-Google[\s\S]*Content-Signal: search=yes, ai-input=yes, ai-train=yes[\s\S]*Allow: \/wp-json\/wc\/store\//u
	);
	assert.match(
		manifest.robots_txt_content.after,
		/User-agent: \*[\s\S]*Content-Signal: search=yes, ai-input=yes, ai-train=yes[\s\S]*Disallow: \/checkout\//u
	);

	const blockedCheckout = storebotOnlyDraft();
	blockedCheckout.robots_txt_content.after = replaceAgentDirective(
		blockedCheckout.robots_txt_content.after,
		'Storebot-Google',
		'Allow: /',
		'Allow: /\nDisallow: /checkout/'
	);
	assert.throws(
		() => finalizeRobotsManifest(blockedCheckout),
		/robots Storebot-Google policy must not contain Disallow: \/checkout\//u
	);

	const missingStoreApi = storebotOnlyDraft();
	missingStoreApi.robots_txt_content.after = replaceAgentDirective(
		missingStoreApi.robots_txt_content.after,
		'Storebot-Google',
		'Allow: /wp-json/wc/store/',
		null
	);
	assert.throws(
		() => finalizeRobotsManifest(missingStoreApi),
		/robots Storebot-Google policy is missing Allow: \/wp-json\/wc\/store\//u
	);

	const trainingChanged = storebotOnlyDraft();
	trainingChanged.robots_txt_content.after = replaceAgentDirective(
		trainingChanged.robots_txt_content.after,
		'Storebot-Google',
		'Content-Signal: search=yes, ai-input=yes, ai-train=yes',
		'Content-Signal: search=yes, ai-input=yes, ai-train=no'
	);
	assert.throws(
		() => finalizeRobotsManifest(trainingChanged),
		/robots Storebot-Google policy must contain exactly Content-Signal/u
	);

	// Un agente selectivo (GPTBot) reclasifica el contenido como política
	// selective y debe caer por SUS requisitos (falta la ceremonia del
	// tradeoff Google-Extended), no por el sello literal de una versión.
	const mixedPolicy = storebotOnlyDraft();
	mixedPolicy.robots_txt_content.after = mixedPolicy.robots_txt_content.after
		.replace(
			'User-agent: Storebot-Google',
			'User-agent: GPTBot\nDisallow: /\n\nUser-agent: Storebot-Google'
		);
	assert.throws(
		() => finalizeRobotsManifest(mixedPolicy),
		/must explain the Google-Extended grounding and future Gemini training tradeoff/u
	);
});

test('robots generator writes a semantic, local-only 0600 manifest', () => {
	const root = fs.mkdtempSync(
		path.join(repository, '.local-reports/rankmath-robots-generator-test-')
	);
	const before = path.join(root, 'before.txt');
	const after = path.join(root, 'after.txt');
	const output = path.join(root, 'manifest.json');
	const reviewed = draft();
	fs.writeFileSync(before, reviewed.robots_txt_content.before);
	fs.writeFileSync(after, reviewed.robots_txt_content.after);
	try {
		const command = spawnSync(
			process.execPath,
			[
				generator,
				'--environment', 'docker',
				'--before', before,
				'--after', after,
				'--output', output,
			],
			{ encoding: 'utf8' }
		);
		assert.equal(command.status, 0, command.stderr);
		assert.equal(fs.statSync(output).mode & 0o777, 0o600);
		assert.doesNotThrow(() => validateRobotsManifest(
			JSON.parse(fs.readFileSync(output, 'utf8'))
		));
		const overwrite = spawnSync(
			process.execPath,
			[
				generator,
				'--environment', 'docker',
				'--before', before,
				'--after', after,
				'--output', output,
			],
			{ encoding: 'utf8' }
		);
		assert.notEqual(overwrite.status, 0);
		assert.match(overwrite.stderr, /refusing to overwrite/u);
	} finally {
		fs.rmSync(root, { recursive: true, force: true });
	}
});

test('Docker robots manifest is preflight-only', () => {
	const root = fs.mkdtempSync(
		path.join(repository, '.local-reports/rankmath-robots-docker-test-')
	);
	const manifest = finalizeRobotsManifest(draft('docker'));
	const manifestPath = path.join(root, 'manifest.json');
	const checkpoint = path.join(root, 'checkpoint.json');
	fs.writeFileSync(manifestPath, JSON.stringify(manifest), { mode: 0o600 });
	try {
		const result = spawnSync(
			process.execPath,
			[
				runner,
				'--manifest', manifestPath,
				'--base-url', manifest.target_origin,
				'--session', 'akb-robots-docker',
				'--checkpoint', checkpoint,
				'--apply',
				'--confirm-manifest-sha256', manifest.manifest_sha256,
			],
			{ encoding: 'utf8' }
		);
		assert.notEqual(result.status, 0);
		assert.match(
			result.stderr,
			/--apply is available only for production manifests/u
		);
		assert.equal(fs.existsSync(checkpoint), false);
	} finally {
		fs.rmSync(root, { recursive: true, force: true });
	}
});

test('production robots apply is SHA-confirmed and physical-file lock aborts before apply', () => {
	const root = fs.mkdtempSync(
		path.join(repository, '.local-reports/rankmath-robots-test-')
	);
	const bin = path.join(root, 'bin');
	const manifestPath = path.join(root, 'manifest.json');
	const checkpoint = path.join(root, 'checkpoint.json');
	const log = path.join(root, 'calls.log');
	fs.mkdirSync(bin);
	fakeNpx(bin);
	const manifest = finalizeRobotsManifest(draft('production'));
	fs.writeFileSync(manifestPath, JSON.stringify(manifest), { mode: 0o600 });
	const invoke = (extra, env = {}) => spawnSync(
		process.execPath,
		[
			runner,
			'--manifest', manifestPath,
			'--base-url', manifest.target_origin,
			'--session', 'akb-robots-production',
			'--checkpoint', checkpoint,
			...extra,
		],
		{
			encoding: 'utf8',
			env: {
				...process.env,
				...env,
				PATH: `${bin}${path.delimiter}${process.env.PATH}`,
				AKB_ROBOTS_LOG: log,
			},
		}
	);
	try {
		const noSha = invoke(['--apply']);
		assert.notEqual(noSha.status, 0);
		assert.equal(fs.existsSync(log), false);
		const applied = invoke([
			'--apply',
			'--confirm-manifest-sha256',
			manifest.manifest_sha256,
		]);
		assert.equal(applied.status, 0, applied.stderr);
		assert.equal(fs.statSync(checkpoint).mode & 0o777, 0o600);
		assert.deepEqual(
			fs.readFileSync(log, 'utf8').trim().split('\n'),
			['preflight', 'apply']
		);

		fs.rmSync(checkpoint);
		fs.writeFileSync(log, '', { mode: 0o600 });
		const blocked = invoke([
			'--apply',
			'--confirm-manifest-sha256',
			manifest.manifest_sha256,
		], {
			AKB_PHYSICAL_ROBOTS: '1',
		});
		assert.notEqual(blocked.status, 0);
		assert.match(
			blocked.stderr,
			/PHYSICAL_ROBOTS_TXT_BLOCKS_RANK_MATH_UI/u
		);
		assert.deepEqual(
			fs.readFileSync(log, 'utf8').trim().split('\n'),
			['preflight']
		);
		const blockedCheckpoint = JSON.parse(
			fs.readFileSync(checkpoint, 'utf8')
		);
		assert.equal(
			blockedCheckpoint.events.preflight.blocked,
			'PHYSICAL_ROBOTS_TXT_BLOCKS_RANK_MATH_UI'
		);
	} finally {
		fs.rmSync(root, { recursive: true, force: true });
	}
});
