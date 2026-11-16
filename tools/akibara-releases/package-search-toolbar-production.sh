#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
contract_file="${repo_root}/ops/contracts/akibara-search-toolbar-0.1.10-theme-2.1.58.json"
output_dir="${repo_root}/dist"
verify_only=0
derive_only=0

usage() {
	cat <<'EOF'
Uso:
  bash tools/akibara-releases/package-search-toolbar-production.sh [opciones]

Opciones:
  --output-dir RUTA  Directorio local para ZIP, inventarios y sidecars.
  --verify-only      Construye y valida los candidatos sin publicarlos.
  --derive-only      Imprime hashes derivados sin exigir/publicar hashes candidatos.

El release siempre parte de los ZIP sellados de la preimagen que declara el
contrato —hoy Search 0.1.9 y theme 2.1.58—. Solo superpone las rutas fuente
declaradas ahí; en este release el theme va SIN cambios (preimage == candidate,
no hay bump de style.css que aplicar).
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
	local relative="$1" resolved
	[[ -n "${relative}" && "${relative}" != /* && "${relative}" != *'..'*
		&& "${relative}" != *'//'*
		&& "${relative}" != *'/./'* ]] || die "Ruta contractual insegura: ${relative}"
	resolved="$(
		cd -- "${repo_root}/$(dirname -- "${relative}")"
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

for command_name in awk cmp find jq python3 shasum unzip zip zipinfo; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| die "Falta el comando requerido: ${command_name}"
done
[[ -f "${contract_file}" && ! -L "${contract_file}" ]] \
	|| die "Falta el contrato regular del hotfix: ${contract_file}"

jq -e '
  .schema_version == 1
  and .contract == "akibara-search-toolbar-production-hotfix"
  and .release_id == "akibara-search-0.1.10_theme-production-2.1.58"
  and .promotion_sequence == ["docker-local", "production"]
  and .components.search.kind == "plugin"
  and .components.search.slug == "akibara-search"
  and .components.search.preimage.version == "0.1.9"
  and .components.search.candidate.version == "0.1.10"
  and .components.search.preimage.runtime_files == 45
  and .components.search.candidate.runtime_files == 45
  and .components.theme.kind == "theme"
  and .components.theme.slug == "akibara-gp"
  and .components.theme.preimage.version == "2.1.58"
  and .components.theme.candidate.version == "2.1.58"
  and .components.theme.preimage.runtime_files == 102
  and .components.theme.candidate.runtime_files == 102
  and .components.search.allowed_runtime_changes == [
    "akibara-search/akibara-search.php",
    "akibara-search/assets/js/akb-search.js",
    "akibara-search/assets/js/akb-search.min.js"
  ]
  and .components.theme.allowed_runtime_changes == []
  and .components.search.added_runtime_files == []
  and .components.theme.added_runtime_files == []
  and .components.search.removed_runtime_files == []
  and .components.theme.removed_runtime_files == []
  and (.components.search.source_inputs | length) == 3
  and (.components.theme.source_inputs | length) == 0
' "${contract_file}" >/dev/null \
	|| die "El contrato no coincide con Search 0.1.10 + theme 2.1.58"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-search-toolbar-release.XXXXXX")"
cleanup() {
	case "${scratch:-}" in
		*/akibara-search-toolbar-release.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

declare -a built_sources=()
declare -a built_destinations=()

verify_preimage() {
	local component_key="$1" archive inventory sidecar expected_archive expected_inventory
	local expected_files slug count forbidden

	archive="$(repo_path "$(jq -r ".components.${component_key}.preimage.archive" "${contract_file}")")"
	inventory="$(repo_path "$(jq -r ".components.${component_key}.preimage.inventory" "${contract_file}")")"
	sidecar="${archive}.sha256"
	expected_archive="$(jq -r ".components.${component_key}.preimage.archive_sha256" "${contract_file}")"
	expected_inventory="$(jq -r ".components.${component_key}.preimage.inventory_sha256" "${contract_file}")"
	expected_files="$(jq -r ".components.${component_key}.preimage.runtime_files" "${contract_file}")"
	slug="$(jq -r ".components.${component_key}.slug" "${contract_file}")"

	for artifact in "${archive}" "${inventory}" "${sidecar}"; do
		[[ -f "${artifact}" && ! -L "${artifact}" && -s "${artifact}" ]] \
			|| die "Preimagen ausente, vacía o insegura: ${artifact}"
	done
	[[ "$(sha256_file "${archive}")" == "${expected_archive}" ]] \
		|| die "El ZIP preimagen cambió: ${archive}"
	[[ "$(sha256_file "${inventory}")" == "${expected_inventory}" ]] \
		|| die "El inventario preimagen cambió: ${inventory}"
	(
		cd -- "$(dirname -- "${archive}")"
		shasum -a 256 -c "$(basename -- "${sidecar}")" >/dev/null
	) || die "El sidecar rechazó la preimagen: ${archive}"
	unzip -tq "${archive}" >/dev/null
	count="$(zipinfo -1 "${archive}" | grep -vc '/$')"
	[[ "${count}" == "${expected_files}" ]] \
		|| die "Conteo inesperado en preimagen ${slug}: ${count}"
	forbidden="$(
		zipinfo -1 "${archive}" | grep -E \
			'(^|/)(tests|node_modules|migrations|graphify-out|coverage|backups|\.git|\.github|\.idea|\.vscode)(/|$)|(^|/)\._[^/]+$|(\.bak|\.bk|\.orig|\.rej|\.tmp|\.temp|\.dump|\.backup|\.sql|\.sqlite|\.tar|\.tgz|\.zip|~|\.DS_Store|\.phpunit\.result\.cache)$' \
			|| true
	)"
	[[ -z "${forbidden}" ]] \
		|| die "La preimagen contiene rutas prohibidas: ${forbidden}"
}

verify_source_inputs() {
	local component_key="$1" source_root relative expected source_file actual

	source_root="$(repo_path "$(jq -r ".components.${component_key}.source_root" "${contract_file}")")"
	[[ -d "${source_root}" && ! -L "${source_root}" ]] \
		|| die "Origen runtime ausente o inseguro: ${source_root}"
	while IFS=$'\t' read -r relative expected; do
		[[ "${relative}" =~ ^[A-Za-z0-9._/-]+$
			&& "${relative}" != /* && "${relative}" != *'..'*
			&& "${relative}" != *'//'*
			&& "${relative}" != *'/./'* ]] \
			|| die "Input fuente inseguro: ${relative}"
		source_file="${source_root}/${relative}"
		[[ -f "${source_file}" && ! -L "${source_file}" ]] \
			|| die "Input fuente ausente o inseguro: ${source_file}"
		actual="$(sha256_file "${source_file}")"
		[[ "${actual}" == "${expected}" ]] \
			|| die "Input fuente cambió fuera del contrato: ${relative}"
	done < <(
		jq -r ".components.${component_key}.source_inputs[] | [.path, .sha256] | @tsv" \
			"${contract_file}"
	)
}

verify_exact_delta() {
	local component_key="$1" old_inventory="$2" new_inventory="$3"
	local delta_dir="${scratch}/delta-${component_key}"
	local old_paths="${delta_dir}/old.paths" new_paths="${delta_dir}/new.paths"
	local changes="${delta_dir}/changes" expected="${delta_dir}/expected"

	mkdir -p -- "${delta_dir}"
	# `comm` exige entrada ordenada: no dependemos del orden del inventario.
	awk '{ $1=""; sub(/^[[:space:]]+/, ""); print }' \
		"${old_inventory}" | LC_ALL=C sort > "${old_paths}"
	awk '{ $1=""; sub(/^[[:space:]]+/, ""); print }' \
		"${new_inventory}" | LC_ALL=C sort > "${new_paths}"
	# Altas y bajas: permitidas SOLO si el contrato las declara exactamente.
	# Antes se rechazaban en bloque (`cmp` de listas de rutas), lo que impedía
	# expresar la eliminación de un archivo muerto (auditoría 2026-07-28). Esto
	# es MÁS estricto que el rechazo previo: cada ruta que aparece o desaparece
	# debe estar enumerada, así que un archivo colado sigue abortando el gate.
	local added="${delta_dir}/added" removed="${delta_dir}/removed"
	local added_expected="${delta_dir}/added.expected"
	local removed_expected="${delta_dir}/removed.expected"

	LC_ALL=C comm -13 "${old_paths}" "${new_paths}" > "${added}"
	LC_ALL=C comm -23 "${old_paths}" "${new_paths}" > "${removed}"
	jq -r ".components.${component_key}.added_runtime_files[]?" "${contract_file}" \
		| LC_ALL=C sort > "${added_expected}"
	jq -r ".components.${component_key}.removed_runtime_files[]?" "${contract_file}" \
		| LC_ALL=C sort > "${removed_expected}"
	cmp -s "${added}" "${added_expected}" \
		|| die "${component_key}: las altas de rutas runtime no coinciden con added_runtime_files"
	cmp -s "${removed}" "${removed_expected}" \
		|| die "${component_key}: las bajas de rutas runtime no coinciden con removed_runtime_files"

	# `changes` compara solo las rutas presentes en AMBOS inventarios: una baja
	# no es una modificación y ya se validó arriba.
	awk '
	  NR == FNR { old[$2] = $1; next }
	  ($2 in old) && old[$2] != $1 { print $2 }
	' "${old_inventory}" "${new_inventory}" \
		| LC_ALL=C sort > "${changes}"
	jq -r ".components.${component_key}.allowed_runtime_changes[]" "${contract_file}" \
		| LC_ALL=C sort > "${expected}"
	cmp -s "${changes}" "${expected}" \
		|| die "${component_key}: el delta runtime excede la allowlist contractual"
}

verify_candidate_header() {
	local component_key="$1" candidate_root="$2" expected_version="$3"
	local header_version constant_version

	if [[ "${component_key}" == search ]]; then
		header_version="$(
			sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' \
				"${candidate_root}/akibara-search.php" | head -n 1
		)"
		constant_version="$(
			sed -nE \
				"s/^[[:space:]]*define\\([[:space:]]*'AKIBARA_SEARCH_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\\)[[:space:]]*;.*/\\1/p" \
				"${candidate_root}/akibara-search.php" | head -n 1
		)"
		[[ "${header_version}" == "${expected_version}"
			&& "${constant_version}" == "${expected_version}" ]] \
			|| die "Header/constante Search no coincide con ${expected_version}"
	else
		header_version="$(
			sed -nE 's/^[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' \
				"${candidate_root}/style.css" | head -n 1
		)"
		[[ "${header_version}" == "${expected_version}" ]] \
			|| die "Header theme no coincide con ${expected_version}"
		grep -Eq '^[[:space:]]*Template:[[:space:]]+generatepress[[:space:]]*$' \
			"${candidate_root}/style.css" \
			|| die "El candidato theme dejó de declarar GeneratePress"
	fi
}

build_component() {
	local component_key="$1" slug kind source_root base_archive base_inventory
	local stage_root candidate_root relative expected source_file destination
	local preimage_version candidate_version candidate_archive_relative
	local candidate_inventory_relative archive inventory sidecar file_list
	local archive_sha inventory_sha expected_archive_sha expected_inventory_sha
	local expected_files count forbidden

	verify_preimage "${component_key}"
	verify_source_inputs "${component_key}"
	slug="$(jq -r ".components.${component_key}.slug" "${contract_file}")"
	kind="$(jq -r ".components.${component_key}.kind" "${contract_file}")"
	source_root="$(repo_path "$(jq -r ".components.${component_key}.source_root" "${contract_file}")")"
	base_archive="$(repo_path "$(jq -r ".components.${component_key}.preimage.archive" "${contract_file}")")"
	base_inventory="$(repo_path "$(jq -r ".components.${component_key}.preimage.inventory" "${contract_file}")")"
	preimage_version="$(jq -r ".components.${component_key}.preimage.version" "${contract_file}")"
	candidate_version="$(jq -r ".components.${component_key}.candidate.version" "${contract_file}")"
	candidate_archive_relative="$(jq -r ".components.${component_key}.candidate.archive" "${contract_file}")"
	candidate_inventory_relative="$(jq -r ".components.${component_key}.candidate.inventory" "${contract_file}")"
	expected_files="$(jq -r ".components.${component_key}.candidate.runtime_files" "${contract_file}")"
	expected_archive_sha="$(jq -r ".components.${component_key}.candidate.archive_sha256" "${contract_file}")"
	expected_inventory_sha="$(jq -r ".components.${component_key}.candidate.inventory_sha256" "${contract_file}")"

	stage_root="${scratch}/stage-${component_key}"
	mkdir -p -- "${stage_root}"
	unzip -q "${base_archive}" -d "${stage_root}"
	candidate_root="${stage_root}/${slug}"
	[[ -d "${candidate_root}" && ! -L "${candidate_root}" ]] \
		|| die "La preimagen ${component_key} no contiene ${slug}/"
	(
		cd -- "${stage_root}"
		shasum -a 256 -c "${base_inventory}" >/dev/null
	) || die "El inventario no valida la preimagen extraída ${component_key}"

	while IFS=$'\t' read -r relative expected; do
		source_file="${source_root}/${relative}"
		destination="${candidate_root}/${relative}"
		mkdir -p -- "$(dirname -- "${destination}")"
		cp -- "${source_file}" "${destination}"
	done < <(
		jq -r ".components.${component_key}.source_inputs[] | [.path, .sha256] | @tsv" \
			"${contract_file}"
	)

	# Bajas declaradas: el candidato se arma desde la preimagen, así que un
	# archivo eliminado hay que borrarlo del staging o sobrevive al release
	# (auditoría 2026-07-28). Las rutas del contrato vienen con prefijo de slug,
	# igual que en los inventarios; se recorta para ubicarlas en el staging.
	while IFS= read -r removed_path; do
		[[ -n "${removed_path}" ]] || continue
		local removed_relative="${removed_path#"${slug}/"}"
		[[ "${removed_relative}" != "${removed_path}" ]] \
			|| die "${component_key}: ruta eliminada sin prefijo de slug: ${removed_path}"
		local removed_file="${candidate_root}/${removed_relative}"
		[[ -f "${removed_file}" && ! -L "${removed_file}" ]] \
			|| die "${component_key}: la baja declarada no existe en la preimagen: ${removed_path}"
		rm -- "${removed_file}"
	done < <(
		jq -r ".components.${component_key}.removed_runtime_files[]?" "${contract_file}"
	)

	# Un componente sin cambios en este release conserva su versión: no hay
	# bump que aplicar y reescribir style.css sería un no-op imposible
	# (`Version: X -> X`). El carril ya tolera esto del lado de Search; esto lo
	# hace simétrico para el theme (auditoría 2026-07-28).
	if [[ "${kind}" == theme && "${preimage_version}" != "${candidate_version}" ]]; then
		python3 - "${candidate_root}/style.css" "${preimage_version}" "${candidate_version}" <<'PY'
from pathlib import Path
import sys

target = Path(sys.argv[1])
old_version = sys.argv[2]
new_version = sys.argv[3]
content = target.read_text(encoding="utf-8")
old = f"Version:           {old_version}"
new = f"Version:           {new_version}"
if content.count(old) != 1 or new in content:
    raise SystemExit(f"style.css no admite el bump exacto {old_version} -> {new_version}")
target.write_text(content.replace(old, new), encoding="utf-8")
PY
	fi
	verify_candidate_header "${component_key}" "${candidate_root}" "${candidate_version}"

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
		|| die "El staging ${component_key} contiene rutas prohibidas: ${forbidden}"

	find "${candidate_root}" -type d -exec chmod 0755 {} +
	find "${candidate_root}" -type f -exec chmod 0644 {} +
	TZ=UTC find "${candidate_root}" -exec touch -t 198001010000 {} +

	file_list="${scratch}/${component_key}.files.list"
	archive="${scratch}/$(basename -- "${candidate_archive_relative}")"
	inventory="${scratch}/$(basename -- "${candidate_inventory_relative}")"
	sidecar="${archive}.sha256"
	(
		cd -- "${stage_root}"
		find "${slug}" -type f -print | LC_ALL=C sort > "${file_list}"
		while IFS= read -r runtime_file; do
			runtime_sha="$(shasum -a 256 "${runtime_file}" | awk '{print $1}')"
			printf '%s  %s\n' "${runtime_sha}" "${runtime_file}"
		done < "${file_list}" > "${inventory}"
		COPYFILE_DISABLE=1 TZ=UTC zip -0 -X -q "${archive}" -@ < "${file_list}"
	)
	count="$(wc -l < "${file_list}" | tr -d ' ')"
	[[ "${count}" == "${expected_files}" ]] \
		|| die "Conteo candidato inesperado para ${component_key}: ${count}"
	zipinfo -1 "${archive}" > "${scratch}/${component_key}.zip.list"
	cmp -s "${file_list}" "${scratch}/${component_key}.zip.list" \
		|| die "Orden/inventario ZIP divergente para ${component_key}"
	(
		cd -- "${stage_root}"
		shasum -a 256 -c "${inventory}" >/dev/null
	) || die "El inventario candidato rechazó ${component_key}"
	verify_exact_delta "${component_key}" "${base_inventory}" "${inventory}"

	archive_sha="$(sha256_file "${archive}")"
	inventory_sha="$(sha256_file "${inventory}")"
	printf '%s  %s\n' "${archive_sha}" "$(basename -- "${archive}")" > "${sidecar}"
	printf '%s candidate_archive_sha256=%s candidate_inventory_sha256=%s files=%s\n' \
		"${component_key}" "${archive_sha}" "${inventory_sha}" "${count}"
	if ((derive_only == 0)); then
		[[ "${archive_sha}" == "${expected_archive_sha}" ]] \
			|| die "SHA-256 candidato inesperado para ${component_key}: ${archive_sha}"
		[[ "${inventory_sha}" == "${expected_inventory_sha}" ]] \
			|| die "SHA-256 inventario inesperado para ${component_key}: ${inventory_sha}"
	fi

	built_sources+=("${archive}" "${inventory}" "${sidecar}")
	built_destinations+=(
		"${output_dir}/$(basename -- "${archive}")"
		"${output_dir}/$(basename -- "${inventory}")"
		"${output_dir}/$(basename -- "${sidecar}")"
	)
}

build_component search
build_component theme

if ((derive_only)); then
	printf 'DERIVE_ONLY: no se publicaron artefactos\n'
	exit 0
fi
if ((verify_only)); then
	printf 'PASS: Search 0.1.10 + theme 2.1.58 reproducibles y verificados sin publicar\n'
	exit 0
fi

mkdir -p -- "${output_dir}"
for ((artifact_index = 0; artifact_index < ${#built_sources[@]}; artifact_index++)); do
	source_artifact="${built_sources[$artifact_index]}"
	target_artifact="${built_destinations[$artifact_index]}"
	if [[ -e "${target_artifact}" ]]; then
		[[ -f "${target_artifact}" && ! -L "${target_artifact}" ]] \
			|| die "Destino de artefacto inseguro: ${target_artifact}"
		cmp -s "${source_artifact}" "${target_artifact}" \
			|| die "Se rechaza sobrescribir un artefacto distinto: ${target_artifact}"
	fi
done
for ((artifact_index = 0; artifact_index < ${#built_sources[@]}; artifact_index++)); do
	source_artifact="${built_sources[$artifact_index]}"
	target_artifact="${built_destinations[$artifact_index]}"
	if [[ ! -e "${target_artifact}" ]]; then
		install -m 0644 -- "${source_artifact}" "${target_artifact}"
	fi
done

printf 'PASS: artefactos Search 0.1.10 + theme 2.1.58 publicados en %s\n' \
	"${output_dir}"
