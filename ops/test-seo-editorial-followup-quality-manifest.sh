#!/usr/bin/env bash

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_dir"

tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/akb-editorial-followup.XXXXXX")"
cleanup() {
	find "$tmp_dir" -type f -delete 2>/dev/null || true
	rmdir "$tmp_dir" 2>/dev/null || true
}
trap cleanup EXIT

node ops/validate-seo-editorial-followup-quality-manifest.mjs
node ops/validate-seo-editorial-followup-quality-manifest.mjs --live --expect either

node ops/generate-seo-editorial-followup-quality-manifest.mjs \
	"$tmp_dir/regenerated.json" >/dev/null
cmp -s ops/seo-editorial-followup-quality-manifest.json "$tmp_dir/regenerated.json" || {
	echo "FAIL: generator is not deterministic" >&2
	exit 1
}

php -l ops/apply-seo-content-quality-data.php >/dev/null
node <<'NODE'
const fs = require('fs');
const source = fs.readFileSync('ops/apply-seo-content-quality-data.php', 'utf8');
if (!source.includes('despertar de ???%')) {
	throw new Error('declarative product excerpt source lacks canonical ???%');
}
if (!source.includes("expected_before'       => 'Mob Psycho 100 07 separa a Mob de Reigen y prepara el despertar de ?%")) {
	throw new Error('declarative product excerpt source lost its exact before guard');
}
NODE

node - "$tmp_dir/bad-before.json" <<'NODE'
const fs = require('fs');
const source = JSON.parse(
	fs.readFileSync('ops/seo-editorial-followup-quality-manifest.json', 'utf8'),
);
source.entries[0].expected_before += 'x';
fs.writeFileSync(process.argv[2], `${JSON.stringify(source, null, 2)}\n`);
NODE
if node ops/validate-seo-editorial-followup-quality-manifest.mjs \
	"$tmp_dir/bad-before.json" >/dev/null 2>&1; then
	echo "FAIL: validator accepted a tampered before state" >&2
	exit 1
fi

node - "$tmp_dir/bad-count.json" <<'NODE'
const fs = require('fs');
const source = JSON.parse(
	fs.readFileSync('ops/seo-editorial-followup-quality-manifest.json', 'utf8'),
);
source.entries[7].substitutions[0].expected_count += 1;
fs.writeFileSync(process.argv[2], `${JSON.stringify(source, null, 2)}\n`);
NODE
if node ops/validate-seo-editorial-followup-quality-manifest.mjs \
	"$tmp_dir/bad-count.json" >/dev/null 2>&1; then
	echo "FAIL: validator accepted a tampered substitution count" >&2
	exit 1
fi

node - "$tmp_dir/bad-mob.json" <<'NODE'
const fs = require('fs');
const crypto = require('crypto');
const source = JSON.parse(
	fs.readFileSync('ops/seo-editorial-followup-quality-manifest.json', 'utf8'),
);
source.entries[0].proposed_after = source.entries[0].proposed_after.replace('???%', '?%');
source.entries[0].proposed_after_sha256 = crypto
	.createHash('sha256')
	.update(source.entries[0].proposed_after)
	.digest('hex');
source.entries[0].substitutions[0].after = '?%';
fs.writeFileSync(process.argv[2], `${JSON.stringify(source, null, 2)}\n`);
NODE
if node ops/validate-seo-editorial-followup-quality-manifest.mjs \
	"$tmp_dir/bad-mob.json" >/dev/null 2>&1; then
	echo "FAIL: validator accepted noncanonical Mob notation" >&2
	exit 1
fi

node - "$tmp_dir/bad-url.json" <<'NODE'
const fs = require('fs');
const crypto = require('crypto');
const source = JSON.parse(
	fs.readFileSync('ops/seo-editorial-followup-quality-manifest.json', 'utf8'),
);
const entry = source.entries.find((candidate) =>
	candidate.key === 'post:23330:post_content'
);
entry.proposed_after = entry.proposed_after.replace(
	'/product-category/comics/',
	'/product-category/cómics/',
);
entry.proposed_after_sha256 = crypto
	.createHash('sha256')
	.update(entry.proposed_after)
	.digest('hex');
fs.writeFileSync(process.argv[2], `${JSON.stringify(source, null, 2)}\n`);
NODE
if node ops/validate-seo-editorial-followup-quality-manifest.mjs \
	"$tmp_dir/bad-url.json" >/dev/null 2>&1; then
	echo "FAIL: validator accepted a changed href" >&2
	exit 1
fi

echo "PASS: editorial follow-up manifest is deterministic, HTML-safe and fail-closed"
