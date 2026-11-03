<?php
/**
 * DocumentBuilder — WC_Product → filas del índice (doc + tokens + facetas).
 *
 * Materializa TODO lo derivado en tiempo de indexado para que el query-time
 * sea barato en DB fría (Hostinger sin object cache):
 *   - texto normalizado (título/serie/autores) — el matching jamás normaliza
 *     en SQL;
 *   - ancestros jerárquicos de product_cat: un producto en una subcategoría
 *     (ej. "shonen") genera fila también para su ancestro ("manga"), garantizando
 *     conteos exactos en archives padres;
 *   - precio efectivo vía filtro `akb_search_effective_price` (akibara-business
 *     aplica descuento editorial si está vigente; sin el módulo, precio base WC);
 *   - tiers de oferta multi-tier calculados sobre el precio efectivo (cubre
 *     tanto rebajas directas como descuentos editoriales por campaña).
 *
 * Los rangos de `precio` se evalúan en tiempo de consulta sobre `value_num`.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class DocumentBuilder {

	/** Fuente compartida para variaciones; Indexer/Reconciler la instalan. */
	private ?ProductSourceAuthority $source_authority = null;

	/** Campos de token — el peso vive en el Scorer (fase motor), acá solo el origen. */
	public const FIELD_TITLE       = 1;
	public const FIELD_SERIE       = 2;
	public const FIELD_AUTOR       = 3;
	public const FIELD_SKU         = 4;
	public const FIELD_TAXONOMY    = 5;
	public const FIELD_DESCRIPTION = 6;

	/**
	 * Cap de tokens de descripción por producto — red de recall de peso bajo;
	 * si el rebuild muestra inflado >100k filas totales se recorta (decisión
	 * reversible y MEDIDA, informe §4.1).
	 */
	public const MAX_DESCRIPTION_TOKENS = 80;

	/**
	 * Cap de variaciones cuyo SKU se tokeniza en el doc del PADRE. Cada hijo
	 * cuesta un `wc_get_product` y hasta 2 filas de token, y un variable con
	 * decenas de variaciones no debe poder inflar el índice ni el tiempo de
	 * rebuild. Hoy el catálogo es 100% simple, así que el cap nunca se toca.
	 */
	public const MAX_VARIATION_SKUS = 50;

	/**
	 * Mapeo canónico editorial -> slug de país en pa_pais para inferencia determinista.
	 */
	public const BRAND_COUNTRY_MAP = array(
		'ivrea-argentina'  => 'argentina',
		'panini-argentina' => 'argentina',
		'ovni-press'       => 'argentina',
		'planeta-espana'   => 'espana',
		'milky-way'        => 'espana',
		'ivrea-espana'     => 'espana',
		'panini-espana'    => 'espana',
		'arechi-manga'     => 'espana',
		'distrito-manga'   => 'espana',
	);

	/**
	 * Tamaño de la miniatura materializada en `docs.thumb_url`.
	 *
	 * `thumbnail` es el subsize registrado de WordPress (150×150, recortado),
	 * NO el alias de WooCommerce — `wc_get_image_size('thumbnail')` devuelve 350
	 * porque Woo lo mapea a `woocommerce_thumbnail`. El panel del autocomplete
	 * pinta esa imagen en una caja de 40×40 con `object-fit: cover`, así que el
	 * recorte cuadrado es indistinguible del retrato: se servían portadas de
	 * 350×525 (29,5 KB de media, 7 por panel = 220 KB medidos) para 40 px.
	 *
	 * ProductPresenter pide EXACTAMENTE este tamaño en el camino vivo: el
	 * Reconciler compara `thumb_url` campo a campo contra el índice y cualquier
	 * divergencia entre los dos lados marca todos los docs como desalineados.
	 */
	public const THUMB_SIZE = 'thumbnail';

	/** Mapa stock_status → rank ordenable (ASC = mejor disponibilidad primero). */
	private const STOCK_RANK = array(
		'instock'     => 0,
		'onbackorder' => 1,
		'outofstock'  => 2,
	);

	/** Instala la misma autoridad sticky que gobierna el producto padre. */
	public function set_source_authority( ProductSourceAuthority $source_authority ): void {
		$this->source_authority = $source_authority;
	}

	/**
	 * Construye las filas del índice para un producto.
	 *
	 * @param \WC_Product $product Producto (publish).
	 * @return array{doc: array<string, mixed>, tokens: array<int, array{token: string, field: int}>, facets: array<int, array<string, mixed>>}
	 * @throws \RuntimeException Cuando una fuente derivada no puede leerse íntegramente.
	 */
	public function build( \WC_Product $product ): array {
		$this->read_source( static fn() => FacetRegistry::definitions(), 'Facet registry source is unreadable.' );
		$this->require_facet_registry_authority();

		$post_id = $this->read_source( static fn() => $product->get_id(), 'Product identity source is unreadable.' );
		if ( ! is_int( $post_id ) || $post_id <= 0 ) {
			throw new \RuntimeException( 'Product identity source has an invalid shape.' );
		}
		$title = $this->read_source( static fn() => $product->get_name(), 'Product title source is unreadable.' );
		if ( ! is_string( $title ) || '' === trim( $title ) ) {
			throw new \RuntimeException( 'Product title source has an invalid shape.' );
		}
		$this->verify_post_field( $post_id, 'post_title', $title );
		$status = $this->read_source( static fn() => $product->get_stock_status(), 'Product stock source is unreadable.' );
		if ( ! is_string( $status ) || ! array_key_exists( $status, self::STOCK_RANK ) ) {
			throw new \RuntimeException( 'Product stock source has an invalid shape.' );
		}
		$is_variable = $this->read_source( static fn() => $product->is_type( 'variable' ), 'Product type source is unreadable.' );
		if ( ! is_bool( $is_variable ) ) {
			throw new \RuntimeException( 'Product type source has an invalid shape.' );
		}
		if ( $is_variable ) {
			if ( null === $this->source_authority
				|| ! $this->source_authority->prepare_variable_price_source( $post_id ) ) {
				$error = null === $this->source_authority ? '' : $this->source_authority->last_error();
				throw new \RuntimeException( $error ?: 'Variable price source authority is unavailable.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
			}
		}

		$terms_by_tax = $this->collect_terms( $post_id );
		$this->require_facet_registry_authority();

		$materialization = $this->editorial_materialization( $product );
		$price_effective = $materialization['price_effective'];
		$regular         = $this->regular_price( $product, $is_variable );

		$raw_sku = $this->read_source( static fn() => $product->get_sku(), 'Product SKU source is unreadable.' );
		if ( ! is_string( $raw_sku ) ) {
			throw new \RuntimeException( 'Product SKU source has an invalid shape.' );
		}
		$menu_order = $this->read_source( static fn() => $product->get_menu_order(), 'Product menu order source is unreadable.' );
		if ( ! is_int( $menu_order ) ) {
			throw new \RuntimeException( 'Product menu order source has an invalid shape.' );
		}
		$this->verify_post_field( $post_id, 'menu_order', $menu_order );
		$visible = $this->read_source( static fn() => $product->is_visible(), 'Product visibility source is unreadable.' );
		if ( ! is_bool( $visible ) ) {
			throw new \RuntimeException( 'Product visibility source has an invalid shape.' );
		}
		$product_status = $this->read_source( static fn() => $product->get_status(), 'Product status source is unreadable.' );
		if ( ! is_string( $product_status ) || 'publish' !== $product_status ) {
			throw new \RuntimeException( 'Product status source has an invalid shape.' );
		}
		$permalink = $this->read_source( static fn() => get_permalink( $post_id ), 'Product permalink source is unreadable.' );
		if ( ! is_string( $permalink ) || '' === trim( $permalink ) ) {
			throw new \RuntimeException( 'Product permalink source has an invalid shape.' );
		}
		$indexed_at = $this->read_source( static fn() => current_time( 'mysql', true ), 'Index timestamp source is unreadable.' );
		if ( ! is_string( $indexed_at ) || '' === trim( $indexed_at ) ) {
			throw new \RuntimeException( 'Index timestamp source has an invalid shape.' );
		}
		$sku = $this->truncate( strtolower( $raw_sku ), 64 );

		$doc = array(
			'post_id'         => $post_id,
			'title'           => $title,
			'title_norm'      => $this->truncate( Normalizer::normalize( $title ), 255 ),
			'serie_norm'      => $this->truncate( Normalizer::normalize( $this->term_names( $terms_by_tax, 'pa_serie' ) ), 255 ),
			'autores_norm'    => $this->truncate( Normalizer::normalize( $this->term_names( $terms_by_tax, 'pa_autor' ) ), 255 ),
			'sku'             => $sku,
			// Normalización persistida (sin guiones ni espacios) para habilitar búsqueda
			// directa en fast-path ISBN/SKU mediante el índice `idx_sku_norm` sin funciones sobre columna.
			'sku_norm'        => str_replace( array( '-', ' ' ), '', $sku ),
			'tomo'            => Normalizer::tomo_from_title( $title ),
			'stock_rank'      => self::STOCK_RANK[ $status ] ?? 2,
			'menu_order'      => $menu_order,
			'price_effective' => $price_effective,
			'visible'         => $visible ? 1 : 0,
			'url'             => $this->truncate( $permalink, 255 ),
			'thumb_url'       => $this->truncate( $this->thumb_url( $product ), 255 ),
			'price_html'      => $materialization['price_html'],
			'indexed_at'      => $indexed_at,
		);

		$tokens = $this->build_tokens( $product, $doc, $terms_by_tax, $is_variable );
		$facets = $this->build_facets( $product, $post_id, $status, $price_effective, $regular, $terms_by_tax );
		$this->require_facet_registry_authority();

		return array(
			'doc'    => $doc,
			'tokens' => $tokens,
			'facets' => $facets,
		);
	}

	/**
	 * Los DOS campos del documento que la configuración editorial puede mover.
	 *
	 * `build()` los toma de aquí, así que el índice y quien quiera comprobar si
	 * un cambio de configuración altera algo comparten UNA sola definición de
	 * «lo que la configuración editorial materializa» — la lección de
	 * `dobles que no modelan producción`: un segundo cálculo de «precio
	 * esperado» es exactamente la clase de doble que esconde fatales vivos.
	 *
	 * `price_effective` es el precio que sirve el índice; `price_html` es el
	 * mismo hecho en su forma de presentación (`filter_price_html` de
	 * TaxonomyDiscount pinta el tachado sólo cuando el resolver devuelve un
	 * precio menor al regular). Se devuelven juntos porque el descuento
	 * editorial mueve los dos o ninguno.
	 *
	 * NO prepara la fuente de precios de un producto variable: `build()` ya lo
	 * hace antes de llamar (y prepararlo dos veces borraría transients dos
	 * veces, contrato fijado en IndexerProtocolV2Test). Cualquier otro caller
	 * con un variable debe preparar la autoridad él mismo.
	 *
	 * @param \WC_Product $product Producto.
	 * @return array{price_effective: float, price_html: string}
	 * @throws \RuntimeException Cuando una fuente derivada no puede leerse íntegramente.
	 */
	public function editorial_materialization( \WC_Product $product ): array {
		$price_effective = $this->effective_price( $product );

		$raw_price_html = $this->read_source( static fn() => $product->get_price_html(), 'Product price HTML source is unreadable.' );
		if ( ! is_string( $raw_price_html ) ) {
			throw new \RuntimeException( 'Product price HTML source has an invalid shape.' );
		}
		$price_html = $this->read_source( static fn() => wp_kses_post( $raw_price_html ), 'Product price HTML sanitizer is unreadable.' );
		if ( ! is_string( $price_html ) ) {
			throw new \RuntimeException( 'Product price HTML sanitizer returned an invalid shape.' );
		}

		return array(
			'price_effective' => $price_effective,
			// La sanitización con wp_kses_post se ejecuta antes del truncado para evitar
			// cortar etiquetas HTML por la mitad y garantizar marcado seguro en el autocomplete.
			// Longitud máxima de 1000 caracteres alineada con el schema de columna de `price_html`.
			'price_html'      => $this->truncate( $price_html, 1000 ),
		);
	}

	/**
	 * Precio final que ve el cliente: descuento editorial (que ya arbitra
	 * contra el sale price según policy) o el precio WC vigente.
	 *
	 * Contrato de autoridad del hook: todo proveedor registrado en
	 * `akb_search_effective_price` debe lanzar una excepción si no pudo leer de
	 * forma autoritativa sus términos, termmeta o policy. DocumentBuilder deja
	 * propagar esa excepción para que Indexer conserve la deuda y no publique un
	 * documento parcial/base-price. La ausencia total de proveedor conserva el
	 * precio WC base como modo válido y forma parte del fingerprint editorial.
	 *
	 * @param \WC_Product $product Producto.
	 * @return float
	 */
	private function effective_price( \WC_Product $product ): float {
		$price = $this->read_source( static fn() => $product->get_price(), 'Product price source is unreadable.' );
		if ( ! is_string( $price ) && ! is_int( $price ) && ! is_float( $price ) ) {
			throw new \RuntimeException( 'Product price source has an invalid shape.' );
		}
		if ( '' === $price ) {
			$base = 0.0;
		} elseif ( ! is_numeric( $price ) || ! is_finite( (float) $price ) || (float) $price < 0.0 ) {
			throw new \RuntimeException( 'Product price source returned an invalid value.' );
		} else {
			$base = (float) $price;
		}

		/**
		 * Precio efectivo del índice. akibara-business (TaxonomyDiscount) engancha
		 * este filtro con el descuento editorial memoizado por request; sin ese
		 * plugin, el precio base WC. Reemplaza la inyección directa de
		 * DiscountResolver — misma semántica (descuento ?? precio vigente).
		 *
		 * @param float       $base    Precio WC vigente (0.0 si no numérico).
		 * @param \WC_Product $product Producto indexado.
		 */
		$effective = $this->read_source(
			static fn() => apply_filters( 'akb_search_effective_price', $base, $product ),
			'Effective-price provider is unreadable.'
		);
		if ( ! is_numeric( $effective ) || ! is_finite( (float) $effective ) || (float) $effective < 0.0 ) {
			throw new \RuntimeException( 'Effective-price provider returned a non-authoritative value.' );
		}
		return (float) $effective;
	}

	/**
	 * Precio regular base utilizado para calcular la faceta `oferta`.
	 *
	 * En productos variables (`WC_Product_Variable`), `_regular_price` a nivel de padre
	 * está vacío. Se utiliza el precio regular mínimo de las variaciones (`get_variation_regular_price('min')`)
	 * para comparar de forma homogénea contra el `price_effective` indexado (que también
	 * toma el mínimo del rango), garantizando que solo variaciones efectivamente rebajadas
	 * generen tiers de descuento válidos sin crear falsos positivos.
	 *
	 * @param \WC_Product $product Producto.
	 * @param bool        $is_variable Si el producto es de tipo variable.
	 * @return float
	 */
	private function regular_price( \WC_Product $product, bool $is_variable ): float {
		// method_exists: un tipo de terceros podría declararse 'variable' sin
		// extender WC_Product_Variable.
		if ( $is_variable && method_exists( $product, 'get_variation_regular_price' ) ) {
			$regular = $this->read_source( static fn() => $product->get_variation_regular_price( 'min' ), 'Variation regular price source is unreadable.' );
		} else {
			$regular = $this->read_source( static fn() => $product->get_regular_price( 'edit' ), 'Product regular price source is unreadable.' );
		}
		if ( '' === $regular || ( $is_variable && false === $regular ) ) {
			return 0.0;
		}
		if ( ( ! is_string( $regular ) && ! is_int( $regular ) && ! is_float( $regular ) )
			|| ! is_numeric( $regular ) || ! is_finite( (float) $regular ) || (float) $regular < 0.0 ) {
			throw new \RuntimeException( 'Regular price source returned an invalid value.' );
		}
		return (float) $regular;
	}

	/**
	 * Tokens de todos los campos, dedupeados por (token, field).
	 *
	 * @param \WC_Product           $product      Producto.
	 * @param array<string, mixed>  $doc          Fila doc ya construida.
	 * @param array<string, array>  $terms_by_tax Términos por taxonomía.
	 * @return array<int, array{token: string, field: int}>
	 * @throws \RuntimeException Cuando una variación hija no puede verificarse.
	 */
	private function build_tokens( \WC_Product $product, array $doc, array $terms_by_tax, bool $is_variable ): array {
		global $wpdb;

		$out  = array();
		$seen = array();

		$add = static function ( array $tokens, int $field ) use ( &$out, &$seen ): void {
			foreach ( $tokens as $token ) {
				$key = $field . ':' . $token;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$out[]        = array(
					'token' => $token,
					'field' => $field,
				);
			}
		};

		$add( Normalizer::tokenize( (string) $doc['title'] ), self::FIELD_TITLE );

		// Token aglutinado del título (sin dígitos): permite emparejar búsquedas continuas
		// de series multi-palabra (ej. 'Dan Da Dan' → 'dandadan', 'One Piece' → 'onepiece').
		// Aplica a títulos multi-palabra respetando los límites de longitud de token.
		$compact = str_replace( ' ', '', trim( (string) preg_replace( '/[0-9]+/', '', (string) $doc['title_norm'] ) ) );
		if ( strlen( $compact ) >= 4
			&& strlen( $compact ) <= Normalizer::MAX_TOKEN_LENGTH
			&& false !== strpos( trim( (string) preg_replace( '/[0-9]+/', '', (string) $doc['title_norm'] ) ), ' ' ) ) {
			$add( array( $compact ), self::FIELD_TITLE );
		}

		$add( Normalizer::tokenize( (string) $doc['serie_norm'] ), self::FIELD_SERIE );
		$add( Normalizer::tokenize( (string) $doc['autores_norm'] ), self::FIELD_AUTOR );

		// SKU/ISBN: token exacto (lowercase) + variante solo-dígitos para
		// ISBN con guiones ("978-956-..." → "978956...").
		$add( $this->sku_tokens( (string) $doc['sku'] ), self::FIELD_SKU );

		// En productos variables, los SKU/ISBN de las variaciones se indexan en el
		// documento del producto padre para que búsquedas por código de barras de una
		// variación específica resuelvan al producto principal en el catálogo.
		if ( $is_variable ) {
			if ( null === $this->source_authority ) {
				throw new \RuntimeException( 'Variation source authority is unavailable.' );
			}
			$children = $this->source_authority->published_variations( (int) $doc['post_id'], self::MAX_VARIATION_SKUS );
			if ( ! $this->source_authority->is_available() ) {
				throw new \RuntimeException( $this->source_authority->last_error() ?: 'Variation source is unreadable.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
			}
			foreach ( $children as $child ) {
				$wpdb->last_error = '';
				try {
					$child_sku = $child->get_sku();
				} catch ( \Throwable $exception ) {
					throw new \RuntimeException( 'Variation SKU source is unreadable.', 0, $exception ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: es la excepción previa encadenada como tercer argumento de RuntimeException.
				}
				if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ! is_string( $child_sku ) ) {
					throw new \RuntimeException( 'Variation SKU source is unreadable.' );
				}
				$add( $this->sku_tokens( $this->truncate( strtolower( $child_sku ), 64 ) ), self::FIELD_SKU );
			}
		}

		// Marca/género/categorías: navegación por texto ("ivrea", "seinen").
		$tax_names = array();
		foreach ( array( 'product_brand', 'pa_genero', 'product_cat' ) as $taxonomy ) {
			$names = $this->term_names( $terms_by_tax, $taxonomy );
			if ( '' !== $names ) {
				$tax_names[] = $names;
			}
		}
		if ( ! empty( $tax_names ) ) {
			$add( Normalizer::tokenize( implode( ' ', $tax_names ) ), self::FIELD_TAXONOMY );
		}

		// Descripción a peso bajo — red de recall para la cola larga
		// ("urasawa" hoy matchea vía sinopsis). Cap duro de tokens.
		$short_description = $this->read_source( static fn() => $product->get_short_description(), 'Product short description source is unreadable.' );
		if ( ! is_string( $short_description ) ) {
			throw new \RuntimeException( 'Product short description source has an invalid shape.' );
		}
		$this->verify_post_field( (int) $doc['post_id'], 'post_excerpt', $short_description );
		$long_description = $this->read_source( static fn() => $product->get_description(), 'Product description source is unreadable.' );
		if ( ! is_string( $long_description ) ) {
			throw new \RuntimeException( 'Product description source has an invalid shape.' );
		}
		$this->verify_post_field( (int) $doc['post_id'], 'post_content', $long_description );
		$description = trim( $short_description . ' ' . $long_description );
		if ( '' !== $description ) {
			$desc_tokens = Normalizer::tokenize( wp_strip_all_tags( $description ) );
			$add( array_slice( $desc_tokens, 0, self::MAX_DESCRIPTION_TOKENS ), self::FIELD_DESCRIPTION );
		}

		return $out;
	}

	/**
	 * Tokens de un SKU/ISBN: el valor exacto (ya en minúsculas) más la variante
	 * solo-dígitos, para que "978-956-…" matchee escrito sin guiones. Extraído
	 * de build_tokens() para reusar la misma regla con los SKU de las
	 * variaciones (SRCH-12); con SKU vacío devuelve un array vacío, que el
	 * colector ignora — mismo comportamiento que el guard `'' !== $sku` previo.
	 *
	 * @param string $sku SKU normalizado a minúsculas y ya truncado.
	 * @return array<int, string>
	 */
	private function sku_tokens( string $sku ): array {
		if ( '' === $sku ) {
			return array();
		}
		$out    = array( $this->truncate( $sku, Normalizer::MAX_TOKEN_LENGTH ) );
		$digits = preg_replace( '/[^0-9]/', '', $sku );
		if ( is_string( $digits ) && '' !== $digits && $digits !== $sku ) {
			$out[] = $this->truncate( $digits, Normalizer::MAX_TOKEN_LENGTH );
		}
		return $out;
	}

	/**
	 * Filas del espejo de facetas según el registry.
	 *
	 * @param \WC_Product          $product         Producto.
	 * @param string               $status          Stock status raw.
	 * @param float                $price_effective Precio efectivo.
	 * @param float                $regular         Precio regular.
	 * @param array<string, array> $terms_by_tax    Términos por taxonomía.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_facets( \WC_Product $product, int $post_id, string $status, float $price_effective, float $regular, array $terms_by_tax ): array {
		$rows    = array();
		$seen    = array();

		$add = static function ( string $facet, string $value, string $label, ?float $value_num = null, ?int $term_id = null, ?int $parent_id = null ) use ( &$rows, &$seen, $post_id ): void {
			$key = $facet . '|' . $value;
			if ( '' === $value || isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$rows[]       = array(
				'facet'     => $facet,
				'post_id'   => $post_id,
				'value'     => $value,
				'label'     => $label,
				'value_num' => $value_num,
				'term_id'   => $term_id,
				'parent_id' => $parent_id,
			);
		};

		$definitions = $this->read_source( static fn() => FacetRegistry::definitions(), 'Facet definitions source is unreadable.' );
		if ( ! is_array( $definitions ) ) {
			throw new \RuntimeException( 'Facet definitions source has an invalid shape.' );
		}
		foreach ( $definitions as $name => $def ) {
			switch ( $def['type'] ?? '' ) {
				case 'taxonomy':
					$this->add_taxonomy_rows( $add, $name, $def, $terms_by_tax, $post_id );
					break;

				case 'stock':
					// Valor de la faceta = estado COMERCIAL, no stock crudo (1.109.0):
					// `onbackorder` se parte en 'a_pedido' (default del grupo) y
					// 'preventa' (lanzamiento pendiente). Quién distingue es business
					// vía el filtro `akb_search_availability_value` — self-contained:
					// sin business enganchado, todo reservable factea como 'a_pedido',
					// que es el default real del catálogo. Cualquier retorno que no
					// sea exactamente 'preventa' degrada a 'a_pedido' (fail-safe ante
					// un filtro ajeno que devuelva basura).
					// El MISMO resolvedor que el badge del autocomplete (StockLabels),
					// para que la palabra «Preventa» y la llamada al filtro existan
					// en un solo sitio.
					$avail = $this->read_source( static fn() => StockLabels::availability( $status, $post_id ), 'Availability source is unreadable.' );
					if ( ! is_array( $avail ) || ! is_string( $avail['value'] ?? null ) || ! is_string( $avail['label'] ?? null ) || '' === trim( $avail['label'] ) ) {
						throw new \RuntimeException( 'Stock label source has an invalid shape.' );
					}
					$add( $name, $avail['value'], $avail['label'] );
					break;

				case 'oferta':
					foreach ( $this->oferta_tiers( $price_effective, $regular ) as $tier ) {
						$add( $name, (string) $tier, FacetRegistry::OFERTA_TIERS[ $tier ], (float) $tier );
					}
					break;

				case 'precio':
					// Sin fila de precio si el efectivo es 0 (precio vacío/oculto o
					// no numérico): value_num=0 caía en el bucket más barato e
					// inflaba su count con productos sin precio real (m7, mismo
					// criterio que el guard de `oferta`). NULL/ausente = ningún
					// bucket lo matchea, que es lo correcto.
					if ( $price_effective > 0 ) {
						$add( $name, 'precio', '', $price_effective );
					}
					break;

				case 'novedad':
					// Materializa SIEMPRE (el flag akb_search_novedad es query-time,
					// como precio): encender la faceta NO exige reindex. Sin fila si
					// es preventa (onbackorder): "novedad" = llegó a tienda, no "se
					// abrió un encargo" [mesa D4; revocable = borrar este guard +
					// bump de DB_VERSION → rebuild automático vía B.3]. Sin fila si
					// la fecha es nula/epoch ≤ 0 (mismo criterio que precio 0:
					// NULL/ausente = ningún bucket lo matchea).
					if ( 'onbackorder' !== $status ) {
						$created = $this->read_source( static fn() => $product->get_date_created(), 'Product creation date source is unreadable.' );
						if ( null !== $created && ! is_object( $created ) ) {
							throw new \RuntimeException( 'Product creation date source has an invalid shape.' );
						}
						$epoch = null !== $created
							? $this->read_source( static fn() => $created->getTimestamp(), 'Product creation timestamp source is unreadable.' )
							: 0;
						if ( ! is_int( $epoch ) ) {
							throw new \RuntimeException( 'Product creation timestamp source has an invalid shape.' );
						}
						if ( $epoch > 0 ) {
							$add( $name, 'novedad', '', (float) $epoch );
						}
					}
					break;
			}
		}

		return $rows;
	}

	/**
	 * Filas de una faceta de taxonomía: términos directos + ancestros si es
	 * jerárquica; con parent_term filtra a hijos de ese término (el set de
	 * candidatos incluye ancestros — un producto solo en "nietos" de Manga
	 * igual cuenta para su demografía).
	 *
	 * @param callable             $add          Colector de filas.
	 * @param string               $name         Nombre de la faceta.
	 * @param array<string, mixed> $def          Definición del registry.
	 * @param array<string, array> $terms_by_tax Términos por taxonomía.
	 * @return void
	 * @throws \RuntimeException Cuando un ancestro no puede resolverse íntegramente.
	 */
	private function add_taxonomy_rows( callable $add, string $name, array $def, array $terms_by_tax, int $post_id ): void {
		global $wpdb;

		$taxonomy = (string) ( $def['taxonomy'] ?? '' );
		$terms    = $terms_by_tax[ $taxonomy ] ?? array();
		if ( empty( $terms ) ) {
			if ( 'pais' === $name && ! empty( $terms_by_tax['product_brand'] ) ) {
				$inferred_term = $this->infer_country_term_from_brands( $terms_by_tax['product_brand'] );
				if ( null !== $inferred_term ) {
					$terms = array( $inferred_term );
				} else {
					return;
				}
			} else {
				return;
			}
		}

		$candidates = array();
		foreach ( $terms as $term ) {
			$candidates[ $term->term_id ] = $term;
		}

		// Materialización de ancestros: para jerárquicas Y para parent_term
		// (demografia/sello necesitan el ancestro intermedio si el producto
		// está en un sub-término).
		if ( ! empty( $def['hierarchical'] ) || ! empty( $def['parent_term'] ) ) {
			foreach ( $terms as $term ) {
				$wpdb->last_error = '';
				$ancestor_ids     = get_ancestors( $term->term_id, $taxonomy, 'taxonomy' );
				if ( ! is_array( $ancestor_ids ) || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
					throw new \RuntimeException( 'Taxonomy ancestor source is unreadable.' );
				}
				if ( null !== $this->source_authority
					&& ! $this->source_authority->verify_product_ancestor_ids( $post_id, $taxonomy, $term->term_id, $ancestor_ids ) ) {
					throw new \RuntimeException( $this->source_authority->last_error() ?: 'Taxonomy ancestor source disagrees with its authority.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
				}
				foreach ( $ancestor_ids as $ancestor_id ) {
					$ancestor_id = is_scalar( $ancestor_id ) ? (int) $ancestor_id : 0;
					if ( 0 >= $ancestor_id ) {
						throw new \RuntimeException( 'Taxonomy ancestor identifier is invalid.' );
					}
					if ( isset( $candidates[ $ancestor_id ] ) ) {
						continue;
					}
					$wpdb->last_error = '';
					$ancestor         = get_term( $ancestor_id, $taxonomy );
					if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $ancestor ) )
						|| '' !== trim( (string) ( $wpdb->last_error ?? '' ) )
						|| ! $ancestor instanceof \WP_Term ) {
						throw new \RuntimeException( 'Taxonomy ancestor source is unreadable.' );
					}
					if ( null !== $this->source_authority
						&& ! $this->source_authority->verify_product_term( $post_id, $taxonomy, $ancestor ) ) {
						throw new \RuntimeException( $this->source_authority->last_error() ?: 'Taxonomy ancestor source disagrees with its authority.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
					}
					$candidates[ $ancestor_id ] = $ancestor;
				}
			}
		}

		$parent_filter = (int) ( $def['parent_term'] ?? 0 );
		$root_only     = ! empty( $def['root_only'] );

		foreach ( $candidates as $term ) {
			if ( $parent_filter > 0 && (int) $term->parent !== $parent_filter ) {
				continue;
			}
			// root_only (Tipo): solo términos raíz — un producto en "Shonen" ya
			// materializó "Manga" como ancestro arriba, que es lo único que se
			// indexa acá; el hijo sigue siendo de demografia/sello. La default
			// de WooCommerce (Uncategorized) tampoco entra.
			if ( $root_only ) {
				$excluded = FacetRegistry::is_excluded_root_term( (int) $term->term_id );
				$this->require_facet_registry_authority();
				if ( 0 !== (int) $term->parent || $excluded ) {
					continue;
				}
			}
			$add( $name, (string) $term->slug, (string) $term->name, null, (int) $term->term_id, (int) $term->parent );
		}
	}

	/**
	 * Infiere el término canónico de país (pa_pais) a partir de la marca/editorial
	 * del producto cuando el producto carece del atributo explícito.
	 *
	 * @param array<int, mixed> $brand_terms Términos de product_brand del producto.
	 * @return \WP_Term|null
	 */
	private function infer_country_term_from_brands( array $brand_terms ): ?\WP_Term {
		foreach ( $brand_terms as $brand ) {
			if ( ! $brand instanceof \WP_Term ) {
				continue;
			}
			$country_slug = self::BRAND_COUNTRY_MAP[ $brand->slug ] ?? null;
			if ( null === $country_slug ) {
				if ( str_ends_with( $brand->slug, '-argentina' ) ) {
					$country_slug = 'argentina';
				} elseif ( str_ends_with( $brand->slug, '-espana' ) ) {
					$country_slug = 'espana';
				}
			}
			if ( null !== $country_slug && function_exists( 'get_term_by' ) ) {
				$term = get_term_by( 'slug', $country_slug, 'pa_pais' );
				if ( $term instanceof \WP_Term ) {
					return $term;
				}
			}
		}
		return null;
	}

	/**
	 * Tiers de oferta que califica el producto (multi-tier, patrón Amazon).
	 * El % se computa contra el precio EFECTIVO — cubre sale_price y
	 * descuento editorial con una sola vara.
	 *
	 * @param float $price_effective Precio final.
	 * @param float $regular         Precio regular.
	 * @return array<int, int> Tiers calificados (ej. 35% → [10, 20, 30]).
	 */
	private function oferta_tiers( float $price_effective, float $regular ): array {
		if ( $regular <= 0 || $price_effective <= 0 || $price_effective >= $regular ) {
			return array();
		}

		// Redondeo hacia abajo (`floor`): un descuento del 9.5% no califica en el tramo '10% o más'.
		// Se agrega un épsilon (1e-9) para proteger imprecisiones de coma flotante en porcentajes exactos.
		$pct = (int) floor( ( ( $regular - $price_effective ) * 100 / $regular ) + 1e-9 );

		$tiers = array();
		foreach ( array_keys( FacetRegistry::OFERTA_TIERS ) as $tier ) {
			if ( $pct >= $tier ) {
				$tiers[] = $tier;
			}
		}
		return $tiers;
	}

	/**
	 * URL del thumbnail para la tarjeta del autocomplete, con placeholder de WC
	 * si el producto no tiene imagen.
	 *
	 * @param \WC_Product $product Producto.
	 * @return string
	 */
	private function thumb_url( \WC_Product $product ): string {
		$raw_image_id = $this->read_source( static fn() => $product->get_image_id(), 'Product image source is unreadable.' );
		$image_id     = ProductSourceAuthority::canonical_image_id( $raw_image_id );
		if ( null === $image_id ) {
			throw new \RuntimeException( 'Product image source has an invalid shape.' );
		}
		if ( $image_id > 0 ) {
			$src = $this->read_source(
				static fn() => wp_get_attachment_image_url( $image_id, self::THUMB_SIZE ),
				'Product thumbnail source is unreadable.'
			);
			if ( false !== $src && ! is_string( $src ) ) {
				throw new \RuntimeException( 'Product thumbnail source has an invalid shape.' );
			}
			if ( is_string( $src ) && '' !== $src ) {
				return $src;
			}
		}
		if ( ! function_exists( 'wc_placeholder_img_src' ) ) {
			return '';
		}
		$placeholder = $this->read_source(
			static fn() => wc_placeholder_img_src( self::THUMB_SIZE ),
			'Product placeholder source is unreadable.'
		);
		if ( ! is_string( $placeholder ) ) {
			throw new \RuntimeException( 'Product placeholder source has an invalid shape.' );
		}
		return $placeholder;
	}

	/**
	 * Términos del producto en todas las taxonomías indexadas, en un solo
	 * paso. `get_the_terms` lee el object-term cache (0 queries en caliente
	 * cuando el rebuild primó caches — patrón woocommerce-performance).
	 *
	 * @param int $post_id Producto.
	 * @return array<string, array<int, \WP_Term>>
	 * @throws \RuntimeException Cuando una lectura de términos falla o es inválida.
	 */
	private function collect_terms( int $post_id ): array {
		$out        = array();
		$taxonomies = $this->read_source( static fn() => FacetRegistry::indexed_taxonomies(), 'Indexed taxonomy registry is unreadable.' );
		if ( ! is_array( $taxonomies ) ) {
			throw new \RuntimeException( 'Indexed taxonomy registry has an invalid shape.' );
		}
		$this->require_facet_registry_authority();
		foreach ( $taxonomies as $taxonomy ) {
			$terms = $this->read_source( static fn() => get_the_terms( $post_id, $taxonomy ), 'Product taxonomy source is unreadable.' );
			if ( false === $terms ) {
				$terms = array();
			}
			if ( ! is_array( $terms ) ) {
				throw new \RuntimeException( 'Product taxonomy source has an invalid shape.' );
			}
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					throw new \RuntimeException( 'Product taxonomy source contains an invalid term.' );
				}
			}
			if ( null !== $this->source_authority
				&& ! $this->source_authority->verify_product_terms( $post_id, $taxonomy, $terms ) ) {
				throw new \RuntimeException( $this->source_authority->last_error() ?: 'Product taxonomy source disagrees with its authority.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
			}
			$out[ $taxonomy ] = $terms;
		}
		return $out;
	}

	/** @param mixed $value */
	private function verify_post_field( int $post_id, string $field, $value ): void {
		if ( null !== $this->source_authority
			&& ! $this->source_authority->verify_product_post_field( $post_id, $field, $value ) ) {
			throw new \RuntimeException( $this->source_authority->last_error() ?: 'Product post field disagrees with its authority.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
		}
	}

	/**
	 * Frontera puntual: ningún getter posterior puede limpiar el last_error de
	 * la lectura anterior ni convertir WP_Error/Throwable en un escalar plausible.
	 *
	 * @return mixed
	 */
	private function read_source( callable $reader, string $message ) {
		global $wpdb;

		if ( is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
		try {
			$value = $reader();
		} catch ( \Throwable $exception ) {
			throw new \RuntimeException( $exception->getMessage() ?: $message, 0, $exception ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades, y no es salida: es la excepción previa encadenada como tercer argumento de RuntimeException.
		}
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $value ) ) {
			throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
		}
		$error = is_object( $wpdb ) && property_exists( $wpdb, 'last_error' )
			? trim( (string) $wpdb->last_error )
			: '';
		if ( '' !== $error ) {
			throw new \RuntimeException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
		}
		return $value;
	}

	/**
	 * Los defaults del registry impiden un fatal visual, pero no son evidencia
	 * suficiente para publicar un documento. Indexer captura esta excepción y
	 * conserva la deuda sin doc/facetas/ACK.
	 */
	private function require_facet_registry_authority(): void {
		if ( ! FacetRegistry::is_available() ) {
			throw new \RuntimeException( FacetRegistry::last_error() ?: 'Facet registry source is not authoritative.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- no es salida: el mensaje viaja como dato al last_error de la fila de trabajo (retry_with_backoff) y lo escapa quien lo pinta; escaparlo aquí ensuciaría el log con entidades.
		}
	}

	/**
	 * @param array<string, array> $terms_by_tax Términos por taxonomía.
	 * @param string               $taxonomy     Taxonomía.
	 * @return string Nombres unidos por espacio.
	 */
	private function term_names( array $terms_by_tax, string $taxonomy ): string {
		$names = array();
		foreach ( $terms_by_tax[ $taxonomy ] ?? array() as $term ) {
			$names[] = (string) $term->name;
		}
		return implode( ' ', $names );
	}

	/**
	 * Recorte seguro por longitud máxima de bytes respetando límites de caracteres multibyte UTF-8.
	 *
	 * Utiliza `mb_strcut` cuando está disponible para evitar truncar caracteres UTF-8
	 * por la mitad (lo que provocaría errores en MySQL bajo STRICT_TRANS_TABLES),
	 * con fallback a `substr()`.
	 *
	 * @param string $value Valor.
	 * @param int    $max   Largo máximo en bytes.
	 * @return string
	 */
	private function truncate( string $value, int $max ): string {
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		return function_exists( 'mb_strcut' ) ? mb_strcut( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
	}
}
