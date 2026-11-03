<?php
/**
 * Envía el aviso transaccional de preventa lista al cliente.
 *
 * Una acción única de Action Scheduler por pedido se agenda desde el cron o
 * una transición operacional. El dispatcher inicia el mailer dentro del worker.
 * El asunto se edita en los ajustes de correo de WooCommerce y el contenido
 * muestra las líneas pendientes según PreventaItems. Sólo se envía mientras el
 * pedido está en processing; un pedido terminado o cancelado queda excluido.
 * No incorpora un botón adicional de WhatsApp: el contacto se ofrece desde
 * las superficies de seguimiento del pedido.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Services\AkibaraEmail;
use Akibara\Business\Utilities\Log;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

final class ReadyToShipEmailDispatchLockException extends \RuntimeException {}

/**
 * El marker de idempotencia no quedó en el almacenamiento.
 *
 * Se trata como fallo PRE-transporte: no se envía, se cuenta contra el
 * presupuesto de reintentos y se relanza. Enviar sin marker durable reabriría el
 * doble despacho AS‖cron que ese marker existe para cerrar.
 */
final class ReadyToShipEmailMarkerNotPersistedException extends \RuntimeException {}

/**
 * Email transaccional standalone "preventa lista".
 *
 * No marcado `final` para permitir override desde tests (partial spy del send)
 * y futura extensión en themes/plugins downstream.
 *
 * @since 1.15.0
 */
class ReadyToShipEmail extends AkibaraEmail {

	public const EMAIL_ID = 'akb_preventa_ready';

	public function __construct() {
		$this->id             = self::EMAIL_ID;
		$this->customer_email = true;
		$this->title          = 'Akibara · Preventa lista';
		$this->description    = 'Se envía al cliente cuando los productos en preventa de su pedido ya están en stock y listos para envío. Disparado por el cron diario o por cambio de stock manual.';
		$this->template_html  = 'emails/customer-preventa-ready.php';
		$this->template_plain = 'emails/plain/customer-preventa-ready.php';
		$this->template_base  = trailingslashit( AKIBARA_BUSINESS_DIR ) . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{site_title}'   => $this->get_blogname(),
		);

		// Required by WC_Email base init.
		parent::__construct();
	}

	/**
	 * @return string
	 */
	public function get_default_subject(): string {
		// Sin emoji ni signos de apertura shouty: house rule de copy (sentence case,
		// sin emoji en subject). El 📦 previo era la única violación del estándar.
		return 'Tu pedido {order_number} ya llegó a Akibara';
	}

	/**
	 * @return string
	 */
	public function get_default_heading(): string {
		return 'Tu pedido ya está en Akibara';
	}

	/**
	 * Ejecuta el despacho único de Action Scheduler.
	 *
	 * Guards:
	 *   - Order exists.
	 *   - Order has billing email.
	 *   - Order status === 'processing' (no on-hold, no cancelled, no completed).
	 *     Si el owner ya despachó manualmente antes del flip, el email
	 *     "ya llegó" sería redundante.
	 *   - has_preventa === 'yes' (safety net — el hook solo dispara desde
	 *     process_order que ya validó esto, pero defendemos contra
	 *     llamadas externas al hook).
	 *
	 * @param int $order_id ID de la orden recién marcada lista.
	 * @return void
	 */
	public function trigger( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! $this->can_trigger_for_order( $order ) ) {
			// La nota se escribe dentro del candado compartido por worker y reintento,
			// para evitar que dos procesos observen la misma marca vacía.

			if ( $this->acquire_dispatch_lock( $order_id ) ) {
				try {
					// Tras reclamar el candado se relee el pedido: una copia cargada antes de
					// esperar puede conservar una marca vacía aunque otro worker ya la persistió.

					$fresco = wc_get_order( $order_id );
					if ( $fresco instanceof WC_Order ) {
						$this->note_suppressed_because_departed( $fresco );
					}
				} finally {
					$this->release_dispatch_lock( $order_id );
				}
			}
			return;
		}

		if ( ! $this->acquire_dispatch_lock( $order_id ) ) {
			throw new ReadyToShipEmailDispatchLockException( 'Ready-to-ship email dispatch lock is held.' );
		}

		try {
			// Releer después del claim: otro worker pudo terminar justo antes de
			// adquirir el lock, dejando el marker persistente para esta pasada.
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || ! $this->can_trigger_for_order( $order ) ) {
				return;
			}

			$this->object = $order;
			$this->recipient = $order->get_billing_email();
			$this->placeholders['{order_number}'] = '#' . $order->get_order_number();

			// Construir el contenido con el locale activo antes de persistir el marker.
			// Una excepción de plantilla o filtro previa al transporte debe dejar abierto
			// el presupuesto de recuperación, sin afirmar que el correo fue entregado.

			$marked = false;

			try {
				// `setup_locale()` va DENTRO del tramo protegido: cambia el locale
				// del sitio y engancha `plugin_locale` antes de terminar, así que
				// una excepción a mitad de camino dejaba el worker en el idioma
				// del cliente sin nadie que lo restaurara [Codex P1-A].
				$this->setup_locale();

				// Se resuelve todo el mensaje antes de marcar: hasta acá NADA
				// se entregó, así que una excepción deja el marker ausente y el
				// reintento automático es seguro. El locale tiene que estar
				// activo ya — asunto y cuerpo se traducen.
				$recipient   = $this->get_recipient();
				$subject     = $this->get_subject();
				$content     = $this->get_content();
				$headers     = $this->get_headers();
				$attachments = $this->get_attachments();

				// Marca ANTES del send (patrón T-7): ante fallo intermitente del proveedor
				// preferimos no spammear con reintentos del doble dispatch AS‖cron.
				$stamp = time();
				$order->update_meta_data( MetaKeys::ORDER_READY_EMAIL_SENT, $stamp );
				$order->save();

				// `WC_Order::save()` devuelve el ID incluso si su data store
				// capturó una excepción interna, así que "guardé" no equivale a
				// "quedó guardado". Sin marker DURABLE el guard de idempotencia
				// no existe y el doble despacho AS‖cron vuelve a estar abierto:
				// se relee del almacenamiento, sin cache, antes de entregar nada
				// [Codex P1-D].
				if ( ! $this->marker_is_durable( $order_id, $stamp ) ) {
					throw new ReadyToShipEmailMarkerNotPersistedException(
						sprintf( 'El marker de despacho no persistió para el pedido %d.', $order_id )
					);
				}

				$marked = true;

				$sent = $this->send( $recipient, $subject, $content, $headers, $attachments );

				// Ante rechazo real del transporte se revierte la marca para restaurar la
				// elegibilidad de can_trigger_for_order() y del reenvío controlado.

				if ( false === $sent ) {
					SendFailureRearm::apply(
						$order,
						MetaKeys::ORDER_READY_EMAIL_SENT,
						MetaKeys::ORDER_READY_EMAIL_ATTEMPTS,
						'El email de preventa lista'
					);
				}
			} catch ( \Throwable $e ) {
				// Excepción DESPUÉS de marcar: el transporte pudo haber aceptado
				// el mensaje, así que la entrega es AMBIGUA. El marker se
				// conserva a propósito —borrarlo acá arriesga un doble envío al
				// cliente— y a cambio el caso deja de ser invisible: nota
				// durable en la orden y traza en el log. La recuperación es
				// manual (Acciones del pedido → reenviar), que es justo la vía
				// que `OrderPreventa` ya expone.
				$detail = substr( str_replace( array( "\r", "\n" ), ' ', $e->getMessage() ), 0, 200 );

				if ( $marked ) {
					$order->add_order_note(
						sprintf(
							'El email de preventa lista se entregó al transporte pero el despacho terminó con un error (%s): entrega INCIERTA y sin reintento automático. Confirmar con el cliente y, si no llegó, reenviar desde Acciones del pedido.',
							$detail
						)
					);
					$order->save();
				} else {
					// Sin invocar al transporte el reintento es seguro, pero usa el mismo tope
					// de SendFailureRearm y el contador de fallos del worker. Borrar la marca en
					// memoria antes de guardar evita cerrar la recuperación con una marca que no
					// llegó a hacerse durable. Con transport_calls cero no existe entrega a duplicar.

					$order->delete_meta_data( MetaKeys::ORDER_READY_EMAIL_SENT );

					$attempts = (int) $order->get_meta( MetaKeys::ORDER_READY_EMAIL_ATTEMPTS ) + 1;
					$order->update_meta_data( MetaKeys::ORDER_READY_EMAIL_ATTEMPTS, $attempts );

					if ( $attempts >= SendFailureRearm::MAX_ATTEMPTS ) {
						$order->add_order_note(
							sprintf(
								'El email de preventa lista falló %d veces ANTES de entregar nada (%s): se deja de reintentar automáticamente. Revisar plantilla y filtros, y reenviar desde Acciones del pedido.',
								$attempts,
								$detail
							)
						);
					}

					$order->save();
				}

				Log::warn(
					'preventa',
					sprintf(
						'ReadyToShipEmail lanzó %s en el pedido %d (marker %s): %s',
						get_class( $e ),
						$order_id,
						$marked ? 'persistido, entrega incierta' : 'ausente, reintento seguro',
						$e->getMessage()
					)
				);

				// Se relanza SIEMPRE: si el job terminara en silencio, Action
				// Scheduler lo registraría como completado y nadie vería el fallo.
				throw $e;
			} finally {
				// Todo lo que WooCommerce deja ABIERTO durante un despacho se
				// cierra acá, no sólo el locale: la clase base sólo limpia por el
				// camino normal y este callback puede salir por excepción en
				// cualquier punto [Codex P1-A/B/C].
				//
				// El locale del cliente no puede quedar abierto en el worker: lo
				// hereda todo lo que corra después en el mismo request.
				$this->restore_locale();

				// `WC_Email::get_content()` enciende `$sending`
				// (`class-wc-email.php:863`) y sólo lo apaga `handle_multipart()`
				// desde `phpmailer_init` (`:369`). Si el render lanza —o si el
				// envío se corta antes de que PHPMailer arranque— queda en true, y
				// ese handler está enganchado globalmente: manipularía el AltBody
				// del PRÓXIMO correo del request.
				$this->sending = false;

				// `WC_Email::send()` instala tres filtros de transporte y los
				// retira sólo al retornar (`:1228-1240`). Una excepción dentro de
				// `woocommerce_mail_content` o del callback los deja puestos, y
				// todo correo posterior del request saldría con el remitente y el
				// content-type de ESTE email.
				$this->remove_transport_filters();
			}
		} finally {
			$this->release_dispatch_lock( $order_id );
		}
	}

	private function can_trigger_for_order( WC_Order $order ): bool {
		return FulfillmentCapabilities::can_send_ready_email( $order ) && $this->would_send_regardless_of_status( $order );
	}

	/**
	 * Anota una vez que se omitió el aviso porque el pedido ya salió.
	 *
	 * departed_order_statuses() define la salida y can_send_ready_email() la respeta.
	 * Sólo se anota si el aviso habría sido elegible con otro estado. Un pedido sin
	 * reserva pendiente, ya avisado o con el correo deshabilitado no genera esta
	 * nota. La comprobación no cambia la definición de estados de salida.
	 *
	 * @since 1.116.9
	 *
	 * @param WC_Order $order Pedido cuyo aviso no se despachó.
	 * @return void
	 */
	private function note_suppressed_because_departed( WC_Order $order ): void {
		if ( FulfillmentCapabilities::can_send_ready_email( $order )
			|| ! $order->has_status( FulfillmentCapabilities::departed_order_statuses( $order ) )
			|| ! $this->would_send_regardless_of_status( $order )
			|| 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_EMAIL_DEPARTED_NOTED ) ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				'Aviso «tu pedido ya llegó a Akibara» NO enviado: cuando tocaba despacharlo, el pedido ya estaba en «%s», que cuenta como salida de la tienda. Mientras siga en ese estado no se envía, tampoco con el reenvío manual. Si el cliente todavía no sabe que su pedido llegó, avísale a mano.',
				wc_get_order_status_name( $order->get_status() )
			)
		);
		$order->update_meta_data( MetaKeys::ORDER_READY_EMAIL_DEPARTED_NOTED, 'yes' );
		$order->save();
	}

	/**
	 * Las guardas del aviso que NO dependen del estado del pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	private function would_send_regardless_of_status( WC_Order $order ): bool {
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return false;
		}
		if ( ! PreventaItems::has_outstanding_items( $order ) ) {
			return false;
		}
		if ( '' !== (string) $order->get_meta( MetaKeys::ORDER_READY_EMAIL_SENT ) ) {
			return false;
		}
		// Al agotar intentos, la ausencia de marker no autoriza más reintentos.
		// El reenvío manual borra contador y marca para abrir un presupuesto nuevo.

		if ( (int) $order->get_meta( MetaKeys::ORDER_READY_EMAIL_ATTEMPTS ) >= SendFailureRearm::MAX_ATTEMPTS ) {
			return false;
		}
		if ( '' === $order->get_billing_email() ) {
			return false;
		}

		return $this->is_enabled();
	}

	/**
	 * ¿El marker quedó realmente en el almacenamiento?
	 *
	 * Lectura DIRECTA y sin cache de objetos: el punto de la comprobación es
	 * detectar un `save()` que aparentó éxito, y releer desde la misma cache que
	 * el `save()` pudo haber calentado no probaría nada. Cubre las dos
	 * topologías vigentes de WooCommerce (HPOS y el legado en `postmeta`).
	 *
	 * **Fail-CLOSED**: sin evidencia positiva de persistencia se responde `false`
	 * —incluido el caso de error de lectura o `$wpdb` ausente— y el despacho se
	 * trata como fallo pre-transporte. La asimetría es deliberada: un correo
	 * diferido es recuperable (queda presupuesto de reintentos y el reenvío
	 * manual), un doble envío al cliente no lo es.
	 *
	 * `protected` a propósito: las pruebas unitarias lo fuerzan para ejercitar
	 * las dos ramas sin base de datos, y el gate de integración ejercita ESTA
	 * implementación contra MariaDB.
	 *
	 * @param int $order_id Pedido.
	 * @param int $expected Valor que se acaba de escribir.
	 * @return bool
	 */
	protected function marker_is_durable( int $order_id, int $expected ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false; // sin almacenamiento que consultar no hay evidencia.
		}

		$hpos = \Akibara\Business\Utilities\OrderStorage::uses_hpos();

		$wpdb->last_error = '';

		if ( $hpos ) {
			$stored = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1",
					$order_id,
					MetaKeys::ORDER_READY_EMAIL_SENT
				)
			);
		} else {
			$stored = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
					$order_id,
					MetaKeys::ORDER_READY_EMAIL_SENT
				)
			);
		}

		if ( null === $stored && '' !== (string) $wpdb->last_error ) {
			Log::warn(
				'preventa',
				sprintf(
					'No se pudo verificar el marker de despacho del pedido %d (%s): NO se envía; queda para el reintento.',
					$order_id,
					$wpdb->last_error
				)
			);
			return false;
		}

		return (string) $expected === (string) $stored;
	}

	/**
	 * Retira los tres filtros que `WC_Email::send()` instala.
	 *
	 * Idempotente: si el envío terminó normalmente, la clase base ya los quitó y
	 * estos `remove_filter()` no hacen nada. Sólo importan cuando el despacho
	 * salió por excepción [Codex P1-C].
	 *
	 * @return void
	 */
	private function remove_transport_filters(): void {
		remove_filter( 'wp_mail_from', array( $this, 'get_from_address' ) );
		remove_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ) );
		remove_filter( 'wp_mail_content_type', array( $this, 'get_content_type' ) );
	}

	private function acquire_dispatch_lock( int $order_id ): bool {
		global $wpdb;

		$lock_name = $this->dispatch_lock_name( $order_id );
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
	}

	private function release_dispatch_lock( int $order_id ): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->dispatch_lock_name( $order_id ) ) );
	}

	private function dispatch_lock_name( int $order_id ): string {
		return 'akb-ready-email-' . $order_id;
	}

	/**
	 * HTML body — usado por WC_Email::get_content().
	 *
	 * @return string
	 */
	/**
	 * Args del template, HTML y plain por igual (esqueleto en AkibaraEmail).
	 *
	 * @param bool $plain_text Versión plain.
	 * @return array<string, mixed>
	 */
	protected function template_args( bool $plain_text ): array {
		return array(
			'order'              => $this->object,
			'preventa_items'     => PreventaItems::outstanding_items( $this->object ),
			'is_local_pickup'    => $this->order_is_local_pickup( $this->object ),
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * Copy propio de `WooCommerce → Ajustes → Emails → Akibara · Preventa lista`.
	 * El esqueleto vive en AkibaraEmail.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function form_field_copy(): array {
		return array(
			'subject'            => array(
				'description' => 'Asunto del email. Placeholders: {order_number}, {site_title}.',
				'placeholder' => $this->get_default_subject(),
			),
			'heading'            => array(
				'placeholder' => $this->get_default_heading(),
			),
		);
	}

	/**
	 * ¿La orden es retiro (sin transportista) y no despacho a domicilio?
	 *
	 * La detección se delega al contrato de fulfillment: por defecto usa los
	 * métodos de retiro que WooCommerce registra en
	 * `woocommerce_local_pickup_methods`; una integración puede extenderlo sin
	 * acoplar este email a su plugin.
	 *
	 * @param WC_Order $order
	 * @return bool true si TODO el envío es retiro/entrega propia (sin transporte).
	 */
	protected function order_is_local_pickup( WC_Order $order ): bool {
		return FulfillmentCapabilities::is_local_pickup_order( $order );
	}
}
