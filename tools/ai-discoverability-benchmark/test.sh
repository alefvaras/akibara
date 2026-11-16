#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cd "${script_dir}"
export PYTHONDONTWRITEBYTECODE=1
exec python3 -m unittest -v test_benchmark.py
