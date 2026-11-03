<?php

/**
 * Recomienda tomos por continuidad y afinidad con la compra.
 *
 * Prioriza el tomo siguiente de las series del carrito, después la continuidad
 * del historial de pedidos y finalmente primeros tomos de series populares de
 * géneros afines que el cliente aún no compró.
 *
 * El análisis y los candidatos se memoizan por petición y se guardan en transient
 * con clave cart_hash + user_id. Así no se mezclan historiales de clientes con
 * carritos idénticos. Los invitados comparten user_id cero porque no aportan
 * historial. La API de transients conserva TTL e invalidación con o sin caché
 * persistente. El filtro final de stock se ejecuta siempre en vivo sobre los
 * candidatos: la disponibilidad no se congela en la caché.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\NativeCart;

use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\VolumeTitle;

defined( 'ABSPATH' ) || exit;

final class SmartCartRecommender {

	/** TTL corto: el cart_hash ya auto-invalida al mutar el carrito; esto acota
	 *  la ventana de staleness ante cambios de catálogo (tomo nuevo publicado). */
	private const CANDIDATE_TTL = 5 * MINUTE_IN_SECONDS;

	/** Prefijo único del historial por usuario, compartido por lectura e invalidación. */
	private const HISTORY_CACHE_PREFIX = 'akb_user_history_data_';

	/**
	 * TTL de respaldo del historial. flush_history_cache() invalida al confirmar
	 * compras; el TTL cubre caminos que no pasan por esos estados.
	 */
	private const HISTORY_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Pedidos que se HIDRATAN (wc_get_order + get_items + get_the_terms por producto)
	 * para el análisis de series y géneros. Es la parte cara y corre dentro del
	 * request que abre el drawer: este límite es lo que la acota con las caches
	 * frías — no aflojarlo apostando al object-cache persistente.
	 */
	private const ANALYSIS_ORDER_LIMIT = 5;

	/**
	 * Pedidos que aportan productos ya comprados para excluirlos de recomendaciones.
	 * Se leen con una consulta indexada a order items, sin hidratar pedidos, y
	 * pueden abarcar una ventana mayor que el análisis de géneros y series.
	 */
	private const OWNED_ORDER_LIMIT = 50;

	/**
	 * Memo por request de candidatos derivados del carrito, keyed por la misma
	 * clave que el transient. Instancia (no static): el filtro se registra con
	 * una única instancia que vive todo el request, así que memoiza aperturas
	 * repetidas del drawer sin filtrar entre requests ni entre tests.
	 *
	 * @var array<string, array<int,int>>
	 */
	private array $memo = array();

	private ?BannerRenderer $eta_renderer;

	/**
	 * Cache por request de id de candidato => bool (si empeora o no la llegada).
	 *
	 * @var array<string, bool>
	 */
	private array $eta_candidate_cache = array();

	public function __construct( ?BannerRenderer $eta_renderer = null ) {
		$this->eta_renderer = $eta_renderer;
	}

	private function resolve_eta_renderer(): ?BannerRenderer {
		if ( null !== $this->eta_renderer ) {
			return $this->eta_renderer;
		}
		if ( class_exists( '\Akibara\Business\Plugin' ) ) {
			$ed = \Akibara\Business\Plugin::instance()->module( 'estimated_delivery' );
			if ( $ed instanceof \Akibara\Business\Modules\EstimatedDelivery\EstimatedDelivery ) {
				$this->eta_renderer = $ed->renderer();
				return $this->eta_renderer;
			}
		}
		return null;
	}

	public function register(): void {
		add_filter( 'woocommerce_cart_crosssell_ids', array( $this, 'inject_smart_recommendations' ), 10, 2 );

		// Invalidar el historial al confirmar una compra para que los tomos
		// recién comprados no sigan apareciendo entre sus candidatos.

		add_action( 'woocommerce_order_status_processing', array( $this, 'flush_history_cache' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'flush_history_cache' ), 10, 2 );
	}

	/**
	 * Borra el historial cacheado del cliente dueño de un pedido.
	 *
	 * @param int|string     $order_id ID del pedido (WooCommerce lo pasa como int; se
	 *                                 castea igual porque `strict_types` no perdona a
	 *                                 quien dispare la acción con un string).
	 * @param mixed          $order    Pedido ya cargado que pasa WooCommerce como 2º
	 *                                 argumento; evita releerlo. Null si el disparador
	 *                                 no lo manda.
	 */
	public function flush_history_cache( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Invitado (customer_id 0) no tiene historial cacheado: no hay nada que borrar.
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			delete_transient( self::HISTORY_CACHE_PREFIX . $customer_id );
		}
	}

	/**
	 * @param array<int> $cross_sells
	 * @param \WC_Cart   $cart
	 * @return array<int>
	 */
	public function inject_smart_recommendations( array $cross_sells, \WC_Cart $cart ): array {
		$items = $cart->get_cart();
		if ( empty( $items ) ) {
			return $cross_sells;
		}

		$user_id   = get_current_user_id();
		$cache_key = 'akb_smartcart_' . md5( $this->cart_hash( $cart, $items ) . '|u' . $user_id );

		// Candidatos "smart" (siguientes tomos + descubrimiento) — parte CARA,
		// cacheada por cart_hash+user_id + memo por request.
		$smart_cross_sells = $this->smart_candidate_ids( $items, $user_id, $cache_key );

		// Prioridad: 1. Siguientes tomos, 2. Descubrimientos, 3. Cross-sells estáticos nativos.
		$merged = array_values( array_unique( array_merge( $smart_cross_sells, $cross_sells ) ) );

		// Primar post/meta/term caches de los candidatos en una pasada, para que el
		// wc_get_product() del filtro de abajo lea de cache y no dispare N queries
		// por cada apertura del drawer.
		if ( ! empty( $merged ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_map( 'intval', $merged ), true, true );
		}

		// Detectar si el carrito actual ya lleva algún producto a pedido o preventa.
		$cart_has_backorders = false;
		foreach ( $items as $cart_item ) {
			$cart_prod = $cart_item['data'] ?? ( isset( $cart_item['product_id'] ) ? wc_get_product( $cart_item['product_id'] ) : null );
			if ( $cart_prod instanceof \WC_Product && StockStatus::is_reservable( $cart_prod ) ) {
				$cart_has_backorders = true;
				break;
			}
		}

		// Si el carrito ya lleva a pedido, preparamos el renderer para evaluar que los
		// candidatos a pedido no empeoren la llegada máxima del carrito.
		$eta_renderer        = $cart_has_backorders ? $this->resolve_eta_renderer() : null;
		$current_cart_max_ts = null;
		$current_cart_is_tba = false;

		if ( $cart_has_backorders && null !== $eta_renderer ) {
			$current_summary = $eta_renderer->summarize_cart( $items );
			if ( null !== $current_summary ) {
				$current_cart_max_ts = $current_summary['max_ts'] ?? null;
				$current_cart_is_tba = ! empty( $current_summary['is_tba'] );
			}
		}

		$cart_hash_key = $this->cart_hash( $cart, $items );

		// Filtro final: NUNCA cacheado. El stock cambia, y un tomo agotado tras
		// cachearse no debe mostrarse en cross-sells (UX "Impulse Buy").
		// Regla Inteligente:
		// - Si el carrito es 100% entrega inmediata: sugerir SOLO tomos en stock físico (is_in_stock() && !is_reservable()).
		// - Si el carrito ya lleva productos a pedido/preventa: un candidato a pedido SÓLO se acepta
		//   si no empeora la llegada del carrito actual (BannerRenderer::summarize_cart()).
		$final_cross_sells = array();
		foreach ( $merged as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}

			if ( StockStatus::is_reservable( $product ) ) {
				// Carrito inmediato nunca recibe productos a pedido.
				if ( ! $cart_has_backorders ) {
					continue;
				}

				// Comportamiento conservador: si no hay renderer disponible, descartar.
				if ( null === $eta_renderer ) {
					continue;
				}

				$cache_k = $cart_hash_key . '|' . $id;
				if ( ! isset( $this->eta_candidate_cache[ $cache_k ] ) ) {
					$candidate_item   = array(
						'product_id' => $id,
						'quantity'   => 1,
						'data'       => $product,
					);
					$combined_summary = $eta_renderer->summarize_cart( array_merge( $items, array( $candidate_item ) ) );

					$allowed = false;
					if ( null !== $combined_summary ) {
						$combined_max_ts = $combined_summary['max_ts'] ?? null;
						$combined_is_tba = ! empty( $combined_summary['is_tba'] );

						if ( ! $current_cart_is_tba ) {
							if ( ! $combined_is_tba && null !== $current_cart_max_ts && null !== $combined_max_ts ) {
								$allowed = ( $combined_max_ts <= $current_cart_max_ts );
							}
						} else {
							$allowed = $combined_is_tba;
						}
					}

					$this->eta_candidate_cache[ $cache_k ] = $allowed;
				}

				if ( ! $this->eta_candidate_cache[ $cache_k ] ) {
					continue;
				}
			}

			$final_cross_sells[] = $id;
		}

		return $final_cross_sells;
	}

	/**
	 * Candidatos derivados del carrito, con memo por request + transient
	 * (cart_hash+user_id). Devuelve IDs SIN filtrar por stock — el filtro vivo
	 * corre siempre en inject_smart_recommendations().
	 *
	 * @param array<string, mixed> $items    Items del carrito ($cart->get_cart()).
	 * @param int                  $user_id  Usuario actual (0 = invitado).
	 * @param string               $cache_key
	 * @return array<int,int>
	 */
	private function smart_candidate_ids( array $items, int $user_id, string $cache_key ): array {
		if ( isset( $this->memo[ $cache_key ] ) ) {
			return $this->memo[ $cache_key ];
		}

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$this->memo[ $cache_key ] = $cached;
			return $cached;
		}

		$smart = $this->compute_smart_candidate_ids( $items, $user_id );

		set_transient( $cache_key, $smart, self::CANDIDATE_TTL );
		$this->memo[ $cache_key ] = $smart;
		return $smart;
	}

	/**
	 * Núcleo caro: analiza carrito + historial y resuelve los "siguientes tomos"
	 * y "descubrimientos por gusto". 2-4 queries SQL. Sin filtro de stock.
	 *
	 * @param array<string, mixed> $items
	 * @param int                  $user_id
	 * @return array<int,int>
	 */
	private function compute_smart_candidate_ids( array $items, int $user_id ): array {
		$current_cart_ids = array();
		$cart_series      = array(); // [ term_taxonomy_id => array( product_id => post_title) ]
		$cart_genres      = array(); // [ term_taxonomy_id => count ]

		// 1. Analizar el carrito actual
		$cart_item_pids = array_values( array_unique( array_filter( array_map( 'intval', array_column( $items, 'product_id' ) ) ) ) );
		if ( ! empty( $cart_item_pids ) && function_exists( 'update_object_term_cache' ) ) {
			update_object_term_cache( $cart_item_pids, 'product' );
		}

		foreach ( $items as $cart_item ) {
			$product_id         = $cart_item['product_id'];
			$current_cart_ids[] = $product_id;

			// Series — B3 (first-term): registrar el producto bajo TODOS sus
			// términos `pa_serie` (no solo reset()); un tomo con 2 series aportaba
			// únicamente a la 1ª y perdía la recomendación de la 2ª. Mismo patrón
			// que CartSeriesCollector::resolve_enabled_series (itera todos).
			$this->index_series_terms( $cart_series, $product_id );

			// Géneros (pa_genero)
			$genero_terms = get_the_terms( $product_id, 'pa_genero' );
			if ( is_array( $genero_terms ) ) {
				foreach ( $genero_terms as $g_term ) {
					$g_id = (int) $g_term->term_taxonomy_id;
					$cart_genres[ $g_id ] = ( $cart_genres[ $g_id ] ?? 0 ) + 1;
				}
			}
		}

		$smart_cross_sells = array();
		$historical_owned_products = array();

		// 2. Analizar Historial de Compras (Si está logueado)
		if ( $user_id > 0 ) {
			// El transient conserva TTL e invalidación con o sin caché persistente.
			// La clave incorpora al usuario; HISTORY_TTL y flush_history_cache()
			// limitan la vigencia de su historial sin mezclar compradores.

			$cache_key = self::HISTORY_CACHE_PREFIX . $user_id;
			$history_data = get_transient( $cache_key );

			if ( false === $history_data || ! is_array( $history_data ) ) {
				$history_data = array(
					'products' => array(),
					'series'   => array(),
					'genres'   => array(),
				);

				// Obtener una vez los IDs de pedidos más recientes.
				// OWNED_ORDER_LIMIT acota el conjunto de exclusión de comprados;
				// ANALYSIS_ORDER_LIMIT acota el análisis de series y géneros.

				$customer_orders = wc_get_orders( array(
					'customer_id' => $user_id,
					'status'      => array( 'wc-completed', 'wc-processing' ),
					'limit'       => self::OWNED_ORDER_LIMIT,
					'return'      => 'ids',
				) );
				$customer_orders = array_values( array_map( 'intval', (array) $customer_orders ) );

				// Una consulta a order items alimenta ambas ventanas sin hidratar
				// pedidos completos ni consultar términos producto por producto.

				$lineas_por_pedido = $this->line_item_product_ids( $customer_orders );

				$history_data['products'] = array();
				foreach ( $lineas_por_pedido as $product_ids ) {
					foreach ( $product_ids as $product_id ) {
						$history_data['products'][] = $product_id;
					}
				}

				// Las líneas del análisis conservan sus REPETICIONES: el peso por
				// género cuenta líneas compradas, no productos distintos.
				$analisis = array();
				foreach ( array_slice( $customer_orders, 0, self::ANALYSIS_ORDER_LIMIT ) as $order_id ) {
					foreach ( $lineas_por_pedido[ $order_id ] ?? array() as $product_id ) {
						$analisis[] = $product_id;
					}
				}

				// Posts y términos de todo el análisis en una pasada: el
				// `get_the_title()` de `index_series_terms()` y los dos
				// `get_the_terms()` de abajo pasan a leer de caché.
				$unique_analisis = array_values( array_unique( $analisis ) );
				if ( ! empty( $unique_analisis ) && function_exists( '_prime_post_caches' ) ) {
					_prime_post_caches( $unique_analisis, true, false );
				}
				if ( ! empty( $unique_analisis ) && function_exists( 'update_object_term_cache' ) ) {
					update_object_term_cache( $unique_analisis, 'product' );
				}

				foreach ( $analisis as $product_id ) {
					$history_data['products'][] = $product_id;

					// B3 (first-term): idéntico al carrito — todas las series.
					$this->index_series_terms( $history_data['series'], $product_id );

					$genero_terms = function_exists( 'get_object_term_cache' )
						? get_object_term_cache( $product_id, 'pa_genero' )
						: false;
					if ( false === $genero_terms ) {
						$genero_terms = get_the_terms( $product_id, 'pa_genero' );
					}
					if ( is_array( $genero_terms ) ) {
						foreach ( $genero_terms as $g_term ) {
							$g_id = (int) $g_term->term_taxonomy_id;
							$history_data['genres'][ $g_id ] = ( $history_data['genres'][ $g_id ] ?? 0 ) + 1;
						}
					}
				}
				// La query de "ya comprado" y la hidratación se solapan en los pedidos
				// del análisis: deduplicar ANTES de persistir para no guardar el mismo
				// id dos veces en cada entrada del transient.
				$history_data['products'] = array_values( array_unique( array_map( 'intval', $history_data['products'] ) ) );

				set_transient( $cache_key, $history_data, self::HISTORY_TTL );
			}

			// Merge del caché con el carrito actual
			$historical_owned_products = $history_data['products'];
			foreach ( $history_data['series'] as $term_id => $prods ) {
				if ( ! isset( $cart_series[ $term_id ] ) ) {
					$cart_series[ $term_id ] = array();
				}
				foreach ( $prods as $pid => $ptitle ) {
					$cart_series[ $term_id ][ $pid ] = $ptitle;
				}
			}
			foreach ( $history_data['genres'] as $g_id => $count ) {
				$cart_genres[ $g_id ] = ( $cart_genres[ $g_id ] ?? 0 ) + $count;
			}
		}

		$historical_owned_products = array_unique( $historical_owned_products );
		$all_owned_products = array_unique( array_merge( $current_cart_ids, $historical_owned_products ) );

		global $wpdb;

		// 3. Continuidad de lectura. Una sola consulta trae las secuencias de
		// todas las series relevantes; VolumeTitle evita que el orden alfabético
		// confunda 99/100 o trate novelas y packs como el tomo siguiente.
		$smart_cross_sells = $this->find_next_volume_ids( $cart_series, $all_owned_products );

		// 4. Recomendación Tipo B: "Descubrimiento por Gustos" (Si hay espacio)
		// Si generamos menos de 3 recomendaciones, buscamos "Tomos 1" de sus géneros favoritos
		if ( count( $smart_cross_sells ) < 3 && ! empty( $cart_genres ) ) {
			arsort( $cart_genres ); // Ordenar géneros por frecuencia (favoritos primero)
			$top_genre_ids = array_slice( array_keys( $cart_genres ), 0, 2 ); // Top 2 géneros

			$genre_in = implode( ',', array_map( 'intval', $top_genre_ids ) );
			$owned_in = implode( ',', array_map( 'intval', $all_owned_products ) );
			$exclude_sql = ! empty( $owned_in ) ? "AND p.ID NOT IN ({$owned_in})" : "";

			// Buscar candidatos a tomo 1. La validación final usa VolumeTitle,
			// por lo que un año, decimal o número dentro del nombre no se confunde
			// con el inicio de una serie.
			//
			// El REGEXP no se indexa, pero se queda: aquí la PRECISIÓN es
			// load-bearing por el LIMIT. Un pre-filtro más laxo (`LIKE '%1'`) case
			// también «Naruto 11» y «Bleach 21», que llenarían las 20 plazas y
			// dejarían fuera tomos 1 reales — el filtro fino de abajo no puede
			// recuperar lo que la consulta ya no trajo. Cualquier reemplazo tiene
			// que conservar «espacio + ceros opcionales + 1 al final».
			$sql = "SELECT DISTINCT p.ID

					FROM {$wpdb->posts} p

					JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id

					WHERE p.post_type = 'product'

					  AND p.post_status = 'publish'

					  AND tr.term_taxonomy_id IN ({$genre_in})

					  AND p.post_title REGEXP '(^|[[:space:]])0*1$'

					  {$exclude_sql}

					ORDER BY p.menu_order ASC, p.ID DESC

					LIMIT 20";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- $sql se arma arriba con {$genre_in}/{$exclude_sql}, ambos listas de enteros casteados.
			$discovery_ids = $wpdb->get_col( $sql );

			if ( ! empty( $discovery_ids ) ) {
				// El filtro fino pide el título de los 20 candidatos: sin cebar, son
				// 20 consultas por cada apertura del drawer que llega hasta aquí.
				if ( function_exists( '_prime_post_caches' ) ) {
					_prime_post_caches( array_map( 'intval', $discovery_ids ), false, false );
				}

				foreach ( $discovery_ids as $d_id ) {
					if ( 1 !== VolumeTitle::extract_number( (string) get_the_title( (int) $d_id ) ) ) {
						continue;
					}
					$smart_cross_sells[] = (int) $d_id;
					if ( count( $smart_cross_sells ) >= 3 ) {
						break;
					}
				}
			}
		}

		return array_values( array_unique( array_map( 'intval', $smart_cross_sells ) ) );
	}

	/**
	 * Líneas de producto de un conjunto de pedidos, agrupadas por pedido, con UNA
	 * query indexada.
	 *
	 * Va contra las tablas de order items, que son las MISMAS con HPOS y sin HPOS (a
	 * diferencia de la tabla de pedidos) y no dependen de que los lookups de Analytics
	 * estén al día. Se hidrata CERO pedidos: por eso puede mirar 50 hacia atrás y
	 * además alimentar el análisis de series/géneros de los 5 más recientes sin
	 * cargar un solo `WC_Order`.
	 *
	 * Las repeticiones se conservan a propósito: el set de «ya comprado» se
	 * deduplica al usarse, pero el peso por género cuenta LÍNEAS compradas.
	 *
	 * El resultado se usa para EXCLUIR y para pesar géneros, nunca para recomendar
	 * directo, así que el peor caso de un fallo es un set vacío: el motor deja de
	 * filtrar algo, no empuja una recomendación equivocada.
	 *
	 * @param array<int, int> $order_ids Pedidos del cliente (ya enteros).
	 * @return array<int, array<int, int>> [order_id => [product_id,...]].
	 */
	private function line_item_product_ids( array $order_ids ): array {
		$order_ids = array_values( array_filter( array_map( 'intval', $order_ids ) ) );
		if ( empty( $order_ids ) ) {
			return array();
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$order_ids_sql = implode( ',', $order_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- order_ids_sql contiene solo enteros; el resultado viaja dentro del transient de historial.
		$rows = $wpdb->get_results(
			"SELECT oi.order_id AS order_id, oim.meta_value AS product_id

			 FROM {$wpdb->prefix}woocommerce_order_items oi

			 JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim

			   ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'

			 WHERE oi.order_id IN ( {$order_ids_sql} )

			   AND oi.order_item_type = 'line_item'",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$por_pedido = array();
		foreach ( (array) $rows as $row ) {
			$product_id = (int) ( $row['product_id'] ?? 0 );
			$order_id   = (int) ( $row['order_id'] ?? 0 );
			if ( 0 === $product_id || 0 === $order_id ) {
				continue;
			}
			$por_pedido[ $order_id ][] = $product_id;
		}

		return $por_pedido;
	}

	/**
	 * Resuelve el primer tomo posterior a lo ya comprado por cada serie.
	 *
	 * @param array<int, array<int, string>> $series         [term_taxonomy_id => [product_id => title]].
	 * @param array<int, int>                $owned_products
	 * @return array<int, int>
	 */
	private function find_next_volume_ids( array $series, array $owned_products ): array {
		if ( empty( $series ) ) {
			return array();
		}

		$term_ids = array_values( array_filter( array_map( 'intval', array_keys( $series ) ) ) );
		if ( empty( $term_ids ) ) {
			return array();
		}

		global $wpdb;
		$term_ids_sql = implode( ',', $term_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- term_ids_sql es un implode de enteros ya casteados; el IN () vive dentro del string y no admite comentario en línea.
		$rows = $wpdb->get_results(
			"SELECT tr.term_taxonomy_id, p.ID AS id, p.post_title AS title

			 FROM {$wpdb->posts} p

			 JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id

			 WHERE p.post_type = 'product'

			   AND p.post_status = 'publish'

			   AND tr.term_taxonomy_id IN ( {$term_ids_sql} )",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$records_by_term = array();
		foreach ( $rows as $row ) {
			$number = VolumeTitle::extract_number( (string) $row['title'] );
			if ( null === $number ) {
				continue;
			}

			$records_by_term[ (int) $row['term_taxonomy_id'] ][] = array(
				'id'            => (int) $row['id'],
				'title'         => (string) $row['title'],
				'volume_number' => $number,
			);
		}

		$owned_products       = array_map( 'intval', $owned_products );
		$candidates_by_series = array();

		foreach ( $series as $term_id => $owned_in_series ) {
			$owned_numbers = array_filter(
				array_map(
					static fn( string $title ): ?int => VolumeTitle::extract_number( $title ),
					array_values( $owned_in_series )
				),
				static fn( ?int $number ): bool => null !== $number
			);
			if ( empty( $owned_numbers ) ) {
				continue;
			}

			$current_number = max( $owned_numbers );
			$next           = VolumeTitle::next_records(
				$records_by_term[ (int) $term_id ] ?? array(),
				$current_number,
				3,
				$owned_products
			);
			if ( ! empty( $next ) ) {
				$candidates_by_series[] = array_map(
					static fn( array $rec ): int => (int) $rec['id'],
					$next
				);
			}
		}

		// Intercalar por serie (round-robin: primero el siguiente de cada serie, luego el segundo de cada serie, etc.)
		// conservando hasta 3 candidatos por serie para que, si el inmediato queda filtrado por stock, entre el próximo.
		$next_ids  = array();
		$max_depth = 3;
		for ( $depth = 0; $depth < $max_depth; $depth++ ) {
			foreach ( $candidates_by_series as $series_list ) {
				if ( isset( $series_list[ $depth ] ) ) {
					$next_ids[] = $series_list[ $depth ];
				}
			}
		}

		return array_values( array_unique( $next_ids ) );
	}

	/**
	 * Registra un producto bajo TODOS sus términos `pa_serie` (B3: nada de
	 * first-term vía reset()). Object-term cache (0 queries en caliente).
	 *
	 * @param array<int, array<int, string>> $series     Acumulador [tt_id => [pid => title]] (por referencia).
	 * @param int                            $product_id
	 */
	private function index_series_terms( array &$series, int $product_id ): void {
		$serie_terms = function_exists( 'get_object_term_cache' )
			? get_object_term_cache( $product_id, 'pa_serie' )
			: false;
		if ( false === $serie_terms ) {
			$serie_terms = get_the_terms( $product_id, 'pa_serie' );
		}
		if ( ! is_array( $serie_terms ) ) {
			return;
		}
		foreach ( $serie_terms as $term ) {
			$tt_id = (int) $term->term_taxonomy_id;
			$series[ $tt_id ][ $product_id ] = (string) get_the_title( $product_id );
		}
	}

	/**
	 * Firma estable del carrito para keyear la caché. Prefiere el hash nativo
	 * de WooCommerce (cambia ante cualquier mutación); si no está disponible,
	 * cae a una firma determinista de (product_id × qty) ordenada.
	 *
	 * @param \WC_Cart             $cart
	 * @param array<string, mixed> $items
	 */
	private function cart_hash( \WC_Cart $cart, array $items ): string {
		if ( method_exists( $cart, 'get_cart_hash' ) ) {
			$hash = (string) $cart->get_cart_hash();
			if ( '' !== $hash ) {
				return $hash;
			}
		}

		$sig = array();
		foreach ( $items as $item ) {
			$sig[] = (int) ( $item['product_id'] ?? 0 ) . 'x' . (int) ( $item['quantity'] ?? 0 );
		}
		sort( $sig );
		return md5( implode( '|', $sig ) );
	}
}
