<?php
/**
 * Template: Vista de Consolidación por Pedido / Casillero.
 *
 * @package Akibara\Business
 * @var array<string, mixed> $view_data
 * @var \Akibara\Business\Modules\OrderPreventa\PreventasScreen $this
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Modules\OrderPreventa\PreventasScreen;

$search_q          = (string) ( $view_data['search_q'] ?? '' );
$filtro_pedido     = (string) ( $view_data['filtro_pedido'] ?? 'todos' );
$pedidos_filtrados = (array) ( $view_data['pedidos_filtrados'] ?? array() );
$total_pedidos     = (int) ( $view_data['total_pedidos'] ?? count( $pedidos_filtrados ) );
$pedidos           = (array) ( $view_data['pedidos'] ?? array() );
$current_paged     = (int) ( $view_data['paged'] ?? 1 );
$max_pages         = (int) ( $view_data['max_pages'] ?? 1 );
$base_url          = (string) ( $view_data['base_url'] ?? '' );
$panel_page        = (string) ( $view_data['panel_page'] ?? 'akibara-panel' );
$panel_tab         = (string) ( $view_data['panel_tab'] ?? 'preventas' );

$filtros = array(
	'todos'        => 'Todos los pedidos',
	'por_sellar'   => '📦 Con tomos por sellar',
	'con_agotados' => '⚠️ Con tomos agotados en tienda',
	'vencidos'     => '⏰ Con promesa vencida',
	'listos'       => '✅ 100% listos en bodega',
);
?>

<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0;">
		<input type="hidden" name="page" value="<?php echo esc_attr( $panel_page ); ?>">
		<input type="hidden" name="tab" value="<?php echo esc_attr( $panel_tab ); ?>">
		<input type="hidden" name="vista" value="pedidos">

		<label for="akb-search-pedidos" class="screen-reader-text">Buscar por # pedido o cliente</label>
		<input type="search" id="akb-search-pedidos" name="q" value="<?php echo esc_attr( $search_q ); ?>" placeholder="Buscar por # pedido o cliente..." style="width:240px;">

		<label for="akb-filtro-pedidos" class="screen-reader-text">Filtrar pedidos</label>
		<select id="akb-filtro-pedidos" name="filtro_pedido">
			<?php foreach ( $filtros as $val => $lbl ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>"<?php echo $filtro_pedido === $val ? ' selected' : ''; ?>>
					<?php echo esc_html( $lbl ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="button">Filtrar</button>
		<?php if ( '' !== $search_q || 'todos' !== $filtro_pedido ) : ?>
			<a class="button" href="<?php echo esc_url( PreventasScreen::url_vista( 'pedidos' ) ); ?>">Limpiar</a>
		<?php endif; ?>
	</form>
</div>

<?php if ( empty( $pedidos_filtrados ) ) : ?>
	<p>No se encontraron pedidos que coincidan con los filtros aplicados.</p>
	<?php return; ?>
<?php endif; ?>

<p>
	Mostrando <strong><?php echo (int) $total_pedidos; ?> <?php echo 1 === $total_pedidos ? 'pedido activo' : 'pedidos activos'; ?></strong> en proceso de recepción o consolidación en bodega:
</p>

<?php
$ahora = time();
foreach ( $pedidos as $wc_order ) {
	$this->render_tarjeta_casillero( $wc_order, $ahora );
}

$this->render_paginacion( $total_pedidos, $current_paged, $max_pages, $base_url, 1 === $total_pedidos ? 'pedido' : 'pedidos' );
?>
