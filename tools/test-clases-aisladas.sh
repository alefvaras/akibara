#!/usr/bin/env bash
#
# Corre CADA clase de prueba sola, una por una, y dice cuáles caen.
#
# Por qué existe
# --------------
# PHPUnit carga TODOS los ficheros de prueba antes de correr ninguno, así que los
# `require`, `define()` y dobles que una clase declara al cargarse le sirven a las
# demás: la suite completa pasa en cualquier orden y, aun así, una clase corrida
# sola cae con «Class not found», «Undefined constant» o —peor— mide otra cosa
# (el motor del buscador devolvía una lista vacía por su rama «indisponible» y la
# prueba parecía fallar por el motor). El 2026-09-19 esto escondía 19 de 67 clases
# en akibara-search, 2 en el tema y 1 en akibara-rewards. `--order-by=random` NO lo
# detecta: el orden de EJECUCIÓN cambia, el de CARGA no.
#
# Uso:   bash tools/test-clases-aisladas.sh <business|search|rewards|gp|search-business>
# Sale != 0 si alguna clase cae sola.
set -uo pipefail

componente="${1:-}"
raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

case "$componente" in
	business)        config="tools/akibara-business/phpunit.xml.dist";        dir="public_html/wp-content/plugins/akibara-business/tests" ;;
	search)          config="tools/akibara-search/phpunit.xml.dist";          dir="public_html/wp-content/plugins/akibara-search/tests" ;;
	rewards)         config="tools/akibara-rewards/phpunit.xml.dist";         dir="public_html/wp-content/plugins/akibara-rewards/tests" ;;
	gp)              config="tools/akibara-gp/phpunit.xml.dist";              dir="public_html/wp-content/themes/akibara-gp/tests" ;;
	search-business) config="tools/akibara-search-business/phpunit.xml.dist"; dir="tools/akibara-search-business/tests" ;;
	*) echo "uso: $0 <business|search|rewards|gp|search-business>" >&2; exit 2 ;;
esac

cd "$raiz" || exit 2
[ -d "$dir" ] || { echo "no existe $dir" >&2; exit 2; }

total=0
caidas=0
while IFS= read -r fichero; do
	total=$((total + 1))
	salida="$(./tools/akibara-business/vendor/bin/phpunit -c "$config" "$fichero" 2>&1)"
	resumen="$(printf '%s\n' "$salida" | grep -E '^(OK|Tests:|No tests executed)' | head -1)"
	case "$resumen" in
		OK*) ;;
		*)
			caidas=$((caidas + 1))
			causa="$(printf '%s\n' "$salida" | grep -E 'not found|Undefined|is not defined|^Failed asserting' | head -1 | cut -c1-160)"
			printf 'CAE SOLA: %s → %s %s\n' "${fichero#"$dir"/}" "${resumen:-sin resumen}" "${causa:+· $causa}"
			;;
	esac
done < <(find "$dir" -name '*Test.php' | sort)

printf '%s: %d clases, %d caen solas\n' "$componente" "$total" "$caidas"
[ "$caidas" -eq 0 ]
