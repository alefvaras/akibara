<?php
/**
 * Declara el atributo adult del feed Merchant según la decisión editorial.
 *
 * La fuente de verdad es la termmeta _akb_adult_content de pa_serie, editable
 * en Productos → Atributos → Serie. La clasificación automática de un proveedor
 * no sustituye la decisión editorial de Akibara.
 *
 * La constante inicial es una semilla de migración: se copia una vez a termmeta
 * y sirve como respuesta mientras no se haya sembrado una instalación nueva.
 * Así no se pierde la declaración entre desplegar el código y ejecutar la siembra.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\ProductSchemaShopping;

use WP_Term;

defined( 'ABSPATH' ) || exit;

final class AdultContentFeed {

	/** Taxonomía de serie. Es la unidad editorial de la decisión, no el producto. */
	private const TAXONOMY = 'pa_serie';

	/**
	 * Capability de la taxonomía, no `manage_categories`.
	 *
	 * WooCommerce registra los atributos de producto con
	 * `'manage_terms' => 'manage_product_terms'`; usar otra dejaría este campo
	 * con un umbral distinto al del resto del mismo formulario
	 * ({@see \Akibara\Business\Modules\SeriesFacts\PublicationStatusFields}).
	 */
	private const CAPABILITY = 'manage_product_terms';

	/** Term meta: '1' = la serie se declara `adult` en el feed de Merchant. */
	public const META_KEY = '_akb_adult_content';

	private const FIELD = 'akb_adult_content';
	private const NONCE = 'akb_adult_content_nonce';

	/** Marca de que la semilla ya se copió a termmeta. */
	private const SEED_OPTION = 'akb_adult_series_seeded';

	/**
	 * Semilla de slugs para inicializar la declaración editorial de contenido adulto.
	 *
	 * La termmeta es autoritativa después de la siembra. La semilla sólo cubre la
	 * inicialización y la ventana anterior a que ésta se complete.
	 */
	private const ADULT_SERIES_SEED = array(
		'ten-count',
		'prison-school',
		'ayakashi-triangle',
		'mushoku-tensei',
		'made-in-abyss',
	);

	/** Cache por request de los slugs +18. `null` = todavía no se resolvió. */
	private ?array $slugs_cache = null;

	public function register(): void {
		add_filter( 'woocommerce_gla_product_attribute_values', array( $this, 'declare_adult_attribute' ), 10, 2 );

		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'render_add_field' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_field' ), 10, 2 );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'save_meta' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_meta' ) );

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'admin_column' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'render_admin_column' ), 10, 3 );

		// Siembra idempotente. En `akibara_business_upgraded` corre una vez por
		// bump de versión; el `admin_init` cubre la instalación cuyo bump ya pasó
		// antes de que este código existiera (mismo patrón que HomeHero\Migration).
		add_action( 'akibara_business_upgraded', array( $this, 'seed_from_constant' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'seed_from_constant' ), 5 );
		}
	}

	/**
	 * True si la serie está declarada +18.
	 */
	public static function is_adult_term( int $term_id ): bool {
		return '1' === (string) get_term_meta( $term_id, self::META_KEY, true );
	}

	/**
	 * Añade `adult: true` a los productos de las series +18.
	 *
	 * @param mixed $attributes Atributos extra que GLA mapeará al producto de Shopping.
	 * @param mixed $product    Producto WooCommerce en sincronización.
	 * @return mixed
	 *
	 * @since 1.106.1
	 */
	public function declare_adult_attribute( $attributes, $product ) {
		try {
			if ( ! is_array( $attributes ) || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
				return $attributes;
			}

			$slugs = $this->adult_series_slugs();
			if ( array() === $slugs ) {
				return $attributes;
			}

			// Variaciones heredan la serie del padre.
			$product_id = (int) $product->get_id();
			if ( method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
				$product_id = (int) $product->get_parent_id();
			}

			if ( has_term( $slugs, self::TAXONOMY, $product_id ) ) {
				$attributes['adult'] = true;
			}

			return $attributes;
		} catch ( \Throwable $throwable ) {
			return $attributes;
		}
	}

	/**
	 * Copia la semilla a termmeta una sola vez.
	 *
	 * Idempotente por opción: una vez sembrado, desmarcar las 5 desde el admin
	 * es una decisión del dueño y esta función no puede volver a imponerlas.
	 *
	 * @internal Callback de `akibara_business_upgraded` y de `admin_init`.
	 */
	public function seed_from_constant(): void {
		if ( '1' === (string) get_option( self::SEED_OPTION, '' ) ) {
			return;
		}

		// La taxonomía la registra WooCommerce con los atributos de producto: si
		// todavía no existe, sembrar escribiría meta de términos inexistentes y
		// dejaría la marca puesta. Se reintenta en el siguiente request.
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}

		$missing = array();
		foreach ( self::ADULT_SERIES_SEED as $slug ) {
			$term = get_term_by( 'slug', $slug, self::TAXONOMY );
			if ( $term instanceof WP_Term ) {
				update_term_meta( $term->term_id, self::META_KEY, '1' );
				continue;
			}
			$missing[] = $slug;
		}

		// La siembra se da por hecha aunque falte algún slug (un entorno sin ese
		// catálogo, un slug renombrado): reintentar en cada request de admin sería
		// pagar 5 `get_term_by()` para siempre. Pero no puede ser silencioso: en
		// producción los 5 existen, así que una ausencia significa que una serie
		// +18 se quedó SIN declarar y Merchant la va a rechazar.
		if ( array() !== $missing ) {
			\Akibara\Business\Utilities\Log::warn(
				'adult-content-feed',
				sprintf( 'Series +18 de la semilla sin término en pa_serie: %s. Márcalas a mano en Productos → Atributos → Serie.', implode( ', ', $missing ) )
			);
		}

		update_option( self::SEED_OPTION, '1', true );
		$this->slugs_cache = null;
	}

	/**
	 * Slugs de las series +18 vigentes.
	 *
	 * Antes de la siembra responde la constante: el feed no puede quedarse sin
	 * declarar `adult` durante la ventana entre el deploy y el primer request de
	 * admin, que es exactamente cuando Merchant vuelve a rechazar productos.
	 *
	 * @return list<string>
	 */
	private function adult_series_slugs(): array {
		if ( null !== $this->slugs_cache ) {
			return $this->slugs_cache;
		}

		if ( '1' !== (string) get_option( self::SEED_OPTION, '' ) ) {
			$this->slugs_cache = self::ADULT_SERIES_SEED;
			return $this->slugs_cache;
		}

		// Consultar termmeta con meta_query para preservar la selección completa.
		// Una lista incompleta desactivaría la declaración editorial adult en el feed.

		$slugs = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'slugs',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 115 términos y una sola vez por request de sincronización.
					array(
						'key'   => self::META_KEY,
						'value' => '1',
					),
				),
			)
		);

		$this->slugs_cache = is_array( $slugs )
			? array_values( array_filter( array_map( 'strval', $slugs ), static fn( string $s ): bool => '' !== $s ) )
			: array();

		return $this->slugs_cache;
	}

	/**
	 * Campo al crear el término.
	 *
	 * @internal Callback de `pa_serie_add_form_fields`.
	 */
	public function render_add_field(): void {
		wp_nonce_field( 'save_' . self::META_KEY, self::NONCE );
		?>
		<div class="form-field">
			<label for="<?php echo esc_attr( self::FIELD ); ?>">
				<input type="checkbox" id="<?php echo esc_attr( self::FIELD ); ?>" name="<?php echo esc_attr( self::FIELD ); ?>" value="1">
				Contenido +18 (Merchant)
			</label>
			<p><?php $this->render_help(); ?></p>
		</div>
		<?php
	}

	/**
	 * Campo al editar el término.
	 *
	 * @internal Callback de `pa_serie_edit_form_fields`.
	 *
	 * @param mixed  $term     Término editado.
	 * @param string $taxonomy Taxonomía recibida por WordPress.
	 */
	public function render_edit_field( $term, $taxonomy = '' ): void {
		if ( self::TAXONOMY !== (string) $taxonomy || ! is_object( $term ) || ! isset( $term->term_id ) ) {
			return;
		}

		$current = self::is_adult_term( (int) $term->term_id );
		wp_nonce_field( 'save_' . self::META_KEY, self::NONCE );
		?>
		<tr class="form-field">
			<th scope="row"><label for="<?php echo esc_attr( self::FIELD ); ?>">Contenido +18 (Merchant)</label></th>
			<td>
				<label for="<?php echo esc_attr( self::FIELD ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( self::FIELD ); ?>" name="<?php echo esc_attr( self::FIELD ); ?>" value="1" <?php checked( $current ); ?>>
					Declarar esta serie como adulta en el feed de Google Merchant
				</label>
				<p class="description"><?php $this->render_help(); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Guarda la meta.
	 *
	 * `edited_pa_serie` lo dispara CUALQUIER `wp_update_term()` —un import, un
	 * cron, otro plugin—, donde no hay formulario ni nonce: sin los tres guards
	 * un guardado ciego desmarcaría las series +18 y Merchant volvería a
	 * rechazarlas. Misma doctrina que `SeriesRelatedGuide::handle_save_term()`.
	 *
	 * @internal Callback de `created_pa_serie` y `edited_pa_serie`.
	 */
	public function save_meta( int $term_id ): void {
		$posted = $_POST[ self::NONCE ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- éste ES el nonce; se desescapa y sanea en la línea siguiente tras comprobar el tipo.
		$nonce  = is_string( $posted ) ? sanitize_text_field( wp_unslash( $posted ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'save_' . self::META_KEY )
			|| ! current_user_can( self::CAPABILITY )
			|| ! term_exists( $term_id, self::TAXONOMY ) ) {
			return;
		}

		$this->slugs_cache = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado arriba.
		$checked = isset( $_POST[ self::FIELD ] ) && '1' === (string) wp_unslash( $_POST[ self::FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se compara contra el literal '1'.
		if ( $checked ) {
			update_term_meta( $term_id, self::META_KEY, '1' );
			return;
		}

		delete_term_meta( $term_id, self::META_KEY );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function admin_column( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'name' === $key ) {
				$new['akb_adult'] = '+18';
			}
		}

		// Si la lista no trae la columna `name` (otro plugin la quitó), la columna
		// va igual al final: una lista sin la marca es una lista que miente.
		if ( ! isset( $new['akb_adult'] ) ) {
			$new['akb_adult'] = '+18';
		}

		return $new;
	}

	/**
	 * @param mixed $content Contenido previo de la celda.
	 * @param mixed $column  Columna que se está pintando.
	 * @param mixed $term_id Término de la fila.
	 */
	public function render_admin_column( $content, $column, $term_id ): string {
		if ( 'akb_adult' !== (string) $column ) {
			return (string) $content;
		}

		return self::is_adult_term( (int) $term_id )
			? '<span title="Declarada adulta en el feed de Merchant" style="color:#e60012;font-weight:700">+18</span>'
			: '<span style="color:#ccc">—</span>';
	}

	/** Ayuda del campo. Explica la consecuencia, que es lo que no se ve. */
	private function render_help(): void {
		?>
		Marca la serie como adulta en el feed de Google Merchant. Google la sigue mostrando,
		con restricciones de audiencia; <strong>no</strong> marcarla cuando su clasificador
		la detecta como adulta es lo que produce el rechazo «Contenido para adultos restringido».
		Si crees que Google se equivocó, apela el producto en Merchant y deja esto sin marcar.
		<?php
	}
}
