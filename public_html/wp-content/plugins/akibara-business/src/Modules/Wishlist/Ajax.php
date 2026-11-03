<?php
/**
 * Ajax — endpoint toggle del botón corazón.
 *
 * Hubo un `akb_wishlist_count` (con gemelo `nopriv` que sólo sabía responder 0)
 * y no lo consultaba nadie: el contador del header lo pinta el theme en PHP y
 * lo refresca el evento `akb:wishlist:count` que emite el propio toggle.
 *
 * Solo usuarios logueados (`wp_ajax_*`). Guests reciben 401 y el JS los
 * redirige a /mi-cuenta/. Nonce `akb_wishlist`, que se AUTO-SANA: el que viaja
 * en la página puede venir de la caché y estar caducado, así que un rechazo
 * devuelve uno nuevo y el cliente reintenta una vez.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Wishlist;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	public const NONCE_ACTION = 'akb_wishlist';

	public function register(): void {
		add_action( 'wp_ajax_akb_wishlist_toggle', array( $this, 'toggle' ) );
		add_action( 'wp_ajax_nopriv_akb_wishlist_toggle', array( $this, 'guest' ) );
	}

	public function toggle(): void {
		// Verificación NO fatal a propósito: el nonce viaja horneado en HTML
		// público que LiteSpeed cachea hasta 7 días, o sea mucho más que las 24 h
		// que vive un nonce. Con `check_ajax_referer()` a secas, el cliente con
		// una pestaña vieja o una página servida desde caché tocaba el corazón y
		// recibía «No pudimos guardar» para siempre. Se responde con un código
		// que el JS reconoce para pedir un nonce fresco al endpoint (que NO se
		// cachea) y reintentar una sola vez — el mismo auto-sanado que usa el
		// carrito nativo.
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => 'Tu sesión caducó. Reintentando…',
					'code'    => 'stale_nonce',
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				),
				403
			);
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( $product_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'product_id requerido' ), 400 );
		}

		$intent = isset( $_POST['intent'] ) ? sanitize_key( (string) $_POST['intent'] ) : 'toggle';

		// Ambas ramas se validan: Storage sólo devuelve false ante fallo real de
		// escritura (o argumentos inválidos), nunca ante el caso idempotente. Sin
		// este chequeo la respuesta anunciaba success + state con la fila sin
		// escribir: el corazón quedaba pintado y al recargar el favorito no estaba.
		// Con intent === 'add' (auto-guardado post-login), si ya está guardado es éxito no-op.
		if ( 'add' === $intent ) {
			if ( ! Storage::has( $product_id ) && ! Storage::add( $product_id ) ) {
				wp_send_json_error( array( 'message' => 'No se pudo agregar a favoritos' ), 500 );
			}
			$state = 'added';
		} elseif ( Storage::has( $product_id ) ) {
			if ( ! Storage::remove( $product_id ) ) {
				wp_send_json_error( array( 'message' => 'No se pudo quitar de favoritos' ), 500 );
			}
			$state = 'removed';
		} else {
			if ( ! Storage::add( $product_id ) ) {
				wp_send_json_error( array( 'message' => 'No se pudo agregar a favoritos' ), 500 );
			}
			$state = 'added';
		}

		wp_send_json_success(
			array(
				'state'      => $state,
				'product_id' => $product_id,
				'count'      => Storage::count(),
			)
		);
	}

	/**
	 * Endpoint nopriv: retorna 401 con la URL de login. El JS redirige.
	 */
	public function guest(): void {
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Rama nopriv de solo lectura: no muta nada, sólo arma la URL de login que el JS usa para redirigir.
		$return_url = $product_id > 0 ? (string) get_permalink( $product_id ) : '';

		$login_url = function_exists( 'wc_get_page_permalink' )
			? (string) wc_get_page_permalink( 'myaccount' )
			: wp_login_url( $return_url );

		if ( '' !== $return_url ) {
			$login_url = add_query_arg( 'redirect_to', rawurlencode( $return_url ), $login_url );
		}

		wp_send_json_error(
			array(
				'message'   => 'Inicia sesión para guardar favoritos',
				'login_url' => esc_url_raw( $login_url ),
			),
			401
		);
	}

}
