<?php
/**
 * El precio editorial no pudo determinarse con autoridad.
 *
 * Se lanza ÚNICAMENTE desde el proveedor del filtro `akb_search_effective_price`
 * (ver `SearchPriceProvider`), que es el que alimenta el índice de Akibara
 * Search. El resto del sitio —los filtros de precio de WooCommerce— sigue
 * degradando a precio base sin excepciones: mostrar un precio sin descuento en
 * una página es recuperable en el siguiente request, sellarlo en un documento
 * del índice no.
 *
 * Contrato para el consumidor (Search): esta excepción significa
 * «no publiques ni caches un precio para este producto». Abortar el documento y
 * reintentar es correcto; capturarla y usar el precio base NO lo es —sería
 * exactamente el defecto que este tipo existe para impedir.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.99.14
 */
final class PriceAuthorityUnavailable extends \RuntimeException {}
