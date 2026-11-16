#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/akb-seo-official-ui-test.XXXXXX")"

akb_cleanup() {
	case "${AKB_TMP_DIR}" in
		*/akb-seo-official-ui-test.*) rm -rf -- "${AKB_TMP_DIR}" ;;
	esac
}
trap akb_cleanup EXIT

node --test \
	"${AKB_REPO_ROOT}/ops/test-wordpress-block-editor-official-ui.mjs"

node - \
	"${AKB_TMP_DIR}/editorial-retired.json" \
	"${AKB_TMP_DIR}/editorial-production.json" <<'NODE'
const crypto = require('node:crypto');
const fs = require('node:fs');
const [retiredOutput, productionOutput] = process.argv.slice(2);
const sha256 = (value) => crypto.createHash('sha256').update(value).digest('hex');
const entries = [];
for (let index = 0; index < 77; index += 1) {
	const field = index < 72 ? 'post_excerpt' : 'post_content';
	const before = `Producto ${index} antes`;
	const after = `Producto ${index} después`;
	entries.push({
		key: `post:${1000 + index}:${field}`,
		object_type: 'post',
		object_id: 1000 + index,
		field,
		title: `Producto ${index}`,
		application_lane: 'official_ui',
		apply_via: field === 'post_excerpt'
			? 'WooCommerce > Productos > Editar producto > Descripción corta del producto > Actualizar'
			: 'WooCommerce > Productos > Editar producto > descripción > Actualizar',
		observed_sha256: sha256(before),
		proposed_sha256: sha256(after),
		observed_before: before,
		proposed_after: after,
	});
}
for (let index = 0; index < 14; index += 1) {
	const field = index < 2 ? 'post_excerpt' : 'post_content';
	const before = `Entrada ${index} antes`;
	const after = `Entrada ${index} después`;
	entries.push({
		key: `post:${2000 + index}:${field}`,
		object_type: 'post',
		object_id: 2000 + index,
		field,
		title: `Entrada ${index}`,
		application_lane: 'official_ui',
		apply_via: field === 'post_excerpt'
			? `WordPress > Entradas > Entrada ${index} > Extracto > Actualizar`
			: `WordPress > Entradas > Entrada ${index} > Editor de código > Actualizar`,
		observed_sha256: sha256(before),
		proposed_sha256: sha256(after),
		observed_before: before,
		proposed_after: after,
	});
}
{
	const before = 'Categoría antes';
	const after = 'Categoría después';
	entries.push({
		key: 'term_taxonomy:3000:description',
		object_type: 'term_taxonomy',
		object_id: 3000,
		field: 'description',
		title: 'Manga',
		application_lane: 'official_ui',
		apply_via: 'WooCommerce > Productos > Categorías > Manga > Descripción > Actualizar',
		observed_sha256: sha256(before),
		proposed_sha256: sha256(after),
		observed_before: before,
		proposed_after: after,
	});
}
const manifest = {
	schema_version: 1,
	result: 'PASS',
	complete: true,
	application_lane: 'official_ui',
	database_mutations: 0,
	environment: 'retired',
	entry_count: entries.length,
	entries,
};
fs.writeFileSync(retiredOutput, `${JSON.stringify(manifest, null, 2)}\n`);
fs.writeFileSync(
	productionOutput,
	`${JSON.stringify({ ...manifest, environment: 'production' }, null, 2)}\n`
);
NODE

node "${AKB_REPO_ROOT}/ops/generate-seo-official-ui-plan.mjs" \
	--environment production \
	--rankmath-manifest "${AKB_REPO_ROOT}/ops/rankmath-shipping-copy-ui-manifest.json" \
	--editorial-manifest "${AKB_TMP_DIR}/editorial-production.json" \
	--output "${AKB_TMP_DIR}/plan.json" >/dev/null

node "${AKB_REPO_ROOT}/ops/run-seo-official-ui-playwright.mjs" \
	--plan "${AKB_TMP_DIR}/plan.json" \
	--validate-only >"${AKB_TMP_DIR}/validation.json"

node - "${AKB_TMP_DIR}/plan.json" "${AKB_TMP_DIR}/validation.json" <<'NODE'
const fs = require('node:fs');
const [planPath, validationPath] = process.argv.slice(2);
const plan = JSON.parse(fs.readFileSync(planPath, 'utf8'));
const validation = JSON.parse(fs.readFileSync(validationPath, 'utf8'));
const expectedLanes = {
	product_classic_editor: 77,
	rankmath_inline_description: 867,
	rankmath_term_description: 4,
	term_editor: 1,
	wordpress_block_editor: 14,
};
if (
	validation.result !== 'PASS'
	|| validation.selected_items !== 963
	|| plan.counts.total !== 963
	|| plan.counts.rankmath !== 871
	|| plan.counts.editorial !== 92
	|| JSON.stringify(plan.counts.by_lane) !== JSON.stringify(expectedLanes)
	|| plan.counts.editorial_by_field.post_content !== 17
	|| plan.counts.editorial_by_field.post_excerpt !== 74
	|| plan.counts.editorial_by_field.description !== 1
	|| plan.application_policy.official_administration_ui_only !== true
	|| plan.application_policy.remote_import_files !== 0
	|| plan.application_policy.woocommerce_csv_importer
		!== 'rejected_remote_artifact_cleanup_not_automatic'
) {
	throw new Error('official UI plan coverage or guardrails failed');
}
NODE

if node "${AKB_REPO_ROOT}/ops/generate-seo-official-ui-plan.mjs" \
	--environment docker \
	--rankmath-manifest "${AKB_REPO_ROOT}/ops/rankmath-shipping-copy-ui-manifest.json" \
	--editorial-manifest "${AKB_TMP_DIR}/editorial-retired.json" \
	--output "${AKB_TMP_DIR}/docker-plan-from-retired.json" \
	>"${AKB_TMP_DIR}/docker-plan-from-retired.out" 2>&1; then
	echo "FAIL: Docker accepted a retired-environment editorial manifest" >&2
	exit 1
fi
if ! grep -Fq \
	"Docker must use the production-source editorial manifest" \
	"${AKB_TMP_DIR}/docker-plan-from-retired.out"; then
	echo "FAIL: Docker retired-source rejection did not explain the source contract" >&2
	exit 1
fi
if [[ -e "${AKB_TMP_DIR}/docker-plan-from-retired.json" ]]; then
	echo "FAIL: rejected Docker retired-source generation left a plan artifact" >&2
	exit 1
fi

if node "${AKB_REPO_ROOT}/ops/generate-seo-official-ui-plan.mjs" \
	--environment qa \
	--rankmath-manifest "${AKB_REPO_ROOT}/ops/rankmath-shipping-copy-ui-manifest.json" \
	--editorial-manifest "${AKB_TMP_DIR}/editorial-production.json" \
	--output "${AKB_TMP_DIR}/retired-qa-plan.json" \
	>"${AKB_TMP_DIR}/retired-qa-plan.out" 2>&1; then
	echo "FAIL: retired QA plan generation remained executable" >&2
	exit 1
fi
grep -Fq 'unsupported environment qa' "${AKB_TMP_DIR}/retired-qa-plan.out"
[[ ! -e "${AKB_TMP_DIR}/retired-qa-plan.json" ]]

node "${AKB_REPO_ROOT}/ops/generate-seo-official-ui-plan.mjs" \
	--environment docker \
	--rankmath-manifest "${AKB_REPO_ROOT}/ops/rankmath-shipping-copy-ui-manifest.json" \
	--editorial-manifest "${AKB_TMP_DIR}/editorial-production.json" \
	--output "${AKB_TMP_DIR}/docker-plan.json" >/dev/null

node "${AKB_REPO_ROOT}/ops/run-seo-official-ui-playwright.mjs" \
	--plan "${AKB_TMP_DIR}/docker-plan.json" \
	--validate-only >"${AKB_TMP_DIR}/docker-validation.json"

node - \
	"${AKB_TMP_DIR}/docker-plan.json" \
	"${AKB_TMP_DIR}/docker-validation.json" <<'NODE'
const fs = require('node:fs');
const [planPath, validationPath] = process.argv.slice(2);
const plan = JSON.parse(fs.readFileSync(planPath, 'utf8'));
const validation = JSON.parse(fs.readFileSync(validationPath, 'utf8'));
if (
	validation.result !== 'PASS'
	|| validation.environment !== 'docker'
	|| validation.selected_items !== 963
	|| plan.environment !== 'docker'
	|| plan.sources.editorial_manifest.source_environment !== 'production'
	|| plan.application_policy.docker_editorial_source
		!== 'production_clone_only'
) {
	throw new Error('Docker production-clone source contract failed');
}
NODE

if node "${AKB_REPO_ROOT}/ops/run-seo-official-ui-playwright.mjs" \
	--plan "${AKB_TMP_DIR}/docker-plan.json" \
	--apply >"${AKB_TMP_DIR}/docker-apply.out" 2>&1; then
	echo "FAIL: Docker SEO plan accepted --apply" >&2
	exit 1
fi
grep -Fq -- '--apply is available only for production plans' \
	"${AKB_TMP_DIR}/docker-apply.out"

node - \
	"${AKB_REPO_ROOT}/ops/run-seo-official-ui-playwright.mjs" \
	"${AKB_REPO_ROOT}/ops/lib/wordpress-block-editor-official-ui.mjs" <<'NODE'
const fs = require('node:fs');
const source = fs.readFileSync(process.argv[2], 'utf8');
const blockEditorSource = fs.readFileSync(process.argv[3], 'utf8');
const saveAll = source.indexOf('await saveAll.click();');
const persistentReload = source.indexOf('await navigate(target);', saveAll);
const persistentRead = source.indexOf('const persistedField', persistentReload);
if (
	!source.includes('page.setDefaultNavigationTimeout(75000)')
	|| !source.includes('for (let attempt = 1; attempt <= 2; attempt += 1)')
	|| source.split('async function navigate(target)').length - 1 !== 3
	|| !source.includes('timeout: 540_000')
	|| !source.includes("'&orderby=ID&order=asc'")
	|| !source.includes('LIST_CARDINALITY_CONTROL_MISSING')
	|| !source.includes('LIST_ID_ORDER_NOT_STABLE')
	|| !source.includes('listing ID order changed')
	|| !source.includes('listing cardinality changed during')
	|| !source.includes('listing cardinality changed after preflight')
	|| !source.includes('ensureWordPressBlockEditorField.toString()')
	|| !source.includes("item.field_selector + '-html'")
	|| !source.includes('CLASSIC_EDITOR_CODE_TAB_MISSING')
	|| !source.includes('CLASSIC_EDITOR_SAVE_HTTP_')
	|| !source.includes("response.request().method() === 'POST'")
	|| !source.includes('RANKMATH_TERM_SNIPPET_CLOSE_MISSING')
	|| !source.includes("snippetDialog.waitFor({ state: 'hidden' })")
	|| !blockEditorSource.includes('BLOCK_EDITOR_OPTIONS_NOT_ACTIONABLE')
	|| !blockEditorSource.includes('BLOCK_EDITOR_UNEXPECTED_DIALOG')
	|| saveAll < 0
	|| persistentReload < saveAll
	|| persistentRead < persistentReload
) {
	throw new Error('navigation or persistent-save verification guard regressed');
}
NODE

AKB_PLAN_SHA="$(
	node -p "JSON.parse(require('fs').readFileSync(process.argv[1], 'utf8')).plan_sha256" \
		"${AKB_TMP_DIR}/plan.json"
)"
if node "${AKB_REPO_ROOT}/ops/run-seo-official-ui-playwright.mjs" \
	--plan "${AKB_TMP_DIR}/plan.json" \
	--apply \
	--confirm-plan-sha256 "${AKB_PLAN_SHA}" \
	--limit 1 >"${AKB_TMP_DIR}/partial-apply.out" 2>&1; then
	echo "FAIL: --apply accepted a partial --limit run" >&2
	exit 1
fi

echo "PASS: 963 UI work items, production-only apply, Docker production-clone preflight, deterministic hashes, local checkpoints and no direct mutation channel"
