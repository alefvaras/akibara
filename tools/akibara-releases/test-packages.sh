#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-release-tests.XXXXXX")"
components=(business search checkout rewards theme theme-production)
selected_component=""

if [[ "$#" -gt 0 ]]; then
	if [[ "$#" -ne 2 || "$1" != "--component" ]]; then
		echo "Uso: $0 [--component business|search|checkout|rewards|theme|theme-production]" >&2
		exit 2
	fi
	selected_component="$2"
	case "${selected_component}" in
		business|search|checkout|rewards|theme|theme-production) ;;
		*)
			echo "Componente desconocido: ${selected_component}" >&2
			exit 2
			;;
	esac
	components=("${selected_component}")
fi

cleanup() {
	case "${test_root}" in
		*/akibara-release-tests.*) rm -rf -- "${test_root}" ;;
	esac
}
trap cleanup EXIT

for command_name in cmp rsync shasum unzip zipinfo; do
	if ! command -v "${command_name}" >/dev/null 2>&1; then
		echo "Falta el comando requerido para las pruebas: ${command_name}" >&2
		exit 1
	fi
done

assert_clean_zip() {
	local archive="$1"
	local inventory="$2"
	local extraction_dir="$3"
	local forbidden

	forbidden="$(
		zipinfo -1 "${archive}" | grep -E \
			'(^|/)(tests|node_modules|migrations|graphify-out|coverage|backups|\.git|\.github|\.idea|\.vscode)(/|$)|(^|/)\._[^/]+$|(\.bak|\.bk|\.orig|\.rej|\.tmp|\.temp|\.dump|\.backup|\.sql|\.sqlite|\.tar|\.tgz|\.zip|~|\.DS_Store|\.phpunit\.result\.cache)$|(^|/)(composer\.(json|lock)|package(-lock)?\.json|yarn\.lock|pnpm-lock\.yaml|phpunit\.xml(\.dist)?|coverage\.xml|debug\.log|error_log)$' \
			|| true
	)"
	if [[ -n "${forbidden}" ]]; then
		echo "La prueba encontró rutas prohibidas en ${archive}:" >&2
		echo "${forbidden}" >&2
		exit 1
	fi

	unzip -tq "${archive}" >/dev/null
	mkdir -p "${extraction_dir}"
	unzip -q "${archive}" -d "${extraction_dir}"
	(
		cd "${extraction_dir}"
		shasum -a 256 -c "${inventory}" >/dev/null
	)
}

for component in "${components[@]}"; do
	first_output="${test_root}/${component}-first"
	second_output="${test_root}/${component}-second"
	mkdir -p "${first_output}" "${second_output}"
	source_dir=""
	if [[ "${component}" == "business" ]]; then
		frozen_business_root="${test_root}/business-frozen-source"
		frozen_business_zip="${repo_root}/dist/akibara-business-1.95.2.zip"
		[[ -f "${frozen_business_zip}" && ! -L "${frozen_business_zip}" ]] || {
			echo "Falta el ZIP histórico congelado de Business 1.95.2." >&2
			exit 1
		}
		mkdir -p "${frozen_business_root}"
		unzip -q "${frozen_business_zip}" -d "${frozen_business_root}"
		[[ -d "${frozen_business_root}/akibara-business"
			&& ! -L "${frozen_business_root}/akibara-business" ]] || {
			echo "El ZIP histórico de Business no contiene su raíz esperada." >&2
			exit 1
		}
		source_dir="${frozen_business_root}/akibara-business"
	elif [[ "${component}" == "theme" ]]; then
		frozen_theme_root="${test_root}/theme-frozen-source"
		frozen_theme_zip="${repo_root}/dist/akibara-gp-2.1.48.zip"
		[[ -f "${frozen_theme_zip}" && ! -L "${frozen_theme_zip}" ]] || {
			echo "Falta el ZIP congelado del theme." >&2
			exit 1
		}
		mkdir -p "${frozen_theme_root}"
		unzip -q "${frozen_theme_zip}" -d "${frozen_theme_root}"
		[[ -d "${frozen_theme_root}/akibara-gp"
			&& ! -L "${frozen_theme_root}/akibara-gp" ]] || {
			echo "El ZIP congelado del theme no contiene su raíz esperada." >&2
			exit 1
		}
		source_dir="${frozen_theme_root}/akibara-gp"
	elif [[ "${component}" == "theme-production" ]]; then
		production_theme_root="${repo_root}/.local-backups/theme-superset/20260726T064000Z/candidate/akibara-gp"
		[[ -d "${production_theme_root}" && ! -L "${production_theme_root}" ]] || {
			echo "Falta la fuente sellada del superset productivo." >&2
			exit 1
		}
		source_dir="${production_theme_root}"
	fi

	# `rewards` entró aquí el 2026-09-19 (ronda 5): el perfil existía en
	# package-runtime.sh desde 0.4.x y ningún gate lo ejercitaba — la misma
	# clase de regresión de empaquetado que ya costó un 500 en producción podía
	# volver por ese perfil sin que nada se pusiera rojo. Sale del árbol vivo,
	# como `search` y `checkout`.
	# `business` y `theme` se validan aquí contra ZIPs HISTÓRICOS congelados, no
	# contra el árbol fuente, así que su versión esperada no es la del carril
	# vivo. Sin declararla, subir el carril (1.97.0 / 2.1.52) hacía fallar esta
	# prueba de reproducibilidad. `search` y `checkout` sí salen del árbol y usan
	# el literal por defecto.
	expected_version=""
	case "${component}" in
		business) expected_version='1.95.2' ;;
		theme)    expected_version='2.1.48' ;;
	esac

	package_once() {
		local build_timezone="$1"
		local build_output="$2"
		if [[ -n "${expected_version}" && -n "${source_dir}" ]]; then
			env AKIBARA_EXPECTED_VERSION="${expected_version}" TZ="${build_timezone}" \
				bash "${script_dir}/package-runtime.sh" "${component}" \
				--source-dir "${source_dir}" --output-dir "${build_output}" >/dev/null
		elif [[ -n "${expected_version}" ]]; then
			env AKIBARA_EXPECTED_VERSION="${expected_version}" TZ="${build_timezone}" \
				bash "${script_dir}/package-runtime.sh" "${component}" \
				--output-dir "${build_output}" >/dev/null
		elif [[ -n "${source_dir}" ]]; then
			env TZ="${build_timezone}" \
				bash "${script_dir}/package-runtime.sh" "${component}" \
				--source-dir "${source_dir}" --output-dir "${build_output}" >/dev/null
		else
			env TZ="${build_timezone}" \
				bash "${script_dir}/package-runtime.sh" "${component}" \
				--output-dir "${build_output}" >/dev/null
		fi
	}

	package_once Pacific/Honolulu "${first_output}"
	package_once Asia/Tokyo "${second_output}"

	first_zip="$(find "${first_output}" -maxdepth 1 -type f -name '*.zip' -print)"
	second_zip="$(find "${second_output}" -maxdepth 1 -type f -name '*.zip' -print)"
	first_inventory="$(find "${first_output}" -maxdepth 1 -type f -name '*.files.sha256' -print)"
	second_inventory="$(find "${second_output}" -maxdepth 1 -type f -name '*.files.sha256' -print)"
	first_checksum="$(find "${first_output}" -maxdepth 1 -type f -name '*.zip.sha256' -print)"
	second_checksum="$(find "${second_output}" -maxdepth 1 -type f -name '*.zip.sha256' -print)"

	for artifact in \
		"${first_zip}" "${second_zip}" \
		"${first_inventory}" "${second_inventory}" \
		"${first_checksum}" "${second_checksum}"; do
		if [[ -z "${artifact}" || ! -f "${artifact}" ]]; then
			echo "Falta un artefacto de prueba para ${component}." >&2
			exit 1
		fi
	done

	cmp -s "${first_zip}" "${second_zip}"
	cmp -s "${first_inventory}" "${second_inventory}"
	cmp -s "${first_checksum}" "${second_checksum}"
	dist_zip="${repo_root}/dist/$(basename -- "${first_zip}")"
	dist_inventory="${repo_root}/dist/$(basename -- "${first_inventory}")"
	dist_checksum="${repo_root}/dist/$(basename -- "${first_checksum}")"
	for dist_artifact in "${dist_zip}" "${dist_inventory}" "${dist_checksum}"; do
		if [[ ! -f "${dist_artifact}" || -L "${dist_artifact}" ]]; then
			echo "Falta un artefacto dist regular para ${component}: ${dist_artifact}" >&2
			exit 1
		fi
	done
	cmp -s "${first_zip}" "${dist_zip}" \
		|| {
			echo "El ZIP dist no corresponde a la fuente probada: ${dist_zip}" >&2
			exit 1
		}
	cmp -s "${first_inventory}" "${dist_inventory}" \
		|| {
			echo "El inventario dist no corresponde a la fuente probada: ${dist_inventory}" >&2
			exit 1
		}
	cmp -s "${first_checksum}" "${dist_checksum}" \
		|| {
			echo "El checksum dist no corresponde a la fuente probada: ${dist_checksum}" >&2
			exit 1
		}
	assert_clean_zip \
		"${first_zip}" \
		"${first_inventory}" \
		"${test_root}/${component}-extract"

	collision_output="${test_root}/${component}-collision"
	mkdir -p "${collision_output}"
	rsync -a "${first_output}/" "${collision_output}/"
	collision_zip="$(find "${collision_output}" -maxdepth 1 -type f -name '*.zip' -print)"
	printf 'tampered\n' >> "${collision_zip}"
	if package_once Pacific/Honolulu "${collision_output}" 2>/dev/null; then
		echo "El empaquetador sobrescribió un artefacto divergente." >&2
		exit 1
	fi
done

bad_source_root="${test_root}/bad-source"
mkdir -p "${bad_source_root}"

if [[ -z "${selected_component}" || "${selected_component}" == "search" ]]; then
	bad_search_source="${bad_source_root}/akibara-search"
	mkdir -p "${bad_search_source}"
	rsync -a \
		"${repo_root}/public_html/wp-content/plugins/akibara-search/" \
		"${bad_search_source}/"
	touch "${bad_search_source}/src/unsafe-backup.tmp"

	if bash "${script_dir}/package-runtime.sh" search \
		--source-dir "${bad_search_source}" \
		--output-dir "${test_root}/must-not-exist" >/dev/null 2>&1; then
		echo "El empaquetador aceptó un artefacto prohibido." >&2
		exit 1
	fi
fi

if [[ -z "${selected_component}" || "${selected_component}" == "theme" || "${selected_component}" == "theme-production" ]]; then
	unknown_theme_source="${bad_source_root}/akibara-gp"
	mkdir -p "${unknown_theme_source}"
	rsync -a \
		"${repo_root}/public_html/wp-content/themes/akibara-gp/" \
		"${unknown_theme_source}/"
	touch "${unknown_theme_source}/unreviewed-runtime.php"

	if bash "${script_dir}/package-runtime.sh" theme \
		--source-dir "${unknown_theme_source}" \
		--output-dir "${test_root}/must-not-exist-theme" >/dev/null 2>&1; then
		echo "El empaquetador aceptó una ruta superior no revisada." >&2
		exit 1
	fi
fi

# El gate del ZIP vivo del tema (`qa-theme.sh`) corre las pruebas del árbol sobre
# un ZIP EXTRAÍDO. Nada lo ejercitaba antes de un release, y el 2026-09-23 estaba
# rojo por dos lados sin que ninguna suite lo dijera: min-assets.test.js importa el
# generador de tools/ por ruta relativa y el gate extraía fuera de la estructura
# del repo (ERR_MODULE_NOT_FOUND en `node --test`), y la verificación final
# (`package-runtime.sh --source-dir`) exigía una carpeta que el tema vivo ya no
# trae. Aquí se construye el ZIP del árbol y se le pasa el gate entero.
if [[ -z "${selected_component}" || "${selected_component}" == "theme" ]]; then
	live_theme_output="${test_root}/theme-live-gate"
	live_theme_log="${test_root}/theme-live-gate.log"
	mkdir -p "${live_theme_output}"
	bash "${script_dir}/package-runtime.sh" theme --output-dir "${live_theme_output}" >/dev/null
	if ! bash "${script_dir}/qa-theme.sh" --candidate-dir "${live_theme_output}" >"${live_theme_log}" 2>&1; then
		echo "El gate del tema (qa-theme.sh) no pasa sobre un ZIP del árbol:" >&2
		tail -n 40 "${live_theme_log}" >&2
		exit 1
	fi
	grep -q '^PASS: akibara-gp ' "${live_theme_log}" || {
		echo "qa-theme.sh terminó sin su línea PASS." >&2
		exit 1
	}
fi

if [[ -z "${selected_component}" || "${selected_component}" == "checkout" ]]; then
	bad_checkout_source="${bad_source_root}/akibara-cart-checkout-blocks"
	mkdir -p "${bad_checkout_source}"
	rsync -a \
		"${repo_root}/public_html/wp-content/plugins/akibara-cart-checkout-blocks/" \
		"${bad_checkout_source}/"
	ln -s "assets/js/checkout-blocks.js" "${bad_checkout_source}/unsafe-runtime-link.js"

	if bash "${script_dir}/package-runtime.sh" checkout \
		--source-dir "${bad_checkout_source}" \
		--output-dir "${test_root}/must-not-exist-checkout" >/dev/null 2>&1; then
		echo "El empaquetador aceptó un enlace simbólico." >&2
		exit 1
	fi
fi

echo "PASS: paquetes reproducibles, inventarios válidos y guardas fail-closed"
