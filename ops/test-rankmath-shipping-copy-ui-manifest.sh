#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

node "${AKB_REPO_ROOT}/ops/generate-rankmath-shipping-copy-ui-manifest.mjs" --check
node "${AKB_REPO_ROOT}/ops/validate-rankmath-shipping-copy-ui-manifest.mjs" \
	--expect "${AKB_RANKMATH_COPY_EXPECT:-either}"

echo "PASS: Rank Math shipping copy manifest is reproducible, exact, read-only and idempotent"
