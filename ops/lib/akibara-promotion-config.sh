#!/usr/bin/env bash

# Shared parser and validators for Akibara's promotion allowlist.
# This file is executable code and may be sourced by trusted local scripts.
# The configuration file itself is always parsed as data and is never sourced.

akb_config_fail() {
	printf 'ERROR: %s\n' "$*" >&2
	return 1
}

akb_config_file_mode() {
	local path="$1" mode
	if mode="$(stat -f '%Lp' "${path}" 2>/dev/null)"; then
		printf '%s' "${mode}"
	else
		stat -c '%a' "${path}"
	fi
}

akb_validate_host() {
	[[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*@[A-Za-z0-9][A-Za-z0-9.-]*$ ]] \
		|| {
			akb_config_fail "Unsafe SSH host allowlist value"
			return 1
		}
}

akb_validate_absolute_path() {
	local path="$1"
	[[ "${path}" =~ ^/[A-Za-z0-9._/-]+$ ]] || {
		akb_config_fail "Unsafe absolute path: ${path}"
		return 1
	}
	[[ "${path}" != *'/../'* && "${path}" != *'/./'* && "${path}" != *'//'*
		&& "${path}" != "/" && "${path}" != "/home" && "${path}" != "/tmp" ]] \
		|| {
			akb_config_fail "Broad or unresolved path rejected: ${path}"
			return 1
		}
}

akb_validate_webroot() {
	akb_validate_absolute_path "$1" || return 1
	[[ "$1" =~ ^/home/[A-Za-z0-9._-]+(/domains/[A-Za-z0-9.-]+)?/public_html$ ]] \
		|| {
			akb_config_fail "Production webroot must end exactly in /public_html"
			return 1
		}
}

akb_validate_base_url() {
	[[ "$1" =~ ^https://[A-Za-z0-9.-]+$ ]] || {
		akb_config_fail "Base URL must be a path-free HTTPS origin"
		return 1
	}
}

akb_validate_environment_targets() {
	[[ "${PRODUCTION_WEBROOT}" =~ /public_html$ ]] || {
		akb_config_fail "Production webroot must end exactly in /public_html"
		return 1
	}
	[[ "${PRODUCTION_BASE_URL}" == "https://akibara.cl" ]] || {
		akb_config_fail "Production base URL must be https://akibara.cl"
		return 1
	}
}

akb_validate_log_paths() {
	local raw="$1" webroot="$2" path site_public_html account_root
	local adbc_prefix token
	local -a paths

	[[ -n "${raw}" && "${raw}" != ';'* && "${raw}" != *';'
		&& "${raw}" != *';;'* ]] || {
		akb_config_fail "At least one explicit log path is required"
		return 1
	}
	akb_validate_webroot "${webroot}" || return 1
	site_public_html="${webroot}"
	account_root="${site_public_html%/public_html}"
	adbc_prefix="${webroot}/wp-content/uploads/adbc_uploads_"
	IFS=';' read -r -a paths <<< "${raw}"
	((${#paths[@]} > 0)) || {
		akb_config_fail "At least one explicit log path is required"
		return 1
	}

	for path in "${paths[@]}"; do
		akb_validate_absolute_path "${path}" || return 1
		case "${path}" in
			"${webroot}/error_log"|"${webroot}/wp-content/debug.log")
				;;
			"${adbc_prefix}"*/debug.log)
				token="${path#"${adbc_prefix}"}"
				token="${token%/debug.log}"
				[[ "${token}" =~ ^[A-Za-z0-9_]{8,96}$ ]] || {
					akb_config_fail "Unsafe ADBC debug log token: ${path}"
					return 1
				}
				;;
			"${account_root}/logs/"*.log|"${account_root}/logs/error_log")
				;;
			*)
				akb_config_fail "Log path is outside the environment log allowlist: ${path}"
				return 1
				;;
		esac
	done
}

akb_load_promotion_config() {
	local config="$1" line key value mode line_number=0 seen_keys="|"
	local -a required_keys=(
		PRODUCTION_HOST
		PRODUCTION_WEBROOT
		PRODUCTION_BASE_URL
		PRODUCTION_LOG_PATHS
	)

	[[ -f "${config}" && ! -L "${config}" ]] || {
		akb_config_fail "Config must be a regular non-symlink file"
		return 1
	}
	mode="$(akb_config_file_mode "${config}")" || {
		akb_config_fail "Unable to inspect config file mode"
		return 1
	}
	[[ "${mode}" == "600" ]] || {
		akb_config_fail "Config file mode must be exactly 0600"
		return 1
	}

	for key in "${required_keys[@]}"; do
		printf -v "${key}" '%s' ""
	done

	while IFS= read -r line || [[ -n "${line}" ]]; do
		line_number=$((line_number + 1))
		[[ -z "${line}" || "${line}" == \#* ]] && continue
		[[ "${line}" == *=* ]] || {
			akb_config_fail "Invalid config line ${line_number}"
			return 1
		}
		key="${line%%=*}"
		value="${line#*=}"
		case "${key}" in
			PRODUCTION_HOST|PRODUCTION_WEBROOT|PRODUCTION_BASE_URL|PRODUCTION_LOG_PATHS)
				;;
			*)
				akb_config_fail "Unknown config key at line ${line_number}: ${key}"
				return 1
				;;
		esac
		[[ "${seen_keys}" != *"|${key}|"* ]] || {
			akb_config_fail "Duplicate config key: ${key}"
			return 1
		}
		seen_keys+="${key}|"
		printf -v "${key}" '%s' "${value}"
	done < "${config}"

	for key in "${required_keys[@]}"; do
		[[ -n "${!key}" ]] || {
			akb_config_fail "Missing config key: ${key}"
			return 1
		}
		[[ "${!key}" != *example* && "${!key}" != *invalid* && "${!key}" != *replace* ]] \
			|| {
				akb_config_fail "Placeholder value rejected for ${key}"
				return 1
			}
	done

	akb_validate_host "${PRODUCTION_HOST}" || return 1
	akb_validate_webroot "${PRODUCTION_WEBROOT}" || return 1
	akb_validate_base_url "${PRODUCTION_BASE_URL}" || return 1
	akb_validate_log_paths "${PRODUCTION_LOG_PATHS}" "${PRODUCTION_WEBROOT}" || return 1
	akb_validate_environment_targets || return 1
}

akb_select_promotion_environment() {
	local env_name="$1"

	[[ -n "${PRODUCTION_HOST:-}"
		&& -n "${PRODUCTION_WEBROOT:-}" && -n "${PRODUCTION_BASE_URL:-}"
		&& -n "${PRODUCTION_LOG_PATHS:-}" ]] || {
		akb_config_fail "Promotion config must be loaded before selecting an environment"
		return 1
	}

	case "${env_name}" in
		production)
			AKB_HOST="${PRODUCTION_HOST}"
			AKB_WEBROOT="${PRODUCTION_WEBROOT}"
			AKB_BASE_URL="${PRODUCTION_BASE_URL}"
			AKB_LOG_PATHS="${PRODUCTION_LOG_PATHS}"
			;;
		*)
			akb_config_fail "Environment must be production"
			return 1
			;;
	esac

	akb_validate_host "${AKB_HOST}" || return 1
	akb_validate_webroot "${AKB_WEBROOT}" || return 1
	akb_validate_base_url "${AKB_BASE_URL}" || return 1
	akb_validate_log_paths "${AKB_LOG_PATHS}" "${AKB_WEBROOT}" || return 1
	akb_validate_environment_targets || return 1
}
