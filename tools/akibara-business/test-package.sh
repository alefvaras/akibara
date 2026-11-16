#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-package-test.XXXXXX")"
plugin_file="${repo_root}/public_html/wp-content/plugins/akibara-business/akibara-business.php"
version="$(
	sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' \
		"${plugin_file}" | head -n 1
)"
[[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] \
	|| {
		echo "No se pudo derivar una versión semántica del header de Business." >&2
		exit 1
	}
artifact_base="akibara-business-${version}"

cleanup() {
	case "${test_root}" in
		*/akibara-business-package-test.*) rm -rf -- "${test_root}" ;;
	esac
}
trap cleanup EXIT

for command_name in cmp shasum unzip zipinfo; do
	if ! command -v "${command_name}" >/dev/null 2>&1; then
		echo "Falta el comando requerido para probar el paquete: ${command_name}" >&2
		exit 1
	fi
done

first_output="${test_root}/first"
second_output="${test_root}/second"
mkdir -p "${first_output}" "${second_output}"

TZ=Pacific/Honolulu "${script_dir}/package.sh" --output-dir "${first_output}" >/dev/null
TZ=Asia/Tokyo "${script_dir}/package.sh" --output-dir "${second_output}" >/dev/null

for suffix in zip files.sha256 zip.sha256; do
	first_artifact="${first_output}/${artifact_base}.${suffix}"
	second_artifact="${second_output}/${artifact_base}.${suffix}"
	if [[ ! -f "${first_artifact}" || ! -f "${second_artifact}" ]]; then
		echo "Falta el artefacto reproducible .${suffix}." >&2
		exit 1
	fi
	cmp -s "${first_artifact}" "${second_artifact}"
done

first_zip="${first_output}/${artifact_base}.zip"
first_inventory="${first_output}/${artifact_base}.files.sha256"
unzip -tq "${first_zip}" >/dev/null
mkdir -p "${test_root}/extract"
unzip -q "${first_zip}" -d "${test_root}/extract"
(
	cd "${test_root}/extract"
	shasum -a 256 -c "${first_inventory}" >/dev/null
)

collision_output="${test_root}/collision"
mkdir -p "${collision_output}"
cp "${first_output}/"* "${collision_output}/"
printf 'tampered\n' >> "${collision_output}/${artifact_base}.zip"
if "${script_dir}/package.sh" --output-dir "${collision_output}" >/dev/null 2>&1; then
	echo "El empaquetador sobrescribió un ZIP divergente." >&2
	exit 1
fi

echo "PASS: paquete, inventario y checksum de Akibara Business reproducibles"
