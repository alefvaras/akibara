#!/usr/bin/env bash

set -euo pipefail

command -v npx >/dev/null 2>&1 || {
	echo 'Error: npx is required but not found on PATH.' >&2
	exit 1
}

exec npx --yes --package @playwright/cli@0.1.18 playwright-cli "$@"
