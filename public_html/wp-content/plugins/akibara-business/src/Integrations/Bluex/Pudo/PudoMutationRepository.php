<?php
/**
 * Atomic persistence contract for authoritative PUDO mutations.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Integrations\Bluex\Pudo;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps request concurrency independent from WooCommerce's whole-session save.
 *
 * @since 1.96.0
 */
interface PudoMutationRepository {

	/**
	 * Namespace de las filas autoritativas de PUDO en `wp_options`.
	 *
	 * Vive en el CONTRATO, no en cada extremo: la clave la construye el guard
	 * (`PudoMutationGuard::option_key()`) y la valida el repositorio
	 * (`assert_key()`), y hasta 1.111.0 cada uno la declaraba por su cuenta como
	 * constante privada. Dos dueños del mismo literal significan que cambiar el
	 * prefijo de un lado deja al otro rechazando toda clave legítima —y el
	 * síntoma es un checkout que pierde el punto de retiro, no un error.
	 */
	public const OPTION_PREFIX = 'akb_pudo_state_';

	/**
	 * Creates a row only when none exists.
	 *
	 * @param string              $key    HMAC-derived option key.
	 * @param array<string,mixed> $record Authoritative record.
	 * @return bool Whether the row was inserted.
	 */
	public function create_if_absent( string $key, array $record ): bool;

	/**
	 * Replaces a row only when its complete current value is still the lease read by the caller.
	 *
	 * @param string              $key         HMAC-derived option key.
	 * @param array<string,mixed> $expected    Exact expected record.
	 * @param array<string,mixed> $replacement Replacement record.
	 * @return bool Whether the compare-and-swap succeeded.
	 */
	public function compare_and_swap( string $key, array $expected, array $replacement ): bool;

	/**
	 * Reads the authoritative row directly from durable storage.
	 *
	 * @param string $key HMAC-derived option key.
	 * @return array<string,mixed>|null
	 */
	public function get( string $key ): ?array;

	/**
	 * Deletes at most a bounded batch of currently expired rows.
	 *
	 * @param int $now   Current Unix timestamp.
	 * @param int $limit Maximum rows to delete.
	 * @return int Deleted row count.
	 */
	public function cleanup_expired( int $now, int $limit ): int;
}
