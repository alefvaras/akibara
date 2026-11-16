#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
subject="${script_dir}/akibara-ui-temp-admin.sh"
config_library="${script_dir}/lib/akibara-promotion-config.sh"
fixture_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-ui-admin-tests.XXXXXX")"
subject_bash="/bin/bash"

cleanup() {
	case "${fixture_root}" in
		*/akibara-ui-admin-tests.*) rm -rf -- "${fixture_root}" ;;
	esac
}
trap cleanup EXIT

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

expect_failure() {
	if "$@" >"${fixture_root}/unexpected.out" 2>"${fixture_root}/unexpected.err"; then
		fail "command unexpectedly succeeded: $*"
	fi
}

run_subject() {
	"${subject_bash}" "${subject}" "$@"
}

[[ -x "${subject_bash}" ]] || fail "required Bash interpreter is missing: ${subject_bash}"
"${subject_bash}" -n "${subject}"
bash -n "${config_library}"
grep -Fq 'source "${config_library}"' "${subject}"
grep -Fq -- '-o BatchMode=yes' "${subject}"
grep -Fq -- '-o StrictHostKeyChecking=yes' "${subject}"
grep -Fq -- '_akibara_ui_temp_admin_token_sha256' "${subject}"
grep -Fq -- 'SELECT COUNT(*) FROM' "${subject}"
if rg -n 'rank_math|rank-math|option (add|update|delete)|post (create|update|delete)' "${subject}"; then
	fail "helper contains Rank Math or content mutation commands"
fi

mkdir -p "${fixture_root}/bin" "${fixture_root}/state" "${fixture_root}/credentials"
export FAKE_WP_STATE="${fixture_root}/state"
export SSH_DECOY_LOG="${fixture_root}/ssh-argv.log"

config="${fixture_root}/promotion.conf"
cat > "${config}" <<'EOF'
PRODUCTION_HOST=deployer@akibara.test
PRODUCTION_WEBROOT=/home/u888022333/domains/akibara.cl/public_html
PRODUCTION_BASE_URL=https://akibara.cl
PRODUCTION_LOG_PATHS=/home/u888022333/domains/akibara.cl/public_html/error_log;/home/u888022333/domains/akibara.cl/public_html/wp-content/uploads/adbc_uploads_AkibaraProd_2026/debug.log
EOF
chmod 0600 "${config}"

cat > "${fixture_root}/bin/ssh" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ "$#" == 8 ]]
[[ "$1" == "-o" && "$2" == "BatchMode=yes" ]]
[[ "$3" == "-o" && "$4" == "StrictHostKeyChecking=yes" ]]
[[ "$5" == "-o" && "$6" == "ConnectTimeout=15" ]]
[[ "$7" == "deployer@akibara.test" ]]
[[ "$8" =~ ^AKB_WEBROOT=/home/[A-Za-z0-9._/-]+/public_html\ bash\ -s$ ]]
printf '%q ' "$@" >> "${SSH_DECOY_LOG}"
printf '\n' >> "${SSH_DECOY_LOG}"
if [[ -n "${FAKE_SSH_RECEIPT_SUFFIX:-}" ]]; then
	output="$(/bin/bash -c "$8")"
	status=$?
	printf '%s%s\n' "${output}" "${FAKE_SSH_RECEIPT_SUFFIX}"
	exit "${status}"
fi
exec /bin/bash -c "$8"
EOF
chmod +x "${fixture_root}/bin/ssh"

cat > "${fixture_root}/bin/wp" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
state="${FAKE_WP_STATE:?}"
[[ "${1:-}" == --path=* ]]
printf '%q ' "$@" >> "${state}/wp-argv.log"
printf '\n' >> "${state}/wp-argv.log"
shift

exists() {
	[[ -f "${state}/username" ]]
}

assert_user() {
	local requested="$1" id username
	exists || exit 1
	id="$(<"${state}/id")"
	username="$(<"${state}/username")"
	[[ "${requested}" == "${id}" || "${requested}" == "${username}" ]] || exit 1
}

case "${1:-} ${2:-}" in
	"core is-installed")
		exit 0
		;;
	"option get")
		if [[ "$3" == "home" && -n "${FAKE_WP_HOME_URL:-}" ]]; then
			printf '%s\n' "${FAKE_WP_HOME_URL}"
		elif [[ "$3" == "siteurl" && -n "${FAKE_WP_SITE_URL:-}" ]]; then
			printf '%s\n' "${FAKE_WP_SITE_URL}"
		else
			printf 'https://akibara.cl\n'
		fi
		;;
	"user create")
		exists && exit 1
		prompted_password=""
		prompt_seen=0
		for arg in "$@"; do
			[[ "${arg}" != --user_pass=* ]]
			[[ "${arg}" != "--prompt=user_pass" ]] || prompt_seen=1
		done
		[[ "${prompt_seen}" == 1 ]]
		IFS= read -r prompted_password
		[[ "${prompted_password}" =~ ^[a-f0-9]{64}$ ]]
		username="$3"
		printf '701\n' > "${state}/id"
		printf '%s\n' "${username}" > "${state}/username"
		printf '%s\n' "$4" > "${state}/email"
		printf 'administrator\n' > "${state}/roles"
		printf '0\n' > "${state}/post-count"
		if [[ "${FAKE_WP_CREATE_MODE:-}" == "malformed" ]]; then
			printf 'created-with-malformed-receipt\n'
			exit 0
		fi
		if [[ "${FAKE_WP_CREATE_MODE:-}" == "nonzero" ]]; then
			exit 43
		fi
		if [[ "${FAKE_WP_CREATE_MODE:-}" == "noisy-prompt" ]]; then
			printf '1/11 [--user_pass=<password>]: \r<%s\n' "${prompted_password}"
		fi
		printf '701\n'
		;;
	"user get")
		field=""
		for arg in "$@"; do
			case "${arg}" in --field=*) field="${arg#--field=}" ;; esac
		done
		if [[ "$3" == "1" ]]; then
			case "${field}" in
				ID) printf '1\n' ;;
				user_login) printf 'primary_admin\n' ;;
				user_email) printf 'admin@akibara.test\n' ;;
				roles) printf 'administrator\n' ;;
				"") printf 'fake primary user\n' ;;
				*) exit 2 ;;
			esac
			exit 0
		fi
		if [[ "$3" == "700" ]]; then
			case "${field}" in
				ID) printf '700\n' ;;
				user_login) printf 'akb_ui_production_20260724000000_000000000000\n' ;;
				user_email) printf 'older-temp@akibara.invalid\n' ;;
				roles) printf 'administrator\n' ;;
				"") printf 'fake older temporary user\n' ;;
				*) exit 2 ;;
			esac
			exit 0
		fi
		assert_user "$3"
		case "${field}" in
			ID) cat "${state}/id" ;;
			user_login) cat "${state}/username" ;;
			user_email) cat "${state}/email" ;;
			roles) cat "${state}/roles" ;;
			"") printf 'fake user\n' ;;
			*) exit 2 ;;
		esac
		;;
	"user list")
		if [[ "${FAKE_WP_ONLY_TEMP_ADMINS:-0}" == 1 ]]; then
			printf '700'
		else
			printf '1'
		fi
		if exists; then printf ' 701'; fi
		printf '\n'
		;;
	"user session")
		[[ "$3" == "destroy" ]]
		assert_user "$4"
		[[ "$5" == "--all" ]]
		printf 'Success\n'
		;;
	"user meta")
		action="$3"
		assert_user "$4"
		[[ "$5" == "_akibara_ui_temp_admin_token_sha256" ]]
		case "${action}" in
			update)
				[[ "${FAKE_WP_FAIL_META:-0}" != 1 ]] || exit 42
				printf '%s\n' "$6" > "${state}/token-sha256"
				printf 'Success\n'
				;;
			get)
				cat "${state}/token-sha256"
				;;
			*) exit 2 ;;
		esac
		;;
	"user delete")
		assert_user "$3"
		reassign_id=""
		for arg in "$@"; do
			case "${arg}" in --reassign=*) reassign_id="${arg#--reassign=}" ;; esac
		done
		if [[ -f "${state}/token-sha256" ]]; then
			[[ "${reassign_id}" == "1" ]]
		fi
		if [[ "${FAKE_WP_RACE_ON_DELETE:-0}" == 1 ]]; then
			printf '1\n' > "${state}/post-count"
			[[ "${reassign_id}" == "1" ]]
			printf '1\n' > "${state}/reassigned-count"
		fi
		printf '0\n' > "${state}/post-count"
		rm -f -- \
			"${state}/id" "${state}/username" "${state}/email" "${state}/roles" \
			"${state}/token-sha256"
		;;
	"db prefix")
		printf 'wp_\n'
		;;
	"db query")
		[[ "$3" == *'SELECT COUNT(*) FROM `wp_posts` WHERE post_author = 701;'* ]]
		if [[ -f "${state}/post-count" ]]; then
			cat "${state}/post-count"
		else
			printf '0\n'
		fi
		;;
	*)
		printf 'Unsupported fake WP command: %s\n' "$*" >&2
		exit 2
		;;
esac
EOF
chmod +x "${fixture_root}/bin/wp"

export PATH="${fixture_root}/bin:${PATH}"
credentials="${fixture_root}/credentials/production.credentials"

export FAKE_WP_CREATE_MODE=noisy-prompt
run_subject create \
	--env production --config "${config}" --credentials "${credentials}" \
	>"${fixture_root}/create.out"
unset FAKE_WP_CREATE_MODE
grep -Fq 'PASS: temporary UI administrator created for production' "${fixture_root}/create.out"
[[ -f "${credentials}" && ! -L "${credentials}" ]]
[[ "$(stat -f '%Lp' "${credentials}" 2>/dev/null || stat -c '%a' "${credentials}")" == 600 ]]
[[ "$(<"${fixture_root}/state/username")" == akb_ui_production_* ]]

username="$(awk -F= '$1 == "AKB_UI_ADMIN_USERNAME" {print substr($0, index($0, "=") + 1)}' "${credentials}")"
password="$(awk -F= '$1 == "AKB_UI_ADMIN_PASSWORD" {print substr($0, index($0, "=") + 1)}' "${credentials}")"
token="$(awk -F= '$1 == "AKB_UI_ADMIN_TOKEN" {print substr($0, index($0, "=") + 1)}' "${credentials}")"
token_sha256="$(printf '%s' "${token}" | shasum -a 256 | awk '{print $1}')"
[[ "${username}" =~ ^akb_ui_production_[0-9]{14}_[a-f0-9]{12}$ ]]
[[ "${password}" =~ ^[a-f0-9]{64}$ ]]
[[ "${token}" =~ ^[a-f0-9]{64}$ ]]
[[ "$(<"${fixture_root}/state/token-sha256")" == "${token_sha256}" ]]
[[ ! -e "${fixture_root}/state/token" ]] || fail "raw token was persisted remotely"
if grep -Fq "${password}" "${fixture_root}/create.out"; then
	fail "password leaked from noisy WP-CLI prompt output"
fi

if grep -Fq "${password}" "${SSH_DECOY_LOG}" || grep -Fq "${token}" "${SSH_DECOY_LOG}"; then
	fail "secret leaked into SSH arguments"
fi
if grep -Fq "${password}" "${fixture_root}/state/wp-argv.log"; then
	fail "password leaked into WP-CLI arguments"
fi

run_subject status \
	--env production --config "${config}" --credentials "${credentials}" \
	>"${fixture_root}/status.out"
grep -Fq 'PASS: exact marked administrator is active with zero authored rows' \
	"${fixture_root}/status.out"

export FAKE_SSH_RECEIPT_SUFFIX=unexpected-suffix
expect_failure run_subject status \
	--env production --config "${config}" --credentials "${credentials}"
unset FAKE_SSH_RECEIPT_SUFFIX

printf '%064d\n' 0 > "${fixture_root}/state/token-sha256"
expect_failure run_subject status \
	--env production --config "${config}" --credentials "${credentials}"
expect_failure run_subject delete \
	--env production --config "${config}" --credentials "${credentials}"
[[ -f "${credentials}" && -f "${fixture_root}/state/username" ]]
printf '%s\n' "${token_sha256}" > "${fixture_root}/state/token-sha256"

printf '1\n' > "${fixture_root}/state/post-count"
expect_failure run_subject status \
	--env production --config "${config}" --credentials "${credentials}"
expect_failure run_subject delete \
	--env production --config "${config}" --credentials "${credentials}"
[[ -f "${credentials}" && -f "${fixture_root}/state/username" ]]
printf '0\n' > "${fixture_root}/state/post-count"

export FAKE_WP_ONLY_TEMP_ADMINS=1
expect_failure run_subject delete \
	--env production --config "${config}" --credentials "${credentials}"
unset FAKE_WP_ONLY_TEMP_ADMINS
[[ -f "${credentials}" && -f "${fixture_root}/state/username" ]] \
	|| fail "delete without a durable reassign administrator changed state"

bad_mode="${fixture_root}/credentials/bad-mode.credentials"
cp "${credentials}" "${bad_mode}"
chmod 0644 "${bad_mode}"
calls_before="$(wc -l < "${SSH_DECOY_LOG}" | tr -d ' ')"
expect_failure run_subject status \
	--env production --config "${config}" --credentials "${bad_mode}"
calls_after="$(wc -l < "${SSH_DECOY_LOG}" | tr -d ' ')"
[[ "${calls_before}" == "${calls_after}" ]] || fail "SSH ran before credentials mode rejection"

duplicate_empty_credentials="${fixture_root}/credentials/duplicate-empty.credentials"
{
	printf 'AKB_UI_ADMIN_SCHEMA=\n'
	cat "${credentials}"
} > "${duplicate_empty_credentials}"
chmod 0600 "${duplicate_empty_credentials}"
calls_before="$(wc -l < "${SSH_DECOY_LOG}" | tr -d ' ')"
expect_failure run_subject status \
	--env production --config "${config}" --credentials "${duplicate_empty_credentials}"
calls_after="$(wc -l < "${SSH_DECOY_LOG}" | tr -d ' ')"
[[ "${calls_before}" == "${calls_after}" ]] \
	|| fail "SSH ran before duplicate credentials key rejection"

calls_before="$(wc -l < "${SSH_DECOY_LOG}" | tr -d ' ')"
expect_failure run_subject status \
	--env qa --config "${config}" --credentials "${credentials}"
calls_after="$(wc -l < "${SSH_DECOY_LOG}" | tr -d ' ')"
[[ "${calls_before}" == "${calls_after}" ]] \
	|| fail "SSH ran for the retired QA environment"

export FAKE_WP_RACE_ON_DELETE=1
run_subject delete \
	--env production --config "${config}" --credentials "${credentials}" \
	>"${fixture_root}/delete.out"
unset FAKE_WP_RACE_ON_DELETE
grep -Fq 'PASS: exact marked administrator deleted; local credentials removed' \
	"${fixture_root}/delete.out"
[[ ! -e "${credentials}" && ! -e "${fixture_root}/state/username" ]]
[[ "$(<"${fixture_root}/state/reassigned-count")" == "1" ]] \
	|| fail "delete race was not preserved through the safe reassign target"

production_credentials="${fixture_root}/credentials/production.credentials"
run_subject create \
	--env production --config "${config}" --credentials "${production_credentials}" \
	>"${fixture_root}/production-create.out"
grep -Fq 'PASS: temporary UI administrator created for production' \
	"${fixture_root}/production-create.out"
[[ "$(<"${fixture_root}/state/username")" == akb_ui_production_* ]]
run_subject status \
	--env production --config "${config}" --credentials "${production_credentials}" \
	>"${fixture_root}/production-status.out"
run_subject delete \
	--env production --config "${config}" --credentials "${production_credentials}" \
	>"${fixture_root}/production-delete.out"
[[ ! -e "${production_credentials}" && ! -e "${fixture_root}/state/username" ]]

malformed_receipt_credentials="${fixture_root}/credentials/malformed-receipt.credentials"
export FAKE_SSH_RECEIPT_SUFFIX=unexpected-suffix
expect_failure run_subject create \
	--env production --config "${config}" --credentials "${malformed_receipt_credentials}"
unset FAKE_SSH_RECEIPT_SUFFIX
[[ -f "${malformed_receipt_credentials}" && -f "${fixture_root}/state/username" ]]
run_subject status \
	--env production --config "${config}" --credentials "${malformed_receipt_credentials}" \
	>"${fixture_root}/malformed-receipt-status.out"
run_subject delete \
	--env production --config "${config}" --credentials "${malformed_receipt_credentials}" \
	>"${fixture_root}/malformed-receipt-delete.out"

unsafe_config="${fixture_root}/unsafe.conf"
sed 's#PRODUCTION_WEBROOT=/home/u888022333/domains/akibara.cl/public_html#PRODUCTION_WEBROOT=/#' \
	"${config}" > "${unsafe_config}"
chmod 0600 "${unsafe_config}"
expect_failure run_subject create \
	--env production --config "${unsafe_config}" \
	--credentials "${fixture_root}/credentials/unsafe.credentials"
[[ ! -e "${fixture_root}/credentials/unsafe.credentials" ]]

unknown_config="${fixture_root}/unknown.conf"
cp "${config}" "${unknown_config}"
printf 'EXTRA_HOST=attacker.invalid\n' >> "${unknown_config}"
chmod 0600 "${unknown_config}"
expect_failure run_subject create \
	--env production --config "${unknown_config}" \
	--credentials "${fixture_root}/credentials/unknown.credentials"

traversal_config="${fixture_root}/traversal.conf"
sed 's#PRODUCTION_WEBROOT=/home/u888022333/domains/akibara.cl/public_html#PRODUCTION_WEBROOT=/home/u888022333/domains/akibara.cl/public_html/../production#' \
	"${config}" > "${traversal_config}"
chmod 0600 "${traversal_config}"
expect_failure run_subject create \
	--env production --config "${traversal_config}" \
	--credentials "${fixture_root}/credentials/traversal.credentials"

other_uploads_config="${fixture_root}/other-uploads.conf"
sed 's#adbc_uploads_AkibaraProd_2026/debug.log#unrelated-plugin/debug.log#' \
	"${config}" > "${other_uploads_config}"
chmod 0600 "${other_uploads_config}"
expect_failure run_subject create \
	--env production --config "${other_uploads_config}" \
	--credentials "${fixture_root}/credentials/other-uploads.credentials"

short_adbc_config="${fixture_root}/short-adbc.conf"
sed 's#adbc_uploads_AkibaraProd_2026/debug.log#adbc_uploads_short/debug.log#' \
	"${config}" > "${short_adbc_config}"
chmod 0600 "${short_adbc_config}"
expect_failure run_subject create \
	--env production --config "${short_adbc_config}" \
	--credentials "${fixture_root}/credentials/short-adbc.credentials"

touch "${fixture_root}/credentials/existing.credentials"
expect_failure run_subject create \
	--env production --config "${config}" \
	--credentials "${fixture_root}/credentials/existing.credentials"

world_writable_config="${fixture_root}/world-writable.conf"
cp "${config}" "${world_writable_config}"
chmod 0666 "${world_writable_config}"
calls_before="$(wc -l < "${SSH_DECOY_LOG}" | tr -d ' ')"
expect_failure run_subject create \
	--env production --config "${world_writable_config}" \
	--credentials "${fixture_root}/credentials/world-writable.credentials"
calls_after="$(wc -l < "${SSH_DECOY_LOG}" | tr -d ' ')"
[[ "${calls_before}" == "${calls_after}" ]] \
	|| fail "SSH ran before world-writable config rejection"

failed_credentials="${fixture_root}/credentials/create-failure.credentials"
export FAKE_WP_FAIL_META=1
expect_failure run_subject create \
	--env production --config "${config}" --credentials "${failed_credentials}"
unset FAKE_WP_FAIL_META
[[ -f "${failed_credentials}" ]] \
	|| fail "recovery credentials were not retained after a failed create"
[[ ! -e "${fixture_root}/state/username" ]] \
	|| fail "failed creation did not clean its newly created remote account"
rm -f -- "${failed_credentials}"

for create_mode in malformed nonzero; do
	create_race_credentials="${fixture_root}/credentials/create-${create_mode}.credentials"
	export FAKE_WP_CREATE_MODE="${create_mode}"
	expect_failure run_subject create \
		--env production --config "${config}" --credentials "${create_race_credentials}"
	unset FAKE_WP_CREATE_MODE
	[[ -f "${create_race_credentials}" ]] \
		|| fail "recovery credentials missing for create mode ${create_mode}"
	[[ ! -e "${fixture_root}/state/username" ]] \
		|| fail "after-commit ${create_mode} create left an orphan administrator"
	rm -f -- "${create_race_credentials}"
done

for option_name in HOME SITE; do
	crossed_site_credentials="${fixture_root}/credentials/crossed-${option_name}.credentials"
	if [[ "${option_name}" == "HOME" ]]; then
		export FAKE_WP_HOME_URL=https://unexpected.akibara.test
	else
		export FAKE_WP_SITE_URL=https://unexpected.akibara.test
	fi
	expect_failure run_subject create \
		--env production --config "${config}" --credentials "${crossed_site_credentials}"
	unset FAKE_WP_HOME_URL FAKE_WP_SITE_URL
	[[ -f "${crossed_site_credentials}" ]] \
		|| fail "recovery credentials were not retained after ${option_name} rejection"
	[[ ! -e "${fixture_root}/state/username" ]] \
		|| fail "${option_name} identity mismatch created a remote account"
	rm -f -- "${crossed_site_credentials}"
done

if find "${fixture_root}/credentials" -name '.*.partial.*' -print -quit | grep -q .; then
	fail "local partial credential file remains"
fi

printf 'PASS: production-only UI temporary administrator lifecycle, retired-QA rejection, fail-closed guards, and SSH decoy\n'
