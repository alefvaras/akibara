<?php
/**
 * SeriesMetaComposer — Composición de metadatos SEO y resolución de medio para series.
 *
 * Calcula títulos y descripciones canónicas a partir del catálogo verificable,
 * resuelve el medio (manga, cómic, manhwa) y gestiona la caché transitoria de landings de serie.
 *
 * @package Akibara\Business\Modules\SeoEnhancements
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoEnhancements;

use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Modules\SeriesFacts\SeriesFacts;
use Akibara\Business\Modules\TaxonomyDiscount\Settings as DiscountSettings;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class SeriesMetaComposer {

	/** Taxonomía de series. */
	public const SERIES_TAXONOMY = 'pa_serie';

	/** Vocabulario cerrado de medios publicables. */
	public const SERIES_MEDIA = array( 'manga', 'cómic', 'manhwa' );

	/** Prefijo de transient para el medio resuelto de una serie. */
	public const SERIES_MEDIUM_TRANSIENT_PREFIX = 'akb_serie_medio_';

	/** TTL del medio cacheado (36 horas). */
	public const SERIES_MEDIUM_TTL = 36 * HOUR_IN_SECONDS;

	/**
	 * Memo por request del meta compuesto de la serie servida.
	 *
	 * @var array<int,array{title:string,description:string}|null>
	 */
	private array $series_meta_memo = array();

	/**
	 * Memo por request del medio por término.
	 *
	 * @var array<int,string>
	 */
	private array $series_medium_memo = array();

	/**
	 * Compone (una vez por request) el meta rico del término de serie.
	 *
	 * @param object $term Término de serie servido.
	 * @return array{title:string,description:string}|null Null si el catálogo no sostiene la variante rica.
	 */
	public function generated_series_meta( object $term ): ?array {
		$term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;
		if ( $term_id < 1 ) {
			return null;
		}
		if ( array_key_exists( $term_id, $this->series_meta_memo ) ) {
			return $this->series_meta_memo[ $term_id ];
		}

		$meta     = null;
		$snapshot = $this->series_catalog_snapshot( $term );
		if ( null !== $snapshot ) {
			$publication = PublicationStatus::scoped_for_term( $term_id );
			$composed    = self::compose_series_meta(
				(string) ( $term->name ?? '' ),
				(string) ( $publication['obra']['estado'] ?? '' ),
				(int) ( $publication['obra']['tomos'] ?? 0 ),
				$snapshot['count'],
				$snapshot['author'],
				$snapshot['brand'],
				$snapshot['medium'],
				$snapshot['price_from'],
				$snapshot['count'] > 0 && (int) ( $snapshot['instock'] ?? 0 ) === $snapshot['count'],
				(string) ( $publication['edicion']['estado'] ?? '' ),
				(int) ( $publication['edicion']['tomos'] ?? 0 )
			);
			if ( '' !== $composed['title'] || '' !== $composed['description'] ) {
				$meta = $composed;
			}
		}

		$this->series_meta_memo[ $term_id ] = $meta;

		return $meta;
	}

	/**
	 * Foto verificable del catálogo de la serie: conteo, autor, editorial, medio.
	 *
	 * @param object $term Término servido.
	 * @return array{count:int,author:string,brand:string,medium:string,price_from:string,instock:int}|null
	 */
	public function series_catalog_snapshot( object $term ): ?array {
		try {
			$medium = $this->series_medium( (int) ( $term->term_id ?? 0 ) );
			if ( '' === $medium ) {
				return null;
			}

			$count      = isset( $term->count ) ? max( 0, (int) $term->count ) : 0;
			$author     = '';
			$brand      = '';
			$price_from = '';
			$instock    = 0;

			$term_taxonomy_id = isset( $term->term_taxonomy_id ) ? (int) $term->term_taxonomy_id : 0;
			if ( $term_taxonomy_id > 0 ) {
				$facts_service = new SeriesFacts( static fn (): bool => ( new DiscountSettings() )->is_enabled() );
				$facts         = $facts_service->catalog_facts( $term_taxonomy_id );
				if ( is_array( $facts ) ) {
					if ( $facts_service->price_range_is_publishable( $facts ) && (float) ( $facts['price_min'] ?? 0 ) > 0 ) {
						$price_from = '$' . number_format( (float) $facts['price_min'], 0, ',', '.' );
					}
					$instock = (int) ( $facts['instock'] ?? 0 );
					if ( (int) ( $facts['tomos'] ?? 0 ) > 0 ) {
						$count = (int) $facts['tomos'];
					}
					$autores = $facts['autores'] ?? array();
					if ( is_array( $autores ) && 1 === count( $autores ) ) {
						$author = trim( (string) ( reset( $autores )['name'] ?? '' ) );
					}
					$editoriales = $facts['editoriales'] ?? array();
					if ( is_array( $editoriales ) && 1 === count( $editoriales ) ) {
						$brand = trim( (string) ( reset( $editoriales )['name'] ?? '' ) );
					}
				}
			}

			return array(
				'count'      => $count,
				'author'     => $author,
				'brand'      => $brand,
				'medium'     => $medium,
				'price_from' => $price_from,
				'instock'    => $instock,
			);
		} catch ( \Throwable $throwable ) {
			return null;
		}
	}

	/**
	 * Sustantivo del medio según la categoría raíz REAL de un producto testigo.
	 *
	 * @param int $term_id Término de la serie.
	 * @return string 'manga' | 'cómic' | 'manhwa' | ''.
	 */
	public function series_medium( int $term_id ): string {
		if ( $term_id < 1 ) {
			return '';
		}
		if ( isset( $this->series_medium_memo[ $term_id ] ) ) {
			return $this->series_medium_memo[ $term_id ];
		}

		$cached = $this->cached_series_medium( $term_id );
		if ( '' !== $cached ) {
			$this->series_medium_memo[ $term_id ] = $cached;

			return $cached;
		}

		$medium = $this->resolve_series_medium( $term_id );
		$this->series_medium_memo[ $term_id ] = $medium;
		if ( '' !== $medium && function_exists( 'set_transient' ) ) {
			set_transient( self::SERIES_MEDIUM_TRANSIENT_PREFIX . $term_id, $medium, self::SERIES_MEDIUM_TTL );
		}

		return $medium;
	}

	/**
	 * Lee el medio cacheado, validando que pertenezca al vocabulario cerrado.
	 *
	 * @param int $term_id Término de la serie.
	 * @return string
	 */
	public function cached_series_medium( int $term_id ): string {
		if ( ! function_exists( 'get_transient' ) ) {
			return '';
		}

		$cached = get_transient( self::SERIES_MEDIUM_TRANSIENT_PREFIX . $term_id );

		return ( is_string( $cached ) && in_array( $cached, self::SERIES_MEDIA, true ) ) ? $cached : '';
	}

	/**
	 * Invalida el medio cacheado al recategorizar productos.
	 *
	 * @param mixed $object_id ID del post.
	 * @param string $taxonomy  Taxonomía modificada.
	 * @return void
	 */
	public function forget_series_medium( $object_id, string $taxonomy = '' ): void {
		if ( 'product_cat' !== $taxonomy ) {
			return;
		}
		$product_id = (int) $object_id;
		if ( $product_id < 1 || ! function_exists( 'get_the_terms' ) || ! function_exists( 'delete_transient' ) ) {
			return;
		}

		$series = get_the_terms( $product_id, self::SERIES_TAXONOMY );
		if ( ! is_array( $series ) ) {
			return;
		}
		foreach ( $series as $term ) {
			$term_id = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : 0;
			if ( $term_id > 0 ) {
				unset( $this->series_medium_memo[ $term_id ] );
				delete_transient( self::SERIES_MEDIUM_TRANSIENT_PREFIX . $term_id );
			}
		}
	}

	/**
	 * Resuelve el medio consultando el catálogo de un producto testigo.
	 *
	 * @param int $term_id Término de serie.
	 * @return string 'manga' | 'cómic' | 'manhwa' | ''.
	 */
	public function resolve_series_medium( int $term_id ): string {
		try {
			if ( $term_id < 1 || ! function_exists( 'get_posts' ) || ! function_exists( 'get_the_terms' ) ) {
				return '';
			}

			$ids = get_posts(
				array(
					'post_type'     => 'product',
					'post_status'   => 'publish',
					'numberposts'   => 1,
					'fields'        => 'ids',
					'no_found_rows' => true,
					'tax_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Un solo producto testigo.
						array(
							'taxonomy' => self::SERIES_TAXONOMY,
							'field'    => 'term_id',
							'terms'    => $term_id,
						),
					),
				)
			);
			if ( ! is_array( $ids ) || array() === $ids ) {
				return '';
			}

			$categories = get_the_terms( (int) $ids[0], 'product_cat' );
			if ( ! is_array( $categories ) ) {
				return '';
			}

			return self::medium_from_categories( $categories );
		} catch ( \Throwable $throwable ) {
			return '';
		}
	}

	/**
	 * Resuelve el medio subiendo cada categoría hasta su raíz real.
	 *
	 * @param array<int,object> $categories Términos de categoría.
	 * @return string 'manga' | 'cómic' | 'manhwa' | ''.
	 */
	public static function medium_from_categories( array $categories ): string {
		foreach ( $categories as $category ) {
			$guard = 0;
			while ( is_object( $category ) && (int) ( $category->parent ?? 0 ) > 0 && $guard++ < 6 ) {
				$parent = get_term( (int) $category->parent, 'product_cat' );
				if ( ! is_object( $parent ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $parent ) ) ) {
					break;
				}
				$category = $parent;
			}
			$medium = match ( is_object( $category ) ? (string) ( $category->slug ?? '' ) : '' ) {
				'manga'  => 'manga',
				'comics' => 'cómic',
				'manhwa' => 'manhwa',
				default  => '',
			};
			if ( '' !== $medium ) {
				return $medium;
			}
		}

		return '';
	}

	/**
	 * Compone una plantilla natural de serie sin señales comerciales volátiles.
	 *
	 * @param string $series_name Nombre de la serie.
	 * @param string $work_state  Estado de la obra.
	 * @return string
	 */
	public static function series_meta_description( string $series_name, string $work_state = '' ): string {
		$name = function_exists( 'strip_shortcodes' )
			? (string) strip_shortcodes( $series_name )
			: $series_name;
		$name = trim( wp_strip_all_tags( $name, true ) );
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = (string) str_replace( array( "\xc2\xa0", "\xe2\x80\x8b" ), ' ', $name );
		$name = preg_replace( '/\s+/u', ' ', $name );
		$name = is_string( $name ) ? trim( $name ) : '';
		if ( '' === $name ) {
			return '';
		}

		$clause = match ( PublicationStatus::sanitize_work_state( $work_state ) ) {
			PublicationStatus::COMPLETE  => 'La obra original está finalizada.',
			PublicationStatus::ONGOING   => 'La obra original sigue en publicación.',
			PublicationStatus::PAUSED    => 'La obra original está en pausa.',
			PublicationStatus::CANCELLED => 'La obra original fue cancelada.',
			default                      => 'Conoce la serie.',
		};

		$candidates = array(
			sprintf( '%s en Akibara Chile. %s Revisa el catálogo, los tomos a pedido y el envío según destino.', $name, $clause ),
			sprintf( '%s en Akibara Chile. %s Revisa catálogo, tomos a pedido y envío según destino.', $name, $clause ),
			sprintf( '%s en Akibara Chile. Revisa el catálogo, los tomos a pedido y el envío según destino.', $name ),
			'Series de manga y cómics en Akibara Chile. Revisa el catálogo, los tomos a pedido y el envío según destino.',
		);

		foreach ( $candidates as $candidate ) {
			if ( Text::length( $candidate ) <= 160 ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Compone título y descripción estructurados para una landing de serie.
	 *
	 * @param string $series_name    Nombre público del término.
	 * @param string $work_state     Estado de la obra original.
	 * @param int    $work_total     Total de tomos de la obra original.
	 * @param int    $catalog_count  Tomos en el catálogo.
	 * @param string $author         Autor único.
	 * @param string $brand          Editorial única.
	 * @param string $medium         Medio ('manga' | 'cómic' | 'manhwa').
	 * @param string $price_from     Precio base formateado.
	 * @param bool   $fully_buyable  Si la serie completa puede comprarse hoy.
	 * @param string $edition_state  Estado de la edición local.
	 * @param int    $edition_total  Total de tomos de la edición local.
	 * @return array{title:string,description:string}
	 */
	public static function compose_series_meta(
		string $series_name,
		string $work_state,
		int $work_total,
		int $catalog_count,
		string $author,
		string $brand,
		string $medium,
		string $price_from = '',
		bool $fully_buyable = false,
		string $edition_state = '',
		int $edition_total = 0
	): array {
		$name = function_exists( 'strip_shortcodes' )
			? (string) strip_shortcodes( $series_name )
			: $series_name;
		$name = trim( wp_strip_all_tags( $name, true ) );
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = (string) str_replace( array( "\xc2\xa0", "\xe2\x80\x8b" ), ' ', $name );
		$name = preg_replace( '/\s+/u', ' ', $name );
		$name = is_string( $name ) ? trim( $name ) : '';

		$empty = array(
			'title'       => '',
			'description' => '',
		);
		if ( '' === $name || ! in_array( $medium, self::SERIES_MEDIA, true ) ) {
			return $empty;
		}

		$state          = PublicationStatus::sanitize_work_state( $work_state );
		$work_total     = max( 0, $work_total );
		$catalog_count  = max( 0, $catalog_count );
		$work_complete  = PublicationStatus::COMPLETE === $state && $work_total > 0;
		$work_covered   = $work_complete && $catalog_count >= $work_total;
		$partial_sold   = $work_complete && ! $work_covered;
		$complete_sold  = $work_covered && $fully_buyable;

		$edition_state_clean = PublicationStatus::sanitize_edition_state( $edition_state );
		$edition_closed      = PublicationStatus::COMPLETE === $edition_state_clean && $edition_total > 0;
		$edition_covered     = $edition_closed && $catalog_count >= $edition_total;
		$edition_sold        = ! $complete_sold && $edition_covered && $fully_buyable;
		$edition_total_label = 1 === $edition_total ? '1 tomo' : sprintf( '%d tomos', $edition_total );
		$critical_state      = in_array( $state, array( PublicationStatus::PAUSED, PublicationStatus::CANCELLED ), true );
		$work_total_label    = 1 === $work_total ? '1 tomo' : sprintf( '%d tomos', $work_total );

		$title_candidates = self::build_series_title_candidates(
			$name,
			$medium,
			$complete_sold,
			$work_total_label,
			$edition_sold,
			$edition_total_label,
			$partial_sold,
			$catalog_count
		);

		$title = '';
		foreach ( $title_candidates as $candidate ) {
			if ( Text::length( $candidate ) <= 62 ) {
				$title = $candidate;
				break;
			}
		}

		$description_candidates = self::build_series_description_candidates(
			$state,
			$complete_sold,
			$partial_sold,
			$catalog_count,
			$work_total_label,
			$author,
			$name,
			$brand,
			$price_from,
			$critical_state
		);

		$description = '';
		foreach ( $description_candidates as $candidate ) {
			$candidate = trim( (string) preg_replace( '/\s+/u', ' ', $candidate ) );
			if ( Text::length( $candidate ) <= 155 ) {
				$description = $candidate;
				break;
			}
		}

		return array(
			'title'       => $title,
			'description' => $description,
		);
	}

	/**
	 * @return array<int, string>
	 */
	public static function build_series_title_candidates(
		string $name,
		string $medium,
		bool $complete_sold,
		string $work_total_label,
		bool $edition_sold,
		string $edition_total_label,
		bool $partial_sold,
		int $catalog_count
	): array {
		$candidates = array();
		if ( $complete_sold ) {
			$candidates[] = sprintf( '%s %s completo: %s | Akibara Chile', $name, $medium, $work_total_label );
			$candidates[] = sprintf( '%s: %s completo en %s | Akibara', $name, $medium, $work_total_label );
			$candidates[] = sprintf( '%s %s completo: %s | Akibara', $name, $medium, $work_total_label );
		} elseif ( $edition_sold ) {
			$candidates[] = sprintf( '%s %s: edición completa en %s | Akibara Chile', $name, $medium, $edition_total_label );
			$candidates[] = sprintf( '%s: edición completa en %s | Akibara Chile', $name, $edition_total_label );
			$candidates[] = sprintf( '%s: edición completa en %s | Akibara', $name, $edition_total_label );
		} elseif ( ! $partial_sold && $catalog_count > 1 ) {
			$candidates[] = sprintf( '%s %s: %d tomos en español | Akibara Chile', $name, $medium, $catalog_count );
			$candidates[] = sprintf( '%s %s en español: %d tomos | Akibara', $name, $medium, $catalog_count );
			$candidates[] = sprintf( '%s %s: %d tomos | Akibara', $name, $medium, $catalog_count );
		}
		$candidates[] = sprintf( '%s %s en español | Comprar en Akibara Chile', $name, $medium );
		$candidates[] = sprintf( '%s %s en español | Akibara Chile', $name, $medium );
		$candidates[] = sprintf( '%s %s | Akibara Chile', $name, $medium );
		$candidates[] = sprintf( '%s | Akibara', $name );

		return $candidates;
	}

	/**
	 * @return array<int, string>
	 */
	public static function build_series_description_candidates(
		string $state,
		bool $complete_sold,
		bool $partial_sold,
		int $catalog_count,
		string $work_total_label,
		string $author,
		string $name,
		string $brand,
		string $price_from,
		bool $critical_state
	): array {
		$state_phrase = match ( $state ) {
			PublicationStatus::COMPLETE  => 'Serie finalizada.',
			PublicationStatus::ONGOING   => 'Serie en publicación.',
			PublicationStatus::PAUSED    => 'Publicación en pausa.',
			PublicationStatus::CANCELLED => 'Serie cancelada.',
			default                      => '',
		};

		if ( $complete_sold ) {
			$core = sprintf( 'serie completa en %s', $work_total_label );
		} elseif ( $partial_sold ) {
			$core = sprintf( 'obra completa en %s', $work_total_label );
		} elseif ( 1 === $catalog_count ) {
			$core = '1 tomo catalogado';
		} elseif ( $catalog_count > 1 ) {
			$core = sprintf( '%d tomos catalogados', $catalog_count );
		} else {
			$core = 'tomos en el catálogo';
		}

		$subject = '' !== $author ? sprintf( '%s de %s', $name, $author ) : $name;

		$closes = array();
		if ( '' !== $price_from ) {
			$closes[] = array(
				'long'  => sprintf( 'Desde %s en Akibara, con despacho a todo Chile.', $price_from ),
				'short' => sprintf( 'Desde %s. Despacho a todo Chile.', $price_from ),
				'local' => sprintf( 'desde %s en Akibara Chile.', $price_from ),
			);
		}
		$closes[] = array(
			'long'  => 'Cómpralo en Akibara con despacho a todo Chile.',
			'short' => 'Despacho a todo Chile.',
			'local' => 'en Akibara Chile.',
		);

		$candidates = array();
		foreach ( $closes as $close ) {
			$long_close  = $close['long'];
			$short_close = $close['short'];
			$local_close = $close['local'];
			if ( $partial_sold ) {
				$catalogued = 1 === $catalog_count
					? '1 tomo catalogado'
					: ( $catalog_count > 1 ? sprintf( '%d tomos catalogados', $catalog_count ) : 'Tomos en el catálogo' );
				if ( '' !== $brand ) {
					$candidates[] = sprintf( '%s: %s. %s de la edición %s %s', $subject, $core, ucfirst( $catalogued ), $brand, $local_close );
				}
				$candidates[] = sprintf( '%s: %s. %s %s', $subject, $core, ucfirst( $catalogued ), $local_close );
				$candidates[] = sprintf( '%s: %s. %s en Akibara.', $name, $core, ucfirst( $catalogued ) );
			} elseif ( $critical_state ) {
				if ( '' !== $brand ) {
					$candidates[] = sprintf( '%s: %s de la edición %s. %s %s', $subject, $core, $brand, $state_phrase, $long_close );
				}
				$candidates[] = sprintf( '%s: %s en español. %s %s', $subject, $core, $state_phrase, $long_close );
				$candidates[] = sprintf( '%s: %s. %s %s', $subject, $core, $state_phrase, $short_close );
				$candidates[] = sprintf( '%s: %s en español. %s %s', $name, $core, $state_phrase, $short_close );
			} else {
				if ( '' !== $brand ) {
					$candidates[] = sprintf( '%s: %s de la edición %s en español. %s', $subject, $core, $brand, $long_close );
					$candidates[] = sprintf( '%s: %s, edición %s. %s', $subject, $core, $brand, $long_close );
					$candidates[] = sprintf( '%s: %s, edición %s. %s', $subject, $core, $brand, $short_close );
				}
				$candidates[] = trim( sprintf( '%s: %s en español. %s %s', $subject, $core, $state_phrase, $long_close ) );
				$candidates[] = sprintf( '%s: %s en español. %s', $subject, $core, $long_close );
				$candidates[] = sprintf( '%s: %s en español. %s', $subject, $core, $short_close );
				$candidates[] = sprintf( '%s: %s en español. %s', $name, $core, $short_close );
			}
		}

		return $candidates;
	}

	/**
	 * Resuelve la descripción de una serie para manifiestos externos (llms.txt).
	 *
	 * @param object $term Término de serie.
	 * @param bool   $is_generated Si la fila fue generada automáticamente.
	 * @return string
	 */
	public function series_manifest_description( object $term, bool $is_generated ): string {
		$term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;
		if ( $term_id < 1 ) {
			return '';
		}

		$stored = trim( (string) get_term_meta( $term_id, 'rank_math_description', true ) );
		if ( '' !== $stored && ! $is_generated ) {
			return $stored;
		}

		$meta = $this->generated_series_meta( $term );
		if ( null !== $meta && '' !== $meta['description'] ) {
			return $meta['description'];
		}

		return $stored;
	}
}
