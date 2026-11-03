<?php
/**
 * Template: Formulario de Cambio de Fecha de Preventa / Por Llegar.
 *
 * @package Akibara\Business
 * @var array<string, mixed> $view_data
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Modules\OrderPreventa\Settings;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Utilities\StoreTime;

/** @var \WC_Product $product */
$product       = $view_data['product'];
$pid           = (int) ( $view_data['pid'] ?? $product->get_id() );
$actual        = (string) ( $view_data['actual'] ?? '' );
$cuantos       = (int) ( $view_data['cuantos'] ?? 0 );
$volver        = (string) ( $view_data['volver'] ?? admin_url( 'admin.php?page=akibara-panel&tab=preventas' ) );
$es_primera    = ! empty( $view_data['es_primera'] );
$ventana       = (int) ( $view_data['ventana'] ?? 14 );
$capados       = (int) ( $view_data['capados'] ?? 0 );
$nota_salieron = (string) ( $view_data['nota_salieron'] ?? '' );
$asuntos       = (array) ( $view_data['asuntos'] ?? array() );
$action_fecha  = (string) ( $view_data['action_fecha'] ?? 'akb_preventa_fecha' );
?>

<div class="akb-preventas">
	<h2>Cambiar la fecha de salida de la editorial</h2>
	<h3 style="font-weight:400;margin-top:0;"><?php echo esc_html( $product->get_name() ); ?></h3>

	<p>Lo esperan <strong><?php echo (int) $cuantos; ?> <?php echo 1 === $cuantos ? 'pedido' : 'pedidos'; ?></strong>.<?php echo '' !== $actual ? sprintf( ' Fecha de salida actual: <strong>%s</strong>.', esc_html( $actual ) ) : ' Ahora mismo no tiene fecha de salida.'; ?></p>
	<p>Es la fecha en que la editorial publica el tomo, no la de llegada: con ella calculamos cuándo le llega al cliente (salida + envío). Si el tomo estaba «a pedido», al ponerle fecha pasa a ser una <strong>preventa</strong>; si le quitas la fecha, queda como preventa <strong>sin fecha confirmada</strong>.</p>

	<?php if ( 0 === $cuantos ) : ?>
		<p>Ahora mismo no hay ningún pedido esperando este tomo, así que guardar la fecha no manda ningún correo. Sólo actualiza la estimación que se ve en la ficha.</p>
	<?php elseif ( $es_primera ) : ?>
		<p style="color:#996800;">
			<strong>Al guardar sale el correo de fecha anunciada</strong> — <?php echo esc_html( implode( ' y ', $asuntos ) ); ?>. Es la primera fecha de este tomo, así que no pasa por el umbral de días; en cada pedido queda la nota «Email de fecha anunciada enviado al cliente».
			<?php
			if ( $capados > 0 ) {
				printf(
					' <strong>A %d de los %d pedidos NO les va a llegar</strong>: ya recibieron un aviso de fecha hace menos de %d días. En esos el cambio queda igualmente anotado y la fecha que ve el cliente se actualiza igual. Del resto, revisa las notas de cada pedido: puede haber otros motivos por los que un aviso no salga.',
					(int) $capados,
					(int) $cuantos,
					(int) $ventana
				);
			} else {
				echo ' Si alguno no lo recibe, quedará dicho en la nota de ese pedido.';
			}
			echo $nota_salieron; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</p>
	<?php else : ?>
		<?php
		$umbral    = Settings::get_int( Settings::OPT_DATE_CHANGE_THRESHOLD );
		$adelantos = 'yes' === (string) get_option( Settings::OPT_DATE_CHANGE_NOTIFY_ADV, Settings::DEFAULTS[ Settings::OPT_DATE_CHANGE_NOTIFY_ADV ] );
		?>
		<p style="color:#996800;">
			<strong>Si retrasas la fecha <?php echo (int) $umbral; ?> días o más, se manda un correo</strong> avisando del cambio a quien lo espera.
			<?php echo $adelantos ? 'Adelantarla también avisa.' : 'Adelantarla no avisa.'; ?>
			Retrasarla menos de <?php echo (int) $umbral; ?> días no avisa. Un mismo pedido no recibe dos avisos de cambio de fecha en menos de <?php echo (int) $ventana; ?> días: en ese caso el cambio queda anotado en el pedido y la fecha que ve el cliente se actualiza igual. A un pedido en espera de pago (no cuenta en «lo esperan», que sólo mira pedidos pagados) se le actualiza la fecha pero no se le escribe hasta que pague. Estos plazos se cambian en «Pedidos y preventa · Ajustes».
			<?php echo $nota_salieron; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</p>
	<?php endif; ?>

	<p>La fecha se usa además en la estimación que el cliente ve en la ficha y en su pedido.</p>
	<?php if ( '' !== $actual ) : ?>
		<p style="color:#666;">Si borras la fecha <strong>no sale ningún correo</strong>: el cliente deja de ver la fecha de llegada en su pedido (verá «sin fecha confirmada») y el cambio queda anotado en cada pedido. Si quieres avisarle, escríbele tú.</p>
	<?php endif; ?>

	<?php if ( Availability::AGOTADO === Availability::of( $product ) ) : ?>
		<div class="notice notice-warning inline">
			<p>
				Ojo: <strong><?php echo esc_html( $product->get_name() ); ?></strong> está marcado como <strong>agotado</strong>, no como «a pedido». La fecha se va a guardar, pero el cliente no la verá en la ficha hasta que cambies el estado del stock a «Se puede reservar» en <a href="<?php echo esc_url( (string) get_edit_post_link( $pid ) ); ?>">la página del producto</a>.
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $action_fecha ); ?>">
		<input type="hidden" name="product_id" value="<?php echo (int) $pid; ?>">
		<?php wp_nonce_field( $action_fecha . '-' . $pid ); ?>

		<p>
			<label>
				Nueva fecha de salida 
				<input type="date" name="fecha" value="<?php echo esc_attr( $actual ); ?>" min="<?php echo esc_attr( StoreTime::today_ymd() ); ?>" max="<?php echo esc_attr( Availability::max_release_date() ); ?>">
			</label> 
			<span style="color:#666;">(déjala vacía para dejarla «sin fecha confirmada»)</span>
		</p>
		<p>
			<button type="submit" class="button button-primary">Guardar la fecha</button> 
			<a class="button" href="<?php echo esc_url( $volver ); ?>">Cancelar</a>
		</p>
	</form>
</div>
