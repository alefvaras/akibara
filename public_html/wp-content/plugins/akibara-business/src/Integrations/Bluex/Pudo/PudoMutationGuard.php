<?php
/**
 * Authoritative concurrency guard for Blue Express PUDO selection.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Integrations\Bluex\Pudo;

defined( 'ABSPATH' ) || exit;

/**
 * Makes request completion order irrelevant using a server lease and exact CAS.
 *
 * @since 1.96.0
 */
final class PudoMutationGuard {

	private const SCHEMA_VERSION = 1;
	private const PENDING_TTL = 60;
	private const CHECKOUT_TTL = 30;
	private const TOMBSTONE_TTL = 900;
	private const CLEANUP_BATCH = 25;
	private const CAS_ATTEMPTS = 8;

	private PudoMutationRepository $repository;
	private \Closure $session_id_resolver;
	private \Closure $token_factory;
	private string $hmac_key;

	/**
	 * Creates the guard.
	 *
	 * @param PudoMutationRepository|null $repository         Atomic repository.
	 * @param callable|null               $session_id_resolver Returns the raw Woo session ID.
	 * @param callable|null               $token_factory       Returns a 64-char random hex token.
	 * @param string|null                 $hmac_key            Private HMAC key.
	 */
	public function __construct(
		?PudoMutationRepository $repository = null,
		?callable $session_id_resolver = null,
		?callable $token_factory = null,
		?string $hmac_key = null
	) {
		$this->repository = $repository ?? new WpOptionsPudoMutationRepository();
		$this->session_id_resolver = \Closure::fromCallable(
			$session_id_resolver ?? static function ( object $session ): string {
				if ( ! method_exists( $session, 'get_customer_id' ) ) {
					throw new \InvalidArgumentException( 'WooCommerce session identity is unavailable.' );
				}

				return (string) $session->get_customer_id();
			}
		);
		$this->token_factory = \Closure::fromCallable(
			$token_factory ?? static fn(): string => bin2hex( random_bytes( 32 ) )
		);
		$this->hmac_key = $hmac_key ?? $this->default_hmac_key();
		if ( 32 > strlen( $this->hmac_key ) ) {
			throw new \InvalidArgumentException( 'PUDO mutation HMAC key is too short.' );
		}
	}

	/**
	 * Supersedes every older operation before external BlueX I/O starts.
	 *
	 * @param object $session          WooCommerce session.
	 * @param string $attempt_id       Browser correlation ID.
	 * @param string $cart_fingerprint Exact server-side cart fingerprint.
	 * @param int    $now              Current timestamp.
	 * @return array{key:string,record:array<string,mixed>}
	 */
	public function begin_selection(
		object $session,
		string $attempt_id,
		string $cart_fingerprint,
		int $now
	): array {
		$this->assert_attempt_id( $attempt_id );
		$this->assert_fingerprint( $cart_fingerprint );

		$lease = array(
			'key'    => $this->option_key( $session ),
			'record' => $this->record(
				'pending',
				$attempt_id,
				$cart_fingerprint,
				null,
				$now,
				$now + self::PENDING_TTL
			),
		);
		$this->replace_mutable( $lease['key'], $lease['record'], $now );
		$this->repository->cleanup_expired( $now, self::CLEANUP_BATCH );

		return $lease;
	}

	/**
	 * Commits only if no newer select/clear replaced this exact lease.
	 *
	 * @param array{key:string,record:array<string,mixed>} $lease     Pending lease.
	 * @param array<string,mixed>                         $selection Prepared canonical selection.
	 * @param int                                         $now       Current timestamp.
	 * @return bool Whether the exact pending lease committed.
	 */
	public function commit_selection( array $lease, array $selection, int $now ): bool {
		$expected = $this->assert_lease( $lease );
		$expires  = $selection['expires_at'] ?? null;
		if (
			! is_int( $expires )
			|| $now >= $expires
			|| $now >= $expected['expires_at']
		) {
			$this->fail_selection( $lease, $now );
			return false;
		}

		$committed = $expected;
		$committed['phase']       = 'committed';
		$committed['selection']   = $selection;
		$committed['expires_at']  = $expires;
		$committed['updated_at']  = $now;

		return $this->repository->compare_and_swap(
			$lease['key'],
			$expected,
			$committed
		);
	}

	/**
	 * Marks only the still-current lease failed.
	 *
	 * @param array{key:string,record:array<string,mixed>} $lease Pending lease.
	 * @param int                                         $now   Current timestamp.
	 * @return bool Whether this lease was still authoritative.
	 */
	public function fail_selection( array $lease, int $now ): bool {
		$expected = $this->assert_lease( $lease );
		$failed   = $expected;
		$failed['phase']      = 'failed';
		$failed['selection']  = null;
		$failed['expires_at'] = $now + self::TOMBSTONE_TTL;
		$failed['updated_at'] = $now;

		return $this->repository->compare_and_swap(
			$lease['key'],
			$expected,
			$failed
		);
	}

	/**
	 * Writes an explicit tombstone that supersedes any in-flight selection.
	 *
	 * @param object $session    WooCommerce session.
	 * @param string $attempt_id Browser or server correlation ID.
	 * @param int    $now        Current timestamp.
	 */
	public function clear( object $session, string $attempt_id, int $now ): void {
		$this->assert_attempt_id( $attempt_id );
		$key = $this->option_key( $session );
		$this->replace_mutable(
			$key,
			$this->record(
				'cleared',
				$attempt_id,
				'',
				null,
				$now,
				$now + self::TOMBSTONE_TTL
			),
			$now
		);
		$this->repository->cleanup_expired( $now, self::CLEANUP_BATCH );
	}

	/**
	 * Returns only an exact, committed, current-cart selection.
	 *
	 * @param object $session          WooCommerce session.
	 * @param string $cart_fingerprint Exact server-side cart fingerprint.
	 * @param int    $now              Current timestamp.
	 * @return array<string,mixed>|null
	 */
	public function current_selection( object $session, string $cart_fingerprint, int $now ): ?array {
		$this->assert_fingerprint( $cart_fingerprint );
		$record = $this->repository->get( $this->option_key( $session ) );
		if (
			! $this->is_valid_record( $record )
			|| 'committed' !== $record['phase']
			|| $cart_fingerprint !== $record['cart_fingerprint']
			|| $now < $record['issued_at']
			|| $now >= $record['expires_at']
			|| ! is_array( $record['selection'] )
		) {
			return null;
		}

		return $record['selection'];
	}

	/**
	 * Returns the authority generation consumed before Woo shipping cache lookup.
	 *
	 * @param object $session          WooCommerce session.
	 * @param string $cart_fingerprint Exact server-side cart fingerprint.
	 * @param int    $now              Current timestamp.
	 * @return array{generation:string,selection:array<string,mixed>|null}
	 *
	 * @since 1.96.0
	 */
	public function shipping_snapshot( object $session, string $cart_fingerprint, int $now ): array {
		$this->assert_fingerprint( $cart_fingerprint );
		$record = $this->repository->get( $this->option_key( $session ) );
		if ( ! $this->is_valid_record( $record ) ) {
			return array(
				'generation' => hash( 'sha256', 'none' ),
				'selection'  => null,
			);
		}

		$state = (string) $record['phase'];
		if ( $now < $record['issued_at'] ) {
			$state = 'future';
		} elseif ( $now >= $record['expires_at'] ) {
			$state = 'expired';
		} elseif (
			! in_array( $record['phase'], array( 'committed', 'checkout' ), true )
			|| $cart_fingerprint !== $record['cart_fingerprint']
			|| ! is_array( $record['selection'] )
		) {
			$state = 'inactive-' . $state;
		} else {
			$state = 'active-' . $state;
		}

		$selection = 0 === strpos( $state, 'active-' )
			? $record['selection']
			: null;

		return array(
			'generation' => hash(
				'sha256',
				implode(
					'|',
					array(
						(string) $record['schema_version'],
						$state,
						(string) $record['version_token'],
						(string) $record['expires_at'],
					)
				)
			),
			'selection'  => $selection,
		);
	}

	/**
	 * Atomically freezes one committed generation while order data is copied.
	 *
	 * Select/clear transitions use the same CAS loop and reject this short-lived
	 * phase, closing the gap between final validation and order persistence.
	 *
	 * @param object $session          WooCommerce session.
	 * @param string $cart_fingerprint Exact cart fingerprint.
	 * @param int    $now              Current timestamp.
	 * @return array{key:string,record:array<string,mixed>,selection:array<string,mixed>}|null
	 */
	public function claim_for_checkout(
		object $session,
		string $cart_fingerprint,
		int $now
	): ?array {
		$this->assert_fingerprint( $cart_fingerprint );
		$key = $this->option_key( $session );

		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; ++$attempt ) {
			$current = $this->repository->get( $key );
			if (
				! $this->is_valid_record( $current )
				|| 'committed' !== $current['phase']
				|| $cart_fingerprint !== $current['cart_fingerprint']
				|| $now < $current['issued_at']
				|| $now >= $current['expires_at']
				|| ! is_array( $current['selection'] )
			) {
				return null;
			}

			$claimed = $current;
			$claimed['phase']      = 'checkout';
			$claimed['expires_at'] = min( $current['expires_at'], $now + self::CHECKOUT_TTL );
			$claimed['updated_at'] = $now;
			if ( $claimed['expires_at'] <= $now ) {
				return null;
			}

			if ( $this->repository->compare_and_swap( $key, $current, $claimed ) ) {
				return array(
					'key'       => $key,
					'record'    => $claimed,
					'selection' => $current['selection'],
				);
			}
		}

		throw new \RuntimeException( 'PUDO state remained contended during checkout validation.' );
	}

	/**
	 * Releases the exact checkout claim back to its original committed TTL.
	 *
	 * @param array{key:string,record:array<string,mixed>,selection:array<string,mixed>} $claim Checkout claim.
	 * @param int                                                                      $now   Current timestamp.
	 * @return bool Whether the exact claim was released.
	 */
	public function release_checkout_claim( array $claim, int $now ): bool {
		if (
			! is_string( $claim['key'] ?? null )
			|| ! is_array( $claim['record'] ?? null )
			|| ! is_array( $claim['selection'] ?? null )
			|| ! $this->is_valid_record( $claim['record'] )
			|| 'checkout' !== $claim['record']['phase']
		) {
			throw new \InvalidArgumentException( 'Invalid PUDO checkout claim.' );
		}

		$selection_expiry = $claim['selection']['expires_at'] ?? null;
		if ( ! is_int( $selection_expiry ) || $selection_expiry <= $now ) {
			return false;
		}

		$committed = $claim['record'];
		$committed['phase']      = 'committed';
		$committed['expires_at'] = $selection_expiry;
		$committed['updated_at'] = $now;

		return $this->repository->compare_and_swap(
			$claim['key'],
			$claim['record'],
			$committed
		);
	}

	/**
	 * Generates a server correlation ID for official server-side transitions.
	 *
	 * @return string
	 */
	public function server_attempt_id(): string {
		return substr( $this->new_token(), 0, 32 );
	}

	/**
	 * Creates one deterministic record shape.
	 *
	 * @param string                   $phase            State phase.
	 * @param string                   $attempt_id       Correlation ID.
	 * @param string                   $cart_fingerprint Cart fingerprint or empty for a tombstone.
	 * @param array<string,mixed>|null $selection        Canonical selection.
	 * @param int                      $issued_at         Start timestamp.
	 * @param int                      $expires_at        Expiry timestamp.
	 * @return array<string,mixed>
	 */
	private function record(
		string $phase,
		string $attempt_id,
		string $cart_fingerprint,
		?array $selection,
		int $issued_at,
		int $expires_at
	): array {
		return array(
			'schema_version'   => self::SCHEMA_VERSION,
			'phase'            => $phase,
			'version_token'    => $this->new_token(),
			'attempt_hash'     => hash_hmac( 'sha256', $attempt_id, $this->hmac_key ),
			'cart_fingerprint' => $cart_fingerprint,
			'selection'        => $selection,
			'issued_at'        => $issued_at,
			'expires_at'       => $expires_at,
			'updated_at'       => $issued_at,
		);
	}

	/**
	 * Validates a pending lease returned by begin_selection().
	 *
	 * @param array{key:string,record:array<string,mixed>} $lease Lease.
	 * @return array<string,mixed>
	 */
	private function assert_lease( array $lease ): array {
		if (
			! is_string( $lease['key'] ?? null )
			|| 1 !== preg_match( '/\A' . PudoMutationRepository::OPTION_PREFIX . '[a-f0-9]{64}\z/', $lease['key'] )
			|| ! is_array( $lease['record'] ?? null )
			|| ! $this->is_valid_record( $lease['record'] )
			|| 'pending' !== $lease['record']['phase']
			|| null !== $lease['record']['selection']
		) {
			throw new \InvalidArgumentException( 'Invalid PUDO mutation lease.' );
		}

		return $lease['record'];
	}

	/**
	 * Validates the durable record envelope.
	 *
	 * @param mixed $record Record.
	 * @return bool
	 */
	private function is_valid_record( $record ): bool {
		return is_array( $record )
			&& self::SCHEMA_VERSION === ( $record['schema_version'] ?? null )
			&& in_array( $record['phase'] ?? null, array( 'pending', 'committed', 'checkout', 'cleared', 'failed' ), true )
			&& is_string( $record['version_token'] ?? null )
			&& 1 === preg_match( '/\A[a-f0-9]{64}\z/', $record['version_token'] )
			&& is_string( $record['attempt_hash'] ?? null )
			&& 1 === preg_match( '/\A[a-f0-9]{64}\z/', $record['attempt_hash'] )
			&& is_string( $record['cart_fingerprint'] ?? null )
			&& ( '' === $record['cart_fingerprint'] || 1 === preg_match( '/\A[a-f0-9]{64}\z/', $record['cart_fingerprint'] ) )
			&& array_key_exists( 'selection', $record )
			&& ( null === $record['selection'] || is_array( $record['selection'] ) )
			&& is_int( $record['issued_at'] ?? null )
			&& is_int( $record['expires_at'] ?? null )
			&& is_int( $record['updated_at'] ?? null )
			&& $record['expires_at'] > $record['issued_at'];
	}

	/**
	 * Returns the HMAC-derived option name without exposing the Woo session ID.
	 *
	 * @param object $session WooCommerce session.
	 * @return string
	 */
	private function option_key( object $session ): string {
		$session_id = trim( (string) ( $this->session_id_resolver )( $session ) );
		if ( '' === $session_id || 200 < strlen( $session_id ) ) {
			throw new \InvalidArgumentException( 'WooCommerce session identity is unavailable.' );
		}

		return PudoMutationRepository::OPTION_PREFIX . hash_hmac( 'sha256', $session_id, $this->hmac_key );
	}

	/**
	 * Returns a cryptographically random server token.
	 *
	 * @return string
	 */
	private function new_token(): string {
		$token = strtolower( (string) ( $this->token_factory )() );
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $token ) ) {
			throw new \RuntimeException( 'Unable to generate a PUDO mutation token.' );
		}

		return $token;
	}

	/**
	 * Validates a client/server correlation ID.
	 *
	 * @param string $attempt_id Correlation ID.
	 */
	private function assert_attempt_id( string $attempt_id ): void {
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $attempt_id ) ) {
			throw new PudoValidationException(
				'pudo_invalid_attempt',
				'La solicitud del punto de retiro no es válida. Inténtalo nuevamente.'
			);
		}
	}

	/**
	 * Validates a cart fingerprint.
	 *
	 * @param string $fingerprint Fingerprint.
	 */
	private function assert_fingerprint( string $fingerprint ): void {
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $fingerprint ) ) {
			throw new \InvalidArgumentException( 'A valid cart fingerprint is required.' );
		}
	}

	/**
	 * Returns the private WordPress salt used only for HMAC derivation.
	 *
	 * @return string
	 */
	private function default_hmac_key(): string {
		if ( ! function_exists( 'wp_salt' ) ) {
			throw new \RuntimeException( 'WordPress salts are unavailable for PUDO state.' );
		}

		return (string) wp_salt( 'auth' );
	}

	/**
	 * Replaces pending/tombstone state unless a final checkout owns the row.
	 *
	 * @param string              $key         HMAC option key.
	 * @param array<string,mixed> $replacement New state.
	 * @param int                 $now         Current timestamp.
	 */
	private function replace_mutable( string $key, array $replacement, int $now ): void {
		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; ++$attempt ) {
			$current = $this->repository->get( $key );
			if ( null === $current ) {
				if ( $this->repository->create_if_absent( $key, $replacement ) ) {
					return;
				}
				continue;
			}

			if (
				$this->is_valid_record( $current )
				&& 'checkout' === $current['phase']
				&& $now < $current['expires_at']
			) {
				throw new PudoValidationException(
					'pudo_checkout_in_progress',
					'El pedido ya está confirmando este punto de retiro. Espera el resultado antes de cambiarlo.'
				);
			}

			if ( $this->repository->compare_and_swap( $key, $current, $replacement ) ) {
				return;
			}
		}

		throw new \RuntimeException( 'PUDO state remained contended during mutation.' );
	}
}
