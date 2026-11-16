#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
verifier="${script_dir}/verify-business-blocks-source-delta.py"

die() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

for required in jq python3 shasum; do
	command -v "${required}" >/dev/null 2>&1 || die "falta ${required}"
done
[[ -f "${verifier}" && ! -L "${verifier}" ]] \
	|| die "falta el verificador regular del delta fuente"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-source-delta-test.XXXXXX")"
cleanup() {
	case "${scratch:-}" in
		*/akibara-source-delta-test.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

source_root="${scratch}/source"
inventory="${scratch}/preimage.files.sha256"
contract="${scratch}/contract.json"
frontend_path="src/Modules/FrontendCompatibility/FrontendCompatibility.php"
mkdir -p -- \
	"${source_root}/src/Modules/FrontendCompatibility" \
	"${source_root}/src/Allowed"

printf 'changed-current\n' > "${source_root}/src/Allowed/changed.php"
printf 'added-current\n' > "${source_root}/src/Allowed/added.php"
printf 'untouched\n' > "${source_root}/src/Allowed/untouched.php"
printf 'preserved-local-drift\n' > "${source_root}/${frontend_path}"

{
	printf '%s  %s\n' \
		"$(printf 'changed-preimage\n' | shasum -a 256 | awk '{print $1}')" \
		"akibara-business/src/Allowed/changed.php"
	printf '%s  %s\n' \
		"$(printf 'removed-preimage\n' | shasum -a 256 | awk '{print $1}')" \
		"akibara-business/src/Allowed/removed.php"
	printf '%s  %s\n' \
		"$(printf 'untouched\n' | shasum -a 256 | awk '{print $1}')" \
		"akibara-business/src/Allowed/untouched.php"
	printf '%s  %s\n' \
		"$(printf 'preserved-preimage\n' | shasum -a 256 | awk '{print $1}')" \
		"akibara-business/${frontend_path}"
} > "${inventory}"

jq -n \
	--arg frontend "akibara-business/${frontend_path}" \
	'{
	  components: {
	    business: {
	      slug: "akibara-business",
	      modified_runtime_files: [
	        "akibara-business/src/Allowed/changed.php"
	      ],
	      added_runtime_files: [
	        "akibara-business/src/Allowed/added.php"
	      ],
	      removed_runtime_files: [
	        "akibara-business/src/Allowed/removed.php"
	      ],
	      preserved_preimage_files: [
	        {path: $frontend, sha256: ("0" * 64)}
	      ]
	    }
	  }
	}' > "${contract}"

verify_fixture() {
	python3 "${verifier}" \
		--contract "${contract}" \
		--component business \
		--slug akibara-business \
		--source-root "${source_root}" \
		--preimage-inventory "${inventory}"
}

verify_fixture

printf 'not-allowlisted\n' > "${source_root}/src/Allowed/rogue.php"
if verify_fixture > "${scratch}/unexpected-added.out" 2>&1; then
	die "el verificador aceptó un archivo fuente agregado fuera de allowlist"
fi
rm -f -- "${source_root}/src/Allowed/rogue.php"

printf 'modified-outside-allowlist\n' > "${source_root}/src/Allowed/untouched.php"
if verify_fixture > "${scratch}/unexpected-modified.out" 2>&1; then
	die "el verificador aceptó una modificación fuente fuera de allowlist"
fi

printf 'PASS: delta fuente completo rechaza archivos agregados/modificados fuera de allowlist\n'
