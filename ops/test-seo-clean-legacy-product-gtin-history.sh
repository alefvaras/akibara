#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_MIGRATION="${AKB_REPO_ROOT}/ops/seo-clean-legacy-product-gtin-history.sql"
AKB_MANIFEST="${AKB_REPO_ROOT}/ops/seo-clean-legacy-product-gtin-history-manifest.json"
AKB_DB_SERVICE="db"
AKB_DB_USER="${AKB_DB_USER:-root}"
AKB_DB_PASSWORD="${AKB_DB_PASSWORD:-root_local}"
AKB_TEST_DB="akb_test_legacy_gtin_cleanup_${PPID}_$$"
AKB_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/akb-legacy-gtin-cleanup.XXXXXX")"

akb_mariadb() {
	docker compose -f "${AKB_REPO_ROOT}/compose.yaml" exec -T "${AKB_DB_SERVICE}" \
		mariadb "-u${AKB_DB_USER}" "-p${AKB_DB_PASSWORD}" "$@"
}

akb_cleanup() {
	if [[ "${AKB_TEST_DB}" =~ ^akb_test_legacy_gtin_cleanup_[0-9]+_[0-9]+$ ]]; then
		akb_mariadb -e "DROP DATABASE IF EXISTS \`${AKB_TEST_DB}\`;" >/dev/null 2>&1 || true
	fi
	case "${AKB_TMP_DIR}" in
		*/akb-legacy-gtin-cleanup.*) rm -rf -- "${AKB_TMP_DIR}" ;;
	esac
}
trap akb_cleanup EXIT

# The synthetic fixture below reproduces the manifest cardinalities exactly
# (rows / distinct values / mismatches per key). If the manifest is regenerated
# these assertions fail first, with a message naming the drifted figure, so the
# fixture is updated on purpose instead of a cryptic grep failure later.
readarray -t AKB_MANIFEST_HASHES < <(
	node - "${AKB_MANIFEST}" "${AKB_MIGRATION}" <<'NODE'
const fs = require('node:fs');
const manifest = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const sql = fs.readFileSync(process.argv[3], 'utf8');
const expect = (actual, wanted, label) => {
	if (actual !== wanted) {
		throw new Error(`${label}: manifest says ${actual}, fixture builds ${wanted}`);
	}
};
expect(manifest.reviewed_original_total_rows, 4221, 'reviewed_original_total_rows');
expect(manifest.protected_global_unique_id.rows, 1568, 'protected rows');
const fixture = {
	_wc_gtin: { rows: 1525, distinct_values_binary: 1466, values_different_from_global_unique_id: 62 },
	rank_math_snippet_product_gtin: { rows: 1525, distinct_values_binary: 1466, values_different_from_global_unique_id: 76 },
	rank_math_gtin_code: { rows: 1171, distinct_values_binary: 1166, values_different_from_global_unique_id: 21 },
};
const keys = manifest.historical_keys.map((row) => row.meta_key);
expect(keys.join(','), Object.keys(fixture).join(','), 'historical key order');
for (const row of manifest.historical_keys) {
	for (const field of Object.keys(fixture[row.meta_key])) {
		expect(row[field], fixture[row.meta_key][field], `${row.meta_key}.${field}`);
	}
	expect(row.distinct_product_ids, row.rows, `${row.meta_key}.distinct_product_ids`);
}
for (const row of [
	manifest.protected_global_unique_id,
	...manifest.historical_keys,
]) {
	if (!/^[a-f0-9]{64}$/.test(row.sha256_post_id_and_hex_value)) {
		throw new Error('manifest contains an invalid SHA-256');
	}
	if (!sql.includes(row.sha256_post_id_and_hex_value)) {
		throw new Error(`SQL does not contain manifest hash ${row.sha256_post_id_and_hex_value}`);
	}
	process.stdout.write(`${row.sha256_post_id_and_hex_value}\n`);
}
NODE
)

if [[ "${#AKB_MANIFEST_HASHES[@]}" -ne 4 ]]; then
	echo "FAIL: manifest/SQL contract check did not yield four fingerprints" >&2
	exit 1
fi

akb_mariadb -e "CREATE DATABASE \`${AKB_TEST_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
akb_mariadb "${AKB_TEST_DB}" <<'SQL'
CREATE TABLE wp_posts (
	ID BIGINT UNSIGNED NOT NULL PRIMARY KEY,
	post_type VARCHAR(32) NOT NULL,
	post_status VARCHAR(32) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE wp_postmeta (
	meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	post_id BIGINT UNSIGNED NOT NULL,
	meta_key VARCHAR(255),
	meta_value LONGTEXT,
	KEY post_id (post_id),
	KEY meta_key (meta_key(191))
) ENGINE=InnoDB;

INSERT INTO wp_posts (ID, post_type, post_status)
SELECT seq, 'product', 'publish'
FROM seq_1_to_1568;

CREATE TABLE fixture_gtin (
	post_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
	gtin CHAR(13) NOT NULL
) ENGINE=MEMORY;

INSERT INTO fixture_gtin (post_id, gtin)
SELECT
	post_id,
	CONCAT(
		base,
		MOD(
			10 - MOD(
				CAST(SUBSTRING(base, 1, 1) AS UNSIGNED)
				+ 3 * CAST(SUBSTRING(base, 2, 1) AS UNSIGNED)
				+ CAST(SUBSTRING(base, 3, 1) AS UNSIGNED)
				+ 3 * CAST(SUBSTRING(base, 4, 1) AS UNSIGNED)
				+ CAST(SUBSTRING(base, 5, 1) AS UNSIGNED)
				+ 3 * CAST(SUBSTRING(base, 6, 1) AS UNSIGNED)
				+ CAST(SUBSTRING(base, 7, 1) AS UNSIGNED)
				+ 3 * CAST(SUBSTRING(base, 8, 1) AS UNSIGNED)
				+ CAST(SUBSTRING(base, 9, 1) AS UNSIGNED)
				+ 3 * CAST(SUBSTRING(base, 10, 1) AS UNSIGNED)
				+ CAST(SUBSTRING(base, 11, 1) AS UNSIGNED)
				+ 3 * CAST(SUBSTRING(base, 12, 1) AS UNSIGNED),
				10
			),
			10
		)
	)
FROM (
	SELECT seq AS post_id, CONCAT('97800000', LPAD(seq, 4, '0')) AS base
	FROM seq_1_to_1568
) AS source;

INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT post_id, '_global_unique_id', gtin FROM fixture_gtin;

INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
	(1, '_sku', 'SKU-KEEP-1'),
	(2, '_price', '12990'),
	(3, 'unrelated_meta', 'must remain byte-for-byte');
SQL

akb_insert_reviewed_history() {
	# Cardinalities mirror the 2026-09-02 production manifest:
	#   _wc_gtin                        1525 rows, 1466 distinct, 62 mismatches
	#   rank_math_snippet_product_gtin  1525 rows, 1466 distinct, 76 mismatches
	#   rank_math_gtin_code             1171 rows, 1166 distinct, 21 mismatches
	# "duplicate" rows copy a value that another row of the same key also holds
	# (lowers distinct AND mismatches); "shifted"/"cycle" rows permute values
	# inside the key (mismatch without lowering distinct); "outside" rows borrow
	# the GTIN of a product that has no row for that key (same effect).
	akb_mariadb "${AKB_TEST_DB}" <<'SQL'
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT
	source.post_id,
	'_wc_gtin',
	CASE
		WHEN source.post_id <= 59 THEN duplicate.gtin
		WHEN source.post_id BETWEEN 119 AND 121 THEN outside.gtin
		ELSE source.gtin
	END
FROM fixture_gtin AS source
LEFT JOIN fixture_gtin AS duplicate ON duplicate.post_id = source.post_id + 59
LEFT JOIN fixture_gtin AS outside ON outside.post_id = source.post_id + 1407
WHERE source.post_id <= 1525;

INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT
	source.post_id,
	'rank_math_snippet_product_gtin',
	CASE
		WHEN source.post_id <= 59 THEN duplicate.gtin
		WHEN source.post_id BETWEEN 200 AND 215 THEN shifted.gtin
		WHEN source.post_id = 216 THEN cycle_start.gtin
		ELSE source.gtin
	END
FROM fixture_gtin AS source
LEFT JOIN fixture_gtin AS duplicate ON duplicate.post_id = source.post_id + 59
LEFT JOIN fixture_gtin AS shifted ON shifted.post_id = source.post_id + 1
LEFT JOIN fixture_gtin AS cycle_start ON cycle_start.post_id = 200
WHERE source.post_id <= 1525;

INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT
	source.post_id,
	'rank_math_gtin_code',
	CASE
		WHEN source.post_id <= 5 THEN duplicate.gtin
		WHEN source.post_id BETWEEN 10 AND 24 THEN shifted.gtin
		WHEN source.post_id = 25 THEN cycle_start.gtin
		ELSE source.gtin
	END
FROM fixture_gtin AS source
LEFT JOIN fixture_gtin AS duplicate ON duplicate.post_id = source.post_id + 5
LEFT JOIN fixture_gtin AS shifted ON shifted.post_id = source.post_id + 1
LEFT JOIN fixture_gtin AS cycle_start ON cycle_start.post_id = 10
WHERE source.post_id <= 1171;
SQL
}

akb_assert_fixture_matches_manifest() {
	local observed
	observed="$(
		akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names <<'SQL'
SELECT CONCAT_WS('|',
	legacy.meta_key,
	COUNT(*),
	COUNT(DISTINCT legacy.post_id),
	COUNT(DISTINCT BINARY legacy.meta_value),
	SUM(BINARY legacy.meta_value <> BINARY global_id.meta_value)
)
FROM wp_postmeta AS legacy
INNER JOIN wp_postmeta AS global_id
	ON global_id.post_id = legacy.post_id AND global_id.meta_key = '_global_unique_id'
WHERE legacy.meta_key IN ('_wc_gtin','rank_math_snippet_product_gtin','rank_math_gtin_code')
GROUP BY legacy.meta_key
ORDER BY FIELD(legacy.meta_key, '_wc_gtin','rank_math_snippet_product_gtin','rank_math_gtin_code');
SQL
	)"
	local expected=$'_wc_gtin|1525|1525|1466|62\nrank_math_snippet_product_gtin|1525|1525|1466|76\nrank_math_gtin_code|1171|1171|1166|21'
	if [[ "${observed}" != "${expected}" ]]; then
		echo "FAIL: fixture cardinalities do not reproduce the manifest" >&2
		echo "expected:" >&2; echo "${expected}" >&2
		echo "observed:" >&2; echo "${observed}" >&2
		exit 1
	fi
}

akb_delete_history_fixture() {
	akb_mariadb "${AKB_TEST_DB}" -e \
		"DELETE FROM wp_postmeta WHERE meta_key IN ('_wc_gtin','rank_math_snippet_product_gtin','rank_math_gtin_code');"
}

akb_history_count() {
	akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names -e \
		"SELECT COUNT(*) FROM wp_postmeta WHERE meta_key IN ('_wc_gtin','rank_math_snippet_product_gtin','rank_math_gtin_code');"
}

akb_non_target_snapshot() {
	akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names -e \
		"SELECT meta_id,post_id,meta_key,HEX(meta_value) FROM wp_postmeta WHERE meta_key NOT IN ('_wc_gtin','rank_math_snippet_product_gtin','rank_math_gtin_code') ORDER BY meta_id;"
}

akb_insert_reviewed_history
akb_assert_fixture_matches_manifest

readarray -t AKB_FIXTURE_HASHES < <(
	akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names <<'SQL'
SELECT SHA2(GROUP_CONCAT(CONCAT(post_id,':',HEX(meta_value)) ORDER BY post_id SEPARATOR '|'),256)
FROM wp_postmeta WHERE meta_key='_global_unique_id';
SELECT SHA2(GROUP_CONCAT(CONCAT(post_id,':',HEX(meta_value)) ORDER BY post_id SEPARATOR '|'),256)
FROM wp_postmeta WHERE meta_key='_wc_gtin';
SELECT SHA2(GROUP_CONCAT(CONCAT(post_id,':',HEX(meta_value)) ORDER BY post_id SEPARATOR '|'),256)
FROM wp_postmeta WHERE meta_key='rank_math_snippet_product_gtin';
SELECT SHA2(GROUP_CONCAT(CONCAT(post_id,':',HEX(meta_value)) ORDER BY post_id SEPARATOR '|'),256)
FROM wp_postmeta WHERE meta_key='rank_math_gtin_code';
SQL
)

if [[ "${#AKB_FIXTURE_HASHES[@]}" -ne 4 ]]; then
	echo "FAIL: could not calculate fixture fingerprints" >&2
	exit 1
fi

# Only the four fingerprints are swapped for the fixture's; every count stays
# as sealed in the SQL, so the fixture must reproduce the manifest exactly.
sed \
	-e "s/${AKB_MANIFEST_HASHES[0]}/${AKB_FIXTURE_HASHES[0]}/g" \
	-e "s/${AKB_MANIFEST_HASHES[1]}/${AKB_FIXTURE_HASHES[1]}/g" \
	-e "s/${AKB_MANIFEST_HASHES[2]}/${AKB_FIXTURE_HASHES[2]}/g" \
	-e "s/${AKB_MANIFEST_HASHES[3]}/${AKB_FIXTURE_HASHES[3]}/g" \
	"${AKB_MIGRATION}" > "${AKB_TMP_DIR}/fixture-migration.sql"
if grep -q -E "$(IFS='|'; echo "${AKB_MANIFEST_HASHES[*]}")" "${AKB_TMP_DIR}/fixture-migration.sql"; then
	echo "FAIL: a manifest fingerprint survived the fixture substitution" >&2
	exit 1
fi

akb_non_target_snapshot > "${AKB_TMP_DIR}/non-target-before.tsv"
first_output="$(
	akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
		< "${AKB_TMP_DIR}/fixture-migration.sql"
)"
grep -q $'CLEANED\t4221\t1568' <<< "${first_output}"
[[ "$(akb_history_count)" == "0" ]]
akb_non_target_snapshot > "${AKB_TMP_DIR}/non-target-after.tsv"
diff -u "${AKB_TMP_DIR}/non-target-before.tsv" "${AKB_TMP_DIR}/non-target-after.tsv"

second_output="$(
	akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
		< "${AKB_TMP_DIR}/fixture-migration.sql"
)"
grep -q $'ALREADY_CLEAN\t0\t1568' <<< "${second_output}"

# A partially repopulated historical set must fail without deleting the row.
akb_mariadb "${AKB_TEST_DB}" -e \
	"INSERT INTO wp_postmeta(post_id,meta_key,meta_value) SELECT post_id,'_wc_gtin',gtin FROM fixture_gtin WHERE post_id=1;"
set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
	< "${AKB_TMP_DIR}/fixture-migration.sql" >/dev/null 2>&1
partial_status=$?
set -e
if [[ "${partial_status}" -eq 0 || "$(akb_history_count)" != "1" ]]; then
	echo "FAIL: partial-state guard did not preserve the database" >&2
	exit 1
fi

# Full counts with changed content must also fail on the fingerprint gate.
akb_delete_history_fixture
akb_insert_reviewed_history
akb_mariadb "${AKB_TEST_DB}" -e \
	"UPDATE wp_postmeta SET meta_value='9780000000000' WHERE meta_key='_wc_gtin' AND post_id=1525;"
set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
	< "${AKB_TMP_DIR}/fixture-migration.sql" >/dev/null 2>&1
drift_status=$?
set -e
if [[ "${drift_status}" -eq 0 || "$(akb_history_count)" != "4221" ]]; then
	echo "FAIL: fingerprint drift did not abort atomically" >&2
	exit 1
fi

# A failure during DELETE must invoke the EXIT handler and roll back all rows.
akb_delete_history_fixture
akb_insert_reviewed_history
akb_mariadb "${AKB_TEST_DB}" <<'SQL'
DELIMITER //
CREATE TRIGGER akb_test_reject_legacy_delete
BEFORE DELETE ON wp_postmeta
FOR EACH ROW
BEGIN
	IF OLD.meta_key = 'rank_math_gtin_code' THEN
		SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced isolated rollback test';
	END IF;
END//
DELIMITER ;
SQL
set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
	< "${AKB_TMP_DIR}/fixture-migration.sql" >/dev/null 2>&1
rollback_status=$?
set -e
if [[ "${rollback_status}" -eq 0 || "$(akb_history_count)" != "4221" ]]; then
	echo "FAIL: SQL exception did not roll back the historical deletion" >&2
	exit 1
fi
akb_mariadb "${AKB_TEST_DB}" -e "DROP TRIGGER akb_test_reject_legacy_delete;"

final_output="$(
	akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
		< "${AKB_TMP_DIR}/fixture-migration.sql"
)"
grep -q $'CLEANED\t4221\t1568' <<< "${final_output}"
[[ "$(akb_history_count)" == "0" ]]

echo "PASS: fail-closed legacy GTIN history cleanup; rollback and idempotency verified"
