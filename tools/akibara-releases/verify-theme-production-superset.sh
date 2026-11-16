#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
contract="${repo_root}/ops/contracts/akibara-gp-2.1.49-production-superset.json"
candidate_override=""

usage() {
	cat <<'EOF'
Uso:
  bash tools/akibara-releases/verify-theme-production-superset.sh [--source-dir RUTA]

Verifica que el theme productivo 2.1.49 conserve los 102 paths normales de la
preimagen 2.1.44, que sólo cambien los 11 paths allowlisted y que los siete
archivos exclusivos de producción permanezcan byte a byte.
EOF
}

while (($#)); do
	case "$1" in
		--source-dir)
			(($# >= 2)) || {
				echo "Falta la ruta para --source-dir." >&2
				exit 2
			}
			candidate_override="$2"
			shift 2
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

for command_name in cmp find jq python3 rg sed shasum tar; do
	command -v "${command_name}" >/dev/null 2>&1 || {
		echo "Falta el comando requerido: ${command_name}" >&2
		exit 1
	}
done

[[ -f "${contract}" && ! -L "${contract}" ]] || {
	echo "Falta el contrato regular del superset productivo." >&2
	exit 1
}
jq -e '
  .schema_version == 1
  and .contract == "akibara-gp-production-superset"
  and .qa_theme.version == "2.1.48"
  and .production_preimage.version == "2.1.44"
  and .production_preimage.normal_files == 102
  and (.production_preimage.normal_tree_sha256 | test("^[a-f0-9]{64}$"))
  and (.production_preimage.appledouble_snapshot | type == "string" and length > 0)
  and (.production_preimage.appledouble_snapshot_sha256 | test("^[a-f0-9]{64}$"))
  and (.production_preimage.appledouble_files | length == 5)
  and ([.production_preimage.appledouble_files[].path] | unique | length == 5)
  and ([.production_preimage.appledouble_files[].sha256]
    | all(test("^[a-f0-9]{64}$")))
  and .production_candidate.version == "2.1.49"
  and .production_candidate.files == 102
  and .production_candidate.preserved_files == 91
  and .production_candidate.changed_files == 11
  and (.production_candidate.normal_tree_sha256 | test("^[a-f0-9]{64}$"))
  and (.allowed_changes | length == 11)
  and ([.allowed_changes[].path] | unique | length == 11)
  and ([.allowed_changes[].mode] | all(. == "qa_exact" or . == "reviewed_merge"))
  and (.required_production_only_files | length == 7)
' "${contract}" >/dev/null || {
	echo "El contrato del superset es inválido." >&2
	exit 1
}

snapshot_relative="$(jq -r '.production_preimage.snapshot' "${contract}")"
appledouble_snapshot_relative="$(
	jq -r '.production_preimage.appledouble_snapshot' "${contract}"
)"
candidate_relative="$(jq -r '.production_candidate.source' "${contract}")"
snapshot="${repo_root}/${snapshot_relative}"
appledouble_snapshot="${repo_root}/${appledouble_snapshot_relative}"
candidate="${candidate_override:-${repo_root}/${candidate_relative}}"
frozen="${repo_root}/.local-backups/theme-superset/20260726T064000Z/frozen/akibara-gp"

[[ -f "${snapshot}" && ! -L "${snapshot}" ]] || {
	echo "Falta el snapshot regular de la preimagen productiva." >&2
	exit 1
}
[[ -f "${appledouble_snapshot}" && ! -L "${appledouble_snapshot}" ]] || {
	echo "Falta el snapshot regular de los AppleDouble productivos." >&2
	exit 1
}
[[ -d "${candidate}" && ! -L "${candidate}" ]] || {
	echo "Falta la fuente regular del candidato productivo." >&2
	exit 1
}
[[ -d "${frozen}" && ! -L "${frozen}" ]] || {
	echo "Falta la extracción congelada del theme de referencia 2.1.48." >&2
	exit 1
}

expected_snapshot_sha="$(jq -r '.production_preimage.snapshot_sha256' "${contract}")"
actual_snapshot_sha="$(shasum -a 256 "${snapshot}" | awk '{print $1}')"
[[ "${actual_snapshot_sha}" == "${expected_snapshot_sha}" ]] || {
	echo "El snapshot productivo cambió." >&2
	exit 1
}
expected_appledouble_snapshot_sha="$(
	jq -r '.production_preimage.appledouble_snapshot_sha256' "${contract}"
)"
actual_appledouble_snapshot_sha="$(
	shasum -a 256 "${appledouble_snapshot}" | awk '{print $1}'
)"
[[ "${actual_appledouble_snapshot_sha}" == "${expected_appledouble_snapshot_sha}" ]] || {
	echo "El snapshot AppleDouble productivo cambió." >&2
	exit 1
}

unsafe_archive_paths="$(
	tar -tzf "${snapshot}" | grep -E \
		'(^/|(^|/)\.\.(/|$)|\\|(^|/)\.DS_Store$)' || true
)"
[[ -z "${unsafe_archive_paths}" ]] || {
	echo "El snapshot contiene paths inseguros." >&2
	echo "${unsafe_archive_paths}" >&2
	exit 1
}

candidate_special="$(find "${candidate}" ! -type d ! -type f -print)"
[[ -z "${candidate_special}" ]] || {
	echo "El candidato contiene symlinks o entradas especiales." >&2
	echo "${candidate_special}" >&2
	exit 1
}
candidate_forbidden="$(
	find "${candidate}" -type f \
		\( -name '._*' -o -name '.DS_Store' -o -name '*.bak' \
			-o -name '*.tmp' -o -name '*.temp' -o -name '*~' \) -print
)"
[[ -z "${candidate_forbidden}" ]] || {
	echo "El candidato contiene artefactos prohibidos." >&2
	echo "${candidate_forbidden}" >&2
	exit 1
}

snapshot_normal_paths() {
	tar -tzf "${snapshot}" |
		awk '!/\/$/ {
			sub(/^akibara-gp\//, "")
			name = $0
			sub(/^.*\//, "", name)
			if (name !~ /^\._/) print
		}' |
		LC_ALL=C sort
}

candidate_paths() {
	(
		cd "${candidate}"
		find . -type f ! -path './tests/*' -print |
			sed 's#^\./##' | LC_ALL=C sort
	)
}

raw_appledouble_manifest() {
	local archive="$1" prefix="$2" mode="$3"
	python3 - "${archive}" "${prefix}" "${mode}" <<'PY'
import hashlib
import pathlib
import sys
import tarfile

archive = pathlib.Path(sys.argv[1])
prefix = sys.argv[2]
mode = sys.argv[3]
if mode not in {"filter", "only"}:
    raise SystemExit("Modo inválido para el manifiesto AppleDouble.")

with tarfile.open(archive, mode="r:gz") as bundle:
    rows = []
    for member in bundle.getmembers():
        raw_path = member.name
        if (
            raw_path.startswith("/")
            or "\\" in raw_path
            or any(part == ".." for part in raw_path.split("/"))
        ):
            raise SystemExit("El snapshot contiene paths inseguros.")
        if prefix and member.isdir() and raw_path == prefix.rstrip("/"):
            continue
        if prefix:
            if not raw_path.startswith(prefix):
                raise SystemExit("Una entrada escapó del prefijo del theme.")
            relative = raw_path[len(prefix):]
        else:
            relative = raw_path
        if member.isdir():
            continue
        if not member.isfile():
            raise SystemExit("El snapshot contiene entradas no regulares.")
        if (
            not relative
            or any(part in {"", ".", ".."} for part in relative.split("/"))
        ):
            raise SystemExit("El snapshot contiene un path de archivo inválido.")
        is_appledouble = pathlib.PurePosixPath(relative).name.startswith("._")
        if mode == "only" and not is_appledouble:
            raise SystemExit("El bundle AppleDouble contiene un archivo normal.")
        if not is_appledouble:
            continue
        stream = bundle.extractfile(member)
        if stream is None:
            raise SystemExit("No se pudo leer un AppleDouble archivado.")
        rows.append((relative, hashlib.sha256(stream.read()).hexdigest()))

    paths = [relative for relative, _ in rows]
    if len(paths) != len(set(paths)):
        raise SystemExit("El snapshot contiene paths AppleDouble duplicados.")
    for relative, digest in sorted(rows):
        print(f"{digest}\t{relative}")
PY
}

snapshot_files="$(snapshot_normal_paths | wc -l | tr -d ' ')"
expected_snapshot_files="$(jq -r '.production_preimage.normal_files' "${contract}")"
[[ "${snapshot_files}" == "${expected_snapshot_files}" ]] || {
	echo "Conteo inesperado de archivos normales en la preimagen: ${snapshot_files}." >&2
	exit 1
}
snapshot_tree_sha="$(
	while IFS= read -r relative; do
		file_sha="$(
			tar -xOzf "${snapshot}" "akibara-gp/${relative}" |
				shasum -a 256 | awk '{print $1}'
		)"
		printf '%s\t%s\n' "${file_sha}" "${relative}"
	done < <(snapshot_normal_paths) |
		shasum -a 256 | awk '{print $1}'
)"
expected_snapshot_tree_sha="$(
	jq -r '.production_preimage.normal_tree_sha256' "${contract}"
)"
[[ "${snapshot_tree_sha}" == "${expected_snapshot_tree_sha}" ]] || {
	echo "El tree SHA-256 de la preimagen productiva cambió." >&2
	exit 1
}

expected_appledouble_manifest="$(
	jq -r '
	  .production_preimage.appledouble_files
	  | sort_by(.path)[]
	  | [.sha256, .path]
	  | @tsv
	' "${contract}"
)"
snapshot_appledouble_manifest="$(
	raw_appledouble_manifest "${snapshot}" "akibara-gp/" "filter"
)"
[[ "${snapshot_appledouble_manifest}" == "${expected_appledouble_manifest}" ]] || {
	echo "El snapshot principal no contiene exactamente los cinco AppleDouble declarados." >&2
	diff -u \
		<(printf '%s\n' "${expected_appledouble_manifest}") \
		<(printf '%s\n' "${snapshot_appledouble_manifest}") >&2 || true
	exit 1
}
appledouble_manifest="$(
	raw_appledouble_manifest "${appledouble_snapshot}" "" "only"
)"
[[ "${appledouble_manifest}" == "${expected_appledouble_manifest}" ]] || {
	echo "El snapshot AppleDouble no contiene exactamente los cinco archivos declarados." >&2
	diff -u \
		<(printf '%s\n' "${expected_appledouble_manifest}") \
		<(printf '%s\n' "${appledouble_manifest}") >&2 || true
	exit 1
}
appledouble_tree_sha="$(
	printf '%s\n' "${snapshot_appledouble_manifest}" |
		shasum -a 256 | awk '{print $1}'
)"
expected_appledouble_tree_sha="$(
	jq -r '.production_preimage.appledouble_tree_sha256' "${contract}"
)"
[[ "${appledouble_tree_sha}" == "${expected_appledouble_tree_sha}" ]] || {
	echo "El tree SHA-256 AppleDouble de la preimagen productiva cambió." >&2
	exit 1
}
[[ "$(
	printf '%s\n' "${appledouble_manifest}" |
		shasum -a 256 | awk '{print $1}'
)" == "${expected_appledouble_tree_sha}" ]] || {
	echo "El tree SHA-256 del bundle AppleDouble cambió." >&2
	exit 1
}

if ! cmp -s <(snapshot_normal_paths) <(candidate_paths); then
	echo "El candidato no conserva exactamente los 102 paths normales de producción." >&2
	comm -3 <(snapshot_normal_paths) <(candidate_paths) >&2 || true
	exit 1
fi

expected_files="$(jq -r '.production_candidate.files' "${contract}")"
actual_files="$(candidate_paths | wc -l | tr -d ' ')"
[[ "${actual_files}" == "${expected_files}" ]] || {
	echo "Conteo inesperado de archivos del candidato: ${actual_files}." >&2
	exit 1
}

tree_sha="$(
	while IFS= read -r relative; do
		file_sha="$(shasum -a 256 "${candidate}/${relative}" | awk '{print $1}')"
		printf '%s\t%s\n' "${file_sha}" "${relative}"
	done < <(candidate_paths) |
		shasum -a 256 | awk '{print $1}'
)"
expected_tree_sha="$(jq -r '.production_candidate.normal_tree_sha256' "${contract}")"
[[ "${tree_sha}" == "${expected_tree_sha}" ]] || {
	echo "El tree SHA-256 del candidato cambió: ${tree_sha}." >&2
	exit 1
}

changed_count=0
preserved_count=0
while IFS= read -r relative; do
	preimage_sha="$(
		tar -xOzf "${snapshot}" "akibara-gp/${relative}" |
			shasum -a 256 | awk '{print $1}'
	)"
	candidate_sha="$(shasum -a 256 "${candidate}/${relative}" | awk '{print $1}')"
	if [[ "${preimage_sha}" == "${candidate_sha}" ]]; then
		preserved_count=$((preserved_count + 1))
		continue
	fi

	contract_entry="$(
		jq -c --arg relative "${relative}" \
			'.allowed_changes[] | select(.path == $relative)' "${contract}"
	)"
	[[ -n "${contract_entry}" ]] || {
		echo "Cambio fuera de allowlist: ${relative}." >&2
		exit 1
	}
	[[ "${preimage_sha}" == "$(jq -r '.preimage_sha256' <<<"${contract_entry}")" ]] || {
		echo "Preimagen inesperada para ${relative}." >&2
		exit 1
	}
	[[ "${candidate_sha}" == "$(jq -r '.candidate_sha256' <<<"${contract_entry}")" ]] || {
		echo "Candidato inesperado para ${relative}." >&2
		exit 1
	}
	if [[ "$(jq -r '.mode' <<<"${contract_entry}")" == "qa_exact" ]]; then
		frozen_sha="$(shasum -a 256 "${frozen}/${relative}" | awk '{print $1}')"
		[[ "${candidate_sha}" == "${frozen_sha}" ]] || {
			echo "El cambio exacto respecto de la referencia congelada divergió para ${relative}." >&2
			exit 1
		}
	fi
	changed_count=$((changed_count + 1))
done < <(candidate_paths)

[[ "${changed_count}" == "$(jq -r '.production_candidate.changed_files' "${contract}")" ]] || {
	echo "Cantidad inesperada de cambios productivos: ${changed_count}." >&2
	exit 1
}
[[ "${preserved_count}" == "$(jq -r '.production_candidate.preserved_files' "${contract}")" ]] || {
	echo "Cantidad inesperada de archivos preservados: ${preserved_count}." >&2
	exit 1
}

while IFS=$'\t' read -r relative expected_sha; do
	actual_sha="$(shasum -a 256 "${candidate}/${relative}" | awk '{print $1}')"
	[[ "${actual_sha}" == "${expected_sha}" ]] || {
		echo "Archivo exclusivo productivo divergente: ${relative}." >&2
		exit 1
	}
done < <(
	jq -r '.required_production_only_files[] | [.path, .sha256] | @tsv' "${contract}"
)

rg -q '^Version:[[:space:]]+2\.1\.49$' "${candidate}/style.css" || {
	echo "El candidato no declara la versión 2.1.49." >&2
	exit 1
}
if rg -q 'function[[:space:]]+akibara_gp_cart_title|<h1>Tu carrito</h1>' \
	"${candidate}/inc/woocommerce.php"; then
	echo "El candidato reintrodujo el H1 de carrito duplicado." >&2
	exit 1
fi
rg -q '<h2 id="akb-cart-empty-title" class="akb-empty__title">' \
	"${candidate}/woocommerce/cart/cart-empty.php" || {
	echo "El carrito vacío dejó de usar H2 bajo el H1 canónico." >&2
	exit 1
}

echo "PASS: superset productivo 2.1.49 preserva 91/102 archivos y limita 11 cambios"
