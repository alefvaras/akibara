<?php
/**
 * Schema — el JSON-LD de reseñas con la forma EXACTA del plugin de terceros
 * (orden explícita del dueño), entregado DENTRO del Product de Rank Math.
 *
 * Ingeniería inversa de CR_StructuredData (04-cusrev-ingenieria-inversa.md):
 *   - aggregateRating con EXACTAMENTE 3 claves: @type, ratingValue (el string
 *     crudo de 2 decimales de WooCommerce, sin redondear) y reviewCount. SIN
 *     bestRating/worstRating/ratingCount a ese nivel.
 *   - review[] = las 5 MÁS RECIENTES con rating > 0; cada nodo con 5 claves
 *     en este orden: @type, reviewRating {@type Rating, bestRating '5',
 *     ratingValue crudo, worstRating '1'}, author {Person, name}, reviewBody
 *     (comment_content SIN filtrar: crudo, sin truncar y sin HTML) y
 *     datePublished (ISO-8601 local, formato 'c').
 *   - Gate: get_rating_count() > 0 — sin ratings, ni una clave se toca.
 *   - El associatedMedia de CusRev es CÓDIGO MUERTO en esta instalación (el
 *     filtro de Woo que lo emitiría no se dispara jamás): el schema real
 *     servido no lo lleva, así que replicarlo sería inventar.
 *
 * Por qué Rank Math y no wp_footer: CusRev emitiría un SEGUNDO nodo Product
 * suelto junto al @graph de Rank Math (entidad duplicada a la primera
 * reseña). Acá las mismas claves entran a la entidad Product,Book existente
 * vía `rank_math/snippet/rich_snippet_product_entity`, reemplazando la forma
 * propia de Rank Math (aggregateRating de 4 claves + review[] SIN límite),
 * que es distinta de la del plugin de terceros.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Schema {

	/**
	 * Prioridad tardía: los enriquecedores de akibara-business (Book, GTIN)
	 * corren antes y esto solo pisa las claves de reseñas.
	 */
	private const PRIORITY = 50;

	/**
	 * Cuelga el filtro de Rank Math.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rank_math/snippet/rich_snippet_product_entity', array( $this, 'inject' ), self::PRIORITY );
	}

	/**
	 * Inyecta aggregateRating y review[] con la forma CusRev en la entidad.
	 *
	 * @param array<string,mixed> $entity Entidad Product de Rank Math.
	 * @return array<string,mixed>
	 */
	public function inject( $entity ) {
		if ( ! is_array( $entity ) ) {
			return $entity;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product() : false;
		if ( ! $product instanceof \WC_Product ) {
			return $entity;
		}

		// El gate del tercero: sin ratings no se toca nada (y se retira lo
		// que Rank Math hubiera puesto por su cuenta, para no publicar una
		// forma ajena a la del plugin que replicamos).
		if ( (int) $product->get_rating_count() <= 0 ) {
			unset( $entity['aggregateRating'], $entity['review'] );
			return $entity;
		}

		$entity['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'ratingValue' => $product->get_average_rating(),
			'reviewCount' => $product->get_review_count(),
		);

		$reviews = self::latest_reviews( (int) $product->get_id() );
		if ( array() !== $reviews ) {
			$entity['review'] = $reviews;
		} else {
			unset( $entity['review'] );
		}

		return $entity;
	}

	/**
	 * Las 5 reseñas más recientes con rating > 0, con los nodos calcados.
	 *
	 * Query clonada del tercero: number 5, status approve, post publicado,
	 * top-level, meta rating NUMERIC > 0, sin orderby explícito (WordPress
	 * cae a comment_date_gmt DESC).
	 *
	 * El interruptor de exclusión omite de `review[]` las reseñas que cobraron
	 * puntos o contaron para un premio. `aggregateRating` conserva la nota y
	 * el total visibles en la ficha. Disclosure resuelve el aviso de incentivo;
	 * su visualización tiene un interruptor independiente.
	 *
	 * @param int $product_id Producto.
	 * @return array<int,array<string,mixed>>
	 */
	private static function latest_reviews( int $product_id ): array {
		$meta = array(
			array(
				'key'     => 'rating',
				'type'    => 'NUMERIC',
				'compare' => '>',
				'value'   => 0,
			),
		);

		if ( self::excludes_rewarded() ) {
			// `pending_account` (el sello de quien reseñó sin cuenta y aún no
			// cobró) castea a 0: todavía no es una reseña premiada. Mismo
			// criterio que Disclosure::notice_for().
			foreach ( array( ReviewPoints::META_POINTS_PAID, ReviewPoints::META_COUNTED ) as $clave ) {
				$meta[] = array(
					'relation' => 'OR',
					array(
						'key'     => $clave,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $clave,
						'type'    => 'NUMERIC',
						'compare' => '<=',
						'value'   => 0,
					),
				);
			}
		}

		$comments = get_comments(
			array(
				'number'      => 5,
				'post_id'     => $product_id,
				'status'      => 'approve',
				'post_status' => 'publish',
				'post_type'   => 'product',
				'parent'      => 0,
				'meta_query'  => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- forma clonada del tercero.
			)
		);

		$nodes = array();
		foreach ( (array) $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}

			// reviewBody sale del comment_content CRUDO: get_comment_text()
			// pasa por el filtro `comment_text` (wpautop + make_clickable) y
			// metía <p> y <br /> dentro del JSON-LD, contra lo que el docblock
			// de este módulo promete.
			$nodes[] = array(
				'@type'        => 'Review',
				'reviewRating' => array(
					'@type'       => 'Rating',
					'bestRating'  => '5',
					'ratingValue' => get_comment_meta( (int) $comment->comment_ID, 'rating', true ),
					'worstRating' => '1',
				),
				'author'       => array(
					'@type' => 'Person',
					'name'  => get_comment_author( $comment ),
				),
				// Incluye en el cuerpo el aviso de transcripción de AdminReviews.
				'reviewBody'   => trim(
					(string) $comment->comment_content
					. ' ' . AdminReviews::transcription_notice( (int) $comment->comment_ID )
				),
				'datePublished' => get_comment_date( 'c', $comment ),
			);
		}

		return $nodes;
	}

	/**
	 * ¿El dueño pidió dejar las reseñas premiadas fuera de los datos
	 * estructurados?
	 *
	 * @return bool
	 */
	private static function excludes_rewarded(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_schema_exclude_rewarded', 'no' );
	}
}
