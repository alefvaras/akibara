/**
 * Read-only local/production parity gate. AKB_ALINEAR=si explicitly requests
 * local option alignment; no production writes or remote temporary files.
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const CWD = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const MARKER = '__AKB_PARITY_SNAPSHOT__';
export const PLUGINS = ['akibara-business', 'akibara-search', 'akibara-cart-checkout-blocks',
  'akibara-rewards', 'woocommerce', 'google-listings-and-ads', 'bluex-for-woocommerce'];
export const RUNTIME_COMPONENTS = [...PLUGINS, 'wordpress-core', 'akibara-gp', 'php'];
export const OPTION_KEYS = [
  'akibara_taxonomy_discount_apply_to', 'akibara_taxonomy_discount_from',
  'akibara_taxonomy_discount_to', 'akibara_fsp_enabled', 'akibara_fsp_threshold',
  'akibara_fsp_carrier_threshold', 'akb_series_bundles_enabled', 'timezone_string',
  'db_version', 'woocommerce_db_version'
];
// Database versions certify completed migrations; copying their option values
// would conceal an unperformed update. These require the official updaters.
const ALIGN_KEYS = OPTION_KEYS.filter(key => !['timezone_string', 'db_version', 'woocommerce_db_version'].includes(key));

// One official WP-CLI eval reads everything. It avoids `wp db query`, whose
// external mysql process cannot run on hosts with proc_open disabled.
const SNAPSHOT_PHP = `<?php
require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$out = array('versions'=>array(), 'options'=>array());
foreach (${JSON.stringify(PLUGINS)} as $slug) {
  $matches = get_plugins('/' . $slug);
  if (count($matches) !== 1) throw new RuntimeException('Plugin header unavailable or ambiguous: ' . $slug);
  $data = reset($matches);
  $out['versions'][$slug] = (string)($data['Version'] ?? '');
}
$out['versions']['wordpress-core'] = (string)get_bloginfo('version');
$out['versions']['akibara-gp'] = (string)wp_get_theme('akibara-gp')->get('Version');
$out['versions']['php'] = PHP_VERSION;
$missing = new stdClass();
foreach (${JSON.stringify(OPTION_KEYS)} as $key) {
  $value = get_option($key, $missing);
  $out['options'][$key] = $value === $missing ? array('exists'=>false) : array('exists'=>true, 'value'=>$value);
}
$count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'pending'");
if (null === $count || '' !== $wpdb->last_error || !ctype_digit((string)$count)) throw new RuntimeException('Cannot measure Action Scheduler pending queue');
$out['queue_pending'] = (int)$count;
echo '${MARKER}' . wp_json_encode($out) . PHP_EOL;
`;

export function decodeSnapshot(output) {
  const markerAt = output.indexOf(MARKER);
  if (markerAt < 0) throw new Error('La lectura no devolvió un snapshot JSON verificable.');
  return JSON.parse(output.slice(markerAt + MARKER.length).split('\n')[0]);
}

export function readSnapshot(target, execute = execFileSync) {
	if (!['local', 'production'].includes(target)) throw new Error('Destino de paridad inválido.');
  const common = { cwd: CWD, encoding: 'utf8', input: SNAPSHOT_PHP, timeout: 90000, stdio: ['pipe', 'pipe', 'pipe'] };
  const raw = target === 'local'
    ? execute('docker', ['compose', 'run', '--rm', '--no-deps', '-T', 'cli', 'wp', 'eval-file', '-', '--skip-plugins', '--skip-themes'], common)
    : execute('ssh', ['akibara', 'cd ~/domains/akibara.cl/public_html && /usr/local/bin/wp eval-file - --skip-plugins --skip-themes'], common);
  return decodeSnapshot(raw);
}

export function validateSnapshot(snapshot, target) {
  const issues = [];
  if (!snapshot || typeof snapshot !== 'object' || Array.isArray(snapshot)) return [`${target}: snapshot ausente o inválido`];
  for (const plugin of RUNTIME_COMPONENTS) {
    const version = snapshot.versions?.[plugin];
    if (typeof version !== 'string' || !/^\d+\.\d+(?:\.\d+)?(?:[-+][a-zA-Z0-9.-]+)?$/.test(version)) issues.push(`${target}: versión no medida de ${plugin}`);
  }
  for (const key of OPTION_KEYS) {
    const option = snapshot.options?.[key];
    if (!option || typeof option.exists !== 'boolean' || (option.exists && !Object.hasOwn(option, 'value'))) issues.push(`${target}: option no medida ${key}`);
  }
  const coreDb = snapshot.options?.db_version;
  if (coreDb?.exists !== true || !['string', 'number'].includes(typeof coreDb.value) || !/^[1-9]\d*$/.test(String(coreDb.value)) || !Number.isSafeInteger(Number(coreDb.value))) {
    issues.push(`${target}: versión de base de datos de WordPress no medida`);
  }
  const wooDb = snapshot.options?.woocommerce_db_version;
  if (wooDb?.exists !== true || typeof wooDb.value !== 'string' || !/^\d+\.\d+(?:\.\d+)?$/.test(wooDb.value)) {
    issues.push(`${target}: versión de base de datos de WooCommerce no medida`);
  }
  if (!Number.isSafeInteger(snapshot.queue_pending) || snapshot.queue_pending < 0) issues.push(`${target}: cola no medida (se requiere entero >= 0)`);
  return issues;
}

export function compareSnapshots(local, production) {
  const problems = [...validateSnapshot(local, 'espejo'), ...validateSnapshot(production, 'producción')];
  const differences = [];
  if (!problems.length) {
    for (const plugin of RUNTIME_COMPONENTS) {
      if (local.versions[plugin] !== production.versions[plugin]) differences.push(`versión ${plugin}`);
    }
    for (const key of OPTION_KEYS) {
      const a = local.options[key];
      const b = production.options[key];
      if (a.exists !== b.exists || (a.exists && JSON.stringify(a.value) !== JSON.stringify(b.value))) differences.push(`option ${key}`);
    }
    if (local.queue_pending > 200) problems.push(`espejo: ${local.queue_pending} acciones pendientes superan el límite de 200`);
  }
  return { usable: !problems.length && !differences.length, problems, differences, local, production };
}

export function alignLocalOptions(local, production, execute = execFileSync) {
  const issues = [...validateSnapshot(local, 'espejo'), ...validateSnapshot(production, 'producción')];
  if (issues.length) throw new Error(`No se alinea a partir de lecturas inválidas: ${issues.join('; ')}`);
  for (const key of ALIGN_KEYS) {
    const current = local.options[key];
    const desired = production.options[key];
    if (current.exists === desired.exists && (!current.exists || JSON.stringify(current.value) === JSON.stringify(desired.value))) continue;
    const args = ['compose', 'run', '--rm', '--no-deps', '-T', 'cli', 'wp', 'option'];
    if (desired.exists) args.push('update', key, JSON.stringify(desired.value), '--format=json');
    else args.push('delete', key);
    execute('docker', args, { cwd: CWD, encoding: 'utf8', timeout: 90000, stdio: ['pipe', 'pipe', 'pipe'] });
  }
}

export async function main({ read = readSnapshot, align = process.env.AKB_ALINEAR === 'si', alignOptions = alignLocalOptions, json = process.argv.includes('--json'), log = console.log } = {}) {
  let local;
  let production;
  try {
    local = read('local');
    production = read('production');
    if (align) {
      alignOptions(local, production);
      local = read('local');
    }
  } catch (error) {
    log(`NO APROBADO: falló una lectura o alineación (${error.message}).`);
    return 1;
  }
  const report = compareSnapshots(local, production);
  if (json) log(JSON.stringify(report, null, 2));
  else {
    log('PARIDAD ESPEJO / PRODUCCIÓN');
    for (const plugin of RUNTIME_COMPONENTS) log(`versión ${plugin}: espejo=${local.versions?.[plugin] ?? 'NO MEDIDA'} producción=${production.versions?.[plugin] ?? 'NO MEDIDA'}`);
    for (const key of OPTION_KEYS) {
      const display = option => !option ? 'NO MEDIDA' : option.exists ? JSON.stringify(option.value) : 'AUSENTE';
      log(`${key}: espejo=${display(local.options?.[key])} producción=${display(production.options?.[key])}`);
    }
    log(`cola pendiente: espejo=${local.queue_pending ?? 'NO MEDIDA'} producción=${production.queue_pending ?? 'NO MEDIDA'}`);
    for (const issue of [...report.problems, ...report.differences]) log(`REQUIERE CORRECCIÓN: ${issue}`);
    log(report.usable ? 'APROBADO: versiones y options coinciden; cola local <= 200. Falta medir cada cambio y producción.' : 'NO APROBADO: no confiar en el espejo hasta corregir y volver a medir.');
  }
  return report.usable ? 0 : 1;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  process.exitCode = await main();
}
