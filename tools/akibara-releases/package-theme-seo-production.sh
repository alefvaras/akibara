#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
contract="${repo_root}/ops/contracts/akibara-gp-2.1.50-seo-overlay.json"
output_dir="${repo_root}/dist"
verify_only=0

usage() {
	cat <<'EOF'
Uso:
  bash tools/akibara-releases/package-theme-seo-production.sh [opciones]

Opciones:
  --output-dir RUTA  Directorio local para ZIP e inventarios.
  --verify-only      Construye y valida sin publicar artefactos.

El candidato siempre parte del ZIP sellado Akibara GP 2.1.49. El árbol fuente
2.1.48 aporta únicamente los bytes SEO declarados en el contrato.
EOF
}

while (($#)); do
	case "$1" in
		--output-dir)
			(($# >= 2)) || {
				echo "Falta la ruta para --output-dir." >&2
				exit 2
			}
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
			echo "Opción desconocida: $1" >&2
			usage >&2
			exit 2
			;;
	esac
done

for command_name in cmp find jq python3 shasum unzip zip zipinfo; do
	command -v "${command_name}" >/dev/null 2>&1 || {
		echo "Falta el comando requerido: ${command_name}" >&2
		exit 1
	}
done
[[ -f "${contract}" && ! -L "${contract}" ]] || {
	echo "Falta el contrato SEO 2.1.50." >&2
	exit 1
}
jq -e '
  .schema_version == 2
  and .contract == "akibara-gp-production-seo-overlay"
  and .release.release_set == "akibara-gp-seo-production-2.1.50"
  and .source_theme.version == "2.1.48"
  and .production_preimage.version == "2.1.49"
  and .production_candidate.version == "2.1.50"
  and .production_candidate.files == 103
  and .production_candidate.modified_files == 4
  and .production_candidate.added_files == 2
  and .production_candidate.removed_files == 1
  and (.modified_files | length == 4)
  and (.added_files | length == 2)
  and (.removed_files | length == 1)
  and .removed_files[0].path == "inc/seo-filtered-urls.php"
  and .removed_files[0].owner == "akibara-business"
  and .release.filtered_urls_owner == "akibara-business"
' "${contract}" >/dev/null || {
	echo "El contrato SEO 2.1.50 es inválido." >&2
	exit 1
}

repo_path() {
	local relative="$1" resolved
	[[ -n "${relative}" && "${relative}" != /* && "${relative}" != *'..'*
		&& "${relative}" != *'//'*
		&& "${relative}" != *'/./'* ]] || {
		echo "Ruta contractual insegura: ${relative}" >&2
		exit 1
	}
	resolved="$(
		cd -- "${repo_root}/$(dirname -- "${relative}")"
		printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${relative}")"
	)"
	[[ "${resolved}" == "${repo_root}/"* ]] || {
		echo "Ruta contractual fuera del repositorio: ${relative}" >&2
		exit 1
	}
	printf '%s\n' "${resolved}"
}

base_archive="$(repo_path "$(jq -r '.production_preimage.archive' "${contract}")")"
base_inventory="$(repo_path "$(jq -r '.production_preimage.inventory' "${contract}")")"
source_theme="$(repo_path "$(jq -r '.source_theme.root' "${contract}")")"
[[ -f "${base_archive}" && ! -L "${base_archive}" ]] || {
	echo "Falta el ZIP base 2.1.49." >&2
	exit 1
}
[[ -f "${base_inventory}" && ! -L "${base_inventory}" ]] || {
	echo "Falta el inventario base 2.1.49." >&2
	exit 1
}
[[ -d "${source_theme}" && ! -L "${source_theme}" ]] || {
	echo "Falta el árbol fuente SEO 2.1.48." >&2
	exit 1
}
[[ "$(shasum -a 256 "${base_archive}" | awk '{print $1}')" \
	== "$(jq -r '.production_preimage.archive_sha256' "${contract}")" ]] || {
	echo "El ZIP base 2.1.49 cambió." >&2
	exit 1
}
[[ "$(shasum -a 256 "${base_inventory}" | awk '{print $1}')" \
	== "$(jq -r '.production_preimage.inventory_sha256' "${contract}")" ]] || {
	echo "El inventario base 2.1.49 cambió." >&2
	exit 1
}
source_version="$(
	sed -nE 's/^[[:space:]]*(\*[[:space:]]*)?Version:[[:space:]]*([^[:space:]]+).*/\2/p' \
		"${source_theme}/style.css" | head -n 1
)"
[[ "${source_version}" == "$(jq -r '.source_theme.version' "${contract}")" ]] || {
	echo "El árbol fuente debe permanecer en 2.1.48; se encontró ${source_version:-sin versión}." >&2
	exit 1
}

while IFS=$'\t' read -r relative expected_sha; do
	source_file="${source_theme}/${relative}"
	[[ -f "${source_file}" && ! -L "${source_file}" ]] || {
		echo "Falta una fuente SEO regular: ${relative}" >&2
		exit 1
	}
	actual_sha="$(shasum -a 256 "${source_file}" | awk '{print $1}')"
	[[ "${actual_sha}" == "${expected_sha}" ]] || {
		echo "La fuente SEO cambió fuera del contrato: ${relative}" >&2
		exit 1
	}
done < <(
	jq -r '.source_theme.required_input_files[] | [.path, .sha256] | @tsv' \
		"${contract}"
)

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-theme-seo-release.XXXXXX")"
cleanup() {
	case "${scratch}" in
		*/akibara-theme-seo-release.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

unzip -q "${base_archive}" -d "${scratch}"
candidate="${scratch}/akibara-gp"
[[ -d "${candidate}" && ! -L "${candidate}" ]] || {
	echo "El ZIP base no contiene akibara-gp/." >&2
	exit 1
}

python3 - "${candidate}/functions.php" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
content = path.read_text(encoding="utf-8")
needle = "require_once $akb_inc . 'blog.php';\n"
seo_line = "require_once $akb_inc . 'seo-filtered-urls.php';\n"
rank_line = "require_once $akb_inc . 'rank-math.php';\n"
if content.count(needle) != 1:
    raise SystemExit("functions.php no contiene un único ancla blog.php")
if content.count(seo_line) != 1:
    raise SystemExit("functions.php no contiene el include legacy seo-filtered-urls.php")
if rank_line in content:
    raise SystemExit("functions.php base ya contiene rank-math.php")
content = content.replace(seo_line, "")
path.write_text(content.replace(needle, needle + rank_line), encoding="utf-8")
PY

cmp -s "${candidate}/functions.php" "${source_theme}/functions.php" || {
	echo "El merge de functions.php no coincide con la fuente editorial revisada." >&2
	exit 1
}

legacy_filtered_urls="${candidate}/inc/seo-filtered-urls.php"
[[ -f "${legacy_filtered_urls}" && ! -L "${legacy_filtered_urls}" ]] || {
	echo "La preimagen no contiene inc/seo-filtered-urls.php para retirar." >&2
	exit 1
}
rm -- "${legacy_filtered_urls}"

python3 - "${candidate}/style.css" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
content = path.read_text(encoding="utf-8")
old = "Version:           2.1.49"
new = "Version:           2.1.50"
if content.count(old) != 1 or new in content:
    raise SystemExit("style.css no admite el bump exacto 2.1.49 -> 2.1.50")
path.write_text(content.replace(old, new), encoding="utf-8")
PY

for relative in \
	"single.php" \
	"inc/blog.php" \
	"inc/rank-math.php" \
	"assets/js/rank-math-content-analysis.js"; do
	mkdir -p -- "${candidate}/$(dirname -- "${relative}")"
	cp -- "${source_theme}/${relative}" "${candidate}/${relative}"
done

bash "${script_dir}/verify-theme-seo-production-overlay.sh" \
	--source-dir "${candidate}"

find "${candidate}" -type d -exec chmod 0755 {} +
find "${candidate}" -type f -exec chmod 0644 {} +
TZ=UTC find "${candidate}" -exec touch -t 198001010000 {} +

file_list="${scratch}/files.list"
archive="${scratch}/akibara-gp-2.1.50.zip"
inventory="${scratch}/akibara-gp-2.1.50.files.sha256"
sidecar="${scratch}/akibara-gp-2.1.50.zip.sha256"
(
	cd "${scratch}"
	find akibara-gp -type f -print | LC_ALL=C sort > "${file_list}"
	while IFS= read -r relative; do
		file_sha="$(shasum -a 256 "${relative}" | awk '{print $1}')"
		printf '%s  %s\n' "${file_sha}" "${relative}"
	done < "${file_list}" > "${inventory}"
	COPYFILE_DISABLE=1 TZ=UTC zip -0 -X -q "${archive}" -@ < "${file_list}"
)
archive_sha="$(shasum -a 256 "${archive}" | awk '{print $1}')"
inventory_sha="$(shasum -a 256 "${inventory}" | awk '{print $1}')"
expected_archive_sha="$(jq -r '.production_candidate.archive_sha256' "${contract}")"
expected_inventory_sha="$(jq -r '.production_candidate.inventory_sha256' "${contract}")"
[[ "${archive_sha}" == "${expected_archive_sha}" ]] || {
	echo "SHA-256 inesperado para el ZIP 2.1.50: ${archive_sha}" >&2
	exit 1
}
[[ "${inventory_sha}" == "${expected_inventory_sha}" ]] || {
	echo "SHA-256 inesperado para el inventario 2.1.50: ${inventory_sha}" >&2
	exit 1
}
printf '%s  %s\n' "${archive_sha}" "$(basename -- "${archive}")" > "${sidecar}"
zipinfo -1 "${archive}" > "${scratch}/zip-files.list"
cmp -s "${file_list}" "${scratch}/zip-files.list" || {
	echo "El inventario o el orden del ZIP 2.1.50 divergió." >&2
	exit 1
}
[[ "$(wc -l < "${file_list}" | tr -d ' ')" \
	== "$(jq -r '.production_candidate.files' "${contract}")" ]] || {
	echo "Conteo inesperado de archivos en el ZIP 2.1.50." >&2
	exit 1
}

if ((verify_only)); then
	echo "PASS: Akibara GP SEO 2.1.50 construido y verificado sin publicar artefactos"
	exit 0
fi

mkdir -p -- "${output_dir}"
publish() {
	local source="$1" destination="${output_dir}/$(basename -- "$1")"
	if [[ -e "${destination}" ]]; then
		[[ -f "${destination}" && ! -L "${destination}" ]] || {
			echo "El destino no es un archivo regular: ${destination}" >&2
			exit 1
		}
		cmp -s "${source}" "${destination}" || {
			echo "Se rechaza sobrescribir un artefacto distinto: ${destination}" >&2
			exit 1
		}
		return
	fi
	install -m 0644 -- "${source}" "${destination}"
}
publish "${archive}"
publish "${inventory}"
publish "${sidecar}"

echo "PASS: artefactos reproducibles Akibara GP SEO 2.1.50 publicados en ${output_dir}"
