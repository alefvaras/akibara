<?php
/**
 * GlobalToggle — el interruptor «Avisos de mi colección», sin JavaScript.
 *
 * Es la única superficie de mando del opt-out GLOBAL con sesión iniciada. Hasta
 * 1.115 ese estado (`_akb_sf_optout_all`) sólo se podía ENCENDER desde el
 * correo (List-Unsubscribe) y sólo se APAGABA como efecto colateral de
 * reactivar una serie concreta: quien se daba de baja desde el inbox no tenía
 * ningún sitio donde volver a encender los avisos.
 *
 * Es un formulario POST con nonce y patrón POST-redirect-GET, no una ruta REST
 * ni un toggle de JS, a propósito:
 *   - el correo y esta pantalla escriben la MISMA usermeta con las MISMAS
 *     funciones (`FollowRegistry::opt_out_all()` / `opt_in_all()`), así que
 *     no hay dos verdades que reconciliar;
 *   - no toca el contrato REST de `/series-follow` (`CollectionToggle`), que
 *     sigue rechazando `term_id` 0;
 *   - el estado pintado sale del servidor en cada carga: sin JS no hay
 *     «parece encendido pero no lo está».
 *
 * SIEMPRE vivo, aunque `akb_sf_enabled` esté en no: la misma doctrina que la
 * baja de un clic. El flag es del ENVÍO; apagar los envíos no puede dejar al
 * cliente sin su interruptor.
 *
 * El `user_id` sale SIEMPRE de `get_current_user_id()` y nunca del cuerpo:
 * mandar el id de otro cliente no lo toca (regla de `CollectionToggle`).
 *
 * Las salidas de fallo TAMBIÉN redirigen. Un nonce caducado (el tick de
 * WordPress es de 12-24 h: basta una pestaña de Colección abierta desde ayer)
 * o una sesión caída no mutan, pero no pueden quedarse mudas: es la superficie
 * donde el cliente ejerce su oposición, y «no pasó nada» sin mensaje se lee
 * como «la tienda ignoró mi baja». Además, dejar seguir el POST pintaba la
 * respuesta en la propia URL del POST y un F5 disparaba el reenvío del
 * formulario. Por eso esas ramas dejan un aviso de error y redirigen igual.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

use Akibara\Business\Modules\AccountCollection\Endpoint;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.116.0
 */
final class GlobalToggle {

	/** Campo del formulario: 'off' apaga, 'on' enciende. Cualquier otro valor se ignora. */
	public const FIELD = 'akb_sf_global';

	/** Campo oculto con el nonce. */
	public const NONCE_FIELD = '_akb_sf_nonce';

	/** Prefijo de la acción del nonce; se completa con el id del cliente de la sesión. */
	public const NONCE_ACTION = 'akb_sf_global_';

	/** Valores aceptados del campo. Nada más se interpreta. */
	public const VALUE_OFF = 'off';
	public const VALUE_ON  = 'on';

	/**
	 * Avisos de éxito. Literales nuestros, nunca formato de `sprintf`: los lee
	 * `wc_add_notice` y los imprime WooCommerce en `woocommerce_account_content`.
	 */
	public const NOTICE_OFF = 'Listo: apagamos los avisos de tu colección. No te escribiremos cuando salgan tomos nuevos.';
	public const NOTICE_ON  = 'Listo: te avisaremos por correo cuando salga el siguiente tomo de tus series.';

	/**
	 * Complemento de NOTICE_ON cuando el cliente tiene series apagadas una a
	 * una: encender el global no las deshace (`opt_in_all()` conserva
	 * `_akb_sf_optout` a propósito), y prometer «tus series» a secas sería
	 * falso para ésas. Va como aviso aparte, tipo 'notice', para no tocar el
	 * copy del dueño.
	 */
	public const NOTICE_ON_PARTIAL = 'Las series que apagaste una a una siguen apagadas. Las reactivas desde su tarjeta, aquí en tu colección.';

	/** Nonce caducado o ajeno: no mutó, y el cliente tiene que saberlo. */
	public const NOTICE_RETRY = 'No pudimos guardar el cambio: la página llevaba demasiado tiempo abierta. Vuelve a intentarlo.';

	/** El POST llegó sin sesión: la Colección le pedirá iniciar sesión. */
	public const NOTICE_SESSION = 'Tu sesión se cerró antes de guardar el cambio. Inicia sesión y vuelve a intentarlo.';

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 1 );
	}

	/**
	 * Procesa el POST del interruptor y redirige a la Colección.
	 *
	 * Sólo muta con: método POST, el campo presente, sesión iniciada y nonce
	 * del cliente de la sesión. Sin sesión o con nonce inválido no muta, deja
	 * un aviso de error y redirige igual (POST-redirect-GET también en el
	 * fallo). Un POST sin el campo, o con un valor que no es 'on'/'off', no es
	 * de este formulario y sigue su curso.
	 *
	 * @return void
	 */
	public function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== strtoupper( $method ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce se verifica justo abajo, tras confirmar que la petición es de este formulario.
		if ( ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}

		$user_id = is_user_logged_in() ? (int) get_current_user_id() : 0;
		if ( $user_id < 1 ) {
			$this->reject( self::NOTICE_SESSION );
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
			: '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $user_id ) ) {
			$this->reject( self::NOTICE_RETRY );
			return;
		}

		$value = is_string( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
		if ( self::VALUE_OFF === $value ) {
			FollowRegistry::opt_out_all( $user_id );
			$this->notice( self::NOTICE_OFF, 'success' );
		} elseif ( self::VALUE_ON === $value ) {
			FollowRegistry::opt_in_all( $user_id );
			$this->notice( self::NOTICE_ON, 'success' );
			if ( array() !== FollowRegistry::opted_out_terms( $user_id ) ) {
				$this->notice( self::NOTICE_ON_PARTIAL, 'notice' );
			}
		} else {
			return;
		}

		$this->redirect();
	}

	/**
	 * Rama de fallo: aviso de error y redirección, sin mutar.
	 *
	 * Sin sesión, WooCommerce no persiste avisos a menos que exista la cookie
	 * de sesión del visitante; se pide expresamente para que el mensaje
	 * sobreviva a la redirección y se vea junto al formulario de acceso.
	 *
	 * @param string $message Aviso.
	 * @return void
	 */
	private function reject( string $message ): void {
		if ( function_exists( 'WC' ) && isset( WC()->session ) && is_callable( array( WC()->session, 'set_customer_session_cookie' ) ) ) {
			WC()->session->set_customer_session_cookie( true );
		}

		$this->notice( $message, 'error' );
		$this->redirect();
	}

	/**
	 * `wc_add_notice` exige la sesión de WooCommerce, que en el front ya está
	 * en `init`. La guarda es por si el handler corre en un contexto sin
	 * WooCommerce cargado: el estado ya mutó y el aviso es cortesía, no
	 * requisito.
	 *
	 * @param string $message Aviso.
	 * @param string $type    success|notice|error.
	 * @return void
	 */
	private function notice( string $message, string $type ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}
	}

	/**
	 * @return void
	 */
	private function redirect(): void {
		wp_safe_redirect( Endpoint::url() );

		/**
		 * Cortocircuito para pruebas (evita el `exit`), como
		 * `akb_sf_unfollow_should_exit` en la baja por correo.
		 *
		 * @since 1.116.0
		 *
		 * @param bool $should_exit Default true.
		 */
		if ( apply_filters( 'akb_sf_global_toggle_should_exit', true ) ) {
			exit;
		}
	}
}
