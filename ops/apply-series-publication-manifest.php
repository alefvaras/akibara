<?php
/**
 * Fail-closed backfill for the edition publication facts on `/serie/*`.
 *
 * The default mode is read-only. The payload may be read from a local file or
 * supplied as base64 so production can execute the script through
 * `wp eval-file -` without creating a remote artifact.
 */

defined( 'ABSPATH' ) || exit( 1 );

/**
 * Applies one reviewed publication manifest, or audits it without writes.
 */
final class Akibara_Series_Publication_Manifest {
	private const TAXONOMY = 'pa_serie';
	private const META_WORK_STATE = '_akb_serie_estado';
	private const META_WORK_TOTAL = '_akb_serie_tomos_obra';
	private const META_EDITION_STATE = '_akb_serie_estado_edicion';
	private const META_EDITION_TOTAL = '_akb_serie_tomos_edicion';
	private const REQUIRED_PLUGIN_VERSION = '1.101.0';
	private const PRODUCTION_URL = 'https://akibara.cl';
	private const EXPECTED_PRODUCTION_ROWS = 104;
	private const WORK_STATES = array(
		'completa',
		'en_publicacion',
		'en_pausa',
		'cancelada',
	);
	private const EDITION_STATES = array(
		'completa',
		'en_publicacion',
		'al_dia',
		'en_pausa',
		'cancelada',
	);

	/**
	 * Run the complete audit/apply gate.
	 *
	 * @return array<string,mixed>
	 */
	public static function run(): array {
		global $wpdb;

		$started_at    = gmdate( 'c' );
		$apply         = self::enabled( 'AKB_SERIES_PUBLICATION_APPLY' );
		$target        = self::required_target();
		$expected_site = self::required_environment( 'AKB_SERIES_PUBLICATION_EXPECTED_SITE_URL' );
		$observed_site = untrailingslashit( home_url( '/' ) );
		$expected_rows = self::required_positive_integer( 'AKB_SERIES_PUBLICATION_EXPECTED_ROWS' );

		self::assert_same(
			untrailingslashit( $expected_site ),
			$observed_site,
			'site_url_mismatch'
		);
		self::assert_runtime( $target, $observed_site, $expected_rows, $apply );

		$manifest_input = self::read_manifest( $target );
		$manifest       = $manifest_input['document'];
		$manifest_sha   = $manifest_input['sha256'];
		self::assert_same(
			self::required_sha256( 'AKB_SERIES_PUBLICATION_MANIFEST_SHA256' ),
			$manifest_sha,
			'manifest_sha256_mismatch'
		);

		self::assert_manifest_header( $manifest, $target, $observed_site );
		$rows = self::validate_manifest_rows( $manifest, $expected_rows );
		$entries = self::canonical_entries();
		self::assert_same( $expected_rows, count( $entries ), 'canonical_row_count_mismatch' );
		self::assert_exact_set( $rows, $entries, $target );

		if ( $apply ) {
			self::assert_apply_confirmation( $target, $manifest_sha );
		}

		$before = self::classify_rows( $rows, $entries );
		if ( ! empty( $before['conflicts'] ) ) {
			self::fail(
				'precondition_conflict',
				'Manifest values no longer match either expected_before or desired.',
				$before['conflicts']
			);
		}

		$changed_slugs = array();
		if ( $apply && ! empty( $before['eligible'] ) ) {
			$changed_slugs = self::apply_transaction( $rows, $entries, $before['eligible'] );
		}

		$after = self::classify_rows( $rows, $entries );
		if ( $apply ) {
			self::assert_same( $expected_rows, count( $after['desired'] ), 'post_apply_not_desired' );
			self::assert_same( 0, count( $after['eligible'] ), 'post_apply_still_eligible' );
			self::assert_same( 0, count( $after['conflicts'] ), 'post_apply_conflict' );
		}

		$purged_urls = array();
		if ( $apply && 'production' === $target ) {
			$purged_urls = self::purge_changed_landings( $changed_slugs, $entries );
		}

		return array(
			'schema_version'        => 2,
			'gate'                  => 'series_publication_edition_manifest',
			'status'                => 'PASS',
			'mode'                  => $apply ? 'apply' : 'audit',
			'target'                => $target,
			'wp_environment_type'   => wp_get_environment_type(),
			'site_url'              => $observed_site,
			'plugin_version'        => AKIBARA_BUSINESS_VERSION,
			'manifest_sha256'       => $manifest_sha,
			'canonical_rows'        => count( $entries ),
			'before'                => self::public_classification( $before ),
			'changed_count'         => count( $changed_slugs ),
			'changed_slugs'         => $changed_slugs,
			'after'                 => self::public_classification( $after ),
			'purged_count'          => count( $purged_urls ),
			'purged_urls'           => $purged_urls,
			'started_at_utc'        => $started_at,
			'completed_at_utc'      => gmdate( 'c' ),
		);
	}

	/**
	 * Validate the active plugin and the explicit target boundary.
	 */
	private static function assert_runtime( string $target, string $site, int $expected_rows, bool $apply ): void {
		if ( ! defined( 'AKIBARA_BUSINESS_VERSION' ) ) {
			self::fail( 'plugin_not_loaded', 'Akibara Business is not active.' );
		}
		// El manifiesto fue revisado contra 1.101.0, que introdujo este contrato.
		// Releases posteriores compatibles deben poder auditar el mismo dataset sin
		// reescribirlo sólo para cambiar una versión declarativa. Nunca se acepta una
		// versión anterior a la que entiende los cuatro campos.
		if ( version_compare( (string) AKIBARA_BUSINESS_VERSION, self::REQUIRED_PLUGIN_VERSION, '<' ) ) {
			self::fail(
				'plugin_version_mismatch',
				'The active plugin is older than the manifest contract.',
				array(
					'minimum'  => self::REQUIRED_PLUGIN_VERSION,
					'observed' => (string) AKIBARA_BUSINESS_VERSION,
				)
			);
		}

		if ( 'production' === $target ) {
			self::assert_same( self::PRODUCTION_URL, $site, 'production_site_mismatch' );
			self::assert_same(
				self::EXPECTED_PRODUCTION_ROWS,
				$expected_rows,
				'production_row_count_mismatch'
			);
			if ( $apply && ! self::enabled( 'AKB_SERIES_PUBLICATION_ALLOW_PRODUCTION' ) ) {
				self::fail( 'production_not_allowed', 'Production apply requires the second explicit opt-in.' );
			}
			if ( $apply && ! has_action( 'litespeed_purge_url' ) ) {
				self::fail( 'litespeed_purge_unavailable', 'The per-URL LiteSpeed purge action is not registered.' );
			}
			return;
		}

		if ( self::PRODUCTION_URL === $site ) {
			self::fail( 'local_target_on_production', 'The local target cannot use the production URL.' );
		}
		if ( $apply && ! self::enabled( 'AKB_SERIES_PUBLICATION_ALLOW_LOCAL' ) ) {
			self::fail( 'local_apply_not_allowed', 'Local apply requires its explicit opt-in.' );
		}
	}

	/**
	 * Read exactly one manifest source.
	 *
	 * @return array{document:array<string,mixed>,sha256:string}
	 */
	private static function read_manifest( string $target ): array {
		$path     = getenv( 'AKB_SERIES_PUBLICATION_MANIFEST_PATH' );
		$b64      = getenv( 'AKB_SERIES_PUBLICATION_MANIFEST_B64' );
		$gzip_b64 = getenv( 'AKB_SERIES_PUBLICATION_MANIFEST_GZIP_B64' );
		$path     = false === $path ? '' : trim( $path );
		$b64      = false === $b64 ? '' : trim( $b64 );
		$gzip_b64 = false === $gzip_b64 ? '' : trim( $gzip_b64 );
		$sources  = count( array_filter( array( $path, $b64, $gzip_b64 ), 'strlen' ) );

		if ( 1 !== $sources ) {
			self::fail( 'manifest_source_invalid', 'Provide exactly one manifest source.' );
		}
		if ( 'production' === $target && '' !== $path ) {
			self::fail( 'production_manifest_path_forbidden', 'Production must receive the payload without a remote file.' );
		}

		if ( '' !== $gzip_b64 ) {
			$compressed = base64_decode( $gzip_b64, true );
			if ( false === $compressed || '' === $compressed ) {
				self::fail( 'manifest_gzip_base64_invalid', 'Could not decode the compressed manifest.' );
			}
			$raw = gzdecode( $compressed );
			if ( false === $raw || '' === $raw ) {
				self::fail( 'manifest_gzip_invalid', 'Could not decompress the manifest.' );
			}
		} elseif ( '' !== $b64 ) {
			$raw = base64_decode( $b64, true );
			if ( false === $raw || '' === $raw ) {
				self::fail( 'manifest_base64_invalid', 'Could not decode the manifest.' );
			}
		} else {
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				self::fail( 'manifest_file_unreadable', 'The local manifest is not readable.' );
			}
			$raw = file_get_contents( $path );
			if ( false === $raw || '' === $raw ) {
				self::fail( 'manifest_file_empty', 'The local manifest is empty.' );
			}
		}

		$document = json_decode( $raw, true );
		if ( ! is_array( $document ) || JSON_ERROR_NONE !== json_last_error() ) {
			self::fail( 'manifest_json_invalid', 'The manifest is not valid JSON.' );
		}

		return array(
			'document' => $document,
			'sha256'   => hash( 'sha256', $raw ),
		);
	}

	/**
	 * Validate immutable manifest metadata.
	 *
	 * @param array<string,mixed> $manifest Manifest document.
	 */
	private static function assert_manifest_header( array $manifest, string $target, string $site ): void {
		self::assert_same( 2, $manifest['schema_version'] ?? null, 'manifest_schema_mismatch' );
		self::assert_same( self::TAXONOMY, $manifest['taxonomy'] ?? null, 'manifest_taxonomy_mismatch' );
		self::assert_same(
			self::REQUIRED_PLUGIN_VERSION,
			$manifest['required_plugin_version'] ?? null,
			'manifest_plugin_version_mismatch'
		);
		self::assert_same(
			self::META_WORK_STATE,
			$manifest['meta_keys']['work_state'] ?? null,
			'manifest_work_state_key_mismatch'
		);
		self::assert_same(
			self::META_WORK_TOTAL,
			$manifest['meta_keys']['work_total'] ?? null,
			'manifest_work_total_key_mismatch'
		);
		self::assert_same(
			self::META_EDITION_STATE,
			$manifest['meta_keys']['edition_state'] ?? null,
			'manifest_edition_state_key_mismatch'
		);
		self::assert_same(
			self::META_EDITION_TOTAL,
			$manifest['meta_keys']['edition_total'] ?? null,
			'manifest_edition_total_key_mismatch'
		);
		if ( 'production' === $target ) {
			self::assert_same(
				$site,
				untrailingslashit( (string) ( $manifest['site_url'] ?? '' ) ),
				'manifest_source_site_mismatch'
			);
		}
	}

	/**
	 * Normalize and validate manifest rows.
	 *
	 * @param array<string,mixed> $manifest Manifest document.
	 * @return array<string,array<string,mixed>> Rows keyed by slug.
	 */
	private static function validate_manifest_rows( array $manifest, int $expected_rows ): array {
		if ( ! isset( $manifest['rows'] ) || ! is_array( $manifest['rows'] ) ) {
			self::fail( 'manifest_rows_missing', 'The manifest has no rows array.' );
		}
		self::assert_same( $expected_rows, count( $manifest['rows'] ), 'manifest_row_count_mismatch' );

		$result = array();
		foreach ( $manifest['rows'] as $index => $row ) {
			if ( ! is_array( $row ) ) {
				self::fail( 'manifest_row_invalid', 'A row is not an object.', $index );
			}
			$slug = isset( $row['slug'] ) && is_string( $row['slug'] ) ? $row['slug'] : '';
			$name = isset( $row['name'] ) && is_string( $row['name'] ) ? $row['name'] : '';
			if ( '' === $slug || '' === $name || isset( $result[ $slug ] ) ) {
				self::fail( 'manifest_identity_invalid', 'Missing or duplicate row identity.', $slug );
			}

			$work_state    = $row['desired']['work_state'] ?? null;
			$work_total    = $row['desired']['work_total'] ?? null;
			$edition_state = $row['desired']['edition_state'] ?? null;
			$edition_total = $row['desired']['edition_total'] ?? null;

			if ( ! is_string( $work_state ) || ! in_array( $work_state, self::WORK_STATES, true ) ) {
				self::fail( 'manifest_work_state_invalid', 'Work state is outside the closed enum.', $slug );
			}
			if ( ! is_string( $edition_state ) || ! in_array( $edition_state, self::EDITION_STATES, true ) ) {
				self::fail( 'manifest_edition_state_invalid', 'Edition state is outside the closed enum.', $slug );
			}
			self::assert_nullable_total( $work_total, 'manifest_work_total_invalid', $slug );
			self::assert_nullable_total( $edition_total, 'manifest_edition_total_invalid', $slug );
			if ( 'completa' !== $work_state && null !== $work_total ) {
				self::fail( 'manifest_work_contract_invalid', 'Only a completed work may have a final total.', $slug );
			}
			if ( 'completa' === $edition_state && null === $edition_total ) {
				self::fail( 'manifest_edition_contract_invalid', 'A completed edition requires a total.', $slug );
			}
			if ( in_array( $edition_state, array( 'al_dia', 'en_pausa', 'cancelada' ), true ) && null !== $edition_total ) {
				self::fail( 'manifest_edition_contract_invalid', 'This edition state requires a null total.', $slug );
			}

			foreach ( array( 'work_state', 'work_total', 'edition_state', 'edition_total' ) as $field ) {
				if ( ! is_string( $row['expected_before'][ $field ] ?? null ) ) {
					self::fail( 'manifest_before_invalid', 'Expected values must be strings.', compact( 'slug', 'field' ) );
				}
			}
			$stored_work_total = null === $work_total ? '' : (string) $work_total;
			self::assert_same(
				$work_state,
				$row['expected_before']['work_state'],
				'manifest_work_before_state_mismatch',
				$slug
			);
			self::assert_same(
				$stored_work_total,
				$row['expected_before']['work_total'],
				'manifest_work_before_total_mismatch',
				$slug
			);
			if ( ! is_string( $row['edition_publisher'] ?? null ) || '' === trim( $row['edition_publisher'] ) ) {
				self::fail( 'manifest_edition_publisher_invalid', 'Edition publisher is required.', $slug );
			}

			$result[ $slug ] = $row;
		}

		ksort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Validate a nullable manifest total.
	 *
	 * @param mixed $total Total value.
	 * @param string $code Failure code.
	 * @param string $slug Series slug.
	 */
	private static function assert_nullable_total( $total, string $code, string $slug ): void {
		if ( null !== $total && ( ! is_int( $total ) || 1 > $total || 999 < $total ) ) {
			self::fail( $code, 'Total must be null or an integer from 1 through 999.', $slug );
		}
	}

	/**
	 * Read all publication metas for one term as stored strings.
	 *
	 * @return array{work_state:string,work_total:string,edition_state:string,edition_total:string}
	 */
	private static function current_values( int $term_id ): array {
		return array(
			'work_state'    => (string) get_term_meta( $term_id, self::META_WORK_STATE, true ),
			'work_total'    => (string) get_term_meta( $term_id, self::META_WORK_TOTAL, true ),
			'edition_state' => (string) get_term_meta( $term_id, self::META_EDITION_STATE, true ),
			'edition_total' => (string) get_term_meta( $term_id, self::META_EDITION_TOTAL, true ),
		);
	}

	/**
	 * Normalize desired manifest values to their term-meta representation.
	 *
	 * @param array<string,mixed> $row Manifest row.
	 * @return array{work_state:string,work_total:string,edition_state:string,edition_total:string}
	 */
	private static function desired_stored_values( array $row ): array {
		return array(
			'work_state'    => (string) $row['desired']['work_state'],
			'work_total'    => null === $row['desired']['work_total'] ? '' : (string) $row['desired']['work_total'],
			'edition_state' => (string) $row['desired']['edition_state'],
			'edition_total' => null === $row['desired']['edition_total'] ? '' : (string) $row['desired']['edition_total'],
		);
	}

	/**
	 * Read the same canonical set published by SeriesIndex.
	 *
	 * @return array<string,array<string,mixed>> Entries keyed by slug.
	 */
	private static function canonical_entries(): array {
		$class = '\\Akibara\\Business\\Modules\\SeriesDirectory\\SeriesIndex';
		if ( ! class_exists( $class ) ) {
			self::fail( 'series_index_unavailable', 'The canonical SeriesIndex class is unavailable.' );
		}

		$index   = new $class();
		$entries = $index->entries();
		$result  = array();
		foreach ( $entries as $entry ) {
			$slug = isset( $entry['slug'] ) ? (string) $entry['slug'] : '';
			if ( '' === $slug || isset( $result[ $slug ] ) ) {
				self::fail( 'canonical_identity_invalid', 'SeriesIndex returned an invalid identity.', $slug );
			}
			$result[ $slug ] = $entry;
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Require identical manifest and runtime term sets.
	 *
	 * @param array<string,array<string,mixed>> $rows Manifest rows.
	 * @param array<string,array<string,mixed>> $entries Runtime entries.
	 */
	private static function assert_exact_set( array $rows, array $entries, string $target ): void {
		$missing_manifest = array_values( array_diff( array_keys( $entries ), array_keys( $rows ) ) );
		$missing_runtime  = array_values( array_diff( array_keys( $rows ), array_keys( $entries ) ) );
		if ( $missing_manifest || $missing_runtime ) {
			self::fail(
				'canonical_set_mismatch',
				'Manifest and SeriesIndex do not contain the same slugs.',
				compact( 'missing_manifest', 'missing_runtime' )
			);
		}

		foreach ( $rows as $slug => $row ) {
			$entry = $entries[ $slug ];
			self::assert_same( (string) $row['name'], (string) $entry['name'], 'term_name_mismatch', $slug );
			if ( 'production' === $target ) {
				self::assert_same( (int) $row['term_id'], (int) $entry['term_id'], 'term_id_mismatch', $slug );
			}
			$expected_path = wp_parse_url( (string) $row['landing_url'], PHP_URL_PATH );
			$observed_path = wp_parse_url( (string) $entry['url'], PHP_URL_PATH );
			self::assert_same( $expected_path, $observed_path, 'landing_path_mismatch', $slug );
		}
	}

	/**
	 * Classify current data as desired, safely eligible, or conflicting.
	 *
	 * @param array<string,array<string,mixed>> $rows Manifest rows.
	 * @param array<string,array<string,mixed>> $entries Runtime entries.
	 * @return array{desired:array<int,string>,eligible:array<int,string>,conflicts:array<int,array<string,mixed>>,before:array<string,array<string,string>>}
	 */
	private static function classify_rows( array $rows, array $entries ): array {
		$result = array(
			'desired'   => array(),
			'eligible'  => array(),
			'conflicts' => array(),
			'before'    => array(),
		);

		foreach ( $rows as $slug => $row ) {
			$term_id = (int) $entries[ $slug ]['term_id'];
			$current = self::current_values( $term_id );
			$before  = $row['expected_before'];
			$desired = self::desired_stored_values( $row );

			$result['before'][ $slug ] = $current;

			if ( $current['work_state'] !== $desired['work_state'] || $current['work_total'] !== $desired['work_total'] ) {
				$result['conflicts'][] = array(
					'slug'     => $slug,
					'scope'    => 'immutable_work_precondition',
					'current'  => $current,
					'expected' => $before,
					'desired'  => $desired,
				);
				continue;
			}

			if ( $current['edition_state'] === $desired['edition_state'] && $current['edition_total'] === $desired['edition_total'] ) {
				$result['desired'][] = $slug;
				continue;
			}

			$state_safe = $current['edition_state'] === $before['edition_state'] || $current['edition_state'] === $desired['edition_state'];
			$total_safe = $current['edition_total'] === $before['edition_total'] || $current['edition_total'] === $desired['edition_total'];
			if ( $state_safe && $total_safe ) {
				$result['eligible'][] = $slug;
				continue;
			}

			$result['conflicts'][] = array(
				'slug'     => $slug,
				'scope'    => 'edition_precondition',
				'current'  => $current,
				'expected' => $before,
				'desired'  => $desired,
			);
		}

		return $result;
	}

	/**
	 * Apply eligible rows inside one database transaction.
	 *
	 * @param array<string,array<string,mixed>> $rows Manifest rows.
	 * @param array<string,array<string,mixed>> $entries Runtime entries.
	 * @param array<int,string> $eligible Eligible slugs.
	 * @return array<int,string>
	 */
	private static function apply_transaction( array $rows, array $entries, array $eligible ): array {
		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			self::fail( 'transaction_start_failed', 'Could not start the termmeta transaction.' );
		}

		$changed = array();
		try {
			foreach ( $eligible as $slug ) {
				$row     = $rows[ $slug ];
				$term_id = (int) $entries[ $slug ]['term_id'];
				$before  = $row['expected_before'];
				$desired = self::desired_stored_values( $row );
				$current = self::current_values( $term_id );

				if ( $current['work_state'] !== $desired['work_state'] || $current['work_total'] !== $desired['work_total'] ) {
					throw new RuntimeException( 'work_precondition_changed:' . $slug );
				}
				if ( ! in_array( $current['edition_state'], array( $before['edition_state'], $desired['edition_state'] ), true )
					|| ! in_array( $current['edition_total'], array( $before['edition_total'], $desired['edition_total'] ), true ) ) {
					throw new RuntimeException( 'edition_precondition_changed:' . $slug );
				}

				$result = update_term_meta( $term_id, self::META_EDITION_STATE, $row['desired']['edition_state'] );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( 'edition_state_write_failed:' . $slug );
				}

				if ( null === $row['desired']['edition_total'] ) {
					delete_term_meta( $term_id, self::META_EDITION_TOTAL );
				} else {
					$result = update_term_meta( $term_id, self::META_EDITION_TOTAL, (int) $row['desired']['edition_total'] );
					if ( is_wp_error( $result ) ) {
						throw new RuntimeException( 'edition_total_write_failed:' . $slug );
					}
				}

				wp_cache_delete( $term_id, 'term_meta' );
				if ( self::current_values( $term_id ) !== $desired ) {
					throw new RuntimeException( 'post_write_verification_failed:' . $slug );
				}
				$changed[] = $slug;
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'transaction_commit_failed' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			foreach ( $eligible as $slug ) {
				wp_cache_delete( (int) $entries[ $slug ]['term_id'], 'term_meta' );
			}
			self::fail( 'transaction_rolled_back', $error->getMessage() );
		}

		return $changed;
	}

	/**
	 * Purge each changed landing through the same LiteSpeed action as the admin UI.
	 *
	 * @param array<int,string> $changed Changed slugs.
	 * @param array<string,array<string,mixed>> $entries Runtime entries.
	 * @return array<int,string>
	 */
	private static function purge_changed_landings( array $changed, array $entries ): array {
		$urls = array();
		foreach ( $changed as $slug ) {
			$url = isset( $entries[ $slug ]['url'] ) ? (string) $entries[ $slug ]['url'] : '';
			if ( '' === $url ) {
				self::fail( 'landing_url_missing_after_commit', 'A changed landing could not be purged.', $slug );
			}
			do_action( 'litespeed_purge_url', $url );
			$urls[] = $url;
		}
		return $urls;
	}

	/**
	 * Require the independent apply checksum.
	 */
	private static function assert_apply_confirmation( string $target, string $manifest_sha ): void {
		self::assert_same(
			$manifest_sha,
			self::required_sha256( 'AKB_SERIES_PUBLICATION_CONFIRM_SHA256' ),
			'apply_confirmation_mismatch'
		);
		if ( 'production' === $target && ! self::enabled( 'AKB_SERIES_PUBLICATION_ALLOW_PRODUCTION' ) ) {
			self::fail( 'production_not_allowed', 'Production apply is not confirmed.' );
		}
	}

	/**
	 * Return only counts and conflict details; the full before snapshot remains in
	 * the manifest/export kept locally.
	 *
	 * @param array<string,mixed> $classification Classification result.
	 * @return array<string,mixed>
	 */
	private static function public_classification( array $classification ): array {
		return array(
			'desired_count'  => count( $classification['desired'] ),
			'eligible_count' => count( $classification['eligible'] ),
			'conflict_count' => count( $classification['conflicts'] ),
			'conflicts'      => $classification['conflicts'],
		);
	}

	private static function required_target(): string {
		$target = self::required_environment( 'AKB_SERIES_PUBLICATION_TARGET' );
		if ( ! in_array( $target, array( 'local', 'production' ), true ) ) {
			self::fail( 'target_invalid', 'Target must be local or production.' );
		}
		return $target;
	}

	private static function required_environment( string $name ): string {
		$value = getenv( $name );
		if ( false === $value || '' === trim( $value ) ) {
			self::fail( 'environment_missing', 'Missing required environment value: ' . $name );
		}
		return trim( $value );
	}

	private static function required_sha256( string $name ): string {
		$value = strtolower( self::required_environment( $name ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			self::fail( 'sha256_invalid', 'Invalid SHA-256 in ' . $name );
		}
		return $value;
	}

	private static function required_positive_integer( string $name ): int {
		$value = self::required_environment( $name );
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			self::fail( 'integer_invalid', 'Expected a positive integer in ' . $name );
		}
		return (int) $value;
	}

	private static function enabled( string $name ): bool {
		return '1' === getenv( $name );
	}

	/**
	 * @param mixed $expected Expected value.
	 * @param mixed $actual Actual value.
	 * @param mixed $details Optional details.
	 */
	private static function assert_same( $expected, $actual, string $code, $details = null ): void {
		if ( $expected !== $actual ) {
			self::fail(
				$code,
				'Expected and observed values differ.',
				array(
					'expected' => $expected,
					'observed' => $actual,
					'details'  => $details,
				)
			);
		}
	}

	/**
	 * @param mixed $details Failure details.
	 */
	private static function fail( string $code, string $message, $details = null ): void {
		throw new RuntimeException(
			wp_json_encode(
				array(
					'code'    => $code,
					'message' => $message,
					'details' => $details,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}
}

try {
	$result = Akibara_Series_Publication_Manifest::run();
	echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
} catch ( Throwable $error ) {
	fwrite(
		STDERR,
		wp_json_encode(
			array(
				'status'  => 'FAIL',
				'failure' => json_decode( $error->getMessage(), true ) ?: $error->getMessage(),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n"
	);
	exit( 1 );
}
