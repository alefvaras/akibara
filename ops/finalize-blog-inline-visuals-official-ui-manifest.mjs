#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import {
	finalizeInlineVisualManifest,
} from './lib/blog-inline-visuals-official-ui-manifest.mjs';

function fail(message) {
	throw new Error(`Inline visual manifest finalization failed: ${message}`);
}

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function inside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== ''
		&& relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function main() {
	const draftValue = option('--draft');
	const outputValue = option('--output');
	if (!draftValue || !outputValue) fail('missing --draft or --output');
	const repository = path.resolve(
		path.dirname(fileURLToPath(import.meta.url)),
		'..'
	);
	const draft = path.resolve(draftValue);
	const output = path.resolve(outputValue);
	if (
		!fs.existsSync(draft)
		|| !fs.lstatSync(draft).isFile()
		|| fs.lstatSync(draft).isSymbolicLink()
	) {
		fail('draft must be a regular local file');
	}
	if (
		!['.local-reports', '.local-checkpoints'].some((directory) => (
			inside(output, path.join(repository, directory))
		))
		|| fs.existsSync(output)
	) {
		fail('output must be new and remain in a local evidence directory');
	}
	let value;
	try {
		value = JSON.parse(fs.readFileSync(draft, 'utf8'));
	} catch {
		fail('draft is not valid JSON');
	}
	const manifest = finalizeInlineVisualManifest(value);
	fs.mkdirSync(path.dirname(output), { recursive: true, mode: 0o700 });
	const temporary = `${output}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(manifest, null, 2)}\n`, {
		mode: 0o600,
		flag: 'wx',
	});
	fs.renameSync(temporary, output);
	fs.chmodSync(output, 0o600);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		environment: manifest.environment,
		posts: manifest.posts.length,
		assets: manifest.assets.length,
		manifest_sha256: manifest.manifest_sha256,
		output,
	}, null, 2)}\n`);
}

try {
	main();
} catch (error) {
	process.stderr.write(`${error.message}\n`);
	process.exitCode = 1;
}

