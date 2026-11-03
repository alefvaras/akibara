<?php
/**
 * El orden de los estados pagados, que es el que decide cuándo se acredita.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Support\OrderStates;

/**
 * @since 0.4.4
 */
final class OrderStatesTest extends RewardsTestCase {

	/** @testdox El estado de referencia y todos los pagados posteriores cuentan. */
	public function test_el_estado_y_los_posteriores(): void {
		self::assertTrue( OrderStates::is_at_or_after( 'processing', 'processing' ) );
		self::assertTrue( OrderStates::is_at_or_after( 'shipping-progress', 'processing' ) );
		self::assertTrue( OrderStates::is_at_or_after( 'completed', 'processing' ) );
		self::assertTrue( OrderStates::is_at_or_after( 'completed', 'shipping-progress' ) );
	}

	/** @testdox Un estado anterior no adelanta la acreditación. */
	public function test_los_anteriores_no(): void {
		self::assertFalse( OrderStates::is_at_or_after( 'processing', 'shipping-progress' ) );
		self::assertFalse( OrderStates::is_at_or_after( 'shipping-progress', 'completed' ) );
	}

	/** @testdox Fuera de los estados pagados no hay orden: sólo coincide él mismo. */
	public function test_fuera_de_pagados_solo_coincide_el_mismo(): void {
		// El panel deja elegir cualquier estado de la tienda, incluidos los que
		// no son de venta pagada: ahí no hay «posterior» que valga.
		self::assertTrue( OrderStates::is_at_or_after( 'on-hold', 'on-hold' ) );
		self::assertFalse( OrderStates::is_at_or_after( 'completed', 'on-hold' ) );
		self::assertFalse( OrderStates::is_at_or_after( 'on-hold', 'processing' ) );
		self::assertFalse( OrderStates::is_at_or_after( 'cancelled', 'processing' ) );
		self::assertFalse( OrderStates::is_at_or_after( 'un-estado-inventado', 'processing' ) );
	}

	/** @testdox El default de fábrica es el estado con el pago confirmado. */
	public function test_el_default_es_procesando(): void {
		self::assertSame( 'processing', \Akibara\Rewards\Support\Options::DEFAULT_AWARD_STATUS );
		self::assertContains( \Akibara\Rewards\Support\Options::DEFAULT_AWARD_STATUS, OrderStates::PAID );
	}
}
