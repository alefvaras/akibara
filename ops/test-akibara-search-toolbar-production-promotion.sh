#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
pipeline="${script_dir}/akibara-search-toolbar-production-promotion.sh"
contract="${script_dir}/contracts/akibara-search-toolbar-0.1.10-theme-2.1.58.json"

die() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

for required in bash grep jq shasum; do
	command -v "${required}" >/dev/null 2>&1 || die "falta ${required}"
done
[[ -f "${pipeline}" && ! -L "${pipeline}" ]] || die "falta pipeline regular"
[[ -f "${contract}" && ! -L "${contract}" ]] || die "falta contrato regular"
bash -n "${pipeline}"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-search-toolbar-pipeline-test.XXXXXX")"
cleanup() {
	case "${scratch:-}" in
		*/akibara-search-toolbar-pipeline-test.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

config="${scratch}/promotion.conf"
fake_bin="${scratch}/fake-bin"
network_sentinel="${scratch}/network-called"
mkdir -p -- "${fake_bin}"
cat > "${config}" <<'EOF'
PRODUCTION_HOST=deployer@akibara.test
PRODUCTION_WEBROOT=/home/u888022333/domains/akibara.cl/public_html
PRODUCTION_BASE_URL=https://akibara.cl
PRODUCTION_LOG_PATHS=/home/u888022333/domains/akibara.cl/public_html/error_log
EOF
chmod 0600 "${config}"

for network_command in ssh curl; do
	cat > "${fake_bin}/${network_command}" <<EOF
#!/usr/bin/env bash
printf '%s\n' '${network_command}' >> '${network_sentinel}'
exit 97
EOF
	chmod 0700 "${fake_bin}/${network_command}"
done

plan_output="${scratch}/plan.txt"
PATH="${fake_bin}:${PATH}" bash "${pipeline}" plan --config "${config}" \
	> "${plan_output}"
[[ ! -e "${network_sentinel}" ]] \
	|| die "plan ejecutó una llamada de red"
grep -Fq 'DRY RUN — no se ejecutó ssh, curl ni escritura remota.' "${plan_output}" \
	|| die "plan no declara su contrato sin red"
grep -Fq 'Search 0.1.9 y theme 2.1.58' "${plan_output}" \
	|| die "plan no fija ambas preimágenes"
grep -Fq 'Search 0.1.10 y theme 2.1.58' "${plan_output}" \
	|| die "plan no fija ambos candidatos"
grep -Fq 'No existe etapa, conexión, inspección ni mutación QA' "${plan_output}" \
	|| die "plan no excluye QA explícitamente"

if PATH="${fake_bin}:${PATH}" bash "${pipeline}" promote --config "${config}" \
	--confirm akibara-search-0.1.10_theme-production-2.1.58 \
	> "${scratch}/missing-apply.out" 2>&1; then
	die "promote aceptó ausencia de --apply"
fi
if PATH="${fake_bin}:${PATH}" bash "${pipeline}" promote --config "${config}" \
	--apply --confirm release-equivocado \
	> "${scratch}/wrong-confirm.out" 2>&1; then
	die "promote aceptó confirmación incorrecta"
fi
[[ ! -e "${network_sentinel}" ]] \
	|| die "un rechazo de argumentos alcanzó la red"

grep -Fq 'akb_select_promotion_environment production' "${pipeline}" \
	|| die "pipeline no selecciona producción de forma explícita"
if grep -Fq 'akb_select_promotion_environment qa' "${pipeline}"; then
	die "pipeline contiene una selección del entorno QA"
fi
if grep -Eq 'QA_(HOST|WEBROOT|BASE_URL|LOG_PATHS)|AKB_(SCAN_)?EXCLUDED_WEBROOT' \
	"${pipeline}"; then
	die "pipeline contiene estado o exclusiones vinculadas al entorno QA"
fi
grep -Fq 'plugins/akibara-search' "${pipeline}" \
	|| die "backup/rollback no incluye Search"
grep -Fq 'themes/akibara-gp' "${pipeline}" \
	|| die "backup/rollback no incluye theme"
grep -Fq '/tmp/akibara-search-toolbar.' "${pipeline}" \
	|| die "gate no controla temporales remotos propios"
grep -Fq 'capture_fingerprint commerce' "${pipeline}" \
	|| die "pipeline no captura comercio"
grep -Fq 'capture_fingerprint search' "${pipeline}" \
	|| die "pipeline no captura Search"
grep -Fq 'remote_artifact_gate absent' "${pipeline}" \
	|| die "pipeline no exige cero artefactos final"
grep -Fq -- '-name "*.sql"' "${pipeline}" \
	|| die "gate de artefactos no cubre volcados SQL"

search_install_line="$(
	grep -n 'plugin akibara-search "${search_candidate_version}"' "${pipeline}" \
		| head -n 1 | cut -d: -f1
)"
theme_install_line="$(
	grep -n 'theme akibara-gp "${theme_candidate_version}"' "${pipeline}" \
		| head -n 1 | cut -d: -f1
)"
[[ -n "${search_install_line}" && -n "${theme_install_line}"
	&& "${search_install_line}" -lt "${theme_install_line}" ]] \
	|| die "orden de instalación no es Search → theme"
maintenance_flag_line="$(
	grep -n 'maintenance_active=1' "${pipeline}" | tail -n 1 | cut -d: -f1
)"
maintenance_call_line="$(
	grep -n 'activate_maintenance | tee' "${pipeline}" | head -n 1 | cut -d: -f1
)"
[[ -n "${maintenance_flag_line}" && -n "${maintenance_call_line}"
	&& "${maintenance_flag_line}" -lt "${maintenance_call_line}" ]] \
	|| die "el handler no asume mantenimiento antes del SSH de activación"

[[ "$(shasum -a 256 "${repo_root}/dist/akibara-search-0.1.9.zip" | awk '{print $1}')" \
	== "$(jq -r '.components.search.preimage.archive_sha256' "${contract}")" ]] \
	|| die "se alteró el ZIP de preimagen Search 0.1.9"
[[ "$(shasum -a 256 "${repo_root}/dist/akibara-gp-2.1.58.zip" | awk '{print $1}')" \
	== "$(jq -r '.components.theme.preimage.archive_sha256' "${contract}")" ]] \
	|| die "se alteró el ZIP de preimagen theme 2.1.58"

bash "${pipeline}" verify-artifacts > "${scratch}/verify-artifacts.out"
grep -Fq 'PASS: preimágenes y candidatos exactos' \
	"${scratch}/verify-artifacts.out" \
	|| die "verify-artifacts no confirmó el release"

rollback_log="${scratch}/rollback.log"
mkdir -p -- "${scratch}/evidence"
AKB_SEARCH_TOOLBAR_PROMOTION_LIBRARY_ONLY=1
# shellcheck source=ops/akibara-search-toolbar-production-promotion.sh
source "${pipeline}"
active_evidence_dir="${scratch}/evidence"
code_backup="${scratch}/search-theme-before.tar.gz"
mutation_started=1
maintenance_active=1
ensure_maintenance() { printf '%s\n' ensure >> "${rollback_log}"; }
restore_code() { printf '%s\n' restore >> "${rollback_log}"; }
purge_caches_under_maintenance() { printf '%s\n' purge-maintenance >> "${rollback_log}"; }
verify_remote_state() { printf '%s\n' verify-preimage >> "${rollback_log}"; }
cleanup_empty_upgrade_dirs() { printf '%s\n' cleanup-upgrade >> "${rollback_log}"; }
remote_artifact_gate() { printf 'artifact-%s\n' "$1" >> "${rollback_log}"; }
deactivate_maintenance() { printf '%s\n' deactivate >> "${rollback_log}"; }
purge_caches_live() { printf '%s\n' purge-live >> "${rollback_log}"; }
cleanup_on_failure 1
expected_rollback="$(
	cat <<'EOF'
ensure
restore
purge-maintenance
verify-preimage
cleanup-upgrade
artifact-active
deactivate
purge-live
artifact-absent
EOF
)"
[[ "$(cat "${rollback_log}")" == "${expected_rollback}" ]] \
	|| die "secuencia de rollback seguro inesperada"

rollback_failed_log="${scratch}/rollback-failed.log"
mutation_started=1
maintenance_active=1
ensure_maintenance() { printf '%s\n' ensure >> "${rollback_failed_log}"; }
restore_code() { printf '%s\n' restore-failed >> "${rollback_failed_log}"; return 1; }
deactivate_maintenance() {
	printf '%s\n' unsafe-deactivate >> "${rollback_failed_log}"
	return 1
}
cleanup_on_failure 1
[[ "${maintenance_active}" == 1 ]] \
	|| die "rollback fallido no conservó mantenimiento fail-closed"
if grep -Fq unsafe-deactivate "${rollback_failed_log}"; then
	die "rollback fallido intentó reabrir producción"
fi

cat > "${fake_bin}/ssh" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
remote_command="${*: -1}"
bash -c "${remote_command}"
EOF
cat > "${fake_bin}/stat" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
if [[ "${1:-}" == -c && "${2:-}" == "%d:%i" && "${3:-}" == -- ]]; then
	exec /usr/bin/stat -f '%d:%i' "${4}"
fi
exec /usr/bin/stat "$@"
EOF
chmod 0700 "${fake_bin}/ssh" "${fake_bin}/stat"

AKB_HOST=fake@host
log_path_secret='adbc-private-token-must-not-leak'
private_log_root="${scratch}/${log_path_secret}"
mkdir -p -- "${private_log_root}"
log_one="${private_log_root}/runtime-one.log"
log_two="${private_log_root}/runtime-two.log"
printf 'baseline-one\n' > "${log_one}"
AKB_LOG_PATHS="${log_one};${log_two}"
safe_baseline="${scratch}/safe-logs-before.tsv"
PATH="${fake_bin}:${PATH}" capture_log_baseline "${safe_baseline}"
grep -Fq $'present\t' "${safe_baseline}" \
	|| die "snapshot no registró el log presente"
grep -Fq $'absent\t-\t0\t-' "${safe_baseline}" \
	|| die "snapshot no registró el log ausente"

safe_secret='safe-secret-marker-must-not-persist'
printf '%s\n' "${safe_secret}" >> "${log_one}"
printf 'created-without-incidents\n' > "${log_two}"
safe_delta_dir="${scratch}/safe-log-deltas"
PATH="${fake_bin}:${PATH}" capture_log_delta \
	"${safe_baseline}" "${safe_delta_dir}"
[[ "$(wc -l < "${safe_delta_dir}/manifest.tsv" | tr -d '[:space:]')" == 2 ]] \
	|| die "delta no cubrió múltiples rutas"
grep -Fq $'append\t' "${safe_delta_dir}/manifest.tsv" \
	|| die "delta no registró append"
grep -Fq $'created\t' "${safe_delta_dir}/manifest.tsv" \
	|| die "delta no registró absent→created"
if grep -R -Fq "${safe_secret}" "${safe_delta_dir}"; then
	die "el gate persistió contenido crudo del log"
fi
test -z "$(
	find "${safe_delta_dir}" -type f \
		\( -name '*.capture' -o -name '*.findings' \) -print -quit
)" || die "el gate dejó archivos de contenido crudo"

danger_index=0
for dangerous_content in \
	'PHP Fatal error: boom' \
	'PHP Parse error: unexpected token' \
	'PHP Warning: unsafe call' \
	'Uncaught TypeError: invalid value' \
	'ERROR: WordPress database error' \
	'Allowed memory size of 128 bytes exhausted' \
	'Maximum execution time of 30 seconds exceeded'; do
	danger_index=$((danger_index + 1))
	danger_log="${private_log_root}/danger-${danger_index}.log"
	danger_baseline="${scratch}/danger-${danger_index}-before.tsv"
	danger_delta="${scratch}/danger-${danger_index}-delta"
	printf 'before\n' > "${danger_log}"
	AKB_LOG_PATHS="${danger_log}"
	PATH="${fake_bin}:${PATH}" capture_log_baseline "${danger_baseline}"
	printf '%s\n' "${dangerous_content}" >> "${danger_log}"
	if (
		PATH="${fake_bin}:${PATH}"
		capture_log_delta "${danger_baseline}" "${danger_delta}"
	); then
		die "el gate aceptó un error productivo: ${dangerous_content}"
	fi
	if grep -R -Fq "${dangerous_content}" "${danger_delta}"; then
		die "el gate filtró contenido sensible del error"
	fi
	grep -Fq $'\tblocked\t' "${danger_delta}/manifest.tsv" \
		|| die "el gate no conservó status bloqueado sin contenido"
done

continuity_case() {
	local case_name="$1" baseline_content="$2" replacement_content="$3"
	local case_log="${private_log_root}/${case_name}.log"
	local case_baseline="${scratch}/${case_name}-before.tsv"
	local case_delta="${scratch}/${case_name}-delta"

	printf '%s' "${baseline_content}" > "${case_log}"
	AKB_LOG_PATHS="${case_log}"
	PATH="${fake_bin}:${PATH}" capture_log_baseline "${case_baseline}"
	case "${case_name}" in
		rotation)
			mv -- "${case_log}" "${case_log}.rotated"
			printf '%s' "${replacement_content}" > "${case_log}"
			;;
		*) printf '%s' "${replacement_content}" > "${case_log}" ;;
	esac
	if (
		PATH="${fake_bin}:${PATH}"
		capture_log_delta "${case_baseline}" "${case_delta}"
	); then
		die "el gate aceptó discontinuidad de log: ${case_name}"
	fi
}
continuity_case rotation 'before-rotation' 'after-rotation'
continuity_case truncation 'baseline-content-is-long' 'short'
continuity_case rewrite 'AAAA-same-size' 'BBBB-same-size'

privacy_log="${private_log_root}/privacy-rewrite.log"
privacy_baseline="${scratch}/privacy-rewrite-before.tsv"
privacy_delta="${scratch}/privacy-rewrite-delta"
privacy_stdout="${scratch}/privacy-rewrite.stdout"
privacy_stderr="${scratch}/privacy-rewrite.stderr"
printf '%s' 'CCCC-same-size' > "${privacy_log}"
AKB_LOG_PATHS="${privacy_log}"
PATH="${fake_bin}:${PATH}" capture_log_baseline "${privacy_baseline}" \
	> "${privacy_stdout}" 2> "${privacy_stderr}"
printf '%s' 'DDDD-same-size' > "${privacy_log}"
if (
	PATH="${fake_bin}:${PATH}"
	capture_log_delta "${privacy_baseline}" "${privacy_delta}"
) >> "${privacy_stdout}" 2>> "${privacy_stderr}"; then
	die "el fixture privado aceptó una reescritura"
fi
if grep -R -Fq "${log_path_secret}" \
	"${privacy_baseline}" "${privacy_delta}" \
	"${privacy_stdout}" "${privacy_stderr}"; then
	die "el gate filtró la ruta privada en evidencia o consola"
fi
grep -Eq $'^present\t[0-9]+:[0-9]+\t[0-9]+\t[a-f0-9]{64}\tlog-01\t[a-f0-9]{64}$' \
	"${privacy_baseline}" \
	|| die "el baseline no usa identidad opaca + hash de ruta"

baseline_line="$(
	grep -n 'capture_log_baseline "${active_evidence_dir}/production-logs-before.tsv"' \
		"${pipeline}" | head -n 1 | cut -d: -f1
)"
mutation_start_line="$(
	grep -n '^[[:space:]]*mutation_started=1$' "${pipeline}" \
		| tail -n 1 | cut -d: -f1
)"
maintenance_start_line="$(
	grep -n '^[[:space:]]*maintenance_active=1$' "${pipeline}" \
		| tail -n 1 | cut -d: -f1
)"
delta_line="$(
	grep -n '^[[:space:]]*capture_log_delta \\$' "${pipeline}" \
		| head -n 1 | cut -d: -f1
)"
mutation_stop_line="$(
	grep -n '^[[:space:]]*mutation_started=0$' "${pipeline}" \
		| tail -n 1 | cut -d: -f1
)"
[[ -n "${baseline_line}" && -n "${maintenance_start_line}"
	&& -n "${mutation_start_line}"
	&& -n "${delta_line}" && -n "${mutation_stop_line}"
	&& "${baseline_line}" -lt "${maintenance_start_line}"
	&& "${maintenance_start_line}" -lt "${mutation_start_line}"
	&& "${mutation_start_line}" -lt "${delta_line}"
	&& "${delta_line}" -lt "${mutation_stop_line}" ]] \
	|| die "el gate de logs no rodea la mutación de forma fail-closed"
grep -Fq 'Log rotado durante el release' "${pipeline}" \
	|| die "el gate de logs no rechaza rotación"
grep -Fq 'Log truncado durante el release' "${pipeline}" \
	|| die "el gate de logs no rechaza truncado"
grep -Fq 'Prefijo de log reescrito durante el release' "${pipeline}" \
	|| die "el gate de logs no detecta reescritura in-place"
grep -Fq 'final_size}" == "${current_size}' "${pipeline}" \
	|| die "el gate no estabiliza el tamaño final del log"
grep -Fq 'final_delta_sha}" == "${delta_sha}' "${pipeline}" \
	|| die "el gate no estabiliza el hash final del delta"
grep -Fq 'Allowed memory size' "${pipeline}" \
	|| die "el gate no detecta agotamiento de memoria"
grep -Fq 'Maximum execution time' "${pipeline}" \
	|| die "el gate no detecta timeout PHP"
if grep -Eq '\.(capture|findings)([^A-Za-z]|$)' "${pipeline}"; then
	die "el pipeline aún persiste contenido crudo de logs"
fi
grep -Fq 'AKB_LOG_PATHS=%q bash -s' "${pipeline}" \
	|| die "las rutas allowlisted no llegan al snapshot read-only"
grep -Fq 'AKB_LOG_LABEL=%q AKB_LOG_PATH_SHA=%q' "${pipeline}" \
	|| die "el delta no transporta identidad opaca + hash de ruta"
if grep -Eq '(die|printf) ".*[Ll]og.*\$\{log_path\}' "${pipeline}"; then
	die "el pipeline aún imprime una ruta real de log"
fi
if find "${scratch}" -type f ! -path "${private_log_root}/*" \
	-exec grep -lF "${log_path_secret}" {} + | grep -q .; then
	die "un artefacto del test persistió el token de ruta privada"
fi

printf 'PASS: plan sin red, exclusión QA, logs metadata-only, argumentos fail-closed, preimágenes y rollback\n'
