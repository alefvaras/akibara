<?php
/**
 * Atomic wp_options persistence for authoritative PUDO mutations.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Integrations\Bluex\Pudo;

defined( 'ABSPATH' ) || exit;

/**
 * Uses direct SQL so persistent object caches and Woo session shutdown writes
 * cannot make an obsolete PUDO request authoritative.
 *
 * Each value starts with a fixed-width expiry timestamp. This permits a
 * bounded, atomic expiry DELETE without relying on MySQL JSON functions.
 *
 * @since 1.96.0
 */
final class WpOptionsPudoMutationRepository implements PudoMutationRepository {

	private const EXPIRY_WIDTH = 12;

	/**
	 * Atomically inserts only when the HMAC option is absent.
	 *
	 * @param string              $key    HMAC-derived option key.
	 * @param array<string,mixed> $record Authoritative record.
	 * @return bool Whether the row was inserted.
	 */
	public function create_if_absent( string $key, array $record ): bool {
		global $wpdb;

		$this->assert_key( $key );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload )
				VALUES ( %s, %s, 'off' )",
				$key,
				$this->encode( $record )
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Unable to create authoritative PUDO state.' );
		}
		if ( 1 === (int) $result ) {
			$this->purge_option_cache( $key );
			return true;
		}

		return false;
	}

	/**
	 * Performs an exact compare-and-swap on the durable value.
	 *
	 * @param string              $key         HMAC-derived option key.
	 * @param array<string,mixed> $expected    Exact expected record.
	 * @param array<string,mixed> $replacement Replacement record.
	 * @return bool Whether the compare-and-swap succeeded.
	 */
	public function compare_and_swap( string $key, array $expected, array $replacement ): bool {
		global $wpdb;

		$this->assert_key( $key );
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				SET option_value = %s, autoload = 'off'
				WHERE option_name = %s AND option_value = %s",
				$this->encode( $replacement ),
				$key,
				$this->encode( $expected )
			)
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'Unable to commit authoritative PUDO state.' );
		}
		if ( 1 === (int) $result ) {
			$this->purge_option_cache( $key );
			return true;
		}

		return false;
	}

	/**
	 * Reads directly from wp_options, deliberately bypassing the options cache.
	 *
	 * @param string $key HMAC-derived option key.
	 * @return array<string,mixed>|null
	 */
	public function get( string $key ): ?array {
		global $wpdb;

		$this->assert_key( $key );
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$key
			)
		);

		if ( ! is_string( $value ) ) {
			return null;
		}

		$record = $this->decode( $value );
		if ( null === $record ) {
			$this->delete_malformed_if_unchanged( $key, $value );
		}

		return $record;
	}

	/**
	 * Deletes only rows whose current encoded expiry is due.
	 *
	 * The predicate and DELETE are one SQL statement, so a concurrently renewed
	 * row cannot be deleted from a stale pre-read.
	 *
	 * @param int $now   Current Unix timestamp.
	 * @param int $limit Maximum rows to delete.
	 * @return int Deleted row count.
	 */
	public function cleanup_expired( int $now, int $limit ): int {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$like  = $wpdb->esc_like( PudoMutationRepository::OPTION_PREFIX ) . '%';
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s
					AND SUBSTRING(option_value, %d, 1) = '|'
					AND CAST(LEFT(option_value, %d) AS UNSIGNED) <= %d
				LIMIT %d",
				$like,
				self::EXPIRY_WIDTH + 1,
				self::EXPIRY_WIDTH,
				$now,
				$limit
			)
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'Unable to clean expired PUDO state.' );
		}

		return (int) $result;
	}

	/**
	 * Encodes a deterministic record with a queryable expiry prefix.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return string
	 */
	private function encode( array $record ): string {
		$expires_at = $record['expires_at'] ?? null;
		if ( ! is_int( $expires_at ) || 0 >= $expires_at || 10 ** self::EXPIRY_WIDTH <= $expires_at ) {
			throw new \InvalidArgumentException( 'Authoritative PUDO state requires a valid expiry.' );
		}

		$json = json_encode(
			$record,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);
		if ( false === $json ) {
			throw new \RuntimeException( 'Unable to encode authoritative PUDO state.' );
		}

		return str_pad( (string) $expires_at, self::EXPIRY_WIDTH, '0', STR_PAD_LEFT ) . '|' . $json;
	}

	/**
	 * Decodes a record and rejects malformed or mismatched expiry envelopes.
	 *
	 * @param string $value Encoded value.
	 * @return array<string,mixed>|null
	 */
	private function decode( string $value ): ?array {
		if (
			self::EXPIRY_WIDTH + 2 > strlen( $value )
			|| '|' !== $value[ self::EXPIRY_WIDTH ]
			|| 1 !== preg_match( '/\A[0-9]{' . self::EXPIRY_WIDTH . '}\z/', substr( $value, 0, self::EXPIRY_WIDTH ) )
		) {
			return null;
		}

		$record = json_decode( substr( $value, self::EXPIRY_WIDTH + 1 ), true );
		if (
			! is_array( $record )
			|| ! is_int( $record['expires_at'] ?? null )
			|| (int) substr( $value, 0, self::EXPIRY_WIDTH ) !== $record['expires_at']
		) {
			return null;
		}

		return $record;
	}

	/**
	 * Self-heals only the exact malformed value read by this request.
	 *
	 * A concurrent valid renewal changes option_value, so the exact predicate
	 * cannot delete the newer generation.
	 *
	 * @param string $key       HMAC-derived option key.
	 * @param string $raw_value Exact malformed value read from storage.
	 */
	private function delete_malformed_if_unchanged( string $key, string $raw_value ): void {
		global $wpdb;

		$this->assert_key( $key );
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name = %s AND option_value = %s",
				$key,
				$raw_value
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Unable to repair malformed PUDO state.' );
		}
		if ( 1 === (int) $result ) {
			$this->purge_option_cache( $key );
		}
	}

	/**
	 * Validates that callers cannot address arbitrary options.
	 *
	 * @param string $key Option key.
	 */
	private function assert_key( string $key ): void {
		if ( 1 !== preg_match( '/\A' . PudoMutationRepository::OPTION_PREFIX . '[a-f0-9]{64}\z/', $key ) ) {
			throw new \InvalidArgumentException( 'Invalid authoritative PUDO option key.' );
		}
	}

	/**
	 * Removes any stale Options API entry after a direct SQL write.
	 *
	 * @param string $key Option key.
	 */
	private function purge_option_cache( string $key ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( $key, 'options' );

		// Retirar sólo la clave modificada de notoptions, igual que add_option().
		// Borrar el índice completo obligaría a releer opciones ausentes en el camino
		// de checkout.

		$ausentes = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $ausentes ) && isset( $ausentes[ $key ] ) ) {
			unset( $ausentes[ $key ] );
			wp_cache_set( 'notoptions', $ausentes, 'options' );
		}
	}
}
