<?php
/**
 * Contrato del fallback por PAÍS de la política de pedidos (pedido del dueño
 * 2026-08-30): «todos los pedidos España hasta el 5 de cada mes… Argentina el
 * 20». La cadena queda: ficha > editorial > PAÍS > plazo continuo del país —
 * y una editorial con política propia SIGUE mandando sobre su país.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.109.10
 */
final class PedidoPolicyPaisFallbackTest extends TestCase {

	/** @var array<int,array<string,mixed>> term_id => metas. */
	private array $term_meta = array();

	/** @var array<string,array<int,object>> taxonomy => terms del producto. */
	private array $terms = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_wp_error' )->justReturn( false );

		$terms = &$this->terms;
		Functions\when( 'get_the_terms' )->alias(
			static function ( $product_id, string $taxonomy ) use ( &$terms ) {
				unset( $product_id );
				return $terms[ $taxonomy ] ?? array();
			}
		);

		$meta = &$this->term_meta;
		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key ) use ( &$meta ) {
				return $meta[ $term_id ][ $key ] ?? '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function producto(): \WC_Product {
		return new class() extends \WC_Product {
			public function get_id(): int {
				return 4242;
			}
		};
	}

	/**
	 * @param int    $term_id Término.
	 * @param string $name    Nombre visible.
	 */
	private function term( int $term_id, string $name ): object {
		return (object) array(
			'term_id' => $term_id,
			'name'    => $name,
		);
	}

	public function test_the_country_cutoff_applies_when_the_brand_inherits(): void {
		// España: corte el 5, ~un mes desde el corte (los valores del dueño).
		$this->terms['product_brand'] = array( $this->term( 10, 'Panini España' ) );
		$this->terms['pa_pais']       = array( $this->term( 90, 'España' ) );
		$this->term_meta[90]          = array(
			PedidoPolicy::META_MODO => PedidoPolicy::MODO_TANDA,
			PedidoPolicy::META_DIA  => 5,
			PedidoPolicy::META_MIN  => 25,
			PedidoPolicy::META_MAX  => 35,
		);

		$policy = ( new PedidoPolicy() )->for_product( $this->producto() );

		self::assertSame( PedidoPolicy::MODO_TANDA, $policy['modo'] );
		self::assertSame( 5, $policy['dia'] );
		self::assertSame( 25, $policy['min'] );
		self::assertSame( 35, $policy['max'] );
		self::assertSame( 'España', $policy['marca'], 'La frase de tanda nombra al país que puso la regla.' );
	}

	public function test_a_brand_with_its_own_policy_beats_the_country(): void {
		$this->terms['product_brand'] = array( $this->term( 10, 'Ivrea Argentina' ) );
		$this->terms['pa_pais']       = array( $this->term( 91, 'Argentina' ) );
		$this->term_meta[10]          = array( PedidoPolicy::META_MODO => PedidoPolicy::MODO_CONTINUO );
		$this->term_meta[91]          = array(
			PedidoPolicy::META_MODO => PedidoPolicy::MODO_TANDA,
			PedidoPolicy::META_DIA  => 20,
			PedidoPolicy::META_MIN  => 18,
			PedidoPolicy::META_MAX  => 24,
		);

		$policy = ( new PedidoPolicy() )->for_product( $this->producto() );

		self::assertSame( PedidoPolicy::MODO_CONTINUO, $policy['modo'], 'Ivrea publica cada jueves: su continuo manda sobre el corte de su país.' );
	}

	public function test_a_paused_country_stops_promising_dates(): void {
		$this->terms['product_brand'] = array( $this->term( 10, 'Editorial X' ) );
		$this->terms['pa_pais']       = array( $this->term( 92, 'Japón' ) );
		$this->term_meta[92]          = array( PedidoPolicy::META_MODO => PedidoPolicy::MODO_PAUSADA );

		$policy = ( new PedidoPolicy() )->for_product( $this->producto() );

		self::assertSame( PedidoPolicy::MODO_PAUSADA, $policy['modo'] );
	}

	/**
	 * @testdox Un tomo SIN editorial sigue tomando el corte de su país.
	 *
	 * Regresión 2026-08-31. `resolve()` devolvía «heredado» en cuanto el producto
	 * no tenía términos de `product_brand`, y el bloque del país de más abajo
	 * quedaba inalcanzable: la cadena documentada —ficha > editorial > PAÍS—
	 * estaba rota en su tercer eslabón. Los 422 productos a pedido de producción
	 * llevan editorial, así que nunca se vio; una importación sin marca habría
	 * hecho que la ficha prometiera el plazo continuo desde la compra, con la
	 * fecha, la nota y el panel de cortes los tres coherentes entre sí y los tres
	 * equivocados.
	 */
	public function test_a_volume_without_a_publisher_still_takes_its_country_cutoff(): void {
		$this->terms['product_brand'] = array();
		$this->terms['pa_pais']       = array( $this->term( 90, 'España' ) );
		$this->term_meta[90]          = array(
			PedidoPolicy::META_MODO => PedidoPolicy::MODO_TANDA,
			PedidoPolicy::META_DIA  => 5,
			PedidoPolicy::META_MIN  => 25,
			PedidoPolicy::META_MAX  => 35,
		);

		$policy = ( new PedidoPolicy() )->for_product( $this->producto() );

		self::assertSame( PedidoPolicy::MODO_TANDA, $policy['modo'] );
		self::assertSame( 5, $policy['dia'] );
		self::assertSame( 'España', $policy['marca'] );
	}

	public function test_without_brand_or_country_config_everything_inherits(): void {
		$this->terms['product_brand'] = array( $this->term( 10, 'Editorial X' ) );
		$this->terms['pa_pais']       = array( $this->term( 93, 'Chile' ) );

		$policy = ( new PedidoPolicy() )->for_product( $this->producto() );

		self::assertSame( PedidoPolicy::MODO_HEREDADO, $policy['modo'], 'Sin configurar nada, nada cambia.' );
	}
}
