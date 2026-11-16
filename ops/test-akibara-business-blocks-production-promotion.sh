#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
pipeline="${script_dir}/akibara-business-blocks-production-promotion.sh"
contract="${script_dir}/contracts/akibara-business-1.98.2-blocks-2.1.6.json"
packager="${repo_root}/tools/akibara-releases/package-business-blocks-production.sh"
docker_gate="${repo_root}/tools/akibara-releases/docker-business-blocks-production.sh"
guard_test="${script_dir}/test-akibara-business-blocks-promotion-guards.sh"

die() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

for required in bash grep jq shasum; do
	command -v "${required}" >/dev/null 2>&1 || die "falta ${required}"
done
for file in \
	"${pipeline}" "${contract}" "${packager}" "${docker_gate}" "${guard_test}"; do
	[[ -f "${file}" && ! -L "${file}" ]] || die "falta archivo regular: ${file}"
done
bash -n "${pipeline}"
bash -n "${packager}"
bash -n "${docker_gate}"
jq -e . "${contract}" >/dev/null

bash "${guard_test}"

[[ "$(jq -r '.seal_state' "${contract}")" == sealed ]] \
	|| die "el contrato debe estar sellado para probar la promoción"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-blocks-pipeline-test.XXXXXX")"
cleanup() {
	case "${scratch:-}" in
		*/akibara-business-blocks-pipeline-test.*) rm -rf -- "${scratch}" ;;
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

PATH="${fake_bin}:${PATH}" bash "${pipeline}" plan --config "${config}" \
	> "${scratch}/plan.txt"
[[ ! -e "${network_sentinel}" ]] \
	|| die "plan ejecutó una llamada de red"
grep -Fq 'DRY RUN — no se ejecutó ssh, curl ni escritura remota.' \
	"${scratch}/plan.txt" || die "plan no declara ejecución sin red"
grep -Fq 'Business 1.98.1 y Blocks 2.1.5 exactas' \
	"${scratch}/plan.txt" || die "plan no fija ambas preimágenes"
grep -Fq 'BlueX 3.2.2' "${scratch}/plan.txt" \
	|| die "plan no protege BlueX"
grep -Fq 'No se instala Search, theme, BlueX ni ningún tercero.' \
	"${scratch}/plan.txt" || die "plan no excluye runtime ajeno"

if PATH="${fake_bin}:${PATH}" bash "${pipeline}" promote --config "${config}" \
	--confirm akibara-business-1.98.2_blocks-2.1.6 \
	> "${scratch}/missing-apply.out" 2>&1; then
	die "promote aceptó ausencia de --apply"
fi
if PATH="${fake_bin}:${PATH}" bash "${pipeline}" promote --config "${config}" \
	--apply --confirm release-equivocado \
	> "${scratch}/wrong-confirm.out" 2>&1; then
	die "promote aceptó confirmación incorrecta"
fi
[[ ! -e "${network_sentinel}" ]] \
	|| die "rechazo de argumentos alcanzó la red"

grep -Fq 'akb_select_promotion_environment production' "${pipeline}" \
	|| die "pipeline no selecciona producción"
if grep -Fq 'akb_select_promotion_environment qa' "${pipeline}"; then
	die "pipeline conserva selección QA"
fi
if grep -Eq 'QA_(HOST|WEBROOT|BASE_URL|LOG_PATHS)|STAG(E|ING)_' "${pipeline}"; then
	die "pipeline conserva configuración QA/stage"
fi
grep -Fq 'plugins/akibara-business' "${pipeline}" \
	|| die "backup/rollback no incluye Business"
grep -Fq 'plugins/akibara-cart-checkout-blocks' "${pipeline}" \
	|| die "backup/rollback no incluye Blocks"
grep -Fq '/tmp/akibara-business-blocks.' "${pipeline}" \
	|| die "gate no controla ZIP temporales propios"
grep -Fq 'capture_fingerprint commerce' "${pipeline}" \
	|| die "pipeline no protege comercio"
grep -Fq 'capture_fingerprint search' "${pipeline}" \
	|| die "pipeline no protege Search"
grep -Fq 'capture_runtime_invariants' "${pipeline}" \
	|| die "pipeline no protege terceros byte a byte"
grep -Fq -- '-name "*.sql"' "${pipeline}" \
	|| die "gate remoto no cubre SQL"
grep -Fq 'graphify_during_release_allowed == false' "${pipeline}" \
	|| die "pipeline no exige congelación Graphify"
grep -Fq 'verify_source_tree_scope "${component}"' "${packager}" \
	|| die "empaquetador no ejecuta el allowlist fuente completo"

if grep -Eq -- \
	'akibara-(business|cart-checkout-blocks):/var/www/html/wp-content/plugins/[^[:space:]]+:ro' \
	"${docker_gate}"; then
	die "gate Docker conserva el candidato de runtime en solo lectura"
fi
grep -Fq \
	'akibara-business:/var/www/html/wp-content/plugins/akibara-business"' \
	"${docker_gate}" || die "gate Docker no monta Business candidato RW"
grep -Fq \
	'akibara-cart-checkout-blocks:/var/www/html/wp-content/plugins/akibara-cart-checkout-blocks"' \
	"${docker_gate}" || die "gate Docker no monta Blocks candidato RW"
grep -Fq 'post-runtime.sha256' "${docker_gate}" \
	|| die "gate Docker no regenera inventarios tras ejecutar el candidato"
grep -Fq 'El runtime Docker alteró el inventario exacto' "${docker_gate}" \
	|| die "gate Docker no compara el inventario posterior con el artefacto"

business_install_line="$(
	grep -n $'^[[:space:]]*install_component akibara-business \\\\' "${pipeline}" \
		| head -n 1 | cut -d: -f1
)"
blocks_install_line="$(
	grep -n $'^[[:space:]]*install_component akibara-cart-checkout-blocks \\\\' "${pipeline}" \
		| head -n 1 | cut -d: -f1
)"
[[ -n "${business_install_line}" && -n "${blocks_install_line}"
	&& "${business_install_line}" -lt "${blocks_install_line}" ]] \
	|| die "orden de instalación no es Business -> Blocks"

maintenance_flag_line="$(
	grep -n '^[[:space:]]*maintenance_active=1$' "${pipeline}" \
		| tail -n 1 | cut -d: -f1
)"
maintenance_call_line="$(
	grep -n 'activate_maintenance | tee' "${pipeline}" \
		| head -n 1 | cut -d: -f1
)"
[[ -n "${maintenance_flag_line}" && -n "${maintenance_call_line}"
	&& "${maintenance_flag_line}" -lt "${maintenance_call_line}" ]] \
	|| die "handler no asume mantenimiento antes del SSH"

[[ "$(shasum -a 256 "${repo_root}/dist/akibara-business-1.98.1.zip" | awk '{print $1}')" \
	== "$(jq -r '.components.business.preimage.archive_sha256' "${contract}")" ]] \
	|| die "preimagen Business 1.98.1 cambió"
[[ "$(shasum -a 256 "${repo_root}/dist/akibara-cart-checkout-blocks-2.1.5.zip" | awk '{print $1}')" \
	== "$(jq -r '.components.blocks.preimage.archive_sha256' "${contract}")" ]] \
	|| die "preimagen Blocks 2.1.5 cambió"
[[ "$(jq -r '.components.business.preserved_preimage_files[0].sha256' "${contract}")" \
	== ccc00fb744a8dcd681ba436dda67362003e74386bf996e905c8d49a5b0561e49 ]] \
	|| die "contrato no preserva FrontendCompatibility productivo"

bash "${pipeline}" verify-artifacts > "${scratch}/verify-artifacts.out"
grep -Fq 'PASS: artefactos exactos Business 1.98.2 + Blocks 2.1.6' \
	"${scratch}/verify-artifacts.out" \
	|| die "verify-artifacts no confirmó el release"

rollback_log="${scratch}/rollback.log"
mkdir -p -- "${scratch}/evidence"
AKB_BUSINESS_BLOCKS_PROMOTION_LIBRARY_ONLY=1
export AKB_BUSINESS_BLOCKS_PROMOTION_LIBRARY_ONLY
# shellcheck source=ops/akibara-business-blocks-production-promotion.sh
source "${pipeline}"
unset AKB_BUSINESS_BLOCKS_PROMOTION_LIBRARY_ONLY
active_evidence_dir="${scratch}/evidence"
code_backup="${scratch}/business-blocks-before.tar.gz"
business_preimage_version=1.98.1
blocks_preimage_version=2.1.5
business_preimage_inventory="${repo_root}/dist/akibara-business-1.98.1.files.sha256"
blocks_preimage_inventory="${repo_root}/dist/akibara-cart-checkout-blocks-2.1.5.files.sha256"
mutation_started=1
maintenance_active=1
ensure_maintenance() { printf '%s\n' ensure >> "${rollback_log}"; }
restore_code() { printf '%s\n' restore >> "${rollback_log}"; }
cleanup_empty_upgrade_dirs() { printf '%s\n' cleanup-upgrade >> "${rollback_log}"; }
purge_caches_under_maintenance() { printf '%s\n' purge-maintenance >> "${rollback_log}"; }
verify_remote_pair_state() { printf '%s\n' verify-preimage >> "${rollback_log}"; }
remote_artifact_gate() { printf 'artifact-%s\n' "$1" >> "${rollback_log}"; }
deactivate_maintenance() { printf '%s\n' deactivate >> "${rollback_log}"; }
purge_caches_live() { printf '%s\n' purge-live >> "${rollback_log}"; }
cleanup_on_failure 1
expected_rollback="$(
	cat <<'EOF'
ensure
restore
cleanup-upgrade
purge-maintenance
verify-preimage
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
restore_code() {
	printf '%s\n' restore-failed >> "${rollback_failed_log}"
	return 1
}
deactivate_maintenance() {
	printf '%s\n' unsafe-deactivate >> "${rollback_failed_log}"
	return 1
}
cleanup_on_failure 1
[[ "${maintenance_active}" == 1 ]] \
	|| die "rollback fallido no conservó mantenimiento"
if grep -Fq unsafe-deactivate "${rollback_failed_log}"; then
	die "rollback fallido intentó reabrir producción"
fi

grep -Fq 'PHP (Fatal|Parse|Warning)' "${pipeline}" \
	|| die "gate de logs no detecta errores PHP"
grep -Fq 'Allowed memory' "${pipeline}" \
	|| die "gate de logs no detecta memoria agotada"
grep -Fq 'Maximum execution' "${pipeline}" \
	|| die "gate de logs no detecta timeout"
grep -Fq 'inode}" == "${old_inode}' "${pipeline}" \
	|| die "gate de logs no detecta rotación"
grep -Fq '"${size}" -ge "${old_size}"' "${pipeline}" \
	|| die "gate de logs no detecta truncado"
grep -Fq '"${prefix_sha}" == "${old_prefix_sha}"' "${pipeline}" \
	|| die "gate de logs no detecta reescritura del prefijo"

printf 'PASS: plan sin red, argumentos fail-closed, alcance aislado, preimágenes, orden y rollback\n'
