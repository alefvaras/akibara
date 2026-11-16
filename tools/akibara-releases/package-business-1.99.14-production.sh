#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
contract_file="${repo_root}/ops/contracts/akibara-business-1.99.14.json"
source_delta_verifier="${script_dir}/verify-business-production-source-delta.py"
runtime_packager="${script_dir}/package-business-seo.sh"
output_dir="${repo_root}/dist"
verify_only=0

usage() {
	cat <<'EOF'
Uso:
  bash tools/akibara-releases/package-business-1.99.14-production.sh [opciones]

Opciones:
  --output-dir RUTA  Directorio local para ZIP, inventario y sidecar.
  --verify-only      Construye dos veces y valida sin publicar artefactos.

El contrato permite exactamente cinco archivos runtime modificados y dos altas
respecto de Business 1.99.10 (la versión que corre en producción). No elimina
archivos.
EOF
}

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

sha256_file() {
	shasum -a 256 "$1" | awk '{print $1}'
}

verify_regular_file() {
	[[ -f "$1" && ! -L "$1" && -s "$1" ]] \
		|| die "Archivo ausente, vacío o inseguro: $1"
}

while (($#)); do
	case "$1" in
		--output-dir)
			(($# >= 2)) || die "Falta ruta para --output-dir"
			output_dir="$2"
			shift 2
			;;
		--verify-only)
			verify_only=1
			shift
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			die "Opción desconocida: $1"
			;;
	esac
done

for command_name in cmp find jq python3 shasum unzip zipinfo; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| die "Falta el comando requerido: ${command_name}"
done
for required in "${contract_file}" "${source_delta_verifier}" "${runtime_packager}"; do
	verify_regular_file "${required}"
done

jq -e '
  .schema_version == 1
  and .contract == "akibara-business-production-release"
  and .release_id == "akibara-business-1.99.14"
  and .seal_state == "sealed"
  and .promotion_sequence == ["docker-local", "production"]
  and (.components | keys) == ["business"]
  and .components.business.kind == "plugin"
  and .components.business.slug == "akibara-business"
  and .components.business.source_root ==
    "public_html/wp-content/plugins/akibara-business"
  and .components.business.preimage.version == "1.99.10"
  and .components.business.preimage.runtime_files == 221
  and .components.business.remote_baseline.version == "1.99.10"
  and .components.business.remote_baseline.runtime_files == 221
  and .components.business.candidate.version == "1.99.14"
  and .components.business.candidate.runtime_files == 223
  and .components.business.modified_runtime_files == [
    "akibara-business/akibara-business.php",
    "akibara-business/src/Modules/OrderPreventa/ReadyToShipEmail.php",
    "akibara-business/src/Modules/TaxonomyDiscount/DiscountResolver.php",
    "akibara-business/src/Modules/TaxonomyDiscount/Settings.php",
    "akibara-business/src/Plugin.php"
  ]
  and .components.business.added_runtime_files == [
    "akibara-business/src/Modules/TaxonomyDiscount/PriceAuthorityUnavailable.php",
    "akibara-business/src/Modules/TaxonomyDiscount/SearchPriceProvider.php"
  ]
  and .components.business.removed_runtime_files == []
  and (.components.business.source_inputs | length) == 7
  and ([
    .components.business.preimage.archive_sha256,
    .components.business.preimage.inventory_sha256,
    .components.business.remote_baseline.inventory_sha256,
    .components.business.candidate.archive_sha256,
    .components.business.candidate.inventory_sha256,
    .components.business.source_inputs[].sha256
  ] | all(test("^[a-f0-9]{64}$")))
  and .production_invariants.remote_backups_allowed == false
  and .production_invariants.graphify_during_release_allowed == false
  and .production_invariants.cloudflare_zone_name == "akibara.cl"
  and .production_invariants.cloudflare_zone_id ==
    "b298201b3a38d017bc8413d9fd7414cd"
  and .production_invariants.cloudflare_purge_required == true
  and .production_invariants.cloudflare_purge_transport ==
    "official-mcp-handoff"
  and .production_invariants.cloudflare_witness_max_age_seconds == 900
  and .production_invariants.cloudflare_purge_payload == {
    "purge_everything": true
  }
  and .production_invariants.live_cache_purge_order == ["litespeed", "cloudflare"]
  and .production_invariants.manga_archive_copy == {
    "source": "woocommerce_product_category_description",
    "canonical_clean_first_page": "first_paragraph_above_rest_after_main_content",
    "query_or_page_2": "suppressed",
    "allowed_html": ["p", "a", "strong", "em", "br", "ul", "ol", "li"],
    "faq_schema_added": false,
    "required_viewports": ["375x812", "1440x1000"]
  }
  and .exclusive_targets == [
    "public_html/wp-content/plugins/akibara-business"
  ]
' "${contract_file}" >/dev/null || die "Contrato Business 1.99.14 rechazado"

source_root="${repo_root}/$(jq -r '.components.business.source_root' "${contract_file}")"
preimage_archive="${repo_root}/$(jq -r '.components.business.preimage.archive' "${contract_file}")"
preimage_inventory="${repo_root}/$(jq -r '.components.business.preimage.inventory' "${contract_file}")"
candidate_archive_name="$(basename -- "$(jq -r '.components.business.candidate.archive' "${contract_file}")")"
candidate_inventory_name="$(basename -- "$(jq -r '.components.business.candidate.inventory' "${contract_file}")")"
candidate_sidecar_name="${candidate_archive_name}.sha256"

[[ -d "${source_root}" && ! -L "${source_root}" ]] \
	|| die "Árbol fuente Business ausente o inseguro"
verify_regular_file "${preimage_archive}"
verify_regular_file "${preimage_archive}.sha256"
verify_regular_file "${preimage_inventory}"

[[ "$(sha256_file "${preimage_archive}")" == \
	"$(jq -r '.components.business.preimage.archive_sha256' "${contract_file}")" ]] \
	|| die "ZIP preimagen 1.99.10 cambió"
[[ "$(sha256_file "${preimage_inventory}")" == \
	"$(jq -r '.components.business.preimage.inventory_sha256' "${contract_file}")" ]] \
	|| die "Inventario preimagen 1.99.10 cambió"
(
	cd -- "$(dirname -- "${preimage_archive}")"
	shasum -a 256 -c "$(basename -- "${preimage_archive}.sha256")" >/dev/null
) || die "Sidecar preimagen 1.99.10 inválido"
unzip -tq "${preimage_archive}" >/dev/null
[[ "$(zipinfo -1 "${preimage_archive}" | grep -vc '/$')" == 221 ]] \
	|| die "Conteo runtime preimagen inesperado"

python3 "${source_delta_verifier}" \
	--contract "${contract_file}" \
	--source-root "${source_root}" \
	--preimage-inventory "${preimage_inventory}"

while IFS=$'\t' read -r logical_path expected_sha; do
	relative_path="${logical_path#akibara-business/}"
	[[ "${relative_path}" != "${logical_path}" ]] \
		|| die "Input fuente fuera del slug: ${logical_path}"
	source_file="${source_root}/${relative_path}"
	verify_regular_file "${source_file}"
	[[ "$(sha256_file "${source_file}")" == "${expected_sha}" ]] \
		|| die "Input fuente divergente tras el sello: ${logical_path}"
done < <(
	jq -r '.components.business.source_inputs[] | [.path, .sha256] | @tsv' \
		"${contract_file}"
)

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-1.99.14-package.XXXXXX")"
# Cleanup idempotente y sólo en EXIT; las señales conservan su código 128+n en
# vez de convertirse en éxito [Codex ronda 2, P1-G].
cleanup_done=0
cleanup() {
	if ((cleanup_done)); then
		return
	fi
	cleanup_done=1
	case "${scratch:-}" in
		*/akibara-business-1.99.14-package.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

first_output="${scratch}/first"
second_output="${scratch}/second"
mkdir -p -- "${first_output}" "${second_output}"
TZ=Pacific/Honolulu bash "${runtime_packager}" --output-dir "${first_output}" >/dev/null
TZ=Asia/Tokyo bash "${runtime_packager}" --output-dir "${second_output}" >/dev/null

for artifact_name in \
	"${candidate_archive_name}" \
	"${candidate_inventory_name}" \
	"${candidate_sidecar_name}"; do
	verify_regular_file "${first_output}/${artifact_name}"
	verify_regular_file "${second_output}/${artifact_name}"
	cmp -s "${first_output}/${artifact_name}" "${second_output}/${artifact_name}" \
		|| die "Artefacto no reproducible: ${artifact_name}"
done

candidate_archive="${first_output}/${candidate_archive_name}"
candidate_inventory="${first_output}/${candidate_inventory_name}"
candidate_sidecar="${first_output}/${candidate_sidecar_name}"
[[ "$(sha256_file "${candidate_archive}")" == \
	"$(jq -r '.components.business.candidate.archive_sha256' "${contract_file}")" ]] \
	|| die "SHA ZIP candidato no coincide con el sello"
[[ "$(sha256_file "${candidate_inventory}")" == \
	"$(jq -r '.components.business.candidate.inventory_sha256' "${contract_file}")" ]] \
	|| die "SHA inventario candidato no coincide con el sello"
(
	cd -- "${first_output}"
	shasum -a 256 -c "${candidate_sidecar_name}" >/dev/null
) || die "Sidecar candidato inválido"
unzip -tq "${candidate_archive}" >/dev/null
[[ "$(wc -l < "${candidate_inventory}" | tr -d ' ')" == 223 ]] \
	|| die "Conteo runtime candidato inesperado"

python3 - "${preimage_inventory}" "${candidate_inventory}" "${contract_file}" <<'PY'
import json
import pathlib
import re
import sys

row = re.compile(r"^([a-f0-9]{64})  ([A-Za-z0-9._/-]+)$")

def load(path):
    result = {}
    for line in pathlib.Path(path).read_text(encoding="utf-8").splitlines():
        match = row.fullmatch(line)
        if match is None or match.group(2) in result:
            raise SystemExit(f"inventario inválido: {path}")
        result[match.group(2)] = match.group(1)
    return result

old = load(sys.argv[1])
new = load(sys.argv[2])
contract = json.loads(pathlib.Path(sys.argv[3]).read_text(encoding="utf-8"))
spec = contract["components"]["business"]
actual = {
    "added": set(new) - set(old),
    "removed": set(old) - set(new),
    "modified": {path for path in set(old) & set(new) if old[path] != new[path]},
}
for category in ("added", "removed", "modified"):
    expected = set(spec[f"{category}_runtime_files"])
    if actual[category] != expected:
        raise SystemExit(
            f"delta empaquetado {category} divergente: {sorted(actual[category])}"
        )
print("PASS: inventario candidato contiene exactamente cinco modificaciones y dos altas runtime")
PY

if ((verify_only)); then
	printf 'PASS: Business 1.99.14 reproducible, sellado y verificado sin publicar\n'
	exit 0
fi

mkdir -p -- "${output_dir}"
[[ -d "${output_dir}" && ! -L "${output_dir}" ]] \
	|| die "Directorio de salida inseguro: ${output_dir}"
for artifact_name in \
	"${candidate_archive_name}" \
	"${candidate_inventory_name}" \
	"${candidate_sidecar_name}"; do
	source_artifact="${first_output}/${artifact_name}"
	target_artifact="${output_dir}/${artifact_name}"
	if [[ -e "${target_artifact}" ]]; then
		[[ -f "${target_artifact}" && ! -L "${target_artifact}" ]] \
			|| die "Destino existente inseguro: ${target_artifact}"
		cmp -s "${source_artifact}" "${target_artifact}" \
			|| die "Se rechaza sobrescribir artefacto divergente: ${target_artifact}"
	else
		install -m 0644 -- "${source_artifact}" "${target_artifact}"
	fi
done

printf 'PASS: artefactos Business 1.99.14 publicados en %s\n' "${output_dir}"
