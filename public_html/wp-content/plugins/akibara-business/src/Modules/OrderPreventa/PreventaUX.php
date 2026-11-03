<?php
/**
 * Akibara OrderPreventa — Mejoras UX para el flujo de Preventa.
 *
 * Implementa las mejores prácticas mundiales de E-commerce para Pre-orders:
 * 1. Cambia el CTA de "Añadir al carrito" a "Reservar en Preventa" dinámicamente.
 * 2. Corrige el precio de la preventa: le quita la semántica de rebaja que le
 *    impone `sale_price` y nombra —visible y de forma accesible— la dirección
 *    real del precio (ver `rewrite_preventa_price_html`).
 * La presentación de preventa en carrito/checkout pertenece exclusivamente al
 * adaptador first-party de WooCommerce Blocks.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\LoopEnhancer\SaleDiscount;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class PreventaUX {

	/** Texto del botón de una PREVENTA real, editable en Akibara → Textos. */
	public const OPTION_CTA_PREVENTA = 'akb_copy_cta_preventa';

	/** Valor de fábrica del botón de preventa. */
	public const DEFAULT_CTA_PREVENTA = 'Reservar en Preventa';

	public function register(): void {
		// Cambiar texto del botón "Añadir al carrito"
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'change_add_to_cart_text' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'change_add_to_cart_text' ), 10, 2 );
		// WCAG 2.5.3: el nombre accesible del botón debe contener el label visible
		// ("Reservar en Preventa"), no el "Añadir al carrito «X»" de WC core.
		add_filter( 'woocommerce_product_add_to_cart_description', array( $this, 'change_add_to_cart_description' ), 10, 2 );

		// Prio 25: después del 20 de TaxonomyDiscount\PriceFilters, para leer el precio
		// ya resuelto por cualquier campaña de descuento por taxonomía.
		add_filter( 'woocommerce_get_price_html', array( $this, 'rewrite_preventa_price_html' ), 25, 2 );

		// UCSS: mismo patrón que LoopEnhancer::allowlist_ucss_selectors y por el
		// mismo motivo — el UCSS de LiteSpeed poda por lo que ve en el HTML que
		// muestrea, y estas dos clases son CONDICIONALES (solo aparecen en una card
		// de preventa con incentivo bajo el umbral). Si el muestreo cae en una URL
		// sin preventa a la vista se poda la regla, y las consecuencias no son
		// cosméticas: sin `.akb-price--preventa` vuelve la estampilla roja de
		// liquidación sobre las 472 cards de preventa —el mismo disfraz que
		// loop.css retira a propósito— y sin `.akb-preventa-alza` la nota de precio
		// queda como texto suelto dentro del `<span class="price">`. Hoy es inerte
		// (`litespeed.conf.optm-ucss` está vacío); se declara para que activar UCSS
		// no reintroduzca en silencio ninguna de las dos.
		add_filter( 'litespeed_ucss_whitelist', array( $this, 'allowlist_ucss_selectors' ), 20 );
	}

	/**
	 * Protege del tree-shaking de UCSS las clases que este módulo emite.
	 *
	 * @param array<int, string> $selectors Selectores ya declarados por otros módulos.
	 * @return array<int, string>
	 */
	public function allowlist_ucss_selectors( array $selectors ): array {
		$selectors[] = '.akb-price--preventa';
		$selectors[] = '.akb-preventa-alza';

		return array_values( array_unique( $selectors ) );
	}

	/**
	 * Presenta el precio de reserva y su relación con el precio regular.
	 *
	 * sale_price representa el importe vigente de reserva cuando es menor que el
	 * regular. El precio regular es el importe aplicable al llegar stock, no un
	 * precio anterior. Se evitan descripciones de liquidación o ahorros fijos.
	 *
	 * El marcado propio permite aplicar el mismo estilo en catálogo, PDP y Vista
	 * Rápida aunque no compartan el ancestro ul.products. El texto accesible anuncia
	 * primero el precio vigente. Una nota visible comunica después el precio al
	 * llegar stock; esa frase no se duplica en screen-reader-text.
	 *
	 * La nota sólo se emite si regular, leído en contexto edit, es distinto y mayor
	 * que el precio vigente. Usa small y el piso estructural de estimated-delivery.css
	 * para conservar jerarquía legible aunque el tema se despliegue por separado.
	 * El tema puede completar su estilo. note_belongs_here() limita las superficies:
	 * la nota no debe materializarse en el índice de búsqueda.
	 *
	 * @since 1.99.6
	 * @since 1.100.0 Micro-línea visible con la dirección del precio.
	 *
	 * @param string     $html    HTML del precio construido por WooCommerce.
	 * @param WC_Product $product Producto.
	 * @return string
	 */
	public function rewrite_preventa_price_html( $html, $product ): string {
		$html = (string) $html;

		$prices = self::reservation_prices( $product );
		if ( null === $prices ) {
			return $html;
		}
		$regular = $prices['regular'];
		$sale    = $prices['sale'];

		// Fuera los screen-reader-text de WC. Se barren por patrón y no por literal
		// porque el texto está traducido (es_CL) y un literal se rompería al cambiar
		// de idioma o al actualizar WooCommerce.
		$limpio = preg_replace( '#<span class="screen-reader-text">.*?</span>#s', '', $html );
		if ( null === $limpio ) {
			return $html; // preg_replace falló (backtrack limit): mejor el original que nada.
		}

		// `wp_strip_all_tags` y no el html de `wc_price()`: dentro de la nota, un
		// `.amount` heredaría el tratamiento de precio del theme y volvería a
		// parecer un importe más. Acá el monto es parte de una frase, no un precio.
		// (La entidad `&#36;` del símbolo sobrevive: `esc_html()` no re-codifica
		// entidades ya formadas — las normaliza a `&#036;`, que es lo que sirve
		// producción hoy en este mismo string.)
		// La palabra sigue al estado comercial: «preventa» solo si el tomo aún no
		// sale; para el backlist a pedido el importe rebajado es el de la RESERVA.
		$palabra = Availability::is_preventa( $product ) ? 'preventa' : 'reserva';
		$lectura = sprintf( 'Precio de %s: %s.', $palabra, wp_strip_all_tags( wc_price( $sale ) ) );
		$alza    = sprintf( 'Al llegar el stock sube a %s', wp_strip_all_tags( wc_price( $regular ) ) );

		/**
		 * Filtra la micro-línea VISIBLE que nombra la dirección del precio de una
		 * preventa. Único punto de override del copy, con el mismo criterio que
		 * `akb_stock_status_label`: el vocabulario de cara al cliente se ajusta por
		 * filtro, no editando 472 cards.
		 *
		 * Devolver cadena vacía suprime la línea SIN tocar la capa accesible: el
		 * `screen-reader-text` sigue nombrando el importe vigente. Es el interruptor
		 * de apagado de una afirmación de precio ante el consumidor, disponible sin
		 * desplegar.
		 *
		 * @since 1.100.0
		 *
		 * @param string     $alza    Copy resuelto ("Al llegar el stock sube a $14.000").
		 * @param float      $regular Precio regular escrito por el dueño (contexto 'edit').
		 * @param float      $sale    Precio de preventa vigente (contexto 'edit').
		 * @param WC_Product $product Producto en preventa.
		 */
		$alza = (string) apply_filters( 'akibara_preventa_price_note', $alza, $regular, $sale, $product );

		$nota = ( '' === trim( $alza ) || ! $this->note_belongs_here() )
			? ''
			: '<small class="akb-preventa-alza">' . esc_html( $alza ) . '</small>';

		return '<span class="akb-price--preventa">'
			. $limpio
			. '<span class="screen-reader-text">' . esc_html( $lectura ) . '</span>'
			. $nota
			. '</span>';
	}

	/**
	 * Fuente compartida de la semántica de reserva; nunca modifica precios.
	 *
	 * @param mixed $product Producto que se va a presentar.
	 * @return array{regular:float,sale:float}|null Null para ofertas reales o sin incentivo.
	 */
	public static function reservation_prices( $product ): ?array {
		if ( ! $product instanceof WC_Product || ! StockStatus::is_reservable( $product ) ) {
			return null;
		}

		// Guard preventivo: en variables `get_regular_price()` del padre viene vacío y
		// el cálculo se caería en silencio. Hoy el catálogo es 100% simple.
		if ( ! $product->is_type( 'simple' ) || ! $product->is_on_sale() ) {
			return null;
		}

		// Contexto 'edit': salta los filtros de precio de TaxonomyDiscount, para leer lo
		// que el dueño escribió y no lo que una campaña calculó encima.
		$regular = (float) $product->get_regular_price( 'edit' );
		$sale    = (float) $product->get_sale_price( 'edit' );

		if ( $regular <= 0.0 || $sale <= 0.0 || $sale >= $regular ) {
			return null;
		}

		/*
		 * Si el descuento supera el umbral anti-trivial, ESO SÍ es una oferta de verdad:
		 * LoopEnhancer muestra el badge "−NN%" y la estampilla roja está justificada.
		 * Se lee el MISMO filtro Y el MISMO default que usa
		 * `LoopEnhancer\SaleDiscount::label()` —el umbral configurado por el dueño—
		 * en vez de repetir un literal: si listado y ficha no comparten la fuente,
		 * el sello aparece en uno y no en el otro.
		 */
		$pct     = (int) round( ( ( $regular - $sale ) / $regular ) * 100 );
		$min_pct = (int) apply_filters( 'akibara_loop_sale_badge_min_pct', SaleDiscount::configured_min_pct(), $product );

		if ( $pct >= $min_pct ) {
			return null;
		}

		return array( 'regular' => $regular, 'sale' => $sale );
	}

	/**
	 * ¿Este `price_html` se está pintando para un cliente que lo está mirando?
	 *
	 * ── La superficie que el docblock de arriba no revisó ──────────────────────
	 * La nota se emite desde `woocommerce_get_price_html`, que es GLOBAL: sale en
	 * toda superficie que imprima un precio, no sólo en el loop y en la PDP que se
	 * analizaron. La más sensible es el índice del buscador propio:
	 * `akibara-search/src/ProductPresenter.php` materializa
	 * `wp_kses_post( $product->get_price_html())` —y `wp_kses_post` conserva el
	 * `<small>` con su clase— en la columna `price_html`, y
	 * `assets/js/search-suggest.js` la inyecta CRUDA dentro de
	 * `.akb-suggest-meta`, que es un contenedor flex de UNA línea junto al badge.
	 * La fila de sugerencia pasaba de «$13.500 [PREVENTA]» a «$13.500 Al llegar el
	 * stock sube a $14.000 [PREVENTA]» en 40 px de alto y ~300 px de ancho: el
	 * precio se comprime y el badge se desplaza. Y como el HTML queda HORNEADO en
	 * el índice, la sugerencia mostraría una nota que ya se apagó por filtro hasta
	 * que ese producto se reindexe.
	 *
	 * El discriminante es `template_redirect`: lo dispara cualquier render de una
	 * página del front, y NO lo dispara ninguno de los caminos por los que se
	 * construye el índice — Action Scheduler corre por un loopback a
	 * `admin-ajax.php`, el rebuild por WP-CLI, y `save_post_product` en el admin
	 * sólo encola. Es más preciso que una lista de `is_shop()/is_product()`, que
	 * además dejaría sin nota a las cards de preventa del home.
	 *
	 * Carrito y checkout quedan fuera por contrato, no por tamaño: la presentación
	 * de preventa ahí «pertenece exclusivamente al adaptador first-party de
	 * WooCommerce Blocks» (docblock de este archivo), y una segunda voz sobre el
	 * mismo precio es exactamente lo que ese contrato evita.
	 *
	 * La Vista Rápida no se pierde nada: `quick-view.js` CLONA los nodos de la card
	 * (`card.querySelectorAll('.akb-badge, …,.price, …')`), o sea copia el markup
	 * que este mismo filtro ya produjo en el archive.
	 */
	private function note_belongs_here(): bool {
		if ( is_admin() || ! did_action( 'template_redirect' ) ) {
			return false;
		}

		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Nombra el estado comercial del reservable en su botón.
	 *
	 * Reservar en Preventa corresponde a una obra aún no publicada; A pedido,
	 * a una obra publicada que se incorpora al próximo pedido a la editorial.
	 * El texto debe concordar con el badge y el filtro de disponibilidad.
	 */
	public function change_add_to_cart_text( string $text, WC_Product $product ): string {
		if ( ! StockStatus::is_reservable( $product ) ) {
			return $text;
		}
		// Sin íconos en el CTA (el dueño los probó y los retiró el mismo día):
		// el botón nombra el ESTADO, igual que el badge y el filtro. Por eso el
		// caso «a pedido» usa la MISMA palabra que el badge —la que el dueño
		// escribe en Textos— y no una copia literal suya.
		return self::cta_de( $product );
	}

	/**
	 * Texto del botón para un reservable, con las palabras del dueño.
	 *
	 * @param WC_Product $product Producto reservable.
	 * @return string
	 */
	private static function cta_de( WC_Product $product ): string {
		if ( ! Availability::is_preventa( $product ) ) {
			return 'Reservar ' . mb_strtolower( Availability::palabra( Availability::A_PEDIDO ), 'UTF-8' );
		}

		$suyo = trim( (string) get_option( self::OPTION_CTA_PREVENTA, self::DEFAULT_CTA_PREVENTA ) );

		return '' === $suyo ? self::DEFAULT_CTA_PREVENTA : $suyo;
	}

	/**
	 * Alinea la descripción accesible del botón con el label visible del CTA
	 * (WCAG 2.5.3 label-in-name). El caso agotado lo cubre LoopEnhancer en este
	 * mismo filter.
	 */
	public function change_add_to_cart_description( string $description, WC_Product $product ): string {
		if ( ! StockStatus::is_reservable( $product ) ) {
			return $description;
		}
		return sprintf(
			'%s — «%s»',
			self::cta_de( $product ),
			$product->get_name()
		);
	}

}
