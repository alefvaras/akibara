<?php
/**
 * ProductPresenter — post_ids ordenados → tarjetas WooCommerce vivas.
 *
 * El índice decide únicamente identidad y ranking. Los campos que el cliente
 * ve (título, URL, imagen, precio, SKU, visibilidad y stock) se hidratan desde
 * WC_Product en el mismo request. Así un receipt editorial válido nunca sirve
 * precio o presentación materializados antes de ese receipt.
 *
 * La lista se limita a 14 productos. Los caches de post, meta y términos los
 * calienta ProductSourceAuthority al autorizar las identidades; aquí sólo se
 * suman los adjuntos de portada, que son posts aparte. El endpoint REST aplica
 * su cache autoritativa después de esta lectura viva.
 *
 * Devuelve DTOs planos (arrays), no HTML: el markup lo arma el cliente vanilla.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class ProductPresenter {

	use Support\FailsClosed;

	/** Máximo que SuggestController puede presentar por request. */
	private const MAX_PRODUCTS = 14;

	/**
	 * Estados de stock que el presentador acepta de WooCommerce; cualquier otro
	 * cierra la tarjeta (fail-closed). El rank del índice lo materializa
	 * DocumentBuilder; aquí sólo se valida el vocabulario.
	 */
	private const KNOWN_STOCK_STATUSES = array( 'instock', 'onbackorder', 'outofstock' );

	/** @var \wpdb */
	private $db;

	/** Fuente compartida y fail-closed de post/meta/términos. */
	private ProductSourceAuthority $source_authority;

	/**
	 * @param \wpdb|null                  $db               WordPress DB.
	 * @param ProductSourceAuthority|null $source_authority Autoridad compartida.
	 */
	public function __construct( $db = null, ?ProductSourceAuthority $source_authority = null ) {
		global $wpdb;
		$this->db               = $db ?? $wpdb;
		$this->source_authority = $source_authority ?? new ProductSourceAuthority( $this->db );
	}

	/**
	 * Hidrata tarjetas vivas, preservando el orden de relevancia del índice.
	 *
	 * Un ID que ya no existe o dejó de estar publicado/visible se omite como
	 * estado real. Una excepción o `$wpdb->last_error` en cualquier fuente hace
	 * fallar cerrado el lote completo: nunca se devuelve una respuesta parcial.
	 *
	 * @param array<int, int> $ids post_ids en orden de relevancia.
	 * @return array<int, array<string, mixed>>
	 */
	public function cards( array $ids ): array {
		if ( ! $this->available ) {
			return array();
		}
		if ( ! EditorialDiscountPublication::ensure_price_authority_adopted() ) {
			$this->mark_unavailable( 'Editorial price authority could not be adopted.' );
			return array();
		}

		$ids = $this->normalize_ids( $ids );
		if ( empty( $ids ) ) {
			return array();
		}

		$ids = $this->source_authority->published_product_ids( $ids );
		if ( ! $this->source_authority->is_available() ) {
			$this->mark_unavailable( $this->source_authority->last_error() ?: 'Product sources are not authoritative.' );
			return array();
		}
		if ( empty( $ids ) ) {
			return array();
		}

		$this->prime_thumbnail_sources( $ids );
		if ( ! $this->available ) {
			return array();
		}

		$cards = array();
		foreach ( $ids as $id ) {
			$product = $this->read_live(
				static function () use ( $id ) {
					return wc_get_product( $id );
				},
				'Could not hydrate WooCommerce product.'
			);

			if ( ! $this->available ) {
				return array();
			}

			// El post publish fue comprobado después de primar posts. WooCommerce
			// atrapa algunas excepciones internas y devuelve false; en este punto ese
			// false ya no puede confundirse con un producto realmente borrado.
			if ( false === $product || null === $product ) {
				$this->mark_unavailable( 'WooCommerce could not hydrate an existing published product.' );
				return array();
			}
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $product ) ) {
				$this->mark_unavailable( 'WooCommerce returned an error while hydrating a product.' );
				return array();
			}
			if ( ! $product instanceof \WC_Product ) {
				$this->mark_unavailable( 'WooCommerce returned an invalid product object.' );
				return array();
			}

			$card = $this->live_card( $product, $id );
			if ( ! $this->available ) {
				return array();
			}
			if ( null !== $card ) {
				$cards[] = $card;
			}
		}

		return $cards;
	}

	/**
	 * Normaliza, deduplica y acota IDs sin cambiar su orden.
	 *
	 * @param array<int, int> $ids IDs de entrada.
	 * @return array<int, int>
	 */
	private function normalize_ids( array $ids ): array {
		$normalized = array();
		$seen       = array();
		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ]  = true;
			$normalized[] = $id;
			if ( self::MAX_PRODUCTS === count( $normalized ) ) {
				break;
			}
		}
		return $normalized;
	}

	/**
	 * Calienta los adjuntos de portada del lote en un solo viaje.
	 *
	 * Las portadas son posts APARTE de los productos, así que el prime que hace
	 * ProductSourceAuthority no las alcanza: cada `wp_get_attachment_image_url()`
	 * salía a buscar su propio `WP_Post` y su propio `_wp_attachment_metadata`.
	 * En el autocompletado eso son dos viajes por tarjeta —y las tarjetas se
	 * pintan de a catorce— en la ruta más caliente del buscador, la que corre
	 * mientras el cliente todavía está escribiendo.
	 *
	 * Los IDs salen del meta ya cacheado por `update_postmeta_cache()`, o sea
	 * que averiguarlos no cuesta ninguna consulta extra.
	 *
	 * @param array<int, int> $ids Productos publish ya verificados.
	 */
	private function prime_thumbnail_sources( array $ids ): void {
		$thumbnail_ids = array();
		foreach ( $ids as $id ) {
			$raw = $this->read_live(
				static function () use ( $id ) {
					return get_post_meta( $id, '_thumbnail_id', true );
				},
				'Could not read the live product thumbnail identity.'
			);
			if ( ! $this->available ) {
				return;
			}
			$thumbnail_id = ProductSourceAuthority::canonical_image_id( $raw );
			if ( null !== $thumbnail_id && $thumbnail_id > 0 ) {
				$thumbnail_ids[] = $thumbnail_id;
			}
		}

		$thumbnail_ids = array_values( array_unique( $thumbnail_ids, SORT_NUMERIC ) );
		if ( empty( $thumbnail_ids ) ) {
			return;
		}

		$this->read_live(
			static function () use ( $thumbnail_ids ): bool {
				_prime_post_caches( $thumbnail_ids, false, true );
				return true;
			},
			'Could not prime WooCommerce product thumbnails.'
		);
	}

	/**
	 * Construye una tarjeta solo con valores vivos de WC/Product + WordPress.
	 *
	 * @param \WC_Product $product     Producto hidratado.
	 * @param int         $expected_id Identidad autorizada antes de hidratar.
	 * @return array<string, mixed>|null Null si el producto ya no es publicable.
	 */
	private function live_card( \WC_Product $product, int $expected_id ): ?array {
		$id = $this->read_live(
			static function () use ( $product ) {
				return $product->get_id();
			},
			'Could not read live product identity.'
		);
		if ( ! is_int( $id ) || $id <= 0 || $id !== $expected_id ) {
			$this->mark_unavailable( 'WooCommerce product identity disagrees with the authoritative source.' );
			return null;
		}
		$is_variable = $this->read_live(
			static function () use ( $product ) {
				return $product->is_type( 'variable' );
			},
			'Could not read live product type.'
		);
		if ( ! $this->available || ! is_bool( $is_variable ) ) {
			$this->mark_unavailable( 'WooCommerce returned an invalid live product type.' );
			return null;
		}
		if ( $is_variable && ! $this->source_authority->prepare_variable_price_source( $id ) ) {
			$this->mark_unavailable( $this->source_authority->last_error() ?: 'Variable price source is not authoritative.' );
			return null;
		}

		$status = $this->read_live(
			static function () use ( $product ) {
				return $product->get_status();
			},
			'Could not read live product status.'
		);
		if ( ! $this->available ) {
			return null;
		}
		if ( ! is_string( $status ) || 'publish' !== $status ) {
			// ProductSourceAuthority ya verificó publish en la foto cruda B. Un
			// status WC distinto sólo puede ser una hidratación incompatible o una
			// carrera: jamás omitirlo como si fuese una baja legítima y devolver el
			// resto del lote parcial.
			$this->mark_unavailable( 'WooCommerce product status disagrees with the authoritative source.' );
			return null;
		}

		$visible = $this->read_live(
			static function () use ( $product ) {
				return $product->is_visible();
			},
			'Could not read live product visibility.'
		);
		if ( ! $this->available ) {
			return null;
		}
		if ( ! is_bool( $visible ) ) {
			$this->mark_unavailable( 'WooCommerce returned an invalid live visibility value.' );
			return null;
		}
		if ( ! $visible ) {
			return null;
		}

		$title = $this->read_live(
			static function () use ( $product ) {
				return $product->get_name();
			},
			'Could not read live product title.'
		);
		if ( ! $this->available || ! is_string( $title ) || '' === trim( $title ) ) {
			$this->mark_unavailable( 'WooCommerce product has no live title or permalink.' );
			return null;
		}
		if ( ! $this->source_authority->verify_product_post_field( $id, 'post_title', $title ) ) {
			$this->mark_unavailable( $this->source_authority->last_error() ?: 'WooCommerce product title disagrees with its source.' );
			return null;
		}
		$url   = $this->read_live(
			static function () use ( $id ) {
				return get_permalink( $id );
			},
			'Could not read live product permalink.'
		);
		if ( ! $this->available || ! is_string( $url ) || '' === trim( $url ) ) {
			$this->mark_unavailable( 'WooCommerce product has no live title or permalink.' );
			return null;
		}
		$thumb = $this->live_thumb_url( $product );
		if ( ! $this->available ) {
			return null;
		}
		// La sonda del proveedor ocurre ANTES del renderer. Así el proveedor
		// autoritativo queda primado y get_price_html consume exactamente la misma
		// fuente; una excepción nunca puede ocurrir después de renderizar precio.
		$this->verify_price_provider_authority( $product );
		if ( ! $this->available ) {
			return null;
		}
		$raw_price_html = $this->read_live(
			static function () use ( $product ) {
				return $product->get_price_html();
			},
			'Could not read live product price.'
		);
		if ( ! $this->available || ! is_string( $raw_price_html ) ) {
			$this->mark_unavailable( 'WooCommerce returned an invalid live product price.' );
			return null;
		}
		$price_html = $this->read_live( static fn() => wp_kses_post( $raw_price_html ), 'Could not sanitize live product price.' );
		if ( ! $this->available || ! is_string( $price_html ) ) {
			$this->mark_unavailable( 'WooCommerce returned an invalid sanitized product price.' );
			return null;
		}
		$sku = $this->read_live(
			static function () use ( $product ) {
				return $product->get_sku();
			},
			'Could not read live product SKU.'
		);
		if ( ! $this->available || ! is_string( $sku ) ) {
			$this->mark_unavailable( 'WooCommerce returned an invalid live SKU.' );
			return null;
		}
		$sku = strtolower( trim( $sku ) );
		$stock_status = $this->read_live(
			static function () use ( $product ) {
				return $product->get_stock_status();
			},
			'Could not read live product stock status.'
		);

		if ( ! $this->available ) {
			return null;
		}
		if ( ! is_string( $stock_status ) || ! in_array( $stock_status, self::KNOWN_STOCK_STATUSES, true ) ) {
			$this->mark_unavailable( 'WooCommerce returned an invalid live stock status.' );
			return null;
		}

		// El estado COMERCIAL por ficha (a pedido / preventa), con el mismo
		// resolvedor que la faceta: el badge decía «A pedido» sobre una preventa
		// que la faceta del mismo índice ya distinguía.
		$badge = $this->read_live(
			static function () use ( $stock_status, $id ): array {
				return StockLabels::availability( $stock_status, $id );
			},
			'The live stock label provider is not authoritative.'
		);
		if ( ! $this->available || ! is_array( $badge )
			|| array_keys( $badge ) !== array( 'value', 'label', 'tone' )
			|| ! is_string( $badge['label'] ) || '' === trim( $badge['label'] )
			|| ! is_string( $badge['tone'] ) || ! in_array( $badge['tone'], array( 'ok', 'pre', 'order', 'out' ), true ) ) {
			$this->mark_unavailable( 'The live stock badge returned an invalid shape.' );
			return null;
		}

		return array(
			'id'           => $id,
			'title'        => $title,
			'url'          => $url,
			'thumb'        => $thumb,
			'price_html'   => $price_html,
			'sku'          => $sku,
			'badge'        => $badge['label'],
			'badge_tone'   => $badge['tone'],
			'availability' => $badge['value'],
		);
	}

	/**
	 * Consulta explícitamente la misma autoridad que materializa
	 * `price_effective`. El proveedor que enganche este filtro DEBE lanzar una
	 * excepción si su fuente (términos/termmeta/policy) no es autoritativa en el
	 * request. Sin proveedor, el filtro devuelve el precio WC base y el modo
	 * base-price sigue siendo válido. Esta comprobación ocurre antes de
	 * `get_price_html()` para que el renderer reutilice la misma autoridad ya
	 * verificada y no pueda materializar un precio desde una fuente distinta.
	 */
	private function verify_price_provider_authority( \WC_Product $product ): void {
		$price = $this->read_live(
			static function () use ( $product ) {
				return $product->get_price();
			},
			'Could not read the live WooCommerce price authority base.'
		);
		if ( ! $this->available ) {
			return;
		}
		if ( '' === $price ) {
			$base = 0.0;
		} elseif ( ( ! is_string( $price ) && ! is_int( $price ) && ! is_float( $price ) )
			|| ! is_numeric( $price ) || ! is_finite( (float) $price ) || (float) $price < 0.0 ) {
			$this->mark_unavailable( 'The live WooCommerce price authority base has an invalid value.' );
			return;
		} else {
			$base = (float) $price;
		}
		$effective = $this->read_live(
			static function () use ( $base, $product ) {
				return apply_filters( 'akb_search_effective_price', $base, $product );
			},
			'The effective-price provider is not authoritative.'
		);
		if ( ! $this->available ) {
			return;
		}
		if ( ! is_numeric( $effective ) || ! is_finite( (float) $effective ) || (float) $effective < 0.0 ) {
			$this->mark_unavailable( 'The effective-price provider returned an invalid value.' );
		}
	}

	/**
	 * Ejecuta una fuente viva y captura su error inmediatamente.
	 *
	 * @param callable $reader   Lectura puntual.
	 * @param string   $fallback Diagnóstico si wpdb no entrega uno.
	 * @return mixed Resultado de la fuente; null tras un fallo.
	 */
	private function read_live( callable $reader, string $fallback ) {
		if ( ! $this->available ) {
			return null;
		}

		$this->clear_database_error();
		try {
			$value = $reader();
		} catch ( \Throwable $exception ) {
			$this->mark_unavailable( $exception->getMessage() ?: $fallback );
			return null;
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $value ) ) {
			$this->mark_unavailable( $fallback );
			return null;
		}
		if ( $this->read_has_error() ) {
			$this->mark_unavailable( $fallback );
			return null;
		}

		return $value;
	}

	/** Limpia errores stale antes de cada fuente. */
	private function clear_database_error(): void {
		if ( property_exists( $this->db, 'last_error' ) ) {
			$this->db->last_error = '';
		}
	}

	/** Indica si la última fuente dejó error explícito en wpdb. */
	private function read_has_error(): bool {
		return property_exists( $this->db, 'last_error' )
			&& '' !== trim( (string) $this->db->last_error );
	}

	/**
	 * Miniatura del camino VIVO, en el mismo tamaño que el índice materializa.
	 *
	 * Es la contraparte de `DocumentBuilder::thumb_url()`: el Reconciler compara
	 * `docs.thumb_url` campo a campo contra lo que sirve este camino, así que
	 * los dos tienen que pedir `DocumentBuilder::THUMB_SIZE` o todos los
	 * documentos aparecen desalineados.
	 *
	 * Devuelve '' cuando el producto no tiene imagen. Ante una lectura
	 * inválida marca el presentador como no disponible: el caller comprueba
	 * `$this->available` y aborta.
	 *
	 * @param \WC_Product $product Producto vivo.
	 * @return string
	 */
	private function live_thumb_url( $product ): string {
		$raw_image_id = $this->read_live(
			static function () use ( $product ) {
				return $product->get_image_id();
			},
			'Could not read live product image.'
		);
		$image_id = ProductSourceAuthority::canonical_image_id( $raw_image_id );
		if ( ! $this->available || null === $image_id ) {
			$this->mark_unavailable( 'WooCommerce returned an invalid live image identity.' );
			return '';
		}
		if ( 0 === $image_id ) {
			return '';
		}

		$thumb_value = $this->read_live(
			static function () use ( $image_id ) {
				return wp_get_attachment_image_url( $image_id, DocumentBuilder::THUMB_SIZE );
			},
			'Could not read live product thumbnail.'
		);
		if ( ! $this->available || ( false !== $thumb_value && ! is_string( $thumb_value ) ) ) {
			$this->mark_unavailable( 'WooCommerce returned an invalid live thumbnail.' );
			return '';
		}

		return false === $thumb_value ? '' : $thumb_value;
	}

}
