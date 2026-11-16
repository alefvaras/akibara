#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
dist_dir="${repo_root}/dist"
gate_root="${repo_root}/.local-backups/code-promotion/gates"
fingerprint_php="${script_dir}/akibara-protected-commerce-fingerprint.php"
search_fingerprint_php="${script_dir}/akibara-search-state-fingerprint.php"
config_library="${script_dir}/lib/akibara-promotion-config.sh"
release_contract="${script_dir}/lib/akibara-release-contract.sh"

# shellcheck source=ops/lib/akibara-release-contract.sh
source "${release_contract}"
production_evidence_base_url="https://akibara.cl"
active_promotion_backup_dir=""
active_promotion_evidence_dir=""
active_release_profile="production"
superset_contract="${repo_root}/${production_superset_contract_relative}"
production_theme_runtime_inventory="${dist_dir}/akibara-gp-${production_theme_version}.files.sha256"

component_slugs=(
	"akibara-business"
	"akibara-search"
	"akibara-cart-checkout-blocks"
	"akibara-gp"
)
component_kinds=("plugin" "plugin" "plugin" "theme")
production_component_versions=("1.95.2" "0.1.4" "2.0.1" "${production_theme_version}")
production_component_counts=("210" "39" "15" "${production_theme_files}")
production_component_sha256=(
	"415a49530ec2613a5df94c8781522dcab24db5b083644bb40e9c1063755d8a8e"
	"a0202bbcec851fdf0671a80c178156ee6491497d9a7eb2ac2d31e34b85005c1e"
	"bf9ebceb16964952a309fffeb21ea372032b7a2c5444d217f0c183f65d0b4999"
	"${production_theme_archive_sha256}"
)
component_versions=("${production_component_versions[@]}")
component_counts=("${production_component_counts[@]}")
component_sha256=("${production_component_sha256[@]}")

# Four exact production artifacts: three plugins plus the production theme.
artifact_slugs=(
	"akibara-business"
	"akibara-search"
	"akibara-cart-checkout-blocks"
	"akibara-gp"
)
artifact_versions=("1.95.2" "0.1.4" "2.0.1" "${production_theme_version}")
artifact_counts=("210" "39" "15" "${production_theme_files}")
artifact_sha256=(
	"${production_component_sha256[@]}"
)
local_gate_suite_root="${repo_root}"
local_gate_suite_files=(
	"compose.yaml"
	"ops/test-akibara-code-promotion.sh"
	"ops/contracts/akibara-gp-2.1.49-production-superset.json"
	"tools/akibara-business/composer.json"
	"tools/akibara-business/composer.lock"
	"tools/akibara-business/package.sh"
	"tools/akibara-business/phpunit.xml.dist"
	"tools/akibara-business/qa.sh"
	"tools/akibara-business/test-package.sh"
	"tools/akibara-releases/package-business.sh"
	"tools/akibara-releases/package-checkout.sh"
	"tools/akibara-releases/package-runtime.sh"
	"tools/akibara-releases/package-search.sh"
	"tools/akibara-releases/package-theme.sh"
	"tools/akibara-releases/qa-checkout.sh"
	"tools/akibara-releases/qa-search.sh"
	"tools/akibara-releases/qa-theme-production-superset.sh"
	"tools/akibara-releases/verify-theme-production-superset.sh"
	"public_html/wp-content/themes/akibara-gp/tests/archive-intro.test.js"
	"public_html/wp-content/themes/akibara-gp/tests/asset-version.test.js"
	"public_html/wp-content/themes/akibara-gp/tests/cart-semantics.test.js"
	"public_html/wp-content/themes/akibara-gp/tests/home-deferred-styles.test.js"
	"public_html/wp-content/themes/akibara-gp/tests/quantity-stepper.test.js"
	"public_html/wp-content/themes/akibara-gp/tests/shop-filters-a11y.test.js"
	"public_html/wp-content/themes/akibara-gp/tests/third-party-contrast.test.js"
	"public_html/wp-content/themes/akibara-gp/tests/visual-polish.test.js"
)
local_gate_suite_directories=(
	"public_html/wp-content/plugins/akibara-business/tests"
	"public_html/wp-content/plugins/akibara-search/tests"
	"public_html/wp-content/plugins/akibara-cart-checkout-blocks/tests"
)
# One atomic code release: domain contract, Search owner, Blocks adapter, UI.
deployment_order=(0 1 2 3)
protected_fingerprint_datasets=(
	"legacy_orders"
	"legacy_order_meta"
	"legacy_order_notes"
	"legacy_order_note_meta"
	"coupon_posts"
	"coupon_post_meta"
	"wordpress_users"
	"wordpress_user_meta"
	"hpos_orders"
	"hpos_order_addresses"
	"hpos_order_operational_data"
	"hpos_order_meta"
	"woocommerce_order_items"
	"woocommerce_order_itemmeta"
	"woocommerce_download_permissions"
	"woocommerce_download_log"
	"woocommerce_payment_tokens"
	"woocommerce_payment_token_meta"
	"woocommerce_customers"
	"woocommerce_order_notes"
	"woocommerce_order_stats"
	"woocommerce_order_product_lookup"
	"woocommerce_order_tax_lookup"
	"woocommerce_order_coupon_lookup"
	"woocommerce_tax_rates"
	"woocommerce_tax_rate_locations"
	"woocommerce_tax_rate_classes"
	"woocommerce_shipping_zones"
	"woocommerce_shipping_zone_locations"
	"woocommerce_shipping_zone_methods"
	"woocommerce_api_keys"
	"woocommerce_webhooks"
	"woocommerce_reserved_stock"
	"woocommerce_order_fulfillments"
	"woocommerce_order_fulfillment_meta"
)

usage() {
	cat <<'EOF'
Usage:
  ops/akibara-code-promotion.sh verify-artifacts
  ops/akibara-code-promotion.sh local-gate
  ops/akibara-code-promotion.sh plan --config FILE
  ops/akibara-code-promotion.sh promote --config FILE \
    --apply --confirm RELEASE_SET

Safety:
  - plan is the default remote-oriented mode and performs no network access.
  - promote requires --apply, the exact release-set confirmation, a valid
    checksum-protected Docker receipt.
  - production is the only remote destination. Hosts, webroots, base URLs,
    and log paths come only from the strict config allowlist.
  - remote ZIPs exist only under mktemp and have an EXIT trap.
  - database, code, logs, fingerprints, evidence, and checksums stay local.
EOF
}

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

[[ -f "${config_library}" && ! -L "${config_library}" ]] \
	|| die "Missing regular config library: ${config_library}"
[[ -f "${search_fingerprint_php}" && ! -L "${search_fingerprint_php}" ]] \
	|| die "Missing regular Search-state fingerprint: ${search_fingerprint_php}"
# shellcheck source=ops/lib/akibara-promotion-config.sh
source "${config_library}"

note() {
	printf '%s\n' "$*"
}

require_command() {
	command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

sha256_file() {
	shasum -a 256 "$1" | awk '{print $1}'
}

compute_local_gate_suite_sha256() {
	local suite_root="${local_gate_suite_root}"
	local relative absolute file_count suite_file suite_relative

	[[ "${suite_root}" == /* && -d "${suite_root}" && ! -L "${suite_root}" ]] \
		|| die "Local-gate suite root must be an absolute regular directory"

	{
		printf '@contract\tlocal-gate-suite-v1\n'
		for relative in "${local_gate_suite_files[@]}"; do
			[[ "${relative}" =~ ^[A-Za-z0-9._/-]+$
				&& "${relative}" != /* && "${relative}" != *'..'*
				&& "${relative}" != *'//'*
				&& "${relative}" != *'/./'* ]] \
				|| die "Unsafe local-gate suite path: ${relative}"
			absolute="${suite_root}/${relative}"
			[[ -f "${absolute}" && ! -L "${absolute}" ]] \
				|| die "Missing regular local-gate suite file: ${relative}"
			printf 'file\t%s\t%s\n' "${relative}" "$(sha256_file "${absolute}")"
		done

		for relative in "${local_gate_suite_directories[@]}"; do
			[[ "${relative}" =~ ^[A-Za-z0-9._/-]+$
				&& "${relative}" != /* && "${relative}" != *'..'*
				&& "${relative}" != *'//'*
				&& "${relative}" != *'/./'* ]] \
				|| die "Unsafe local-gate suite directory: ${relative}"
			absolute="${suite_root}/${relative}"
			[[ -d "${absolute}" && ! -L "${absolute}" ]] \
				|| die "Missing regular local-gate suite directory: ${relative}"
			test -z "$(find "${absolute}" ! -type d ! -type f -print -quit)" \
				|| die "Local-gate suite contains a symlink or special entry: ${relative}"
			file_count=0
			while IFS= read -r suite_file; do
				[[ "${suite_file}" == "${suite_root}/"* ]] \
					|| die "Local-gate suite file escaped its root"
				suite_relative="${suite_file#"${suite_root}/"}"
				[[ "${suite_relative}" =~ ^[A-Za-z0-9._/-]+$ ]] \
					|| die "Unsafe local-gate suite file name: ${suite_relative}"
				printf 'file\t%s\t%s\n' \
					"${suite_relative}" "$(sha256_file "${suite_file}")"
				file_count=$((file_count + 1))
			done < <(find "${absolute}" -type f -print | LC_ALL=C sort)
			((file_count > 0)) \
				|| die "Local-gate suite directory is empty: ${relative}"
		done
	} | LC_ALL=C sort | shasum -a 256 | awk '{print $1}'
}

absolute_path() {
	local input="$1"
	(
		cd -- "$(dirname -- "${input}")"
		printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${input}")"
	)
}

select_release_profile() {
	local profile="$1"
	case "${profile}" in
		production)
			component_versions=("${production_component_versions[@]}")
			component_counts=("${production_component_counts[@]}")
			component_sha256=("${production_component_sha256[@]}")
			;;
		*)
			die "Unsupported release artifact profile: ${profile}"
			;;
	esac
	active_release_profile="${profile}"
}

release_profile_digest() {
	local profile="$1" i canonical=""
	[[ "${profile}" == "production" ]] \
		|| die "Unsupported release digest profile: ${profile}"
	for ((i = 0; i < ${#component_slugs[@]}; i++)); do
		canonical+="${component_kinds[$i]}:${component_slugs[$i]}:${production_component_versions[$i]}:${production_component_sha256[$i]}"$'\n'
	done
	printf '%s' "${canonical}" | shasum -a 256 | awk '{print $1}'
}

release_digest() {
	local production_digest
	production_digest="$(release_profile_digest production)"
	{
		printf '@contract\t%s\n' "${release_contract_schema}"
		printf 'production\t%s\n' "${production_digest}"
		printf 'superset\t%s\n' "${production_superset_contract_sha256}"
		printf 'preimage\t%s\t%s\t%s\t%s\t%s\n' \
			"${production_theme_preimage_version}" \
			"${production_theme_preimage_normal_files}" \
			"${production_theme_preimage_normal_tree_sha256}" \
			"${production_theme_preimage_appledouble_files}" \
			"${production_theme_preimage_appledouble_tree_sha256}"
	} | shasum -a 256 | awk '{print $1}'
}

verify_release_contract() {
	[[ "${#component_slugs[@]}" == 4
		&& "${component_slugs[0]}" == "akibara-business"
		&& "${component_slugs[1]}" == "akibara-search"
		&& "${component_slugs[2]}" == "akibara-cart-checkout-blocks"
		&& "${component_slugs[3]}" == "akibara-gp"
		&& "${component_kinds[*]}" == $'plugin\nplugin\nplugin\ntheme'
		&& "${deployment_order[*]}" == $'0\n1\n2\n3' ]] \
		|| die "Release scope/order must be Business, Search, Checkout, then theme"
	[[ "${#artifact_slugs[@]}" == 4
		&& "${artifact_versions[3]}" == "${production_theme_version}"
		&& "${artifact_sha256[3]}" == "${production_theme_archive_sha256}"
		&& "${artifact_counts[3]}" == "${production_theme_files}" ]] \
		|| die "Logical artifacts must be the four exact production components"
	[[ -f "${superset_contract}" && ! -L "${superset_contract}"
		&& "$(sha256_file "${superset_contract}")" == "${production_superset_contract_sha256}" ]] \
		|| die "Production theme superset contract is absent or changed"
	[[ "$(release_profile_digest production)" == "${production_release_digest}" ]] \
		|| die "Production artifact profile digest is inconsistent"
	[[ "$(release_digest)" == "${expected_release_digest}" ]] \
		|| die "Production release-set digest is inconsistent"
	[[ "${active_release_profile}" == "production"
		&& "${component_versions[*]}" == "${production_component_versions[*]}"
		&& "${component_sha256[*]}" == "${production_component_sha256[*]}" ]] \
		|| die "Active production artifacts drifted from the production profile"
}

verify_artifacts() {
	local verify_root i slug version archive inventory sidecar actual count entry

	for command_name in awk find grep shasum unzip zipinfo; do
		require_command "${command_name}"
	done
	verify_release_contract
	verify_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-promotion-artifacts.XXXXXX")"
	trap 'case "${verify_root:-}" in */akibara-promotion-artifacts.*) rm -rf -- "${verify_root}" ;; esac' RETURN

	for ((i = 0; i < ${#artifact_slugs[@]}; i++)); do
		slug="${artifact_slugs[$i]}"
		version="${artifact_versions[$i]}"
		archive="${dist_dir}/${slug}-${version}.zip"
		inventory="${dist_dir}/${slug}-${version}.files.sha256"
		sidecar="${dist_dir}/${slug}-${version}.zip.sha256"

		[[ -f "${archive}" && ! -L "${archive}" ]] || die "Missing regular archive: ${archive}"
		[[ -f "${inventory}" && ! -L "${inventory}" ]] || die "Missing regular inventory: ${inventory}"
		[[ -f "${sidecar}" && ! -L "${sidecar}" ]] || die "Missing regular checksum: ${sidecar}"
		actual="$(sha256_file "${archive}")"
		[[ "${actual}" == "${artifact_sha256[$i]}" ]] || die "Unexpected SHA-256 for ${archive}"
		(
			cd -- "${dist_dir}"
			shasum -a 256 -c "$(basename -- "${sidecar}")" >/dev/null
		) || die "Checksum sidecar rejected ${archive}"

		count=0
		while IFS= read -r entry; do
			[[ -n "${entry}" ]] || continue
			[[ "${entry}" != /* && "${entry}" != *'../'* && "${entry}" != '..'* && "${entry}" != *\\* ]] \
				|| die "Unsafe ZIP entry in ${archive}: ${entry}"
			[[ "${entry}" == "${slug}/"* ]] || die "Unexpected ZIP root in ${archive}: ${entry}"
			case "${entry}" in
				*/ ) ;;
				* ) count=$((count + 1)) ;;
			esac
		done < <(zipinfo -1 "${archive}")
		[[ "${count}" == "${artifact_counts[$i]}" ]] \
			|| die "Unexpected file count for ${archive}: ${count}"

		unzip -tq "${archive}" >/dev/null
		mkdir -p "${verify_root}/${i}"
		unzip -q "${archive}" -d "${verify_root}/${i}"
		[[ -z "$(find "${verify_root}/${i}" -type l -print -quit)" ]] \
			|| die "Archive extracted a symlink: ${archive}"
		(
			cd -- "${verify_root}/${i}"
			shasum -a 256 -c "${inventory}" >/dev/null
		) || die "Runtime inventory rejected ${archive}"
	done

	trap - RETURN
	rm -rf -- "${verify_root}"
	note "PASS: four exact production artifacts, checksums, paths, counts, and inventories"
}

write_receipt() {
	local stage="$1" target="$2" promotion_evidence_dir="${3:-}"
	local receipt tmp pipeline_sha config_library_sha fingerprint_sha search_fingerprint_sha
	local local_gate_suite_sha release_contract_sha
	case "${stage}" in docker|production) ;; *) die "Unsupported receipt stage: ${stage}" ;; esac
	pipeline_sha="$(sha256_file "${BASH_SOURCE[0]}")"
	config_library_sha="$(sha256_file "${config_library}")"
	release_contract_sha="$(sha256_file "${release_contract}")"
	fingerprint_sha="$(sha256_file "${fingerprint_php}")"
	search_fingerprint_sha="$(sha256_file "${search_fingerprint_php}")"
	local_gate_suite_sha="$(compute_local_gate_suite_sha256)"
	require_command jq
	mkdir -p "${gate_root}"
	receipt="${gate_root}/${stage}-passed-${expected_release_digest}.json"
	tmp="${receipt}.partial"
	jq -n \
		--arg stage "${stage}" \
		--arg release_set "${release_set}" \
		--arg release_digest "${expected_release_digest}" \
		--arg artifact_digest "${production_release_digest}" \
		--arg superset_sha "${production_superset_contract_sha256}" \
		--arg pipeline_sha "${pipeline_sha}" \
		--arg config_library_sha "${config_library_sha}" \
		--arg release_contract_sha "${release_contract_sha}" \
		--arg fingerprint_sha "${fingerprint_sha}" \
		--arg search_fingerprint_sha "${search_fingerprint_sha}" \
		--arg local_gate_suite_sha "${local_gate_suite_sha}" \
		--arg target "${target}" \
		--arg promotion_evidence_dir "${promotion_evidence_dir}" \
		--arg completed_at_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
		'{
		  schema_version: 7,
		  stage: $stage,
		  status: "PASS",
		  release_set: $release_set,
		  release_digest: $release_digest,
		  artifact_profile: "production",
		  artifact_digest: $artifact_digest,
		  production_superset_contract_sha256: $superset_sha,
		  pipeline_sha256: $pipeline_sha,
		  config_library_sha256: $config_library_sha,
		  release_contract_sha256: $release_contract_sha,
		  fingerprint_sha256: $fingerprint_sha,
		  search_fingerprint_sha256: $search_fingerprint_sha,
		  local_gate_suite_sha256: $local_gate_suite_sha,
		  target: $target,
		  promotion_evidence_dir: $promotion_evidence_dir,
		  completed_at_utc: $completed_at_utc
		}' > "${tmp}"
	mv -- "${tmp}" "${receipt}"
	shasum -a 256 "${receipt}" > "${receipt}.sha256"
	note "Gate receipt: ${receipt}"
}

verify_receipt() {
	local stage="$1" expected_target="${2:-}" receipt checksum
	local pipeline_sha config_library_sha fingerprint_sha search_fingerprint_sha
	local local_gate_suite_sha release_contract_sha
	local promotion_evidence_relative promotion_evidence_absolute
	local completed_epoch now_epoch
	case "${stage}" in docker|production) ;; *) die "Unsupported receipt stage: ${stage}" ;; esac
	pipeline_sha="$(sha256_file "${BASH_SOURCE[0]}")"
	config_library_sha="$(sha256_file "${config_library}")"
	release_contract_sha="$(sha256_file "${release_contract}")"
	fingerprint_sha="$(sha256_file "${fingerprint_php}")"
	search_fingerprint_sha="$(sha256_file "${search_fingerprint_php}")"
	local_gate_suite_sha="$(compute_local_gate_suite_sha256)"
	receipt="${gate_root}/${stage}-passed-${expected_release_digest}.json"
	checksum="${receipt}.sha256"
	[[ -f "${receipt}" && ! -L "${receipt}" && -f "${checksum}" && ! -L "${checksum}" ]] \
		|| die "Missing regular ${stage} gate receipt for this release"
	shasum -a 256 -c "${checksum}" >/dev/null || die "Invalid ${stage} gate checksum"
	require_command jq
	jq -e \
		--arg stage "${stage}" \
		--arg release_set "${release_set}" \
		--arg release_digest "${expected_release_digest}" \
		--arg artifact_digest "${production_release_digest}" \
		--arg superset_sha "${production_superset_contract_sha256}" \
		--arg pipeline_sha "${pipeline_sha}" \
		--arg config_library_sha "${config_library_sha}" \
		--arg release_contract_sha "${release_contract_sha}" \
		--arg fingerprint_sha "${fingerprint_sha}" \
		--arg search_fingerprint_sha "${search_fingerprint_sha}" \
		--arg local_gate_suite_sha "${local_gate_suite_sha}" \
		--arg expected_target "${expected_target}" \
		'
		  type == "object"
		  and ((keys | sort) == [
		    "artifact_digest", "artifact_profile", "completed_at_utc",
		    "config_library_sha256", "fingerprint_sha256",
		    "local_gate_suite_sha256", "pipeline_sha256",
		    "production_superset_contract_sha256", "promotion_evidence_dir",
		    "release_contract_sha256", "release_digest", "release_set",
		    "schema_version", "search_fingerprint_sha256", "stage",
		    "status", "target"
		  ])
		  and .schema_version == 7
		  and .stage == $stage
		  and .status == "PASS"
		  and .release_set == $release_set
		  and .release_digest == $release_digest
		  and .artifact_profile == "production"
		  and .artifact_digest == $artifact_digest
		  and .production_superset_contract_sha256 == $superset_sha
		  and .pipeline_sha256 == $pipeline_sha
		  and .config_library_sha256 == $config_library_sha
		  and .release_contract_sha256 == $release_contract_sha
		  and .fingerprint_sha256 == $fingerprint_sha
		  and .search_fingerprint_sha256 == $search_fingerprint_sha
		  and .local_gate_suite_sha256 == $local_gate_suite_sha
		  and ($expected_target == "" or .target == $expected_target)
		  and (.completed_at_utc
		    | type == "string"
		    and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
		' "${receipt}" >/dev/null || die "Gate receipt contract rejected: ${stage}"
	completed_epoch="$(jq -er '.completed_at_utc | fromdateiso8601' "${receipt}")" \
		|| die "Invalid ${stage} gate timestamp"
	now_epoch="$(date -u +%s)"
	((completed_epoch <= now_epoch + 300)) || die "${stage} gate receipt is dated in the future"
	((now_epoch - completed_epoch <= 86400)) \
		|| die "${stage} gate receipt is stale; rerun the Docker gate"
	promotion_evidence_relative="$(jq -r '.promotion_evidence_dir' "${receipt}")"
	if [[ "${stage}" == "docker" ]]; then
		[[ -z "${promotion_evidence_relative}" ]] \
			|| die "Docker receipt must not claim remote promotion evidence"
	else
		[[ "${promotion_evidence_relative}" == .local-backups/code-promotion/production/*-"${expected_release_digest}"/evidence
			&& "${promotion_evidence_relative}" != *'..'* && "${promotion_evidence_relative}" != *'//'*
			&& "${promotion_evidence_relative}" != *'/./'* ]] \
			|| die "Production receipt lacks its exact local promotion evidence directory"
		promotion_evidence_absolute="$(absolute_path "${repo_root}/${promotion_evidence_relative}")"
		[[ "${promotion_evidence_absolute}" == "${repo_root}/${promotion_evidence_relative}"
			&& -d "${promotion_evidence_absolute}" && ! -L "${promotion_evidence_absolute}" ]] \
			|| die "Production promotion evidence directory is absent or unsafe"
	fi
}

run_local_gate() {
	select_release_profile production
	verify_artifacts
	require_command docker
	require_command node
	note "Running promotion pipeline regression contract..."
	bash "${repo_root}/ops/test-akibara-code-promotion.sh"
	note "Running Docker-local Akibara quality gates..."
	bash "${repo_root}/tools/akibara-business/qa.sh"
	bash "${repo_root}/tools/akibara-releases/qa-search.sh"
	bash "${repo_root}/tools/akibara-releases/qa-checkout.sh"
	bash "${repo_root}/tools/akibara-releases/qa-theme-production-superset.sh"
	write_receipt "docker" "local-compose"
}

PRODUCTION_HOST=""
PRODUCTION_WEBROOT=""
PRODUCTION_BASE_URL=""
PRODUCTION_LOG_PATHS=""
AKB_REMOTE_TMP_ROOT="/tmp"

remote_command() {
	local script="$1" rendered
	printf -v rendered 'AKB_WEBROOT=%q AKB_LOG_PATHS=%q AKB_REMOTE_TMP_ROOT=%q bash -c %q' \
		"${AKB_WEBROOT}" "${AKB_LOG_PATHS}" "${AKB_REMOTE_TMP_ROOT}" "${script}"
	printf '%s' "${rendered}"
}

ssh_read() {
	local script="$1" command
	printf -v command 'AKB_WEBROOT=%q AKB_LOG_PATHS=%q AKB_REMOTE_TMP_ROOT=%q bash -s' \
		"${AKB_WEBROOT}" "${AKB_LOG_PATHS}" "${AKB_REMOTE_TMP_ROOT}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" <<< "${script}"
}

pre_backup_artifact_gate() {
	local script
	script='
set -Eeuo pipefail
environment_find() {
  find "${AKB_WEBROOT}" -xdev "$@"
}
IFS=";" read -r -a allowed_logs <<< "${AKB_LOG_PATHS}"
for log_path in "${allowed_logs[@]}"; do
  if [[ -e "${log_path}" ]]; then
    [[ -f "${log_path}" && ! -L "${log_path}" ]]
  fi
done

found_logs="$(environment_find -type f \
  \( -name "debug.log" -o -name "error_log" \) -print)"
for log_path in ${found_logs}; do
  allowed=0
  for expected in "${allowed_logs[@]}"; do
    [[ "${log_path}" == "${expected}" ]] && allowed=1
  done
  ((allowed == 1)) || {
    echo "Unexpected runtime log: ${log_path}" >&2
    exit 1
  }
done

for upgrade_dir in \
  "${AKB_WEBROOT}/wp-content/upgrade" \
  "${AKB_WEBROOT}/wp-content/upgrade-temp-backup"; do
  if [[ -e "${upgrade_dir}" ]]; then
    [[ -d "${upgrade_dir}" && ! -L "${upgrade_dir}" ]]
    test -z "$(find "${upgrade_dir}" -mindepth 1 ! -type d -print -quit)"
  fi
done

test ! -e "${AKB_WEBROOT}/.local-backups"
test ! -e "${AKB_WEBROOT}/wp-content/.local-backups"
[[ ! -e "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
test -z "$(find "${AKB_WEBROOT}" -maxdepth 1 -name ".maintenance.akb.*" -print -quit)"
test -z "$(
  environment_find -type f \( \
    -name "*.bak" -o -name "*.bk" -o -name "*.orig" -o -name "*.rej" -o \
    -name "*.tmp" -o -name "*.temp" -o -name "*.dump" -o -name "*.backup" -o \
    -name "*.sqlite" -o -name "*.tar" -o -name "*.tgz" -o \
    -name "*.zip" -o -name "*~" -o -name ".DS_Store" \
  \) -print -quit
)"
found_sql="$(environment_find -type f -name "*.sql" -print)"
for sql_path in ${found_sql}; do
  case "${sql_path}" in
    "${AKB_WEBROOT}/wp-content/plugins/akibara-business/migrations/"*.sql) ;;
    "${AKB_WEBROOT}/wp-content/plugins/litespeed-cache/src/data_structure/"*.sql) ;;
    *)
      echo "Unexpected SQL artifact: ${sql_path}" >&2
      exit 1
      ;;
  esac
done
# El residuo real de un swap directo se llama `.akibara-business-old-1788174146`
# o `.akibara-provider-access.php.old-1788174146`: punto DELANTE y epoch DETRAS
# de «old». El patron viejo pedia el prefijo sin punto y `.old` como sufijo, asi
# que daba limpio con el residuo puesto; el de mu-plugins llego a servirse en
# texto plano desde el docroot. Por eso tambien se barre mu-plugins.
test -z "$(
  find "${AKB_WEBROOT}/wp-content/plugins" "${AKB_WEBROOT}/wp-content/themes" \
    "${AKB_WEBROOT}/wp-content/mu-plugins" \
    -maxdepth 1 \( \
      -name "*akibara-*.new" -o -name "*akibara-*.old" -o \
      -name "*akibara-*.old-*" -o -name "*akibara-*-old-*" -o \
      -name "*akibara-*.previous" -o -name "*akibara-*.backup" -o \
      -name "*akibara-*.bak" -o -name "*akibara-*.tmp" -o -name "*akibara-*.temp" \
    \) -print -quit 2>/dev/null
)"
test -z "$(
  find "${AKB_REMOTE_TMP_ROOT}" -maxdepth 1 \( \
    -name "akibara-deploy.*.zip" -o -name "akb-deploy-*" -o \
    -name "akibara-*.previous" -o -name "akibara-*.backup" \
  \) -print -quit 2>/dev/null
)"
echo "PRE_BACKUP_ARTIFACT_GATE_PASS"'
	ssh_read "${script}"
}

zero_remote_artifact_gate() {
	local script
	script='
set -Eeuo pipefail
environment_find() {
  find "${AKB_WEBROOT}" -xdev "$@"
}
test ! -e "${AKB_WEBROOT}/.local-backups"
test ! -e "${AKB_WEBROOT}/wp-content/.local-backups"
IFS=";" read -r -a allowed_logs <<< "${AKB_LOG_PATHS}"
for log_path in "${allowed_logs[@]}"; do
  if [[ -e "${log_path}" ]]; then
    [[ -f "${log_path}" && ! -L "${log_path}" && ! -s "${log_path}" ]]
  fi
done
found_logs="$(environment_find -type f \
  \( -name "debug.log" -o -name "error_log" \) -print)"
for log_path in ${found_logs}; do
  allowed=0
  for expected in "${allowed_logs[@]}"; do
    [[ "${log_path}" == "${expected}" ]] && allowed=1
  done
  ((allowed == 1)) || {
    echo "Unexpected runtime log: ${log_path}" >&2
    exit 1
  }
  [[ ! -s "${log_path}" ]] || {
    echo "Allowlisted runtime log is not empty: ${log_path}" >&2
    exit 1
  }
done
for upgrade_dir in \
  "${AKB_WEBROOT}/wp-content/upgrade" \
  "${AKB_WEBROOT}/wp-content/upgrade-temp-backup"; do
  if [[ -e "${upgrade_dir}" ]]; then
    [[ -d "${upgrade_dir}" && ! -L "${upgrade_dir}" ]]
    test -z "$(find "${upgrade_dir}" -mindepth 1 -print -quit)"
  fi
done
test -z "$(find "${AKB_WEBROOT}" -maxdepth 1 -name ".maintenance.akb.*" -print -quit)"
test -z "$(
  environment_find -type f \( \
    -name "*.bak" -o -name "*.bk" -o -name "*.orig" -o -name "*.rej" -o \
    -name "*.tmp" -o -name "*.temp" -o -name "*.dump" -o -name "*.backup" -o \
    -name "*.sqlite" -o -name "*.tar" -o -name "*.tgz" -o \
    -name "*.zip" -o -name "*~" -o -name ".DS_Store" \
  \) -print -quit
)"
found_sql="$(environment_find -type f -name "*.sql" -print)"
for sql_path in ${found_sql}; do
  case "${sql_path}" in
    "${AKB_WEBROOT}/wp-content/plugins/akibara-business/migrations/"*.sql) ;;
    "${AKB_WEBROOT}/wp-content/plugins/litespeed-cache/src/data_structure/"*.sql) ;;
    *)
      echo "Unexpected SQL artifact: ${sql_path}" >&2
      exit 1
      ;;
  esac
done
# El residuo real de un swap directo se llama `.akibara-business-old-1788174146`
# o `.akibara-provider-access.php.old-1788174146`: punto DELANTE y epoch DETRAS
# de «old». El patron viejo pedia el prefijo sin punto y `.old` como sufijo, asi
# que daba limpio con el residuo puesto; el de mu-plugins llego a servirse en
# texto plano desde el docroot. Por eso tambien se barre mu-plugins.
test -z "$(
  find "${AKB_WEBROOT}/wp-content/plugins" "${AKB_WEBROOT}/wp-content/themes" \
    "${AKB_WEBROOT}/wp-content/mu-plugins" \
    -maxdepth 1 \( \
      -name "*akibara-*.new" -o -name "*akibara-*.old" -o \
      -name "*akibara-*.old-*" -o -name "*akibara-*-old-*" -o \
      -name "*akibara-*.previous" -o -name "*akibara-*.backup" -o \
      -name "*akibara-*.bak" -o -name "*akibara-*.tmp" -o -name "*akibara-*.temp" \
    \) -print -quit 2>/dev/null
)"
test -z "$(
  find "${AKB_REMOTE_TMP_ROOT}" -maxdepth 1 \( \
    -name "akibara-deploy.*.zip" -o -name "akb-deploy-*" -o \
    -name "akibara-*.previous" -o -name "akibara-*.backup" \
  \) -print -quit 2>/dev/null
)"
echo "ZERO_REMOTE_ARTIFACTS_PASS"'
	ssh_read "${script}"
}

remote_preflight() {
	local script
	script='
set -Eeuo pipefail
command -v sha256sum >/dev/null
command -v cmp >/dev/null
command -v grep >/dev/null
command -v ln >/dev/null
	command -v mktemp >/dev/null
	test -d "${AKB_WEBROOT}/wp-content/plugins/akibara-business"
	test -d "${AKB_WEBROOT}/wp-content/plugins/akibara-search"
	test -d "${AKB_WEBROOT}/wp-content/plugins/akibara-cart-checkout-blocks"
	test -d "${AKB_WEBROOT}/wp-content/themes/akibara-gp"
	wp --path="${AKB_WEBROOT}" core is-installed
	wp --path="${AKB_WEBROOT}" plugin get akibara-business --fields=name,status,version
	wp --path="${AKB_WEBROOT}" plugin get akibara-search --fields=name,status,version
	wp --path="${AKB_WEBROOT}" plugin get akibara-cart-checkout-blocks --fields=name,status,version
	wp --path="${AKB_WEBROOT}" theme get generatepress --fields=name,status,version
	wp --path="${AKB_WEBROOT}" theme get akibara-gp --fields=name,status,version
	grep -Eq "^[[:space:]]*Template:[[:space:]]+generatepress[[:space:]]*$" \
		"${AKB_WEBROOT}/wp-content/themes/akibara-gp/style.css"
	wp --path="${AKB_WEBROOT}" plugin is-active akibara-business
	wp --path="${AKB_WEBROOT}" plugin is-active akibara-search
	wp --path="${AKB_WEBROOT}" plugin is-active akibara-cart-checkout-blocks
	wp --path="${AKB_WEBROOT}" theme is-active akibara-gp'
	ssh_read "${script}"
}

verify_production_theme_preimage() {
	local output="$1" baseline="${2:-}" partial script
	local version normal_count appledouble_count normal_tree appledouble_tree
	local actual_appledouble expected_appledouble
	local release_normal_count release_normal_tree empty_tree_sha256
	local matches_historical=false matches_deployed_release=false
	local accepted_mode=""
	partial="${output}.partial"
	[[ "${active_release_profile}" == "production" ]] || {
		printf 'ERROR: Production theme preimage guard used outside production\n' >&2
		return 1
	}
	script='
set -Eeuo pipefail
root="${AKB_WEBROOT}/wp-content/themes/akibara-gp"
[[ -d "${root}" && ! -L "${root}" ]]
test -z "$(find "${root}" ! -type d ! -type f -print -quit)"
version="$(wp --path="${AKB_WEBROOT}" theme get akibara-gp --field=version)"
printf "@version\t%s\n" "${version}"
cd "${root}"
find . -type f -print | LC_ALL=C sort |
while IFS= read -r file; do
  relative="${file#./}"
  [[ "${relative}" =~ ^[A-Za-z0-9._/-]+$ ]]
  [[ "${relative}" != /* && "${relative}" != *".."* && "${relative}" != *"//"* ]]
  sha="$(sha256sum "${file}" | awk "{print \$1}")"
  case "${relative##*/}" in
    ._*) class="appledouble" ;;
    *) class="normal" ;;
  esac
  printf "%s\t%s\t%s\n" "${class}" "${sha}" "${relative}"
done'
	if ! ssh_read "${script}" > "${partial}"; then
		rm -f -- "${partial}"
		return 1
	fi
	mv -- "${partial}" "${output}"
	[[ -f "${output}" && ! -L "${output}" && -s "${output}" ]] || {
		printf 'ERROR: Production theme preimage capture is absent\n' >&2
		return 1
	}
	awk -F '\t' '
	  $1 == "@version" {
	    if (NF != 2 || seen_version++) exit 1
	    next
	  }
	  $1 == "normal" || $1 == "appledouble" {
	    if (NF != 3 || $2 !~ /^[a-f0-9]{64}$/ ||
	        $3 !~ /^[A-Za-z0-9._\/-]+$/ || $3 ~ /(^|\/)\.\.(\/|$)/ ||
	        seen_path[$3]++) exit 1
	    base = $3
	    sub(/^.*\//, "", base)
	    if (($1 == "appledouble") != (base ~ /^\._/)) exit 1
	    next
	  }
	  { exit 1 }
	  END { if (seen_version != 1) exit 1 }
	' "${output}" || {
		printf 'ERROR: Production theme preimage inventory format is invalid\n' >&2
		return 1
	}
	version="$(awk -F '\t' '$1 == "@version" { print $2 }' "${output}")"
	normal_count="$(awk -F '\t' '$1 == "normal" { count++ } END { print count + 0 }' "${output}")"
	appledouble_count="$(awk -F '\t' '$1 == "appledouble" { count++ } END { print count + 0 }' "${output}")"
	normal_tree="$(
		awk -F '\t' '$1 == "normal" { printf "%s\t%s\n", $2, $3 }' "${output}" |
			shasum -a 256 | awk '{print $1}'
	)"
	appledouble_tree="$(
		awk -F '\t' '$1 == "appledouble" { printf "%s\t%s\n", $2, $3 }' "${output}" |
			shasum -a 256 | awk '{print $1}'
	)"
	actual_appledouble="$(
		awk -F '\t' '$1 == "appledouble" { printf "%s\t%s\n", $2, $3 }' "${output}" |
			LC_ALL=C sort
	)"
	expected_appledouble="$(
		jq -r '.production_preimage.appledouble_files[]
		  | [.sha256, .path] | @tsv' "${superset_contract}" |
			LC_ALL=C sort
	)"
	[[ -f "${production_theme_runtime_inventory}" && ! -L "${production_theme_runtime_inventory}" ]] || {
		printf 'ERROR: Production theme runtime inventory is absent or unsafe\n' >&2
		return 1
	}
	awk -v prefix="akibara-gp/" '
	  NF != 2 || length($1) != 64 || $1 ~ /[^a-f0-9]/ ||
	      index($2, prefix) != 1 || $2 !~ /^[A-Za-z0-9._\/-]+$/ ||
	      $2 ~ /(^|\/)\.\.(\/|$)/ || seen[$2]++ {
	    exit 1
	  }
	  {
	    relative = substr($2, length(prefix) + 1)
	    base = relative
	    sub(/^.*\//, "", base)
	    if (relative == "" || base ~ /^\._/) exit 1
	    count++
	  }
	  END { if (count == 0) exit 1 }
	' "${production_theme_runtime_inventory}" || {
		printf 'ERROR: Production theme runtime inventory format is invalid\n' >&2
		return 1
	}
	release_normal_count="$(
		awk 'END { print NR + 0 }' "${production_theme_runtime_inventory}"
	)"
	release_normal_tree="$(
		awk -v prefix="akibara-gp/" '{
		  relative = substr($2, length(prefix) + 1)
		  printf "%s\t%s\n", $1, relative
		}' "${production_theme_runtime_inventory}" |
			shasum -a 256 | awk '{print $1}'
	)"
	empty_tree_sha256="$(printf '' | shasum -a 256 | awk '{print $1}')"

	if [[ "${version}" == "${production_theme_preimage_version}"
		&& "${normal_count}" == "${production_theme_preimage_normal_files}"
		&& "${normal_tree}" == "${production_theme_preimage_normal_tree_sha256}"
		&& "${appledouble_count}" == "${production_theme_preimage_appledouble_files}"
		&& "${appledouble_tree}" == "${production_theme_preimage_appledouble_tree_sha256}"
		&& "${actual_appledouble}" == "${expected_appledouble}" ]]; then
		matches_historical=true
	fi
	if [[ "${version}" == "${production_theme_version}"
		&& "${normal_count}" == "${production_theme_files}"
		&& "${release_normal_count}" == "${production_theme_files}"
		&& "${normal_tree}" == "${release_normal_tree}"
		&& "${appledouble_count}" == "0"
		&& "${appledouble_tree}" == "${empty_tree_sha256}"
		&& -z "${actual_appledouble}" ]]; then
		matches_deployed_release=true
	fi

	if [[ "${matches_historical}" == "true" ]]; then
		accepted_mode="historical"
	elif [[ "${matches_deployed_release}" == "true" ]]; then
		accepted_mode="deployed-release"
	else
		printf 'ERROR: Production theme preimage drifted: version=%s normal=%s/%s appledouble=%s/%s\n' \
			"${version}" "${normal_count}" "${normal_tree}" \
			"${appledouble_count}" "${appledouble_tree}" >&2
		return 1
	fi

	if [[ -n "${baseline}" ]]; then
		[[ -f "${baseline}" && ! -L "${baseline}" && -s "${baseline}" ]] || {
			printf 'ERROR: Production theme preimage baseline is absent or unsafe\n' >&2
			return 1
		}
		cmp -s -- "${baseline}" "${output}" || {
			printf 'ERROR: Production theme preimage changed during the release window\n' >&2
			return 1
		}
		note "PRODUCTION_THEME_PREIMAGE_CONTINUITY_PASS mode=${accepted_mode}"
		return 0
	fi

	note "PRODUCTION_THEME_PREIMAGE_PASS mode=${accepted_mode}"
}

verify_production_theme_zero_appledouble() {
	local script
	[[ "${active_release_profile}" == "production" ]] || {
		printf 'ERROR: AppleDouble production gate used outside production\n' >&2
		return 1
	}
	script='
set -Eeuo pipefail
root="${AKB_WEBROOT}/wp-content/themes/akibara-gp"
[[ -d "${root}" && ! -L "${root}" ]]
test -z "$(find "${root}" -type f -name "._*" -print -quit)"
echo "PRODUCTION_THEME_ZERO_APPLEDOUBLE_PASS"'
	ssh_read "${script}"
}

activate_maintenance_mode() {
	local script
	script='
set -Eeuo pipefail
maintenance_file="${AKB_WEBROOT}/.maintenance"
[[ ! -e "${maintenance_file}" && ! -L "${maintenance_file}" ]]
maintenance_tmp="$(mktemp "${AKB_WEBROOT}/.maintenance.akb.XXXXXX")"
cleanup_maintenance_tmp() {
  [[ -z "${maintenance_tmp:-}" ]] || rm -f -- "${maintenance_tmp}"
}
trap "cleanup_maintenance_tmp" EXIT
trap "cleanup_maintenance_tmp; exit 129" HUP
trap "cleanup_maintenance_tmp; exit 130" INT
trap "cleanup_maintenance_tmp; exit 143" TERM
[[ -f "${maintenance_tmp}" && ! -L "${maintenance_tmp}" ]]
chmod 0644 "${maintenance_tmp}"
printf "%s\n" "<?php \$upgrading = time(); ?>" > "${maintenance_tmp}"
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_tmp}"
ln -- "${maintenance_tmp}" "${maintenance_file}"
rm -f -- "${maintenance_tmp}"
maintenance_tmp=""
trap - EXIT HUP INT TERM
[[ -f "${maintenance_file}" && ! -L "${maintenance_file}" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
echo "MAINTENANCE_MODE_ACTIVE"'
	ssh_read "${script}"
}

verify_maintenance_mode() {
	local script
	script='
set -Eeuo pipefail
maintenance_file="${AKB_WEBROOT}/.maintenance"
[[ -f "${maintenance_file}" && ! -L "${maintenance_file}" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
echo "MAINTENANCE_MODE_VERIFIED"'
	ssh_read "${script}"
}

ensure_maintenance_mode() {
	local script
	script='
set -Eeuo pipefail
maintenance_file="${AKB_WEBROOT}/.maintenance"
if [[ -e "${maintenance_file}" || -L "${maintenance_file}" ]]; then
  [[ -f "${maintenance_file}" && ! -L "${maintenance_file}" ]]
  printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
else
  maintenance_tmp="$(mktemp "${AKB_WEBROOT}/.maintenance.akb.XXXXXX")"
  cleanup_maintenance_tmp() {
    [[ -z "${maintenance_tmp:-}" ]] || rm -f -- "${maintenance_tmp}"
  }
  trap "cleanup_maintenance_tmp" EXIT
  trap "cleanup_maintenance_tmp; exit 129" HUP
  trap "cleanup_maintenance_tmp; exit 130" INT
  trap "cleanup_maintenance_tmp; exit 143" TERM
  [[ -f "${maintenance_tmp}" && ! -L "${maintenance_tmp}" ]]
  chmod 0644 "${maintenance_tmp}"
  printf "%s\n" "<?php \$upgrading = time(); ?>" > "${maintenance_tmp}"
  printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_tmp}"
  ln -- "${maintenance_tmp}" "${maintenance_file}"
  rm -f -- "${maintenance_tmp}"
  maintenance_tmp=""
  trap - EXIT HUP INT TERM
fi
[[ -f "${maintenance_file}" && ! -L "${maintenance_file}" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
echo "MAINTENANCE_MODE_ENSURED"'
	ssh_read "${script}"
}

deactivate_maintenance_mode() {
	local script
	script='
set -Eeuo pipefail
maintenance_file="${AKB_WEBROOT}/.maintenance"
[[ -f "${maintenance_file}" && ! -L "${maintenance_file}" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
rm -f -- "${maintenance_file}"
[[ ! -e "${maintenance_file}" && ! -L "${maintenance_file}" ]]
echo "MAINTENANCE_MODE_INACTIVE"'
	ssh_read "${script}"
}

maintenance_absent_gate() {
	local script
	script='
set -Eeuo pipefail
[[ ! -e "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
echo "MAINTENANCE_FILE_ABSENT"'
	ssh_read "${script}"
}

purge_runtime_caches() {
	local script
	script='
set -Eeuo pipefail
maintenance_file="${AKB_WEBROOT}/.maintenance"
[[ -f "${maintenance_file}" && ! -L "${maintenance_file}" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
wp --path="${AKB_WEBROOT}" cache flush >/dev/null
if wp --path="${AKB_WEBROOT}" plugin is-active litespeed-cache; then
  wp --path="${AKB_WEBROOT}" eval "if (! class_exists(\"LiteSpeed\\\\Purge\")) { throw new RuntimeException(\"LiteSpeed Purge class unavailable\"); } \\LiteSpeed\\Purge::purge_all(\"Akibara code promotion under maintenance\");" >/dev/null
fi
[[ -f "${maintenance_file}" && ! -L "${maintenance_file}" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
echo "RUNTIME_CACHES_PURGED_UNDER_MAINTENANCE"'
	ssh_read "${script}"
}

purge_runtime_caches_live() {
	local script
	script='
set -Eeuo pipefail
maintenance_file="${AKB_WEBROOT}/.maintenance"
[[ ! -e "${maintenance_file}" && ! -L "${maintenance_file}" ]]
wp --path="${AKB_WEBROOT}" cache flush >/dev/null
if wp --path="${AKB_WEBROOT}" plugin is-active litespeed-cache; then
  wp --path="${AKB_WEBROOT}" litespeed-purge all >/dev/null
fi
[[ ! -e "${maintenance_file}" && ! -L "${maintenance_file}" ]]
echo "RUNTIME_CACHES_PURGED_LIVE"'
	ssh_read "${script}"
}

maintenance_http_gate() {
	local path meta http_code effective_url
	for path in "/" "/?akb-maintenance-check=$(date -u +%s)-$$"; do
		meta="$(
			curl --silent --show-error \
				--proto '=https' --proto-redir '=https' \
				--connect-timeout 15 --max-time 45 \
				--output /dev/null \
				--write-out '%{http_code}\t%{url_effective}' \
				"${AKB_BASE_URL}${path}"
		)"
		http_code="${meta%%$'\t'*}"
		effective_url="${meta#*$'\t'}"
		if [[ "${http_code}" != 503 ]]; then
			printf 'ERROR: Maintenance HTTP gate expected 503 for %s; received %s\n' \
				"${path}" "${http_code}" >&2
			return 1
		fi
		if [[ "${effective_url}" != "${AKB_BASE_URL}"
			&& "${effective_url}" != "${AKB_BASE_URL}/"* ]]; then
			printf 'ERROR: Maintenance HTTP gate escaped the canonical origin: %s\n' \
				"${effective_url}" >&2
			return 1
		fi
	done
	note "MAINTENANCE_HTTP_503_PASS"
}

refresh_maintenance_cache_barrier() {
	note "Refreshing the maintenance cache barrier after a cached live response."
	verify_maintenance_mode || return 1
	deactivate_maintenance_mode || return 1
	if ! purge_runtime_caches_live; then
		ensure_maintenance_mode >/dev/null 2>&1 || true
		return 1
	fi
	if ! activate_maintenance_mode; then
		ensure_maintenance_mode >/dev/null 2>&1 || true
		return 1
	fi
	purge_runtime_caches || return 1
	maintenance_http_gate
}

stream_code_backup() {
	local output="$1" script
	script='
set -Eeuo pipefail
	tar -C "${AKB_WEBROOT}" -czf - \
	  wp-content/plugins/akibara-business \
	  wp-content/plugins/akibara-search \
	  wp-content/plugins/akibara-cart-checkout-blocks \
	  wp-content/themes/akibara-gp'
	ssh_read "${script}" > "${output}.partial"
	tar -tzf "${output}.partial" >/dev/null
	mv -- "${output}.partial" "${output}"
	shasum -a 256 "${output}" > "${output}.sha256"
}

stream_database_backup() {
	local output="$1" script
	script='set -Eeuo pipefail; wp --path="${AKB_WEBROOT}" db export - --single-transaction --default-character-set=utf8mb4'
	ssh_read "${script}" | gzip -9 > "${output}.partial"
	gzip -t "${output}.partial"
	mv -- "${output}.partial" "${output}"
	shasum -a 256 "${output}" > "${output}.sha256"
}

stream_logs_backup() {
	local output="$1" script
	script='
set -Eeuo pipefail
IFS=";" read -r -a paths <<< "${AKB_LOG_PATHS}"
existing=()
for path in "${paths[@]}"; do
  if [[ -e "${path}" ]]; then
    [[ -f "${path}" && ! -L "${path}" ]]
    existing+=("${path}")
  fi
done
if (( ${#existing[@]} > 0 )); then
  tar -czf - -- "${existing[@]}"
else
  tar -czf - --files-from /dev/null
fi'
	ssh_read "${script}" > "${output}.partial"
	tar -tzf "${output}.partial" >/dev/null
	mv -- "${output}.partial" "${output}"
	shasum -a 256 "${output}" > "${output}.sha256"
}

cleanup_empty_upgrade_dirs() {
	local script
	script='
set -Eeuo pipefail
for upgrade_dir in \
  "${AKB_WEBROOT}/wp-content/upgrade" \
  "${AKB_WEBROOT}/wp-content/upgrade-temp-backup"; do
  if [[ -e "${upgrade_dir}" ]]; then
    [[ -d "${upgrade_dir}" && ! -L "${upgrade_dir}" ]]
    find "${upgrade_dir}" -depth -type d -empty -delete
  fi
  if [[ -e "${upgrade_dir}" ]]; then
    [[ -d "${upgrade_dir}" && ! -L "${upgrade_dir}" ]]
    test -z "$(find "${upgrade_dir}" -mindepth 1 -print -quit)"
  fi
done
echo "EMPTY_UPGRADE_DIRS_CLEAN"'
	ssh_read "${script}"
}

cleanup_release_upgrade_artifacts() {
	local script
	script='
set -Eeuo pipefail
[[ "${AKB_WEBROOT}" =~ ^/home/[A-Za-z0-9._-]+(/domains/[A-Za-z0-9.-]+)?/public_html$ ]]
for upgrade_dir in \
  "${AKB_WEBROOT}/wp-content/upgrade" \
  "${AKB_WEBROOT}/wp-content/upgrade-temp-backup"; do
  if [[ -e "${upgrade_dir}" ]]; then
    [[ -d "${upgrade_dir}" && ! -L "${upgrade_dir}" ]]
    find "${upgrade_dir}" -mindepth 1 -depth -delete
    rmdir -- "${upgrade_dir}"
  fi
  [[ ! -e "${upgrade_dir}" ]]
done
echo "RELEASE_UPGRADE_ARTIFACTS_REMOVED"'
	ssh_read "${script}"
}

cleanup_backed_up_logs_and_empty_upgrade_dirs() {
	local script
	script='
set -Eeuo pipefail
IFS=";" read -r -a log_paths <<< "${AKB_LOG_PATHS}"
for log_path in "${log_paths[@]}"; do
  if [[ -e "${log_path}" ]]; then
    [[ -f "${log_path}" && ! -L "${log_path}" ]]
    rm -f -- "${log_path}"
  fi
  if [[ -e "${log_path}" ]]; then
    [[ -f "${log_path}" && ! -L "${log_path}" && ! -s "${log_path}" ]]
  fi
done
for upgrade_dir in \
  "${AKB_WEBROOT}/wp-content/upgrade" \
  "${AKB_WEBROOT}/wp-content/upgrade-temp-backup"; do
  if [[ -e "${upgrade_dir}" ]]; then
    [[ -d "${upgrade_dir}" && ! -L "${upgrade_dir}" ]]
    find "${upgrade_dir}" -depth -type d -empty -delete
  fi
  if [[ -e "${upgrade_dir}" ]]; then
    [[ -d "${upgrade_dir}" && ! -L "${upgrade_dir}" ]]
    test -z "$(find "${upgrade_dir}" -mindepth 1 -print -quit)"
  fi
done
echo "BACKED_UP_LOGS_AND_EMPTY_UPGRADE_DIRS_REMOVED"'
	ssh_read "${script}"
}

capture_fingerprint() {
	local output="$1" php_code script
	php_code="$(sed '1{/^<?php$/d;}' "${fingerprint_php}")"
	printf -v script 'set -Eeuo pipefail; wp --path="${AKB_WEBROOT}" eval %q' "${php_code}"
	ssh_read "${script}" > "${output}.partial"
	grep -Fq $'@dataset\tlegacy_orders\t' "${output}.partial" \
		|| die "Fingerprint output is incomplete"
	LC_ALL=C sort "${output}.partial" > "${output}"
	rm -f -- "${output}.partial"
	validate_fingerprint_snapshot "${output}"
	shasum -a 256 "${output}" > "${output}.sha256"
}

validate_search_fingerprint() {
	local snapshot="$1"

	[[ -f "${snapshot}" && ! -L "${snapshot}" && -s "${snapshot}" ]] \
		|| die "Search fingerprint must be a nonempty regular file"
	LC_ALL=C sort -c "${snapshot}" >/dev/null 2>&1 \
		|| die "Search fingerprint is not canonically sorted"
	awk -F '\t' '
	  function is_sha256(value) {
	    return value ~ /^[a-f0-9]{64}$/
	  }
	  $1 == "@table" {
	    if (NF != 3 || $2 !~ /^akb_search_[a-z0-9_]+$/ || !is_sha256($3)) {
	      invalid = 1
	      next
	    }
	    table_markers[$2]++
	    next
	  }
	  $1 == "@dataset" {
	    if (NF != 3 || $3 != "present") {
	      invalid = 1
	      next
	    }
	    if ($2 == "search_options") {
	      options_marker++
	    } else if ($2 == "search_arrival_postmeta") {
	      arrival_marker++
	    } else if ($2 == "search_action_scheduler_groups") {
	      scheduler_groups_marker++
	    } else if ($2 == "search_action_scheduler_actions") {
	      scheduler_actions_marker++
	    } else {
	      invalid = 1
	    }
	    next
	  }
	  $1 ~ /^akb_search_[a-z0-9_]+$/ {
	    key_part_count = split($2, key_parts, ":")
	    if (NF != 3 || key_part_count != 2 || !is_sha256(key_parts[1]) ||
	        key_parts[2] !~ /^[1-9][0-9]*$/ || !is_sha256($3) ||
	        key_parts[1] != $3) {
	      invalid = 1
	      next
	    }
	    table_rows[$1]++
	    next
	  }
	  $1 ~ /^search_action_scheduler_(groups|actions)$/ {
	    key_part_count = split($2, key_parts, ":")
	    if (NF != 3 || key_part_count != 2 || !is_sha256(key_parts[1]) ||
	        key_parts[2] !~ /^[1-9][0-9]*$/ || !is_sha256($3) ||
	        key_parts[1] != $3) {
	      invalid = 1
	      next
	    }
	    scheduler_rows[$1]++
	    next
	  }
	  $1 == "search_options" {
	    if (NF != 3 || $2 !~ /^[A-Za-z0-9+\/=]+$/ || !is_sha256($3)) {
	      invalid = 1
	      next
	    }
	    options_rows++
	    next
	  }
	  $1 == "search_arrival_postmeta" {
	    if (NF != 3 || $2 !~ /^[0-9]+$/ || !is_sha256($3)) {
	      invalid = 1
	      next
	    }
	    arrival_rows++
	    next
	  }
	  {
	    invalid = 1
	  }
	  END {
	    if (invalid || options_marker != 1 || arrival_marker != 1 ||
	        scheduler_groups_marker != 1 || scheduler_actions_marker != 1) exit 1
	    if (table_markers["akb_search_docs"] != 1 ||
	        table_markers["akb_search_tokens"] != 1 ||
	        table_markers["akb_search_facet_index"] != 1 ||
	        table_markers["akb_search_qlog"] != 1) exit 1
	    for (table in table_markers) {
	      if (table_markers[table] != 1) exit 1
	    }
	    for (table in table_rows) {
	      if (table_markers[table] != 1) exit 1
	    }
	    exit 0
	  }
	' "${snapshot}" || die "Search fingerprint contract rejected"
}

capture_search_fingerprint() {
	local output="$1" php_code script

	php_code="$(sed '1{/^<?php$/d;}' "${search_fingerprint_php}")"
	printf -v script 'set -Eeuo pipefail; wp --path="${AKB_WEBROOT}" eval %q' "${php_code}"
	ssh_read "${script}" > "${output}.partial"
	LC_ALL=C sort "${output}.partial" > "${output}"
	rm -f -- "${output}.partial"
	validate_search_fingerprint "${output}"
	shasum -a 256 "${output}" > "${output}.sha256"
}

write_search_preservation_evidence() {
	local before="$1" after="$2" evidence_dir="$3"
	local marker="${evidence_dir}/search-state-preservation.txt"

	[[ "${before}" == "${evidence_dir}/search-state-before.tsv"
		&& "${after}" == "${evidence_dir}/search-state-after.tsv" ]] \
		|| die "Search fingerprints must use their exact evidence paths"
	validate_search_fingerprint "${before}"
	validate_search_fingerprint "${after}"
	cmp -s -- "${before}" "${after}" \
		|| die "Search database or index changed during the code release"
	[[ ! -e "${marker}" && ! -L "${marker}" ]] \
		|| die "Search-state preservation marker already exists"
	printf 'SEARCH_STATE_PRESERVATION_PASS\n' > "${marker}.partial"
	mv -- "${marker}.partial" "${marker}"
	shasum -a 256 "${marker}" > "${marker}.sha256"
}

validate_fingerprint_snapshot() {
	local snapshot="$1" dataset count marker_count
	[[ -f "${snapshot}" && ! -L "${snapshot}" && -s "${snapshot}" ]] \
		|| die "Fingerprint must be a nonempty regular file"
	LC_ALL=C sort -c "${snapshot}" >/dev/null 2>&1 \
		|| die "Fingerprint is not canonically sorted"
	awk -F '\t' '
	  BEGIN { ok = 1 }
	  $1 == "@dataset" {
	    if (NF != 3 || ($3 != "present" && $3 != "absent")) ok = 0
	    headers[$2]++
	    states[$2] = $3
	    next
	  }
	  {
	    if (NF != 3 || $2 !~ /^-?[0-9]+(:-?[0-9]+)*$/ || length($3) != 64 || $3 !~ /^[a-f0-9]+$/) ok = 0
	    key = $1 "\t" $2
	    if (rows[key]++) ok = 0
	    row_datasets[$1] = 1
	  }
	  END {
	    for (dataset in row_datasets) {
	      if (headers[dataset] != 1 || states[dataset] != "present") ok = 0
	    }
	    exit(ok ? 0 : 1)
	  }
	' "${snapshot}" || die "Fingerprint row contract rejected"
	marker_count="$(
		awk -F '\t' '$1 == "@dataset" { count++ } END { print count + 0 }' "${snapshot}"
	)"
	[[ "${marker_count}" == "${#protected_fingerprint_datasets[@]}" ]] \
		|| die "Fingerprint contains an unexpected dataset marker count"
	for dataset in "${protected_fingerprint_datasets[@]}"; do
		count="$(grep -Ec "^@dataset	${dataset}	(present|absent)$" "${snapshot}")"
		[[ "${count}" == 1 ]] || die "Fingerprint lacks one exact dataset marker: ${dataset}"
	done
}

historical_evidence_directory() {
	local relative="$1" prefix suffix run_id absolute
	[[ "${relative}" =~ ^[A-Za-z0-9._/-]+$ ]] \
		|| die "Unsafe historical evidence directory"
	[[ "${relative}" != /* && "${relative}" != *'..'* && "${relative}" != *'//'*
		&& "${relative}" != *'/./'* ]] \
		|| die "Unresolved historical evidence directory"
	prefix=".local-backups/code-promotion/production/"
	suffix="-${expected_release_digest}/evidence"
	case "${relative}" in
		"${prefix}"*"${suffix}") ;;
		*) die "Historical evidence directory is not tied to this production release" ;;
	esac
	run_id="${relative#"${prefix}"}"
	run_id="${run_id%"${suffix}"}"
	[[ "${run_id}" =~ ^[A-Za-z0-9TZ._-]+$ ]] \
		|| die "Unsafe historical run identifier"
	absolute="$(absolute_path "${repo_root}/${relative}")"
	[[ "${absolute}" == "${repo_root}/${relative}" ]] \
		|| die "Historical evidence directory escaped the repository"
	[[ -d "${absolute}" && ! -L "${absolute}" ]] \
		|| die "Historical evidence directory must be an existing non-symlink directory"
	printf '%s' "${absolute}"
}

write_historical_evidence() {
	local before="$1" after="$2" evidence_dir="$3"
	local evidence_relative before_relative after_relative marker receipt
	local marker_tmp receipt_tmp before_sha after_sha fingerprint_sha target

	evidence_relative="${evidence_dir#"${repo_root}/"}"
	[[ "${evidence_relative}" != "${evidence_dir}" ]] \
		|| die "Historical evidence directory must be inside the repository"
	[[ "$(historical_evidence_directory "${evidence_relative}")" == "${evidence_dir}" ]] \
		|| die "Historical evidence directory mismatch"
	[[ "${before}" == "${evidence_dir}/"*.tsv && "${before#"${evidence_dir}/"}" != */* ]] \
		|| die "Historical before snapshot must be a direct TSV child of the evidence directory"
	[[ "${after}" == "${evidence_dir}/"*.tsv && "${after#"${evidence_dir}/"}" != */* ]] \
		|| die "Historical after snapshot must be a direct TSV child of the evidence directory"
	[[ "${before}" != "${after}" ]] || die "Historical before and after snapshots must differ"
	validate_fingerprint_snapshot "${before}"
	validate_fingerprint_snapshot "${after}"
	cmp -s -- "${before}" "${after}" \
		|| die "Commerce fingerprint changed during the no-data-mutation release"

	before_relative="${before#"${repo_root}/"}"
	after_relative="${after#"${repo_root}/"}"
	before_sha="$(sha256_file "${before}")"
	after_sha="$(sha256_file "${after}")"
	fingerprint_sha="$(sha256_file "${fingerprint_php}")"
	target="${AKB_HOST}:${AKB_WEBROOT}"
	marker="${evidence_dir}/protected-history-preservation.txt"
	receipt="${evidence_dir}/protected-history-preservation.json"
	[[ ! -e "${marker}" && ! -e "${marker}.sha256"
		&& ! -e "${receipt}" && ! -e "${receipt}.sha256" ]] \
		|| die "Historical evidence already exists; refusing to overwrite it"
	marker_tmp="${marker}.partial.$$"
	receipt_tmp="${receipt}.partial.$$"
	trap 'rm -f -- "${marker_tmp:-}" "${receipt_tmp:-}"' RETURN

	cat > "${receipt_tmp}" <<EOF
{
	  "schema_version": 1,
	  "stage": "historical",
	  "status": "PASS",
	  "environment": "production",
	  "base_url": "${production_evidence_base_url}",
	  "release_digest": "${expected_release_digest}",
	  "target": "${target}",
  "fingerprint_sha256": "${fingerprint_sha}",
  "before": {
    "path": "${before_relative}",
    "sha256": "${before_sha}"
  },
  "after": {
    "path": "${after_relative}",
    "sha256": "${after_sha}"
  },
  "completed_at_utc": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF
	jq -e . "${receipt_tmp}" >/dev/null || die "Historical receipt JSON is invalid"
	mv -- "${receipt_tmp}" "${receipt}"
	shasum -a 256 "${receipt}" > "${receipt}.sha256"
	printf 'PROTECTED_HISTORY_PRESERVATION_PASS\n' > "${marker_tmp}"
	mv -- "${marker_tmp}" "${marker}"
	shasum -a 256 "${marker}" > "${marker}.sha256"
	trap - RETURN
}

install_component() {
	local kind="$1" slug="$2" version="$3" expected_sha="$4" archive="$5"
	local script command
	case "${kind}:${slug}" in
		plugin:akibara-business|plugin:akibara-search|plugin:akibara-cart-checkout-blocks|theme:akibara-gp) ;;
		*) die "Install target is outside the exact four-component release: ${kind}:${slug}" ;;
	esac
	script='
set -Eeuo pipefail
remote_zip="$(mktemp --suffix=.zip /tmp/akibara-deploy.XXXXXX)"
cleanup() { rm -f -- "${remote_zip}"; }
trap "cleanup || true" EXIT
trap "exit 129" HUP
trap "exit 130" INT
trap "exit 143" TERM
cat > "${remote_zip}"
[[ "$(sha256sum "${remote_zip}" | awk "{print \$1}")" == "${AKB_SHA}" ]]
if [[ "${AKB_KIND}" == "plugin" ]]; then
  wp --path="${AKB_WEBROOT}" plugin install "${remote_zip}" --force
  [[ "$(wp --path="${AKB_WEBROOT}" plugin get "${AKB_SLUG}" --field=version)" == "${AKB_VERSION}" ]]
  wp --path="${AKB_WEBROOT}" plugin is-active "${AKB_SLUG}"
else
  [[ "${AKB_KIND}" == "theme" && "${AKB_SLUG}" == "akibara-gp" ]]
  wp --path="${AKB_WEBROOT}" theme install "${remote_zip}" --force
  [[ "$(wp --path="${AKB_WEBROOT}" theme get "${AKB_SLUG}" --field=version)" == "${AKB_VERSION}" ]]
  wp --path="${AKB_WEBROOT}" theme is-active "${AKB_SLUG}"
fi
cleanup
trap - EXIT HUP INT TERM
[[ ! -e "${remote_zip}" ]]'
	printf -v command 'AKB_WEBROOT=%q AKB_KIND=%q AKB_SLUG=%q AKB_VERSION=%q AKB_SHA=%q bash -c %q' \
		"${AKB_WEBROOT}" "${kind}" "${slug}" "${version}" "${expected_sha}" "${script}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" < "${archive}"
}

verify_remote_inventory() {
	local evidence_dir="$1" i kind slug version inventory root expected remote script
	for ((i = 0; i < ${#component_slugs[@]}; i++)); do
		kind="${component_kinds[$i]}"
		slug="${component_slugs[$i]}"
		version="${component_versions[$i]}"
		inventory="${dist_dir}/${slug}-${version}.files.sha256"
		expected="${evidence_dir}/${slug}-expected.files.sha256"
		remote="${evidence_dir}/${slug}-remote.files.sha256"
		cp -- "${inventory}" "${expected}"
		if [[ "${kind}" == "theme" ]]; then
			root="${AKB_WEBROOT}/wp-content/themes"
		else
			root="${AKB_WEBROOT}/wp-content/plugins"
		fi
		printf -v script '
set -Eeuo pipefail
cd %q
[[ -d %q && ! -L %q ]]
test -z "$(find %q ! -type d ! -type f -print -quit)"
find %q -type f -print | LC_ALL=C sort |
while IFS= read -r file; do sha256sum "${file}"; done
' "${root}" "${slug}" "${slug}" "${slug}" "${slug}"
		ssh_read "${script}" > "${remote}.partial"
		mv -- "${remote}.partial" "${remote}"
		cmp -s "${expected}" "${remote}" || die "Remote inventory mismatch for ${slug}"
	done
}

verify_versions_and_active_state() {
	local script
	printf -v script '
set -Eeuo pipefail
	wp --path="${AKB_WEBROOT}" core is-installed
	[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-business --field=version)" == %q ]]
	[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-search --field=version)" == %q ]]
	[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-cart-checkout-blocks --field=version)" == %q ]]
	[[ "$(wp --path="${AKB_WEBROOT}" theme get akibara-gp --field=version)" == %q ]]
	grep -Eq "^[[:space:]]*Template:[[:space:]]+generatepress[[:space:]]*$" \
		"${AKB_WEBROOT}/wp-content/themes/akibara-gp/style.css"
	wp --path="${AKB_WEBROOT}" plugin is-active akibara-business
	wp --path="${AKB_WEBROOT}" plugin is-active akibara-search
	wp --path="${AKB_WEBROOT}" plugin is-active akibara-cart-checkout-blocks
	wp --path="${AKB_WEBROOT}" theme is-active akibara-gp' \
		"${component_versions[0]}" "${component_versions[1]}" \
		"${component_versions[2]}" "${component_versions[3]}"
	ssh_read "${script}"
}

verify_http() {
	local path meta http_code effective_url search_payload cart_payload
	for path in / /tienda/ /carrito/ /checkout/ /wp-json/; do
		note "HTTP gate: ${AKB_BASE_URL}${path}"
		meta="$(
			curl --silent --show-error --location \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 45 \
			--retry 2 --retry-delay 2 --retry-all-errors \
				--output /dev/null \
				--write-out '%{http_code}\t%{url_effective}' \
				"${AKB_BASE_URL}${path}"
		)"
		http_code="${meta%%$'\t'*}"
		effective_url="${meta#*$'\t'}"
		[[ "${http_code}" == 200 ]] \
			|| die "HTTP gate expected 200 for ${path}; received ${http_code}"
		[[ "${effective_url}" == "${AKB_BASE_URL}" || "${effective_url}" == "${AKB_BASE_URL}/"* ]] \
			|| die "HTTP gate escaped the canonical origin: ${effective_url}"
	done

	note "HTTP gate: real Search suggest contract"
	search_payload="$(
		curl --silent --show-error \
			--proto '=https' \
			--connect-timeout 15 --max-time 45 \
			--retry 2 --retry-delay 2 --retry-all-errors \
			"${AKB_BASE_URL}/wp-json/akibara/v1/search/suggest?q=one%20piece"
	)"
	jq -e '
	  type == "object"
	  and .query == "one piece"
	  and (.total | type == "number" and floor == . and . > 0)
	  and (.items | type == "array" and length > 0 and length <= 7)
	  and ([.items[]] | all(
	    (.title | type == "string" and length > 0)
	    and (.url | type == "string" and length > 0)
	    and (.badge | type == "string" and length > 0)
	    and (.badge_tone | type == "string" and length > 0)
	  ))
	' <<<"${search_payload}" >/dev/null \
		|| die "HTTP gate rejected the real Search suggest contract"

	note "HTTP gate: WooCommerce Store API extension contract"
	cart_payload="$(
		curl --silent --show-error \
			--proto '=https' \
			--connect-timeout 15 --max-time 45 \
			--retry 2 --retry-delay 2 --retry-all-errors \
			"${AKB_BASE_URL}/wp-json/wc/store/v1/cart"
	)"
	jq -e '
	  (.extensions.akibara_cart_checkout_blocks | type == "object")
	  and (.extensions.akibara_cart_checkout_blocks | has("free_shipping_html"))
	  and (.extensions.akibara_cart_checkout_blocks | has("show_free_shipping_on_cart"))
	  and (.extensions.akibara_cart_checkout_blocks | has("show_free_shipping_on_checkout"))
	  and (.extensions.akibara_cart_checkout_blocks | has("preventa_count"))
	  and (.extensions.akibara_cart_checkout_blocks | has("preventa_is_mixed"))
	  and (.extensions.akibara_cart_checkout_blocks | has("preventa_summary_value"))
	' <<<"${cart_payload}" >/dev/null \
		|| die "HTTP gate rejected the Cart/Checkout Store API contract"
}

verify_production_redirect_prerequisites() {
	local -a sources destinations
	local probe_root index source destination headers meta http_code redirect_url
	local redirect_by destination_meta destination_code destination_effective
	local destination_rest destination_redirects

	[[ "${AKB_BASE_URL}" == "${production_evidence_base_url}" ]] \
		|| die "Production redirect prerequisites require the canonical production origin"
	sources=(
		"/generos/"
		"/store-listing/"
		"/jujutsu-kaisen-en-que-orden-leer-el-manga-guia-definitiva-2026/"
		"/guia-de-dandadan-desde-que-tomo-leer-el-manga-despues-del-anime/"
		"/cart/"
		"/preguntas-frecuentes/"
		"/mi-cuenta/downloads/"
	)
	destinations=(
		"/tienda/"
		"/tienda/"
		"/en-que-tomo-continuar-jujutsu-kaisen/"
		"/dandadan-guia-para-leer-el-manga-despues-del-anime/"
		"/carrito/"
		"/faq/"
		"/mi-cuenta/"
	)
	[[ "${#sources[@]}" == "${#destinations[@]}" ]] \
		|| die "Production redirect prerequisite contract is inconsistent"

	probe_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-production-redirects.XXXXXX")"
	trap 'case "${probe_root:-}" in */akibara-production-redirects.*) rm -rf -- "${probe_root}" ;; esac' RETURN

	for ((index = 0; index < ${#sources[@]}; index++)); do
		source="${sources[$index]}"
		destination="${destinations[$index]}"
		headers="${probe_root}/headers-${index}.txt"
		meta="$(
			curl --silent --show-error \
				--proto '=https' --proto-redir '=https' \
				--connect-timeout 15 --max-time 45 \
				--retry 2 --retry-delay 2 --retry-all-errors \
				--header 'Cache-Control: no-cache' \
				--dump-header "${headers}" \
				--output /dev/null \
				--write-out '%{http_code}\t%{redirect_url}' \
				"${AKB_BASE_URL}${source}"
		)"
		http_code="${meta%%$'\t'*}"
		redirect_url="${meta#*$'\t'}"
		redirect_by="$(
			awk -F ':' '
			  tolower($1) == "x-redirect-by" {
			    value = substr($0, index($0, ":") + 1)
			    sub(/^[[:space:]]+/, "", value)
			    sub(/\r$/, "", value)
			    print value
			  }
			' "${headers}" | tail -n 1
		)"
		[[ "${http_code}" == 301 ]] \
			|| die "Production prerequisite expected 301 for ${source}; received ${http_code}"
		[[ "${redirect_url}" == "${AKB_BASE_URL}${destination}" ]] \
			|| die "Production prerequisite has the wrong Location for ${source}: ${redirect_url}"
		[[ "${redirect_by}" == "Rank Math" ]] \
			|| die "Production prerequisite is not owned by Rank Math for ${source}: ${redirect_by:-missing}"

		destination_meta="$(
			curl --silent --show-error \
				--proto '=https' \
				--connect-timeout 15 --max-time 45 \
				--retry 2 --retry-delay 2 --retry-all-errors \
				--output /dev/null \
				--write-out '%{http_code}\t%{url_effective}\t%{num_redirects}' \
				"${AKB_BASE_URL}${destination}"
		)"
		destination_code="${destination_meta%%$'\t'*}"
		destination_rest="${destination_meta#*$'\t'}"
		destination_effective="${destination_rest%%$'\t'*}"
		destination_redirects="${destination_rest##*$'\t'}"
		[[ "${destination_code}" == 200 ]] \
			|| die "Production prerequisite destination is not 200 for ${source}: ${destination_code}"
		[[ "${destination_effective}" == "${AKB_BASE_URL}${destination}" ]] \
			|| die "Production prerequisite destination drifted for ${source}: ${destination_effective}"
		[[ "${destination_redirects}" == 0 ]] \
			|| die "Production prerequisite destination has an extra redirect for ${source}"
		printf 'PASS\t%s\t301\t%s\tRank Math\t200\n' "${source}" "${destination}"
	done

	trap - RETURN
	rm -rf -- "${probe_root}"
	note "PRODUCTION_RANK_MATH_HTTP_PREREQUISITES_PASS"
}

restore_code_from_local_stream() {
	local backup="$1" script
	[[ -f "${backup}" && ! -L "${backup}"
		&& -f "${backup}.sha256" && ! -L "${backup}.sha256" ]] || return 1
	shasum -a 256 -c "${backup}.sha256" >/dev/null || return 1
	tar -tzf "${backup}" >/dev/null || return 1
	script='
set -Eeuo pipefail
[[ "${AKB_WEBROOT}" =~ ^/home/[A-Za-z0-9._-]+(/domains/[A-Za-z0-9.-]+)?/public_html$ ]]
rm -rf -- \
  "${AKB_WEBROOT}/wp-content/plugins/akibara-business" \
  "${AKB_WEBROOT}/wp-content/plugins/akibara-search" \
  "${AKB_WEBROOT}/wp-content/plugins/akibara-cart-checkout-blocks" \
  "${AKB_WEBROOT}/wp-content/themes/akibara-gp"
tar -C "${AKB_WEBROOT}" -xzf -'
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "$(remote_command "${script}")" < "${backup}"
}

cleanup_on_failure() {
	local exit_status="$1"
	local rollback_safe=1 maintenance_verified=0
	((exit_status != 0)) || return 0
	set +e
	if [[ "${mutation_started:-0}" == 1 ]]; then
			note "Failure detected: ensuring managed non-expiring maintenance mode before rollback."
		if ensure_maintenance_mode; then
			maintenance_mode_active=1
			if purge_runtime_caches && maintenance_http_gate; then
				maintenance_verified=1
			elif refresh_maintenance_cache_barrier; then
				maintenance_verified=1
			else
				rollback_safe=0
				note "Rollback stopped: maintenance could not be proven externally before restore."
			fi
		else
			rollback_safe=0
			note "Rollback stopped: maintenance mode could not be ensured."
		fi
		if [[ "${maintenance_verified}" == 1 ]]; then
				note "Failure detected: restoring all four allowlisted code trees from the local stream."
			if ! restore_code_from_local_stream "${code_backup:-}"; then
				rollback_safe=0
				note "Rollback stopped: the local code stream could not be validated or restored."
			elif [[ "${active_release_profile}" == "production" ]] \
				&& ! verify_production_theme_preimage \
					"${active_promotion_evidence_dir}/production-theme-preimage-after-rollback.tsv" \
					"${active_promotion_evidence_dir}/production-theme-preimage-before-backup.tsv"; then
				rollback_safe=0
				note "Rollback stopped: the restored production theme differs from its exact preimage."
			elif ! purge_runtime_caches || ! maintenance_http_gate; then
				rollback_safe=0
				note "Rollback stopped: restored-code cache/HTTP maintenance gate failed."
			elif ! (
					capture_fingerprint "${active_promotion_evidence_dir}/protected-after-rollback.tsv"
					capture_search_fingerprint "${active_promotion_evidence_dir}/search-state-after-rollback.tsv"
			); then
				rollback_safe=0
				note "Rollback stopped: post-rollback preservation fingerprints could not be captured."
			elif ! cmp -s -- \
				"${active_promotion_evidence_dir}/protected-before.tsv" \
				"${active_promotion_evidence_dir}/protected-after-rollback.tsv"; then
				rollback_safe=0
				note "Fail-closed: commerce state changed and was not restored by the code rollback."
			elif ! cmp -s -- \
					"${active_promotion_evidence_dir}/search-state-before.tsv" \
					"${active_promotion_evidence_dir}/search-state-after-rollback.tsv"; then
					rollback_safe=0
					note "Fail-closed: Search database/index changed and remains different after rollback."
			fi
		fi
	fi
	if [[ "${remote_cleanup_authorized:-0}" == 1 ]]; then
		note "Failure detected: backing up failure logs and removing only release-created artifacts before reopening."
		if stream_logs_backup "${active_promotion_backup_dir}/logs/runtime-logs-on-failure.tar.gz" \
			&& cleanup_release_upgrade_artifacts \
			&& cleanup_backed_up_logs_and_empty_upgrade_dirs \
			&& zero_remote_artifact_gate; then
			remote_cleanup_authorized=0
		else
			rollback_safe=0
			note "Fail-closed: release artifacts could not be proven absent after rollback."
		fi
	fi
	if [[ "${maintenance_mode_active:-0}" == 1 ]]; then
		if [[ "${rollback_safe}" == 1 ]]; then
			note "Failure detected: leaving the safe site outside maintenance mode."
			if deactivate_maintenance_mode \
				&& purge_runtime_caches_live \
				&& maintenance_absent_gate; then
				maintenance_mode_active=0
			else
				note "Fail-closed: maintenance state could not be cleared safely."
				if ensure_maintenance_mode; then
					maintenance_mode_active=1
					note "Fail-closed: managed maintenance was restored after live cache cleanup failed."
				fi
			fi
		else
			note "Fail-closed: maintenance remains active because rollback was not safe."
		fi
	fi
	set -e
}

promotion_exit_handler() {
	local exit_status=$?
	trap - EXIT HUP INT TERM
	cleanup_on_failure "${exit_status}"
	exit "${exit_status}"
}

promotion_signal_handler() {
	local exit_status="$1"
	trap - EXIT HUP INT TERM
	cleanup_on_failure "${exit_status}"
	exit "${exit_status}"
}

render_plan() {
	select_release_profile production
	verify_release_contract
	cat <<EOF
DRY RUN ONLY — no ssh, curl, or remote command was executed.

Release: ${release_set}
Digest:  ${expected_release_digest}
Target:  production
Host:    ${AKB_HOST}
Webroot: ${AKB_WEBROOT}
URL:     ${AKB_BASE_URL}

Sequential gates:
  1. Verify exact local ZIP checksums, safe paths, counts, and file inventories.
  2. Require checksum-protected Docker PASS receipt.
  3. Require seven observable 301 redirects with exact Location,
     X-Redirect-By: Rank Math, and a final 200 destination. This pipeline never
     reads or writes Rank Math DB/UI.
  4. Read-only pre-backup gate: only exact allowlisted logs, empty upgrade dirs,
     and reviewed runtime SQL may exist.
  5. Stream all four target code trees, plus database and allowlisted logs,
     into local-only backups.
  6. Delete only those backed-up logs; rmdir the two verified-empty upgrade dirs.
  7. Require the strict zero-artifact gate.
  8. Enter fail-closed non-expiring maintenance and prove external HTTP 503.
  9. Under maintenance, capture exact commerce and the complete read-only
     Search database/index fingerprint.
 10. Install Business, Search, Checkout, and theme in that exact order; every
     ZIP uses remote mktemp, SHA-256, and trap deletion.
 11. After each target, verify maintenance/HTTP 503, rmdir empty upgrade
     dirs, and require zero artifacts.
 12. Verify all target versions/inventories and require exact commerce and
     Search-state before/after equality.
 13. Purge caches again for the verified code, leave maintenance mode, require
     its file absent, verify canonical HTTP paths, rerun zero-artifact gate,
     and write the production receipt.

Any failure stops production. If code mutation began, all four target code trees
are restored from the local streamed backup. Commerce and Search-state
fingerprints are recaptured after rollback; any remaining difference leaves the
site fail-closed in maintenance. The database is never blindly restored.

This code pipeline never creates WordPress users. Temporary administrators for
official UI flows are managed only by ops/akibara-ui-temp-admin.sh.
EOF
}

promote_environment() {
	local stamp backup_dir evidence_dir deployment_index i
	select_release_profile production
	verify_artifacts
	verify_receipt "docker" "local-compose"
	for command_name in awk cmp curl find grep gzip jq sed shasum ssh tar; do
		require_command "${command_name}"
	done

	stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"
	backup_dir="${repo_root}/.local-backups/code-promotion/production/${stamp}-${expected_release_digest}"
	evidence_dir="${backup_dir}/evidence"
	mkdir -p "${backup_dir}/code" "${backup_dir}/database" "${backup_dir}/logs" "${evidence_dir}"
	chmod 0700 "${backup_dir}"
	active_promotion_backup_dir="${backup_dir}"
	active_promotion_evidence_dir="${evidence_dir}"
	code_backup="${backup_dir}/code/runtime-before.tar.gz"
	mutation_started=0
	remote_cleanup_authorized=0
	maintenance_mode_active=0
	trap promotion_exit_handler EXIT
	trap 'promotion_signal_handler 129' HUP
	trap 'promotion_signal_handler 130' INT
	trap 'promotion_signal_handler 143' TERM

	verify_production_redirect_prerequisites \
		| tee "${evidence_dir}/production-rank-math-http-prerequisites.txt"
	remote_preflight | tee "${evidence_dir}/remote-preflight.txt"
	verify_production_theme_preimage \
		"${evidence_dir}/production-theme-preimage-before-backup.tsv"
	pre_backup_artifact_gate | tee "${evidence_dir}/pre-backup-artifact-gate.txt"
	stream_code_backup "${code_backup}"
	stream_database_backup "${backup_dir}/database/database-before.sql.gz"
	stream_logs_backup "${backup_dir}/logs/runtime-logs-before.tar.gz"
	remote_cleanup_authorized=1
	cleanup_backed_up_logs_and_empty_upgrade_dirs \
		| tee "${evidence_dir}/backed-up-log-cleanup.txt"
	zero_remote_artifact_gate | tee "${evidence_dir}/zero-remote-artifacts-before.txt"

	purge_runtime_caches_live \
		| tee "${evidence_dir}/runtime-caches-purged-before-maintenance.txt"
	activate_maintenance_mode | tee "${evidence_dir}/maintenance-mode-active.txt"
	maintenance_mode_active=1
	purge_runtime_caches | tee "${evidence_dir}/runtime-caches-purged-before-mutation.txt"
	if ! maintenance_http_gate \
		| tee "${evidence_dir}/maintenance-http-before-mutation.txt"; then
		refresh_maintenance_cache_barrier \
			| tee "${evidence_dir}/maintenance-cache-barrier-refreshed.txt"
		maintenance_http_gate \
			| tee "${evidence_dir}/maintenance-http-before-mutation-after-refresh.txt"
	fi
	verify_production_theme_preimage \
		"${evidence_dir}/production-theme-preimage-under-maintenance.tsv" \
		"${evidence_dir}/production-theme-preimage-before-backup.tsv"
	capture_fingerprint "${evidence_dir}/protected-before.tsv"
	capture_search_fingerprint "${evidence_dir}/search-state-before.tsv"
	mutation_started=1
	for deployment_index in "${deployment_order[@]}"; do
		i="${deployment_index}"
		verify_maintenance_mode \
			| tee "${evidence_dir}/maintenance-verified-before-${component_slugs[$i]}.txt"
		if [[ "${component_slugs[$i]}" == "akibara-gp" ]]; then
			verify_production_theme_preimage \
				"${evidence_dir}/production-theme-preimage-before-theme-install.tsv" \
				"${evidence_dir}/production-theme-preimage-before-backup.tsv"
		fi
		install_component \
			"${component_kinds[$i]}" "${component_slugs[$i]}" \
			"${component_versions[$i]}" "${component_sha256[$i]}" \
			"${dist_dir}/${component_slugs[$i]}-${component_versions[$i]}.zip"
		verify_maintenance_mode \
			| tee "${evidence_dir}/maintenance-verified-after-${component_slugs[$i]}.txt"
		maintenance_http_gate \
			| tee "${evidence_dir}/maintenance-http-after-${component_slugs[$i]}.txt"
		stream_logs_backup \
			"${backup_dir}/logs/runtime-logs-after-${component_slugs[$i]}.tar.gz"
		cleanup_backed_up_logs_and_empty_upgrade_dirs \
			| tee "${evidence_dir}/runtime-cleanup-after-${component_slugs[$i]}.txt"
		cleanup_empty_upgrade_dirs
		zero_remote_artifact_gate
	done

	verify_maintenance_mode | tee "${evidence_dir}/maintenance-verified-before-validation.txt"
	verify_versions_and_active_state
	verify_remote_inventory "${evidence_dir}"
	verify_production_theme_zero_appledouble \
		| tee "${evidence_dir}/production-theme-zero-appledouble-under-maintenance.txt"
	capture_fingerprint "${evidence_dir}/protected-after.tsv"
	capture_search_fingerprint "${evidence_dir}/search-state-after.tsv"
	write_search_preservation_evidence \
		"${evidence_dir}/search-state-before.tsv" \
		"${evidence_dir}/search-state-after.tsv" \
		"${evidence_dir}"
	write_historical_evidence \
		"${evidence_dir}/protected-before.tsv" \
		"${evidence_dir}/protected-after.tsv" \
		"${evidence_dir}"
	verify_maintenance_mode | tee "${evidence_dir}/maintenance-verified-before-final-purge.txt"
	purge_runtime_caches | tee "${evidence_dir}/runtime-caches-purged-after-validation.txt"
	maintenance_http_gate | tee "${evidence_dir}/maintenance-http-after-validation.txt"
	stream_logs_backup "${backup_dir}/logs/runtime-logs-after-validation.tar.gz"
	cleanup_backed_up_logs_and_empty_upgrade_dirs \
		| tee "${evidence_dir}/runtime-cleanup-after-validation.txt"
	cleanup_empty_upgrade_dirs
	zero_remote_artifact_gate
	deactivate_maintenance_mode | tee "${evidence_dir}/maintenance-mode-inactive.txt"
	purge_runtime_caches_live \
		| tee "${evidence_dir}/runtime-caches-purged-live-after-validation.txt"
	maintenance_absent_gate | tee "${evidence_dir}/maintenance-file-absent.txt"
	maintenance_mode_active=0
	verify_http
	zero_remote_artifact_gate | tee "${evidence_dir}/zero-remote-artifacts.txt"
	verify_production_theme_zero_appledouble \
		| tee "${evidence_dir}/production-theme-zero-appledouble-final.txt"
	shasum -a 256 "${evidence_dir}/zero-remote-artifacts.txt" \
		> "${evidence_dir}/zero-remote-artifacts.txt.sha256"
	mutation_started=0
	trap - EXIT HUP INT TERM
	write_receipt \
		"production" "${AKB_HOST}:${AKB_WEBROOT}" \
		"${evidence_dir#"${repo_root}/"}"
	note "PASS: production promotion completed with local evidence in ${backup_dir}"
}

if [[ "${AKB_PROMOTION_LIBRARY_ONLY:-0}" == 1 ]]; then
	return 0 2>/dev/null || exit 0
fi

command_name="${1:-}"
[[ -n "${command_name}" ]] || {
	usage
	exit 2
}
if [[ "${command_name}" == "-h" || "${command_name}" == "--help" ]]; then
	usage
	exit 0
fi
shift || true

config_file=""
apply=0
confirmation=""
while (($# > 0)); do
	case "$1" in
		--config)
			(($# >= 2)) || die "--config requires a value"
			config_file="$2"
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
		*) die "Unknown argument: $1" ;;
	esac
done

case "${command_name}" in
	verify-artifacts)
		[[ -z "${config_file}${confirmation}" && "${apply}" == 0 ]] \
			|| die "verify-artifacts accepts no options"
		verify_artifacts
		;;
	local-gate)
		[[ -z "${config_file}${confirmation}" && "${apply}" == 0 ]] \
			|| die "local-gate accepts no options"
		run_local_gate
		;;
	plan|promote)
		[[ -n "${config_file}" ]] || die "--config is required"
		akb_load_promotion_config "$(absolute_path "${config_file}")"
		akb_select_promotion_environment production
		select_release_profile production
		if [[ "${command_name}" == "plan" ]]; then
			[[ "${apply}" == 0 && -z "${confirmation}" ]] || die "plan rejects --apply and --confirm"
			render_plan
		else
			[[ "${apply}" == 1 ]] || die "promote requires --apply"
			[[ "${confirmation}" == "${release_set}" ]] || die "Exact --confirm release set is required"
			promote_environment
		fi
		;;
	*)
		usage
		die "Unknown command: ${command_name}"
		;;
esac
