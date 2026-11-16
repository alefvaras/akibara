#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_MIGRATION="${AKB_REPO_ROOT}/ops/seo-clean-rankmath-product-legacy-defaults.sql"
AKB_MANIFEST="${AKB_REPO_ROOT}/ops/rankmath-product-legacy-default-cleanup-manifest.json"
AKB_TEST_DB="akb_test_rankmath_defaults_${PPID}_$$"
AKB_TEST_MIGRATION="$(mktemp "${TMPDIR:-/tmp}/akb-rankmath-defaults.XXXXXX.sql")"

akb_mariadb() {
	docker compose -f "${AKB_REPO_ROOT}/compose.yaml" exec -T db \
		mariadb -uroot -proot_local "$@"
}

akb_cleanup() {
	if [[ "${AKB_TEST_DB}" =~ ^akb_test_rankmath_defaults_[0-9]+_[0-9]+$ ]]; then
		akb_mariadb -e "DROP DATABASE IF EXISTS \`${AKB_TEST_DB}\`;" >/dev/null 2>&1 || true
	fi
	rm -f "${AKB_TEST_MIGRATION}"
}
trap akb_cleanup EXIT

akb_seed_fixture() {
	akb_mariadb -e "
		DROP DATABASE IF EXISTS \`${AKB_TEST_DB}\`;
		CREATE DATABASE \`${AKB_TEST_DB}\`
			CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"

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

CREATE TABLE wp_options (
	option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	option_name VARCHAR(191) NOT NULL UNIQUE,
	option_value LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

DELIMITER //
BEGIN NOT ATOMIC
	DECLARE v_id INT UNSIGNED DEFAULT 1;

	WHILE v_id <= 1501 DO
		INSERT INTO wp_posts
			(ID, post_type, post_status, post_name, post_title)
		VALUES
			(
				v_id,
				'product',
				'publish',
				CONCAT('product-', LPAD(v_id, 4, '0')),
				CONCAT('Product ', v_id)
			);

		INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
		VALUES
			(v_id, 'rank_math_rich_snippet', 'product'),
			(v_id, 'rank_math_snippet_product_gtin', CONCAT('gtin-', LPAD(v_id, 4, '0')));

		IF v_id <= 1168 THEN
			INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
			VALUES
				(v_id, 'rank_math_gtin_code', CONCAT('code-', LPAD(v_id, 4, '0')));
		END IF;

		SET v_id = v_id + 1;
	END WHILE;
END//
DELIMITER ;

-- One protected modern product and one product already inheriting the global
-- default produce the same 1,501 / 1 / 1 partition as the reviewed site.
INSERT INTO wp_posts
	(ID, post_type, post_status, post_name, post_title)
VALUES
	(21761, 'product', 'publish', 'akira-6-ovni-press', 'Akira 06'),
	(24774, 'product', 'publish', 'blue-period-15-panini-argentina', 'Blue Period 15'),
	(99999, 'page', 'publish', 'fixture-home', 'Fixture Home');

INSERT INTO wp_postmeta
	(meta_id, post_id, meta_key, meta_value)
VALUES
	(
		900000,
		21761,
		'rank_math_schema_WooCommerceProduct',
		'a:2:{s:8:"metadata";a:4:{s:4:"type";s:8:"template";s:9:"shortcode";s:38:"s-11111111-1111-1111-1111-111111111111";s:9:"isPrimary";s:1:"1";s:5:"title";s:19:"WooCommerce Product";}s:5:"@type";s:18:"WooCommerceProduct";}'
	),
	(
		900001,
		21761,
		'rank_math_shortcode_schema_s-11111111-1111-1111-1111-111111111111',
		'900000'
	),
	(900002, 21761, 'rank_math_snippet_product_gtin', 'gtin-akira'),
	(900003, 24774, 'rank_math_snippet_product_gtin', 'gtin-blue'),
	(900004, 99999, 'rank_math_rich_snippet', 'off');

INSERT INTO wp_options (option_name, option_value)
VALUES
	('rank_math_version', '1.0.274.1'),
	(
		'rank-math-options-titles',
		'a:1:{s:31:"pt_product_default_rich_snippet";s:7:"product";}'
	);
SQL

	local fixture_hashes
	fixture_hashes="$(
		akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names <<'SQL'
SET SESSION group_concat_max_len=16777216;
SELECT CONCAT_WS('|',
	SHA2(
		GROUP_CONCAT(
			CONCAT_WS('|', ID, post_name)
			ORDER BY ID SEPARATOR '\n'
		),
		256
	),
	(
		SELECT SHA2(
			GROUP_CONCAT(
				CONCAT_WS(
					'|',
					pm.post_id,
					p.post_name,
					pm.meta_key,
					pm.meta_value
				)
				ORDER BY pm.post_id SEPARATOR '\n'
			),
			256
		)
		FROM wp_postmeta AS pm
		INNER JOIN wp_posts AS p
			ON p.ID = pm.post_id
		WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND pm.meta_key = 'rank_math_rich_snippet'
			AND pm.meta_value = 'product'
	),
	(
		SELECT SHA2(
			GROUP_CONCAT(
				CONCAT_WS('|', pm.post_id, pm.meta_value)
				ORDER BY pm.post_id SEPARATOR '\n'
			),
			256
		)
		FROM wp_postmeta AS pm
		INNER JOIN wp_posts AS p
			ON p.ID = pm.post_id
		WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND pm.meta_key = 'rank_math_snippet_product_gtin'
	),
	(
		SELECT SHA2(
			GROUP_CONCAT(
				CONCAT_WS('|', pm.post_id, pm.meta_value)
				ORDER BY pm.post_id SEPARATOR '\n'
			),
			256
		)
		FROM wp_postmeta AS pm
		INNER JOIN wp_posts AS p
			ON p.ID = pm.post_id
		WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND pm.meta_key = 'rank_math_gtin_code'
	)
)
FROM wp_posts
WHERE post_type = 'product'
	AND post_status = 'publish';
SQL
	)"

	IFS='|' read -r fixture_products_hash fixture_candidates_hash fixture_gtin_hash fixture_gtin_code_hash \
		<<<"${fixture_hashes}"

	sed \
		-e "s/e7bf513557e8a31c1dec5d1e42d3a84fb3ffab4b0907771ebd3ec86b76d56ae3/${fixture_products_hash}/g" \
		-e "s/a738bd010598c54e86629291b5e0c35fb92730435cf71be8aa2fbb4c6c77f553/${fixture_candidates_hash}/g" \
		-e "s/a9dbbfbf1043f983f6a307938b9eeaca3d42993c2c42ce066c10dcff64a33402/${fixture_gtin_hash}/g" \
		-e "s/2596a871e4d20ffd9a0ec5803f61cc9cc8b03baea93c1ed066d563d2b83cfd90/${fixture_gtin_code_hash}/g" \
		"${AKB_MIGRATION}" > "${AKB_TEST_MIGRATION}"
}

akb_expect_abort() {
	local label="$1"
	set +e
	akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
		< "${AKB_TEST_MIGRATION}" >/dev/null 2>&1
	local status=$?
	set -e

	if [[ "${status}" -eq 0 ]]; then
		echo "FAIL: ${label} did not abort" >&2
		exit 1
	fi
}

# Keep the machine-readable manifest and the production guards synchronized.
node - "${AKB_MANIFEST}" "${AKB_MIGRATION}" <<'JS'
const fs = require('fs');
const manifest = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const sql = fs.readFileSync(process.argv[3], 'utf8');
const required = [
	manifest.rank_math.version,
	String(manifest.snapshot.published_products),
	String(manifest.snapshot.candidate_rows),
	manifest.snapshot.published_product_identity_sha256,
	manifest.snapshot.candidate_identity_sha256,
	manifest.snapshot.protected_gtin.rank_math_snippet_product_gtin_sha256,
	manifest.snapshot.protected_gtin.rank_math_gtin_code_sha256,
];
for (const value of required) {
	if (!sql.includes(value)) {
		throw new Error(`manifest value missing from migration: ${value}`);
	}
}
JS

# Forced failure during DELETE must roll back every reviewed row.
akb_seed_fixture
akb_mariadb "${AKB_TEST_DB}" <<'SQL'
DELIMITER //
CREATE TRIGGER akb_reject_rankmath_default_delete
BEFORE DELETE ON wp_postmeta
FOR EACH ROW
BEGIN
	IF OLD.post_id = 1 AND OLD.meta_key = 'rank_math_rich_snippet' THEN
		SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture rollback';
	END IF;
END//
DELIMITER ;
SQL
akb_expect_abort "forced delete failure"

rollback_rows="$(
	akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e \
		"SELECT COUNT(*) FROM wp_postmeta
		 WHERE meta_key='rank_math_rich_snippet'
		   AND meta_value='product';"
)"
if [[ "${rollback_rows}" != "1501" ]]; then
	echo "FAIL: rollback preserved ${rollback_rows}/1501 reviewed rows" >&2
	exit 1
fi
akb_mariadb "${AKB_TEST_DB}" -e \
	"DROP TRIGGER akb_reject_rankmath_default_delete;"

# Exact reviewed state succeeds; the clean state is an idempotent no-op.
first_output="$(
	akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
		< "${AKB_TEST_MIGRATION}"
)"
second_output="$(
	akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" \
		< "${AKB_TEST_MIGRATION}"
)"

if ! grep -q $'complete\t1501\t0\t1502\t1\t1503\t1168' <<<"${first_output}"; then
	echo "FAIL: first run did not report the exact cleanup" >&2
	echo "${first_output}" >&2
	exit 1
fi
if ! grep -q $'complete\t0\t0\t1502\t1\t1503\t1168' <<<"${second_output}"; then
	echo "FAIL: second run was not idempotent" >&2
	echo "${second_output}" >&2
	exit 1
fi

preservation="$(
	akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names <<'SQL'
SELECT CONCAT_WS('|',
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE meta_key='rank_math_rich_snippet' AND meta_value='product'),
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE post_id=99999 AND meta_key='rank_math_rich_snippet' AND meta_value='off'),
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE post_id=21761 AND meta_key='rank_math_schema_WooCommerceProduct'),
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE post_id=21761
	   AND meta_key='rank_math_shortcode_schema_s-11111111-1111-1111-1111-111111111111'
	   AND meta_value='900000'),
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE meta_key='rank_math_snippet_product_gtin'),
	(SELECT COUNT(*) FROM wp_postmeta
	 WHERE meta_key='rank_math_gtin_code'),
	(SELECT COUNT(*) FROM wp_options
	 WHERE option_name='rank-math-options-titles'
	   AND option_value LIKE '%s:31:"pt_product_default_rich_snippet";s:7:"product";%')
);
SQL
)"
if [[ "${preservation}" != "0|1|1|1|1503|1168|1" ]]; then
	echo "FAIL: exact run changed protected data: ${preservation}" >&2
	exit 1
fi

# A custom per-product schema value is protected by aborting the whole cleanup.
akb_seed_fixture
akb_mariadb "${AKB_TEST_DB}" -e \
	"UPDATE wp_postmeta
	 SET meta_value='off'
	 WHERE post_id=1 AND meta_key='rank_math_rich_snippet';"
akb_expect_abort "custom explicit schema"
if [[ "$(
	akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e \
		"SELECT COUNT(*) FROM wp_postmeta
		 WHERE post_id=1
		   AND meta_key='rank_math_rich_snippet'
		   AND meta_value='off';"
)" != "1" ]]; then
	echo "FAIL: custom explicit schema was changed" >&2
	exit 1
fi

# Unexpected old detail fields require a separately reviewed cleanup.
akb_seed_fixture
akb_mariadb "${AKB_TEST_DB}" -e \
	"INSERT INTO wp_postmeta(post_id,meta_key,meta_value)
	 VALUES(1,'rank_math_snippet_name','Unexpected custom headline');"
akb_expect_abort "unexpected legacy detail"
if [[ "$(
	akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e \
		"SELECT COUNT(*) FROM wp_postmeta
		 WHERE post_id=1
		   AND meta_key='rank_math_snippet_name'
		   AND meta_value='Unexpected custom headline';"
)" != "1" ]]; then
	echo "FAIL: unexpected legacy detail was changed" >&2
	exit 1
fi

# The cleanup must not run if the official global UI setting stops owning
# WooCommerce Product schema.
akb_seed_fixture
akb_mariadb "${AKB_TEST_DB}" -e \
	"UPDATE wp_options
	 SET option_value =
	   REPLACE(
	     option_value,
	     's:31:\\\"pt_product_default_rich_snippet\\\";s:7:\\\"product\\\";',
	     's:31:\\\"pt_product_default_rich_snippet\\\";s:3:\\\"off\\\";'
	   )
	 WHERE option_name='rank-math-options-titles';"
akb_expect_abort "global schema default change"

# A new modern schema overlapping a reviewed legacy row is drift. It must be
# preserved and force a new audit rather than being guessed away.
akb_seed_fixture
akb_mariadb "${AKB_TEST_DB}" -e \
	"INSERT INTO wp_postmeta(post_id,meta_key,meta_value)
	 VALUES(
	   2,
	   'rank_math_schema_CustomProduct',
	   'a:1:{s:5:\\\"@type\\\";s:7:\\\"Product\\\";}'
	 );"
akb_expect_abort "modern schema overlap"
if [[ "$(
	akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e \
		"SELECT COUNT(*) FROM wp_postmeta
		 WHERE post_id=2 AND meta_key='rank_math_schema_CustomProduct';"
)" != "1" ]]; then
	echo "FAIL: overlapping modern schema was changed" >&2
	exit 1
fi

# Plugin upgrades invalidate the version-specific audit.
akb_seed_fixture
akb_mariadb "${AKB_TEST_DB}" -e \
	"UPDATE wp_options SET option_value='1.0.999.0'
	 WHERE option_name='rank_math_version';"
akb_expect_abort "Rank Math version drift"

echo "PASS: global Rank Math legacy defaults cleanup, rollback, idempotency, UI-default guard, custom-schema preservation, GTIN and modern-schema preservation"
