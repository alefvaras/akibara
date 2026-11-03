<?php
/**
 * Tests del precio de una card AGOTADA (bug medido en prod el 2026-08-09).
 *
 * Los tomos 11, 08 y 06 de `/serie/the-climber/` —agotados y con `sale_price`—
 * mostraban «$14.000» tachado en gris y NINGÚN precio vigente, mientras el
 * lector de pantalla sí oía «El precio actual es: $13.500». Estos tests fijan
 * las cuatro ramas del contrato: agotado con oferta (un solo importe, el
 * vigente), agotado sin oferta (idéntico a antes), disponible y preventa
 * (intactos, la preventa es dominio de OrderPreventa\PreventaUX).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\LoopEnhancer;

use Akibara\Business\Modules\LoopEnhancer\LoopEnhancer;
use Akibara\Business\Modules\OrderPreventa\PreventaUX;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Modules\TaxonomyDiscount\PriceFilters;
use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OutOfStockPriceTest extends TestCase {

	/**
	 * El html de oferta EXACTO que producción servía para el tomo 11 (copiado del
	 * HTML capturado el 2026-08-09, con las entidades ya decodificadas). Se usa
	 * literal a propósito: si WooCommerce cambia la forma de
	 * `wc_format_sale_price()`, los tests que dependen de esta forma deben
	 * enterarse acá y no en producción.
	 */
	private const SALE_HTML = '<del aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>'
		. '<span class="woocommerce-Price-currencySymbol">$</span>14.000</bdi></span></del>'
		. ' <span class="screen-reader-text">El precio original era: $14.000.</span>'
		. '<ins aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>'
		. '<span class="woocommerce-Price-currencySymbol">$</span>13.500</bdi></span></ins>'
		. '<span class="screen-reader-text">El precio actual es: $13.500.</span>';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		/*
		 * Defaults DETERMINISTAS en setUp: mockear una función la define para todo
		 * el proceso, así que dejarlas dentro de un test las volvería dependientes
		 * del orden (verde en orden natural, rojo con --order-by=random).
		 */
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value, ...$args ) => $value
		);
		Functions\when( 'wc_price' )->alias(
			static fn( $amount ): string => '<span class="woocommerce-Price-amount amount"><bdi>'
				. '<span class="woocommerce-Price-currencySymbol">$</span>'
				. number_format( (float) $amount, 0, ',', '.' ) . '</bdi></span>'
		);
		/*
		 * Espeja el default real de WC: sin `$args['price']` muestra `get_price()`.
		 * El módulo usa esta función —y no `wc_price( get_price() )` a secas— para
		 * no divergir de la rama no-oferta de WC core si la tienda pasara a mostrar
		 * precios sin IVA.
		 */
		Functions\when( 'wc_get_price_to_display' )->alias(
			static fn( $product, array $args = array() ) => $args['price'] ?? $product->get_price()
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Producto de prueba con el precio ya resuelto (el vigente, $13.500) y el
	 * regular escrito ($14.000): la forma real de los tomos agotados en oferta.
	 *
	 * @param string $stock_status Estado de stock crudo.
	 * @param bool   $on_sale      ¿WooCommerce lo considera en oferta?
	 * @param string $price        Precio vigente que devuelve `get_price()`.
	 * @param string $type         Tipo de producto WC.
	 */
	private function product(
		string $stock_status,
		bool $on_sale,
		string $price = '13500',
		string $type = 'simple'
	): PricedProductStub {
		$product                    = new PricedProductStub( $stock_status );
		$product->akb_on_sale       = $on_sale;
		$product->akb_price         = $price;
		$product->akb_type          = $type;
		$product->akb_regular_price = '14000';
		$product->akb_sale_price    = $price;

		return $product;
	}

	/**
	 * @param string $stock_status Estado de stock crudo.
	 * @param bool   $on_sale      ¿En oferta?
	 * @param string $html         HTML de entrada que WooCommerce ya construyó.
	 */
	private function filtered(
		string $stock_status,
		bool $on_sale,
		string $html,
		string $price = '13500',
		string $type = 'simple'
	): string {
		return ( new LoopEnhancer() )->simplify_out_of_stock_price_html(
			$html,
			$this->product( $stock_status, $on_sale, $price, $type )
		);
	}

	/**
	 * El caso del bug: agotado + oferta. Un solo importe y que sea el VIGENTE.
	 *
	 * @testdox Should render a single current amount for an out-of-stock product on sale.
	 */
	public function test_out_of_stock_on_sale_keeps_only_the_current_amount(): void {
		$html = $this->filtered( StockStatus::OUT_OF_STOCK, true, self::SALE_HTML );

		self::assertStringContainsString( '13.500', $html, 'El importe visible debe ser el vigente.' );
		self::assertStringNotContainsString(
			'14.000',
			$html,
			'El precio tachado era el ÚNICO visible en prod: un precio anterior sin sustituto no comunica nada.'
		);
		self::assertStringNotContainsString( '<del', $html );
		self::assertStringNotContainsString( '<ins', $html );
	}

	/**
	 * La otra mitad del bug: la capa accesible narraba una oferta que el usuario
	 * vidente no veía. Los dos `screen-reader-text` son HERMANOS del `<ins>`, así
	 * que ningún `display:none` del theme los alcanzaba.
	 *
	 * @testdox Should sweep the original/current price screen-reader strings.
	 */
	public function test_out_of_stock_on_sale_drops_the_sale_screen_reader_strings(): void {
		$html = $this->filtered( StockStatus::OUT_OF_STOCK, true, self::SALE_HTML );

		self::assertStringNotContainsString( 'screen-reader-text', $html );
		self::assertStringNotContainsString( 'El precio original era', $html );
		self::assertStringNotContainsString( 'El precio actual es', $html );
	}

	/**
	 * Contrato de FORMA, no solo de contenido: la card agotada-en-oferta debe
	 * quedar estructuralmente IDÉNTICA a la agotada-sin-oferta (el tomo 03, que
	 * ya rendía bien). Es lo que permite que el theme escriba una sola regla para
	 * el precio de un agotado sin conocer un selector nuevo — y lo que este test
	 * pone en rojo si alguien envuelve el importe en una clase propia.
	 *
	 * @testdox Should emit markup identical to the out-of-stock product that was never on sale.
	 */
	public function test_out_of_stock_on_sale_matches_the_plain_out_of_stock_markup(): void {
		$plain = '<span class="woocommerce-Price-amount amount"><bdi>'
			. '<span class="woocommerce-Price-currencySymbol">$</span>13.500</bdi></span>';

		self::assertSame(
			$plain,
			$this->filtered( StockStatus::OUT_OF_STOCK, true, self::SALE_HTML ),
			'Sin wrapper propio: las dos ramas del agotado comparten markup.'
		);
		self::assertSame(
			$plain,
			$this->filtered( StockStatus::OUT_OF_STOCK, false, $plain ),
			'Y la rama sin oferta sale intacta, no reconstruida.'
		);
	}

	/**
	 * `.akb-price--preventa` NO se puede reusar (PreventaUX hace return temprano
	 * si no es preventa) y el `<ins>` recuperaría la estampilla roja completa.
	 * Este test fija que no aparezca ninguna clase de precio propia.
	 *
	 * @testdox Should not introduce any custom price class the theme would have to learn.
	 */
	public function test_output_carries_no_custom_price_class(): void {
		$html = $this->filtered( StockStatus::OUT_OF_STOCK, true, self::SALE_HTML );

		self::assertStringNotContainsString( 'akb-price--preventa', $html );
		self::assertStringNotContainsString( 'akb-price', $html );
	}

	/**
	 * @testdox Should leave untouched every price html that is not an out-of-stock sale.
	 *
	 * @dataProvider untouched_cases
	 *
	 * @param string $stock_status Estado de stock crudo.
	 * @param bool   $on_sale      ¿En oferta?
	 * @param string $html         HTML de entrada.
	 * @param string $why          Motivo del contrato (mensaje de fallo).
	 */
	public function test_other_branches_pass_through_untouched(
		string $stock_status,
		bool $on_sale,
		string $html,
		string $why
	): void {
		self::assertSame( $html, $this->filtered( $stock_status, $on_sale, $html ), $why );
	}

	/**
	 * @return array<string, array{string, bool, string, string}>
	 */
	public function untouched_cases(): array {
		$plain = '<span class="woocommerce-Price-amount amount"><bdi>'
			. '<span class="woocommerce-Price-currencySymbol">$</span>14.000</bdi></span>';

		return array(
			'agotado sin oferta' => array(
				StockStatus::OUT_OF_STOCK,
				false,
				$plain,
				'Sin oferta el html YA es el importe simple: reconstruirlo sería reimplementar WC core para nada.',
			),
			'disponible en oferta' => array(
				StockStatus::IN_STOCK,
				true,
				self::SALE_HTML,
				'Una oferta real sobre un producto comprable SÍ es una rebaja: el <del>/<ins> le corresponde.',
			),
			'disponible sin oferta' => array(
				StockStatus::IN_STOCK,
				false,
				$plain,
				'',
			),
			'preventa en oferta' => array(
				StockStatus::ON_BACKORDER,
				true,
				self::SALE_HTML,
				'La preventa es dominio EXCLUSIVO de OrderPreventa\PreventaUX (prio 25): acá no se toca.',
			),
			'estado desconocido' => array(
				'algo-que-woo-no-conoce',
				true,
				self::SALE_HTML,
				'El predicado es outofstock ESTRICTO, no "todo lo que no está in stock".',
			),
		);
	}

	/**
	 * En un variable el price_html es un RANGO («$X – $Y»): reducirlo a un solo
	 * importe borraría el mínimo o el máximo. Hoy el catálogo es 100% simple; el
	 * guard existe para cuando no lo sea.
	 *
	 * @testdox Should not collapse the price range of a variable product.
	 */
	public function test_variable_product_keeps_its_price_range(): void {
		$range = '<span class="woocommerce-Price-amount amount">$13.500</span>'
			. ' &ndash; <span class="woocommerce-Price-amount amount">$27.000</span>';

		self::assertSame(
			$range,
			$this->filtered( StockStatus::OUT_OF_STOCK, true, $range, '13500', 'variable' )
		);
	}

	/**
	 * Sin precio, WC devuelve lo que filtre `woocommerce_empty_price_html`. Pasar
	 * eso por `wc_price()` imprimiría un «$0» inventado sobre un producto que no
	 * tiene precio publicado.
	 *
	 * @testdox Should not invent a zero price when the product has no price at all.
	 */
	public function test_product_without_a_numeric_price_is_left_alone(): void {
		$empty = '<span class="akb-precio-a-confirmar">Precio a confirmar</span>';

		$html = $this->filtered( StockStatus::OUT_OF_STOCK, true, $empty, '' );

		self::assertSame( $empty, $html );
		self::assertStringNotContainsString( '$0', $html );
	}

	/**
	 * `woocommerce_get_price_html` es un filtro público: cualquiera puede
	 * dispararlo con algo que no sea un WC_Product.
	 *
	 * @testdox Should return the html unchanged when the second argument is not a product.
	 */
	public function test_non_product_argument_is_returned_unchanged(): void {
		$enhancer = new LoopEnhancer();

		self::assertSame( self::SALE_HTML, $enhancer->simplify_out_of_stock_price_html( self::SALE_HTML, null ) );
		self::assertSame( '', $enhancer->simplify_out_of_stock_price_html( null, 'no soy un producto' ) );
	}

	/**
	 * Contrato de ORDEN entre los tres filtros de la casa sobre el mismo hook.
	 * Son disjuntos por `stock_status`, así que un empate no rompe nada HOY —
	 * pero correr antes de TaxonomyDiscount dejaría que la campaña volviera a
	 * pegar su `<del>`/`<ins>` encima, y el bug reaparecería solo en los
	 * productos con descuento por taxonomía: el peor modo de falla posible.
	 *
	 * @testdox Should run after the taxonomy-discount and preventa price filters.
	 */
	public function test_runs_after_the_other_price_html_filters(): void {
		$enhancer = new LoopEnhancer();
		$enhancer->register();

		$preventa = new PreventaUX();
		$preventa->register();

		// PriceFilters real pide un DiscountResolver que register() no toca.
		$discounts = ( new ReflectionClass( PriceFilters::class ) )->newInstanceWithoutConstructor();
		$discounts->register();

		$mine     = Filters\has( 'woocommerce_get_price_html', array( $enhancer, 'simplify_out_of_stock_price_html' ) );
		$theirs   = Filters\has( 'woocommerce_get_price_html', array( $preventa, 'rewrite_preventa_price_html' ) );
		$campaign = Filters\has( 'woocommerce_get_price_html', array( $discounts, 'filter_price_html' ) );

		self::assertSame( 30, $mine );
		self::assertGreaterThan( $campaign, $mine, 'Debe leer el precio que la campaña ya resolvió, no adelantarse a ella.' );
		self::assertGreaterThan( $theirs, $mine, 'Última palabra sobre el precio del agotado.' );
	}

	/**
	 * El allowlist de UCSS no cambia con este arreglo: el filtro no crea ni
	 * elimina ninguna clase (las de `wc_price()` son de WC core y las emite toda
	 * card). Lo que sí debe seguir protegido es el gancho del que cuelga el theme
	 * para vestir la card agotada.
	 *
	 * @testdox Should keep shielding the out-of-stock badge hook the theme styles from.
	 */
	public function test_ucss_allowlist_still_shields_the_out_of_stock_hook(): void {
		$selectors = ( new LoopEnhancer() )->allowlist_ucss_selectors( array() );

		self::assertContains( '.akb-badge--out_of_stock', $selectors );
	}
}

/**
 * Doble de producto con precio.
 *
 * `get_price()`/`get_price_suffix()` NO pueden vivir en el stub compartido de
 * `tests/wp-stubs.php`: ya hay dobles en la suite que declaran `get_price()` con
 * firmas incompatibles entre sí (`get_price( string $context = 'view' ): string`
 * en uno, `get_price(): string` en otro), así que ponerlo en el padre rompería a
 * uno de los dos por covarianza. Misma trampa que el stub documenta para
 * `get_id()`. Como hoja, esta clase es segura.
 */
final class PricedProductStub extends \WC_Product {

	public string $akb_price = '';

	public string $akb_price_suffix = '';

	/**
	 * @param string $context Contexto WC ('view' | 'edit').
	 */
	public function get_price( string $context = 'view' ): string {
		unset( $context );
		return $this->akb_price;
	}

	public function get_price_suffix(): string {
		return $this->akb_price_suffix;
	}
}
