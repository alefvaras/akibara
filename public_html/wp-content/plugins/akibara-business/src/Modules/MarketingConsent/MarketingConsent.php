<?php
/**
 * MarketingConsent — prueba de la decisión de ofertas y novedades en el pedido.
 *
 * Guarda valor, etiqueta exacta mostrada, fecha, origen y versión de privacidad,
 * y añade una nota privada legible. La etiqueta procede del registro de campos
 * de WooCommerce, sin consultar APIs u opciones del proveedor. No duplica IP ni
 * user-agent, no escribe usermeta y deja que Store API persista el pedido.
 *
 * Checkout Blocks sólo envía additional_fields que el cliente modificó. Al final
 * de update_order_from_request, si falta la prueba pero el campo sigue registrado,
 * se registra la casilla sin marcar con la etiqueta mostrada. Si no está registrado,
 * no se inventa una decisión: se registra un warning por pedido afectado.
 *
 * Cada decisión se anota una vez: PUT y POST repetidos con igual valor y etiqueta
 * no reescriben la prueba. Una edición administrativa puede cambiar el dato que
 * consume el proveedor, pero conserva la prueba del cliente y deja una nota que
 * identifica la edición administrativa. Las notas pendientes se emiten cuando
 * el pedido ya tiene ID, sin iniciar un save() adicional.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\MarketingConsent;

use Akibara\Business\Utilities\Log;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.116.0
 */
final class MarketingConsent {

	/** Meta del pedido con la prueba (JSON). */
	public const META_KEY = '_akb_marketing_consent';

	/**
	 * Clave del campo adicional de WooCommerce que registra el proveedor de
	 * campañas. Es un identificador de campo del checkout, no una llamada al
	 * proveedor: por eso vive aquí y no en un mu-plugin.
	 */
	public const DEFAULT_FIELD_KEY = 'SendinblueWoocommerce/newsletter_opt_in';

	/** Origen de la prueba. */
	public const SOURCE = 'checkout-blocks';

	/**
	 * Notas privadas del pedido. Marcadores por `str_replace`, NUNCA formato de
	 * `sprintf`: el texto de la etiqueta lo escribe el dueño y puede traer «%».
	 */
	private const NOTE_YES = 'Consentimiento de ofertas y novedades: SÍ — casilla del checkout marcada. Texto que vio el cliente: “{texto}”. Política vigente del {fecha}.';
	private const NOTE_NO  = 'Consentimiento de ofertas y novedades: NO — casilla del checkout sin marcar. Texto: “{texto}”.';

	/**
	 * Nota de una edición desde el panel. WooCommerce firma la nota con el
	 * usuario del panel (`$added_by_user`), así que aquí no va el nombre.
	 */
	private const NOTE_ADMIN = 'Casilla de ofertas y novedades cambiada desde el panel: ahora {estado}. No es consentimiento del cliente: la prueba del checkout queda como estaba.';

	/** Lo que se anota cuando no hay página de política o no tiene fecha. */
	private const SIN_FECHA = 'sin fecha registrada';

	/**
	 * @return void
	 */
	public function register(): void {
		// Hook del núcleo de WooCommerce (desde 8.9), disparado ANTES de
		// `update_meta_data` para cada campo adicional guardado en el pedido.
		add_action( 'woocommerce_set_additional_field_value', array( $this, 'on_field_value' ), 10, 4 );

		// Al final de `update_order_from_request`, ya persistidos los campos
		// que viajaron y antes del `save()`: la casilla que el bloque no envió
		// es una casilla sin marcar.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'on_order_from_request' ), 10, 1 );

		// Una vez por pedido procesado por el checkout de bloques: ¿quedó la
		// prueba? Si no, la clave del campo ya no coincide con el proveedor.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'warn_if_proof_missing' ), 10, 1 );
	}

	/**
	 * Clave del campo cuyo valor es el consentimiento.
	 *
	 * @return string
	 */
	public static function field_key(): string {
		/**
		 * Clave del campo adicional del checkout que representa el
		 * consentimiento de ofertas y novedades. Cambiar de proveedor de
		 * campañas es cambiar esta clave.
		 *
		 * @since 1.116.0
		 *
		 * @param string $key Default: la casilla del plugin de Brevo.
		 */
		return (string) apply_filters( 'akibara_marketing_consent_field_key', self::DEFAULT_FIELD_KEY );
	}

	/**
	 * @param string $key       Clave del campo adicional.
	 * @param mixed  $value     Valor que el cliente envió.
	 * @param string $group     Grupo del campo (shipping|billing|other).
	 * @param mixed  $wc_object `WC_Order` o `WC_Customer`.
	 * @return void
	 */
	public function on_field_value( string $key, $value, string $group, $wc_object ): void {
		// Sólo pedidos: `is_customer_field()` de WooCommerce excluye la
		// ubicación 'order', así que este campo nunca llega a usermeta.
		if ( ! $wc_object instanceof \WC_Order ) {
			return;
		}

		if ( $key !== self::field_key() ) {
			return;
		}

		$accepted = in_array( $value, array( true, 1, '1', 'true' ), true );

		// Una edición desde el admin no es consentimiento del cliente: la
		// prueba no se toca, pero el cambio queda anotado.
		if ( is_admin() ) {
			$this->note_admin_change( $key, $accepted, $group, $wc_object );
			return;
		}

		$label = $this->field_label( $key );

		$previous = $this->previous( $wc_object );
		if ( null !== $previous && $previous['value'] === $accepted && $previous['label'] === $label ) {
			return;
		}

		$this->record( $wc_object, $accepted, $label );
	}

	/**
	 * La casilla que el bloque no envió es una casilla sin marcar.
	 *
	 * Corre al final de `update_order_from_request`: WooCommerce ya persistió
	 * los campos que viajaron (y `on_field_value` ya guardó la prueba si la
	 * casilla venía), y el `save()` del pedido viene después, así que aquí
	 * tampoco se guarda. Si el campo no está registrado no se escribe nada:
	 * la prueba ausente es la señal que `warn_if_proof_missing` levanta.
	 *
	 * @param mixed $order Pedido del checkout de bloques.
	 * @return void
	 */
	public function on_order_from_request( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->flush_pending_note( $order );

		if ( null !== $this->previous( $order ) ) {
			return;
		}

		$key = self::field_key();
		if ( null === $this->field_definition( $key ) ) {
			return;
		}

		$this->record( $order, false, $this->field_label( $key ) );
	}

	/**
	 * Un pedido del checkout de bloques sin la prueba es la señal de que la
	 * clave del campo ya no coincide con lo que registra el proveedor. Un
	 * warning por pedido afectado, con la clave vigente, para que se vea en
	 * Registros y se sepa a cuántos pedidos alcanzó.
	 *
	 * @param mixed $order Pedido procesado.
	 * @return void
	 */
	public function warn_if_proof_missing( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$raw = $order->get_meta( self::META_KEY, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			return;
		}

		// Por la ruta real sólo se llega aquí sin el campo registrado (el cierre
		// de `update_order_from_request` escribe la prueba siempre que lo esté),
		// así que el aviso lleva ese dato en vez de adivinar una causa.
		$key = self::field_key();

		Log::warn(
			'consentimiento',
			'El pedido #' . (string) $order->get_id() . ' se procesó sin la prueba de consentimiento de ofertas y novedades. '
			. 'Campo «' . $key . '» registrado en WooCommerce: ' . ( null === $this->field_definition( $key ) ? 'no' : 'sí' ) . '. '
			. 'Si es «no», el proveedor cambió la clave de su casilla o dejó de registrarla; la clave se ajusta con el filtro akibara_marketing_consent_field_key.',
			array( 'order_id' => $order->get_id() )
		);
	}

	/**
	 * Escribe la prueba y su nota. No llama a `save()`: el Store API guarda el
	 * pedido después de los dos hooks que llegan aquí.
	 *
	 * @param \WC_Order $order    Pedido.
	 * @param bool      $accepted ¿Marcó la casilla?
	 * @param string    $label    Texto que vio el cliente.
	 * @return void
	 */
	private function record( \WC_Order $order, bool $accepted, string $label ): void {
		$policy = $this->policy_modified_gmt();

		$order->update_meta_data(
			self::META_KEY,
			(string) wp_json_encode(
				array(
					'value'               => $accepted,
					'label'               => $label,
					'at'                  => gmdate( 'c' ),
					'source'              => self::SOURCE,
					'policy_modified_gmt' => $policy,
				)
			)
		);

		$this->add_note( $order, $this->note( $accepted, $label, $policy ) );
	}

	/**
	 * Notas que no se pudieron escribir porque el pedido aún no tenía ID,
	 * por `spl_object_id` del pedido. Viven lo que dura la petición.
	 *
	 * @var array<int, string>
	 */
	private array $pending_notes = array();

	/**
	 * Anota el pedido o difiere la nota hasta que tenga ID.
	 *
	 * WooCommerce copia los campos de sesión antes del primer guardado mediante
	 * create_order_from_cart → update_addresses_from_cart →
	 * sync_order_additional_fields_with_customer. on_field_value puede recibir un
	 * pedido con ID cero: sus metas se persistirán, pero add_order_note() necesita
	 * un ID. La nota queda pendiente hasta on_order_from_request, sin duplicar una
	 * decisión cuyo valor y etiqueta ya estén almacenados.
	 *
	 * @param \WC_Order $order Pedido.
	 * @param string    $note  Nota privada.
	 * @return void
	 */
	private function add_note( \WC_Order $order, string $note ): void {
		if ( $order->get_id() > 0 ) {
			// Una decisión nueva con el pedido ya guardado deja sin efecto la
			// nota que quedó pendiente de la copia de sesión (ID 0): si no, la
			// nota vieja saldría DESPUÉS de la nueva y la última nota del
			// pedido contradiría la prueba (revisión independiente, H1).
			unset( $this->pending_notes[ spl_object_id( $order ) ] );
			$order->add_order_note( $note, 0 );
			return;
		}

		$this->pending_notes[ spl_object_id( $order ) ] = $note;
	}

	/**
	 * @param \WC_Order $order Pedido, ya con ID.
	 * @return void
	 */
	private function flush_pending_note( \WC_Order $order ): void {
		$id = spl_object_id( $order );
		if ( ! isset( $this->pending_notes[ $id ] ) || $order->get_id() < 1 ) {
			return;
		}

		$order->add_order_note( $this->pending_notes[ $id ], 0 );
		unset( $this->pending_notes[ $id ] );
	}

	/**
	 * Anota una edición de la casilla desde el panel, sólo si de verdad cambia
	 * el valor guardado: el metabox del pedido reenvía TODOS los campos en cada
	 * «Actualizar», y un guardado sin tocar la casilla no merece nota.
	 *
	 * @param string    $key      Clave del campo.
	 * @param bool      $accepted Valor que llega del panel.
	 * @param string    $group    Grupo del campo.
	 * @param \WC_Order $order    Pedido.
	 * @return void
	 */
	private function note_admin_change( string $key, bool $accepted, string $group, \WC_Order $order ): void {
		$registry = $this->registry();
		if ( null === $registry || ! is_callable( array( $registry, 'get_field_from_object' ) ) ) {
			return;
		}

		try {
			$stored = $registry->get_field_from_object( $key, $order, $group );
		} catch ( \Throwable $error ) {
			unset( $error );
			return;
		}

		if ( in_array( $stored, array( true, 1, '1', 'true' ), true ) === $accepted ) {
			return;
		}

		$order->add_order_note(
			str_replace( '{estado}', $accepted ? 'SÍ' : 'NO', self::NOTE_ADMIN ),
			0,
			true
		);
	}

	/**
	 * Lo ya guardado en el pedido, si tiene forma.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array{value:bool, label:string}|null
	 */
	private function previous( \WC_Order $order ): ?array {
		$raw = $order->get_meta( self::META_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! array_key_exists( 'value', $decoded ) ) {
			return null;
		}

		return array(
			'value' => true === $decoded['value'],
			'label' => isset( $decoded['label'] ) && is_string( $decoded['label'] ) ? $decoded['label'] : '',
		);
	}

	/**
	 * Registro de campos adicionales del checkout de WooCommerce, o null si
	 * Blocks no está cargado.
	 *
	 * @return object|null
	 */
	private function registry(): ?object {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Package' )
			|| ! class_exists( '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields' ) ) {
			return null;
		}

		try {
			$registry = \Automattic\WooCommerce\Blocks\Package::container()
				->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
		} catch ( \Throwable $error ) {
			unset( $error );
			return null;
		}

		return is_object( $registry ) ? $registry : null;
	}

	/**
	 * Etiqueta del campo tal como la PINTÓ el checkout de bloques.
	 *
	 * Se lee del registro de campos de WooCommerce, NO de la option del
	 * proveedor, para que la prueba sobreviva a un cambio de proveedor. Y se
	 * elige la misma clave que elige el checkout (`checkout.js`:
	 * `required ? label: optionalLabel`): la casilla de ofertas es opcional
	 * por definición, así que el cliente vio `optionalLabel`, que WooCommerce
	 * rellena siempre —«{label} (opcional)» si el proveedor no declara otro—.
	 * Guardar `label` era guardar un texto que el cliente nunca leyó, que es
	 * justo lo que este módulo existe para evitar. `label` queda como
	 * respaldo por si un registro no trae `optionalLabel`.
	 *
	 * @param string $key Clave del campo.
	 * @return string
	 */
	private function field_label( string $key ): string {
		$field = $this->field_definition( $key );
		if ( null === $field ) {
			return '';
		}

		$required = ! empty( $field['required'] );
		$label    = $field['label'] ?? '';
		$optional = $field['optionalLabel'] ?? '';

		$seen = $label;
		if ( ! $required && is_string( $optional ) && '' !== trim( $optional ) ) {
			$seen = $optional;
		}

		return is_string( $seen ) ? trim( wp_strip_all_tags( $seen ) ) : '';
	}

	/**
	 * Definición del campo en el registro de campos adicionales de WooCommerce,
	 * o null si no está registrado (o Blocks no está cargado). Que esté
	 * registrado es lo que decide si el checkout pudo pintar la casilla.
	 *
	 * @param string $key Clave del campo.
	 * @return array<string, mixed>|null
	 */
	private function field_definition( string $key ): ?array {
		$registry = $this->registry();
		if ( null === $registry || ! is_callable( array( $registry, 'get_additional_fields' ) ) ) {
			return null;
		}

		try {
			$fields = $registry->get_additional_fields();
		} catch ( \Throwable $error ) {
			unset( $error );
			return null;
		}

		if ( ! is_array( $fields ) || ! isset( $fields[ $key ] ) || ! is_array( $fields[ $key ] ) ) {
			return null;
		}

		return $fields[ $key ];
	}

	/**
	 * `post_modified_gmt` de la página de privacidad configurada en WordPress
	 * ('' si no hay página).
	 *
	 * @return string
	 */
	private function policy_modified_gmt(): string {
		$page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		if ( $page_id < 1 ) {
			return '';
		}

		$modified = get_post_field( 'post_modified_gmt', $page_id );

		return is_string( $modified ) ? $modified : '';
	}

	/**
	 * @param bool   $accepted            ¿Marcó la casilla?
	 * @param string $label               Texto que vio el cliente.
	 * @param string $policy_modified_gmt Fecha GMT de la política ('' si no hay).
	 * @return string
	 */
	private function note( bool $accepted, string $label, string $policy_modified_gmt ): string {
		// post_modified_gmt debe interpretarse como GMT y formatearse con wp_date()
		// en la zona del sitio. La prueba de consentimiento registra el día local de
		// la política, que puede diferir del día UTC cerca de medianoche.

		$fecha     = self::SIN_FECHA;
		$timestamp = '' !== $policy_modified_gmt ? strtotime( $policy_modified_gmt . ' UTC' ) : false;
		if ( false !== $timestamp ) {
			$fecha = (string) wp_date( 'd/m/Y', $timestamp );
		}

		return str_replace(
			array( '{texto}', '{fecha}' ),
			array( $label, $fecha ),
			$accepted ? self::NOTE_YES : self::NOTE_NO
		);
	}
}
