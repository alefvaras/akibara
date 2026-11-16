<?php
/**
 * Fail-closed WooCommerce expired-session cleanup.
 *
 * This payload is streamed to `wp eval-file -`; it must never be copied to a
 * remote filesystem.
 */

defined( 'ABSPATH' ) || exit( 1 );

/**
 * Cleans only expired WooCommerce sessions through the official API.
 */
final class Akibara_WooCommerce_Expired_Sessions_Cleanup {
	private const ACTIVE_HORIZON_SECONDS = 300;

	/**
	 * Execute the read-only audit or two-pass cleanup.
	 *
	 * @return array<string,mixed>
	 */
	public static function run(): array {
		$started_at        = gmdate( 'c' );
		$apply             = self::required_boolean_environment( 'AKB_APPLY' );
		$expected_site     = self::required_environment( 'AKB_EXPECTED_SITE_URL' );
		$target            = self::required_environment( 'AKB_TARGET_ENVIRONMENT' );
		$promotion_target  = self::required_environment( 'AKB_PROMOTION_TARGET' );
		$promotion_run     = self::required_promotion_run();
		$release_set       = self::required_environment( 'AKB_RELEASE_SET' );
		$release_digest    = self::required_sha256_environment( 'AKB_RELEASE_DIGEST' );
		$payload_sha256    = self::required_sha256_environment( 'AKB_PAYLOAD_SHA256' );
		$mutation_id       = self::required_mutation_id();
		$commercial_before = $apply
			? self::required_sha256_environment( 'AKB_COMMERCIAL_BEFORE_SHA256' )
			: null;
		$observed_site     = untrailingslashit( home_url( '/' ) );
		$table             = self::sessions_table();
		$cutoff            = time();

		self::assert_same( untrailingslashit( $expected_site ), $observed_site, 'site_url_mismatch' );
		self::assert_table_contract( $table );
		self::assert_cleanup_api_available();

		$before           = self::snapshot( $table, $cutoff );
		$minimum_distance = null === $before['minimum_active_expiry']
			? null
			: $before['minimum_active_expiry'] - $cutoff;
		$eligible         = null === $minimum_distance
			|| self::ACTIVE_HORIZON_SECONDS <= $minimum_distance;
		$after_first      = null;
		$after_second     = null;
		$deleted_count    = 0;

		if ( $apply ) {
			if ( ! $eligible ) {
				self::fail(
					'active_session_inside_horizon',
					'An active session expires within five minutes; no cleanup was started.'
				);
			}

			self::cleanup_once( $table );
			$after_first = self::snapshot( $table, $cutoff );
			self::assert_same( 0, $after_first['expired'], 'expired_sessions_remain_after_first_pass' );
			self::assert_same(
				$before['active_fingerprint'],
				$after_first['active_fingerprint'],
				'active_sessions_changed_after_first_pass'
			);
			self::assert_same(
				$before['expired'],
				$before['total'] - $after_first['total'],
				'first_pass_deleted_unexpected_count'
			);

			self::cleanup_once( $table );
			$after_second = self::snapshot( $table, $cutoff );
			self::assert_same( 0, $after_second['expired'], 'expired_sessions_remain_after_second_pass' );
			self::assert_same(
				$after_first['all_fingerprint'],
				$after_second['all_fingerprint'],
				'second_pass_not_idempotent'
			);
			self::assert_same(
				$after_first['active_fingerprint'],
				$after_second['active_fingerprint'],
				'active_sessions_changed_after_second_pass'
			);
			$deleted_count = $before['expired'];
		}

		return array(
			'schema_version'                           => 2,
			'gate'                                     => 'woocommerce_expired_sessions_cleanup',
			'status'                                   => 'PASS',
			'mode'                                     => $apply ? 'apply' : 'audit',
			'target_environment'                       => $target,
			'promotion_target'                         => $promotion_target,
			'promotion_run'                            => $promotion_run,
			'release_set'                              => $release_set,
			'release_digest'                           => $release_digest,
			'wp_environment_type'                      => wp_get_environment_type(),
			'site_url'                                 => $observed_site,
			'woocommerce_version'                      => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'cleanup_api'                              => 'wc_cleanup_session_data',
			'active_horizon_seconds'                   => self::ACTIVE_HORIZON_SECONDS,
			'cutoff_utc'                               => gmdate( 'c', $cutoff ),
			'payload_sha256'                           => $payload_sha256,
			'mutation_id'                              => $mutation_id,
			'external_commercial_fingerprint_required' => $apply,
			'commercial_before_sha256'                 => $commercial_before,
			'seal_status'                              => $apply
				? 'MUTATION_COMPLETE_SEAL_PENDING'
				: 'NOT_APPLICABLE_READ_ONLY',
			'apply_precondition'                       => array(
				'eligible'                       => $eligible,
				'minimum_active_expiry_distance' => $minimum_distance,
			),
			'before'                                   => $before,
			'after_first'                              => $after_first,
			'after_second'                             => $after_second,
			'deleted_count'                            => $deleted_count,
			'started_at_utc'                           => $started_at,
			'completed_at_utc'                         => gmdate( 'c' ),
		);
	}

	/**
	 * Resolve the production table or a strictly local fixture table.
	 */
	private static function sessions_table(): string {
		global $wpdb;

		$test_mode = '1' === getenv( 'AKB_ENABLE_LOCAL_TEST_SEAMS' );
		$override  = getenv( 'AKB_LOCAL_TEST_SESSIONS_TABLE' );
		if ( ! $test_mode && ( false === $override || '' === $override ) ) {
			return $wpdb->prefix . 'woocommerce_sessions';
		}

		self::assert_local_test_environment();
		if ( false === $override || '' === $override ) {
			self::fail( 'missing_test_table', 'The enabled local test seam requires an isolated sessions table.' );
		}
		$pattern = '/^' . preg_quote( $wpdb->prefix, '/' ) . 'akb_wc_sessions_fixture_[a-f0-9]{8}$/D';
		if ( 1 !== preg_match( $pattern, $override ) ) {
			self::fail( 'unsafe_test_table', 'The local session fixture table is not allowlisted.' );
		}
		return $override;
	}

	/**
	 * Verify the session table shape required by this cleanup.
	 */
	private static function assert_table_contract( string $table ): void {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
		if ( $table !== $exists ) {
			self::fail( 'sessions_table_missing', 'The WooCommerce sessions table is unavailable.' );
		}

		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . self::quote_identifier( $table ), 0 );
		if (
			! is_array( $columns )
			|| array() !== array_diff(
				array( 'session_id', 'session_key', 'session_value', 'session_expiry' ),
				$columns
			)
		) {
			self::fail( 'sessions_table_contract_drift', 'The WooCommerce sessions table shape changed.' );
		}
	}

	/**
	 * Verify that the official WooCommerce cleanup API is loaded.
	 */
	private static function assert_cleanup_api_available(): void {
		if (
			'1' !== getenv( 'AKB_ENABLE_LOCAL_TEST_SEAMS' )
			&& ! function_exists( 'wc_cleanup_session_data' )
		) {
			self::fail( 'cleanup_api_missing', 'The official WooCommerce cleanup API is unavailable.' );
		}
	}

	/**
	 * Run exactly one cleanup pass.
	 *
	 * The SQL branch exists only for an isolated Docker fixture. Real
	 * environments always call WooCommerce's official API.
	 */
	private static function cleanup_once( string $table ): void {
		global $wpdb;

		if ( '1' === getenv( 'AKB_ENABLE_LOCAL_TEST_SEAMS' ) ) {
			self::assert_local_test_environment();
			$deleted = $wpdb->query(
				'DELETE FROM ' . self::quote_identifier( $table ) . ' WHERE session_expiry < UNIX_TIMESTAMP()'
			);
			if ( false === $deleted ) {
				self::fail( 'fixture_cleanup_failed', 'The local session fixture cleanup failed.' );
			}
			return;
		}

		wc_cleanup_session_data();
	}

	/**
	 * Capture counts and hashes without exposing session identifiers or values.
	 *
	 * @param string $table  Sessions table.
	 * @param int    $cutoff Fixed cutoff timestamp.
	 * @return array<string,mixed>
	 */
	private static function snapshot( string $table, int $cutoff ): array {
		global $wpdb;

		$quoted = self::quote_identifier( $table );
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					COALESCE(SUM(session_expiry < %d), 0) AS expired,
					COALESCE(SUM(session_expiry >= %d), 0) AS active,
					MIN(CASE WHEN session_expiry >= %d THEN session_expiry END) AS minimum_active_expiry
				FROM {$quoted}",
				$cutoff,
				$cutoff,
				$cutoff
			),
			ARRAY_A
		);
		if ( ! is_array( $counts ) ) {
			self::fail( 'session_count_failed', 'Could not count WooCommerce sessions.' );
		}

		return array(
			'total'                 => (int) $counts['total'],
			'expired'               => (int) $counts['expired'],
			'active'                => (int) $counts['active'],
			'minimum_active_expiry' => null === $counts['minimum_active_expiry']
				? null
				: (int) $counts['minimum_active_expiry'],
			'all_fingerprint'       => self::fingerprint_rows( $table, null, $cutoff ),
			'active_fingerprint'    => self::fingerprint_rows( $table, 'active', $cutoff ),
		);
	}

	/**
	 * Hash session rows in stable primary-key order.
	 *
	 * @param string      $table  Sessions table.
	 * @param string|null $scope  Null for all rows or active.
	 * @param int         $cutoff Fixed cutoff timestamp.
	 * @return array{count:int,sha256:string}
	 */
	private static function fingerprint_rows( string $table, ?string $scope, int $cutoff ): array {
		global $wpdb;

		$quoted     = self::quote_identifier( $table );
		$last_id    = 0;
		$count      = 0;
		$hash       = hash_init( 'sha256' );
		$scope_sql  = 'active' === $scope ? ' AND session_expiry >= %d' : '';

		do {
			$sql    = "SELECT session_id,session_key,session_value,session_expiry
				FROM {$quoted}
				WHERE session_id > %d{$scope_sql}
				ORDER BY session_id
				LIMIT 500";
			$values = 'active' === $scope
				? array( $last_id, $cutoff )
				: array( $last_id );
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );
			if ( ! is_array( $rows ) ) {
				self::fail( 'session_fingerprint_failed', 'Could not fingerprint WooCommerce sessions.' );
			}

			foreach ( $rows as $row ) {
				$encoded = wp_json_encode(
					$row,
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
				);
				if ( ! is_string( $encoded ) ) {
					self::fail( 'session_fingerprint_encoding_failed', 'Could not encode a session fingerprint.' );
				}
				hash_update( $hash, $encoded . "\n" );
				$last_id = (int) $row['session_id'];
				++$count;
			}
		} while ( 500 === count( $rows ) );

		return array(
			'count'  => $count,
			'sha256' => hash_final( $hash ),
		);
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
	 * Restrict test seams to Docker/local.
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
	$akb_report = Akibara_WooCommerce_Expired_Sessions_Cleanup::run();
	$akb_exit   = 0;
} catch ( Throwable $akb_error ) {
	$akb_message = $akb_error->getMessage();
	$akb_code    = str_contains( $akb_message, ':' )
		? strstr( $akb_message, ':', true )
		: 'unexpected_failure';
	$akb_report  = array(
		'schema_version'                           => 2,
		'gate'                                     => 'woocommerce_expired_sessions_cleanup',
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
