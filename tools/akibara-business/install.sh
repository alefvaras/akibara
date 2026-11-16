#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker es obligatorio para instalar el tooling local." >&2
	exit 1
fi

docker run --rm \
	--user "$(id -u):$(id -g)" \
	-e COMPOSER_HOME=/tmp/composer \
	-v "${repo_root}:/workspace" \
	-w /workspace/tools/akibara-business \
	composer:2 \
	install --no-interaction --prefer-dist

echo "Tooling local instalado en tools/akibara-business/vendor."
