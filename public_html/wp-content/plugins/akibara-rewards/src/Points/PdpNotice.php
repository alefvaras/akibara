<?php
/**
 * PdpNotice — Aviso de puntos por compra bajo el formulario de compra en la ficha de producto.
 *
 * Ubicado de forma no intrusiva bajo el botón de adición al carrito y la línea de despacho.
 * El enlace dirige a la página pública del programa (/akibara-puntos/).
 * Para usuarios invitados, presenta de forma condicional el beneficio de bienvenida.
 *
 * La promesa utiliza el cálculo canónico de acreditación del programa sobre el precio con IVA.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 * @api
 * @psalm-suppress HookNotFound
 * @psalm-suppress RedundantCondition
 */
final class PdpNotice {

	/**
	 * Producto cuyo aviso ya salió en esta petición (null = ninguno).
	 *
	 * @var int|null
	 */
	private ?int $impreso = null;

	/**
	 * Cuelga el aviso de dos ganchos, y sale por el primero que llegue.
	 *
	 * - `woocommerce_after_add_to_cart_form` a 10: bajo el botón, después de la
	 *   línea de despacho (business, a 5) y antes de los sellos de confianza del
	 *   tema (a 15). Ese gancho sólo existe cuando WooCommerce pinta el
	 *   formulario, o sea, cuando hay algo que comprar hoy.
	 * - `woocommerce_single_product_summary` a 32: el respaldo de un tomo
	 *   agotado, que no tiene formulario.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'render' ), 10 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render' ), 32 );
	}

	/**
	 * Pinta el aviso, una sola vez por producto.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( 'yes' !== (string) get_option( 'akb_rewards_pdp_notice_enabled', 'yes' ) ) {
			return;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product() : false;
		if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() ) {
			return;
		}

		// El respaldo del resumen llega DESPUÉS del formulario: si el aviso ya
		// salió bajo el botón, no se repite.
		if ( $this->impreso === $product->get_id() ) {
			return;
		}

		$price = function_exists( 'wc_get_price_including_tax' )
			? (float) wc_get_price_including_tax( $product )
			: (float) $product->get_price();

		// Promise sólo anuncia puntos cuando el programa está vigente.
		$points = Promise::points_for( $price );
		if ( $points <= 0 ) {
			return;
		}

		// En un variable, wc_get_price_including_tax devuelve el precio
		// MÍNIMO del rango: la promesa dice «desde», nunca un número que la
		// variación cara no cumple.
		$desde = method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) ? 'desde ' : ''; // @phpstan-ignore function.alreadyNarrowedType

		$programa_url = ProgramPage::url();

		// Expresión equivalente en pesos según la tasa de canje configurada en el panel.
		// El texto es único, conciso y uniforme entre todas las fichas (invitado y sesión
		// iniciada), evitando contaminación de caché en LiteSpeed/Cloudflare y cortes
		// ("creando tu ...") en extractos y pantallas estrechas.
		$texto = sprintf(
			'Gana <strong>%s%s Puntos Akibara = %s</strong> con este tomo.',
			$desde,
			number_format_i18n( $points ),
			Options::points_label( $points )
		);

		// El relleno vertical y white-space:nowrap proporcionan un blanco de toque accesible
		// (mínimo 24px) y previenen particiones huérfanas de línea en viewport móvil.
		$enlace = '' !== $programa_url
			? sprintf( ' <a href="%s" style="text-decoration:underline;padding:4px 0;white-space:nowrap">Ver programa</a>', esc_url( $programa_url ) )
			: '';

		$this->impreso = $product->get_id();

		// El margen de arriba es el de la línea de despacho (12 px), la vecina
		// de arriba bajo el botón. La estrella usa el rojo de texto sobre fondo
		// oscuro (5.6:1 contra #0e0e10; --aki-red daba 4.31:1, bajo el 4.5:1 AA).
		// data-nosnippet evita que buscadores indexen o trunquen el aviso de puntos
		// como descripción del producto en lugar de su sinopsis editorial.
		printf(
			'<p class="akb-rw-pdp-points" data-nosnippet style="margin:12px 0 0;font-size:14px;line-height:1.45;color:var(--aki-gray-200,#e0e0e0)"><span aria-hidden="true" style="color:var(--aki-red-text,#ff4756);margin-right:4px">★</span>%s%s</p>',
			wp_kses_post( $texto ),
			wp_kses_post( $enlace )
		);
	}
}
