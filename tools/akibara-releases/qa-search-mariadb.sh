#!/usr/bin/env bash

# Gate MariaDB P0 de Akibara Search sobre clones desechables de la DB Docker.
# La DB fuente se lee mediante mariadb-dump; nunca se detiene cron ni se escribe
# la base principal. Cada modo recibe una base distinta y el PHP vuelve a
# comprobar el nombre efímero antes de cualquier limpieza destructiva.

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
compose_file="${repo_root}/compose.yaml"
integration_file="${repo_root}/public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php"
source_database="akibara"
fixture_databases=()
cleanup_done=0

die() {
	printf '%s\n' "$*" >&2
	exit 1
}

valid_fixture_name() {
	[[ "$1" =~ ^akb_search_itest_[0-9]+_[0-9a-f]{12}_(baseline|candidate)_[12]$ ]]
}

drop_fixture() {
	local fixture_database="$1"
	if ! valid_fixture_name "${fixture_database}" || [[ "${fixture_database}" == "${source_database}" ]]; then
		printf 'Nombre de fixture inseguro; se rehúsa DROP: %s\n' "${fixture_database}" >&2
		return 1
	fi

	docker compose -f "${compose_file}" exec -T db mariadb -uroot -proot_local \
		-e "DROP DATABASE IF EXISTS \`${fixture_database}\`;" >/dev/null 2>&1
}

cleanup() {
	local fixture_database
	if (( cleanup_done )); then
		return
	fi
	cleanup_done=1
	trap - EXIT HUP INT TERM
	set +e
	for fixture_database in "${fixture_databases[@]:-}"; do
		if [[ -n "${fixture_database}" ]]; then
			drop_fixture "${fixture_database}"
		fi
	done
}

trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

command -v docker >/dev/null 2>&1 || die 'Falta el comando requerido: docker'
[[ -f "${compose_file}" ]] || die "Falta compose.yaml: ${compose_file}"
[[ -f "${integration_file}" ]] || die "Falta el gate MariaDB: ${integration_file}"
[[ "${source_database}" =~ ^[A-Za-z0-9_]+$ ]] || die 'Nombre de DB fuente inseguro'

docker compose -f "${compose_file}" ps --status running db | grep -q 'db' \
	|| die 'El servicio Docker db no está activo'

cron_was_running=0
if docker compose -f "${compose_file}" ps --status running --services cron | grep -qx 'cron'; then
	cron_was_running=1
fi

source_exists="$({
	docker compose -f "${compose_file}" exec -T db mariadb -N -B -uroot -proot_local \
		-e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '${source_database}';"
} | tr -d '[:space:]')"
[[ "${source_exists}" == '1' ]] || die "No existe la DB fuente local ${source_database}"

printf -v run_nonce '%06x%06x' "${RANDOM}" "${RANDOM}"
gate_pid="$$"

run_mode() {
	local mode="$1"
	local iteration="$2"
	local fixture_database="akb_search_itest_${gate_pid}_${run_nonce}_${mode}_${iteration}"
	local fixture_index
	local gate_output

	[[ "${mode}" == 'baseline' || "${mode}" == 'candidate' ]] \
		|| die "Modo inseguro: ${mode}"
	valid_fixture_name "${fixture_database}" || die "Nombre de fixture inválido: ${fixture_database}"
	[[ "${fixture_database}" != "${source_database}" ]] \
		|| die 'La fixture no puede coincidir con la DB fuente'

	fixture_index="${#fixture_databases[@]}"
	fixture_databases+=("${fixture_database}")
	drop_fixture "${fixture_database}"

	printf 'Creando clon efímero %s...\n' "${fixture_database}"
	docker compose -f "${compose_file}" exec -T db mariadb -uroot -proot_local \
		-e "CREATE DATABASE \`${fixture_database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
	docker compose -f "${compose_file}" exec -T db mariadb-dump -uroot -proot_local \
		--single-transaction --quick --skip-comments --skip-lock-tables "${source_database}" \
		| docker compose -f "${compose_file}" exec -T db mariadb -uroot -proot_local "${fixture_database}"

	printf 'Ejecutando modo %s, repetición %s...\n' "${mode}" "${iteration}"
	if ! gate_output="$(
		docker compose -f "${compose_file}" run --rm --no-deps -T \
			-e WORDPRESS_DB_NAME="${fixture_database}" \
			-e WORDPRESS_DB_USER=root \
			-e WORDPRESS_DB_PASSWORD=root_local \
			-e WP_ENVIRONMENT_TYPE=local \
			-e AKB_ITEST_EPHEMERAL_DB=1 \
			-e AKB_ITEST_MODE="${mode}" \
			--volume "${repo_root}/public_html:/var/www/html:ro" \
			cli wp eval-file - < "${integration_file}" 2>&1
	)"; then
		printf '%s\n' "${gate_output}" >&2
		die "El proceso WP-CLI falló en modo ${mode}, repetición ${iteration}"
	fi
	printf '%s\n' "${gate_output}"
	grep -Fq "Success: Gate MariaDB Search v2 OK (${mode})." <<< "${gate_output}" \
		|| die "WP-CLI terminó sin el receipt de éxito en modo ${mode}, repetición ${iteration}"

	drop_fixture "${fixture_database}"
	fixture_databases[fixture_index]=''
}

for iteration in 1 2; do
	run_mode baseline "${iteration}"
	run_mode candidate "${iteration}"
done

remaining_fixtures="$({
	docker compose -f "${compose_file}" exec -T db mariadb -N -B -uroot -proot_local \
		-e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME REGEXP '^akb_search_itest_${gate_pid}_${run_nonce}_(baseline|candidate)_[12]$';"
} | tr -d '[:space:]')"
[[ "${remaining_fixtures}" == '0' ]] || die "Quedaron ${remaining_fixtures} fixture(s) de este gate"

cron_is_running=0
if docker compose -f "${compose_file}" ps --status running --services cron | grep -qx 'cron'; then
	cron_is_running=1
fi
[[ "${cron_is_running}" == "${cron_was_running}" ]] \
	|| die "El gate alteró el estado de cron (antes=${cron_was_running}, después=${cron_is_running})"

printf '%s\n' 'PASS: control baseline y candidato v2 repetidos dos veces en clones efímeros; DB principal y estado de cron intactos'
