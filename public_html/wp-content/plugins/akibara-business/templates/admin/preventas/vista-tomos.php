<?php
/**
 * Template: Vista Principal por Tomos (Compras y Recepción).
 *
 * @package Akibara\Business
 * @var array<string, mixed> $view_data
 * @var \Akibara\Business\Modules\OrderPreventa\PreventasScreen $this
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Modules\OrderPreventa\PreventasScreen;

$completa        = (array) ( $view_data['completa'] ?? array() );
$total_pedidos   = (int) ( $view_data['total_pedidos'] ?? 0 );
$total_unidades  = (int) ( $view_data['total_unidades'] ?? 0 );
$fecha_pasada    = (int) ( $view_data['fecha_pasada'] ?? 0 );
$agotados_count  = (int) ( $view_data['agotados_count'] ?? 0 );
$por_editorial   = (array) ( $view_data['por_editorial'] ?? array() );
$vista           = (string) ( $view_data['vista'] ?? 'tomos' );
$current_paged   = (int) ( $view_data['paged'] ?? 1 );
$search_q        = (string) ( $view_data['search_q'] ?? '' );
$filtro_sel      = (string) ( $view_data['filtro_sel'] ?? 'todos' );
$editorial_sel   = (string) ( $view_data['editorial_sel'] ?? '' );
$tomos           = (array) ( $view_data['tomos'] ?? array() );
$total_filtrados = (int) ( $view_data['total_filtrados'] ?? count( $completa ) );
$max_pages       = (int) ( $view_data['max_pages'] ?? 1 );
$base_url        = (string) ( $view_data['base_url'] ?? '' );
$max_tomos       = (int) ( $view_data['max_tomos'] ?? 60 );
?>

<div class="akb-preventas">
	<h2>Lo que estás esperando</h2>

	<?php if ( empty( $completa ) ) : ?>
		<p>No hay ningún pedido esperando tomos ahora mismo. Cuando alguien compre algo «a pedido» o en preventa, aparecerá aquí hasta que llegue.</p>
		<?php
		$this->render_acciones_globales();
		?>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<p><strong><?php echo (int) count( $completa ); ?></strong> <?php echo 1 === count( $completa ) ? 'tomo pendiente' : 'tomos pendientes'; ?> en <strong><?php echo (int) $total_pedidos; ?></strong> <?php echo 1 === $total_pedidos ? 'pedido' : 'pedidos'; ?>.<?php if ( $fecha_pasada > 0 ) : ?> <strong style="color:#b32d2e;"><?php echo (int) $fecha_pasada; ?> con la fecha ya pasada.</strong><?php endif; ?><?php if ( count( $completa ) > $max_tomos && 1 === $current_paged ) : ?> <span style="color:#666;">(se muestran los <?php echo (int) $max_tomos; ?> más urgentes)</span><?php endif; ?></p>

	<!-- Barra de Tarjetas KPI operativas -->
	<?php
	$this->render_kpis_resumen(
		count( $completa ),
		$total_unidades,
		$total_pedidos,
		$por_editorial,
		$agotados_count,
		$fecha_pasada
	);
	?>

	<!-- Conmutador de vistas -->
	<ul class="subsubsub" style="margin-bottom:16px;">
		<li><a href="<?php echo esc_url( PreventasScreen::url_vista( 'tomos' ) ); ?>" class="<?php echo 'tomos' === $vista ? 'current' : ''; ?>">📖 Vista por Tomo (Compras y Recepción)</a> | </li>
		<li><a href="<?php echo esc_url( PreventasScreen::url_vista( 'pedidos' ) ); ?>" class="<?php echo 'pedidos' === $vista ? 'current' : ''; ?>">🗂️ Vista por Pedido / Casillero (Consolidación)</a></li>
	</ul>
	<div class="clear" style="clear:both;"></div>

	<?php if ( 'pedidos' === $vista ) : ?>
		<?php
		$this->render_vista_pedidos();
		$this->render_acciones_globales();
		?>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<!-- Barra de herramientas: Búsqueda, filtros y Exportar CSV -->
	<?php $this->render_barra_filtros_tomos( $search_q, $filtro_sel, $editorial_sel, $por_editorial ); ?>

	<!-- Tabla principal de tomos -->
	<div class="akb-preventas__wrap" style="overflow-x:auto;">
		<table class="widefat striped akb-preventas__table">
			<thead>
				<tr>
					<th scope="col">Tomo</th>
					<th scope="col" style="width:110px;">Stock</th>
					<th scope="col" style="width:160px;">Plazo / Lanzamiento</th>
					<th scope="col" style="width:90px;">Pedidos</th>
					<th scope="col">Quién espera</th>
					<th scope="col" style="width:190px;">Acción</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tomos as $t ) : ?>
					<?php $this->render_fila( $t ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<!-- Paginación -->
	<?php
	$this->render_paginacion( $total_filtrados, $current_paged, $max_pages, $base_url, 1 === $total_filtrados ? 'tomo' : 'tomos' );
	?>

	<p style="color:#666;font-size:12px;margin-top:16px;">
		Un tomo desaparece de esta lista cuando llega a stock o se aparta en casillero y se avisa a quien lo esperaba, o cuando devuelves el dinero de esa línea desde el propio pedido.
	</p>

	<?php $this->render_acciones_globales(); ?>
</div>
