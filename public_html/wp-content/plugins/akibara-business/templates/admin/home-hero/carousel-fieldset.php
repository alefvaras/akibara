<?php
/**
 * Template para el bloque de settings del carrusel Home Hero.
 *
 * @package Akibara\Business\Modules\HomeHero
 *
 * @var array<string, mixed> $carousel
 * @var string               $height_current
 * @var int                  $custom_d
 * @var int                  $custom_m
 */

declare( strict_types = 1 );

use Akibara\Business\Modules\HomeHero\Settings;

defined( 'ABSPATH' ) || exit;

$akb_height_current = (string) ( $carousel['hero_height'] ?? 'standard' );
$akb_custom_d       = (int) ( $carousel['hero_height_custom_desktop'] ?? 700 );
$akb_custom_m       = (int) ( $carousel['hero_height_custom_mobile'] ?? 55 );
?>
<fieldset class="akb-hero-height">
	<legend>Tamaño del hero</legend>
	<p class="description">Aplica a todos los slides. Mobile usa el preset equivalente.</p>
	<table class="form-table">
		<tbody>
			<tr>
				<th scope="row">Altura</th>
				<td>
					<div class="akb-hero-height__presets" role="radiogroup" aria-label="Altura del hero">
						<?php foreach ( Settings::HEIGHT_PRESETS as $akb_hkey => $akb_preset ) : ?>
							<label class="akb-hero-height__option <?php echo $akb_height_current === $akb_hkey ? 'is-active' : ''; ?>">
								<input
									type="radio"
									name="carousel[hero_height]"
									value="<?php echo esc_attr( $akb_hkey ); ?>"
									<?php checked( $akb_height_current, $akb_hkey ); ?>
									data-height-radio
								>
								<span class="akb-hero-height__option-label"><?php echo esc_html( $akb_preset['label'] ); ?></span>
								<?php if ( null !== $akb_preset['desktop'] ) : ?>
									<span class="akb-hero-height__option-meta"><?php echo esc_html( $akb_preset['desktop'] ); ?> · <?php echo esc_html( $akb_preset['mobile'] ); ?></span>
								<?php else : ?>
									<span class="akb-hero-height__option-meta">px · vh</span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					</div>
				</td>
			</tr>
			<tr class="akb-hero-height__custom-row" <?php echo 'custom' === $akb_height_current ? '' : 'hidden'; ?>>
				<th scope="row">Tamaño personalizado</th>
				<td>
					<label>Desktop: <input type="number" name="carousel[hero_height_custom_desktop]" value="<?php echo (int) $akb_custom_d; ?>" min="200" max="2000" step="10" style="width:90px;"> px</label>
					&nbsp;&nbsp;
					<label>Mobile: <input type="number" name="carousel[hero_height_custom_mobile]" value="<?php echo (int) min( 50, (int) $akb_custom_m ); ?>" min="30" max="50" step="1" style="width:80px;"> vh</label>
					<p class="description">Mobile en vh (% del viewport), máximo 50: sobre ese techo el botón del hero queda escondido bajo la barra de navegación del teléfono.</p>
				</td>
			</tr>
		</tbody>
	</table>
</fieldset>

<fieldset class="akb-hero-carousel">
	<legend>Comportamiento del carrusel <span class="akb-hero-carousel__note">(aplica solo con 2+ slides)</span></legend>

	<table class="form-table">
		<tbody>
			<tr>
				<th scope="row">Autoplay</th>
				<td>
					<label>
						<input type="checkbox" name="carousel[autoplay]" value="1" <?php checked( ! empty( $carousel['autoplay'] ) ); ?>>
						Rotar automáticamente entre slides
					</label>
					<p class="description" style="margin-top:6px">
						Recomendamos dejarlo <strong>desactivado</strong> — los usuarios controlan el carrusel con swipe (mobile) o drag/flechas (desktop). El autoplay puede bajar conversión porque las personas no terminan de leer los slides antes de que roten.
					</p>
					<p class="description">
						Si lo activas, el segmento activo del indicador se llena en amarillo mostrando el tiempo restante, y aparece un botón de pausa junto al indicador (cumple WCAG 2.2.2). La rotación automática solo aplica en desktop; en mobile el usuario controla con swipe.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Intervalo (segundos)</th>
				<td>
					<input type="number" name="carousel[interval_seconds]" value="<?php echo (int) ( $carousel['interval_seconds'] ?? 6 ); ?>" min="2" max="30" step="1" style="width:80px;" aria-label="Intervalo del carrusel en segundos">
					<span class="description">Tiempo que dura cada slide antes de rotar. 4-8 s recomendado.</span>
				</td>
			</tr>
			<tr>
				<th scope="row">Pause</th>
				<td>
					<label>
						<input type="checkbox" name="carousel[pause_on_hover]" value="1" <?php checked( ! empty( $carousel['pause_on_hover'] ) ); ?>>
						Pausar al pasar el cursor encima
					</label><br>
					<label>
						<input type="checkbox" name="carousel[pause_on_blur]" value="1" <?php checked( ! empty( $carousel['pause_on_blur'] ) ); ?>>
						Pausar cuando la pestaña pierde foco
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">Loop</th>
				<td>
					<label>
						<input type="checkbox" name="carousel[loop]" value="1" <?php checked( ! empty( $carousel['loop'] ) ); ?>>
						Volver al primer slide después del último
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">Flechas (desktop)</th>
				<td>
					<label>
						<input type="checkbox" name="carousel[show_arrows_desktop]" value="1" <?php checked( ! empty( $carousel['show_arrows_desktop'] ) ); ?>>
						Mostrar flechas next/prev en desktop
					</label>
					<p class="description" style="margin-top:6px">
						Las flechas aparecen solo al pasar el cursor sobre el carrusel (patrón moderno).
					</p>
					<p class="description">En mobile las flechas se ocultan siempre — el usuario navega con swipe + dots.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Indicador de slides</th>
				<td>
					<label>
						<input type="checkbox" name="carousel[show_dots_desktop]" value="1" <?php checked( ! empty( $carousel['show_dots_desktop'] ) ); ?>>
						Mostrar el indicador en desktop
					</label><br>
					<label>
						<input type="checkbox" name="carousel[show_dots_mobile]" value="1" <?php checked( ! empty( $carousel['show_dots_mobile'] ) ); ?>>
						Mostrar el indicador en mobile
					</label>
					<p class="description" style="margin-top:6px">
						Barra segmentada arriba del hero: un segmento por diapositiva, clicable para saltar. Con <strong>rotación automática activa</strong> el indicador se muestra siempre (contiene el botón de pausa — WCAG 2.2.2) y estas casillas se ignoran.
					</p>
				</td>
			</tr>
		</tbody>
	</table>
</fieldset>
