<?php
/**
 * Navegación entre ediciones físicas de una misma obra.
 *
 * Una URL `pa_serie` representa la edición que Akibara vende, no una entidad
 * abstracta: One Piece de Ivrea y el 3 en 1 de Planeta tienen numeración,
 * disponibilidad y estado editorial distintos. Este módulo permite relacionar
 * esos términos sin fusionarlos ni cambiar sus slugs, y publica enlaces
 * recíprocos junto al H1 para que personas y rastreadores descubran la edición
 * exacta en un salto.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesEditions;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Services\VolumeVariant;
use Akibara\Business\Utilities\RobotsMeta;
use Akibara\Business\Utilities\Text;
use WC_Product;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * UI, persistencia y navegación de ediciones relacionadas.
 *
 * @since 1.102.0
 */
final class SeriesEditions {

	public const META_KEY = '_akb_serie_ediciones_relacionadas';

	/**
	 * Rótulo delante de las otras ediciones, en la cabecera de la serie. Se
	 * edita en Akibara → Textos → «Landing de serie». Corto a propósito: con
	 * «Otras ediciones en Akibara» el rótulo y la edición no cabían en una línea
	 * a 390 px y el bloque medía ~96 px, que empujaban el primer tomo bajo la
	 * barra inferior en One Piece y Naruto (revisión B3-fix).
	 */
	public const OPTION_LABEL = 'akb_copy_series_editions_label';

	public const DEFAULT_LABEL = 'También en:';

	private const TAXONOMY    = 'pa_serie';
	private const ROBOTS_META = 'rank_math_robots';
	private const FIELD       = 'akb_serie_ediciones_relacionadas';
	private const NONCE_NAME  = 'akb_serie_ediciones_nonce';
	private const NONCE_ACTION = 'akb_save_serie_ediciones';
	private const CAPABILITY   = 'manage_product_terms';
	private const MAX_RELATED  = 8;

	/** @var array<int,true> Term tags already purged in this request. */
	private array $purged_term_ids = array();

	private SeriesCatalogEligibility $eligibility;

	public function __construct( ?SeriesCatalogEligibility $eligibility = null ) {
		$this->eligibility = $eligibility ?? new SeriesCatalogEligibility();
	}

	/** Conecta frontend, formulario nativo y limpieza de relaciones. */
	public function register(): void {
		add_action( 'akibara_gp_series_hero_after_title', array( $this, 'render' ), 10, 1 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_pdp_editions' ), 35 );
		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'handle_add_form_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'handle_edit_form_fields' ), 20, 2 );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'handle_save_term' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'handle_save_term' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'handle_peer_term_changed' ), 100 );
		add_action( 'added_term_meta', array( $this, 'handle_peer_term_meta_changed' ), 100, 4 );
		add_action( 'updated_term_meta', array( $this, 'handle_peer_term_meta_changed' ), 100, 4 );
		add_action( 'deleted_term_meta', array( $this, 'handle_peer_term_meta_changed' ), 100, 4 );
		add_action( 'edited_term_taxonomy', array( $this, 'handle_peer_term_taxonomy_changed' ), 100, 2 );
		add_action( 'set_object_terms', array( $this, 'handle_catalog_relationship_changed' ), 100, 4 );
		add_action( 'deleted_term_relationships', array( $this, 'handle_catalog_relationship_deleted' ), 100, 3 );
		// WordPress elimina termmeta ANTES de `delete_{$taxonomy}`. La relación
		// original sólo sigue disponible en este hook previo y genérico.
		add_action( 'pre_delete_term', array( $this, 'handle_pre_delete_term' ), 10, 2 );
		add_filter( 'litespeed_ucss_whitelist', array( $this, 'allowlist_ucss_selectors' ), 20 );
	}

	/**
	 * Imprime enlaces a las otras ediciones indexables y con catálogo.
	 *
	 * @param mixed $term Término que el hero ya resolvió.
	 */
	public function render( $term ): void {
		if ( ! $term instanceof WP_Term || self::TAXONOMY !== $term->taxonomy ) {
			return;
		}

		$links = array();
		foreach ( $this->related_terms( (int) $term->term_id ) as $related ) {
			$url = get_term_link( $related, self::TAXONOMY );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$links[] = sprintf(
				'<li><a class="akb-series-editions__link" href="%s">%s</a></li>',
				esc_url( $url ),
				esc_html( $related->name )
			);
		}

		if ( array() === $links ) {
			return;
		}
		?>
		<nav class="akb-series-editions" aria-label="Otras ediciones de esta obra">
			<span class="akb-series-editions__label"><?php echo esc_html( Text::one_line_option( self::OPTION_LABEL, self::DEFAULT_LABEL ) ); ?></span>
			<ul class="akb-series-editions__list">
				<?php echo implode( '', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cada URL y nombre se escapa antes de entrar a la lista. ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Bloque comparativo «Otras ediciones de esta obra» en la ficha de producto (PDP).
	 *
	 * Busca las otras ediciones indexables y con catálogo declaradas en
	 * `_akb_serie_ediciones_relacionadas` y, para cada una, localiza el tomo
	 * homólogo (mismo número de volumen) para mostrar su portada, editorial y
	 * país de origen, estado comercial en vivo + precio, total de tomos en
	 * catálogo y enlace directo tanto al tomo equivalente como a la edición
	 * completa.
	 *
	 * @param mixed $product_arg Producto explícito (tests) o null para leer `$GLOBALS['product']`.
	 */
	public function render_pdp_editions( $product_arg = null ): void {
		global $product;

		$current = $product_arg instanceof WC_Product
			? $product_arg
			: ( isset( $product ) && $product instanceof WC_Product ? $product : null );
		if ( null === $current ) {
			return;
		}

		$product_id = (int) $current->get_id();
		if ( $product_id < 1 ) {
			return;
		}

		$own_terms = get_the_terms( $product_id, self::TAXONOMY );
		if ( ! is_array( $own_terms ) || array() === $own_terms ) {
			return;
		}

		$own_ids = array();
		foreach ( $own_terms as $own_term ) {
			if ( $own_term instanceof WP_Term ) {
				$own_ids[ (int) $own_term->term_id ] = true;
			}
		}
		if ( array() === $own_ids ) {
			return;
		}

		$related_terms = array();
		foreach ( array_keys( $own_ids ) as $own_id ) {
			foreach ( $this->related_terms( (int) $own_id ) as $rel_term ) {
				$rel_id = (int) $rel_term->term_id;
				if ( ! isset( $own_ids[ $rel_id ] ) && ! isset( $related_terms[ $rel_id ] ) ) {
					$related_terms[ $rel_id ] = $rel_term;
				}
			}
		}
		if ( array() === $related_terms ) {
			return;
		}

		$current_title = is_callable( array( $current, 'get_name' ) ) ? (string) $current->get_name() : '';
		if ( '' === $current_title && function_exists( 'get_the_title' ) ) {
			$current_title = (string) get_the_title( $product_id );
		}
		$target_volume = VolumeTitle::extract_number( $current_title );

		$counts = $this->eligibility->counts(
			array_map( static fn( WP_Term $term ): int => (int) $term->term_taxonomy_id, array_values( $related_terms ) )
		);

		$cards = array();
		foreach ( $related_terms as $rel_term ) {
			$series_url = get_term_link( $rel_term, self::TAXONOMY );
			if ( ! is_string( $series_url ) || '' === $series_url ) {
				continue;
			}

			$catalog_total = (int) ( $counts[ (int) $rel_term->term_taxonomy_id ] ?? $rel_term->count );
			$snapshot      = $this->resolve_related_edition_snapshot( (int) $rel_term->term_id, $target_volume );

			$cards[] = array(
				'series_name'   => (string) $rel_term->name,
				'series_url'    => $series_url,
				'catalog_total' => $catalog_total,
				'origin'        => $snapshot['origin'],
				'volume'        => $snapshot['volume'],
			);
		}

		if ( array() === $cards ) {
			return;
		}
		?>
		<aside class="akb-pdp-other-editions" aria-label="Otras ediciones de esta obra">
			<div class="akb-pdp-other-editions__header">
				<span class="akb-pdp-other-editions__eyebrow">Comparar ediciones</span>
				<h2 class="akb-pdp-other-editions__title">Otras ediciones de esta obra</h2>
			</div>
			<div class="akb-pdp-other-editions__grid">
				<?php foreach ( $cards as $card ) : ?>
					<?php
					$vol         = $card['volume'];
					$total_label = 1 === $card['catalog_total']
						? '1 tomo en catálogo'
						: sprintf( '%d tomos en catálogo', $card['catalog_total'] );
					?>
					<div class="akb-pdp-other-editions__card">
						<?php if ( null !== $vol && '' !== $vol['thumb_html'] ) : ?>
							<a class="akb-pdp-other-editions__thumb" href="<?php echo esc_url( $vol['url'] ); ?>" tabindex="-1" aria-hidden="true">
								<?php echo wp_kses_post( $vol['thumb_html'] ); ?>
							</a>
						<?php endif; ?>
						<div class="akb-pdp-other-editions__body">
							<div class="akb-pdp-other-editions__meta">
								<?php if ( '' !== $card['origin'] ) : ?>
									<span class="akb-pdp-other-editions__origin"><?php echo esc_html( $card['origin'] ); ?></span>
								<?php endif; ?>
								<?php if ( $card['catalog_total'] > 0 ) : ?>
									<span class="akb-pdp-other-editions__count"><?php echo esc_html( $total_label ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( null !== $vol ) : ?>
								<a class="akb-pdp-other-editions__vol-link" href="<?php echo esc_url( $vol['url'] ); ?>">
									<span class="akb-pdp-other-editions__vol-name"><?php echo esc_html( $vol['title'] ); ?></span>
								</a>
								<div class="akb-pdp-other-editions__availability">
									<span class="akb-pdp-other-editions__badge akb-pdp-other-editions__badge--<?php echo esc_attr( $vol['status_key'] ); ?>"><?php echo esc_html( $vol['status_label'] ); ?></span>
									<?php if ( '' !== $vol['price_label'] ) : ?>
										<span class="akb-pdp-other-editions__price"><?php echo esc_html( $vol['price_label'] ); ?></span>
									<?php endif; ?>
									<a class="akb-pdp-other-editions__cta" href="<?php echo esc_url( $vol['url'] ); ?>">
										<?php echo esc_html( null !== $target_volume ? sprintf( 'Ver tomo %02d →', $target_volume ) : 'Ver tomo →' ); ?>
									</a>
								</div>
								<a class="akb-pdp-other-editions__series-link" href="<?php echo esc_url( $card['series_url'] ); ?>">
									<?php echo esc_html( sprintf( 'Ver edición completa: %s →', $card['series_name'] ) ); ?>
								</a>
							<?php else : ?>
								<a class="akb-pdp-other-editions__vol-link" href="<?php echo esc_url( $card['series_url'] ); ?>">
									<span class="akb-pdp-other-editions__vol-name"><?php echo esc_html( $card['series_name'] ); ?></span>
								</a>
								<p class="akb-pdp-other-editions__note">
									<?php
									echo esc_html(
										null !== $target_volume
											? sprintf( 'El tomo %02d aún no está publicado en esta edición o lleva otra numeración.', $target_volume )
											: 'Explora los tomos publicados de esta edición.'
									);
									?>
								</p>
								<a class="akb-pdp-other-editions__series-link" href="<?php echo esc_url( $card['series_url'] ); ?>">
									<?php
									echo esc_html(
										$card['catalog_total'] > 0
											? sprintf( 'Ver los %d tomos de esta edición →', $card['catalog_total'] )
											: 'Ver edición completa →'
									);
									?>
								</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</aside>
		<?php
	}

	/**
	 * Resuelve el tomo homólogo (mismo número) y el sello de editorial/país de una edición relacionada.
	 *
	 * @param int      $related_term_id Término `pa_serie` relacionado.
	 * @param int|null $target_volume   Número de tomo buscado, o null si la ficha actual es tomo único / sin número.
	 * @return array{origin:string,volume:array{id:int,title:string,url:string,status_key:string,status_label:string,price_label:string,thumb_html:string}|null}
	 */
	private function resolve_related_edition_snapshot( int $related_term_id, ?int $target_volume ): array {
		$empty = array(
			'origin' => '',
			'volume' => null,
		);
		if ( $related_term_id < 1 || ! function_exists( 'get_posts' ) ) {
			return $empty;
		}

		$post_ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => 250,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $related_term_id,
					),
				),
			)
		);
		if ( ! is_array( $post_ids ) || array() === $post_ids ) {
			return $empty;
		}

		$matched_id      = 0;
		$variant_fallback = 0;
		$sample_id       = 0;

		foreach ( $post_ids as $candidate ) {
			$cand_id = (int) $candidate;
			if ( $cand_id < 1 ) {
				continue;
			}
			if ( 0 === $sample_id ) {
				$sample_id = $cand_id;
			}
			if ( null === $target_volume ) {
				continue;
			}

			$cand_product = function_exists( 'wc_get_product' ) ? wc_get_product( $cand_id ) : null;
			$cand_title   = $cand_product instanceof WC_Product && is_callable( array( $cand_product, 'get_name' ) )
				? (string) $cand_product->get_name()
				: ( function_exists( 'get_the_title' ) ? (string) get_the_title( $cand_id ) : '' );
			if ( '' === $cand_title ) {
				continue;
			}

			if ( $target_volume !== VolumeTitle::extract_number( $cand_title ) ) {
				continue;
			}

			if ( VolumeVariant::is_variant( $cand_id ) ) {
				if ( 0 === $variant_fallback ) {
					$variant_fallback = $cand_id;
				}
				continue;
			}

			$matched_id = $cand_id;
			break;
		}

		if ( 0 === $matched_id && $variant_fallback > 0 ) {
			$matched_id = $variant_fallback;
		}

		$origin_source_id = $matched_id > 0 ? $matched_id : $sample_id;
		$origin           = $origin_source_id > 0
			? self::format_edition_origin(
				self::first_term_name( $origin_source_id, 'product_brand' ),
				self::first_term_name( $origin_source_id, 'pa_pais' )
			)
			: '';

		if ( $matched_id < 1 || ! function_exists( 'wc_get_product' ) ) {
			return array(
				'origin' => $origin,
				'volume' => null,
			);
		}

		$matched_product = wc_get_product( $matched_id );
		if ( ! $matched_product instanceof WC_Product ) {
			return array(
				'origin' => $origin,
				'volume' => null,
			);
		}

		$vol_url = function_exists( 'get_permalink' ) ? (string) get_permalink( $matched_id ) : '';
		if ( '' === $vol_url && is_callable( array( $matched_product, 'get_permalink' ) ) ) {
			$vol_url = (string) $matched_product->get_permalink();
		}
		if ( '' === $vol_url ) {
			return array(
				'origin' => $origin,
				'volume' => null,
			);
		}

		$vol_title = is_callable( array( $matched_product, 'get_name' ) ) ? (string) $matched_product->get_name() : '';
		if ( '' === $vol_title && function_exists( 'get_the_title' ) ) {
			$vol_title = (string) get_the_title( $matched_id );
		}

		$raw_price = is_callable( array( $matched_product, 'get_price' ) ) ? (float) $matched_product->get_price() : 0.0;
		if ( $raw_price <= 0 && is_callable( array( $matched_product, 'get_regular_price' ) ) ) {
			$raw_price = (float) $matched_product->get_regular_price();
		}
		$price_label = $raw_price > 0 ? '$' . number_format( $raw_price, 0, ',', '.' ) : '';
		$thumb_html  = is_callable( array( $matched_product, 'get_image' ) )
			? (string) $matched_product->get_image(
				'woocommerce_thumbnail',
				array(
					'class'   => 'akb-pdp-other-editions__thumb-img',
					'loading' => 'lazy',
					'alt'     => '',
				)
			)
			: '';

		return array(
			'origin' => $origin,
			'volume' => array(
				'id'           => $matched_id,
				'title'        => $vol_title,
				'url'          => $vol_url,
				'status_key'   => Availability::of( $matched_product ),
				'status_label' => Availability::label_of( $matched_product ),
				'price_label'  => $price_label,
				'thumb_html'   => $thumb_html,
			),
		);
	}

	/** Primer nombre de término de una taxonomía asignada a un producto. */
	private static function first_term_name( int $product_id, string $taxonomy ): string {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return '';
		}
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term && '' !== trim( (string) $term->name ) ) {
				return trim( (string) $term->name );
			}
		}
		return '';
	}

	/**
	 * Compone el sello de origen editorial sin repetir el país cuando ya va en la marca
	 * (ej. «Ivrea Argentina» + «Argentina» → «Ivrea Argentina»; «Planeta Cómic» + «España» → «Planeta Cómic · España»).
	 */
	public static function format_edition_origin( string $brand, string $country ): string {
		$brand   = trim( $brand );
		$country = trim( $country );
		if ( '' === $brand ) {
			return $country;
		}
		if ( '' === $country ) {
			return $brand;
		}
		if ( false !== strpos( self::fold_ascii( $brand ), self::fold_ascii( $country ) ) ) {
			return $brand;
		}
		return $brand . ' · ' . $country;
	}

	/** Plegado ASCII en minúsculas para comparación insensible a tildes. */
	private static function fold_ascii( string $value ): string {
		$folded = strtr(
			$value,
			array(
				'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
				'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
				'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i', 'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
				'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
				'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
				'Ñ' => 'n', 'ñ' => 'n',
			)
		);
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $folded, 'UTF-8' ) : strtolower( $folded );
	}

	/**
	 * `term_taxonomy_id` de las ediciones que el dueño declaró como la MISMA obra
	 * que alguna serie del producto (la relación de este módulo), sin las del
	 * propio producto.
	 *
	 * Lo lee «Productos relacionados» en el modo «Empezar otra serie», que
	 * promete no repetir la serie de la ficha: sin esto, quien miraba One Piece
	 * 3 en 1 nº 01 recibía «One Piece 001» como otra serie, y Berserk Master
	 * Edition 01 ofrecía Maximum Berserk 01 (revisión B2-2-R2, las cuatro parejas
	 * del espejo). Para esa promesa cuentan todas las ediciones relacionadas,
	 * también las noindex o sin catálogo que la cabecera no enlaza: siguen siendo
	 * la misma historia.
	 *
	 * Coste: los términos del producto salen de la caché de términos que el
	 * relleno ya cebó, su meta de la de termmeta, y el paso a
	 * `term_taxonomy_id` es UNA consulta sólo si hay ediciones relacionadas.
	 *
	 * @param int $product_id Producto.
	 * @return array<int, int>
	 */
	public static function same_work_series_tt_ids( int $product_id ): array {
		$terms = $product_id > 0 ? get_the_terms( $product_id, self::TAXONOMY ) : false;
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$own     = array();
		$related = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$own[] = (int) $term->term_id;
			foreach ( self::stored_ids( (int) $term->term_id ) as $related_id ) {
				$related[] = $related_id;
			}
		}
		$related = array_values( array_diff( array_unique( $related ), $own ) );
		if ( array() === $related ) {
			return array();
		}

		$tt_ids = get_terms(
			array(
				'taxonomy'               => self::TAXONOMY,
				'include'                => $related,
				'hide_empty'             => false,
				'fields'                 => 'tt_ids',
				'update_term_meta_cache' => false,
			)
		);
		if ( ! is_array( $tt_ids ) ) {
			return array();
		}

		$tt_ids = array_values( array_unique( array_filter( array_map( 'intval', $tt_ids ) ) ) );
		sort( $tt_ids );

		return $tt_ids;
	}

	/** Campo de relación al crear una serie. */
	public function handle_add_form_fields(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="form-field">
			<label for="<?php echo esc_attr( self::FIELD ); ?>">Ediciones relacionadas</label>
			<?php $this->render_editions_picker( 0, array() ); ?>
			<p>Selecciona otras ediciones físicas de la misma obra. La relación se guarda en ambos sentidos.</p>
		</div>
		<?php
	}

	/**
	 * Campo de relación al editar una serie.
	 *
	 * @param mixed  $term     Término editado.
	 * @param string $taxonomy Taxonomía recibida por WordPress.
	 */
	public function handle_edit_form_fields( $term, string $taxonomy = '' ): void {
		if ( self::TAXONOMY !== $taxonomy || ! $term instanceof WP_Term ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<tr class="form-field">
			<th scope="row"><label for="<?php echo esc_attr( self::FIELD ); ?>">Ediciones relacionadas</label></th>
			<td>
				<?php $this->render_editions_picker( (int) $term->term_id, self::stored_ids( (int) $term->term_id ) ); ?>
				<p class="description">Selecciona otras ediciones físicas de la misma obra. La relación se guarda en ambos sentidos.</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Guarda la lista saneada y mantiene automáticamente los enlaces recíprocos.
	 */
	public function handle_save_term( int $term_id ): void {
		$posted_nonce = $_POST[ self::NONCE_NAME ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- es el nonce y se valida en la línea siguiente.
		$nonce        = is_string( $posted_nonce ) ? sanitize_text_field( wp_unslash( $posted_nonce ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION )
			|| ! current_user_can( self::CAPABILITY )
			|| ! term_exists( $term_id, self::TAXONOMY ) ) {
			return;
		}

		$posted = $_POST[ self::FIELD ] ?? array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verificado arriba; se comprueba que sea array y se desescapa en la línea siguiente.
		$raw    = is_array( $posted ) ? wp_unslash( $posted ) : array();
		$next   = array();

		foreach ( $raw as $candidate ) {
			$id = is_scalar( $candidate ) ? absint( $candidate ) : 0;
			if ( $id < 1 || $id === $term_id || ! term_exists( $id, self::TAXONOMY ) ) {
				continue;
			}
			$next[] = $id;
		}

		$requested = array_slice( array_values( array_unique( $next ) ), 0, self::MAX_RELATED );
		$before    = self::stored_ids( $term_id );
		$next      = array();
		$originals = array();
		$desired   = array();

		// Una relación sólo entra al término principal si el peer puede conservar
		// también el edge inverso. Así el límite de ocho nunca crea un vínculo de
		// una sola dirección, y un guardado normal repara drift previo cuando hay
		// capacidad aunque la selección visible no haya cambiado.
		foreach ( $requested as $peer_id ) {
			$peer = self::stored_ids( $peer_id );
			$originals[ $peer_id ] = $peer;
			if ( ! in_array( $term_id, $peer, true ) ) {
				if ( count( $peer ) >= self::MAX_RELATED ) {
					continue;
				}
				$peer[] = $term_id;
				$desired[ $peer_id ] = $peer;
			}
			$next[] = $peer_id;
		}

		$originals[ $term_id ] = $before;
		$desired[ $term_id ]   = $next;

		foreach ( array_diff( $before, $next ) as $removed_id ) {
			$removed_id = (int) $removed_id;
			$peer    = self::stored_ids( (int) $removed_id );
			$updated = array_values( array_diff( $peer, array( $term_id ) ) );
			$originals[ $removed_id ] = $peer;
			$desired[ $removed_id ]   = $updated;
		}

		$changed = array();
		foreach ( $desired as $target_id => $target_ids ) {
			$original = $originals[ $target_id ] ?? self::stored_ids( (int) $target_id );
			if ( $original === $target_ids ) {
				continue;
			}

			if ( ! $this->persist_ids( (int) $target_id, $target_ids ) ) {
				// Compensación inmediata: una falla intermedia nunca debe dejar un
				// edge en una sola dirección. Las restauraciones usan la misma API
				// para mantener coherentes DB y object cache.
				foreach ( array_reverse( $changed ) as $written_id ) {
					$this->persist_ids( $written_id, $originals[ $written_id ] );
				}
				$this->purge_terms( array_merge( $changed, array( (int) $target_id ) ) );
				return;
			}
			$changed[] = (int) $target_id;
		}

		$this->purge_terms( $changed );
	}

	/**
	 * Purga una edición y las páginas que publican un enlace hacia ella.
	 *
	 * El HTML de la edición A incorpora el nombre, slug e indexabilidad de B.
	 * Por eso un cambio de B debe invalidar A aunque el edge no haya cambiado.
	 */
	public function handle_peer_term_changed( int $term_id ): void {
		$this->purge_terms( $this->dependent_term_ids( $term_id ) );
	}

	/**
	 * Invalida dependientes cuando cambia robots o el propio grafo de ediciones.
	 *
	 * @param mixed  $meta_ids   Meta ID or IDs supplied by WordPress.
	 * @param int    $term_id    Changed term ID.
	 * @param string $meta_key   Changed metadata key.
	 * @param mixed  $meta_value Changed value.
	 */
	public function handle_peer_term_meta_changed( $meta_ids, int $term_id, string $meta_key, $meta_value = null ): void {
		unset( $meta_ids, $meta_value );

		if ( self::ROBOTS_META !== $meta_key && self::META_KEY !== $meta_key ) {
			return;
		}

		$this->handle_peer_term_changed( $term_id );
	}

	/**
	 * El conteo decide si el enlace existe; un cambio 0↔N invalida sus peers.
	 *
	 * @param int    $term_taxonomy_id Term-taxonomy ID actualizado por core.
	 * @param string $taxonomy        Taxonomía actualizada.
	 */
	public function handle_peer_term_taxonomy_changed( int $term_taxonomy_id, string $taxonomy ): void {
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}

		$term = get_term_by( 'term_taxonomy_id', $term_taxonomy_id, self::TAXONOMY );
		if ( $term instanceof WP_Term ) {
			$this->handle_peer_term_changed( (int) $term->term_id );
		}
	}

	/**
	 * Purga ediciones dependientes cuando cambia la visibilidad de un producto.
	 *
	 * @param int|string $object_id Producto.
	 * @param mixed      $terms     Términos, no usados.
	 * @param mixed      $tt_ids    TTIDs, no usados.
	 * @param string     $taxonomy  Taxonomía afectada.
	 */
	public function handle_catalog_relationship_changed( $object_id, $terms, $tt_ids, string $taxonomy ): void {
		unset( $terms, $tt_ids );
		if ( SeriesCatalogEligibility::VISIBILITY_TAXONOMY !== $taxonomy ) {
			return;
		}

		$this->eligibility->reset_cache();
		foreach ( $this->series_term_ids_of_product( (int) $object_id ) as $term_id ) {
			$this->handle_peer_term_changed( $term_id );
		}
	}

	/** Cubre `wp_remove_object_terms()`, que omite `set_object_terms`. */
	public function handle_catalog_relationship_deleted( $object_id, $tt_ids, string $taxonomy ): void {
		$this->handle_catalog_relationship_changed( $object_id, array(), $tt_ids, $taxonomy );
	}

	/** Retira referencias recíprocas antes de que WordPress borre termmeta. */
	public function handle_pre_delete_term( int $term_id, string $taxonomy ): void {
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}

		$changed = array();
		// Incluye edges entrantes para reparar drift histórico A→B sin B→A.
		// Si B se elimina, A no debe conservar una meta colgante ni HTML cacheado.
		foreach ( array_diff( $this->dependent_term_ids( $term_id ), array( $term_id ) ) as $peer_id ) {
			$peer    = self::stored_ids( $peer_id );
			$updated = array_values( array_diff( $peer, array( $term_id ) ) );
			if ( $peer !== $updated ) {
				$this->persist_ids( $peer_id, $updated );
				$changed[] = $peer_id;
			}
		}
		$this->purge_terms( $changed );
	}

	/** Selectores condicionales que LiteSpeed UCSS no debe podar. */
	public function allowlist_ucss_selectors( array $selectors ): array {
		foreach ( array( '.akb-series-editions', '.akb-series-editions__label', '.akb-series-editions__list', '.akb-series-editions__link' ) as $selector ) {
			$selectors[] = $selector;
		}
		return array_values( array_unique( $selectors ) );
	}

	/** @return array<int,WP_Term> */
	private function related_terms( int $term_id ): array {
		$stored_ids = self::stored_ids( $term_id );
		if ( array() === $stored_ids ) {
			return array();
		}

		global $wpdb;
		$queries_before = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->num_queries )
			? (int) $wpdb->num_queries
			: null;
		$loaded         = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'include'    => $stored_ids,
				'orderby'    => 'include',
			)
		);
		if ( ! $this->term_batch_is_reliable( $loaded, $queries_before ) ) {
			$this->mark_request_uncacheable( 'series related editions unavailable' );
			return array();
		}

		$terms_by_id = array();
		foreach ( $loaded as $term ) {
			if ( $term instanceof WP_Term && self::TAXONOMY === (string) $term->taxonomy ) {
				$terms_by_id[ (int) $term->term_id ] = $term;
			}
		}

		$candidates = array();
		foreach ( $stored_ids as $related_id ) {
			$term = $terms_by_id[ $related_id ] ?? null;
			if ( null === $term || $this->is_noindex( $related_id ) ) {
				continue;
			}
			$candidates[] = $term;
		}
		if ( array() === $candidates ) {
			return array();
		}

		$counts = $this->eligibility->counts(
			array_map( static fn( WP_Term $term ): int => (int) $term->term_taxonomy_id, $candidates )
		);
		if ( ! $this->eligibility->is_reliable() ) {
			$this->mark_request_uncacheable( 'series catalogue eligibility unavailable' );

			return array();
		}
		$terms = array();
		foreach ( $candidates as $term ) {
			if ( ( $counts[ (int) $term->term_taxonomy_id ] ?? 0 ) > 0 ) {
				$terms[] = $term;
			}
		}
		return $terms;
	}

	/**
	 * Distingue un lote vacío real de un fallo que `get_terms()` normalizó.
	 *
	 * Un `last_error` antiguo no invalida un resultado servido desde object cache;
	 * sólo se considera cuando este llamado aumentó `wpdb::num_queries`.
	 *
	 * @param mixed    $terms          Resultado de WordPress.
	 * @param int|null $queries_before Contador anterior, si el runtime lo expone.
	 */
	private function term_batch_is_reliable( $terms, ?int $queries_before ): bool {
		if ( ! is_array( $terms ) ) {
			return false;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return true;
		}
		if ( isset( $wpdb->ready ) && ! (bool) $wpdb->ready ) {
			return false;
		}

		$query_ran = null === $queries_before
			|| ! isset( $wpdb->num_queries )
			|| (int) $wpdb->num_queries > $queries_before;

		return ! ( $query_ran && isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) );
	}

	/** Evita sellar HTML parcial causado por una lectura no autoritativa. */
	private function mark_request_uncacheable( string $reason ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constante estándar de facto de los plugins de caché (LiteSpeed, W3TC, WP Super Cache): prefijarla la dejaría inerte.
		}
		do_action( 'litespeed_control_set_nocache', $reason ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
	}

	/** @return array<int,int> */
	private function series_term_ids_of_product( int $product_id ): array {
		if ( $product_id < 1 ) {
			return array();
		}
		$ids = wp_get_object_terms( $product_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 )
			)
		);
	}

	private function is_noindex( int $term_id ): bool {
		return ! RobotsMeta::is_indexable( get_term_meta( $term_id, self::ROBOTS_META, true ) );
	}

	/** @return array<int,int> */
	private static function stored_ids( int $term_id ): array {
		$raw = get_term_meta( $term_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			$raw = is_numeric( $raw ) ? array( $raw ) : array();
		}

		$ids = array();
		foreach ( $raw as $candidate ) {
			$id = is_scalar( $candidate ) ? absint( $candidate ) : 0;
			if ( $id > 0 && $id !== $term_id ) {
				$ids[] = $id;
			}
		}
		return array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_RELATED );
	}

	/**
	 * @param array<int,int> $ids IDs relacionados normalizados.
	 * @return bool Si termmeta confirmó la escritura o eliminación.
	 */
	private function persist_ids( int $term_id, array $ids ): bool {
		if ( array() === $ids ) {
			return false !== delete_term_meta( $term_id, self::META_KEY );
		}
		return false !== update_term_meta( $term_id, self::META_KEY, array_values( array_unique( $ids ) ) );
	}

	/**
	 * Selector de ediciones relacionadas.
	 *
	 * Antes era un `<select multiple>` nativo con las 119 series de la tienda, y
	 * ese control tiene un modo de fallo que no perdona: un clic simple sobre
	 * cualquier opción DESELECCIONA todas las demás. En un desplegable de siete
	 * filas de alto, las relaciones que se pierden ni siquiera están a la vista,
	 * y como el guardado es recíproco (`handle_save_term` retira el edge inverso
	 * en el otro término), un roce al entrar a revisar una serie borraba la
	 * relación en los dos sentidos sin un solo aviso. En el teléfono es peor
	 * todavía: iOS y Android abren su propia hoja de selección, y el dueño
	 * administra desde el teléfono.
	 *
	 * Las casillas no tienen ese modo de fallo: marcar una nunca desmarca otra,
	 * y el estado completo se lee de un vistazo. Las ya relacionadas van primero
	 * porque la pregunta que se hace al abrir el término es «¿con qué está
	 * enlazada?», no «¿dónde está la Z?». El filtro y el contador son mejoras
	 * progresivas: sin JavaScript queda la lista entera y el servidor sigue
	 * recortando en MAX_RELATED, que es la única frontera que manda.
	 *
	 * @param array<int,int> $selected
	 */
	private function render_editions_picker( int $current_id, array $selected ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$terms = is_array( $terms ) ? $terms : array();

		$chosen = array();
		$rest   = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term || (int) $term->term_id === $current_id ) {
				continue;
			}
			if ( in_array( (int) $term->term_id, $selected, true ) ) {
				$chosen[] = $term;
				continue;
			}
			$rest[] = $term;
		}

		$field    = self::FIELD;
		$total    = count( $chosen );
		$group_id = $field . '-' . ( $current_id > 0 ? (string) $current_id : 'new' );

		?>
		<div class="akb-editions-picker" data-akb-editions-picker data-max="<?php echo esc_attr( (string) self::MAX_RELATED ); ?>">
			<p class="akb-editions-picker__bar">
				<label class="screen-reader-text" for="<?php echo esc_attr( $group_id ); ?>-filter">Filtrar ediciones por nombre</label>
				<input type="search" id="<?php echo esc_attr( $group_id ); ?>-filter" class="akb-editions-picker__filter" placeholder="Filtrar por nombre…" autocomplete="off">
				<span class="akb-editions-picker__count" data-akb-editions-count aria-live="polite"><?php echo esc_html( sprintf( '%d de %d seleccionadas', $total, self::MAX_RELATED ) ); ?></span>
			</p>
			<div class="akb-editions-picker__list">
				<?php if ( array() !== $chosen ) : ?>
					<p class="akb-editions-picker__group">Relacionadas ahora</p>
					<?php foreach ( $chosen as $term ) : ?>
						<?php self::render_picker_option( $group_id, $field, $term, true ); ?>
					<?php endforeach; ?>
					<p class="akb-editions-picker__group">Otras series</p>
				<?php endif; ?>
				<?php foreach ( $rest as $term ) : ?>
					<?php self::render_picker_option( $group_id, $field, $term, false ); ?>
				<?php endforeach; ?>
				<p class="akb-editions-picker__empty" data-akb-editions-empty hidden>Ninguna serie coincide con el filtro.</p>
			</div>
		</div>
		<?php
		self::print_picker_assets();
	}

	/** Una casilla de la lista. */
	private static function render_picker_option( string $group_id, string $field, WP_Term $term, bool $checked ): void {
		$id = $group_id . '-' . (int) $term->term_id;
		?>
		<label class="akb-editions-picker__item">
			<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $field ); ?>[]" value="<?php echo esc_attr( (string) $term->term_id ); ?>"<?php echo $checked ? ' checked' : ''; ?>>
			<span class="akb-editions-picker__name"><?php echo esc_html( $term->name ); ?></span>
		</label>
		<?php
	}

	/**
	 * Imprime estilos y comportamiento una sola vez por pantalla.
	 *
	 * Va en línea a propósito: son ~40 líneas que sólo existen en la pantalla de
	 * edición de una taxonomía del admin, y registrar un handle para eso obliga a
	 * versionarlo y desplegarlo como asset sin ganar nada.
	 */
	private static function print_picker_assets(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
			.akb-editions-picker { max-width: 460px; }
			.akb-editions-picker__bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 6px; }
			.akb-editions-picker__filter { flex: 1 1 200px; min-width: 0; }
			.akb-editions-picker__count { font-size: 12px; color: #50575e; }
			.akb-editions-picker.is-full .akb-editions-picker__count { color: #b32d2e; font-weight: 600; }
			.akb-editions-picker__list { max-height: 320px; overflow-y: auto; border: 1px solid #8c8f94; border-radius: 4px; background: #fff; padding: 4px; }
			.akb-editions-picker__group { margin: 6px 6px 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #50575e; }
			.akb-editions-picker__group + .akb-editions-picker__group { display: none; }
			.akb-editions-picker__item { display: flex; align-items: center; gap: 8px; padding: 8px 6px; min-height: 32px; border-radius: 3px; cursor: pointer; }
			.akb-editions-picker__item[hidden] { display: none; }
			.akb-editions-picker__item:hover { background: #f0f0f1; }
			.akb-editions-picker__item input:focus-visible { outline: 2px solid #2271b1; outline-offset: 1px; }
			.akb-editions-picker__item input:disabled + .akb-editions-picker__name { color: #8c8f94; }
			.akb-editions-picker__empty { margin: 10px 6px; color: #50575e; font-style: italic; }
			@media (max-width: 782px) { .akb-editions-picker__item { min-height: 40px; } }
		</style>
		<script>
		( function () {
			var norm = function ( value ) {
				return value.toLowerCase().normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
			};

			var init = function () {
				document.querySelectorAll( '[data-akb-editions-picker]' ).forEach( function ( root ) {
					if ( root.getAttribute( 'data-akb-editions-ready' ) === '1' ) { return; }
					root.setAttribute( 'data-akb-editions-ready', '1' );
					var max     = parseInt( root.getAttribute( 'data-max' ), 10 ) || 0;
					var boxes   = Array.prototype.slice.call( root.querySelectorAll( 'input[type="checkbox"]' ) );
					var counter = root.querySelector( '[data-akb-editions-count]' );
					var empty   = root.querySelector( '[data-akb-editions-empty]' );
					var filter  = root.querySelector( '.akb-editions-picker__filter' );

					var sync = function () {
						var picked = boxes.filter( function ( box ) { return box.checked; } ).length;
						if ( counter ) {
							counter.textContent = picked + ' de ' + max + ' seleccionadas';
						}
						root.classList.toggle( 'is-full', max > 0 && picked >= max );
						boxes.forEach( function ( box ) {
							box.disabled = ! box.checked && max > 0 && picked >= max;
						} );
					};

					boxes.forEach( function ( box ) { box.addEventListener( 'change', sync ); } );
					sync();

					if ( ! filter ) {
						return;
					}
					filter.addEventListener( 'input', function () {
						var needle  = norm( filter.value.trim() );
						var visible = 0;
						boxes.forEach( function ( box ) {
							var item = box.closest( '.akb-editions-picker__item' );
							if ( ! item ) { return; }
							var name = norm( item.textContent || '' );
							var show = '' === needle || name.indexOf( needle ) !== -1;
							item.hidden = ! show;
							if ( show ) { visible++; }
						} );
						root.querySelectorAll( '.akb-editions-picker__group' ).forEach( function ( group ) {
							group.hidden = '' !== needle;
						} );
						if ( empty ) { empty.hidden = 0 !== visible; }
					} );
				} );
			};
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', init, { once: true } );
			} else {
				init();
			}
		}() );
		</script>
		<?php
	}

	/**
	 * Obtiene edges salientes y entrantes, incluso si existe drift histórico.
	 *
	 * @return array<int,int>
	 */
	private function dependent_term_ids( int $term_id ): array {
		if ( $term_id < 1 ) {
			return array();
		}

		$dependent_ids = array_merge( array( $term_id ), self::stored_ids( $term_id ) );
		$all_term_ids  = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( is_array( $all_term_ids ) ) {
			$all_term_ids = array_values(
				array_filter(
					array_map(
						static fn( $candidate ): int => is_scalar( $candidate ) ? absint( $candidate ) : 0,
						$all_term_ids
					)
				)
			);
			// La consulta puede salir del cache de WP_Term_Query sin volver a cebar
			// termmeta. Este priming explícito mantiene el scan inverso en una sola
			// lectura agrupada, en vez de una consulta por cada serie.
			update_meta_cache( 'term', $all_term_ids );
			foreach ( $all_term_ids as $candidate_id ) {
				if ( $candidate_id > 0 && in_array( $term_id, self::stored_ids( $candidate_id ), true ) ) {
					$dependent_ids[] = $candidate_id;
				}
			}
		}

		return array_values( array_unique( array_map( 'intval', $dependent_ids ) ) );
	}

	/** @param array<int,int> $term_ids */
	private function purge_terms( array $term_ids ): void {
		foreach ( array_unique( $term_ids ) as $term_id ) {
			$term_id = (int) $term_id;
			if ( $term_id < 1 || isset( $this->purged_term_ids[ $term_id ] ) ) {
				continue;
			}

			$this->purged_term_ids[ $term_id ] = true;
			do_action( 'litespeed_purge', 'T.' . $term_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
		}
	}
}
