#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_MIGRATION="${AKB_REPO_ROOT}/ops/seo-clean-orphan-postmeta-relationships.sql"
AKB_TEST_DB="akb_test_orphans_${PPID}_$$"
AKB_LIVE_DB="${AKB_LIVE_DB:-akibara}"

akb_mariadb() {
	docker compose -f "${AKB_REPO_ROOT}/compose.yaml" exec -T db \
		mariadb -uroot -proot_local "$@"
}

if [[ "${1:-}" == "--dry-run" ]]; then
	akb_mariadb "${AKB_LIVE_DB}" --batch --raw <<'SQL'
SELECT pm.post_id,pm.meta_key,COUNT(*) AS orphan_rows
FROM wp_postmeta AS pm
LEFT JOIN wp_posts AS parent ON parent.ID=pm.post_id
WHERE parent.ID IS NULL
GROUP BY pm.post_id,pm.meta_key
ORDER BY pm.post_id,pm.meta_key;

SELECT tm.term_id,tm.meta_key,COUNT(*) AS orphan_rows
FROM wp_termmeta AS tm
LEFT JOIN wp_terms AS parent ON parent.term_id=tm.term_id
WHERE parent.term_id IS NULL
GROUP BY tm.term_id,tm.meta_key
ORDER BY tm.term_id,tm.meta_key;

SELECT
	tr.object_id,tr.term_taxonomy_id,tt.taxonomy,t.name AS term_name,
	t.slug AS term_slug,tt.count AS stored_count
FROM wp_term_relationships AS tr
LEFT JOIN wp_posts AS parent ON parent.ID=tr.object_id
INNER JOIN wp_term_taxonomy AS tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
INNER JOIN wp_terms AS t ON t.term_id=tt.term_id
WHERE parent.ID IS NULL
ORDER BY tr.object_id,tr.term_taxonomy_id;
SQL
	exit 0
fi

akb_cleanup() {
	if [[ "${AKB_TEST_DB}" =~ ^akb_test_orphans_[0-9]+_[0-9]+$ ]]; then
		akb_mariadb -e "DROP DATABASE IF EXISTS \`${AKB_TEST_DB}\`;" >/dev/null 2>&1 || true
	fi
}
trap akb_cleanup EXIT

akb_mariadb -e "CREATE DATABASE \`${AKB_TEST_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;"
akb_mariadb "${AKB_TEST_DB}" <<'SQL'
CREATE TABLE wp_posts (
	ID BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB;
CREATE TABLE wp_postmeta (
	meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	post_id BIGINT UNSIGNED NOT NULL,
	meta_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
	meta_value LONGTEXT
) ENGINE=InnoDB;
CREATE TABLE wp_terms (
	term_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
	name VARCHAR(200) NOT NULL,
	slug VARCHAR(200) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE wp_termmeta (
	meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	term_id BIGINT UNSIGNED NOT NULL,
	meta_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
	meta_value LONGTEXT
) ENGINE=InnoDB;
CREATE TABLE wp_term_taxonomy (
	term_taxonomy_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
	term_id BIGINT UNSIGNED NOT NULL,
	taxonomy VARCHAR(32) NOT NULL,
	count BIGINT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE wp_term_relationships (
	object_id BIGINT UNSIGNED NOT NULL,
	term_taxonomy_id BIGINT UNSIGNED NOT NULL,
	PRIMARY KEY (object_id,term_taxonomy_id)
) ENGINE=InnoDB;

INSERT INTO wp_posts VALUES (1);
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (1,'valid','keep');

INSERT INTO wp_postmeta (post_id,meta_key,meta_value)
SELECT reviewed.post_id,'_wp_attachment_metadata','reviewed'
FROM (
	SELECT 24964 AS post_id UNION ALL SELECT 24965 UNION ALL SELECT 25026
	UNION ALL SELECT 25031 UNION ALL SELECT 25056 UNION ALL SELECT 25057
	UNION ALL SELECT 25058 UNION ALL SELECT 25059 UNION ALL SELECT 25060
	UNION ALL SELECT 25075 UNION ALL SELECT 25144 UNION ALL SELECT 25145
	UNION ALL SELECT 25146 UNION ALL SELECT 25147 UNION ALL SELECT 25148
	UNION ALL SELECT 25149 UNION ALL SELECT 25150 UNION ALL SELECT 25151
	UNION ALL SELECT 25152 UNION ALL SELECT 25153 UNION ALL SELECT 25154
	UNION ALL SELECT 25155 UNION ALL SELECT 25156 UNION ALL SELECT 25157
	UNION ALL SELECT 25158 UNION ALL SELECT 25159 UNION ALL SELECT 25160
	UNION ALL SELECT 25161 UNION ALL SELECT 25162 UNION ALL SELECT 25163
	UNION ALL SELECT 25164 UNION ALL SELECT 25165 UNION ALL SELECT 25166
	UNION ALL SELECT 25545 UNION ALL SELECT 25546 UNION ALL SELECT 25547
	UNION ALL SELECT 25548 UNION ALL SELECT 25549 UNION ALL SELECT 25550
	UNION ALL SELECT 25551 UNION ALL SELECT 25552 UNION ALL SELECT 25553
	UNION ALL SELECT 25554 UNION ALL SELECT 25555 UNION ALL SELECT 25556
	UNION ALL SELECT 25557 UNION ALL SELECT 25558 UNION ALL SELECT 25559
	UNION ALL SELECT 25560 UNION ALL SELECT 25561 UNION ALL SELECT 25562
	UNION ALL SELECT 25563 UNION ALL SELECT 25564 UNION ALL SELECT 25565
	UNION ALL SELECT 25566 UNION ALL SELECT 25567 UNION ALL SELECT 25568
	UNION ALL SELECT 25569 UNION ALL SELECT 25570 UNION ALL SELECT 25571
	UNION ALL SELECT 25572
) AS reviewed;
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
	(25026,'_wp_attached_file','reviewed'),
	(25031,'_wp_attached_file','reviewed'),
	(25572,'_wp_attached_file','reviewed');
INSERT INTO wp_postmeta (post_id,meta_key,meta_value)
SELECT reviewed_id.post_id,reviewed_key.meta_key,'reviewed'
FROM (
	SELECT 25056 AS post_id UNION ALL SELECT 25057 UNION ALL SELECT 25058
	UNION ALL SELECT 25059 UNION ALL SELECT 25060 UNION ALL SELECT 25075
	UNION ALL SELECT 25077
) AS reviewed_id
CROSS JOIN (
	SELECT 'actions' AS meta_key UNION ALL SELECT 'rule_options'
	UNION ALL SELECT 'trigger_name' UNION ALL SELECT 'trigger_options'
	UNION ALL SELECT 'type' UNION ALL SELECT 'workflow_options'
) AS reviewed_key;
INSERT INTO wp_postmeta (post_id,meta_key,meta_value)
SELECT reviewed_id.post_id,reviewed_key.meta_key,'reviewed'
FROM (
	SELECT 25056 AS post_id UNION ALL SELECT 25057 UNION ALL SELECT 25058
	UNION ALL SELECT 25059 UNION ALL SELECT 25060 UNION ALL SELECT 25075
) AS reviewed_id
CROSS JOIN (
	SELECT '_edit_lock' AS meta_key UNION ALL SELECT 'is_transactional'
	UNION ALL SELECT 'status'
) AS reviewed_key;
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
	(25075,'_edit_last','reviewed'),
	(25056,'origin','reviewed');

INSERT INTO wp_terms VALUES
	(226,'Cumpleaños','cumpleanos'),
	(300,'Unreviewed','unreviewed');
INSERT INTO wp_termmeta (term_id,meta_key,meta_value) VALUES
	(226,'valid','keep'),
	(487,'product_count_product_cat','677'),
	(487,'rank_math_description','Reserva manga antes del lanzamiento. +800 titulos en preventa con envio a todo Chile cuando esten listos.'),
	(487,'rank_math_focus_keyword','preventas manga chile'),
	(487,'rank_math_title','Preventas Manga Chile | Reserva tu Manga | Akibara');
INSERT INTO wp_term_taxonomy VALUES
	(226,226,'giftcard-category',2),
	(300,300,'product_cat',1);
INSERT INTO wp_term_relationships VALUES
	(1,226),(12360,226),(2000,300);
SQL

# Reproduce the production failure mode explicitly: the database default and
# wp_postmeta.meta_key use different collations. The migration must inherit the
# source column collation rather than assuming either server default.
collation_fixture="$(
	akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names <<'SQL'
SELECT CONCAT(
	@@collation_database,
	'|',
	(
		SELECT COLLATION_NAME
		FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = 'wp_postmeta'
			AND COLUMN_NAME = 'meta_key'
	)
);
SQL
)"
if [[ "${collation_fixture}" != "utf8mb4_uca1400_ai_ci|utf8mb4_unicode_520_ci" ]]; then
	echo "FAIL: mixed-collation fixture was not created: ${collation_fixture}" >&2
	exit 1
fi

# An unrelated orphan relationship must abort before any of the 126 reviewed
# postmeta rows are deleted.
set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_MIGRATION}" >/dev/null 2>&1
guard_status=$?
set -e
if [[ "${guard_status}" -eq 0 ]]; then
	echo "FAIL: unreviewed orphan relationship was accepted" >&2
	exit 1
fi
if [[ "$(akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e \
	'SELECT CONCAT((SELECT COUNT(*) FROM wp_postmeta), "|", (SELECT COUNT(*) FROM wp_termmeta), "|", (SELECT COUNT(*) FROM wp_term_relationships));')" != "127|5|3" ]]; then
	echo "FAIL: relationship guard did not preserve reviewed rows atomically" >&2
	exit 1
fi
akb_mariadb "${AKB_TEST_DB}" -e \
	"DELETE FROM wp_term_relationships WHERE object_id=2000; DELETE FROM wp_term_taxonomy WHERE term_id=300; DELETE FROM wp_terms WHERE term_id=300;"

akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_MIGRATION}" >/dev/null
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_MIGRATION}" >/dev/null

assertions="$(
	akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names <<'SQL'
SELECT CONCAT_WS('|',
	(SELECT COUNT(*) FROM wp_postmeta WHERE post_id=1 AND meta_key='valid' AND meta_value='keep'),
	(SELECT COUNT(*) FROM wp_postmeta),
	(SELECT COUNT(*) FROM wp_termmeta WHERE term_id=226 AND meta_key='valid' AND meta_value='keep'),
	(SELECT COUNT(*) FROM wp_termmeta),
	(SELECT COUNT(*) FROM wp_term_relationships WHERE object_id=1 AND term_taxonomy_id=226),
	(SELECT COUNT(*) FROM wp_term_relationships),
	(SELECT count FROM wp_term_taxonomy WHERE term_taxonomy_id=226)
);
SQL
)"
if [[ "${assertions}" != "1|1|1|1|1|1|1" ]]; then
	echo "FAIL: orphan cleanup fixture assertions: ${assertions}" >&2
	exit 1
fi

# A single unexpected orphan termmeta row must abort without changing it.
akb_mariadb "${AKB_TEST_DB}" -e \
	"INSERT INTO wp_termmeta(term_id,meta_key,meta_value) VALUES(999,'unexpected','keep');"
set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_MIGRATION}" >/dev/null 2>&1
unexpected_termmeta_status=$?
set -e
if [[ "${unexpected_termmeta_status}" -eq 0 ]] || [[ "$(akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e \
	"SELECT COUNT(*) FROM wp_termmeta WHERE term_id=999 AND meta_key='unexpected';")" != "1" ]]; then
	echo "FAIL: exact four-row termmeta guard accepted or changed an unexpected orphan" >&2
	exit 1
fi
akb_mariadb "${AKB_TEST_DB}" -e \
	"DELETE FROM wp_termmeta WHERE term_id=999 AND meta_key='unexpected';"

# A single unexpected orphan cannot be mistaken for the reviewed snapshot.
akb_mariadb "${AKB_TEST_DB}" -e \
	"INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES(999,'unexpected','keep');"
set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_MIGRATION}" >/dev/null 2>&1
unexpected_status=$?
set -e
if [[ "${unexpected_status}" -eq 0 ]] || [[ "$(akb_mariadb "${AKB_TEST_DB}" --batch --skip-column-names -e \
	"SELECT COUNT(*) FROM wp_postmeta WHERE post_id=999 AND meta_key='unexpected';")" != "1" ]]; then
	echo "FAIL: exact 126-row allowlist guard accepted or changed an unexpected orphan" >&2
	exit 1
fi

echo "PASS: mixed-collation allowlist, relationship scope, idempotency and rollback"
