import assert from 'node:assert/strict';
import { test } from 'node:test';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import crypto from 'node:crypto';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const title = 'fixture.md';
const notebook = 'fixture-notebook';
const document = '# Fuente sintética\n';
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const ready = { id: 'existing', title, status: 'ready', type: 'file' };

function fixture(run, options = {}) {
	const directory = mkdtempSync(path.join(tmpdir(), 'akb-notebooklm-test-'));
	try {
		for (const folder of ['tools', 'bin', 'notbooklm', 'home']) mkdirSync(path.join(directory, folder));
		for (const script of ['sync-notebooklm-sources.mjs', 'sync-notebooklm.mjs']) {
			copyFileSync(path.join(root, 'tools', script), path.join(directory, 'tools', script));
		}
		writeFileSync(path.join(directory, 'tools/generate-complete-codebase-md.mjs'), 'export function generateCodebaseVolumes() {}\n');
		writeFileSync(path.join(directory, 'notbooklm', title), document);
		const statePath = path.join(directory, 'tools/.notebooklm-sync-state.json');
		if (options.previousHash) writeFileSync(statePath, JSON.stringify({ [notebook]: { [title]: options.previousHash } }));
		const configPath = path.join(directory, 'cli-config.json');
		const callsPath = path.join(directory, 'cli-calls.jsonl');
		const remotePath = path.join(directory, 'cli-remote.json');
		writeFileSync(configPath, JSON.stringify(options));
		writeFileSync(remotePath, JSON.stringify({ sources: options.initialSources || [], listCount: 0 }));
		writeFileSync(path.join(directory, 'bin/notebooklm'), `#!${process.execPath}
const fs = require('node:fs');
const crypto = require('node:crypto');
const config = JSON.parse(fs.readFileSync(${JSON.stringify(configPath)}, 'utf8'));
const remotePath = ${JSON.stringify(remotePath)};
const remote = JSON.parse(fs.readFileSync(remotePath, 'utf8'));
const args = process.argv.slice(2);
if (args[0] === '-p') args.splice(0, 2);
fs.appendFileSync(${JSON.stringify(callsPath)}, JSON.stringify(args) + '\\n');
function reply(value, status = 0) { console.log(JSON.stringify(value)); process.exit(status); }
function save() { fs.writeFileSync(remotePath, JSON.stringify(remote)); }
if (config.auth) reply({ error: true, code: 'AUTH_ERROR' }, config.auth === 'exit' ? 1 : 0);
if (args[0] === 'list') reply({ notebooks: [{ id: ${JSON.stringify(notebook)}, title: ${JSON.stringify(notebook)} }] });
if (args[0] === 'source' && args[1] === 'list') {
  remote.listCount++;
  save();
  if (remote.listCount > 1 && config.finalError) reply({ error: true, code: 'AUTH_ERROR' });
  let sources = remote.sources;
  if (remote.listCount > 1 && config.finalSources) sources = config.finalSources;
  if (remote.listCount > 1 && config.finalStatus) sources = sources.map(source => ({ ...source, status: config.finalStatus }));
  reply({ sources });
}
if (args[0] === 'source' && args[1] === 'add') {
  if (config.addFails) reply({ error: true, code: 'SERVER_ERROR' }, 1);
  const filePath = args[4];
  const sourceTitle = args[args.indexOf('--title') + 1];
  remote.uploadedHash = crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
  remote.sources.push({ id: 'uploaded', title: sourceTitle, status: 'ready', type: 'file' });
  save();
  if (config.mutateLocalAfterUpload) fs.writeFileSync(filePath, 'Changed during upload');
  reply({ source: { id: 'uploaded' } });
}
if (args[0] === 'source' && args[1] === 'delete') {
  remote.sources = remote.sources.filter(source => source.id !== args.at(-1));
  save();
  reply({ success: true });
}
reply({ error: true, code: 'UNEXPECTED_COMMAND', args }, 1);
`, { mode: 0o755 });
		const invoke = (wrapper = false) => spawnSync(process.execPath, [path.join(directory, 'tools', wrapper ? 'sync-notebooklm.mjs' : 'sync-notebooklm-sources.mjs')], {
			cwd: directory,
			encoding: 'utf8',
			timeout: 20000,
			env: {
				PATH: path.join(directory, 'bin') + path.delimiter + path.dirname(process.execPath) + path.delimiter + '/usr/bin:/bin',
				HOME: path.join(directory, 'home'),
				NOTEBOOKLM_PROFILE: 'fixture-profile',
				NOTEBOOKLM_NOTEBOOK: notebook,
			},
		});
		run({
			invoke,
			calls: () => readFileSync(callsPath, 'utf8').trim().split('\n').map(line => JSON.parse(line)),
			remote: () => JSON.parse(readFileSync(remotePath, 'utf8')),
			state: () => existsSync(statePath) ? JSON.parse(readFileSync(statePath, 'utf8')) : {},
		});
	} finally {
		rmSync(directory, { recursive: true, force: true });
	}
}

function assertRejected(result) {
	assert.notEqual(result.status, 0, result.stdout + result.stderr);
	assert.ok(!result.stdout.includes('COMPLETADO EXITOSAMENTE'), result.stdout);
	assert.ok(!result.stdout.includes('SINCRONIZACIÓN EXITOSA'), result.stdout);
}

for (const auth of ['exit', 'json']) {
	test(`authentication failure (${auth}) aborts without creating a notebook or reporting success`, () => fixture(({ invoke, calls, state }) => {
		const result = invoke();
		assertRejected(result);
		assert.equal(calls().length, 1);
		assert.equal(calls()[0][0], 'list');
		assert.deepEqual(state(), {});
	}, { auth }));
}

test('an uploaded source is acknowledged only after a second ready listing and its local hash is persisted', () => fixture(({ invoke, calls, remote, state }) => {
	const result = invoke();
	assert.equal(result.status, 0, result.stdout + result.stderr);
	assert.equal(remote().uploadedHash, hash(document));
	assert.equal(state()[notebook][title], hash(document));
	const commands = calls();
	assert.equal(commands.filter(args => args[0] === 'source' && args[1] === 'list').length, 2);
	assert.equal(commands.at(-1)[1], 'list');
	assert.ok(result.stdout.includes('COMPLETADO EXITOSAMENTE'));
}));

for (const finalStatus of ['preparing', 'processing', 'failed', 'error']) {
	test(`a source left ${finalStatus} is rejected and does not persist an acknowledged hash`, () => fixture(({ invoke, state }) => {
		assertRejected(invoke());
		assert.deepEqual(state(), {});
	}, { finalStatus }));
}

test('two ready copies after an upload cannot be reported as synchronized', () => fixture(({ invoke, state }) => {
	assertRejected(invoke());
	assert.deepEqual(state(), {});
}, { finalSources: [ready, { ...ready, id: 'duplicate' }] }));

test('a missing remote source is rejected despite a successful upload command', () => fixture(({ invoke, state }) => {
	assertRejected(invoke());
	assert.deepEqual(state(), {});
}, { finalSources: [] }));

test('an error JSON in the final listing cannot pass as an empty list', () => fixture(({ invoke, state }) => {
	assertRejected(invoke());
	assert.deepEqual(state(), {});
}, { finalError: true }));

test('a source changed locally during upload is not acknowledged', () => fixture(({ invoke, state }) => {
	assertRejected(invoke());
	assert.deepEqual(state(), {});
}, { mutateLocalAfterUpload: true }));

test('an old source is neither accepted as a failed new upload nor deleted', () => fixture(({ invoke, calls, remote, state }) => {
	assertRejected(invoke());
	assert.ok(!calls().some(args => args[0] === 'source' && args[1] === 'delete'));
	assert.deepEqual(remote().sources, [ready]);
	assert.equal(state()[notebook][title], hash('old content'));
}, { initialSources: [ready], previousHash: hash('old content'), addFails: true }));

test('the top-level pipeline propagates authentication errors instead of printing global success', () => fixture(({ invoke }) => {
	assertRejected(invoke(true));
}, { auth: 'exit' }));

test('the top-level pipeline prints success only after its source child verifies readiness', () => fixture(({ invoke, remote }) => {
	const result = invoke(true);
	assert.equal(result.status, 0, result.stdout + result.stderr);
	assert.ok(result.stdout.includes('SINCRONIZACIÓN EXITOSA'));
	assert.equal(remote().listCount, 2);
}));
