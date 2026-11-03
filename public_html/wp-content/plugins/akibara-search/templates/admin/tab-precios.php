<?php
/**
 * Template: Tab Rangos de Precio de SearchAdminSection.
 *
 * @package Akibara\Search
 * @var array<string, mixed> $view_data
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

use Akibara\Search\PriceBuckets;

$auto     = ! empty( $view_data['auto'] );
$has      = ! empty( $view_data['has'] );
$rows     = (array) ( $view_data['rows'] ?? array() );
$counts   = (array) ( $view_data['counts'] ?? array() );
$real     = (int) ( $view_data['real'] ?? 0 );
$max_rows = (int) ( $view_data['max_rows'] ?? 8 );
?>
<h2>Rangos de precio 
	<span class="akb-mode-badge" style="font-size:11px;font-weight:800;letter-spacing:0.5px;text-transform:uppercase;padding:2px 8px;border-radius:3px;vertical-align:middle;border:1px solid rgba(0,0,0,0.12);box-shadow:1px 1px 0 rgba(0,0,0,0.08);<?php echo $auto ? 'background:#d5e8d4;color:#1e4620' : 'background:#fff3cd;color:#664d03'; ?>">
		<?php echo $auto ? 'Automático' : 'Manual'; ?>
	</span>
</h2>
<p class="description">Los tramos del filtro «Precio» se calculan solos desde los precios reales del catálogo y se actualizan en cada reconstrucción del índice. Si editas un mínimo o máximo acá, pasan a modo <strong>Manual</strong> (la reconstrucción ya no los pisa); «Recalcular» vuelve al automático. Todo aplica al instante, <strong>sin reconstruir el índice</strong>.</p>
<p class="description">Cada tramo va desde su mínimo (incluido) hasta su máximo (<strong>excluido</strong>): el máximo de un tramo es el mínimo del siguiente, sin huecos ni solapes. Primer mínimo vacío = sin tope inferior; último máximo vacío = «o más». <strong>La etiqueta se genera sola del rango</strong> (por eso nunca puede mentir sobre el corte). Para quitar un tramo, vacía sus campos. Montos en pesos, sin puntos (ej: <code>10000</code>).</p>

<?php if ( ! $has ) : ?>
	<div class="notice notice-warning inline">
		<p>Todavía no hay rangos derivados (la faceta Precio está oculta en la tienda). Usa «Recalcular desde el catálogo» para generarlos ahora, o espera a la próxima reconstrucción del índice.</p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'akb_search_precios_nonce' ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( 'akb_search_save_precios' ); ?>">

	<div class="akb-admin-scroll">
		<table class="widefat striped akb-precios-table">
			<thead>
				<tr>
					<th scope="col">Desde (mín.)</th>
					<th scope="col">Hasta (máx.)</th>
					<th scope="col">Etiqueta (automática)</th>
					<th scope="col">Corte efectivo · productos</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $i => $bucket ) : ?>
					<?php
					$is_real     = $i < $real;
					$cut_preview = '—';
					if ( $is_real ) {
						$b_min = $bucket['min'];
						$b_max = $bucket['max'];
						$cut   = null === $b_min
							? '&lt; $' . number_format( (int) $b_max, 0, ',', '.' )
							: ( null === $b_max
								? '≥ $' . number_format( (int) $b_min, 0, ',', '.' )
								: '≥ $' . number_format( (int) $b_min, 0, ',', '.' ) . ' y &lt; $' . number_format( (int) $b_max, 0, ',', '.' ) );
						$cut_preview = $cut . ' · <strong>' . number_format( (int) ( $counts[ $i ] ?? 0 ), 0, ',', '.' ) . '</strong> productos';
					}
					$label_cell = $is_real
						? esc_html( PriceBuckets::derive_label( $bucket['min'] ?? null, $bucket['max'] ?? null ) )
						: '—';
					?>
					<tr>
						<td data-label="Desde (mín.)">
							<input type="number" name="precio_min[]" value="<?php echo esc_attr( null === ( $bucket['min'] ?? null ) ? '' : (string) $bucket['min'] ); ?>" min="0" step="1" inputmode="numeric" placeholder="<?php echo esc_attr( 0 === $i ? 'sin tope' : '' ); ?>" aria-label="Mínimo del tramo <?php echo (int) $i + 1; ?>" style="width:8em">
						</td>
						<td data-label="Hasta (máx.)">
							<input type="number" name="precio_max[]" value="<?php echo esc_attr( null === ( $bucket['max'] ?? null ) ? '' : (string) $bucket['max'] ); ?>" min="0" step="1" inputmode="numeric" placeholder="<?php echo esc_attr( ( $is_real && $i === $real - 1 ) ? 'o más' : '' ); ?>" aria-label="Máximo del tramo <?php echo (int) $i + 1; ?>" style="width:8em">
						</td>
						<td data-label="Etiqueta (automática)"><?php echo $label_cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="description" data-label="Corte efectivo · productos"><?php echo wp_kses( $cut_preview, array( 'strong' => array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<p class="description">Entre 2 y <?php echo (int) $max_rows; ?> tramos.</p>

	<p>
		<?php submit_button( 'Guardar rangos', 'primary', 'submit', false ); ?>
		<?php echo ' '; ?>
		<?php submit_button( 'Recalcular desde el catálogo (modo automático)', 'secondary', 'recalc', false, array( 'style' => 'max-width:100%;white-space:normal;height:auto;line-height:1.4' ) ); ?>
	</p>
</form>
