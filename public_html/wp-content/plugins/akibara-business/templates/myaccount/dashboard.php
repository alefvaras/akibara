<?php
/**
 * /mi-cuenta/ dashboard — override del default WC.
 *
 * Default WC imprime "Hello user_login (not user_login? Log out)" + un
 * párrafo descriptivo con links inline. Para Akibara:
 *   - Saludo con first_name (no user_login técnico).
 *   - Sin "¿no eres X?" — sin sentido cuando está logueado.
 *   - Acciones primarias como cards (hit target generoso).
 *   - "Cerrar sesión" como botón con ≥44px, no link inline diminuto.
 *
 * Resuelto vía woocommerce_locate_template en
 * `Modules\MyAccountUx\MyAccountUx`. Si el theme decide overridear
 * `woocommerce/myaccount/dashboard.php` por su cuenta, ese gana
 * (WC busca theme primero).
 *
 * @package Akibara\Business
 * @version 4.4.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$current_user = wp_get_current_user();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$first_name   = trim( (string) $current_user->first_name );
if ( '' === $first_name ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
	$first_name = (string) $current_user->display_name;
}
if ( '' === $first_name ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
	$parts      = explode( '@', (string) $current_user->user_email );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
	$first_name = $parts[0];
}

// Dato real para la card primaria. wc_get_customer_order_count() está cacheado en
// user meta (wc_order_count): 1 lookup, no una query de órdenes completa.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$order_count = (int) wc_get_customer_order_count( get_current_user_id() );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$orders_meta = 0 === $order_count
	? 'Sin pedidos aún'
	: sprintf(
		1 === $order_count ? '%s pedido' : '%s pedidos',
		number_format_i18n( $order_count )
	);
?>
<div class="akb-dashboard">

	<p class="akb-dashboard__greeting">
		Hola, <strong><?php echo esc_html( $first_name ); ?></strong>.
	</p>

	<nav class="akb-dashboard__actions" aria-label="Accesos rápidos">
		<a class="akb-dashboard__card akb-dashboard__card--primary" href="<?php echo esc_url( wc_get_endpoint_url( 'orders' ) ); ?>">
			<span class="akb-dashboard__card-title">Pedidos</span>
			<span class="akb-dashboard__card-desc">Seguimiento y estado de tus compras.</span>
			<span class="akb-dashboard__card-meta"><?php echo esc_html( $orders_meta ); ?></span>
		</a>
		<a class="akb-dashboard__card" href="<?php echo esc_url( wc_get_endpoint_url( 'coleccion' ) ); ?>">
			<span class="akb-dashboard__card-title">Colección</span>
			<span class="akb-dashboard__card-desc">Tus tomos, el avance de cada edición y lo que te falta.</span>
			<span class="akb-dashboard__card-meta">Estantería y alertas de salida</span>
		</a>
		<?php
		/**
		 * La tarjeta de puntos sólo existe si el programa está activo: el
		 * endpoint `rewards` lo registra Simple Points and Rewards, así que sin
		 * el plugin este enlace sería un 404. Se comprueba por la función que
		 * lee el saldo, que es la misma pieza que provee el dato del meta.
		 */
		if ( function_exists( 'spar_get_user_points' ) ) :
			$akb_points = (int) spar_get_user_points( get_current_user_id() );

			/**
			 * Cuánto valen esos puntos, DICHO POR QUIEN LOS PAGA.
			 *
			 * Esta tarjeta los convertía con `wc_price()`, que es uno a uno:
			 * el panel del programa permite cambiar «Pesos de descuento por
			 * punto canjeado» con un clic y, en cuanto dejara de valer 1, Mi
			 * cuenta prometía un descuento que la caja no iba a dar (hallazgo
			 * S-c94a30df). El cambio vive en el plugin de puntos y se pide por
			 * filtro, como el vocabulario del buscador: si nadie responde
			 * —porque el programa no está—, la tarjeta muestra los puntos y
			 * calla el monto en vez de inventarlo.
			 *
			 * @since 1.116.22
			 *
			 * @param string $label  Equivalencia ya formateada («$1.250»); '' si nadie la sabe.
			 * @param int    $points Puntos del cliente.
			 */
			$akb_points_label = (string) apply_filters( 'akb_rewards_points_label', '', $akb_points );
			?>
		<a class="akb-dashboard__card" href="<?php echo esc_url( wc_get_endpoint_url( 'rewards' ) ); ?>">
			<span class="akb-dashboard__card-title">Puntos Akibara</span>
			<span class="akb-dashboard__card-desc">Tus Puntos Akibara y el descuento que puedes usar.</span>
			<span class="akb-dashboard__card-meta">
				<?php
				if ( 0 === $akb_points ) {
					$akb_meta = 'Aún sin puntos';
				} elseif ( '' === $akb_points_label ) {
					$akb_meta = sprintf(
						1 === $akb_points ? '%s punto' : '%s puntos',
						number_format_i18n( $akb_points )
					);
				} else {
					$akb_meta = sprintf(
						1 === $akb_points ? '%1$s punto · %2$s' : '%1$s puntos · %2$s',
						number_format_i18n( $akb_points ),
						$akb_points_label
					);
				}

				echo esc_html( $akb_meta );
				?>
			</span>
		</a>
		<?php endif; ?>
		<a class="akb-dashboard__card" href="<?php echo esc_url( wc_get_endpoint_url( 'favoritos' ) ); ?>">
			<span class="akb-dashboard__card-title">Favoritos</span>
			<span class="akb-dashboard__card-desc">Guardados para después.</span>
		</a>
		<a class="akb-dashboard__card" href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address' ) ); ?>">
			<span class="akb-dashboard__card-title">Direcciones</span>
			<span class="akb-dashboard__card-desc">Envío y facturación.</span>
		</a>
		<a class="akb-dashboard__card" href="<?php echo esc_url( wc_get_endpoint_url( 'edit-account' ) ); ?>">
			<span class="akb-dashboard__card-title">Detalles</span>
			<span class="akb-dashboard__card-desc">Nombre, correo y contraseña.</span>
		</a>
	</nav>

	<a class="akb-dashboard__logout" href="<?php echo esc_url( wc_logout_url() ); ?>">
		Cerrar sesión
	</a>

</div>

<?php
/**
 * Mantengo los do_action() del template default por si algún plugin
 * (Wishlist counters, mensajes promo, etc.) se cuelga acá.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
do_action( 'woocommerce_account_dashboard' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
do_action( 'woocommerce_before_my_account' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
do_action( 'woocommerce_after_my_account' );
