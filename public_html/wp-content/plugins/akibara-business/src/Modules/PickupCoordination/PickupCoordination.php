<?php
/**
 * Explica la coordinación del retiro en Metro San Miguel.
 *
 * La página de gracias, el correo al cliente y la vista de pedido indican que
 * Akibara avisa al estar listo y coordina día y hora por correo o WhatsApp.
 * No aparece en correos administrativos, despacho a domicilio ni retiro BlueX,
 * cuya coordinación corresponde al transportista.
 *
 * El título y cuerpo se editan en Akibara → Textos; el correo de contacto procede
 * del formulario de Contacto. El armazón de enlaces con número de pedido es fijo
 * y el asunto prellenado es neutro para no contradecir un punto de entrega
 * editable. El texto del dueño no se trata como plantilla de formato.
 *
 * @package Akibara\Business
 * @since 1.115.23
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PickupCoordination;

use Akibara\Business\Modules\Forms\Settings as FormsSettings;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.115.23
 * @api
 */
final class PickupCoordination {

	/**
	 * Métodos de envío que son «retiro con nosotros».
	 *
	 * `local_pickup` es el de WooCommerce y es el que la tienda usa para Metro
	 * San Miguel (zona «Región Metropolitana - Blue Express», instancia 70).
	 * `pickup_location` entra por si algún día se migra al método nuevo de
	 * WooCommerce. `bluex-pudo` queda FUERA a propósito: ese retiro es en un
	 * punto del transportista y no lo coordinamos nosotros.
	 */
	private const METODOS_DE_RETIRO = array( 'local_pickup', 'pickup_location' );

	/**
	 * Excluye la coordinación cuando el pedido no llegará a entregarse.
	 *
	 * Los correos de cancelación y reembolso comparten los hooks de presentación
	 * con las confirmaciones. Estos estados deben cortar el recuadro tanto en correo
	 * como en la vista del pedido, además de verificar el método de retiro.
	 */
	private const ESTADOS_SIN_RETIRO = array( 'cancelled', 'refunded', 'failed' );

	/** Título del aviso, editable en Akibara → Textos. */
	public const OPTION_TITULO = 'akb_copy_pickup_title';

	/** Cómo se coordina el retiro, editable en Akibara → Textos. */
	public const OPTION_CUERPO = 'akb_copy_pickup_body';

	/** Valor de fábrica del título. */
	public const DEFAULT_TITULO = 'Cómo es el retiro en Metro San Miguel';

	/** Valor de fábrica del cuerpo. */
	public const DEFAULT_CUERPO = 'Te escribimos en cuanto tu pedido esté listo y coordinamos ahí mismo el día y la hora que te acomode. La entrega es en la salida del Metro San Miguel.';

	/** Promesa concisa mostrada junto al método de retiro en Checkout / Carrito. */
	public const PROMESA_TABLA_ENVIO = 'Coordinación: te contactaremos en menos de 24 horas.';

	/** Teléfono de respaldo si el plugin de WhatsApp no tiene uno configurado. */
	private const TELEFONO_RESPALDO = '+56944242844';

	public function register(): void {
		// Correo del pedido (cliente). Después de la tabla, que es justo donde
		// el cliente acaba de leer «Retiro en Metro San Miguel».
		add_action( 'woocommerce_email_after_order_table', array( $this, 'pintar_en_correo' ), 20, 3 );

		// Página de gracias y /mi-cuenta/view-order/{id}/ comparten hook.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'pintar_en_pantalla' ), 20, 1 );

		// Superficie activa Store API / Cart & Checkout Blocks (`delivery_time` y `pickup_details`).
		add_filter( 'woocommerce_package_rates', array( $this, 'asignar_tiempo_entrega_retiro' ), 20, 1 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'ocultar_meta_promesa_en_orden' ), 20, 2 );

		// Respaldo para plantilla clásica de carrito/checkout.
		add_filter( 'woocommerce_cart_shipping_method_full_label', array( $this, 'agregar_nota_en_tabla_envio' ), 20, 2 );
	}

	/**
	 * Asigna `delivery_time` y `pickup_details` en tarifas de retiro propio para Store API (Cart y Checkout Blocks).
	 *
	 * @param array<string|int, mixed> $rates Tarifas calculadas del paquete.
	 * @return array<string|int, mixed>
	 */
	public function asignar_tiempo_entrega_retiro( array $rates ): array {
		foreach ( $rates as $rate ) {
			if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_method_id' ) ) {
				continue;
			}
			if ( ! in_array( (string) $rate->get_method_id(), self::METODOS_DE_RETIRO, true ) ) {
				continue;
			}

			if (
				method_exists( $rate, 'set_delivery_time' )
				&& ( ! method_exists( $rate, 'get_delivery_time' ) || '' === trim( (string) $rate->get_delivery_time() ) )
			) {
				$rate->set_delivery_time( self::PROMESA_TABLA_ENVIO );
			}

			if ( method_exists( $rate, 'add_meta_data' ) ) {
				$existing_meta = method_exists( $rate, 'get_meta_data' ) ? $rate->get_meta_data() : array();
				$current_val   = is_array( $existing_meta ) ? trim( (string) ( $existing_meta['pickup_details'] ?? '' ) ) : '';
				if ( '' === $current_val ) {
					$rate->add_meta_data( 'pickup_details', self::PROMESA_TABLA_ENVIO );
				}
			}
		}

		return $rates;
	}

	/**
	 * Evita mostrar la clave técnica `pickup_details` en el desglose del pedido/correo
	 * cuando únicamente porta nuestra promesa sintética (ya mostrada en `.akb-pickup-coord`).
	 *
	 * @param array<int|string, object> $formatted_meta Metadatos formateados del ítem.
	 * @param mixed                     $item           Ítem del pedido.
	 * @return array<int|string, object>
	 */
	public function ocultar_meta_promesa_en_orden( array $formatted_meta, $item ): array {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_method_id' ) ) {
			return $formatted_meta;
		}
		if ( ! in_array( (string) $item->get_method_id(), self::METODOS_DE_RETIRO, true ) ) {
			return $formatted_meta;
		}

		return array_filter(
			$formatted_meta,
			static fn( $meta ): bool => ! (
				is_object( $meta )
				&& isset( $meta->key, $meta->value )
				&& 'pickup_details' === (string) $meta->key
				&& self::PROMESA_TABLA_ENVIO === trim( (string) $meta->value )
			)
		);
	}

	/**
	 * Añade la nota concisa de coordinación en la etiqueta del método de retiro (plantilla clásica).
	 *
	 * @param string $label  Etiqueta completa formateada por WooCommerce.
	 * @param mixed  $method Instancia de WC_Shipping_Rate.
	 */
	public function agregar_nota_en_tabla_envio( string $label, $method ): string {
		if ( is_object( $method ) && method_exists( $method, 'get_method_id' ) && in_array( $method->get_method_id(), self::METODOS_DE_RETIRO, true ) ) {
			$label .= '<span class="akb-pickup-promise-label">' . esc_html( self::PROMESA_TABLA_ENVIO ) . '</span>';
		}
		return $label;
	}

	/**
	 * @param mixed $order         Pedido.
	 * @param mixed $sent_to_admin Si el correo va al administrador.
	 * @param mixed $plain_text    Si es la versión de texto plano.
	 */
	public function pintar_en_correo( $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( ! $order instanceof WC_Order || $sent_to_admin || ! self::es_retiro( $order ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n\n" . wp_kses( wp_strip_all_tags( self::texto_plano( $order ) ), array() ) . "\n";
			return;
		}

		self::pintar_html_correo( $order );
	}

	/**
	 * @param mixed $order Pedido.
	 */
	public function pintar_en_pantalla( $order ): void {
		if ( ! $order instanceof WC_Order || ! self::es_retiro( $order ) ) {
			return;
		}

		self::pintar_html_pantalla( $order );
	}

	/**
	 * ¿Este pedido se retira con nosotros?
	 *
	 * Se mira el método REAL de cada línea de envío, no el título: el título lo
	 * edita el dueño desde WooCommerce y un día puede decir otra cosa.
	 */
	private static function es_retiro( WC_Order $order ): bool {
		if ( $order->has_status( self::ESTADOS_SIN_RETIRO ) ) {
			return false;
		}

		foreach ( $order->get_shipping_methods() as $linea ) {
			if ( in_array( (string) $linea->get_method_id(), self::METODOS_DE_RETIRO, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Un texto del dueño, o el de fábrica si lo dejó en blanco.
	 *
	 * @param string $option  Option de Akibara → Textos.
	 * @param string $fabrica Valor de fábrica.
	 * @return string
	 */
	private static function texto( string $option, string $fabrica ): string {
		$suyo = trim( (string) get_option( $option, $fabrica ) );

		return '' === $suyo ? $fabrica : $suyo;
	}

	/** Correo de atención: el mismo buzón que el dueño configuró para Contacto. */
	private static function correo(): string {
		return FormsSettings::get_notify_email();
	}

	/**
	 * Datos normalizados para ambos formatos (correo y pantalla).
	 *
	 * @return array{numero:string,wa:string,mail:string,titulo:string,cuerpo:string}
	 */
	private static function datos_aviso( WC_Order $order ): array {
		$numero = '#' . $order->get_order_number();

		return array(
			'numero' => $numero,
			'wa'     => self::url_whatsapp( $numero ),
			'mail'   => self::url_correo( $numero ),
			'titulo' => self::texto( self::OPTION_TITULO, self::DEFAULT_TITULO ),
			'cuerpo' => self::texto( self::OPTION_CUERPO, self::DEFAULT_CUERPO ),
		);
	}

	private static function pintar_html_correo( WC_Order $order ): void {
		$d = self::datos_aviso( $order );
		?>
		<div class="akb-email-callout akb-pickup-coord" style="margin: 20px 0; padding: 16px 20px; background: #f7f7f7; border: 1px solid #d4d4d4; border-top: 3px solid #E60012; border-radius: 6px; color: #0d0d0d;">
			<p style="margin: 0 0 8px 0; font-weight: 600; font-size: 1.05em; color: #0d0d0d;">
				📍 <?php echo esc_html( $d['titulo'] ); ?>
			</p>
			<p style="margin: 0 0 8px 0; font-size: 0.95em; line-height: 1.5; color: #2b2b2b;">
				<?php echo esc_html( $d['cuerpo'] ); ?>
			</p>
			<p style="margin: 0; font-size: 0.95em; line-height: 1.5; color: #2b2b2b;">
				Si prefieres adelantarlo, escríbenos por
				<a href="<?php echo esc_url( $d['wa'] ); ?>" style="color:#0d0d0d;-webkit-text-fill-color:#0d0d0d;text-decoration:underline;font-weight:600;">WhatsApp</a>
				o a
				<a href="<?php echo esc_url( $d['mail'] ); ?>" style="color:#0d0d0d;-webkit-text-fill-color:#0d0d0d;text-decoration:underline;font-weight:600;"><?php echo esc_html( self::correo() ); ?></a>
				con tu número de pedido <?php echo esc_html( $d['numero'] ); ?>.
			</p>
		</div>
		<?php
	}

	private static function pintar_html_pantalla( WC_Order $order ): void {
		$d = self::datos_aviso( $order );
		?>
		<div class="akb-pickup-coord">
			<p class="akb-pickup-coord__title">
				<span aria-hidden="true">📍</span>
				<?php echo esc_html( $d['titulo'] ); ?>
			</p>
			<p class="akb-pickup-coord__body">
				<?php echo esc_html( $d['cuerpo'] ); ?>
			</p>
			<p class="akb-pickup-coord__contact">
				Si prefieres adelantarlo, escríbenos por
				<a href="<?php echo esc_url( $d['wa'] ); ?>">WhatsApp</a>
				o a
				<a href="<?php echo esc_url( $d['mail'] ); ?>"><?php echo esc_html( self::correo() ); ?></a>
				con tu número de pedido <?php echo esc_html( $d['numero'] ); ?>.
			</p>
		</div>
		<?php
	}

	private static function texto_plano( WC_Order $order ): string {
		$numero = '#' . $order->get_order_number();
		$titulo = self::texto( self::OPTION_TITULO, self::DEFAULT_TITULO );

		// El correo en texto plano titula en mayúsculas. `mb_strtoupper` y no
		// `strtoupper`, que con acentos devuelve bytes rotos; sin mbstring, el
		// título va tal como lo escribió el dueño.
		if ( function_exists( 'mb_strtoupper' ) ) {
			$titulo = mb_strtoupper( $titulo, 'UTF-8' );
		}

		return "----------\n"
			. $titulo . "\n"
			. self::texto( self::OPTION_CUERPO, self::DEFAULT_CUERPO ) . "\n"
			. "Si prefieres adelantarlo, escríbenos por WhatsApp al " . self::telefono_legible()
			. " o a " . self::correo() . " con tu número de pedido " . $numero . ".\n";
	}

	private static function url_whatsapp( string $numero_pedido ): string {
		$mensaje = sprintf( 'Hola, quiero coordinar el retiro de mi pedido %s', $numero_pedido );

		return 'https://wa.me/' . rawurlencode( self::telefono_limpio() ) . '?text=' . rawurlencode( $mensaje );
	}

	private static function url_correo( string $numero_pedido ): string {
		// El asunto del enlace es neutro porque el punto de retiro es editable.
		// Así el asunto y el mensaje de WhatsApp no conservan un lugar distinto del
		// que anuncia el recuadro de coordinación.

		$asunto = sprintf( 'Retiro — pedido %s', $numero_pedido );

		return 'mailto:' . self::correo() . '?subject=' . rawurlencode( $asunto );
	}

	/**
	 * Teléfono en E164 leyendo la option del plugin de WhatsApp, igual que
	 * `ContactFooter`: un solo número que cambiar el día que cambie.
	 */
	private static function telefono(): string {
		$joinchat = get_option( 'joinchat' );
		if ( is_array( $joinchat ) && ! empty( $joinchat['telephone'] ) ) {
			return (string) $joinchat['telephone'];
		}

		return self::TELEFONO_RESPALDO;
	}

	private static function telefono_limpio(): string {
		return (string) preg_replace( '/[^0-9]/', '', self::telefono() );
	}

	private static function telefono_legible(): string {
		$e164 = self::telefono();
		if ( 1 === preg_match( '/^\+56(9)(\d{4})(\d{4})$/', $e164, $m ) ) {
			return '+56 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
		}

		return $e164;
	}
}
