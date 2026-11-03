import assert from 'node:assert/strict';
import { PLUGINS, RUNTIME_COMPONENTS, OPTION_KEYS, compareSnapshots, decodeSnapshot, readSnapshot, alignLocalOptions, main } from './qa-paridad-espejo.mjs';

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
for (const key of ['db_version', 'woocommerce_db_version']) {
  for (const invalid of [{ exists: false }, { exists: true, value: null }, { exists: true, value: 'ERROR' }, { exists: true, value: '0' }, { exists: true, value: false }]) {
    const local = good(); const production = good();
    local.options[key] = invalid; production.options[key] = invalid;
    assert.equal(compareSnapshots(local, production).usable, false, `Dos lecturas inválidas iguales no aprueban ${key}`);
    assert.throws(() => alignLocalOptions(local, production, () => assert.fail('Una lectura inválida no escribe')));
    checks++;
  }
}
console.log(`PASS: ${checks} comprobaciones de paridad, incluidos ERROR/NaN, ausente frente a cero, mismatch y fallo de transporte; sin red ni escrituras reales.`);
