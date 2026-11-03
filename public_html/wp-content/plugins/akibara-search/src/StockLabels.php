<?php
/**
 * StockLabels — fuente ÚNICA search-side del vocabulario de stock (badges/facetas).
 *
 * Antes el buscador DUPLICABA la palabra de stock en dos sitios independientes:
 * `ProductPresenter::STOCK_BADGE` (0/1/2 → label+tono del autocomplete) y
 * `FacetRegistry::DISPONIBILIDAD_LABELS` (status → label de la faceta). Esta
 * clase los colapsa en un solo lugar y los enchufa al MISMO punto de override
 * que expone `Stock\StockStatus::label()` de akibara-business: el filtro
 * `akb_stock_status_label`. Así el vocabulario Disponible/Preventa/Agotado tiene
 * un único override para todo el sitio (tienda + buscador).
 *
 * SELF-CONTAINED a propósito (independencia del plugin extraído en v1.60.0): el
 * mapa por defecto vive ACÁ, nunca se hace `use Akibara\Business\...`. Si
 * akibara-business está ausente o desactivado (p.ej. cuarentena Imunify post
 * deploy), nadie engancha el filtro y `apply_filters()` devuelve este default
 * local — el buscador sigue etiquetando correcto, jamás tira fatal. El filtro es
 * el contrato compartido; la palabra base no depende del otro plugin.
 *
 * ┌─ CONTRATO CRUZADO `akb_stock_status_label` ───────────────────────────────────┐
 * │ Roles de integración:                                                         │
 * │   · akibara-search   = CONSUMIDOR. Conserva `DEFAULT_LABELS` como FALLBACK    │
 * │     local defensivo y siempre invoca `apply_filters()`.                       │
 * │   · akibara-business = PROVEEDOR. Registra `add_filter` sobre este hook y     │
 * │     retorna la etiqueta vigente de `Stock\StockStatus`. Si no está disponible,│
 * │     el buscador opera con su fallback garantizando degradación elegante.      │
 * └───────────────────────────────────────────────────────────────────────────────┘
 *
 * SOLO la palabra y el tono (clase CSS): nada de color/ícono. El color vive en
 * cada CSS (`.akb-badge-{tone}` en search-suggest.css). Fail-closed a 'Agotado'
 * (el estado más conservador: nunca promete disponibilidad no verificada).
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class StockLabels {

	/**
	 * Palabra por estado de stock — DEFAULT LOCAL (self-contained).
	 *
	 * Espeja `StockStatus::LABELS` de akibara-business, pero SIN acoplarse: es la
	 * palabra base que se pasa al filtro `akb_stock_status_label` como default.
	 * Con business presente el filtro puede sobreescribirla; sin business, esta
	 * es la fuente. Keyed por el `_stock_status` crudo de WooCommerce.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_LABELS = array(
		'instock'     => 'Disponible',
		// «A pedido» y no «Preventa» (1.109.0 de business): `onbackorder` agrupa
		// dos estados comerciales y el default del grupo es el pedido a editorial
		// (la realidad de TODO el catálogo al separarse). La palabra «Preventa»
		// la resuelve por producto `availability()`, vía el filtro
		// `akb_search_availability_value` que engancha business cuando detecta
		// un lanzamiento pendiente. Este mapa NUNCA dice «Preventa» por sí solo.
		'onbackorder' => 'A pedido',
		'outofstock'  => 'Agotado',
	);

	/**
	 * Estado de stock → tono del badge = sufijo de la clase CSS `.akb-badge-{tone}`
	 * (definida en search-suggest.css y consumida por search-suggest.js). Es
	 * presentación estable: NO se toca al unificar el vocabulario.
	 *
	 * @var array<string, string>
	 */
	/**
	 * Palabra por defecto de los estados COMERCIALES que no son un
	 * `_stock_status` de WooCommerce.
	 *
	 * Hoy sólo «preventa», que es la mitad de `onbackorder` que `availability()`
	 * separa. Vive aparte de `DEFAULT_LABELS` para que el mapa de stock crudo
	 * siga siendo exactamente el de WooCommerce.
	 *
	 * @var array<string, string>
	 */
	private const ESTADOS_COMERCIALES = array(
		'preventa' => 'Preventa',
	);

	private const STATUS_TONE = array(
		'instock'     => 'ok',
		'onbackorder' => 'order',
		'outofstock'  => 'out',
	);

	/**
	 * Palabra visible de un estado de stock, vía el filtro compartido.
	 *
	 * Fail-closed: un `$status` desconocido cae a la palabra de 'outofstock'
	 * ('Agotado') ANTES de aplicar el filtro, igual que `StockStatus::label()`.
	 * El filtro `akb_stock_status_label` es el único override del vocabulario;
	 * recibe la palabra ya resuelta y el status crudo (mismo contrato que
	 * business, para que un único hook gobierne tienda + buscador).
	 *
	 * @since 0.1.0
	 *
	 * @param string $status Uno de instock|onbackorder|outofstock (otro → fail-closed).
	 * @return string Palabra visible del estado.
	 */
	public static function label( string $status ): string {
		$default = self::DEFAULT_LABELS[ $status ]
			?? ( self::ESTADOS_COMERCIALES[ $status ] ?? self::DEFAULT_LABELS['outofstock'] );

		/**
		 * Filtra la palabra visible de un estado de stock. Mismo hook que
		 * `Akibara\Business\Modules\Stock\StockStatus::label()`: un único punto
		 * de override del vocabulario Disponible/Preventa/Agotado para todo el
		 * sitio. El buscador aporta su propio default local (self-contained).
		 *
		 * @since 0.1.0
		 *
		 * @param string $default Palabra resuelta (fail-closed a 'Agotado' si el status es desconocido).
		 * @param string $status  Estado de stock crudo consultado.
		 */
		$label = apply_filters( 'akb_stock_status_label', $default, $status );
		if ( ! is_string( $label ) || '' === trim( $label ) || strlen( $label ) > 100
			|| 1 !== preg_match( '//u', $label ) || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $label ) ) {
			throw new \UnexpectedValueException( 'Stock label provider returned an invalid value.' );
		}

		return $label;
	}

	/**
	 * Estado COMERCIAL de un producto: parte `onbackorder` en `a_pedido` |
	 * `preventa` por el MISMO contrato que ya usaba la faceta, y devuelve la
	 * palabra y el tono del badge.
	 *
	 * Es el ÚNICO resolvedor, consumido por las DOS superficies del buscador
	 * (la faceta que materializa DocumentBuilder y el badge del autocomplete
	 * que pinta ProductPresenter). Antes el badge se resolvía por `stock_rank`
	 * y nunca podía decir «Preventa»: el desplegable rotulaba «A pedido» un
	 * tomo que la faceta del mismo índice ya sabía que era preventa.
	 *
	 * Fail-safe: cualquier retorno del filtro que no sea exactamente
	 * `'preventa'` degrada a `a_pedido`, que es el default real del catálogo.
	 * Self-contained: sin business enganchado, todo reservable es `a_pedido`.
	 *
	 * @since 0.1.45
	 *
	 * @param string $status  Uno de instock|onbackorder|outofstock (otro → fail-closed a outofstock).
	 * @param int    $post_id Producto, para que business decida por ficha.
	 * @return array{value: string, label: string, tone: string}
	 */
	public static function availability( string $status, int $post_id ): array {
		$known = isset( self::DEFAULT_LABELS[ $status ] ) ? $status : 'outofstock';
		$tone  = self::STATUS_TONE[ $known ];

		if ( 'onbackorder' !== $known ) {
			return array(
				'value' => $known,
				'label' => self::label( $known ),
				'tone'  => $tone,
			);
		}

		/**
		 * Filtra el estado comercial de un producto reservable. business devuelve
		 * `'preventa'` cuando el tomo tiene un lanzamiento pendiente
		 * (`Availability::is_preventa`); cualquier otra cosa es «a pedido».
		 *
		 * El proveedor vive en akibara-business (SearchAvailabilityBridge); si se
		 * cambia el momento en que se invoca, hay que tocar también su docblock.
		 *
		 * @since 0.1.36 Nace con la separación a pedido/preventa (DocumentBuilder lo aplicaba en la faceta).
		 * @since 0.1.45 Se resuelve aquí, y también en el autocomplete vivo.
		 * @param string $value   Default `'a_pedido'`.
		 * @param int    $post_id Producto.
		 */
		$resolved = apply_filters( 'akb_search_availability_value', 'a_pedido', $post_id );

		if ( 'preventa' !== $resolved ) {
			return array( 'value' => 'a_pedido', 'label' => self::label( $known ), 'tone' => $tone );
		}

		// La palabra de «preventa» pasa por el MISMO hook del vocabulario, con
		// el estado comercial como segundo argumento. Era el único literal que
		// quedaba: el dueño renombraba «Preventa» en Textos, la tienda obedecía
		// y el badge del buscador seguía diciendo lo de antes. Sin business
		// enganchado —o con una versión que no conozca este estado— el filtro
		// devuelve este mismo default, así que los dos rieles pueden ir por
		// separado sin romperse (0.1.67).
		return array(
			'value' => 'preventa',
			'label' => self::label( 'preventa' ),
			'tone'  => 'pre',
		);
	}
}
