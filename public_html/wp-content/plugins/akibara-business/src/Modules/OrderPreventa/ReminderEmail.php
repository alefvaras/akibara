<?php
/**
 * Email transaccional intermedio durante la espera de un tomo a pedido o en
 * preventa.
 *
 * Maneja 3 modos en una sola clase para minimizar duplicación de WC_Email
 * boilerplate y mantener el form_fields del wp-admin centralizado:
 *
 *   - `t7`             Aviso previo a la llegada («tu pedido se acerca»).
 *                      Dispara desde `RemindersCron` via action
 *                      `akb_order_preventa_t7_reminder`.
 *
 *   - `date_changed`   La editorial movió la fecha de salida (postergación de
 *                      `Settings::OPT_DATE_CHANGE_THRESHOLD` días o más, o
 *                      adelanto con el toggle ON). Dispara desde
 *                      `ReleaseDateChangeListener` via action
 *                      `akb_order_preventa_date_changed`.
 *
 *   - `date_announced` Primera fecha de salida de un tomo que no la tenía.
 *                      Es buena noticia sólo si la línea se compró SIN fecha
 *                      (`tba`); a un tomo a pedido —el caso normal— le llega
 *                      como un cambio de fecha. La PALABRA (pedido/preventa)
 *                      sigue a cómo se compró (`PreventaItems`). Dispara desde
 *                      `ReleaseDateChangeListener` via action
 *                      `akb_order_preventa_date_announced`.
 *
 * Cap por FRECUENCIA asimétrico por diseño (colisión de cap del gate):
 * `date_changed` lee/escribe SOLO su marca; `date_announced` lee el max() de
 * AMBAS marcas pero escribe solo la propia — una postergación (material bajo
 * Ley 21.719) jamás queda suprimida por un anuncio previo, y la buena noticia
 * cede ante cualquier email reciente.
 *
 * Estado inmutable por envío: `reset_send_state()` al tope de los TRES
 * triggers elimina la clase "residuo entre sends sobre la instancia
 * compartida de WC_Emails" (antes `date_change_ctx` sobrevivía a trigger_t7).
 *
 * NO lleva ctor-DI: WC instancia las clases de email él mismo vía
 * `woocommerce_email_classes` — un constructor con argumentos obligatorios
 * rompe ese ciclo de vida.
 *
 * Diseño basado en research industry (Smart Pre-Order Manager + Crunchyroll +
 * Norma + Klaviyo benchmarks): mantener strictly transaccional (sin upsell,
 * sin emojis decorativos en subject, sin promos). Conformidad Ley 19.628 CL
 * (ejecución de contrato, sin requerir opt-in marketing) + LSSI-CE ES.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Services\AkibaraEmail;
use Akibara\Business\Utilities\StoreTime;
use DateTimeImmutable;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Email "preventa reminder/cambio de fecha".
 *
 * No `final` para permitir override desde tests / themes downstream.
 *
 * @since 1.19.0
 */
class ReminderEmail extends AkibaraEmail {

	public const EMAIL_ID = 'akb_preventa_reminder';

	/**
	 * Modo activo del envío actual. Lo fija `reset_send_state()` al tope de
	 * cada `trigger_*`.
	 *
	 * @var string `t7` | `date_changed` | `date_announced`
	 */
	private string $mode = 't7';

	/**
	 * Contexto default del cambio de fecha (restaurado por `reset_send_state`).
	 *
	 * @var array{product_id:int,product_name:string,old_ymd:string,new_ymd:string}
	 */
	private const DATE_CTX_DEFAULT = array(
		'product_id'   => 0,
		'product_name' => '',
		'old_ymd'      => '',
		'new_ymd'      => '',
	);

	/**
	 * Contexto extra del cambio/anuncio de fecha (modos date_changed y
	 * date_announced; en announced `old_ymd` queda '').
	 *
	 * @var array{product_id:int,product_name:string,old_ymd:string,new_ymd:string}
	 */
	private array $date_change_ctx = self::DATE_CTX_DEFAULT;

	/**
	 * Reloj inyectable (epoch UTC). Default `time()`. Solo lo sobreescriben los
	 * tests para ejercitar el cap por frecuencia sin sleeps. NUNCA usar
	 * `strtotime`: epoch aritmético directo.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * ¿La línea que acaba de recibir fecha se compró de verdad SIN fecha?
	 *
	 * Sólo tiene sentido en modo `date_announced`. Decide el asunto, el
	 * encabezado y la apertura del cuerpo: «que compraste sin fecha confirmada»
	 * es cierto para una preventa `tba`, y falso para un tomo a pedido que ya
	 * tenía un rango de llegada prometido y al que ahora la editorial le pone
	 * fecha de salida — para ese cliente no es «buena noticia», es un cambio.
	 *
	 * @since 1.114.12
	 */
	private bool $announced_line_was_tba = false;

	public function __construct() {
		$this->clock = static fn(): int => time();
		$this->id             = self::EMAIL_ID;
		$this->customer_email = true;
		$this->title          = 'Akibara · Preventa recordatorio';
		$this->description    = 'Se envía al cliente mientras espera tomos a pedido o en preventa. Tres modos: recordatorio unos días antes del arribo estimado; cambio de fecha cuando la editorial posterga la salida (por defecto, retrasos de 7 días o más; configurable en Pedidos y preventa · Ajustes); y fecha anunciada cuando un tomo que no tenía fecha de salida la recibe. El asunto dice «tu preventa» sólo si el cliente compró una preventa de verdad; si compró a pedido, dice «tu pedido». Un solo envío por pedido en el primero; en los otros dos, tope de frecuencia por pedido.';
		$this->template_html  = 'emails/customer-preventa-reminder.php';
		$this->template_plain = 'emails/plain/customer-preventa-reminder.php';
		$this->template_base  = trailingslashit( AKIBARA_BUSINESS_DIR ) . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{site_title}'   => $this->get_blogname(),
		);

		// Hooks `_notification`: ver comentario en ReadyToShipEmail — las actions
		// crudas van por woocommerce_email_actions y WC re-dispara con sufijo,
		// garantizando mailer booteado en cron. Escuchar la cruda = doble envío.
		add_action( 'akb_order_preventa_t7_reminder_notification',    array( $this, 'trigger_t7' ), 10, 1 );
		add_action( 'akb_order_preventa_date_changed_notification',   array( $this, 'trigger_date_changed' ), 10, 4 );
		add_action( 'akb_order_preventa_date_announced_notification', array( $this, 'trigger_date_announced' ), 10, 4 );

		parent::__construct();
	}

	/**
	 * Inyecta el reloj (tests). En producción se usa el default `time()`.
	 *
	 * @param callable(): int $clock
	 * @return void
	 */
	public function set_clock( callable $clock ): void {
		$this->clock = $clock;
	}

	/**
	 * Asunto por modo. La palabra sigue a cómo se compró: «tu preventa» sólo si
	 * alguna línea en espera lo es de verdad. Decía «preventa» a fuego, y las 20
	 * líneas en espera de la tienda () eran a pedido.
	 *
	 * @return string
	 */
	public function get_default_subject(): string {
		return match ( $this->mode ) {
			'date_changed'   => $this->order_has_real_preventa()
				? self::SUBJECT_DATE_CHANGED_PREVENTA
				: self::SUBJECT_DATE_CHANGED_PEDIDO,
			// El asunto sigue a cómo se compró la línea y a su fecha de compra.
			// TBA por pausa editorial de A pedido no convierte la línea en Preventa.
			// Una fecha retirada y reanunciada se comunica como cambio si ya existía al
			// comprar; asunto y cuerpo no deben celebrar y lamentar el mismo evento.

			'date_announced' => $this->order_has_real_preventa()
				? ( $this->announced_line_was_tba ? self::SUBJECT_DATE_ANNOUNCED_PREVENTA : self::SUBJECT_DATE_CHANGED_PREVENTA )
				: self::SUBJECT_DATE_ANNOUNCED_PEDIDO,
			default          => 'Tu pedido {order_number} se acerca',
		};
	}

	/**
	 * Un asunto tal como lo describe la pantalla «Por llegar»: sin el número
	 * de pedido, que allí no se conoce. Así la pantalla no copia el texto a
	 * mano y deja de mentir el día que el asunto cambie.
	 *
	 * @since 1.114.16
	 *
	 * @param string $subject Una de las constantes SUBJECT_*.
	 * @return string
	 */
	public static function subject_for_screen( string $subject ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', str_replace( ' {order_number}', '', $subject ) ) );
	}

	/** Asuntos por modo y por palabra. `{order_number}` lo rellena WooCommerce. */
	public const SUBJECT_DATE_CHANGED_PREVENTA   = 'Actualización de tu preventa {order_number}: nueva fecha';
	public const SUBJECT_DATE_CHANGED_PEDIDO     = 'Actualización de tu pedido {order_number}: cambio de fecha';
	public const SUBJECT_DATE_ANNOUNCED_PREVENTA = '¡Ya tenemos fecha para tu preventa {order_number}!';
	public const SUBJECT_DATE_ANNOUNCED_PEDIDO   = 'Tu pedido {order_number}: la editorial anunció fecha de salida';

	public function get_default_heading(): string {
		return match ( $this->mode ) {
			'date_changed'   => $this->order_has_real_preventa()
				? 'Cambio de fecha en tu preventa'
				: 'Cambio de fecha en tu pedido',
			// Misma regla que el asunto: sólo es «ya tiene fecha» si se compró sin ella.
			'date_announced' => $this->order_has_real_preventa()
				? ( $this->announced_line_was_tba ? 'Tu pedido ya tiene fecha' : 'Cambio de fecha en tu preventa' )
				: 'La editorial anunció fecha de salida',
			default          => 'Tu pedido llega pronto',
		};
	}

	/**
	 * Fuera de un envío (la pantalla de ajustes de WooCommerce) no hay pedido:
	 * se cae a la palabra neutra.
	 *
	 * @return bool
	 */
	private function order_has_real_preventa(): bool {
		return $this->object instanceof WC_Order && PreventaItems::has_real_preventa( $this->object );
	}

	/**
	 * Nombre de la línea del pedido para ese producto ('' si no está).
	 *
	 * @param WC_Order $order      Pedido.
	 * @param int      $product_id Producto.
	 * @return string
	 */
	private function line_name( WC_Order $order, int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && (int) $item->get_product_id() === $product_id ) {
				return (string) $item->get_name();
			}
		}
		return '';
	}

	/**
	 * @param WC_Order $order      Pedido.
	 * @param int      $product_id Producto que acaba de recibir fecha.
	 * @return bool
	 */
	private function line_was_tba( WC_Order $order, int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_product_id() !== $product_id ) {
				continue;
			}
			if ( 'yes' === (string) $item->get_meta( MetaKeys::ITEM_IS_TBA_AT_PURCHASE ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Subject efectivo por modo (Bug 3, locked #3 — sin campos de form nuevos).
	 *
	 * El campo "Asunto (modo T-7d)" del form pertenece SOLO al modo t7: WC lee
	 * la option guardada en vivo para todos los modos (`WC_Email::get_subject`
	 * → `get_option('subject', default)`), así que un asunto personalizado
	 * para el T-7 salía también en los emails de cambio/anuncio de fecha. Los
	 * modos date_changed/date_announced usan su default literal mode-aware +
	 * el filtro estándar de WC (`woocommerce_email_subject_{id}`), que sigue
	 * vivo para los 3 modos. Nota consciente: el path no-t7 omite la
	 * personalización del block-email-editor — email custom clásico, no aplica.
	 *
	 * @return string
	 */
	public function get_subject(): string {
		if ( 't7' === $this->mode ) {
			// La option del admin aplica; '' cae al default (contrato WC).
			return parent::get_subject();
		}

		return (string) apply_filters(
			'woocommerce_email_subject_' . $this->id, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de WooCommerce core: se re-aplica con su nombre original para que los callbacks de terceros sigan viéndolo.
			$this->format_string( $this->get_default_subject() ),
			$this->object,
			$this
		);
	}

	/**
	 * Heading efectivo por modo — espejo de `get_subject()` (ver su docblock).
	 *
	 * @return string
	 */
	public function get_heading(): string {
		if ( 't7' === $this->mode ) {
			return parent::get_heading();
		}

		return (string) apply_filters(
			'woocommerce_email_heading_' . $this->id, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de WooCommerce core: se re-aplica con su nombre original para que los callbacks de terceros sigan viéndolo.
			$this->format_string( $this->get_default_heading() ),
			$this->object,
			$this
		);
	}

	/**
	 * Estado inmutable por envío: restaura la instancia compartida de
	 * WC_Emails a un estado conocido ANTES de cada trigger. Elimina la clase
	 * "residuo entre sends" (p.ej. `date_change_ctx` de un date_changed
	 * sobreviviendo a un trigger_t7 posterior) en vez de razonar caso a caso
	 * qué residuo es benigno.
	 *
	 * @param string   $mode  Modo del envío entrante.
	 * @param WC_Order $order Orden objetivo.
	 * @return void
	 */
	private function reset_send_state( string $mode, WC_Order $order ): void {
		$this->mode            = $mode;
		$this->object          = $order;
		$this->date_change_ctx = self::DATE_CTX_DEFAULT;
		$this->recipient       = '';

		$this->announced_line_was_tba = false;

		$this->placeholders['{order_number}'] = '#' . $order->get_order_number();
	}

	/**
	 * Envía el recordatorio T-7d con marca previa e información de resultado real.
	 *
	 * La marca t7_reminder_sent se guarda antes de send() para cortar despachos
	 * concurrentes. Si el transporte devuelve false se revierte para permitir
	 * recuperación controlada. La nota afirmativa se escribe sólo después de aceptar
	 * el envío; no debe coexistir con una nota que confirme que el correo no salió.
	 *
	 * @param int $order_id
	 * @return void
	 */
	public function trigger_t7( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->reset_send_state( 't7', $order );

		if ( ! $this->order_passes_guards( $order ) ) {
			return;
		}

		$recipient = $order->get_billing_email();
		if ( '' === $recipient ) {
			return;
		}
		$this->recipient = $recipient;

		if ( ! $this->is_enabled() ) {
			return;
		}

		// El MARKER va antes del send (anti doble dispatch). La NOTA no: se
		// redacta recién con el resultado en la mano — ver docblock.
		$order->update_meta_data( MetaKeys::ORDER_T7_REMINDER_SENT, time() );
		$order->save();

		$this->setup_locale();
		$sent = $this->send(
			$this->get_recipient(),
			$this->get_subject(),
			$this->get_content(),
			$this->get_headers(),
			$this->get_attachments()
		);
		$this->restore_locale();

		if ( false === $sent ) {
			SendFailureRearm::apply(
				$order,
				MetaKeys::ORDER_T7_REMINDER_SENT,
				MetaKeys::ORDER_T7_REMINDER_ATTEMPTS,
				'El recordatorio T-7d'
			);
			return;
		}

		// Redactar la nota afirmativa después de aceptar el envío. add_order_note()
		// la inserta inmediatamente y no requiere save() adicional sin meta pendiente.
		// La marca se guarda antes del transporte para impedir despachos concurrentes.

		$order->add_order_note( 'Recordatorio T-7d enviado al cliente.' );
	}

	/**
	 * Trigger del modo `date_changed`. La meta de signature ya la persistió
	 * `ReleaseDateChangeListener` antes de disparar — acá solo enviamos.
	 *
	 * Cap: lee y escribe SOLO `ORDER_DATE_CHANGE_LAST_NOTIFIED_AT` — una
	 * postergación jamás es suprimida por un anuncio previo.
	 *
	 * @param int    $order_id
	 * @param int    $product_id
	 * @param string $old_ymd
	 * @param string $new_ymd
	 * @return void
	 */
	public function trigger_date_changed( $order_id, $product_id, $old_ymd, $new_ymd ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->dispatch_date_email(
			'date_changed',
			$order,
			array(
				'product_id' => (int) $product_id,
				'old_ymd'    => (string) $old_ymd,
				'new_ymd'    => (string) $new_ymd,
			),
			array( MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT ),
			MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT,
			MetaKeys::ORDER_DATE_CHANGE_SEND_ATTEMPTS
		);
	}

	/**
	 * Trigger del modo `date_announced` (TBA→fecha, buena noticia).
	 *
	 * Cap asimétrico: la ventana se evalúa contra el max() de AMBAS
	 * marcas (la buena noticia cede ante CUALQUIER email reciente — cumple el
	 * "respeta el cap de frecuencia" locked) pero escribe SOLO la propia:
	 * `ORDER_DATE_ANNOUNCED_LAST_NOTIFIED_AT`.
	 *
	 * @param int    $order_id
	 * @param int    $product_id
	 * @param string $old_ymd Siempre '' en este evento.
	 * @param string $new_ymd
	 * @return void
	 */
	public function trigger_date_announced( $order_id, $product_id, $old_ymd, $new_ymd ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->dispatch_date_email(
			'date_announced',
			$order,
			array(
				'product_id' => (int) $product_id,
				'old_ymd'    => (string) $old_ymd,
				'new_ymd'    => (string) $new_ymd,
			),
			array(
				MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT,
				MetaKeys::ORDER_DATE_ANNOUNCED_LAST_NOTIFIED_AT,
			),
			MetaKeys::ORDER_DATE_ANNOUNCED_LAST_NOTIFIED_AT,
			MetaKeys::ORDER_DATE_ANNOUNCED_SEND_ATTEMPTS
		);
	}

	/**
	 * Secuencia común de los avisos de fecha.
	 *
	 * Reinicia estado de envío, aplica guardas, destinatario y habilitación; compara
	 * la ventana con max(cap_read_keys), escribe cap_write_key, envía y finalmente
	 * registra la nota de resultado. La nota afirmativa requiere aceptación real
	 * del transporte. Ante rechazo se revierte la marca para no consumir una
	 * ventana de un mensaje que no salió.
	 *
	 * El límite de frecuencia se aplica en el punto efectivo de envío asíncrono,
	 * no al agendar el evento. La firma y la nota del evento pertenecen al listener.
	 * ReleaseDateChangeListener serializa las pasadas automáticas con un candado
	 * global. El reenvío manual de ReadyEmailDispatcher no adquiere ese candado:
	 * si se solapa con un worker puede duplicar el mensaje; este límite de
	 * concurrencia es distinto de la deduplicación de firmas.
	 *
	 * @param string                                              $mode          `date_changed` | `date_announced`.
	 * @param WC_Order                                            $order         Orden objetivo.
	 * @param array{product_id:int,product_name:string,old_ymd:string,new_ymd:string} $ctx           Contexto del template.
	 * @param array<int, string>                                  $cap_read_keys Claves cuya marca max() gatea la ventana.
	 * @param string                                              $cap_write_key Clave que se marca al enviar (solo la propia).
	 * @param string                                              $attempts_key  Contador de intentos fallidos del modo.
	 * @return void
	 */
	private function dispatch_date_email( string $mode, WC_Order $order, array $ctx, array $cap_read_keys, string $cap_write_key, string $attempts_key ): void {
		$this->reset_send_state( $mode, $order );
		// El nombre del tomo, para que el correo diga CUÁL cambió: en un pedido
		// de varias líneas «un tomo de tu pedido» no le decía nada al cliente.
		$ctx['product_name']   = $this->line_name( $order, (int) ( $ctx['product_id'] ?? 0 ) );
		$this->date_change_ctx = $ctx;

		$this->announced_line_was_tba = 'date_announced' === $mode
			&& $this->line_was_tba( $order, (int) ( $ctx['product_id'] ?? 0 ) );

		if ( ! $this->order_passes_guards( $order ) ) {
			return;
		}

		$recipient = $order->get_billing_email();
		if ( '' === $recipient ) {
			return;
		}
		$this->recipient = $recipient;

		if ( ! $this->is_enabled() ) {
			// Si el correo está apagado, dejar nota compensatoria sin consumir la marca
			// de frecuencia. No se agenda un reenvío automático: reactivar permite avisar
			// de un cambio nuevo, no reenviar por sí solo este evento ya registrado.

			$order->add_order_note(
				sprintf(
					'%s NO enviado al cliente: el correo «%s» está deshabilitado en WooCommerce → Ajustes → Emails.',
					'date_announced' === $mode ? 'Email de fecha anunciada' : 'Email de cambio de fecha',
					$this->title
				)
			);
			$order->save();
			return;
		}

		$now = (int) ( $this->clock )();

		if ( self::within_notify_window( $order, $cap_read_keys, $now ) ) {
			$order->add_order_note(
				sprintf(
					'%s NO re-notificado al cliente: dentro de la ventana de %d días desde el último aviso.',
					'date_announced' === $mode ? 'Aviso de fecha anunciada' : 'Cambio de fecha',
					Settings::get_int( Settings::OPT_DATE_CHANGE_NOTIFY_WINDOW )
				)
			);
			$order->save();
			return;
		}

		// Igual que en `trigger_t7()`: la marca del cap se commitea antes del
		// send (es la que corta el doble dispatch), la NOTA después.
		$order->update_meta_data( $cap_write_key, $now );
		$order->save();

		$this->setup_locale();
		$sent = $this->send(
			$this->get_recipient(),
			$this->get_subject(),
			$this->get_content(),
			$this->get_headers(),
			$this->get_attachments()
		);
		$this->restore_locale();

		if ( false === $sent ) {
			SendFailureRearm::apply(
				$order,
				$cap_write_key,
				$attempts_key,
				'date_announced' === $mode
					? 'El email de fecha anunciada'
					: 'El email de cambio de fecha'
			);
			return;
		}

		// Sin `save()`: ver `trigger_t7()`.
		$order->add_order_note(
			'date_announced' === $mode
				? 'Email de fecha anunciada enviado al cliente.'
				: 'Email de cambio de fecha enviado al cliente.'
		);
	}

	/**
	 * ¿A este pedido le cae AHORA el tope de frecuencia?
	 *
	 * Una sola implementación del cap, para que la pantalla «Por llegar» pueda
	 * decir la verdad antes de que el dueño pulse «Guardar»: prometía «a N
	 * personas les llegará el correo» y el cap se lo comía a la mitad. Recibe
	 * el reloj por parámetro: el dispatcher usa el suyo inyectable, la pantalla
	 * `time()`.
	 *
	 * @since 1.114.13
	 *
	 * @param WC_Order           $order         Pedido.
	 * @param array<int, string> $cap_read_keys Claves cuya marca max() gatea la ventana.
	 * @param int                $now           Instante de la decisión.
	 * @return bool true = dentro de la ventana, NO se manda.
	 */
	public static function within_notify_window( WC_Order $order, array $cap_read_keys, int $now ): bool {
		$window_secs = Settings::get_int( Settings::OPT_DATE_CHANGE_NOTIFY_WINDOW ) * DAY_IN_SECONDS;

		$last_notified = 0;
		foreach ( $cap_read_keys as $key ) {
			$last_notified = max( $last_notified, (int) $order->get_meta( $key ) );
		}

		// Ausente (get_meta = 0) => primer aviso pasa.
		return $last_notified > 0 && ( $now - $last_notified ) < $window_secs;
	}

	/**
	 * Guards comunes a los tres modos: estado de espera declarado por fulfillment
	 * + has_preventa=yes + NOT ready_to_ship. Si ya está lista, los tres son irrelevantes (el
	 * cliente recibió o recibirá inmediatamente el ReadyToShipEmail).
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private function order_passes_guards( WC_Order $order ): bool {
		if ( ! $order->has_status( FulfillmentCapabilities::waiting_order_statuses( $order ) ) ) {
			return false;
		}
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return false;
		}
		if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
			return false;
		}
		if ( ! PreventaItems::has_outstanding_items( $order ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Args comunes para template HTML y plain.
	 *
	 * @return array<string, mixed>
	 */
	protected function template_args( bool $plain_text ): array {
		return array(
			'order'              => $this->object,
			'mode'               => $this->mode,
			// Sólo lo que SIGUE SIN LLEGAR: un tomo reembolsado del todo no se
			// anuncia, y uno que ya está en bodega tampoco «está por llegar» (el
			// T-7 y los avisos de fecha listaban ese tomo bajo un texto que decía
			// lo contrario, mientras la página del pedido decía «Ya llegó»).
			'preventa_items'     => PreventaItems::pending_items( $this->object ),
			'summary_max_ymd'    => $this->summary_max_ymd( $this->object ),
			// El arribo del tomo que CAMBIÓ, no el máximo del pedido: con dos
			// tomos, «Te llegaría alrededor del 1 de diciembre» era la fecha del
			// otro, y el cliente leía un atraso de dos meses donde había diez días.
			'item_max_ymd'       => $this->item_max_ymd( $this->object, (int) ( $this->date_change_ctx['product_id'] ?? 0 ) ),
			'date_change_ctx'    => $this->date_change_ctx,
			'bought_without_date' => $this->announced_line_was_tba,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * Form fields wp-admin → WooCommerce → Ajustes → Emails → este email.
	 *
	 * Notar: solo expone 1 set de subject/heading y pertenece SOLO al modo
	 * t7 — los overrides `get_subject()`/`get_heading()` garantizan que los
	 * modos `date_changed`/`date_announced` usen su default literal propio
	 * (con la option guardada, WC la aplicaría en vivo a TODOS los modos).
	 * Para ajustar el copy de esos modos: filters estándar de WC
	 * (`woocommerce_email_subject_akb_preventa_reminder` /
	 * `woocommerce_email_heading_akb_preventa_reminder`) o template override.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function form_field_copy(): array {
		return array(
			'subject'            => array(
				'title'       => 'Asunto (aviso previo a la llegada)',
				'description' => 'Asunto del email recordatorio. Placeholders: {order_number}, {site_title}. Los modos "cambio de fecha" y "fecha anunciada" usan su texto propio.',
				'placeholder' => 'Tu pedido {order_number} se acerca',
			),
			'heading'            => array(
				'title'       => 'Encabezado (aviso previo a la llegada)',
				'description' => 'Encabezado del email recordatorio. Los modos "cambio de fecha" y "fecha anunciada" usan su texto propio.',
				'placeholder' => 'Tu pedido llega pronto',
			),
			'additional_content' => array(
				'description' => 'Texto opcional al final de los tres modos.',
			),
		);
	}

	/**
	 * `Y-m-d` del summary_max de la orden, '' si no aplicable. Usado por el
	 * template para mostrar la fecha estimada actual en formato amigable.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	/**
	 * `Y-m-d` del arribo que rige para la línea de ese producto, '' si no hay.
	 *
	 * @since 1.114.16
	 *
	 * @param WC_Order $order      Pedido.
	 * @param int      $product_id Producto que cambió de fecha.
	 * @return string
	 */
	private function item_max_ymd( WC_Order $order, int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_product_id() !== $product_id ) {
				continue;
			}
			$max = PreventaItems::promised_window( $item )['max'];
			if ( null === $max ) {
				return '';
			}
			return ( new DateTimeImmutable( '@' . $max ) )->setTimezone( StoreTime::zone() )->format( 'Y-m-d' );
		}
		return '';
	}

	private function summary_max_ymd( WC_Order $order ): string {
		$ts = SummaryRecalculator::current_max_ts( $order );
		if ( null === $ts ) {
			return '';
		}
		$dt = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( StoreTime::zone() );
		return $dt->format( 'Y-m-d' );
	}
}
