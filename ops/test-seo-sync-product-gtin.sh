#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_VALIDATOR="${AKB_REPO_ROOT}/ops/seo-sync-product-gtin.sql"
AKB_DB_SERVICE="db"
AKB_DB_USER="${AKB_DB_USER:-root}"
AKB_DB_PASSWORD="${AKB_DB_PASSWORD:-root_local}"
AKB_LIVE_DB="${AKB_LIVE_DB:-akibara}"

akb_mariadb() {
	docker compose -f "${AKB_REPO_ROOT}/compose.yaml" exec -T "${AKB_DB_SERVICE}" \
		mariadb "-u${AKB_DB_USER}" "-p${AKB_DB_PASSWORD}" "$@"
}

if [[ "${1:-}" == "--dry-run" ]]; then
	akb_mariadb "${AKB_LIVE_DB}" --batch --raw <<'SQL'
SELECT 'published_products' AS metric, COUNT(*) AS value
FROM wp_posts
WHERE post_type='product' AND post_status='publish'
UNION ALL
SELECT 'global_unique_id_cardinality_failures', COUNT(*)
FROM wp_posts AS product
LEFT JOIN (
	SELECT post_id,COUNT(*) AS source_rows
	FROM wp_postmeta
	WHERE meta_key='_global_unique_id'
	GROUP BY post_id
) AS source ON source.post_id=product.ID
WHERE product.post_type='product' AND product.post_status='publish'
	AND COALESCE(source.source_rows,0)<>1
UNION ALL
SELECT 'invalid_global_unique_id_ean13', COUNT(*)
FROM wp_posts AS product
INNER JOIN wp_postmeta AS global_id
	ON global_id.post_id=product.ID AND global_id.meta_key='_global_unique_id'
WHERE product.post_type='product' AND product.post_status='publish'
	AND (
		global_id.meta_value NOT REGEXP '^[0-9]{13}$'
		OR MOD(
			CAST(SUBSTRING(global_id.meta_value,1,1) AS UNSIGNED)
			+ 3*CAST(SUBSTRING(global_id.meta_value,2,1) AS UNSIGNED)
			+ CAST(SUBSTRING(global_id.meta_value,3,1) AS UNSIGNED)
			+ 3*CAST(SUBSTRING(global_id.meta_value,4,1) AS UNSIGNED)
			+ CAST(SUBSTRING(global_id.meta_value,5,1) AS UNSIGNED)
			+ 3*CAST(SUBSTRING(global_id.meta_value,6,1) AS UNSIGNED)
			+ CAST(SUBSTRING(global_id.meta_value,7,1) AS UNSIGNED)
			+ 3*CAST(SUBSTRING(global_id.meta_value,8,1) AS UNSIGNED)
			+ CAST(SUBSTRING(global_id.meta_value,9,1) AS UNSIGNED)
			+ 3*CAST(SUBSTRING(global_id.meta_value,10,1) AS UNSIGNED)
			+ CAST(SUBSTRING(global_id.meta_value,11,1) AS UNSIGNED)
			+ 3*CAST(SUBSTRING(global_id.meta_value,12,1) AS UNSIGNED)
			+ CAST(SUBSTRING(global_id.meta_value,13,1) AS UNSIGNED),
			10
		)<>0
	)
UNION ALL
SELECT 'duplicate_global_unique_id_assignments',COALESCE(SUM(duplicate.product_count-1),0)
FROM (
	SELECT global_id.meta_value,COUNT(*) AS product_count
	FROM wp_posts AS product
	INNER JOIN wp_postmeta AS global_id
		ON global_id.post_id=product.ID AND global_id.meta_key='_global_unique_id'
	WHERE product.post_type='product' AND product.post_status='publish'
	GROUP BY BINARY global_id.meta_value
	HAVING COUNT(*)>1
) AS duplicate
UNION ALL
SELECT 'informational_wc_gtin_rows',COUNT(*)
FROM wp_posts AS product
INNER JOIN wp_postmeta AS meta ON meta.post_id=product.ID AND meta.meta_key='_wc_gtin'
WHERE product.post_type='product' AND product.post_status='publish'
UNION ALL
SELECT 'informational_rank_math_gtin_rows',COUNT(*)
FROM wp_posts AS product
INNER JOIN wp_postmeta AS meta
	ON meta.post_id=product.ID AND meta.meta_key='rank_math_snippet_product_gtin'
WHERE product.post_type='product' AND product.post_status='publish';
SQL
	exit 0
fi

AKB_TEST_DB="akb_test_seo_gtin_${PPID}_$$"
AKB_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/akb-seo-gtin-test.XXXXXX")"

akb_cleanup() {
	if [[ "${AKB_TEST_DB}" =~ ^akb_test_seo_gtin_[0-9]+_[0-9]+$ ]]; then
		akb_mariadb -e "DROP DATABASE IF EXISTS \`${AKB_TEST_DB}\`;" >/dev/null 2>&1 || true
	fi
	case "${AKB_TMP_DIR}" in
		*/akb-seo-gtin-test.*) rm -rf -- "${AKB_TMP_DIR}" ;;
	esac
}
trap akb_cleanup EXIT

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
	meta_value LONGTEXT
) ENGINE=InnoDB;

INSERT INTO wp_posts VALUES
	(1,'product','publish'),
	(2,'product','publish'),
	(3,'product','draft'),
	(4,'shop_order','wc-processing');
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
	(1,'_sku','SKU-NOT-A-GTIN'),
	(1,'_global_unique_id','9780306406157'),
	(1,'_wc_gtin','legacy-wc-must-not-change'),
	(1,'rank_math_snippet_product_gtin','legacy-rank-math-must-not-change'),
	(2,'_global_unique_id','9783161484100'),
	(2,'_wc_gtin','different-legacy-wc'),
	(2,'rank_math_snippet_product_gtin','different-legacy-rank-math'),
	(3,'_global_unique_id','invalid-draft-is-out-of-scope'),
	(4,'_global_unique_id','invalid-order-is-out-of-scope');
SQL

docker compose -f "${AKB_REPO_ROOT}/compose.yaml" exec -T "${AKB_DB_SERVICE}" \
	mariadb-dump "-u${AKB_DB_USER}" "-p${AKB_DB_PASSWORD}" \
	--skip-comments --compact --skip-add-locks --skip-disable-keys "${AKB_TEST_DB}" \
	> "${AKB_TMP_DIR}/before.sql"

akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_VALIDATOR}" >/dev/null
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_VALIDATOR}" >/dev/null

docker compose -f "${AKB_REPO_ROOT}/compose.yaml" exec -T "${AKB_DB_SERVICE}" \
	mariadb-dump "-u${AKB_DB_USER}" "-p${AKB_DB_PASSWORD}" \
	--skip-comments --compact --skip-add-locks --skip-disable-keys "${AKB_TEST_DB}" \
	> "${AKB_TMP_DIR}/after.sql"

if ! diff -u "${AKB_TMP_DIR}/before.sql" "${AKB_TMP_DIR}/after.sql" >/dev/null; then
	echo "FAIL: GTIN validator changed source, SKU, or legacy metadata" >&2
	exit 1
fi

# Duplicate source rows must fail without touching either source or legacy keys.
akb_mariadb "${AKB_TEST_DB}" -e \
	"INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES(2,'_global_unique_id','9780306406157');"
duplicate_checksum_before="$(akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e 'CHECKSUM TABLE wp_postmeta;' | awk '{print $2}')"
set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_VALIDATOR}" >/dev/null 2>&1
duplicate_status=$?
set -e
duplicate_checksum_after="$(akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e 'CHECKSUM TABLE wp_postmeta;' | awk '{print $2}')"
if [[ "${duplicate_status}" -eq 0 || "${duplicate_checksum_before}" != "${duplicate_checksum_after}" ]]; then
	echo "FAIL: duplicate _global_unique_id cardinality guard changed data" >&2
	exit 1
fi

akb_mariadb "${AKB_TEST_DB}" -e \
	"DELETE FROM wp_postmeta WHERE post_id=2 AND meta_key='_global_unique_id' AND meta_value='9780306406157'; UPDATE wp_postmeta SET meta_value='9783161484101' WHERE post_id=2 AND meta_key='_global_unique_id';"
invalid_checksum_before="$(akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e 'CHECKSUM TABLE wp_postmeta;' | awk '{print $2}')"
set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_VALIDATOR}" >/dev/null 2>&1
invalid_status=$?
set -e
invalid_checksum_after="$(akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e 'CHECKSUM TABLE wp_postmeta;' | awk '{print $2}')"
if [[ "${invalid_status}" -eq 0 || "${invalid_checksum_before}" != "${invalid_checksum_after}" ]]; then
	echo "FAIL: invalid EAN-13 guard changed data" >&2
	exit 1
fi

echo "PASS: _global_unique_id-only GTIN validation; SKU/legacy keys untouched"
