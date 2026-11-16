import assert from 'node:assert/strict';
import { test } from 'node:test';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
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
		if (Array.isArray(options.profiles)) {
			mkdirSync(path.join(directory, 'home/.notebooklm/profiles'), { recursive: true });
			for (const profileName of options.profiles) {
				mkdirSync(path.join(directory, 'home/.notebooklm/profiles', profileName), { recursive: true });
			}
		}
		for (const script of ['sync-notebooklm-sources.mjs', 'sync-notebooklm.mjs']) {
			copyFileSync(path.join(root, 'tools', script), path.join(directory, 'tools', script));
		}
		writeFileSync(path.join(directory, 'tools/generate-complete-codebase-md.mjs'), 'export function generateCodebaseVolumes() {}\n');
		writeFileSync(path.join(directory, 'notbooklm', title), document);
		const statePath = path.join(directory, '.local-quality/notebooklm-sync-state.json');
		const legacyStatePath = path.join(directory, 'tools/.notebooklm-sync-state.json');
		mkdirSync(path.dirname(statePath), { recursive: true });
		if (options.legacyInitialState) {
			writeFileSync(legacyStatePath, JSON.stringify(options.legacyInitialState));
		}
		if (options.initialState) {
			writeFileSync(statePath, JSON.stringify(options.initialState));
		} else if (options.previousHash) {
			writeFileSync(statePath, JSON.stringify({ [notebook]: { [title]: options.previousHash } }));
		}
		const configPath = path.join(directory, 'cli-config.json');
		const callsPath = path.join(directory, 'cli-calls.jsonl');
		const remotePath = path.join(directory, 'cli-remote.json');
		writeFileSync(configPath, JSON.stringify(options));
		writeFileSync(
			remotePath,
			JSON.stringify({
				sources: options.initialSources || [],
				sourcesByNotebook: options.initialSourcesByNotebook || {},
				listCount: 0,
			})
		);
		writeFileSync(path.join(directory, 'bin/notebooklm'), `#!${process.execPath}
const fs = require('node:fs');
const crypto = require('node:crypto');
const config = JSON.parse(fs.readFileSync(${JSON.stringify(configPath)}, 'utf8'));
const remotePath = ${JSON.stringify(remotePath)};
const remote = JSON.parse(fs.readFileSync(remotePath, 'utf8'));
const rawArgs = process.argv.slice(2);
const args = [...rawArgs];
let activeProfile = 'default';
if (args[0] === '-p') {
  activeProfile = args[1];
  args.splice(0, 2);
}
fs.appendFileSync(${JSON.stringify(callsPath)}, JSON.stringify(config.recordProfile ? rawArgs : args) + '\\n');
function reply(value, status = 0) { console.log(JSON.stringify(value)); process.exit(status); }
function save() { fs.writeFileSync(remotePath, JSON.stringify(remote)); }
function getNbId() {
  const idx = args.indexOf('-n');
  return idx >= 0 ? args[idx + 1] : ${JSON.stringify(notebook)};
}
function getSources(nbId) {
  if (config.notebooksByProfile) {
    if (!remote.sourcesByNotebook[nbId]) remote.sourcesByNotebook[nbId] = [];
    return remote.sourcesByNotebook[nbId];
  }
  return remote.sources;
}
function setSources(nbId, next) {
  if (config.notebooksByProfile) remote.sourcesByNotebook[nbId] = next;
  else remote.sources = next;
}
const profileAuth = config.authByProfile?.[activeProfile] || config.auth;
if (profileAuth) reply({ error: true, code: 'AUTH_ERROR', message: 'Authentication expired' }, profileAuth === 'exit' ? 1 : 0);
if (args[0] === 'list') {
  const notebooks = config.notebooksByProfile?.[activeProfile] || [{ id: ${JSON.stringify(notebook)}, title: ${JSON.stringify(notebook)} }];
  reply({ notebooks });
}
if (args[0] === 'source' && args[1] === 'list') {
  const nbId = getNbId();
  remote.listCount++;
  save();
  if (remote.listCount > 1 && config.finalError) reply({ error: true, code: 'AUTH_ERROR' });
  let sources = getSources(nbId);
  if (remote.listCount > 1 && config.finalSources) sources = config.finalSources;
  if (remote.listCount > 1 && config.finalStatus) sources = sources.map(source => ({ ...source, status: config.finalStatus }));
  reply({ sources });
}
if (args[0] === 'source' && args[1] === 'add') {
  if (config.addFails) reply({ error: true, code: 'SERVER_ERROR' }, 1);
  const nbId = getNbId();
  const filePath = args[4];
  const sourceTitle = args[args.indexOf('--title') + 1];
  remote.uploadedHash = crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
  const next = [...getSources(nbId), { id: 'uploaded-' + nbId, title: sourceTitle, status: 'ready', type: 'file' }];
  setSources(nbId, next);
  save();
  if (config.mutateLocalAfterUpload) fs.writeFileSync(filePath, 'Changed during upload');
  reply({ source: { id: 'uploaded-' + nbId } });
}
if (args[0] === 'source' && args[1] === 'delete') {
  const nbId = getNbId();
  setSources(nbId, getSources(nbId).filter(source => source.id !== args.at(-1)));
  save();
  reply({ success: true });
}
reply({ error: true, code: 'UNEXPECTED_COMMAND', args }, 1);
`, { mode: 0o755 });
		const invoke = (wrapper = false, extraArgs = [], envOverrides = null) => {
			const defaultEnv = options.omitDefaultEnv
				? {}
				: { NOTEBOOKLM_PROFILE: 'fixture-profile', NOTEBOOKLM_NOTEBOOK: notebook };
			return spawnSync(process.execPath, [path.join(directory, 'tools', wrapper ? 'sync-notebooklm.mjs' : 'sync-notebooklm-sources.mjs'), ...extraArgs], {
				cwd: directory,
				encoding: 'utf8',
				timeout: 20000,
				env: {
					PATH: path.join(directory, 'bin') + path.delimiter + path.dirname(process.execPath) + path.delimiter + '/usr/bin:/bin',
					HOME: path.join(directory, 'home'),
					...defaultEnv,
					...(envOverrides || {}),
				},
			});
		};
		run({
			invoke,
			calls: () => readFileSync(callsPath, 'utf8').trim().split('\n').map(line => JSON.parse(line)),
			remote: () => JSON.parse(readFileSync(remotePath, 'utf8')),
			state: () => existsSync(statePath) ? JSON.parse(readFileSync(statePath, 'utf8')) : {},
			stateFilePath: statePath,
			legacyStateFilePath: legacyStatePath,
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

const PRIMARY_NB = '06cc0698-c80f-4ac9-bc38-d8d15a96a17f';
const PRIMARY_NOTEBOOKS = {
	'ale-fvaras': [{ id: PRIMARY_NB, title: 'akibara' }],
};

test('primary profile auth failure aborts fail-closed without persisting state', () => fixture(({ invoke, calls, state }) => {
	const result = invoke();
	assertRejected(result);
	const commands = calls();
	assert.equal(commands.length, 1);
	assert.deepEqual(commands[0], ['-p', 'ale-fvaras', 'list', '--json']);
	assert.deepEqual(state(), {});
}, {
	omitDefaultEnv: true,
	recordProfile: true,
	profiles: ['ale-fvaras', 'default'],
	notebooksByProfile: PRIMARY_NOTEBOOKS,
	authByProfile: { 'ale-fvaras': 'json' },
}));

for (const mode of ['default', 'flag', 'env']) {
	test(`missing primary profile ale-fvaras aborts fail-closed when profiles dir exists (${mode})`, () => fixture(({ invoke, state }) => {
		const extraArgs = mode === 'flag' ? ['--strict'] : [];
		const envOverrides = mode === 'env' ? { NOTEBOOKLM_STRICT: '1' } : {};
		const result = invoke(false, extraArgs, envOverrides);
		assertRejected(result);
		assert.ok(result.stderr.includes('ale-fvaras'), result.stderr);
		assert.deepEqual(state(), {});
	}, {
		omitDefaultEnv: true,
		profiles: ['other-profile'],
		notebooksByProfile: PRIMARY_NOTEBOOKS,
	}));
}

test('explicit NOTEBOOKLM_PROFILE enforces fail-closed on AUTH_ERROR', () => fixture(({ invoke, state }) => {
	const result = invoke(false, [], { NOTEBOOKLM_PROFILE: 'custom-profile' });
	assertRejected(result);
	assert.deepEqual(state(), {});
}, {
	omitDefaultEnv: true,
	profiles: ['ale-fvaras', 'custom-profile'],
	notebooksByProfile: {
		...PRIMARY_NOTEBOOKS,
		'custom-profile': [{ id: 'custom-nb', title: 'custom' }],
	},
	authByProfile: { 'custom-profile': 'json' },
}));

test('primary profile syncs only ale-fvaras and isolates hashes in canonical notebook UUID', () => fixture(({ invoke, calls, state }) => {
	const result = invoke(true);
	assert.equal(result.status, 0, result.stdout + result.stderr);
	assert.ok(result.stdout.includes('SINCRONIZACIÓN EXITOSA'));
	const usedProfiles = calls()
		.filter(args => args[2] === 'list')
		.map(args => args[1]);
	assert.deepEqual(usedProfiles, ['ale-fvaras']);
	const nextState = state();
	assert.equal(nextState[PRIMARY_NB][title], hash(document));
	assert.equal(nextState._profiles['ale-fvaras'].status, 'SYNCED');
	assert.equal(nextState._profiles['ale-fvaras'].email, 'ale.fvaras@gmail.com');
	assert.equal(Object.keys(nextState._profiles).length, 1);
}, {
	omitDefaultEnv: true,
	recordProfile: true,
	profiles: ['ale-fvaras', 'other-profile'],
	notebooksByProfile: PRIMARY_NOTEBOOKS,
}));

test('migrates legacy tools/.notebooklm-sync-state.json into .local-quality without re-uploading matching sources', () => fixture(({ invoke, calls, state, stateFilePath, legacyStateFilePath }) => {
	const result = invoke();
	assert.equal(result.status, 0, result.stdout + result.stderr);
	assert.equal(calls().some(args => args[0] === 'source' && args[1] === 'add'), false);
	assert.equal(existsSync(stateFilePath), true);
	assert.equal(state()[notebook][title], hash(document));
	assert.equal(readFileSync(legacyStateFilePath, 'utf8'), JSON.stringify({ [notebook]: { [title]: hash(document) } }));
}, {
	initialSources: [ready],
	legacyInitialState: { [notebook]: { [title]: hash(document) } },
}));

test('steady-state sync does not rewrite state or touch tools/ when hashes and profile status are unchanged', () => fixture(({ invoke, state, stateFilePath }) => {
	const first = invoke();
	assert.equal(first.status, 0, first.stdout + first.stderr);
	const firstContent = readFileSync(stateFilePath, 'utf8');
	const firstMtime = statSync(stateFilePath).mtimeMs;
	const firstUpdatedAt = state()._profiles['ale-fvaras'].updatedAt;

	const second = invoke();
	assert.equal(second.status, 0, second.stdout + second.stderr);
	assert.equal(readFileSync(stateFilePath, 'utf8'), firstContent);
	assert.equal(statSync(stateFilePath).mtimeMs, firstMtime);
	assert.equal(state()._profiles['ale-fvaras'].updatedAt, firstUpdatedAt);
}, {
	omitDefaultEnv: true,
	profiles: ['ale-fvaras'],
	notebooksByProfile: { 'ale-fvaras': [{ id: PRIMARY_NB, title: 'akibara' }] },
	initialSourcesByNotebook: { [PRIMARY_NB]: [ready] },
	initialState: { [PRIMARY_NB]: { [title]: hash(document) } },
}));
