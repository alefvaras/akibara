#!/usr/bin/env bash

# Rail negativo dedicado al packaging Business 1.99.16. No usa Docker, no
# modifica contratos/runtime/dist reales y no sella ni publica producción.

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "${script_dir}/../.." && pwd -P)"
packager="${script_dir}/package-business-1.99.16-production.sh"
runtime_packager="${script_dir}/package-runtime.sh"
runtime_wrapper="${script_dir}/package-business-seo.sh"
source_delta_verifier="${script_dir}/verify-business-production-source-delta.py"
business_contract="${repo_root}/ops/contracts/akibara-business-1.99.16.json"
search_contract="${repo_root}/ops/contracts/akibara-search-0.1.14.json"
business_source="${repo_root}/public_html/wp-content/plugins/akibara-business"
preimage_archive="${repo_root}/dist/akibara-business-1.99.10.zip"
preimage_inventory="${repo_root}/dist/akibara-business-1.99.10.files.sha256"
preimage_sidecar="${preimage_archive}.sha256"

die() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

sha256_file() {
	shasum -a 256 "$1" | awk '{print $1}'
}

tree_digest() {
	local root="$1"
	(
		cd -- "${root}"
		find . -type f -print0 \
			| LC_ALL=C sort -z \
			| xargs -0 shasum -a 256 \
			| shasum -a 256 \
			| awk '{print $1}'
	)
}

for command_name in awk bash chmod cmp cp find grep jq ln mktemp mv python3 rm rsync seq shasum sort tr unzip wc xargs; do
	command -v "${command_name}" >/dev/null 2>&1 || die "falta ${command_name}"
done
for required in \
	"${packager}" \
	"${runtime_packager}" \
	"${runtime_wrapper}" \
	"${source_delta_verifier}" \
	"${business_contract}" \
	"${search_contract}" \
	"${preimage_archive}" \
	"${preimage_inventory}" \
	"${preimage_sidecar}"; do
	[[ -f "${required}" && ! -L "${required}" && -s "${required}" ]] \
		|| die "falta archivo regular: ${required}"
done
[[ -d "${business_source}" && ! -L "${business_source}" ]] || die 'fuente Business insegura'
bash -n "${packager}"
bash -n "${BASH_SOURCE[0]}"
python3 - "${source_delta_verifier}" <<'PY'
import ast
import pathlib
import sys

ast.parse(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"), filename=sys.argv[1])
PY

business_contract_before="$(sha256_file "${business_contract}")"
search_contract_before="$(sha256_file "${search_contract}")"
business_source_before="$(tree_digest "${business_source}")"
dist_before="$(
	find "${repo_root}/dist" -maxdepth 1 -type f \
		\( -name 'akibara-business-1.99.16.zip' \
			-o -name 'akibara-business-1.99.16.files.sha256' \
			-o -name 'akibara-business-1.99.16.zip.sha256' \) \
		-print0 | LC_ALL=C sort -z | xargs -0 shasum -a 256 2>/dev/null || true
)"

temp_root="${TMPDIR:-/tmp}"
[[ -d "${temp_root}" ]] || die 'TMPDIR ausente'
temp_root="$(cd -- "${temp_root}" && pwd -P)"
scratch="$(mktemp -d "${temp_root%/}/akibara-business-1.99.16-release-test.XXXXXX")"
scratch="$(cd -- "$(dirname -- "${scratch}")" && printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${scratch}")")"
cleanup() {
	case "${scratch:-}" in
		*/akibara-business-1.99.16-release-test.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

call_secure_publish() (
	AKB_BUSINESS_19916_PACKAGER_LIBRARY_ONLY=1
	export AKB_BUSINESS_19916_PACKAGER_LIBRARY_ONLY
	# shellcheck source=tools/akibara-releases/package-business-1.99.16-production.sh
	source "${packager}"
	secure_publish_artifacts "$@"
)

call_verify_archive() (
	AKB_BUSINESS_19916_PACKAGER_LIBRARY_ONLY=1
	export AKB_BUSINESS_19916_PACKAGER_LIBRARY_ONLY
	# shellcheck source=tools/akibara-releases/package-business-1.99.16-production.sh
	source "${packager}"
	verify_archive_inventory "$@"
)

assert_rejected() {
	local label="$1"
	shift
	if "$@" > "${scratch}/${label}.out" 2>&1; then
		die "se aceptó caso negativo: ${label}"
	fi
}

# Publicación segura: una primera escritura y la repetición idempotente deben
# pasar; colisiones, symlinks, hardlinks y escapes deben cerrar sin tocar el
# objetivo externo.
library_source="${scratch}/library-source"
mkdir -p -- "${library_source}"
printf 'payload-v1\n' > "${library_source}/artifact.bin"

safe_output="${scratch}/safe-output"
published_path="$(call_secure_publish "${safe_output}" "${library_source}" artifact.bin)"
[[ "${published_path}" == "${safe_output}" ]] || die 'helper no devolvió output canónico'
cmp -s "${library_source}/artifact.bin" "${safe_output}/artifact.bin" \
	|| die 'publicación segura cambió bytes'
call_secure_publish "${safe_output}" "${library_source}" artifact.bin >/dev/null \
	|| die 'output idéntico preexistente dejó de ser idempotente'

divergent_output="${scratch}/divergent-output"
mkdir -p -- "${divergent_output}"
printf 'otro\n' > "${divergent_output}/artifact.bin"
assert_rejected divergent call_secure_publish "${divergent_output}" "${library_source}" artifact.bin
[[ "$(< "${divergent_output}/artifact.bin")" == otro ]] || die 'colisión divergente fue sobrescrita'

symlink_target="${scratch}/symlink-target.txt"
printf 'externo\n' > "${symlink_target}"
symlink_file_output="${scratch}/symlink-file-output"
mkdir -p -- "${symlink_file_output}"
ln -s "${symlink_target}" "${symlink_file_output}/artifact.bin"
assert_rejected symlink-file call_secure_publish "${symlink_file_output}" "${library_source}" artifact.bin
[[ "$(< "${symlink_target}")" == externo ]] || die 'symlink target fue modificado'

hardlink_origin="${scratch}/hardlink-origin.bin"
cp -- "${library_source}/artifact.bin" "${hardlink_origin}"
hardlink_output="${scratch}/hardlink-output"
mkdir -p -- "${hardlink_output}"
ln "${hardlink_origin}" "${hardlink_output}/artifact.bin"
assert_rejected hardlink-file call_secure_publish "${hardlink_output}" "${library_source}" artifact.bin
cmp -s "${hardlink_origin}" "${library_source}/artifact.bin" || die 'hardlink externo fue modificado'

real_alias_output="${scratch}/real-alias-output"
linked_output="${scratch}/linked-output"
mkdir -p -- "${real_alias_output}"
ln -s "${real_alias_output}" "${linked_output}"
assert_rejected output-alias call_secure_publish "${linked_output}" "${library_source}" artifact.bin
[[ ! -e "${real_alias_output}/artifact.bin" ]] || die 'output symlink recibió artefactos'

real_ancestor="${scratch}/real-ancestor"
linked_ancestor="${scratch}/linked-ancestor"
mkdir -p -- "${real_ancestor}"
ln -s "${real_ancestor}" "${linked_ancestor}"
assert_rejected ancestor-alias call_secure_publish "${linked_ancestor}/nested" "${library_source}" artifact.bin
[[ ! -e "${real_ancestor}/nested" ]] || die 'ancestro symlink creó directorio fuera del path léxico'

assert_rejected artifact-escape call_secure_publish "${scratch}/escape-output" "${library_source}" ../escape.bin
[[ ! -e "${scratch}/escape.bin" ]] || die 'nombre escape creó archivo externo'
assert_rejected duplicate-name call_secure_publish "${scratch}/duplicate-output" "${library_source}" artifact.bin artifact.bin

source_alias_dir="${scratch}/source-alias"
mkdir -p -- "${source_alias_dir}"
ln -s "${library_source}/artifact.bin" "${source_alias_dir}/artifact.bin"
assert_rejected source-symlink call_secure_publish "${scratch}/source-alias-output" "${source_alias_dir}" artifact.bin

if find "${scratch}" -type f -name '.*.akb-stage-*' -print -quit | grep -q .; then
	die 'quedó staging parcial de publicación segura'
fi

# Carrera reproducible durante staging: muchos archivos fuerzan una ventana
# amplia. Un proceso crea el último target después de observar el primer stage;
# el segundo preflight debe cerrar, conservar el archivo ajeno y limpiar sólo
# los inodes privados de esta invocación.
race_source="${scratch}/race-source"
race_output="${scratch}/race-output"
mkdir -p -- "${race_source}" "${race_output}"
race_names=()
race_target='artifact-96.bin'
for index in $(seq 1 96); do
	name="artifact-${index}.bin"
	printf 'payload-%03d\n' "${index}" > "${race_source}/${name}"
	race_names+=("${name}")
done
python3 - "${race_output}" "${race_target}" <<'PY' &
import os
import pathlib
import sys
import time

root = pathlib.Path(sys.argv[1])
target = root / sys.argv[2]
deadline = time.monotonic() + 20
while time.monotonic() < deadline:
    if any(".akb-stage-" in entry.name for entry in root.iterdir()):
        descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        try:
            os.write(descriptor, b"concurrent-owner\n")
        finally:
            os.close(descriptor)
        raise SystemExit(0)
    time.sleep(0.001)
raise SystemExit("no se observó staging para fixture de carrera")
PY
race_watcher_pid=$!
if call_secure_publish "${race_output}" "${race_source}" "${race_names[@]}" \
	> "${scratch}/destination-race.out" 2>&1; then
	wait "${race_watcher_pid}" || true
	die 'publicación aceptó target aparecido durante staging'
fi
wait "${race_watcher_pid}" || die 'fixture concurrente no alcanzó la ventana de staging'
[[ "$(< "${race_output}/${race_target}")" == concurrent-owner ]] \
	|| die 'rollback tocó el archivo del actor concurrente'
if find "${race_output}" -maxdepth 1 -type f -name '.*.akb-stage-*' -print -quit | grep -q .; then
	die 'fallo durante segundo preflight dejó stage parcial'
fi
[[ "$(find "${race_output}" -maxdepth 1 -type f ! -name '.*.akb-stage-*' | wc -l | tr -d ' ')" == 1 ]] \
	|| die 'rollback de carrera dejó outputs parciales propios'

# El parser ZIP dedicado debe cerrar escapes y entradas especiales antes de que
# cualquier unzip del packager pueda materializarlas.
zip_fixture="${scratch}/zip-fixture"
mkdir -p -- "${zip_fixture}"
python3 - "${zip_fixture}" <<'PY'
import hashlib
import pathlib
import stat
import sys
import zipfile

root = pathlib.Path(sys.argv[1])
payload = b"<?php // safe\n"
safe_name = "akibara-business/akibara-business.php"
(root / "safe.files.sha256").write_text(
    f"{hashlib.sha256(payload).hexdigest()}  {safe_name}\n", encoding="utf-8"
)
with zipfile.ZipFile(root / "safe.zip", "w") as bundle:
    bundle.writestr(safe_name, payload)
for label, bad_name in (
    ("traversal", "akibara-business/../../escape.php"),
    ("absolute", "/akibara-business/escape.php"),
    ("backslash", "akibara-business\\escape.php"),
):
    with zipfile.ZipFile(root / f"{label}.zip", "w") as bundle:
        bundle.writestr(safe_name, payload)
        bundle.writestr(bad_name, b"escape")
with zipfile.ZipFile(root / "extra.zip", "w") as bundle:
    bundle.writestr(safe_name, payload)
    bundle.writestr("akibara-business/extra.php", b"extra")
with zipfile.ZipFile(root / "symlink.zip", "w") as bundle:
    bundle.writestr(safe_name, payload)
    link = zipfile.ZipInfo("akibara-business/link.php")
    link.create_system = 3
    link.external_attr = (stat.S_IFLNK | 0o777) << 16
    bundle.writestr(link, b"akibara-business.php")
PY

call_verify_archive "${zip_fixture}/safe.zip" "${zip_fixture}/safe.files.sha256" akibara-business \
	|| die 'parser rechazó ZIP seguro'
for bad_zip in traversal absolute backslash extra symlink; do
	assert_rejected "zip-${bad_zip}" call_verify_archive \
		"${zip_fixture}/${bad_zip}.zip" "${zip_fixture}/safe.files.sha256" akibara-business
done

# Fixture integral: deriva, sella sólo copias locales y publica a un output
# privado. Así se prueba que el packager real está conectado al helper, no sólo
# que la función aislada funciona.
fixture_root="${scratch}/fixture-repo"
fixture_tools="${fixture_root}/tools/akibara-releases"
fixture_contracts="${fixture_root}/ops/contracts"
fixture_dist="${fixture_root}/dist"
fixture_source="${fixture_root}/public_html/wp-content/plugins/akibara-business"
mkdir -p -- "${fixture_tools}" "${fixture_contracts}" "${fixture_dist}" "${fixture_source}"
cp -- "${packager}" "${fixture_tools}/package-business-1.99.16-production.sh"
cp -- "${runtime_packager}" "${fixture_tools}/package-runtime.sh"
cp -- "${runtime_wrapper}" "${fixture_tools}/package-business-seo.sh"
cp -- "${source_delta_verifier}" "${fixture_tools}/verify-business-production-source-delta.py"
cp -- "${preimage_archive}" "${fixture_dist}/akibara-business-1.99.10.zip"
cp -- "${preimage_inventory}" "${fixture_dist}/akibara-business-1.99.10.files.sha256"
cp -- "${preimage_sidecar}" "${fixture_dist}/akibara-business-1.99.10.zip.sha256"
rsync -a --delete "${business_source}/" "${fixture_source}/"

jq '
  .seal_state="draft"
  | .sealed_at_utc=null
  | .components.business.candidate.archive_sha256=null
  | .components.business.candidate.inventory_sha256=null
  | .components.business.candidate.runtime_files=null
  | .components.business.modified_runtime_files=null
  | .components.business.added_runtime_files=null
  | .components.business.removed_runtime_files=null
  | .components.business.source_inputs=null
' "${business_contract}" > "${fixture_contracts}/akibara-business-1.99.16.json"
jq '
  .seal_state="draft"
  | .sealed_at_utc=null
  | .paired_release.business_contract_sha256=null
' "${search_contract}" > "${fixture_contracts}/akibara-search-0.1.14.json"

fixture_packager="${fixture_tools}/package-business-1.99.16-production.sh"
proposal="${scratch}/fixture-proposal.json"
TMPDIR="${scratch}" bash "${fixture_packager}" --derive-only > "${proposal}"
jq -e '
  .seal_state == "draft-proposal"
  and .candidate.version == "1.99.16"
  and .candidate.runtime_files == 226
  and (.modified_runtime_files | length) == 6
  and (.added_runtime_files | length) == 5
  and .removed_runtime_files == []
  and (.source_inputs | length) == 11
' "${proposal}" >/dev/null || die 'propuesta fixture inválida'

jq --slurpfile proposal "${proposal}" '
  .seal_state="sealed"
  | .sealed_at_utc="2026-08-06T00:00:00Z"
  | .components.business.candidate=$proposal[0].candidate
  | .components.business.modified_runtime_files=$proposal[0].modified_runtime_files
  | .components.business.added_runtime_files=$proposal[0].added_runtime_files
  | .components.business.removed_runtime_files=$proposal[0].removed_runtime_files
  | .components.business.source_inputs=$proposal[0].source_inputs
' "${fixture_contracts}/akibara-business-1.99.16.json" \
	> "${fixture_contracts}/akibara-business-1.99.16.json.sealed"
mv -- "${fixture_contracts}/akibara-business-1.99.16.json.sealed" \
	"${fixture_contracts}/akibara-business-1.99.16.json"
fixture_business_sha="$(sha256_file "${fixture_contracts}/akibara-business-1.99.16.json")"
jq --arg business_sha "${fixture_business_sha}" '
  .seal_state="sealed"
  | .sealed_at_utc="2026-08-06T00:00:00Z"
  | .paired_release.business_contract_sha256=$business_sha
' "${fixture_contracts}/akibara-search-0.1.14.json" \
	> "${fixture_contracts}/akibara-search-0.1.14.json.sealed"
mv -- "${fixture_contracts}/akibara-search-0.1.14.json.sealed" \
	"${fixture_contracts}/akibara-search-0.1.14.json"

fixture_output="${scratch}/fixture-output"
TMPDIR="${scratch}" bash "${fixture_packager}" --output-dir "${fixture_output}" >/dev/null
for artifact in \
	akibara-business-1.99.16.zip \
	akibara-business-1.99.16.files.sha256 \
	akibara-business-1.99.16.zip.sha256; do
	[[ -f "${fixture_output}/${artifact}" && ! -L "${fixture_output}/${artifact}" ]] \
		|| die "fixture no publicó artefacto regular: ${artifact}"
done
[[ "$(sha256_file "${fixture_output}/akibara-business-1.99.16.zip")" == \
	"$(jq -r '.candidate.archive_sha256' "${proposal}")" ]] || die 'ZIP fixture diverge de propuesta'
[[ "$(sha256_file "${fixture_output}/akibara-business-1.99.16.files.sha256")" == \
	"$(jq -r '.candidate.inventory_sha256' "${proposal}")" ]] || die 'inventario fixture diverge de propuesta'
if find "${fixture_output}" -maxdepth 1 -name '.*.akb-stage-*' -print -quit | grep -q .; then
	die 'fixture dejó staging parcial en output'
fi

# TOCTOU reproducible: los dos primeros builds producen bytes iguales y el
# wrapper muta un archivo runtime justo al terminar el segundo. El tercer build
# final del packager debe observar y rechazar ese drift.
jq '
  .seal_state="draft"
  | .sealed_at_utc=null
  | .components.business.candidate.archive_sha256=null
  | .components.business.candidate.inventory_sha256=null
  | .components.business.candidate.runtime_files=null
  | .components.business.modified_runtime_files=null
  | .components.business.added_runtime_files=null
  | .components.business.removed_runtime_files=null
  | .components.business.source_inputs=null
' "${business_contract}" > "${fixture_contracts}/akibara-business-1.99.16.json"
jq '
  .seal_state="draft"
  | .sealed_at_utc=null
  | .paired_release.business_contract_sha256=null
' "${search_contract}" > "${fixture_contracts}/akibara-search-0.1.14.json"
{
	printf '%s\n' '#!/usr/bin/env bash'
	printf '%s\n' 'set -Eeuo pipefail'
	printf '%s\n' 'script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"'
	printf '%s\n' 'repo_root="$(cd -- "${script_dir}/../.." && pwd -P)"'
	printf '%s\n' 'counter_file="${repo_root}/.business-packager-call-count"'
	printf '%s\n' 'count=0'
	printf '%s\n' '[[ ! -f "${counter_file}" ]] || count="$(< "${counter_file}")"'
	printf '%s\n' '((count += 1))'
	printf '%s\n' 'printf "%s\n" "${count}" > "${counter_file}"'
	printf '%s\n' 'bash "${script_dir}/package-runtime.sh" business-seo "$@"'
	printf '%s\n' 'if [[ "${count}" == 2 ]]; then'
	printf '%s\n' '  printf "\n// injected TOCTOU fixture\n" >> "${repo_root}/public_html/wp-content/plugins/akibara-business/uninstall.php"'
	printf '%s\n' 'fi'
} > "${fixture_tools}/package-business-seo.sh"
chmod 700 "${fixture_tools}/package-business-seo.sh"
assert_rejected source-toctou env TMPDIR="${scratch}" bash "${fixture_packager}" --derive-only
grep -Fq 'Árbol fuente cambió entre builds:' "${scratch}/source-toctou.out" \
	|| die 'TOCTOU fue rechazado por una causa distinta al snapshot final'
[[ "$(< "${fixture_root}/.business-packager-call-count")" == 3 ]] \
	|| die 'fixture TOCTOU no llegó al tercer build final'

# El test mismo debe ser hermético respecto de los artefactos reales.
[[ "$(sha256_file "${business_contract}")" == "${business_contract_before}" ]] \
	|| die 'test modificó contrato Business real'
[[ "$(sha256_file "${search_contract}")" == "${search_contract_before}" ]] \
	|| die 'test modificó contrato Search real'
[[ "$(tree_digest "${business_source}")" == "${business_source_before}" ]] \
	|| die 'test modificó runtime Business real'
dist_after="$(
	find "${repo_root}/dist" -maxdepth 1 -type f \
		\( -name 'akibara-business-1.99.16.zip' \
			-o -name 'akibara-business-1.99.16.files.sha256' \
			-o -name 'akibara-business-1.99.16.zip.sha256' \) \
		-print0 | LC_ALL=C sort -z | xargs -0 shasum -a 256 2>/dev/null || true
)"
[[ "${dist_after}" == "${dist_before}" ]] || die 'test creó o cambió dist Business 1.99.16 real'

printf 'PASS: Business 1.99.16 rechaza symlink/hardlink/escape/colisión y drift TOCTOU; fixture integral hermética\n'
