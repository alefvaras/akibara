#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd -P)"
compose_file="${repo_root}/compose.yaml"
compose_source="docker-compose:cli"
wordpress_webroot="/var/www/html"
base_url="http://localhost:8080"

active_local_partial=""
cleanup_local_partial() {
	if [[ -n "${active_local_partial}" && -f "${active_local_partial}"
		&& ! -L "${active_local_partial}" ]]; then
		case "$(basename -- "${active_local_partial}")" in
			.*.partial.*) rm -f -- "${active_local_partial}" ;;
		esac
	fi
}
trap cleanup_local_partial EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

usage() {
	cat <<'EOF'
Usage:
  ops/akibara-local-ui-temp-admin.sh create --credentials FILE
  ops/akibara-local-ui-temp-admin.sh status --credentials FILE
  ops/akibara-local-ui-temp-admin.sh delete --credentials FILE

Purpose:
  Manage only the short-lived administrator used by Docker-local official-UI
  Playwright flows. Account lifecycle runs through WP-CLI in the existing
  Compose `cli` service. This helper never targets QA or production and never
  writes posts, options, plugin settings, schemas, or redirects.

Safety:
  - The target is fixed to compose.yaml, service cli, /var/www/html, and
    http://localhost:8080; there is no host, environment, or config override.
  - Password and marker token persist only in the local 0600 credentials file.
  - The password reaches WP-CLI through stdin, never through process arguments.
  - status and delete require the exact marker, administrator role, and zero
    authored rows in the WordPress posts table.
EOF
}

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

note() {
	printf '%s\n' "$*"
}

require_command() {
	command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

sha256_text() {
	printf '%s' "$1" | shasum -a 256 | awk '{print $1}'
}

file_mode() {
	local path="$1" mode
	if mode="$(stat -f '%Lp' "${path}" 2>/dev/null)"; then
		printf '%s' "${mode}"
	else
		stat -c '%a' "${path}"
	fi
}

absolute_existing_path() {
	local input="$1"
	[[ -f "${input}" && ! -L "${input}" ]] \
		|| die "Path must be a regular non-symlink file: ${input}"
	(
		cd -- "$(dirname -- "${input}")"
		printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${input}")"
	)
}

absolute_new_path() {
	local input="$1" parent base
	parent="$(dirname -- "${input}")"
	base="$(basename -- "${input}")"
	[[ -d "${parent}" && ! -L "${parent}" ]] \
		|| die "Credentials parent must be an existing non-symlink directory"
	[[ "${base}" =~ ^[A-Za-z0-9._-]+$ && "${base}" != "." && "${base}" != ".." ]] \
		|| die "Unsafe credentials filename"
	(
		cd -- "${parent}"
		printf '%s/%s\n' "$(pwd -P)" "${base}"
	)
}

contains_line() {
	local haystack="$1" needle="$2" line
	while IFS= read -r line; do
		[[ "${line}" == "${needle}" ]] && return 0
	done <<< "${haystack}"
	return 1
}

compose() {
	docker compose \
		--project-directory "${repo_root}" \
		-f "${compose_file}" \
		--profile tools \
		"$@"
}

assert_local_stack() {
	local configured running
	[[ -f "${compose_file}" && ! -L "${compose_file}" ]] \
		|| die "compose.yaml must be a regular non-symlink file"
	require_command docker
	docker compose version >/dev/null \
		|| die "Docker Compose is unavailable"
	configured="$(compose config --services)" \
		|| die "Unable to inspect Docker Compose services"
	contains_line "${configured}" db || die "Compose db service is missing"
	contains_line "${configured}" wordpress || die "Compose wordpress service is missing"
	contains_line "${configured}" cli || die "Compose cli service is missing"
	running="$(compose ps --status running --services)" \
		|| die "Unable to inspect running Docker Compose services"
	contains_line "${running}" db || die "Docker db service is not running"
	contains_line "${running}" wordpress \
		|| die "Docker wordpress service is not running"
}

compose_cli_stdin() {
	local program="$1"
	printf '%s' "${program}" |
		compose run --rm --no-deps -T cli bash -s
}

CRED_SCHEMA=""
CRED_ENV=""
CRED_HOST=""
CRED_WEBROOT=""
CRED_BASE_URL=""
CRED_USERNAME=""
CRED_PASSWORD=""
CRED_TOKEN=""
CRED_CREATED_AT_UTC=""

load_credentials() {
	local file="$1" line key value target credential_stamp line_number=0 seen_keys="|"
	[[ -f "${file}" && ! -L "${file}" ]] \
		|| die "Credentials must be a regular non-symlink file"
	[[ "$(file_mode "${file}")" == "600" ]] \
		|| die "Credentials file mode must be exactly 0600"

	while IFS= read -r line || [[ -n "${line}" ]]; do
		line_number=$((line_number + 1))
		[[ "${line}" == *=* ]] || die "Invalid credentials line ${line_number}"
		key="${line%%=*}"
		value="${line#*=}"
		case "${key}" in
			AKB_UI_ADMIN_SCHEMA) target=CRED_SCHEMA ;;
			AKB_UI_ADMIN_ENV) target=CRED_ENV ;;
			AKB_UI_ADMIN_HOST) target=CRED_HOST ;;
			AKB_UI_ADMIN_WEBROOT) target=CRED_WEBROOT ;;
			AKB_UI_ADMIN_BASE_URL) target=CRED_BASE_URL ;;
			AKB_UI_ADMIN_USERNAME) target=CRED_USERNAME ;;
			AKB_UI_ADMIN_PASSWORD) target=CRED_PASSWORD ;;
			AKB_UI_ADMIN_TOKEN) target=CRED_TOKEN ;;
			AKB_UI_ADMIN_CREATED_AT_UTC) target=CRED_CREATED_AT_UTC ;;
			*) die "Unknown credentials key at line ${line_number}: ${key}" ;;
		esac
		[[ "${seen_keys}" != *"|${key}|"* ]] \
			|| die "Duplicate credentials key: ${key}"
		seen_keys+="${key}|"
		printf -v "${target}" '%s' "${value}"
	done < "${file}"

	[[ "${CRED_SCHEMA}" == "2" ]] || die "Unsupported local credentials schema"
	[[ "${CRED_ENV}" == "local" ]] || die "Credentials are not Docker-local"
	[[ "${CRED_HOST}" == "${compose_source}" ]] \
		|| die "Credentials Compose source does not match"
	[[ "${CRED_WEBROOT}" == "${wordpress_webroot}" ]] \
		|| die "Credentials webroot does not match"
	[[ "${CRED_BASE_URL}" == "${base_url}" ]] \
		|| die "Credentials base URL does not match"
	[[ "${CRED_USERNAME}" =~ ^akb_ui_local_[0-9]{14}_[a-f0-9]{12}$ ]] \
		|| die "Temporary username marker rejected"
	[[ "${CRED_PASSWORD}" =~ ^[a-f0-9]{64}$ ]] \
		|| die "Password encoding rejected"
	[[ "${CRED_TOKEN}" =~ ^[a-f0-9]{64}$ ]] \
		|| die "Marker token encoding rejected"
	[[ "${CRED_CREATED_AT_UTC}" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] \
		|| die "Creation timestamp rejected"
	credential_stamp="${CRED_CREATED_AT_UTC//T/}"
	credential_stamp="${credential_stamp%Z}"
	[[ "${CRED_USERNAME}" == "akb_ui_local_${credential_stamp}_"* ]] \
		|| die "Temporary username is not bound to its timestamp"
}

write_credentials() {
	local destination="$1" username="$2" password="$3" token="$4" created_at="$5"
	local parent base partial
	[[ ! -e "${destination}" && ! -L "${destination}" ]] \
		|| die "Credentials destination already exists"
	parent="$(dirname -- "${destination}")"
	base="$(basename -- "${destination}")"
	partial="$(mktemp "${parent}/.${base}.partial.XXXXXX")"
	active_local_partial="${partial}"
	chmod 0600 "${partial}"
	printf '%s\n' \
		"AKB_UI_ADMIN_SCHEMA=2" \
		"AKB_UI_ADMIN_ENV=local" \
		"AKB_UI_ADMIN_HOST=${compose_source}" \
		"AKB_UI_ADMIN_WEBROOT=${wordpress_webroot}" \
		"AKB_UI_ADMIN_BASE_URL=${base_url}" \
		"AKB_UI_ADMIN_USERNAME=${username}" \
		"AKB_UI_ADMIN_PASSWORD=${password}" \
		"AKB_UI_ADMIN_TOKEN=${token}" \
		"AKB_UI_ADMIN_CREATED_AT_UTC=${created_at}" > "${partial}"
	if ! ln -- "${partial}" "${destination}"; then
		rm -f -- "${partial}"
		die "Credentials destination appeared concurrently"
	fi
	rm -f -- "${partial}"
	active_local_partial=""
	[[ "$(file_mode "${destination}")" == "600" ]] \
		|| die "Failed to enforce credentials mode 0600"
}

container_common_body() {
	local body
	read -r -d '' body <<'CONTAINER' || true
set -Eeuo pipefail
IFS=$'\n\t'

[[ "${AKB_USERNAME}" =~ ^akb_ui_local_[0-9]{14}_[a-f0-9]{12}$ ]] || exit 1
[[ "${AKB_TOKEN_SHA256}" =~ ^[a-f0-9]{64}$ ]] || exit 1
[[ "${AKB_EXPECTED_BASE_URL}" == "http://localhost:8080" ]] || exit 1

wp_local() {
	wp --skip-plugins --skip-themes "$@"
}

require_wp() {
	local home_url site_url
	command -v wp >/dev/null 2>&1 || return 1
	[[ "$(pwd -P)" == "/var/www/html" ]] || return 1
	wp_local core is-installed >/dev/null || return 1
	home_url="$(wp_local option get home)" || return 1
	site_url="$(wp_local option get siteurl)" || return 1
	home_url="${home_url%/}"
	site_url="${site_url%/}"
	[[ "${home_url}" == "${AKB_EXPECTED_BASE_URL}" ]] || return 1
	[[ "${site_url}" == "${AKB_EXPECTED_BASE_URL}" ]] || return 1
}

authored_rows() {
	local user_id="$1" prefix count
	[[ "${user_id}" =~ ^[1-9][0-9]*$ ]] || return 1
	prefix="$(wp_local db prefix)" || return 1
	[[ "${prefix}" =~ ^[A-Za-z0-9_]+$ ]] || return 1
	count="$(wp_local db query \
		"SELECT COUNT(*) FROM \`${prefix}posts\` WHERE post_author = ${user_id};" \
		--skip-column-names)" || return 1
	count="${count//$'\r'/}"
	count="${count//$'\n'/}"
	[[ "${count}" =~ ^[0-9]+$ ]] || return 1
	printf '%s' "${count}"
}

exact_user_id() {
	local user_id login
	user_id="$(wp_local user get "${AKB_USERNAME}" --field=ID)" || return 1
	[[ "${user_id}" =~ ^[1-9][0-9]*$ ]] || return 1
	login="$(wp_local user get "${user_id}" --field=user_login)" || return 1
	[[ "${login}" == "${AKB_USERNAME}" ]] || return 1
	printf '%s' "${user_id}"
}

assert_exact_marker_and_role() {
	local user_id="$1" marker roles
	marker="$(wp_local user meta get \
		"${user_id}" _akibara_ui_temp_admin_token_sha256)" || return 1
	[[ "${marker}" == "${AKB_TOKEN_SHA256}" ]] || return 1
	roles="$(wp_local user get "${user_id}" --field=roles)" || return 1
	roles=" ${roles//$'\n'/ } "
	[[ "${roles}" == *" administrator "* ]] || return 1
}

safe_reassign_user_id() {
	local temporary_id="$1" candidates old_ifs candidate login roles
	candidates="$(wp_local user list \
		--role=administrator --orderby=ID --order=ASC --field=ID --format=ids)" \
		|| return 1
	old_ifs="${IFS}"
	IFS=' '
	for candidate in ${candidates}; do
		IFS="${old_ifs}"
		[[ "${candidate}" =~ ^[1-9][0-9]*$ ]] || return 1
		if [[ "${candidate}" != "${temporary_id}" ]]; then
			login="$(wp_local user get "${candidate}" --field=user_login)" || return 1
			if [[ "${login}" == akb_ui_* ]]; then
				IFS=' '
				continue
			fi
			roles="$(wp_local user get "${candidate}" --field=roles)" || return 1
			roles=" ${roles//$'\n'/ } "
			if [[ "${roles}" == *" administrator "* ]]; then
				printf '%s' "${candidate}"
				return 0
			fi
		fi
		IFS=' '
	done
	IFS="${old_ifs}"
	return 1
}
CONTAINER
	printf '%s' "${body}"
}

container_create_script() {
	local username="$1" password="$2" token_sha256="$3" common body
	common="$(container_common_body)"
	read -r -d '' body <<'CONTAINER' || true

[[ "${AKB_PASSWORD}" =~ ^[a-f0-9]{64}$ ]] || exit 1
require_wp || exit 1
if wp_local user get "${AKB_USERNAME}" >/dev/null 2>&1; then
	exit 1
fi

created_id=""
create_attempted=0
cleanup_create_failure() {
	local cleanup_exit_code=$? candidate_id current_login current_email marker rows
	trap - EXIT HUP INT TERM
	if ((cleanup_exit_code != 0 && create_attempted == 1)); then
		candidate_id="${created_id}"
		if [[ ! "${candidate_id}" =~ ^[1-9][0-9]*$ ]]; then
			candidate_id="$(wp_local user get \
				"${AKB_USERNAME}" --field=ID 2>/dev/null || true)"
		fi
		if [[ "${candidate_id}" =~ ^[1-9][0-9]*$ ]]; then
			current_login="$(wp_local user get \
				"${candidate_id}" --field=user_login 2>/dev/null || true)"
			current_email="$(wp_local user get \
				"${candidate_id}" --field=user_email 2>/dev/null || true)"
			marker="$(wp_local user meta get \
				"${candidate_id}" _akibara_ui_temp_admin_token_sha256 \
				2>/dev/null || true)"
			rows="$(authored_rows "${candidate_id}" 2>/dev/null || printf 'unsafe')"
			if [[ "${current_login}" == "${AKB_USERNAME}"
				&& "${current_email}" == "${AKB_USERNAME}@akibara.invalid"
				&& ( -z "${marker}" || "${marker}" == "${AKB_TOKEN_SHA256}" )
				&& "${rows}" == "0" ]]; then
				wp_local user delete "${candidate_id}" --yes >/dev/null 2>&1 || true
			fi
		fi
	fi
	exit "${cleanup_exit_code}"
}
trap cleanup_create_failure EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

create_attempted=1
created_id="$(
	printf '%s\n' "${AKB_PASSWORD}" |
		wp_local user create \
			"${AKB_USERNAME}" "${AKB_USERNAME}@akibara.invalid" \
			--role=administrator --prompt=user_pass --porcelain --quiet |
		awk '/^[0-9]+\r?$/{ gsub(/\r/, ""); id=$0 } END { if (id != "") print id }'
)"
[[ "${created_id}" =~ ^[1-9][0-9]*$ ]] || exit 1
wp_local user meta update \
	"${created_id}" _akibara_ui_temp_admin_token_sha256 "${AKB_TOKEN_SHA256}" \
	>/dev/null || exit 1
[[ "$(exact_user_id)" == "${created_id}" ]] || exit 1
assert_exact_marker_and_role "${created_id}" || exit 1
[[ "$(authored_rows "${created_id}")" == "0" ]] || exit 1

trap - EXIT HUP INT TERM
printf 'CREATED\t%s\t%s\n' "${AKB_USERNAME}" "${created_id}"
CONTAINER
	printf "AKB_USERNAME='%s'\nAKB_PASSWORD='%s'\nAKB_TOKEN_SHA256='%s'\nAKB_EXPECTED_BASE_URL='%s'\n%s\n%s\n" \
		"${username}" "${password}" "${token_sha256}" "${base_url}" "${common}" "${body}"
}

container_status_script() {
	local username="$1" token_sha256="$2" common body
	common="$(container_common_body)"
	read -r -d '' body <<'CONTAINER' || true

require_wp || exit 1
user_id="$(exact_user_id)" || exit 1
assert_exact_marker_and_role "${user_id}" || exit 1
rows="$(authored_rows "${user_id}")" || exit 1
printf 'STATUS\tACTIVE\t%s\t%s\t%s\n' "${AKB_USERNAME}" "${user_id}" "${rows}"
CONTAINER
	printf "AKB_USERNAME='%s'\nAKB_TOKEN_SHA256='%s'\nAKB_EXPECTED_BASE_URL='%s'\n%s\n%s\n" \
		"${username}" "${token_sha256}" "${base_url}" "${common}" "${body}"
}

container_delete_script() {
	local username="$1" token_sha256="$2" common body
	common="$(container_common_body)"
	read -r -d '' body <<'CONTAINER' || true

require_wp || exit 1
user_id="$(exact_user_id)" || exit 1
assert_exact_marker_and_role "${user_id}" || exit 1
[[ "$(authored_rows "${user_id}")" == "0" ]] || exit 1
wp_local user session destroy "${user_id}" --all >/dev/null || exit 1
[[ "$(authored_rows "${user_id}")" == "0" ]] || exit 1
reassign_id="$(safe_reassign_user_id "${user_id}")" || exit 1
wp_local user delete "${user_id}" --reassign="${reassign_id}" --yes >/dev/null || exit 1
if wp_local user get "${AKB_USERNAME}" >/dev/null 2>&1; then
	exit 1
fi
[[ "$(authored_rows "${user_id}")" == "0" ]] || exit 1
printf 'DELETED\t%s\t%s\n' "${AKB_USERNAME}" "${user_id}"
CONTAINER
	printf "AKB_USERNAME='%s'\nAKB_TOKEN_SHA256='%s'\nAKB_EXPECTED_BASE_URL='%s'\n%s\n%s\n" \
		"${username}" "${token_sha256}" "${base_url}" "${common}" "${body}"
}

parse_three_field_receipt() {
	local action="$1" username="$2" output="$3" prefix identifier
	[[ "${output}" != *$'\n'* && "${output}" != *$'\r'* ]] || return 1
	prefix="${action}"$'\t'"${username}"$'\t'
	[[ "${output}" == "${prefix}"* ]] || return 1
	identifier="${output#"${prefix}"}"
	[[ "${identifier}" =~ ^[1-9][0-9]*$ ]] || return 1
	printf '%s' "${identifier}"
}

parse_status_receipt() {
	local username="$1" output="$2" prefix remainder identifier rows
	[[ "${output}" != *$'\n'* && "${output}" != *$'\r'* ]] || return 1
	prefix=$'STATUS\tACTIVE\t'"${username}"$'\t'
	[[ "${output}" == "${prefix}"* ]] || return 1
	remainder="${output#"${prefix}"}"
	[[ "${remainder}" == *$'\t'* ]] || return 1
	identifier="${remainder%%$'\t'*}"
	rows="${remainder#*$'\t'}"
	[[ "${identifier}" =~ ^[1-9][0-9]*$ ]] || return 1
	[[ "${rows}" =~ ^[0-9]+$ ]] || return 1
	printf '%s' "${rows}"
}

create_admin() {
	local credentials_file="$1" created_at credential_stamp
	local username password token token_sha256 output receipt_id
	require_command openssl
	require_command shasum
	[[ ! -e "${credentials_file}" && ! -L "${credentials_file}" ]] \
		|| die "Credentials destination already exists"
	credential_stamp="$(date -u +%Y%m%d%H%M%S)"
	created_at="${credential_stamp:0:8}T${credential_stamp:8:6}Z"
	username="akb_ui_local_${credential_stamp}_$(openssl rand -hex 6)"
	password="$(openssl rand -hex 32)"
	token="$(openssl rand -hex 32)"
	token_sha256="$(sha256_text "${token}")"
	write_credentials \
		"${credentials_file}" "${username}" "${password}" "${token}" "${created_at}"

	if ! output="$(compose_cli_stdin "$(container_create_script \
		"${username}" "${password}" "${token_sha256}")")"; then
		note "The local 0600 credentials file was retained for status/delete recovery:"
		note "${credentials_file}"
		die "Docker-local temporary administrator creation failed"
	fi
	if ! receipt_id="$(parse_three_field_receipt "CREATED" "${username}" "${output}")"; then
		die "Unexpected Docker creation receipt; credentials retained for recovery"
	fi
	note "PASS: Docker-local temporary UI administrator created"
	note "Login: ${base_url}/wp-admin/"
	note "Credentials (local 0600): ${credentials_file}"
}

status_admin() {
	local token_sha256 output authored_count
	require_command shasum
	token_sha256="$(sha256_text "${CRED_TOKEN}")"
	output="$(compose_cli_stdin "$(container_status_script \
		"${CRED_USERNAME}" "${token_sha256}")")"
	if ! authored_count="$(parse_status_receipt "${CRED_USERNAME}" "${output}")"; then
		die "Unexpected Docker status receipt"
	fi
	[[ "${authored_count}" == "0" ]] \
		|| die "Temporary administrator authored content; UI lifecycle is unsafe"
	note "PASS: exact Docker-local administrator is active with zero authored rows"
}

delete_admin() {
	local credentials_file="$1" token_sha256 output receipt_id
	require_command shasum
	token_sha256="$(sha256_text "${CRED_TOKEN}")"
	output="$(compose_cli_stdin "$(container_delete_script \
		"${CRED_USERNAME}" "${token_sha256}")")"
	if ! receipt_id="$(parse_three_field_receipt "DELETED" "${CRED_USERNAME}" "${output}")"; then
		die "Unexpected Docker deletion receipt; credentials retained"
	fi
	rm -f -- "${credentials_file}"
	[[ ! -e "${credentials_file}" && ! -L "${credentials_file}" ]] \
		|| die "Docker account is deleted but local credentials could not be removed"
	note "PASS: exact Docker-local administrator deleted; credentials removed"
}

command_name="${1:-}"
[[ -n "${command_name}" ]] || {
	usage
	exit 2
}
if [[ "${command_name}" == "-h" || "${command_name}" == "--help" ]]; then
	usage
	exit 0
fi
shift

credentials_file=""
while (($# > 0)); do
	case "$1" in
		--credentials)
			(($# >= 2)) || die "--credentials requires a value"
			[[ -z "${credentials_file}" ]] || die "Duplicate argument: --credentials"
			credentials_file="$2"
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*) die "Unknown argument: $1" ;;
	esac
done

case "${command_name}" in
	create|status|delete)
		;;
	*)
		usage
		die "Unknown command: ${command_name}"
		;;
esac

[[ -n "${credentials_file}" ]] \
	|| die "${command_name} requires --credentials"
assert_local_stack

if [[ "${command_name}" == "create" ]]; then
	credentials_file="$(absolute_new_path "${credentials_file}")"
	create_admin "${credentials_file}"
else
	credentials_file="$(absolute_existing_path "${credentials_file}")"
	load_credentials "${credentials_file}"
	if [[ "${command_name}" == "status" ]]; then
		status_admin
	else
		delete_admin "${credentials_file}"
	fi
fi
