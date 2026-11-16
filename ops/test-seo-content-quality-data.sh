#!/usr/bin/env bash
#
# All writes occur in an isolated clone of the Docker-local database.

set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_dir"

php -l ops/apply-seo-content-quality-data.php >/dev/null

fixture_name="akb_content_fixture_${BASHPID}"
if [[ ! "$fixture_name" =~ ^akb_content_fixture_[0-9]+$ ]]; then
	echo "Unsafe fixture database name: $fixture_name" >&2
	exit 97
fi

cleanup_fixture() {
	if [[ "$fixture_name" =~ ^akb_content_fixture_[0-9]+$ ]]; then
		docker compose exec -T db mariadb -uroot -proot_local \
			-e "DROP DATABASE IF EXISTS \`$fixture_name\`;" >/dev/null 2>&1 || true
	fi
}
trap cleanup_fixture EXIT

docker compose exec -T db mariadb -uroot -proot_local \
	-e "CREATE DATABASE \`$fixture_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
docker compose exec -T db mariadb-dump -uroot -proot_local \
	--single-transaction --skip-comments akibara \
	| docker compose exec -T db mariadb -uroot -proot_local "$fixture_name"

# Keep this fixture deterministic after the official WooCommerce UI/import lane
# has already applied some or all curated excerpts in the source database.
# These writes are limited to the isolated clone created above.
docker compose exec -T db mariadb -uroot -proot_local "$fixture_name" -e "
	UPDATE wp_posts
	SET post_excerpt = CASE
		WHEN ID IN (15406,15409,15412,15415)
			THEN 'Edición de lujo 2 en 1 de Ivrea Argentina. Envío a todo Chile desde Akibara.'
		WHEN ID = 15418
			THEN 'Mob Psycho 100 07 separa a Mob de Reigen y prepara el despertar de ?%, su emoción reprimida. Edición 2 en 1 de Ivrea Argentina; despacho según cobertura.'
		WHEN ID IN (15428,15431,15434,15437,15440,15443)
			THEN 'La obra cumbre de Naoki Urasawa en formato A5 de lujo. Envío a todo Chile desde Akibara.'
		ELSE post_excerpt
	END
	WHERE ID IN (15406,15409,15412,15415,15418,15428,15431,15434,15437,15440,15443);
"

wp_fixture() {
	docker compose run --rm -T \
		-e WORDPRESS_DB_NAME="$fixture_name" \
		-e WORDPRESS_DB_USER=root \
		-e WORDPRESS_DB_PASSWORD=root_local \
		-e AKB_SEO_CONTENT_APPLY="${AKB_SEO_CONTENT_APPLY:-}" \
		-e AKB_SEO_CONTENT_STRICT="${AKB_SEO_CONTENT_STRICT:-}" \
		cli "$@"
}

json_from_wp_output() {
	sed -n '/^{/,$p'
}

dry_run="$(
	wp_fixture wp eval-file - < ops/apply-seo-content-quality-data.php 2>/dev/null \
		| json_from_wp_output
)"
if [[ "$(jq -r '.mode' <<<"$dry_run")" != "dry-run" ]]; then
	echo "FAIL: default execution is not a dry-run" >&2
	exit 1
fi
if [[ "$(jq -r '.summary.conflicts' <<<"$dry_run")" != "0" ]]; then
	jq '.conflicts' <<<"$dry_run" >&2
	echo "FAIL: initial content manifest has conflicts" >&2
	exit 1
fi
if [[ "$(jq -r '.summary.rank_math_ui_mismatches' <<<"$dry_run")" != "0" ]]; then
	jq '.rank_math_ui' <<<"$dry_run" >&2
	echo "FAIL: Docker fixture does not reflect the completed Rank Math UI configuration" >&2
	exit 1
fi
if [[ "$(jq -r '.summary.nonempty_author_noindex_overrides' <<<"$dry_run")" != "0" ]]; then
	jq '.author_policy' <<<"$dry_run" >&2
	echo "FAIL: Docker fixture still has nonempty-author noindex overrides" >&2
	exit 1
fi

expected_excerpt_ids='[15406,15409,15412,15415,15418,15428,15431,15434,15437,15440,15443]'
planned_excerpts="$(
	jq '[.changes[] | select(.operation == "update_product_excerpt") | .payload]' <<<"$dry_run"
)"
if [[ "$(jq -c '[.[].product_id] | sort' <<<"$planned_excerpts")" != "$expected_excerpt_ids" ]]; then
	jq . <<<"$planned_excerpts" >&2
	echo "FAIL: curated excerpt product inventory drifted" >&2
	exit 1
fi
if [[ "$(jq -r '[.[].to] | unique | length' <<<"$planned_excerpts")" != "11" ]]; then
	echo "FAIL: curated product excerpts are not unique" >&2
	exit 1
fi
if [[ "$(jq -r '[.[].to | length <= 180 and contains("despacho según cobertura")] | all' <<<"$planned_excerpts")" != "true" ]]; then
	jq '[.[] | {product_id, length: (.to | length), to}]' <<<"$planned_excerpts" >&2
	echo "FAIL: curated product excerpt length or shipping policy drifted" >&2
	exit 1
fi

# A fingerprint conflict must abort the requested apply before any unrelated
# planned mutation is written.
preflight_baseline="$(
	docker compose exec -T db mariadb -uroot -proot_local "$fixture_name" \
		--batch --skip-column-names -e \
		"SELECT CONCAT_WS(CHAR(124),(SELECT COUNT(*) FROM wp_postmeta WHERE post_id=24992 AND meta_key='_wp_attachment_image_alt' AND meta_value<>''),(SELECT COUNT(*) FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=13120 AND tt.taxonomy='pa_autor'));"
)"
docker compose exec -T db mariadb -uroot -proot_local "$fixture_name" \
	-e "UPDATE wp_posts SET post_name='divergent-ui-slug' WHERE ID=24992;"
set +e
AKB_SEO_CONTENT_APPLY=1 AKB_SEO_CONTENT_STRICT=1 \
	wp_fixture wp eval-file - < ops/apply-seo-content-quality-data.php >/dev/null 2>&1
preflight_failure_status=$?
set -e
if [[ "$preflight_failure_status" -eq 0 ]]; then
	echo "FAIL: content apply accepted a divergent fixed-ID fingerprint" >&2
	exit 1
fi
preflight_zero_write="$(
	docker compose exec -T db mariadb -uroot -proot_local "$fixture_name" \
		--batch --skip-column-names -e \
		"SELECT CONCAT_WS(CHAR(124),(SELECT COUNT(*) FROM wp_postmeta WHERE post_id=24992 AND meta_key='_wp_attachment_image_alt' AND meta_value<>''),(SELECT COUNT(*) FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=13120 AND tt.taxonomy='pa_autor'));"
)"
if [[ "$preflight_zero_write" != "$preflight_baseline" ]]; then
	echo "FAIL: content preflight conflict allowed partial writes: $preflight_zero_write" >&2
	exit 1
fi
docker compose exec -T db mariadb -uroot -proot_local "$fixture_name" \
	-e "UPDATE wp_posts SET post_name='24975_new' WHERE ID=24992;"

# A stale excerpt must fail the exact guard without overwriting the editor.
docker compose exec -T db mariadb -uroot -proot_local "$fixture_name" \
	-e "UPDATE wp_posts SET post_excerpt='Edición manual posterior que debe preservarse.' WHERE ID=15406;"
set +e
AKB_SEO_CONTENT_APPLY=1 AKB_SEO_CONTENT_STRICT=1 \
	wp_fixture wp eval-file - < ops/apply-seo-content-quality-data.php >/dev/null 2>&1
excerpt_guard_status=$?
set -e
if [[ "$excerpt_guard_status" -eq 0 ]]; then
	echo "FAIL: curated excerpt guard accepted divergent editor content" >&2
	exit 1
fi
preserved_excerpt="$(
	docker compose exec -T db mariadb -uroot -proot_local "$fixture_name" \
		--batch --skip-column-names -e "SELECT post_excerpt FROM wp_posts WHERE ID=15406;"
)"
if [[ "$preserved_excerpt" != "Edición manual posterior que debe preservarse." ]]; then
	echo "FAIL: curated excerpt guard overwrote divergent editor content" >&2
	exit 1
fi
docker compose exec -T db mariadb -uroot -proot_local "$fixture_name" \
	-e "UPDATE wp_posts SET post_excerpt='Edición de lujo 2 en 1 de Ivrea Argentina. Envío a todo Chile desde Akibara.' WHERE ID=15406;"

# A race after preflight must roll back the complete transaction. The injected
# hook changes the second excerpt when the first save fires; the executor's
# exact in-transaction guard must reject that drift.
set +e
awk '
		NR == 1 {
			print
			print "add_action( \"save_post\", static function ( $post_id ) {"
			print "    static $injected = false;"
			print "    if ( ! $injected && 15406 === (int) $post_id ) {"
			print "        global $wpdb;"
			print "        $injected = true;"
			print "        $wpdb->update( $wpdb->posts, array( \"post_excerpt\" => \"Concurrent editor change\" ), array( \"ID\" => 15409 ) );"
			print "        clean_post_cache( 15409 );"
			print "    }"
			print "} );"
			next
		}
		{ print }
	' ops/apply-seo-content-quality-data.php \
		| AKB_SEO_CONTENT_APPLY=1 AKB_SEO_CONTENT_STRICT=1 \
			wp_fixture wp eval-file - >/dev/null 2>&1
transaction_failure_status=$?
set -e
if [[ "$transaction_failure_status" -eq 0 ]]; then
	echo "FAIL: transaction accepted an excerpt changed after preflight" >&2
	exit 1
fi
transaction_state="$(
	docker compose exec -T db mariadb -uroot -proot_local "$fixture_name" \
		--batch --skip-column-names -e \
		"SELECT CONCAT_WS(CHAR(124),(SELECT post_excerpt FROM wp_posts WHERE ID=15406),(SELECT post_excerpt FROM wp_posts WHERE ID=15409));"
)"
expected_mob_excerpt='Edición de lujo 2 en 1 de Ivrea Argentina. Envío a todo Chile desde Akibara.'
if [[ "$transaction_state" != "${expected_mob_excerpt}|${expected_mob_excerpt}" ]]; then
	echo "FAIL: excerpt transaction did not roll back atomically: $transaction_state" >&2
	exit 1
fi

applied="$(
	AKB_SEO_CONTENT_APPLY=1 AKB_SEO_CONTENT_STRICT=1 \
		wp_fixture wp eval-file - < ops/apply-seo-content-quality-data.php 2>/dev/null \
		| json_from_wp_output
)"
if [[ "$(jq -r '.summary.mutations' <<<"$applied")" != "11" ]]; then
	jq '.summary, .changes' <<<"$applied" >&2
	echo "FAIL: fixture did not apply the 11 curated excerpts exactly once" >&2
	exit 1
fi
if [[ "$(jq -r '.summary.duplicate_excerpt_groups' <<<"$applied")" != "0" ]]; then
	jq '.editorial_quality.duplicate_excerpt_groups' <<<"$applied" >&2
	echo "FAIL: duplicate excerpt groups remain after fixture apply" >&2
	exit 1
fi

idempotent="$(
	AKB_SEO_CONTENT_APPLY=1 AKB_SEO_CONTENT_STRICT=1 \
		wp_fixture wp eval-file - < ops/apply-seo-content-quality-data.php 2>/dev/null \
		| json_from_wp_output
)"
if [[ "$(jq -r '.summary.mutations' <<<"$idempotent")" != "0" ]]; then
	jq '.changes' <<<"$idempotent" >&2
	echo "FAIL: curated excerpt apply is not idempotent" >&2
	exit 1
fi

echo "PASS: 11 guarded excerpts, zero duplicates, atomic rollback and idempotent fixture apply"
