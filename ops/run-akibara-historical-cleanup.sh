#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
config_library="${script_dir}/lib/akibara-promotion-config.sh"
promotion_pipeline="${script_dir}/akibara-code-promotion.sh"
release_contract="${script_dir}/lib/akibara-release-contract.sh"

[[ -f "${release_contract}" && ! -L "${release_contract}" ]] \
	|| { printf 'ERROR: Missing regular release contract\n' >&2; exit 1; }
# shellcheck source=ops/lib/akibara-release-contract.sh
source "${release_contract}"

workflow=""
env_name=""
config_file=""
output_file=""
commercial_before=""
confirmation=""
apply=0

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

usage() {
	cat <<'EOF'
Usage:
  ops/run-akibara-historical-cleanup.sh \
    --workflow rankmath-options|woocommerce-sessions \
    --env docker|production \
    --output .local-reports/.../evidence.json \
    [--config /absolute/promotion.conf]

  ops/run-akibara-historical-cleanup.sh \
    --workflow rankmath-options|woocommerce-sessions \
    --env docker|production \
    --output .local-reports/.../evidence.json \
    [--config /absolute/promotion.conf] \
    --commercial-before /absolute/protected-before.tsv \
    --apply --confirm WORKFLOW-v1:ENV

The default is a read-only audit. Apply mode requires the canonical integral
commerce fingerprint captured before the mutation. The resulting PASS report
remains explicitly unsealed until an after fingerprint is captured and the
promotion pipeline's `seal-historical` gate succeeds.

No PHP file, report, dump, backup, or temporary artifact is created remotely:
the selected payload is streamed to `wp eval-file -`. Evidence remains local,
either under `.local-reports` or in the exact code-promotion evidence directory
under `.local-backups/code-promotion/production/.../evidence`.
EOF
}

sha256_file() {
	shasum -a 256 "$1" | awk '{print $1}'
}

absolute_existing_file() {
	local input="$1" absolute
	[[ -f "${input}" && ! -L "${input}" ]] \
		|| die "Expected a regular non-symlink file: ${input}"
	absolute="$(cd -- "$(dirname -- "${input}")" && pwd -P)/$(basename -- "${input}")"
	[[ -f "${absolute}" && ! -L "${absolute}" ]] \
		|| die "Could not resolve regular file: ${input}"
	printf '%s' "${absolute}"
}

prepare_output_path() {
	local relative="$1" parent parent_absolute allowed_root
	if [[ "${relative}" =~ ^\.local-reports/[A-Za-z0-9._/-]+\.json$ ]]; then
		allowed_root="${repo_root}/.local-reports"
	elif [[ "${relative}" =~ ^\.local-backups/code-promotion/production/[A-Za-z0-9._/-]+/evidence/[A-Za-z0-9._/-]+\.json$ ]]; then
		allowed_root="${repo_root}/.local-backups/code-promotion"
	else
		die "Output must stay in a local report or exact promotion evidence directory"
	fi
	[[ "${relative}" != *'..'* && "${relative}" != *'//'*
		&& "${relative}" != *'/./'* ]] \
		|| die "Output path contains unresolved segments"
	parent="$(dirname -- "${repo_root}/${relative}")"
	mkdir -p -- "${parent}"
	[[ -d "${parent}" && ! -L "${parent}" ]] \
		|| die "Output parent must be a non-symlink directory"
	parent_absolute="$(cd -- "${parent}" && pwd -P)"
	allowed_root="$(cd -- "${allowed_root}" && pwd -P)"
	[[ "${parent_absolute}" == "${allowed_root}" || "${parent_absolute}" == "${allowed_root}/"* ]] \
		|| die "Output escaped its local evidence tree"
	[[ ! -e "${repo_root}/${relative}" && ! -e "${repo_root}/${relative}.sha256" ]] \
		|| die "Refusing to overwrite cleanup evidence"
	printf '%s' "${repo_root}/${relative}"
}

validate_integral_fingerprint() {
	local fingerprint="$1"
	(
		export AKB_PROMOTION_LIBRARY_ONLY=1
		# shellcheck source=ops/akibara-code-promotion.sh
		source "${promotion_pipeline}"
		validate_fingerprint_snapshot "${fingerprint}"
	)
}

validate_report() {
	local report="$1" expected_gate="$2" expected_mode="$3" expected_env="$4"
	local expected_payload_sha="$5" expected_mutation_id="$6" expected_before_sha="$7"

	jq -e \
		--arg gate "${expected_gate}" \
		--arg mode "${expected_mode}" \
		--arg environment "${expected_env}" \
		--arg payload_sha "${expected_payload_sha}" \
		--arg mutation_id "${expected_mutation_id}" \
		--arg before_sha "${expected_before_sha}" \
		'
		  type == "object"
		  and .schema_version == 2
		  and .gate == $gate
		  and (.status == "PASS" or .status == "FAIL")
		  and .mode == $mode
		  and .target_environment == $environment
		  and .mutation_id == $mutation_id
		  and (.started_at_utc | type == "string")
		  and (.completed_at_utc | type == "string")
		  and (
		    if .status == "FAIL" then
		      (.error_code | type == "string" and length > 0)
		    elif $gate == "rankmath_obsolete_options_cleanup" then
		      .payload_sha256 == $payload_sha
		      and .rank_math_version == "1.0.274.1"
		      and (.exact_option_names == [
		        "rank_math_csv_import_redirections_status",
		        "rank_math_gtin_products",
		        "rank_math_regenerate_state",
		        "rank_math_video_posts"
		      ])
		      and (.scheduled_references_before.total == 0)
		      and (.scheduled_references_after.total == 0)
		      and (
		        if $mode == "apply" then
		          .commercial_before_sha256 == $before_sha
		          and .external_commercial_fingerprint_required == true
		          and .seal_status == "MUTATION_COMPLETE_SEAL_PENDING"
		          and .after.state == "already_clean"
		          and (.deleted_count == 0 or .deleted_count == 4)
		        else
		          .commercial_before_sha256 == null
		          and .external_commercial_fingerprint_required == false
		          and .seal_status == "NOT_APPLICABLE_READ_ONLY"
		          and .deleted_count == 0
		        end
		      )
		    else
		      .payload_sha256 == $payload_sha
		      and .cleanup_api == "wc_cleanup_session_data"
		      and .active_horizon_seconds == 300
		      and (
		        if $mode == "apply" then
		          .commercial_before_sha256 == $before_sha
		          and .external_commercial_fingerprint_required == true
		          and .seal_status == "MUTATION_COMPLETE_SEAL_PENDING"
		          and .apply_precondition.eligible == true
		          and .after_first.expired == 0
		          and .after_second.expired == 0
		          and .after_first.all_fingerprint == .after_second.all_fingerprint
		        else
		          .commercial_before_sha256 == null
		          and .external_commercial_fingerprint_required == false
		          and .seal_status == "NOT_APPLICABLE_READ_ONLY"
		          and .after_first == null
		          and .after_second == null
		          and .deleted_count == 0
		        end
		      )
		    end
		  )
		' "${report}" >/dev/null
}

execute_payload() {
	local payload="$1" target_host="$2" target_webroot="$3" expected_site="$4"
	local payload_sha="$5" mutation_id="$6" before_sha="$7" partial="$8"
	local remote_command
	local promotion_target promotion_run

	promotion_target="${target_host}:${target_webroot}"
	promotion_run="docker-local"
	if [[ "${env_name}" != "docker" ]]; then
		promotion_run="$(basename -- "$(dirname -- "$(dirname -- "${output_absolute}")")")"
	fi

	if [[ "${env_name}" == "docker" ]]; then
		docker compose run --rm --no-deps -T \
			-e "AKB_APPLY=${apply}" \
			-e "AKB_EXPECTED_SITE_URL=${expected_site}" \
			-e "AKB_TARGET_ENVIRONMENT=${env_name}" \
			-e "AKB_PROMOTION_TARGET=local-compose:/var/www/html" \
			-e "AKB_PROMOTION_RUN=${promotion_run}" \
			-e "AKB_RELEASE_SET=${release_set}" \
			-e "AKB_RELEASE_DIGEST=${expected_release_digest}" \
			-e "AKB_PAYLOAD_SHA256=${payload_sha}" \
			-e "AKB_MUTATION_ID=${mutation_id}" \
			-e "AKB_COMMERCIAL_BEFORE_SHA256=${before_sha}" \
			cli wp --path=/var/www/html eval-file - < "${payload}" > "${partial}"
		return
	fi

	printf -v remote_command \
		'AKB_APPLY=%q AKB_EXPECTED_SITE_URL=%q AKB_TARGET_ENVIRONMENT=%q AKB_PROMOTION_TARGET=%q AKB_PROMOTION_RUN=%q AKB_RELEASE_SET=%q AKB_RELEASE_DIGEST=%q AKB_PAYLOAD_SHA256=%q AKB_MUTATION_ID=%q AKB_COMMERCIAL_BEFORE_SHA256=%q wp --path=%q eval-file -' \
		"${apply}" "${expected_site}" "${env_name}" "${promotion_target}" \
		"${promotion_run}" "${release_set}" "${expected_release_digest}" "${payload_sha}" \
		"${mutation_id}" "${before_sha}" "${target_webroot}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${target_host}" "${remote_command}" < "${payload}" > "${partial}"
}

while (($#)); do
	case "$1" in
		--workflow)
			(($# >= 2)) || die "--workflow requires a value"
			workflow="$2"
			shift 2
			;;
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
		--output)
			(($# >= 2)) || die "--output requires a value"
			output_file="$2"
			shift 2
			;;
		--commercial-before)
			(($# >= 2)) || die "--commercial-before requires a value"
			commercial_before="$2"
			shift 2
			;;
		--apply)
			apply=1
			shift
			;;
		--confirm)
			(($# >= 2)) || die "--confirm requires a value"
			confirmation="$2"
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			die "Unknown argument: $1"
			;;
	esac
done

[[ "${workflow}" == "rankmath-options" || "${workflow}" == "woocommerce-sessions" ]] \
	|| die "Workflow must be rankmath-options or woocommerce-sessions"
[[ "${env_name}" == "docker" || "${env_name}" == "production" ]] \
	|| die "Environment must be docker or production"
[[ -n "${output_file}" ]] || die "--output is required"

payload="${script_dir}/rankmath-obsolete-options-cleanup.php"
gate="rankmath_obsolete_options_cleanup"
if [[ "${workflow}" == "woocommerce-sessions" ]]; then
	payload="${script_dir}/woocommerce-expired-sessions-cleanup.php"
	gate="woocommerce_expired_sessions_cleanup"
fi
payload="$(absolute_existing_file "${payload}")"
output_absolute="$(prepare_output_path "${output_file}")"

target_host=""
target_webroot=""
expected_site="http://localhost:8080"
if [[ "${env_name}" == "docker" ]]; then
	[[ -z "${config_file}" ]] || die "Docker rejects --config"
else
	[[ -n "${config_file}" ]] || die "Production requires --config"
	[[ -f "${config_library}" && ! -L "${config_library}" ]] \
		|| die "Missing regular promotion config library"
	# shellcheck source=ops/lib/akibara-promotion-config.sh
	source "${config_library}"
	akb_load_promotion_config "$(absolute_existing_file "${config_file}")" \
		|| die "Promotion configuration rejected"
	akb_select_promotion_environment "${env_name}" \
		|| die "Promotion environment rejected"
	target_host="${AKB_HOST}"
	target_webroot="${AKB_WEBROOT}"
	expected_site="${AKB_BASE_URL}"
fi

before_sha=""
if [[ "${apply}" == 1 ]]; then
	expected_confirmation="${workflow}-v1:${env_name}"
	[[ "${confirmation}" == "${expected_confirmation}" ]] \
		|| die "Apply requires exact --confirm ${expected_confirmation}"
	[[ -n "${commercial_before}" ]] \
		|| die "Apply requires --commercial-before"
	commercial_before="$(absolute_existing_file "${commercial_before}")"
	validate_integral_fingerprint "${commercial_before}" \
		|| die "Integral commercial before-fingerprint rejected"
	before_sha="$(sha256_file "${commercial_before}")"
else
	[[ -z "${commercial_before}${confirmation}" ]] \
		|| die "Read-only audit rejects mutation-only arguments"
fi

for command_name in jq shasum; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| die "Missing required command: ${command_name}"
done
if [[ "${env_name}" == "docker" ]]; then
	command -v docker >/dev/null 2>&1 || die "Missing required command: docker"
else
	command -v ssh >/dev/null 2>&1 || die "Missing required command: ssh"
fi

payload_sha="$(sha256_file "${payload}")"
mutation_id="${workflow}:${env_name}:$(date -u +%Y%m%dT%H%M%SZ):$$"
partial="${output_absolute}.partial.$$"
trap 'rm -f -- "${partial:-}"' EXIT HUP INT TERM

set +e
execute_payload "${payload}" "${target_host}" "${target_webroot}" "${expected_site}" \
	"${payload_sha}" "${mutation_id}" "${before_sha}" "${partial}"
execution_status=$?
set -e

[[ -s "${partial}" ]] || die "Cleanup emitted no JSON evidence"
validate_report "${partial}" "${gate}" "$([[ "${apply}" == 1 ]] && printf apply || printf audit)" \
	"${env_name}" "${payload_sha}" "${mutation_id}" "${before_sha}" \
	|| die "Cleanup evidence contract rejected"

report_status="$(jq -r '.status' "${partial}")"
if [[ "${execution_status}" == 0 ]]; then
	[[ "${report_status}" == "PASS" ]] \
		|| die "Cleanup returned success with a FAIL report"
else
	[[ "${report_status}" == "FAIL" ]] \
		|| die "Cleanup failed without a typed FAIL report"
fi

mv -- "${partial}" "${output_absolute}"
shasum -a 256 "${output_absolute}" > "${output_absolute}.sha256"
trap - EXIT HUP INT TERM

if [[ "${execution_status}" != 0 ]]; then
	printf 'FAIL evidence saved locally: %s\n' "${output_absolute}" >&2
	exit "${execution_status}"
fi

printf 'PASS evidence saved locally: %s\n' "${output_absolute}"
if [[ "${apply}" == 1 ]]; then
	printf '%s\n' \
		'SEAL PENDING: capture the integral after-fingerprint and run seal-historical before promotion.'
fi
