#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
pipeline="${script_dir}/akibara-code-promotion.sh"
config_library="${script_dir}/lib/akibara-promotion-config.sh"
release_contract_file="${script_dir}/lib/akibara-release-contract.sh"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-code-promotion-test.XXXXXX")"
production_evidence_fixture=""

cleanup() {
	case "${production_evidence_fixture:-}" in
		"${repo_root}/.local-backups/code-promotion/production/test-"*)
			rm -rf -- "${production_evidence_fixture}"
			;;
	esac
	case "${test_root}" in
		*/akibara-code-promotion-test.*) rm -rf -- "${test_root}" ;;
	esac
}
trap cleanup EXIT

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

expect_failure() {
	if ("$@" >/dev/null 2>&1); then
		fail "se esperaba rechazo: $*"
	fi
}

for command_name in awk bash chmod cp curl date find grep jq node sed shasum tar unzip zipinfo; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| fail "falta comando de prueba: ${command_name}"
done

bash -n "${pipeline}"
bash -n "${config_library}"
bash -n "${release_contract_file}"

for required_function in \
	verify_artifacts write_receipt verify_receipt run_local_gate \
	pre_backup_artifact_gate zero_remote_artifact_gate remote_preflight \
	verify_production_theme_preimage verify_production_redirect_prerequisites \
	activate_maintenance_mode ensure_maintenance_mode deactivate_maintenance_mode \
	stream_code_backup stream_database_backup stream_logs_backup \
	capture_fingerprint capture_search_fingerprint install_component \
	restore_code_from_local_stream cleanup_on_failure promote_environment; do
	grep -Eq "^${required_function}\\(\\)" "${pipeline}" \
		|| fail "falta protección obligatoria: ${required_function}"
done

for forbidden in \
	'QA_HOST' 'QA_WEBROOT' 'QA_BASE_URL' 'QA_LOG_PATHS' \
	'qa_release_digest' 'qa_theme_version' 'qa_theme_archive' \
	'seal-qa' 'qa-full' 'qa-code' 'refresh-qa-receipt' \
	'AKB_SCAN_EXCLUDED_WEBROOT' 'qa.akibara.cl' '/public_html/qa'; do
	if grep -Fq -- "${forbidden}" "${pipeline}" \
		|| grep -Fq -- "${forbidden}" "${config_library}" \
		|| grep -Fq -- "${forbidden}" "${release_contract_file}"; then
		fail "quedó lógica QA/stage activa: ${forbidden}"
	fi
done

[[ ! -e "${script_dir}/generate-akibara-qa-full-evidence.sh" ]] \
	|| fail "el generador qa-full obsoleto sigue presente"
[[ ! -e "${script_dir}/akibara-qa-full-evidence-manifest.example.json" ]] \
	|| fail "el manifiesto qa-full obsoleto sigue presente"

grep -Fq 'mktemp --suffix=.zip /tmp/akibara-deploy.XXXXXX' "${pipeline}"
grep -Fq 'trap "cleanup || true" EXIT' "${pipeline}"
grep -Fq 'Stream all four target code trees, plus database and allowlisted logs' \
	"${pipeline}"
grep -Fq 'fail-closed non-expiring maintenance' "${pipeline}"
grep -Fq 'all four target code trees' "${pipeline}"
grep -Fq 'zero_remote_artifact_gate' "${pipeline}"
grep -Fq 'X-Redirect-By: Rank Math' "${pipeline}"
grep -Fq 'The database is never blindly restored.' "${pipeline}"
grep -Fq '.local-backups/code-promotion/production/' "${pipeline}"
if grep -Eq 'remote.*(backup|evidence).*(\\.bak|\\.zip|\\.tar|\\.sql)' "${pipeline}"; then
	fail "se detectó un respaldo persistente descrito como remoto"
fi

AKB_PROMOTION_LIBRARY_ONLY=1
export AKB_PROMOTION_LIBRARY_ONLY
# shellcheck source=ops/akibara-code-promotion.sh
source "${pipeline}"
unset AKB_PROMOTION_LIBRARY_ONLY

[[ "${release_contract_schema}" == "akibara-production-profile-v1" ]]
[[ "${release_set}" == \
	"akibara-business-1.95.2_search-0.1.4_checkout-2.0.1_theme-production-2.1.49" ]]
[[ "${#component_slugs[@]}" == 4 ]]
[[ "${component_slugs[*]}" == $'akibara-business\nakibara-search\nakibara-cart-checkout-blocks\nakibara-gp' ]]
[[ "${component_versions[*]}" == $'1.95.2\n0.1.4\n2.0.1\n2.1.49' ]]
[[ "${active_release_profile}" == production ]]
[[ "$(release_profile_digest production)" == "${production_release_digest}" ]]
[[ "$(release_digest)" == "${expected_release_digest}" ]]
expect_failure release_profile_digest qa
expect_failure select_release_profile qa
verify_release_contract
verify_artifacts >/dev/null

config="${test_root}/promotion.conf"
printf '%s\n' \
	'PRODUCTION_HOST=deployer@akibara.cl' \
	'PRODUCTION_WEBROOT=/home/u888022333/domains/akibara.cl/public_html' \
	'PRODUCTION_BASE_URL=https://akibara.cl' \
	'PRODUCTION_LOG_PATHS=/home/u888022333/domains/akibara.cl/public_html/error_log;/home/u888022333/domains/akibara.cl/public_html/wp-content/debug.log' \
	> "${config}"
chmod 0600 "${config}"

akb_load_promotion_config "${config}"
akb_select_promotion_environment production
[[ "${AKB_HOST}" == deployer@akibara.cl ]]
[[ "${AKB_WEBROOT}" == /home/u888022333/domains/akibara.cl/public_html ]]
[[ "${AKB_BASE_URL}" == https://akibara.cl ]]
expect_failure akb_select_promotion_environment qa

bad_config="${test_root}/qa-key.conf"
cp -- "${config}" "${bad_config}"
printf 'QA_HOST=deployer@qa.invalid\n' >> "${bad_config}"
chmod 0600 "${bad_config}"
expect_failure bash "${pipeline}" plan --config "${bad_config}"

bad_config="${test_root}/qa-webroot.conf"
sed 's#/public_html$#/public_html/qa#' "${config}" > "${bad_config}"
chmod 0600 "${bad_config}"
expect_failure bash "${pipeline}" plan --config "${bad_config}"

bad_config="${test_root}/wrong-origin.conf"
sed 's#https://akibara.cl#https://otro.cl#' "${config}" > "${bad_config}"
chmod 0600 "${bad_config}"
expect_failure bash "${pipeline}" plan --config "${bad_config}"

bad_config="${test_root}/duplicate.conf"
cp -- "${config}" "${bad_config}"
printf 'PRODUCTION_HOST=deployer@akibara.cl\n' >> "${bad_config}"
chmod 0600 "${bad_config}"
expect_failure bash "${pipeline}" plan --config "${bad_config}"

bad_config="${test_root}/world-readable.conf"
cp -- "${config}" "${bad_config}"
chmod 0644 "${bad_config}"
expect_failure bash "${pipeline}" plan --config "${bad_config}"

stub_bin="${test_root}/bin"
mkdir -p "${stub_bin}"
for command_name in ssh curl; do
	printf '%s\n' '#!/usr/bin/env bash' 'exit 97' > "${stub_bin}/${command_name}"
	chmod 0700 "${stub_bin}/${command_name}"
done
plan_output="$(
	PATH="${stub_bin}:${PATH}" bash "${pipeline}" plan --config "${config}"
)"
grep -Fq 'DRY RUN ONLY — no ssh, curl, or remote command was executed.' \
	<<< "${plan_output}"
grep -Fq 'Target:  production' <<< "${plan_output}"
grep -Fq 'Require checksum-protected Docker PASS receipt.' <<< "${plan_output}"
grep -Fq 'Require seven observable 301 redirects' <<< "${plan_output}"
grep -Fq 'Stream all four target code trees, plus database and allowlisted logs' \
	<<< "${plan_output}"
grep -Fq 'Any failure stops production.' <<< "${plan_output}"
if grep -Eqi 'remote qa|stage|qa\.akibara|/public_html/qa' <<< "${plan_output}"; then
	fail "el plan conserva un destino QA/stage"
fi

expect_failure bash "${pipeline}" plan --env qa --config "${config}"
expect_failure bash "${pipeline}" plan --config "${config}" --apply
expect_failure bash "${pipeline}" promote --config "${config}" \
	--confirm "${release_set}"
expect_failure bash "${pipeline}" promote --config "${config}" \
	--apply --confirm incorrecto
expect_failure bash "${pipeline}" verify-artifacts --config "${config}"

gate_root="${test_root}/gates"
write_receipt docker local-compose >/dev/null
verify_receipt docker local-compose
docker_receipt="${gate_root}/docker-passed-${expected_release_digest}.json"
docker_checksum="${docker_receipt}.sha256"
jq -e '
  .schema_version == 7
  and .stage == "docker"
  and .artifact_profile == "production"
  and .target == "local-compose"
  and .promotion_evidence_dir == ""
  and ((keys | length) == 17)
' "${docker_receipt}" >/dev/null

jq '.status = "FAIL"' "${docker_receipt}" > "${docker_receipt}.tampered"
mv -- "${docker_receipt}.tampered" "${docker_receipt}"
shasum -a 256 "${docker_receipt}" > "${docker_checksum}"
expect_failure verify_receipt docker local-compose

write_receipt docker local-compose >/dev/null
jq '.completed_at_utc = "2020-01-01T00:00:00Z"' "${docker_receipt}" \
	> "${docker_receipt}.stale"
mv -- "${docker_receipt}.stale" "${docker_receipt}"
shasum -a 256 "${docker_receipt}" > "${docker_checksum}"
expect_failure verify_receipt docker local-compose
expect_failure write_receipt qa local-compose

production_evidence_fixture="${repo_root}/.local-backups/code-promotion/production/test-$$-${expected_release_digest}"
mkdir -p "${production_evidence_fixture}/evidence"
write_receipt production "deployer@akibara.cl:/home/u888022333/domains/akibara.cl/public_html" \
	"${production_evidence_fixture#"${repo_root}/"}/evidence" >/dev/null
verify_receipt production \
	"deployer@akibara.cl:/home/u888022333/domains/akibara.cl/public_html"

redirect_trace="${test_root}/redirects.txt"
(
	AKB_BASE_URL="https://akibara.cl"
	curl() {
		local dump="" url="${!#}" destination=""
		while (($#)); do
			case "$1" in
				--dump-header)
					dump="$2"
					shift 2
					;;
				*) shift ;;
			esac
		done
		case "${url}" in
			"https://akibara.cl/generos/") destination="/tienda/" ;;
			"https://akibara.cl/store-listing/") destination="/tienda/" ;;
			"https://akibara.cl/jujutsu-kaisen-en-que-orden-leer-el-manga-guia-definitiva-2026/")
				destination="/en-que-tomo-continuar-jujutsu-kaisen/"
				;;
			"https://akibara.cl/guia-de-dandadan-desde-que-tomo-leer-el-manga-despues-del-anime/")
				destination="/dandadan-guia-para-leer-el-manga-despues-del-anime/"
				;;
			"https://akibara.cl/cart/") destination="/carrito/" ;;
			"https://akibara.cl/preguntas-frecuentes/") destination="/faq/" ;;
			"https://akibara.cl/mi-cuenta/downloads/") destination="/mi-cuenta/" ;;
		esac
		if [[ -n "${destination}" ]]; then
			printf 'HTTP/2 301\r\nX-Redirect-By: Rank Math\r\n' > "${dump}"
			printf '301\t%s%s' "${AKB_BASE_URL}" "${destination}"
		else
			printf '200\t%s\t0' "${url}"
		fi
	}
	verify_production_redirect_prerequisites
) > "${redirect_trace}"
[[ "$(grep -c '^PASS' "${redirect_trace}")" == 7 ]]
grep -Fq $'PASS\t/preguntas-frecuentes/\t301\t/faq/\tRank Math\t200' \
	"${redirect_trace}"
grep -Fq 'PRODUCTION_RANK_MATH_HTTP_PREREQUISITES_PASS' "${redirect_trace}"

safe_trace="${test_root}/rollback-safe.trace"
safe_evidence="${test_root}/rollback-safe"
mkdir -p "${safe_evidence}"
printf 'commerce\n' > "${safe_evidence}/protected-before.tsv"
printf 'search\n' > "${safe_evidence}/search-state-before.tsv"
(
	mutation_started=1
	maintenance_mode_active=1
	remote_cleanup_authorized=0
	active_release_profile=production
	active_promotion_evidence_dir="${safe_evidence}"
	code_backup="${test_root}/unused-backup.tar.gz"
	ensure_maintenance_mode() { printf 'ensure\n' >> "${safe_trace}"; }
	purge_runtime_caches() { printf 'purge\n' >> "${safe_trace}"; }
	maintenance_http_gate() { printf 'http-503\n' >> "${safe_trace}"; }
	restore_code_from_local_stream() { printf 'restore\n' >> "${safe_trace}"; }
	verify_production_theme_preimage() { printf 'preimage\n' >> "${safe_trace}"; }
	capture_fingerprint() {
		cp -- "${safe_evidence}/protected-before.tsv" "$1"
	}
	capture_search_fingerprint() {
		cp -- "${safe_evidence}/search-state-before.tsv" "$1"
	}
	deactivate_maintenance_mode() { printf 'deactivate\n' >> "${safe_trace}"; }
	purge_runtime_caches_live() { printf 'purge-live\n' >> "${safe_trace}"; }
	maintenance_absent_gate() { printf 'maintenance-absent\n' >> "${safe_trace}"; }
	cleanup_on_failure 1 >/dev/null
)
grep -Fq 'restore' "${safe_trace}"
grep -Fq 'deactivate' "${safe_trace}"
grep -Fq 'maintenance-absent' "${safe_trace}"

failed_trace="${test_root}/rollback-failed.trace"
(
	mutation_started=1
	maintenance_mode_active=1
	remote_cleanup_authorized=0
	active_release_profile=production
	active_promotion_evidence_dir="${safe_evidence}"
	code_backup="${test_root}/unused-backup.tar.gz"
	ensure_maintenance_mode() { printf 'ensure\n' >> "${failed_trace}"; }
	purge_runtime_caches() { printf 'purge\n' >> "${failed_trace}"; }
	maintenance_http_gate() { printf 'http-503\n' >> "${failed_trace}"; }
	restore_code_from_local_stream() {
		printf 'restore-failed\n' >> "${failed_trace}"
		return 1
	}
	deactivate_maintenance_mode() { printf 'deactivate\n' >> "${failed_trace}"; }
	cleanup_on_failure 1 >/dev/null
)
grep -Fq 'restore-failed' "${failed_trace}"
if grep -Fq 'deactivate' "${failed_trace}"; then
	fail "el rollback fallido reabrió producción"
fi

echo "PASS: promoción core Docker→production, recibos sellados, redirects Rank Math y rollback fail-closed"
