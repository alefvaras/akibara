#!/usr/bin/env bash
set -euo pipefail
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"
current="$(git config --get core.hooksPath || true)"
[[ -z "$current" || "$current" == .githooks ]] || { printf 'Ya hay hooks propios; integra pre-push antes de cambiar hooksPath.\n' >&2; exit 1; }
chmod +x .githooks/pre-push
git config core.hooksPath .githooks
printf 'Protección pre-push instalada en este checkout.\n'
