#!/usr/bin/env bash
# Build the theme and Search assets with the lockfile-installed compiler.
set -euo pipefail
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
export AKB_ESBUILD="${AKB_ESBUILD:-${repo_root}/node_modules/.bin/esbuild}"
cd "$repo_root"
bash tools/akibara-releases/build-theme-core-css.sh
node tools/akibara-releases/build-search-assets.mjs
