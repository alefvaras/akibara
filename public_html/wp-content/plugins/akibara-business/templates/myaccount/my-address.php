<?php
/**
 * /mi-cuenta/edit-address/ (listado) — override del default WC.
 *
 * Default WC renderea cada dirección como `<header>title + .edit link</header>
 * + <address>contenido</address>`. Sin border, sin spacing visual — se ve
 * como texto a pelo sobre fondo.
 *
 * Acá envolvemos cada dirección en una card con border + padding + botón
 * "Editar" prominente. Para Akibara solo hay billing (shipping ocultado por
 * diseño, ver `feedback_akibara_edit_address_billing_only`).
 *
 * Preservamos:
 *   - filter `woocommerce_my_account_get_addresses` (qué tipos de dir)
 *   - filter `woocommerce_my_account_my_address_description` (copy header)
 *   - action `woocommerce_my_account_after_my_address` (slot extensión)
 *
 * @package Akibara\Business
 * @version 9.3.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$customer_id = get_current_user_id();

if ( ! wc_ship_to_billing_address_only() && wc_shipping_enabled() ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
	$get_addresses = apply_filters(
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
		'woocommerce_my_account_get_addresses',
		array(
			'billing'  => 'Dirección de facturación',
			'shipping' => 'Dirección de envío',
		),
		$customer_id
	);
} else {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
	$get_addresses = apply_filters(
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
		'woocommerce_my_account_get_addresses',
		array(
			'billing' => 'Dirección de facturación',
		),
		$customer_id
	);
}
?>

<p class="akb-address-intro">
	<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ echo wp_kses_post( apply_filters( 'woocommerce_my_account_my_address_description', 'Estas son tus direcciones guardadas. Las usamos al pagar.' ) ); ?>
</p>

<div class="akb-addresses">
	<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global. */ foreach ( $get_addresses as $name => $address_title ) :
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
		$address = wc_get_account_formatted_address( $name );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
		$has_address = ! empty( $address );
		?>
		<article class="akb-address-card<?php echo $has_address ? '' : ' akb-address-card--empty'; ?>">

			<header class="akb-address-card__header">
				<h2 class="akb-address-card__title"><?php echo esc_html( $address_title ); ?></h2>
				<?php if ( $has_address ) : ?>
					<span class="akb-address-card__badge">Por defecto</span>
				<?php endif; ?>
			</header>

			<div class="akb-address-card__body">
				<?php if ( $has_address ) : ?>
					<address class="akb-address-card__address"><?php echo wp_kses_post( $address ); ?></address>
				<?php else : ?>
					<p class="akb-address-card__empty">Todavía no agregaste una dirección de este tipo.</p>
				<?php endif; ?>

				<?php
				/**
				 * Hook WC 8.7+ — permite a plugins inyectar contenido tras
				 * el cuerpo del address (e.g. mapa, validación visual).
				 */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
				do_action( 'woocommerce_my_account_after_my_address', $name );
				?>
			</div>

			<a class="akb-address-card__edit" href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address', $name ) ); ?>">
				<?php echo esc_html( $has_address ? 'Editar dirección' : 'Agregar dirección' ); ?>
			</a>

		</article>
	<?php endforeach; ?>
</div>
