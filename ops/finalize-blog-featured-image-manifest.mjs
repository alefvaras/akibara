#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import {
	finalizeFeaturedImageManifest,
} from './lib/blog-featured-image-official-ui-manifest.mjs';

function option(name) {
	const index = process.argv.indexOf(name);
	return index === -1 ? undefined : process.argv[index + 1];
}

function fail(message) {
	throw new Error(`Featured image manifest finalization failed: ${message}`);
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
	const relative = path.relative(
		path.join(repository, '.local-reports'),
		output
	);
	if (
		relative === ''
		|| relative === '..'
		|| relative.startsWith(`..${path.sep}`)
		|| path.isAbsolute(relative)
		|| fs.existsSync(output)
	) {
		fail('output must be a new file under .local-reports');
	}
	const manifest = finalizeFeaturedImageManifest(
		JSON.parse(fs.readFileSync(draft, 'utf8'))
	);
	fs.mkdirSync(path.dirname(output), { recursive: true, mode: 0o700 });
	const temporary = `${output}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(manifest, null, 2)}\n`, {
		flag: 'wx',
		mode: 0o600,
	});
	fs.renameSync(temporary, output);
	fs.chmodSync(output, 0o600);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		environment: manifest.environment,
		posts: manifest.posts.length,
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
