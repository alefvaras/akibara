#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_MANIFEST="${AKB_REPO_ROOT}/ops/seo-editorial-orthography-quality-manifest.json"
AKB_VALIDATOR="${AKB_REPO_ROOT}/ops/validate-seo-editorial-orthography-quality-manifest.mjs"
AKB_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/akb-editorial-orthography-test.XXXXXX")"

akb_cleanup() {
	case "${AKB_TMP_DIR}" in
		*/akb-editorial-orthography-test.*) rm -rf -- "${AKB_TMP_DIR}" ;;
	esac
}
trap akb_cleanup EXIT

node "${AKB_VALIDATOR}" "${AKB_MANIFEST}" --live --expect=either

node - "${AKB_MANIFEST}" "${AKB_TMP_DIR}/drifted.json" <<'NODE'
const fs = require('node:fs');
const [source, target] = process.argv.slice(2);
const manifest = JSON.parse(fs.readFileSync(source, 'utf8'));
manifest.entries[0].expected_before += ' cambio editorial posterior';
fs.writeFileSync(target, `${JSON.stringify(manifest, null, 2)}\n`);
NODE

if node "${AKB_VALIDATOR}" "${AKB_TMP_DIR}/drifted.json" >/dev/null 2>&1; then
	echo "FAIL: validator accepted a stale expected-before value" >&2
	exit 1
fi

node - "${AKB_MANIFEST}" "${AKB_TMP_DIR}/bad-count.json" <<'NODE'
const fs = require('node:fs');
const [source, target] = process.argv.slice(2);
const manifest = JSON.parse(fs.readFileSync(source, 'utf8'));
manifest.entries[0].substitutions[0].expected_count += 1;
fs.writeFileSync(target, `${JSON.stringify(manifest, null, 2)}\n`);
NODE

if node "${AKB_VALIDATOR}" "${AKB_TMP_DIR}/bad-count.json" >/dev/null 2>&1; then
	echo "FAIL: validator accepted a stale exact substitution count" >&2
	exit 1
fi

echo "PASS: orthography manifest static guards and Docker drift detection"
