#!/usr/bin/env node

/**
 * Build the reviewed v2 work/edition publication manifest.
 *
 * The legacy work metas are immutable preconditions. The emitted mutation
 * candidate contains desired edition values only; the applicator independently
 * verifies the work facts before it writes the edition metas.
 */

import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { basename, resolve } from 'node:path';

const WORK_STATES = new Set([
  'completa',
  'en_publicacion',
  'en_pausa',
  'cancelada',
]);
const EDITION_STATES = new Set([
  'completa',
  'en_publicacion',
  'al_dia',
  'en_pausa',
  'cancelada',
]);
const CONFIDENCE = new Set(['high', 'medium', 'ambiguous']);
const CANONICAL_RESEARCH_ROWS = 104;

function fail(code, message, details = undefined) {
  const error = new Error(`${code}: ${message}`);
  error.code = code;
  error.details = details;
  throw error;
}

function parseArgs(argv) {
  const options = {
    inventory: '',
    research: [],
    output: '',
    expectedRows: CANONICAL_RESEARCH_ROWS,
    allowInventorySubset: false,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    const value = argv[index + 1];

    if ('--inventory' === argument && value) {
      options.inventory = resolve(value);
      index += 1;
    } else if ('--research' === argument && value) {
      options.research.push(resolve(value));
      index += 1;
    } else if ('--output' === argument && value) {
      options.output = resolve(value);
      index += 1;
    } else if ('--expected-rows' === argument && value) {
      options.expectedRows = Number.parseInt(value, 10);
      index += 1;
    } else if ('--allow-inventory-subset' === argument) {
      options.allowInventorySubset = true;
    } else {
      fail('invalid_argument', `Unknown or incomplete argument: ${argument}`);
    }
  }

  if (!options.inventory || !options.output || 0 === options.research.length) {
    fail(
      'missing_argument',
      'Usage: --inventory FILE --research FILE [--research FILE ...] --output FILE [--expected-rows N] [--allow-inventory-subset]',
    );
  }
  if (
    !Number.isInteger(options.expectedRows) ||
    options.expectedRows < 1 ||
    options.expectedRows > CANONICAL_RESEARCH_ROWS
  ) {
    fail('invalid_expected_rows', 'Expected rows must be an integer from 1 through 104.');
  }
  if (
    options.expectedRows !== CANONICAL_RESEARCH_ROWS &&
    !options.allowInventorySubset
  ) {
    fail(
      'subset_not_allowed',
      'A non-canonical row count requires --allow-inventory-subset.',
    );
  }

  return options;
}

function sha256(content) {
  return createHash('sha256').update(content).digest('hex');
}

async function readJson(path) {
  const raw = await readFile(path, 'utf8');
  let value;

  try {
    value = JSON.parse(raw);
  } catch (error) {
    fail('invalid_json', `${path}: ${error.message}`);
  }

  return { path, raw, sha256: sha256(raw), value };
}

function requireString(row, field, context, { allowEmpty = false } = {}) {
  const value = row[field];
  if ('string' !== typeof value || (!allowEmpty && '' === value.trim())) {
    fail('invalid_research_row', `${context}: ${field} must be a string`);
  }
  return value;
}

function requireNullableTotal(value, field, context) {
  if (null === value) return null;
  if (!Number.isInteger(value) || value < 1 || value > 999) {
    fail(
      'invalid_research_row',
      `${context}: ${field} must be null or an integer from 1 through 999`,
    );
  }
  return value;
}

function validateResearchRow(row, sourcePath) {
  if (!row || 'object' !== typeof row || Array.isArray(row)) {
    fail('invalid_research_row', `${sourcePath}: every row must be an object`);
  }

  const order = row.order;
  if (!Number.isInteger(order) || order < 1 || order > CANONICAL_RESEARCH_ROWS) {
    fail('invalid_research_row', `${sourcePath}: invalid order ${order}`);
  }

  const context = `${sourcePath}#${order}`;
  const name = requireString(row, 'name', context);
  const slug = requireString(row, 'slug', context);
  const legacyState = requireString(row, 'state', context);
  const workState = requireString(row, 'work_state', context);
  const editionState = requireString(row, 'edition_state', context);
  const confidence = requireString(row, 'confidence', context);
  const evidence = requireString(row, 'evidence', context);

  if (!WORK_STATES.has(legacyState) || !WORK_STATES.has(workState)) {
    fail('invalid_research_row', `${context}: work state is outside the closed enum`);
  }
  if (!EDITION_STATES.has(editionState)) {
    fail('invalid_research_row', `${context}: edition_state is outside the closed enum`);
  }
  if (!CONFIDENCE.has(confidence)) {
    fail('invalid_research_row', `${context}: invalid confidence ${confidence}`);
  }
  if (legacyState !== workState) {
    fail(
      'contract_mismatch',
      `${context}: legacy state must equal work_state (${legacyState} != ${workState})`,
    );
  }

  const legacyTotal = requireNullableTotal(row.total, 'total', context);
  const workTotal = requireNullableTotal(row.work_total, 'work_total', context);
  const editionTotal = requireNullableTotal(row.edition_total, 'edition_total', context);

  if ('completa' === workState) {
    if (legacyTotal !== workTotal) {
      fail('contract_mismatch', `${context}: total must equal work_total`, {
        legacyTotal,
        workTotal,
      });
    }
    if (null === workTotal && 'ambiguous' !== confidence) {
      fail(
        'contract_mismatch',
        `${context}: unknown completed-work total must be explicitly ambiguous`,
      );
    }
  } else if (null !== workTotal || null !== legacyTotal) {
    fail('contract_mismatch', `${context}: unfinished work must not have a final total`);
  }

  if ('completa' === editionState && null === editionTotal) {
    fail('contract_mismatch', `${context}: completed edition requires edition_total`);
  }
  if (
    ['al_dia', 'en_pausa', 'cancelada'].includes(editionState) &&
    null !== editionTotal
  ) {
    fail(
      'contract_mismatch',
      `${context}: ${editionState} edition must not have edition_total`,
    );
  }

  if (!Array.isArray(row.source_urls) || 0 === row.source_urls.length) {
    fail('invalid_research_row', `${context}: source_urls must not be empty`);
  }
  for (const url of row.source_urls) {
    if ('string' !== typeof url || !url.startsWith('https://')) {
      fail('invalid_research_row', `${context}: invalid source URL`);
    }
  }

  if (null !== row.ambiguity && 'string' !== typeof row.ambiguity) {
    fail('invalid_research_row', `${context}: ambiguity must be null or text`);
  }
  const ambiguity =
    'string' === typeof row.ambiguity && '' !== row.ambiguity.trim()
      ? row.ambiguity.trim()
      : null;

  return {
    order,
    name,
    slug,
    confidence,
    work_state: workState,
    work_total: workTotal,
    edition_state: editionState,
    edition_total: editionTotal,
    ambiguity,
    evidence,
    source_urls: [...row.source_urls],
  };
}

function requireInventoryString(row, field, path) {
  if ('string' !== typeof row[field]) {
    fail('invalid_inventory', `${path}: ${row.slug || '<unknown>'}.${field} must be a string`);
  }
  return row[field];
}

function validateInventory(document, path, expectedRows, allowSubset) {
  if (!document || 'object' !== typeof document || !Array.isArray(document.rows)) {
    fail('invalid_inventory', `${path}: expected an object with rows[]`);
  }
  if (2 !== document.schema_version) {
    fail('invalid_inventory', `${path}: inventory schema_version must be 2`);
  }
  if ('pa_serie' !== document.taxonomy) {
    fail('invalid_inventory', `${path}: taxonomy must be pa_serie`);
  }
  if (expectedRows !== document.rows.length) {
    fail('invalid_inventory', `${path}: expected ${expectedRows} indexable rows`);
  }
  if (allowSubset && 'production' === document.environment) {
    fail('production_subset_forbidden', 'A production inventory cannot be a subset.');
  }

  const rowsBySlug = new Map();
  for (const row of document.rows) {
    if (!row || 'object' !== typeof row || Array.isArray(row)) {
      fail('invalid_inventory', `${path}: every row must be an object`);
    }
    if ('string' !== typeof row.slug || '' === row.slug) {
      fail('invalid_inventory', `${path}: row has no slug`);
    }
    if (rowsBySlug.has(row.slug)) fail('duplicate_inventory_slug', row.slug);

    requireInventoryString(row, 'estado', path);
    requireInventoryString(row, 'tomos_obra', path);
    requireInventoryString(row, 'estado_edicion', path);
    requireInventoryString(row, 'tomos_edicion', path);
    if ('string' !== typeof row.edition_publisher || '' === row.edition_publisher.trim()) {
      fail('invalid_inventory', `${path}: ${row.slug} has no exact edition publisher`);
    }
    if ('' !== row.estado_edicion || '' !== row.tomos_edicion) {
      fail(
        'unexpected_existing_edition_data',
        `${row.slug}: edition termmeta is no longer empty`,
      );
    }
    rowsBySlug.set(row.slug, row);
  }

  return rowsBySlug;
}

function nullableTotalToStored(value) {
  return null === value ? '' : String(value);
}

function countsFor(rows, selector, states) {
  return Object.fromEntries(
    [...states].map((state) => [
      state,
      rows.filter((row) => selector(row) === state).length,
    ]),
  );
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  const inventoryFile = await readJson(options.inventory);
  const rowsBySlug = validateInventory(
    inventoryFile.value,
    options.inventory,
    options.expectedRows,
    options.allowInventorySubset,
  );
  const researchFiles = await Promise.all(options.research.map(readJson));
  const researchRows = [];
  const orderSet = new Set();
  const slugSet = new Set();

  for (const file of researchFiles) {
    if (!Array.isArray(file.value)) {
      fail('invalid_research', `${file.path}: expected an array`);
    }
    for (const rawRow of file.value) {
      const row = validateResearchRow(rawRow, file.path);
      if (orderSet.has(row.order)) fail('duplicate_research_order', String(row.order));
      if (slugSet.has(row.slug)) fail('duplicate_research_slug', row.slug);
      orderSet.add(row.order);
      slugSet.add(row.slug);
      researchRows.push(row);
    }
  }

  if (
    CANONICAL_RESEARCH_ROWS !== researchRows.length ||
    CANONICAL_RESEARCH_ROWS !== orderSet.size
  ) {
    fail(
      'incomplete_research',
      `expected 104 unique rows and orders, got ${researchRows.length}`,
    );
  }
  for (let order = 1; order <= CANONICAL_RESEARCH_ROWS; order += 1) {
    if (!orderSet.has(order)) fail('missing_research_order', String(order));
  }

  const researchBySlug = new Map(researchRows.map((row) => [row.slug, row]));
  const missingFromResearch = [...rowsBySlug.keys()].filter(
    (slug) => !researchBySlug.has(slug),
  );
  const missingFromInventory = [...researchBySlug.keys()].filter(
    (slug) => !rowsBySlug.has(slug),
  );
  if (missingFromResearch.length) {
    fail('canonical_set_mismatch', 'Inventory contains unreviewed series.', {
      missingFromResearch,
    });
  }
  if (!options.allowInventorySubset && missingFromInventory.length) {
    fail('canonical_set_mismatch', 'Research and production inventory differ.', {
      missingFromInventory,
    });
  }

  const manifestRows = [...rowsBySlug.values()]
    .map((inventory) => {
      const research = researchBySlug.get(inventory.slug);
      if (inventory.name !== research.name) {
        fail(
          'canonical_name_mismatch',
          `${research.slug}: ${research.name} != ${inventory.name}`,
        );
      }

      const desiredWorkTotal = nullableTotalToStored(research.work_total);
      if (
        inventory.estado !== research.work_state ||
        inventory.tomos_obra !== desiredWorkTotal
      ) {
        fail('work_precondition_mismatch', `${research.slug}: legacy work facts drifted`, {
          expected: {
            state: research.work_state,
            total: desiredWorkTotal,
          },
          observed: {
            state: inventory.estado,
            total: inventory.tomos_obra,
          },
        });
      }

      return {
        term_id: inventory.term_id,
        name: inventory.name,
        slug: inventory.slug,
        landing_url: inventory.landing_url,
        catalog_count: inventory.catalog_count,
        edition_publisher: inventory.edition_publisher,
        expected_before: {
          work_state: inventory.estado,
          work_total: inventory.tomos_obra,
          edition_state: inventory.estado_edicion,
          edition_total: inventory.tomos_edicion,
        },
        desired: {
          work_state: research.work_state,
          work_total: research.work_total,
          edition_state: research.edition_state,
          edition_total: research.edition_total,
        },
        confidence: research.confidence,
        research: {
          order: research.order,
          ambiguity: research.ambiguity,
          evidence: research.evidence,
          source_urls: research.source_urls,
        },
      };
    })
    .sort((left, right) => left.slug.localeCompare(right.slug, 'en'));

  const manifest = {
    schema_version: 2,
    generated_at: new Date().toISOString(),
    site_url: inventoryFile.value.site_url,
    taxonomy: 'pa_serie',
    required_plugin_version: '1.101.0',
    meta_keys: {
      work_state: '_akb_serie_estado',
      work_total: '_akb_serie_tomos_obra',
      edition_state: '_akb_serie_estado_edicion',
      edition_total: '_akb_serie_tomos_edicion',
    },
    contract: {
      work_state_enum: [...WORK_STATES],
      edition_state_enum: [...EDITION_STATES],
      work_total:
        'Integer 1-999 for a completed original work; null when unknown or unfinished.',
      edition_total:
        'Completed editions require an integer 1-999; ongoing editions may have a planned total; al_dia, paused and cancelled editions require null.',
      mutation_scope: 'Edition metas only. Legacy work metas are immutable preconditions.',
      source_of_truth: 'Reviewed research matched to the exact production term and publisher edition.',
    },
    inputs: {
      inventory: {
        file: basename(inventoryFile.path),
        sha256: inventoryFile.sha256,
      },
      research: researchFiles.map((file) => ({
        file: basename(file.path),
        sha256: file.sha256,
      })),
    },
    summary: {
      rows: manifestRows.length,
      work_states: countsFor(manifestRows, (row) => row.desired.work_state, WORK_STATES),
      edition_states: countsFor(
        manifestRows,
        (row) => row.desired.edition_state,
        EDITION_STATES,
      ),
      confidence: countsFor(manifestRows, (row) => row.confidence, CONFIDENCE),
      work_totals_defined: manifestRows.filter(
        (row) => null !== row.desired.work_total,
      ).length,
      work_totals_omitted: manifestRows.filter(
        (row) => null === row.desired.work_total,
      ).length,
      edition_totals_defined: manifestRows.filter(
        (row) => null !== row.desired.edition_total,
      ).length,
      edition_totals_omitted: manifestRows.filter(
        (row) => null === row.desired.edition_total,
      ).length,
    },
    rows: manifestRows,
  };

  const serialized = `${JSON.stringify(manifest, null, 2)}\n`;
  await writeFile(options.output, serialized, { mode: 0o600 });
  process.stdout.write(
    `${JSON.stringify({
      status: 'PASS',
      output: options.output,
      sha256: sha256(serialized),
      summary: manifest.summary,
    })}\n`,
  );
}

main().catch((error) => {
  process.stderr.write(
    `${JSON.stringify({
      status: 'FAIL',
      code: error.code || 'unexpected_error',
      message: error.message,
      details: error.details,
    })}\n`,
  );
  process.exitCode = 1;
});
