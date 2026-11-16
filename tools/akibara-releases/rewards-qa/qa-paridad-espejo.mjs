/**
 * Read-only local/production parity gate. AKB_ALINEAR=si explicitly requests
 * local option alignment; no production writes or remote temporary files.
 */
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync, lstatSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const CWD = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const MARKER = '__AKB_PARITY_SNAPSHOT__';
export const PLUGINS = ['akibara-business', 'akibara-search', 'akibara-cart-checkout-blocks',
  'akibara-rewards', 'woocommerce', 'bluex-for-woocommerce'];
export const RUNTIME_COMPONENTS = [...PLUGINS, 'wordpress-core', 'akibara-gp', 'php'];
export const RELEASE_COMPONENTS = [...PLUGINS.slice(0, 4), 'akibara-gp'];
const VERSION = /^\d+\.\d+(?:\.\d+)?(?:[-+][a-zA-Z0-9.-]+)?$/;
const RELEASE_VERSION = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/;
const verifiedReleaseSets = new WeakSet();

function record(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function exactKeys(value, keys, label) {
  if (!record(value) || Object.keys(value).sort().join('\0') !== [...keys].sort().join('\0')) {
    throw new Error(`${label}: campos obligatorios o desconocidos`);
  }
}

function ascendingVersion(from, to) {
  if (!RELEASE_VERSION.test(from) || !RELEASE_VERSION.test(to)) return false;
  const a = from.split('.').map(BigInt);
  const b = to.split('.').map(BigInt);
  for (let i = 0; i < a.length; i++) {
    if (a[i] !== b[i]) return b[i] > a[i];
  }
  return false;
}

function fileHash(filename) {
  if (!lstatSync(filename).isFile()) throw new Error(`Artefacto no regular: ${filename}`);
  return createHash('sha256').update(readFileSync(filename)).digest('hex');
}

/**
 * Explicit release contract; paths resolve relative to its JSON file:
 * {schema_version:1,components:{slug:{from,to,artifact:{zip,inventory,checksum,sha256},requires:{slug:version}}}}
 * The caller supplies the exact deployment component set independently. Each
 * declared target must be the header version of a package accepted by the
 * existing runtime verifier. `requires` pins the final measured versions of
 * dependencies; completeness of that contract still requires release review.
 * This gate does not certify Docker/runtime bytes or approve a deployment.
 */
export function loadReleaseSet(filename, expectedComponents, execute = execFileSync) {
  const absolute = path.resolve(filename);
  if (!lstatSync(absolute).isFile()) throw new Error('Release-set no regular.');
  const release = JSON.parse(readFileSync(absolute, 'utf8'));
  exactKeys(release, ['schema_version', 'components'], 'release-set');
  if (release.schema_version !== 1 || !record(release.components)) throw new Error('Esquema release-set inválido.');
  const slugs = Object.keys(release.components).sort();
  if (!Array.isArray(expectedComponents) || !expectedComponents.length
      || expectedComponents.some(slug => !RELEASE_COMPONENTS.includes(slug))
      || new Set(expectedComponents).size !== expectedComponents.length
      || slugs.join('\0') !== [...expectedComponents].sort().join('\0')) {
    throw new Error('El release-set no coincide con el conjunto exacto de componentes solicitado.');
  }
  // Validate the whole declaration before launching any verifier or reading a host.
  for (const slug of slugs) {
    const candidate = release.components[slug];
    exactKeys(candidate, ['from', 'to', 'artifact', 'requires'], slug);
    if (typeof candidate.from !== 'string' || typeof candidate.to !== 'string'
        || !ascendingVersion(candidate.from, candidate.to)) throw new Error(`${slug}: se requiere una transición ascendente estable from → to`);
    exactKeys(candidate.artifact, ['zip', 'inventory', 'checksum', 'sha256'], `${slug}.artifact`);
    if (!/^[a-f0-9]{64}$/.test(candidate.artifact.sha256)
        || ['zip', 'inventory', 'checksum'].some(key => typeof candidate.artifact[key] !== 'string' || !candidate.artifact[key])) {
      throw new Error(`${slug}: referencias de artefacto inválidas`);
    }
    if (!record(candidate.requires)) throw new Error(`${slug}: requires debe declarar sus dependencias`);
    for (const [dependency, version] of Object.entries(candidate.requires)) {
      if (!RUNTIME_COMPONENTS.includes(dependency) || dependency === slug || typeof version !== 'string' || !VERSION.test(version)) {
        throw new Error(`${slug}: dependencia inválida ${dependency}`);
      }
    }
  }
  for (const slug of slugs) {
    const candidate = release.components[slug];
    for (const [dependency, version] of Object.entries(candidate.requires)) {
      if (release.components[dependency] && release.components[dependency].to !== version) {
        throw new Error(`${slug}: dependencia candidata desalineada ${dependency}`);
      }
    }
    const files = ['zip', 'inventory', 'checksum'].map(key => path.resolve(path.dirname(absolute), candidate.artifact[key]));
    const before = files.map(fileHash);
    if (before[0] !== candidate.artifact.sha256) throw new Error(`${slug}: SHA-256 distinto al release-set`);
    execute('python3', [path.join(CWD, 'tools/akibara-releases/verify-runtime-package.py'), ...files, slug, candidate.to],
      { cwd: CWD, encoding: 'utf8', timeout: 90000, stdio: ['pipe', 'pipe', 'pipe'] });
    if (files.some((file, index) => fileHash(file) !== before[index])) throw new Error(`${slug}: el artefacto cambió durante la validación`);
    Object.freeze(candidate.artifact);
    Object.freeze(candidate.requires);
    Object.freeze(candidate);
  }
  Object.freeze(release.components);
  Object.freeze(release);
  verifiedReleaseSets.add(release);
  return release;
}

export function parseArguments(args, env = process.env) {
  const result = { json: false, releaseSet: null, components: [] };
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--json') result.json = true;
    else if (['--release-set', '--component'].includes(args[i]) && args[i + 1] && !args[i + 1].startsWith('--')) {
      const option = args[i++];
      if (option === '--component') result.components.push(args[i]);
      else {
        if (result.releaseSet) throw new Error('--release-set no admite duplicados.');
        result.releaseSet = args[i];
      }
    } else throw new Error(`Argumento inválido: ${args[i]}`);
  }
  if (!result.releaseSet && !result.components.length && env.AKB_RELEASE_SET && env.AKB_RELEASE_COMPONENTS) {
    result.releaseSet = env.AKB_RELEASE_SET;
    result.components = env.AKB_RELEASE_COMPONENTS.split(',').map((item) => item.trim()).filter(Boolean);
  }
  if (Boolean(result.releaseSet) !== Boolean(result.components.length)) throw new Error('--release-set exige --component por cada componente y viceversa.');
  return result;
}
export const OPTION_KEYS = [
  'akibara_taxonomy_discount_apply_to', 'akibara_taxonomy_discount_from',
  'akibara_taxonomy_discount_to', 'akibara_fsp_enabled', 'akibara_fsp_threshold',
  'akibara_fsp_carrier_threshold', 'timezone_string',
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
  if (wooDb?.exists !== true || typeof wooDb.value !== 'string' || !/^\d+\.\d+(?:\.\d+)?(?:-[1-9]\d*)?$/.test(wooDb.value)) {
    issues.push(`${target}: versión de base de datos de WooCommerce no medida`);
  }
  if (!Number.isSafeInteger(snapshot.queue_pending) || snapshot.queue_pending < 0) issues.push(`${target}: cola no medida (se requiere entero >= 0)`);
  return issues;
}

export function compareSnapshots(local, production, release = null) {
  const problems = [...validateSnapshot(local, 'espejo'), ...validateSnapshot(production, 'producción')];
  const differences = [];
  if (release !== null && (!record(release) || !verifiedReleaseSets.has(release))) problems.push('release-set no validado contra sus artefactos');
  if (!problems.length) {
    for (const plugin of RUNTIME_COMPONENTS) {
      const candidate = release?.components[plugin];
      if (candidate) {
        if (production.versions[plugin] !== candidate.from && production.versions[plugin] !== candidate.to) differences.push(`versión origen ${plugin}: producción no coincide con from`);
        if (local.versions[plugin] !== candidate.to) differences.push(`versión candidata ${plugin}: espejo no coincide con to`);
        for (const [dependency, version] of Object.entries(candidate.requires)) {
          if (local.versions[dependency] !== version) differences.push(`dependencia ${plugin} → ${dependency}: versión final desalineada`);
        }
      } else if (local.versions[plugin] !== production.versions[plugin]) differences.push(`versión ${plugin}`);
    }
    for (const key of OPTION_KEYS) {
      const a = local.options[key];
      const b = production.options[key];
      if (a.exists !== b.exists || (a.exists && JSON.stringify(a.value) !== JSON.stringify(b.value))) differences.push(`option ${key}`);
    }
    if (local.queue_pending > 200) problems.push(`espejo: ${local.queue_pending} acciones pendientes superan el límite de 200`);
  }
  return { usable: !problems.length && !differences.length, problems, differences, local, production, release };
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

export async function main({ read = readSnapshot, align = process.env.AKB_ALINEAR === 'si', alignOptions = alignLocalOptions,
  json = false, log = console.log, releaseSet = null, components = [], execute = execFileSync } = {}) {
  let local;
  let production;
  let release = null;
  try {
    if (Boolean(releaseSet) !== Boolean(components.length)) throw new Error('Se requieren release-set y conjunto explícito de componentes juntos.');
    if (releaseSet) release = loadReleaseSet(releaseSet, components, execute);
    if (release && align) throw new Error('La certificación de candidato es solo lectura; alinear options en una ejecución separada.');
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
  const report = compareSnapshots(local, production, release);
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
    log(report.usable ? (release ? 'APROBADO: transiciones candidatas verificadas contra artefactos; resto de versiones y options coinciden; cola local <= 200. Falta certificar runtime y despliegue conjunto.' : 'APROBADO: versiones y options coinciden; cola local <= 200. Falta medir cada cambio y producción.') : 'NO APROBADO: no confiar en el espejo hasta corregir y volver a medir.');
  }
  return report.usable ? 0 : 1;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  try {
    process.exitCode = await main(parseArguments(process.argv.slice(2)));
  } catch (error) {
    console.error(`NO APROBADO: ${error.message}`);
    process.exitCode = 1;
  }
}
