<?php
/**
 * Fail-closed applicator for reviewed `/serie/*` editorial copy corrections.
 *
 * Audit is the default. Production receives the manifest through stdin/base64
 * with `wp eval-file -`; no manifest, backup or temporary PHP file is created
 * remotely. Each replacement is idempotent and requires exactly one reviewed
 * `before` fragment or exactly one already-applied `after` fragment.
 */

defined( 'ABSPATH' ) || exit( 1 );

/** Applies or audits the reviewed content-truth manifest. */
final class Akibara_Series_Content_Truth_Manifest {
	private const TAXONOMY                 = 'pa_serie';
	private const REQUIRED_PLUGIN_VERSION = '1.102.4';
	private const PRODUCTION_URL           = 'https://akibara.cl';
	private const PRODUCTION_ROWS          = 104;

	/** @return array<string,mixed> */
	public static function run(): array {
		$started_at    = gmdate( 'c' );
		$target        = self::required_target();
		$apply         = self::enabled( 'AKB_SERIES_CONTENT_APPLY' );
		$expected_site = untrailingslashit( self::required_environment( 'AKB_SERIES_CONTENT_EXPECTED_SITE_URL' ) );
		$observed_site = untrailingslashit( home_url( '/' ) );
		$expected_rows = self::required_positive_integer( 'AKB_SERIES_CONTENT_EXPECTED_ROWS' );

		self::assert_same( $expected_site, $observed_site, 'site_url_mismatch' );
		self::assert_runtime( $target, $observed_site, $expected_rows, $apply );

		$input        = self::read_manifest( $target );
		$manifest     = $input['document'];
		$manifest_sha = $input['sha256'];
		self::assert_same(
			self::required_sha256( 'AKB_SERIES_CONTENT_MANIFEST_SHA256' ),
			$manifest_sha,
			'manifest_sha256_mismatch'
		);

		$rows    = self::validate_manifest( $manifest );
		$entries = self::canonical_entries();
		self::assert_same( $expected_rows, count( $entries ), 'canonical_row_count_mismatch' );
		$scope = self::resolve_scope( $rows, $entries, $target );

		if ( $apply ) {
			self::assert_apply_confirmation( $target, $manifest_sha );
		}

		$before = self::classify_rows( $scope['present'], $entries );
		if ( ! empty( $before['conflicts'] ) ) {
			self::fail(
				'precondition_conflict',
				'One or more reviewed fragments no longer match production.',
				$before['conflicts']
			);
		}

		// LiteSpeed's WP-CLI callbacks print human success lines from inside their
		// hooks. Keep stdout as exactly one JSON document without weakening any
		// post-write verification or suppressing STDERR failures.
		$buffer_level = ob_get_level();
		if ( $apply ) {
			ob_start();
		}
		try {
			$changed = array();
			if ( $apply && ! empty( $before['eligible'] ) ) {
				$changed = self::apply_transaction( $scope['present'], $entries );
			}

			$after = self::classify_rows( $scope['present'], $entries );
			if ( $apply ) {
				self::assert_same( count( $scope['present'] ), count( $after['desired'] ), 'post_apply_not_desired' );
				self::assert_same( 0, count( $after['eligible'] ), 'post_apply_still_eligible' );
				self::assert_same( 0, count( $after['conflicts'] ), 'post_apply_conflict' );
			}

			$purged = $apply ? self::purge_changed_surfaces( $changed, $rows ) : array();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
		}

		return array(
			'schema_version'      => 1,
			'gate'                => 'series_content_truth_manifest',
			'status'              => 'PASS',
			'mode'                => $apply ? 'apply' : 'audit',
			'target'              => $target,
			'wp_environment_type' => wp_get_environment_type(),
			'site_url'            => $observed_site,
			'plugin_version'      => AKIBARA_BUSINESS_VERSION,
			'manifest_sha256'     => $manifest_sha,
			'canonical_rows'      => count( $entries ),
			'manifest_change_rows' => count( $rows ),
			'present_change_rows' => count( $scope['present'] ),
			'missing_change_rows' => count( $scope['missing'] ),
			'missing_slugs'       => $scope['missing'],
			'before'              => self::public_classification( $before ),
			'changed_count'       => count( $changed ),
			'changed_slugs'       => $changed,
			'after'               => self::public_classification( $after ),
			'purged'              => $purged,
			'started_at_utc'      => $started_at,
			'completed_at_utc'    => gmdate( 'c' ),
		);
	}

	private static function assert_runtime( string $target, string $site, int $expected_rows, bool $apply ): void {
		if ( ! defined( 'AKIBARA_BUSINESS_VERSION' ) ) {
			self::fail( 'plugin_not_loaded', 'Akibara Business is not active.' );
		}
		self::assert_same( self::REQUIRED_PLUGIN_VERSION, (string) AKIBARA_BUSINESS_VERSION, 'plugin_version_mismatch' );

		if ( 'production' === $target ) {
			self::assert_same( self::PRODUCTION_URL, $site, 'production_site_mismatch' );
			self::assert_same( self::PRODUCTION_ROWS, $expected_rows, 'production_row_count_mismatch' );
			if ( $apply && ! self::enabled( 'AKB_SERIES_CONTENT_ALLOW_PRODUCTION' ) ) {
				self::fail( 'production_not_allowed', 'Production apply requires a second explicit opt-in.' );
			}
			if ( $apply && ! has_action( 'litespeed_purge' ) ) {
				self::fail( 'litespeed_purge_unavailable', 'The LiteSpeed term-tag purge hook is unavailable.' );
			}
			return;
		}

		if ( self::PRODUCTION_URL === $site ) {
			self::fail( 'local_target_on_production', 'The local target cannot use the production URL.' );
		}
		if ( $apply && ! self::enabled( 'AKB_SERIES_CONTENT_ALLOW_LOCAL' ) ) {
			self::fail( 'local_apply_not_allowed', 'Local apply requires its explicit opt-in.' );
		}
	}

	/** @return array{document:array<string,mixed>,sha256:string} */
	private static function read_manifest( string $target ): array {
		$path     = self::optional_environment( 'AKB_SERIES_CONTENT_MANIFEST_PATH' );
		$b64      = self::optional_environment( 'AKB_SERIES_CONTENT_MANIFEST_B64' );
		$gzip_b64 = self::optional_environment( 'AKB_SERIES_CONTENT_MANIFEST_GZIP_B64' );
		if ( 1 !== count( array_filter( array( $path, $b64, $gzip_b64 ), 'strlen' ) ) ) {
			self::fail( 'manifest_source_invalid', 'Provide exactly one manifest source.' );
		}
		if ( 'production' === $target && '' !== $path ) {
			self::fail( 'production_manifest_path_forbidden', 'Production must receive the manifest without a remote file.' );
		}

		if ( '' !== $gzip_b64 ) {
			$compressed = base64_decode( $gzip_b64, true );
			$raw        = false === $compressed ? false : gzdecode( $compressed );
			if ( ! is_string( $raw ) || '' === $raw ) {
				self::fail( 'manifest_gzip_invalid', 'Could not decode the compressed manifest.' );
			}
		} elseif ( '' !== $b64 ) {
			$raw = base64_decode( $b64, true );
			if ( ! is_string( $raw ) || '' === $raw ) {
				self::fail( 'manifest_base64_invalid', 'Could not decode the manifest.' );
			}
		} else {
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				self::fail( 'manifest_file_unreadable', 'The local manifest is not readable.' );
			}
			$raw = file_get_contents( $path );
			if ( ! is_string( $raw ) || '' === $raw ) {
				self::fail( 'manifest_file_empty', 'The local manifest is empty.' );
			}
		}

		$document = json_decode( $raw, true );
		if ( ! is_array( $document ) || JSON_ERROR_NONE !== json_last_error() ) {
			self::fail( 'manifest_json_invalid', 'The manifest is not valid JSON.' );
		}

		return array( 'document' => $document, 'sha256' => hash( 'sha256', $raw ) );
	}

	/**
	 * @param array<string,mixed> $manifest Manifest document.
	 * @return array<string,array<string,mixed>> Rows keyed by slug.
	 */
	private static function validate_manifest( array $manifest ): array {
		self::assert_same( '1.0.0', $manifest['schema_version'] ?? null, 'manifest_schema_mismatch' );
		self::assert_same( 'proposal_only', $manifest['mode'] ?? null, 'manifest_mode_mismatch' );
		self::assert_same( self::PRODUCTION_URL, untrailingslashit( (string) ( $manifest['scope']['site'] ?? '' ) ), 'manifest_site_mismatch' );
		self::assert_same( self::TAXONOMY, $manifest['scope']['taxonomy'] ?? null, 'manifest_taxonomy_mismatch' );
		self::assert_same( self::PRODUCTION_ROWS, $manifest['scope']['terms_audited'] ?? null, 'manifest_audited_rows_mismatch' );

		$description_changes = $manifest['description_changes'] ?? null;
		$name_changes        = $manifest['name_changes'] ?? null;
		if ( ! is_array( $description_changes ) || ! array_is_list( $description_changes ) || ! is_array( $name_changes ) || ! array_is_list( $name_changes ) ) {
			self::fail( 'manifest_changes_invalid', 'Description and name changes must be JSON lists.' );
		}

		$rows               = array();
		$replacement_count  = 0;
		$description_slugs  = array();
		foreach ( $description_changes as $change ) {
			$identity = self::validated_identity( $change );
			$slug     = $identity['slug'];
			if ( isset( $description_slugs[ $slug ] ) ) {
				self::fail( 'manifest_description_duplicate', 'A description row is duplicated.', $slug );
			}
			$replacements = $change['replacements'] ?? null;
			if ( ! is_array( $replacements ) || ! array_is_list( $replacements ) || array() === $replacements ) {
				self::fail( 'manifest_replacements_invalid', 'Every description row needs a replacements list.', $slug );
			}

			$validated = array();
			foreach ( $replacements as $replacement ) {
				$before = is_array( $replacement ) && is_string( $replacement['before'] ?? null ) ? $replacement['before'] : '';
				$after  = is_array( $replacement ) && is_string( $replacement['after'] ?? null ) ? $replacement['after'] : '';
				if ( '' === $before || '' === $after || $before === $after ) {
					self::fail( 'manifest_replacement_invalid', 'Replacement fragments must be non-empty and different.', $slug );
				}
				$validated[] = array( 'before' => $before, 'after' => $after );
				++$replacement_count;
			}

			$rows[ $slug ] = array(
				'term_id'      => $identity['term_id'],
				'slug'         => $slug,
				'priority'     => $identity['priority'],
				'replacements' => $validated,
				'name_change'  => null,
			);
			$description_slugs[ $slug ] = true;
		}

		foreach ( $name_changes as $change ) {
			$identity = self::validated_identity( $change );
			$slug     = $identity['slug'];
			$before   = is_string( $change['before'] ?? null ) ? $change['before'] : '';
			$after    = is_string( $change['after'] ?? null ) ? $change['after'] : '';
			if ( '' === $before || '' === $after || $before === $after ) {
				self::fail( 'manifest_name_change_invalid', 'Name fragments must be non-empty and different.', $slug );
			}
			if ( isset( $rows[ $slug ] ) && (int) $rows[ $slug ]['term_id'] !== $identity['term_id'] ) {
				self::fail( 'manifest_identity_conflict', 'Name and description identities disagree.', $slug );
			}
			if ( ! isset( $rows[ $slug ] ) ) {
				$rows[ $slug ] = array(
					'term_id'      => $identity['term_id'],
					'slug'         => $slug,
					'priority'     => $identity['priority'],
					'replacements' => array(),
					'name_change'  => null,
				);
			}
			if ( null !== $rows[ $slug ]['name_change'] ) {
				self::fail( 'manifest_name_duplicate', 'A name row is duplicated.', $slug );
			}
			$rows[ $slug ]['name_change'] = array( 'before' => $before, 'after' => $after );
		}

		$summary = $manifest['summary'] ?? array();
		self::assert_same( count( $description_changes ), $summary['terms_with_description_change'] ?? null, 'manifest_description_summary_mismatch' );
		self::assert_same( $replacement_count, $summary['description_replacements'] ?? null, 'manifest_replacement_summary_mismatch' );
		self::assert_same( count( $name_changes ), $summary['term_name_changes'] ?? null, 'manifest_name_summary_mismatch' );
		self::assert_same( self::PRODUCTION_ROWS, $summary['terms_audited'] ?? null, 'manifest_term_summary_mismatch' );

		ksort( $rows, SORT_STRING );
		return $rows;
	}

	/** @param mixed $change @return array{term_id:int,slug:string,priority:string} */
	private static function validated_identity( $change ): array {
		if ( ! is_array( $change ) ) {
			self::fail( 'manifest_change_invalid', 'A change row is not an object.' );
		}
		$term_id  = isset( $change['term_id'] ) && is_int( $change['term_id'] ) ? $change['term_id'] : 0;
		$slug     = is_string( $change['slug'] ?? null ) ? $change['slug'] : '';
		$priority = is_string( $change['priority'] ?? null ) ? $change['priority'] : '';
		if ( $term_id < 1 || '' === $slug || sanitize_title( $slug ) !== $slug || ! in_array( $priority, array( 'P0', 'P1' ), true ) ) {
			self::fail( 'manifest_identity_invalid', 'A change identity is invalid.', $slug );
		}
		return compact( 'term_id', 'slug', 'priority' );
	}

	/** @return array<string,array<string,mixed>> */
	private static function canonical_entries(): array {
		$class = '\\Akibara\\Business\\Modules\\SeriesDirectory\\SeriesIndex';
		if ( ! class_exists( $class ) ) {
			self::fail( 'series_index_unavailable', 'The canonical SeriesIndex class is unavailable.' );
		}
		$result = array();
		foreach ( ( new $class() )->entries() as $entry ) {
			$slug = is_array( $entry ) && is_string( $entry['slug'] ?? null ) ? $entry['slug'] : '';
			if ( '' === $slug || isset( $result[ $slug ] ) ) {
				self::fail( 'canonical_identity_invalid', 'SeriesIndex returned an invalid identity.', $slug );
			}
			$result[ $slug ] = $entry;
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * @param array<string,array<string,mixed>> $rows Manifest rows.
	 * @param array<string,array<string,mixed>> $entries Runtime entries.
	 * @return array{present:array<string,array<string,mixed>>,missing:array<int,string>}
	 */
	private static function resolve_scope( array $rows, array $entries, string $target ): array {
		$present = array();
		$missing = array();
		foreach ( $rows as $slug => $row ) {
			if ( ! isset( $entries[ $slug ] ) ) {
				$missing[] = $slug;
				continue;
			}
			if ( 'production' === $target ) {
				self::assert_same( (int) $row['term_id'], (int) $entries[ $slug ]['term_id'], 'production_term_id_mismatch', $slug );
			}
			$present[ $slug ] = $row;
		}
		if ( 'production' === $target && array() !== $missing ) {
			self::fail( 'production_change_set_incomplete', 'A reviewed production change is absent from SeriesIndex.', $missing );
		}
		return compact( 'present', 'missing' );
	}

	/**
	 * @param array<string,array<string,mixed>> $rows Selected manifest rows.
	 * @param array<string,array<string,mixed>> $entries Runtime entries.
	 * @return array{desired:array<int,string>,eligible:array<string,array<string,string>>,conflicts:array<int,array<string,mixed>>}
	 */
	private static function classify_rows( array $rows, array $entries ): array {
		$result = array( 'desired' => array(), 'eligible' => array(), 'conflicts' => array() );
		foreach ( $rows as $slug => $row ) {
			$term = get_term( (int) $entries[ $slug ]['term_id'], self::TAXONOMY );
			if ( ! $term instanceof WP_Term ) {
				$result['conflicts'][] = array( 'slug' => $slug, 'field' => 'identity', 'reason' => 'term_unavailable' );
				continue;
			}
			$current_name        = (string) $term->name;
			$current_description = (string) $term->description;
			$next_name           = $current_name;
			$next_description    = $current_description;
			$changed             = false;
			$conflict            = null;

			if ( is_array( $row['name_change'] ) ) {
				$before = $row['name_change']['before'];
				$after  = $row['name_change']['after'];
				if ( $current_name === $before ) {
					$next_name = $after;
					$changed   = true;
				} elseif ( $current_name !== $after ) {
					$conflict = array( 'slug' => $slug, 'field' => 'name', 'reason' => 'exact_name_mismatch', 'current_hash' => hash( 'sha256', $current_name ) );
				}
			}

			foreach ( $row['replacements'] as $index => $replacement ) {
				if ( null !== $conflict ) {
					break;
				}
				$before_count = substr_count( $next_description, $replacement['before'] );
				$after_count  = substr_count( $next_description, $replacement['after'] );
				if ( 1 === $before_count && 0 === $after_count ) {
					$next_description = str_replace( $replacement['before'], $replacement['after'], $next_description );
					$changed          = true;
					continue;
				}
				if ( 0 === $before_count && 1 === $after_count ) {
					continue;
				}
				$conflict = array(
					'slug'         => $slug,
					'field'        => 'description',
					'replacement'  => $index,
					'before_count' => $before_count,
					'after_count'  => $after_count,
					'current_hash' => hash( 'sha256', $current_description ),
				);
			}

			if ( null !== $conflict ) {
				$result['conflicts'][] = $conflict;
			} elseif ( $changed ) {
				$result['eligible'][ $slug ] = array( 'name' => $next_name, 'description' => $next_description );
			} else {
				$result['desired'][] = $slug;
			}
		}
		return $result;
	}

	/**
	 * @param array<string,array<string,mixed>> $rows Selected manifest rows.
	 * @param array<string,array<string,mixed>> $entries Runtime entries.
	 * @return array<int,string>
	 */
	private static function apply_transaction( array $rows, array $entries ): array {
		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			self::fail( 'transaction_start_failed', 'Could not start the term transaction.' );
		}

		$changed = array();
		try {
			$inside = self::classify_rows( $rows, $entries );
			if ( ! empty( $inside['conflicts'] ) ) {
				throw new RuntimeException( 'precondition_changed_inside_transaction' );
			}

			foreach ( array_keys( $inside['eligible'] ) as $slug ) {
				$single = self::classify_rows( array( $slug => $rows[ $slug ] ), $entries );
				if ( ! empty( $single['conflicts'] ) ) {
					throw new RuntimeException( 'precondition_changed:' . $slug );
				}
				if ( empty( $single['eligible'][ $slug ] ) ) {
					continue;
				}

				$term_id = (int) $entries[ $slug ]['term_id'];
				$desired = $single['eligible'][ $slug ];
				$updated = wp_update_term(
					$term_id,
					self::TAXONOMY,
					array( 'name' => $desired['name'], 'description' => $desired['description'] )
				);
				if ( is_wp_error( $updated ) ) {
					throw new RuntimeException( 'term_write_failed:' . $slug . ':' . $updated->get_error_code() );
				}

				clean_term_cache( $term_id, self::TAXONOMY );
				$verified = self::classify_rows( array( $slug => $rows[ $slug ] ), $entries );
				if ( ! in_array( $slug, $verified['desired'], true ) ) {
					throw new RuntimeException( 'post_write_verification_failed:' . $slug );
				}
				$changed[] = $slug;
			}

			$precommit = self::classify_rows( $rows, $entries );
			if ( count( $precommit['desired'] ) !== count( $rows ) || ! empty( $precommit['eligible'] ) || ! empty( $precommit['conflicts'] ) ) {
				throw new RuntimeException( 'precommit_verification_failed' );
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'transaction_commit_failed' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			foreach ( $entries as $entry ) {
				clean_term_cache( (int) $entry['term_id'], self::TAXONOMY );
			}
			self::fail( 'transaction_rolled_back', $error->getMessage() );
		}

		return $changed;
	}

	/** @param array<int,string> $changed @param array<string,array<string,mixed>> $rows @return array<string,mixed> */
	private static function purge_changed_surfaces( array $changed, array $rows ): array {
		$term_tags = array();
		$name_changed = false;
		foreach ( $changed as $slug ) {
			$term = get_term_by( 'slug', $slug, self::TAXONOMY );
			if ( ! $term instanceof WP_Term ) {
				self::fail( 'purge_term_unavailable', 'A changed term cannot be resolved for cache purge.', $slug );
			}
			$tag = 'T.' . (int) $term->term_id;
			do_action( 'litespeed_purge', $tag );
			$term_tags[] = $tag;
			$name_changed = $name_changed || is_array( $rows[ $slug ]['name_change'] );
		}

		$hub = '';
		if ( $name_changed ) {
			$hub = home_url( '/series/' );
			do_action( 'litespeed_purge_url', $hub );
		}
		return array( 'term_tags' => $term_tags, 'series_hub_url' => $hub );
	}

	/** @param array<string,mixed> $classification @return array<string,mixed> */
	private static function public_classification( array $classification ): array {
		return array(
			'desired_count'  => count( $classification['desired'] ),
			'eligible_count' => count( $classification['eligible'] ),
			'conflict_count' => count( $classification['conflicts'] ),
			'conflicts'      => $classification['conflicts'],
		);
	}

	private static function assert_apply_confirmation( string $target, string $manifest_sha ): void {
		self::assert_same(
			$manifest_sha,
			self::required_sha256( 'AKB_SERIES_CONTENT_CONFIRM_SHA256' ),
			'apply_confirmation_mismatch'
		);
		if ( 'production' === $target && ! self::enabled( 'AKB_SERIES_CONTENT_ALLOW_PRODUCTION' ) ) {
			self::fail( 'production_not_allowed', 'Production apply is not confirmed.' );
		}
	}

	private static function required_target(): string {
		$target = self::required_environment( 'AKB_SERIES_CONTENT_TARGET' );
		if ( ! in_array( $target, array( 'local', 'production' ), true ) ) {
			self::fail( 'target_invalid', 'Target must be local or production.' );
		}
		return $target;
	}

	private static function required_environment( string $name ): string {
		$value = self::optional_environment( $name );
		if ( '' === $value ) {
			self::fail( 'environment_missing', 'Missing required environment value: ' . $name );
		}
		return $value;
	}

	private static function optional_environment( string $name ): string {
		$value = getenv( $name );
		return false === $value ? '' : trim( $value );
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

	/** @param mixed $expected @param mixed $actual @param mixed $details */
	private static function assert_same( $expected, $actual, string $code, $details = null ): void {
		if ( $expected !== $actual ) {
			self::fail( $code, 'Expected and observed values differ.', compact( 'expected', 'actual', 'details' ) );
		}
	}

	/** @param mixed $details */
	private static function fail( string $code, string $message, $details = null ): void {
		throw new RuntimeException(
			wp_json_encode(
				array( 'code' => $code, 'message' => $message, 'details' => $details ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}
}

try {
	$result = Akibara_Series_Content_Truth_Manifest::run();
	echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
} catch ( Throwable $error ) {
	fwrite(
		STDERR,
		wp_json_encode(
			array( 'status' => 'FAIL', 'failure' => json_decode( $error->getMessage(), true ) ?: $error->getMessage() ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n"
	);
	exit( 1 );
}
