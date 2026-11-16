#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import {
	finalizeRobotsManifest,
} from './lib/rankmath-robots-official-ui-manifest.mjs';

function fail(message) {
	throw new Error(`Rank Math robots manifest generation failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function regularInput(value, label) {
	if (!value) fail(`missing ${label}`);
	const file = path.resolve(value);
	if (
		!fs.existsSync(file)
		|| !fs.lstatSync(file).isFile()
		|| fs.lstatSync(file).isSymbolicLink()
	) {
		fail(`${label} must be a regular non-symlink file`);
	}
	return file;
}

function localOutput(value) {
	if (!value) fail('missing --output');
	const output = path.resolve(value);
	const repository = path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
	const roots = ['.local-reports', '.local-checkpoints']
		.map((name) => path.join(repository, name));
	const inside = roots.some((root) => {
		const relative = path.relative(root, output);
		return relative && !relative.startsWith('..') && !path.isAbsolute(relative);
	});
	if (!inside) fail('--output must remain under a local report directory');
	let cursor = output;
	while (cursor !== repository && cursor !== path.dirname(cursor)) {
		if (fs.existsSync(cursor) && fs.lstatSync(cursor).isSymbolicLink()) {
			fail('--output may not traverse symlinks');
		}
		cursor = path.dirname(cursor);
	}
	if (fs.existsSync(output)) fail('refusing to overwrite --output');
	return output;
}

function writeAtomic0600(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		mode: 0o600,
		flag: 'wx',
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

function main() {
	const environment = option('--environment');
	if (!['docker', 'production'].includes(environment)) {
		fail('--environment must be docker or production');
	}
	const beforeFile = regularInput(option('--before'), '--before');
	const afterFile = regularInput(option('--after'), '--after');
	const output = localOutput(option('--output'));
	const targetOrigin = environment === 'docker'
		? 'http://localhost:8080'
		: 'https://akibara.cl';
	const manifest = finalizeRobotsManifest({
		schema_version: 1,
		manifest_type: 'akibara_rankmath_robots_official_ui',
		environment,
		target_origin: targetOrigin,
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
			before: fs.readFileSync(beforeFile, 'utf8'),
			after: fs.readFileSync(afterFile, 'utf8'),
		},
	});
	writeAtomic0600(output, manifest);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		environment,
		manifest_sha256: manifest.manifest_sha256,
		after_sha256: manifest.robots_txt_content.after_sha256,
		output,
	}, null, 2)}\n`);
}

try {
	main();
} catch (error) {
	process.stderr.write(`${error.message}\n`);
	process.exitCode = 1;
}
