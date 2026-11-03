<?php
/**
 * Stubs mínimos de WordPress/WooCommerce para la suite de Akibara Rewards.
 *
 * FUENTE ÚNICA de dobles de clase (lección de business 2026-07-28: dobles
 * duplicados por bootstrap se desincronizan). Cada uno bajo class_exists para
 * no chocar con otras suites.
 *
 * Los dobles modelan el comportamiento REAL que el código ejercita — un stub
 * más permisivo que producción esconde fatales vivos (lección de
 * maybe_serialize, 2026-08-24).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

if ( ! defined( 'AKIBARA_REWARDS_URL' ) ) {
	define( 'AKIBARA_REWARDS_URL', 'https://akibara.test/wp-content/plugins/akibara-rewards/' );
}
if ( ! defined( 'AKIBARA_REWARDS_VERSION' ) ) {
	define( 'AKIBARA_REWARDS_VERSION', 'test' );
}

if ( ! class_exists( 'WC_Coupon' ) ) {
	class WC_Coupon {
		public string $code = '';
		public function __construct( $data = '' ) {
			if ( is_string( $data ) ) {
				$this->code = $data;
			}
		}
		public function get_code(): string {
			return $this->code;
		}
	}
}

if ( ! class_exists( 'WC_DateTime' ) ) {
	/** Doble mínimo: WooCommerce extiende DateTime. */
	class WC_DateTime extends \DateTime {}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/** Doble mínimo para get_page_by_path y hooks de posts. */
	class WP_Post {
		public int $ID           = 0;
		public string $post_type = 'post';

		/**
		 * Estado del post; en WordPress, 'publish' por defecto.
		 *
		 * @var string
		 */
		public string $post_status = 'publish';

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}
	}
}

if ( ! class_exists( 'WP_Comment' ) ) {
	/**
	 * Doble mínimo de comentario/reseña: las propiedades que leen los módulos
	 * de Reviews. comment_approved usa los literales reales de WordPress
	 * ('0' pendiente, '1' aprobado, 'spam', 'trash').
	 */
	class WP_Comment {
		public int $comment_ID          = 0;
		public int $comment_post_ID     = 0;
		public int $comment_parent      = 0;
		public int $user_id             = 0;
		public string $comment_type     = 'review';
		public string $comment_approved = '0';
		public string $comment_author   = '';
		public string $comment_author_email = '';
		public string $comment_content  = '';
		public string $comment_date     = '2026-08-30 12:00:00';

		/** @param array<string,mixed> $props Propiedades iniciales. */
		public function __construct( array $props = array() ) {
			foreach ( $props as $k => $v ) {
				$this->$k = $v;
			}
		}
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	/** Doble mínimo de producto: las props de rating que lee el Schema. */
	class WC_Product {
		public int $id                 = 0;
		public int $rating_count       = 0;
		public string $average_rating  = '0.00';
		public int $review_count       = 0;

		public function get_id(): int {
			return $this->id;
		}

		public function get_rating_count(): int {
			return $this->rating_count;
		}

		/** WooCommerce devuelve STRING de 2 decimales (number_format). */
		public function get_average_rating(): string {
			return $this->average_rating;
		}

		public function get_review_count(): int {
			return $this->review_count;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/** Doble mínimo de WP_Error. */
	class WP_Error {
		public string $code;
		public string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/** Doble mínimo de usuario. */
	class WP_User {
		public int $ID              = 0;
		public string $user_email   = '';
		public string $user_login   = '';
		// El nombre que el exportador de datos personales entrega como «te
		// invitó»: el correo del padrino no sale nunca de la tienda.
		public string $display_name = '';

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}
	}
}

if ( ! class_exists( 'WC_Order_Item' ) ) {
	/**
	 * Doble de línea de pedido (line_item o fee) con los totales que lee el
	 * Calculator. En un reembolso, WooCommerce entrega los totales NEGATIVOS
	 * y el doble respeta eso: los tests pasan los signos de producción.
	 */
	class WC_Order_Item {
		private string $name;
		private float $total;
		private float $total_tax;

		/** Producto de la línea: lo pide el enlace del empujón de reseñas. */
		public int $product_id = 0;
		public int $quantity = 1;

		public function get_quantity(): int {
			return $this->quantity;
		}

		public function get_product_id(): int {
			return $this->product_id;
		}

		public function __construct( string $name, float $total, float $total_tax = 0.0 ) {
			$this->name      = $name;
			$this->total     = $total;
			$this->total_tax = $total_tax;
		}

		public function get_name(): string {
			return $this->name;
		}

		/** @return float */
		public function get_total() {
			return $this->total;
		}

		/** @return float */
		public function get_total_tax() {
			return $this->total_tax;
		}
	}
}

if ( ! class_exists( 'WC_Order_Refund' ) ) {
	/**
	 * Doble de reembolso: líneas por tipo + importe total. Un reembolso POR
	 * IMPORTE (sin líneas) se modela con items vacíos y amount > 0, como lo
	 * entrega WooCommerce.
	 */
	class WC_Order_Refund {
		/** @var array<string,array<int,\WC_Order_Item>> */
		private array $items;
		private float $amount;

		/**
		 * @param array<int,\WC_Order_Item> $line_items Líneas de producto (totales negativos).
		 * @param float                     $amount     Importe total del reembolso (positivo). 0 = derivar de las líneas.
		 * @param array<int,\WC_Order_Item> $shipping   Líneas de envío reembolsadas.
		 * @param array<int,\WC_Order_Item> $fees       Líneas de fee reembolsadas.
		 */
		public function __construct( array $line_items = array(), float $amount = 0.0, array $shipping = array(), array $fees = array() ) {
			$this->items = array(
				'line_item' => $line_items,
				'shipping'  => $shipping,
				'fee'       => $fees,
			);

			if ( $amount <= 0 ) {
				foreach ( array_merge( $line_items, $shipping, $fees ) as $item ) {
					$amount += abs( (float) $item->get_total() + (float) $item->get_total_tax() );
				}
			}
			$this->amount = $amount;
		}

		/**
		 * @param string $type Tipo de línea.
		 * @return array<int,\WC_Order_Item>
		 */
		public function get_items( string $type = 'line_item' ): array {
			return $this->items[ $type ] ?? array();
		}

		/** Importe total del reembolso, positivo (como el real). */
		public function get_amount(): float {
			return $this->amount;
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Fake' ) ) {
	/** Línea de producto con id (lo que ReviewForm necesita del item real). */
	class WC_Order_Item_Fake {
		private int $product_id;
		private string $name;

		public function __construct( int $product_id, string $name ) {
			$this->product_id = $product_id;
			$this->name       = $name;
		}

		public function get_product_id(): int {
			return $this->product_id;
		}

		public function get_name(): string {
			return $this->name;
		}

		public function get_quantity(): int {
			return 1;
		}

		public function get_total(): float {
			return 10000.0;
		}

		public function get_total_tax(): float {
			return 1900.0;
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Doble de pedido respaldado por arrays: metas, notas, líneas, reembolsos.
	 * `save_meta_data()` copia a `saved_meta` para poder afirmar QUÉ quedó
	 * persistido (y no solo qué quedó en memoria).
	 */
	class WC_Order {
		public int $id                = 0;
		public int $customer_id       = 0;
		public string $billing_email  = '';
		public string $billing_phone  = '';
		public string $billing_first_name = '';
		/** @var array<string,mixed> */
		public array $meta = array();
		/** @var array<string,mixed> */
		public array $saved_meta = array();
		/** @var array<int,string> */
		public array $notes = array();
		/** @var array<string,array<int,\WC_Order_Item>> */
		public array $items = array(
			'line_item' => array(),
			'fee'       => array(),
		);
		/** @var array<int,\WC_Order_Refund> */
		public array $refunds = array();
		public ?\WC_DateTime $date_created = null;
		public ?\WC_DateTime $date_paid = null;
		/** @var array<int,float> Cantidades devueltas por línea (negativas). */
		public array $refunded_quantities = array();

		public function get_date_paid(): ?\WC_DateTime {
			return $this->date_paid;
		}

		public function get_qty_refunded_for_item( int $item_id ): float {
			return $this->refunded_quantities[ $item_id ] ?? 0.0;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_customer_id(): int {
			return $this->customer_id;
		}

		public function get_view_order_url(): string {
			return 'https://akibara.test/mi-cuenta/view-order/' . $this->id . '/';
		}

		public function get_checkout_order_received_url(): string {
			return 'https://akibara.test/checkout/order-received/' . $this->id . '/?key=wc_order_qa';
		}

		public function get_billing_email(): string {
			return $this->billing_email;
		}

		public function get_billing_phone(): string {
			return $this->billing_phone;
		}

		public function get_billing_first_name(): string {
			return $this->billing_first_name;
		}

		public string $billing_last_name = '';

		public function get_billing_last_name(): string {
			return $this->billing_last_name;
		}

		public string $status = 'completed';

		public function get_status(): string {
			return $this->status;
		}

		/**
		 * @param string|array<int,string> $status Estado(s) sin prefijo wc-.
		 */
		public function has_status( $status ): bool {
			return in_array( $this->status, (array) $status, true );
		}

		/** Como el real: pendiente o fallido, con algo que cobrar. */
		public function needs_payment(): bool {
			return $this->has_status( array( 'pending', 'failed' ) ) && $this->get_total() > 0;
		}

		/**
		 * Cambia el estado y deja la nota, como el real (sin disparar ganchos).
		 *
		 * @param string $status Estado sin prefijo wc-.
		 * @param string $note   Nota opcional.
		 */
		public function update_status( string $status, string $note = '' ): void {
			$this->status = $status;
			if ( '' !== $note ) {
				$this->notes[] = $note;
			}
		}

		/**
		 * @param string $key    Meta.
		 * @param bool   $single Único (firma real).
		 * @return mixed '' si no existe, como el real.
		 */
		public function get_meta( string $key, bool $single = true ) {
			unset( $single );
			return $this->meta[ $key ] ?? '';
		}

		/**
		 * @param string $key   Meta.
		 * @param mixed  $value Valor.
		 */
		public function update_meta_data( string $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		/**
		 * @param string $key Meta.
		 */
		public function delete_meta_data( string $key ): void {
			unset( $this->meta[ $key ] );
		}

		public function save_meta_data(): void {
			$this->saved_meta = $this->meta;
		}

		public function add_order_note( string $note ): void {
			$this->notes[] = $note;
		}

		/**
		 * @param string $type Tipo de línea.
		 * @return array<int,\WC_Order_Item>
		 */
		public function get_items( string $type = 'line_item' ): array {
			return $this->items[ $type ] ?? array();
		}

		/** @return array<int,\WC_Order_Refund> */
		public function get_refunds(): array {
			return $this->refunds;
		}

		public function get_date_created(): ?\WC_DateTime {
			return $this->date_created;
		}

		/** Enlace del pedido en el escritorio (con HPOS apagado, como el real). */
		public function get_edit_order_url(): string {
			return 'https://akibara.test/wp-admin/post.php?post=' . $this->id . '&action=edit';
		}

		/**
		 * Total del pedido (líneas + envío − descuentos). Configurable; si no
		 * se fija, deriva de las líneas.
		 */
		public float $total = 0.0;

		public function get_total(): float {
			if ( $this->total > 0 ) {
				return $this->total;
			}
			$sum = 0.0;
			foreach ( $this->items['line_item'] as $item ) {
				$sum += (float) $item->get_total() + (float) $item->get_total_tax();
			}
			foreach ( $this->items['fee'] as $item ) {
				$sum += (float) $item->get_total() + (float) $item->get_total_tax();
			}
			return $sum;
		}

		public function get_total_refunded(): float {
			$sum = 0.0;
			foreach ( $this->refunds as $refund ) {
				$sum += abs( $refund->get_amount() );
			}
			return $sum;
		}

		/**
		 * Contador de re-lecturas forzadas: los tests de frescura afirman que
		 * los caminos de dinero SIEMPRE re-leen antes de decidir.
		 */
		public int $meta_reloads = 0;

		public function read_meta_data( bool $force_read = false ): void {
			if ( $force_read ) {
				++$this->meta_reloads;
			}
		}
	}
}

if ( ! class_exists( 'WC_Cart' ) ) {
	/**
	 * Doble de carrito: contenidos con line_total/line_tax (post-cálculo,
	 * como los lee el código) y registro de fees añadidos.
	 */
	class WC_Cart {
		/** @var array<int,array<string,mixed>> */
		public array $contents = array();
		/** @var array<int,array{name:string,amount:float,taxable:bool}> */
		public array $fees = array();

		/** @return array<int,array<string,mixed>> */
		public function get_cart(): array {
			return $this->contents;
		}

		/**
		 * @param string $name    Nombre del fee.
		 * @param float  $amount  Importe (negativo = descuento).
		 * @param bool   $taxable Imponible.
		 */
		public function add_fee( string $name, float $amount, bool $taxable = true ): void {
			$this->fees[] = array(
				'name'    => $name,
				'amount'  => $amount,
				'taxable' => $taxable,
			);
		}
	}
}

if ( ! class_exists( 'Akibara_Rewards_Fake_Session' ) ) {
	/** Sesión WC mínima: set/get respaldados por array. */
	class Akibara_Rewards_Fake_Session {
		/** @var array<string,mixed> */
		public array $data = array();

		/**
		 * @param string $key   Clave.
		 * @param mixed  $value Valor (null borra, como el real conserva null).
		 */
		public function set( string $key, $value ): void {
			$this->data[ $key ] = $value;
		}

		/**
		 * @param string $key Clave.
		 * @return mixed
		 */
		public function get( string $key ) {
			return $this->data[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'Akibara_Rewards_Fake_Mailer' ) ) {
	/** Mailer WC mínimo: registra envíos y devuelve lo scripteado. */
	/**
	 * Doble de `WC_Email`: lo que hace el real al enviar es `wp_mail()` con
	 * las cabeceras dadas y disparar `woocommerce_email_sent` con su `id`. Aquí
	 * el transporte es el mailer falso (misma bandeja que miran las pruebas) y
	 * el `id` queda anotado para comprobar la identidad del correo
	 * (R7-correos-sin-nombre).
	 */
	class WC_Email {
		public string $id         = '';
		public string $title      = '';
		public string $email_type = '';
		/** @var array<string, mixed> */
		public array $form_fields = array();
		/** @var array<int, string> Ids con que se envió, en orden. */
		public static array $sent_ids = array();

		public function __construct() {
			$this->init_form_fields();
		}

		public function init_form_fields() {
			$this->form_fields = array();
		}

		/**
		 * @param string       $to          Destinatario.
		 * @param string       $subject     Asunto.
		 * @param string       $message     Cuerpo.
		 * @param string       $headers     Cabeceras.
		 * @param array<mixed> $attachments Adjuntos.
		 * @return bool
		 */
		public function send( $to, $subject, $message, $headers, $attachments = array() ) {
			unset( $attachments );
			self::$sent_ids[] = $this->id;
			return (bool) WC()->mailer()->send( (string) $to, (string) $subject, (string) $message, (string) $headers );
		}
	}

	class Akibara_Rewards_Fake_Mailer {
		public bool $send_result = true;
		/** @var array<int,array{to:string,subject:string,body:string,headers:string}> */
		public array $sent = array();
		/**
		 * Correos registrados, como `WC_Emails::get_emails()`: clase → objeto
		 * con `id` y `title` públicos.
		 *
		 * @var array<string,object>
		 */
		public array $emails = array();

		/** @return array<string,object> */
		public function get_emails(): array {
			return $this->emails;
		}

		public function wrap_message( string $heading, string $body ): string {
			return '<wrapped heading="' . $heading . '">' . $body . '</wrapped>';
		}

		/**
		 * Fiel a `WC_Emails::send()`, cabeceras incluidas: el doble se las
		 * comía y por eso nada podía afirmar sobre el List-Unsubscribe de la
		 * invitación (lección «dobles que no modelan producción», 2026-09-18).
		 */
		public function send( string $to, string $subject, string $body, string $headers = '', $attachments = array() ): bool {
			$this->sent[] = array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			);
			return $this->send_result;
		}
	}
}

if ( ! class_exists( 'Akibara_Rewards_Fake_Logger' ) ) {
	/**
	 * Logger WC mínimo: registra nivel, mensaje y contexto tal como los
	 * recibe. La forma importa — el `source` es lo que filtra la pantalla de
	 * WooCommerce → Estado → Registros.
	 */
	class Akibara_Rewards_Fake_Logger {
		/** @var array<int,array{level:string,message:string,context:array<string,mixed>}> */
		public array $entries = array();

		/**
		 * @param string              $message Mensaje.
		 * @param array<string,mixed> $context Contexto (source incluido).
		 */
		public function error( string $message, array $context = array() ): void {
			$this->entries[] = array(
				'level'   => 'error',
				'message' => $message,
				'context' => $context,
			);
		}
	}
}

if ( ! class_exists( 'Akibara_Rewards_Fake_WC' ) ) {
	/** Contenedor WC() mínimo: session + mailer. */
	class Akibara_Rewards_Fake_WC {
		public ?Akibara_Rewards_Fake_Session $session = null;
		public ?Akibara_Rewards_Fake_Mailer $the_mailer = null;
		/** @var mixed */
		public $cart = null;

		public function mailer(): Akibara_Rewards_Fake_Mailer {
			if ( null === $this->the_mailer ) {
				$this->the_mailer = new Akibara_Rewards_Fake_Mailer();
			}
			return $this->the_mailer;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/** Doble mínimo de la petición REST: método y ruta. */
	class WP_REST_Request {
		private string $method;
		private string $route;

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/** Doble mínimo de la respuesta REST: datos y estado. */
	class WP_REST_Response {
		/** @var mixed */
		private $data;
		private int $status;

		/**
		 * @param mixed $data   Datos.
		 * @param int   $status Estado HTTP.
		 */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/** @return mixed */
		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}
