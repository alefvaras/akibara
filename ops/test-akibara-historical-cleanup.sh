#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
rank_payload="${script_dir}/rankmath-obsolete-options-cleanup.php"
session_payload="${script_dir}/woocommerce-expired-sessions-cleanup.php"
runner="${script_dir}/run-akibara-historical-cleanup.sh"
release_contract="${script_dir}/lib/akibara-release-contract.sh"
fixture_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-historical-cleanup-tests.XXXXXX")"
suffix="$(printf '%08x' "$$")"
options_table="wp_akb_rank_math_options_fixture_${suffix}"
actions_table="wp_akb_rank_math_actions_fixture_${suffix}"
sessions_table="wp_akb_wc_sessions_fixture_${suffix}"
trigger_name="akb_rank_math_delete_fixture_${suffix}"

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

db_query() {
	docker compose run --rm --no-deps -T cli \
		wp --path=/var/www/html db query "$1" --skip-column-names
}

cleanup() {
	set +e
	db_query "DROP TRIGGER IF EXISTS \`${trigger_name}\`;
		DROP TABLE IF EXISTS \`${options_table}\`,\`${actions_table}\`,\`${sessions_table}\`;" \
		>/dev/null 2>&1
	case "${fixture_root}" in
		*/akibara-historical-cleanup-tests.*)
			rm -rf -- "${fixture_root}"
			;;
	esac
}
trap cleanup EXIT HUP INT TERM

expect_failure() {
	if "$@"; then
		fail "command unexpectedly succeeded: $*"
	fi
}

rank_manifest="$(
	jq -cn \
		--arg csv "$(printf 'csv-finished' | shasum -a 256 | awk '{print $1}')" \
		--arg gtin "$(printf 'gtin-finished' | shasum -a 256 | awk '{print $1}')" \
		--arg regenerate "$(printf 'regenerate-finished' | shasum -a 256 | awk '{print $1}')" \
		--arg video "$(printf 'video-finished' | shasum -a 256 | awk '{print $1}')" \
		'{
		  rank_math_csv_import_redirections_status: {
		    bytes: 12, sha256: $csv, autoload: "auto"
		  },
		  rank_math_gtin_products: {
		    bytes: 13, sha256: $gtin, autoload: "yes"
		  },
		  rank_math_regenerate_state: {
		    bytes: 19, sha256: $regenerate, autoload: "off"
		  },
		  rank_math_video_posts: {
		    bytes: 14, sha256: $video, autoload: "off"
		  }
		}'
)"
rank_payload_sha="$(shasum -a 256 "${rank_payload}" | awk '{print $1}')"
session_payload_sha="$(shasum -a 256 "${session_payload}" | awk '{print $1}')"
commercial_sha="$(printf 'integral-commercial-before' | shasum -a 256 | awk '{print $1}')"
# shellcheck source=ops/lib/akibara-release-contract.sh
source "${release_contract}"
fixture_release_set="${release_set}"
fixture_release_digest="${expected_release_digest}"

rank_rows_sql="
	INSERT INTO \`${options_table}\` (option_name,option_value,autoload) VALUES
	('rank_math_csv_import_redirections_status','csv-finished','auto'),
	('rank_math_gtin_products','gtin-finished','yes'),
	('rank_math_regenerate_state','regenerate-finished','off'),
	('rank_math_video_posts','video-finished','off');"

run_rank_payload() {
	local output="$1" mode="$2" expected_site="${3:-http://localhost:8080}"
	docker compose run --rm --no-deps -T \
		-e "AKB_APPLY=${mode}" \
		-e "AKB_EXPECTED_SITE_URL=${expected_site}" \
		-e "AKB_TARGET_ENVIRONMENT=docker" \
		-e "AKB_PROMOTION_TARGET=local-compose:/var/www/html" \
		-e "AKB_PROMOTION_RUN=test-${suffix}" \
		-e "AKB_RELEASE_SET=${fixture_release_set}" \
		-e "AKB_RELEASE_DIGEST=${fixture_release_digest}" \
		-e "AKB_PAYLOAD_SHA256=${rank_payload_sha}" \
		-e "AKB_MUTATION_ID=rank-fixture:${suffix}:${mode}" \
		-e "AKB_COMMERCIAL_BEFORE_SHA256=${commercial_sha}" \
		-e "AKB_ENABLE_LOCAL_TEST_SEAMS=1" \
		-e "AKB_LOCAL_TEST_OPTIONS_TABLE=${options_table}" \
		-e "AKB_LOCAL_TEST_ACTIONS_TABLE=${actions_table}" \
		-e "AKB_LOCAL_TEST_EXPECTED_OPTIONS_JSON=${rank_manifest}" \
		cli wp --path=/var/www/html eval-file - < "${rank_payload}" > "${output}" 2>"${output}.stderr"
}

run_session_payload() {
	local output="$1" mode="$2"
	docker compose run --rm --no-deps -T \
		-e "AKB_APPLY=${mode}" \
		-e "AKB_EXPECTED_SITE_URL=http://localhost:8080" \
		-e "AKB_TARGET_ENVIRONMENT=docker" \
		-e "AKB_PROMOTION_TARGET=local-compose:/var/www/html" \
		-e "AKB_PROMOTION_RUN=test-${suffix}" \
		-e "AKB_RELEASE_SET=${fixture_release_set}" \
		-e "AKB_RELEASE_DIGEST=${fixture_release_digest}" \
		-e "AKB_PAYLOAD_SHA256=${session_payload_sha}" \
		-e "AKB_MUTATION_ID=session-fixture:${suffix}:${mode}" \
		-e "AKB_COMMERCIAL_BEFORE_SHA256=${commercial_sha}" \
		-e "AKB_ENABLE_LOCAL_TEST_SEAMS=1" \
		-e "AKB_LOCAL_TEST_SESSIONS_TABLE=${sessions_table}" \
		cli wp --path=/var/www/html eval-file - < "${session_payload}" > "${output}" 2>"${output}.stderr"
}

bash -n "${runner}"
php -l "${rank_payload}" >/dev/null
php -l "${session_payload}" >/dev/null
grep -Fq 'wc_cleanup_session_data();' "${session_payload}"
[[ "$(grep -Fc 'self::cleanup_once( $table );' "${session_payload}")" == 2 ]] \
	|| fail "session payload does not execute exactly two cleanup passes"
grep -Fq 'START TRANSACTION' "${rank_payload}"
grep -Fq 'FOR UPDATE' "${rank_payload}"
grep -Fq 'ROLLBACK' "${rank_payload}"
grep -Fq 'eval-file -' "${runner}"
if rg -n '\b(scp|rsync|sftp)\b|eval-file [^"-]' "${runner}"; then
	fail "runner contains a remote file-copy path"
fi
for fingerprint in \
	9aadea42c740918fa5e7db34a0685b45360c11cdb49f8d76c2268419b9a73726 \
	35786c7117b4e38d0f169239752ce71158266ae2f6e4aa230fbbb87bd699c0e3 \
	6f55c2a060faa953526cdccfec64d897c509a6b633d893ea1c00064820603707 \
	5fa17c3cfac9c3432b1352194aa34fab074b2357d0a94a32451bd05afe77e101; do
	grep -Fq "${fingerprint}" "${rank_payload}"
done

expect_failure "${runner}" \
	--workflow rankmath-options --env docker \
	--output ../escaped.json >/dev/null 2>&1
expect_failure "${runner}" \
	--workflow rankmath-options --env qa \
	--output .local-reports/test-retired-qa.json >/dev/null 2>&1
expect_failure "${runner}" \
	--workflow rankmath-options --env stage \
	--output .local-reports/test-retired-stage.json >/dev/null 2>&1
expect_failure "${runner}" \
	--workflow rankmath-options --env docker \
	--output .local-backups/code-promotion/qa/test/evidence/rejected.json \
	>/dev/null 2>&1
expect_failure "${runner}" \
	--workflow rankmath-options --env docker \
	--output .local-reports/test-missing-before.json \
	--apply --confirm rankmath-options-v1:docker >/dev/null 2>&1

db_query "
	CREATE TABLE \`${options_table}\` LIKE wp_options;
	CREATE TABLE \`${actions_table}\` (
		action_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		hook VARCHAR(191) NOT NULL,
		args TEXT NOT NULL,
		extended_args TEXT NULL,
		PRIMARY KEY (action_id)
	) ENGINE=InnoDB;
	CREATE TABLE \`${sessions_table}\` LIKE wp_woocommerce_sessions;
	${rank_rows_sql}" >/dev/null

real_session_count_before="$(
	db_query "SELECT COUNT(*) FROM wp_woocommerce_sessions;" \
		| awk 'NF == 1 && $1 ~ /^[0-9]+$/ { value = $1 } END { print value }'
)"
unsafe_test_seam="${fixture_root}/unsafe-test-seam.json"
if docker compose run --rm --no-deps -T \
	-e "AKB_APPLY=1" \
	-e "AKB_EXPECTED_SITE_URL=http://localhost:8080" \
	-e "AKB_TARGET_ENVIRONMENT=docker" \
	-e "AKB_PROMOTION_TARGET=local-compose:/var/www/html" \
	-e "AKB_PROMOTION_RUN=test-${suffix}" \
	-e "AKB_RELEASE_SET=${fixture_release_set}" \
	-e "AKB_RELEASE_DIGEST=${fixture_release_digest}" \
	-e "AKB_PAYLOAD_SHA256=${session_payload_sha}" \
	-e "AKB_MUTATION_ID=unsafe-fixture:${suffix}:1" \
	-e "AKB_COMMERCIAL_BEFORE_SHA256=${commercial_sha}" \
	-e "AKB_ENABLE_LOCAL_TEST_SEAMS=1" \
	cli wp --path=/var/www/html eval-file - \
	< "${session_payload}" > "${unsafe_test_seam}" 2>"${unsafe_test_seam}.stderr"; then
	fail "local test seam accepted the real WooCommerce sessions table"
fi
jq -e '.status == "FAIL" and .error_code == "missing_test_table"' \
	"${unsafe_test_seam}" >/dev/null
real_session_count_after="$(
	db_query "SELECT COUNT(*) FROM wp_woocommerce_sessions;" \
		| awk 'NF == 1 && $1 ~ /^[0-9]+$/ { value = $1 } END { print value }'
)"
[[ "${real_session_count_before}" == "${real_session_count_after}" ]] \
	|| fail "rejected local test seam changed the real sessions table"

rank_exact="${fixture_root}/rank-exact.json"
run_rank_payload "${rank_exact}" 0
jq -e '
	.status == "PASS"
	and .mode == "audit"
	and .before.state == "eligible_cleanup"
	and .deleted_count == 0
	and .scheduled_references_before.total == 0
' "${rank_exact}" >/dev/null

db_query "DELETE FROM \`${options_table}\`
	WHERE option_name = 'rank_math_video_posts';" >/dev/null
rank_partial="${fixture_root}/rank-partial.json"
expect_failure run_rank_payload "${rank_partial}" 0
jq -e '.status == "FAIL" and .error_code == "partial_option_set"' \
	"${rank_partial}" >/dev/null
db_query "INSERT INTO \`${options_table}\` (option_name,option_value,autoload)
	VALUES ('rank_math_video_posts','video-finished','off');" >/dev/null

db_query "UPDATE \`${options_table}\`
	SET option_value = 'drift'
	WHERE option_name = 'rank_math_gtin_products';" >/dev/null
rank_drift="${fixture_root}/rank-drift.json"
expect_failure run_rank_payload "${rank_drift}" 0
jq -e '.status == "FAIL" and .error_code == "option_fingerprint_drift"' \
	"${rank_drift}" >/dev/null
db_query "UPDATE \`${options_table}\`
	SET option_value = 'gtin-finished'
	WHERE option_name = 'rank_math_gtin_products';" >/dev/null

db_query "INSERT INTO \`${options_table}\` (option_name,option_value,autoload)
	VALUES ('cron','rank_math_video_posts','off');" >/dev/null
rank_cron="${fixture_root}/rank-cron.json"
expect_failure run_rank_payload "${rank_cron}" 0
jq -e '.status == "FAIL" and .error_code == "scheduled_reference_found"' \
	"${rank_cron}" >/dev/null
db_query "DELETE FROM \`${options_table}\` WHERE option_name = 'cron';" >/dev/null

db_query "INSERT INTO \`${actions_table}\` (hook,args,extended_args)
	VALUES ('fixture','{\"option\":\"rank_math_gtin_products\"}',NULL);" >/dev/null
rank_actions="${fixture_root}/rank-actions.json"
expect_failure run_rank_payload "${rank_actions}" 0
jq -e '.status == "FAIL" and .error_code == "scheduled_reference_found"' \
	"${rank_actions}" >/dev/null
db_query "TRUNCATE TABLE \`${actions_table}\`;" >/dev/null

rank_site="${fixture_root}/rank-site.json"
expect_failure run_rank_payload "${rank_site}" 0 "https://wrong.example"
jq -e '.status == "FAIL" and .error_code == "site_url_mismatch"' \
	"${rank_site}" >/dev/null

db_query "CREATE TRIGGER \`${trigger_name}\`
	BEFORE DELETE ON \`${options_table}\`
	FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture delete blocked';" >/dev/null
rank_rollback="${fixture_root}/rank-rollback.json"
expect_failure run_rank_payload "${rank_rollback}" 1
jq -e '.status == "FAIL" and .error_code == "transaction_delete_mismatch"' \
	"${rank_rollback}" >/dev/null
remaining_rank_rows="$(
	db_query "SELECT COUNT(*) FROM \`${options_table}\`
		WHERE option_name IN (
			'rank_math_csv_import_redirections_status',
			'rank_math_gtin_products',
			'rank_math_regenerate_state',
			'rank_math_video_posts'
		);" \
		| awk 'NF == 1 && $1 ~ /^[0-9]+$/ { value = $1 } END { print value }'
)"
[[ "${remaining_rank_rows}" == 4 ]] \
	|| fail "failed Rank Math transaction retained ${remaining_rank_rows:-unknown}, expected four rows"
db_query "DROP TRIGGER \`${trigger_name}\`;" >/dev/null

rank_apply="${fixture_root}/rank-apply.json"
run_rank_payload "${rank_apply}" 1
jq -e '
	.status == "PASS"
	and .mode == "apply"
	and .before.state == "eligible_cleanup"
	and .deleted_count == 4
	and .after.state == "already_clean"
	and .seal_status == "MUTATION_COMPLETE_SEAL_PENDING"
' "${rank_apply}" >/dev/null

rank_idempotent="${fixture_root}/rank-idempotent.json"
run_rank_payload "${rank_idempotent}" 1
jq -e '
	.status == "PASS"
	and .before.state == "already_clean"
	and .deleted_count == 0
	and .after.state == "already_clean"
' "${rank_idempotent}" >/dev/null

db_query "INSERT INTO \`${sessions_table}\`
	(session_key,session_value,session_expiry) VALUES
	('11111111111111111111111111111111','expired',UNIX_TIMESTAMP() - 600),
	('22222222222222222222222222222222','active-a',UNIX_TIMESTAMP() + 600),
	('33333333333333333333333333333333','active-b',UNIX_TIMESTAMP() + 900);" >/dev/null

session_audit="${fixture_root}/session-audit.json"
run_session_payload "${session_audit}" 0
jq -e '
	.status == "PASS"
	and .mode == "audit"
	and .before.total == 3
	and .before.expired == 1
	and .before.active == 2
	and .after_first == null
	and .after_second == null
' "${session_audit}" >/dev/null

db_query "UPDATE \`${sessions_table}\`
	SET session_expiry = UNIX_TIMESTAMP() + 100
	WHERE session_key = '22222222222222222222222222222222';" >/dev/null
session_horizon="${fixture_root}/session-horizon.json"
expect_failure run_session_payload "${session_horizon}" 1
jq -e '.status == "FAIL" and .error_code == "active_session_inside_horizon"' \
	"${session_horizon}" >/dev/null
remaining_sessions="$(
	db_query "SELECT COUNT(*) FROM \`${sessions_table}\`;" \
		| awk 'NF == 1 && $1 ~ /^[0-9]+$/ { value = $1 } END { print value }'
)"
[[ "${remaining_sessions}" == 3 ]] \
	|| fail "five-minute horizon guard retained ${remaining_sessions:-unknown}, expected three sessions"
db_query "UPDATE \`${sessions_table}\`
	SET session_expiry = UNIX_TIMESTAMP() + 600
	WHERE session_key = '22222222222222222222222222222222';" >/dev/null

session_apply="${fixture_root}/session-apply.json"
run_session_payload "${session_apply}" 1
jq -e '
	.status == "PASS"
	and .mode == "apply"
	and .before.expired == 1
	and .deleted_count == 1
	and .after_first.expired == 0
	and .after_second.expired == 0
	and .before.active_fingerprint == .after_first.active_fingerprint
	and .after_first.all_fingerprint == .after_second.all_fingerprint
	and .seal_status == "MUTATION_COMPLETE_SEAL_PENDING"
' "${session_apply}" >/dev/null

session_idempotent="${fixture_root}/session-idempotent.json"
run_session_payload "${session_idempotent}" 1
jq -e '
	.status == "PASS"
	and .before.expired == 0
	and .deleted_count == 0
	and .after_first.all_fingerprint == .after_second.all_fingerprint
' "${session_idempotent}" >/dev/null

printf '%s\n' \
	'PASS: exact Rank Math set/hash/version/site/scheduler/transaction guards; session horizon/two-pass/idempotency; Docker/production-only runner with retired-environment rejection'
