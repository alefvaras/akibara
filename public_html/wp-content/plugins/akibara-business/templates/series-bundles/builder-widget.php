<?php
/**
 * Vista del widget interactivo "Arma tu Pack" (Single Product).
 *
 * @package Akibara\Business
 *
 * @var int                                  $term_id
 * @var string                               $serie_name
 * @var array<int, array{min:int, pct:int}>  $tiers
 * @var string                               $tiers_json
 * @var int                                  $max_tier
 * @var array<int, array<string, mixed>>     $volumes
 * @var int                                  $user_id
 * @var array<int>                           $owned_ids
 * @var int                                  $owned_count
 * @var int                                  $series_total
 * @var int                                  $faltan
 * @var bool                                 $near_goal
 * @var array<int>                           $missing_ids
 * @var bool                                 $can_complete
 * @var int                                  $scarce_count
 * @var bool                                 $has_preventa
 * @var bool                                 $has_preventa_real
 * @var bool                                 $has_offer
 * @var bool                                 $show_picker_tools
 * @var bool                                 $show_tools
 * @var array<int, array{min:int, max:int}>  $volume_ranges
 * @var int                                  $free_ship_thresh
 * @var string                               $title_id_prefix
 */

declare( strict_types = 1 );

use Akibara\Business\Modules\BackInStock\CaptureForm;
use Akibara\Business\Modules\SeriesBundles\BuilderWidget;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Services\NavigationLinks;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<div class="akb-sb-builder"
		data-akb-sb-builder
		data-term-id="<?php echo (int) $term_id; ?>"
		data-tiers="<?php echo esc_attr( (string) $tiers_json ); ?>"
		data-serie="<?php echo esc_attr( $serie_name ); ?>"
		data-free-ship="<?php echo esc_attr( (string) $free_ship_thresh ); ?>"
		role="region"
		aria-labelledby="<?php echo esc_attr( $title_id_prefix . (int) $term_id ); ?>">

		<h2 class="akb-sb-builder__title" id="<?php echo esc_attr( $title_id_prefix . (int) $term_id ); ?>">
			<?php echo esc_html( 'Arma tu pack y ahorra en ' . $serie_name ); ?>
		</h2>

	<?php if ( $user_id > 0 && $series_total > 0 && $owned_count > 0 ) : ?>
		<div class="akb-sb-builder__collection" role="status">
			<div class="akb-sb-builder__collection-head">
				<span class="akb-sb-builder__collection-count"><?php echo (int) $owned_count; ?>/<?php echo (int) $series_total; ?></span>
				<span class="akb-sb-builder__collection-label">
					<?php echo esc_html( 0 === $faltan ? '¡Ya tienes todos los tomos disponibles de ' . $serie_name . '!' : 'Llevas ' . $owned_count . ' de ' . $series_total . ' tomos de ' . $serie_name ); ?>
				</span>
			</div>
			<?php if ( $near_goal ) : ?>
				<div class="akb-sb-builder__collection-track" aria-hidden="true">
					<div class="akb-sb-builder__collection-fill" style="width: <?php echo (int) round( $owned_count / $series_total * 100 ); ?>%"></div>
				</div>
				<p class="akb-sb-builder__collection-sub"><?php echo esc_html( 1 === $faltan ? '¡Estás a solo 1 tomo de completar tu serie con descuento!' : '¡Te faltan solo ' . $faltan . ' tomos para completar tu serie con descuento!' ); ?></p>
				<?php if ( $can_complete ) : ?>
					<button type="button" class="akb-sb-builder__complete" data-akb-sb-complete="<?php echo esc_attr( (string) wp_json_encode( $missing_ids ) ); ?>">
						<?php echo esc_html( 1 === count( $missing_ids ) ? '⚡ Llevar el tomo que te falta con descuento' : '⚡ Llevar los ' . count( $missing_ids ) . ' que te faltan con descuento' ); ?>
					</button>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php elseif ( $user_id <= 0 ) : ?>
		<p class="akb-sb-builder__collection-guest">¿Tienes tomos de esta serie? <a href="<?php echo esc_url( NavigationLinks::account_url_returning_here() ); ?>" rel="nofollow">Inicia sesión</a> para armar tu pack solo con los que te faltan.</p>
	<?php endif; ?>

	<?php if ( ! empty( $tiers ) ) : ?>
			<?php if ( 1 === count( $tiers ) ) : ?>
				<?php // Con un solo tramo no hay progresión: prometer "más tomos, más baja el precio" sería falso. Framing de umbral. ?>
				<p class="akb-sb-builder__intro"><?php echo esc_html( sprintf( 'Selecciona %d tomos o más y obtén %d%% de descuento en los tomos elegibles que selecciones.', (int) $tiers[0]['min'], (int) $tiers[0]['pct'] ) ); ?></p>
			<?php else : ?>
				<p class="akb-sb-builder__intro">Elige tus tomos: mientras más sumes, mayor será el descuento.</p>
			<?php endif; ?>
		<ul class="akb-sb-builder__tiers">
			<?php foreach ( $tiers as $tier ) : ?>
				<li class="akb-sb-builder__tier" data-min="<?php echo (int) $tier['min']; ?>" data-pct="<?php echo (int) $tier['pct']; ?>">
					<?php
					// La frase sale del resolver, no de un sprintf local: la landing de
					// serie anuncia el MISMO tramo y las dos tienen que decirlo igual.
					echo esc_html( $this->tiers->tier_label( (int) $tier['min'], (int) $tier['pct'] ) );
					?>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="akb-sb-builder__progress-wrap" aria-hidden="true">
			<div class="akb-sb-builder__progress-bar" data-akb-sb-progress data-max="<?php echo (int) $max_tier; ?>"></div>
			<?php foreach ( $tiers as $tier ) : ?>
				<?php $tick_left = (int) round( min( 100, (int) $tier['min'] / max( 1, $max_tier ) * 100 ) ); ?>
				<span class="akb-sb-builder__progress-tick" style="left: <?php echo (int) $tick_left; ?>%" data-min="<?php echo (int) $tier['min']; ?>"></span>
			<?php endforeach; ?>
		</div>
		<details class="akb-sb-builder__rules">
			<summary class="akb-sb-builder__rules-toggle">
				<span class="akb-sb-builder__rules-label">Condiciones del descuento y encargos</span>
			</summary>
			<div class="akb-sb-builder__rules-body">
				<p class="akb-sb-builder__list-note">Cada tomo distinto cuenta una vez; el descuento aplica a una unidad de cada tomo elegible.</p>
				<?php // Aclaracion honesta: el % es sobre el precio de LISTA, no sobre el precio ya rebajado. Evita la duda "prometia 10% y me hizo 5%" en tomos ya en oferta (el pack solo aporta hasta regular*(1-pct)). M3: solo si hay tomos ya en oferta (si no, el % nominal se materializa entero y la nota confunde de más). ?>
				<?php if ( $has_offer ) : ?>
					<p class="akb-sb-builder__list-note">El descuento se aplica sobre el precio de lista. En tomos que ya están en oferta, parte del ahorro ya viene incluido.</p>
				<?php endif; ?>
				<?php // M2: aviso a nivel pack (una línea, cualitativo) cuando la serie incluye preventa. La fecha por tomo la da la etiqueta inline; acá solo se orienta al cliente. ?>
				<?php if ( $has_preventa ) : ?>
					<p class="akb-sb-builder__preventa-note"><?php echo wp_kses( BuilderWidget::pack_notice( $has_preventa_real ), array( 'strong' => array() ) ); ?></p>
					<?php if ( ! $this->settings->allows_backorder() ) : ?>
						<?php // La MISMA frase que la landing de la serie: si las dos superficies ofrecen lo mismo, tienen que explicarlo igual. ?>
						<p class="akb-sb-builder__preventa-note">Cuentan los tomos disponibles ahora; los a pedido no. Puedes agregarlos juntos igual.</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</details>
	<?php endif; ?>

		<?php if ( $scarce_count > 0 ) : ?>
			<p class="akb-sb-builder__scarcity-note">
				<?php
				echo esc_html(
					1 === $scarce_count
						? '1 tomo tiene solo una unidad disponible.'
						: $scarce_count . ' tomos tienen solo una unidad disponible.'
				);
				?>
			</p>
		<?php endif; ?>

	<?php if ( empty( $tiers ) && $has_preventa ) : ?>
		<p class="akb-sb-builder__preventa-note"><?php echo wp_kses( BuilderWidget::pack_notice( $has_preventa_real ), array( 'strong' => array() ) ); ?></p>
	<?php endif; ?>

	<?php if ( $show_tools ) : ?>
		<div class="akb-sb-builder__tools" data-akb-sb-tools data-akb-sb-interactive hidden>
			<div class="akb-sb-builder__shortcuts" role="toolbar" aria-label="<?php echo esc_attr( 'Atajos rápidos de pack' ); ?>">
				<button type="button" class="akb-sb-builder__shortcut-btn" data-akb-sb-shortcut="stock">+ Seleccionar en stock</button>
				<?php if ( count( $volumes ) >= 5 ) : ?>
					<button type="button" class="akb-sb-builder__shortcut-btn" data-akb-sb-shortcut="last5">+ Últimos 5 tomos</button>
				<?php endif; ?>
				<button type="button" class="akb-sb-builder__shortcut-btn akb-sb-builder__shortcut-btn--clear" data-akb-sb-shortcut="clear">Limpiar</button>
			</div>
			<?php if ( $has_preventa ) : ?>
				<?php // S1: toggle client-side "solo en stock". Oculta (y desmarca) los tomos en preventa sin tocar el servidor (querystring rompería la caché de la PDP). ?>
				<label class="akb-sb-builder__stock-toggle">
					<input type="checkbox" data-akb-sb-stock-toggle>
					<span>Ver solo tomos en stock</span>
				</label>
			<?php endif; ?>
			<?php if ( $show_picker_tools && ! empty( $volume_ranges ) ) : ?>
				<div class="akb-sb-builder__ranges" role="group" aria-label="<?php echo esc_attr( 'Filtrar tomos de ' . $serie_name . ' por rango' ); ?>">
					<button type="button" class="akb-sb-builder__range is-active" data-akb-sb-range="all" aria-pressed="true">Todos</button>
					<?php foreach ( $volume_ranges as $range ) : ?>
						<button
							type="button"
							class="akb-sb-builder__range"
							data-akb-sb-range="<?php echo esc_attr( (string) $range['min'] . '-' . (string) $range['max'] ); ?>"
							data-akb-sb-range-min="<?php echo (int) $range['min']; ?>"
							data-akb-sb-range-max="<?php echo (int) $range['max']; ?>"
							aria-pressed="false">
							<?php echo esc_html( (string) $range['min'] . '-' . (string) $range['max'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $show_picker_tools ) : ?>
				<div class="akb-sb-builder__bulk">
					<button type="button" class="akb-sb-builder__bulk-btn" data-akb-sb-visible-action="select">Seleccionar disponibles</button>
					<button type="button" class="akb-sb-builder__bulk-btn akb-sb-builder__bulk-btn--secondary" data-akb-sb-visible-action="clear-all">Quitar selección</button>
					<span class="akb-sb-builder__filter-status" data-akb-sb-filter-status aria-live="polite"></span>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="akb-sb-builder__grid-wrap" data-akb-sb-interactive data-volumes-count="<?php echo (int) count( $volumes ); ?>" hidden>
	<div class="akb-sb-builder__grid" role="group" aria-label="<?php echo esc_attr( 'Tomos de ' . $serie_name ); ?>">
		<?php foreach ( $volumes as $vol_index => $vol ) : ?>
			<?php
			$item_class = $vol['is_purchasable'] ? '' : 'is-disabled';
			$is_owned   = in_array( (int) $vol['id'], $owned_ids, true );
			// Agotado + waitlist activo ⇒ ofrecemos "Avísame cuando vuelva". La card
			// pasa de <label> a <div> porque el formulario lleva input/botón y no
			// pueden anidarse en un <label> (el checkbox está disabled igual).
			$is_notifiable = ! $vol['is_purchasable']
				&& empty( $vol['is_preventa'] )
				&& $this->waitlist_active();
			$card_tag      = $is_notifiable ? 'div' : 'label';
			$card_class    = trim( $item_class . ( $is_owned ? ' is-owned' : '' ) . ( $is_notifiable ? ' is-notifiable' : '' ) );
			?>
			<<?php echo esc_html( $card_tag ); ?> class="akb-sb-builder__card <?php echo esc_attr( $card_class ); ?>" style="--akb-i:<?php echo (int) min( 10, $vol_index ); ?>">
				<?php if ( $is_owned ) : ?><span class="akb-sb-builder__owned-badge">En tu colección</span><?php endif; ?>
				<input type="checkbox"
					class="akb-sb-builder__check screen-reader-text"
					data-akb-sb-vol
					aria-label="<?php echo esc_attr( 'Seleccionar ' . $vol['title'] ); ?>"
					value="<?php echo (int) $vol['id']; ?>"
					data-price="<?php echo esc_attr( (string) $vol['price'] ); ?>"
					data-regular-price="<?php echo esc_attr( (string) $vol['regular_price'] ); ?>"
					data-akb-sb-volume="<?php echo esc_attr( null === $vol['volume_number'] ? '' : (string) $vol['volume_number'] ); ?>"
					data-akb-sb-current="<?php echo ! empty( $vol['is_current'] ) ? '1' : '0'; ?>"
					data-akb-sb-preventa="<?php echo ! empty( $vol['is_preventa'] ) ? '1' : '0'; ?>"
					data-discount-eligible="<?php echo ! empty( $vol['discount_eligible'] ) ? '1' : '0'; ?>"
					<?php echo ! $vol['is_purchasable'] ? 'disabled' : ''; ?>>

				<div class="akb-sb-builder__vol-thumb akb-cover-cage">
					<?php echo wp_kses_post( $vol['thumb_html'] ); ?>
				</div>

				<div class="akb-sb-builder__vol-info">
					<span class="akb-sb-builder__vol-title"><?php echo esc_html( $vol['title'] ); ?></span>
					<span class="akb-sb-builder__vol-price" data-akb-sb-vol-price>
						<?php
						if ( ! $vol['is_purchasable'] ) {
							$unavailable_label = ! empty( $vol['is_preventa'] )
								? ( (string) ( $vol['avail_label'] ?? Availability::label_for_state( Availability::A_PEDIDO ) ) ) . ' fuera del pack'
								: 'Agotado';
							echo '<span class="akb-sb-builder__badge-soldout">' . esc_html( $unavailable_label ) . '</span>';
						} else {
							echo wp_kses_post( $vol['price_html'] );
						}
						?>
					</span>
					<?php // M1: etiqueta de preventa por tomo (fecha inline). Va como HERMANA del precio, no dentro de [data-akb-sb-vol-price] (el JS reescribe ese nodo al seleccionar y borraría la etiqueta). Texto+forma → distinguible sin color (a11y). ?>
					<?php if ( ! empty( $vol['is_preventa'] ) && '' !== (string) $vol['eta_label'] ) : ?>
						<span class="akb-sb-builder__vol-preventa"><?php echo esc_html( (string) $vol['eta_label'] ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $is_notifiable ) : ?>
					<?php
					// Deep-link al form de lista de espera del PDP (mismo anchor y
					// filter que LoopEnhancer): así el tomo agotado aterriza en el
					// formulario "Avísame cuando vuelva" y no arriba del PDP, sin
					// scroll manual. El scroll suave + foco lo sella bis-form.js.
					$notify_anchor = CaptureForm::anchor();
					$notify_url    = get_permalink( (int) $vol['id'] ) . $notify_anchor;
					?>
					<a class="akb-sb-builder__notify-link"
						href="<?php echo esc_url( $notify_url ); ?>"
						target="_blank"
						rel="noopener">
						Avísame cuando vuelva
						<span class="screen-reader-text"> (se abre la página del tomo)</span>
					</a>
				<?php else : ?>
					<span class="akb-sb-builder__selection-mark" aria-hidden="true">✓</span>
				<?php endif; ?>
			</<?php echo esc_html( $card_tag ); ?>>
		<?php endforeach; ?>
	</div>
	<?php if ( count( $volumes ) > 6 ) : ?>
		<div class="akb-sb-builder__expand-wrap">
			<button type="button"
				class="akb-sb-builder__expand-btn"
				data-akb-sb-expand-btn
				data-total="<?php echo (int) count( $volumes ); ?>"
				aria-expanded="false">
				<span class="akb-sb-builder__expand-icon">▾</span>
				<span class="akb-sb-builder__expand-text"><?php echo esc_html( sprintf( 'Ver los %d tomos de la serie', count( $volumes ) ) ); ?></span>
			</button>
		</div>
	<?php endif; ?>
	</div>

	<div class="akb-sb-builder__summary-wrap" data-akb-sb-interactive hidden>
		<div class="akb-sb-builder__summary" data-akb-sb-summary aria-live="polite" aria-atomic="true">
			<span class="akb-sb-builder__count" data-akb-sb-count></span>
			<span class="akb-sb-builder__disc" data-akb-sb-disc></span>
			<span class="akb-sb-builder__total" data-akb-sb-total hidden></span>
			<span class="akb-sb-builder__savings" data-akb-sb-savings hidden></span>
		</div>

		<?php // Sin las clases `button alt` de WC: arrastraban el hover a rayas (barber-pole) y el disabled morado #7f54b3 de WooCommerce, pisando el hover de marca (amarillo) por especificidad. El estilo completo del botón vive en .akb-sb-builder__add. ?>
		<button type="button" class="akb-sb-builder__add" data-akb-sb-add>
			Agregar mi pack al carrito
		</button>
		<p class="akb-sb-builder__msg" data-akb-sb-msg role="status" aria-live="polite"></p>
	</div>

	<noscript>
		<div class="akb-sb-builder__noscript">
			<p>Para comprar sin JavaScript, abre la ficha de cada tomo; allí se confirma el precio y stock vigentes.</p>
			<ul>
				<?php foreach ( $volumes as $vol ) : ?>
					<li>
						<a href="<?php echo esc_url( (string) $vol['url'] ); ?>"><?php echo esc_html( (string) $vol['title'] ); ?></a>
						<span><?php echo esc_html( ! empty( $vol['is_purchasable'] ) ? 'Ver y comprar' : ( ! empty( $vol['is_preventa'] ) ? 'Ver preventa' : 'Ver disponibilidad' ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</noscript>
</div>
