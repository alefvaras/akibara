#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const repoRoot = resolve(import.meta.dirname, '..');
const outputPath = resolve(
	repoRoot,
	process.argv[2] ?? 'ops/seo-editorial-orthography-quality-manifest.json',
);

const POST_ID = 23327;
const EXPECTED_BEFORE_SHA256 = '95cf6eefc3058840c1e2244047891b9426d93e1ad030f9ad0c4c0cc3c2e1062e';
const EXPECTED_AFTER_SHA256 = '7d4c17af24cf5c484ae28043da56be78762d6c4d374f2d64c52f3656258ddbc8';

const substitutions = [
	{ before: 'conquisto', after: 'conquistó', expected_count: 1 },
	{ before: 'capitulos', after: 'capítulos', expected_count: 2 },
	{ before: 'Capitulos', after: 'Capítulos', expected_count: 7 },
	{ before: 'edicion', after: 'edición', expected_count: 2 },
	{ before: 'coleccion', after: 'colección', expected_count: 1 },
	{ before: 'anos', after: 'años', expected_count: 2 },
	{ before: 'mas', after: 'más', expected_count: 5 },
	{ before: 'vias', after: 'vías', expected_count: 1 },
	{ before: 'tragico', after: 'trágico', expected_count: 2 },
	{ before: 'debera', after: 'deberá', expected_count: 1 },
	{ before: 'epoca', after: 'época', expected_count: 1 },
	{ before: 'formacion', after: 'formación', expected_count: 1 },
	{ before: 'destruccion', after: 'destrucción', expected_count: 1 },
	{ before: 'guia', after: 'guía', expected_count: 1 },
	{ before: 'Aqui', after: 'Aquí', expected_count: 3 },
	{ before: 'carismatico', after: 'carismático', expected_count: 1 },
	{ before: 'mecanica', after: 'mecánica', expected_count: 1 },
	{ before: 'marcaria', after: 'marcaría', expected_count: 1 },
	{ before: 'organizacion', after: 'organización', expected_count: 1 },
	{ before: 'desafia', after: 'desafía', expected_count: 1 },
	{ before: 'Dia', after: 'Día', expected_count: 1 },
	{ before: 'detras', after: 'detrás', expected_count: 1 },
	{ before: 'decima', after: 'décima', expected_count: 1 },
	{ before: 'generacion', after: 'generación', expected_count: 1 },
	{ before: 'maximo', after: 'máximo', expected_count: 1 },
	{ before: 'genero', after: 'generó', expected_count: 1 },
	{ before: 'continua', after: 'continúa', expected_count: 1 },
	{ before: 'despues', after: 'después', expected_count: 1 },
	{ before: 'ultimos', after: 'últimos', expected_count: 2 },
	{ before: 'adaptacion', after: 'adaptación', expected_count: 2 },
	{ before: 'unica', after: 'única', expected_count: 1 },
	{ before: 'lineas', after: 'líneas', expected_count: 1 },
	{ before: 'espanol', after: 'español', expected_count: 1 },
	{ before: '¿Que cubre', after: '¿Qué cubre', expected_count: 1 },
	{ before: '¿Por que leer', after: '¿Por qué leer', expected_count: 1 },
	{
		before: 'para que sepas que esperar',
		after: 'para que sepas qué esperar',
		expected_count: 1,
	},
];

const sha256 = (value) => createHash('sha256').update(value, 'utf8').digest('hex');

function escapeRegExp(value) {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function substitutionPattern(substitution) {
	const escaped = escapeRegExp(substitution.before);
	return /^[\p{L}]+$/u.test(substitution.before)
		? new RegExp(`(?<![\\p{L}])${escaped}(?![\\p{L}])`, 'gu')
		: new RegExp(escaped, 'gu');
}

function exactCount(value, substitution) {
	return [...value.matchAll(substitutionPattern(substitution))].length;
}

function applySubstitution(value, substitution) {
	return value.replace(substitutionPattern(substitution), substitution.after);
}

function readDockerPostContent(postId) {
	const sql = [
		'SELECT REPLACE(TO_BASE64(post_content),CHAR(10),\'\')',
		'FROM wp_posts',
		`WHERE ID=${postId}`,
		'AND post_type=\'post\'',
		'AND post_status=\'publish\';',
	].join(' ');
	const query = spawnSync(
		'docker',
		[
			'compose', 'exec', '-T', 'db',
			'mariadb', '-uroot', '-proot_local', 'akibara',
			'--batch', '--raw', '--skip-column-names',
			'-e', sql,
		],
		{ cwd: repoRoot, encoding: 'utf8', maxBuffer: 2 * 1024 * 1024 },
	);
	if (query.status !== 0) {
		throw new Error(`Docker read failed: ${query.stderr.trim()}`);
	}
	const encoded = query.stdout.trim().replace(/\s+/g, '');
	if (!encoded) {
		throw new Error(`Published post ${postId} was not found in Docker`);
	}
	return Buffer.from(encoded, 'base64').toString('utf8');
}

const expectedBefore = readDockerPostContent(POST_ID);
if (sha256(expectedBefore) !== EXPECTED_BEFORE_SHA256) {
	throw new Error(
		`Post ${POST_ID} drifted; expected ${EXPECTED_BEFORE_SHA256}, observed ${sha256(expectedBefore)}`,
	);
}

let proposedAfter = expectedBefore;
for (const substitution of substitutions) {
	const observed = exactCount(proposedAfter, substitution);
	if (observed !== substitution.expected_count) {
		throw new Error(
			`${JSON.stringify(substitution.before)} count drifted; `
			+ `expected ${substitution.expected_count}, observed ${observed}`,
		);
	}
	proposedAfter = applySubstitution(proposedAfter, substitution);
}

if (sha256(proposedAfter) !== EXPECTED_AFTER_SHA256) {
	throw new Error(
		`Proposed content drifted; expected ${EXPECTED_AFTER_SHA256}, observed ${sha256(proposedAfter)}`,
	);
}

const manifest = {
	schema_version: 1,
	generated_at: '2026-07-24',
	environment: 'docker-local-snapshot',
	purpose: 'Fail-closed, UI-only restoration of Spanish orthography in valid published editorial content.',
	rank_math_excluded: true,
	policy: {
		official_ui_first: true,
		no_direct_database_writes: true,
		delete_as_historical_garbage: false,
	},
	audit_summary: {
		entries: 1,
		total_exact_substitutions: substitutions.reduce(
			(total, substitution) => total + substitution.expected_count,
			0,
		),
		encoding_replacement_characters_observed: 0,
		mojibake_markers_observed: 0,
	},
	entries: [
		{
			key: `post:${POST_ID}:post_content`,
			object_type: 'post',
			object_id: POST_ID,
			title: 'Tokyo Revengers: guía para leer el manga completo',
			field: 'post_content',
			post_status: 'publish',
			content_disposition: 'valid_published_content_keep',
			issue_codes: [
				'spanish_diacritics_omitted',
				'missing_enye',
				'question_word_diacritics_omitted',
			],
			severity: 'high',
			application_lane: 'official_ui',
			apply_via: 'WordPress > Entradas > Tokyo Revengers: guía para leer el manga completo > Editor de código > Actualizar',
			expected_before: expectedBefore,
			expected_before_sha256: sha256(expectedBefore),
			substitutions: substitutions.map((substitution) => ({
				...substitution,
				match_mode: /^[\p{L}]+$/u.test(substitution.before)
					? 'whole_word'
					: 'literal',
			})),
			proposed_after: proposedAfter,
			proposed_after_sha256: sha256(proposedAfter),
			question_marks_preserved: true,
			note: 'The two ASCII question marks close valid Spanish questions; this proposal restores diacritics and does not delete or reinterpret punctuation.',
		},
	],
};

writeFileSync(outputPath, `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
console.log(`Wrote ${outputPath}`);
