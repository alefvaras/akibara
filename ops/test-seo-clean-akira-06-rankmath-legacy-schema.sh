#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_MIGRATION="${AKB_REPO_ROOT}/ops/seo-clean-akira-06-rankmath-legacy-schema.sql"
AKB_TEST_DB="akb_test_akira_schema_${PPID}_$$"

akb_mariadb() {
	docker compose -f "${AKB_REPO_ROOT}/compose.yaml" exec -T db \
		mariadb -uroot -proot_local "$@"
}

akb_cleanup() {
	if [[ "${AKB_TEST_DB}" =~ ^akb_test_akira_schema_[0-9]+_[0-9]+$ ]]; then
		akb_mariadb -e "DROP DATABASE IF EXISTS \`${AKB_TEST_DB}\`;" >/dev/null 2>&1 || true
	fi
}
trap akb_cleanup EXIT

akb_mariadb -e \
	"CREATE DATABASE \`${AKB_TEST_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"

akb_mariadb "${AKB_TEST_DB}" <<'SQL'
CREATE TABLE wp_posts (
	ID BIGINT UNSIGNED NOT NULL PRIMARY KEY,
	post_type VARCHAR(20) NOT NULL,
	post_status VARCHAR(20) NOT NULL,
	post_name VARCHAR(200) NOT NULL,
	post_title TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE wp_postmeta (
	meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	post_id BIGINT UNSIGNED NOT NULL,
	meta_key VARCHAR(255),
	meta_value LONGTEXT,
	KEY post_id (post_id),
	KEY meta_key (meta_key(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

INSERT INTO wp_posts
	(ID,post_type,post_status,post_name,post_title)
VALUES
	(21761,'product','publish','akira-6-ovni-press','Akira 06'),
	(99999,'product','publish','unrelated-product','Unrelated Product');

INSERT INTO wp_postmeta (meta_id,post_id,meta_key,meta_value) VALUES
	(9001,21761,'rank_math_schema_WooCommerceProduct',
	'a:2:{s:8:"metadata";a:4:{s:4:"type";s:8:"template";s:9:"shortcode";s:38:"s-11111111-1111-1111-1111-111111111111";s:9:"isPrimary";s:1:"1";s:5:"title";s:19:"WooCommerce Product";}s:5:"@type";s:18:"WooCommerceProduct";}'),
	(9002,21761,'rank_math_shortcode_schema_s-11111111-1111-1111-1111-111111111111','9001'),
	(9003,21761,'_sku','9789878190846'),
	(9004,21761,'_global_unique_id','9789878190846'),
	(9005,21761,'rank_math_description',
	'Compra Akira 6 – Ovni Press en Chile. Tomo final de Katsuhiro Otomo, manga seinen original, en stock con envío según destino y 3 cuotas sin interés.'),
	(9006,21761,'rank_math_rich_snippet','product'),
	(9007,21761,'rank_math_snippet_name','Akira 6 – Ovni Press'),
	(9008,21761,'rank_math_snippet_desc',
	'Compra Akira 6 – Ovni Press en Chile. Tomo final de Katsuhiro Otomo, manga seinen original, en stock con envío a todo Chile y 3 cuotas sin interés.'),
	(9009,21761,'rank_math_snippet_product_gtin','9789878190846'),
	(9010,21761,'rank_math_gtin_code','9789878190846'),
	(9011,21761,'unrelated','keep'),
	(9101,99999,'rank_math_rich_snippet','product'),
	(9102,99999,'rank_math_snippet_name','Do not delete'),
	(9103,99999,'rank_math_snippet_desc','Do not delete');
SQL

# Force an error from inside the DELETE. The EXIT handler must roll back all
# three reviewed rows rather than leaving a partial state.
akb_mariadb "${AKB_TEST_DB}" <<'SQL'
DELIMITER //
CREATE TRIGGER akb_reject_legacy_delete
BEFORE DELETE ON wp_postmeta
FOR EACH ROW
BEGIN
	IF OLD.post_id = 21761 AND OLD.meta_key = 'rank_math_snippet_desc' THEN
		SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture rollback';
	END IF;
END//
DELIMITER ;
SQL

set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
	< "${AKB_MIGRATION}" >/dev/null 2>&1
rollback_status=$?
set -e

if [[ "${rollback_status}" -eq 0 ]]; then
	echo "FAIL: forced delete error did not abort" >&2
	exit 1
fi

rollback_rows="$(
	akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e \
		"SELECT COUNT(*) FROM wp_postmeta
		 WHERE post_id=21761
		   AND meta_key IN ('rank_math_rich_snippet','rank_math_snippet_name','rank_math_snippet_desc');"
)"
if [[ "${rollback_rows}" != "3" ]]; then
	echo "FAIL: transaction rollback preserved ${rollback_rows}/3 legacy rows" >&2
	exit 1
fi

akb_mariadb "${AKB_TEST_DB}" -e "DROP TRIGGER akb_reject_legacy_delete;"

# Exact reviewed state succeeds, and a second invocation is an idempotent no-op.
if ! first_output="$(
	akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_MIGRATION}"
)"; then
	echo "FAIL: exact reviewed state did not apply" >&2
	exit 1
fi
if ! second_output="$(
	akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_MIGRATION}"
)"; then
	echo "FAIL: already-clean state did not complete" >&2
	exit 1
fi

if ! grep -q $'complete\t3\t0\t1\t1' <<<"${first_output}"; then
	echo "FAIL: first run did not report the exact three-row cleanup" >&2
	echo "${first_output}" >&2
	exit 1
fi
if ! grep -q $'complete\t0\t0\t1\t1' <<<"${second_output}"; then
	echo "FAIL: second run was not idempotent" >&2
	echo "${second_output}" >&2
	exit 1
fi

preservation="$(
	akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names <<'SQL'
SELECT CONCAT_WS('|',
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE post_id=21761
	   AND meta_key IN ('rank_math_rich_snippet','rank_math_snippet_name','rank_math_snippet_desc')),
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE post_id=21761
	   AND meta_id=9001
	   AND meta_key='rank_math_schema_WooCommerceProduct'
	   AND meta_value LIKE '%s:5:"@type";s:18:"WooCommerceProduct";%'),
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE post_id=21761
	   AND meta_id=9002
	   AND meta_key='rank_math_shortcode_schema_s-11111111-1111-1111-1111-111111111111'
	   AND meta_value='9001'),
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE post_id=21761 AND meta_key='rank_math_description'),
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE post_id=21761 AND meta_key='rank_math_snippet_product_gtin'),
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE post_id=21761 AND meta_key='rank_math_gtin_code'),
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE post_id=21761 AND meta_key='unrelated' AND meta_value='keep'),
	(SELECT COUNT(*) FROM wp_postmeta WHERE post_id=99999)
);
SQL
)"
if [[ "${preservation}" != "0|1|1|1|1|1|1|3" ]]; then
	echo "FAIL: migration changed out-of-scope data: ${preservation}" >&2
	exit 1
fi

# A partial historical state is neither a valid pre-state nor the clean state.
# It must abort and preserve the unexpected row.
akb_mariadb "${AKB_TEST_DB}" -e \
	"INSERT INTO wp_postmeta(post_id,meta_key,meta_value)
	 VALUES(21761,'rank_math_snippet_name','Akira 6 – Ovni Press');"
set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
	< "${AKB_MIGRATION}" >/dev/null 2>&1
partial_status=$?
set -e
if [[ "${partial_status}" -eq 0 ]] || [[ "$(
	akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e \
		"SELECT COUNT(*) FROM wp_postmeta
		 WHERE post_id=21761 AND meta_key='rank_math_snippet_name';"
)" != "1" ]]; then
	echo "FAIL: partial legacy state was accepted or changed" >&2
	exit 1
fi
akb_mariadb "${AKB_TEST_DB}" -e \
	"DELETE FROM wp_postmeta
	 WHERE post_id=21761 AND meta_key='rank_math_snippet_name';"

# A broken schema shortcut must abort even in the already-clean state.
akb_mariadb "${AKB_TEST_DB}" -e \
	"UPDATE wp_postmeta SET meta_value='999999'
	 WHERE post_id=21761
	   AND meta_key='rank_math_shortcode_schema_s-11111111-1111-1111-1111-111111111111';"
set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
	< "${AKB_MIGRATION}" >/dev/null 2>&1
shortcut_status=$?
set -e
if [[ "${shortcut_status}" -eq 0 ]]; then
	echo "FAIL: invalid Rank Math shortcut was accepted" >&2
	exit 1
fi
akb_mariadb "${AKB_TEST_DB}" -e \
	"UPDATE wp_postmeta SET meta_value='9001'
	 WHERE post_id=21761
	   AND meta_key='rank_math_shortcode_schema_s-11111111-1111-1111-1111-111111111111';"

# A changed current SEO description must abort before legacy data is removed.
akb_mariadb "${AKB_TEST_DB}" -e \
	"UPDATE wp_postmeta SET meta_value='unexpected current description'
	 WHERE post_id=21761 AND meta_key='rank_math_description';
	 INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES
	 (21761,'rank_math_rich_snippet','product'),
	 (21761,'rank_math_snippet_name','Akira 6 – Ovni Press'),
	 (21761,'rank_math_snippet_desc',
	  'Compra Akira 6 – Ovni Press en Chile. Tomo final de Katsuhiro Otomo, manga seinen original, en stock con envío a todo Chile y 3 cuotas sin interés.');"
set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
	< "${AKB_MIGRATION}" >/dev/null 2>&1
description_status=$?
set -e
remaining_after_guard="$(
	akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e \
		"SELECT COUNT(*) FROM wp_postmeta
		 WHERE post_id=21761
		   AND meta_key IN ('rank_math_rich_snippet','rank_math_snippet_name','rank_math_snippet_desc');"
)"
if [[ "${description_status}" -eq 0 ]] || [[ "${remaining_after_guard}" != "3" ]]; then
	echo "FAIL: current-description guard accepted or changed the legacy snapshot" >&2
	exit 1
fi

echo "PASS: exact Akira 06 legacy cleanup, modern schema preservation, rollback, scope and idempotency"
