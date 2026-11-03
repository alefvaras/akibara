<?php
/**
 * Template para un fieldset de slide del Home Hero.
 *
 * @package Akibara\Business\Modules\HomeHero
 *
 * @var int                                                    $idx
 * @var array<string, mixed>                                   $slide
 * @var int                                                    $desktop_id
 * @var int                                                    $mobile_id
 * @var string                                                 $desktop_url
 * @var string                                                 $mobile_url
 * @var int                                                    $desktop_w
 * @var int                                                    $desktop_h
 * @var int                                                    $mobile_w
 * @var int                                                    $mobile_h
 * @var bool                                                   $is_active
 * @var string                                                 $slide_state
 * @var array{0:int, 1:int}                                    $focal_d
 * @var array{0:int, 1:int}                                    $focal_m
 * @var \Akibara\Business\Modules\HomeHero\AdminUI              $admin_ui
 */

declare( strict_types = 1 );

use Akibara\Business\Modules\HomeHero\Settings;

defined( 'ABSPATH' ) || exit;
?>
<fieldset class="akb-hero-slide <?php echo esc_attr( $slide_state ); ?>" data-slide-idx="<?php echo (int) $idx; ?>">
	<legend class="akb-hero-slide__legend">
		<span class="akb-hero-slide__drag" aria-hidden="true" title="Arrastra para reordenar">⋮⋮</span>
		<span class="akb-hero-slide__title">Slide <span data-slide-number><?php echo (int) ( $idx + 1 ); ?></span></span>

		<?php if ( ! $desktop_id && ! $mobile_id ) : ?>
			<span class="akb-hero-slide__warning" data-slide-no-image title="Esta diapositiva no se muestra en el sitio hasta que tenga al menos una imagen (desktop o mobile).">⚠ Sin imagen: no se muestra</span>
		<?php endif; ?>

		<label class="akb-hero-slide__toggle" title="Mostrar / ocultar este slide en el sitio">
			<input type="hidden" name="slides[<?php echo (int) $idx; ?>][is_active]" value="0">
			<input
				type="checkbox"
				name="slides[<?php echo (int) $idx; ?>][is_active]"
				value="1"
				<?php checked( $is_active ); ?>
				data-slide-toggle
			>
			<span>Mostrar</span>
		</label>

		<span class="akb-hero-slide__legend-spacer"></span>

		<button type="button" class="button-link akb-hero-slide__action" data-action="duplicate-slide" title="Duplicar este slide">Duplicar</button>
		<button type="button" class="button-link akb-hero-slide__move" data-action="move-up" aria-label="Subir">↑</button>
		<button type="button" class="button-link akb-hero-slide__move" data-action="move-down" aria-label="Bajar">↓</button>
		<button type="button" class="button-link akb-hero-slide__remove" data-action="remove-slide" aria-label="Eliminar slide">Eliminar</button>
	</legend>

	<div class="akb-hero-slide__grid">

		<div class="akb-hero-slide__media">
			<label class="akb-hero-slide__label">Imagen desktop <span class="akb-hero-slide__rec">· sugerido <?php echo (int) Settings::REC_DESKTOP_WIDTH; ?>×<?php echo (int) Settings::REC_DESKTOP_HEIGHT; ?></span></label>
			<div class="akb-hero-media" data-media="desktop">
				<div class="akb-hero-media__preview <?php echo $desktop_url ? '' : 'is-empty'; ?>" data-focal-x="<?php echo esc_attr( (string) $focal_d[0] ); ?>" data-focal-y="<?php echo esc_attr( (string) $focal_d[1] ); ?>">
					<?php if ( $desktop_url ) : ?>
						<img src="<?php echo esc_url( $desktop_url ); ?>" alt="" data-media-img>
					<?php else : ?>
						<span class="akb-hero-media__placeholder" data-media-img>Sin imagen</span>
					<?php endif; ?>
					<span class="akb-hero-media__focal" data-focal-pin aria-hidden="true"></span>
				</div>
				<input type="hidden" name="slides[<?php echo (int) $idx; ?>][desktop_id]" value="<?php echo (int) $desktop_id; ?>" data-media-input>
				<input type="hidden" name="slides[<?php echo (int) $idx; ?>][object_position_desktop]" value="<?php echo esc_attr( (string) ( $slide['object_position_desktop'] ?? 'center center' ) ); ?>" data-objpos>
				<input type="hidden" data-media-dims-w value="<?php echo (int) $desktop_w; ?>">
				<input type="hidden" data-media-dims-h value="<?php echo (int) $desktop_h; ?>">
				<div class="akb-hero-media__buttons">
					<button type="button" class="button" data-media-pick><?php echo $desktop_url ? 'Cambiar' : 'Seleccionar'; ?></button>
					<a href="<?php echo $desktop_id ? esc_url( admin_url( 'post.php?post=' . (int) $desktop_id . '&action=edit' ) ) : '#'; ?>" target="_blank" rel="noopener" class="button-link akb-hero-media__edit" data-media-edit <?php echo $desktop_url ? '' : 'hidden'; ?>>Editar imagen</a>
					<button type="button" class="button-link akb-hero-media__remove" data-media-clear>Quitar</button>
				</div>
				<p class="akb-hero-media__focal-hint">Haz clic sobre la imagen para fijar el punto focal.</p>
				<p class="akb-hero-media__hint" data-media-hint></p>
			</div>
		</div>

		<div class="akb-hero-slide__media">
			<label class="akb-hero-slide__label">Imagen mobile <span class="akb-hero-slide__rec">· sugerido <?php echo (int) Settings::REC_MOBILE_WIDTH; ?>×<?php echo (int) Settings::REC_MOBILE_HEIGHT; ?></span></label>
			<div class="akb-hero-media" data-media="mobile">
				<div class="akb-hero-media__preview <?php echo $mobile_url ? '' : 'is-empty'; ?>" data-focal-x="<?php echo esc_attr( (string) $focal_m[0] ); ?>" data-focal-y="<?php echo esc_attr( (string) $focal_m[1] ); ?>">
					<?php if ( $mobile_url ) : ?>
						<img src="<?php echo esc_url( $mobile_url ); ?>" alt="" data-media-img>
					<?php else : ?>
						<span class="akb-hero-media__placeholder" data-media-img>Sin imagen</span>
					<?php endif; ?>
					<span class="akb-hero-media__focal" data-focal-pin aria-hidden="true"></span>
				</div>
				<input type="hidden" name="slides[<?php echo (int) $idx; ?>][mobile_id]" value="<?php echo (int) $mobile_id; ?>" data-media-input>
				<input type="hidden" name="slides[<?php echo (int) $idx; ?>][object_position_mobile]" value="<?php echo esc_attr( (string) ( $slide['object_position_mobile'] ?? 'center 80%' ) ); ?>" data-objpos>
				<input type="hidden" data-media-dims-w value="<?php echo (int) $mobile_w; ?>">
				<input type="hidden" data-media-dims-h value="<?php echo (int) $mobile_h; ?>">
				<div class="akb-hero-media__buttons">
					<button type="button" class="button" data-media-pick><?php echo $mobile_url ? 'Cambiar' : 'Seleccionar'; ?></button>
					<a href="<?php echo $mobile_id ? esc_url( admin_url( 'post.php?post=' . (int) $mobile_id . '&action=edit' ) ) : '#'; ?>" target="_blank" rel="noopener" class="button-link akb-hero-media__edit" data-media-edit <?php echo $mobile_url ? '' : 'hidden'; ?>>Editar imagen</a>
					<button type="button" class="button-link akb-hero-media__remove" data-media-clear>Quitar</button>
				</div>
				<p class="akb-hero-media__focal-hint">Haz clic sobre la imagen para fijar el punto focal.</p>
				<?php
				$akb_tablet_override = (string) ( $slide['object_position_tablet'] ?? '' );
				$akb_tablet_capped   = $focal_m[1] > Settings::TABLET_FOCAL_Y_CAP;
				$akb_hint_hidden     = ( '' === $akb_tablet_override && ! $akb_tablet_capped ) ? 'hidden' : '';
				$akb_hint_auto       = 'En tablet (601-768 px) el punto se ajusta solo (tope ' . (int) Settings::TABLET_FOCAL_Y_CAP . '%) para mantener el sujeto en cuadro. Verifícalo en Vista previa completa → Tablet.';
				$akb_hint_manual     = 'Tablet: usando el encuadre manual de Ajustes avanzados.';
				?>
				<p
					class="akb-hero-media__tablet-hint"
					data-tablet-hint
					data-auto-text="<?php echo esc_attr( $akb_hint_auto ); ?>"
					data-manual-text="<?php echo esc_attr( $akb_hint_manual ); ?>"
					<?php echo $akb_hint_hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal 'hidden' o ''. ?>
				><?php echo esc_html( '' !== $akb_tablet_override ? $akb_hint_manual : $akb_hint_auto ); ?></p>
				<p class="akb-hero-media__hint" data-media-hint></p>
			</div>
		</div>

		<div class="akb-hero-slide__fields">

			<p>
				<label>Texto alternativo (alt)
					<input type="text" name="slides[<?php echo (int) $idx; ?>][alt]" value="<?php echo esc_attr( (string) ( $slide['alt'] ?? '' ) ); ?>" maxlength="180" class="regular-text">
				</label>
				<span class="description">Déjalo vacío si la imagen es decorativa. Si aporta información que no está en el título, descríbela brevemente sin repetir el texto visible.</span>
			</p>

			<div class="akb-hero-slide__fieldgroup">
				<p>
					<label>Eyebrow (texto pequeño encima del título)
						<input type="text" name="slides[<?php echo (int) $idx; ?>][eyebrow]" value="<?php echo esc_attr( (string) ( $slide['eyebrow'] ?? '' ) ); ?>" maxlength="40" class="regular-text" placeholder="Ej: NOVEDAD, PREVENTA, OFERTA">
					</label>
				</p>

				<p>
					<label>Título (H1)
						<input type="text" name="slides[<?php echo (int) $idx; ?>][title]" value="<?php echo esc_attr( (string) ( $slide['title'] ?? '' ) ); ?>" maxlength="60" class="regular-text" data-live-field="title">
					</label>
				</p>

				<p>
					<label>Lede (subtítulo)
						<input type="text" name="slides[<?php echo (int) $idx; ?>][lede]" value="<?php echo esc_attr( (string) ( $slide['lede'] ?? '' ) ); ?>" maxlength="180" class="regular-text" data-live-field="lede">
					</label>
				</p>
			</div>

			<div class="akb-hero-slide__fieldgroup">
				<p class="akb-hero-slide__inline">
					<label>Color del título
						<input type="text" name="slides[<?php echo (int) $idx; ?>][title_color]" value="<?php echo esc_attr( (string) ( $slide['title_color'] ?? '' ) ); ?>" class="akb-color-picker" data-default-color="#FFFFFF">
					</label>
					<label>Color del lede
						<input type="text" name="slides[<?php echo (int) $idx; ?>][lede_color]" value="<?php echo esc_attr( (string) ( $slide['lede_color'] ?? '' ) ); ?>" class="akb-color-picker" data-default-color="#FFFFFF">
					</label>
				</p>
				<span class="description">Deja vacío para usar los colores default del tema (blanco). Para destacar "NOVEDAD", "PREVENTA", etc. usa el <strong>Eyebrow</strong> arriba.</span>
			</div>

			<div class="akb-hero-slide__fieldgroup">
				<p class="akb-hero-slide__inline">
					<label>Fuente del título
						<select name="slides[<?php echo (int) $idx; ?>][title_font]">
							<option value="" <?php selected( $slide['title_font'] ?? '', '' ); ?>>Default (Bebas Neue)</option>
							<option value="Saira" <?php selected( $slide['title_font'] ?? '', 'Saira' ); ?>>Saira</option>
							<option value="Inter" <?php selected( $slide['title_font'] ?? '', 'Inter' ); ?>>Inter</option>
							<option value="system-ui" <?php selected( $slide['title_font'] ?? '', 'system-ui' ); ?>>Sistema (UI del dispositivo)</option>
						</select>
					</label>
					<label>Peso (Título)
						<select name="slides[<?php echo (int) $idx; ?>][title_weight]">
							<option value="" <?php selected( $slide['title_weight'] ?? '', '' ); ?>>Default</option>
							<option value="400" <?php selected( $slide['title_weight'] ?? '', '400' ); ?>>Normal (400)</option>
							<option value="600" <?php selected( $slide['title_weight'] ?? '', '600' ); ?>>Semi-bold (600)</option>
							<option value="700" <?php selected( $slide['title_weight'] ?? '', '700' ); ?>>Bold (700)</option>
							<option value="900" <?php selected( $slide['title_weight'] ?? '', '900' ); ?>>Black (900)</option>
						</select>
					</label>
				</p>
				<p class="akb-hero-slide__inline">
					<label>Fuente del lede
						<select name="slides[<?php echo (int) $idx; ?>][lede_font]">
							<option value="" <?php selected( $slide['lede_font'] ?? '', '' ); ?>>Default (Inter)</option>
							<option value="Bebas Neue" <?php selected( $slide['lede_font'] ?? '', 'Bebas Neue' ); ?>>Bebas Neue</option>
							<option value="Saira" <?php selected( $slide['lede_font'] ?? '', 'Saira' ); ?>>Saira</option>
							<option value="system-ui" <?php selected( $slide['lede_font'] ?? '', 'system-ui' ); ?>>Sistema (UI del dispositivo)</option>
						</select>
					</label>
					<label>Peso (Lede)
						<select name="slides[<?php echo (int) $idx; ?>][lede_weight]">
							<option value="" <?php selected( $slide['lede_weight'] ?? '', '' ); ?>>Default</option>
							<option value="400" <?php selected( $slide['lede_weight'] ?? '', '400' ); ?>>Normal (400)</option>
							<option value="600" <?php selected( $slide['lede_weight'] ?? '', '600' ); ?>>Semi-bold (600)</option>
							<option value="700" <?php selected( $slide['lede_weight'] ?? '', '700' ); ?>>Bold (700)</option>
							<option value="900" <?php selected( $slide['lede_weight'] ?? '', '900' ); ?>>Black (900)</option>
						</select>
					</label>
					<label>Estilo visual
						<select name="slides[<?php echo (int) $idx; ?>][lede_style]">
							<?php foreach ( Settings::LEDE_STYLES as $akb_lsk => $akb_lsv ) : ?>
								<option value="<?php echo esc_attr( $akb_lsk ); ?>" <?php selected( $slide['lede_style'] ?? 'plain', $akb_lsk ); ?>><?php echo esc_html( $akb_lsv ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</p>
			</div>

			<div class="akb-hero-slide__fieldgroup akb-hero-position">
				<label class="akb-hero-slide__label">Disposición del texto</label>
				<div class="akb-hero-position__layout">

					<div class="akb-hero-position__step">
						<span class="akb-hero-position__step-num" aria-hidden="true">1</span>
						<div class="akb-hero-position__step-content">
							<span class="akb-hero-position__step-title">Posición sobre la imagen <small>(desktop)</small></span>
							<div class="akb-position-grid" role="radiogroup" aria-label="Posición del bloque de texto sobre la imagen (desktop)">
								<?php
								$akb_current_pos = (string) ( $slide['text_position'] ?? 'bottom-left' );
								foreach ( Settings::TEXT_POSITIONS as $akb_pos ) :
									$akb_pos_label = $admin_ui->position_label( $akb_pos );
									?>
									<label class="akb-position-grid__cell <?php echo $akb_current_pos === $akb_pos ? 'is-active' : ''; ?>" title="<?php echo esc_attr( $akb_pos_label ); ?>">
										<input
											type="radio"
											name="slides[<?php echo (int) $idx; ?>][text_position]"
											value="<?php echo esc_attr( $akb_pos ); ?>"
											<?php checked( $akb_current_pos, $akb_pos ); ?>
											data-position-radio
										>
										<span class="akb-position-grid__dot" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php echo esc_html( $akb_pos_label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>

					<div class="akb-hero-position__step">
						<span class="akb-hero-position__step-num" aria-hidden="true">2</span>
						<div class="akb-hero-position__step-content">
							<span class="akb-hero-position__step-title">Alineación del texto</span>
							<div class="akb-hero-position__pills" role="radiogroup" aria-label="Alineación del texto">
								<?php
								$akb_current_align = (string) ( $slide['text_align'] ?? 'left' );
								$akb_align_icons   = array( 'left' => '⟸', 'center' => '⇔', 'right' => '⟹' );
								$akb_align_labels  = array( 'left' => 'Izquierda', 'center' => 'Centro', 'right' => 'Derecha' );
								foreach ( Settings::TEXT_ALIGNS as $akb_align ) : ?>
									<label class="akb-hero-position__pill <?php echo $akb_current_align === $akb_align ? 'is-active' : ''; ?>">
										<input
											type="radio"
											name="slides[<?php echo (int) $idx; ?>][text_align]"
											value="<?php echo esc_attr( $akb_align ); ?>"
											<?php checked( $akb_current_align, $akb_align ); ?>
											data-align-radio
										>
										<span><?php echo esc_html( $akb_align_icons[ $akb_align ] ); ?> <?php echo esc_html( $akb_align_labels[ $akb_align ] ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>

					<div class="akb-hero-position__step">
						<span class="akb-hero-position__step-num" aria-hidden="true">3</span>
						<div class="akb-hero-position__step-content">
							<span class="akb-hero-position__step-title">Layout en mobile</span>
							<div class="akb-hero-position__pills akb-hero-position__pills--block" role="radiogroup" aria-label="Layout del texto en mobile">
								<?php
								$akb_current_mlayout = (string) ( $slide['mobile_text_layout'] ?? 'below' );
								$akb_mlayout_svgs    = array(
									'below'          => '<svg viewBox="0 0 40 40" aria-hidden="true" focusable="false"><rect x="6" y="6" width="28" height="20" rx="2" fill="currentColor" opacity=".18"/><rect x="6" y="28" width="28" height="6" rx="1.2" fill="currentColor"/></svg>',
									'overlay-bottom' => '<svg viewBox="0 0 40 40" aria-hidden="true" focusable="false"><rect x="6" y="6" width="28" height="28" rx="2" fill="currentColor" opacity=".18"/><rect x="9" y="24" width="22" height="6" rx="1.2" fill="currentColor"/></svg>',
									'overlay-center' => '<svg viewBox="0 0 40 40" aria-hidden="true" focusable="false"><rect x="6" y="6" width="28" height="28" rx="2" fill="currentColor" opacity=".18"/><rect x="9" y="17" width="22" height="6" rx="1.2" fill="currentColor"/></svg>',
								);
								foreach ( Settings::MOBILE_TEXT_LAYOUTS as $akb_mlk => $akb_mlv ) : ?>
									<label class="akb-hero-position__pill akb-hero-position__pill--svg <?php echo $akb_current_mlayout === $akb_mlk ? 'is-active' : ''; ?>">
										<input
											type="radio"
											name="slides[<?php echo (int) $idx; ?>][mobile_text_layout]"
											value="<?php echo esc_attr( $akb_mlk ); ?>"
											<?php checked( $akb_current_mlayout, $akb_mlk ); ?>
											data-mlayout-radio
										>
										<span class="akb-hero-position__pill-svg"><?php echo $akb_mlayout_svgs[ $akb_mlk ] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literales del array arriba. ?></span>
										<span><?php echo esc_html( $akb_mlv ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>

				</div>
			</div>

			<details class="akb-hero-slide__advanced">
				<summary>Ajustes avanzados (vignette + link envoltorio + encuadre tablet)</summary>

				<p>
					<label>Opacidad del vignette (%)
						<input type="number" name="slides[<?php echo (int) $idx; ?>][vignette_opacity]" value="<?php echo (int) ( $slide['vignette_opacity'] ?? 50 ); ?>" min="0" max="100" step="1" style="width:80px;">
					</label>
					<span class="description">Oscurecimiento bottom-left para legibilidad. 0 = sin vignette. 50 = default.</span>
				</p>
				<p class="akb-hero-slide__vignette-warning" data-vignette-warning hidden role="status" aria-live="polite"></p>

				<p>
					<label>Link envoltorio (opcional)
						<input type="text" inputmode="url" autocomplete="url" name="slides[<?php echo (int) $idx; ?>][link_url]" value="<?php echo esc_url( (string) ( $slide['link_url'] ?? '' ) ); ?>" class="regular-text" placeholder="/tienda/">
					</label>
					<span class="description">Si no usas botones y quieres que toda la imagen sea clicable, ingresa aquí una URL.</span>
				</p>

				<p>
					<label>Encuadre tablet (opcional)
						<input type="text" name="slides[<?php echo (int) $idx; ?>][object_position_tablet]" value="<?php echo esc_attr( (string) ( $slide['object_position_tablet'] ?? '' ) ); ?>" class="regular-text" placeholder="auto (derivado del punto focal mobile)" data-objpos-tablet>
					</label>
					<span class="description">Solo si el ajuste automático no te sirve (ej. sujeto en la mitad inferior del arte). Formato <code>object-position</code>, ej. <code>center 30%</code>. Vacío = automático.</span>
				</p>
			</details>

			<div class="akb-hero-slide__ctas">
				<label class="akb-hero-slide__label">Llamados a la acción (máx <?php echo (int) Settings::MAX_CTAS; ?>)</label>
				<div class="akb-hero-ctas" data-ctas>
					<?php
					$akb_ctas = isset( $slide['ctas'] ) && is_array( $slide['ctas'] ) ? $slide['ctas'] : array();
					foreach ( $akb_ctas as $akb_cidx => $akb_cta ) {
						$admin_ui->render_cta_fieldset( $idx, (int) $akb_cidx, $akb_cta );
					}
					?>
				</div>
				<button type="button" class="button-link akb-hero-cta__add" data-action="add-cta">+ Agregar CTA</button>
			</div>

		</div>

		<aside class="akb-hero-slide__preview-pane" aria-label="Vista previa del slide">
			<div class="akb-hero-preview" data-slide-preview>
				<div class="akb-hero-preview__head">
					<span class="akb-hero-preview__title-head">Vista previa fiel</span>
					<div class="akb-hero-preview__device-toggle" role="tablist" aria-label="Cambiar vista de dispositivo">
						<button type="button" role="tab" data-preview-device="desktop" class="is-active" aria-selected="true" title="Vista desktop">🖥</button>
						<button type="button" role="tab" data-preview-device="tablet" aria-selected="false" title="Vista tablet">▢</button>
						<button type="button" role="tab" data-preview-device="mobile" aria-selected="false" title="Vista mobile">📱</button>
					</div>
				</div>
				<div class="akb-hero-preview__frame-wrap" data-preview-frame-wrap>
					<div class="akb-hero-preview__skeleton" data-preview-skeleton<?php echo $desktop_url ? '' : ' hidden'; ?>>
						<?php if ( $desktop_url ) : ?>
							<img src="<?php echo esc_url( $desktop_url ); ?>" alt="" loading="lazy">
						<?php endif; ?>
						<span class="akb-hero-preview__skeleton-label">Cargando vista fiel…</span>
					</div>
					<iframe class="akb-hero-preview__frame" data-preview-frame title="Vista previa fiel del slide" scrolling="no" tabindex="-1" hidden></iframe>
				</div>
				<p class="akb-hero-preview__hint">Vista <strong>real</strong> de la diapositiva: usa el mismo motor que el sitio y se actualiza al editar, con un instante de retardo. Para revisar el carrusel completo usa <strong>Vista previa completa</strong>.</p>
			</div>
		</aside>

	</div>
</fieldset>
