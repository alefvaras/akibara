<?php
/**
 * El RUT sale en la exportación de datos personales y se va con el borrado.
 *
 * Hallazgo S-23bc8b3c: era el único dato de cliente de la caja que la
 * herramienta de privacidad no veía. WooCommerce no conoce los campos
 * adicionales del checkout de bloques, y el RUT vive en DOS metas por dueño.
 *
 * Los dobles hacen lo que hace WooCommerce con estos filtros
 * (`class-wc-privacy-erasers.php`): con un valor anonimizado no vacío ESCRIBE
 * la meta; con uno vacío la BORRA.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\CartCheckoutBlocks\RutPrivacy;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/RutPrivacy.php';

final class RutPrivacyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Se cuelga de los filtros de WooCommerce: exportar y borrar, pedido y cliente. */
	public function test_se_cuelga_de_los_filtros_de_woocommerce(): void {
		foreach ( array(
			'woocommerce_privacy_export_order_personal_data_meta',
			'woocommerce_privacy_remove_order_personal_data_meta',
			'woocommerce_privacy_remove_order_personal_data_meta_value',
			'woocommerce_privacy_export_customer_personal_data',
			'woocommerce_privacy_erase_personal_data_customer',
		) as $filtro ) {
			Monkey\Filters\expectAdded( $filtro )->once();
		}

		( new RutPrivacy() )->register();
		self::assertTrue( true );
	}

	/**
	 * La primera versión nombraba `_wc_billing/akibara/rut`, y la prueba sembraba
	 * esa misma clave: verde con la pieza rota. La clave se ANCLA a sus dos
	 * fuentes de verdad, no se repite a mano.
	 *
	 * @testdox La clave nativa es la que WooCommerce escribe de verdad: ubicación «contact» → `_wc_other/`.
	 */
	public function test_la_clave_nativa_es_la_que_woocommerce_escribe(): void {
		$campos = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/CheckoutFields.php' );
		self::assertMatchesRegularExpression( "/RUT_FIELD_ID\s*=\s*'akibara\/rut'/", $campos );
		self::assertMatchesRegularExpression( "/'location'\s*=>\s*'contact'/", $campos, 'Si el campo cambia de ubicación, cambia su prefijo y esta pieza tiene que cambiar con él.' );

		// WooCommerce: `contact` se persiste con el grupo `other` → `_wc_other/`.
		self::assertSame( '_wc_other/akibara/rut', RutPrivacy::NATIVE_META );
		// Y es la misma que ya lee el plugin principal para pintar el RUT del pedido.
		// (La constante es privada: se lee por reflexión, que para eso es una prueba.)
		$en_business = ( new \ReflectionClassConstant( \Akibara\Business\Modules\RutValidation\RutValidation::class, 'NATIVE_META_KEY' ) )->getValue();
		self::assertSame( $en_business, RutPrivacy::NATIVE_META );

		self::assertContains( RutPrivacy::NATIVE_META, RutPrivacy::ORDER_META );
		self::assertContains( RutPrivacy::NATIVE_META, RutPrivacy::CUSTOMER_META );
	}

	/** @testdox El pedido exporta UNA fila «RUT», sin perder las que ya exportaba WooCommerce. */
	public function test_el_pedido_exporta_el_rut(): void {
		$meta = ( new RutPrivacy() )->export_order_meta( array( 'Transaction ID' => 'Transaction ID' ) );

		self::assertSame( 'RUT', $meta['_billing_rut'] );
		self::assertArrayHasKey( 'Transaction ID', $meta );
		self::assertArrayNotHasKey( '_wc_other/akibara/rut', $meta, 'Las dos metas guardan el mismo valor: una fila, no dos.' );
	}

	/** @testdox Al borrar un pedido se van LAS DOS metas del RUT, y se BORRAN en vez de quedar en «[deleted]». */
	public function test_el_borrado_del_pedido_se_lleva_las_dos_metas(): void {
		$sut     = new RutPrivacy();
		$retirar = $sut->remove_order_meta( array( 'Transaction ID' => 'numeric_id' ) );

		// Lo que WooCommerce hace con esa lista, meta por meta.
		$pedido = array(
			'_billing_rut'            => '12.345.678-5',
			'_wc_other/akibara/rut' => '12.345.678-5',
			'Transaction ID'          => '991',
		);
		foreach ( $retirar as $clave => $tipo ) {
			$anon = $sut->delete_instead_of_anonymize( 'numeric_id' === $tipo ? '0' : '[deleted]', $clave );
			if ( '' !== (string) $anon ) {
				$pedido[ $clave ] = $anon;
			} else {
				unset( $pedido[ $clave ] );
			}
		}

		self::assertSame( array( 'Transaction ID' => '0' ), $pedido, 'Ni rastro del RUT, y lo ajeno se anonimiza como siempre.' );
	}

	/** @testdox El cliente exporta su RUT una vez, esté en la meta histórica o sólo en el campo de bloques. */
	public function test_el_cliente_exporta_su_rut(): void {
		$sut = new RutPrivacy();

		$historico = $sut->export_customer( array(), $this->cliente( array( 'billing_rut' => '12.345.678-5', '_wc_other/akibara/rut' => '12.345.678-5' ) ) );
		self::assertSame( array( array( 'name' => 'RUT', 'value' => '12.345.678-5' ) ), $historico );

		$solo_bloques = $sut->export_customer( array(), $this->cliente( array( '_wc_other/akibara/rut' => '9.876.543-3' ) ) );
		self::assertSame( '9.876.543-3', $solo_bloques[0]['value'] );

		self::assertSame( array(), $sut->export_customer( array(), $this->cliente( array() ) ), 'Sin RUT no hay fila vacía.' );
	}

	/** @testdox Al borrar un cliente se van sus dos metas, se guarda, y la respuesta lo dice. */
	public function test_el_borrado_del_cliente_se_lleva_las_dos_metas(): void {
		$cliente   = $this->cliente( array( 'billing_rut' => '12.345.678-5', '_wc_other/akibara/rut' => '12.345.678-5', 'otra' => 'x' ) );
		$respuesta = ( new RutPrivacy() )->erase_customer( array( 'items_removed' => false, 'messages' => array( 'previo' ) ), $cliente );

		self::assertSame( array( 'otra' => 'x' ), $cliente->meta );
		self::assertSame( 1, $cliente->guardados );
		self::assertTrue( $respuesta['items_removed'] );
		self::assertSame( array( 'previo', 'Se eliminó el RUT del cliente.' ), $respuesta['messages'] );
	}

	/** @testdox Un cliente sin RUT no se guarda ni se anuncia nada borrado. */
	public function test_sin_rut_no_se_toca_al_cliente(): void {
		$cliente   = $this->cliente( array( 'otra' => 'x' ) );
		$respuesta = ( new RutPrivacy() )->erase_customer( array( 'items_removed' => false, 'messages' => array() ), $cliente );

		self::assertSame( 0, $cliente->guardados );
		self::assertFalse( $respuesta['items_removed'] );
	}

	/**
	 * @param array<string,string> $meta Metas del cliente.
	 */
	private function cliente( array $meta ): object {
		return new class( $meta ) {
			/** @var array<string,string> */
			public array $meta;

			public int $guardados = 0;

			/** @param array<string,string> $meta Metas. */
			public function __construct( array $meta ) {
				$this->meta = $meta;
			}

			public function get_meta( string $clave ): string {
				return $this->meta[ $clave ] ?? '';
			}

			public function delete_meta_data( string $clave ): void {
				unset( $this->meta[ $clave ] );
			}

			public function save(): void {
				++$this->guardados;
			}
		};
	}
}
