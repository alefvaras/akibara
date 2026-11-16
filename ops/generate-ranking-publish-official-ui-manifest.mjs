#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import {
	buildRankingPublishManifest,
	rankingPublishSha256,
} from './lib/ranking-publish-official-ui-manifest.mjs';

function fail(message) {
	throw new Error(`Ranking publish manifest generator failed: ${message}`);
}

function usage() {
	process.stdout.write(`Usage:
  node ops/generate-ranking-publish-official-ui-manifest.mjs \\
    --production-snapshot .local-reports/.../production-snapshot.json \\
    --production-structure .local-reports/.../production-structure.json \\
    --output .local-reports/.../ranking-publish-official-ui-manifest.json

The output is deterministic, mode 0600, production-only and describes exactly
one official-UI transition: post 27460 from draft to publish. A .sha256 sidecar
is written beside the manifest. Existing outputs are never overwritten.
`);
}

function parseArgs(argv) {
	const parsed = {
		productionSnapshot: '',
		productionStructure: '',
		output: '',
	};
	const names = {
		'--production-snapshot': 'productionSnapshot',
		'--production-structure': 'productionStructure',
		'--output': 'output',
	};
	for (let index = 0; index < argv.length; index += 1) {
		const argument = argv[index];
		if (argument === '--help' || argument === '-h') {
			usage();
			process.exit(0);
		}
		const key = names[argument];
		if (!key) fail(`unknown argument ${argument}`);
		if (parsed[key]) fail(`duplicate argument ${argument}`);
		index += 1;
		if (index >= argv.length || argv[index].startsWith('--')) {
			fail(`${argument} requires a value`);
		}
		parsed[key] = argv[index];
	}
	for (const [key, value] of Object.entries(parsed)) {
		if (!value) fail(`missing ${key}`);
	}
	return parsed;
}

function repositoryRoot() {
	return path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
}

function isInside(file, directory) {
	const relative = path.relative(directory, file);
	return relative !== ''
		&& relative !== '..'
		&& !relative.startsWith(`..${path.sep}`)
		&& !path.isAbsolute(relative);
}

function assertNoSymlinkTraversal(file, root, label) {
	let current = file;
	while (current !== root && current !== path.dirname(current)) {
		if (
			fs.existsSync(current)
			&& fs.lstatSync(current).isSymbolicLink()
		) {
			fail(`${label} may not traverse symbolic links`);
		}
		current = path.dirname(current);
	}
}

function resolveUnderReports(value, label, { mustExist }) {
	const repository = repositoryRoot();
	const root = path.join(repository, '.local-reports');
	const file = path.resolve(value);
	if (!isInside(file, root)) {
		fail(`${label} must remain under ${root}`);
	}
	assertNoSymlinkTraversal(file, root, label);
	if (mustExist) {
		if (!fs.existsSync(file)) fail(`${label} does not exist`);
		const stats = fs.lstatSync(file);
		if (!stats.isFile() || stats.isSymbolicLink()) {
			fail(`${label} must be a regular non-symlink file`);
		}
	}
	return file;
}

function readJsonCapture(value, label) {
	const file = resolveUnderReports(value, label, { mustExist: true });
	const bytes = fs.readFileSync(file);
	let parsed;
	try {
		parsed = JSON.parse(bytes.toString('utf8'));
	} catch (error) {
		fail(`${label} is not valid JSON: ${error.message}`);
	}
	return {
		file,
		parsed,
		sha256: rankingPublishSha256(bytes),
	};
}

function writeExclusive0600(file, content) {
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	fs.writeFileSync(file, content, { mode: 0o600, flag: 'wx' });
	fs.chmodSync(file, 0o600);
}

function main() {
	const args = parseArgs(process.argv.slice(2));
	const snapshot = readJsonCapture(
		args.productionSnapshot,
		'--production-snapshot',
	);
	const structure = readJsonCapture(
		args.productionStructure,
		'--production-structure',
	);
	const output = resolveUnderReports(
		args.output,
		'--output',
		{ mustExist: false },
	);
	const sidecar = `${output}.sha256`;
	if (fs.existsSync(output) || fs.existsSync(sidecar)) {
		fail('output or hash sidecar already exists');
	}
	const manifest = buildRankingPublishManifest({
		productionSnapshot: snapshot.parsed,
		productionSnapshotSha256: snapshot.sha256,
		productionStructure: structure.parsed,
		productionStructureSha256: structure.sha256,
	});
	const serializedManifest = `${JSON.stringify(manifest, null, 2)}\n`;
	const manifestFileSha256 = rankingPublishSha256(serializedManifest);
	let manifestWritten = false;
	try {
		writeExclusive0600(
			output,
			serializedManifest,
		);
		manifestWritten = true;
		writeExclusive0600(
			sidecar,
			`${manifestFileSha256}  ${path.basename(output)}\n`,
		);
	} catch (error) {
		if (manifestWritten && !fs.existsSync(sidecar)) {
			fs.rmSync(output, { force: true });
		}
		throw error;
	}
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		manifest: output,
		manifest_sha256: manifest.manifest_sha256,
		manifest_file_sha256: manifestFileSha256,
		hash_sidecar: sidecar,
		post_id: manifest.post.post_id,
		transition: `${manifest.post.before_status}->${manifest.post.after_status}`,
	}, null, 2)}\n`);
}

try {
	main();
} catch (error) {
	process.stderr.write(`${error.message}\n`);
	process.exitCode = 1;
}
