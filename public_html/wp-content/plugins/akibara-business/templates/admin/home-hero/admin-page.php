<?php
/**
 * Template para la página de administración del Home Hero.
 *
 * @package Akibara\Business\Modules\HomeHero
 *
 * @var array<int, array<string, mixed>>           $slides
 * @var array<string, mixed>                       $carousel
 * @var \Akibara\Business\Modules\HomeHero\AdminUI $admin_ui
 */

declare( strict_types = 1 );

use Akibara\Business\Modules\HomeHero\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="akb-hero-admin">

	<div class="akb-hero-admin__intro">
		<div class="akb-hero-admin__intro-head">
			<h2>Slider de Inicio</h2>
			<p>Configura el hero principal de la página de inicio. <strong>1 slide</strong> = hero estático. <strong>2-4 slides</strong> = carrusel con scroll-snap nativo, drag (desktop), swipe (mobile) y flechas que aparecen al hover. Autoplay opcional con barra de progreso visible.</p>
		</div>
		<details class="akb-hero-admin__help">
			<summary>📐 Dimensiones recomendadas y ayuda</summary>
			<ul>
				<li><strong>Desktop:</strong> <?php echo (int) Settings::REC_DESKTOP_WIDTH; ?>×<?php echo (int) Settings::REC_DESKTOP_HEIGHT; ?> px (aspect ratio ≈ 2.37:1). Formato preferido: WebP. Tamaño objetivo: ≤ 150 KB.</li>
				<li><strong>Mobile:</strong> <?php echo (int) Settings::REC_MOBILE_WIDTH; ?>×<?php echo (int) Settings::REC_MOBILE_HEIGHT; ?> px (vertical, ≈ 0.56:1). Punto focal centrado al 60-80 %. Tamaño objetivo: ≤ 100 KB.</li>
				<li><strong>Encuadre visual:</strong> haz clic sobre la miniatura de la imagen para fijar el punto focal. Reemplaza al campo <code>object-position</code> manual.</li>
				<li><strong>Editor de imagen:</strong> el botón "Editar imagen" abre el editor nativo de WordPress (crop, rotar, escalar) para esa imagen en una pestaña nueva.</li>
				<li><strong>Tolerancia dimensiones:</strong> ±<?php echo (int) Settings::ASPECT_TOLERANCE_PCT; ?> % en aspect-ratio antes de advertir. No bloquea.</li>
				<li><strong>Shortcode:</strong> <code>[akb_hero]</code> (ya activo en la página de Inicio).</li>
			</ul>
		</details>
	</div>

	<div class="akb-hero-admin__toolbar">
		<button type="button" class="button" data-action="open-preview-modal" title="Ver el hero completo en una vista previa (con carrusel real si hay 2+ slides)">👁 Vista previa completa</button>
		<span class="akb-hero-admin__toolbar-spacer"></span>
		<span class="akb-hero-admin__toolbar-hint">El panel lateral derecho muestra una vista previa simple que se actualiza al instante.</span>
	</div>

	<form
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		class="akb-hero-admin__form"
	>
		<?php wp_nonce_field( 'akibara_hero_save' ); ?>
		<input type="hidden" name="action" value="akibara_hero_save">

		<div class="akb-hero-admin__slides" data-slides>
			<?php if ( empty( $slides ) ) : ?>
				<p class="akb-hero-admin__empty">No hay slides configurados todavía. Haz clic en "Agregar slide" para comenzar.</p>
			<?php else : ?>
				<?php foreach ( $slides as $akb_idx => $akb_slide ) : ?>
					<?php $admin_ui->render_slide_fieldset( (int) $akb_idx, $akb_slide ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<div class="akb-hero-admin__add-row">
			<button
				type="button"
				class="button button-secondary"
				data-action="add-slide"
			>+ Agregar slide</button>
			<span class="akb-hero-admin__count">
				<span data-slide-count><?php echo count( $slides ); ?></span> / <?php echo (int) Settings::MAX_SLIDES; ?>
			</span>
		</div>

		<?php $admin_ui->render_carousel_fieldset( $carousel ); ?>

		<p class="submit akb-hero-admin__submit">
			<button type="submit" class="button button-primary">Guardar configuración</button>
			<button type="submit" name="open_after_save" value="1" class="button">💾 Guardar y abrir en el sitio</button>
		</p>
	</form>

	<dialog class="akb-hero-modal" data-preview-modal aria-labelledby="akb-hero-modal-title">
		<form method="dialog" class="akb-hero-modal__dialog">
			<div class="akb-hero-modal__header">
				<h2 class="akb-hero-modal__title" id="akb-hero-modal-title">Vista previa completa</h2>
				<div class="akb-hero-modal__devices" role="group" aria-label="Cambiar dispositivo de vista">
					<button type="button" data-modal-device="desktop" class="is-active" aria-pressed="true">🖥 Desktop</button>
					<button type="button" data-modal-device="tablet" aria-pressed="false">▢ Tablet</button>
					<button type="button" data-modal-device="mobile" aria-pressed="false">📱 Mobile</button>
				</div>
				<button type="button" class="button" data-modal-reload title="Recargar preview con cambios del form">↻ Recargar</button>
				<button type="submit" class="akb-hero-modal__close" data-modal-close aria-label="Cerrar (ESC)">✕</button>
			</div>
			<div class="akb-hero-modal__body">
				<div class="akb-hero-modal__loading" data-modal-loading>Cargando vista previa…</div>
				<div class="akb-hero-modal__error" data-modal-error hidden></div>
				<iframe class="akb-hero-modal__iframe" data-modal-iframe title="Vista previa del hero" hidden></iframe>
			</div>
			<div class="akb-hero-modal__footer">
				<span class="akb-hero-modal__hint">Esta es una vista previa con los cambios actuales (sin guardar). ESC para cerrar.</span>
			</div>
		</form>
	</dialog>

	<dialog class="akb-hero-modal akb-hero-modal--confirm" data-confirm-modal aria-labelledby="akb-hero-confirm-title">
		<form method="dialog" class="akb-hero-modal__dialog akb-hero-modal__dialog--sm">
			<div class="akb-hero-modal__header">
				<h2 class="akb-hero-modal__title" id="akb-hero-confirm-title">¿Eliminar este slide?</h2>
			</div>
			<div class="akb-hero-modal__body akb-hero-modal__body--text">
				<p>Esta acción no puede deshacerse hasta que guardes el formulario. Si luego guardas sin restaurar la diapositiva, se borrará definitivamente.</p>
			</div>
			<div class="akb-hero-modal__footer akb-hero-modal__footer--actions">
				<button type="submit" value="cancel" class="button" data-confirm-cancel>Cancelar</button>
				<button type="submit" value="confirm" class="button button-primary akb-hero-modal__btn-danger" data-confirm-ok>Eliminar slide</button>
			</div>
		</form>
	</dialog>
</div>
