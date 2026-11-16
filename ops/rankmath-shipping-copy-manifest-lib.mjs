#!/usr/bin/env node

import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';

export const TRANSFORMATION_VERSION = 'rankmath-shipping-destination-v1';
export const POST_META_KEYS = [
	'rank_math_description',
	'rank_math_snippet_desc',
];
export const POST_TYPES = [
	'page',
	'post',
	'product',
];
export const PUBLIC_TERM_TAXONOMIES = [
	'pa_autor',
	'pa_genero',
	'pa_serie',
	'product_brand',
	'product_cat',
];

const ABSOLUTE_GEOGRAPHY_SOURCE = [
	'todo\\s+Chile',
	'todo\\s+el\\s+pa[ií]s',
	'todas\\s+las\\s+regiones(?:\\s+de\\s+Chile)?',
].join('|');

const SHIPPING_CLAIM_SOURCE = [
	'(\\b(?:env[ií]os?|despachos?|entregas?)',
	'(?:\\s+(?:rápid[oa]s?|segur[oa]s?|express|disponibles?)){0,3}',
	'\\s+)a\\s+(?:',
	ABSOLUTE_GEOGRAPHY_SOURCE,
	')',
].join('');

function absoluteGeographyPattern() {
	return new RegExp(ABSOLUTE_GEOGRAPHY_SOURCE, 'iu');
}

function shippingClaimPattern() {
	return new RegExp(SHIPPING_CLAIM_SOURCE, 'giu');
}

function decodeHex(value) {
	return Buffer.from(String(value), 'hex').toString('utf8');
}

function stableSort(rows) {
	return [...rows].sort((left, right) => (
		left.scope.localeCompare(right.scope)
		|| left.id - right.id
		|| left.meta_key.localeCompare(right.meta_key)
		|| left.meta_id - right.meta_id
	));
}

function countBy(rows, key) {
	return Object.fromEntries(
		[...rows.reduce((counts, row) => {
			const value = String(row[key]);
			counts.set(value, (counts.get(value) ?? 0) + 1);
			return counts;
		}, new Map()).entries()].sort(([left], [right]) => left.localeCompare(right))
	);
}

function serializedValue(value) {
	return /^(?:a|O|s|i|b|d|C|R|r):[0-9]+[:;{]/u.test(value) || value === 'N;';
}

function assertionError(message) {
	throw new Error(`Rank Math shipping manifest assertion failed: ${message}`);
}

export function sha256(value) {
	return crypto.createHash('sha256').update(String(value), 'utf8').digest('hex');
}

export function entityKey(row) {
	return [
		row.scope,
		row.id,
		row.type,
		row.taxonomy,
		row.meta_key,
	].join('|');
}

export function containsAbsoluteGeography(value) {
	return absoluteGeographyPattern().test(String(value));
}

export function normalizeShippingClaim(value) {
	return String(value).replace(shippingClaimPattern(), '$1según destino');
}

export function candidateRows(rows) {
	return stableSort(rows.filter((row) => containsAbsoluteGeography(row.before)));
}

export function readLiveScopedRows({
	repoRoot,
	dbService = process.env.AKB_DOCKER_DB_SERVICE ?? 'db',
	dbUser = process.env.AKB_DB_USER ?? 'root',
	dbPassword = process.env.AKB_DB_PASSWORD ?? 'root_local',
	database = process.env.AKB_LIVE_DB ?? 'akibara',
} = {}) {
	if (!repoRoot) {
		throw new Error('readLiveScopedRows requires repoRoot');
	}

	const postTypesSql = POST_TYPES.map((value) => `'${value}'`).join(', ');
	const postMetaKeysSql = POST_META_KEYS.map((value) => `'${value}'`).join(', ');
	const taxonomiesSql = PUBLIC_TERM_TAXONOMIES.map((value) => `'${value}'`).join(', ');
	const sql = `
START TRANSACTION READ ONLY;
SELECT
	scoped.scope,
	scoped.meta_id,
	scoped.entity_id,
	HEX(scoped.entity_type),
	HEX(scoped.taxonomy),
	HEX(scoped.slug),
	HEX(scoped.label),
	HEX(scoped.entity_status),
	HEX(scoped.meta_key),
	HEX(scoped.meta_value)
FROM (
	SELECT
		'postmeta' AS scope,
		pm.meta_id,
		p.ID AS entity_id,
		p.post_type AS entity_type,
		'' AS taxonomy,
		p.post_name AS slug,
		p.post_title AS label,
		p.post_status AS entity_status,
		pm.meta_key,
		pm.meta_value
	FROM wp_postmeta AS pm
	INNER JOIN wp_posts AS p ON p.ID = pm.post_id
	WHERE p.post_status = 'publish'
		AND p.post_type IN (${postTypesSql})
		AND pm.meta_key IN (${postMetaKeysSql})

	UNION ALL

	SELECT
		'termmeta' AS scope,
		tm.meta_id,
		tm.term_id AS entity_id,
		'term' AS entity_type,
		tt.taxonomy,
		t.slug,
		t.name AS label,
		'public_term' AS entity_status,
		tm.meta_key,
		tm.meta_value
	FROM wp_termmeta AS tm
	INNER JOIN wp_terms AS t ON t.term_id = tm.term_id
	INNER JOIN wp_term_taxonomy AS tt ON tt.term_id = tm.term_id
	WHERE tt.taxonomy IN (${taxonomiesSql})
		AND tm.meta_key = 'rank_math_description'
) AS scoped
ORDER BY
	scoped.scope,
	scoped.entity_id,
	scoped.meta_key,
	scoped.meta_id;
COMMIT;
`;

	const output = execFileSync(
		'docker',
		[
			'compose',
			'-f',
			`${repoRoot}/compose.yaml`,
			'exec',
			'-T',
			dbService,
			'mariadb',
			`-u${dbUser}`,
			`-p${dbPassword}`,
			database,
			'--batch',
			'--raw',
			'--skip-column-names',
			'-e',
			sql,
		],
		{
			cwd: repoRoot,
			encoding: 'utf8',
			maxBuffer: 64 * 1024 * 1024,
			stdio: ['ignore', 'pipe', 'pipe'],
		}
	);

	const rows = output
		.split('\n')
		.filter((line) => line.trim() !== '')
		.map((line) => {
			const columns = line.split('\t');
			if (columns.length !== 10) {
				assertionError(`unexpected read-only SELECT column count (${columns.length})`);
			}

			return {
				scope: columns[0],
				meta_id: Number(columns[1]),
				id: Number(columns[2]),
				type: decodeHex(columns[3]),
				taxonomy: decodeHex(columns[4]),
				slug: decodeHex(columns[5]),
				label: decodeHex(columns[6]),
				status: decodeHex(columns[7]),
				meta_key: decodeHex(columns[8]),
				before: decodeHex(columns[9]),
			};
		});

	assertScopedRows(rows);
	return stableSort(rows);
}

export function assertScopedRows(rows) {
	const keys = new Set();
	const metaIds = new Set();

	for (const row of rows) {
		if (!Number.isSafeInteger(row.meta_id) || row.meta_id <= 0) {
			assertionError(`invalid meta_id ${row.meta_id}`);
		}
		if (!Number.isSafeInteger(row.id) || row.id <= 0) {
			assertionError(`invalid entity id ${row.id}`);
		}
		if (!['postmeta', 'termmeta'].includes(row.scope)) {
			assertionError(`unsupported scope ${row.scope}`);
		}
		if (row.scope === 'postmeta') {
			if (!POST_TYPES.includes(row.type) || row.taxonomy !== '' || row.status !== 'publish') {
				assertionError(`postmeta scope drift for entity ${row.id}`);
			}
			if (!POST_META_KEYS.includes(row.meta_key)) {
				assertionError(`unsupported post meta key ${row.meta_key}`);
			}
		} else {
			if (
				row.type !== 'term'
				|| !PUBLIC_TERM_TAXONOMIES.includes(row.taxonomy)
				|| row.status !== 'public_term'
				|| row.meta_key !== 'rank_math_description'
			) {
				assertionError(`termmeta scope drift for entity ${row.id}`);
			}
		}

		const key = entityKey(row);
		if (keys.has(key)) {
			assertionError(`duplicate entity/meta key ${key}`);
		}
		keys.add(key);

		const metaIdKey = `${row.scope}|${row.meta_id}`;
		if (metaIds.has(metaIdKey)) {
			assertionError(`duplicate ${metaIdKey}; taxonomy ownership is ambiguous`);
		}
		metaIds.add(metaIdKey);
	}
}

export function buildSourceSnapshot(rows, capturedDate) {
	const candidates = candidateRows(rows);
	for (const row of candidates) {
		if (serializedValue(row.before)) {
			assertionError(`serialized candidate ${entityKey(row)} requires manual review`);
		}
		if (normalizeShippingClaim(row.before) === row.before) {
			assertionError(`unsupported absolute claim in ${entityKey(row)}`);
		}
	}

	const snapshotRows = candidates.map((row) => ({
		scope: row.scope,
		meta_id: row.meta_id,
		id: row.id,
		type: row.type,
		taxonomy: row.taxonomy,
		slug: row.slug,
		label: row.label,
		status: row.status,
		meta_key: row.meta_key,
		before: row.before,
	}));
	const rowsSha256 = sha256(JSON.stringify(snapshotRows));

	return {
		metadata: {
			format_version: 1,
			captured_date: capturedDate,
			source: 'Docker MariaDB fixed SELECT inside READ ONLY transaction',
			database_mutations: 0,
			scope: {
				post_types: POST_TYPES,
				post_meta_keys: POST_META_KEYS,
				term_taxonomies: PUBLIC_TERM_TAXONOMIES,
				term_meta_keys: ['rank_math_description'],
			},
			row_count: snapshotRows.length,
			counts: {
				scope: countBy(snapshotRows, 'scope'),
				type: countBy(snapshotRows, 'type'),
				taxonomy: countBy(snapshotRows, 'taxonomy'),
				meta_key: countBy(snapshotRows, 'meta_key'),
			},
			rows_sha256: rowsSha256,
		},
		rows: snapshotRows,
	};
}

export function assertSourceSnapshot(snapshot) {
	if (!snapshot || !snapshot.metadata || !Array.isArray(snapshot.rows)) {
		assertionError('invalid source snapshot structure');
	}
	assertScopedRows(snapshot.rows);

	if (snapshot.rows.length === 0) {
		assertionError('source snapshot is empty; preserve the reviewed baseline');
	}
	if (snapshot.metadata.row_count !== snapshot.rows.length) {
		assertionError('source snapshot row_count mismatch');
	}
	if (snapshot.metadata.rows_sha256 !== sha256(JSON.stringify(snapshot.rows))) {
		assertionError('source snapshot SHA256 mismatch');
	}
	if (candidateRows(snapshot.rows).length !== snapshot.rows.length) {
		assertionError('source snapshot contains a non-candidate row');
	}
}

export function buildManifest(snapshot) {
	assertSourceSnapshot(snapshot);

	const rows = snapshot.rows.map((sourceRow) => {
		const matches = [...sourceRow.before.matchAll(shippingClaimPattern())];
		const after = normalizeShippingClaim(sourceRow.before);
		const beforeLength = [...sourceRow.before].length;
		const afterLength = [...after].length;

		if (matches.length === 0 || after === sourceRow.before) {
			assertionError(`candidate did not transform: ${entityKey(sourceRow)}`);
		}
		if (containsAbsoluteGeography(after)) {
			assertionError(`absolute geography remains after transform: ${entityKey(sourceRow)}`);
		}
		if (normalizeShippingClaim(after) !== after) {
			assertionError(`transform is not idempotent: ${entityKey(sourceRow)}`);
		}
		if (afterLength > 160) {
			assertionError(`after copy exceeds 160 characters: ${entityKey(sourceRow)} (${afterLength})`);
		}

		const productContext = sourceRow.type === 'product'
			|| sourceRow.taxonomy.startsWith('pa_')
			|| ['product_brand', 'product_cat'].includes(sourceRow.taxonomy);
		const uiPath = sourceRow.scope === 'postmeta'
			? `/wp-admin/post.php?post=${sourceRow.id}&action=edit`
			: `/wp-admin/term.php?taxonomy=${encodeURIComponent(sourceRow.taxonomy)}&tag_ID=${sourceRow.id}${productContext ? '&post_type=product' : ''}`;

		return {
			manifest_id: `${sourceRow.scope.toUpperCase()}-${sourceRow.meta_id}`,
			scope: sourceRow.scope,
			meta_id: sourceRow.meta_id,
			id: sourceRow.id,
			type: sourceRow.type,
			taxonomy: sourceRow.taxonomy,
			slug: sourceRow.slug,
			label: sourceRow.label,
			status: sourceRow.status,
			meta_key: sourceRow.meta_key,
			ui_path: uiPath,
			replacement_count: matches.length,
			before_length: beforeLength,
			after_length: afterLength,
			before_sha256: sha256(sourceRow.before),
			after_sha256: sha256(after),
			before: sourceRow.before,
			after,
		};
	});

	const rowsSha256 = sha256(JSON.stringify(rows));
	const maxBeforeLength = Math.max(...rows.map((row) => row.before_length));
	const maxAfterLength = Math.max(...rows.map((row) => row.after_length));
	const replacementCount = rows.reduce((sum, row) => sum + row.replacement_count, 0);

	return {
		metadata: {
			format_version: 1,
			transformation_version: TRANSFORMATION_VERSION,
			source_rows_sha256: snapshot.metadata.rows_sha256,
			rows_sha256: rowsSha256,
			row_count: rows.length,
			replacement_count: replacementCount,
			max_before_length: maxBeforeLength,
			max_after_length: maxAfterLength,
			counts: {
				scope: countBy(rows, 'scope'),
				type: countBy(rows, 'type'),
				taxonomy: countBy(rows, 'taxonomy'),
				meta_key: countBy(rows, 'meta_key'),
			},
			editorial_policy: {
				change: 'Replace only the absolute geographic clause after a shipping noun with "según destino".',
				preserved: 'Entity-specific copy, prices, titles, series, availability, punctuation and non-geographic claims.',
				length_limit: 160,
				application_channel: 'Official Rank Math administration UI only',
				database_mutations: 0,
			},
			assertions: {
				all_rows_changed: rows.every((row) => row.before !== row.after),
				all_hashes_exact: rows.every(
					(row) => row.before_sha256 === sha256(row.before)
						&& row.after_sha256 === sha256(row.after)
				),
				no_absolute_geography_after: rows.every(
					(row) => !containsAbsoluteGeography(row.after)
				),
				idempotent: rows.every(
					(row) => normalizeShippingClaim(row.after) === row.after
				),
				lengths_at_most_160: rows.every((row) => row.after_length <= 160),
				ui_only: true,
			},
		},
		rows,
	};
}

export function assertManifest(manifest) {
	if (!manifest || !manifest.metadata || !Array.isArray(manifest.rows)) {
		assertionError('invalid manifest structure');
	}
	if (manifest.metadata.row_count !== manifest.rows.length) {
		assertionError('manifest row_count mismatch');
	}
	if (manifest.metadata.rows_sha256 !== sha256(JSON.stringify(manifest.rows))) {
		assertionError('manifest rows SHA256 mismatch');
	}

	const keys = new Set();
	for (const row of manifest.rows) {
		const key = entityKey(row);
		if (keys.has(key)) {
			assertionError(`duplicate manifest entity/meta key ${key}`);
		}
		keys.add(key);

		if (row.before_sha256 !== sha256(row.before) || row.after_sha256 !== sha256(row.after)) {
			assertionError(`row SHA256 mismatch for ${row.manifest_id}`);
		}
		if (normalizeShippingClaim(row.before) !== row.after) {
			assertionError(`transformation drift for ${row.manifest_id}`);
		}
		if (normalizeShippingClaim(row.after) !== row.after) {
			assertionError(`non-idempotent row ${row.manifest_id}`);
		}
		if (containsAbsoluteGeography(row.after)) {
			assertionError(`absolute geography remains in ${row.manifest_id}`);
		}
		if (
			row.before_length !== [...row.before].length
			|| row.after_length !== [...row.after].length
			|| row.after_length > 160
		) {
			assertionError(`length assertion failed for ${row.manifest_id}`);
		}
	}
}

export function manifestCsv(manifest) {
	const columns = [
		'manifest_id',
		'scope',
		'meta_id',
		'id',
		'type',
		'taxonomy',
		'slug',
		'label',
		'status',
		'meta_key',
		'ui_path',
		'replacement_count',
		'before_length',
		'after_length',
		'before_sha256',
		'after_sha256',
		'before',
		'after',
	];
	const csvCell = (value) => {
		const string = String(value ?? '');
		return /[",\r\n]/u.test(string) ? `"${string.replaceAll('"', '""')}"` : string;
	};

	return `${[
		columns.join(','),
		...manifest.rows.map(
			(row) => columns.map((column) => csvCell(row[column])).join(',')
		),
	].join('\n')}\n`;
}

export function validateManifestAgainstLive(manifest, liveRows, expectedState = 'either') {
	assertManifest(manifest);
	assertScopedRows(liveRows);
	if (!['pending', 'applied', 'either'].includes(expectedState)) {
		throw new Error(`Unsupported expected state: ${expectedState}`);
	}

	const liveByKey = new Map(liveRows.map((row) => [entityKey(row), row]));
	const manifestByKey = new Map(manifest.rows.map((row) => [entityKey(row), row]));
	const stateRows = [];
	const missing = [];
	const stale = [];

	for (const manifestRow of manifest.rows) {
		const key = entityKey(manifestRow);
		const liveRow = liveByKey.get(key);
		if (!liveRow) {
			missing.push({
				manifest_id: manifestRow.manifest_id,
				key,
			});
			continue;
		}

		const currentSha256 = sha256(liveRow.before);
		let state = 'stale';
		if (currentSha256 === manifestRow.before_sha256 && liveRow.before === manifestRow.before) {
			state = 'pending';
		} else if (currentSha256 === manifestRow.after_sha256 && liveRow.before === manifestRow.after) {
			state = 'applied';
		}

		stateRows.push({
			manifest_id: manifestRow.manifest_id,
			key,
			state,
			observed_meta_id: liveRow.meta_id,
			manifest_meta_id: manifestRow.meta_id,
			current_sha256: currentSha256,
		});
		if (state === 'stale') {
			stale.push(stateRows.at(-1));
		}
	}

	const extraCandidates = candidateRows(liveRows)
		.filter((row) => !manifestByKey.has(entityKey(row)))
		.map((row) => ({
			key: entityKey(row),
			meta_id: row.meta_id,
			id: row.id,
			type: row.type,
			taxonomy: row.taxonomy,
			meta_key: row.meta_key,
			current_sha256: sha256(row.before),
		}));
	const states = countBy(stateRows, 'state');
	const stateMatches = expectedState === 'either'
		|| (
			expectedState === 'pending'
			&& (states.pending ?? 0) === manifest.rows.length
		)
		|| (
			expectedState === 'applied'
			&& (states.applied ?? 0) === manifest.rows.length
		);
	const assertions = {
		manifest_integrity: true,
		live_scope_has_no_duplicate_keys: liveByKey.size === liveRows.length,
		no_missing_rows: missing.length === 0,
		no_stale_rows: stale.length === 0,
		no_extra_candidates: extraCandidates.length === 0,
		expected_state_matches: stateMatches,
		idempotent_after_values: manifest.rows.every(
			(row) => normalizeShippingClaim(row.after) === row.after
		),
	};
	const failures = Object.entries(assertions)
		.filter(([, passed]) => !passed)
		.map(([name]) => name);

	return {
		validation_version: 1,
		transformation_version: TRANSFORMATION_VERSION,
		expected_state: expectedState,
		result: failures.length === 0 ? 'PASS' : 'FAIL',
		failures,
		manifest_rows: manifest.rows.length,
		live_scoped_rows: liveRows.length,
		live_scoped_rows_sha256: sha256(JSON.stringify(liveRows)),
		states,
		missing,
		stale,
		extra_candidates: extraCandidates,
		assertions,
	};
}
