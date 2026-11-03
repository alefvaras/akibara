<?php
/**
 * Baja por token siempre disponible, independiente de los flags de captura
 * y despacho. GET sólo presenta confirmación; POST aplica la baja, incluido
 * List-Unsubscribe=One-Click de RFC 8058. Es idempotente y responde igual para
 * tokens válidos o inválidos, sin revelar pertenencia a la lista.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.53.0
 */
final class UnsubscribeHandler {

	/** Query var pública del token. */
	public const QUERY_VAR = 'akb_bis_unsub';

	/** @var SubscriberRepository */
	private SubscriberRepository $repository;

	/**
	 * @param SubscriberRepository $repository Persistencia.
	 */
	public function __construct( SubscriberRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 1 );
	}

	/**
	 * Genera la baja bajo la base privada de Mi cuenta, evitando que la caché
	 * de portada intercepte el enlace. Usa OneClickPage::private_base_url().
	 *
	 * @param string $token Token hex de la suscripción.
	 * @return string
	 */
	public static function url_for( string $token ): string {
		return add_query_arg( self::QUERY_VAR, rawurlencode( $token ), \Akibara\Business\Services\OneClickPage::private_base_url() );
	}

	/**
	 * Enruta GET (confirmación) vs POST (mutación) cuando la query var viene
	 * con un token bien formado. Cualquier otra request sigue su curso.
	 *
	 * @return void
	 */
	public function handle(): void {
		// is_string: un param array (?akb_bis_unsub[]=x) castearía a "Array" con warning (hardening del gate QA).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- endpoint público por token de un solo uso; el POST es la confirmación (RFC 8058), un nonce mataría el one-click desde el inbox.
		$raw = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $raw ) {
			return;
		}

		$token = strtolower( trim( $raw ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			// Malformado: misma cara que "listo" (no filtrar señal) y SIN
			// tocar el repositorio.
			$this->render_page( $this->done_markup() );
			return;
		}

		$is_post = isset( $_SERVER['REQUEST_METHOD'] )
			&& 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );

		if ( ! $is_post ) {
			// GET solo CONFIRMA (form con botón POST): los link-scanners y el
			// prefetch hacen GET — si mutara acá, escanear el email daría de
			// baja sin consentimiento. El return importa aunque render_page
			// haga exit: en tests el exit se filtra.
			$this->render_page( $this->confirm_markup( $token, $this->product_name_for( $token ) ) );
			return;
		}

		$subscriber = $this->repository->find_by_token( $token );
		$nombre     = $subscriber instanceof Subscriber ? self::product_name( $subscriber->product_id ) : '';
		$otras      = 0;

		if ( $subscriber instanceof Subscriber ) {
			$otras = $this->repository->count_other_waiting( $subscriber->email, $subscriber->id );
			$this->repository->unsubscribe( $subscriber->id ); // Idempotente.
		}

		$this->render_page( $this->done_markup( $nombre, $otras ) );
	}

	/**
	 * Nombre del producto del token, para nombrarlo en pantalla. En el GET se
	 * LEE, nunca se muta: la baraja contra los escáneres de correo sigue intacta.
	 *
	 * @since 1.114.2
	 *
	 * @param string $token Token validado.
	 * @return string
	 */
	private function product_name_for( string $token ): string {
		$subscriber = $this->repository->find_by_token( $token );
		return $subscriber instanceof Subscriber ? self::product_name( $subscriber->product_id ) : '';
	}

	/**
	 * @since 1.114.2
	 *
	 * @param int $product_id Producto.
	 * @return string
	 */
	private static function product_name( int $product_id ): string {
		$titulo = $product_id > 0 ? (string) get_the_title( $product_id ) : '';
		return '' !== trim( $titulo ) ? $titulo : '';
	}

	/**
	 * @param string $token   Token validado.
	 * @param string $product Nombre del producto, o cadena vacía si no se pudo resolver.
	 * @return string
	 */
	private function confirm_markup( string $token, string $product = '' ): string {
		$action = esc_url( self::url_for( $token ) );

		// Nombrar el tomo: quien espera varios no puede adivinar de cuál se está
		// dando de baja, y con el tope de altas subido a 12 esperar varios pasó
		// a ser lo normal, no la excepción.
		$que = '' !== $product
			? sprintf( '<strong>%s</strong>', esc_html( $product ) )
			: 'este producto';

		return '<h1>Salir de la lista de espera</h1>'
			. '<p>Si confirmas, dejaremos de avisarte cuando ' . $que . ' vuelva a stock.</p>'
			. '<form method="post" action="' . $action . '">'
			. '<button type="submit">Confirmar baja</button>'
			. '</form>';
	}

	/**
	 * @param string $product Nombre del producto, o cadena vacía.
	 * @param int    $otras   Cuántas otras esperas siguen vivas para ese correo.
	 * @return string
	 */
	private function done_markup( string $product = '', int $otras = 0 ): string {
		$que = '' !== $product
			? sprintf( '<strong>%s</strong>', esc_html( $product ) )
			: 'este producto';

		$html = '<h1>Listo</h1>'
			. '<p>Ya no te enviaremos avisos de ' . $que . '.</p>';

		// Sin esto, quien espera tres tomos y se da de baja de uno cree que se
		// borró de todos.
		if ( $otras > 0 ) {
			$html .= sprintf(
				'<p>Tus avisos de %s siguen activos.</p>',
				1 === $otras ? 'otro producto' : esc_html( sprintf( 'otros %d productos', $otras ) )
			);
		}

		return $html . '<p><a href="' . esc_url( home_url( '/' ) ) . '">Volver a la tienda</a></p>';
	}

	/**
	 * Página mínima autocontenida (sin theme: siempre renderiza igual, no
	 * depende de headers/footers pesados para una acción de 2 segundos).
	 * En tests se corta por el filtro que anula el exit.
	 *
	 * @param string $body_html Markup interno ya escapado.
	 * @return void
	 */
	private function render_page( string $body_html ): void {
		\Akibara\Business\Services\OneClickPage::render( 'Lista de espera', $body_html, 'akb_bis_unsub_should_exit' );
	}
}
