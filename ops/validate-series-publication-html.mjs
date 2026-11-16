#!/usr/bin/env node

/**
 * Verify the server-rendered v2 work/edition SEO surface for every manifest row.
 */

import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

function fail(message) {
  throw new Error(message);
}

function parseArgs(argv) {
  const options = {
    manifest: '',
    baseUrl: '',
    concurrency: 8,
    allowLegacyTitle: false,
  };
  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    const value = argv[index + 1];
    if ('--manifest' === argument && value) {
      options.manifest = resolve(value);
      index += 1;
    } else if ('--base-url' === argument && value) {
      options.baseUrl = value.replace(/\/$/, '');
      index += 1;
    } else if ('--concurrency' === argument && value) {
      options.concurrency = Number.parseInt(value, 10);
      index += 1;
    } else if ('--allow-legacy-title' === argument) {
      options.allowLegacyTitle = true;
    } else {
      fail(`Unknown or incomplete argument: ${argument}`);
    }
  }

  if (!options.manifest || !options.baseUrl) {
    fail(
      'Usage: --manifest FILE --base-url URL [--concurrency N] [--allow-legacy-title]',
    );
  }
  if (
    !Number.isInteger(options.concurrency) ||
    options.concurrency < 1 ||
    options.concurrency > 20
  ) {
    fail('Concurrency must be an integer from 1 through 20.');
  }
  return options;
}

function textContent(fragment) {
  return fragment
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;|&#160;/g, ' ')
    .replace(/&#(\d+);/g, (_, code) => String.fromCodePoint(Number(code)))
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#039;|&apos;/g, "'")
    .replace(/\s+/g, ' ')
    .trim();
}

function tomos(total) {
  return `${total} ${1 === total ? 'tomo' : 'tomos'}`;
}

function expectedWorkPhrase(row) {
  const { work_state: state, work_total: total } = row.desired;
  if ('completa' === state) {
    return Number.isInteger(total) ? `Finalizada en ${tomos(total)}` : 'Finalizada';
  }
  if ('en_publicacion' === state) return 'En publicación';
  if ('en_pausa' === state) return 'En pausa';
  if ('cancelada' === state) return 'Cancelada';
  fail(`${row.slug}: unknown work state ${state}`);
}

function expectedEditionPhrase(row) {
  const { edition_state: state, edition_total: total } = row.desired;
  if ('completa' === state) {
    if (!Number.isInteger(total)) fail(`${row.slug}: completed edition has no total`);
    return `Completa en ${tomos(total)}`;
  }
  if ('en_publicacion' === state) {
    return Number.isInteger(total)
      ? `En publicación ${tomos(total)} ${1 === total ? 'previsto' : 'previstos'}`
      : 'En publicación';
  }
  if ('al_dia' === state) return 'Al día';
  if ('en_pausa' === state) return 'En pausa';
  if ('cancelada' === state) return 'Cancelada';
  fail(`${row.slug}: unknown edition state ${state}`);
}

function shouldShowWork(row) {
  const { work_state: workState, work_total: workTotal } = row.desired;
  const { edition_state: editionState, edition_total: editionTotal } = row.desired;
  return (
    workState !== editionState ||
    (Number.isInteger(workTotal) && workTotal > 0 && workTotal !== editionTotal)
  );
}

function factRows(html) {
  const pattern =
    /<dt\b[^>]*class="[^"]*\bakb-series-facts__label\b[^"]*"[^>]*>([\s\S]*?)<\/dt>\s*<dd\b[^>]*class="[^"]*\bakb-series-facts__value\b[^"]*"[^>]*>([\s\S]*?)<\/dd>/g;
  return [...html.matchAll(pattern)].map((match) => ({
    label: textContent(match[1]),
    value: textContent(match[2]),
  }));
}

function requireExactFact(rows, label, expectedValue, errors) {
  const matches = rows.filter((row) => row.label === label);
  if (1 !== matches.length) {
    errors.push(`${label} rows: ${matches.length}`);
    return;
  }
  if (matches[0].value !== expectedValue) {
    errors.push(
      `${label} phrase ${JSON.stringify(matches[0].value)} != ${JSON.stringify(expectedValue)}`,
    );
  }
}

async function verifyRow(row, baseUrl, allowLegacyTitle) {
  const url = `${baseUrl}/serie/${row.slug}/`;
  const response = await fetch(url, {
    redirect: 'follow',
    headers: {
      'user-agent': 'AkibaraPublicationValidator/2.0',
    },
  });
  const html = await response.text();
  const errors = [];

  if (200 !== response.status) errors.push(`HTTP ${response.status}`);

  const rows = factRows(html);
  const editionLabel = `Edición ${row.edition_publisher}`;
  const editionPhrase = expectedEditionPhrase(row);
  requireExactFact(rows, editionLabel, editionPhrase, errors);

  const workMatches = rows.filter((fact) => 'Obra original' === fact.label);
  if (shouldShowWork(row)) {
    requireExactFact(rows, 'Obra original', expectedWorkPhrase(row), errors);
  } else if (0 !== workMatches.length) {
    errors.push(`Obra original rows: ${workMatches.length}; expected 0`);
  }

  const factsPosition = html.indexOf('class="akb-series-facts');
  const productsPosition = html.indexOf('class="products');
  if (factsPosition < 0 || productsPosition < 0 || factsPosition > productsPosition) {
    errors.push('facts are not server-rendered before the product grid');
  }

  const title = html.match(/<title\b[^>]*>([\s\S]*?)<\/title>/i);
  if (!title) {
    errors.push('document title missing');
  } else if (!allowLegacyTitle) {
    const titleText = textContent(title[1]);
    if (/todos los tomos/i.test(titleText)) {
      errors.push(`legacy title phrase present: ${JSON.stringify(titleText)}`);
    } else if (!/tomos disponibles/i.test(titleText)) {
      errors.push(`safe title phrase missing: ${JSON.stringify(titleText)}`);
    }
  }

  const robots = html.match(/<meta name="robots" content="([^"]+)"\s*\/?>(?:<\/meta>)?/i);
  if (!robots || !robots[1].includes('index') || robots[1].includes('noindex')) {
    errors.push('indexable robots directive missing');
  }

  const canonical = html.match(/<link rel="canonical" href="([^"]+)"\s*\/?>(?:<\/link>)?/i);
  const expectedPath = `/serie/${row.slug}/`;
  if (!canonical) {
    errors.push('canonical missing');
  } else {
    try {
      if (new URL(canonical[1]).pathname !== expectedPath) {
        errors.push(`canonical path mismatch: ${canonical[1]}`);
      }
    } catch {
      errors.push(`canonical invalid: ${canonical[1]}`);
    }
  }

  if (!/"@type"\s*:\s*"ComicSeries"/.test(html)) {
    errors.push('ComicSeries JSON-LD missing');
  }

  return {
    slug: row.slug,
    url,
    expected: {
      edition_label: editionLabel,
      edition_phrase: editionPhrase,
      work_label: shouldShowWork(row) ? 'Obra original' : null,
      work_phrase: shouldShowWork(row) ? expectedWorkPhrase(row) : null,
    },
    status: 0 === errors.length ? 'PASS' : 'FAIL',
    errors,
  };
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  const manifest = JSON.parse(await readFile(options.manifest, 'utf8'));
  if (2 !== manifest.schema_version) fail('Manifest schema_version must be 2.');
  if (!Array.isArray(manifest.rows) || 0 === manifest.rows.length) {
    fail('Manifest has no rows.');
  }

  const results = new Array(manifest.rows.length);
  let cursor = 0;
  async function worker() {
    while (cursor < manifest.rows.length) {
      const index = cursor;
      cursor += 1;
      try {
        results[index] = await verifyRow(
          manifest.rows[index],
          options.baseUrl,
          options.allowLegacyTitle,
        );
      } catch (error) {
        results[index] = {
          slug: manifest.rows[index].slug,
          url: `${options.baseUrl}/serie/${manifest.rows[index].slug}/`,
          status: 'FAIL',
          errors: [error.message],
        };
      }
    }
  }

  await Promise.all(
    Array.from(
      { length: Math.min(options.concurrency, manifest.rows.length) },
      () => worker(),
    ),
  );

  const failures = results.filter((result) => 'FAIL' === result.status);
  const summary = {
    status: 0 === failures.length ? 'PASS' : 'FAIL',
    base_url: options.baseUrl,
    allow_legacy_title: options.allowLegacyTitle,
    rows: results.length,
    passed: results.length - failures.length,
    failed: failures.length,
    failures,
  };
  process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
  if (failures.length) process.exitCode = 1;
}

main().catch((error) => {
  process.stderr.write(
    `${JSON.stringify({ status: 'FAIL', message: error.message })}\n`,
  );
  process.exitCode = 1;
});
