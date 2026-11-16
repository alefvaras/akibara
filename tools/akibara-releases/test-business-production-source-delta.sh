#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
verifier="${script_dir}/verify-business-production-source-delta.py"

die() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

for command_name in jq python3 shasum; do
	command -v "${command_name}" >/dev/null 2>&1 || die "falta ${command_name}"
done
[[ -f "${verifier}" && ! -L "${verifier}" ]] || die "falta verificador regular"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-source-delta.XXXXXX")"
cleanup() {
	case "${scratch:-}" in
		*/akibara-business-source-delta.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

source_root="${scratch}/source"
inventory="${scratch}/preimage.files.sha256"
contract="${scratch}/contract.json"
mkdir -p -- "${source_root}/src/Allowed"
printf 'candidate\n' > "${source_root}/src/Allowed/changed.php"
printf 'stable\n' > "${source_root}/src/Allowed/untouched.php"

{
	printf '%s  %s\n' \
		"$(printf 'preimage\n' | shasum -a 256 | awk '{print $1}')" \
		'akibara-business/src/Allowed/changed.php'
	printf '%s  %s\n' \
		"$(printf 'stable\n' | shasum -a 256 | awk '{print $1}')" \
		'akibara-business/src/Allowed/untouched.php'
} > "${inventory}"

jq -n \
	--arg input_sha "$(shasum -a 256 "${source_root}/src/Allowed/changed.php" | awk '{print $1}')" \
	'{
	  components: {
	    business: {
	      slug: "akibara-business",
	      modified_runtime_files: ["akibara-business/src/Allowed/changed.php"],
	      added_runtime_files: [],
	      removed_runtime_files: [],
	      source_inputs: [{
	        path: "akibara-business/src/Allowed/changed.php",
	        sha256: $input_sha
	      }]
	    }
	  }
	}' > "${contract}"

verify_fixture() {
	python3 "${verifier}" \
		--contract "${contract}" \
		--source-root "${source_root}" \
		--preimage-inventory "${inventory}" \
		--expected-modified 1 \
		--expected-added 0 \
		--expected-removed 0
}

verify_fixture >/dev/null

if python3 "${verifier}" \
	--contract "${contract}" \
	--source-root "${source_root}" \
	--preimage-inventory "${inventory}" \
	--expected-modified 2 > "${scratch}/cardinality.out" 2>&1; then
	die "se aceptó una cardinalidad de delta incorrecta"
fi

printf 'rogue\n' > "${source_root}/src/Allowed/rogue.php"
if verify_fixture > "${scratch}/rogue.out" 2>&1; then
	die "se aceptó un archivo agregado fuera del contrato"
fi
rm -f -- "${source_root}/src/Allowed/rogue.php"

printf 'rewritten\n' > "${source_root}/src/Allowed/untouched.php"
if verify_fixture > "${scratch}/untouched.out" 2>&1; then
	die "se aceptó una modificación fuera del contrato"
fi
printf 'stable\n' > "${source_root}/src/Allowed/untouched.php"

printf 'candidate-after-seal\n' > "${source_root}/src/Allowed/changed.php"
if verify_fixture > "${scratch}/sealed-input.out" 2>&1; then
	die "se aceptó un input cambiado después del sello"
fi

printf 'PASS: verificador rechaza drift agregado, modificado y posterior al sello\n'
