#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
pipeline="${script_dir}/akibara-business-1.99.15-production-promotion.sh"
legacy_library="${script_dir}/akibara-business-blocks-production-promotion.sh"
contract="${script_dir}/contracts/akibara-business-1.99.15.json"
packager="${repo_root}/tools/akibara-releases/package-business-1.99.15-production.sh"
docker_gate="${repo_root}/tools/akibara-releases/docker-business-1.99.15-production.sh"

die() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

for required in bash cmp grep jq shasum; do
	command -v "${required}" >/dev/null 2>&1 || die "falta ${required}"
done
for file in \
	"${pipeline}" "${legacy_library}" "${contract}" "${packager}" "${docker_gate}"; do
	[[ -f "${file}" && ! -L "${file}" ]] || die "falta archivo regular: ${file}"
done
bash -n "${pipeline}"
bash -n "${packager}"
bash -n "${docker_gate}"
jq -e '
  .seal_state == "sealed"
  and .release_id == "akibara-business-1.99.15"
  and .components.business.preimage.version == "1.99.10"
  and .components.business.candidate.version == "1.99.15"
  and .components.business.modified_runtime_files == [
    "akibara-business/akibara-business.php",
    "akibara-business/src/Modules/OrderPreventa/ReadyToShipEmail.php",
    "akibara-business/src/Modules/TaxonomyDiscount/DiscountResolver.php",
    "akibara-business/src/Modules/TaxonomyDiscount/Settings.php",
    "akibara-business/src/Plugin.php"
  ]
  and .components.business.added_runtime_files == [
    "akibara-business/src/Modules/TaxonomyDiscount/AuthoritativeReader.php",
    "akibara-business/src/Modules/TaxonomyDiscount/PriceAuthority.php",
    "akibara-business/src/Modules/TaxonomyDiscount/PriceAuthorityUnavailable.php",
    "akibara-business/src/Modules/TaxonomyDiscount/SearchPriceProvider.php"
  ]
  and .components.business.removed_runtime_files == []
  and .production_invariants.manga_archive_copy.query_or_page_2 == "suppressed"
  and .production_invariants.live_cache_purge_order == ["litespeed", "cloudflare"]
  and .production_invariants.cloudflare_zone_id ==
    "b298201b3a38d017bc8413d9fd7414cd"
  and .production_invariants.cloudflare_purge_transport ==
    "official-mcp-handoff"
  and .production_invariants.cloudflare_witness_max_age_seconds == 900
  and .production_invariants.cloudflare_purge_payload == {purge_everything:true}
  and .production_invariants.remote_backups_allowed == false
  and .production_invariants.graphify_during_release_allowed == false
  and .exclusive_targets == ["public_html/wp-content/plugins/akibara-business"]
' "${contract}" >/dev/null || die "contrato Business-only/MCP incompleto"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-1.99.15-pipeline-test.XXXXXX")"
cleanup() {
	case "${scratch:-}" in
		*/akibara-business-1.99.15-pipeline-test.*) rm -rf -- "${scratch}" ;;
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
[[ ! -e "${network_sentinel}" ]] || die "plan ejecutó una llamada de red"
grep -Fq 'DRY RUN — no se ejecutó ssh, curl ni escritura remota.' \
	"${scratch}/plan.txt" || die "plan no declara ejecución sin red"
grep -Fq 'Business 1.99.10' "${scratch}/plan.txt" \
	|| die "plan no fija la preimagen exacta"
grep -Fq 'el sitio permanece cerrado' "${scratch}/plan.txt" \
	|| die "plan no conserva mantenimiento durante el handoff"
grep -Fq 'mcp__cloudflare__execute' "${scratch}/plan.txt" \
	|| die "plan no sella el MCP oficial"
grep -Fq 'cero token shell' "${scratch}/plan.txt" \
	|| die "plan no excluye credenciales Cloudflare del shell"
grep -Fq 'No se instala Search, Blocks, theme, BlueX ni terceros.' \
	"${scratch}/plan.txt" || die "plan no excluye runtime ajeno"

if PATH="${fake_bin}:${PATH}" bash "${pipeline}" prepare --config "${config}" \
	--confirm akibara-business-1.99.15 > "${scratch}/missing-apply.out" 2>&1; then
	die "prepare aceptó ausencia de --apply"
fi
if PATH="${fake_bin}:${PATH}" bash "${pipeline}" prepare --config "${config}" \
	--apply --confirm release-equivocado > "${scratch}/wrong-confirm.out" 2>&1; then
	die "prepare aceptó confirmación incorrecta"
fi
if PATH="${fake_bin}:${PATH}" bash "${pipeline}" finalize --config "${config}" \
	--apply --confirm akibara-business-1.99.15 > "${scratch}/missing-witness.out" 2>&1; then
	die "finalize aceptó ausencia de testigo MCP"
fi
if PATH="${fake_bin}:${PATH}" bash "${pipeline}" promote --config "${config}" \
	--apply --confirm akibara-business-1.99.15 > "${scratch}/legacy-promote.out" 2>&1; then
	die "el comando monolítico promote sigue disponible"
fi
[[ ! -e "${network_sentinel}" ]] || die "rechazo de argumentos alcanzó la red"

grep -Fq 'akb_select_promotion_environment production' "${legacy_library}" \
	|| die "primitiva de entorno no selecciona producción"
if grep -Eq 'QA_(HOST|WEBROOT|BASE_URL|LOG_PATHS)|STAG(E|ING)_' "${pipeline}"; then
	die "pipeline conserva configuración QA/stage"
fi
grep -Fq 'Componente instalable fuera del único allowlist' "${pipeline}" \
	|| die "instalador no está limitado a Business"
grep -Fq 'tar -C "${content}" -czf - plugins/akibara-business' "${pipeline}" \
	|| die "backup no transmite únicamente Business a local"
grep -Fq 'remote_zip="$(mktemp --suffix=.zip' "${pipeline}" \
	|| die "instalador no usa mktemp remoto"
grep -Fq 'trap "cleanup || true" EXIT' "${pipeline}" \
	|| die "ZIP remoto no tiene trap de limpieza"
grep -Fq 'capture_fingerprint commerce' "${pipeline}" \
	|| die "pipeline no protege comercio"
grep -Fq 'capture_fingerprint search' "${pipeline}" \
	|| die "pipeline no protege Search"
grep -Fq 'capture_runtime_invariants' "${pipeline}" \
	|| die "pipeline no protege runtime ajeno byte a byte"
grep -Fq -- '-name "*.sql"' "${pipeline}" \
	|| die "gate remoto no cubre SQL"
grep -Fq 'graphify_during_release_allowed == false' "${pipeline}" \
	|| die "pipeline no exige congelación Graphify"
if grep -Eq 'AKB_CLOUDFLARE_API_TOKEN|AKB_CLOUDFLARE_ZONE_ID|api\.cloudflare\.com|purge_cloudflare_live' \
	"${pipeline}"; then
	die "el carril conserva token shell o API Cloudflare directa"
fi
if grep -Fq 'purge_litespeed_live' "${pipeline}"; then
	die "el carril conserva reapertura/purge live fuera del handoff MCP"
fi
grep -Fq '.transport == "mcp__cloudflare__execute"' "${pipeline}" \
	|| die "el testigo no exige el MCP oficial"
grep -Fq '.zone == {id:$zone_id,name:$zone_name,status:"active"}' "${pipeline}" \
	|| die "el testigo no fija zona exacta"
grep -Fq 'write_handoff_state awaiting-cloudflare-candidate' "${pipeline}" \
	|| die "prepare no deja estado pendiente explícito"
grep -Fq 'write_handoff_state awaiting-cloudflare-rollback' "${pipeline}" \
	|| die "rollback no exige segundo handoff"

litespeed_line="$(grep -n 'litespeed-candidate-ready.txt' "${pipeline}" | head -n 1 | cut -d: -f1)"
pending_line="$(grep -n 'write_handoff_state awaiting-cloudflare-candidate' "${pipeline}" | head -n 1 | cut -d: -f1)"
candidate_witness_line="$(grep -n 'validate_cloudflare_witness "${witness}" candidate' "${pipeline}" | head -n 1 | cut -d: -f1)"
candidate_open_line="$(grep -n 'maintenance-inactive.txt' "${pipeline}" | head -n 1 | cut -d: -f1)"
rollback_witness_line="$(grep -n 'validate_cloudflare_witness "${witness}" rollback' "${pipeline}" | head -n 1 | cut -d: -f1)"
rollback_open_line="$(grep -n 'maintenance-inactive-rollback.txt' "${pipeline}" | head -n 1 | cut -d: -f1)"
[[ -n "${litespeed_line}" && -n "${pending_line}" \
	&& "${litespeed_line}" -lt "${pending_line}" ]] \
	|| die "prepare no sella LiteSpeed antes del handoff MCP"
[[ -n "${candidate_witness_line}" && -n "${candidate_open_line}" \
	&& "${candidate_witness_line}" -lt "${candidate_open_line}" ]] \
	|| die "finalize puede abrir antes de validar el testigo"
[[ -n "${rollback_witness_line}" && -n "${rollback_open_line}" \
	&& "${rollback_witness_line}" -lt "${rollback_open_line}" ]] \
	|| die "finalize-rollback puede abrir antes del segundo testigo"

grep -Fq 'render_manga_viewport 375 812 900 mobile' "${docker_gate}" \
	|| die "gate Docker no sella 375x812"
grep -Fq 'render_manga_viewport 1440 1000 800 desktop' "${docker_gate}" \
	|| die "gate Docker no sella 1440x1000"
grep -Fq "'http://localhost:8080/manga/?orderby=price'" "${docker_gate}" \
	|| die "gate Docker no cubre la variante orderby noindex"
grep -Fq 'manga/page/2/' "${docker_gate}" \
	|| die "gate Docker no cubre page/2"
grep -Fq 'post-runtime.sha256' "${docker_gate}" \
	|| die "gate Docker no regenera inventario tras ejecutar"

bash "${pipeline}" verify-artifacts > "${scratch}/verify-artifacts.out"
grep -Fq 'PASS: artefactos exactos Business 1.99.10 -> 1.99.15' \
	"${scratch}/verify-artifacts.out" || die "verify-artifacts no confirmó el release"

# Carga las funciones sin ejecutar main y prueba estado/testigos solo en scratch.
actual_repo_root="${repo_root}"
AKB_BUSINESS_19910_PROMOTION_LIBRARY_ONLY=1
export AKB_BUSINESS_19910_PROMOTION_LIBRARY_ONLY
# shellcheck source=ops/akibara-business-1.99.15-production-promotion.sh
source "${pipeline}"
unset AKB_BUSINESS_19910_PROMOTION_LIBRARY_ONLY
load_contract

repo_root="${scratch}/state-root"
gate_root="${repo_root}/.local-backups/business-1.99.15-production/gates"
handoff_root="${repo_root}/.local-backups/business-1.99.15-production/handoff"
handoff_state_file="${handoff_root}/${release_digest}.json"
active_backup_dir="${repo_root}/.local-backups/business-1.99.15-production/production/run-${release_digest}"
active_evidence_dir="${active_backup_dir}/evidence"
code_backup="${active_backup_dir}/code/business-before.tar.gz"
mkdir -p -- "$(dirname -- "${code_backup}")" "${active_evidence_dir}"
printf 'fixture-backup\n' > "${code_backup}"
shasum -a 256 "${code_backup}" > "${code_backup}.sha256"
AKB_HOST=deployer@akibara.test
AKB_WEBROOT=/home/u888022333/domains/akibara.cl/public_html
AKB_BASE_URL=https://akibara.cl
cloudflare_zone_name=akibara.cl
cloudflare_zone_id=b298201b3a38d017bc8413d9fd7414cd
cloudflare_witness_max_age_seconds=900
compute_suite_sha256() { printf '%s\n' fixture-suite-sha; }

write_receipt docker local-compose > "${scratch}/docker-receipt.out"
verify_receipt docker local-compose
[[ "$(local_file_mode "$(receipt_path docker)")" == 600 ]] \
	|| die "recibo Docker local no quedó protegido con modo 0600"

write_handoff_state awaiting-cloudflare-candidate > "${scratch}/state-write.out"
load_handoff_state

# Un rollback posterior a un PASS productivo debe revocar el recibo que ya no
# representa el runtime vivo, conservándolo únicamente como evidencia local.
mkdir -p -- "${gate_root}"
production_receipt="$(receipt_path production)"
printf '{"fixture":"production-pass"}\n' > "${production_receipt}"
shasum -a 256 "${production_receipt}" > "${production_receipt}.sha256"
revoke_production_receipt > "${scratch}/receipt-revoke.out"
[[ ! -e "${production_receipt}" && ! -e "${production_receipt}.sha256" ]] \
	|| die "rollback dejó un recibo productivo PASS vigente"
revoked_receipt="$(find "${active_evidence_dir}" -maxdepth 1 \
	-type f -name 'production-receipt-revoked-*.json' -print -quit)"
[[ -n "${revoked_receipt}" && -f "${revoked_receipt}.sha256" ]] \
	|| die "recibo revocado no quedó preservado como evidencia local"
[[ "${active_phase}" == awaiting-cloudflare-candidate ]] \
	|| die "estado MCP válido no recargó su fase"

make_witness() {
	local output="$1" purpose="$2" zone_id="$3" completed="$4"
	jq -n \
		--arg release_id "${release_id}" \
		--arg release_digest "${release_digest}" \
		--arg purpose "${purpose}" \
		--arg zone_id "${zone_id}" \
		--arg completed_at_utc "${completed}" '
      {
        schema_version:1,
        kind:"akibara-cloudflare-purge-witness",
        transport:"mcp__cloudflare__execute",
        release_id:$release_id,
        release_digest:$release_digest,
        purpose:$purpose,
        zone:{id:$zone_id,name:"akibara.cl",status:"active"},
        purge:{request:{purge_everything:true},success:true,result:{id:$zone_id},errors:[]},
        completed_at_utc:$completed_at_utc
      }
    ' > "${output}"
}
witness="${scratch}/candidate-witness.json"
make_witness "${witness}" candidate "${cloudflare_zone_id}" \
	"$(date -u +%Y-%m-%dT%H:%M:%SZ)"
validate_cloudflare_witness "${witness}" candidate
record_cloudflare_witness "${witness}" candidate
divergent_witness="${scratch}/candidate-witness-divergent.json"
jq '.completed_at_utc="2099-01-01T00:00:00Z"' "${witness}" > "${divergent_witness}"
if (record_cloudflare_witness "${divergent_witness}" candidate) >/dev/null 2>&1; then
	die "resume permitió sustituir la evidencia MCP ya registrada"
fi

wrong_zone="${scratch}/wrong-zone.json"
make_witness "${wrong_zone}" candidate 00000000000000000000000000000000 \
	"$(date -u +%Y-%m-%dT%H:%M:%SZ)"
if (validate_cloudflare_witness "${wrong_zone}" candidate) >/dev/null 2>&1; then
	die "testigo aceptó otra zona"
fi
wrong_purpose="${scratch}/wrong-purpose.json"
make_witness "${wrong_purpose}" rollback "${cloudflare_zone_id}" \
	"$(date -u +%Y-%m-%dT%H:%M:%SZ)"
if (validate_cloudflare_witness "${wrong_purpose}" candidate) >/dev/null 2>&1; then
	die "testigo aceptó otro propósito"
fi
extra_key="${scratch}/extra-key.json"
jq '.credential="forbidden"' "${witness}" > "${extra_key}"
if (validate_cloudflare_witness "${extra_key}" candidate) >/dev/null 2>&1; then
	die "testigo aceptó una clave adicional"
fi
if stale_time="$(date -u -v-3600S +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)"; then
	:
else
	stale_time="$(date -u -d '1 hour ago' +%Y-%m-%dT%H:%M:%SZ)"
fi
before_phase_witness="${scratch}/before-phase.json"
make_witness "${before_phase_witness}" candidate "${cloudflare_zone_id}" "${stale_time}"
if (validate_cloudflare_witness "${before_phase_witness}" candidate) >/dev/null 2>&1; then
	die "testigo anterior a ready_at_utc fue aceptado"
fi
if old_ready="$(date -u -v-7200S +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)"; then
	future_time="$(date -u -v+600S +%Y-%m-%dT%H:%M:%SZ)"
else
	old_ready="$(date -u -d '2 hours ago' +%Y-%m-%dT%H:%M:%SZ)"
	future_time="$(date -u -d '10 minutes' +%Y-%m-%dT%H:%M:%SZ)"
fi
saved_ready_at_utc="${active_ready_at_utc}"
active_ready_at_utc="${old_ready}"
stale_witness="${scratch}/stale.json"
make_witness "${stale_witness}" candidate "${cloudflare_zone_id}" "${stale_time}"
if (validate_cloudflare_witness "${stale_witness}" candidate) >/dev/null 2>&1; then
	die "testigo posterior al ready pero vencido fue aceptado"
fi
active_ready_at_utc="${saved_ready_at_utc}"
future_witness="${scratch}/future.json"
make_witness "${future_witness}" candidate "${cloudflare_zone_id}" "${future_time}"
if (validate_cloudflare_witness "${future_witness}" candidate) >/dev/null 2>&1; then
	die "testigo fechado más de cinco minutos en el futuro fue aceptado"
fi

cp -- "${handoff_state_file}" "${scratch}/state-good.json"
printf '\n' >> "${handoff_state_file}"
if (load_handoff_state) >/dev/null 2>&1; then
	die "estado alterado aceptó sidecar anterior"
fi
cp -- "${scratch}/state-good.json" "${handoff_state_file}"
(
	cd -- "${handoff_root}"
	shasum -a 256 "$(basename -- "${handoff_state_file}")" \
		> "$(basename -- "${handoff_state_file}.sha256")"
)
chmod 0600 "${handoff_state_file}" "${handoff_state_file}.sha256"
load_handoff_state

# El rollback real usa un subshell estricto porque cleanup_on_failure preserva
# el status original con `set +e`. Un fallo temprano no puede quedar oculto por
# comandos posteriores exitosos ni producir un handoff falso.
strict_restore_log="${scratch}/strict-restore.log"
ensure_maintenance() { printf '%s\n' ensure >> "${strict_restore_log}"; }
restore_code() {
	printf '%s\n' restore-failed >> "${strict_restore_log}"
	return 77
}
cleanup_empty_upgrade_dirs() {
	printf '%s\n' unsafe-after-failure >> "${strict_restore_log}"
}
set +e
restore_preimage_pending_cloudflare strict-test >/dev/null 2>&1
strict_restore_status=$?
set -e
[[ "${strict_restore_status}" != 0 \
	&& "$(cat "${strict_restore_log}")" == $'ensure\nrestore-failed' ]] \
	|| die "rollback estricto enmascaró un fallo intermedio"

rollback_log="${scratch}/rollback.log"
active_operation=finalize
mutation_started=1
maintenance_active=0
restore_preimage_pending_cloudflare() {
	printf '%s\n' restore-pending >> "${rollback_log}"
	maintenance_active=1
	active_phase=awaiting-cloudflare-rollback
}
deactivate_maintenance() { printf '%s\n' unsafe-deactivate >> "${rollback_log}"; }
cleanup_on_failure 1
[[ "$(cat "${rollback_log}")" == restore-pending \
	&& "${maintenance_active}" == 1 \
	&& "${active_phase}" == awaiting-cloudflare-rollback ]] \
	|| die "fallo mutado no quedó pendiente del segundo purge"
if grep -Fq unsafe-deactivate "${rollback_log}"; then
	die "fallo mutado intentó reabrir sin segundo testigo"
fi

no_mutation_log="${scratch}/no-mutation-rollback.log"
active_operation=prepare
mutation_started=0
maintenance_active=1
active_phase=preparing
restore_preimage_pending_cloudflare() {
	printf '%s\n' restore-pending >> "${no_mutation_log}"
	maintenance_active=1
	active_phase=awaiting-cloudflare-rollback
}
deactivate_maintenance() { printf '%s\n' unsafe-deactivate >> "${no_mutation_log}"; }
cleanup_on_failure 1
[[ "$(cat "${no_mutation_log}")" == restore-pending \
	&& "${maintenance_active}" == 1 \
	&& "${active_phase}" == awaiting-cloudflare-rollback ]] \
	|| die "fallo sin mutación demostrada no quedó pendiente del segundo purge"
if grep -Fq unsafe-deactivate "${no_mutation_log}"; then
	die "fallo sin mutación intentó reabrir sin segundo testigo"
fi

rollback_failed_log="${scratch}/rollback-failed.log"
active_operation=finalize
mutation_started=1
maintenance_active=1
restore_preimage_pending_cloudflare() {
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

signal_log="${scratch}/signal.log"
cleanup_on_failure() { printf '%s\n' "$1" >> "${signal_log}"; }
set +e
(promotion_signal_handler 143)
signal_status=$?
set -e
[[ "${signal_status}" == 143 && "$(cat "${signal_log}")" == 143 ]] \
	|| die "señal no propagó status y cleanup"

repo_root="${actual_repo_root}"
grep -Fq 'PHP (Fatal|Parse|Warning)' "${legacy_library}" \
	|| die "gate heredado de logs no detecta errores PHP"
grep -Fq 'Allowed memory' "${legacy_library}" \
	|| die "gate heredado no detecta memoria agotada"
grep -Fq 'inode}" == "${old_inode}' "${legacy_library}" \
	|| die "gate heredado no detecta rotación"
grep -Fq '"${prefix_sha}" == "${old_prefix_sha}"' "${legacy_library}" \
	|| die "gate heredado no detecta reescritura de prefijo"

printf 'PASS: plan sin red, handoff MCP exacto/fresco, estado sellado, resume y rollback fail-closed\n'
