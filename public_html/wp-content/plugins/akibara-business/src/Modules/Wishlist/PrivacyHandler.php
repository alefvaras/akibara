<?php
/**
 * PrivacyHandler — exporter y eraser de datos personales (GDPR/Ley 19.628).
 *
 * La tabla wp_akb_wishlist guarda qué productos guardó una persona identificada
 * (user_id → email) y cuándo: las herramientas nativas de WP (Herramientas →
 * Exportar/Borrar datos personales) deben verla. Mismo contrato que el módulo
 * hermano BackInStock.
 *
 *   - Exporter: resuelve el email a usuario y lista cada favorito (producto, fecha).
 *   - Eraser: borrado FÍSICO — la fila sin titular no tiene valor de negocio ni
 *     obligación de retención (no es transaccional/contable).
 *
 * WP entrega email, no user_id: si no hay cuenta con ese email no hay wishlist
 * que exportar ni borrar (el módulo es login-required, no existe wishlist guest).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Wishlist;

defined( 'ABSPATH' ) || exit;

final class PrivacyHandler {

	private const GROUP_ID = 'akb-wishlist';

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * @param array<string, mixed> $exporters Exporters registrados.
	 * @return array<string, mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['akibara-wishlist'] = array(
			'exporter_friendly_name' => 'Favoritos Akibara',
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string, mixed> $erasers Erasers registrados.
	 * @return array<string, mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['akibara-wishlist'] = array(
			'eraser_friendly_name' => 'Favoritos Akibara',
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporter de WP: una entrada por producto guardado.
	 *
	 * @param string $email Email del titular.
	 * @param int    $page  Página (las wishlists son chicas: una sola pasada).
	 * @return array{data: array<int, mixed>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$data = array();
		$user = get_user_by( 'email', $email );

		if ( $user instanceof \WP_User ) {
			foreach ( Storage::entries( (int) $user->ID ) as $entry ) {
				$product_id = $entry['product_id'];
				$product    = function_exists( 'get_the_title' ) ? get_the_title( $product_id ) : '';

				$data[] = array(
					'group_id'    => self::GROUP_ID,
					'group_label' => 'Favoritos (productos guardados)',
					'item_id'     => 'akb-wl-' . $product_id,
					'data'        => array(
						array( 'name' => 'Producto',        'value' => '' !== $product ? $product : ( '#' . $product_id ) ),
						array( 'name' => 'Enlace',          'value' => (string) get_permalink( $product_id ) ),
						array( 'name' => 'Guardado (local)', 'value' => $entry['added_at'] ),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Eraser de WP: borrado físico de todos los favoritos del titular.
	 *
	 * @param string $email Email del titular.
	 * @param int    $page  Página.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email );
		$removed = $user instanceof \WP_User ? Storage::erase_user( (int) $user->ID ) : 0;

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
