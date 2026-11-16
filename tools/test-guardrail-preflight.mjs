import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtempSync, mkdirSync, copyFileSync, writeFileSync, readFileSync, existsSync, rmSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const commands = ['node', 'python3', 'php', 'composer', 'npm', 'npx', 'opengrep', 'sqlfluff'];

function fixture(run, missing = '') {
	const directory = mkdtempSync(path.join(tmpdir(), 'akb-guardrail-test-'));
	try {
		const bin = path.join(directory, 'bin');
		const log = path.join(directory, 'commands.log');
		mkdirSync(bin);
		mkdirSync(path.join(directory, 'tools'));
		copyFileSync(path.join(root, 'tools/guardrail-ci.sh'), path.join(directory, 'tools/guardrail-ci.sh'));
		for (const name of ['dirname', 'find']) symlinkSync('/usr/bin/' + name, path.join(bin, name));
		const stub = '#!/bin/bash\nprintf "%s %s\\n" "${0##*/}" "$*" >> "$AKB_TEST_LOG"\nif [[ "${0##*/} $*" == "npm audit --audit-level=moderate" ]]; then exit "${AKB_TEST_AUDIT_EXIT:-0}"; fi\n';
		for (const name of commands.filter(name => name !== missing)) writeFileSync(path.join(bin, name), stub, { mode: 0o755 });
		for (const name of ['tools/akibara-business/vendor/bin/phpunit', 'tools/akibara-business/vendor/bin/phpcs', 'vendor/bin/phpcs', 'vendor/bin/phpstan', 'vendor/bin/psalm', 'vendor/bin/rector']) {
			const filename = path.join(directory, name);
			mkdirSync(path.dirname(filename), { recursive: true });
			writeFileSync(filename, stub, { mode: 0o755 });
		}
		for (const name of ['plugins/akibara-business', 'plugins/akibara-search', 'plugins/akibara-cart-checkout-blocks', 'plugins/akibara-rewards', 'themes/akibara-gp', 'mu-plugins']) {
			const tests = path.join(directory, 'public_html/wp-content', name, 'tests');
			mkdirSync(tests, { recursive: true });
			writeFileSync(path.join(tests, 'fixture.test.js'), '');
		}
		const invoke = env => spawnSync('/bin/bash', ['tools/guardrail-ci.sh', '--full'], {
			cwd: directory, encoding: 'utf8', env: { PATH: bin, AKB_TEST_LOG: log, ...env },
		});
		run(invoke, () => existsSync(log) ? readFileSync(log, 'utf8') : '');
	} finally {
		rmSync(directory, { recursive: true, force: true });
	}
}

for (const name of commands) {
	test(`guardrail rejects missing ${name} before running checks`, () => fixture((invoke, log) => {
		const result = invoke();
		assert.equal(result.status, 1, result.stdout + result.stderr);
		assert.ok(result.stderr.includes('Falta herramienta obligatoria de calidad: ' + name));
		assert.equal(log(), '');
	}, name));
}

test('guardrail runs every analyzer and the full suites before synchronization', () => fixture((invoke, log) => {
	const result = invoke();
	assert.equal(result.status, 0, result.stdout + result.stderr);
	const calls = log();
	for (const command of ['composer audit', 'npm audit --audit-level=moderate', 'opengrep scan', 'sqlfluff lint', 'phpunit -c', 'node --test', 'sync-notebooklm.mjs']) assert.ok(calls.includes(command), command);
	assert.ok(calls.includes('opengrep scan --error --taint-intrafile '));
	assert.ok(calls.split('\n').some(line => line.startsWith('node --test ') && line.endsWith('/test-woocommerce-hook-metadata.mjs')), 'WooCommerce metadata probes run unconditionally');
	assert.ok(calls.indexOf('sqlfluff lint') < calls.indexOf('sync-notebooklm.mjs'));
}));

test('npm audit network failure stops quality checks and prevents synchronization', () => fixture((invoke, log) => {
	const result = invoke({ AKB_TEST_AUDIT_EXIT: '42' });
	assert.equal(result.status, 42, result.stdout + result.stderr);
	assert.ok(log().includes('npm audit --audit-level=moderate'));
	assert.ok(!log().includes('sync-notebooklm.mjs'));
}));

test('real default SAST rejects an untracked PHP eval canary and accepts the safe control with unchanged repository rules', () => {
	const directory = mkdtempSync(path.join(tmpdir(), 'akb-sast-untracked-'));
	try {
		const runtime = 'public_html/wp-content/plugins/akibara-business/src';
		mkdirSync(path.join(directory, runtime), { recursive: true });
		mkdirSync(path.join(directory, '.semgrep'));
		copyFileSync(path.join(root, '.semgrep/wordpress-security.yml'), path.join(directory, '.semgrep/wordpress-security.yml'));
		copyFileSync(path.join(root, '.semgrepignore'), path.join(directory, '.semgrepignore'));
		writeFileSync(path.join(directory, runtime, 'Safe.php'), '<?php echo "safe control";\n');
		for (const args of [['init', '--quiet'], ['add', '.']]) {
			const git = spawnSync('git', args, { cwd: directory, encoding: 'utf8' });
			assert.equal(git.status, 0, git.stdout + git.stderr);
		}
		const canary = runtime + '/UntrackedCanary.php';
		writeFileSync(path.join(directory, canary), '<?php eval($_GET["canary"]);\n');
		const status = spawnSync('git', ['status', '--porcelain', '--', canary], { cwd: directory, encoding: 'utf8' });
		assert.equal(status.status, 0, status.stderr);
		assert.equal(status.stdout.trim(), '?? ' + canary);
		const scan = extra => {
			const result = spawnSync('opengrep', ['scan', '--error', ...extra, '--taint-intrafile', '-f', '.semgrep/', '--json', runtime], {
				cwd: directory, encoding: 'utf8', timeout: 60000, maxBuffer: 8 * 1024 * 1024
			});
			assert.equal(result.error, undefined, result.error?.message);
			return { ...result, parsed: JSON.parse(result.stdout) };
		};
		const rejected = scan([]);
		assert.equal(rejected.status, 1, rejected.stderr);
		assert.ok(rejected.parsed.paths.scanned.some(filename => filename.endsWith('UntrackedCanary.php')));
		assert.ok(rejected.parsed.results.some(finding => finding.path.endsWith('UntrackedCanary.php') && finding.check_id.endsWith('wp-direct-eval-execution')));
		rmSync(path.join(directory, canary));
		const safe = scan([]);
		assert.equal(safe.status, 0, safe.stderr);
		assert.deepEqual(safe.parsed.results, []);
		assert.ok(safe.parsed.paths.scanned.some(filename => filename.endsWith('Safe.php')));
		for (const filename of ['.semgrep/wordpress-security.yml', '.semgrepignore']) {
			assert.equal(readFileSync(path.join(directory, filename), 'utf8'), readFileSync(path.join(root, filename), 'utf8'));
		}
	} finally {
		rmSync(directory, { recursive: true, force: true });
	}
});
