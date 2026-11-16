#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import {
	readLiveScopedRows,
	validateManifestAgainstLive,
} from './rankmath-shipping-copy-manifest-lib.mjs';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.dirname(scriptDir);

function optionValue(name, fallback) {
	const index = process.argv.indexOf(name);
	return index === -1 ? fallback : process.argv[index + 1];
}

const manifestPath = path.resolve(
	optionValue('--manifest', path.join(scriptDir, 'rankmath-shipping-copy-ui-manifest.json'))
);
const outputPath = path.resolve(
	optionValue('--output', path.join(scriptDir, 'rankmath-shipping-copy-ui-validation.json'))
);
const expectedState = optionValue('--expect', 'either');
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const liveRows = readLiveScopedRows({ repoRoot });
const validation = validateManifestAgainstLive(manifest, liveRows, expectedState);

fs.writeFileSync(outputPath, `${JSON.stringify(validation, null, 2)}\n`);
console.log(JSON.stringify(validation, null, 2));

if (validation.result !== 'PASS') {
	process.exitCode = 1;
}
