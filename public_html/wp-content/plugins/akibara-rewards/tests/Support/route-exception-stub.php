<?php
/**
 * Doble de la excepción de ruta de la Store API de WooCommerce.
 *
 * La suite no carga WooCommerce, así que sin este doble la rama que devuelve un
 * 409 con el carrito recalculado —la que ve de verdad el cliente en la caja de
 * bloques— no la ejecutaba ninguna prueba (revisión independiente 2026-09-13).
 * Replica la forma real: el código HTTP viaja como código de la excepción.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\StoreApi\Exceptions;

if ( ! class_exists( RouteException::class ) ) {
	/**
	 * Doble mínimo con la firma de WooCommerce.
	 */
	class RouteException extends \Exception {

		/** Código estable del error. */
		private string $error_code;

		/** @var array<string,mixed> */
		private array $additional_data;

		/**
		 * @param string              $error_code       Código.
		 * @param string              $message          Mensaje.
		 * @param int                 $http_status_code Estado HTTP.
		 * @param array<string,mixed> $additional_data  Datos para el cliente.
		 */
		public function __construct( $error_code, $message, $http_status_code = 400, $additional_data = array() ) {
			$this->error_code      = (string) $error_code;
			$this->additional_data = (array) $additional_data;
			parent::__construct( (string) $message, (int) $http_status_code );
		}

		public function getErrorCode(): string {
			return $this->error_code;
		}

		/** @return array<string,mixed> */
		public function getAdditionalData(): array {
			return $this->additional_data;
		}
	}
}
