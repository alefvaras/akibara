<?php
/**
 * Pruebas de ProductSchemaShopping.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\ProductSchemaShopping;

use Akibara\Business\Modules\ProductSchemaShopping\ProductSchemaShopping;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

final class ProductSchemaShoppingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.cl' . $path
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);

		// Defaults deterministas para los dos guards de contexto. Brain Monkey
		// DEFINE la función en cuanto cualquier test de la suite la mockea, así
		// que `function_exists('is_product')` puede ser true en un test que no
		// la declaró: sin este default, el resultado depende del ORDEN de la
		// suite (aislado pasa, completa falla). Cada test los sobrescribe.
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( false );
		// Defaults de los helpers de WooCommerce que usan los arreglos de precio
		// y de atributos; cada test los sobrescribe cuando le importan.
		Functions\when( 'get_the_ID' )->justReturn( 0 );
		Functions\when( 'wc_get_product' )->justReturn( null );
		// `function_exists()` miente en la suite (Patchwork): el guard del schema
		// de precio cree que existe y hay que doblarla explícitamente.
		Functions\when( 'get_queried_object_id' )->justReturn( 0 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'CLP' );
		Functions\when( 'wc_attribute_label' )->alias(
			static fn( string $slug ): string => array(
				'pa_pais'   => 'País',
				'pa_autor'  => 'Autor',
				'pa_genero' => 'Género',
			)[ $slug ] ?? $slug
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should add one accurate return policy without claiming return labels.
	 */
	public function test_adds_one_accurate_return_policy_to_global_store(): void {
		Functions\when( 'is_front_page' )->justReturn( true );

		$input = array(
			'publisher' => array(
				'@type' => array( 'Organization', 'OnlineStore' ),
				'@id'   => 'https://akibara.cl/#organization',
			),
			'webpage'   => array(
				'@type' => 'WebPage',
			),
		);

		$result = ( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input );
		$policy = $result['publisher']['hasMerchantReturnPolicy'];

		self::assertSame( 'MerchantReturnPolicy', $policy['@type'] );
		self::assertSame( 10, $policy['merchantReturnDays'] );
		self::assertSame( 'CL', $policy['applicableCountry'] );
		self::assertSame( 'https://akibara.cl/devoluciones/', $policy['merchantReturnLink'] );
		self::assertSame( 'https://schema.org/ReturnFeesCustomerResponsibility', $policy['returnFees'] );
		self::assertSame( 'https://schema.org/FreeReturn', $policy['itemDefectReturnFees'] );
		self::assertSame( 'https://schema.org/FullRefund', $policy['refundType'] );
		self::assertArrayNotHasKey( 'returnLabelSource', $policy );
		self::assertArrayNotHasKey( 'customerRemorseReturnLabelSource', $policy );
		self::assertArrayNotHasKey( 'itemDefectReturnLabelSource', $policy );
		self::assertSame(
			array( 'https://schema.org/NewCondition', 'https://schema.org/DamagedCondition' ),
			$policy['itemCondition']
		);
		self::assertArrayNotHasKey( 'hasMerchantReturnPolicy', $result['webpage'] );
	}

	/**
	 * @testdox Should not change schema outside the front page.
	 */
	public function test_does_not_change_schema_outside_front_page(): void {
		Functions\when( 'is_front_page' )->justReturn( false );

		$input = array(
			'publisher' => array(
				'@type' => 'Organization',
			),
		);

		self::assertSame( $input, ( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input ) );
	}

	/**
	 * @testdox Should never create a parallel merchant node when Rank Math has none.
	 */
	public function test_does_not_create_merchant_node_when_rank_math_has_none(): void {
		Functions\when( 'is_front_page' )->justReturn( true );

		$input = array(
			'webpage' => array(
				'@type' => 'WebPage',
			),
		);

		self::assertSame( $input, ( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input ) );
	}

	/**
	 * @testdox Should be idempotent, attach one policy for a string type, and leave Offer untouched.
	 */
	public function test_is_idempotent_and_does_not_modify_offer(): void {
		Functions\when( 'is_front_page' )->justReturn( true );

		$input = array(
			'offer' => array(
				'@type'         => 'Offer',
				'priceCurrency' => 'CLP',
				'price'         => '12990',
			),
			'store' => array(
				'@type' => 'OnlineStore',
				'@id'   => 'https://akibara.cl/#organization',
			),
		);

		$module = new ProductSchemaShopping();
		$once   = $module->handle_rank_math_json_ld( $input );
		$twice  = $module->handle_rank_math_json_ld( $once );

		$nodes_with_policy = array_filter(
			$twice,
			static fn( $node ): bool => is_array( $node ) && array_key_exists( 'hasMerchantReturnPolicy', $node )
		);

		self::assertSame( $once, $twice, 'Applying the filter twice must not change the graph.' );
		self::assertCount( 1, $nodes_with_policy, 'The graph must contain one attached return policy.' );
		self::assertSame( $input['offer'], $twice['offer'], 'Offer must remain owned by Rank Math.' );
	}

	/**
	 * Medido en el benchmark B1 (2026-08-09): la política sólo vivía en la
	 * portada, así que quien abre UNA ficha —un asistente de IA citando esa
	 * URL, Storebot validando el producto— nunca la veía.
	 *
	 * @testdox Should publish the same return policy inside the product offer.
	 */
	public function test_attaches_policy_to_product_offer(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( true );

		$input = array(
			'product' => array(
				'@type'  => array( 'Product', 'Book' ),
				'name'   => 'One Punch-Man 35',
				'offers' => array(
					'@type'         => 'Offer',
					'priceCurrency' => 'CLP',
					'price'         => '12990',
					'availability'  => 'https://schema.org/InStock',
				),
			),
		);

		$out = ( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input );

		$policy = $out['product']['offers']['hasMerchantReturnPolicy'] ?? null;
		self::assertIsArray( $policy, 'The offer must carry the return policy.' );
		self::assertSame( 'MerchantReturnPolicy', $policy['@type'] );
		self::assertSame( 10, $policy['merchantReturnDays'], 'Ley 19.496: 10 días corridos.' );
		self::assertSame(
			'https://akibara.cl/devoluciones/#merchant-return-policy',
			$policy['@id'],
			'Mismo @id que la política global: una sola verdad, no una segunda.'
		);
		// Rank Math conserva lo suyo.
		self::assertSame( '12990', $out['product']['offers']['price'] );
		self::assertSame( 'https://schema.org/InStock', $out['product']['offers']['availability'] );
	}

	/**
	 * @testdox Should cover every offer of a variable product and never overwrite an existing policy.
	 */
	public function test_covers_offer_lists_and_respects_existing_policy(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( true );

		$input = array(
			'product' => array(
				'@type'  => 'Product',
				'offers' => array(
					array( '@type' => 'Offer', 'price' => '12990' ),
					array(
						'@type'                    => 'Offer',
						'price'                    => '14990',
						'hasMerchantReturnPolicy' => array( '@type' => 'MerchantReturnPolicy', 'merchantReturnDays' => 30 ),
					),
				),
			),
		);

		$out = ( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input );

		self::assertSame( 10, $out['product']['offers'][0]['hasMerchantReturnPolicy']['merchantReturnDays'] );
		self::assertSame(
			30,
			$out['product']['offers'][1]['hasMerchantReturnPolicy']['merchantReturnDays'],
			'Una política ajena ya presente no se pisa.'
		);
	}

	/**
	 * @testdox Should leave non-product, non-front pages untouched.
	 */
	public function test_leaves_other_pages_untouched(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( false );

		$input = array( 'article' => array( '@type' => 'Article', 'headline' => 'Guía' ) );

		self::assertSame( $input, ( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input ) );
	}

	/**
	 * Doc de Google (verificada 2026-08-09): «If you use both the offers.price and
	 * offers.priceSpecification properties to encode an active price, Google will
	 * use the price provided through the offers.price property and IGNORE the
	 * offers.priceSpecification property». Rank Math emitía las dos con el mismo
	 * número: peso muerto.
	 *
	 * @testdox Should drop the duplicated price specification when there is no discount.
	 */
	public function test_drops_the_redundant_price_specification(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( true );

		$input = array(
			'product' => array(
				'@type'  => array( 'Product', 'Book' ),
				'offers' => array(
					'@type'             => 'Offer',
					'price'             => '12990',
					'priceCurrency'     => 'CLP',
					'priceSpecification' => array( 'price' => '12990', 'priceCurrency' => 'CLP' ),
				),
			),
		);

		$out = ( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input );

		self::assertArrayNotHasKey( 'priceSpecification', $out['product']['offers'] );
		self::assertSame( '12990', $out['product']['offers']['price'], 'El precio activo no se toca.' );
	}

	/**
	 * 440 de 1568 productos están rebajados (medido en prod): en esos, el slot
	 * sirve para declarar el precio de lista REAL, que es lo que habilita el
	 * precio tachado.
	 *
	 * @testdox Should publish the real list price when the product is discounted.
	 */
	public function test_publishes_the_real_list_price_when_discounted(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 13629 );
		Functions\when( 'wc_get_product' )->justReturn(
			new class() {
				public function is_on_sale(): bool {
					return true;
				}
				public function get_regular_price(): string {
					return '12990';
				}
			}
		);

		$input = array(
			'product' => array(
				'@type'  => 'Product',
				'offers' => array(
					'@type'             => 'Offer',
					'price'             => '9500',
					'priceCurrency'     => 'CLP',
					'priceSpecification' => array( 'price' => '9500' ),
				),
			),
		);

		$spec = ( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input )['product']['offers']['priceSpecification'];

		self::assertSame( 'UnitPriceSpecification', $spec['@type'] );
		self::assertSame( 'https://schema.org/ListPrice', $spec['priceType'] );
		self::assertSame( '12990', $spec['price'], 'El precio de lista sale de WooCommerce, no se inventa.' );
	}

	/**
	 * @testdox Should never invent a discount when the regular price is not higher.
	 */
	public function test_never_invents_a_discount(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'wc_get_product' )->justReturn(
			new class() {
				public function is_on_sale(): bool {
					return true;
				}
				public function get_regular_price(): string {
					return '9500'; // Igual al activo: no hay descuento real.
				}
			}
		);

		$input = array(
			'product' => array(
				'@type'  => 'Product',
				'offers' => array( '@type' => 'Offer', 'price' => '9500', 'priceSpecification' => array( 'price' => '9500' ) ),
			),
		);

		self::assertArrayNotHasKey(
			'priceSpecification',
			( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input )['product']['offers']
		);
	}

	/**
	 * @testdox Should publish human attribute labels instead of internal taxonomy slugs.
	 */
	public function test_humanizes_attribute_slugs(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( true );

		$input = array(
			'product' => array(
				'@type'              => 'Product',
				'additionalProperty' => array(
					array( '@type' => 'PropertyValue', 'name' => 'pa_pais', 'value' => 'España' ),
					array( '@type' => 'PropertyValue', 'name' => 'pa_autor', 'value' => 'ONE' ),
					array( '@type' => 'PropertyValue', 'name' => 'Formato', 'value' => 'Rústica' ),
				),
			),
		);

		$props = ( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input )['product']['additionalProperty'];

		self::assertSame( 'País', $props[0]['name'] );
		self::assertSame( 'Autor', $props[1]['name'] );
		self::assertSame( 'España', $props[0]['value'], 'El valor no se toca.' );
		self::assertSame( 'Formato', $props[2]['name'], 'Lo que ya era legible se deja igual.' );
	}
	/**
	 * Google: «Your listing may not display if the priceValidUntil property
	 * indicates a past date». Comparado el 2026-08-09: bazarhistorias (mismo
	 * stack) lo declara y Akibara no lo tenía.
	 *
	 * @testdox Should publish a future price validity date that can never expire.
	 */
	public function test_publishes_a_future_price_validity_date(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( true );

		$input = array(
			'product' => array(
				'@type'  => 'Product',
				'offers' => array( '@type' => 'Offer', 'price' => '12990', 'priceCurrency' => 'CLP' ),
			),
		);

		$until = ( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input )['product']['offers']['priceValidUntil'];

		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $until );
		self::assertGreaterThan( gmdate( 'Y-m-d' ), $until, 'Una fecha pasada puede tumbar el listing entero.' );
	}

	/**
	 * @testdox Should respect a price validity date already set by Rank Math.
	 */
	public function test_respects_existing_price_validity(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( true );

		$input = array(
			'product' => array(
				'@type'  => 'Product',
				'offers' => array( '@type' => 'Offer', 'price' => '12990', 'priceValidUntil' => '2030-01-01' ),
			),
		);

		self::assertSame(
			'2030-01-01',
			( new ProductSchemaShopping() )->handle_rank_math_json_ld( $input )['product']['offers']['priceValidUntil']
		);
	}
}
