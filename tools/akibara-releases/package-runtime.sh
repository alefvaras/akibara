#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
output_dir="${repo_root}/dist"
source_override=""
verify_only=0

usage() {
	cat <<'EOF'
Uso:
  bash tools/akibara-releases/package-runtime.sh <business|business-seo|search|checkout|rewards|theme|theme-production> [opciones]

Opciones:
  --output-dir RUTA  Directorio local para ZIP e inventarios.
  --source-dir RUTA  Fuente alternativa; se usa en las pruebas aisladas.
  --verify-only      Construye y valida sin publicar artefactos.
EOF
}

if (($# < 1)); then
	usage >&2
	exit 2
fi

component="$1"
shift

while (($#)); do
	case "$1" in
		--output-dir)
			if (($# < 2)); then
				echo "Falta la ruta para --output-dir." >&2
				exit 2
			fi
			output_dir="$2"
			shift 2
			;;
		--source-dir)
			if (($# < 2)); then
				echo "Falta la ruta para --source-dir." >&2
				exit 2
			fi
			source_override="$2"
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

	case "${component}" in
	business|business-seo)
		slug="akibara-business"
		expected_version=""
		default_source="${repo_root}/public_html/wp-content/plugins/${slug}"
		header_file="akibara-business.php"
		version_constant_name="AKIBARA_BUSINESS_VERSION"
		required_paths=(
			"akibara-business.php"
			"assets"
			"src/Contracts/CheckoutDataProvider.php"
			"src/Plugin.php"
			"src/Services/CheckoutDataService.php"
			"src/Utilities/ChileanPhone.php"
			"templates"
			"uninstall.php"
		)
		included_top_level=(
			"akibara-business.php"
			"assets"
			"src"
			"templates"
			"uninstall.php"
		)
		excluded_top_level=(".distignore" ".graphifyignore" "tests")
		;;
	search)
		slug="akibara-search"
		expected_version=""
		default_source="${repo_root}/public_html/wp-content/plugins/${slug}"
		header_file="akibara-search.php"
		version_constant_name="AKIBARA_SEARCH_VERSION"
		required_paths=(
			"akibara-search.php"
			"assets/css"
			"assets/js"
			"frontend/facets-render.php"
			"templates/admin"
			"src/Search.php"
			"src/Install.php"
			"uninstall.php"
		)
		included_top_level=(
			"akibara-search.php"
			"assets"
			"frontend"
			"templates"
			"src"
			"uninstall.php"
		)
		excluded_top_level=("tests")
		;;
	checkout)
		slug="akibara-cart-checkout-blocks"
		expected_version=""
		default_source="${repo_root}/public_html/wp-content/plugins/${slug}"
		header_file="akibara-cart-checkout-blocks.php"
		version_constant_name="AKIBARA_CART_CHECKOUT_BLOCKS_VERSION"
		required_paths=(
			"akibara-cart-checkout-blocks.php"
			"assets/css/cart-checkout-blocks.css"
			"assets/css/comuna-combobox.css"
			"assets/css/pudo-blocks.css"
			"assets/images/google-maps-logo-dark-gray.svg"
			"assets/js/cart-checkout-blocks.js"
			"assets/js/checkout-blocks.js"
			"assets/js/checkout-inner-blocks-editor.js"
			"assets/js/checkout-inner-blocks-frontend.js"
			"assets/js/checkout-rut.js"
			"assets/js/comuna-combobox.js"
			"assets/js/google-places-address-provider.js"
			"assets/js/pudo-blocks.js"
			"src/AddressIntegration.php"
			"src/Assets.php"
			"src/BlocksPage.php"
			"src/CheckoutBlockIntegration.php"
			"src/CheckoutFields.php"
			"src/ComunaDirectory.php"
			"src/GooglePlacesAddressProvider.php"
			"src/Plugin.php"
			"src/PudoIntegration.php"
			"src/PudoServiceAdapter.php"
			"src/RuntimeCompatibility.php"
			"src/Shortcodes.php"
			"src/StoreApiIntegration.php"
		)
		included_top_level=(
			"akibara-cart-checkout-blocks.php"
			"assets"
			"src"
			"uninstall.php"
		)
		excluded_top_level=("tests")
		;;
	rewards)
		slug="akibara-rewards"
		expected_version=""
		default_source="${repo_root}/public_html/wp-content/plugins/${slug}"
		header_file="akibara-rewards.php"
		version_constant_name="AKIBARA_REWARDS_VERSION"
		required_paths=(
			"akibara-rewards.php"
			"assets"
			"src/Plugin.php"
			"src/Support/Schema.php"
			"src/Support/SettingsPage.php"
			"src/Points/Ledger.php"
			"src/Points/Migration.php"
			"src/Reviews/Invitations.php"
			"uninstall.php"
		)
		included_top_level=(
			"akibara-rewards.php"
			"assets"
			"src"
			"uninstall.php"
		)
		excluded_top_level=("tests")
		;;
	theme)
		slug="akibara-gp"
		expected_version=""
		default_source="${repo_root}/public_html/wp-content/themes/${slug}"
		header_file="style.css"
		version_constant_name=""
		required_paths=(
			"style.css"
			"functions.php"
			"theme.json"
			"inc/setup.php"
			"inc/enqueue.php"
			"template-parts/header/shell.php"
			# archive-product.php RETIRADO 2026-08-31 (era copia idéntica del core, tanda 1)
		)
		included_top_level=(
			"404.php"
			"archive.php"
			"assets"
			"footer.php"
			"functions.php"
			"header.php"
			"home.php"
			"inc"
			"single.php"
			"style.css"
			"template-parts"
			"theme.json"
			"woocommerce"
		)
		excluded_top_level=("tests")
		;;
	theme-production)
		slug="akibara-gp"
		# Fuente CONGELADA: el pin es legítimo acá, protege que la copia sellada
		# de .local-backups no cambie. No lo conviertas en "" como los perfiles vivos.
		expected_version="2.1.49"
		default_source="${repo_root}/.local-backups/theme-superset/20260726T064000Z/candidate/${slug}"
		header_file="style.css"
		version_constant_name=""
		required_paths=(
			"style.css"
			"functions.php"
			"theme.json"
			"inc/setup.php"
			"inc/enqueue.php"
			"inc/seo-filtered-urls.php"
			"assets/images/embalaje/caja-abierta.webp"
			"assets/images/embalaje/caja-burbujas.webp"
			"assets/images/embalaje/caja-cerrada.webp"
			"assets/images/embalaje/manga_sleeve_real.webp"
			"assets/img/akibara-logo.svg"
			"assets/img/logo-akibara-2x.webp"
			"template-parts/header/shell.php"
			# archive-product.php RETIRADO 2026-08-31 (era copia idéntica del core, tanda 1)
			"woocommerce/cart/cart-empty.php"
		)
		included_top_level=(
			"404.php"
			"archive.php"
			"assets"
			"footer.php"
			"functions.php"
			"header.php"
			"home.php"
			"inc"
			"single.php"
			"style.css"
			"template-parts"
			"theme.json"
			"woocommerce"
		)
		excluded_top_level=("tests")
		;;
	*)
		echo "Componente no soportado: ${component}" >&2
		usage >&2
		exit 2
		;;
esac

# El componente `theme` lo comparten dos consumidores con expectativas distintas:
# el carril vivo (empaqueta el árbol fuente) y los gates de artefacto
# (`qa-theme.sh` sobre el ZIP de la versión viva; los congelados 2.1.49/2.1.50
# en sus propios gates), que pasan un ZIP extraído por --source-dir. Con un
# único literal, subir la versión del carril vivo rompía los gates. Este
# override deja que cada consumidor declare la versión que le corresponde sin
# tocar el literal del otro.
expected_version="${AKIBARA_EXPECTED_VERSION:-${expected_version}}"

source_dir="${source_override:-${default_source}}"

# Rutas superiores que sólo existen en FUENTES CONGELADAS del tema (el ZIP
# histórico 2.1.48 y el superset sellado 2.1.49): `customer-reviews-woocommerce/`
# se retiró del tema vivo y de `included_top_level` el 2026-09-18 (S-288616fe),
# y desde entonces los dos gates históricos morían con «Ruta superior no
# revisada» — nadie los corría (ronda 5, 2026-09-19). Esas carpetas se admiten
# SÓLO con --source-dir (una fuente congelada), para reproducir byte a byte los
# ZIP sellados; el árbol vivo sigue fallando cerrado si alguien la recrea.
#
# Y sólo si la fuente la TRAE: `--source-dir` también es un ZIP vivo extraído
# (`qa-theme.sh` lo verifica así), que ya no la lleva, y admitirla de todas formas
# hacía que el staging copiara una ruta inexistente — rsync salía con 23 y el gate
# del tema moría en su último paso con cualquier versión viva (medido el
# 2026-09-23 con el ZIP publicado 2.1.142).
if [[ -n "${source_override:-}" && -d "${source_dir}/customer-reviews-woocommerce" ]]; then
	case "${component}" in
		theme|theme-production) included_top_level+=("customer-reviews-woocommerce") ;;
	esac
fi

for command_name in python3 rsync shasum unzip zip zipinfo; do
	if ! command -v "${command_name}" >/dev/null 2>&1; then
		echo "Falta el comando requerido: ${command_name}" >&2
		exit 1
	fi
done

if [[ ! -d "${source_dir}" ]]; then
	echo "No existe el origen: ${source_dir}" >&2
	exit 1
fi

source_special="$(find "${source_dir}" ! -type d ! -type f -print)"
if [[ -n "${source_special}" ]]; then
	echo "El origen contiene enlaces simbólicos o entradas especiales; el paquete se rechaza." >&2
	echo "${source_special}" >&2
	exit 1
fi

is_known_top_level() {
	local candidate="$1"
	local known
	for known in "${included_top_level[@]}" "${excluded_top_level[@]}"; do
		if [[ "${candidate}" == "${known}" ]]; then
			return 0
		fi
	done
	return 1
}

while IFS= read -r top_path; do
	top_name="${top_path##*/}"
	if ! is_known_top_level "${top_name}"; then
		echo "Ruta superior no revisada para ${slug}: ${top_name}" >&2
		exit 1
	fi
done < <(find "${source_dir}" -mindepth 1 -maxdepth 1 -print | LC_ALL=C sort)

for required_path in "${required_paths[@]}"; do
	if [[ ! -e "${source_dir}/${required_path}" ]]; then
		echo "Falta una ruta runtime requerida: ${required_path}" >&2
		exit 1
	fi
done

version="$(
	sed -nE 's/^[[:space:]]*(\*[[:space:]]*)?Version:[[:space:]]*([^[:space:]]+).*/\2/p' \
		"${source_dir}/${header_file}" | head -n 1
)"

# Un `expected_version` literal por perfil CADUCA en cada release: el packager
# quedaba rojo desde el bump y abortaba antes de empaquetar nada, de modo que el
# riel moría por una cifra vieja y no por un defecto. Pasó a la vez en tres
# perfiles (business y business-seo clavados en 1.107.9 con el plugin en
# 1.107.10; checkout en 2.2.3 con el plugin en 2.2.4).
#
# Lo que un release SÍ puede romper de verdad es la relación entre la cabecera y
# la constante: publicar con las dos en versiones distintas deja el asset
# versionado con un `?ver=` que no corresponde. Esa es la invariante que se fija
# acá, y no caduca nunca.
#
# El pin explícito sigue existiendo para las fuentes CONGELADAS —`theme-production`
# empaqueta una copia sellada bajo .local-backups, donde la versión no debe
# moverse— y para cualquier consumidor que quiera exigir una versión concreta vía
# AKIBARA_EXPECTED_VERSION. Cuando no hay pin, no se inventa uno.
if [[ -z "${version}" ]]; then
	echo "No se pudo leer la versión de ${slug} en ${header_file}." >&2
	exit 1
fi
if ! [[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Versión con forma inesperada para ${slug}: ${version} (se espera N.N.N)." >&2
	exit 1
fi
if [[ -n "${expected_version}" && "${version}" != "${expected_version}" ]]; then
	echo "Versión inesperada para ${slug}: ${version}; se esperaba ${expected_version}." >&2
	exit 1
fi

if [[ -n "${version_constant_name}" ]]; then
	constant_version="$(
		sed -nE \
			"s/^[[:space:]]*define\\([[:space:]]*'${version_constant_name}'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\\)[[:space:]]*;.*/\\1/p" \
			"${source_dir}/${header_file}" | head -n 1
	)"
	if [[ "${constant_version}" != "${version}" ]]; then
		echo "${version_constant_name} (${constant_version:-ausente}) no coincide con la cabecera (${version})." >&2
		exit 1
	fi
elif ! grep -Eq '^[[:space:]]*Template:[[:space:]]+generatepress[[:space:]]*$' \
	"${source_dir}/style.css"; then
	echo "El theme dejó de declarar GeneratePress como parent." >&2
	exit 1
fi

if [[ "${component}" == "theme-production" ]]; then
	bash "${script_dir}/verify-theme-production-superset.sh" \
		--source-dir "${source_dir}"
fi

temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/akibara-release-${slug}.XXXXXX")"
cleanup() {
	case "${temp_dir}" in
		*/akibara-release-"${slug}".*) rm -rf -- "${temp_dir}" ;;
	esac
}
trap cleanup EXIT

stage_dir="${temp_dir}/${slug}"
file_list="${temp_dir}/files.list"
archive_name="${slug}-${version}.zip"
inventory_name="${slug}-${version}.files.sha256"
checksum_name="${archive_name}.sha256"
archive_tmp="${temp_dir}/${archive_name}"
inventory_tmp="${temp_dir}/${inventory_name}"
checksum_tmp="${temp_dir}/${checksum_name}"

mkdir -p "${stage_dir}"
for relative_path in "${included_top_level[@]}"; do
	source_path="${source_dir}/${relative_path}"
	if [[ -d "${source_path}" ]]; then
		mkdir -p "${stage_dir}/${relative_path}"
		rsync -a -- "${source_path}/" "${stage_dir}/${relative_path}/"
	else
		rsync -a -- "${source_path}" "${stage_dir}/"
	fi
done

find "${stage_dir}" -type d -exec chmod 0755 {} +
find "${stage_dir}" -type f -exec chmod 0644 {} +
TZ=UTC find "${stage_dir}" -exec touch -t 198001010000 {} +

(
	cd "${temp_dir}"
	find "${slug}" -type f -print | LC_ALL=C sort > "${file_list}"
	while IFS= read -r relative_file; do
		file_hash="$(shasum -a 256 "${relative_file}" | awk '{print $1}')"
		printf '%s  %s\n' "${file_hash}" "${relative_file}"
	done < "${file_list}" > "${inventory_tmp}"
	COPYFILE_DISABLE=1 TZ=UTC zip -0 -X -q "${archive_tmp}" -@ < "${file_list}"
)

if [[ ! -s "${archive_tmp}" || ! -s "${inventory_tmp}" ]]; then
	echo "El empaquetado produjo un ZIP o inventario vacío." >&2
	exit 1
fi

zipinfo -1 "${archive_tmp}" > "${temp_dir}/zip-files.list"
cmp -s "${file_list}" "${temp_dir}/zip-files.list" || {
	echo "El orden o inventario del ZIP no coincide con el staging." >&2
	exit 1
}

extracted_dir="${temp_dir}/verify"
mkdir -p "${extracted_dir}"
unzip -q "${archive_tmp}" -d "${extracted_dir}"
(
	cd "${extracted_dir}"
	shasum -a 256 -c "${inventory_tmp}" >/dev/null
)

archive_hash="$(shasum -a 256 "${archive_tmp}" | awk '{print $1}')"
printf '%s  %s\n' "${archive_hash}" "${archive_name}" > "${checksum_tmp}"

# The same release boundary is enforced here and again before deployment.
python3 "${script_dir}/verify-runtime-package.py" "${archive_tmp}" "${inventory_tmp}" "${checksum_tmp}" "${slug}" "${version}"

if ((verify_only)); then
	echo "Paquete verificado: ${slug} ${version}"
	echo "Archivos runtime: $(wc -l < "${file_list}" | tr -d ' ')"
	echo "SHA-256 ZIP: ${archive_hash}"
	exit 0
fi

mkdir -p "${output_dir}"
for artifact_name in "${archive_name}" "${inventory_name}" "${checksum_name}"; do
	case "${artifact_name}" in
		"${archive_name}") source_artifact="${archive_tmp}" ;;
		"${inventory_name}") source_artifact="${inventory_tmp}" ;;
		"${checksum_name}") source_artifact="${checksum_tmp}" ;;
	esac
	target_artifact="${output_dir}/${artifact_name}"
	if [[ -e "${target_artifact}" ]] && ! cmp -s "${source_artifact}" "${target_artifact}"; then
		echo "El artefacto existente difiere; no se sobrescribe: ${target_artifact}" >&2
		exit 1
	fi
done

for artifact_name in "${archive_name}" "${inventory_name}" "${checksum_name}"; do
	case "${artifact_name}" in
		"${archive_name}") source_artifact="${archive_tmp}" ;;
		"${inventory_name}") source_artifact="${inventory_tmp}" ;;
		"${checksum_name}") source_artifact="${checksum_tmp}" ;;
	esac
	target_artifact="${output_dir}/${artifact_name}"
	if [[ ! -e "${target_artifact}" ]]; then
		cp "${source_artifact}" "${target_artifact}"
	fi
done

echo "Paquete: ${output_dir}/${archive_name}"
echo "Inventario: ${output_dir}/${inventory_name}"
echo "Checksum: ${output_dir}/${checksum_name}"
echo "Archivos runtime: $(wc -l < "${file_list}" | tr -d ' ')"
echo "SHA-256 ZIP: ${archive_hash}"
