<?php
/**
 * Relaciona una serie de WooCommerce con una guía editorial publicada.
 *
 * El vínculo se administra desde la UI nativa del término `pa_serie`; el
 * frontend solo lo muestra en la URL canónica, limpia y no paginada del
 * archivo. No emite schema ni reemplaza el H1/hero que pertenece al theme.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\RelatedGuides;

use Akibara\Business\Modules\SiteSpeed\EdgePurge;
use Akibara\Business\Utilities\CatalogQueryGrammar;
use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

/**
 * Guía editorial relacionada por término de serie.
 *
 * @since 1.93.0
 */
final class SeriesRelatedGuide {

	private const TAXONOMY    = 'pa_serie';
	private const FIELD_NAME  = 'akb_related_guide_post_id';
	private const NONCE_NAME  = 'akb_related_guide_nonce';
	private const NONCE_ACTION = 'akb_save_related_guide';

	/** Term meta que almacena el ID del post relacionado. */
	public const META_KEY = '_akb_related_guide_post_id';

	/** @var array<int,int> Entradas cuyas series se purgarán al final del request. */
	private array $pending_guide_post_ids = array();

	/** Impide registrar más de una barrera tardía por request. */
	private bool $purge_scheduled = false;

	/**
	 * Registra la UI del término y el bloque del archivo.
	 */
	public function register(): void {
		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'handle_add_form_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'handle_edit_form_fields' ), 10, 2 );
		if ( is_admin() ) {
			// El vínculo sólo se veía entrando a editar el término uno por uno:
			// 119 series y ningún indicador en el listado. Los dos filtros sólo
			// existen en pantallas de admin, así que no se registran en front.
			add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'handle_admin_column' ) );
			add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'handle_render_admin_column' ), 10, 3 );
		}
		add_action( 'created_' . self::TAXONOMY, array( $this, 'handle_save_term' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'handle_save_term' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'handle_enqueue_assets' ) );
		// La guía se presenta bajo la rejilla, tras paginación10 y SeriesFacts20.
		// Orienta a quien recorrió los tomos sin desplazar la entrada a la compra.

		add_action( 'woocommerce_after_shop_loop', array( $this, 'handle_render_related_guide' ), 25 );
		add_action( 'post_updated', array( $this, 'handle_guide_post_updated' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'handle_guide_status_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'handle_guide_post_deleted' ), 10, 2 );
	}

	/**
	 * Detecta cambios de título o URL de una guía sin reaccionar a cada autosave.
	 *
	 * @param int|string $post_id     ID de la entrada.
	 * @param mixed      $post_after  Estado posterior.
	 * @param mixed      $post_before Estado anterior.
	 */
	public function handle_guide_post_updated( $post_id, $post_after, $post_before ): void {
		if (
			! is_object( $post_after )
			|| ! is_object( $post_before )
			|| 'post' !== (string) ( $post_after->post_type ?? '' )
		) {
			return;
		}

		if (
			(string) ( $post_after->post_title ?? '' ) === (string) ( $post_before->post_title ?? '' )
			&& (string) ( $post_after->post_name ?? '' ) === (string) ( $post_before->post_name ?? '' )
		) {
			return;
		}

		$this->queue_guide_post_purge( (int) $post_id );
	}

	/** Encola la aparición o retirada del bloque al cambiar publish. */
	public function handle_guide_status_transition( $new_status, $old_status, $post ): void {
		if (
			! is_object( $post )
			|| 'post' !== (string) ( $post->post_type ?? '' )
			|| (string) $new_status === (string) $old_status
		) {
			return;
		}

		$this->queue_guide_post_purge( (int) ( $post->ID ?? 0 ) );
	}

	/** Conserva la última purga cuando una guía se borra sin pasar por papelera. */
	public function handle_guide_post_deleted( $post_id, $post ): void {
		if ( ! is_object( $post ) || 'post' !== (string) ( $post->post_type ?? '' ) ) {
			return;
		}

		$this->queue_guide_post_purge( (int) $post_id );
	}

	/** Resuelve todas las dependencias en un lote y purga cada landing una vez. */
	public function flush_pending_guide_purges(): void {
		$post_ids                    = array_values( $this->pending_guide_post_ids );
		$this->pending_guide_post_ids = array();
		if ( array() === $post_ids ) {
			return;
		}

		$term_ids = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- consulta inversa única, coalescida y acotada a cambios editoriales.
					array(
						'key'     => self::META_KEY,
						'value'   => array_map( 'strval', $post_ids ),
						'compare' => 'IN',
					),
				),
			)
		);
		if ( ! is_array( $term_ids ) ) {
			return;
		}

		foreach ( array_unique( array_map( 'intval', $term_ids ) ) as $term_id ) {
			if ( $term_id > 0 ) {
				do_action( 'litespeed_purge', 'T.' . $term_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
			}
		}
	}

	/** Encola un post y registra una sola frontera posterior a todas sus mutaciones. */
	private function queue_guide_post_purge( int $post_id ): void {
		if ( $post_id < 1 ) {
			return;
		}

		$this->pending_guide_post_ids[ $post_id ] = $post_id;
		if ( $this->purge_scheduled ) {
			return;
		}

		add_action( 'shutdown', array( $this, 'flush_pending_guide_purges' ), PHP_INT_MAX );
		$this->purge_scheduled = true;
	}

	/**
	 * Columna «Guía» en el listado de series.
	 *
	 * @internal Callback de `manage_edit-pa_serie_columns`.
	 *
	 * @param mixed $columns Columnas recibidas.
	 * @return mixed
	 */
	public function handle_admin_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$columns['akb_related_guide'] = 'Guía';

		return $columns;
	}

	/**
	 * Contenido de la columna: qué serie tiene guía y cuál, sin abrir el término.
	 *
	 * Distingue tres estados a propósito. «Sin guía» es una decisión editorial
	 * legítima (hay guías temáticas —«¿Qué es el manga?»— que no cuelgan de
	 * ninguna serie), mientras que un vínculo cuyo destino ya no está publicado
	 * es un bloque que el front deja de pintar en silencio: `resolve_view_model()`
	 * devuelve null y la landing se queda sin guía sin avisar a nadie. Esa
	 * diferencia es justo lo que no se veía desde el listado.
	 *
	 * @internal Callback de `manage_pa_serie_custom_column`.
	 *
	 * @param mixed $content Contenido acumulado.
	 * @param mixed $column  Columna pedida.
	 * @param mixed $term_id Término de la fila.
	 * @return mixed
	 */
	public function handle_render_admin_column( $content, $column = '', $term_id = 0 ) {
		if ( 'akb_related_guide' !== (string) $column ) {
			return $content;
		}

		$post_id = (int) get_term_meta( (int) $term_id, self::META_KEY, true );
		if ( $post_id < 1 ) {
			return '—';
		}

		if ( ! $this->is_published_post( $post_id ) ) {
			// `<strong>` y no una clase propia: el listado de términos no carga
			// ninguna hoja del plugin, así que una clase inventada no pintaría nada.
			return '<strong>Vínculo roto (#' . esc_html( (string) $post_id ) . ')</strong>';
		}

		$title = trim( (string) get_the_title( $post_id ) );
		$edit  = get_edit_post_link( $post_id );

		if ( ! is_string( $edit ) || '' === $edit ) {
			return esc_html( $title );
		}

		return '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
	}

	/**
	 * Renderiza el selector en el formulario de creación.
	 *
	 * @internal
	 */
	public function handle_add_form_fields(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="form-field">
			<label for="<?php echo esc_attr( self::FIELD_NAME ); ?>">Guía relacionada</label>
			<?php $this->render_post_select( 0 ); ?>
			<p>Elige una entrada publicada para mostrarla antes del catálogo de esta serie.</p>
		</div>
		<?php
	}

	/**
	 * Renderiza el selector en el formulario de edición.
	 *
	 * @internal
	 *
	 * @param object $term     Término editado.
	 * @param string $taxonomy Taxonomía recibida por WordPress.
	 */
	public function handle_edit_form_fields( $term, string $taxonomy ): void {
		if ( self::TAXONOMY !== $taxonomy || ! isset( $term->term_id ) ) {
			return;
		}

		$selected = (int) get_term_meta( (int) $term->term_id, self::META_KEY, true );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="<?php echo esc_attr( self::FIELD_NAME ); ?>">Guía relacionada</label>
			</th>
			<td>
				<?php $this->render_post_select( $selected ); ?>
				<p class="description">Entrada publicada que se mostrará antes del catálogo de esta serie. La opción “Sin guía relacionada” retira el bloque.</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persiste el vínculo solo para términos, permisos y posts válidos.
	 *
	 * @internal
	 *
	 * @param int $term_id ID del término creado o editado.
	 */
	public function handle_save_term( int $term_id ): void {
		$nonce = $_POST[ self::NONCE_NAME ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida el tipo y luego se desescapa/sanea antes de verificar.
		if ( ! is_string( $nonce )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), self::NONCE_ACTION )
			|| ! current_user_can( 'manage_product_terms' )
			|| ! term_exists( $term_id, self::TAXONOMY ) ) {
			return;
		}

		if ( ! array_key_exists( self::FIELD_NAME, $_POST ) ) {
			return;
		}

		$raw_post_id = wp_unslash( $_POST[ self::FIELD_NAME ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el nonce y la capacidad ya se verificaron al entrar; el valor se comprueba con is_scalar() y se reduce a entero con absint() unas líneas abajo, saneado que el sniff no reconoce fuera de la misma línea.
		if ( ! is_scalar( $raw_post_id ) ) {
			return;
		}

		$antes   = absint( get_term_meta( $term_id, self::META_KEY, true ) );
		$post_id = absint( $raw_post_id );
		if ( 0 === $post_id ) {
			delete_term_meta( $term_id, self::META_KEY );
			$this->purge_guides( $antes, 0 );
			return;
		}

		if ( ! $this->is_published_post( $post_id ) ) {
			return;
		}

		update_term_meta( $term_id, self::META_KEY, $post_id );
		$this->purge_guides( $antes, $post_id );
	}

	/**
	 * Purga la guía que deja de estar ligada y la que pasa a estarlo.
	 *
	 * La entrada de una guía pinta la tarjeta de su serie (tema,
	 * inc/guide-series.php) y lleva la etiqueta de caché `T.<serie>`, así que
	 * sólo una guía que YA estaba ligada se enteraba de un cambio en la serie.
	 * Una recién ligada seguía sirviendo su copia sin tarjeta hasta el TTL de
	 * LiteSpeed, más 10 minutos del borde de Cloudflare; y la que se desligaba,
	 * con la tarjeta de una serie que ya no es la suya (D4, revisión T7-1).
	 *
	 * Sólo si el vínculo cambió: guardar la serie sin tocar la guía no purga.
	 *
	 * @param int $antes   Guía ligada antes de guardar (0 = ninguna).
	 * @param int $despues Guía ligada después (0 = ninguna).
	 */
	private function purge_guides( int $antes, int $despues ): void {
		if ( $antes === $despues ) {
			return;
		}

		foreach ( array( $antes, $despues ) as $guia ) {
			if ( $guia < 1 ) {
				continue;
			}
			do_action( 'litespeed_purge_post', $guia ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
			$url = get_permalink( $guia );
			if ( is_string( $url ) && '' !== $url ) {
				EdgePurge::anotar( $url );
			}
		}
	}

	/**
	 * Encola el estilo solo cuando el bloque realmente se mostrará.
	 *
	 * @internal
	 */
	public function handle_enqueue_assets(): void {
		if ( null === $this->resolve_view_model() ) {
			return;
		}

		wp_enqueue_style(
			'akibara-series-related-guide',
			AKIBARA_BUSINESS_URL . 'assets/css/series-related-guide.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/series-related-guide.css' )
		);
	}

	/**
	 * Imprime un bloque editorial accesible bajo la grilla, tras la ficha de datos.
	 *
	 * @internal
	 */
	public function handle_render_related_guide(): void {
		$guide = $this->resolve_view_model();
		if ( null === $guide ) {
			return;
		}

		$heading_id = 'akb-related-guide-title-' . $guide['post_id'];
		?>
		<aside class="akb-related-guide" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
			<div class="akb-related-guide__body">
				<p class="akb-related-guide__eyebrow">Guía de lectura</p>
				<h2 class="akb-related-guide__title" id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $guide['title'] ); ?></h2>
			</div>
			<a
				class="akb-related-guide__cta"
				href="<?php echo esc_url( $guide['url'] ); ?>"
				aria-label="<?php echo esc_attr( 'Leer la guía: ' . $guide['title'] ); ?>"
			>Leer la guía <span aria-hidden="true">→</span></a>
		</aside>
		<?php
	}

	/**
	 * Indica si el request es la página canónica limpia de una serie.
	 *
	 * «Limpia» significa SIN FACETAS, no «sin cadena de consulta». El guard
	 * comparaba `! empty( $_GET)`, así que cualquier `?utm_source=…`,
	 * `?gclid=…` o `?fbclid=…` escondía el bloque editorial en una landing que
	 * seguía siendo exactamente la misma colección — justo en el tráfico de
	 * campaña, que es el que más necesita el contexto. El criterio real es la
	 * gramática de facetas del catálogo, la misma que gobierna robots y el 404
	 * de colección vacía.
	 *
	 * @internal
	 */
	public function is_clean_series_archive(): bool {
		if ( is_admin() || ! is_tax( self::TAXONOMY ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo se leen los NOMBRES de los parámetros.
		if ( CatalogQueryGrammar::has_filter_parameter( $_GET ) ) {
			return false;
		}

		if ( (int) get_query_var( 'paged' ) > 1 ) {
			return false;
		}

		$term = get_queried_object();
		return is_object( $term )
			&& isset( $term->term_id, $term->taxonomy )
			&& self::TAXONOMY === (string) $term->taxonomy
			&& (int) $term->term_id > 0;
	}

	/**
	 * Renderiza las entradas publicadas ordenadas por título.
	 *
	 * @param int $selected ID actualmente seleccionado.
	 */
	private function render_post_select( int $selected ): void {
		$posts = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		?>
		<select name="<?php echo esc_attr( self::FIELD_NAME ); ?>" id="<?php echo esc_attr( self::FIELD_NAME ); ?>" class="regular-text">
			<option value="0">— Sin guía relacionada —</option>
			<?php foreach ( $posts as $post ) : ?>
				<?php if ( ! isset( $post->ID, $post->post_title ) ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<option value="<?php echo esc_attr( (string) $post->ID ); ?>"<?php echo (int) $post->ID === $selected ? ' selected' : ''; ?>>
					<?php echo esc_html( (string) $post->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Construye los datos del bloque sin aceptar posts retirados o borradores.
	 *
	 * @return array{post_id: int, title: string, url: string}|null
	 */
	private function resolve_view_model(): ?array {
		if ( ! $this->is_clean_series_archive() ) {
			return null;
		}

		$term    = get_queried_object();
		$post_id = (int) get_term_meta( (int) $term->term_id, self::META_KEY, true );
		if ( ! $this->is_published_post( $post_id ) ) {
			return null;
		}

		$title = trim( (string) get_the_title( $post_id ) );
		$url   = get_permalink( $post_id );
		if ( '' === $title || ! is_string( $url ) || '' === $url ) {
			return null;
		}

		return array(
			'post_id' => $post_id,
			'title'   => $title,
			'url'     => $url,
		);
	}

	/**
	 * Valida que el destino siga siendo una entrada pública.
	 */
	private function is_published_post( int $post_id ): bool {
		return $post_id > 0
			&& 'post' === get_post_type( $post_id )
			&& 'publish' === get_post_status( $post_id );
	}
}
