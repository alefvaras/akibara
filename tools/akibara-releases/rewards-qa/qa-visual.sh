#!/usr/bin/env bash
# Sonda visual de textos corridos — buscador, puntos y reseñas (riel del gate
# QA; orden del dueño 2026-08-31). Requiere: Docker local arriba, npm.
# Uso: AKB_TOKEN=<token-resena> AKB_PDP=<url-ficha> ./qa-visual.sh
set -euo pipefail
cd "$(dirname "$0")"
[ -d node_modules/playwright ] || npm i playwright --no-audit --no-fund >/dev/null
npx playwright install chromium >/dev/null 2>&1 || true
node qa-visual-sonda.mjs
