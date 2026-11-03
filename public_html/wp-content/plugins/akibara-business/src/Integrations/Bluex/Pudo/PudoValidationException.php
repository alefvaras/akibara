<?php
/**
 * Domain exception for fail-closed Blue Express PUDO validation.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Integrations\Bluex\Pudo;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a stable public error code without exposing provider responses.
 *
 * @since 1.96.0
 */
final class PudoValidationException extends RuntimeException {

	private string $error_code;

	/**
	 * Condición concreta del rechazo para el registro interno.
	 *
	 * El contexto permite diagnosticar la validación sin exponer detalles internos:
	 * al cliente sólo se le muestra el mensaje público de la excepción.
	 *
	 * @var array<string, mixed>
	 */
	private array $details;

	/**
	 * Creates a safe PUDO validation exception.
	 *
	 * @param string               $error_code Stable machine-readable code.
	 * @param string               $message    Safe customer-facing message.
	 * @param Throwable|null       $previous   Previous exception.
	 * @param array<string, mixed> $details    Detail for the log (never shown to the customer).
	 *
	 * @since 1.96.0
	 */
	public function __construct( string $error_code, string $message, ?Throwable $previous = null, array $details = array() ) {
		$this->error_code = $error_code;
		$this->details    = $details;

		parent::__construct( $message, 0, $previous );
	}

	/**
	 * Detail for the log.
	 *
	 * @return array<string, mixed>
	 *
	 * @since 1.116.31
	 */
	public function details(): array {
		return $this->details;
	}

	/**
	 * Returns the stable machine-readable code.
	 *
	 * @return string
	 *
	 * @since 1.96.0
	 */
	public function error_code(): string {
		return $this->error_code;
	}
}
