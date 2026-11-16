#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
subject="${script_dir}/akibara-local-ui-temp-admin.sh"
fixture_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-local-ui-admin-tests.XXXXXX")"
subject_bash="/bin/bash"

cleanup() {
	case "${fixture_root}" in
		*/akibara-local-ui-admin-tests.*) rm -rf -- "${fixture_root}" ;;
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

file_mode() {
	stat -f '%Lp' "$1" 2>/dev/null || stat -c '%a' "$1"
}

[[ -x "${subject_bash}" ]] || fail "required Bash interpreter is missing"
"${subject_bash}" -n "${subject}"
grep -Fq 'base_url="http://localhost:8080"' "${subject}"
grep -Fq 'compose run --rm --no-deps -T cli bash -s' "${subject}"
grep -Fq '_akibara_ui_temp_admin_token_sha256' "${subject}"
grep -Fq 'SELECT COUNT(*) FROM' "${subject}"
grep -Fq 'AKB_UI_ADMIN_SCHEMA=2' "${subject}"
if rg -n '\bssh\b|wp (post|option) (create|add|update|delete)|rank_math|rank-math' \
	"${subject}"; then
	fail "local helper contains a remote, content, option, or plugin mutation"
fi

mkdir -p "${fixture_root}/bin" "${fixture_root}/state" "${fixture_root}/credentials"
export FAKE_WP_STATE="${fixture_root}/state"
export DOCKER_DECOY_LOG="${fixture_root}/docker-argv.log"
export FAKE_DOCKER_PROGRAM_DIR="${fixture_root}/programs"
mkdir -p "${FAKE_DOCKER_PROGRAM_DIR}"

cat > "${fixture_root}/bin/docker" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
printf '%q ' "$@" >> "${DOCKER_DECOY_LOG}"
printf '\n' >> "${DOCKER_DECOY_LOG}"
[[ "${1:-}" == "compose" ]] || exit 91
shift
if [[ "${1:-}" == "version" ]]; then
	printf 'Docker Compose version v2.fake\n'
	exit 0
fi
[[ "${1:-}" == "--project-directory" && -n "${2:-}" ]]
shift 2
[[ "${1:-}" == "-f" && "${2:-}" == */compose.yaml ]]
shift 2
[[ "${1:-}" == "--profile" && "${2:-}" == "tools" ]]
shift 2
case "${1:-}" in
	config)
		[[ "${2:-}" == "--services" ]]
		printf 'db\nwordpress\ncli\n'
		;;
	ps)
		[[ "${2:-}" == "--status" && "${3:-}" == "running"
			&& "${4:-}" == "--services" ]]
		[[ "${FAKE_DOCKER_WORDPRESS_DOWN:-0}" != 1 ]] && printf 'wordpress\n'
		printf 'db\n'
		;;
	run)
		[[ "${2:-}" == "--rm" && "${3:-}" == "--no-deps"
			&& "${4:-}" == "-T" && "${5:-}" == "cli"
			&& "${6:-}" == "bash" && "${7:-}" == "-s" && "$#" == 7 ]]
		program="$(mktemp "${FAKE_DOCKER_PROGRAM_DIR}/program.XXXXXX")"
		chmod 0600 "${program}"
		trap 'rm -f -- "${program}"' EXIT
		IFS= read -r -d '' source || true
		printf '%s' "${source}" > "${program}"
		grep -vF '[[ "$(pwd -P)" == "/var/www/html" ]] || return 1' \
			"${program}" | /bin/bash -s
		;;
	*)
		exit 92
		;;
esac
EOF
chmod +x "${fixture_root}/bin/docker"

cat > "${fixture_root}/bin/wp" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
state="${FAKE_WP_STATE:?}"
printf '%q ' "$@" >> "${state}/wp-argv.log"
printf '\n' >> "${state}/wp-argv.log"
[[ "${1:-}" == "--skip-plugins" ]]
shift
[[ "${1:-}" == "--skip-themes" ]]
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
			printf 'http://localhost:8080\n'
		fi
		;;
	"user create")
		exists && exit 1
		prompt_seen=0
		for arg in "$@"; do
			[[ "${arg}" != --user_pass=* ]]
			[[ "${arg}" != "--prompt=user_pass" ]] || prompt_seen=1
		done
		[[ "${prompt_seen}" == 1 ]]
		IFS= read -r prompted_password
		[[ "${prompted_password}" =~ ^[a-f0-9]{64}$ ]]
		printf '801\n' > "${state}/id"
		printf '%s\n' "$3" > "${state}/username"
		printf '%s\n' "$4" > "${state}/email"
		printf 'administrator\n' > "${state}/roles"
		printf '0\n' > "${state}/post-count"
		if [[ "${FAKE_WP_CREATE_MODE:-}" == "malformed" ]]; then
			printf 'malformed\n'
			exit 0
		fi
		if [[ "${FAKE_WP_CREATE_MODE:-}" == "nonzero" ]]; then
			exit 43
		fi
		printf '801\n'
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
		if [[ "$3" == "800" ]]; then
			case "${field}" in
				ID) printf '800\n' ;;
				user_login) printf 'akb_ui_local_20260724000000_000000000000\n' ;;
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
			printf '800'
		else
			printf '1'
		fi
		exists && printf ' 801'
		printf '\n'
		;;
	"user session")
		[[ "$3" == "destroy" ]]
		assert_user "$4"
		[[ "$5" == "--all" ]]
		;;
	"user meta")
		assert_user "$4"
		[[ "$5" == "_akibara_ui_temp_admin_token_sha256" ]]
		case "$3" in
			update)
				[[ "${FAKE_WP_FAIL_META:-0}" != 1 ]] || exit 42
				printf '%s\n' "$6" > "${state}/token-sha256"
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
			"${state}/id" "${state}/username" "${state}/email" \
			"${state}/roles" "${state}/token-sha256"
		;;
	"db prefix")
		printf 'wp_\n'
		;;
	"db query")
		[[ "$3" == *'SELECT COUNT(*) FROM `wp_posts` WHERE post_author = 801;'* ]]
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
credentials="${fixture_root}/credentials/local.credentials"

expect_failure run_subject create --env qa --credentials "${credentials}"
expect_failure run_subject create --config /tmp/remote.conf --credentials "${credentials}"

run_subject create --credentials "${credentials}" >"${fixture_root}/create.out"
grep -Fq 'PASS: Docker-local temporary UI administrator created' \
	"${fixture_root}/create.out"
[[ -f "${credentials}" && ! -L "${credentials}" ]]
[[ "$(file_mode "${credentials}")" == "600" ]]
grep -Fq 'AKB_UI_ADMIN_SCHEMA=2' "${credentials}"
grep -Fq 'AKB_UI_ADMIN_ENV=local' "${credentials}"
grep -Fq 'AKB_UI_ADMIN_HOST=docker-compose:cli' "${credentials}"
grep -Fq 'AKB_UI_ADMIN_WEBROOT=/var/www/html' "${credentials}"
grep -Fq 'AKB_UI_ADMIN_BASE_URL=http://localhost:8080' "${credentials}"

username="$(awk -F= '$1 == "AKB_UI_ADMIN_USERNAME" {print $2}' "${credentials}")"
password="$(awk -F= '$1 == "AKB_UI_ADMIN_PASSWORD" {print $2}' "${credentials}")"
token="$(awk -F= '$1 == "AKB_UI_ADMIN_TOKEN" {print $2}' "${credentials}")"
token_sha256="$(printf '%s' "${token}" | shasum -a 256 | awk '{print $1}')"
[[ "${username}" =~ ^akb_ui_local_[0-9]{14}_[a-f0-9]{12}$ ]]
[[ "${password}" =~ ^[a-f0-9]{64}$ ]]
[[ "${token}" =~ ^[a-f0-9]{64}$ ]]
[[ "$(<"${fixture_root}/state/token-sha256")" == "${token_sha256}" ]]
if grep -Fq "${password}" "${DOCKER_DECOY_LOG}" \
	|| grep -Fq "${token}" "${DOCKER_DECOY_LOG}"; then
	fail "secret leaked into Docker Compose arguments"
fi
if grep -Fq "${password}" "${fixture_root}/state/wp-argv.log"; then
	fail "password leaked into WP-CLI arguments"
fi

run_subject status --credentials "${credentials}" >"${fixture_root}/status.out"
grep -Fq 'zero authored rows' "${fixture_root}/status.out"

printf '%064d\n' 0 > "${fixture_root}/state/token-sha256"
expect_failure run_subject status --credentials "${credentials}"
expect_failure run_subject delete --credentials "${credentials}"
[[ -f "${credentials}" && -f "${fixture_root}/state/username" ]]
printf '%s\n' "${token_sha256}" > "${fixture_root}/state/token-sha256"

printf '1\n' > "${fixture_root}/state/post-count"
expect_failure run_subject status --credentials "${credentials}"
expect_failure run_subject delete --credentials "${credentials}"
[[ -f "${credentials}" && -f "${fixture_root}/state/username" ]]
printf '0\n' > "${fixture_root}/state/post-count"

export FAKE_WP_ONLY_TEMP_ADMINS=1
expect_failure run_subject delete --credentials "${credentials}"
unset FAKE_WP_ONLY_TEMP_ADMINS
[[ -f "${credentials}" && -f "${fixture_root}/state/username" ]]

bad_mode="${fixture_root}/credentials/bad-mode.credentials"
cp "${credentials}" "${bad_mode}"
chmod 0644 "${bad_mode}"
expect_failure run_subject status --credentials "${bad_mode}"

export FAKE_DOCKER_WORDPRESS_DOWN=1
expect_failure run_subject status --credentials "${credentials}"
unset FAKE_DOCKER_WORDPRESS_DOWN

export FAKE_WP_RACE_ON_DELETE=1
run_subject delete --credentials "${credentials}" >"${fixture_root}/delete.out"
unset FAKE_WP_RACE_ON_DELETE
grep -Fq 'PASS: exact Docker-local administrator deleted; credentials removed' \
	"${fixture_root}/delete.out"
[[ ! -e "${credentials}" && ! -e "${fixture_root}/state/username" ]]
[[ "$(<"${fixture_root}/state/reassigned-count")" == "1" ]]

failed_credentials="${fixture_root}/credentials/create-failure.credentials"
export FAKE_WP_FAIL_META=1
expect_failure run_subject create --credentials "${failed_credentials}"
unset FAKE_WP_FAIL_META
[[ -f "${failed_credentials}" ]] \
	|| fail "recovery credentials were not retained after failed create"
[[ ! -e "${fixture_root}/state/username" ]] \
	|| fail "failed create left an orphan local administrator"
rm -f -- "${failed_credentials}"

for create_mode in malformed nonzero; do
	create_credentials="${fixture_root}/credentials/create-${create_mode}.credentials"
	export FAKE_WP_CREATE_MODE="${create_mode}"
	expect_failure run_subject create --credentials "${create_credentials}"
	unset FAKE_WP_CREATE_MODE
	[[ -f "${create_credentials}" ]] \
		|| fail "recovery credentials missing for ${create_mode}"
	[[ ! -e "${fixture_root}/state/username" ]] \
		|| fail "${create_mode} create left an orphan local administrator"
	rm -f -- "${create_credentials}"
done

if find "${fixture_root}/credentials" -name '.*.partial.*' -print -quit | grep -q .; then
	fail "local partial credential file remains"
fi

printf 'PASS: Docker-local UI administrator lifecycle, zero-author guard, and cleanup\n'
