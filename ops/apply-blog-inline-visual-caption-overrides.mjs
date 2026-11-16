#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const moduleDir = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(moduleDir, '..');
const manifestPath = path.join(
	projectRoot,
	'output/blog-seo-inline-visuals-20260726/manifest.json'
);
const overridesPath = path.join(
	projectRoot,
	'.local-reports/seo-blog-score-audit/'
		+ 'inline-visual-caption-overrides.json'
);
const expectedOverridesSha256 =
	'6ab29699e795d7529e92d96f79655e9c3bf56500f9882c68dad5b395bcfd44d7';

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

const overrideBuffer = fs.readFileSync(overridesPath);
if (sha256(overrideBuffer) !== expectedOverridesSha256) {
	throw new Error('Inline visual caption override source drifted');
}
const overrides = JSON.parse(overrideBuffer);
if (
	overrides?.schema_version !== 1
	|| overrides?.override_type
		!== 'akibara_blog_inline_visual_caption_overrides'
	|| !/^[a-f0-9]{64}$/u.test(overrides?.base_manifest_sha256 ?? '')
	|| !/^[a-f0-9]{64}$/u.test(overrides?.target_manifest_sha256 ?? '')
	|| !Array.isArray(overrides?.assets)
	|| overrides.assets.length !== 4
) {
	throw new Error('Inline visual caption override schema is invalid');
}

const manifestBuffer = fs.readFileSync(manifestPath);
const currentHash = sha256(manifestBuffer);
if (currentHash === overrides.target_manifest_sha256) {
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		changed: false,
		manifest: manifestPath,
		manifest_sha256: currentHash,
	}, null, 2)}\n`);
	process.exit(0);
}
if (currentHash !== overrides.base_manifest_sha256) {
	throw new Error(
		`Inline visual manifest drifted: ${currentHash} `
		+ `!= ${overrides.base_manifest_sha256}`
	);
}

const manifest = JSON.parse(manifestBuffer);
for (const override of overrides.assets) {
	const matches = manifest.assets.filter((asset) => (
		asset.post_id === override.post_id
		&& asset.role === override.role
	));
	if (
		matches.length !== 1
		|| matches[0].file !== override.expected_file
		|| matches[0].sha256 !== override.expected_asset_sha256
		|| matches[0].caption !== override.before
		|| typeof override.after !== 'string'
		|| override.after.trim().length < 20
	) {
		throw new Error(
			`Inline visual caption base drifted for `
			+ `${override.post_id}:${override.role}`
		);
	}
	matches[0].caption = override.after;
}

const output = `${JSON.stringify(manifest, null, 2)}\n`;
const targetHash = sha256(output);
if (targetHash !== overrides.target_manifest_sha256) {
	throw new Error(
		`Inline visual caption target drifted: ${targetHash} `
		+ `!= ${overrides.target_manifest_sha256}`
	);
}
const temporary = `${manifestPath}.tmp-${process.pid}`;
fs.writeFileSync(temporary, output, { flag: 'wx', mode: 0o600 });
fs.renameSync(temporary, manifestPath);
fs.chmodSync(manifestPath, 0o600);

process.stdout.write(`${JSON.stringify({
	result: 'PASS',
	changed: true,
	assets: overrides.assets.length,
	manifest: manifestPath,
	manifest_sha256: targetHash,
}, null, 2)}\n`);
