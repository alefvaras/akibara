#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
contract_file="${repo_root}/ops/contracts/akibara-business-1.98.2-blocks-2.1.6.json"
source_delta_verifier="${script_dir}/verify-business-blocks-source-delta.py"
output_dir="${repo_root}/dist"
verify_only=0
derive_only=0

usage() {
	cat <<'EOF'
Uso:
  bash tools/akibara-releases/package-business-blocks-production.sh [opciones]

Opciones:
  --output-dir RUTA  Directorio local para ZIP, inventarios y sidecars.
  --verify-only      Construye y valida sin publicar artefactos.
  --derive-only      Deriva hashes y source_inputs para sellar el contrato.

El candidato Business parte siempre del ZIP productivo sellado 1.98.1 y el
candidato Blocks del ZIP sellado 2.1.4. Solo se superponen, agregan o eliminan
las rutas enumeradas por el contrato. FrontendCompatibility.php de Business
se conserva byte a byte desde la preimagen.
EOF
}

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

sha256_file() {
	shasum -a 256 "$1" | awk '{print $1}'
}

repo_path() {
	local relative="$1" parent resolved
	[[ -n "${relative}" && "${relative}" != /* && "${relative}" != *'..'*
		&& "${relative}" != *'//'*
		&& "${relative}" != *'/./'* ]] \
		|| die "Ruta contractual insegura: ${relative}"
	parent="${repo_root}/$(dirname -- "${relative}")"
	[[ -d "${parent}" && ! -L "${parent}" ]] \
		|| die "Padre contractual ausente o inseguro: ${relative}"
	resolved="$(
		cd -- "${parent}"
		printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${relative}")"
	)"
	[[ "${resolved}" == "${repo_root}/"* ]] \
		|| die "Ruta contractual fuera del repositorio: ${relative}"
	printf '%s\n' "${resolved}"
}

while (($#)); do
	case "$1" in
		--output-dir)
			(($# >= 2)) || die "Falta la ruta para --output-dir"
			output_dir="$2"
			shift 2
			;;
		--verify-only)
			verify_only=1
			shift
			;;
		--derive-only)
			derive_only=1
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

((verify_only + derive_only <= 1)) \
	|| die "--verify-only y --derive-only son mutuamente excluyentes"

for command_name in awk cmp comm find jq python3 shasum unzip zip zipinfo; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| die "Falta el comando requerido: ${command_name}"
done
[[ -f "${contract_file}" && ! -L "${contract_file}" ]] \
	|| die "Falta el contrato regular Business + Blocks"
[[ -f "${source_delta_verifier}" && ! -L "${source_delta_verifier}" ]] \
	|| die "Falta el verificador regular del delta fuente"

jq -e '
  .schema_version == 1
  and .contract == "akibara-business-blocks-production-release"
  and .release_id == "akibara-business-1.98.2_blocks-2.1.6"
  and (.seal_state == "pending" or .seal_state == "sealed")
  and .promotion_sequence == ["docker-local", "production"]
  and (.components | keys | sort) == ["blocks", "business"]
  and .components.business.kind == "plugin"
  and .components.business.slug == "akibara-business"
  and .components.business.preimage.version == "1.98.1"
  and .components.business.candidate.version == "1.98.2"
  and .components.business.preimage.runtime_files == 217
  and .components.business.candidate.runtime_files == 217
  and .components.blocks.kind == "plugin"
  and .components.blocks.slug == "akibara-cart-checkout-blocks"
  and .components.blocks.preimage.version == "2.1.5"
  and .components.blocks.candidate.version == "2.1.6"
  and .components.blocks.preimage.runtime_files == 22
  and .components.blocks.candidate.runtime_files == 22
  and .components.business.preserved_preimage_files == [{
    "path": "akibara-business/src/Modules/FrontendCompatibility/FrontendCompatibility.php",
    "sha256": "ccc00fb744a8dcd681ba436dda67362003e74386bf996e905c8d49a5b0561e49"
  }]
  and .components.blocks.preserved_preimage_files == []
  and .production_invariants.search.slug == "akibara-search"
  and .production_invariants.search.version == "0.1.8"
  and .production_invariants.theme.slug == "akibara-gp"
  and .production_invariants.theme.version == "2.1.58"
  and .production_invariants.bluex.slug == "bluex-for-woocommerce"
  and .production_invariants.bluex.version == "3.2.2"
  and .production_invariants.bluex.must_remain_byte_identical == true
  and .production_invariants.remote_backups_allowed == false
  and .production_invariants.graphify_during_release_allowed == false
  and .exclusive_targets == [
    "public_html/wp-content/plugins/akibara-business",
    "public_html/wp-content/plugins/akibara-cart-checkout-blocks"
  ]
' "${contract_file}" >/dev/null \
	|| die "El contrato no coincide con Business 1.98.2 + Blocks 2.1.6"

seal_state="$(jq -r '.seal_state' "${contract_file}")"
if ((derive_only == 0)) && [[ "${seal_state}" != sealed ]]; then
	die "Contrato pendiente: usa --derive-only después del cierre CAS y sella hashes antes de validar/publicar"
fi

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-blocks-release.XXXXXX")"
cleanup() {
	case "${scratch:-}" in
		*/akibara-business-blocks-release.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

declare -a built_sources=()
declare -a built_destinations=()

validate_component_paths() {
	local component="$1" slug path category duplicates
	slug="$(jq -r ".components.${component}.slug" "${contract_file}")"
	for category in modified_runtime_files added_runtime_files removed_runtime_files; do
		while IFS= read -r path; do
			[[ "${path}" =~ ^[A-Za-z0-9._/-]+$
				&& "${path}" == "${slug}/"*
				&& "${path}" != *'..'* && "${path}" != *'//'*
				&& "${path}" != *'/./'* ]] \
				|| die "${component}: ruta insegura en ${category}: ${path}"
		done < <(jq -r ".components.${component}.${category}[]" "${contract_file}")
	done
	duplicates="$(
		jq -r "[
		  .components.${component}.modified_runtime_files[],
		  .components.${component}.added_runtime_files[],
		  .components.${component}.removed_runtime_files[]
		] | group_by(.)[] | select(length > 1) | .[0]" "${contract_file}"
	)"
	[[ -z "${duplicates}" ]] \
		|| die "${component}: una ruta aparece en más de una categoría: ${duplicates}"
}

verify_source_tree_scope() {
	local component="$1" slug source_root base_inventory

	slug="$(jq -r ".components.${component}.slug" "${contract_file}")"
	source_root="$(repo_path "$(jq -r ".components.${component}.source_root" "${contract_file}")")"
	base_inventory="$(repo_path "$(jq -r ".components.${component}.preimage.inventory" "${contract_file}")")"
	python3 "${source_delta_verifier}" \
		--contract "${contract_file}" \
		--component "${component}" \
		--slug "${slug}" \
		--source-root "${source_root}" \
		--preimage-inventory "${base_inventory}"
}

verify_preimage() {
	local component="$1" archive inventory sidecar
	local expected_archive expected_inventory expected_files slug count forbidden

	archive="$(repo_path "$(jq -r ".components.${component}.preimage.archive" "${contract_file}")")"
	inventory="$(repo_path "$(jq -r ".components.${component}.preimage.inventory" "${contract_file}")")"
	sidecar="${archive}.sha256"
	expected_archive="$(jq -r ".components.${component}.preimage.archive_sha256" "${contract_file}")"
	expected_inventory="$(jq -r ".components.${component}.preimage.inventory_sha256" "${contract_file}")"
	expected_files="$(jq -r ".components.${component}.preimage.runtime_files" "${contract_file}")"
	slug="$(jq -r ".components.${component}.slug" "${contract_file}")"

	for artifact in "${archive}" "${inventory}" "${sidecar}"; do
		[[ -f "${artifact}" && ! -L "${artifact}" && -s "${artifact}" ]] \
			|| die "${component}: preimagen ausente, vacía o insegura: ${artifact}"
	done
	[[ "$(sha256_file "${archive}")" == "${expected_archive}" ]] \
		|| die "${component}: cambió el ZIP preimagen"
	[[ "$(sha256_file "${inventory}")" == "${expected_inventory}" ]] \
		|| die "${component}: cambió el inventario preimagen"
	(
		cd -- "$(dirname -- "${archive}")"
		shasum -a 256 -c "$(basename -- "${sidecar}")" >/dev/null
	) || die "${component}: sidecar preimagen inválido"
	unzip -tq "${archive}" >/dev/null
	count="$(zipinfo -1 "${archive}" | grep -vc '/$')"
	[[ "${count}" == "${expected_files}" ]] \
		|| die "${component}: conteo preimagen inesperado: ${count}"
	forbidden="$(
		zipinfo -1 "${archive}" | grep -E \
			'(^|/)(tests|node_modules|migrations|graphify-out|coverage|backups|\.git|\.github|\.idea|\.vscode)(/|$)|(^|/)\._[^/]+$|(\.bak|\.bk|\.orig|\.rej|\.tmp|\.temp|\.dump|\.backup|\.sql|\.sqlite|\.tar|\.tgz|\.zip|~|\.DS_Store|\.phpunit\.result\.cache)$' \
			|| true
	)"
	[[ -z "${forbidden}" ]] \
		|| die "${component}: preimagen contiene rutas prohibidas: ${forbidden}"
	zipinfo -1 "${archive}" \
		| awk -v root="${slug}/" 'NF && substr($0, 1, length(root)) != root { exit 1 }' \
		|| die "${component}: preimagen fuera de su raíz"
}

verify_source_inputs() {
	local component="$1" slug source_root path relative expected actual
	local expected_paths="${scratch}/${component}.expected-source-paths"
	local contract_paths="${scratch}/${component}.contract-source-paths"

	slug="$(jq -r ".components.${component}.slug" "${contract_file}")"
	source_root="$(repo_path "$(jq -r ".components.${component}.source_root" "${contract_file}")")"
	[[ -d "${source_root}" && ! -L "${source_root}" ]] \
		|| die "${component}: raíz fuente ausente o insegura"
	{
		jq -r ".components.${component}.modified_runtime_files[]" "${contract_file}"
		jq -r ".components.${component}.added_runtime_files[]" "${contract_file}"
	} | LC_ALL=C sort > "${expected_paths}"

	if [[ "${seal_state}" == sealed ]]; then
		jq -r ".components.${component}.source_inputs[].path" "${contract_file}" \
			| LC_ALL=C sort > "${contract_paths}"
		cmp -s "${expected_paths}" "${contract_paths}" \
			|| die "${component}: source_inputs no cubre exactamente el overlay"
	fi

	while IFS= read -r path; do
		relative="${path#"${slug}/"}"
		[[ "${relative}" != "${path}" ]] \
			|| die "${component}: input fuera de raíz: ${path}"
		source_file="${source_root}/${relative}"
		[[ -f "${source_file}" && ! -L "${source_file}" ]] \
			|| die "${component}: input fuente ausente o inseguro: ${relative}"
		actual="$(sha256_file "${source_file}")"
		if [[ "${seal_state}" == sealed ]]; then
			expected="$(
				jq -er --arg path "${path}" \
					".components.${component}.source_inputs[]
					 | select(.path == \$path) | .sha256" "${contract_file}"
			)" || die "${component}: falta hash fuente para ${path}"
			[[ "${expected}" =~ ^[a-f0-9]{64}$ && "${actual}" == "${expected}" ]] \
				|| die "${component}: input fuente cambió tras el sello: ${path}"
		fi
	done < "${expected_paths}"
}

verify_delta() {
	local component="$1" old_inventory="$2" new_inventory="$3"
	local delta_root="${scratch}/delta-${component}"
	local category expected actual

	mkdir -p -- "${delta_root}"
	python3 - "${old_inventory}" "${new_inventory}" "${delta_root}" <<'PY'
from pathlib import Path
import sys

def load(path):
    rows = {}
    for line in Path(path).read_text(encoding="utf-8").splitlines():
        digest, name = line.split(None, 1)
        rows[name.strip()] = digest
    return rows

old = load(sys.argv[1])
new = load(sys.argv[2])
root = Path(sys.argv[3])

def dump(name, paths):
    # Un conjunto vacío debe producir un archivo VACÍO, no una línea en blanco:
    # el lado esperado lo genera `jq -r '...[]'`, que para un array vacío no
    # emite nada, y la comparación es con `cmp` (byte a byte). Con el
    # "\n".join(...) + "\n" de antes, un delta vacío escribía "\n" y el gate
    # moría con "delta added excede el contrato" en cualquier release de puras
    # modificaciones — el caso nunca se había dado hasta 1.97.3.
    rows = sorted(paths)
    (root / name).write_text("".join(f"{p}\n" for p in rows), encoding="utf-8")

dump("added", new.keys() - old.keys())
dump("removed", old.keys() - new.keys())
dump("modified", (p for p in old.keys() & new.keys() if old[p] != new[p]))
PY
	for category in added removed modified; do
		jq -r ".components.${component}.${category}_runtime_files[]" "${contract_file}" \
			| LC_ALL=C sort > "${delta_root}/${category}.expected"
		actual="${delta_root}/${category}"
		expected="${delta_root}/${category}.expected"
		cmp -s "${actual}" "${expected}" \
			|| {
				printf 'Delta real %s/%s:\n' "${component}" "${category}" >&2
				cat "${actual}" >&2
				die "${component}: delta ${category} excede el contrato"
			}
	done
}

verify_candidate_header() {
	local component="$1" candidate_root="$2" version="$3"
	local header constant

	if [[ "${component}" == business ]]; then
		header="$(
			sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' \
				"${candidate_root}/akibara-business.php" | head -n 1
		)"
		constant="$(
			sed -nE \
				"s/^[[:space:]]*define\\([[:space:]]*'AKIBARA_BUSINESS_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\\)[[:space:]]*;.*/\\1/p" \
				"${candidate_root}/akibara-business.php" | head -n 1
		)"
	else
		header="$(
			sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' \
				"${candidate_root}/akibara-cart-checkout-blocks.php" | head -n 1
		)"
		constant="$(
			sed -nE \
				"s/^[[:space:]]*define\\([[:space:]]*'AKIBARA_CART_CHECKOUT_BLOCKS_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\\)[[:space:]]*;.*/\\1/p" \
				"${candidate_root}/akibara-cart-checkout-blocks.php" | head -n 1
		)"
	fi
	[[ "${header}" == "${version}" && "${constant}" == "${version}" ]] \
		|| die "${component}: header/constante no coincide con ${version}"
}

build_component() {
	local component="$1" slug source_root base_archive base_inventory
	local stage_root candidate_root path relative source_file destination
	local candidate_version candidate_archive_relative candidate_inventory_relative
	local archive inventory sidecar file_list zip_list expected_files count forbidden
	local archive_sha inventory_sha expected_archive_sha expected_inventory_sha
	local preserved_sha preserved_actual

	validate_component_paths "${component}"
	verify_preimage "${component}"
	verify_source_tree_scope "${component}"
	verify_source_inputs "${component}"
	slug="$(jq -r ".components.${component}.slug" "${contract_file}")"
	source_root="$(repo_path "$(jq -r ".components.${component}.source_root" "${contract_file}")")"
	base_archive="$(repo_path "$(jq -r ".components.${component}.preimage.archive" "${contract_file}")")"
	base_inventory="$(repo_path "$(jq -r ".components.${component}.preimage.inventory" "${contract_file}")")"
	candidate_version="$(jq -r ".components.${component}.candidate.version" "${contract_file}")"
	candidate_archive_relative="$(jq -r ".components.${component}.candidate.archive" "${contract_file}")"
	candidate_inventory_relative="$(jq -r ".components.${component}.candidate.inventory" "${contract_file}")"
	expected_files="$(jq -r ".components.${component}.candidate.runtime_files" "${contract_file}")"

	stage_root="${scratch}/stage-${component}"
	mkdir -p -- "${stage_root}"
	unzip -q "${base_archive}" -d "${stage_root}"
	candidate_root="${stage_root}/${slug}"
	[[ -d "${candidate_root}" && ! -L "${candidate_root}" ]] \
		|| die "${component}: la preimagen no contiene ${slug}/"
	(
		cd -- "${stage_root}"
		shasum -a 256 -c "${base_inventory}" >/dev/null
	) || die "${component}: la preimagen extraída no coincide con su inventario"

	while IFS=$'\t' read -r path preserved_sha; do
		preserved_actual="$(sha256_file "${stage_root}/${path}")"
		[[ "${preserved_actual}" == "${preserved_sha}" ]] \
			|| die "${component}: archivo preservado no coincide con preimagen: ${path}"
	done < <(
		jq -r ".components.${component}.preserved_preimage_files[]
			| [.path, .sha256] | @tsv" "${contract_file}"
	)

	{
		jq -r ".components.${component}.modified_runtime_files[]" "${contract_file}"
		jq -r ".components.${component}.added_runtime_files[]" "${contract_file}"
	} | while IFS= read -r path; do
		relative="${path#"${slug}/"}"
		source_file="${source_root}/${relative}"
		destination="${candidate_root}/${relative}"
		mkdir -p -- "$(dirname -- "${destination}")"
		cp -- "${source_file}" "${destination}"
	done
	while IFS= read -r path; do
		relative="${path#"${slug}/"}"
		destination="${candidate_root}/${relative}"
		[[ -f "${destination}" && ! -L "${destination}" ]] \
			|| die "${component}: la eliminación contractual no existe: ${path}"
		rm -f -- "${destination}"
	done < <(jq -r ".components.${component}.removed_runtime_files[]" "${contract_file}")

	while IFS=$'\t' read -r path preserved_sha; do
		preserved_actual="$(sha256_file "${stage_root}/${path}")"
		[[ "${preserved_actual}" == "${preserved_sha}" ]] \
			|| die "${component}: el overlay alteró archivo preservado: ${path}"
	done < <(
		jq -r ".components.${component}.preserved_preimage_files[]
			| [.path, .sha256] | @tsv" "${contract_file}"
	)

	verify_candidate_header "${component}" "${candidate_root}" "${candidate_version}"
	forbidden="$(
		find "${candidate_root}" \
			\( -type d \( -name tests -o -name node_modules -o -name migrations \
				-o -name graphify-out -o -name coverage -o -name backups \
				-o -name '.git' -o -name '.github' -o -name '.idea' \
				-o -name '.vscode' \) \
				-o -type f \( -name '*.bak' -o -name '*.bk' -o -name '*.orig' \
				-o -name '*.rej' -o -name '*.tmp' -o -name '*.temp' \
				-o -name '*.dump' -o -name '*.backup' -o -name '*.sql' \
				-o -name '*.sqlite' -o -name '*.tar' -o -name '*.tgz' \
				-o -name '*.zip' -o -name '*~' -o -name '._*' \
				-o -name '.DS_Store' -o -name 'debug.log' -o -name 'error_log' \) \) \
			-print
	)"
	[[ -z "${forbidden}" ]] \
		|| die "${component}: staging contiene rutas prohibidas: ${forbidden}"
	test -z "$(find "${candidate_root}" ! -type d ! -type f -print -quit)" \
		|| die "${component}: staging contiene enlaces o entradas especiales"

	find "${candidate_root}" -type d -exec chmod 0755 {} +
	find "${candidate_root}" -type f -exec chmod 0644 {} +
	TZ=UTC find "${candidate_root}" -exec touch -t 198001010000 {} +

	file_list="${scratch}/${component}.files.list"
	archive="${scratch}/$(basename -- "${candidate_archive_relative}")"
	inventory="${scratch}/$(basename -- "${candidate_inventory_relative}")"
	sidecar="${archive}.sha256"
	zip_list="${scratch}/${component}.zip.list"
	(
		cd -- "${stage_root}"
		find "${slug}" -type f -print | LC_ALL=C sort > "${file_list}"
		while IFS= read -r runtime_file; do
			printf '%s  %s\n' "$(sha256_file "${runtime_file}")" "${runtime_file}"
		done < "${file_list}" > "${inventory}"
		COPYFILE_DISABLE=1 TZ=UTC zip -0 -X -q "${archive}" -@ < "${file_list}"
	)
	count="$(wc -l < "${file_list}" | tr -d ' ')"
	[[ "${count}" == "${expected_files}" ]] \
		|| die "${component}: conteo candidato ${count}; se esperaban ${expected_files}"
	zipinfo -1 "${archive}" > "${zip_list}"
	cmp -s "${file_list}" "${zip_list}" \
		|| die "${component}: orden ZIP divergente"
	(
		cd -- "${stage_root}"
		shasum -a 256 -c "${inventory}" >/dev/null
	) || die "${component}: inventario candidato inválido"
	verify_delta "${component}" "${base_inventory}" "${inventory}"

	archive_sha="$(sha256_file "${archive}")"
	inventory_sha="$(sha256_file "${inventory}")"
	printf '%s  %s\n' "${archive_sha}" "$(basename -- "${archive}")" > "${sidecar}"

	if [[ "${seal_state}" == sealed ]]; then
		expected_archive_sha="$(jq -r ".components.${component}.candidate.archive_sha256" "${contract_file}")"
		expected_inventory_sha="$(jq -r ".components.${component}.candidate.inventory_sha256" "${contract_file}")"
		[[ "${expected_archive_sha}" =~ ^[a-f0-9]{64}$
			&& "${archive_sha}" == "${expected_archive_sha}" ]] \
			|| die "${component}: SHA candidato no coincide con el contrato"
		[[ "${expected_inventory_sha}" =~ ^[a-f0-9]{64}$
			&& "${inventory_sha}" == "${expected_inventory_sha}" ]] \
			|| die "${component}: SHA inventario no coincide con el contrato"
	fi

	jq -n \
		--arg component "${component}" \
		--arg archive_sha256 "${archive_sha}" \
		--arg inventory_sha256 "${inventory_sha}" \
		--argjson runtime_files "${count}" \
		--argjson source_inputs "$(
			while IFS= read -r path; do
				relative="${path#"${slug}/"}"
				jq -cn \
					--arg path "${path}" \
					--arg sha256 "$(sha256_file "${source_root}/${relative}")" \
					'{path:$path,sha256:$sha256}'
			done < <(
				{
					jq -r ".components.${component}.modified_runtime_files[]" "${contract_file}"
					jq -r ".components.${component}.added_runtime_files[]" "${contract_file}"
				} | LC_ALL=C sort
			) | jq -s .
		)" \
		'{
		  component: $component,
		  candidate: {
		    archive_sha256: $archive_sha256,
		    inventory_sha256: $inventory_sha256,
		    runtime_files: $runtime_files
		  },
		  source_inputs: $source_inputs
		}' > "${scratch}/${component}.derived.json"

	built_sources+=("${archive}" "${inventory}" "${sidecar}")
	built_destinations+=(
		"${output_dir}/$(basename -- "${archive}")"
		"${output_dir}/$(basename -- "${inventory}")"
		"${output_dir}/$(basename -- "${sidecar}")"
	)
	printf '%s archive_sha256=%s inventory_sha256=%s files=%s\n' \
		"${component}" "${archive_sha}" "${inventory_sha}" "${count}"
}

build_component business
build_component blocks

if ((derive_only)); then
	jq -s '{
	  seal_state: "sealed",
	  components: (map({
	    key: .component,
	    value: {
	      candidate: .candidate,
	      source_inputs: .source_inputs
	    }
	  }) | from_entries)
	}' "${scratch}/business.derived.json" "${scratch}/blocks.derived.json"
	printf 'DERIVE_ONLY: no se publicaron artefactos\n'
	exit 0
fi

if ((verify_only)); then
	printf 'PASS: Business 1.98.2 + Blocks 2.1.6 reproducibles y verificados sin publicar\n'
	exit 0
fi

mkdir -p -- "${output_dir}"
for ((artifact_index = 0; artifact_index < ${#built_sources[@]}; artifact_index++)); do
	source_artifact="${built_sources[$artifact_index]}"
	target_artifact="${built_destinations[$artifact_index]}"
	if [[ -e "${target_artifact}" ]]; then
		[[ -f "${target_artifact}" && ! -L "${target_artifact}" ]] \
			|| die "Destino inseguro: ${target_artifact}"
		cmp -s "${source_artifact}" "${target_artifact}" \
			|| die "Se rechaza sobrescribir artefacto distinto: ${target_artifact}"
	fi
done
for ((artifact_index = 0; artifact_index < ${#built_sources[@]}; artifact_index++)); do
	source_artifact="${built_sources[$artifact_index]}"
	target_artifact="${built_destinations[$artifact_index]}"
	if [[ ! -e "${target_artifact}" ]]; then
		install -m 0644 -- "${source_artifact}" "${target_artifact}"
	fi
done

printf 'PASS: artefactos Business 1.98.2 + Blocks 2.1.6 publicados en %s\n' \
	"${output_dir}"
