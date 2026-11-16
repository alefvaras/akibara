#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_RUNNER="${AKB_REPO_ROOT}/ops/run-rankmath-product-schema-official-ui-playwright.mjs"
AKB_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/akb-rankmath-product-schema-ui.XXXXXX")"

akb_cleanup() {
	case "${AKB_TMP_DIR}" in
		*/akb-rankmath-product-schema-ui.*) rm -rf -- "${AKB_TMP_DIR}" ;;
	esac
}
trap akb_cleanup EXIT

node --check "${AKB_RUNNER}"

node "${AKB_RUNNER}" \
	--environment docker \
	--base-url http://localhost:8080 \
	--session validate-only \
	--checkpoint "${AKB_TMP_DIR}/checkpoint.json" \
	--validate-only >"${AKB_TMP_DIR}/validation.json"

node - "${AKB_TMP_DIR}/validation.json" <<'NODE'
const fs = require('node:fs');
const validation = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
	if (
		validation.result !== 'PASS'
		|| validation.validate_only !== true
		|| validation.policy?.official_ui_only !== true
		|| validation.policy?.product_schema_write !== false
		|| validation.policy?.direct_api_writes !== 0
		|| validation.policy?.direct_sql_writes !== 0
		|| validation.policy?.resume_revalidates_ui !== true
	) {
	throw new Error('official UI product schema policy validation failed');
}
NODE

if node "${AKB_RUNNER}" \
	--environment production \
	--base-url http://localhost:8080 \
	--session invalid \
	--validate-only >"${AKB_TMP_DIR}/invalid.out" 2>&1; then
	echo "FAIL: production accepted a localhost base URL" >&2
	exit 1
fi

if node "${AKB_RUNNER}" \
	--environment qa \
	--base-url https://retired.invalid \
	--session invalid \
	--validate-only >"${AKB_TMP_DIR}/retired-qa.out" 2>&1; then
	echo "FAIL: retired QA product-schema environment remained executable" >&2
	exit 1
fi
grep -Fq -- '--environment must be docker or production' \
	"${AKB_TMP_DIR}/retired-qa.out"

AKB_FAKE_BIN="${AKB_TMP_DIR}/fake-bin"
AKB_FAKE_COUNTER="${AKB_TMP_DIR}/fake-npx-count"
mkdir -p "${AKB_FAKE_BIN}"
printf '0\n' >"${AKB_FAKE_COUNTER}"
cp \
	"${AKB_REPO_ROOT}/ops/test-fixtures/fake-playwright-cli-npx.sh" \
	"${AKB_FAKE_BIN}/npx"
chmod 700 "${AKB_FAKE_BIN}/npx"

PATH="${AKB_FAKE_BIN}:${PATH}" AKB_FAKE_COUNTER="${AKB_FAKE_COUNTER}" \
	node "${AKB_RUNNER}" \
		--environment docker \
		--base-url http://localhost:8080 \
		--session fake-resume \
		--checkpoint "${AKB_TMP_DIR}/resume-checkpoint.json" \
		>"${AKB_TMP_DIR}/first-run.json"

PATH="${AKB_FAKE_BIN}:${PATH}" AKB_FAKE_COUNTER="${AKB_FAKE_COUNTER}" \
	node "${AKB_RUNNER}" \
		--environment docker \
		--base-url http://localhost:8080 \
		--session fake-resume \
		--checkpoint "${AKB_TMP_DIR}/resume-checkpoint.json" \
		--resume >"${AKB_TMP_DIR}/resume-run.json"

node - \
	"${AKB_FAKE_COUNTER}" \
	"${AKB_TMP_DIR}/resume-run.json" <<'NODE'
const fs = require('node:fs');
const [counterPath, resultPath] = process.argv.slice(2);
const calls = Number(fs.readFileSync(counterPath, 'utf8').trim());
const result = JSON.parse(fs.readFileSync(resultPath, 'utf8'));
if (
	calls !== 4
	|| result.global?.revalidated !== true
	|| result.product?.revalidated !== true
) {
	throw new Error('resume trusted stale checkpoint instead of revalidating both UI phases');
}
NODE

printf '0\n' >"${AKB_FAKE_COUNTER}"
if PATH="${AKB_FAKE_BIN}:${PATH}" AKB_FAKE_COUNTER="${AKB_FAKE_COUNTER}" \
	node "${AKB_RUNNER}" \
		--environment docker \
		--base-url http://localhost:8080 \
		--session rejected-docker-save \
		--checkpoint "${AKB_TMP_DIR}/rejected-docker-save.json" \
		--save-global >"${AKB_TMP_DIR}/docker-save.out" 2>&1; then
	echo "FAIL: Docker accepted the Rank Math global UI save" >&2
	exit 1
fi
grep -Fq -- '--save-global is available only in production' \
	"${AKB_TMP_DIR}/docker-save.out"
[[ "$(<"${AKB_FAKE_COUNTER}")" == "0" ]]
[[ ! -e "${AKB_TMP_DIR}/rejected-docker-save.json" ]]

if rg -n \
	'page\.request|context\.request|request\.(post|put|delete)|fetch\(|XMLHttpRequest|/wp-json|admin-ajax\.php|wp post meta|mariadb|mysql ' \
	"${AKB_RUNNER}"; then
	echo "FAIL: prohibited direct write channel found in official UI runner" >&2
	exit 1
fi

if ! rg -n \
	'button\[role="tab"\]\.rank-math-schema-tab|GLOBAL_SCHEMA_NOT_WOOCOMMERCE_PRODUCT|SCHEMA_COUNT_NOT_ONE|SHORTCUT_LENGTH_MISMATCH|product_schema_write: false' \
	"${AKB_RUNNER}" >/dev/null; then
	echo "FAIL: required fail-closed assertions are missing" >&2
	exit 1
fi

echo "PASS: production-only Rank Math UI global save, Docker read-only schema gate, resume and retired-environment guards"
