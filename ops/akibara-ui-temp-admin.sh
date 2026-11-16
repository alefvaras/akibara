#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
config_library="${script_dir}/lib/akibara-promotion-config.sh"
[[ -f "${config_library}" && ! -L "${config_library}" ]] || {
	printf 'ERROR: Missing regular config library: %s\n' "${config_library}" >&2
	exit 1
}
# shellcheck source=ops/lib/akibara-promotion-config.sh
source "${config_library}"

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
  ops/akibara-ui-temp-admin.sh create --env production --config FILE \
    --credentials FILE
  ops/akibara-ui-temp-admin.sh status --env production --config FILE \
    --credentials FILE
  ops/akibara-ui-temp-admin.sh delete --env production --config FILE \
    --credentials FILE

Purpose:
  Manage only the short-lived WordPress administrator used to operate official
  plugin administration screens. This helper never changes Rank Math content,
  options, schemas, redirects, or any other application data.

Safety:
  - Hosts and webroots come only from the strict promotion config allowlist.
  - SSH always uses BatchMode=yes and StrictHostKeyChecking=yes.
  - Password and marker token persist only in the local 0600 credentials file.
  - Secrets are sent to the remote shell over stdin, never in SSH arguments.
  - No secret, script, backup, or temporary file is created remotely.
  - delete requires the exact marker token, administrator role, and zero rows
    authored by the temporary user in the WordPress posts table.
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

file_mode() {
	local path="$1" mode
	if mode="$(stat -f '%Lp' "${path}" 2>/dev/null)"; then
		printf '%s' "${mode}"
	else
		stat -c '%a' "${path}"
	fi
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
		[[ "${seen_keys}" != *"|${key}|"* ]] || die "Duplicate credentials key: ${key}"
		seen_keys+="${key}|"
		printf -v "${target}" '%s' "${value}"
	done < "${file}"

	[[ "${CRED_SCHEMA}" == "1" ]] || die "Unsupported credentials schema"
	[[ "${CRED_ENV}" == "production" ]] \
		|| die "Invalid credentials environment"
	akb_validate_host "${CRED_HOST}" || die "Credentials host validation failed"
	akb_validate_webroot "${CRED_WEBROOT}" || die "Credentials webroot validation failed"
	akb_validate_base_url "${CRED_BASE_URL}" || die "Credentials base URL validation failed"
	[[ "${CRED_USERNAME}" =~ ^akb_ui_production_[0-9]{14}_[a-f0-9]{12}$ ]] \
		|| die "Temporary username marker rejected"
	[[ "${CRED_PASSWORD}" =~ ^[a-f0-9]{64}$ ]] || die "Password encoding rejected"
	[[ "${CRED_TOKEN}" =~ ^[a-f0-9]{64}$ ]] || die "Marker token encoding rejected"
	[[ "${CRED_CREATED_AT_UTC}" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] \
		|| die "Creation timestamp rejected"
	credential_stamp="${CRED_CREATED_AT_UTC//T/}"
	credential_stamp="${credential_stamp%Z}"
	[[ "${CRED_USERNAME}" == "akb_ui_${CRED_ENV}_${credential_stamp}_"* ]] \
		|| die "Temporary username is not bound to its environment and timestamp"
}

assert_credentials_bound_to_target() {
	local env_name="$1"
	[[ "${CRED_ENV}" == "${env_name}" ]] \
		|| die "Credentials belong to a different environment"
	[[ "${CRED_HOST}" == "${AKB_HOST}" ]] \
		|| die "Credentials host does not match the config allowlist"
	[[ "${CRED_WEBROOT}" == "${AKB_WEBROOT}" ]] \
		|| die "Credentials webroot does not match the config allowlist"
	[[ "${CRED_BASE_URL}" == "${AKB_BASE_URL}" ]] \
		|| die "Credentials base URL does not match the config allowlist"
}

write_credentials() {
	local destination="$1" env_name="$2" username="$3" password="$4" token="$5"
	local created_at="$6" parent base partial
	[[ ! -e "${destination}" && ! -L "${destination}" ]] \
		|| die "Credentials destination already exists"
	parent="$(dirname -- "${destination}")"
	base="$(basename -- "${destination}")"
	partial="$(mktemp "${parent}/.${base}.partial.XXXXXX")"
	active_local_partial="${partial}"
	chmod 0600 "${partial}"
	printf '%s\n' \
		"AKB_UI_ADMIN_SCHEMA=1" \
		"AKB_UI_ADMIN_ENV=${env_name}" \
		"AKB_UI_ADMIN_HOST=${AKB_HOST}" \
		"AKB_UI_ADMIN_WEBROOT=${AKB_WEBROOT}" \
		"AKB_UI_ADMIN_BASE_URL=${AKB_BASE_URL}" \
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

ssh_stdin() {
	local remote_script="$1"
	printf '%s' "${remote_script}" |
		ssh \
			-o BatchMode=yes \
			-o StrictHostKeyChecking=yes \
			-o ConnectTimeout=15 \
			"${AKB_HOST}" \
			"AKB_WEBROOT=$(printf '%q' "${AKB_WEBROOT}") bash -s"
}

remote_common_body() {
	local body
	read -r -d '' body <<'REMOTE' || true
set -Eeuo pipefail
IFS=$'\n\t'

[[ "${AKB_WEBROOT}" =~ ^/home/[A-Za-z0-9._-]+(/domains/[A-Za-z0-9.-]+)?/public_html$ ]] \
	|| exit 1
[[ "${AKB_USERNAME}" =~ ^akb_ui_production_[0-9]{14}_[a-f0-9]{12}$ ]] \
	|| exit 1
[[ "${AKB_TOKEN_SHA256}" =~ ^[a-f0-9]{64}$ ]] || exit 1
[[ "${AKB_EXPECTED_BASE_URL}" =~ ^https://[A-Za-z0-9.-]+$ ]] || exit 1

require_wp() {
	local home_url site_url
	command -v wp >/dev/null 2>&1 || return 1
	wp --path="${AKB_WEBROOT}" core is-installed >/dev/null || return 1
	home_url="$(wp --path="${AKB_WEBROOT}" option get home)" || return 1
	site_url="$(wp --path="${AKB_WEBROOT}" option get siteurl)" || return 1
	home_url="${home_url%/}"
	site_url="${site_url%/}"
	[[ "${home_url}" == "${AKB_EXPECTED_BASE_URL}" ]] || return 1
	[[ "${site_url}" == "${AKB_EXPECTED_BASE_URL}" ]] || return 1
}

authored_rows() {
	local user_id="$1" prefix count
	[[ "${user_id}" =~ ^[1-9][0-9]*$ ]] || return 1
	prefix="$(wp --path="${AKB_WEBROOT}" db prefix)" || return 1
	[[ "${prefix}" =~ ^[A-Za-z0-9_]+$ ]] || return 1
	count="$(wp --path="${AKB_WEBROOT}" db query \
		"SELECT COUNT(*) FROM \`${prefix}posts\` WHERE post_author = ${user_id};" \
		--skip-column-names)" || return 1
	count="${count//$'\r'/}"
	count="${count//$'\n'/}"
	[[ "${count}" =~ ^[0-9]+$ ]] || return 1
	printf '%s' "${count}"
}

exact_user_id() {
	local user_id login
	user_id="$(wp --path="${AKB_WEBROOT}" user get "${AKB_USERNAME}" --field=ID)" \
		|| return 1
	[[ "${user_id}" =~ ^[1-9][0-9]*$ ]] || return 1
	login="$(wp --path="${AKB_WEBROOT}" user get "${user_id}" --field=user_login)" \
		|| return 1
	[[ "${login}" == "${AKB_USERNAME}" ]] || return 1
	printf '%s' "${user_id}"
}

assert_exact_marker_and_role() {
	local user_id="$1" marker roles
	marker="$(wp --path="${AKB_WEBROOT}" user meta get \
		"${user_id}" _akibara_ui_temp_admin_token_sha256)" || return 1
	[[ "${marker}" == "${AKB_TOKEN_SHA256}" ]] || return 1
	roles="$(wp --path="${AKB_WEBROOT}" user get "${user_id}" --field=roles)" \
		|| return 1
	roles=" ${roles//$'\n'/ } "
	[[ "${roles}" == *" administrator "* ]] || return 1
}

safe_reassign_user_id() {
	local temporary_id="$1" candidates old_ifs candidate login roles
	candidates="$(wp --path="${AKB_WEBROOT}" user list \
		--role=administrator --orderby=ID --order=ASC --field=ID --format=ids)" \
		|| return 1
	old_ifs="${IFS}"
	IFS=' '
	for candidate in ${candidates}; do
		IFS="${old_ifs}"
		[[ "${candidate}" =~ ^[1-9][0-9]*$ ]] || return 1
		if [[ "${candidate}" != "${temporary_id}" ]]; then
			login="$(wp --path="${AKB_WEBROOT}" user get \
				"${candidate}" --field=user_login)" || return 1
			if [[ "${login}" == akb_ui_* ]]; then
				IFS=' '
				continue
			fi
			roles="$(wp --path="${AKB_WEBROOT}" user get \
				"${candidate}" --field=roles)" || return 1
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
REMOTE
	printf '%s' "${body}"
}

remote_create_script() {
	local username="$1" password="$2" token_sha256="$3" common body
	common="$(remote_common_body)"
	read -r -d '' body <<'REMOTE' || true

[[ "${AKB_PASSWORD}" =~ ^[a-f0-9]{64}$ ]] || exit 1
require_wp || exit 1
if wp --path="${AKB_WEBROOT}" user get "${AKB_USERNAME}" >/dev/null 2>&1; then
	exit 1
fi

created_id=""
create_attempted=0
cleanup_create_failure() {
	local status=$? candidate_id current_login current_email marker rows
	trap - EXIT HUP INT TERM
	if ((status != 0 && create_attempted == 1)); then
		candidate_id="${created_id}"
		if [[ ! "${candidate_id}" =~ ^[1-9][0-9]*$ ]]; then
			candidate_id="$(wp --path="${AKB_WEBROOT}" user get \
				"${AKB_USERNAME}" --field=ID 2>/dev/null || true)"
		fi
		if [[ "${candidate_id}" =~ ^[1-9][0-9]*$ ]]; then
			current_login="$(wp --path="${AKB_WEBROOT}" user get \
				"${candidate_id}" --field=user_login 2>/dev/null || true)"
			current_email="$(wp --path="${AKB_WEBROOT}" user get \
				"${candidate_id}" --field=user_email 2>/dev/null || true)"
			marker="$(wp --path="${AKB_WEBROOT}" user meta get \
				"${candidate_id}" _akibara_ui_temp_admin_token_sha256 2>/dev/null || true)"
			rows="$(authored_rows "${candidate_id}" 2>/dev/null || printf 'unsafe')"
			if [[ "${current_login}" == "${AKB_USERNAME}"
				&& "${current_email}" == "${AKB_USERNAME}@akibara.invalid"
				&& ( -z "${marker}" || "${marker}" == "${AKB_TOKEN_SHA256}" )
				&& "${rows}" == "0" ]]; then
				wp --path="${AKB_WEBROOT}" user delete \
					"${candidate_id}" --yes >/dev/null 2>&1 || true
			fi
		fi
	fi
	exit "${status}"
}
trap cleanup_create_failure EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

create_attempted=1
created_id="$(
	printf '%s\n' "${AKB_PASSWORD}" |
		wp --path="${AKB_WEBROOT}" user create \
			"${AKB_USERNAME}" "${AKB_USERNAME}@akibara.invalid" \
			--role=administrator --prompt=user_pass --porcelain --quiet |
		awk '/^[0-9]+\r?$/{ gsub(/\r/, ""); id=$0 } END { if (id != "") print id }'
)"
[[ "${created_id}" =~ ^[1-9][0-9]*$ ]] || exit 1
wp --path="${AKB_WEBROOT}" user meta update \
	"${created_id}" _akibara_ui_temp_admin_token_sha256 "${AKB_TOKEN_SHA256}" >/dev/null \
	|| exit 1
[[ "$(exact_user_id)" == "${created_id}" ]] || exit 1
assert_exact_marker_and_role "${created_id}" || exit 1
[[ "$(authored_rows "${created_id}")" == "0" ]] || exit 1

trap - EXIT HUP INT TERM
printf 'CREATED\t%s\t%s\n' "${AKB_USERNAME}" "${created_id}"
REMOTE
	printf 'AKB_USERNAME=%q\nAKB_PASSWORD=%q\nAKB_TOKEN_SHA256=%q\nAKB_EXPECTED_BASE_URL=%q\n%s\n%s\n' \
		"${username}" "${password}" "${token_sha256}" "${AKB_BASE_URL}" "${common}" "${body}"
}

remote_status_script() {
	local username="$1" token_sha256="$2" common body
	common="$(remote_common_body)"
	read -r -d '' body <<'REMOTE' || true

require_wp || exit 1
user_id="$(exact_user_id)" || exit 1
assert_exact_marker_and_role "${user_id}" || exit 1
rows="$(authored_rows "${user_id}")" || exit 1
printf 'STATUS\tACTIVE\t%s\t%s\t%s\n' "${AKB_USERNAME}" "${user_id}" "${rows}"
REMOTE
	printf 'AKB_USERNAME=%q\nAKB_TOKEN_SHA256=%q\nAKB_EXPECTED_BASE_URL=%q\n%s\n%s\n' \
		"${username}" "${token_sha256}" "${AKB_BASE_URL}" "${common}" "${body}"
}

remote_delete_script() {
	local username="$1" token_sha256="$2" common body
	common="$(remote_common_body)"
	read -r -d '' body <<'REMOTE' || true

require_wp || exit 1
user_id="$(exact_user_id)" || exit 1
assert_exact_marker_and_role "${user_id}" || exit 1
[[ "$(authored_rows "${user_id}")" == "0" ]] || exit 1
wp --path="${AKB_WEBROOT}" user session destroy "${user_id}" --all >/dev/null \
	|| exit 1
[[ "$(authored_rows "${user_id}")" == "0" ]] || exit 1
reassign_id="$(safe_reassign_user_id "${user_id}")" || exit 1
wp --path="${AKB_WEBROOT}" user delete "${user_id}" \
	--reassign="${reassign_id}" --yes >/dev/null || exit 1
if wp --path="${AKB_WEBROOT}" user get "${AKB_USERNAME}" >/dev/null 2>&1; then
	exit 1
fi
[[ "$(authored_rows "${user_id}")" == "0" ]] || exit 1
printf 'DELETED\t%s\t%s\n' "${AKB_USERNAME}" "${user_id}"
REMOTE
	printf 'AKB_USERNAME=%q\nAKB_TOKEN_SHA256=%q\nAKB_EXPECTED_BASE_URL=%q\n%s\n%s\n' \
		"${username}" "${token_sha256}" "${AKB_BASE_URL}" "${common}" "${body}"
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
	local env_name="$1" credentials_file="$2" created_at credential_stamp
	local username password token token_sha256 output receipt_id
	require_command openssl
	require_command shasum
	require_command ssh
	[[ ! -e "${credentials_file}" && ! -L "${credentials_file}" ]] \
		|| die "Credentials destination already exists"
	credential_stamp="$(date -u +%Y%m%d%H%M%S)"
	created_at="${credential_stamp:0:8}T${credential_stamp:8:6}Z"
	username="akb_ui_${env_name}_${credential_stamp}_$(openssl rand -hex 6)"
	password="$(openssl rand -hex 32)"
	token="$(openssl rand -hex 32)"
	token_sha256="$(sha256_text "${token}")"
	write_credentials \
		"${credentials_file}" "${env_name}" "${username}" "${password}" "${token}" "${created_at}"

	if ! output="$(ssh_stdin "$(remote_create_script \
		"${username}" "${password}" "${token_sha256}")")"; then
		note "The local 0600 credentials file was retained for status/delete recovery:"
		note "${credentials_file}"
		die "Remote temporary administrator creation failed"
	fi
	if ! receipt_id="$(parse_three_field_receipt "CREATED" "${username}" "${output}")"; then
		die "Unexpected remote creation receipt; credentials retained for recovery"
	fi
	note "PASS: temporary UI administrator created for ${env_name}"
	note "Login: ${AKB_BASE_URL}/wp-admin/"
	note "Credentials (local 0600): ${credentials_file}"
}

status_admin() {
	local token_sha256 output authored_count
	require_command shasum
	require_command ssh
	token_sha256="$(sha256_text "${CRED_TOKEN}")"
	output="$(ssh_stdin "$(remote_status_script "${CRED_USERNAME}" "${token_sha256}")")"
	if ! authored_count="$(parse_status_receipt "${CRED_USERNAME}" "${output}")"; then
		die "Unexpected remote status receipt"
	fi
	[[ "${authored_count}" == "0" ]] \
		|| die "Temporary administrator authored content; UI lifecycle is unsafe"
	note "PASS: exact marked administrator is active with zero authored rows"
}

delete_admin() {
	local credentials_file="$1" token_sha256 output receipt_id
	require_command shasum
	require_command ssh
	token_sha256="$(sha256_text "${CRED_TOKEN}")"
	output="$(ssh_stdin "$(remote_delete_script "${CRED_USERNAME}" "${token_sha256}")")"
	if ! receipt_id="$(parse_three_field_receipt "DELETED" "${CRED_USERNAME}" "${output}")"; then
		die "Unexpected remote deletion receipt; credentials retained"
	fi
	rm -f -- "${credentials_file}"
	[[ ! -e "${credentials_file}" && ! -L "${credentials_file}" ]] \
		|| die "Remote account is deleted but local credentials could not be removed"
	note "PASS: exact marked administrator deleted; local credentials removed"
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

env_name=""
config_file=""
credentials_file=""
while (($# > 0)); do
	case "$1" in
		--env)
			(($# >= 2)) || die "--env requires a value"
			env_name="$2"
			shift 2
			;;
		--config)
			(($# >= 2)) || die "--config requires a value"
			config_file="$2"
			shift 2
			;;
		--credentials)
			(($# >= 2)) || die "--credentials requires a value"
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

[[ -n "${env_name}" && -n "${config_file}" && -n "${credentials_file}" ]] \
	|| die "${command_name} requires --env, --config, and --credentials"
[[ "${env_name}" == "production" ]] \
	|| die "Environment must be production"

config_file="$(absolute_existing_path "${config_file}")"
akb_load_promotion_config "${config_file}" || die "Promotion config rejected"
akb_select_promotion_environment "${env_name}" || die "Environment config rejected"

if [[ "${command_name}" == "create" ]]; then
	credentials_file="$(absolute_new_path "${credentials_file}")"
	create_admin "${env_name}" "${credentials_file}"
else
	credentials_file="$(absolute_existing_path "${credentials_file}")"
	load_credentials "${credentials_file}"
	assert_credentials_bound_to_target "${env_name}"
	if [[ "${command_name}" == "status" ]]; then
		status_admin
	else
		delete_admin "${credentials_file}"
	fi
fi
