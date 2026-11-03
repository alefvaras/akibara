<?php
/**
 * SchemaProductRankMath — propietario único del Product JSON-LD en Rank Math.
 *
 * Rank Math arma el producto desde WooCommerce y suprime el nodo nativo de WC.
 * Este módulo agrega únicamente el conocimiento propio de Akibara que Rank Math
 * no puede inferir: preventa y semántica editorial Book.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SchemaProductRankMath;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\SchemaGraph;
use Akibara\Business\Utilities\Text;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class SchemaProductRankMath {

	public const ENABLED_FILTER = 'akibara_schema_rankmath_product_enabled';
	private const DESCRIPTION_MAX_LENGTH = 500;

	private BookSchemaEnricher $book_schema;
	private ?bool $rank_math_active;

	public function __construct(
		?BookSchemaEnricher $book_schema = null,
		?bool $rank_math_active = null
	) {
		$this->book_schema      = $book_schema ?? new BookSchemaEnricher();
		$this->rank_math_active = $rank_math_active;
	}

	public function register(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( ! $this->is_rank_math_active() ) {
			// Fallback seguro: WooCommerce conserva su Product como dueño y
			// Akibara agrega los mismos datos first-party sin suprimir el nodo.
			add_filter( 'woocommerce_structured_data_product', array( $this, 'augment_woocommerce_entity' ), 20, 2 );
			return;
		}

		add_filter( 'rank_math/snippet/rich_snippet_product_entity', array( $this, 'augment_entity' ), 20 );
		add_filter( 'rank_math/json_ld', array( $this, 'restore_book_series_relationship' ), SchemaGraph::PRODUCT_RELATIONSHIPS, 2 );
		add_filter(
			'rank_math/opengraph/facebook/product_availability',
			array( $this, 'filter_facebook_product_availability' ),
			99
		);

		// Un solo dueño del nodo Product: con RankMath emitiendo, el de WC core
		// se suprime (array vacío falla la validación de @type en set_data y el
		// nodo se descarta). Mismo patrón que el BreadcrumbList en
		// SeoEnhancements. Los demás tipos de WC no usan este filtro.
		add_filter( 'woocommerce_structured_data_product', '__return_empty_array', 999 );
	}

	/**
	 * Evita declarar una preventa como `instock` en Open Graph.
	 *
	 * WooCommerce considera `onbackorder` como `is_in_stock() = true`; Rank Math
	 * reutiliza ese predicado y emite `product:availability=instock`. Para una
	 * preventa esa afirmación contradice el Product JSON-LD. El filtro dinámico
	 * oficial de Rank Math permite omitir únicamente la etiqueta incorrecta,
	 * conservándola intacta para el stock real.
	 *
	 * @param mixed $availability Valor calculado por Rank Math.
	 */
	public function filter_facebook_product_availability( $availability ): string {
		if ( ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
			return is_scalar( $availability ) ? (string) $availability : '';
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
		if ( $product instanceof WC_Product && StockStatus::is_reservable( $product ) ) {
			return '';
		}

		return is_scalar( $availability ) ? (string) $availability : '';
	}

	public function is_enabled(): bool {
		return (bool) apply_filters( self::ENABLED_FILTER, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::ENABLED_FILTER vale «akibara_schema_rankmath_product_enabled»: sí lleva prefijo, el sniff no resuelve la constante.
	}

	/**
	 * Replica sobre la entity de RankMath la lógica first-party que hoy vive en
	 * los filtros de WC core: preventa y schema Book.
	 *
	 * @param array<string,mixed> $entity Nodo Product armado por RankMath.
	 * @return array<string,mixed>
	 */
	public function augment_entity( array $entity ): array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
		if ( ! $product instanceof WC_Product ) {
			return $entity;
		}

		$product_name = trim( wp_strip_all_tags( (string) $product->get_name(), true ) );
		if ( '' !== $product_name ) {
			// Rank Math reuses the SEO title here. Product schema must match the
			// visible catalog/H1 name; brand and seller already have own fields.
			$entity['name'] = $product_name;
		}

		$entity = $this->augment_product_entity( $entity, $product );

		// Rank Math puede heredar URLs legacy http://schema.org en Offer. Se
		// normalizan recursivamente solo valores del vocabulario Schema.org;
		// URLs comerciales u otros strings permanecen byte a byte.
		return self::normalize_schema_org_urls( $entity );
	}

	/**
	 * Restores an eligible ComicSeries after Rank Math connects its graph in
	 * priority 99, or removes an upstream ComicSeries assertion when the catalog
	 * term is explicitly classified as non-comic.
	 *
	 * This final official filter mutates only the existing book Product entity:
	 * it never creates another Product and preserves `mainEntityOfPage`, `@id`
	 * and every commercial property added by Rank Math.
	 *
	 * @internal Callback del hook `rank_math/json_ld`.
	 *
	 * @param array<string,mixed> $schemas Rank Math JSON-LD graph.
	 * @param mixed               $jsonld  Rank Math JSON-LD instance, unused.
	 * @return array<string,mixed>
	 *
	 * @since 1.93.1
	 */
	public function restore_book_series_relationship( array $schemas, $jsonld = null ): array {
		unset( $jsonld );

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
		if ( ! $product instanceof WC_Product ) {
			return $schemas;
		}

		foreach ( $schemas as $key => $entity ) {
			if ( ! is_array( $entity ) || ! self::entity_has_type( $entity, 'Product' ) ) {
				continue;
			}

			$is_book = self::entity_has_type( $entity, 'Book' )
				|| 'https://schema.org/Book' === ( $entity['additionalType'] ?? null );
			if ( ! $is_book ) {
				continue;
			}

			$entity          = $this->book_schema->restore_series_relationship( $entity, $product );
			$schemas[ $key ] = self::promote_book_multitype( $entity );
		}

		return $schemas;
	}

	/**
	 * Enriquece el Product nativo de WooCommerce cuando Rank Math no está activo.
	 *
	 * @internal Callback del hook `woocommerce_structured_data_product`.
	 *
	 * @param array<string,mixed> $entity  Nodo Product armado por WooCommerce.
	 * @param mixed               $product Producto asociado al nodo.
	 * @return array<string,mixed>
	 */
	public function augment_woocommerce_entity( array $entity, $product = null ): array {
		if ( ! $product instanceof WC_Product ) {
			return $entity;
		}

		return $this->augment_product_entity( $entity, $product );
	}

	/**
	 * Aplica el conocimiento first-party al nodo Product del emisor disponible.
	 *
	 * @param array<string,mixed> $entity  Nodo Product.
	 * @param WC_Product          $product Producto asociado al nodo.
	 * @return array<string,mixed>
	 */
	private function augment_product_entity( array $entity, WC_Product $product ): array {
		$entity = $this->apply_preventa( $entity, $product );
		$entity = $this->sanitize_simple_product_offers( $entity, $product );
		$entity = $this->book_schema->enrich( $entity, $product );

		return self::replace_product_description( $entity, $product );
	}

	/**
	 * Reemplaza la descripción heredada del emisor por hechos del producto.
	 *
	 * Rank Math puede poblar `description` desde la plantilla SEO global, que es
	 * compartida por todo el catálogo y puede contener afirmaciones comerciales.
	 * El Product schema usa en cambio el nombre visible y, cuando BookSchemaEnricher
	 * los acreditó, la posición dentro de ComicSeries y todos sus autores. No se
	 * publican precio, stock, envío, CTA, nombre de la tienda ni editorial.
	 *
	 * Cada cláusula entra completa o se omite: el límite defensivo nunca corta una
	 * palabra, un nombre propio ni una lista de autores. Sin nombre no hay una
	 * descripción factual posible y se elimina cualquier valor heredado.
	 *
	 * @param array<string,mixed> $entity  Nodo Product ya enriquecido.
	 * @param WC_Product          $product Producto asociado al nodo.
	 * @return array<string,mixed>
	 */
	private static function replace_product_description( array $entity, WC_Product $product ): array {
		$name = is_scalar( $entity['name'] ?? null )
			? self::sanitize_description_component( (string) $entity['name'] )
			: '';
		if ( '' === $name && is_callable( array( $product, 'get_name' ) ) ) {
			$name = self::sanitize_description_component( (string) $product->get_name() );
		}

		if ( '' === $name ) {
			unset( $entity['description'] );
			return $entity;
		}

		$description = self::complete_sentence( $name );
		if ( Text::length( $description ) > self::DESCRIPTION_MAX_LENGTH ) {
			unset( $entity['description'] );
			return $entity;
		}

		$clauses = array();
		$is_book = self::entity_has_type( $entity, 'Book' )
			|| 'https://schema.org/Book' === ( $entity['additionalType'] ?? null );
		if ( $is_book ) {
			$series = self::comic_series_name( $entity['isPartOf'] ?? null );
			$volume = self::positive_integer( $entity['position'] ?? null );
			if ( '' !== $series ) {
				if ( null !== $volume ) {
					$clauses[] = sprintf( 'Tomo %d de la serie %s.', $volume, $series );
				} elseif ( ! self::same_description_text( $name, $series ) ) {
					$clauses[] = sprintf( 'Serie: %s.', $series );
				}
			}

			$authors = self::author_names( $entity['author'] ?? null );
			if ( array() !== $authors ) {
				$clauses[] = 'Autoría: ' . self::join_names( $authors ) . '.';
			}
		}

		foreach ( $clauses as $clause ) {
			$candidate = $description . ' ' . $clause;
			if ( Text::length( $candidate ) <= self::DESCRIPTION_MAX_LENGTH ) {
				$description = $candidate;
			}
		}

		$entity['description'] = $description;

		return $entity;
	}

	/**
	 * Devuelve el nombre únicamente de una relación ComicSeries verificable.
	 *
	 * @param mixed $relationship Relación `isPartOf` del nodo.
	 */
	private static function comic_series_name( $relationship ): string {
		if ( ! is_array( $relationship ) ) {
			return '';
		}

		$types = $relationship['@type'] ?? array();
		$types = is_array( $types ) ? $types : array( $types );
		if ( ! in_array( 'ComicSeries', $types, true ) || ! is_scalar( $relationship['name'] ?? null ) ) {
			return '';
		}

		return self::sanitize_description_component( (string) $relationship['name'] );
	}

	/**
	 * Extrae todos los autores nombrados, en el orden estable del enricher.
	 *
	 * @param mixed $authors Un Person, una lista de Person o una forma escalar.
	 * @return string[]
	 */
	private static function author_names( $authors ): array {
		if ( null === $authors || '' === $authors || array() === $authors ) {
			return array();
		}

		if ( is_scalar( $authors ) ) {
			$authors = array( $authors );
		} elseif ( is_array( $authors ) && array_key_exists( 'name', $authors ) ) {
			$authors = array( $authors );
		} elseif ( ! is_array( $authors ) ) {
			return array();
		}

		$names = array();
		$seen  = array();
		foreach ( $authors as $author ) {
			if ( is_scalar( $author ) ) {
				$name = self::sanitize_description_component( (string) $author );
			} elseif ( is_array( $author ) && is_scalar( $author['name'] ?? null ) ) {
				$name = self::sanitize_description_component( (string) $author['name'] );
			} else {
				continue;
			}

			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
			if ( '' === $name || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$names[]      = $name;
		}

		return $names;
	}

	/**
	 * Une nombres completos sin truncar la lista.
	 *
	 * @param string[] $names Nombres ya saneados.
	 */
	private static function join_names( array $names ): string {
		if ( 1 === count( $names ) ) {
			return $names[0];
		}

		$last = array_pop( $names );

		return implode( ', ', $names ) . ' y ' . $last;
	}

	/**
	 * Normaliza una pieza visible sin ejecutar contenido ni conservar markup.
	 */
	private static function sanitize_description_component( string $value ): string {
		if ( function_exists( 'strip_shortcodes' ) ) {
			$value = (string) strip_shortcodes( $value );
		}
		$value = self::separate_block_markup( $value );
		$value = wp_strip_all_tags( $value, true );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Shortcodes y etiquetas codificados como entidades sólo se vuelven activos
		// después del decode; se retiran de nuevo antes de publicar JSON-LD.
		if ( function_exists( 'strip_shortcodes' ) ) {
			$value = (string) strip_shortcodes( $value );
		}
		$value = self::separate_block_markup( $value );
		$value = wp_strip_all_tags( $value, true );
		$value = str_replace(
			array( "\xc2\xa0", "\xe2\x80\x8b", "\xe2\x80\x8c", "\xe2\x80\x8d", "\xe2\x81\xa0", "\xef\xbb\xbf" ),
			' ',
			$value
		);
		$value = preg_replace( '/\s+/u', ' ', $value );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Conserva el límite de palabra de markup de bloque sin tocar tags inline.
	 */
	private static function separate_block_markup( string $value ): string {
		$pattern = '/<\/?(?:address|article|aside|blockquote|br|div|figcaption|figure|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|tbody|td|tfoot|th|thead|tr|ul)\b[^>]*>/iu';
		$value   = preg_replace( $pattern, ' ', $value );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Compara texto visible con normalización Unicode cuando está disponible.
	 */
	private static function same_description_text( string $left, string $right ): bool {
		if ( class_exists( '\\Normalizer' ) ) {
			$normalized_left  = \Normalizer::normalize( $left, \Normalizer::FORM_KC );
			$normalized_right = \Normalizer::normalize( $right, \Normalizer::FORM_KC );
			$left             = is_string( $normalized_left ) ? $normalized_left : $left;
			$right            = is_string( $normalized_right ) ? $normalized_right : $right;
		}

		$left  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $left, 'UTF-8' ) : strtolower( $left );
		$right = function_exists( 'mb_strtolower' ) ? mb_strtolower( $right, 'UTF-8' ) : strtolower( $right );

		return $left === $right;
	}

	/**
	 * Añade puntuación solo cuando el texto aún no termina una oración.
	 */
	private static function complete_sentence( string $value ): string {
		return 1 === preg_match( '/[.!?…]$/u', $value ) ? $value : $value . '.';
	}

	/**
	 * Acepta solo posiciones enteras positivas, nunca casts parciales.
	 *
	 * @param mixed $value Posición candidata.
	 */
	private static function positive_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9]\d*$/D', $value ) ) {
			return null;
		}

		$integer = (int) $value;

		return $integer > 0 ? $integer : null;
	}

	/**
	 *  (): desde 1.109.0 `availability` sigue el estado
	 * COMERCIAL derivado, NO «todo onbackorder» — ese fue el bug histórico que
	 * el propio código de abajo describe. PreOrder solo en preventa (lanzamiento
	 * pendiente); BackOrder en a pedido (ya publicado). `availabilityStarts`
	 * solo se añade en modo preventa con fecha explícita válida; la ETA
	 * automática no representa una fecha oficial de disponibilidad ni un
	 * vencimiento del precio.
	 *
	 * @param array<string,mixed> $entity Nodo Product.
	 * @return array<string,mixed>
	 */
	private function apply_preventa( array $entity, WC_Product $product ): array {
		if ( ! StockStatus::is_reservable( $product ) ) {
			return $entity;
		}
		if ( ! isset( $entity['offers'] ) || ! is_array( $entity['offers'] ) ) {
			return $entity;
		}

		// Separación 1.109.0: el `availability` sigue al estado COMERCIAL.
		//   - Preventa (lanzamiento pendiente)   → schema.org/PreOrder.
		//   - A pedido (ya publicado, se trae)   → schema.org/BackOrder.
		// Antes TODO onbackorder emitía PreOrder, y una fecha vencida seguía
		// saliendo como `availabilityStarts` en el pasado hacia Google. Con el
		// estado derivado eso es imposible: fecha pasada ⇒ A_PEDIDO ⇒ BackOrder
		// sin availabilityStarts.
		$is_preventa  = Availability::is_preventa( $product );
		$availability = $is_preventa ? 'https://schema.org/PreOrder' : 'https://schema.org/BackOrder';

		$release_date     = (string) $product->get_meta( '_akb_eta_release_date' );
		// Misma regla que las dos puertas de captura: una fecha con el año mal
		// tecleado no sale a Google como `availabilityStarts`.
		$has_release_date = $is_preventa && Availability::is_valid_release_date( $release_date );

		// Rank Math usa un Offer asociativo; WooCommerce core envuelve el suyo
		// en una lista. El fallback debe respetar ambas estructuras.
		if ( isset( $entity['offers'][0] ) && is_array( $entity['offers'][0] ) ) {
			foreach ( $entity['offers'] as $index => $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}
				$entity['offers'][ $index ] = self::mark_offer_as_reservable( $offer, $availability, $release_date, $has_release_date );
			}
		} else {
			$entity['offers'] = self::mark_offer_as_reservable( $entity['offers'], $availability, $release_date, $has_release_date );
		}

		return $entity;
	}

	/**
	 * Aplica la semántica de reserva (preventa o a pedido) a un Offer.
	 *
	 * @param array<string,mixed> $offer            Nodo Offer.
	 * @param string              $availability     URL schema.org resuelta por estado.
	 * @param string              $release_date     Fecha configurada.
	 * @param bool                $has_release_date Si la fecha es publicable (solo preventa).
	 * @return array<string,mixed>
	 */
	private static function mark_offer_as_reservable( array $offer, string $availability, string $release_date, bool $has_release_date ): array {
		$offer['availability'] = $availability;
		if ( $has_release_date ) {
			$offer['availabilityStarts'] = $release_date;
		}

		return $offer;
	}

	/**
	 * Retira hechos comerciales sintéticos de Offers de productos simples.
	 *
	 * @param array<string,mixed> $entity  Nodo Product.
	 * @param WC_Product          $product Producto asociado al nodo.
	 * @return array<string,mixed>
	 */
	private function sanitize_simple_product_offers( array $entity, WC_Product $product ): array {
		if ( ! $product->is_type( 'simple' ) || ! isset( $entity['offers'] ) || ! is_array( $entity['offers'] ) ) {
			return $entity;
		}

		$has_future_sale_end = $this->has_future_sale_end( $product );

		if ( isset( $entity['offers'][0] ) && is_array( $entity['offers'][0] ) ) {
			foreach ( $entity['offers'] as $index => $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}

				$entity['offers'][ $index ] = $this->sanitize_simple_product_offer( $offer, $has_future_sale_end );
			}
		} else {
			$entity['offers'] = $this->sanitize_simple_product_offer( $entity['offers'], $has_future_sale_end );
		}

		return $entity;
	}

	/**
	 * Sanea un Offer sin inventar fecha de precio ni vendedor.
	 *
	 * @param array<string,mixed> $offer              Nodo Offer.
	 * @param bool                $has_future_sale_end Si WooCommerce tiene fin de oferta futuro.
	 * @return array<string,mixed>
	 */
	private function sanitize_simple_product_offer( array $offer, bool $has_future_sale_end ): array {
		if ( ! $has_future_sale_end ) {
			unset( $offer['priceValidUntil'] );
		}

		if ( isset( $offer['seller'] ) && is_array( $offer['seller'] ) ) {
			$offer['seller'] = $this->normalize_own_organization_seller( $offer['seller'] );
		}

		return $offer;
	}

	/**
	 * Comprueba que WooCommerce tenga una fecha real y futura de fin de oferta.
	 */
	private function has_future_sale_end( WC_Product $product ): bool {
		$sale_end = $product->get_date_on_sale_to();

		return $sale_end instanceof \DateTimeInterface && $sale_end->getTimestamp() > time();
	}

	/**
	 * Apunta una Organization propia existente al nodo canónico de la tienda.
	 *
	 * @param array<string,mixed> $seller Nodo seller existente.
	 * @return array<string,mixed>
	 */
	private function normalize_own_organization_seller( array $seller ): array {
		$types = is_array( $seller['@type'] ?? null )
			? $seller['@type']
			: array( $seller['@type'] ?? null );

		$organization_types = array(
			'Organization',
			'http://schema.org/Organization',
			'https://schema.org/Organization',
		);

		if ( empty( array_intersect( $organization_types, $types ) ) ) {
			return $seller;
		}

		$home_url      = rtrim( home_url( '/' ), '/' );
		$canonical_id  = home_url( '/#organization' );
		$seller_id     = is_scalar( $seller['@id'] ?? null ) ? rtrim( (string) $seller['@id'], '/' ) : '';
		$seller_url    = is_scalar( $seller['url'] ?? null ) ? rtrim( (string) $seller['url'], '/' ) : '';
		$is_own_seller = '' !== $seller_id
			? in_array( $seller_id, array( $home_url, rtrim( $canonical_id, '/' ) ), true )
			: $home_url === $seller_url;

		if ( $is_own_seller ) {
			$seller['@id'] = $canonical_id;
		}

		return $seller;
	}

	/**
	 * Normaliza recursivamente URLs del vocabulario Schema.org.
	 *
	 * @param mixed $value Nodo o valor de la entity.
	 * @return mixed
	 */
	private static function normalize_schema_org_urls( $value ) {
		if ( is_string( $value ) ) {
			return 0 === strpos( $value, 'http://schema.org/' )
				? 'https://schema.org/' . substr( $value, strlen( 'http://schema.org/' ) )
				: $value;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::normalize_schema_org_urls( $item );
		}

		return $value;
	}

	/**
	 * Promotes a Rank Math Product after its graph connector has finished.
	 *
	 * Keeping scalar `Product` through priority 99 lets Rank Math recognize the
	 * commercial entity and change WebPage to ItemPage. The native WooCommerce
	 * fallback never calls this method and retains Product + additionalType.
	 *
	 * @param array<string,mixed> $entity Product entity.
	 * @return array<string,mixed>
	 */
	private static function promote_book_multitype( array $entity ): array {
		$types = isset( $entity['@type'] ) && is_array( $entity['@type'] )
			? $entity['@type']
			: array( $entity['@type'] ?? 'Product' );

		$entity['@type'] = array_values( array_unique( array_merge( array( 'Product', 'Book' ), $types ) ) );
		unset( $entity['additionalType'] );

		return $entity;
	}

	/**
	 * Checks a scalar or multi-type schema entity.
	 *
	 * @param array<string,mixed> $entity Schema entity.
	 */
	private static function entity_has_type( array $entity, string $type ): bool {
		$types = $entity['@type'] ?? array();
		$types = is_array( $types ) ? $types : array( $types );

		return in_array( $type, $types, true );
	}

	/**
	 * Detecta Rank Math en runtime o usa el valor inyectado en pruebas.
	 */
	private function is_rank_math_active(): bool {
		return $this->rank_math_active ?? defined( 'RANK_MATH_VERSION' );
	}
}
