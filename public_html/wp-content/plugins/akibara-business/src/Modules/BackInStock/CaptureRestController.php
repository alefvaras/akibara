<?php
/**
 * Endpoint público POST /wp-json/akibara/v1/back-in-stock. El adaptador HTTP
 * delega en subscribe(), que recibe valores planos y devuelve code/status.
 * No incrusta nonce de invitado en páginas cacheadas. Aplica honeypot y
 * RateLimit por IP después de validar, evitando consumir cuota por basura.
 * Cada alta anuncia akb_bis_subscribed para enviar el aviso con enlace de baja;
 * los controles anti-bot no sustituyen al consentimiento.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

use Akibara\Business\Modules\Forms\Honeypot;
use Akibara\Business\Utilities\RateLimit;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.53.0
 */
final class CaptureRestController {

	private const REST_NAMESPACE = 'akibara/v1';
	private const REST_ROUTE     = '/back-in-stock';

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
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Adaptador HTTP: extrae valores planos, delega en subscribe() y mapea
	 * el resultado a WP_REST_Response. El honeypot responde 200 con la MISMA
	 * forma que un alta real (el bot no distingue).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_params();

		if ( Honeypot::tripped( $params ) ) {
			// EXACTAMENTE la misma shape que un alta real (code+message+201):
			// un bot no puede fingerprintear el señuelo por la respuesta (M4).
			return new WP_REST_Response(
				array(
					'code'    => 'subscribed',
					'message' => 'Listo. Te avisaremos cuando vuelva.',
				),
				201
			);
		}

		$result = $this->subscribe(
			(int) ( $params['product_id'] ?? 0 ),
			sanitize_email( (string) ( $params['email'] ?? '' ) ),
			get_current_user_id() ?: null
		);

		return new WP_REST_Response(
			array(
				'code'    => $result['code'],
				'message' => $result['message'],
			),
			$result['status']
		);
	}

	/**
	 * Núcleo de la captura, libre de WP_REST_*. Orden de guards:
	 * flag → validación → estado de stock → rate-limit → alta.
	 *
	 * @param int      $product_id Producto pedido.
	 * @param string   $email      Email ya pasado por sanitize_email().
	 * @param int|null $user_id    Usuario logueado o null.
	 * @return array{code: string, message: string, status: int}
	 */
	public function subscribe( int $product_id, string $email, ?int $user_id ): array {
		if ( ! BackInStock::capture_enabled() ) {
			return $this->result( 'disabled', 'La lista de espera no está disponible.', 503 );
		}

		// Limita el correo a la columna VARCHAR(100), más estrecha que el dominio
		// aceptado por is_email, para distinguir formato inválido de duplicado UNIQUE.
		if ( '' === $email || strlen( $email ) > 100 || ! is_email( $email ) ) {
			return $this->result( 'invalid_email', 'Ingresa un correo válido.', 400 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $this->result( 'not_found', 'El producto no existe.', 404 );
		}

		// Sólo admite agotados estrictos. onbackorder ya es comprable y devuelve
		// un código propio; el code preventa conserva compatibilidad con el cliente
		// mientras la etiqueta visible sigue al estado comercial.
		if ( StockStatus::is_reservable( $product ) ) {
			$mensaje = Availability::is_preventa( $product )
				? 'Este producto está en preventa: puedes reservarlo ahora.'
				: 'Este producto está a pedido: puedes pedirlo ahora.';
			return $this->result( 'preventa', $mensaje, 409 );
		}
		if ( ! StockStatus::is_out_of_stock( $product ) ) {
			return $this->result( 'in_stock', 'Este producto está disponible: puedes comprarlo ahora.', 409 );
		}

		if ( RateLimit::exceeded( 'bis' ) ) {
			return $this->result( 'rate_limited', 'Demasiados intentos. Vuelve a intentarlo en una hora.', 429 );
		}

		// El 409 already_subscribed revela pertenencia email→producto. Se limita
		// a agotados y llega después de cobrar RateLimit de 12/h/IP. Mantener ese límite
		// es necesario para acotar la exposición del endpoint.
		$existing = $this->repository->find( $product_id, $email );

		if ( $existing instanceof Subscriber ) {
			if ( $existing->is_waiting() ) {
				return $this->result( 'already_subscribed', 'Este correo ya está en la lista de espera.', 409 );
			}
			// notified/unsubscribed/failed → re-alta (el producto volvió a agotarse).
			if ( ! $this->repository->reactivate( $existing->id ) ) {
				return $this->result( 'storage_error', 'No pudimos anotarte. Intenta de nuevo.', 500 );
			}
			// La re-alta es el caso MÁS sensible: puede estar resucitando a
			// alguien que ya se había dado de baja.
			$this->announce( $existing->email, $product_id, $existing->token );
			return $this->result( 'resubscribed', 'Listo. Te avisaremos cuando vuelva.', 200 );
		}

		$token = bin2hex( random_bytes( 32 ) );
		$id    = $this->repository->insert( $product_id, $email, $user_id, $token );

		if ( 0 === $id ) {
			// Un INSERT fallido puede ser una carrera contra el UNIQUE o un
			// error real de escritura. Confirmar la fila evita prometer éxito
			// cuando la base de datos no persistió nada.
			if ( $this->repository->find( $product_id, $email ) instanceof Subscriber ) {
				return $this->result( 'already_subscribed', 'Este correo ya está en la lista de espera.', 409 );
			}
			return $this->result( 'storage_error', 'No pudimos anotarte. Intenta de nuevo.', 500 );
		}

		$this->announce( $email, $product_id, $token );

		return $this->result( 'subscribed', 'Listo. Te avisaremos cuando vuelva.', 201 );
	}

	/**
	 * Anuncia el alta por action para que SubscriptionNotice informe al titular
	 * y entregue el enlace de baja. El endpoint público no verifica previamente
	 * la titularidad del correo; honeypot y RateLimit sólo acotan volumen.
	 * La action mantiene subscribe() independiente del transporte de correo.
	 *
	 * @param string $email      Email dado de alta.
	 * @param int    $product_id Producto suscrito.
	 * @param string $token      Token de baja de esa suscripción.
	 * @return void
	 */
	private function announce( string $email, int $product_id, string $token ): void {
		/**
		 * Se dio de alta (o re-alta) una suscripción a la lista de espera.
		 *
		 * @since 1.99.0
		 *
		 * @param string $email      Email del suscriptor.
		 * @param int    $product_id Producto agotado.
		 * @param string $token      Token de baja one-click.
		 */
		do_action( 'akb_bis_subscribed', $email, $product_id, $token );
	}

	/**
	 * @param string $code    Código estable para el front.
	 * @param string $message Mensaje humano (tuteo, copy de la casa).
	 * @param int    $status  HTTP status.
	 * @return array{code: string, message: string, status: int}
	 */
	private function result( string $code, string $message, int $status ): array {
		return array(
			'code'    => $code,
			'message' => $message,
			'status'  => $status,
		);
	}
}
