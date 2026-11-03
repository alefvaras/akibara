<?php
/**
 * Cambiar «A pedido» en Akibara → Textos tiene que cambiarlo EN TODAS PARTES.
 *
 * Hallazgo S-6d21e49e: había dos vocabularios para la misma palabra. El dueño
 * escribía «Por encargo» en Textos y obedecían el cartel de la ficha, el
 * carrito, la línea del pedido y los correos —que leen la option— pero no el
 * badge de la tarjeta, ni el botón de agregar, ni los chips de la landing, que
 * salían del `const` del código. El cliente leía dos palabras para lo mismo en
 * la misma pantalla.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Stock;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use PHPUnit\Framework\TestCase;

final class UnaSolaPalabraParaCadaEstadoTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['akibara_test_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['akibara_test_options'] = array();
		parent::tearDown();
	}

	/** @testdox Sin nada guardado, la palabra es la de fábrica. */
	public function test_de_fabrica(): void {
		self::assertSame( 'A pedido', Availability::palabra( Availability::A_PEDIDO ) );
		self::assertSame( 'Preventa', Availability::palabra( Availability::PREVENTA ) );
		self::assertSame( 'Disponible', Availability::palabra( Availability::DISPONIBLE ) );
		self::assertSame( 'Agotado', Availability::palabra( Availability::AGOTADO ) );
	}

	/** @testdox Lo que el dueño escribe en Textos manda sobre el valor de fábrica. */
	public function test_la_palabra_del_dueno_manda(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akb_copy_eta_single_pedido'   => 'Por encargo',
			'akb_copy_eta_single_preventa' => 'Reserva anticipada',
		);

		self::assertSame( 'Por encargo', Availability::palabra( Availability::A_PEDIDO ) );
		self::assertSame( 'Reserva anticipada', Availability::palabra( Availability::PREVENTA ) );
	}

	/** @testdox La palabra del GRUPO reservable es la misma que la del estado «a pedido». */
	public function test_el_grupo_reservable_dice_lo_mismo(): void {
		$GLOBALS['akibara_test_options'] = array( 'akb_copy_eta_single_pedido' => 'Por encargo' );

		self::assertSame( 'Por encargo', StockStatus::palabra( StockStatus::ON_BACKORDER ) );
		self::assertSame( 'Disponible', StockStatus::palabra( StockStatus::IN_STOCK ) );
		self::assertSame( 'Agotado', StockStatus::palabra( StockStatus::OUT_OF_STOCK ) );
	}

	/** @testdox Un estado que no existe cae a la palabra más conservadora. */
	public function test_estado_desconocido_es_agotado(): void {
		self::assertSame( 'Agotado', Availability::palabra( 'inventado' ) );
		self::assertSame( 'Agotado', StockStatus::palabra( 'inventado' ) );
	}

	/** @testdox Una option guardada en blanco NO deja la etiqueta vacía. */
	public function test_el_vacio_no_borra_la_palabra(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akb_copy_eta_single_pedido'   => '   ',
			'akb_copy_eta_single_preventa' => '',
		);

		self::assertSame( 'A pedido', Availability::palabra( Availability::A_PEDIDO ) );
		self::assertSame( 'Preventa', Availability::palabra( Availability::PREVENTA ) );
	}

	/** @testdox Una palabra larguísima NO viaja al buscador: allá se rechaza con excepción. */
	public function test_la_palabra_esta_acotada(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akb_copy_eta_single_pedido' => str_repeat( 'á', Availability::MAX_PALABRA + 1 ),
		);

		self::assertSame(
			'A pedido',
			Availability::palabra( Availability::A_PEDIDO ),
			'`Search\StockLabels::label()` lanza si pasa de 100 bytes, y quien la come descarta la tarjeta o no publica el documento.'
		);
		self::assertLessThanOrEqual( 100, strlen( str_repeat( 'á', Availability::MAX_PALABRA ) ), 'El tope en caracteres tiene que caber en el tope en bytes del consumidor.' );
	}

	/** @testdox La palabra se sanea al LEER, no sólo al guardar. */
	public function test_se_sanea_al_leer(): void {
		$GLOBALS['akibara_test_options'] = array(
			'akb_copy_eta_single_pedido' => "  Por
<strong>encargo</strong>  ",
		);

		self::assertSame(
			'Por encargo',
			Availability::palabra( Availability::A_PEDIDO ),
			'`wp option update` no pasa por ningún formulario.'
		);
	}

	/** @testdox Ninguna superficie visible vuelve a escribir la palabra a mano. */
	public function test_ninguna_superficie_repite_el_literal(): void {
		$raiz     = dirname( __DIR__, 3 );
		$vigilados = array(
			'src/Modules/SeriesBundles/LandingOffer.php'   => "=> array( 'label' => 'A pedido'",
			'src/Modules/OrderPreventa/PreventasScreen.php' => "=> array( 'A pedido'",
			'src/Modules/BackInStock/BackInStock.php'      => "array( 'A pedido', '#996800' )",
			'src/Modules/OrderPreventa/PreventaUX.php'     => ": 'A pedido';",
			'src/Modules/EstimatedDelivery/BannerRenderer.php' => "get_option( 'akb_copy_eta_single_pedido'",
			'src/Modules/OrderPreventa/PreventaItems.php'  => "get_option( 'akb_copy_eta_single_preventa'",
			'src/Modules/SeriesBundles/BypCartNoticeProvider.php' => "'tomos en preventa'",
		);

		foreach ( $vigilados as $archivo => $literal ) {
			self::assertStringNotContainsString(
				$literal,
				(string) file_get_contents( $raiz . '/' . $archivo ),
				sprintf( '%s volvió a escribir la palabra a mano.', $archivo )
			);
		}
	}

	/**
	 * Hallazgo S-6d21e49e: el verbo del CTA de «Mi colección» decidía el estado
	 * comparando texto contra la palabra de fábrica (`str_starts_with(...,
	 * 'Preventa')`) y el fallback repetía 'A pedido' a mano. Un renombre en
	 * Akibara → Textos rompía el botón sin que ningún otro candado lo viera:
	 * éste vigila el fichero en concreto.
	 *
	 * @testdox Ninguna superficie de «Mi colección» repite la palabra a mano.
	 */
	public function test_ninguna_superficie_de_mi_coleccion_repite_el_literal(): void {
		$archivo = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/AccountCollection/Renderer.php'
		);

		self::assertStringNotContainsString(
			"'Preventa' )",
			$archivo,
			'El CTA de Mi colección volvió a decidir el verbo comparando contra el texto de fábrica.'
		);
		self::assertStringNotContainsString(
			"'A pedido'",
			$archivo,
			'El fallback del label volvió a repetir el literal en vez de usar Availability::label_for_state().'
		);
	}
}
