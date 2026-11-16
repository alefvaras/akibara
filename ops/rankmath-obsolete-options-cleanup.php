<?php
/**
 * Exact, fail-closed cleanup for four completed Rank Math internal states.
 *
 * This payload is streamed to `wp eval-file -`; it must never be copied to a
 * remote filesystem.
 */

defined( 'ABSPATH' ) || exit( 1 );

/**
 * Removes only the reviewed Rank Math completion-state options.
 */
final class Akibara_Rank_Math_Obsolete_Options_Cleanup {
	private const RANK_MATH_VERSION = '1.0.274.1';

	/**
	 * Execute the audit or exact transaction.
	 *
	 * @return array<string,mixed>
	 */
	public static function run(): array {
		global $wpdb;

		$started_at       = gmdate( 'c' );
		$apply            = self::required_boolean_environment( 'AKB_APPLY' );
		$expected_site    = self::required_environment( 'AKB_EXPECTED_SITE_URL' );
		$target           = self::required_environment( 'AKB_TARGET_ENVIRONMENT' );
		$promotion_target = self::required_environment( 'AKB_PROMOTION_TARGET' );
		$promotion_run    = self::required_promotion_run();
		$release_set      = self::required_environment( 'AKB_RELEASE_SET' );
		$release_digest   = self::required_sha256_environment( 'AKB_RELEASE_DIGEST' );
		$payload_sha256   = self::required_sha256_environment( 'AKB_PAYLOAD_SHA256' );
		$mutation_id      = self::required_mutation_id();
		$commercial_before = $apply
			? self::required_sha256_environment( 'AKB_COMMERCIAL_BEFORE_SHA256' )
			: null;
		$options_table    = self::options_table();
		$actions_table    = self::actions_table();
		$expected_options = self::expected_options();
		$observed_site    = untrailingslashit( home_url( '/' ) );
		$rank_math_version = self::rank_math_version();

		self::assert_same( untrailingslashit( $expected_site ), $observed_site, 'site_url_mismatch' );
		self::assert_same( self::RANK_MATH_VERSION, $rank_math_version, 'rank_math_version_mismatch' );

		$references_before = self::scheduled_references(
			$options_table,
			$actions_table,
			array_keys( $expected_options )
		);
		self::assert_no_scheduled_references( $references_before );

		$before_rows  = self::read_options( $options_table, array_keys( $expected_options ), false );
		$before_state = self::classify_options( $before_rows, $expected_options );
		$deleted      = 0;

		if ( $apply && 'eligible_cleanup' === $before_state['state'] ) {
			$deleted = self::delete_exact_set_transaction(
				$options_table,
				$expected_options
			);
		}

		$after_rows  = self::read_options( $options_table, array_keys( $expected_options ), false );
		$after_state = self::classify_options( $after_rows, $expected_options );

		if ( $apply ) {
			self::assert_same( 'already_clean', $after_state['state'], 'post_cleanup_state_not_clean' );
			self::assert_same(
				'eligible_cleanup' === $before_state['state'] ? 4 : 0,
				$deleted,
				'unexpected_deleted_count'
			);
		} else {
			self::assert_same( 0, $deleted, 'audit_attempted_mutation' );
		}

		$references_after = self::scheduled_references(
			$options_table,
			$actions_table,
			array_keys( $expected_options )
		);
		self::assert_no_scheduled_references( $references_after );

		return array(
			'schema_version'                           => 2,
			'gate'                                     => 'rankmath_obsolete_options_cleanup',
			'status'                                   => 'PASS',
			'mode'                                     => $apply ? 'apply' : 'audit',
			'target_environment'                       => $target,
			'promotion_target'                         => $promotion_target,
			'promotion_run'                            => $promotion_run,
			'release_set'                              => $release_set,
			'release_digest'                           => $release_digest,
			'wp_environment_type'                      => wp_get_environment_type(),
			'site_url'                                 => $observed_site,
			'rank_math_version'                        => $rank_math_version,
			'payload_sha256'                           => $payload_sha256,
			'mutation_id'                              => $mutation_id,
			'external_commercial_fingerprint_required' => $apply,
			'commercial_before_sha256'                 => $commercial_before,
			'seal_status'                              => $apply
				? 'MUTATION_COMPLETE_SEAL_PENDING'
				: 'NOT_APPLICABLE_READ_ONLY',
			'exact_option_names'                       => array_keys( $expected_options ),
			'before'                                   => array(
				'state'   => $before_state['state'],
				'options' => self::public_option_rows( $before_rows ),
			),
			'deleted_count'                            => $deleted,
			'after'                                    => array(
				'state'   => $after_state['state'],
				'options' => self::public_option_rows( $after_rows ),
			),
			'scheduled_references_before'              => $references_before,
			'scheduled_references_after'               => $references_after,
			'started_at_utc'                           => $started_at,
			'completed_at_utc'                         => gmdate( 'c' ),
		);
	}

	/**
	 * Return the immutable reviewed option manifest.
	 *
	 * @return array<string,array{bytes:int,sha256:string,autoload:string}>
	 */
	private static function expected_options(): array {
		$expected = array(
			'rank_math_csv_import_redirections_status' => array(
				'bytes'    => 699,
				'sha256'   => '9aadea42c740918fa5e7db34a0685b45360c11cdb49f8d76c2268419b9a73726',
				'autoload' => 'auto',
			),
			'rank_math_gtin_products'                   => array(
				'bytes'    => 6,
				'sha256'   => '35786c7117b4e38d0f169239752ce71158266ae2f6e4aa230fbbb87bd699c0e3',
				'autoload' => 'yes',
			),
			'rank_math_regenerate_state'                => array(
				'bytes'    => 174,
				'sha256'   => '6f55c2a060faa953526cdccfec64d897c509a6b633d893ea1c00064820603707',
				'autoload' => 'off',
			),
			'rank_math_video_posts'                     => array(
				'bytes'    => 20051,
				'sha256'   => '5fa17c3cfac9c3432b1352194aa34fab074b2357d0a94a32451bd05afe77e101',
				'autoload' => 'off',
			),
		);

		if ( '1' !== getenv( 'AKB_ENABLE_LOCAL_TEST_SEAMS' ) ) {
			return $expected;
		}

		self::assert_local_test_environment();
		$override = getenv( 'AKB_LOCAL_TEST_EXPECTED_OPTIONS_JSON' );
		if ( false === $override || '' === $override ) {
			self::fail( 'missing_test_manifest', 'The enabled local test seam requires an isolated manifest.' );
		}

		$decoded = json_decode( $override, true );
		if ( ! is_array( $decoded ) || array_keys( $decoded ) !== array_keys( $expected ) ) {
			self::fail( 'invalid_test_manifest', 'The local test manifest changed the exact option-name set.' );
		}

		foreach ( $decoded as $name => $spec ) {
			if (
				! is_array( $spec )
				|| ! isset( $spec['bytes'], $spec['sha256'], $spec['autoload'] )
				|| ! is_int( $spec['bytes'] )
				|| 0 > $spec['bytes']
				|| ! is_string( $spec['sha256'] )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $spec['sha256'] )
				|| ! is_string( $spec['autoload'] )
			) {
				self::fail( 'invalid_test_manifest', 'The local test manifest is malformed.' );
			}
		}

		/** @var array<string,array{bytes:int,sha256:string,autoload:string}> $decoded */
		return $decoded;
	}

	/**
	 * Resolve the options table, allowing only an isolated local test fixture.
	 */
	private static function options_table(): string {
		global $wpdb;

		$test_mode = '1' === getenv( 'AKB_ENABLE_LOCAL_TEST_SEAMS' );
		$override  = getenv( 'AKB_LOCAL_TEST_OPTIONS_TABLE' );
		if ( ! $test_mode && ( false === $override || '' === $override ) ) {
			return $wpdb->options;
		}

		self::assert_local_test_environment();
		if ( false === $override || '' === $override ) {
			self::fail( 'missing_test_table', 'The enabled local test seam requires an isolated options table.' );
		}
		$pattern = '/^' . preg_quote( $wpdb->prefix, '/' ) . 'akb_rank_math_options_fixture_[a-f0-9]{8}$/D';
		if ( 1 !== preg_match( $pattern, $override ) ) {
			self::fail( 'unsafe_test_table', 'The local options fixture table is not allowlisted.' );
		}

		return $override;
	}

	/**
	 * Resolve the Action Scheduler table, allowing only an isolated local fixture.
	 */
	private static function actions_table(): string {
		global $wpdb;

		$test_mode = '1' === getenv( 'AKB_ENABLE_LOCAL_TEST_SEAMS' );
		$override  = getenv( 'AKB_LOCAL_TEST_ACTIONS_TABLE' );
		if ( ! $test_mode && ( false === $override || '' === $override ) ) {
			return $wpdb->prefix . 'actionscheduler_actions';
		}

		self::assert_local_test_environment();
		if ( false === $override || '' === $override ) {
			self::fail( 'missing_test_table', 'The enabled local test seam requires an isolated actions table.' );
		}
		$pattern = '/^' . preg_quote( $wpdb->prefix, '/' ) . 'akb_rank_math_actions_fixture_[a-f0-9]{8}$/D';
		if ( 1 !== preg_match( $pattern, $override ) ) {
			self::fail( 'unsafe_test_table', 'The local actions fixture table is not allowlisted.' );
		}

		return $override;
	}

	/**
	 * Read only the exact reviewed option names.
	 *
	 * @param string        $table        Options table.
	 * @param array<string> $option_names Exact option names.
	 * @param bool          $for_update   Whether to lock the matching rows.
	 * @return array<string,array{option_name:string,option_value:string,autoload:string}>
	 */
	private static function read_options( string $table, array $option_names, bool $for_update ): array {
		global $wpdb;

		$quoted_table = self::quote_identifier( $table );
		$placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );
		$sql          = $wpdb->prepare(
			"SELECT option_name,option_value,autoload
			FROM {$quoted_table}
			WHERE option_name IN ({$placeholders})
			ORDER BY option_name" . ( $for_update ? ' FOR UPDATE' : '' ),
			...$option_names
		);
		$rows         = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			self::fail( 'option_read_failed', 'Could not read the exact option set.' );
		}

		$result = array();
		foreach ( $rows as $row ) {
			$name = (string) ( $row['option_name'] ?? '' );
			if ( isset( $result[ $name ] ) ) {
				self::fail( 'duplicate_option_name', 'The exact option set is not unique.' );
			}
			$result[ $name ] = array(
				'option_name'  => $name,
				'option_value' => (string) ( $row['option_value'] ?? '' ),
				'autoload'     => (string) ( $row['autoload'] ?? '' ),
			);
		}

		return $result;
	}

	/**
	 * Classify the exact set or abort on partial/fingerprint drift.
	 *
	 * @param array<string,array{option_name:string,option_value:string,autoload:string}> $rows Observed rows.
	 * @param array<string,array{bytes:int,sha256:string,autoload:string}>                  $expected Expected manifest.
	 * @return array{state:string}
	 */
	private static function classify_options( array $rows, array $expected ): array {
		if ( array() === $rows ) {
			return array( 'state' => 'already_clean' );
		}

		if ( count( $expected ) !== count( $rows ) || array_keys( $expected ) !== array_keys( $rows ) ) {
			self::fail( 'partial_option_set', 'The four reviewed options are not all present or all absent.' );
		}

		foreach ( $expected as $name => $spec ) {
			$row = $rows[ $name ];
			if (
				$spec['bytes'] !== strlen( $row['option_value'] )
				|| ! hash_equals( $spec['sha256'], hash( 'sha256', $row['option_value'] ) )
				|| $spec['autoload'] !== $row['autoload']
			) {
				self::fail( 'option_fingerprint_drift', 'A reviewed option differs from its exact fingerprint.' );
			}
		}

		return array( 'state' => 'eligible_cleanup' );
	}

	/**
	 * Delete the exact locked set atomically.
	 *
	 * @param string                                                               $table    Options table.
	 * @param array<string,array{bytes:int,sha256:string,autoload:string}>          $expected Expected manifest.
	 */
	private static function delete_exact_set_transaction( string $table, array $expected ): int {
		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			self::fail( 'transaction_start_failed', 'Could not start the exact cleanup transaction.' );
		}

		$committed = false;
		try {
			$locked = self::read_options( $table, array_keys( $expected ), true );
			self::classify_options( $locked, $expected );

			$quoted_table = self::quote_identifier( $table );
			$names        = array_keys( $expected );
			$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
			$deleted      = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$quoted_table} WHERE option_name IN ({$placeholders})",
					...$names
				)
			);
			if ( count( $expected ) !== $deleted ) {
				self::fail( 'transaction_delete_mismatch', 'The transaction did not delete exactly four rows.' );
			}

			$remaining = self::read_options( $table, $names, false );
			if ( array() !== $remaining ) {
				self::fail( 'transaction_postcondition_failed', 'A reviewed option remained inside the transaction.' );
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				self::fail( 'transaction_commit_failed', 'Could not commit the exact cleanup transaction.' );
			}
			$committed = true;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}

		if ( $table === $wpdb->options ) {
			foreach ( array_keys( $expected ) as $name ) {
				wp_cache_delete( $name, 'options' );
			}
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		return count( $expected );
	}

	/**
	 * Count exact option-name references in WordPress cron and Action Scheduler.
	 *
	 * @param string        $options_table Options table.
	 * @param string        $actions_table Actions table.
	 * @param array<string> $option_names  Exact option names.
	 * @return array<string,mixed>
	 */
	private static function scheduled_references(
		string $options_table,
		string $actions_table,
		array $option_names
	): array {
		global $wpdb;

		$quoted_options = self::quote_identifier( $options_table );
		$cron_value     = $wpdb->get_var(
			"SELECT option_value FROM {$quoted_options} WHERE option_name = 'cron' LIMIT 1"
		);
		$cron_counts    = array_fill_keys( $option_names, 0 );
		if ( is_string( $cron_value ) ) {
			foreach ( $option_names as $name ) {
				$cron_counts[ $name ] = substr_count( $cron_value, $name );
			}
		}

		$action_counts = array_fill_keys( $option_names, 0 );
		$table_exists  = $actions_table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions_table ) )
		);
		$columns       = array();
		if ( $table_exists ) {
			$quoted_actions = self::quote_identifier( $actions_table );
			$all_columns    = $wpdb->get_col( "SHOW COLUMNS FROM {$quoted_actions}", 0 );
			if ( ! is_array( $all_columns ) ) {
				self::fail( 'actions_columns_failed', 'Could not inspect Action Scheduler columns.' );
			}
			$columns = array_values(
				array_intersect( array( 'hook', 'args', 'extended_args' ), $all_columns )
			);
			if ( array() === $columns ) {
				self::fail( 'actions_columns_missing', 'Action Scheduler has no inspectable payload columns.' );
			}

			foreach ( $option_names as $name ) {
				$conditions = array();
				$values     = array();
				foreach ( $columns as $column ) {
					$conditions[] = 'COALESCE(' . self::quote_identifier( $column ) . ", '') LIKE %s";
					$values[]     = '%' . $wpdb->esc_like( $name ) . '%';
				}
				$action_counts[ $name ] = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$quoted_actions} WHERE " . implode( ' OR ', $conditions ),
						...$values
					)
				);
			}
		}

		return array(
			'wordpress_cron' => array(
				'total'   => array_sum( $cron_counts ),
				'by_name' => $cron_counts,
			),
			'action_scheduler' => array(
				'table_present'    => $table_exists,
				'inspected_columns' => $columns,
				'total'            => array_sum( $action_counts ),
				'by_name'          => $action_counts,
			),
			'total' => array_sum( $cron_counts ) + array_sum( $action_counts ),
		);
	}

	/**
	 * Reject any scheduled reference.
	 *
	 * @param array<string,mixed> $references Reference inventory.
	 */
	private static function assert_no_scheduled_references( array $references ): void {
		if ( 0 !== (int) $references['total'] ) {
			self::fail( 'scheduled_reference_found', 'Cron or Action Scheduler still references a reviewed option.' );
		}
	}

	/**
	 * Return only non-sensitive row fingerprints for the report.
	 *
	 * @param array<string,array{option_name:string,option_value:string,autoload:string}> $rows Rows.
	 * @return array<int,array{name:string,bytes:int,sha256:string,autoload:string}>
	 */
	private static function public_option_rows( array $rows ): array {
		$public = array();
		foreach ( $rows as $row ) {
			$public[] = array(
				'name'     => $row['option_name'],
				'bytes'    => strlen( $row['option_value'] ),
				'sha256'   => hash( 'sha256', $row['option_value'] ),
				'autoload' => $row['autoload'],
			);
		}
		return $public;
	}

	/**
	 * Read the installed Rank Math version.
	 */
	private static function rank_math_version(): string {
		$plugin_file = WP_PLUGIN_DIR . '/seo-by-rank-math/rank-math.php';
		if ( ! is_readable( $plugin_file ) ) {
			self::fail( 'rank_math_missing', 'The expected Rank Math plugin file is unavailable.' );
		}

		$headers = get_file_data( $plugin_file, array( 'version' => 'Version' ) );
		$version = (string) ( $headers['version'] ?? '' );
		if ( '' === $version ) {
			self::fail( 'rank_math_version_unreadable', 'Could not read the Rank Math version.' );
		}
		return $version;
	}

	/**
	 * Quote an allowlisted SQL identifier.
	 */
	private static function quote_identifier( string $identifier ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $identifier ) ) {
			self::fail( 'unsafe_identifier', 'A database identifier is unsafe.' );
		}
		return '`' . $identifier . '`';
	}

	/**
	 * Require a nonempty environment value.
	 */
	private static function required_environment( string $name ): string {
		$value = getenv( $name );
		if ( false === $value || '' === $value ) {
			self::fail( 'missing_environment', 'A required execution contract value is absent.' );
		}
		return $value;
	}

	/**
	 * Require an exact boolean environment value.
	 */
	private static function required_boolean_environment( string $name ): bool {
		$value = self::required_environment( $name );
		if ( ! in_array( $value, array( '0', '1' ), true ) ) {
			self::fail( 'invalid_boolean_environment', 'An execution boolean is invalid.' );
		}
		return '1' === $value;
	}

	/**
	 * Require a SHA-256 environment value.
	 */
	private static function required_sha256_environment( string $name ): string {
		$value = self::required_environment( $name );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			self::fail( 'invalid_sha256_environment', 'An execution SHA-256 is invalid.' );
		}
		return $value;
	}

	/**
	 * Require a safe mutation identifier.
	 */
	private static function required_mutation_id(): string {
		$value = self::required_environment( 'AKB_MUTATION_ID' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{8,160}$/D', $value ) ) {
			self::fail( 'invalid_mutation_id', 'The mutation identifier is unsafe.' );
		}
		return $value;
	}

	/**
	 * Require the local promotion run identifier that owns this evidence.
	 */
	private static function required_promotion_run(): string {
		$value = self::required_environment( 'AKB_PROMOTION_RUN' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9TZ._-]{1,120}$/D', $value ) ) {
			self::fail( 'invalid_promotion_run', 'The promotion run identifier is unsafe.' );
		}
		return $value;
	}

	/**
	 * Verify a strict equality invariant.
	 *
	 * @param mixed $expected Expected value.
	 * @param mixed $observed Observed value.
	 */
	private static function assert_same( $expected, $observed, string $code ): void {
		if ( $expected !== $observed ) {
			self::fail( $code, 'An exact cleanup invariant failed.' );
		}
	}

	/**
	 * Restrict all test seams to Docker/local.
	 */
	private static function assert_local_test_environment(): void {
		if (
			'1' !== getenv( 'AKB_ENABLE_LOCAL_TEST_SEAMS' )
			|| 'local' !== wp_get_environment_type()
		) {
			self::fail( 'test_seam_rejected', 'A local-only test seam was requested outside Docker.' );
		}
	}

	/**
	 * Raise a machine-readable failure.
	 *
	 * @return never
	 */
	private static function fail( string $code, string $message ): never {
		throw new RuntimeException( $code . ': ' . $message );
	}
}

$akb_started_at = gmdate( 'c' );
try {
	$akb_report = Akibara_Rank_Math_Obsolete_Options_Cleanup::run();
	$akb_exit   = 0;
} catch ( Throwable $akb_error ) {
	$akb_message = $akb_error->getMessage();
	$akb_code    = str_contains( $akb_message, ':' )
		? strstr( $akb_message, ':', true )
		: 'unexpected_failure';
	$akb_report  = array(
		'schema_version'                           => 2,
		'gate'                                     => 'rankmath_obsolete_options_cleanup',
		'status'                                   => 'FAIL',
		'mode'                                     => '1' === getenv( 'AKB_APPLY' ) ? 'apply' : 'audit',
		'target_environment'                       => (string) getenv( 'AKB_TARGET_ENVIRONMENT' ),
		'promotion_target'                         => (string) getenv( 'AKB_PROMOTION_TARGET' ),
		'promotion_run'                            => (string) getenv( 'AKB_PROMOTION_RUN' ),
		'release_set'                              => (string) getenv( 'AKB_RELEASE_SET' ),
		'release_digest'                           => (string) getenv( 'AKB_RELEASE_DIGEST' ),
		'payload_sha256'                           => (string) getenv( 'AKB_PAYLOAD_SHA256' ),
		'mutation_id'                              => (string) getenv( 'AKB_MUTATION_ID' ),
		'external_commercial_fingerprint_required' => '1' === getenv( 'AKB_APPLY' ),
		'commercial_before_sha256'                 => '1' === getenv( 'AKB_APPLY' )
			? (string) getenv( 'AKB_COMMERCIAL_BEFORE_SHA256' )
			: null,
		'seal_status'                              => '1' === getenv( 'AKB_APPLY' )
			? 'MUTATION_FAILED_NOT_SEALED'
			: 'NOT_APPLICABLE_READ_ONLY',
		'error_code'                               => $akb_code,
		'started_at_utc'                           => $akb_started_at,
		'completed_at_utc'                         => gmdate( 'c' ),
	);
	$akb_exit = 1;
}

$akb_json = wp_json_encode(
	$akb_report,
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
if ( ! is_string( $akb_json ) ) {
	fwrite( STDERR, "Could not encode cleanup evidence.\n" );
	exit( 2 );
}

echo $akb_json . "\n";
exit( $akb_exit );
