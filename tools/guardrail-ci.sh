#!/usr/bin/env bash
# Checks of runtime syntax, static rules, delivery gates and query budgets.
# --full also runs the PHP and JavaScript suites used to qualify a release.
set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
full=0
if [[ "${1:-}" == --full && "$#" == 1 ]]; then
	full=1
elif [[ "$#" != 0 ]]; then
	printf 'Uso: bash tools/guardrail-ci.sh [--full]\n' >&2
	exit 2
fi

for required_command in node python3 php composer npm npx opengrep sqlfluff; do
	command -v "$required_command" >/dev/null 2>&1 || {
		printf 'Falta herramienta obligatoria de calidad: %s\n' "$required_command" >&2
		exit 1
	}
done

phpunit="${script_dir}/akibara-business/vendor/bin/phpunit"
phpcs="${script_dir}/akibara-business/vendor/bin/phpcs"
root_phpcs="${repo_root}/vendor/bin/phpcs"
phpstan="${repo_root}/vendor/bin/phpstan"
psalm="${repo_root}/vendor/bin/psalm"
rector="${repo_root}/vendor/bin/rector"

[[ -x "$phpunit" && -x "$phpcs" ]] || {
	printf 'Instalar dependencias con composer install --working-dir=tools/akibara-business\n' >&2
	exit 1
}
[[ -x "$root_phpcs" && -x "$phpstan" && -x "$psalm" && -x "$rector" ]] || {
	printf 'Instalar dependencias raíz con composer install\n' >&2
	exit 1
}

printf '\n[1/7] Pruebas negativas de los instrumentos de calidad y despliegue\n'
node --test "${script_dir}/test-runtime-delivery.mjs" "${script_dir}/test-deploy-runtime.mjs" "${script_dir}/test-guardrail-preflight.mjs" "${script_dir}/test-qa-playwright-full-suite.mjs" "${script_dir}/test-post-hygiene-verdict.mjs" "${script_dir}/test-muplugins-deploy.mjs"
node --test "${script_dir}/test-mail-health.mjs"
node --test "${script_dir}/test-woocommerce-hook-metadata.mjs"
node --test "${script_dir}/test-build-search-assets.mjs"
node --test "${script_dir}/testing/dom-harness.test.js" "${script_dir}/testing/review-lightbox.test.js"
node --test "${script_dir}/testing/jev-gate.test.js"
node --test "${script_dir}/akibara-releases/rewards-qa/test-qa-paridad-espejo.mjs"
python3 "${repo_root}/ops/test-guardrail-inventario-akibara.py"
python3 "${script_dir}/test-private-data-guard.py"
python3 "${script_dir}/test-source-export.py"
python3 "${script_dir}/check-antigravity-policy.py"
python3 "${script_dir}/test-antigravity-policy.py"
python3 "${script_dir}/verify-devsecops-seal.py"
python3 "${script_dir}/test-devsecops-seal.py"
python3 "${script_dir}/test-runtime-artifact-boundary.py"
python3 "${script_dir}/ci-dependencies/test_materialize.py"

printf '\n[2/7] Sintaxis del runtime PHP\n'
runtime_dirs=(
	"${repo_root}/public_html/wp-content/plugins/akibara-business"
	"${repo_root}/public_html/wp-content/plugins/akibara-search"
	"${repo_root}/public_html/wp-content/plugins/akibara-cart-checkout-blocks"
	"${repo_root}/public_html/wp-content/plugins/akibara-rewards"
	"${repo_root}/public_html/wp-content/themes/akibara-gp"
	"${repo_root}/public_html/wp-content/mu-plugins"
)
for runtime_dir in "${runtime_dirs[@]}"; do
	[[ -d "$runtime_dir" ]] || { printf 'Falta %s\n' "$runtime_dir" >&2; exit 1; }
	while IFS= read -r -d '' filename; do
		php -l "$filename" >/dev/null
	done < <(find "$runtime_dir" -type f -name '*.php' -print0)
done

printf '\n[3/7] Análisis AST; bloqueo estricto de errores y asignaciones muertas\n'
php "${script_dir}/guardrail-ast-hygiene.php" --fail-on-error --fail-on-warning

printf '\n[4/7] Presupuesto de consultas del carrito y candado de dominios erradicados\n'
"$phpunit" -c "${script_dir}/akibara-business/phpunit.xml.dist" \
	"${repo_root}/public_html/wp-content/plugins/akibara-business/tests/Modules/NativeCart/NPlusOneQueryBudgetTest.php"
"$phpunit" -c "${script_dir}/akibara-business/phpunit.xml.dist" \
	"${repo_root}/public_html/wp-content/plugins/akibara-business/tests/ForbiddenLegacyDomainsContractTest.php"

printf '\n[5/7] PHPCS integral sobre los 6 directorios runtime y plantillas administrativas\n'
"$phpcs" --standard="${script_dir}/akibara-business/phpcs.xml.dist" \
	"${repo_root}/public_html/wp-content/plugins/akibara-search/templates/admin" \
	"${repo_root}/public_html/wp-content/plugins/akibara-business/templates/admin"
"$root_phpcs" --standard="${repo_root}/phpcs.xml.dist"

if [[ "$full" == 1 ]]; then
	for component in akibara-business akibara-search akibara-rewards akibara-gp; do
		"$phpunit" -c "${script_dir}/${component}/phpunit.xml.dist"
	done
	js_tests=()
	for runtime_dir in "${runtime_dirs[@]}"; do
		[[ -d "${runtime_dir}/tests" ]] || continue
		while IFS= read -r -d '' filename; do js_tests+=("$filename"); done \
			< <(find "${runtime_dir}/tests" -type f \( -name '*.test.js' -o -name '*.test.mjs' \) -print0)
	done
	[[ "${#js_tests[@]}" -gt 0 ]] || { printf 'No se descubrieron pruebas JavaScript.\n' >&2; exit 1; }
	node --test "${js_tests[@]}"
fi

printf '\n[6/7] Auditoría DevSecOps Estricta (SCA, PHPStan L6, Psalm, Rector, ESLint, Stylelint, Knip y Opengrep en los 6 directorios)\n'
(
	cd "${repo_root}"
	composer audit
	npm audit --audit-level=moderate
	php -d disable_functions=proc_open "$phpstan" analyse --memory-limit=1G --no-progress
	"$psalm" --threads=1 --no-progress --taint-analysis
	"$rector" process --dry-run
	npx eslint . --max-warnings=0
	npx stylelint "public_html/wp-content/plugins/akibara-*/**/*.css" "public_html/wp-content/themes/akibara-gp/**/*.css" --max-warnings=0
	npx knip --reporter compact
	opengrep scan --error --taint-intrafile -f "${repo_root}/.semgrep/" "${runtime_dirs[@]}"
	sqlfluff lint "${repo_root}/ops/" --dialect mysql
)

printf '\n[7/7] Sincronización continua del Segundo Cerebro (NotebookLM, Obsidian & Drive)\n'
node "${script_dir}/sync-notebooklm.mjs"

printf '\nPASS: comprobaciones ejecutadas; los avisos AST y el QA público se revisan aparte.\n'
