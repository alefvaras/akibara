<?php
/**
 * Tab "Disponibilidad" en la pantalla de edición de producto Woo.
 *
 * ES el selector del estado comercial de un producto reservable (1.109.0):
 *   - `auto` (default): A PEDIDO — se pide a la editorial; hereda su regla
 *     (tanda o país). Cargar backlist no requiere tocar este panel.
 *   - `release_date`: PREVENTA con fecha de lanzamiento (+ plazo personalizado
 *     opcional por ficha). Al vencer la fecha, la ficha degrada SOLA a A pedido.
 *   - `tba`: PREVENTA sin fecha confirmada.
 *   - `disabled`: reservable sin promesa de fecha (sigue siendo A pedido).
 *
 * Hooks WC oficiales:
 *   - `woocommerce_product_data_tabs` → registrar el tab en el meta box.
 *   - `woocommerce_product_data_panels` → render del panel asociado.
 *   - `woocommerce_process_product_meta` → save (WC ya verificó nonce).
 *
 * Assets: `admin-eda.css` + `admin-eda.js` enqueued solo en el screen
 * `product` (post.php / post-new.php con post_type=product).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * Product edit screen — tab para override del ETA.
 *
 * @since 1.7.0
 */
final class ProductDataPanel {

	/**
	 * Campo oculto que confirma que el panel viajó en el envío del formulario.
	 *
	 * `woocommerce_process_product_meta` corre en cada guardado de producto,
	 * venga de donde venga. Sin este testigo no hay forma de distinguir «el dueño
	 * dejó el tipo en auto y sin fecha» de «este POST nunca incluyó el panel», y
	 * la segunda lectura es la que borraba datos.
	 */
	public const PANEL_MARKER = '_akb_eta_panel';

	private const TYPE_LABELS = array(
		Availability::TYPE_AUTO         => 'A pedido (automático) — se pide a la editorial; usa la regla de su editorial o país',
		Availability::TYPE_RELEASE_DATE => 'Preventa — todavía no sale (con fecha de lanzamiento)',
		Availability::TYPE_TBA          => 'Preventa — todavía no sale (aún sin fecha confirmada)',
		Availability::TYPE_DISABLED     => 'Sin promesa de fecha (no mostrar plazos en la ficha)',
	);

	/**
	 * Días sumados a hoy para la sugerencia de release_date cuando el field
	 * está vacío. 60d ≈ tiempo típico entre que se conoce la fecha y se publica.
	 */
	private const RELEASE_DATE_SUGGESTION_OFFSET_DAYS = 60;

	private DateCalculator $calculator;

	/**
	 * @param DateCalculator $calculator Usado para computar el preview server-side
	 *                                   del radio "Auto".
	 */
	public function __construct( DateCalculator $calculator ) {
		$this->calculator = $calculator;
	}

	/**
	 * Hooks entry point. Llamado desde EstimatedDelivery::register().
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue de CSS+JS solo en el screen de edición de producto.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}

		wp_enqueue_style(
			'akb-admin-eda',
			AKIBARA_BUSINESS_URL . 'assets/css/admin-eda.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/admin-eda.css' )
		);

		wp_enqueue_script(
			'akb-admin-eda',
			AKIBARA_BUSINESS_URL . 'assets/js/admin-eda.js',
			array( 'jquery' ),
			PluginAssetVersion::for_path( 'assets/js/admin-eda.js' ),
			true
		);
	}

	/**
	 * Agrega el tab al meta box "Datos del producto".
	 *
	 * @param array<string, array<string, mixed>> $tabs Tabs registrados por WC + plugins.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_tab( array $tabs ): array {
		$tabs['akb_eda'] = array(
			'label'    => 'Disponibilidad',
			'target'   => 'akb_eda_data',
			'class'    => array( 'show_if_simple', 'show_if_variable', 'show_if_external' ),
			'priority' => 70,
		);
		return $tabs;
	}

	/**
	 * Renderiza el contenido del panel.
	 *
	 * @return void
	 */
	public function render_panel(): void {
		global $post;

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$current_type = Availability::sanitize_type( (string) get_post_meta( $post->ID, DateCalculator::META_TYPE, true ) );

		$release_date = (string) get_post_meta( $post->ID, DateCalculator::META_RELEASE_DATE, true );
		$manual_min   = (string) get_post_meta( $post->ID, DateCalculator::META_MANUAL_MIN_DIAS, true );
		$manual_max   = (string) get_post_meta( $post->ID, DateCalculator::META_MANUAL_MAX_DIAS, true );

		$product       = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
		$is_instock    = $product && StockStatus::is_instock( $product );
		$auto_preview  = $product ? $this->calculator->preview_for_admin( $product ) : null;

		$tz                  = StoreTime::zone();
		$today_local         = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		// Misma fuente que el guard de `sanitize_post()`: UI y backend no pueden
		// divergir en la cota o el admin vería un `max` que el save no respeta.
		$max_date_local      = Availability::max_release_date();
		$suggested_date_local = ( new \DateTimeImmutable( 'now', $tz ) )
			->modify( '+' . self::RELEASE_DATE_SUGGESTION_OFFSET_DAYS . ' days' )
			->format( 'Y-m-d' );

		// Los controles ya NO se deshabilitan. Un `<input disabled>` no se envía
		// con el formulario, así que al guardar un producto en stock el POST
		// llegaba sin `_akb_eta_type` ni `_akb_eta_release_date`: `sanitize_post()`
		// lo leía como «auto / sin fecha» y `save_product_meta()` sobrescribía el
		// tipo y BORRABA la fecha de lanzamiento configurada. Peor todavía, el
		// borrado disparaba `akb_eta_release_date_changed` con fecha nueva vacía y
		// `ReleaseDateChangeListener` avisaba a los clientes con preventas vivas de
		// un cambio que nadie hizo. Bastaba con corregir el precio de un producto
		// ya lanzado —o reponer stock de uno que estuvo en preventa— para perder su
		// configuración.
		//
		// Dejarlos editables además habilita el flujo natural: dejar la fecha lista
		// y recién entonces pasar el stock a preventa.
		?>
		<div id="akb_eda_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<?php // Marca que este panel viajó en el POST. Sin ella, cualquier guardado que no lo incluya se leería como «el dueño lo dejó todo vacío». ?>
				<input type="hidden" name="<?php echo esc_attr( self::PANEL_MARKER ); ?>" value="1" />
				<?php if ( $is_instock ) : ?>
					<div class="notice notice-info inline akb-eda-instock-notice">
						<p>
							Este producto está <strong>en stock</strong>, así que el banner de fecha de llegada no se muestra en la ficha: solo aplica a productos en preventa (stock status <code>onbackorder</code>). Lo que configures aquí se guarda igual y vuelve a usarse en cuanto pases el producto a preventa.
						</p>
					</div>
				<?php endif; ?>

				<p class="form-field">
					<label><strong>Estado del producto reservable</strong></label>
					<span class="description">«A pedido» = ya publicado, lo traemos en el próximo pedido. «Preventa» = todavía no sale y se reserva antes del lanzamiento.</span>
				</p>
				<?php foreach ( self::TYPE_LABELS as $value => $label ) : ?>
					<p class="form-field akb-eda-radio-row">
						<label class="akb-eda-radio-label">
							<input
								type="radio"
								name="_akb_eta_type"
								value="<?php echo esc_attr( $value ); ?>"
								<?php checked( $current_type, $value ); ?>
							/>
							<?php echo esc_html( $label ); ?>
						</label>
						<?php if ( Availability::TYPE_AUTO === $value && null !== $auto_preview ) : ?>
							<span class="akb-eda-auto-preview">
								<?php echo $this->render_auto_preview( $auto_preview ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped internamente. ?>
							</span>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>

				<p class="form-field akb-eda-release-date-row">
					<label for="_akb_eta_release_date">Fecha de lanzamiento</label>
					<input
						type="date"
						id="_akb_eta_release_date"
						name="_akb_eta_release_date"
						value="<?php echo esc_attr( $release_date ); ?>"
						class="short"
						min="<?php echo esc_attr( $today_local ); ?>"
						max="<?php echo esc_attr( $max_date_local ); ?>"
					/>
					<span class="description akb-eda-release-date-help">
						Fecha en que la editorial publica el producto en su país de origen.
						<?php if ( '' === $release_date ) : ?>
							<br>
							<em>Sugerencia: <?php echo esc_html( $suggested_date_local ); ?> (hoy + <?php echo (int) self::RELEASE_DATE_SUGGESTION_OFFSET_DAYS; ?> días).</em>
						<?php endif; ?>
						<br>
						<em>Cuando esta fecha se cumpla, la ficha pasará sola a «A pedido» y entrará al pedido normal de su editorial.</em>
					</span>
				</p>
				<?php if ( Availability::TYPE_RELEASE_DATE === $current_type && '' !== $release_date && $release_date < $today_local ) : ?>
					<div class="notice notice-warning inline">
						<p><strong>Esa fecha ya pasó:</strong> el tomo ya salió, así que la ficha se está mostrando como <strong>«A pedido»</strong> (no como preventa). Si es un error de fecha, corrígela; si el tomo efectivamente salió, puedes dejar el estado en «A pedido (automático)».</p>
					</div>
				<?php endif; ?>

				<p class="form-field akb-eda-manual-lead-row">
					<label for="_akb_eta_manual_min_dias">Plazo personalizado (opcional)</label>
					<input
						type="number"
						id="_akb_eta_manual_min_dias"
						name="_akb_eta_manual_min_dias"
						value="<?php echo esc_attr( $manual_min ); ?>"
						class="short"
						min="1"
						max="365"
						step="1"
						placeholder="mín"
						style="width:5em;"
					/>
					&nbsp;a&nbsp;
					<input
						type="number"
						id="_akb_eta_manual_max_dias"
						name="_akb_eta_manual_max_dias"
						value="<?php echo esc_attr( $manual_max ); ?>"
						class="short"
						min="1"
						max="365"
						step="1"
						placeholder="máx"
						style="width:5em;"
					/>
					&nbsp;días corridos tras el lanzamiento
					<span class="description">
						Cada preventa es distinta: escribe aquí su plazo si no calza con la regla general
						(p. ej. 60 a 75 para «unos 2 meses», 90 a 105 para «unos 3»). Vacío = usa la regla
						de la editorial o del país, como siempre. Este plazo manda sobre la tanda.
					</span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza el texto inline de preview del radio "Auto".
	 *
	 * Tres formatos según el status del preview (post-refactor pa_pais 1.14.0):
	 *   - `ok`               → "Argentina → 12-15 días → llega 27 may – 1 jun"
	 *   - `no_pa_pais`       → "Sin país asignado — asigná pa_pais en este producto."
	 *   - `invalid_lead_time`→ "X — lead time no configurado para este país."
	 *
	 * @param array<string, mixed> $preview Resultado de DateCalculator::preview_for_admin.
	 * @return string HTML pre-escapado.
	 */
	private function render_auto_preview( array $preview ): string {
		$status = isset( $preview['status'] ) ? (string) $preview['status'] : 'no_pa_pais';

		if ( 'ok' === $status ) {
			$current_year = (int) ( new \DateTimeImmutable( 'now', StoreTime::zone() ) )->format( 'Y' );
			$date_range   = BannerRenderer::format_range(
				(int) $preview['min_ts'],
				(int) $preview['max_ts'],
				$current_year
			);
			return sprintf(
				'<span class="akb-eda-preview-ok"><strong>%s</strong> → %d-%d días → llega %s</span>',
				esc_html( (string) $preview['country_name'] ),
				(int) $preview['min_days'],
				(int) $preview['max_days'],
				esc_html( $date_range )
			);
		}

		if ( 'no_pa_pais' === $status ) {
			return '<span class="akb-eda-preview-warn">Sin país asignado — asigná el atributo <code>pa_pais</code> a este producto para que el banner se calcule.</span>';
		}

		// invalid_lead_time
		$country_name = (string) ( $preview['country_name'] ?? $preview['country_slug'] ?? '—' );
		$edit_url     = admin_url( 'edit-tags.php?taxonomy=pa_pais&post_type=product' );
		return sprintf(
			'<span class="akb-eda-preview-warn"><strong>%s</strong> — lead time no configurado. Editá el término en <a href="%s">wp-admin → Productos → Atributos → País</a>.</span>',
			esc_html( $country_name ),
			esc_url( $edit_url )
		);
	}

	/**
	 * Persiste los postmeta. WC ya verificó nonce + capability en su flujo.
	 *
	 * @param int $product_id ID del producto.
	 * @return void
	 */
	public function save_product_meta( int $product_id ): void {
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC verifica el nonce antes de llamar a process_product_meta.
		// Sin el testigo del panel no hay nada que guardar. Escribir igual
		// convierte cualquier guardado ajeno —o cualquier envío donde el panel
		// no viajó— en un borrado silencioso del tipo y la fecha.
		if ( empty( $_POST[ self::PANEL_MARKER ] ) ) {
			return;
		}

		$sanitized = self::sanitize_post( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Preventa sin fecha se guarda como TBA: el lanzamiento sigue pendiente.
		// Una fecha inválida conserva el valor anterior, sin convertir la obra
		// pendiente en un pedido de una edición ya publicada.
		if ( Availability::TYPE_RELEASE_DATE === $sanitized['type']
			&& null === $sanitized['release_date']
			&& empty( $sanitized['release_date_invalid'] ) ) {
			$sanitized['type'] = Availability::TYPE_TBA;
			// Se le dice, como en la rama de fecha inválida: cambiar lo que el
			// dueño marcó sin avisar es lo que esta ficha no debe hacer.
			if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
				\WC_Admin_Meta_Boxes::add_error( 'Marcaste «Preventa — con fecha» sin escribir la fecha: el tomo quedó como «Preventa — sin fecha confirmada». Ponle la fecha cuando la editorial la confirme; mientras tanto, para Google es como «Sin promesa de fecha» (Akibara → Entregas).' );
			}
		}

		update_post_meta( $product_id, DateCalculator::META_TYPE, $sanitized['type'] );

		// Capturar valor previo ANTES de mutar — el listener de
		// OrderPreventa lo necesita para decidir si notificar al cliente
		// el cambio de fecha (threshold 7d + solo postergación).
		$old_release_date = (string) get_post_meta( $product_id, DateCalculator::META_RELEASE_DATE, true );
		$new_release_date = $sanitized['release_date'] ?? '';

		if ( ! empty( $sanitized['release_date_invalid'] ) ) {
			// Conserva la fecha anterior e informa del rechazo en la ficha y en Por llegar.
			$new_release_date = $old_release_date;
			if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
				\WC_Admin_Meta_Boxes::add_error(
					sprintf(
						'La fecha de lanzamiento no se guardó: tiene que ser un día real y como mucho %d años en el futuro (el error típico es un año con un dígito de más, 2206 en vez de 2026). Se mantiene la anterior.',
						Availability::MAX_RELEASE_DATE_YEARS
					)
				);
			}
		} elseif ( null !== $sanitized['release_date'] ) {
			update_post_meta( $product_id, DateCalculator::META_RELEASE_DATE, $sanitized['release_date'] );
		} else {
			delete_post_meta( $product_id, DateCalculator::META_RELEASE_DATE );
		}

		if ( null !== $sanitized['manual_min'] && null !== $sanitized['manual_max'] ) {
			update_post_meta( $product_id, DateCalculator::META_MANUAL_MIN_DIAS, (string) $sanitized['manual_min'] );
			update_post_meta( $product_id, DateCalculator::META_MANUAL_MAX_DIAS, (string) $sanitized['manual_max'] );
		} else {
			delete_post_meta( $product_id, DateCalculator::META_MANUAL_MIN_DIAS );
			delete_post_meta( $product_id, DateCalculator::META_MANUAL_MAX_DIAS );
		}

		if ( $old_release_date !== $new_release_date ) {
			/**
			 * Fires cuando un producto cambia su `_akb_eta_release_date` desde
			 * el admin. Hook anémico — `ReleaseDateChangeListener` se subscribe
			 * para detectar órdenes preventa afectadas y notificar al cliente.
			 *
			 * @since 1.19.0
			 *
			 * @param int    $product_id       ID del producto.
			 * @param string $old_release_date Y-m-d previo, '' si no existía.
			 * @param string $new_release_date Y-m-d nuevo, '' si se borró.
			 */
			do_action( 'akb_eta_release_date_changed', $product_id, $old_release_date, $new_release_date );
		}
	}

	/**
	 * Sanitiza el `$_POST` array crudo. Pure function — testeable sin WP.
	 *
	 * Reglas:
	 *   - `type`: allowlist de `Availability::TYPES`. Cualquier otro → TYPE_AUTO.
	 *   - `release_date`: solo se guarda si type=release_date Y matchea Y-m-d Y
	 *     no supera hoy + MAX_RELEASE_DATE_YEARS. Si no, se devuelve null
	 *     (signal al caller para `delete_post_meta`).
	 *
	 * Pureza: el default de `$today` consulta el reloj (cota superior relativa a
	 * hoy). Pasando `$today` explícito la función vuelve a ser pura y testeable
	 * sin WP, como declaraba el contrato original.
	 *
	 * Los plazos manuales (`manual_min`/`manual_max`) solo sobreviven con un
	 * tipo de PREVENTA (release_date/tba), como enteros 1–365 con max >= min.
	 * Un par a medias o fuera de rango se descarta COMPLETO (ambos null →
	 * delete): mejor caer a la regla general que persistir un rango imposible.
	 *
	 * @param array<string, mixed> $post_data Array crudo (típicamente `$_POST`).
	 * @param string|null          $today     `Y-m-d` base de la cota superior. null = hoy.
	 * @return array{type: string, release_date: string|null, release_date_invalid: bool, manual_min: int|null, manual_max: int|null}
	 */
	public static function sanitize_post( array $post_data, ?string $today = null ): array {
		$type_raw = isset( $post_data['_akb_eta_type'] ) ? (string) $post_data['_akb_eta_type'] : '';
		$type     = Availability::sanitize_type( $type_raw );

		$release_date         = null;
		$release_date_invalid = false;
		if ( Availability::TYPE_RELEASE_DATE === $type ) {
			$raw_date = isset( $post_data['_akb_eta_release_date'] ) ? trim( (string) $post_data['_akb_eta_release_date'] ) : '';
			// La regla vive con la meta (Availability): la misma que aplica la
			// pantalla «Por llegar» y el schema de producto. Una fecha escrita
			// pero inválida (el año con un dígito de más) se REPORTA, no se
			// convierte en «sin fecha»: borrarla en silencio disparaba «fecha
			// retirada» en todos los pedidos que la esperaban.
			if ( Availability::is_valid_release_date( $raw_date, $today ) ) {
				$release_date = $raw_date;
			} elseif ( '' !== $raw_date ) {
				$release_date_invalid = true;
			}
		}

		$manual_min = null;
		$manual_max = null;
		if ( in_array( $type, Availability::TYPES_PREVENTA, true ) ) {
			$raw_min = isset( $post_data['_akb_eta_manual_min_dias'] ) ? trim( (string) $post_data['_akb_eta_manual_min_dias'] ) : '';
			$raw_max = isset( $post_data['_akb_eta_manual_max_dias'] ) ? trim( (string) $post_data['_akb_eta_manual_max_dias'] ) : '';
			if ( 1 === preg_match( '/^\d{1,3}$/', $raw_min ) && 1 === preg_match( '/^\d{1,3}$/', $raw_max ) ) {
				$min = (int) $raw_min;
				$max = (int) $raw_max;
				if ( $min >= 1 && $min <= 365 && $max >= $min && $max <= 365 ) {
					$manual_min = $min;
					$manual_max = $max;
				}
			}
		}

		return array(
			'type'                 => $type,
			'release_date'         => $release_date,
			'release_date_invalid' => $release_date_invalid,
			'manual_min'           => $manual_min,
			'manual_max'           => $manual_max,
		);
	}

}
