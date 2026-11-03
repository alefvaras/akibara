<?php
/**
 * Títulos editables de los dos bloques de recomendaciones de la ficha.
 *
 * También te recomendamos presenta continuidad de la misma serie mediante
 * UpsellsDisplay. Productos relacionados ofrece entradas de otras series con
 * RelatedProducts. Los valores de fábrica se conservan hasta que el dueño
 * escriba otro texto en Akibara → Textos.
 *
 * El copy obligatorio de una línea se sanea mediante Text::one_line() tanto al
 * guardar como al leer. Una fila vacía o compuesta sólo de etiquetas vuelve al
 * valor predeterminado; no deja un bloque de tarjetas sin título.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Recommendations;

use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class Headings {

	/** Título del bloque de upsells de la ficha. */
	public const OPTION_UPSELLS = 'akb_copy_reco_upsells_heading';

	/** Título del bloque de relacionados de la ficha. */
	public const OPTION_RELATED = 'akb_copy_reco_related_heading';

	/** El que WooCommerce pintaba en español (`You may also like&hellip;`). */
	public const DEFAULT_UPSELLS = 'También te recomendamos…';

	/** El que WooCommerce pintaba en español (`Related products`). */
	public const DEFAULT_RELATED = 'Productos relacionados';

	public function register(): void {
		add_filter( 'woocommerce_product_upsells_products_heading', array( self::class, 'upsells' ) );
		add_filter( 'woocommerce_product_related_products_heading', array( self::class, 'related' ) );
	}

	/**
	 * option → de fábrica, para CopyManager (exposición y saneado obligatorio).
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			self::OPTION_UPSELLS => self::DEFAULT_UPSELLS,
			self::OPTION_RELATED => self::DEFAULT_RELATED,
		);
	}

	/**
	 * @internal Callback de `woocommerce_product_upsells_products_heading`.
	 */
	public static function upsells(): string {
		return Text::one_line_option( self::OPTION_UPSELLS, self::DEFAULT_UPSELLS );
	}

	/**
	 * @internal Callback de `woocommerce_product_related_products_heading`.
	 */
	public static function related(): string {
		return Text::one_line_option( self::OPTION_RELATED, self::DEFAULT_RELATED );
	}
}
