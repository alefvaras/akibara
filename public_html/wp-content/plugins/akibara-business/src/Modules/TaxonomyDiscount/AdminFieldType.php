<?php
/**
 * AdminFieldType — custom WC field type `akb_taxonomy_pct_row`.
 *
 * Renderea una fila por término (de la taxonomía del sub-tab activo) con:
 * nombre + input % + count de productos. Persiste term-meta
 * `_akb_taxonomy_discount_pct` via `Settings::set_term_pct`.
 *
 * El input name embebe `term_id` (único cross-taxonomy en WP), no la taxonomía.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.16.1
 */
final class AdminFieldType {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'woocommerce_admin_field_akb_taxonomy_pct_row', array( $this, 'render_row' ) );
		add_action( 'woocommerce_update_option_akb_taxonomy_pct_row', array( $this, 'save_row' ) );

		// Accordion wrappers para agrupar campos bajo un `<details>` colapsable.
		// `akb_accordion_start` abre el details + summary; `akb_accordion_end` cierra.
		add_action( 'woocommerce_admin_field_akb_accordion_start', array( $this, 'render_accordion_start' ) );
		add_action( 'woocommerce_admin_field_akb_accordion_end', array( $this, 'render_accordion_end' ) );

		// Helper para insertar el filter bar dentro de un accordion.
		add_action( 'woocommerce_admin_field_akb_filter_bar', array( $this, 'render_filter_bar' ) );
	}

	/**
	 * @param array<string, mixed> $value Field config con term_id, title, current_pct,
	 *                                    product_count.
	 * @return void
	 */
	public function render_row( array $value ): void {
		$term_id     = isset( $value['term_id'] ) ? (int) $value['term_id'] : 0;
		$title       = isset( $value['title'] ) ? (string) $value['title'] : '';
		$current_pct = isset( $value['current_pct'] ) ? (int) $value['current_pct'] : 0;
		$product_cnt = isset( $value['product_count'] ) ? (int) $value['product_count'] : 0;

		if ( 0 === $term_id ) {
			return;
		}

		$input_name = 'akibara_taxonomy_discount_pct_' . $term_id;
		$desc_id    = $input_name . '_desc';
		$display    = $current_pct > 0 ? (string) $current_pct : '';
		?>
		<tr valign="top" class="akb-taxonomy-discount-row">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $input_name ); ?>">
					<?php echo esc_html( $title ); ?>
				</label>
			</th>
			<td class="forminp forminp-akb-taxonomy-pct-row">
				<input
					type="number"
					inputmode="numeric"
					pattern="[0-9]*"
					id="<?php echo esc_attr( $input_name ); ?>"
					name="<?php echo esc_attr( $input_name ); ?>"
					value="<?php echo esc_attr( $display ); ?>"
					min="0"
					max="<?php echo esc_attr( (string) $this->settings->max_pct() ); ?>"
					step="1"
					style="width:80px;"
					placeholder="0"
					aria-describedby="<?php echo esc_attr( $desc_id ); ?>"
				/>
				<span style="margin-left: 6px; color: #50575e;">%</span>
				<p class="description" id="<?php echo esc_attr( $desc_id ); ?>" style="margin-top:4px; color:#666;">
					<?php
					printf(
						'%d %s.',
						(int) $product_cnt,
						1 === $product_cnt ? 'producto' : 'productos'
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Llamado por WC vía `do_action('woocommerce_update_option_akb_taxonomy_pct_row', $value)`.
	 *
	 * @param array<string, mixed> $value Field config.
	 * @return void
	 */
	public function save_row( array $value ): void {
		$term_id = isset( $value['term_id'] ) ? (int) $value['term_id'] : 0;
		if ( 0 === $term_id ) {
			return;
		}

		$input_name = 'akibara_taxonomy_discount_pct_' . $term_id;

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- AdminPanel verifica nonce antes de delegar.
		if ( ! array_key_exists( $input_name, $_POST ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cada tipo de campo lo sanea a su manera justo debajo; sanearlo aquí en genérico rompería los que llevan lista o número.
		$raw = wp_unslash( $_POST[ $input_name ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$raw = trim( (string) $raw );
		$pct = '' === $raw ? 0 : max( 0, min( $this->settings->max_pct(), (int) $raw ) );

		// Comparar current_pct con lo enviado antes de escribir termmeta.
		// Incluso borrar una fila ausente puede disparar la publicación editorial;
		// un guardado sin cambio no debe reconstruir el índice. El action de pestaña
		// sólo registra que se guardó, no confirma un cambio de precio.

		$pintado = array_key_exists( 'current_pct', $value ) ? (int) $value['current_pct'] : null;
		if ( null !== $pintado && $pct === $pintado ) {
			do_action( 'akibara_taxonomy_discount_pct_saved', $term_id, $pct );
			return;
		}

		$this->settings->set_term_pct( $term_id, $pct );
		do_action( 'akibara_taxonomy_discount_pct_saved', $term_id, $pct );
	}

	/**
	 * Render del `<details>` open. Cierra antes el `<table class="form-table">`
	 * que WC abrió implícitamente, abre el details, y vuelve a abrir un
	 * nuevo form-table dentro para que los rows siguientes encajen.
	 *
	 * @param array<string, mixed> $value Field config con `title`, `summary_meta`
	 *                                    (texto del summary derecho), `id`,
	 *                                    `open` (bool — abrir por default).
	 * @return void
	 */
	public function render_accordion_start( array $value ): void {
		$title   = isset( $value['title'] ) ? (string) $value['title'] : '';
		$meta    = isset( $value['summary_meta'] ) ? (string) $value['summary_meta'] : '';
		$id      = isset( $value['id'] ) ? (string) $value['id'] : '';
		$open    = ! empty( $value['open'] );
		$variant = isset( $value['variant'] ) ? (string) $value['variant'] : 'taxonomy';

		// NO cerrar `</table>` outer porque WC `woocommerce_admin_fields()` solo
		// abre `<table class="form-table">` cuando encuentra un field tipo `title`.
		// Nuestros fields son todos custom types, no usamos `title`, por lo cual
		// no hay table outer abierto. El accordion abre su propio table interno.
		?>
		<details
			class="akb-accordion akb-accordion--<?php echo esc_attr( $variant ); ?>"
			id="<?php echo esc_attr( $id ); ?>"
			<?php echo $open ? 'open' : ''; ?>
		>
			<summary class="akb-accordion__summary">
				<span class="akb-accordion__title"><?php echo esc_html( $title ); ?></span>
				<?php if ( '' !== $meta ) : ?>
					<span class="akb-accordion__meta"><?php echo esc_html( $meta ); ?></span>
				<?php endif; ?>
			</summary>
			<div class="akb-accordion__body">
				<table class="form-table" role="presentation">
		<?php
	}

	/**
	 * Cierra el `<details>` (incluyendo su table interno). No reabre table
	 * outer porque no hay table outer abierto (ver render_accordion_start).
	 *
	 * @param array<string, mixed> $value Field config (no usa props).
	 * @return void
	 */
	public function render_accordion_end( array $value ): void {
		?>
				</table>
			</div>
		</details>
		<?php
	}

	/**
	 * Filter bar (search + toggle %>0) — wrapper como field type para
	 * que encaje dentro del `<details>` accordion como un row más.
	 *
	 * @param array<string, mixed> $value Field config con `accordion_id` (slug
	 *                                    para scope del JS al accordion).
	 * @return void
	 */
	public function render_filter_bar( array $value ): void {
		$accordion_id = isset( $value['accordion_id'] ) ? sanitize_key( (string) $value['accordion_id'] ) : '';
		$search_id    = 'akb-filter-search-' . $accordion_id;
		$toggle_id    = 'akb-filter-toggle-' . $accordion_id;
		$counter_id   = 'akb-filter-counter-' . $accordion_id;
		?>
		<tr>
			<td colspan="2" class="akb-filter-bar-cell">
				<div class="akb-tax-filter-bar" data-accordion="<?php echo esc_attr( $accordion_id ); ?>">
						<label class="akb-tax-filter-bar__search" for="<?php echo esc_attr( $search_id ); ?>">
							<span>Buscar término</span>
							<input
								type="search"
								id="<?php echo esc_attr( $search_id ); ?>"
								class="akb-filter-search"
								placeholder="Buscar término…"
							/>
					</label>
					<label style="display:flex;gap:6px;align-items:center;cursor:pointer;">
						<input type="checkbox" id="<?php echo esc_attr( $toggle_id ); ?>" class="akb-filter-toggle" />
						<span>Solo con % &gt; 0</span>
					</label>
					<span class="akb-counter akb-filter-counter" id="<?php echo esc_attr( $counter_id ); ?>" aria-live="polite"></span>
				</div>
			</td>
		</tr>
		<?php
	}
}
