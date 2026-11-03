<?php
/**
 * CollectionToggle — el interruptor de «Mi colección» y su ruta REST.
 *
 * DELIBERADAMENTE distinta de la ruta pública de la lista de espera
 * (`'permission_callback' => '__return_true'`): aquella captura un correo
 * anónimo, ésta MUTA el estado del titular de la sesión. El `user_id` sale
 * SIEMPRE de `get_current_user_id()` y nunca del cuerpo de la petición, así que
 * mandar el id de otro cliente no lo toca.
 *
 * La superficie ya es privada y sin caché de página (`AccountCollection\Endpoint`
 * marca `no-store` y desactiva la caché), así que el toggle no necesita
 * cabeceras propias.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class CollectionToggle {

	public const REST_NAMESPACE = 'akibara/v1';
	public const REST_ROUTE     = '/series-follow';

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_toggle' ),
			)
		);
	}

	/**
	 * Sesión iniciada Y nonce de REST válido. El nonce no es ceremonia: sin él,
	 * un sitio de terceros podría dar de baja las series del cliente con una
	 * petición cross-site que viaja con su cookie.
	 *
	 * @param WP_REST_Request $request Petición entrante.
	 * @return bool
	 */
	public function can_toggle( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return (bool) wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}

	/**
	 * @param WP_REST_Request $request Petición entrante.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) get_current_user_id();
		$term_id = (int) $request->get_param( 'term_id' );
		$follow  = $this->normalize_follow( $request->get_param( 'follow' ) );

		if ( $user_id < 1 || $term_id < 1 ) {
			return new WP_REST_Response(
				array(
					'code'    => 'invalid_series',
					'message' => 'No pudimos identificar esa serie.',
				),
				400
			);
		}

		if ( $follow ) {
			FollowRegistry::opt_in( $user_id, $term_id );
		} else {
			FollowRegistry::opt_out( $user_id, $term_id );
		}

		return new WP_REST_Response(
			array(
				'code'      => $follow ? 'following' : 'unfollowed',
				'following' => $follow,
				'message'   => $follow
					? 'Listo: te avisamos cuando salga el siguiente tomo.'
					: 'Listo: no te avisaremos de esta serie.',
			),
			200
		);
	}

	/**
	 * @param mixed $raw Valor crudo del cuerpo.
	 * @return bool
	 */
	private function normalize_follow( $raw ): bool {
		if ( is_bool( $raw ) ) {
			return $raw;
		}

		return in_array( $raw, array( 1, '1', 'true', 'yes', 'on' ), true );
	}
}
