<?php
/**
 * /mi-cuenta/orders/ — override del default WC con layout card-based.
 *
 * Default WC usa `<table class="my_account_orders shop_table_responsive">`
 * que colapsa filas a 2 columnas label/value en mobile. Funciona pero pierde
 * jerarquía: el usuario no ve qué compró (sin thumbnails) ni qué acciones
 * tiene disponibles sin scrollear horizontal.
 *
 * Acá renderemos cada pedido como una card con:
 *   - Thumbnail del primer producto + count "+N" si tiene más
 *   - Header: #orderNumber · fecha
 *   - Badge de estado (color según estado WC)
 *   - Total + nº items
 *   - Slot para columnas custom (OrderPreventa "Llegada") via do_action
 *   - Acciones: las que devuelva `wc_get_account_orders_actions()` (Ver pedido,
 *     Pagar, Cancelar) — full-width en mobile. «Volver a comprar» NO se ofrece:
 *     ver `MyAccountUx::orders_actions()`.
 *
 * Preserva `do_action( 'woocommerce_my_account_my_orders_column_*' )` para
 * que plugins (OrderPreventa "Llegada") puedan inyectar contenido.
 *
 * @package Akibara\Business
 * @version 9.5.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
do_action( 'woocommerce_before_account_orders', $has_orders );

if ( ! $has_orders ) {
	?>
	<div class="akb-orders-empty">
		<p class="akb-orders-empty__title">Aún no tienes pedidos.</p>
		<p class="akb-orders-empty__desc">Cuando compres un tomo lo vas a ver acá.</p>
		<a class="akb-orders-empty__cta" href="<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">Explorar catálogo</a>
	</div>
	<?php
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
	do_action( 'woocommerce_after_account_orders', $has_orders );
	return;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$standard_columns = array( 'order-number', 'order-date', 'order-status', 'order-total', 'order-actions' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$all_columns      = wc_get_account_orders_columns();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
$extra_columns    = array_diff_key( $all_columns, array_flip( $standard_columns ) );
?>

<div class="akb-orders">

	<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global. */ foreach ( $customer_orders->orders as $customer_order ) :
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
		$order      = wc_get_order( $customer_order );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
		$items      = $order->get_items();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
		$item_count = $order->get_item_count() - $order->get_item_count_refunded();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
		$status     = $order->get_status();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
		$actions    = wc_get_account_orders_actions( $order );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
		$column_hook = static fn( string $column_id ): string => 'woocommerce_my_account_my_orders_column_' . $column_id;

		// Primer producto con imagen para el thumbnail.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
		$first_product = null;
		foreach ( $items as $item ) {
			$prod = $item->get_product();
			if ( $prod instanceof \WC_Product ) {
				$first_product = $prod;
				break;
			}
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		// Si no hay producto (producto eliminado) o no tiene imagen, usar
		// el placeholder oficial de WC en vez de un thumbnail vacío.
		if ( $first_product instanceof \WC_Product ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
			$thumb_html = $first_product->get_image(
				'woocommerce_thumbnail',
				array( 'class' => 'akb-orders-card__thumb-img', 'loading' => 'lazy' )
			);
		} else {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
			$thumb_html = wc_placeholder_img(
				'woocommerce_thumbnail',
				array( 'class' => 'akb-orders-card__thumb-img', 'alt' => '' )
			);
		}
		?>
		<article class="akb-orders-card akb-orders-card--status-<?php echo esc_attr( $status ); ?>">

			<div class="akb-orders-card__thumb akb-cover-cage">
				<?php
				echo $thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- es el marcado de imagen que devuelven WC_Product::get_image() y wc_placeholder_img(), HTML de WooCommerce core; escaparlo imprimiría la etiqueta como texto.
				if ( $item_count > 1 ) :
					?>
					<span class="akb-orders-card__thumb-badge">+<?php echo (int) ( $item_count - 1 ); ?></span>
				<?php endif; ?>
			</div>

			<div class="akb-orders-card__body">

				<div class="akb-orders-card__header">
					<?php if ( has_action( $column_hook( 'order-number' ) ) ) : ?>
						<div class="akb-orders-card__number"><?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( $column_hook( 'order-number' ), $order ); ?></div>
					<?php else : ?>
						<a class="akb-orders-card__number" href="<?php echo esc_url( $order->get_view_order_url() ); ?>" aria-label="<?php echo esc_attr( sprintf( 'Ver pedido %s', $order->get_order_number() ) ); ?>">
							#<?php echo esc_html( $order->get_order_number() ); ?>
						</a>
					<?php endif; ?>
					<?php if ( has_action( $column_hook( 'order-date' ) ) ) : ?>
						<div class="akb-orders-card__date"><?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( $column_hook( 'order-date' ), $order ); ?></div>
					<?php else : ?>
						<time class="akb-orders-card__date" datetime="<?php echo esc_attr( $order->get_date_created()->date( 'c' ) ); ?>">
							<?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
						</time>
					<?php endif; ?>
				</div>

				<div class="akb-orders-card__meta">
					<span class="akb-orders-card__status akb-orders-card__status--<?php echo esc_attr( $status ); ?>">
						<?php
						if ( has_action( $column_hook( 'order-status' ) ) ) {
							// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
							do_action( $column_hook( 'order-status' ), $order );
						} else {
							echo esc_html( wc_get_order_status_name( $status ) );
						}
						?>
					</span>
					<span class="akb-orders-card__total">
						<?php if ( has_action( $column_hook( 'order-total' ) ) ) : ?>
							<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( $column_hook( 'order-total' ), $order ); ?>
						<?php else : ?>
							<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
							<span class="akb-orders-card__items">· <?php echo (int) $item_count; ?> <?php echo 1 === $item_count ? 'tomo' : 'tomos'; ?></span>
						<?php endif; ?>
					</span>
				</div>

				<?php
				if ( ! empty( $extra_columns ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
					foreach ( $extra_columns as $column_id => $column_label ) {
						if ( ! has_action( 'woocommerce_my_account_my_orders_column_' . $column_id ) ) {
							continue;
						}

						ob_start();
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
						do_action( 'woocommerce_my_account_my_orders_column_' . $column_id, $order );
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
						$column_value = (string) ob_get_clean();
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
						$raw_value    = trim( $column_value );
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
						$plain_value  = html_entity_decode(
							trim( wp_strip_all_tags( $column_value ) ),
							ENT_QUOTES | ENT_HTML5,
							'UTF-8'
						);
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
						$plain_value  = trim( (string) preg_replace( '/[\p{Z}\s]+/u', ' ', $plain_value ) );
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
						$has_visual   = 1 === preg_match( '/<(?:img|svg|canvas|video|audio|meter|progress)\b/i', $raw_value );

						// En las cards, una columna vacía o "—" solo agrega altura sin
						// información. Conservamos contenido visual aunque no tenga texto.
						if (
							'' === $raw_value
							|| ( '' === $plain_value && ! $has_visual )
							|| '—' === $plain_value
						) {
							continue;
						}

						echo '<div class="akb-orders-card__extra" data-col="' . esc_attr( $column_id ) . '">';
						echo '<span class="akb-orders-card__extra-label">' . esc_html( $column_label ) . ':</span> ';
						echo '<span class="akb-orders-card__extra-value">';
						echo $column_value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado por callbacks registrados.
						echo '</span>';
						echo '</div>';
					}
				}
				?>

				<?php if ( has_action( $column_hook( 'order-actions' ) ) || ! empty( $actions ) ) : ?>
					<div class="akb-orders-card__actions">
						<?php
						if ( has_action( $column_hook( 'order-actions' ) ) ) {
							// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
							do_action( $column_hook( 'order-actions' ), $order );
						} else {
							// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
							foreach ( $actions as $key => $action ) {
								// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
								$is_primary = 'view' === $key;
								// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
								$cls        = 'akb-orders-card__action' . ( $is_primary ? ' akb-orders-card__action--primary' : ' akb-orders-card__action--secondary' );
								// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locales del render: wc_get_template() incluye la plantilla DENTRO de una función, no en el ámbito global.
								$aria       = isset( $action['aria-label'] ) ? $action['aria-label'] : sprintf( '%s pedido %s', $action['name'], $order->get_order_number() );
								printf(
									'<a href="%1$s" class="%2$s %3$s" aria-label="%4$s">%5$s</a>',
									esc_url( $action['url'] ),
									esc_attr( $cls ),
									esc_attr( sanitize_html_class( $key ) ),
									esc_attr( $aria ),
									esc_html( $action['name'] )
								);
							}
						}
						?>
					</div>
				<?php endif; ?>

			</div>

		</article>
	<?php endforeach; ?>

</div>

<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( 'woocommerce_before_account_orders_pagination' ); ?>

<?php if ( 1 < $customer_orders->max_num_pages ) : ?>
	<nav class="akb-orders-pagination" aria-label="Paginación de pedidos">
		<?php if ( 1 !== $current_page ) : ?>
			<a class="akb-orders-pagination__btn akb-orders-pagination__btn--prev" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page - 1 ) ); ?>">Anteriores</a>
		<?php endif; ?>
		<?php if ( (int) $customer_orders->max_num_pages !== $current_page ) : ?>
			<a class="akb-orders-pagination__btn akb-orders-pagination__btn--next" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page + 1 ) ); ?>">Siguientes</a>
		<?php endif; ?>
	</nav>
<?php endif; ?>

<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( 'woocommerce_after_account_orders', $has_orders ); ?>
