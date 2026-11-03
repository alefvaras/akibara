<?php
/**
 * Bordes del cálculo del precio: los casos que nadie prueba porque «no pasan».
 *
 * Campaña pedida por el dueño el 2026-09-06 («son errores iterar con qa pruebas
 * de borde y de todo tipo»). Este fichero cubre el dominio que más daño hace si
 * falla: el dinero. Un precio mal redondeado o un descuento que se pasa del tope
 * no da error en ninguna parte — simplemente le cobra otra cosa al cliente.
 *
 * Hoy el catálogo no tiene ningún precio que no sea múltiplo de 10 (medido en
 * producción: 0 de 1.631) y los decimales están en 0, así que varias de estas
 * combinaciones no se dan HOY. Están escritas igual, porque el día que se dé
 * será por un cambio de configuración del dueño y no habrá nadie mirando.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\DiscountResolver;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Tests\Support\AuthorityWpdb;
use Akibara\Business\Utilities\DiscountCeiling;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Product;

require_once dirname( __DIR__, 2 ) . '/Support/AuthorityWpdb.php';

final class BordesDeDineroTest extends TestCase {

	private const PRODUCT = 4242;
	private const TERM    = 501;

	private AuthorityWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb                 = new AuthorityWpdb();
		$this->wpdb->option_rows    = array(
			Settings::OPTION_ENABLED         => 'yes',
			Settings::OPTION_APPLY_TO        => Settings::APPLY_ALL,
			Settings::OPTION_SALE_POLICY     => Settings::POLICY_GREATEST_WINS,
			'woocommerce_price_num_decimals' => '0',
			'timezone_string'                => 'America/Santiago',
		);
		$this->wpdb->term_meta_rows = array( self::TERM => '10' );
		$this->wpdb->relationships  = array(
			self::PRODUCT => array( array( self::TERM, 'product_brand' ) ),
		);
		$this->wpdb->attribute_rows = array(
			array( 'attribute_id' => '7', 'attribute_name' => 'editorial', 'attribute_label' => 'Editorial' ),
		);

		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-09-06' );
		Functions\when( 'clean_object_term_cache' )->justReturn( null );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string $regular Precio normal.
	 * @param string $sale    Precio rebajado a mano, o cadena vacía.
	 * @param string $stock   Estado de stock.
	 */
	private function producto( string $regular, string $sale = '', string $stock = 'instock' ): WC_Product {
		$product                    = new WC_Product( $stock );
		$product->akb_id            = self::PRODUCT;
		$product->akb_regular_price = $regular;
		if ( '' !== $sale ) {
			// El doble lleva el precio rebajado y la bandera POR SEPARADO, igual
			// que WooCommerce: `is_on_sale()` no se deduce del precio. Marcar sólo
			// uno de los dos produce un producto que no existe en producción, y
			// con él dos «defectos» que no lo eran [medido al escribir esto].
			$product->akb_sale_price = $sale;
			$product->akb_on_sale    = true;
		}

		return $product;
	}

	/** Precio resuelto por el camino estricto, o null si no hay descuento. */
	private function precio( WC_Product $product ): ?float {
		$resolver = new DiscountResolver( new Settings() );
		$valor    = $resolver->resolve_strict( $product );

		self::assertTrue(
			$resolver->is_authoritative(),
			'La lectura tiene que ser autoritativa o el precio no vale: ' . (string) $resolver->authority_error()
		);

		return null === $valor ? null : (float) $valor;
	}

	// ---------------------------------------------------------------- bordes

	/**
	 * Un término con 0% no es «sin configurar»: es una decisión explícita del
	 * dueño de no descontar esa editorial. No puede acabar aplicando otro.
	 *
	 * @testdox Un término con 0% no descuenta nada.
	 */
	public function test_zero_percent_discounts_nothing(): void {
		$this->wpdb->term_meta_rows = array( self::TERM => '0' );

		self::assertNull( $this->precio( $this->producto( '10000' ) ) );
	}

	/**
	 * El tope duro es el último muro entre una campaña mal escrita y vender bajo
	 * costo. Con el término al máximo, el precio no puede bajar del 10%.
	 *
	 * @testdox Con el término al 90% se aplica el tope de la tienda, no el 90.
	 */
	public function test_the_store_ceiling_wins_over_the_term(): void {
		$this->wpdb->term_meta_rows = array( self::TERM => '90' );

		$precio = $this->precio( $this->producto( '10000' ) );

		self::assertNotNull( $precio );
		$maximo_descontable = 10000.0 * ( 1 - ( DiscountCeiling::DEFAULT_PCT / 100 ) );
		self::assertSame(
			$maximo_descontable,
			$precio,
			'El tope de la tienda tiene que recortar el porcentaje del término, no al revés.'
		);
	}

	/**
	 * Un tomo sin precio no es un tomo gratis.
	 *
	 * @testdox Un producto con precio 0 no entra en el cálculo.
	 */
	public function test_a_zero_price_is_left_alone(): void {
		$precio = $this->precio( $this->producto( '0' ) );

		self::assertTrue(
			null === $precio || 0.0 === $precio,
			'Descontar sobre 0 no puede producir un número negativo ni un precio inventado.'
		);
	}

	/**
	 * @testdox Un producto sin precio no produce un precio.
	 */
	public function test_an_empty_price_produces_no_price(): void {
		$precio = $this->precio( $this->producto( '' ) );

		self::assertTrue(
			null === $precio || 0.0 === $precio,
			'Sin precio normal no hay nada que descontar.'
		);
	}

	/**
	 * El borde del redondeo, que es el que le cobraría de más a alguien. Con
	 * decimales en 0, un precio que al descontar caiga en «.5» tiene que
	 * resolverse SIEMPRE igual, y el número tiene que ser el mismo que publique
	 * cualquier otra superficie.
	 *
	 * @testdox Un descuento que cae en medio peso redondea de forma estable.
	 */
	public function test_a_half_peso_rounds_the_same_way_every_time(): void {
		$this->wpdb->term_meta_rows = array( self::TERM => '5' );

		// 10.101 al 5% = 9.595,95 → con 0 decimales, 9.596.
		$primero = $this->precio( $this->producto( '10101' ) );
		$segundo = $this->precio( $this->producto( '10101' ) );

		self::assertSame( $primero, $segundo, 'Dos lecturas del mismo producto no pueden dar precios distintos.' );
		self::assertNotNull( $primero );
		self::assertSame(
			round( 10101.0 * 0.95, 0 ),
			$primero,
			'El redondeo tiene que ser el de la configuración de la tienda, sin criterio propio.'
		);
	}

	/**
	 * @testdox El precio resuelto nunca es mayor que el precio normal.
	 */
	public function test_the_result_is_never_more_expensive(): void {
		foreach ( array( '1', '999', '10000', '123456' ) as $regular ) {
			$precio = $this->precio( $this->producto( $regular ) );
			if ( null === $precio ) {
				continue;
			}
			self::assertLessThanOrEqual(
				(float) $regular,
				$precio,
				'Un descuento que sube el precio es la peor forma de fallar: nadie lo mira.'
			);
			self::assertGreaterThanOrEqual( 0.0, $precio, 'Un precio negativo no existe.' );
		}
	}

	/**
	 * Con `skip_if_on_sale`, un tomo que el dueño rebajó a mano se queda como
	 * está. Es la política por defecto justamente para no pisar su trabajo.
	 *
	 * @testdox Con «respetar la rebaja manual», el tomo rebajado se queda igual.
	 */
	public function test_manual_sale_is_respected_when_configured(): void {
		$this->wpdb->option_rows[ Settings::OPTION_SALE_POLICY ] = Settings::POLICY_SKIP_IF_ON_SALE;

		self::assertNull(
			$this->precio( $this->producto( '10000', '9500' ) ),
			'La política por defecto existe para no pisar la rebaja que puso el dueño a mano.'
		);
	}

	/**
	 * Con `greatest_wins` gana el más barato de los dos, no el último calculado.
	 *
	 * @testdox Con «gana el mayor descuento», se sirve el más barato de los dos.
	 */
	public function test_greatest_wins_serves_the_cheapest(): void {
		$this->wpdb->term_meta_rows = array( self::TERM => '10' );

		// 10.000 al 10% = 9.000, que es más barato que la rebaja manual de 9.500.
		$precio = $this->precio( $this->producto( '10000', '9500' ) );

		self::assertNotNull( $precio );
		self::assertSame( 9000.0, $precio );

		// Y al revés: con la rebaja manual más agresiva, gana ella.
		$precio_manual = $this->precio( $this->producto( '10000', '8000' ) );
		self::assertNotNull( $precio_manual );
		self::assertSame(
			8000.0,
			$precio_manual,
			'Si la rebaja manual ya es mejor, aplicar la regla encarecería el tomo.'
		);
	}

	/**
	 * Encadenar descuentos es la política que puede irse de las manos: el
	 * porcentaje se aplica sobre un precio ya rebajado. El tope de la tienda
	 * tiene que seguir mandando sobre el resultado FINAL.
	 *
	 * @testdox Encadenando sobre una rebaja manual, el tope sigue mandando.
	 */
	public function test_stacking_still_respects_the_store_ceiling(): void {
		$this->wpdb->option_rows[ Settings::OPTION_SALE_POLICY ] = Settings::POLICY_STACK_ON_SALE;
		$this->wpdb->term_meta_rows                             = array( self::TERM => '90' );

		$precio = $this->precio( $this->producto( '10000', '5000' ) );

		self::assertNotNull( $precio );
		$suelo = 10000.0 * ( 1 - ( DiscountCeiling::HARD_MAX / 100 ) );
		self::assertGreaterThanOrEqual(
			$suelo,
			$precio,
			'Ni encadenando se puede bajar del suelo que impone el tope duro sobre el precio normal.'
		);
	}

	/**
	 * @testdox Un producto agotado no recibe descuento con «sólo disponibles».
	 */
	public function test_an_out_of_stock_product_gets_nothing_when_scoped_to_instock(): void {
		$this->wpdb->option_rows[ Settings::OPTION_APPLY_TO ] = Settings::APPLY_INSTOCK_ONLY;

		self::assertNull( $this->precio( $this->producto( '10000', '', 'outofstock' ) ) );
	}
}
