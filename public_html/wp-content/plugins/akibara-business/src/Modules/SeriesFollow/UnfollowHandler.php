<?php
/**
 * UnfollowHandler — baja de un clic, sin tabla, con firma HMAC.
 *
 * SIEMPRE vivo (no cuelga de ningún flag): un enlace de baja muerto en un correo
 * ya enviado es incumplimiento.
 *
 *   - GET  → página de confirmación con botón (POST). Los link-scanners
 *     corporativos y el prefetch del inbox HACEN GET a todos los enlaces: si el
 *     GET mutara, escanear el correo daría de baja sin consentimiento.
 *   - POST → muta. Es también lo que exige el RFC 8058 junto al header
 *     `List-Unsubscribe-Post`.
 *   - Token inválido o manipulado → la MISMA página de «listo», sin tocar nada:
 *     la respuesta no puede confirmar ni negar que un cliente existe.
 *
 * La firma es `hash_hmac` con `wp_salt('auth')` y se compara con `hash_equals`.
 * Sin eso, cambiar el `user_id` de la URL daría de baja a otro cliente.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class UnfollowHandler {

	/** Cliente. */
	public const QUERY_USER = 'akb_sf_u';

	/** Término de `pa_serie`; 0 = baja global. */
	public const QUERY_TERM = 'akb_sf_t';

	/** Firma. */
	public const QUERY_KEY = 'akb_sf_k';

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 1 );
	}

	/**
	 * Firma de una baja concreta.
	 *
	 * @param int $user_id Cliente.
	 * @param int $term_id Término de `pa_serie`; 0 para la baja global.
	 * @return string
	 */
	public static function token( int $user_id, int $term_id ): string {
		return substr( hash_hmac( 'sha256', $user_id . '|' . $term_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * URL de baja de una serie bajo una ruta privada del cliente.
	 *
	 * OneClickPage::private_base_url() utiliza Mi cuenta para evitar que una copia
	 * de HTML de invitado del borde retenga el resultado de una baja individual.
	 *
	 * @param int $user_id Cliente.
	 * @param int $term_id Término de `pa_serie`.
	 * @return string
	 */
	public static function url_for( int $user_id, int $term_id ): string {
		return add_query_arg(
			array(
				self::QUERY_USER => $user_id,
				self::QUERY_TERM => $term_id,
				self::QUERY_KEY  => self::token( $user_id, $term_id ),
			),
			\Akibara\Business\Services\OneClickPage::private_base_url()
		);
	}

	/**
	 * URL de baja GLOBAL (la del header `List-Unsubscribe`).
	 *
	 * @param int $user_id Cliente.
	 * @return string
	 */
	public static function url_for_all( int $user_id ): string {
		return self::url_for( $user_id, 0 );
	}

	/**
	 * Enruta GET (confirmación) vs POST (mutación).
	 *
	 * @return void
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Endpoint público firmado con HMAC; un nonce mataría el one-click desde el inbox (RFC 8058).
		if ( ! isset( $_GET[ self::QUERY_USER ], $_GET[ self::QUERY_TERM ], $_GET[ self::QUERY_KEY ] ) ) {
			return;
		}

		// `is_scalar` antes del cast: un parámetro array (`?akb_sf_u[]=1`) daría
		// "Array" con warning.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Ver arriba.
		$user_id = is_scalar( $_GET[ self::QUERY_USER ] ) ? (int) sanitize_text_field( wp_unslash( $_GET[ self::QUERY_USER ] ) ) : 0;
		$term_id = is_scalar( $_GET[ self::QUERY_TERM ] ) ? (int) sanitize_text_field( wp_unslash( $_GET[ self::QUERY_TERM ] ) ) : -1;
		$key     = is_string( $_GET[ self::QUERY_KEY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_KEY ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$is_post = 'POST' === strtoupper( $method );

		if ( ! $is_post ) {
			$this->render_page( $this->confirm_markup( $user_id, $term_id, $key ) );
			return;
		}

		if ( $user_id > 0 && $term_id >= 0 && hash_equals( self::token( $user_id, $term_id ), $key ) ) {
			if ( 0 === $term_id ) {
				FollowRegistry::opt_out_all( $user_id );
			} else {
				FollowRegistry::opt_out( $user_id, $term_id );
			}
		}

		$this->render_page( $this->done_markup() );
	}

	/**
	 * @param int    $user_id Cliente de la URL (aún sin verificar).
	 * @param int    $term_id Término de la URL (aún sin verificar).
	 * @param string $key     Firma de la URL.
	 * @return string
	 */
	private function confirm_markup( int $user_id, int $term_id, string $key ): string {
		$action = esc_url(
			add_query_arg(
				array(
					self::QUERY_USER => $user_id,
					self::QUERY_TERM => $term_id,
					self::QUERY_KEY  => $key,
				),
				\Akibara\Business\Services\OneClickPage::private_base_url()
			)
		);

		$copy = 0 === $term_id
			? 'Si confirmas, dejaremos de avisarte cuando salga un tomo nuevo de cualquiera de tus series.'
			: 'Si confirmas, dejaremos de avisarte cuando salga un tomo nuevo de esta serie. Las demás siguen igual.';

		return '<h1>Dejar de recibir avisos</h1>'
			. '<p>' . esc_html( $copy ) . '</p>'
			. '<form method="post" action="' . $action . '">'
			. '<button type="submit">Confirmar baja</button>'
			. '</form>';
	}

	/**
	 * La página «Listo» cierra el ciclo: dice cómo volver. El camino de vuelta
	 * es el interruptor de Mi cuenta → Colección (`GlobalToggle`), que escribe
	 * la MISMA usermeta que esta baja. Con token inválido se sirve la misma
	 * página, sin mutar nada.
	 *
	 * @return string
	 */
	private function done_markup(): string {
		return '<h1>Listo</h1>'
			. '<p>Ya no te enviaremos estos avisos.</p>'
			. '<p>Si cambias de idea, los vuelves a encender en <a href="' . esc_url( \Akibara\Business\Modules\AccountCollection\Endpoint::url() ) . '">Mi cuenta, pestaña Colección</a>.</p>'
			. '<p><a href="' . esc_url( home_url( '/' ) ) . '">Volver a la tienda</a></p>';
	}

	/**
	 * Página mínima autocontenida. En pruebas se corta por el filtro que anula
	 * el `exit`.
	 *
	 * @param string $body_html Markup interno ya escapado.
	 * @return void
	 */
	private function render_page( string $body_html ): void {
		\Akibara\Business\Services\OneClickPage::render( 'Avisos de serie', $body_html, 'akb_sf_unfollow_should_exit' );
	}
}
