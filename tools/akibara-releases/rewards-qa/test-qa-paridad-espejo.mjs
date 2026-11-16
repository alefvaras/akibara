import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync, rmSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { PLUGINS, RUNTIME_COMPONENTS, OPTION_KEYS, compareSnapshots, decodeSnapshot, readSnapshot, alignLocalOptions, loadReleaseSet, parseArguments, main } from './qa-paridad-espejo.mjs';

const good = () => ({
  versions: Object.fromEntries(RUNTIME_COMPONENTS.map(name => [name, '1.2.3'])),
  options: Object.fromEntries(OPTION_KEYS.map(name => [name, { exists: true, value: name === 'db_version' ? '61833' : name === 'woocommerce_db_version' ? '11.2.1' : '0' }])),
  queue_pending: 0
});
let checks = 0;
assert.equal(compareSnapshots(good(), good()).usable, true); checks++;
for (const badCount of [NaN, null, undefined, -1, '0', 'ERROR', Infinity]) {
  const local = good(); local.queue_pending = badCount;
  assert.equal(compareSnapshots(local, good()).usable, false);
  assert.equal(await main({ read: target => target === 'local' ? local : good(), align: false, log() {} }), 1);
  checks++;
}
for (const badVersion of ['ERROR', '', null, undefined]) {
  const local = good(); local.versions[PLUGINS[0]] = badVersion;
  assert.equal(compareSnapshots(local, good()).usable, false); checks++;
}
{
  const local = good(); local.versions[PLUGINS[0]] = '1.2.4';
  assert.equal(compareSnapshots(local, good()).usable, false); checks++;
}
{
  const local = good(); local.options[OPTION_KEYS[0]] = { exists: false };
  assert.equal(compareSnapshots(local, good()).usable, false);
  const production = good(); production.options[OPTION_KEYS[0]] = { exists: false };
  assert.equal(compareSnapshots(local, production).usable, true);
  checks++;
}
{
  const local = good(); local.queue_pending = 201;
  assert.equal(compareSnapshots(local, good()).usable, false); checks++;
}
{
  const local = good(); const production = good(); local.queue_pending = 10; production.queue_pending = 30;
  assert.equal(compareSnapshots(local, production).usable, true); checks++;
}
{
  assert.throws(() => decodeSnapshot('Error: proc_open disabled'));
  const stdout = `warning\n__AKB_PARITY_SNAPSHOT__${JSON.stringify(good())}\n`;
  const calls = [];
  const execute = (cmd, args, options) => { calls.push({ cmd, args, options }); return stdout; };
  assert.deepEqual(readSnapshot('local', execute), good());
  assert.deepEqual(readSnapshot('production', execute), good());
  assert.equal(calls[0].cmd, 'docker');
  assert.equal(calls[1].cmd, 'ssh');
  assert.match(calls[0].options.input, /get_plugins/);
  assert.match(calls[0].options.input, /get_bloginfo/);
  assert.match(calls[0].options.input, /wp_get_theme/);
  assert.match(calls[0].options.input, /get_var/);
  assert.doesNotMatch(calls[1].args.join(' '), /db query/);
  checks++;
}
{
  const calls = [];
  const local = good(); const production = good();
  production.options[OPTION_KEYS[0]] = { exists: false };
  production.options[OPTION_KEYS[1]] = { exists: true, value: 0 };
  alignLocalOptions(local, production, (...args) => { calls.push(args); return ''; });
  assert.equal(calls.length, 2);
  assert.equal(calls[0][0], 'docker');
  assert.deepEqual(calls[0][1].slice(-2), ['delete', OPTION_KEYS[0]]);
  assert.deepEqual(calls[1][1].slice(-4), ['update', OPTION_KEYS[1], '0', '--format=json']);
  checks++;
}
{
  let aligns = 0;
  assert.equal(await main({ read: () => good(), align: false, alignOptions() { aligns++; }, log() {} }), 0);
  assert.equal(aligns, 0);
  assert.equal(await main({ read() { throw new Error('transport failed'); }, align: false, log() {} }), 1);
  checks++;
}
for (const component of RUNTIME_COMPONENTS) {
  const local = good();
  local.versions[component] = '1.2.4';
  assert.equal(compareSnapshots(local, good()).usable, false, `Debe bloquear deriva de ${component}`);
  delete local.versions[component];
  assert.equal(compareSnapshots(local, good()).usable, false, `Debe medir ${component}`);
  checks++;
}
{
  const calls = [];
  const local = good(); const production = good();
  production.options.db_version = { exists: true, value: '61834' };
  production.options.woocommerce_db_version = { exists: true, value: '11.2.2' };
  assert.equal(compareSnapshots(local, production).usable, false);
  alignLocalOptions(local, production, (...args) => { calls.push(args); return ''; });
  assert.equal(calls.length, 0, 'No se puede fingir una migración copiando su versión');
  checks++;
}
{
  const patched = good();
  patched.options.woocommerce_db_version = { exists: true, value: '11.3.0-1' };
  assert.equal(compareSnapshots(patched, structuredClone(patched)).usable, true, 'Acepta sufijo de revisión de esquema WooCommerce');
  checks++;
}
for (const key of ['db_version', 'woocommerce_db_version']) {
  for (const invalid of [{ exists: false }, { exists: true, value: null }, { exists: true, value: 'ERROR' }, { exists: true, value: '0' }, { exists: true, value: '11.3.0-0' }, { exists: true, value: false }]) {
    const local = good(); const production = good();
    local.options[key] = invalid; production.options[key] = invalid;
    assert.equal(compareSnapshots(local, production).usable, false, `Dos lecturas inválidas iguales no aprueban ${key}`);
    assert.throws(() => alignLocalOptions(local, production, () => assert.fail('Una lectura inválida no escribe')));
    checks++;
  }
}
// Genuine local ZIPs exercise the official verifier, not a forged validated flag.
const scratch = mkdtempSync(path.join(tmpdir(), 'akb-parity-release-'));
try {
  const components = ['akibara-business', 'akibara-rewards', 'akibara-gp'];
  const makeArtifact = (slug, version) => {
    const base = `${slug}-${version}`;
    execFileSync('python3', ['-c', `import hashlib, pathlib, sys, zipfile
root, slug, version = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
base = slug + '-' + version
name = slug + '/' + ('style.css' if slug == 'akibara-gp' else slug + '.php')
content = ('/*\\nVersion: ' + version + '\\n*/\\n').encode()
archive = root / (base + '.zip')
with zipfile.ZipFile(archive, 'w') as out:
    out.writestr(name, content)
(root / (base + '.files.sha256')).write_text(hashlib.sha256(content).hexdigest() + '  ' + name + '\\n')
(root / (base + '.zip.sha256')).write_text(hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\\n')
`, scratch, slug, version]);
    return { zip: `${base}.zip`, inventory: `${base}.files.sha256`, checksum: `${base}.zip.sha256`,
      sha256: createHash('sha256').update(readFileSync(path.join(scratch, `${base}.zip`))).digest('hex') };
  };
  const contract = { schema_version: 1, components: Object.fromEntries(components.map(slug => [slug,
    { from: '1.2.3', to: '1.2.4', artifact: makeArtifact(slug, '1.2.4'), requires: {} }])) };
  contract.components['akibara-business'].requires = { 'akibara-rewards': '1.2.4', woocommerce: '1.2.3' };
  contract.components['akibara-gp'].requires = { 'akibara-business': '1.2.4' };
  const manifest = path.join(scratch, 'release-set.json');
  const writeContract = (value = contract) => { writeFileSync(manifest, JSON.stringify(value)); return manifest; };
  const local = good();
  for (const slug of components) local.versions[slug] = '1.2.4';
  const release = loadReleaseSet(writeContract(), components);
  assert.equal(compareSnapshots(local, good(), release).usable, true);
  assert.equal(compareSnapshots(local, good()).usable, false, 'Una diferencia sin artefactos sigue fallando');
  assert.equal(compareSnapshots(local, good(), structuredClone(release)).usable, false, 'Un flag o JSON no suplanta la validación');
  assert.throws(() => { release.components['akibara-business'].to = '9.0.0'; });
  checks += 4;

  for (const [target, slug, value] of [['local', 'akibara-business', '1.2.5'], ['production', 'akibara-business', '1.2.2'],
    ['local', 'akibara-search', '1.2.4'], ['local', 'woocommerce', '1.2.5']]) {
    const a = structuredClone(local); const b = good();
    (target === 'local' ? a : b).versions[slug] = value;
    assert.equal(compareSnapshots(a, b, release).usable, false, `Bloquea ${target} ${slug} ${value}`);
    checks++;
  }
  const dependencies = structuredClone(contract);
  dependencies.components['akibara-business'].requires.woocommerce = '1.2.4';
  assert.equal(compareSnapshots(local, good(), loadReleaseSet(writeContract(dependencies), components)).usable, false,
    'Dos dependencias iguales pero distintas al contrato no aprueban'); checks++;
  for (const change of [
    value => { value.components['akibara-business'].to = '1.2.2'; },
    value => { value.components['akibara-business'].to = '1.2.3'; },
    value => { value.components['akibara-business'].to = '1.2.5'; }, // Version differs from ZIP header.
    value => { value.components['akibara-business'].artifact = value.components['akibara-rewards'].artifact; },
    value => { value.components['akibara-business'].artifact.sha256 = '0'.repeat(64); },
    value => { value.components['akibara-business'].requires['akibara-rewards'] = '1.2.3'; },
    value => { value.components['akibara-business'].requires.typo = '1.2.3'; },
    value => { value.components['akibara-business'].acceptDifferences = true; },
    value => { value.components['akibara-search'] = value.components['akibara-business']; },
    value => { value.components.php = value.components['akibara-business']; },
    value => { value.schema_version = 2; },
  ]) {
    const invalid = structuredClone(contract); change(invalid);
    assert.throws(() => loadReleaseSet(writeContract(invalid), components)); checks++;
  }
  writeContract();
  for (const requested of [[], [...components, components[0]], components.slice(1), [...components, 'php'], ['unknown']]) {
    assert.throws(() => loadReleaseSet(manifest, requested)); checks++;
  }
  for (const key of ['zip', 'inventory', 'checksum']) {
    const filename = path.join(scratch, contract.components['akibara-business'].artifact[key]);
    const original = readFileSync(filename);
    writeFileSync(filename, 'tampered');
    assert.throws(() => loadReleaseSet(manifest, components), `Rechaza ${key} manipulado`);
    writeFileSync(filename, original); checks++;
  }
  {
    const candidate = structuredClone(contract);
    const link = path.join(scratch, 'alias.zip');
    symlinkSync(path.join(scratch, candidate.components['akibara-business'].artifact.zip), link);
    candidate.components['akibara-business'].artifact.zip = 'alias.zip';
    assert.throws(() => loadReleaseSet(writeContract(candidate), components)); checks++;
  }
  writeContract();
  {
    const file = path.join(scratch, contract.components['akibara-business'].artifact.inventory);
    const original = readFileSync(file);
    assert.throws(() => loadReleaseSet(manifest, components, (command, args, options) => {
      execFileSync(command, args, options);
      writeFileSync(file, 'changed-during-verification');
    }), /cambió durante/);
    writeFileSync(file, original); checks++;
  }
  for (const mutate of [a => { a.options.woocommerce_db_version.value = '11.3.0'; },
    a => { a.options.db_version.value = '61834'; }, a => { a.options.timezone_string.value = 'Europe/Madrid'; },
    a => { a.queue_pending = 201; }]) {
    const changed = structuredClone(local); mutate(changed);
    assert.equal(compareSnapshots(changed, good(), release).usable, false); checks++;
  }
  {
    const logs = []; let reads = 0; let aligns = 0;
    const options = { releaseSet: manifest, components, align: false, json: true, log: message => logs.push(message),
      read: target => { reads++; return target === 'local' ? local : good(); }, alignOptions: () => { aligns++; } };
    assert.equal(await main(options), 0);
    assert.equal(reads, 2);
    assert.equal(JSON.parse(logs[0]).release.components['akibara-business'].to, '1.2.4');
    reads = 0;
    assert.equal(await main({ ...options, align: true }), 1);
    assert.equal(reads, 0); assert.equal(aligns, 0);
    assert.equal(await main({ ...options, components: ['unknown'] }), 1);
    assert.equal(reads, 0, 'Contrato inválido falla antes de contactar hosts'); checks += 3;
  }
  assert.deepEqual(parseArguments(['--json', '--release-set', manifest, ...components.flatMap(slug => ['--component', slug])], {}),
    { json: true, releaseSet: manifest, components }); checks++;
  assert.deepEqual(parseArguments([], { AKB_RELEASE_SET: manifest, AKB_RELEASE_COMPONENTS: components.join(',') }),
    { json: false, releaseSet: manifest, components }); checks++;
  for (const args of [['--allow-differences'], ['--release-set'], ['--release-set', manifest],
    ['--component', components[0]], ['--release-set', manifest, '--release-set', manifest]]) {
    assert.throws(() => parseArguments(args)); checks++;
  }
} finally {
  rmSync(scratch, { recursive: true, force: true });
}
console.log(`PASS: ${checks} comprobaciones de paridad y release-set con ZIPs sintéticos verificados; sin red ni escrituras en WordPress.`);
