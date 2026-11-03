<?php
/**
 * Template: Tab Búsquedas de SearchAdminSection.
 *
 * @package Akibara\Search
 * @var array<string, mixed> $view_data
 * @var \Akibara\Search\SearchAdminSection $this
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

use Akibara\Search\SearchAdminSection;

$top         = (array) ( $view_data['top'] ?? array() );
$zero        = (array) ( $view_data['zero'] ?? array() );
$rescues     = (array) ( $view_data['rescues'] ?? array() );
$reales      = (array) ( $view_data['reales'] ?? array() );
$descartadas = (array) ( $view_data['descartadas'] ?? array() );
?>
<?php if ( ! empty( $reales ) || ! empty( $descartadas ) ) : ?>
	<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid var(--akb-admin-red, #d90010);padding:16px 20px;margin:8px 0 20px;max-width:760px;overflow-x:auto;min-width:0">
		<h2 style="margin:0 0 4px">Los más buscados que no tienes</h2>
		<p class="description" style="margin-top:0">Búsquedas de los últimos 90 días sin ningún resultado, ya descartados los errores de tipeo de títulos que sí existen. Es tu lista corta de compra y fichas.</p>
		<?php if ( array() !== $reales ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col">Título buscado</th>
						<th scope="col">90 días</th>
						<th scope="col">Este mes</th>
						<th scope="col"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $reales as $row ) : ?>
						<?php
						$query = (string) $row->query_norm;
						$s30   = (int) ( $row->s30 ?? 0 );
						?>
						<tr>
							<td data-label="Título buscado" style="font-size:14px"><strong><?php echo esc_html( $query ); ?></strong></td>
							<td data-label="90 días"><strong><?php echo esc_html( number_format_i18n( (int) $row->s ) ); ?></strong></td>
							<td data-label="Este mes"><?php echo $s30 > 0 ? esc_html( number_format_i18n( $s30 ) ) : '<span style="color:#787c82">—</span>'; ?></td>
							<td data-label="Acciones">
								<?php echo $this->create_product_link_html( $query ) . ' · ' . $this->zero_result_actions_html( $query ) . ' · ' . $this->gap_dismiss_link_html( $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<p class="description">«Este mes» en guion = nadie lo buscó en 30 días: demanda antigua, prioridad baja. «Descartar» la oculta del radar (recuperable); el log se poda solo a 180 días.</p>
		<?php $this->render_dismissed_gaps( $descartadas ); ?>
	</div>
<?php endif; ?>

<h2 style="margin-top:8px">Qué busca la gente (últimos 30 días)</h2>

<?php if ( empty( $top ) ) : ?>
	<p class="description">Sin búsquedas registradas todavía. El log se llena cuando el autocomplete está activo.</p>
<?php else : ?>
	<div style="display:flex;gap:32px;flex-wrap:wrap">
		<div style="flex:0 1 360px;min-width:0">
			<h3>Top búsquedas</h3>
			<div class="akb-admin-scroll">
				<table class="widefat striped" style="max-width:360px">
					<thead>
						<tr>
							<th scope="col">Búsqueda</th>
							<th scope="col">Veces</th>
							<th scope="col">Resultados</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top as $row ) : ?>
							<tr>
								<td data-label="Búsqueda"><?php echo esc_html( (string) $row->query_norm ); ?></td>
								<td data-label="Veces"><?php echo esc_html( number_format_i18n( (int) $row->s ) ); ?></td>
								<td data-label="Resultados"><?php echo 0 === (int) $row->h ? '<span style="color:#b32d2e">0</span>' : esc_html( (string) $row->h ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div style="flex:1 1 420px;min-width:0">
			<h3>Sin resultados (oportunidades)</h3>
			<?php if ( empty( $zero ) ) : ?>
				<p class="description">Ninguna búsqueda quedó en 0 — buena señal.</p>
			<?php else : ?>
				<div class="akb-admin-scroll">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col">Búsqueda</th>
								<th scope="col">Veces</th>
								<th scope="col">Última vez</th>
								<th scope="col">¿La resuelve hoy el buscador?</th>
								<th scope="col">Acciones</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $zero as $row ) : ?>
								<?php
								$query   = (string) $row->query_norm;
								$rescue  = $rescues[ $query ] ?? null;
								$rescued = is_array( $rescue ) && (int) $rescue['hits'] > 0;
								?>
								<tr<?php echo $rescued ? ' style="opacity:.72"' : ''; ?>>
									<td data-label="Búsqueda"><?php echo esc_html( $query ); ?></td>
									<td data-label="Veces"><?php echo esc_html( number_format_i18n( (int) $row->s ) ); ?></td>
									<td data-label="Última vez"><?php echo esc_html( $this->qlog_day_label( (string) ( $row->last_day ?? '' ) ) ); ?></td>
									<td data-label="¿La resuelve hoy el buscador?">
										<?php echo $this->zero_result_verdict_html( $rescue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</td>
									<td data-label="Acciones">
										<?php echo $this->zero_result_actions_html( $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="description">Las filas atenuadas ya las resuelve el corrector de tipeo: no son contenido que falte. Las que dicen <strong>No</strong> sí son huecos reales — contenido o sinónimo. La columna se mide llamando al buscador real (con corrector) y se cachea una hora.</p>
			<?php endif; ?>
		</div>
	</div>
	<p class="description">El registro cuenta las búsquedas confirmadas desde el buscador del sitio (sugerencias y envío del formulario, ambas con JavaScript). No incluye visitas directas a una URL de búsqueda ni clientes sin JavaScript, así que es una muestra, no el total.</p>
<?php endif; ?>
