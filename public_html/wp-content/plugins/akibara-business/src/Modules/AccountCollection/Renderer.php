<?php
/**
 * Renderer server-side de Mi colección.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AccountCollection;

use Akibara\Business\Modules\SeriesFollow\FollowRegistry;
use Akibara\Business\Modules\SeriesFollow\GlobalToggle;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Services\SeriesHubUrl;
use Akibara\Business\Utilities\Log;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/**
	 * Aviso de que una compra recién hecha todavía no se ve.
	 *
	 * La colección no se arma al pagar: cuenta sólo los pedidos con el pago ya
	 * confirmado —`purchase_statuses()` de `SeriesBundles\CustomerCollection`:
	 * processing, completed y shipping-progress, nunca pending ni on-hold—. Sin
	 * decirlo, el cliente que acaba de comprar abre esta pantalla, no encuentra
	 * su tomo y concluye que la compra se perdió, que es la lectura más cara de
	 * las posibles.
	 */
	public const OPTION_DELAY_NOTICE = 'akb_copy_coleccion_demora';

	/**
	 * Texto inicial del aviso de colección; vacío en el panel oculta la línea.
	 * Describe la condición de pago confirmado sin prometer un plazo, ya que
	 * los pedidos por transferencia pueden esperar confirmación o cancelarse.
	 */
	public const DEFAULT_DELAY_NOTICE = 'Acá aparecen los tomos de los pedidos con el pago confirmado. Si compraste recién, tu tomo se suma cuando el pago quede confirmado: en las transferencias eso puede demorar, porque las revisamos a mano.';

	/** @var \Closure(int):array<string,mixed> */
	private \Closure $resolver;

	/**
	 * @param callable(int):array<string,mixed> $resolver Resuelve el DTO privado.
	 */
	public function __construct( callable $resolver ) {
		$this->resolver = \Closure::fromCallable( $resolver );
	}

	/**
	 * @param int $user_id ID del cliente autenticado.
	 */
	public function render( int $user_id ): string {
		try {
			$resolved = ( $this->resolver )( $user_id );
			$data     = $this->view_data( is_array( $resolved ) ? $resolved : array(), $user_id );
		} catch ( \Throwable $error ) {
			// El estado «no disponible» es la ÚNICA salida de error de esta
			// pantalla, y sin traza no hay forma de saber por qué le apareció a un
			// cliente: se veía igual que una colección vacía. No cambia el control
			// de flujo, sólo deja evidencia en WooCommerce → Estado → Registros.
			Log::warn(
				'coleccion',
				sprintf(
					'No se pudo resolver la colección del cliente %d: %s',
					$user_id,
					$error->getMessage()
				),
				array( 'exception' => get_class( $error ) )
			);

			$data = $this->view_data( array( 'status' => 'unavailable' ), $user_id );
		}

		$template = AKIBARA_BUSINESS_DIR . 'templates/myaccount/collection.php';
		if ( ! is_readable( $template ) ) {
			return '';
		}

		ob_start();
		require $template;
		$html = ob_get_clean();

		return false === $html ? '' : $html;
	}

	/**
	 * Reduce el DTO de dominio a datos de presentación predecibles.
	 *
	 * Nunca calcula progreso ni completitud desde el catálogo: consume sólo los
	 * campos explícitos de `edition` preparados por el resolver.
	 *
	 * @param array<string,mixed> $resolved DTO de CollectionResolver.
	 * @param int                 $user_id  Cliente autenticado (estado del toggle de avisos).
	 * @return array<string,mixed>
	 */
	private function view_data( array $resolved, int $user_id ): array {
		$status = isset( $resolved['status'] ) && is_string( $resolved['status'] )
			? $resolved['status']
			: 'unavailable';
		if ( ! in_array( $status, array( 'ok', 'empty', 'unavailable' ), true ) ) {
			$status = 'unavailable';
		}

		// El estado del interruptor GLOBAL se lee una vez para toda la vista: lo
		// necesitan la sección «Avisos de mi colección» y cada tarjeta (que con
		// el global apagado no pinta su botón). Es un `get_user_meta` que WP
		// cachea, así que en la rama 'empty' —donde no corre el prime— sigue
		// costando una consulta como mucho.
		$follow_all_off = $user_id > 0 && FollowRegistry::opted_out_of_everything( $user_id );

		$series = array();
		if ( 'ok' === $status && isset( $resolved['series'] ) && is_array( $resolved['series'] ) ) {
			$this->prime_cover_images( $resolved['series'] );
			$this->prime_follow_state( $user_id );
			foreach ( $resolved['series'] as $item ) {
				if ( is_array( $item ) ) {
					$normalized = $this->series_data( $item, $user_id, $follow_all_off );
					if ( '' !== $normalized['name'] ) {
						$series[] = $normalized;
					}
				}
			}
		}

		$unclassified_count = isset( $resolved['unclassified_count'] ) ? max( 0, (int) $resolved['unclassified_count'] ) : 0;
		if ( 'ok' === $status && array() === $series && 0 === $unclassified_count ) {
			$status = 'empty';
		}

		$owned_total       = 0;
		$owned_total_known = true;
		$complete_total    = 0;
		$series_off        = 0;
		foreach ( $series as $item ) {
			if ( is_int( $item['owned_volume_count'] ) ) {
				$owned_total += $item['owned_volume_count'];
			} else {
				$owned_total_known = false;
			}
			if ( true === $item['edition']['is_complete'] || 'complete' === $item['continuity']['kind'] ) {
				++$complete_total;
			}
			if ( true === $item['follow']['available'] && false === $item['follow']['following'] ) {
				++$series_off;
			}
		}

		return array(
			'status'             => $status,
			'only_unclassified'  => 'ok' === $status && array() === $series && $unclassified_count > 0,
			'series'             => $series,
			'series_count'       => count( $series ),
			'owned_total'        => $owned_total_known ? $owned_total : null,
			'owned_label'        => $owned_total_known
				? ( $unclassified_count > 0 ? 'Tomos agrupados' : 'Tomos' )
				: 'Compras registradas',
			'complete_total'     => $complete_total,
			'unclassified_count' => $unclassified_count,
			'collection_url'     => Endpoint::url(),
			'orders_url'         => function_exists( 'wc_get_endpoint_url' ) ? (string) wc_get_endpoint_url( 'orders' ) : home_url( '/mi-cuenta/orders/' ),
			// SeriesHubUrl resuelve el directorio sin fijar el slug de la página.
			'series_url'         => SeriesHubUrl::url(),
			'follow_all'         => $this->follow_all_data( $user_id, $follow_all_off, $series_off ),
			'delay_notice'       => self::delay_notice(),
			'rewards_banner'     => $user_id > 0 && '' !== self::rewards_points_label(),
			'rewards_points'     => self::rewards_points_label(),
		);
	}

	/**
	 * Premio vigente por reseña con foto, leído SIEMPRE del plugin Rewards
	 * (Ajustes del programa en el panel). Sin monto propio ni default fijo:
	 * si el carril está apagado, CusRev manda o el plugin no está, devuelve
	 * '' y ninguna superficie promete puntos que nadie puede pagar.
	 *
	 * @return string Ej. «+500 Puntos Akibara» o «+500 Puntos Akibara cada 3 reseñas».
	 */
	public static function rewards_points_label(): string {
		$options = '\\Akibara\\Rewards\\Support\\Options';
		if ( ! class_exists( $options ) || ! $options::reviews_lane_on() ) {
			return '';
		}
		$points = (int) $options::review_points();
		if ( $points <= 0 ) {
			return '';
		}
		$fmt   = static function ( int $n ): string {
			return function_exists( 'number_format_i18n' ) ? (string) number_format_i18n( $n ) : number_format( $n, 0, ',', '.' );
		};
		$every = method_exists( $options, 'review_every_n' ) ? max( 1, (int) $options::review_every_n() ) : 1;
		$label = '+' . $fmt( $points ) . ' Puntos Akibara';
		return 1 === $every ? $label : $label . ' cada ' . $fmt( $every ) . ' reseñas';
	}

	/**
	 * El aviso de demora, ya saneado, o cadena vacía si el dueño lo apagó.
	 *
	 * Se lee como el resto de los copys apagables del sitio: el literal es el
	 * default de `get_option()`, así que sale de fábrica; un valor guardado
	 * VACÍO es la forma de retirar la línea, y por eso el fallback de
	 * `Text::one_line()` es la cadena vacía y no el literal — si fuera el
	 * literal, borrarlo en el panel lo resucitaría en la siguiente carga.
	 */
	private static function delay_notice(): string {
		return Text::one_line(
			get_option( self::OPTION_DELAY_NOTICE, self::DEFAULT_DELAY_NOTICE ),
			''
		);
	}

	/**
	 * Estado del interruptor GLOBAL «Avisos de mi colección».
	 *
	 * Se expone y se pinta en TODAS las ramas con sesión, incluida
	 * 'unavailable': el consentimiento no depende de tener tomos ni de que el
	 * resolver de compras funcione. 'unavailable' es un fallo de la VISTA de
	 * la colección (cualquier Throwable del resolver), y mientras dure el
	 * cliente sigue necesitando una superficie para apagar o encender los
	 * avisos: es justo adonde apunta la página «Listo» de la baja por correo.
	 * Quien se dio de baja desde un correo tiene que poder reencender aunque
	 * su colección esté vacía o caída. `available` cae sólo sin sesión. El
	 * nonce va atado al id del cliente: el handler lo verifica contra la
	 * sesión, así que un formulario copiado no sirve para otro usuario.
	 *
	 * `series_off_count` cuenta las series de la colección apagadas una a una
	 * (con el global encendido): la plantilla lo usa para no prometer avisos
	 * de series que el cliente apagó desde el correo.
	 *
	 * @param int  $user_id        Cliente autenticado (0 = sin sesión).
	 * @param bool $follow_all_off ¿Tiene la baja global puesta?
	 * @param int  $series_off     Series apagadas una a una en esta colección.
	 * @return array{available:bool, enabled:bool, action_url:string, nonce:string, series_off_count:int}
	 */
	private function follow_all_data( int $user_id, bool $follow_all_off, int $series_off ): array {
		return array(
			'available'        => $user_id > 0,
			'enabled'          => $user_id > 0 && ! $follow_all_off,
			'action_url'       => Endpoint::url(),
			'nonce'            => $user_id > 0 ? (string) wp_create_nonce( GlobalToggle::NONCE_ACTION . $user_id ) : '',
			'series_off_count' => $follow_all_off ? 0 : max( 0, $series_off ),
		);
	}

	/**
	 * Una sola lectura de usermeta para todo el listado: el toggle de avisos
	 * pregunta por serie, y sin este prime cada tarjeta pagaría su consulta.
	 *
	 * @param int $user_id Cliente autenticado.
	 * @return void
	 */
	private function prime_follow_state( int $user_id ): void {
		if ( $user_id > 0 ) {
			FollowRegistry::prime( array( $user_id ) );
		}
	}

	/**
	 * @param array<string,mixed> $item           Serie del resolver.
	 * @param int                 $user_id        Cliente autenticado.
	 * @param bool                $follow_all_off ¿Baja global puesta? Bloquea el botón por serie.
	 * @return array<string,mixed>
	 */
	private function series_data( array $item, int $user_id, bool $follow_all_off = false ): array {
		$owned_products = $this->products( $item['owned_products'] ?? array() );
		$missing        = $this->products( $item['missing_catalog_products'] ?? array() );
		$owned_count    = isset( $item['owned_count'] ) ? max( 0, (int) $item['owned_count'] ) : count( $owned_products );
		$owned_volumes  = isset( $item['owned_volume_count'] ) && is_int( $item['owned_volume_count'] ) && $item['owned_volume_count'] >= 0
			? $item['owned_volume_count']
			: null;
		$catalog_count  = isset( $item['catalog_count'] ) ? max( 0, (int) $item['catalog_count'] ) : 0;
		$missing_count  = isset( $item['missing_catalog_count'] ) ? max( 0, (int) $item['missing_catalog_count'] ) : count( $missing );
		$historical     = isset( $item['historical_only_count'] ) ? max( 0, (int) $item['historical_only_count'] ) : 0;
		$edition        = isset( $item['edition'] ) && is_array( $item['edition'] ) ? $item['edition'] : array();
		$state          = isset( $edition['state'] ) && is_string( $edition['state'] ) ? $edition['state'] : '';
		$total          = isset( $edition['total'] ) ? max( 0, (int) $edition['total'] ) : 0;
		$progress_owned = isset( $edition['progress_owned'] ) ? max( 0, (int) $edition['progress_owned'] ) : null;
		$is_complete    = isset( $edition['is_complete'] ) && true === $edition['is_complete'];
		$is_one_shot    = ( 'completa' === $state && 1 === $total );
		$raw_continuity = is_array( $item['continuity'] ?? null ) ? $item['continuity'] : array();

		// Un tomo único («completa» en 1 tomo) suele no llevar «01» en el título
		// («Uzumaki», «From Hell», «Look Back»). Si el cliente ya compró ese tomo
		// y no le falta ninguno del catálogo, la edición está completa en su
		// colección y aporta 1 tomo al resumen, sin pedir «siguiente tomo».
		if ( $is_one_shot && $owned_count >= 1 && 0 === $missing_count ) {
			if ( null === $owned_volumes ) {
				$owned_volumes = 1;
			}
			$progress_owned = 1;
			$is_complete    = true;
			if ( 'unknown' === ( $raw_continuity['kind'] ?? 'unknown' ) ) {
				$raw_continuity = array(
					'kind'         => 'complete',
					'availability' => 'none',
				);
			}
		}

		$url            = isset( $item['url'] ) && is_string( $item['url'] ) ? trim( $item['url'] ) : '';
		$completion_url = isset( $item['completion_url'] ) && is_string( $item['completion_url'] ) ? trim( $item['completion_url'] ) : '';
		$name           = isset( $item['name'] ) && is_string( $item['name'] ) ? trim( $item['name'] ) : '';

		return array(
			'name'                  => $name,
			'url'                   => $url,
			'completion_url'        => $completion_url,
			'owned_products'        => $owned_products,
			'owned_count'           => $owned_count,
			'owned_volume_count'    => $owned_volumes,
			'catalog_count'         => $catalog_count,
			'missing_catalog_count' => $missing_count,
			'historical_only_count' => $historical,
			'cover_url'             => $this->cover_url( isset( $item['cover_product_id'] ) ? (int) $item['cover_product_id'] : 0 ),
			'edition'               => array(
				'state'          => $state,
				'state_label'    => $this->edition_state_label( $state, $total ),
				'total'          => $total,
				'progress_owned' => $progress_owned,
				'is_complete'    => $is_complete,
			),
			'continuity'            => $this->continuity_data( $raw_continuity, $name, $url, $completion_url, $is_one_shot ),
			'follow'                => $this->follow_data( $item, $user_id, $follow_all_off, $is_one_shot ),
			'bundle_suggestion'     => $item['bundle_suggestion'] ?? null,
			'manual_owned_product_ids' => $item['manual_owned_product_ids'] ?? array(),
		);
	}

	/**
	 * Estado del interruptor «avisarme del siguiente tomo».
	 *
	 * Seguir una serie es un hecho DERIVADO de haberla comprado: lo que se
	 * almacena es la negación. Por eso el default es `true` y `available` cae
	 * sólo cuando no hay término al que colgar la preferencia, cuando es una
	 * edición de tomo único ya cerrada, o cuando el interruptor GLOBAL está
	 * apagado (`locked`). En ese caso la tarjeta no pinta botón:
	 * `FollowRegistry::opt_in()` de UNA serie levanta la baja global, y N
	 * botones «Avisarme» reencenderían todos los avisos desde una tarjeta sin
	 * que el cliente lo haya pedido para toda su colección.
	 *
	 * @param array<string,mixed> $item           Serie del resolver.
	 * @param int                 $user_id        Cliente autenticado.
	 * @param bool                $follow_all_off ¿Baja global puesta?
	 * @param bool                $is_one_shot    ¿Es edición de tomo único?
	 * @return array{available:bool, locked:bool, term_id:int, following:bool}
	 */
	private function follow_data( array $item, int $user_id, bool $follow_all_off, bool $is_one_shot = false ): array {
		$term_id = isset( $item['term_id'] ) ? max( 0, (int) $item['term_id'] ) : 0;

		if ( $term_id < 1 || $user_id < 1 || $is_one_shot ) {
			return array(
				'available' => false,
				'locked'    => false,
				'term_id'   => 0,
				'following' => false,
			);
		}

		if ( $follow_all_off ) {
			return array(
				'available' => false,
				'locked'    => true,
				'term_id'   => $term_id,
				'following' => false,
			);
		}

		return array(
			'available' => true,
			'locked'    => false,
			'term_id'   => $term_id,
			'following' => ! FollowRegistry::is_opted_out( $user_id, $term_id ),
		);
	}

	/**
	 * Normaliza la recomendación semántica y prepara una sola acción visible.
	 *
	 * La vista no escoge silenciosamente una variante ni deduce continuidad
	 * desde conteos: si el contrato es incompleto, falla de forma honesta a
	 * `unknown`.
	 *
	 * @param mixed  $value      DTO semántico de ContinuityResolver.
	 * @param string $series_name Nombre visible de la edición.
	 * @param string $series_url  URL canónica de la edición.
	 * @param string $completion_url Ancla canónica para completar la edición.
	 * @return array<string,mixed>
	 */
	private function continuity_data( $value, string $series_name, string $series_url, string $completion_url, bool $is_one_shot = false ): array {
		$data         = is_array( $value ) ? $value : array();
		$allowed_kind = array( 'gap', 'next', 'start', 'complete', 'up_to_date', 'unknown' );
		$kind         = isset( $data['kind'] ) && is_string( $data['kind'] ) ? $data['kind'] : 'unknown';
		if ( ! in_array( $kind, $allowed_kind, true ) ) {
			$kind = 'unknown';
		}

		$allowed_availability = array( 'available', 'preorder', 'out_of_stock', 'unavailable', 'unknown', 'none' );
		$availability         = isset( $data['availability'] ) && is_string( $data['availability'] ) ? $data['availability'] : 'none';
		if ( ! in_array( $availability, $allowed_availability, true ) ) {
			$availability = 'unavailable';
		}

		$volume_number = isset( $data['volume_number'] ) && is_int( $data['volume_number'] ) && $data['volume_number'] > 0
			? $data['volume_number']
			: null;
		$product       = $this->continuity_product( $data['product'] ?? array() );
		$options       = $this->continuity_products( $data['options'] ?? array() );
		// El conteo declarado puede superar a la lista (viene recortada), pero
		// nunca puede quedar por debajo de lo que se está pintando.
		$options_count = max(
			isset( $data['options_count'] ) ? (int) $data['options_count'] : 0,
			count( $options )
		);

		if ( in_array( $kind, array( 'gap', 'next', 'start' ), true ) && null === $volume_number ) {
			$kind         = 'unknown';
			$availability = 'none';
		}

		$message = 'No podemos determinar el siguiente tomo con seguridad';
		switch ( $kind ) {
			case 'gap':
				$message = sprintf( 'No vemos el tomo %d entre tus compras de Akibara', $volume_number );
				break;
			case 'next':
				$message = sprintf( 'Siguiente en tu edición: tomo %d', $volume_number );
				break;
			case 'start':
				$message = sprintf( 'Empieza esta edición con el tomo %d', $volume_number );
				break;
			case 'complete':
				$message = $is_one_shot ? 'Edición completa · Obra autoconclusiva' : 'Edición completa';
				break;
			case 'up_to_date':
				$message = 'Estás al día con lo publicado en Akibara';
				break;
		}

		$availability_label = '';
		if ( in_array( $kind, array( 'gap', 'next', 'start' ), true ) ) {
			switch ( $availability ) {
				case 'available':
					$availability_label = 'Disponible';
					break;
				case 'preorder':
					// El fallback solicita la palabra vigente a la autoridad de disponibilidad.
					$availability_label = '' !== $product['preorder_label']
						? $product['preorder_label']
						: Availability::label_for_state( Availability::A_PEDIDO );
					break;
				case 'out_of_stock':
					$availability_label = 'Agotado';
					break;
				case 'unavailable':
				case 'unknown':
					$availability_label = 'No disponible por ahora';
					break;
			}
		} else {
			$availability = 'none';
		}

		$versions_label = '';
		$cta_label      = '';
		$cta_url        = '';
		if ( in_array( $kind, array( 'gap', 'next', 'start' ), true ) && $options_count > 1 ) {
			$versions_label = sprintf( 'Hay %d versiones del tomo %d', $options_count, $volume_number );
			if ( '' !== $series_url && '' !== $completion_url ) {
				$cta_label = sprintf( 'Ver versiones del tomo %d', $volume_number );
				$cta_url   = $completion_url;
			}
		} elseif ( in_array( $kind, array( 'gap', 'next', 'start' ), true ) ) {
			if ( 'available' === $availability && '' !== $product['url'] ) {
				$cta_label = sprintf( 'Ver tomo %d', $volume_number );
				$cta_url   = $product['url'];
			} elseif ( 'preorder' === $availability && '' !== $product['url'] ) {
				// El estado comercial determina Pedir o Reservar. Renombrar la etiqueta
				// en Akibara → Textos no cambia el verbo del CTA.
				$cta_label = Availability::PREVENTA === $product['availability_state']
					? sprintf( 'Reservar tomo %d', $volume_number )
					: sprintf( 'Pedir tomo %d', $volume_number );
				$cta_url   = $product['url'];
			} elseif ( 'out_of_stock' === $availability && '' !== $product['waitlist_url'] ) {
				$cta_label = 'Avísame cuando vuelva';
				$cta_url   = $product['waitlist_url'];
			} elseif ( 'out_of_stock' === $availability && '' !== $product['url'] ) {
				$cta_label = sprintf( 'Ver tomo %d', $volume_number );
				$cta_url   = $product['url'];
			}
		}

		$cta_aria = $cta_label;
		if ( '' !== $cta_aria && '' !== $series_name ) {
			$cta_aria .= ' de ' . $series_name;
		}

		return array(
			'kind'               => $kind,
			'availability'       => $availability,
			'volume_number'      => $volume_number,
			'product'            => $product,
			'options'            => $options,
			'options_count'      => $options_count,
			'message'            => $message,
			'availability_label' => $availability_label,
			'versions_label'     => $versions_label,
			'cta_label'          => $cta_label,
			'cta_url'            => $cta_url,
			'cta_aria'           => $cta_aria,
		);
	}

	/**
	 * @param mixed $value Producto candidato.
	 * @return array{id:int,title:string,url:string,stock_status:string,is_purchasable:bool,preorder_label:string,availability_state:string,waitlist_url:string}
	 */
	private function continuity_product( $value ): array {
		$product = is_array( $value ) ? $value : array();

		return array(
			'id'                 => isset( $product['id'] ) ? max( 0, (int) $product['id'] ) : 0,
			'title'              => isset( $product['title'] ) && is_string( $product['title'] ) ? trim( $product['title'] ) : '',
			'url'                => isset( $product['url'] ) && is_string( $product['url'] ) ? trim( $product['url'] ) : '',
			'stock_status'       => isset( $product['stock_status'] ) && is_string( $product['stock_status'] ) ? $product['stock_status'] : '',
			'is_purchasable'     => isset( $product['is_purchasable'] ) && true === $product['is_purchasable'],
			'preorder_label'     => isset( $product['preorder_label'] ) && is_string( $product['preorder_label'] ) ? trim( $product['preorder_label'] ) : '',
			// El verbo depende de Availability::A_PEDIDO/PREVENTA; preorder_label sólo
			// contiene el texto que el dueño puede editar.
			'availability_state' => isset( $product['availability_state'] ) && is_string( $product['availability_state'] ) ? $product['availability_state'] : '',
			'waitlist_url'       => isset( $product['waitlist_url'] ) && is_string( $product['waitlist_url'] ) ? trim( $product['waitlist_url'] ) : '',
		);
	}

	/**
	 * @param mixed $products Productos alternativos del mismo volumen.
	 * @return array<int,array{id:int,title:string,url:string,stock_status:string,is_purchasable:bool,preorder_label:string,availability_state:string,waitlist_url:string}>
	 */
	private function continuity_products( $products ): array {
		if ( ! is_array( $products ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $products as $product ) {
			if ( is_array( $product ) ) {
				$normalized[] = $this->continuity_product( $product );
			}
		}

		return $normalized;
	}

	/**
	 * @param mixed $products Productos del DTO.
	 * @return array<int,array{id:int,title:string,url:string}>
	 */
	private function products( $products ): array {
		if ( ! is_array( $products ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$title = isset( $product['title'] ) && is_string( $product['title'] ) ? trim( $product['title'] ) : '';
			if ( '' === $title ) {
				continue;
			}
			$normalized[] = array(
				'id'    => isset( $product['id'] ) ? max( 0, (int) $product['id'] ) : 0,
				'title' => $title,
				'url'   => isset( $product['url'] ) && is_string( $product['url'] ) ? $product['url'] : '',
			);
		}

		return $normalized;
	}

	private function cover_url( int $product_id ): string {
		if ( $product_id < 1 || ! function_exists( 'get_the_post_thumbnail_url' ) ) {
			return '';
		}

		$url = get_the_post_thumbnail_url( $product_id, 'woocommerce_thumbnail' );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Primes product thumbnail IDs and attachment metadata in two batches.
	 *
	 * Without this pass, rendering one cover per owned series can reopen one
	 * query for every attachment even though the resolver already batched the
	 * product catalogue itself.
	 *
	 * @param array<int,mixed> $series Raw series DTOs.
	 */
	private function prime_cover_images( array $series ): void {
		if ( ! function_exists( '_prime_post_caches' ) || ! function_exists( 'get_post_thumbnail_id' ) ) {
			return;
		}

		$product_ids = array();
		foreach ( $series as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$product_id = isset( $item['cover_product_id'] ) ? (int) $item['cover_product_id'] : 0;
			if ( $product_id > 0 ) {
				$product_ids[ $product_id ] = $product_id;
			}
		}

		if ( array() === $product_ids ) {
			return;
		}

		$product_ids = array_values( $product_ids );
		_prime_post_caches( $product_ids, false, true );

		$attachment_ids = array();
		foreach ( $product_ids as $product_id ) {
			$attachment_id = (int) get_post_thumbnail_id( $product_id );
			if ( $attachment_id > 0 ) {
				$attachment_ids[ $attachment_id ] = $attachment_id;
			}
		}

		if ( array() !== $attachment_ids ) {
			_prime_post_caches( array_values( $attachment_ids ), false, true );
		}
	}

	private function edition_state_label( string $state, int $total = 0 ): string {
		if ( 'completa' === $state && 1 === $total ) {
			return 'Edición de tomo único';
		}

		$labels = array(
			'completa'       => 'Edición finalizada',
			'en_publicacion' => 'Edición en publicación',
			'en_pausa'       => 'Edición en pausa',
			'cancelada'      => 'Edición cancelada',
			'al_dia'         => 'Edición al día con la obra',
		);

		return $labels[ $state ] ?? 'Estado editorial por confirmar';
	}
}
