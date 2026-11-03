<?php
/**
 * AccountCollection — composición del endpoint privado "Mi colección".
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AccountCollection;

defined( 'ABSPATH' ) || exit;

final class AccountCollection {

	private Endpoint $endpoint;

	/**
	 * @param callable(int):array<string,mixed> $resolver Resuelve la colección del cliente.
	 */
	public function __construct( callable $resolver ) {
		$this->endpoint = new Endpoint( new Renderer( $resolver ) );
	}

	/**
	 * Registra la ruta, navegación, headers privados y assets del endpoint.
	 */
	public function register(): void {
		$this->endpoint->register();
	}
}
