#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_MANIFEST="${AKB_REPO_ROOT}/ops/seo-editorial-shipping-quality-manifest.json"
AKB_VALIDATOR="${AKB_REPO_ROOT}/ops/validate-seo-editorial-shipping-quality-manifest.mjs"
AKB_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/akb-editorial-shipping-test.XXXXXX")"

akb_cleanup() {
	case "${AKB_TMP_DIR}" in
		*/akb-editorial-shipping-test.*) rm -rf -- "${AKB_TMP_DIR}" ;;
	esac
}
trap akb_cleanup EXIT

node "${AKB_VALIDATOR}" "${AKB_MANIFEST}" --live --expect either

# The validator must fail closed if an editor changes source content after the
# audit snapshot. This fixture is local-only and never touches WordPress.
node - "${AKB_MANIFEST}" "${AKB_TMP_DIR}/drifted.json" <<'NODE'
const fs = require('node:fs');
const [source, target] = process.argv.slice(2);
const manifest = JSON.parse(fs.readFileSync(source, 'utf8'));
manifest.entries[0].expected_before += ' cambio posterior';
fs.writeFileSync(target, `${JSON.stringify(manifest, null, 2)}\n`);
NODE

if node "${AKB_VALIDATOR}" "${AKB_TMP_DIR}/drifted.json" >/dev/null 2>&1; then
	echo "FAIL: validator accepted a stale expected-before SHA" >&2
	exit 1
fi

# Rank Math remains an explicit UI-only workflow and can never enter this
# content-quality manifest, even in a future edit.
node - "${AKB_MANIFEST}" "${AKB_TMP_DIR}/rank-math.json" <<'NODE'
const fs = require('node:fs');
const [source, target] = process.argv.slice(2);
const manifest = JSON.parse(fs.readFileSync(source, 'utf8'));
manifest.entries[0].field = 'rank_math_description';
fs.writeFileSync(target, `${JSON.stringify(manifest, null, 2)}\n`);
NODE

if node "${AKB_VALIDATOR}" "${AKB_TMP_DIR}/rank-math.json" >/dev/null 2>&1; then
	echo "FAIL: validator accepted Rank Math metadata" >&2
	exit 1
fi

# Atelier (post 22353) must remain a single, combined UI operation. Applying
# only the older ampersand fix would preserve seven dead spans that make
# wpautop emit malformed closing paragraph tags.
node - "${AKB_MANIFEST}" <<'NODE'
const crypto = require('node:crypto');
const fs = require('node:fs');
const manifest = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const entry = manifest.entries.find((item) => item.key === 'post:22353:post_content');
if (!entry || !Array.isArray(entry.exact_transformations)
	|| entry.exact_transformations.length !== 2) {
	throw new Error('post 22353 combined transformation manifest is missing');
}

let transformed = entry.expected_before;
for (const transformation of entry.exact_transformations) {
	const observed = transformed.split(transformation.before).length - 1;
	if (observed !== transformation.expected_count) {
		throw new Error(
			`post 22353 transformation count drifted for ${transformation.before}`,
		);
	}
	transformed = transformed.split(transformation.before).join(transformation.after);
}
if (transformed !== entry.proposed_after) {
	throw new Error('post 22353 transformations do not reproduce proposed_after');
}
if (crypto.createHash('sha256').update(transformed, 'utf8').digest('hex')
	!== '2e695d32635671710196383d0b6d724ba619964b7330db2db4d6f60e54779f2d') {
	throw new Error('post 22353 combined proposal SHA drifted');
}
for (const icon of ['🎨', '✨', '📖', '🎁']) {
	if (transformed.split(icon).length - 1 !== 1) {
		throw new Error(`post 22353 valid icon was changed: ${icon}`);
	}
}
if (transformed.includes('<span class="aki-page-section-accent"></span>')) {
	throw new Error('post 22353 still contains a dead section accent span');
}
NODE

echo "PASS: editorial manifest static guards, Docker drift detection and Rank Math exclusion"
