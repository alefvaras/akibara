<?php
/**
 * Akibara EstimatedDelivery — banner de fecha estimada de llegada para preventas.
 *
 * Modela 2 tipos de "preventa" reales del catálogo Akibara:
 *
 *   A) Importación pendiente — producto publicado por editorial, falta importar.
 *      Lead time según país (AR 12-15d, ES 17-20d). Caso default.
 *
 *   B) Pre-lanzamiento — editorial aún no publica. Postmeta `_akb_eta_release_date`
 *      con fecha lanzamiento + lead time país. Si fecha desconocida → TBA.
 *      Caso paradigmático: Berserk Master Edition Panini ES (lanza 2 jul 2026).
 *
 * Desde 1.14.0 (refactor pa_pais):
 *   - País del producto se resuelve desde el atributo `pa_pais` del producto.
 *   - Lead times viven en term-meta de cada término pa_pais (UI nativa en
 *     wp-admin → Productos → Atributos → País → editar término).
 *   - Owner agrega países nuevos sin tocar código.
 *   - Settings AdminPanel solo conserva "Saltar fines de semana" (toggle global).
 *
 * Arquitectura:
 *   - DateCalculator (lógica pura).
 *   - Settings (term-meta + fallback options legacy + ultimate const).
 *   - ProductDataPanel (tab "Fecha de llegada" en product edit).
 *   - PaisTermFields (campos min/max días en cada término pa_pais).
 *   - BannerRenderer (single + product loops).
 *   - CheckoutDataService exposes cart aggregation to the Blocks adapter.
 *   - Migration (idempotent: initializes term-meta para slugs conocidos).
 *   - GlaBridge (feed Google Shopping availabilityDate).
 *   - get_settings() → AdminPanel consolidado (`wp-admin → Akibara`).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * Module shell — orquesta sub-componentes + integra con AdminPanel.
 *
 * @since 1.7.0
 */
final class EstimatedDelivery {

	private Settings $settings;
	private DateCalculator $calculator;
	private ProductDataPanel $product_panel;
	private BannerRenderer $banner;
	private GlaBridge $gla_bridge;

	public function __construct() {
		$this->settings      = new Settings();
		$this->calculator    = new DateCalculator( $this->settings );
		$this->product_panel = new ProductDataPanel( $this->calculator );
		$this->banner        = new BannerRenderer( $this->calculator );
		$this->gla_bridge    = new GlaBridge( $this->calculator, $this->settings );
	}

	/**
	 * Transient key prefix para errores de validación cross-field.
	 * El AdminPanel lo consume en render_page() y los pinta como
	 * `notice notice-error` después del redirect post-save.
	 */
	public const SAVE_ERRORS_TRANSIENT_PREFIX = 'akibara_eda_save_errors_';

	/**
	 * Hooks entry point llamado por Plugin::register_modules().
	 *
	 * @return void
	 */
	public function register(): void {
		$this->product_panel->register();
		$this->banner->register();
		$this->gla_bridge->register();

		add_action( 'woocommerce_admin_field_akb_notice', array( $this, 'render_notice' ) );

		// Field type custom para min/max días de cada país (term-meta).
		add_action( 'woocommerce_admin_field_akb_pais_range_pair', array( $this, 'render_pais_range_pair' ) );
		add_action( 'woocommerce_update_option_akb_pais_range_pair', array( $this, 'save_pais_range_pair' ) );
	}

	/**
	 * Settings fields para AdminPanel. Llamado por `woocommerce_admin_fields()`
	 * en el tab "Fecha de llegada" del Akibara panel.
	 *
	 * Post-refactor pa_pais (1.14.0):
	 *   - Sección "Países y días de tránsito" lista los términos `pa_pais`
	 *     detectados con sus min/max editables inline. Cada par escribe a
	 *     term-meta `_akb_min_days` / `_akb_max_days` del término.
	 *   - El loop por términos es dinámico: si owner agrega "México" en el
	 *     taxonomy, aparece automáticamente una línea nueva con min/max vacíos.
	 *   - Sección "Ajustes globales" mantiene "Saltar fines de semana".
	 *   - Alternativa equivalente: editar el término desde wp-admin → Productos
	 *     → Atributos → País → click en término (vía `PaisTermFields`). Ambas
	 *     UIs leen/escriben el mismo term-meta — single source of truth.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		$fields = array(
			array(
				'title' => 'Fecha estimada de llegada',
				'type'  => 'title',
				'desc'  => 'La ETA aparece en productos y, mediante Akibara Cart and Checkout Blocks, en carrito y checkout para preventas (<code>onbackorder</code>). Cada producto debe tener el atributo <strong>País</strong> (<code>pa_pais</code>) asignado para calcular su llegada.',
				'id'    => 'akibara_eda_intro_section',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akibara_eda_intro_section',
			),

			array(
				'title' => 'Países y días de tránsito',
				'type'  => 'title',
				'desc'  => 'Cada país detectado del taxonomy <code>pa_pais</code> aparece acá. Los días son hábiles desde generar la importación hasta llegar a Akibara.',
				'id'    => 'akibara_eda_paises_section',
			),
		);

		$fields = array_merge( $fields, $this->build_pais_fields() );

		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'akibara_eda_paises_section',
		);

		$fields[] = array(
			'title' => 'Ajustes globales',
			'type'  => 'title',
			'id'    => 'akibara_eda_global_section',
		);
		$fields[] = array(
			'title'   => 'Saltar fines de semana',
			'desc'    => 'Excluir sábados y domingos al sumar días al lead time.',
			'id'      => 'akibara_eda_business_days_only',
			'type'    => 'checkbox',
			'default' => 'yes',
		);
		$fields[] = array(
			'title'    => 'Tomos «a pedido» sin promesa de fecha, para Google',
			'desc'     => 'Google Shopping exige una fecha para un tomo «a pedido» o en preventa. Un tomo con «Sin promesa de fecha» o una preventa «sin fecha confirmada» no la tiene: aquí decides qué le dice el feed. «Agotado» lo saca de Shopping sin prometer nada (recomendado); «Fecha del próximo pedido» lo publica con la fecha que tendría un «a pedido» normal; «Dejar con error» es el estado anterior (Merchant Center lo rechaza).',
			'desc_tip' => true,
			'id'       => Settings::GOOGLE_SIN_PROMESA_OPTION,
			'type'     => 'select',
			'options'  => array(
				'out_of_stock' => 'Agotado para Google (recomendado)',
				'next_cycle'   => 'Fecha del próximo pedido, como un «a pedido» normal',
				'leave'        => 'Dejar con error en Merchant Center',
			),
			'default'  => 'out_of_stock',
			// Sólo la lee el job de sincronización de GLA (Action Scheduler),
			// nunca el front: no tiene por qué viajar en `alloptions`.
			'autoload' => false,
		);
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'akibara_eda_global_section',
		);

		return $fields;
	}

	/**
	 * Construye los fields del bloque "Países y días de tránsito".
	 *
	 * Itera todos los términos del taxonomy `pa_pais` (incluyendo los que no
	 * tienen productos, para que owner los pueda configurar antes de empezar
	 * a cargar productos). Cada término produce un field `akb_pais_range_pair`
	 * que renderea min/max con valores leídos de term-meta.
	 *
	 * Si algún término no tiene term-meta válido, agrega al final un notice
	 * warning listando esos países — pre-empts el caso de "agregué México pero
	 * olvidé llenar los días".
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_pais_fields(): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array(
				array(
					'type'    => 'akb_notice',
					'id'      => 'akibara_eda_no_taxonomy_runtime',
					'kind'    => 'warning',
					'message' => 'Runtime WP no expone <code>get_terms()</code>. Carga el plugin desde un contexto normal.',
				),
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'pa_pais',
				'hide_empty' => false,
			)
		);

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) {
			$terms = array();
		}
		if ( ! is_array( $terms ) ) {
			$terms = array();
		}

		if ( empty( $terms ) ) {
			return array(
				array(
					'type'    => 'akb_notice',
					'id'      => 'akibara_eda_no_pais_terms',
					'kind'    => 'warning',
					'message' => 'El taxonomy <code>pa_pais</code> existe pero no tiene términos. Crea al menos uno en <a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=pa_pais&post_type=product' ) ) . '">Productos → Atributos → País</a> antes de continuar.',
				),
			);
		}

		usort(
			$terms,
			static fn( $a, $b ): int => strcmp( (string) $a->name, (string) $b->name )
		);

		$fields    = array();
		$unmapped  = array();

		foreach ( $terms as $term ) {
			if ( ! isset( $term->term_id, $term->slug, $term->name ) ) {
				continue;
			}

			$term_id      = (int) $term->term_id;
			$slug         = (string) $term->slug;
			$name         = (string) $term->name;
			$product_cnt  = isset( $term->count ) ? (int) $term->count : 0;
			$min          = (int) get_term_meta( $term_id, Settings::TERM_META_MIN_DAYS, true );
			$max          = (int) get_term_meta( $term_id, Settings::TERM_META_MAX_DAYS, true );
			$is_valid     = $min >= 1 && $max >= $min && $max <= 365;

			if ( ! $is_valid ) {
				$unmapped[] = $name;
			}

			$fields[] = array(
				'type'          => 'akb_pais_range_pair',
				'id'            => 'akibara_eda_pais_pair_' . $term_id,
				'title'         => $name,
				'desc'          => sprintf(
					'%d %s.',
					$product_cnt,
					1 === $product_cnt ? 'producto' : 'productos'
				),
				'desc_tip'      => true,
				'term_id'       => $term_id,
				'slug'          => $slug,
				'min_default'   => Settings::DEFAULT_LEAD_TIMES[ $slug ]['min'] ?? null,
				'max_default'   => Settings::DEFAULT_LEAD_TIMES[ $slug ]['max'] ?? null,
				'current_min'   => $min,
				'current_max'   => $max,
				'is_valid'      => $is_valid,
				'product_count' => $product_cnt,
			);
		}

		if ( ! empty( $unmapped ) ) {
			$fields[] = array(
				'type'    => 'akb_notice',
				'id'      => 'akibara_eda_unmapped_pais_warning',
				'kind'    => 'warning',
				'message' => sprintf(
					'Hay %d %s sin días configurados: <strong>%s</strong>. Sus productos en preventa no mostrarán fecha estimada hasta que llenes los valores arriba.',
					count( $unmapped ),
					1 === count( $unmapped ) ? 'país' : 'países',
					esc_html( implode( ', ', $unmapped ) )
				),
			);
		}

		return $fields;
	}

	/**
	 * Renderea un par min/max side-by-side para 1 término `pa_pais`.
	 *
	 * Esperado en context `<table class="form-table">` (entre title y sectionend).
	 * El name de los inputs codifica el term_id para que `save_pais_range_pair`
	 * sepa a qué término escribir term-meta.
	 *
	 * Layout: `Argentina   [ 12 ] – [ 15 ] días   (1.118 productos)`
	 *
	 * @param array<string, mixed> $value Field config con keys term_id, slug, title,
	 *                                    desc, current_min, current_max, min_default,
	 *                                    max_default, is_valid, product_count.
	 * @return void
	 */
	public function render_pais_range_pair( array $value ): void {
		$term_id     = isset( $value['term_id'] ) ? (int) $value['term_id'] : 0;
		$title       = isset( $value['title'] ) ? (string) $value['title'] : '';
		$desc        = isset( $value['desc'] ) ? (string) $value['desc'] : '';
		$current_min = isset( $value['current_min'] ) ? (int) $value['current_min'] : 0;
		$current_max = isset( $value['current_max'] ) ? (int) $value['current_max'] : 0;
		$min_default = isset( $value['min_default'] ) ? (int) $value['min_default'] : 0;
		$max_default = isset( $value['max_default'] ) ? (int) $value['max_default'] : 0;
		$is_valid    = ! empty( $value['is_valid'] );
		$product_cnt = isset( $value['product_count'] ) ? (int) $value['product_count'] : 0;

		if ( 0 === $term_id ) {
			return;
		}

		$min_input_name = 'akibara_eda_pais_min_' . $term_id;
		$max_input_name = 'akibara_eda_pais_max_' . $term_id;

		// Valor mostrado: si term-meta tiene valor válido, usar ese; si no, usar
		// el default del slug si existe; si no, blank (caso país nuevo sin defaults).
		$min_display = $current_min > 0 ? (string) $current_min : ( $min_default > 0 ? (string) $min_default : '' );
		$max_display = $current_max > 0 ? (string) $current_max : ( $max_default > 0 ? (string) $max_default : '' );

		$row_class = $is_valid ? 'akb-eda-pais-row' : 'akb-eda-pais-row akb-eda-pais-row--unset';
		?>
		<tr valign="top" class="<?php echo esc_attr( $row_class ); ?>">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $min_input_name ); ?>">
					<?php echo esc_html( $title ); ?>
					<?php if ( ! $is_valid ) : ?>
						<span style="color:#856404; font-weight:normal; font-size:11px;">⚠ sin configurar</span>
					<?php endif; ?>
				</label>
			</th>
			<td class="forminp forminp-akb-pais-range-pair">
				<input
					type="number"
					id="<?php echo esc_attr( $min_input_name ); ?>"
					name="<?php echo esc_attr( $min_input_name ); ?>"
					value="<?php echo esc_attr( $min_display ); ?>"
					min="1"
					max="365"
					step="1"
					style="width:80px;"
				/>
				<span style="margin: 0 8px; color: #50575e;">–</span>
				<input
					type="number"
					id="<?php echo esc_attr( $max_input_name ); ?>"
					name="<?php echo esc_attr( $max_input_name ); ?>"
					value="<?php echo esc_attr( $max_display ); ?>"
					min="1"
					max="365"
					step="1"
					style="width:80px;"
				/>
				<span style="margin-left: 8px; color: #50575e;">días</span>
				<?php if ( '' !== $desc ) : ?>
					<p class="description" style="margin-top:4px;">
						<?php echo wp_kses_post( $desc ); ?>
					</p>
				<?php endif; ?>
				<?php if ( 0 === $product_cnt ) : ?>
					<p class="description" style="margin-top:4px; color:#666;">
						Aún no hay productos con este país asignado.
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persiste min/max validando cross-field. Si valida → update_term_meta; si
	 * no, conserva el valor anterior y empuja error al transient (consumido por
	 * AdminPanel::render_page).
	 *
	 * Reglas:
	 *   - 1 ≤ min ≤ 365
	 *   - max ≥ min
	 *   - max ≤ 365
	 *   - Vacío en ambos → borrar term-meta (caer al fallback de Settings).
	 *
	 * Llamado por WC vía `do_action('woocommerce_update_option_akb_pais_range_pair', $option)`.
	 * AdminPanel::handle_save() ya verificó nonce + capability.
	 *
	 * @param array<string, mixed> $value Field config (mismo array que get_settings).
	 * @return void
	 */
	public function save_pais_range_pair( array $value ): void {
		$term_id = isset( $value['term_id'] ) ? (int) $value['term_id'] : 0;
		$title   = isset( $value['title'] ) ? (string) $value['title'] : 'País';
		if ( 0 === $term_id ) {
			return;
		}

		$min_input_name = 'akibara_eda_pais_min_' . $term_id;
		$max_input_name = 'akibara_eda_pais_max_' . $term_id;

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- AdminPanel verifica nonce antes de delegar a woocommerce_update_options; la entrada cruda se sanea en PaisTermFields::sanitize_post() (línea 390).
		$min_raw = isset( $_POST[ $min_input_name ] ) ? wp_unslash( $_POST[ $min_input_name ] ) : '';
		$max_raw = isset( $_POST[ $max_input_name ] ) ? wp_unslash( $_POST[ $max_input_name ] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Caso: ambos vacíos → borrar term-meta para caer al fallback de Settings.
		if ( '' === trim( (string) $min_raw ) && '' === trim( (string) $max_raw ) ) {
			delete_term_meta( $term_id, Settings::TERM_META_MIN_DAYS );
			delete_term_meta( $term_id, Settings::TERM_META_MAX_DAYS );
			return;
		}

		$sanitized = PaisTermFields::sanitize_post(
			array(
				'akb_min_days' => $min_raw,
				'akb_max_days' => $max_raw,
			)
		);

		if ( null !== $sanitized['min'] && null !== $sanitized['max'] ) {
			update_term_meta( $term_id, Settings::TERM_META_MIN_DAYS, $sanitized['min'] );
			update_term_meta( $term_id, Settings::TERM_META_MAX_DAYS, $sanitized['max'] );
			return;
		}

		// Inputs presentes pero inválidos → revertimos (no escribimos), push error.
		self::push_save_error(
			sprintf(
				'%s: valores inválidos (min=%s, max=%s). Debe cumplir 1 ≤ min ≤ max ≤ 365. No se guardó.',
				$title,
				is_numeric( $min_raw ) ? (string) (int) $min_raw : '—',
				is_numeric( $max_raw ) ? (string) (int) $max_raw : '—'
			)
		);
	}

	/**
	 * Agrega un mensaje al transient leído por AdminPanel::render_page().
	 *
	 * Append-only: si ya existen errores, los preserva. TTL corto (60s) — solo
	 * debe sobrevivir el redirect post-save.
	 *
	 * @param string $error Mensaje human-readable.
	 * @return void
	 */
	private static function push_save_error( string $error ): void {
		$key     = self::SAVE_ERRORS_TRANSIENT_PREFIX . get_current_user_id();
		$current = get_transient( $key );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$current[] = $error;
		set_transient( $key, $current, 60 );
	}

	/**
	 * Renderiza un bloque `notice` WP fuera del context de form-table.
	 *
	 * Debe colocarse en el array `$fields` ENTRE un `sectionend` y el siguiente
	 * `title` (o al final del array). Si se coloca dentro de una table abierta,
	 * el `<div>` resultante invalida el markup.
	 *
	 * @param array<string, mixed> $value Field config: kind (info|warning|error|success),
	 *                                    message (HTML permitido vía wp_kses_post).
	 * @return void
	 */
	public function render_notice( array $value ): void {
		$kind    = isset( $value['kind'] ) ? (string) $value['kind'] : 'info';
		$message = isset( $value['message'] ) ? (string) $value['message'] : '';

		if ( '' === $message ) {
			return;
		}

		if ( ! in_array( $kind, array( 'info', 'warning', 'error', 'success' ), true ) ) {
			$kind = 'info';
		}
		?>
		<div class="notice notice-<?php echo esc_attr( $kind ); ?> inline" style="margin: 16px 0;">
			<p><?php echo wp_kses_post( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Accessor del calculator. Usado por BannerRenderer y OrderPreventa.
	 *
	 * @return DateCalculator
	 */
	public function calculator(): DateCalculator {
		return $this->calculator;
	}

	/**
	 * Accessor for the canonical ETA presentation service.
	 *
	 * @return BannerRenderer
	 *
	 * @since 1.95.0
	 */
	public function renderer(): BannerRenderer {
		return $this->banner;
	}

	/**
	 * Accessor de settings. Usado por integraciones externas.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}
}
