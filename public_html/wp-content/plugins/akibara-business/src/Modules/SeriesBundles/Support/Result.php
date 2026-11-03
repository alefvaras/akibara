<?php
/**
 * Series Bundles — Support\Result.
 *
 * Tipo de resultado ok/error con mensaje, usado por la normalización de tramos.
 * Inmutable.
 *
 * Sin funciones i18n (Req 11.4): los mensajes se reciben en español literal.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles\Support;

defined( 'ABSPATH' ) || exit;

final class Result {

	private function __construct(
		private readonly bool $ok,
		private readonly string $message
	) {
	}

	/**
	 * Resultado exitoso (sin mensaje de error).
	 */
	public static function ok(): self {
		return new self( true, '' );
	}

	/**
	 * Resultado fallido con mensaje descriptivo.
	 */
	public static function error( string $msg ): self {
		return new self( false, $msg );
	}

	/**
	 * True si el resultado es exitoso.
	 */
	public function is_ok(): bool {
		return $this->ok;
	}

	/**
	 * Mensaje de error (cadena vacía cuando el resultado es exitoso).
	 */
	public function message(): string {
		return $this->message;
	}
}
