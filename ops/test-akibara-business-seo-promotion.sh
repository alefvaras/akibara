#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
pipeline="${script_dir}/akibara-business-seo-promotion.sh"
config_library="${script_dir}/lib/akibara-promotion-config.sh"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-seo-promotion-test.XXXXXX")"

cleanup() {
	case "${test_root}" in
		*/akibara-business-seo-promotion-test.*) rm -rf -- "${test_root}" ;;
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

for command_name in bash chmod cmp cp find grep jq sed shasum tar unzip zipinfo; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| fail "falta comando de prueba: ${command_name}"
done

bash -n "${pipeline}"
bash -n "${config_library}"
grep -Fq "akb-business-seo-maintenance=" "${pipeline}"
grep -Fq "cf-cache-status:" "${pipeline}"
grep -Fq 'mktemp --suffix=.zip /tmp/akibara-business-seo.XXXXXX' "${pipeline}"
grep -Fq 'trap "cleanup || true" EXIT' "${pipeline}"
grep -Fq '.local-backups/business-seo-promotion/production/' "${pipeline}"
if grep -Fq 'for probe in "/"' "${pipeline}"; then
	fail "el gate de mantenimiento conserva una portada cacheable"
fi
for forbidden in \
	'QA_HOST' 'QA_WEBROOT' 'QA_BASE_URL' 'QA_LOG_PATHS' \
	'qa_validation_root' 'refresh_qa_receipt' 'qa.akibara.cl' \
	'/public_html/qa' 'AKB_SCAN_EXCLUDED_WEBROOT'; do
	if grep -Fq -- "${forbidden}" "${pipeline}"; then
		fail "el carril Business conserva lógica QA/stage: ${forbidden}"
	fi
done
jq -e '.promotion_sequence == ["docker", "production"]' \
	"${script_dir}/contracts/akibara-business-1.95.3-mailpoet.json" >/dev/null \
	|| fail "el contrato Business no exige Docker→production"

AKB_BUSINESS_SEO_PROMOTION_LIBRARY_ONLY=1
export AKB_BUSINESS_SEO_PROMOTION_LIBRARY_ONLY
# shellcheck source=ops/akibara-business-seo-promotion.sh
source "${pipeline}"
unset AKB_BUSINESS_SEO_PROMOTION_LIBRARY_ONLY

[[ "${release_id}" == "akibara-business-1.95.3-mailpoet-noindex" ]]
[[ "${component_slug}" == akibara-business ]]
[[ "${component_version}" == 1.95.3 ]]
[[ "${preimage_version}" == 1.95.2 ]]
verify_artifact >/dev/null

robots_fixture="${test_root}/robots.html"
printf '%s\n' '<meta name="robots" content="noindex, follow"/>' > "${robots_fixture}"
robots_meta="$(robots_meta_tag "${robots_fixture}")"
[[ "${robots_meta}" == '<meta name="robots" content="noindex, follow"/>' ]]
robots_meta_has_directive "${robots_meta}" noindex
robots_meta_has_directive "${robots_meta}" follow
expect_failure robots_meta_has_directive "${robots_meta}" nofollow
expect_failure robots_meta_has_directive \
	'<meta name="robots" content="noindex, nofollow"/>' follow
verify_production_robots_contract \
	'<meta name="robots" content="noindex, follow"/>' mailpoet
verify_production_robots_contract \
	'<meta name="robots" content="index, follow"/>' unrelated
expect_failure verify_production_robots_contract \
	'<meta name="robots" content="noindex, nofollow"/>' unrelated
expect_failure verify_production_robots_contract \
	'<meta name="robots" content="index, follow"/>' mailpoet
expect_failure verify_production_robots_contract \
	'<meta name="robots" content="index, follow"/>' desconocido

artifact_script="${test_root}/remote-artifact-gate.sh"
(
	AKB_WEBROOT='/home/akibara/domains/akibara.cl/public_html'
	ssh_read() {
		printf '%s\n' "$1" > "${artifact_script}"
	}
	remote_artifact_gate active >/dev/null
)
bash -n "${artifact_script}"
grep -Fq 'BUSINESS_SEO_REMOTE_ARTIFACT_GATE_PASS' "${artifact_script}"
grep -Fq 'find /tmp -maxdepth 1 -name "akibara-business-seo.*"' \
	"${artifact_script}"
grep -Fq 'wp-content/upgrade-temp-backup' "${artifact_script}"

install_command="${test_root}/install-command.sh"
(
	AKB_WEBROOT='/home/akibara/domains/akibara.cl/public_html'
	AKB_HOST='deployer@akibara.cl'
	ssh() {
		local last_argument="${!#}"
		printf '%s\n' "${last_argument}" > "${install_command}"
		while IFS= read -r _line; do :; done
	}
	install_business
)
bash -n "${install_command}"
grep -Fq 'wp --path="${AKB_WEBROOT}" plugin install "${remote_zip}" --force' \
	"${install_command}"
grep -Fq 'mktemp --suffix=.zip /tmp/akibara-business-seo.XXXXXX' \
	"${install_command}"
if grep -Eq 'akibara-search|akibara-cart-checkout-blocks|akibara-gp|theme install' \
	"${install_command}"; then
	fail "el instalador Business-only incluyó otro componente"
fi

backup_script="${test_root}/backup-script.sh"
mkdir -p "${test_root}/remote-backup-source/akibara-business"
printf 'fixture\n' > "${test_root}/remote-backup-source/akibara-business/akibara-business.php"
(
	AKB_WEBROOT='/home/akibara/domains/akibara.cl/public_html'
	ssh_read() {
		printf '%s\n' "$1" > "${backup_script}"
		tar -C "${test_root}/remote-backup-source" -czf - akibara-business
	}
	stream_code_backup "${test_root}/captured-backup.tar.gz"
)
bash -n "${backup_script}"
grep -Fq 'tar -C "${root}" -czf - akibara-business' "${backup_script}"
if grep -Eq 'akibara-search|akibara-cart-checkout-blocks|akibara-gp' \
	"${backup_script}"; then
	fail "el respaldo Business-only incluyó otro componente"
fi

restore_command="${test_root}/restore-command.sh"
backup_fixture="${test_root}/business-before.tar.gz"
mkdir -p "${test_root}/backup-source/akibara-business"
printf 'fixture\n' > "${test_root}/backup-source/akibara-business/akibara-business.php"
tar -C "${test_root}/backup-source" -czf "${backup_fixture}" akibara-business
shasum -a 256 "${backup_fixture}" > "${backup_fixture}.sha256"
(
	AKB_WEBROOT='/home/akibara/domains/akibara.cl/public_html'
	AKB_HOST='deployer@akibara.cl'
	ssh() {
		local last_argument="${!#}"
		printf '%s\n' "${last_argument}" > "${restore_command}"
		while IFS= read -r _line; do :; done
	}
	restore_business "${backup_fixture}"
)
bash -n "${restore_command}"
grep -Fq 'rm -rf -- "${target}"' "${restore_command}"
grep -Fq 'AKB_PREIMAGE_VERSION=1.95.2' "${restore_command}"
if grep -Eq 'akibara-search|akibara-cart-checkout-blocks|akibara-gp|theme install' \
	"${restore_command}"; then
	fail "el rollback Business-only incluyó otro componente"
fi

tampered_archive="${test_root}/akibara-business-1.95.3.zip"
cp -- "${repo_root}/dist/akibara-business-1.95.3.zip" "${tampered_archive}"
printf 'tampered\n' >> "${tampered_archive}"
expect_failure env \
	AKB_BUSINESS_SEO_PROMOTION_LIBRARY_ONLY=1 \
	bash -c "source '${pipeline}'; archive_path='${tampered_archive}'; verify_artifact"

gate_root="${test_root}/gates"
write_receipt docker local-compose >/dev/null
verify_receipt docker local-compose
write_receipt production \
	'deployer@akibara.cl:/home/akibara/domains/akibara.cl/public_html' >/dev/null
verify_receipt production \
	'deployer@akibara.cl:/home/akibara/domains/akibara.cl/public_html'
expect_failure write_receipt qa local-compose

docker_receipt="$(receipt_path docker)"
jq '.status = "FAIL"' "${docker_receipt}" > "${docker_receipt}.tampered"
mv -- "${docker_receipt}.tampered" "${docker_receipt}"
(
	cd -- "$(dirname -- "${docker_receipt}")"
	shasum -a 256 "$(basename -- "${docker_receipt}")" \
		> "$(basename -- "${docker_receipt}").sha256"
)
expect_failure verify_receipt docker local-compose

write_receipt docker local-compose >/dev/null
docker_receipt="$(receipt_path docker)"
jq '.completed_at_utc = "2020-01-01T00:00:00Z"' "${docker_receipt}" \
	> "${docker_receipt}.stale"
mv -- "${docker_receipt}.stale" "${docker_receipt}"
(
	cd -- "$(dirname -- "${docker_receipt}")"
	shasum -a 256 "$(basename -- "${docker_receipt}")" \
		> "$(basename -- "${docker_receipt}").sha256"
)
expect_failure verify_receipt docker local-compose

config="${test_root}/promotion.conf"
printf '%s\n' \
	'PRODUCTION_HOST=deployer@akibara.cl' \
	'PRODUCTION_WEBROOT=/home/akibara/domains/akibara.cl/public_html' \
	'PRODUCTION_BASE_URL=https://akibara.cl' \
	'PRODUCTION_LOG_PATHS=/home/akibara/domains/akibara.cl/public_html/error_log' \
	> "${config}"
chmod 0600 "${config}"

plan_output="$(bash "${pipeline}" plan --config "${config}")"
grep -Fq 'DRY RUN — no se ejecutó ssh, curl ni escritura remota.' \
	<<< "${plan_output}"
grep -Fq 'Destino: production' <<< "${plan_output}"
grep -Fq 'Exigir recibo Docker' <<< "${plan_output}"
grep -Fq 'Respaldar únicamente el árbol Business por streaming a local.' \
	<<< "${plan_output}"
grep -Fq 'No se empaqueta ni instala Search, Checkout o themes.' \
	<<< "${plan_output}"
if grep -Eqi 'remote qa|stage|qa\.akibara|/public_html/qa' <<< "${plan_output}"; then
	fail "el plan Business conserva QA/stage"
fi

qa_config="${test_root}/qa-key.conf"
cp -- "${config}" "${qa_config}"
printf 'QA_HOST=deployer@qa.invalid\n' >> "${qa_config}"
chmod 0600 "${qa_config}"
expect_failure bash "${pipeline}" plan --config "${qa_config}"
expect_failure bash "${pipeline}" plan --env qa --config "${config}"
expect_failure bash "${pipeline}" promote --config "${config}" \
	--confirm "${release_id}"
expect_failure bash "${pipeline}" promote --config "${config}" \
	--apply --confirm incorrecto
expect_failure bash "${pipeline}" plan --config "${config}" --apply

safe_trace="${test_root}/rollback-safe.trace"
safe_evidence="${test_root}/rollback-safe"
mkdir -p "${safe_evidence}"
(
	mutation_started=1
	maintenance_active=1
	active_evidence_dir="${safe_evidence}"
	code_backup="${backup_fixture}"
	ensure_maintenance() { printf 'ensure\n' >> "${safe_trace}"; }
	restore_business() { printf 'restore\n' >> "${safe_trace}"; }
	purge_caches_under_maintenance() { printf 'purge\n' >> "${safe_trace}"; }
	verify_remote_state() { printf 'verify-preimage\n' >> "${safe_trace}"; }
	cleanup_empty_upgrade_dirs() { printf 'cleanup-upgrades\n' >> "${safe_trace}"; }
	deactivate_maintenance() { printf 'deactivate\n' >> "${safe_trace}"; }
	purge_caches_live() { printf 'purge-live\n' >> "${safe_trace}"; }
	remote_artifact_gate() { printf 'zero-artifacts\n' >> "${safe_trace}"; }
	cleanup_on_failure 1 >/dev/null
)
grep -Fq 'restore' "${safe_trace}"
grep -Fq 'verify-preimage' "${safe_trace}"
grep -Fq 'deactivate' "${safe_trace}"
grep -Fq 'zero-artifacts' "${safe_trace}"

failed_trace="${test_root}/rollback-failed.trace"
(
	mutation_started=1
	maintenance_active=1
	active_evidence_dir="${safe_evidence}"
	code_backup="${backup_fixture}"
	ensure_maintenance() { printf 'ensure\n' >> "${failed_trace}"; }
	restore_business() {
		printf 'restore-failed\n' >> "${failed_trace}"
		return 1
	}
	deactivate_maintenance() { printf 'deactivate\n' >> "${failed_trace}"; }
	cleanup_on_failure 1 >/dev/null
)
grep -Fq 'restore-failed' "${failed_trace}"
if grep -Fq 'deactivate' "${failed_trace}"; then
	fail "el rollback Business fallido reabrió producción"
fi

if grep -Eq \
	'wp-content/(plugins/(akibara-search|akibara-cart-checkout-blocks)|themes/akibara-gp)' \
	"${pipeline}"; then
	fail "el carril Business-only contiene otro árbol desplegable"
fi

echo "PASS: carril Business Docker→production, robots, recibos, respaldo local y rollback fail-closed"
