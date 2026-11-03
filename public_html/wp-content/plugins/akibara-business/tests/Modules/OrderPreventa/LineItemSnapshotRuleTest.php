<?php
/**
 * Una sola regla de qué metas lleva una línea de preventa.
 *
 * El checkout (`LineItemSnapshot::on_create_line_item`) y el back-fill
 * (`WpCli::backfill_one`) escribían el snapshot por separado, y las dos copias
 * YA habían divergido: la del CLI nunca capturó la fecha de lanzamiento y
 * enumeraba el vocabulario de tipos a mano. Desde 1.110.1 las dos rutas pasan
 * por `stamp_preventa_details()`, así que una meta nueva la ganan juntas o no la
 * gana ninguna.
 *
 * Fija además la retirada de `_akb_eta_release_date_at_purchase`: era una meta
 * de SÓLO ESCRITURA (su único lector se eliminó a propósito en OrderTimeline,
 * para no resucitar una fecha retractada) que seguía estampándose en cada línea.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Contracts\EtaProvider;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Modules\OrderPreventa\LineItemSnapshot;
use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\Stock\Availability;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class LineItemSnapshotRuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Estampa las siete metas del snapshot, con el tipo ya normalizado.
	 */
	public function test_estampa_el_snapshot_completo(): void {
		$item    = $this->item();
		$product = $this->product( 'release_date' );

		( new LineItemSnapshot( $this->calculator() ) )->stamp_preventa_details( $item, $product );

		self::assertSame( Availability::TYPE_RELEASE_DATE, $item->meta[ MetaKeys::ITEM_TYPE_AT_PURCHASE ] );
		self::assertSame( 'espana', $item->meta[ MetaKeys::ITEM_COUNTRY_AT_PURCHASE ] );
		self::assertSame( 'España', $item->meta[ MetaKeys::ITEM_COUNTRY_NAME_AT_PURCHASE ] );
		self::assertSame( 'no', $item->meta[ MetaKeys::ITEM_IS_TBA_AT_PURCHASE ] );
		self::assertSame( 1000, $item->meta[ MetaKeys::ITEM_MIN_AT_PURCHASE ] );
		self::assertSame( 2000, $item->meta[ MetaKeys::ITEM_MAX_AT_PURCHASE ] );
	}

	/**
	 * @testdox Un tipo que no es del vocabulario cae a «auto», no se persiste crudo.
	 */
	public function test_el_tipo_se_normaliza_antes_de_persistirse(): void {
		$item = $this->item();

		( new LineItemSnapshot( $this->calculator() ) )
			->stamp_preventa_details( $item, $this->product( 'preventa-vieja' ) );

		self::assertSame( Availability::TYPE_AUTO, $item->meta[ MetaKeys::ITEM_TYPE_AT_PURCHASE ] );
	}

	/**
	 * @testdox La fecha de lanzamiento al comprar ya no se estampa: nadie la lee.
	 */
	public function test_no_estampa_la_meta_retirada(): void {
		$item = $this->item();

		( new LineItemSnapshot( $this->calculator() ) )
			->stamp_preventa_details( $item, $this->product( 'release_date' ) );

		self::assertArrayNotHasKey( '_akb_eta_release_date_at_purchase', $item->meta );
	}

	/**
	 * @testdox Sin ETA capturable marca is_tba=no y no inventa min/max.
	 */
	public function test_sin_eta_no_escribe_ventana(): void {
		$item = $this->item();

		( new LineItemSnapshot( $this->calculator( null ) ) )
			->stamp_preventa_details( $item, $this->product( 'disabled' ) );

		self::assertSame( 'no', $item->meta[ MetaKeys::ITEM_IS_TBA_AT_PURCHASE ] );
		self::assertArrayNotHasKey( MetaKeys::ITEM_MIN_AT_PURCHASE, $item->meta );
		self::assertArrayNotHasKey( MetaKeys::ITEM_MAX_AT_PURCHASE, $item->meta );
	}

	/**
	 * El back-fill NO puede tener su propia lista de metas: tiene que llamar al
	 * dueño de la regla. Se comprueba sobre el código, que es donde vivía la
	 * copia.
	 */
	public function test_el_backfill_delega_en_el_dueno_de_la_regla(): void {
		$fuente = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/OrderPreventa/WpCli.php'
		);

		self::assertStringContainsString( 'stamp_preventa_details(', $fuente );
		self::assertStringNotContainsString( 'ITEM_TYPE_AT_PURCHASE', $fuente );
		self::assertStringNotContainsString( 'ITEM_IS_TBA_AT_PURCHASE', $fuente );
		self::assertStringNotContainsString( 'ITEM_COUNTRY_AT_PURCHASE', $fuente );
	}

	/**
	 * Doble de línea que RECOGE lo escrito: el módulo usa `update_meta_data()`,
	 * que el stub compartido no necesita para leer.
	 */
	private function item(): \WC_Order_Item_Product {
		return new class() extends \WC_Order_Item_Product {
			/** @var array<string, mixed> */
			public array $meta = array();

			/**
			 * @param string $key   Meta key.
			 * @param mixed  $value Valor.
			 * @return void
			 */
			public function update_meta_data( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}

			/**
			 * @param string $key Meta key.
			 * @return void
			 */
			public function delete_meta_data( string $key ): void {
				unset( $this->meta[ $key ] );
			}
		};
	}

	private function product( string $type ): \WC_Product {
		return new \WC_Product( 'onbackorder', array( '_akb_eta_type' => $type ) );
	}

	private function calculator( ?string $eta = 'ok' ): EtaProvider {
		return new class( $eta ) implements EtaProvider {
			private ?string $eta;

			public function __construct( ?string $eta ) {
				$this->eta = $eta;
			}

			public function calculate( \WC_Product $product ): ?EtaResult {
				return null === $this->eta ? null : EtaResult::range( 1000, 2000 );
			}

			public function resolve_country( \WC_Product $product ): ?string {
				return 'espana';
			}

			public function get_country_name( string $slug ): ?string {
				return 'España';
			}
		};
	}
}
