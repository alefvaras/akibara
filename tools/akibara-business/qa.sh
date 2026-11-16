#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
plugin_dir="${repo_root}/public_html/wp-content/plugins/akibara-business"
theme_dir="${repo_root}/public_html/wp-content/themes/akibara-gp"

for command_name in curl docker jq node npx openssl rg; do
	if ! command -v "${command_name}" >/dev/null 2>&1; then
		echo "Falta el comando requerido: ${command_name}" >&2
		exit 1
	fi
done

if [[ ! -x "${script_dir}/vendor/bin/phpunit" ]]; then
	echo "Faltan dependencias locales. Ejecuta primero:" >&2
	echo "  ./tools/akibara-business/install.sh" >&2
	exit 2
fi

if ! docker compose -f "${repo_root}/compose.yaml" ps --status running wordpress \
	| grep -q 'wordpress'; then
	echo "El contenedor WordPress no está activo. Ejecuta: docker compose up -d" >&2
	exit 1
fi

echo "PHP lint en el contenedor Docker..."
docker compose -f "${repo_root}/compose.yaml" exec -T wordpress sh -lc \
	"find \
		/var/www/html/wp-content/plugins/akibara-business \
		/var/www/html/wp-content/themes/akibara-gp \
		-type f -name '*.php' ! -path '*/vendor/*' -print0 \
		| xargs -0 -n1 php -l >/dev/null"

echo "Sintaxis JavaScript..."
for asset_root in "${plugin_dir}/assets" "${theme_dir}/assets"; do
	while IFS= read -r -d '' js_file; do
		node --check "${js_file}" >/dev/null
	done < <(find "${asset_root}" -type f -name '*.js' ! -path '*/vendor/*' -print0)
done

business_js_tests=()
while IFS= read -r -d '' js_test; do
	business_js_tests+=("${js_test}")
done < <(find "${plugin_dir}/tests" -type f -name '*.test.js' -print0)
if ((${#business_js_tests[@]} == 0)); then
	echo "No se encontraron pruebas JavaScript de Akibara Business." >&2
	exit 1
fi
echo "Pruebas JavaScript de Akibara Business..."
node --test "${business_js_tests[@]}"

# Análisis estático (ENV-03 de la auditoría 2026-07-29). `php -l` solo ve
# errores de sintaxis: no detecta escaping faltante, SQL sin preparar, nonces
# ausentes ni APIs inexistentes en el PHP mínimo declarado. PHPCS+WPCS cubre eso.
#
# ADVISORY a propósito: el árbol tiene ~100k líneas escritas antes de que este
# gate existiera, así que bloquear el día uno detendría todo release por deuda
# preexistente. Se publica el conteo para que la deuda baje por módulo; cuando
# llegue a cero, quitar el `|| true` y el gate pasa a bloqueante.
if [[ -x "${script_dir}/vendor/bin/phpcs" ]]; then
	echo "Análisis estático (PHPCS + WPCS, advisory)..."
	phpcs_summary="$(
		"${script_dir}/vendor/bin/phpcs" \
			--standard="${script_dir}/phpcs.xml.dist" \
			--report=summary --no-colors 2>/dev/null | grep -E 'A TOTAL OF|WERE FOUND IN' || true
	)"
	echo "${phpcs_summary}"
	echo "  (detalle: composer --working-dir=tools/akibara-business sniff)"
else
	echo "PHPCS ausente — omitido. Instálalo con ./tools/akibara-business/install.sh" >&2
fi

echo "Guardrail Inviolable de Higiene AST y Prevención N+1..."
"${repo_root}/tools/guardrail-ci.sh"

source_artifacts="$(
	find "${plugin_dir}" "${theme_dir}" \
		\( -path '*/vendor' -o -path '*/tests' \) -prune -o \
		-type f \
		\( -name '*.bak' -o -name '*.orig' -o -name '*.rej' -o -name '*.tmp' \
		-o -name '*~' -o -name '.DS_Store' -o -name '.phpunit.result.cache' \) \
		-print
)"
if [[ -n "${source_artifacts}" ]]; then
	echo "Se encontraron respaldos o artefactos temporales en el plugin o theme:" >&2
	echo "${source_artifacts}" >&2
	exit 1
fi

public_backup_artifacts="$(
	find "${repo_root}/public_html" -type f \
		\( -name '.htaccess_back_*' -o -name '*.bak' -o -name '*.orig' \
		-o -name '*.rej' -o -name '*.tmp' -o -name '*~' -o -name '*.dump' \
		-o -name '*.backup' \) \
		-print
)"
if [[ -n "${public_backup_artifacts}" ]]; then
	echo "Se encontraron respaldos o artefactos de despliegue en public_html:" >&2
	echo "${public_backup_artifacts}" >&2
	exit 1
fi

echo "PHPUnit en Docker..."
docker run --rm \
	--user "$(id -u):$(id -g)" \
	-v "${repo_root}:/workspace" \
	-w /workspace/tools/akibara-business \
	--entrypoint php \
	wordpress:php8.4-apache \
	/workspace/tools/akibara-business/vendor/bin/phpunit \
	--configuration /workspace/tools/akibara-business/phpunit.xml.dist

# Backend PHP del theme (THM-06). La suite Node del theme cubre contratos de
# CSS/fuente; esta ejecuta las condicionales de ruta y los callbacks de filtro,
# que es donde una regresión llega a producción con todo lo demás en verde.
echo "PHPUnit del theme en Docker..."
docker run --rm \
	--user "$(id -u):$(id -g)" \
	-v "${repo_root}:/workspace" \
	-w /workspace/tools/akibara-gp \
	--entrypoint php \
	wordpress:php8.4-apache \
	/workspace/tools/akibara-business/vendor/bin/phpunit \
	--configuration /workspace/tools/akibara-gp/phpunit.xml.dist

echo "Gate E2E del progreso de envío gratis (Chromium/WebKit, móvil/desktop)..."
"${repo_root}/tools/akibara-releases/qa-free-shipping-progress.sh"

echo "Contrato del paquete..."
"${script_dir}/package.sh" --verify-only

echo "Reproducibilidad del paquete..."
"${script_dir}/test-package.sh"

echo "QA local de Akibara Business completada."
