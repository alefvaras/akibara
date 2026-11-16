#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_CAPTURE="${AKB_REPO_ROOT}/ops/capture-seo-editorial-public-values.mjs"
AKB_GENERATOR="${AKB_REPO_ROOT}/ops/generate-seo-editorial-environment-ui-manifest.mjs"
AKB_VALIDATOR="${AKB_REPO_ROOT}/ops/validate-seo-editorial-environment-ui-manifest.mjs"
AKB_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/akb-seo-editorial-environment-test.XXXXXX")"

akb_cleanup() {
	case "${AKB_TMP_DIR}" in
		*/akb-seo-editorial-environment-test.*) rm -rf -- "${AKB_TMP_DIR}" ;;
	esac
}
trap akb_cleanup EXIT

node --check "${AKB_CAPTURE}"
node --check "${AKB_GENERATOR}"
node --check "${AKB_VALIDATOR}"

node - "${AKB_CAPTURE}" <<'NODE'
const fs = require('node:fs');
const source = fs.readFileSync(process.argv[2], 'utf8');
const start = source.indexOf('const environments = ');
const end = source.indexOf('const manifestFiles = ');
if (start < 0 || end <= start) {
	throw new Error('remote environment contract is missing');
}
const contract = source.slice(start, end);
const occurrences = (value) => contract.split(value).length - 1;
if (
	occurrences("site_url: 'https://akibara.cl'") !== 1
	|| occurrences(
		"wp_path: '/home/u888022333/domains/akibara.cl/public_html'",
	) !== 1
	|| occurrences('site_url:') !== 1
	|| occurrences('wp_path:') !== 1
) {
	throw new Error('remote capture target is not the single exact production target');
}
NODE

if node "${AKB_CAPTURE}" \
	--environment docker-local \
	--ssh-host no-network.invalid \
	--output "${AKB_TMP_DIR}/must-not-capture.json" \
	>"${AKB_TMP_DIR}/retired-environment.out" 2>&1
then
	echo "FAIL: remote capture accepted a non-production environment" >&2
	exit 1
fi
grep -Fq -- '--environment must be production' \
	"${AKB_TMP_DIR}/retired-environment.out"
[[ ! -e "${AKB_TMP_DIR}/must-not-capture.json" ]]

node "${AKB_REPO_ROOT}/ops/test-seo-editorial-curated-excerpts-quality-manifest.mjs"

node - "${AKB_REPO_ROOT}" "${AKB_TMP_DIR}/capture.json" <<'NODE'
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const [root, output] = process.argv.slice(2);
const files = [
	'seo-editorial-curated-excerpts-quality-manifest.json',
	'seo-editorial-shipping-quality-manifest.json',
	'seo-editorial-followup-quality-manifest.json',
	'seo-editorial-orthography-quality-manifest.json',
];
const rows = files
	.flatMap((file) => JSON.parse(fs.readFileSync(path.join(root, 'ops', file), 'utf8')).entries)
	.map((entry) => ({
		key: entry.key,
		storage: entry.object_type === 'term_taxonomy' ? 'term_taxonomy' : 'posts',
		object_id: entry.object_id,
		object_type: entry.object_type,
		public_type: entry.object_type === 'term_taxonomy' ? 'product_cat' : 'product',
		field: entry.field,
		value_sha256: crypto.createHash('sha256').update(entry.expected_before).digest('hex'),
		value: entry.expected_before,
	}))
	.sort((left, right) => left.key.localeCompare(right.key));
const semanticFixtures = new Map([
	[
		'post:21627:post_excerpt',
		'<p class="font-claude-response-body break-words whitespace-normal leading-[1.7]">Tokyo Revengers Tomo 2 Takemichi Hanagaki, un fracasado de 26 años, descubre que su ex novia Hinata fue asesinada por la Tokyo Manji-kai. Tras ser empujado a las vías del tren, viaja 12 años al pasado a su época de secundaria. Con el poder de cambiar el futuro, Takemichi intentará salvar a Hinata y corregir los errores que arruinaron su vida, enfrentándose al peligroso mundo de las pandillas. Obra de Ken Wakui que revolucionó el shonen moderno con su mezcla de acción, drama y viajes en el tiempo. Envío a todo Chile desde Akibara.</p>',
	],
	[
		'product:15418:post_excerpt',
		'Edición de lujo 2 en 1 de Ivrea Argentina. Envío a todo Chile desde Akibara.',
	],
]);
for (const row of rows) {
	if (!semanticFixtures.has(row.key)) {
		continue;
	}
	row.value = semanticFixtures.get(row.key);
	row.value_sha256 = crypto.createHash('sha256').update(row.value).digest('hex');
}
const capture = {
	schema_version: 1,
	environment: 'docker-local',
	site_url: 'http://localhost:8080',
	captured_at: new Date().toISOString(),
	source: 'local test fixture',
	database_mutations: 0,
	pii_fields_captured: [],
	manifest_files: files,
	row_count: rows.length,
	rows_sha256: crypto.createHash('sha256').update(JSON.stringify(rows)).digest('hex'),
	rows,
};
fs.writeFileSync(output, `${JSON.stringify(capture, null, 2)}\n`);
NODE

node - "${AKB_TMP_DIR}/capture.json" "${AKB_TMP_DIR}/invalid-origin.json" <<'NODE'
const fs = require('node:fs');
const [source, target] = process.argv.slice(2);
const capture = JSON.parse(fs.readFileSync(source, 'utf8'));
capture.site_url = 'https://invalid.example';
fs.writeFileSync(target, `${JSON.stringify(capture, null, 2)}\n`);
NODE

if node "${AKB_GENERATOR}" \
	--snapshot "${AKB_TMP_DIR}/invalid-origin.json" \
	--report "${AKB_TMP_DIR}/invalid-origin-report.json" \
	--ui-manifest "${AKB_TMP_DIR}/invalid-origin-ui.json" \
	>"${AKB_TMP_DIR}/invalid-origin.out" 2>&1
then
	echo "FAIL: generator accepted an environment/origin mismatch" >&2
	exit 1
fi
grep -Fq 'capture snapshot integrity failed' \
	"${AKB_TMP_DIR}/invalid-origin.out"
[[ ! -e "${AKB_TMP_DIR}/invalid-origin-report.json" ]]
[[ ! -e "${AKB_TMP_DIR}/invalid-origin-ui.json" ]]

node "${AKB_GENERATOR}" \
	--snapshot "${AKB_TMP_DIR}/capture.json" \
	--report "${AKB_TMP_DIR}/report.json" \
	--ui-manifest "${AKB_TMP_DIR}/ui.json"

node - "${AKB_REPO_ROOT}" "${AKB_TMP_DIR}/report.json" "${AKB_TMP_DIR}/ui.json" <<'NODE'
const fs = require('node:fs');
const path = require('node:path');
const [root, reportPath, uiPath] = process.argv.slice(2);
const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
const ui = JSON.parse(fs.readFileSync(uiPath, 'utf8'));
const reviewed = JSON.parse(
	fs.readFileSync(path.join(root, 'ops', 'seo-editorial-shipping-quality-manifest.json'), 'utf8'),
).entries.find((entry) => entry.key === 'post:21627:post_excerpt');
const exactReviewedEntry = ui.entries.find(
	(entry) => entry.key === 'post:21627:post_excerpt',
);
const mobExcerptEntry = ui.entries.find(
	(entry) => entry.key === 'product:15418:post_excerpt',
);
const termEntry = ui.entries.find(
	(entry) => entry.key === 'term_taxonomy:28:description',
);
const mobReviewed = JSON.parse(
	fs.readFileSync(path.join(root, 'ops', 'seo-editorial-followup-quality-manifest.json'), 'utf8'),
).entries.find((entry) => entry.key === 'product:15418:post_excerpt');
if (
	report.result !== 'PASS'
	|| report.counts.pending !== 92
	|| report.counts.drift !== 0
	|| report.counts.semantic_reviewed !== 2
) {
	throw new Error('reviewed before fixture did not produce 92 safe pending entries');
}
if (
	!exactReviewedEntry
	|| exactReviewedEntry.proposed_after !== reviewed.proposed_after
	|| exactReviewedEntry.proposed_sha256 !== reviewed.proposed_after_sha256
	|| exactReviewedEntry.reviewed_after_sha256 !== reviewed.proposed_after_sha256
	|| exactReviewedEntry.exact_reviewed_target_preserved !== true
	|| exactReviewedEntry.semantic_review?.strategy
		!== 'reviewed_product_specific_condensation_exact_reviewed_html'
	|| exactReviewedEntry.semantic_review?.review_version !== 2
	|| exactReviewedEntry.semantic_review?.exact_reviewed_target_used !== true
	|| exactReviewedEntry.semantic_review?.semantic_scope_exact !== true
	|| exactReviewedEntry.semantic_review?.reviewed_target_sha256
		!== reviewed.proposed_after_sha256
	|| !exactReviewedEntry.proposed_after.includes('<strong>Tokyo Revengers Tomo 2</strong>')
	|| !exactReviewedEntry.proposed_after.includes('<strong>Ken Wakui</strong>')
	|| exactReviewedEntry.proposed_after.includes('font-claude-response-body')
	|| exactReviewedEntry.semantic_review.checks.some((check) => check.passed !== true)
	|| mobExcerptEntry?.proposed_after !== mobReviewed.proposed_after
	|| mobExcerptEntry?.proposed_sha256 !== mobReviewed.proposed_after_sha256
	|| mobExcerptEntry?.semantic_review?.strategy
		!== 'reviewed_exact_curated_product_excerpt_from_pinned_legacy_source'
	|| mobExcerptEntry?.semantic_review?.exact_reviewed_target_used !== true
	|| mobExcerptEntry?.semantic_review?.reviewed_target_sha256
		!== mobReviewed.proposed_after_sha256
	|| mobExcerptEntry?.semantic_review?.checks.some((check) => check.passed !== true)
	|| termEntry?.official_ui_serialization
		!== 'html_textarea_initial_lf_normalized'
	|| termEntry?.observed_before.startsWith('\n')
	|| termEntry?.proposed_after.startsWith('\n')
	|| report.assertions.all_exact_reviewed_targets_preserved !== true
	|| ui.entry_count !== 92
	|| !ui.entries.every((entry) =>
		entry.html_outside_approved_transformations_preserved
		&& entry.url_destinations_preserved
		&& entry.observed_before !== entry.proposed_after)
) {
	throw new Error('UI manifest preservation assertions failed');
}
NODE

node "${AKB_VALIDATOR}" \
	--snapshot "${AKB_TMP_DIR}/capture.json" \
	--report "${AKB_TMP_DIR}/report.json" \
	--ui-manifest "${AKB_TMP_DIR}/ui.json" \
	--expect-semantic 2 >/dev/null

node - "${AKB_TMP_DIR}/capture.json" "${AKB_TMP_DIR}/production-fixture.json" <<'NODE'
const fs = require('node:fs');
const [source, target] = process.argv.slice(2);
const capture = JSON.parse(fs.readFileSync(source, 'utf8'));
capture.environment = 'production';
capture.site_url = 'https://akibara.cl';
fs.writeFileSync(target, `${JSON.stringify(capture, null, 2)}\n`);
NODE

node "${AKB_GENERATOR}" \
	--snapshot "${AKB_TMP_DIR}/production-fixture.json" \
	--report "${AKB_TMP_DIR}/production-report.json" \
	--ui-manifest "${AKB_TMP_DIR}/production-ui.json" >/dev/null
node "${AKB_VALIDATOR}" \
	--snapshot "${AKB_TMP_DIR}/production-fixture.json" \
	--report "${AKB_TMP_DIR}/production-report.json" \
	--ui-manifest "${AKB_TMP_DIR}/production-ui.json" \
	--expect-semantic 2 >/dev/null
node - "${AKB_TMP_DIR}/production-report.json" "${AKB_TMP_DIR}/production-ui.json" <<'NODE'
const fs = require('node:fs');
const [reportPath, uiPath] = process.argv.slice(2);
const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
const ui = JSON.parse(fs.readFileSync(uiPath, 'utf8'));
if (
	report.environment !== 'production'
	|| ui.environment !== 'production'
	|| report.result !== 'PASS'
	|| ui.result !== 'PASS'
) {
	throw new Error('exact production fixture did not preserve its environment');
}
NODE

node - "${AKB_TMP_DIR}/capture.json" "${AKB_TMP_DIR}/drift.json" <<'NODE'
const crypto = require('node:crypto');
const fs = require('node:fs');
const [source, target] = process.argv.slice(2);
const capture = JSON.parse(fs.readFileSync(source, 'utf8'));
const row = capture.rows.find((item) => item.key === 'product:15418:post_excerpt');
row.value += ' CAMBIO POSTERIOR NO REVISADO';
row.value_sha256 = crypto.createHash('sha256').update(row.value).digest('hex');
capture.rows_sha256 = crypto.createHash('sha256').update(JSON.stringify(capture.rows)).digest('hex');
fs.writeFileSync(target, `${JSON.stringify(capture, null, 2)}\n`);
NODE

if node "${AKB_GENERATOR}" \
	--snapshot "${AKB_TMP_DIR}/drift.json" \
	--report "${AKB_TMP_DIR}/drift-report.json" \
	--ui-manifest "${AKB_TMP_DIR}/must-not-exist.json" >/dev/null 2>&1; then
	echo "FAIL: generator accepted unapproved content divergence" >&2
	exit 1
fi
if [[ ! -e "${AKB_TMP_DIR}/must-not-exist.json" ]]; then
	echo "FAIL: generator did not publish the safe UI subset" >&2
	exit 1
fi
node - "${AKB_TMP_DIR}/must-not-exist.json" <<'NODE'
const fs = require('node:fs');
const manifest = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
if (
	manifest.result !== 'PARTIAL_BLOCKED'
	|| manifest.complete !== false
	|| manifest.fail_closed !== true
	|| manifest.blocked_count !== 1
	|| manifest.entry_count !== 91
) {
	throw new Error('semantic source hash drift did not exclude exactly the blocked entry');
}
NODE

node - "${AKB_REPO_ROOT}" "${AKB_TMP_DIR}" <<'NODE'
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const [root, temp] = process.argv.slice(2);
const files = [
	'seo-editorial-curated-excerpts-quality-manifest.json',
	'seo-editorial-shipping-quality-manifest.json',
	'seo-editorial-followup-quality-manifest.json',
	'seo-editorial-orthography-quality-manifest.json',
];
const cases = new Map([
	[
		'unbalanced_html',
		(entry) => {
			entry.proposed_after = '<p>Compra <strong>Tokyo Revengers Tomo 2</strong> de <strong>Ken Wakui</strong> en Akibara.</p></strong>';
		},
	],
	[
		'missing_identity',
		(entry) => {
			entry.proposed_after = '<p>Compra este manga de <strong>Ken Wakui</strong> en Akibara. Despacho sujeto a cobertura.</p>';
		},
	],
	[
		'changed_urls',
		(entry) => {
			entry.proposed_after = '<p>Compra <strong>Tokyo Revengers Tomo 2</strong> de <strong>Ken Wakui</strong> en <a href="https://example.com/">Akibara</a>.</p>';
		},
	],
	[
		'over_320_visible_chars',
		(entry) => {
			entry.proposed_after = `<p><strong>Tokyo Revengers Tomo 2</strong> de <strong>Ken Wakui</strong> ${'contenido '.repeat(40)}</p>`;
		},
	],
	[
		'out_of_scope_field',
		(entry) => {
			entry.field = 'post_content';
		},
	],
]);
for (const [name, mutate] of cases) {
	const caseDir = path.join(temp, `invalid-${name}`, 'ops');
	fs.mkdirSync(caseDir, { recursive: true });
	fs.copyFileSync(
		path.join(root, 'ops', 'generate-seo-editorial-environment-ui-manifest.mjs'),
		path.join(caseDir, 'generate-seo-editorial-environment-ui-manifest.mjs'),
	);
	for (const file of files) {
		const manifest = JSON.parse(fs.readFileSync(path.join(root, 'ops', file), 'utf8'));
		if (file === 'seo-editorial-shipping-quality-manifest.json') {
			const entry = manifest.entries.find(
				(item) => item.key === 'post:21627:post_excerpt',
			);
			mutate(entry);
			entry.proposed_after_sha256 = crypto
				.createHash('sha256')
				.update(entry.proposed_after)
				.digest('hex');
			entry.proposed_visible_chars = entry.proposed_after
				.replace(/<!--[\s\S]*?-->/gu, ' ')
				.replace(/<[^>]+>/gu, ' ')
				.replace(/\s+/gu, ' ')
				.trim()
				.length;
		}
		fs.writeFileSync(
			path.join(caseDir, file),
			`${JSON.stringify(manifest, null, 2)}\n`,
		);
	}
}
NODE

for AKB_INVALID_CASE in \
	unbalanced_html \
	missing_identity \
	changed_urls \
	over_320_visible_chars \
	out_of_scope_field
do
	AKB_INVALID_DIR="${AKB_TMP_DIR}/invalid-${AKB_INVALID_CASE}"
	if node "${AKB_INVALID_DIR}/ops/generate-seo-editorial-environment-ui-manifest.mjs" \
		--snapshot "${AKB_TMP_DIR}/capture.json" \
		--report "${AKB_INVALID_DIR}/report.json" \
		--ui-manifest "${AKB_INVALID_DIR}/ui.json" >/dev/null 2>&1
	then
		echo "FAIL: generator accepted invalid reviewed target ${AKB_INVALID_CASE}" >&2
		exit 1
	fi
	node - "${AKB_INVALID_CASE}" "${AKB_INVALID_DIR}/report.json" "${AKB_INVALID_DIR}/ui.json" <<'NODE'
const fs = require('node:fs');
const [name, reportPath, uiPath] = process.argv.slice(2);
const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
const ui = JSON.parse(fs.readFileSync(uiPath, 'utf8'));
const blocked = report.rows.find((row) => row.key === 'post:21627:post_excerpt');
if (
	report.result !== 'FAIL'
	|| report.counts.drift !== 1
	|| report.counts.content_divergent !== 1
	|| blocked?.state !== 'content_divergent'
	|| ui.result !== 'PARTIAL_BLOCKED'
	|| ui.complete !== false
	|| ui.fail_closed !== true
	|| ui.blocked_count !== 1
		|| ui.entry_count !== 91
	|| ui.entries.some((entry) => entry.key === 'post:21627:post_excerpt')
) {
	throw new Error(`invalid reviewed target did not fail closed: ${name}`);
}
NODE
done

echo "PASS: production-only remote capture, Docker-local fixtures, exact reviewed HTML, invariant gates, exact/rebased transformations and fail-closed drift"
