import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const analyzer = path.join(root, 'tools/guardrail-ast-hygiene.php');
function fixture(run) {
  const directory = mkdtempSync(path.join(tmpdir(), 'akb-delivery-'));
  try { return run(directory); } finally { rmSync(directory, { recursive: true, force: true }); }
}
function scan(source, flags = []) {
  return fixture(directory => {
    const filename = path.join(directory, 'fixture.php');
    writeFileSync(filename, source);
    return spawnSync('php', [analyzer, `--path=${filename}`, ...flags], { cwd: root, encoding: 'utf8' });
  });
}

test('parse errors block the AST gate even without a fail flag', () => {
  const result = scan('<?php function broken( {');
  assert.equal(result.status, 1);
  assert.match(result.stderr, /SCAN_ERROR/);
});
test('an unknown input path cannot produce a successful audit', () => fixture(directory => {
  const result = spawnSync('php', [analyzer, `--path=${directory}/missing.php`], { encoding: 'utf8' });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /Ruta inexistente/);
}));
test('an empty input cannot produce a successful audit', () => fixture(directory => {
  const result = spawnSync('php', [analyzer, '--path', directory], { encoding: 'utf8' });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /ningún archivo PHP/);
}));
test('unused foreach keys block the gate', () => {
  const result = scan('<?php function f($items) { foreach ($items as $key => $value) { echo $value; } }', ['--fail-on-error']);
  assert.equal(result.status, 1);
  assert.match(result.stdout, /UNUSED_FOREACH_KEY/);
});
test('a direct stock SQL write blocks the gate', () => {
  const result = scan('<?php function f() { return "UPDATE wp_postmeta SET meta_value = \'instock\' WHERE meta_key = \'_stock_status\'"; }', ['--fail-on-error']);
  assert.equal(result.status, 1);
  assert.match(result.stdout, /SQL_STOCK_WRITE/);
});
test('interpolated and concatenated stock reads remain visible diagnostics', () => {
  for (const source of [
    '<?php function f($wpdb) { return $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = \'_stock_status\'"); }',
    '<?php function f($table) { return "SELECT post_id FROM " . $table . " WHERE meta_key = \'_stock_status\'"; }'
  ]) {
    const result = scan(source, ['--fail-on-error']);
    assert.equal(result.status, 0, result.stdout + result.stderr);
    assert.match(result.stdout, /SQL_STOCK_READ/);
  }
});
test('interpolated stock writes block the gate', () => {
  const result = scan('<?php function f($wpdb) { return $wpdb->query("UPDATE {$wpdb->postmeta} SET meta_value = \'instock\' WHERE meta_key = \'_stock_status\'"); }', ['--fail-on-error']);
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.match(result.stdout, /SQL_STOCK_WRITE/);
});
test('unused assignments are visible and --fail-on-warning blocks them', () => {
  const result = scan('<?php function f() { $dead = 1; return 2; }', ['--fail-on-warning']);
  assert.equal(result.status, 1);
  assert.match(result.stdout, /DEAD_ASSIGNMENT/);
});
test('an arrow function reads its captures in the enclosing scope', () => {
  const result = scan('<?php function f() { $captured = 1; return fn() => $captured; }', ['--fail-on-warning', '--fail-on-error']);
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.doesNotMatch(result.stdout, /DEAD_ASSIGNMENT/);
});
test('an explicit closure capture reads the enclosing foreach key', () => {
  const result = scan('<?php function f($items) { foreach ($items as $key => $value) { echo (function() use ($key, $value) { return $key . $value; })(); } }', ['--fail-on-error', '--fail-on-warning']);
  assert.equal(result.status, 0, result.stdout + result.stderr);
});
test('reference output parameters remain observable without a local read', () => {
  const result = scan('<?php function f(&$output) { $output = 3; }', ['--fail-on-warning']);
  assert.equal(result.status, 0, result.stdout + result.stderr);
});
test('variables read by a statically resolved template are used', () => fixture(directory => {
  writeFileSync(path.join(directory, 'view.php'), '<?php echo $from_controller;');
  writeFileSync(path.join(directory, 'controller.php'), '<?php function f() { $from_controller = 3; $template = __DIR__ . "/view.php"; include $template; }');
  const result = spawnSync('php', [analyzer, `--path=${directory}/controller.php`, '--fail-on-warning'], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
}));
test('variables read by nested sub-templates in a shell view are marked as used', () => fixture(directory => {
  mkdirSync(path.join(directory, 'sub'), { recursive: true });
  writeFileSync(path.join(directory, 'sub/tab-general.php'), '<?php echo $from_tab;');
  writeFileSync(path.join(directory, 'sub/script.php'), '<?php echo $from_script;');
  writeFileSync(
    path.join(directory, 'shell.php'),
    '<?php $sub = __DIR__ . "/sub/tab-" . $tab . ".php"; include $sub; $scr = __DIR__ . "/sub/script.php"; include $scr;'
  );
  writeFileSync(
    path.join(directory, 'controller.php'),
    '<?php function f($tab) { $from_tab = 1; $from_script = 2; $template = __DIR__ . "/shell.php"; include $template; }'
  );
  const result = spawnSync('php', [analyzer, `--path=${directory}/controller.php`, '--fail-on-warning'], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
}));
test('unknown AST flags fail rather than silently scanning another target', () => {
  const result = spawnSync('php', [analyzer, '--typo'], { encoding: 'utf8' });
  assert.equal(result.status, 2);
});
test('the search runtime archive includes its administrative templates', () => fixture(directory => {
  const source = path.join(directory, 'source');
  for (const child of ['assets/css', 'assets/js', 'frontend', 'src', 'templates/admin']) {
    mkdirSync(path.join(source, child), { recursive: true });
  }
  writeFileSync(path.join(source, 'akibara-search.php'), "<?php\n/**\n * Version: 9.9.9\n */\ndefine( 'AKIBARA_SEARCH_VERSION', '9.9.9' );\n");
  for (const child of ['src/Search.php', 'src/Install.php', 'frontend/facets-render.php', 'templates/admin/tab-runtime.php', 'uninstall.php']) {
    writeFileSync(path.join(source, child), '<?php // Runtime fixture.\n');
  }
  writeFileSync(path.join(source, 'assets/css/test.css'), 'body{}');
  writeFileSync(path.join(source, 'assets/js/test.js'), 'void 0;');
  const output = path.join(directory, 'dist');
  const result = spawnSync('bash', [path.join(root, 'tools/akibara-releases/package-runtime.sh'), 'search', '--source-dir', source, '--output-dir', output], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
  const inventory = readFileSync(path.join(output, 'akibara-search-9.9.9.files.sha256'), 'utf8');
  assert.match(inventory, /akibara-search\/templates\/admin\/tab-runtime\.php/);
  const archive = spawnSync('unzip', ['-p', path.join(output, 'akibara-search-9.9.9.zip'), 'akibara-search/templates/admin/tab-runtime.php'], { encoding: 'utf8' });
  assert.equal(archive.status, 0);
  assert.equal(archive.stdout, '<?php // Runtime fixture.\n');
  rmSync(path.join(source, 'templates'), { recursive: true });
  const missing = spawnSync('bash', [path.join(root, 'tools/akibara-releases/package-runtime.sh'), 'search', '--source-dir', source, '--verify-only'], { encoding: 'utf8' });
  assert.equal(missing.status, 1);
  assert.match(missing.stderr, /ruta runtime requerida: templates\/admin/);
}));
