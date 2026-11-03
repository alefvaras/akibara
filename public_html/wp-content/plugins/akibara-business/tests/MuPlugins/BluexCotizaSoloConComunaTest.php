<?php
/**
 * Blue Express no cotiza (ni grita) sin región y comuna.
 *
 * Medido en producción por QA el 2026-09-06: 113 líneas «Destination state or
 * city is missing in the package» en el log del hosting en un día con cuatro
 * pedidos, una por cada cálculo de envío anterior a que el cliente escribiera
 * su comuna. El mu-plugin responde «no disponible» a WooCommerce en ese caso,
 * antes de que el plugin de Blue llegue a cotizar; el cliente ya no veía tarifa
 * de Blue en esa situación (el plugin la descartaba con `Erro -888`).
 *
 * Patrón de `BrevoPortadaJpgEnElCarritoTest`: doble de la clase del tercero y
 * llamada directa a las funciones del fichero.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	if ( ! class_exists( 'WC_Correios_Webservice' ) ) {
		/** Doble del webservice de Blue Express: aquí sólo importa que exista. */
		class WC_Correios_Webservice {}
	}
}

namespace Akibara\Business\Tests\MuPlugins {

	use Brain\Monkey;
	use PHPUnit\Framework\TestCase;

	final class BluexCotizaSoloConComunaTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			if ( ! function_exists( 'akibara_bluex_disponible_solo_con_comuna' ) ) {
				require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-bluex-cotiza-solo-con-comuna.php';
			}

			unset( $GLOBALS['wp_filter'] );
		}

		protected function tearDown(): void {
			unset( $GLOBALS['wp_filter'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		/** @return array<string, array{0: array<string, mixed>, 1: bool}> */
		public function destinos(): array {
			return array(
				'región y comuna'      => array( array( 'state' => 'CL-RM', 'city' => 'Providencia' ), true ),
				'sin comuna'           => array( array( 'state' => 'CL-RM', 'city' => '' ), false ),
				'comuna en blanco'     => array( array( 'state' => 'CL-RM', 'city' => '   ' ), false ),
				'sin región'           => array( array( 'state' => '', 'city' => 'Providencia' ), false ),
				'destino vacío'        => array( array(), false ),
			);
		}

		/**
		 * @dataProvider destinos
		 * @testdox Disponible sólo cuando el destino trae región y comuna
		 */
		public function test_disponible_solo_con_region_y_comuna( array $destino, bool $esperado ): void {
			self::assertSame( $esperado, akibara_bluex_disponible_solo_con_comuna( true, array( 'destination' => $destino ) ) );
		}

		/** @testdox Lo que WooCommerce ya marcó como no disponible sigue no disponible, con o sin comuna. */
		public function test_respeta_lo_que_woocommerce_ya_decidio(): void {
			self::assertFalse( akibara_bluex_disponible_solo_con_comuna( false, array( 'destination' => array( 'state' => 'CL-RM', 'city' => 'Providencia' ) ) ) );
		}

		/** @testdox Un paquete sin `destination` o con una forma rara no rompe nada: no disponible. */
		public function test_es_defensivo_con_la_forma_del_paquete(): void {
			self::assertFalse( akibara_bluex_disponible_solo_con_comuna( true, array() ) );
			self::assertFalse( akibara_bluex_disponible_solo_con_comuna( true, 'no-es-un-paquete' ) );
			self::assertFalse( akibara_bluex_disponible_solo_con_comuna( true, array( 'destination' => 'texto' ) ) );
		}

		/**
		 * @testdox Con Blue Express presente engancha los tres métodos que cotizan contra su API, y sólo esos.
		 */
		public function test_con_blue_presente_engancha_los_tres_metodos_que_cotizan(): void {
			akibara_bluex_registrar_cotiza_solo_con_comuna();

			foreach ( akibara_bluex_metodos_que_cotizan() as $id ) {
				self::assertSame( 10, has_filter( 'woocommerce_shipping_' . $id . '_is_available', 'akibara_bluex_disponible_solo_con_comuna' ), "Engancha {$id}." );
			}
			self::assertSame( array( 'bluex-ex', 'bluex-py', 'bluex-md' ), akibara_bluex_metodos_que_cotizan(), 'Los tres ids de class-wc-bluex-express.php.' );
			self::assertFalse( has_filter( 'woocommerce_shipping_bluex-pudo_is_available', 'akibara_bluex_disponible_solo_con_comuna' ), 'PUDO ofrece tarifa fija sin dirección: no se toca.' );
		}

		/**
		 * @testdox El registro espera a `plugins_loaded` tarde y muere con el tercero.
		 */
		public function test_el_registro_espera_a_plugins_loaded_tarde_y_muere_con_el_tercero(): void {
			$fuente = (string) file_get_contents( dirname( __DIR__, 4 ) . '/mu-plugins/akibara-bluex-cotiza-solo-con-comuna.php' );
			$fuente = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $fuente );

			self::assertStringContainsString( "add_action( 'plugins_loaded', 'akibara_bluex_registrar_cotiza_solo_con_comuna', 20 );", $fuente );
			self::assertStringContainsString( "class_exists( 'WC_Correios_Webservice' )", $fuente, 'Blue Express incluye esa clase en plugins_loaded a 10; sin ella no hay nada que silenciar.' );
		}
	}
}
