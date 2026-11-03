<?php
/**
 * Aviso inmediato al titular del correo dado de alta en un endpoint público.
 * Incluye el token de baja ya emitido por la captura. Se envía síncronamente
 * mediante EmailTemplateGateway, sin depender del flag de despacho ni de un
 * interruptor WC_Email, para que las altas conservadas durante una pausa
 * también reciban su aviso. RateLimit de captura acota el volumen a 12/h/IP.
 * El gateway conserva la marca y la presentación también sin mailer Woo cargado.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

use Akibara\Business\Services\EmailTemplateGateway;
use Akibara\Business\Utilities\Log;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.99.0
 */
final class SubscriptionNotice {

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'akb_bis_subscribed', array( $this, 'send' ), 10, 3 );
	}

	/**
	 * Envía el aviso de alta. Silencioso ante cualquier problema: la
	 * suscripción ya está persistida y el request de captura no debe fallar
	 * por el correo (el fallo queda en el log de la casa).
	 *
	 * @param string $email      Email dado de alta.
	 * @param int    $product_id Producto suscrito.
	 * @param string $token      Token de baja one-click.
	 * @return void
	 */
	public function send( string $email, int $product_id, string $token ): void {
		/**
		 * Permite apagar el aviso de alta (p.ej. durante una migración masiva
		 * de suscriptores, donde no corresponde avisar de cada fila).
		 *
		 * @since 1.99.0
		 *
		 * @param bool   $enabled    Default true.
		 * @param string $email      Email dado de alta.
		 * @param int    $product_id Producto suscrito.
		 */
		if ( ! apply_filters( 'akb_bis_subscribe_notice_enabled', true, $email, $product_id ) ) {
			return;
		}

		if ( '' === $email || '' === $token || ! function_exists( 'wp_mail' ) ) {
			return;
		}

		$product      = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$product_name = $product ? (string) $product->get_name() : ( 'el producto #' . $product_id );
		$product_url  = (string) get_permalink( $product_id );
		$unsub_url    = UnsubscribeHandler::url_for( $token );

		$sent = EmailTemplateGateway::send(
			$email,
			// Sin emoji (house rule de deliverability) y explícito sobre qué
			// pasó, para que quien no se anotó lo entienda del asunto.
			sprintf( 'Te anotamos en la lista de espera de %s', $product_name ),
			'Te anotamos en la lista de espera',
			$this->body_html( $product_name, $product_url, $unsub_url ),
			array(
				'key'     => 'bis_subscription',
				// RFC 8058: mismo one-click nativo de Gmail/Yahoo que usa el
				// aviso de restock.
				'headers' => array(
					'List-Unsubscribe: <' . $unsub_url . '>',
					'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
				),
			)
		);

		if ( ! $sent ) {
			Log::warn(
				'bis',
				sprintf( 'No se pudo enviar el aviso de alta BIS del producto %d.', $product_id )
			);
		}
	}

	/**
	 * Cuerpo del aviso. Markup mínimo e inline (sin template de WC): el correo
	 * tiene que salir aunque el mailer de WC no esté cargado (contexto REST).
	 *
	 * @param string $product_name Nombre del producto.
	 * @param string $product_url  Permalink del producto ('' si no resuelve).
	 * @param string $unsub_url    URL de baja one-click.
	 * @return string
	 */
	private function body_html( string $product_name, string $product_url, string $unsub_url ): string {
		$name_html = '' !== $product_url
			? sprintf( '<a href="%s">%s</a>', esc_url( $product_url ), esc_html( $product_name ) )
			: esc_html( $product_name );

		$body  = '<p>Anotamos este correo en la lista de espera de ' . $name_html . '.</p>';
		$body .= '<p>Te avisamos una sola vez, cuando el producto vuelva a estar disponible.</p>';
		$body .= sprintf(
			'<p><strong>¿No fuiste tú?</strong> <a href="%s">Sácanos de tu correo con un clic</a> y no te escribimos más por este producto.</p>',
			esc_url( $unsub_url )
		);

		return $body;
	}
}
