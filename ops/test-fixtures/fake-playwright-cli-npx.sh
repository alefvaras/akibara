#!/bin/sh

set -eu

: "${AKB_FAKE_COUNTER:?}"

function_path=''
for argument in "$@"; do
	case "${argument}" in
		--filename=*) function_path="${argument#--filename=}" ;;
	esac
done
test -n "${function_path}"

count="$(sed -n '1p' "${AKB_FAKE_COUNTER}")"
printf '%s\n' "$((count + 1))" >"${AKB_FAKE_COUNTER}"

if grep -q 'RANK_MATH_TITLES_SURFACE_ESCAPE' "${function_path}"; then
	if grep -q 'const saveGlobal = true;' "${function_path}"; then
		payload='{"status":"PASS","saved":true}'
	else
		payload='{"status":"PASS","saved":false}'
	fi
else
	payload='{"status":"PASS","read_only":true}'
fi

node - "${payload}" <<'NODE'
const payload = JSON.parse(process.argv[2]);
process.stdout.write(`${JSON.stringify({ result: JSON.stringify(payload) })}\n`);
NODE
