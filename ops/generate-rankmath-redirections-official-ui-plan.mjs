#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import {
	canonicalDestination,
	canonicalSource,
	EXPECTED_TARGET,
	logicalKey,
	normalizeTargetIgnore,
	parseCsv,
	validateTarget,
} from './compare-rankmath-redirections-snapshot.mjs';
import {
	reviewedMalformedReason,
	rowPayloadSha256,
} from './rankmath-redirections-row-fingerprint.mjs';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const defaultTarget = path.join(scriptDir, 'rankmath-redirections-ui-final.csv');

function fail(message) {
	throw new Error(`Rank Math official UI plan generation failed: ${message}`);
}

function option(name, fallback = undefined) {
	const index = process.argv.indexOf(name);
	return index === -1 ? fallback : process.argv[index + 1];
}

function sha256(value) {
	return crypto.createHash('sha256').update(String(value), 'utf8').digest('hex');
}

function read(file) {
	const raw = fs.readFileSync(file, 'utf8');
	return { raw, value: JSON.parse(raw) };
}

function countBy(rows, field) {
	return Object.fromEntries(
		[...rows.reduce((counts, row) => {
			const key = String(row[field]);
			counts.set(key, (counts.get(key) ?? 0) + 1);
			return counts;
		}, new Map()).entries()].sort(([left], [right]) => left.localeCompare(right))
	);
}

function writeAtomic(file, value) {
	fs.mkdirSync(path.dirname(file), { recursive: true });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, { mode: 0o600 });
	fs.renameSync(temporary, file);
}

const comparisonOption = option('--comparison');
const outputOption = option('--output');
if (!comparisonOption || !outputOption) {
	fail('--comparison and --output are required');
}

const targetPath = path.resolve(option('--target', defaultTarget));
const comparisonPath = path.resolve(comparisonOption);
const outputPath = path.resolve(outputOption);
const environment = option('--environment', 'docker');
if (!['docker', 'production'].includes(environment)) {
	fail(`unsupported environment ${environment}`);
}

const targetRaw = fs.readFileSync(targetPath, 'utf8');
const comparisonSource = read(comparisonPath);
const comparison = comparisonSource.value;
const reviewedMalformedRows = Array.isArray(comparison?.remote?.reviewed_malformed_rows)
	? comparison.remote.reviewed_malformed_rows
	: [];
const reviewedMalformed = [...Map.groupBy(
	reviewedMalformedRows,
	(row) => row.payload_sha256
).entries()].map(([payloadSha256, rows]) => ({
	kind: 'reviewed_malformed',
	action: 'trash',
	reason: rows[0].reason,
	payload: rows[0].payload,
	payload_sha256: payloadSha256,
	expected_count: rows.length,
}));
if (
	!['PASS', 'DRIFT'].includes(comparison?.result)
	|| comparison?.schema_version !== 2
	|| comparison?.target?.rows !== EXPECTED_TARGET.total
	|| comparison?.target?.unique_logical_keys !== EXPECTED_TARGET.total
	|| comparison?.target?.validation?.pass !== true
	|| comparison?.projected?.pass !== true
	|| comparison?.remote?.malformed_rows?.length !== 0
	|| !reviewedMalformed.every((row) =>
		row.reason === 'reviewed_missing_sources_mi_cuenta'
		&& row.action === 'trash'
		&& [1, 2].includes(row.expected_count)
		&& row.payload_sha256 === rowPayloadSha256(row.payload)
		&& reviewedMalformedReason(row.payload) === row.reason
		&& comparison.actions.delete.some((action) =>
			action.reason === 'reviewed_malformed'
			&& action.payload_sha256 === row.payload_sha256))
	|| new Set(reviewedMalformed.map((row) => row.payload_sha256)).size !== reviewedMalformed.length
	|| reviewedMalformed.reduce((total, row) => total + row.expected_count, 0) !== reviewedMalformedRows.length
) {
	fail(`comparison is not a complete fail-closed ${EXPECTED_TARGET.total}-rule preflight`);
}

const rules = parseCsv(targetRaw).map((row, index) => {
	const matching = String(row.matching).trim().toLowerCase();
	const source = canonicalSource(row.source, matching);
	const rule = {
		logical_key: logicalKey(source, matching),
		source,
		matching,
		ignore: normalizeTargetIgnore(row.ignore),
		destination: canonicalDestination(row.destination),
		type: String(row.type).trim(),
		status: String(row.status).trim().toLowerCase(),
		target_row: index + 2,
	};
	rule.semantic_sha256 = sha256(JSON.stringify({
		source: rule.source,
		matching: rule.matching,
		ignore: rule.ignore,
		destination: rule.destination,
		type: rule.type,
		status: rule.status,
	}));
	return rule;
}).sort((left, right) => left.logical_key.localeCompare(right.logical_key));

const validation = validateTarget(rules);
if (
	!validation.pass
	|| rules.length !== EXPECTED_TARGET.total
	|| new Set(rules.map((row) => row.logical_key)).size !== EXPECTED_TARGET.total
) {
	fail(`target CSV is not the reviewed closed set of ${EXPECTED_TARGET.total} logical rules`);
}

const rulesSha256 = sha256(JSON.stringify(rules));
const plan = {
	schema_version: 3,
	plan_type: 'akibara_rankmath_redirections_official_ui',
	environment,
	generated_at: new Date().toISOString(),
	application_policy: {
		official_administration_ui_only: true,
		global_inventory_before_write: true,
		global_inventory_before_each_phase: true,
		row_payload_revalidation_immediately_before_click: true,
		reviewed_malformed_actions: 'trash_only',
		reviewed_malformed_identity: 'visible_payload_sha256',
		direct_rest_writes: 0,
		direct_ajax_writes: 0,
		direct_sql_writes: 0,
		direct_wp_cli_writes: 0,
		remote_import_files: 0,
		logical_identity_uses_ids: false,
		ids_are_transient_ui_evidence_only: true,
		source_ignore_contract: 'literal_case_only',
		legacy_ignore_values_normalized_via_official_checkbox: true,
		local_atomic_checkpoints: true,
		resume_reinventories_ui: true,
		clear_404_monitor_via_official_button: true,
		commercial_history_mutations: 0,
	},
	allowed_admin_surfaces: [
		'/wp-admin/admin.php?page=rank-math-redirections',
		'/wp-admin/admin.php?page=rank-math-404-monitor',
		'/wp-admin/admin.php?page=wc-orders',
	],
	sources: {
		target: {
			path: path.relative(path.dirname(outputPath), targetPath),
			sha256: sha256(targetRaw),
		},
		comparison: {
			path: path.relative(path.dirname(outputPath), comparisonPath),
			sha256: sha256(comparisonSource.raw),
			result: comparison.result,
			remote_rows: comparison.remote.rows,
			counts: comparison.counts,
		},
	},
	expected: {
		total: EXPECTED_TARGET.total,
		status: { ...EXPECTED_TARGET.status },
		matching: { ...EXPECTED_TARGET.matching },
		ignore: { ...EXPECTED_TARGET.ignore },
		type: { ...EXPECTED_TARGET.type },
		trash: 0,
		duplicates: 0,
		reviewed_malformed_to_trash: reviewedMalformedRows.length,
	},
	counts: {
		total: rules.length,
		status: countBy(rules, 'status'),
		matching: countBy(rules, 'matching'),
		ignore: countBy(rules, 'ignore'),
		type: countBy(rules, 'type'),
	},
	rules_sha256: rulesSha256,
	rules,
	reviewed_malformed_sha256: sha256(JSON.stringify(reviewedMalformed)),
	reviewed_malformed: reviewedMalformed,
};
plan.plan_sha256 = sha256(JSON.stringify({
	schema_version: plan.schema_version,
	plan_type: plan.plan_type,
	environment: plan.environment,
	application_policy: plan.application_policy,
	allowed_admin_surfaces: plan.allowed_admin_surfaces,
	sources: plan.sources,
	expected: plan.expected,
	counts: plan.counts,
	rules_sha256: plan.rules_sha256,
	reviewed_malformed_sha256: plan.reviewed_malformed_sha256,
}));

writeAtomic(outputPath, plan);
console.log(JSON.stringify({
	result: 'PASS',
	output: outputPath,
	environment,
	plan_sha256: plan.plan_sha256,
	rules: rules.length,
	reviewed_malformed: reviewedMalformedRows.length,
	comparison_result: comparison.result,
	comparison_counts: comparison.counts,
}, null, 2));
