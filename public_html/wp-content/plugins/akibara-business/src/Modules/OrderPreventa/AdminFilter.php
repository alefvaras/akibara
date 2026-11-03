<?php
/**
 * Dropdown filter arriba de la lista de órdenes para filtrar por estado de
 * preventa.
 *
 * Opciones:
 *   - Todas las órdenes (sin filtro)
 *   - Solo preventa pendiente (has_preventa=yes AND ready_to_ship!=yes,
 *     incluida la ausencia de la meta —)
 *   - Solo preventa lista     (has_preventa=yes AND ready_to_ship=yes)
 *   - Sin preventa            (has_preventa=no)
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

defined( 'ABSPATH' ) || exit;

/**
 * Filter dropdown integrado al toolbar admin de órdenes.
 *
 * @since 1.14.0
 */
final class AdminFilter {

	public const QUERY_VAR = 'akb_preventa_filter';

	public function register(): void {
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_dropdown' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'apply_filter' ) );
	}

	/**
	 * Renderiza el <select> en el toolbar.
	 *
	 * @return void
	 */
	public function render_dropdown(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtro del listado de pedidos del admin: el GET sólo elige qué se lista, no muta nada, y el valor pasa por sanitize_text_field() en la línea siguiente.
		$current = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		$options = array(
			''                   => 'Todas las órdenes',
			'pending'            => 'Solo preventa pendiente',
			'ready'              => 'Solo preventa lista',
			'no_preventa'        => 'Sin preventa',
		);
		?>
		<select name="<?php echo esc_attr( self::QUERY_VAR ); ?>" id="<?php echo esc_attr( self::QUERY_VAR ); ?>">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Aplica meta_query según selección.
	 *
	 * @param array<string, mixed> $args Query args para wc_get_orders.
	 * @return array<string, mixed>
	 */
	public function apply_filter( array $args ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtro del listado de pedidos del admin: el GET sólo elige qué se lista, no muta nada, y el valor pasa por sanitize_text_field() en la línea siguiente.
		$filter = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $filter ) {
			return $args;
		}

		$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

		switch ( $filter ) {
			case 'pending':
				$meta_query[] = array(
					'key'   => MetaKeys::ORDER_HAS_PREVENTA,
					'value' => 'yes',
				);
				// Un pedido sin meta de listo también es candidato: sólo yes significa listo.
				// Esto incluye altas manuales, importadas o API que aún no fueron etiquetadas.

				$meta_query[] = array(
					'relation' => 'OR',
					array(
						'key'     => MetaKeys::ORDER_READY_TO_SHIP,
						'value'   => 'yes',
						'compare' => '!=',
					),
					array(
						'key'     => MetaKeys::ORDER_READY_TO_SHIP,
						'compare' => 'NOT EXISTS',
					),
				);
				break;

			case 'ready':
				$meta_query[] = array(
					'key'   => MetaKeys::ORDER_HAS_PREVENTA,
					'value' => 'yes',
				);
				$meta_query[] = array(
					'key'   => MetaKeys::ORDER_READY_TO_SHIP,
					'value' => 'yes',
				);
				break;

			case 'no_preventa':
				$meta_query[] = array(
					'relation' => 'OR',
					array(
						'key'     => MetaKeys::ORDER_HAS_PREVENTA,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => MetaKeys::ORDER_HAS_PREVENTA,
						'value' => 'no',
					),
				);
				break;
		}

		$args['meta_query'] = $meta_query;
		return $args;
	}
}
