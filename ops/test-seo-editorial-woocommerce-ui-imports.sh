#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

node "${AKB_REPO_ROOT}/ops/test-seo-editorial-woocommerce-ui-imports.mjs"
node "${AKB_REPO_ROOT}/ops/generate-seo-editorial-woocommerce-ui-imports.mjs"
node "${AKB_REPO_ROOT}/ops/generate-seo-editorial-woocommerce-ui-imports.mjs" --check

echo "PASS: two separate official WooCommerce UI imports generated under .local-reports/seo-editorial-ui/"

