#!/usr/bin/env bash
set -Eeuo pipefail

AKB_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AKB_MIGRATION="${AKB_REPO_ROOT}/ops/seo-normalize-national-shipping-copy.sql"
AKB_DOCKER_DB_SERVICE="db"
AKB_DB_USER="${AKB_DB_USER:-root}"
AKB_DB_PASSWORD="${AKB_DB_PASSWORD:-root_local}"
AKB_LIVE_DB="${AKB_LIVE_DB:-akibara}"

akb_mariadb() {
	docker compose -f "${AKB_REPO_ROOT}/compose.yaml" exec -T "${AKB_DOCKER_DB_SERVICE}" \
		mariadb "-u${AKB_DB_USER}" "-p${AKB_DB_PASSWORD}" "$@"
}

if [[ "${1:-}" == "--dry-run" ]]; then
	akb_mariadb "${AKB_LIVE_DB}" --batch --raw <<'SQL'
SELECT 'published_post_content' AS scope, COUNT(*) AS candidate_rows
FROM wp_posts
WHERE post_type IN ('product', 'post', 'page')
	AND post_status = 'publish'
	AND LOWER(post_content) REGEXP
		'todo[[:space:]]+chile|a[[:space:]]+todo[[:space:]]+el[[:space:]]+pa[ií]s|a[[:space:]]+todas[[:space:]]+las[[:space:]]+regiones'
UNION ALL
SELECT 'published_post_excerpt', COUNT(*)
FROM wp_posts
WHERE post_type IN ('product', 'post', 'page')
	AND post_status = 'publish'
	AND LOWER(post_excerpt) REGEXP
		'todo[[:space:]]+chile|todo[[:space:]]+el[[:space:]]+pa[ií]s|todas[[:space:]]+las[[:space:]]+regiones'
UNION ALL
SELECT 'published_rank_math_postmeta', COUNT(*)
FROM wp_postmeta AS pm
INNER JOIN wp_posts AS p ON p.ID = pm.post_id
WHERE p.post_type IN ('product', 'post', 'page')
	AND p.post_status = 'publish'
	AND pm.meta_key IN (
		'rank_math_description',
		'rank_math_snippet_desc',
		'rank_math_facebook_description',
		'rank_math_twitter_description'
	)
	AND NOT (
		pm.meta_value REGEXP '^(a|O|s|i|b|d|C|R|r):[0-9]+[:;{]'
		OR pm.meta_value = 'N;'
	)
	AND LOWER(pm.meta_value) REGEXP
		'todo[[:space:]]+chile|todo[[:space:]]+el[[:space:]]+pa[ií]s|todas[[:space:]]+las[[:space:]]+regiones'
UNION ALL
SELECT 'public_taxonomy_descriptions', COUNT(*)
FROM wp_term_taxonomy
WHERE taxonomy IN ('product_cat', 'product_brand', 'pa_serie', 'pa_genero', 'pa_autor')
	AND LOWER(description) REGEXP
		'todo[[:space:]]+chile|todo[[:space:]]+el[[:space:]]+pa[ií]s|todas[[:space:]]+las[[:space:]]+regiones'
UNION ALL
SELECT 'public_rank_math_termmeta', COUNT(*)
FROM wp_termmeta AS tm
WHERE tm.meta_key IN (
		'rank_math_description',
		'rank_math_facebook_description',
		'rank_math_twitter_description'
	)
	AND EXISTS (
		SELECT 1
		FROM wp_term_taxonomy AS tt
		WHERE tt.term_id = tm.term_id
			AND tt.taxonomy IN ('product_cat', 'product_brand', 'pa_serie', 'pa_genero', 'pa_autor')
	)
	AND NOT (
		tm.meta_value REGEXP '^(a|O|s|i|b|d|C|R|r):[0-9]+[:;{]'
		OR tm.meta_value = 'N;'
	)
	AND LOWER(tm.meta_value) REGEXP
		'todo[[:space:]]+chile|todo[[:space:]]+el[[:space:]]+pa[ií]s|todas[[:space:]]+las[[:space:]]+regiones'
UNION ALL
SELECT 'known_orphan_termmeta_487', COUNT(*)
FROM wp_termmeta AS tm
LEFT JOIN wp_terms AS t ON t.term_id = tm.term_id
WHERE tm.term_id = 487
	AND t.term_id IS NULL;
SQL
	exit 0
fi

AKB_TEST_DB="akb_test_seo_copy_${PPID}_$$"
AKB_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/akb-seo-copy-test.XXXXXX")"

akb_cleanup() {
	akb_mariadb -e "DROP DATABASE IF EXISTS \`${AKB_TEST_DB}\`;" >/dev/null 2>&1 || true
	case "${AKB_TMP_DIR}" in
		*/akb-seo-copy-test.*) rm -rf -- "${AKB_TMP_DIR}" ;;
	esac
}
trap akb_cleanup EXIT

akb_mariadb -e "CREATE DATABASE \`${AKB_TEST_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

akb_mariadb "${AKB_TEST_DB}" <<'SQL'
CREATE TABLE wp_posts (
	ID BIGINT UNSIGNED NOT NULL PRIMARY KEY,
	post_type VARCHAR(32) NOT NULL,
	post_status VARCHAR(32) NOT NULL,
	post_title TEXT NOT NULL,
	post_content LONGTEXT NOT NULL,
	post_excerpt LONGTEXT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE wp_postmeta (
	meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	post_id BIGINT UNSIGNED NOT NULL,
	meta_key VARCHAR(255),
	meta_value LONGTEXT
) ENGINE=InnoDB;

CREATE TABLE wp_terms (
	term_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
	name VARCHAR(200) NOT NULL,
	slug VARCHAR(200) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE wp_term_taxonomy (
	term_taxonomy_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	term_id BIGINT UNSIGNED NOT NULL,
	taxonomy VARCHAR(32) NOT NULL,
	description LONGTEXT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE wp_termmeta (
	meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	term_id BIGINT UNSIGNED NOT NULL,
	meta_key VARCHAR(255),
	meta_value LONGTEXT
) ENGINE=InnoDB;

CREATE TABLE wp_options (
	option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	option_name VARCHAR(191) NOT NULL,
	option_value LONGTEXT NOT NULL
) ENGINE=InnoDB;

INSERT INTO wp_posts VALUES
	(1, 'product', 'publish', 'Producto público',
		'Envío a Todo Chile. Gatz grita por todo el país y llegan viajeros desde todas las regiones del continente.',
		'Despacho rápido a todas las regiones de Chile.'),
	(2, 'page', 'publish', 'FAQ',
		'Despacho Blue Express (todo Chile).',
		''),
	(3, 'post', 'draft', 'Borrador',
		'Envío a todo Chile.',
		'Envío a todo el país.'),
	(4, 'shop_order', 'wc-processing', 'Pedido',
		'Envío a todo Chile.',
		''),
		(40, 'page', 'publish', 'Blog',
			'Guías con envío a todo Chile.',
			''),
		(16557, 'product', 'publish', 'Maximum Berserk 10', '',
			'<p>¡El clímax de la torre! Compra <strong>Maximum Berserk 10</strong> en <strong>Akibara</strong>. Vive la batalla contra Mozgus y la ceremonia de encarnación en la edición <strong>Maximum de Panini España</strong>. y seguro a todo el país. Envío a todo Chile desde Akibara.</p>'),
		(17204, 'product', 'publish', 'One Punch-Man 02', '',
			'<p>¡Descubre el secreto de Saitama! Compra <strong>One Punch-Man Tomo 2</strong> en <strong>Akibara</strong>. Vive el enfrentamiento contra <strong>Carnage Kabuto</strong> y la <strong>Casa de la Evolución</strong>. Edición original de <strong>Ivrea España</strong> con la mejor traducción al castellano. <strong>Envío seguro y rápido a todas las regiones.</strong> Envío a todo Chile desde Akibara.</p>'),
		(17240, 'product', 'publish', 'One Punch-Man 13', '',
			'<p>¡Saitama llega al rescate! Compra <strong>One Punch-Man Tomo 13</strong> en <strong>Akibara</strong>. Vive el final del torneo, la transformación de <strong>Bakuzan</strong> y el enfrentamiento contra <strong>Gouketsu</strong>. Edición original de <strong>Ivrea España</strong>. <strong> a todas las regiones de Chile.</strong> Envío a todo Chile desde Akibara.</p>'),
		(17249, 'product', 'publish', 'One Punch-Man 16', '',
			'<p>¡El Puñetazo Serio ha llegado! Compra <strong>One Punch-Man Tomo 16</strong> en <strong>Akibara</strong>. Sé testigo de la caída del <strong>Ciempiés Anciano</strong> y el rescate de <strong>Garou</strong>. Edición original <strong>Ivrea España</strong> con a todas las regiones de Chile. Envío a todo Chile desde Akibara.</p>'),
		(17283, 'product', 'publish', 'One Punch-Man 27', '',
			'<p>¡Los monstruos dominan! Compra <strong>One Punch-Man Tomo 27</strong> en <strong>Akibara</strong>. Vive la derrota de la Clase S ante <strong>Black Sperm</strong> y <strong>Fuhrer Ugly</strong>. Edición original de <strong>Ivrea España</strong>. Despacho rápido a todas las regiones de Chile. Envío a todo Chile desde Akibara.</p>'),
		(17289, 'product', 'publish', 'One Punch-Man 29', '',
			'<p>¡El Motor King retumba! Compra <strong>One Punch-Man Tomo 29</strong> en <strong>Akibara</strong>. Sé testigo del bluff de <strong>King</strong> contra los ejecutivos y el debut de <strong>Golden Sperm</strong>. Edición <strong>Ivrea España</strong> con despacho express a todo el país. <strong>¡!</strong> Envío a todo Chile desde Akibara.</p>'),
		(17302, 'product', 'publish', 'One Punch-Man 33', '',
			'<p>¡Choque de titanes! Compra <strong>One Punch-Man Tomo 33</strong> en <strong>Akibara</strong>. Vive la batalla épica entre <strong>Saitama y Tatsumaki</strong> en una persecución destructiva sin igual. Edición original <strong>Ivrea España</strong> con a todas las regiones. Envío a todo Chile desde Akibara.</p>');

INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
	(1, 'rank_math_description', 'Compra con envío rápido a todo el país.'),
	(1, 'rank_math_facebook_description', 'Envíos a todo Chile.'),
	(1, 'rank_math_twitter_description', 'a:1:{s:4:"copy";s:20:"Envío a todo Chile";}'),
	(3, 'rank_math_description', 'Borrador con envío a todo Chile.'),
	(4, 'rank_math_description', 'Pedido con envío a todo Chile.'),
	(40, 'rank_math_description', 'Blog de Akibara con envío a todo el país.');

INSERT INTO wp_terms VALUES
	(28, 'Manga', 'manga'),
	(81, 'Cómics', 'comics'),
	(244, 'Eiichiro Oda', 'eiichiro-oda'),
	(250, 'Manhwa', 'manhwa'),
	(362, 'Ivrea España', 'ivrea-espana');

INSERT INTO wp_term_taxonomy (term_id, taxonomy, description) VALUES
	(28, 'product_cat', 'Envíos a Todo Chile. Envíos rápidos y seguros a todas las regiones.'),
	(81, 'product_cat', 'Catálogo con envío seguro a todo el país.'),
	(244, 'pa_autor', 'Obras disponibles con envío a todo Chile.'),
	(250, 'product_cat', 'Manhwa con envíos a todo Chile.'),
	(362, 'product_brand', 'Importación directa y despachos a todo el país.');

INSERT INTO wp_termmeta (term_id, meta_key, meta_value) VALUES
	(244, 'rank_math_description', 'Catálogo con envío rápido a todo el país.'),
	(250, 'rank_math_description', 'Manhwa con envíos a todo Chile.'),
	(250, 'rank_math_twitter_description', 'a:1:{s:4:"copy";s:20:"Envío a todo Chile";}'),
	(487, 'product_count_product_cat', '677'),
	(487, 'rank_math_description', 'Reserva con envío a todo Chile.'),
	(487, 'rank_math_focus_keyword', 'preventas manga chile'),
	(487, 'rank_math_title', 'Preventas Manga Chile');

INSERT INTO wp_options (option_name, option_value)
VALUES ('serialized_shipping_copy', 'a:1:{s:4:"copy";s:20:"Envío a todo Chile";}');
SQL

akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_MIGRATION}" >/dev/null

AKB_ASSERTIONS="$(akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names <<'SQL'
SELECT CONCAT_WS('|',
	(SELECT COUNT(*) FROM wp_posts
		WHERE post_type IN ('product','post','page') AND post_status='publish'
		AND LOWER(post_content) REGEXP 'todo[[:space:]]+chile'),
	(SELECT COUNT(*) FROM wp_posts
		WHERE post_type IN ('product','post','page') AND post_status='publish'
		AND LOWER(post_excerpt) REGEXP 'todo[[:space:]]+chile|todo[[:space:]]+el[[:space:]]+pa[ií]s|todas[[:space:]]+las[[:space:]]+regiones'),
	(SELECT COUNT(*) FROM wp_postmeta pm JOIN wp_posts p ON p.ID=pm.post_id
		WHERE p.post_type IN ('product','post','page') AND p.post_status='publish'
		AND pm.meta_key IN ('rank_math_description','rank_math_snippet_desc','rank_math_facebook_description','rank_math_twitter_description')
		AND pm.meta_value NOT REGEXP '^(a|O|s|i|b|d|C|R|r):[0-9]+[:;{]'
		AND LOWER(pm.meta_value) REGEXP 'todo[[:space:]]+chile|todo[[:space:]]+el[[:space:]]+pa[ií]s|todas[[:space:]]+las[[:space:]]+regiones'),
	(SELECT COUNT(*) FROM wp_term_taxonomy
		WHERE LOWER(description) REGEXP 'todo[[:space:]]+chile|todo[[:space:]]+el[[:space:]]+pa[ií]s|todas[[:space:]]+las[[:space:]]+regiones'),
	(SELECT COUNT(*) FROM wp_termmeta tm JOIN wp_term_taxonomy tt ON tt.term_id=tm.term_id
		WHERE tm.meta_key IN ('rank_math_description','rank_math_facebook_description','rank_math_twitter_description')
		AND tm.meta_value NOT REGEXP '^(a|O|s|i|b|d|C|R|r):[0-9]+[:;{]'
		AND LOWER(tm.meta_value) REGEXP 'todo[[:space:]]+chile|todo[[:space:]]+el[[:space:]]+pa[ií]s|todas[[:space:]]+las[[:space:]]+regiones'),
	(SELECT COUNT(*) FROM wp_posts WHERE ID=3 AND post_content='Envío a todo Chile.' AND post_excerpt='Envío a todo el país.'),
	(SELECT COUNT(*) FROM wp_posts WHERE ID=4 AND post_content='Envío a todo Chile.'),
	(SELECT COUNT(*) FROM wp_posts WHERE ID=1 AND post_content LIKE '%grita por todo el país%' AND post_content LIKE '%desde todas las regiones del continente%'),
	(SELECT COUNT(*) FROM wp_options WHERE option_value='a:1:{s:4:"copy";s:20:"Envío a todo Chile";}'),
	(SELECT COUNT(*) FROM wp_postmeta WHERE post_id=1 AND meta_key='rank_math_twitter_description' AND meta_value='a:1:{s:4:"copy";s:20:"Envío a todo Chile";}'),
	(SELECT COUNT(*) FROM wp_termmeta WHERE term_id=487)
);
SQL
)"

if [[ "${AKB_ASSERTIONS}" != "0|0|3|0|2|1|1|1|1|1|4" ]]; then
	echo "FAIL: shipping fixture assertions: ${AKB_ASSERTIONS}" >&2
	exit 1
fi

docker compose -f "${AKB_REPO_ROOT}/compose.yaml" exec -T "${AKB_DOCKER_DB_SERVICE}" \
	mariadb-dump "-u${AKB_DB_USER}" "-p${AKB_DB_PASSWORD}" \
	--skip-comments --compact --skip-add-locks --skip-disable-keys "${AKB_TEST_DB}" \
	> "${AKB_TMP_DIR}/first.sql"

akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_MIGRATION}" >/dev/null

docker compose -f "${AKB_REPO_ROOT}/compose.yaml" exec -T "${AKB_DOCKER_DB_SERVICE}" \
	mariadb-dump "-u${AKB_DB_USER}" "-p${AKB_DB_PASSWORD}" \
	--skip-comments --compact --skip-add-locks --skip-disable-keys "${AKB_TEST_DB}" \
	> "${AKB_TMP_DIR}/second.sql"

if ! diff -u "${AKB_TMP_DIR}/first.sql" "${AKB_TMP_DIR}/second.sql" >/dev/null; then
	echo "FAIL: shipping migration is not idempotent" >&2
	exit 1
fi

# A newer UI edit in any curated row must abort before the generic replacements
# can touch other data.
akb_mariadb "${AKB_TEST_DB}" <<'SQL'
UPDATE wp_posts
SET post_excerpt='<p>Edición UI posterior que debe preservarse.</p>'
WHERE ID=16557;
UPDATE wp_posts
SET post_content='Envío a todo Chile.'
WHERE ID=2;
SQL

set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_MIGRATION}" \
	> "${AKB_TMP_DIR}/expected-curated-conflict.log" 2>&1
AKB_CURATED_FAILURE_STATUS=$?
set -e

if [[ "${AKB_CURATED_FAILURE_STATUS}" -eq 0 ]]; then
	echo "FAIL: divergent curated excerpt was overwritten" >&2
	exit 1
fi
if [[ "$(akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names -e 'SELECT post_content FROM wp_posts WHERE ID=2;')" != "Envío a todo Chile." ]]; then
	echo "FAIL: curated preflight conflict did not prevent/roll back generic updates" >&2
	exit 1
fi

# Restore the exact desired excerpt. Rank Math metadata remains untouched and is
# handled exclusively in its administration UI.
akb_mariadb "${AKB_TEST_DB}" <<'SQL'
UPDATE wp_posts
SET post_excerpt='<p>Compra <strong>Maximum Berserk 10</strong> en <strong>Akibara</strong>. Vive la batalla contra Mozgus y la ceremonia de encarnación en la edición <strong>Maximum de Panini España</strong>. Despacho disponible según cobertura del transportista.</p>'
WHERE ID=16557;
SQL

# Fail-closed test: this unsupported shipping variant is intentionally not
# rewritten blindly in post_content, but the contextual assertion must detect it
# and roll back every earlier change in the same transaction.
akb_mariadb "${AKB_TEST_DB}" -e \
	"UPDATE wp_posts SET post_content='Envíos por todo el país.' WHERE ID=2;"

set +e
akb_mariadb --abort-source-on-error "${AKB_TEST_DB}" < "${AKB_MIGRATION}" \
	> "${AKB_TMP_DIR}/expected-failure.log" 2>&1
AKB_FAILURE_STATUS=$?
set -e

if [[ "${AKB_FAILURE_STATUS}" -eq 0 ]]; then
	echo "FAIL: PRE-COMMIT assertion accepted an unhandled absolute claim" >&2
	exit 1
fi

if [[ "$(akb_mariadb "${AKB_TEST_DB}" --batch --raw --skip-column-names -e 'SELECT post_content FROM wp_posts WHERE ID=2;')" != "Envíos por todo el país." ]]; then
	echo "FAIL: failed migration did not roll back atomically" >&2
	exit 1
fi

echo "PASS: shipping copy normalization, Rank Math metadata exclusion, scope, idempotency and rollback"
