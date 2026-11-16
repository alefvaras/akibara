<?php
/**
 * Real-MariaDB PUDO data-integrity and Woo shipping-cache gate.
 *
 * This file is mounted read-only and executed with
 * `wp eval-file <file> --use-include` against the exact candidate runtime.
 * `--use-include` is mandatory: WP-CLI's default eval mode prepends `?>`,
 * which makes the strict_types declaration below fatal. It creates only
 * random HMAC-shaped test options and removes them in a finally block.
 *
 * @package Akibara\Operations
 */

declare( strict_types = 1 );

use Akibara\Business\Integrations\Bluex\Pudo\PickupPointProvider;
use Akibara\Business\Integrations\Bluex\Pudo\PudoCheckoutService;
use Akibara\Business\Integrations\Bluex\Pudo\PudoMutationGuard;
use Akibara\Business\Integrations\Bluex\Pudo\PudoSelectionStore;
use Akibara\Business\Integrations\Bluex\Pudo\WpOptionsPudoMutationRepository;

defined( 'ABSPATH' ) || exit( 1 );

global $wpdb;

$fail = static function ( string $message ): void {
	throw new RuntimeException( $message );
};

foreach (
	array(
		PickupPointProvider::class,
		PudoCheckoutService::class,
		PudoMutationGuard::class,
		PudoSelectionStore::class,
		WpOptionsPudoMutationRepository::class,
		'WC_Cache_Helper',
	) as $required_class
) {
	if ( ! class_exists( $required_class ) ) {
		$fail( 'Required PUDO/WooCommerce candidate class is unavailable: ' . $required_class );
	}
}

$prefix = 'akb_pudo_state_';
$keys   = array(
	$self_heal_key = $prefix . hash( 'sha256', 'self-heal|' . bin2hex( random_bytes( 32 ) ) ),
	$renewal_key   = $prefix . hash( 'sha256', 'renewal|' . bin2hex( random_bytes( 32 ) ) ),
	$package_key   = $prefix . hash( 'sha256', 'package|' . bin2hex( random_bytes( 32 ) ) ),
);

$insert_raw = static function ( string $key, string $raw ) use ( $wpdb, $fail ): void {
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload )
			VALUES ( %s, %s, 'off' )",
			$key,
			$raw
		)
	);
	if ( 1 !== (int) $result ) {
		$fail( 'Unable to insert an isolated PUDO gate row.' );
	}
};

$read_raw = static function ( string $key ) use ( $wpdb ) {
	return $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options}
			WHERE option_name = %s LIMIT 1",
			$key
		)
	);
};

$encode = static function ( array $record ) use ( $fail ): string {
	$json = wp_json_encode(
		$record,
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
	);
	if ( ! is_string( $json ) ) {
		$fail( 'Unable to encode a PUDO gate record.' );
	}

	return str_pad( (string) $record['expires_at'], 12, '0', STR_PAD_LEFT ) . '|' . $json;
};

$woo_package_hash = static function ( array $package ) use ( $fail ): string {
	$package_to_hash = $package;
	foreach ( (array) ( $package_to_hash['contents'] ?? array() ) as $item_id => $item ) {
		if ( is_array( $item ) ) {
			unset( $package_to_hash['contents'][ $item_id ]['data'] );
		}
	}

	$json = wp_json_encode( $package_to_hash );
	if ( ! is_string( $json ) ) {
		$fail( 'Unable to encode the WooCommerce package hash fixture.' );
	}

	return 'wc_ship_' . md5( $json . WC_Cache_Helper::get_transient_version( 'shipping' ) );
};

$repository = new WpOptionsPudoMutationRepository();
$result     = array();

try {
	$malformed = 'malformed-without-expiry-delimiter';
	$insert_raw( $self_heal_key, $malformed );
	if ( null !== $repository->get( $self_heal_key ) || null !== $read_raw( $self_heal_key ) ) {
		$fail( 'An exact malformed PUDO row was not self-healed.' );
	}

	$healed_record = array(
		'phase'      => 'pending',
		'expires_at' => time() + 600,
	);
	if (
		! $repository->create_if_absent( $self_heal_key, $healed_record )
		|| $healed_record !== $repository->get( $self_heal_key )
	) {
		$fail( 'A new PUDO generation was not created after exact self-heal.' );
	}
	$autoload = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT autoload FROM {$wpdb->options}
			WHERE option_name = %s LIMIT 1",
			$self_heal_key
		)
	);
	if ( 'off' !== $autoload ) {
		$fail( 'The self-healed PUDO generation is not non-autoload.' );
	}

	$stale_raw = 'stale-malformed-value';
	$insert_raw( $renewal_key, $stale_raw );
	if ( $stale_raw !== $read_raw( $renewal_key ) ) {
		$fail( 'Unable to read the exact stale malformed PUDO row.' );
	}

	$renewed_record = array(
		'phase'      => 'cleared',
		'expires_at' => time() + 900,
	);
	$renewed_raw    = $encode( $renewed_record );
	$renewed        = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options}
			SET option_value = %s, autoload = 'off'
			WHERE option_name = %s AND option_value = %s",
			$renewed_raw,
			$renewal_key,
			$stale_raw
		)
	);
	if ( 1 !== (int) $renewed ) {
		$fail( 'Unable to renew the PUDO row between read and repair.' );
	}

	$repair = new ReflectionMethod(
		WpOptionsPudoMutationRepository::class,
		'delete_malformed_if_unchanged'
	);
	$repair->invoke( $repository, $renewal_key, $stale_raw );
	if (
		$renewed_raw !== $read_raw( $renewal_key )
		|| $renewed_record !== $repository->get( $renewal_key )
	) {
		$fail( 'A stale malformed repair deleted or changed the renewed PUDO row.' );
	}

	$session_id = 'akb-pudo-package-gate-' . bin2hex( random_bytes( 16 ) );
	$hmac_key   = str_repeat( 'k', 64 );
	$derived_package_key = $prefix . hash_hmac( 'sha256', $session_id, $hmac_key );
	if ( $package_key !== $derived_package_key ) {
		$package_key = $derived_package_key;
		$keys[2]     = $package_key;
	}

	$session = new class( $session_id ) {
		private string $customer_id;

		/** @var array<string,mixed> */
		private array $values = array();

		public function __construct( string $customer_id ) {
			$this->customer_id = $customer_id;
		}

		public function get_customer_id(): string {
			return $this->customer_id;
		}

		/**
		 * @param mixed $default Default value.
		 * @return mixed
		 */
		public function get( string $key, $default = null ) {
			return $this->values[ $key ] ?? $default;
		}

		/**
		 * @param mixed $value Session value.
		 */
		public function set( string $key, $value ): void {
			$this->values[ $key ] = $value;
		}

		public function __unset( string $key ): void {
			unset( $this->values[ $key ] );
		}
	};

	$now      = time();
	$packages = array(
		array(
			'contents'      => array(
				'gate-line' => array(
					'product_id'   => 27136,
					'variation_id' => 0,
					'quantity'     => 1,
					'line_total'   => 55000.0,
				),
			),
			'contents_cost' => 55000.0,
			'destination'   => array(
				'country'  => 'CL',
				'state'    => 'CL-RM',
				'city'     => 'Santiago',
				'postcode' => '8320000',
			),
		),
	);
	$store       = new PudoSelectionStore();
	$token_index = 0;
	$guard       = new PudoMutationGuard(
		$repository,
		static fn( object $candidate_session ): string => $candidate_session->get_customer_id(),
		static function () use ( &$token_index ): string {
			++$token_index;
			return str_pad( dechex( $token_index ), 64, '0', STR_PAD_LEFT );
		},
		$hmac_key
	);
	$service = new PudoCheckoutService(
		new PickupPointProvider(),
		$store,
		$guard,
		static fn(): int => $now
	);
	$business_fingerprint = $service->cart_fingerprint( $packages );

	$selection_a = $store->prepare(
		array(
			'agency_id'        => 'GATE-A',
			'agency_name'      => 'Gate A',
			'agency_address'   => 'Santiago',
			'region_code'      => 'CL-RM',
			'district_code'    => 'SANTIAGO',
			'city'             => 'Santiago',
			'shipping_address' => null,
			'quote'            => array(
				'total'       => 0.0,
				'is_free'     => true,
				'promise_day' => 1,
			),
		),
		$business_fingerprint,
		$now
	);
	$selection_b = $selection_a;
	$selection_b['agency_id']   = 'GATE-B';
	$selection_b['agency_name'] = 'Gate B';

	$base_record = array(
		'schema_version'   => 1,
		'phase'            => 'committed',
		'version_token'    => str_repeat( 'a', 64 ),
		'attempt_hash'     => hash( 'sha256', 'attempt-a' ),
		'cart_fingerprint' => $business_fingerprint,
		'selection'        => $selection_a,
		'issued_at'        => $now,
		'expires_at'       => $selection_a['expires_at'],
		'updated_at'       => $now,
	);
	if ( ! $repository->create_if_absent( $package_key, $base_record ) ) {
		$fail( 'Unable to create package generation A.' );
	}
	$packages_a = $service->prepare_shipping_packages( $packages, $session, $now + 1 );
	$hash_a     = $woo_package_hash( $packages_a[0] );

	$record_b                    = $base_record;
	$record_b['version_token']   = str_repeat( 'b', 64 );
	$record_b['attempt_hash']    = hash( 'sha256', 'attempt-b' );
	$record_b['selection']       = $selection_b;
	$record_b['updated_at']      = $now + 2;
	if ( ! $repository->compare_and_swap( $package_key, $base_record, $record_b ) ) {
		$fail( 'Unable to commit package generation B.' );
	}
	$packages_b = $service->prepare_shipping_packages( $packages, $session, $now + 3 );
	$hash_b     = $woo_package_hash( $packages_b[0] );

	$record_clear = array(
		'schema_version'   => 1,
		'phase'            => 'cleared',
		'version_token'    => str_repeat( 'c', 64 ),
		'attempt_hash'     => hash( 'sha256', 'attempt-clear' ),
		'cart_fingerprint' => '',
		'selection'        => null,
		'issued_at'        => $now + 4,
		'expires_at'       => $now + 904,
		'updated_at'       => $now + 4,
	);
	if ( ! $repository->compare_and_swap( $package_key, $record_b, $record_clear ) ) {
		$fail( 'Unable to commit the package clear tombstone.' );
	}
	$packages_clear = $service->prepare_shipping_packages( $packages, $session, $now + 5 );
	$hash_clear     = $woo_package_hash( $packages_clear[0] );

	$generations = array(
		$packages_a[0]['_akb_pudo_generation'] ?? null,
		$packages_b[0]['_akb_pudo_generation'] ?? null,
		$packages_clear[0]['_akb_pudo_generation'] ?? null,
	);
	foreach ( $generations as $generation ) {
		if ( ! is_string( $generation ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $generation ) ) {
			$fail( 'A candidate PUDO shipping generation is invalid.' );
		}
	}
	if (
		3 !== count( array_unique( $generations ) )
		|| 3 !== count( array_unique( array( $hash_a, $hash_b, $hash_clear ) ) )
	) {
		$fail( 'Generation A, generation B and clear did not invalidate the Woo package hash.' );
	}

	foreach ( array( $packages_a, $packages_b, $packages_clear ) as $prepared_packages ) {
		if ( $business_fingerprint !== $service->cart_fingerprint( $prepared_packages ) ) {
			$fail( 'PUDO cache authority metadata changed the Business cart fingerprint.' );
		}
	}

	$result = array(
		'schema_version'               => 1,
		'gate'                         => 'akibara-business-blocks-pudo-docker',
		'status'                       => 'PASS',
		'exact_malformed_self_heal'    => true,
		'renewed_row_preserved'        => true,
		'new_state_created'            => true,
		'package_hash_a'               => $hash_a,
		'package_hash_b'               => $hash_b,
		'package_hash_clear'           => $hash_clear,
		'business_fingerprint_stable'  => true,
	);
} finally {
	foreach ( array_unique( $keys ) as $key ) {
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s",
				$key
			)
		);
		if ( false === $deleted ) {
			throw new RuntimeException( 'Unable to remove an isolated PUDO gate row.' );
		}
		wp_cache_delete( $key, 'options' );
	}
	wp_cache_delete( 'notoptions', 'options' );
}

$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
$remaining    = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->options}
		WHERE option_name IN ({$placeholders})",
		...$keys
	)
);
if ( 0 !== (int) $remaining ) {
	$fail( 'PUDO Docker gate rows remain after cleanup.' );
}
$result['test_rows_remaining'] = 0;

$encoded_result = wp_json_encode(
	$result,
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
if ( ! is_string( $encoded_result ) ) {
	$fail( 'Unable to encode the PUDO Docker gate result.' );
}

echo $encoded_result . "\n";
