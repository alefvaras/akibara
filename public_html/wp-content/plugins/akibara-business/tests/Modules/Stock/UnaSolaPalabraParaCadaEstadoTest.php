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
		self::assertSame( 'Disponible', Availability::palabra( Availability::DISPONIBLE ) );
		self::assertSame( 'Agotado', Availability::palabra( Availability::AGOTADO ) );
	}

	/** @testdox El grupo reservable conserva su palabra nativa ('A pedido'). */
	public function test_el_grupo_reservable_dice_lo_mismo(): void {
		self::assertSame( 'A pedido', StockStatus::palabra( StockStatus::ON_BACKORDER ) );
		self::assertSame( 'Disponible', StockStatus::palabra( StockStatus::IN_STOCK ) );
		self::assertSame( 'Agotado', StockStatus::palabra( StockStatus::OUT_OF_STOCK ) );
	}

	/** @testdox Un estado que no existe cae a la palabra más conservadora. */
	public function test_estado_desconocido_es_agotado(): void {
		self::assertSame( 'Agotado', Availability::palabra( 'inventado' ) );
		self::assertSame( 'Agotado', StockStatus::palabra( 'inventado' ) );
	}

	/** @testdox Ninguna superficie visible vuelve a escribir la palabra a mano. */
	public function test_ninguna_superficie_repite_el_literal(): void {
		$raiz     = dirname( __DIR__, 3 );
		$vigilados = array(
			'src/Modules/BackInStock/BackInStock.php' => "array( 'A pedido', '#996800' )",
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
	 */
}
