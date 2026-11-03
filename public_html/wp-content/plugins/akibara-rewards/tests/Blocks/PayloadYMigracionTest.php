<?php
/**
 * Dos piezas pequeñas que la revisión independiente del 2026-09-16 encontró
 * rotas y que no tenían quién las mirara:
 *
 *  1. `account_url`: `wc_get_page_permalink()` cae a la PORTADA cuando la
 *     página de Mi cuenta no existe, así que el enlace del carrito llevaba al
 *     home; y con el registro apagado en WooCommerce lleva a una puerta que
 *     sólo deja entrar, no registrarse.
 *  2. La migración del estado que acredita: cambiar el default no mueve a las
 *     tiendas donde el panel ya guardó el valor viejo.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Blocks;

use Akibara\Rewards\Blocks\CheckoutIntegration;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\Schema;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.4.4
 */
final class PayloadYMigracionTest extends RewardsTestCase {

	/** Invoca un método privado estático sin cambiar su visibilidad por la prueba. */
	private static function llamar( string $clase, string $metodo, array $args = array() ) {
		$m = new \ReflectionMethod( $clase, $metodo );
		$m->setAccessible( true );

		return $m->invokeArgs( null, $args );
	}

	private function tienda( int $page_id, string $registro ): void {
		Functions\when( 'wc_get_page_id' )->justReturn( $page_id );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.cl/mi-cuenta/' );
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'woocommerce_enable_myaccount_registration' === $option ? $registro : $default_value
		);
	}

	/** @testdox Con la página de Mi cuenta puesta y el registro abierto, se ofrece el enlace. */
	public function test_con_registro_abierto_hay_enlace(): void {
		$this->tienda( 9, 'yes' );

		self::assertSame( 'https://akibara.cl/mi-cuenta/', self::llamar( CheckoutIntegration::class, 'account_url' ) );
	}

	/** @testdox Sin página de Mi cuenta no se manda a la portada disfrazada de registro. */
	public function test_sin_pagina_no_hay_enlace(): void {
		$this->tienda( 0, 'yes' );

		self::assertSame( '', self::llamar( CheckoutIntegration::class, 'account_url' ) );
	}

	/** @testdox Con el registro cerrado en WooCommerce tampoco: sería una puerta cerrada. */
	public function test_sin_registro_no_hay_enlace(): void {
		$this->tienda( 9, 'no' );

		self::assertSame( '', self::llamar( CheckoutIntegration::class, 'account_url' ) );
	}

	/**
	 * El guardado del panel escribe TODAS las options del catálogo, así que en
	 * cualquier tienda donde el dueño haya pulsado «Guardar» una vez quedó
	 * `shipping-progress` persistido y el default nuevo no la tocaría jamás.
	 *
	 * @testdox El estado viejo de fábrica se migra al nuevo, una sola vez.
	 */
	public function test_la_migracion_mueve_el_estado_viejo(): void {
		$escrito = array();
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_award_status' === $option ? 'shipping-progress' : $default_value
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $option, $value, $autoload = null ) use ( &$escrito ): bool {
				$escrito[ $option ] = $value;
				return true;
			}
		);

		self::llamar( Schema::class, 'migrate_award_status' );

		self::assertSame( Options::DEFAULT_AWARD_STATUS, $escrito['akb_rewards_award_status'] ?? null );
	}

	/** @testdox Un estado elegido a mano por el dueño NO se toca. */
	public function test_la_migracion_respeta_lo_que_eligio_el_dueno(): void {
		$escrito = array();
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_award_status' === $option ? 'completed' : $default_value
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $option, $value, $autoload = null ) use ( &$escrito ): bool {
				$escrito[ $option ] = $value;
				return true;
			}
		);

		self::llamar( Schema::class, 'migrate_award_status' );

		self::assertSame( array(), $escrito );
	}

	/** @testdox La versión de esquema sube con la migración: si no, no corre en las tiendas vivas. */
	public function test_la_version_de_esquema_subio(): void {
		self::assertTrue( version_compare( Schema::DB_VERSION, '1.2.0', '>' ) );
	}
}
