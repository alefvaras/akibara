#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/akb-rankmath-ui-test.XXXXXX")"
AKB_EXPECTED_TOTAL="$(node -e "import('file://${AKB_REPO_ROOT}/ops/compare-rankmath-redirections-snapshot.mjs').then((m) => console.log(m.EXPECTED_TARGET.total))")"

akb_cleanup() {
	case "${AKB_TMP_DIR}" in
		*/akb-rankmath-ui-test.*) rm -rf -- "${AKB_TMP_DIR}" ;;
	esac
}
trap akb_cleanup EXIT

node - "${AKB_REPO_ROOT}" "${AKB_TMP_DIR}/snapshot.json" <<'NODE'
const fs = require('node:fs');
const root = process.argv[2];
const output = process.argv[3];
const csv = fs.readFileSync(`${root}/ops/rankmath-redirections-ui-final.csv`, 'utf8');
const parse = (text) => {
	const rows = []; let row = []; let cell = ''; let quoted = false;
	for (let i = 0; i < text.length; i += 1) {
		const c = text[i];
		if (quoted) {
			if (c === '"' && text[i + 1] === '"') { cell += '"'; i += 1; }
			else if (c === '"') quoted = false;
			else cell += c;
		} else if (c === '"') quoted = true;
		else if (c === ',') { row.push(cell); cell = ''; }
		else if (c === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; }
		else if (c !== '\r') cell += c;
	}
	const [headers, ...values] = rows.filter(r => r.some(Boolean));
	return values.map(cells => Object.fromEntries(headers.map((h, i) => [h, cells[i]])));
};
const snapshot = parse(csv).map((rule, index) => ({
	id: 9000 + index,
	sources: [{
		pattern: rule.source,
		comparison: rule.matching,
		ignore: rule.ignore,
	}],
	url_to: rule.destination,
	header_code: rule.type,
	status: rule.status,
}));
fs.writeFileSync(output, `${JSON.stringify(snapshot, null, 2)}\n`);
NODE

node "${AKB_REPO_ROOT}/ops/compare-rankmath-redirections-snapshot.mjs" \
	--snapshot "${AKB_TMP_DIR}/snapshot.json" \
	--target "${AKB_REPO_ROOT}/ops/rankmath-redirections-ui-final.csv" \
	--output-dir "${AKB_TMP_DIR}/comparison" \
	--expect clean >/dev/null

node "${AKB_REPO_ROOT}/ops/generate-rankmath-redirections-official-ui-plan.mjs" \
	--comparison "${AKB_TMP_DIR}/comparison/comparison.json" \
	--target "${AKB_REPO_ROOT}/ops/rankmath-redirections-ui-final.csv" \
	--environment docker \
	--output "${AKB_TMP_DIR}/plan.json" >"${AKB_TMP_DIR}/generation.json"

node "${AKB_REPO_ROOT}/ops/run-rankmath-redirections-official-ui-playwright.mjs" \
	--plan "${AKB_TMP_DIR}/plan.json" \
	--validate-only >"${AKB_TMP_DIR}/validation.json"

node - "${AKB_TMP_DIR}/plan.json" "${AKB_TMP_DIR}/validation.json" "${AKB_REPO_ROOT}" <<'NODE'
const fs = require('node:fs');
const [planPath, validationPath, repoRoot] = process.argv.slice(2);
// Los recuentos se DERIVAN del gate; nunca se escriben como literales aquí.
const { EXPECTED_TARGET } = require(`${repoRoot}/ops/compare-rankmath-redirections-snapshot.mjs`);
const plan = JSON.parse(fs.readFileSync(planPath, 'utf8'));
const validation = JSON.parse(fs.readFileSync(validationPath, 'utf8'));
const kagurabachi = plan.rules.find(
	(row) => row.logical_key === 'exact|kagurabachi-01-copia'
);
const chainsawMan5 = plan.rules.find(
	(row) => row.logical_key === 'exact|producto/chainsaw-man-vol-5'
);
const chainsawMan1 = plan.rules.find(
	(row) => row.logical_key === 'exact|producto/chainsaw-man-tomo-01'
);
const onePiece4 = plan.rules.find(
	(row) => row.logical_key === 'exact|producto/one-piece-n-4-3-en-1'
);
const jujutsuKaisen1 = plan.rules.find(
	(row) => row.logical_key === 'exact|tienda/manga/jujutsu-kaisen-vol-1'
);
if (
	validation.result !== 'PASS'
	|| plan.rules.length !== EXPECTED_TARGET.total
	|| new Set(plan.rules.map(row => row.logical_key)).size !== EXPECTED_TARGET.total
	|| plan.counts.status.active !== EXPECTED_TARGET.status.active
	|| plan.counts.matching.exact !== EXPECTED_TARGET.matching.exact
	|| plan.counts.matching.regex !== EXPECTED_TARGET.matching.regex
	|| plan.counts.ignore.case !== EXPECTED_TARGET.ignore.case
	|| Object.keys(plan.counts.ignore).length !== Object.keys(EXPECTED_TARGET.ignore).length
	|| plan.counts.type['301'] !== EXPECTED_TARGET.type['301']
		|| plan.counts.type['410'] !== EXPECTED_TARGET.type['410']
		|| plan.application_policy.official_administration_ui_only !== true
		|| plan.application_policy.source_ignore_contract !== 'literal_case_only'
		|| plan.application_policy.legacy_ignore_values_normalized_via_official_checkbox !== true
		|| plan.application_policy.logical_identity_uses_ids !== false
		|| plan.application_policy.global_inventory_before_each_phase !== true
		|| plan.application_policy.row_payload_revalidation_immediately_before_click !== true
		|| plan.application_policy.reviewed_malformed_actions !== 'trash_only'
		|| plan.application_policy.reviewed_malformed_identity !== 'visible_payload_sha256'
		|| plan.application_policy.direct_rest_writes !== 0
	|| plan.application_policy.direct_ajax_writes !== 0
	|| plan.application_policy.direct_sql_writes !== 0
		|| plan.application_policy.clear_404_monitor_via_official_button !== true
		|| plan.application_policy.commercial_history_mutations !== 0
		|| plan.reviewed_malformed.length !== 0
		|| !validation.generated_browser_functions.includes('trash-batch')
		|| !validation.generated_browser_functions.includes('inventory-404')
		|| validation.synthetic_reconciliation.resumed_actions !== 4
		|| validation.synthetic_reconciliation.drift_fail_closed !== true
		|| validation.synthetic_reconciliation.ignore_drift_detected !== true
		|| validation.ignore_checkbox_guarded !== true
		|| validation.synthetic_404_inventory.multi_page_total !== 242
		|| validation.synthetic_404_inventory.mismatch_fail_closed !== true
		|| validation.synthetic_404_inventory.empty_inventory_valid !== true
		|| kagurabachi?.destination !== '/kagurabachi-1-ivrea-argentina/'
		|| chainsawMan5?.destination !== '/chainsaw-man-5-ivrea-argentina/'
		|| chainsawMan1?.destination !== '/chainsaw-man-1-ivrea-argentina/'
		|| onePiece4?.destination !== '/one-piece-no-4-3-en-1-planeta-espana/'
		|| jujutsuKaisen1?.destination !== '/jujutsu-kaisen-1-panini-argentina/'
	) {
	throw new Error('official UI redirection plan coverage or guardrails failed');
}
NODE

node - "${AKB_TMP_DIR}/snapshot.json" "${AKB_TMP_DIR}/snapshot-reviewed-malformed.json" <<'NODE'
const fs = require('node:fs');
const [input, output] = process.argv.slice(2);
const snapshot = JSON.parse(fs.readFileSync(input, 'utf8'));
snapshot.push(
	{
		id: 223,
		sources: false,
		url_to: 'http://localhost:8080/mi-cuenta/',
		header_code: '301',
		status: 'active',
		created: '2026-04-28 03:24:44',
		updated: '2026-04-28 03:24:44',
		last_accessed: '0000-00-00 00:00:00',
		hits: '0',
	},
	{
		id: 228,
		sources: false,
		url_to: 'http://localhost:8080/mi-cuenta/',
		header_code: '301',
		status: 'active',
		created: '2026-05-04 01:25:12',
		updated: '2026-05-04 01:25:12',
		last_accessed: '0000-00-00 00:00:00',
		hits: '0',
	},
);
fs.writeFileSync(output, `${JSON.stringify(snapshot, null, 2)}\n`);
NODE

node "${AKB_REPO_ROOT}/ops/compare-rankmath-redirections-snapshot.mjs" \
	--snapshot "${AKB_TMP_DIR}/snapshot-reviewed-malformed.json" \
	--target "${AKB_REPO_ROOT}/ops/rankmath-redirections-ui-final.csv" \
	--output-dir "${AKB_TMP_DIR}/comparison-reviewed-malformed" \
	--expect drift >/dev/null

node "${AKB_REPO_ROOT}/ops/generate-rankmath-redirections-official-ui-plan.mjs" \
	--comparison "${AKB_TMP_DIR}/comparison-reviewed-malformed/comparison.json" \
	--target "${AKB_REPO_ROOT}/ops/rankmath-redirections-ui-final.csv" \
	--environment docker \
	--output "${AKB_TMP_DIR}/plan-reviewed-malformed.json" >/dev/null

node "${AKB_REPO_ROOT}/ops/run-rankmath-redirections-official-ui-playwright.mjs" \
	--plan "${AKB_TMP_DIR}/plan-reviewed-malformed.json" \
	--validate-only >"${AKB_TMP_DIR}/validation-reviewed-malformed.json"

node - "${AKB_TMP_DIR}/plan-reviewed-malformed.json" "${AKB_TMP_DIR}/validation-reviewed-malformed.json" <<'NODE'
const fs = require('node:fs');
const plan = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const validation = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
if (
	plan.reviewed_malformed.length !== 1
	|| plan.expected.reviewed_malformed_to_trash !== 2
	|| plan.reviewed_malformed.some(row =>
		row.action !== 'trash'
		|| row.kind !== 'reviewed_malformed'
		|| row.expected_count !== 2
		|| !/^[a-f0-9]{64}$/u.test(row.payload_sha256))
	|| JSON.stringify(plan.reviewed_malformed).includes('"id"')
	|| validation.synthetic_reconciliation.reviewed_malformed_transition !== true
) {
	throw new Error('reviewed malformed payload fingerprint guard failed');
}
NODE

if node "${AKB_REPO_ROOT}/ops/run-rankmath-redirections-official-ui-playwright.mjs" \
	--plan "${AKB_TMP_DIR}/plan-reviewed-malformed.json" \
	--base-url http://localhost:8080 \
	--session docker-preflight \
	--checkpoint "${AKB_TMP_DIR}/docker-apply-checkpoint.json" \
	--apply >"${AKB_TMP_DIR}/docker-apply.out" 2>&1; then
	echo "FAIL: Docker redirection plan accepted --apply" >&2
	exit 1
fi
grep -Fq -- '--apply is available only for production plans' \
	"${AKB_TMP_DIR}/docker-apply.out"

if node "${AKB_REPO_ROOT}/ops/generate-rankmath-redirections-official-ui-plan.mjs" \
	--comparison "${AKB_TMP_DIR}/comparison/comparison.json" \
	--target "${AKB_REPO_ROOT}/ops/rankmath-redirections-ui-final.csv" \
	--environment qa \
	--output "${AKB_TMP_DIR}/retired-qa-plan.json" \
	>"${AKB_TMP_DIR}/retired-qa-plan.out" 2>&1; then
	echo "FAIL: retired QA redirection plan generation remained executable" >&2
	exit 1
fi
grep -Fq 'unsupported environment qa' "${AKB_TMP_DIR}/retired-qa-plan.out"
[[ ! -e "${AKB_TMP_DIR}/retired-qa-plan.json" ]]

if rg -n 'page\.request|context\.request|request\.(post|put|delete)|["'"'"']/wp-json|["'"'"']admin-ajax\.php' \
	"${AKB_REPO_ROOT}/ops/run-rankmath-redirections-official-ui-playwright.mjs"; then
	echo "FAIL: prohibited direct channel found in runner" >&2
	exit 1
fi
if ! rg -Fq 'Playwright browser function failed' \
	"${AKB_REPO_ROOT}/ops/run-rankmath-redirections-official-ui-playwright.mjs"; then
	echo "FAIL: Playwright browser errors would be hidden as undefined results" >&2
	exit 1
fi
if ! rg -Fq 'await row.hover()' \
	"${AKB_REPO_ROOT}/ops/run-rankmath-redirections-official-ui-playwright.mjs"; then
	echo "FAIL: hidden WordPress row actions are clicked without revealing them" >&2
	exit 1
fi

echo "PASS: ${AKB_EXPECTED_TOTAL} rules, production-only apply, Docker-safe normalization/preflight, retired-QA rejection, exact malformed fingerprints, phase inventories, global 404 pagination, pre-click payload revalidation and resume"
