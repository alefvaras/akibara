<?php
/**
 * StockLabels — fuente ÚNICA search-side del vocabulario de stock (badges/facetas).
 *
 * Antes el buscador DUPLICABA la palabra de stock en dos sitios independientes:
 * `ProductPresenter::STOCK_BADGE` (0/1/2 → label+tono del autocomplete) y
 * `FacetRegistry::DISPONIBILIDAD_LABELS` (status → label de la faceta). Esta
 * clase los colapsa en un solo lugar y los enchufa al MISMO punto de override
 * que expone `Stock\StockStatus::label()` de akibara-business: el filtro
 * `akb_stock_status_label`. Así el vocabulario Disponible/Agotado tiene
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
 * @api
 * @psalm-suppress TypeDoesNotContainType
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
		'outofstock'  => 'Agotado',
	);

	private const STATUS_TONE = array(
		'instock'     => 'ok',
		'outofstock'  => 'out',
	);

	/**
	 * Palabra visible de un estado de stock, vía el filtro compartido.
	 *
	 * Fail-closed: un `$status` desconocido cae a la palabra de 'outofstock'
	 * ('Agotado') ANTES de aplicar el filtro, igual que `StockStatus::label()`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status Uno de instock|outofstock (otro → fail-closed).
	 * @return string Palabra visible del estado.
	 */
	public static function label( string $status ): string {
		$default = self::DEFAULT_LABELS[ $status ] ?? self::DEFAULT_LABELS['outofstock'];

		/**
		 * Filtra la palabra visible de un estado de stock.
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
	 * Estado COMERCIAL de un producto (disponible o agotado).
	 *
	 * @since 0.1.45
	 *
	 * @param string $status  Uno de instock|outofstock (otro → fail-closed a outofstock).
	 * @param int    $post_id Producto.
	 * @return array{value: string, label: string, tone: string}
	 */
	public static function availability( string $status, int $post_id ): array {
		unset( $post_id );
		$known = isset( self::DEFAULT_LABELS[ $status ] ) ? $status : 'outofstock';
		$tone  = self::STATUS_TONE[ $known ];

		return array(
			'value' => $known,
			'label' => self::label( $known ),
			'tone'  => $tone,
		);
	}
}
