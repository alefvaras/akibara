<?php
/**
 * TaxonomyDiscount — descuento porcentual por cualquier taxonomía de producto.
 *
 * UI single-page (1.17.0) reemplaza sub-tabs horizontales:
 *   1. Card de estado (master switch + summary de config actual).
 *   2. Card "Reglas activas" listando TODOS los términos con %>0 de todas
 *      las taxonomías en una vista unificada.
 *   3. Accordion expandible por taxonomía para edición masiva inline.
 *
 * Estructura del form (rendered por `woocommerce_admin_fields`):
 *   - Accordion "Configuración general" (collapsed)
 *   - Accordion por cada taxonomía soportada (collapsed por default;
 *     se expande si tiene términos con descuento)
 *
 * El header card (status + reglas activas) se renderea via header_fn del
 * AdminPanel — encima del form, no dentro.
 *
 * Para agregar una taxonomía custom sin tocar código:
 *   add_filter( 'akibara_taxonomy_discount_taxonomies', function( $tax) {
 *       $tax['mi_taxonomia_custom'] = 'Mi label';
 *       return $tax;
 *   });
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\LoopEnhancer\SaleDiscount;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\DiscountCeiling;
use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.17.0
 */
final class TaxonomyDiscount {

	/**
	 * Conteo del catálogo por estado, memoizado por PETICIÓN.
	 *
	 * Propiedad de clase y no `static` dentro del método para que las pruebas
	 * puedan devolverla a null: un memo que sobrevive entre pruebas hace que el
	 * resultado dependa del orden.
	 *
	 * @var array{instock:int,onbackorder:int,outofstock:int,total:int}|null
	 */
	private static ?array $stock_counts_memo = null;

	public const PANEL_TAB_ID = 'taxonomy_discount';

	/**
	 * Prefijo del rastro post-guardado. Es por usuario porque el aviso le
	 * pertenece a quien apretó «Guardar ajustes», no al siguiente que entre.
	 */
	private const SAVE_TRACE_TRANSIENT = 'akibara_taxonomy_discount_save_trace_';

	private Settings $settings;
	private DiscountResolver $resolver;
	private PriceFilters $price_filters;
	private CouponInteraction $coupon_interaction;
	private LscPurger $lsc_purger;
	private AdminFieldType $admin_field_type;

	/**
	 * Términos por taxonomía, memoizados por request.
	 *
	 * @var array<string, array<int, object>>
	 */
	private array $terms_cache = array();

	/**
	 * Reglas activas cross-taxonomy, memoizadas por request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $active_rules_cache = null;

	/** ¿Esta petición guardó la pestaña de descuentos? */
	private bool $save_seen = false;

	/**
	 * Términos cuyo porcentaje CAMBIÓ de verdad en esta petición (las claves son
	 * term_id). `update_term_meta` no dispara `updated_term_meta` cuando el valor
	 * es idéntico, así que este conjunto sólo crece con cambios reales.
	 *
	 * @var array<int, true>
	 */
	private array $changed_term_ids = array();

	/** ¿Cambió alguna opción de configuración general en esta petición? */
	private bool $changed_config = false;

	/** El rastro se persiste una sola vez, en shutdown. */
	private bool $trace_scheduled = false;

	public function __construct() {
		$this->settings            = new Settings();
		$this->resolver            = new DiscountResolver( $this->settings );
		$this->price_filters       = new PriceFilters( $this->resolver );
		$this->coupon_interaction  = new CouponInteraction( $this->settings, $this->resolver );
		$this->lsc_purger          = new LscPurger();
		$this->admin_field_type    = new AdminFieldType( $this->settings );
	}

	/**
	 * Resolver compartido del módulo. SeriesBundles lo consume vía wiring de
	 * Plugin.php para no duplicar instancia (y memoización) en el mismo request.
	 */
	public function resolver(): DiscountResolver {
		return $this->resolver;
	}

	/**
	 * Settings compartidos del módulo — la MISMA instancia que alimenta a los
	 * filtros de precio.
	 *
	 * La consume `SeriesFacts` para saber si hay una campaña vigente: mientras
	 * la haya, el precio que WooCommerce materializó en
	 * `wc_product_meta_lookup` (contexto `edit`, sin filtros) deja de ser el que
	 * ve el cliente, y ese bloque prefiere no publicar rango antes que publicar
	 * uno que la grilla de abajo contradice. Se expone la instancia en vez de un
	 * booleano para no duplicar acá la semántica de vigencia (switch + ventana)
	 * que ya vive en `Settings::is_enabled()`.
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	public function register(): void {
		$this->price_filters->register();
		$this->coupon_interaction->register();
		$this->lsc_purger->register();
		$this->admin_field_type->register();

		// Assets de edición cargados solo en la pestaña de descuentos.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Notice post-save mencionando la purga de LiteSpeed Cache.
		add_action( 'admin_notices', array( $this, 'render_post_save_notice' ) );

		// Detección de cambio REAL para ese aviso. `akibara_taxonomy_discount_pct_saved`
		// se dispara para las ~277 filas del formulario aunque no toques ninguna
		// (AdminFieldType::save_row lo emite por fila posteada), así que NO sirve
		// como señal de «cambió un precio»: sólo dice «hubo guardado de esta
		// pestaña». El cambio real lo dan los hooks de meta y de opción, que
		// WordPress sólo dispara cuando el valor guardado difiere del anterior.
		add_action( 'akibara_taxonomy_discount_pct_saved', array( $this, 'note_tab_saved' ) );
		add_action( 'added_term_meta', array( $this, 'note_term_pct_change' ), 10, 3 );
		add_action( 'updated_term_meta', array( $this, 'note_term_pct_change' ), 10, 3 );
		add_action( 'deleted_term_meta', array( $this, 'note_term_pct_change' ), 10, 3 );
		// Escucha tanto `updated_option` como `added_option`: la primera escritura
		// de una opción nueva en la base de datos dispara `added_option`, mientras que
		// las modificaciones posteriores disparan `updated_option`. Ambos hooks son
		// necesarios para registrar con precisión los cambios en `render_post_save_notice()`.
		add_action( 'updated_option', array( $this, 'note_config_change' ), 10, 1 );
		add_action( 'added_option', array( $this, 'note_config_change' ), 10, 1 );
		add_action( 'akibara_panel_form_hidden_inputs', array( $this, 'render_payload_guard' ) );
		add_filter( 'akibara_panel_save_errors', array( $this, 'validate_payload_guard' ), 10, 2 );

		// Las fechas de la ventana y el alcance («aplica a») se sanean estrictamente.
		// El campo de fecha se valida en servidor para evitar fechas imposibles o
		// fuera de formato que rompan la vigencia de la campaña o dejen el estado congelado.
		add_filter( 'akibara_panel_save_errors', array( $this, 'validate_window_dates' ), 10, 2 );
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . Settings::OPTION_APPLY_TO,
			array( $this, 'sanitize_apply_to' ),
			10,
			3
		);
		// La política ante rebajas manuales valida estrictamente que el valor
		// corresponda a uno de los dominios comerciales permitidos.
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . Settings::OPTION_SALE_POLICY,
			array( $this, 'sanitize_sale_policy' ),
			10,
			3
		);

		// El tope configurado entra como DEFAULT del filtro, en prioridad 5, para
		// que los tres lectores del tope editorial vean el mismo número sin que
		// ninguno tenga que aprender a leer la option: `Settings::max_pct()`
		// (storefront y el `max=` del campo por término), `AuthoritativeReader`
		// (camino estricto, que a propósito no toca APIs cacheadas) y la
		// publicación de akibara-search, que aplica este mismo filtro sobre su
		// snapshot. El tope se pasa como default del filtro para no romper el contrato
		// autoritativo.
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . DiscountCeiling::OPTION,
			array( $this, 'sanitize_max_pct' ),
			10,
			3
		);
		// Que guardar la pestaña no cree una fila de default intacto lo resuelve
		// el panel centralizado (`AdminPanel::skip_untouched_default()`).
		// El umbral del sello pertenece a `LoopEnhancer\SaleDiscount`, pero su campo se
		// renderiza en este acordeón por afinidad temática.
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . SaleDiscount::OPTION_MIN_PCT,
			array( $this, 'sanitize_sale_badge_min_pct' ),
			10,
			3
		);
	}

	/**
	 * Normaliza el umbral del sello rojo al guardarlo.
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	public function sanitize_sale_badge_min_pct( $value, array $option = array(), $raw_value = null ): int {
		unset( $option );

		return SaleDiscount::configured_min_pct( null !== $raw_value ? $raw_value : $value );
	}

	/**
	 * Normaliza el tope al guardarlo. Un valor ilegible cae al literal de
	 * siempre y uno fuera de rango se acota: el margen no depende de que el
	 * navegador haya respetado el `min`/`max` del input.
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	public function sanitize_max_pct( $value, array $option = array(), $raw_value = null ): int {
		unset( $option );

		return DiscountCeiling::sanitize( null !== $raw_value ? $raw_value : $value );
	}

	/**
	 * Rechaza (con frase, bloqueando el guardado) una ventana que no sea
	 * `AAAA-MM-DD` real o que esté invertida.
	 *
	 * Bloquear y no corregir en silencio, a propósito: una fecha es una
	 * decisión del dueño, y una fecha no válida corregida en silencio alteraría la vigencia esperada. Mismo canal y mismo tono que
	 * `OrderPreventa\Settings::validate_windows()`.
	 *
	 * @param array<int, string> $errors Errores previos de otros módulos.
	 * @param string             $tab    Pestaña que se está guardando.
	 * @return array<int, string>
	 */
	public function validate_window_dates( array $errors, string $tab ): array {
		if ( self::PANEL_TAB_ID !== $tab ) {
			return $errors;
		}

		$leer = static function ( string $option ): ?string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AdminPanel verifica el nonce antes de este filter.
			if ( ! isset( $_POST[ $option ] ) ) {
				return null;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ídem.
			return trim( sanitize_text_field( wp_unslash( (string) $_POST[ $option ] ) ) );
		};

		$desde = $leer( Settings::OPTION_FROM );
		$hasta = $leer( Settings::OPTION_TO );

		// Sub-pestañas que no postean estos campos no deben bloquear nada.
		if ( null === $desde && null === $hasta ) {
			return $errors;
		}

		$valida = static function ( ?string $valor ): bool {
			if ( null === $valor || '' === $valor ) {
				return true; // Vacío = sin límite, y los dos lados lo aceptan.
			}
			$fecha = \DateTimeImmutable::createFromFormat( '!Y-m-d', $valor );

			return $fecha instanceof \DateTimeImmutable && $fecha->format( 'Y-m-d' ) === $valor;
		};

		$desde_ok = $valida( $desde );
		$hasta_ok = $valida( $hasta );

		if ( ! $desde_ok ) {
			$errors[] = sprintf(
				'Descuento: «%s» no es una fecha real (formato AAAA-MM-DD). No se guardó nada de esta pestaña: con esa fecha el buscador y los filtros de la tienda se apagarían para todos los clientes.',
				(string) $desde
			);
		}
		if ( ! $hasta_ok ) {
			$errors[] = sprintf(
				'Descuento: «%s» no es una fecha real (formato AAAA-MM-DD). No se guardó nada de esta pestaña: con esa fecha el buscador se apagaría y la campaña no caducaría nunca.',
				(string) $hasta
			);
		}
		if ( $desde_ok && $hasta_ok && '' !== (string) $desde && '' !== (string) $hasta && $desde > $hasta ) {
			$errors[] = sprintf(
				'Descuento: la ventana está invertida (desde %s hasta %s). Así la campaña queda apagada sin decirlo.',
				(string) $desde,
				(string) $hasta
			);
		}

		return $errors;
	}

	/**
	 * Acota «aplica a» a su dominio. Un `radio` del navegador no puede producir
	 * otra cosa; un POST fabricado sí, y WooCommerce no lo filtra. Fuera de
	 * dominio cae al valor más conservador, que es el de siempre.
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	public function sanitize_apply_to( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );
		$candidato = trim( (string) ( null !== $raw_value ? $raw_value : $value ) );
		$dominio   = array( Settings::APPLY_INSTOCK_ONLY, Settings::APPLY_INSTOCK_AND_ONBACKORDER, Settings::APPLY_ALL );

		return in_array( $candidato, $dominio, true ) ? $candidato : Settings::APPLY_INSTOCK_ONLY;
	}

	/**
	 * Acota la política ante rebajas manuales a su dominio. Fuera de dominio
	 * cae a «respetar la rebaja manual», que es el default y el más
	 * conservador (no pisa un `_sale_price` puesto a mano).
	 *
	 * @param mixed               $value     Valor preprocesado por WooCommerce.
	 * @param array<string,mixed> $option    Definición del ajuste.
	 * @param mixed               $raw_value Valor crudo del formulario.
	 */
	public function sanitize_sale_policy( $value, array $option = array(), $raw_value = null ): string {
		unset( $option );
		$candidato = trim( (string) ( null !== $raw_value ? $raw_value : $value ) );

		return in_array( $candidato, Settings::sale_policies(), true ) ? $candidato : Settings::POLICY_SKIP_IF_ON_SALE;
	}

	/**
	 * Adds an end-of-form sentinel to detect PHP max_input_vars truncation.
	 *
	 * @param string $tab Active panel tab.
	 */
	public function render_payload_guard( string $tab ): void {
		if ( self::PANEL_TAB_ID !== $tab ) {
			return;
		}

		echo '<input type="hidden" name="akb_taxonomy_discount_payload_complete" value="1">';
	}

	/**
	 * Prevents partial saves when PHP truncated the large taxonomy form.
	 *
	 * @param array<int, string> $errors Existing validation errors.
	 * @param string             $tab    Active panel tab.
	 * @return array<int, string>
	 */
	public function validate_payload_guard( array $errors, string $tab ): array {
		if ( self::PANEL_TAB_ID !== $tab ) {
			return $errors;
		}

		// El nonce lo verifica AdminPanel antes de este filtro; aquí sólo se mira
		// si el centinela del final del formulario llegó, para detectar que PHP
		// truncó el envío. Se desliza y se sanea igual: lo que se compara tiene
		// que ser exactamente lo que llegó, no una versión escapada de ello.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado por AdminPanel antes de este filtro.
		$centinela = isset( $_POST['akb_taxonomy_discount_payload_complete'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ídem.
			? sanitize_text_field( wp_unslash( $_POST['akb_taxonomy_discount_payload_complete'] ) )
			: '';

		if ( '1' !== $centinela ) {
			$errors[] = 'No se guardó ningún descuento porque el servidor truncó el formulario. Reduce la cantidad visible o aumenta max_input_vars antes de intentarlo nuevamente.';
		}

		return $errors;
	}

	/**
	 * Settings fields para AdminPanel. Devuelve TODOS los fields en una sola
	 * vista — General + 1 accordion por taxonomía soportada con términos.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		$fields = $this->get_general_accordion_fields();

		$taxonomies = $this->settings->get_supported_taxonomies();
		foreach ( $taxonomies as $slug => $label ) {
			$total = $this->count_total_terms( $slug );
			if ( 0 === $total ) {
				continue; // Esconder taxonomías vacías.
			}
			$fields = array_merge( $fields, $this->get_taxonomy_accordion_fields( $slug, (string) $label ) );
		}

		return $fields;
	}

	/**
	 * Header del panel: renderea card de estado + card de reglas activas +
	 * estilos CSS. AdminPanel llama esto antes del form.
	 *
	 * @return void
	 */
	public function render_subtab_nav(): void {
		$this->render_status_card();
		$this->render_active_rules_card();
		$this->render_explore_intro();
	}

	/**
	 * Carga la UI de edición solo en Akibara > Descuentos.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_akibara-panel' !== $hook_suffix || self::PANEL_TAB_ID !== $this->get_current_admin_tab() ) {
			return;
		}

		wp_enqueue_style(
			'akibara-taxonomy-discount-admin',
			AKIBARA_BUSINESS_URL . 'assets/css/taxonomy-discount-admin.css',
			array( 'akibara-admin-panel' ),
			PluginAssetVersion::for_path( 'assets/css/taxonomy-discount-admin.css' )
		);

		wp_enqueue_script(
			'akibara-taxonomy-discount-admin',
			AKIBARA_BUSINESS_URL . 'assets/js/taxonomy-discount-admin.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/taxonomy-discount-admin.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	private function render_status_card(): void {
		// TODO lo de esta tarjeta sale de la BASE, en una sola lectura.
		//
		// Es la pantalla que le confirma al dueño que su cambio entró, y
		// `get_option()` puede devolver el valor anterior justo después de
		// guardar: en producción lee un blob compartido que la escritura no
		// siempre ha refrescado todavía. Cuando eso pasaba, la tarjeta decía que
		// Para garantizar consistencia atómica tras el guardado, los seis valores
		// de configuración (fechas, estado, política de rebajas, alcance y cupones)
		// se obtienen desde la misma lectura cruda de base de datos, evitando desajustes
		// provocados por el caché en memoria de opciones.
		$ventana       = $this->settings->window_state_from_database();
		$from          = $ventana['from'];
		$to            = $ventana['to'];
		$master_on     = 'yes' === $ventana['enabled'];
		$is_enabled    = $master_on && Settings::window_covers( $from, $to, (string) current_time( 'Y-m-d' ) );
		$sale_policy   = $ventana['sale_policy'];
		$apply_to      = $ventana['apply_to'];
		$stacks        = $ventana['stacks_coupons'];
		$total_rules   = $this->count_total_active_rules();

		$vigencia = ( '' === $from && '' === $to )
			? 'indefinida'
			: sprintf( '%s → %s', '' === $from ? '∞' : $from, '' === $to ? '∞' : $to );

		$policy_label = $this->humanize_sale_policy( $sale_policy );
		$scope_label  = $this->humanize_apply_to( $apply_to );

		$status_text = $master_on
			? ( $is_enabled ? 'Activo' : 'Activo pero fuera de vigencia' )
			: 'Apagado';
		$dot_class   = $is_enabled ? 'akb-status__dot--on' : 'akb-status__dot--off';

		// El rastro del apagado automático se muestra mientras el interruptor
		// siga apagado: si el dueño ya volvió a encender, es historia.
		$auto_off = $master_on ? null : get_transient( CampaignExpiryCron::NOTICE_TRANSIENT );
		$auto_off = is_array( $auto_off ) && '' !== (string) ( $auto_off['to'] ?? '' ) ? $auto_off : null;
		?>
		<section class="akb-discount-card" aria-labelledby="akb-status-heading">
			<h2 id="akb-status-heading" class="akb-discount-card__heading">Estado</h2>
			<div class="akb-status">
				<span class="akb-status__badge">
					<span class="akb-status__dot <?php echo esc_attr( $dot_class ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $status_text ); ?>
				</span>
				<div class="akb-status__meta">
					<span><strong>Vigencia:</strong> <?php echo esc_html( $vigencia ); ?></span>
					<span><strong>Reglas activas:</strong> <?php echo (int) $total_rules; ?></span>
				</div>
				<?php if ( null !== $auto_off ) : ?>
					<p class="akb-status__auto-off" style="margin:8px 0 0;color:#50575e;">Se apagó sola el <?php echo esc_html( (string) $auto_off['off_at'] ); ?>: su vigencia terminó el <?php echo esc_html( (string) $auto_off['to'] ); ?>. Para una campaña nueva, pon las fechas y vuelve a encenderla.</p>
				<?php endif; ?>
					<div class="akb-status__meta">
						<span><strong>Rebajas manuales:</strong> <?php echo esc_html( $policy_label ); ?></span>
						<span><strong>Productos incluidos:</strong> <?php echo esc_html( $scope_label ); ?></span>
						<span><strong>Acumulable con cupones:</strong> <?php echo $stacks ? 'sí' : 'no'; ?></span>
				</div>
				<div class="akb-status__cta">
					<a href="#akb-accordion-general" class="button">Cambiar configuración general</a>
				</div>
			</div>
		</section>
		<?php
	}

	private function render_active_rules_card(): void {
		$rules = $this->collect_active_rules();
		?>
		<section class="akb-discount-card" aria-labelledby="akb-active-rules-heading">
			<h2 id="akb-active-rules-heading" class="akb-discount-card__heading">Reglas activas</h2>
			<?php if ( empty( $rules ) ) : ?>
					<p class="akb-empty" style="margin:0;color:#50575e;font-style:italic;">
						Aún no hay reglas. Abre una taxonomía y asigna un porcentaje mayor que 0 al término que quieras promocionar.
					</p>
				<?php else : ?>
					<table class="akb-active-rules" aria-label="Lista de términos con descuento activo">
					<thead>
						<tr>
							<th scope="col">Término</th>
							<th scope="col">Taxonomía</th>
							<th scope="col" style="text-align:right;">Descuento</th>
							<th scope="col" style="text-align:right;">Productos</th>
							<th scope="col"><span class="screen-reader-text">Acciones</span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rules as $rule ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $rule['term_name'] ); ?></strong></td>
								<td><?php echo esc_html( $rule['taxonomy_label'] ); ?></td>
								<td class="akb-pct-cell" style="text-align:right;"><?php echo (int) $rule['pct']; ?>%</td>
								<td style="text-align:right;"><?php echo (int) $rule['product_count']; ?></td>
								<td style="text-align:right;">
									<a href="#akb-accordion-<?php echo esc_attr( $rule['taxonomy'] ); ?>" class="button-link">Editar</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_explore_intro(): void {
		?>
		<h2 class="akb-explore-intro">Explorar y editar por taxonomía</h2>
		<?php
	}

	/**
	 * Recolecta TODOS los términos con descuento activo cross-taxonomy.
	 *
	 * @return array<int, array{term_id: int, term_name: string, taxonomy: string, taxonomy_label: string, pct: int, product_count: int}>
	 */
	private function collect_active_rules(): array {
		if ( null !== $this->active_rules_cache ) {
			return $this->active_rules_cache;
		}

		$rules      = array();
		$taxonomies = $this->settings->get_supported_taxonomies();

		foreach ( $taxonomies as $slug => $label ) {
			foreach ( $this->terms_for( $slug ) as $term ) {
				$pct = $this->settings->get_term_pct( (int) $term->term_id );
				if ( $pct <= 0 ) {
					continue;
				}
				$rules[] = array(
					'term_id'        => (int) $term->term_id,
					'term_name'      => (string) ( $term->name ?? '' ),
					'taxonomy'       => $slug,
					'taxonomy_label' => (string) $label,
					'pct'            => $pct,
					'product_count'  => isset( $term->count ) ? (int) $term->count : 0,
				);
			}
		}

		// Sort: highest pct first, then by product_count desc.
		usort( $rules, static function ( $a, $b ): int {
			if ( $a['pct'] !== $b['pct'] ) {
				return $b['pct'] - $a['pct'];
			}
			return $b['product_count'] - $a['product_count'];
		} );

		$this->active_rules_cache = $rules;

		return $rules;
	}

	/**
	 * Tira los memos de esta petición.
	 *
	 * Los memos existen para no recalcular las reglas en cada producto del
	 * catálogo, y eso vale mientras las reglas no cambien. En la petición que
	 * GUARDA un descuento sí cambian: sin este olvido, todo lo que se lea después
	 * del guardado —dentro de la misma petición— seguiría viendo el porcentaje
	 * anterior, y el síntoma sería el peor posible («guardé el descuento y no se
	 * aplicó») porque desaparece al recargar.
	 *
	 * Se olvidan los DOS: `terms_for()` alimenta a `collect_active_rules()`, así
	 * que dejar vivo el de términos reconstruiría las reglas con la lista vieja.
	 *
	 * @return void
	 */
	private function forget_rules(): void {
		$this->active_rules_cache = null;
		$this->terms_cache        = array();
	}

	/**
	 * Términos de una taxonomía, solicitados una sola vez por petición.
	 *
	 * Memoiza la consulta de términos para evitar llamadas redundantes de base de datos
	 * entre el conteo total, los términos con descuento y la construcción de campos.
	 * Preserva el orden alfabético para estabilidad del formulario.
	 *
	 * @param string $taxonomy Slug.
	 * @return array<int, object>
	 */
	private function terms_for( string $taxonomy ): array {
		if ( isset( $this->terms_cache[ $taxonomy ] ) ) {
			return $this->terms_cache[ $taxonomy ];
		}

		$terms = array();

		if ( function_exists( 'get_terms' ) && taxonomy_exists( $taxonomy ) ) {
			$found = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			) );

			if ( ! is_wp_error( $found ) && is_array( $found ) ) {
				$terms = array_values( array_filter(
					$found,
					static fn( $term ): bool => is_object( $term ) && isset( $term->term_id )
				) );
				usort(
					$terms,
					static fn( $a, $b ): int => strcmp( (string) ( $a->name ?? '' ), (string) ( $b->name ?? '' ) )
				);
			}
		}

		$this->terms_cache[ $taxonomy ] = $terms;

		return $terms;
	}

	private function count_total_active_rules(): int {
		return count( $this->collect_active_rules() );
	}

	private function humanize_sale_policy( string $policy ): string {
		switch ( $policy ) {
			case Settings::POLICY_SKIP_IF_ON_SALE: return 'respetar rebaja manual';
			case Settings::POLICY_GREATEST_WINS:   return 'precio más bajo gana';
			case Settings::POLICY_FROM_REGULAR:    return 'reemplazar rebaja manual';
			case Settings::POLICY_STACK_ON_SALE:   return 'encadenar';
			default:                               return $policy;
		}
	}

	private function humanize_apply_to( string $apply_to ): string {
		switch ( $apply_to ) {
			case Settings::APPLY_INSTOCK_ONLY:            return 'solo en stock';
			case Settings::APPLY_INSTOCK_AND_ONBACKORDER: return 'en stock + a pedido';
			case Settings::APPLY_ALL:                     return 'todos';
			default:                                       return $apply_to;
		}
	}

	/**
	 * Opciones del alcance comercial de la campaña, con el conteo de productos actual.
	 *
	 * Comunica con precisión el impacto en inventario mostrando la cantidad de productos
	 * por estado (disponibles, a pedido y catálogo completo).
	 *
	 * Los identificadores canónicos (`instock_only`, `instock_and_onbackorder` y `all`)
	 * forman parte del contrato editorial validado por `akibara-search`.
	 *
	 * @return array<string, string>
	 */
	private static function apply_to_labels(): array {
		$n = self::catalog_stock_counts();

		$sufijo = static function ( ?int $cuantos ): string {
			// Sin conteo fiable, la etiqueta va sin número.
			return null === $cuantos ? '' : ' (' . number_format_i18n( $cuantos ) . ')';
		};

		$instock = $n['instock'] ?? null;
		$pedido  = $n['onbackorder'] ?? null;
		$total   = $n['total'] ?? null;
		$suma    = ( null === $instock || null === $pedido ) ? null : $instock + $pedido;

		return array(
			Settings::APPLY_INSTOCK_ONLY            => 'Solo productos disponibles (en stock)' . $sufijo( $instock ),
			Settings::APPLY_INSTOCK_AND_ONBACKORDER => 'Disponibles + a pedido' . $sufijo( $suma ),
			Settings::APPLY_ALL                     => 'Todos, incluso agotados' . $sufijo( $total ),
		);
	}

	/**
	 * Cuántos productos publicados hay por estado de stock, o `null` por estado
	 * si la lectura falló.
	 *
	 * Una consulta agregada, memoizada por PETICIÓN (no por transient: ver el
	 * comentario de más abajo, que es la razón). Abrir el panel no puede costar
	 * un recorrido del catálogo, y el número tampoco necesita ser exacto entre
	 * cargas — se lee para decidir un alcance, no para facturar.
	 *
	 * @return array{instock: int|null, onbackorder: int|null, outofstock: int|null, total: int|null}
	 */
	private static function catalog_stock_counts(): array {
		$vacio = array(
			'instock'     => null,
			'onbackorder' => null,
			'outofstock'  => null,
			'total'       => null,
		);

		/*
		 * Memo por PETICIÓN, no transient.
		 *
		 * El panel se pinta una vez por carga y esto es pantalla de
		 * administración: una consulta agregada por carga no se nota. Un
		 * transient sí se notaba en otro sitio — declarar `get_transient` en los
		 * stubs compartidos puso en rojo dos pruebas de `LlmsManifest` que cuentan
		 * consultas por un camino que sólo existe cuando esa función NO está
		 * definida. Cachear aquí no valía mover el suelo de 2.750 pruebas.
		 */
		if ( is_array( self::$stock_counts_memo ) ) {
			return self::$stock_counts_memo;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $vacio;
		}

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- agregado del catálogo cacheado cinco minutos justo debajo; no hay API de WooCommerce que lo dé en una consulta.
		$filas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS estado, COUNT(*) AS n
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = %s
				GROUP BY pm.meta_value",
				'_stock_status',
				'product',
				'publish'
			),
			ARRAY_A
		);

		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ! is_array( $filas ) ) {
			return $vacio; // La lectura falló: no se inventa un número, y NO se memoiza.
		}

		$conteo = array(
			'instock'     => 0,
			'onbackorder' => 0,
			'outofstock'  => 0,
		);
		foreach ( $filas as $fila ) {
			$estado = (string) ( $fila['estado'] ?? '' );
			if ( array_key_exists( $estado, $conteo ) ) {
				$conteo[ $estado ] = (int) ( $fila['n'] ?? 0 );
			}
		}
		$conteo['total'] = array_sum( $conteo );

		// `onbackorder` agrupa dos estados comerciales: «a pedido» y «preventa».
		// El descuento editorial excluye las preventas con fecha de lanzamiento pendiente,
		// por lo que se desglosan para que el número del alcance refleje fielmente
		// los productos que efectivamente recibirán el descuento.
		$conteo['preventa']    = self::pending_launch_count();
		$conteo['onbackorder'] = null === $conteo['preventa']
			? $conteo['onbackorder']
			: max( 0, $conteo['onbackorder'] - $conteo['preventa'] );

		self::$stock_counts_memo = $conteo;

		return $conteo;
	}

	/**
	 * Cuántos reservables son PREVENTA de verdad: con lanzamiento pendiente.
	 *
	 * Mismo criterio que `Availability::pending_launch()` —tipo `tba`, o tipo
	 * `release_date` con la fecha todavía por llegar—, en una sola consulta.
	 * Hoy el catálogo no tiene ninguno («a pedido» y «preventa» son lo mismo en
	 * la práctica), y por eso este número es cero: el defecto estaba latente,
	 * esperando al primer lanzamiento con fecha.
	 *
	 * @return int|null Cuántos, o null si la lectura falló (no se inventa).
	 */
	private static function pending_launch_count(): ?int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return null;
		}

		$hoy = ( new \DateTimeImmutable( 'now', StoreTime::zone() ) )->format( 'Y-m-d' );

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- agregado del catálogo, memoizado por petición junto al conteo de estados.
		$cuantos = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} stock ON stock.post_id = p.ID AND stock.meta_key = %s AND stock.meta_value = %s
				INNER JOIN {$wpdb->postmeta} tipo ON tipo.post_id = p.ID AND tipo.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} fecha ON fecha.post_id = p.ID AND fecha.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = %s
				  AND ( tipo.meta_value = %s OR ( tipo.meta_value = %s AND fecha.meta_value >= %s ) )",
				'_stock_status',
				StockStatus::ON_BACKORDER,
				Availability::META_TYPE,
				Availability::META_RELEASE_DATE,
				'product',
				'publish',
				Availability::TYPE_TBA,
				Availability::TYPE_RELEASE_DATE,
				$hoy
			)
		);

		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || null === $cuantos ) {
			return null;
		}

		return (int) $cuantos;
	}

	/**
	 * Fields del accordion "Configuración general" (collapsed por default).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_general_accordion_fields(): array {
		return array(
			array(
				'type'    => 'akb_accordion_start',
				'id'      => 'akb-accordion-general',
				'title'   => 'Configuración general',
				'variant' => 'general',
				'open'    => false,
				// Ver la nota de `build_term_fields`: los envoltorios tampoco son
				// opciones, y sin `value` cada uno se cobraba su propio SELECT.
				'value'   => '',
			),
				array(
					'title'   => 'Activar descuentos por taxonomía',
					'desc'    => 'Al desactivarlo, ninguna regla se aplica aunque los porcentajes permanezcan guardados.',
				'id'      => Settings::OPTION_ENABLED,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'       => 'Vigencia desde (opcional)',
					'desc'        => 'Si dejas ambas fechas vacías, las reglas permanecen activas hasta que apagues esta sección.',
				'desc_tip'    => true,
				'id'          => Settings::OPTION_FROM,
				'type'        => 'date',
				'placeholder' => 'YYYY-MM-DD',
				'css'         => 'min-width:160px;',
			),
			array(
				// El nombre completo, no un «Hasta» suelto: esta etiqueta también
				// viaja al aviso de escritura rechazada, donde se lee fuera de la
				// fila y sin el campo de al lado que le daba sentido.
				'title'       => 'Vigencia hasta (opcional)',
				'id'          => Settings::OPTION_TO,
				'type'        => 'date',
				'placeholder' => 'YYYY-MM-DD',
				'css'         => 'min-width:160px;',
			),
			array(
				'title'   => 'Aplicar a productos según stock',
				'id'      => Settings::OPTION_APPLY_TO,
				'type'    => 'radio',
				'default' => Settings::APPLY_INSTOCK_ONLY,
				'options' => self::apply_to_labels(),
			),
			array(
				'title'   => 'Política para productos con precio rebajado manual',
				'id'      => Settings::OPTION_SALE_POLICY,
				'type'    => 'radio',
				'desc'    => '<strong>Ejemplo</strong>: producto con precio normal $10.000, rebaja manual a $8.000 y taxonomía Panini al 20%.<br>'
					. '— <em>No aplicar</em>: queda $8.000 (respeta tu rebaja manual).<br>'
					. '— <em>Precio más bajo</em>: queda $8.000 (la rebaja manual ya es mejor).<br>'
					. '— <em>Reemplazar rebaja</em>: queda $8.000 (20% sobre $10.000).<br>'
					. '— <em>Encadenar</em>: queda $6.400 (20% sobre $8.000).',
				'default' => Settings::POLICY_SKIP_IF_ON_SALE,
				'options' => array(
					Settings::POLICY_SKIP_IF_ON_SALE => 'No aplicar la regla si el producto ya tiene precio rebajado manual (recomendado — respeta tu rebaja)',
					Settings::POLICY_GREATEST_WINS   => 'Aplicar y dejar el precio más bajo entre los dos',
					Settings::POLICY_FROM_REGULAR    => 'Reemplazar la rebaja manual: calcular siempre sobre precio normal',
					Settings::POLICY_STACK_ON_SALE   => 'Encadenar: aplicar el % sobre el precio ya rebajado',
				),
			),
			array(
				'title'   => 'Acumulable con cupones',
				'desc'    => 'Si está desactivado, los cupones quedan inválidos cuando el carrito tiene productos con descuento por taxonomía activo.',
				'id'      => Settings::OPTION_STACKS_COUPONS,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'             => 'Descuento máximo de la tienda (%)',
				'desc'              => '<strong>Cuidado con el margen:</strong> este % se calcula sobre el precio normal y, sumado al envío gratis, puede dejarte vendiendo bajo costo. '
					. 'Es el mismo techo para el descuento por editorial/categoría y para «Arma tu pack»: ningún tramo ni ninguna regla puede pasarlo.',
				'id'                => DiscountCeiling::OPTION,
				'type'              => 'number',
				'default'           => (string) DiscountCeiling::DEFAULT_PCT,
				'css'               => 'width: 80px;',
				'custom_attributes' => array(
					'min'  => (string) DiscountCeiling::HARD_MIN,
					'max'  => (string) DiscountCeiling::HARD_MAX,
					'step' => '1',
				),
			),
			array(
				'title'             => 'El sello rojo «−NN%» aparece desde (%)',
				'desc'              => 'Umbral a partir del cual una rebaja se anuncia con el sello rojo en el catálogo y en la ficha. '
					. 'Con un número bajo se llena la tienda de sellos «−2%», que abaratan la marca en vez de vender; con 0 el sello sale con cualquier rebaja.',
				'id'                => SaleDiscount::OPTION_MIN_PCT,
				'type'              => 'number',
				'default'           => (string) SaleDiscount::DEFAULT_MIN_PCT,
				'css'               => 'width: 80px;',
				'custom_attributes' => array(
					'min'  => (string) SaleDiscount::MIN_PCT_FLOOR,
					'max'  => (string) SaleDiscount::MIN_PCT_CEILING,
					'step' => '1',
				),
			),
			array(
				'type'  => 'akb_accordion_end',
				'id'    => 'akb-accordion-general-end',
				'value' => '',
			),
		);
	}

	/**
	 * Fields del accordion de una taxonomía: summary + filter bar + term rows.
	 * Open por default si tiene al menos 1 término con descuento.
	 *
	 * @param string $taxonomy Slug.
	 * @param string $label    Label legible.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_taxonomy_accordion_fields( string $taxonomy, string $label ): array {
		$total      = $this->count_total_terms( $taxonomy );
		$discounted = $this->count_terms_with_discount( $taxonomy );
		$affected   = $this->count_affected_products( $taxonomy );

		$meta_parts = array( $total . ' términos' );
			if ( $discounted > 0 ) {
				$meta_parts[] = $discounted . ' con descuento';
				$meta_parts[] = $affected . ' asignaciones de productos';
		}
		$meta = implode( ' · ', $meta_parts );

		$fields = array(
			array(
				'type'         => 'akb_accordion_start',
				'id'           => 'akb-accordion-' . sanitize_html_class( $taxonomy ),
				'title'        => $label,
				'summary_meta' => $meta,
				'variant'      => 'taxonomy',
				'open'         => $discounted > 0,
				'value'        => '',
			),
			array(
				'type'         => 'akb_filter_bar',
				'id'           => 'akb_filter_bar_' . $taxonomy,
				'accordion_id' => $taxonomy,
				'value'        => '',
			),
		);

		$fields = array_merge( $fields, $this->build_term_fields( $taxonomy ) );

		$fields[] = array(
			'type'  => 'akb_accordion_end',
			'id'    => 'akb-accordion-' . sanitize_html_class( $taxonomy ) . '-end',
			'value' => '',
		);

		return $fields;
	}

	/**
	 * Term rows. Auto-incluye cualquier término nuevo creado por el owner.
	 *
	 * @param string $taxonomy Slug.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_term_fields( string $taxonomy ): array {
		$fields = array();
		foreach ( $this->terms_for( $taxonomy ) as $term ) {
			if ( ! isset( $term->name ) ) {
				continue;
			}
			$term_id  = (int) $term->term_id;
			$pct      = $this->settings->get_term_pct( $term_id );
			$fields[] = array(
				'type'          => 'akb_taxonomy_pct_row',
				'id'            => 'akibara_taxonomy_discount_term_' . $term_id,
				'title'         => (string) $term->name,
				'term_id'       => $term_id,
				'current_pct'   => $pct,
				'product_count' => isset( $term->count ) ? (int) $term->count : 0,
				// El `id` de estas filas no es una opción de wp_options, sino la clave de term-meta.
				// Declarar `value` explícito previene que WC_Admin_Settings::output_fields() intente
				// resolver get_option(id) individualmente, optimizando las consultas del render.
				'value'         => $pct > 0 ? (string) $pct : '',
			);
		}

		return $fields;
	}

	/**
	 * Términos de la taxonomía. Cuenta sobre la MISMA lista que se renderiza:
	 * `wp_count_terms` contaba filas de la base que un filtro de `get_terms`
	 * podía no devolver, y ahí el acordeón salía anunciando términos que no
	 * estaban dentro.
	 */
	private function count_total_terms( string $taxonomy ): int {
		return count( $this->terms_for( $taxonomy ) );
	}

	private function count_terms_with_discount( string $taxonomy ): int {
		$count = 0;
		foreach ( $this->terms_for( $taxonomy ) as $term ) {
			if ( $this->settings->get_term_pct( (int) $term->term_id ) > 0 ) {
				$count++;
			}
		}
		return $count;
	}

	private function count_affected_products( string $taxonomy ): int {
		$total = 0;
		foreach ( $this->terms_for( $taxonomy ) as $term ) {
			if ( $this->settings->get_term_pct( (int) $term->term_id ) > 0 ) {
				$total += isset( $term->count ) ? (int) $term->count : 0;
			}
		}
		return $total;
	}

	/**
	 * Marca que ESTA petición guardó la pestaña de descuentos.
	 *
	 * Lo dispara `AdminFieldType::save_row` por cada fila posteada, así que llega
	 * sí o sí en cualquier guardado del tab — incluso en uno que sólo tocó la
	 * configuración general.
	 *
	 * @return void
	 */
	public function note_tab_saved(): void {
		$this->save_seen = true;
		$this->schedule_save_trace();
	}

	/**
	 * Cuenta los términos cuyo porcentaje cambió de verdad.
	 *
	 * Firma común de `added_term_meta` / `updated_term_meta` (meta_id, term_id,
	 * meta_key) y de `deleted_term_meta` (meta_ids[], term_id, meta_key): el
	 * primer argumento se ignora a propósito.
	 *
	 * @param mixed  $unused   Id(s) de la fila de meta.
	 * @param int    $term_id  Término afectado.
	 * @param string $meta_key Clave escrita.
	 * @return void
	 */
	public function note_term_pct_change( $unused, $term_id, $meta_key ): void {
		unset( $unused );

		if ( Settings::TERM_META_PCT !== (string) $meta_key ) {
			return;
		}

		$this->changed_term_ids[ (int) $term_id ] = true;
		$this->forget_rules();
		$this->schedule_save_trace();
	}

	/**
	 * Marca un cambio en la configuración general del módulo.
	 *
	 * El conjunto es el MISMO que escucha `LscPurger::register()` (el prefijo
	 * del módulo más el tope compartido): si una opción disparó purga, tiene que
	 * poder explicarla el aviso.
	 *
	 * @param string $option_name Opción recién actualizada.
	 * @return void
	 */
	public function note_config_change( $option_name ): void {
		$name = (string) $option_name;
		if ( ! str_starts_with( $name, 'akibara_taxonomy_discount_' ) && DiscountCeiling::OPTION !== $name ) {
			return;
		}

		$this->changed_config = true;
		$this->forget_rules();
		$this->schedule_save_trace();
	}

	/**
	 * Deja el rastro en shutdown, una sola vez.
	 *
	 * En shutdown y no aquí porque el guardado escribe ~277 filas: persistir en
	 * cada hook serían 277 escrituras de transient por guardado. `wp_safe_redirect()`
	 * + `exit` NO se salta el hook (WordPress lo registra con
	 * `register_shutdown_function`); es el mismo mecanismo del que ya depende
	 * `LscPurger` para purgar después de redirigir.
	 *
	 * @return void
	 */
	private function schedule_save_trace(): void {
		if ( $this->trace_scheduled ) {
			return;
		}
		$this->trace_scheduled = true;
		add_action( 'shutdown', array( $this, 'persist_save_trace' ), 90 );
	}

	/**
	 * Persiste el rastro del guardado para el aviso de la siguiente petición.
	 *
	 * @return void
	 */
	public function persist_save_trace(): void {
		if ( ! $this->save_seen ) {
			// Cambio de term-meta fuera del panel (WP-CLI, importador): no hay
			// aviso que explicar.
			return;
		}

		set_transient(
			self::SAVE_TRACE_TRANSIENT . get_current_user_id(),
			array(
				'terms'  => count( $this->changed_term_ids ),
				'config' => $this->changed_config,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Presenta el resultado de un guardado que cambió descuentos.
	 *
	 * Complementa el aviso central del panel con el cambio efectivo y la propagación
	 * al borde. Si no hay rastro disponible del guardado, mantiene un aviso genérico
	 * para no silenciar una escritura realizada por otra vía. No promete una demora
	 * fija que contradiga la purga ya programada por AdminPanel.
	 *
	 * @return void
	 */
	public function render_post_save_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- notice display only.
		if ( ! isset( $_GET['page'], $_GET['tab'], $_GET['saved'] ) ) {
			return;
		}
		if ( 'akibara-panel' !== sanitize_key( (string) $_GET['page'] ) ) {
			return;
		}
		if ( self::PANEL_TAB_ID !== sanitize_key( (string) $_GET['tab'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$trace_key = self::SAVE_TRACE_TRANSIENT . get_current_user_id();
		$trace     = get_transient( $trace_key );
		if ( is_array( $trace ) ) {
			delete_transient( $trace_key );
		}

		$changed_terms = is_array( $trace ) ? (int) ( $trace['terms'] ?? 0 ) : -1;
		$changed_conf  = is_array( $trace ) ? ! empty( $trace['config'] ) : false;

		if ( 0 === $changed_terms && ! $changed_conf ) {
			return; // Guardaste sin cambiar nada: no hay caché que explicar.
		}

		$what = 'Se guardó la configuración de descuentos.';
		if ( $changed_terms > 0 ) {
			$what = sprintf(
				'Cambiaste el porcentaje de %d %s.',
				$changed_terms,
				1 === $changed_terms ? 'término' : 'términos'
			);
			if ( $changed_conf ) {
				$what .= ' También cambió la configuración general.';
			}
		} elseif ( $changed_conf ) {
			$what = 'Cambiaste la configuración general de los descuentos.';
		}
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong>Precios.</strong>
				<?php echo esc_html( $what ); ?>
				La purga de caché ya se lanzó, pero el borde (Cloudflare) puede seguir
				sirviendo la copia vieja unos segundos.
				<a href="<?php echo esc_url( home_url( '/tienda/?_v=' . time() ) ); ?>" target="_blank" rel="noopener">
					Abrir <code>/tienda/</code> con cache-buster
				</a>
				para verificar.
			</p>
		</div>
		<?php
	}

	/**
	 * Tab activo del AdminPanel principal.
	 */
	private function get_current_admin_tab(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		return isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
