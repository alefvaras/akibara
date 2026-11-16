#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
pipeline="${script_dir}/akibara-theme-seo-production-promotion.sh"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-theme-seo-promotion-test.XXXXXX")"

cleanup() {
	case "${test_root}" in
		*/akibara-theme-seo-promotion-test.*) rm -rf -- "${test_root}" ;;
	esac
}
trap cleanup EXIT

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

for command_name in bash chmod cp grep jq shasum tar; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| fail "falta comando de prueba: ${command_name}"
done

bash -n "${pipeline}"
grep -Fq "akb-theme-seo-maintenance=" "${pipeline}"
grep -Fq "cf-cache-status:" "${pipeline}"
if grep -Fq 'for probe in "/"' "${pipeline}"; then
	fail "el gate de mantenimiento conserva una portada cacheable"
fi
AKB_THEME_SEO_PROMOTION_LIBRARY_ONLY=1
export AKB_THEME_SEO_PROMOTION_LIBRARY_ONLY
# shellcheck source=ops/akibara-theme-seo-production-promotion.sh
source "${pipeline}"
unset AKB_THEME_SEO_PROMOTION_LIBRARY_ONLY

verify_artifact >/dev/null

tampered_archive="${test_root}/akibara-gp-2.1.50.zip"
cp -- "${repo_root}/dist/akibara-gp-2.1.50.zip" "${tampered_archive}"
printf 'tampered\n' >> "${tampered_archive}"
if (
	archive_path="${tampered_archive}"
	verify_artifact >/dev/null 2>&1
); then
	fail "el gate aceptó un ZIP 2.1.50 divergente"
fi

install_command="${test_root}/install-command.sh"
(
	AKB_WEBROOT='/home/akibara/domains/akibara.cl/public_html'
	AKB_HOST='deploy@akibara.cl'
	ssh() {
		local last_argument="${!#}"
		printf '%s\n' "${last_argument}" > "${install_command}"
		while IFS= read -r _line; do :; done
	}
	install_theme
)
bash -n "${install_command}"
grep -Fq 'wp --path="${AKB_WEBROOT}" theme install "${remote_zip}" --force' \
	"${install_command}"
grep -Fq 'mktemp --suffix=.zip /tmp/akibara-theme-seo.XXXXXX' \
	"${install_command}"
grep -Fq 'trap "cleanup || true" EXIT' "${install_command}"
grep -Fq 'plugin is-active seo-by-rank-math' "${install_command}"
if grep -Eq 'wp-content/plugins|plugin install|option update|db (export|import|query)' \
	"${install_command}"; then
	fail "el instalador theme-only contiene otra superficie escribible"
fi

artifact_script="${test_root}/remote-artifact-gate.sh"
(
	AKB_WEBROOT='/home/akibara/domains/akibara.cl/public_html'
	ssh_read() {
		printf '%s\n' "$1" > "${artifact_script}"
	}
	remote_artifact_gate active >/dev/null
)
bash -n "${artifact_script}"
grep -Fq 'THEME_SEO_ZERO_REMOTE_ARTIFACTS_PASS' "${artifact_script}"
grep -Fq 'find /tmp -maxdepth 1 -name "akibara-theme-seo.*"' \
	"${artifact_script}"
grep -Fq 'wp-content/upgrade-temp-backup' "${artifact_script}"

backup_script="${test_root}/backup-script.sh"
mkdir -p "${test_root}/remote-backup-source/akibara-gp"
printf 'remote fixture\n' \
	> "${test_root}/remote-backup-source/akibara-gp/style.css"
(
	AKB_WEBROOT='/home/akibara/domains/akibara.cl/public_html'
	ssh_read() {
		printf '%s\n' "$1" > "${backup_script}"
		tar -C "${test_root}/remote-backup-source" -czf - akibara-gp
	}
	stream_theme_backup "${test_root}/captured-backup.tar.gz"
)
bash -n "${backup_script}"
grep -Fq 'tar -C "${root}" -czf - akibara-gp' "${backup_script}"
if grep -Eq 'akibara-(business|search|cart-checkout-blocks)' "${backup_script}"; then
	fail "el respaldo theme-only incluyó otro componente"
fi

restore_command="${test_root}/restore-command.sh"
backup_fixture="${test_root}/theme-before.tar.gz"
mkdir -p "${test_root}/backup-source/akibara-gp"
printf 'fixture\n' > "${test_root}/backup-source/akibara-gp/style.css"
tar -C "${test_root}/backup-source" -czf "${backup_fixture}" akibara-gp
shasum -a 256 "${backup_fixture}" > "${backup_fixture}.sha256"
(
	AKB_WEBROOT='/home/akibara/domains/akibara.cl/public_html'
	AKB_HOST='deploy@akibara.cl'
	ssh() {
		local last_argument="${!#}"
		printf '%s\n' "${last_argument}" > "${restore_command}"
		while IFS= read -r _line; do :; done
	}
	restore_theme "${backup_fixture}"
)
bash -n "${restore_command}"
grep -Fq 'rm -rf -- "${target}"' "${restore_command}"
grep -Fq 'AKB_PREIMAGE_VERSION=2.1.49' "${restore_command}"
if grep -Eq 'wp-content/plugins|plugin install|option update|db (export|import|query)' \
	"${restore_command}"; then
	fail "el rollback theme-only contiene otra superficie escribible"
fi

gate_root="${test_root}/gates"
write_receipt docker local-compose >/dev/null
verify_receipt docker local-compose
docker_receipt="$(receipt_path docker)"
jq '.status = "FAIL"' "${docker_receipt}" > "${docker_receipt}.tampered"
mv -- "${docker_receipt}.tampered" "${docker_receipt}"
(
	cd -- "$(dirname -- "${docker_receipt}")"
	shasum -a 256 "$(basename -- "${docker_receipt}")" \
		> "$(basename -- "${docker_receipt}").sha256"
)
if (verify_receipt docker local-compose >/dev/null 2>&1); then
	fail "el gate aceptó un recibo Docker no-PASS con checksum recalculado"
fi

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
if (verify_receipt docker local-compose >/dev/null 2>&1); then
	fail "el gate aceptó un recibo Docker vencido"
fi

config="${test_root}/promotion.conf"
printf '%s\n' \
	'PRODUCTION_HOST=deploy@akibara.cl' \
	'PRODUCTION_WEBROOT=/home/akibara/domains/akibara.cl/public_html' \
	'PRODUCTION_BASE_URL=https://akibara.cl' \
	'PRODUCTION_LOG_PATHS=/home/akibara/domains/akibara.cl/public_html/error_log' \
	> "${config}"
chmod 0600 "${config}"

plan_output="$(
	bash "${pipeline}" plan --config "${config}"
)"
grep -Fq 'DRY RUN — no se ejecutó ssh, curl ni escritura remota.' \
	<<< "${plan_output}"
grep -Fq 'Release: akibara-gp-seo-production-2.1.50' <<< "${plan_output}"
grep -Fq 'Destino: production-only' <<< "${plan_output}"
grep -Fq 'No se escribe Business, Search, Checkout' <<< "${plan_output}"

qa_config="${test_root}/qa-key.conf"
cp -- "${config}" "${qa_config}"
printf 'QA_HOST=deploy@qa.invalid\n' >> "${qa_config}"
chmod 0600 "${qa_config}"
if bash "${pipeline}" plan --config "${qa_config}" >/dev/null 2>&1; then
	fail "el carril theme aceptó una clave QA obsoleta"
fi
if bash "${pipeline}" plan --env qa --config "${config}" >/dev/null 2>&1; then
	fail "el carril theme aceptó el selector QA obsoleto"
fi

if bash "${pipeline}" promote \
	--config "${config}" \
	--confirm akibara-gp-seo-production-2.1.50 >/dev/null 2>&1; then
	fail "el carril permitió promote sin --apply"
fi
if bash "${pipeline}" promote \
	--config "${config}" \
	--apply \
	--confirm release-equivocado >/dev/null 2>&1; then
	fail "el carril permitió una confirmación no exacta"
fi
if bash "${pipeline}" plan \
	--config "${config}" \
	--apply >/dev/null 2>&1; then
	fail "el dry run aceptó --apply"
fi

run_cleanup_scenario() {
	local scenario="$1" restore_status="$2"
	local trace="${test_root}/cleanup-${scenario}.trace"
	local evidence="${test_root}/cleanup-${scenario}"
	mkdir -p "${evidence}"
	(
		mutation_started=1
		maintenance_active=1
		active_evidence_dir="${evidence}"
		theme_backup="${backup_fixture}"
		ensure_maintenance() {
			printf 'ensure\n' >> "${trace}"
			return 0
		}
		restore_theme() {
			printf 'restore\n' >> "${trace}"
			return "${restore_status}"
		}
		purge_caches_under_maintenance() {
			printf 'purge\n' >> "${trace}"
			return 0
		}
		verify_remote_state() {
			printf 'verify-preimage\n' >> "${trace}"
			return 0
		}
		cleanup_empty_upgrade_dirs() {
			printf 'cleanup-upgrades\n' >> "${trace}"
			return 0
		}
		deactivate_maintenance() {
			printf 'deactivate\n' >> "${trace}"
			return 0
		}
		purge_caches_live() {
			printf 'purge-live\n' >> "${trace}"
			return 0
		}
		remote_artifact_gate() {
			printf 'zero-artifacts\n' >> "${trace}"
			return 0
		}
		cleanup_on_failure 1 >/dev/null
	)
	printf '%s' "${trace}"
}

rollback_pass_trace="$(run_cleanup_scenario pass 0)"
grep -Fq 'restore' "${rollback_pass_trace}"
grep -Fq 'verify-preimage' "${rollback_pass_trace}"
grep -Fq 'deactivate' "${rollback_pass_trace}"
grep -Fq 'zero-artifacts' "${rollback_pass_trace}"

rollback_fail_trace="$(run_cleanup_scenario fail 1)"
grep -Fq 'restore' "${rollback_fail_trace}"
if grep -Fq 'deactivate' "${rollback_fail_trace}"; then
	fail "el rollback fallido reabrió el sitio"
fi

if grep -Eq \
	'wp-content/plugins/(akibara-business|akibara-search|akibara-cart-checkout-blocks)' \
	"${pipeline}"; then
	fail "el carril theme-only contiene un árbol de plugin desplegable"
fi
if grep -Eq 'wp --path=.* (db|option) (export|import|query|update|delete)' \
	"${pipeline}"; then
	fail "el carril theme-only contiene una escritura de base de datos"
fi
if grep -Eq 'graphify (update|add)|rank-math.*(option update|redirection)' \
	"${pipeline}"; then
	fail "el carril theme-only contiene una escritura fuera de alcance"
fi

echo "PASS: carril production-only theme SEO fail-closed, rollback local y cero artefactos"
