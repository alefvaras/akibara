<?php
/**
 * Pruebas unitarias de los niveles de gamificación (Tiers).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Calculator;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Tiers;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.4.37
 */
final class TiersTest extends RewardsTestCase {

	public function test_tier_resolution_by_points(): void {
		// 1. Nivel Lector (0 a 1.999 pts).
		$t0 = Tiers::get_tier_by_points( 0 );
		$this->assertSame( Tiers::TIER_LECTOR, $t0['slug'] );
		$this->assertSame( 'Lector', $t0['name'] );
		$this->assertSame( 1.0, $t0['multiplier'] );
		$this->assertSame( 'Coleccionista', $t0['next_tier'] );
		$this->assertSame( 2000, $t0['next_min'] );
		$this->assertSame( 2000, $t0['pts_to_next'] );
		$this->assertSame( 0.0, $t0['progress_pct'] );

		$t500 = Tiers::get_tier_by_points( 500 );
		$this->assertSame( Tiers::TIER_LECTOR, $t500['slug'] );
		$this->assertSame( 1500, $t500['pts_to_next'] );
		$this->assertSame( 25.0, $t500['progress_pct'] );

		$t1999 = Tiers::get_tier_by_points( 1999 );
		$this->assertSame( Tiers::TIER_LECTOR, $t1999['slug'] );
		$this->assertSame( 1, $t1999['pts_to_next'] );
		$this->assertSame( 100.0, $t1999['progress_pct'] );

		// 2. Nivel Coleccionista (2.000 a 7.999 pts).
		$t2000 = Tiers::get_tier_by_points( 2000 );
		$this->assertSame( Tiers::TIER_COLECCIONISTA, $t2000['slug'] );
		$this->assertSame( 'Coleccionista', $t2000['name'] );
		$this->assertSame( 1.2, $t2000['multiplier'] );
		$this->assertSame( 'Residente de Akiba', $t2000['next_tier'] );
		$this->assertSame( 8000, $t2000['next_min'] );
		$this->assertSame( 6000, $t2000['pts_to_next'] );
		$this->assertSame( 0.0, $t2000['progress_pct'] );

		$t5000 = Tiers::get_tier_by_points( 5000 );
		$this->assertSame( Tiers::TIER_COLECCIONISTA, $t5000['slug'] );
		$this->assertSame( 3000, $t5000['pts_to_next'] );
		$this->assertSame( 50.0, $t5000['progress_pct'] );

		// 3. Nivel Residente de Akiba (8.000+ pts).
		$t8000 = Tiers::get_tier_by_points( 8000 );
		$this->assertSame( Tiers::TIER_RESIDENTE, $t8000['slug'] );
		$this->assertSame( 'Residente de Akiba', $t8000['name'] );
		$this->assertSame( 1.5, $t8000['multiplier'] );
		$this->assertNull( $t8000['next_tier'] );
		$this->assertNull( $t8000['next_min'] );
		$this->assertSame( 0, $t8000['pts_to_next'] );
		$this->assertSame( 100.0, $t8000['progress_pct'] );

		$t50000 = Tiers::get_tier_by_points( 50000 );
		$this->assertSame( Tiers::TIER_RESIDENTE, $t50000['slug'] );
		$this->assertSame( 1.5, $t50000['multiplier'] );
		$this->assertSame( 100.0, $t50000['progress_pct'] );
	}

	public function test_apply_multiplier(): void {
		$this->assertSame( 0, Tiers::apply_multiplier( 0, 1.2 ) );
		$this->assertSame( 200, Tiers::apply_multiplier( 200, 1.0 ) );
		$this->assertSame( 240, Tiers::apply_multiplier( 200, 1.2 ) );
		$this->assertSame( 300, Tiers::apply_multiplier( 200, 1.5 ) );
		$this->assertSame( 127, Tiers::apply_multiplier( 106, 1.2 ) ); // round(127.2) -> 127
	}

	public function test_calculator_points_for_with_tiers(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $opt, $default = false ) => match ( $opt ) {
				'akb_rewards_earn_points'   => 2,
				'akb_rewards_earn_amount'   => 100,
				'akb_rewards_tiers_enabled' => 'yes',
				default                     => $default,
			}
		);

		// Sin usuario o invitado -> base 200 pts por $10.000.
		$this->assertSame( 200, Calculator::points_for( 10000.0, 0 ) );

		// Usuario 10: Lector (500 ganados) -> 200 pts.
		Functions\when( 'get_user_meta' )->alias(
			static function( int $uid, string $key, bool $single = false ) {
				if ( Ledger::META_TOTAL_EARNED === $key ) {
					return match ( $uid ) {
						10 => 500,
						20 => 3500,  // Coleccionista
						30 => 12000, // Residente de Akiba
						default => 0,
					};
				}
				return $single ? '' : array();
			}
		);

		$this->assertSame( 200, Calculator::points_for( 10000.0, 10 ) );

		// Usuario 20: Coleccionista (+20%) -> 240 pts.
		$this->assertSame( 240, Calculator::points_for( 10000.0, 20 ) );

		// Usuario 30: Residente de Akiba (+50%) -> 300 pts.
		$this->assertSame( 300, Calculator::points_for( 10000.0, 30 ) );
	}

	public function test_calculator_respects_tiers_disabled_option(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $opt, $default = false ) => match ( $opt ) {
				'akb_rewards_earn_points'   => 2,
				'akb_rewards_earn_amount'   => 100,
				'akb_rewards_tiers_enabled' => 'no', // Tiers desactivados
				default                     => $default,
			}
		);

		Functions\when( 'get_user_meta' )->alias(
			static fn( int $uid, string $key ) => Ledger::META_TOTAL_EARNED === $key && 30 === $uid ? 15000 : 0
		);

		// Aunque el usuario 30 tenga 15.000 pts, si tiers_enabled = 'no', recibe tasa base 200.
		$this->assertSame( 200, Calculator::points_for( 10000.0, 30 ) );
	}

	public function test_badge_html_structure(): void {
		$badge = Tiers::badge_html( Tiers::TIER_COLECCIONISTA, 'Coleccionista', '🥈', true );
		$this->assertStringContainsString( 'akb-tier-badge--coleccionista', $badge );
		$this->assertStringContainsString( 'Nivel Coleccionista', $badge );
		$this->assertStringContainsString( '🥈', $badge );
	}

	public function test_review_badge_renders_only_for_coleccionista_and_residente(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $opt, $default = false ) => 'akb_rewards_tiers_enabled' === $opt ? 'yes' : $default
		);

		Functions\when( 'get_user_meta' )->alias(
			static function( int $uid, string $key ) {
				if ( Ledger::META_TOTAL_EARNED === $key ) {
					return match ( $uid ) {
						1 => 100,   // Lector
						2 => 3000,  // Coleccionista
						3 => 10000, // Residente de Akiba
						default => 0,
					};
				}
				return '';
			}
		);

		$tiers_service = new Tiers();

		// 1. Lector -> no imprime nada para no ensuciar comentarios básicos.
		$c1 = new \WP_Comment( array( 'user_id' => 1 ) );
		ob_start();
		$tiers_service->render_review_badge( $c1 );
		$out1 = ob_get_clean();
		$this->assertSame( '', $out1 );

		// 2. Coleccionista -> imprime insignia plateada.
		$c2 = new \WP_Comment( array( 'user_id' => 2 ) );
		ob_start();
		$tiers_service->render_review_badge( $c2 );
		$out2 = ob_get_clean();
		$this->assertStringContainsString( 'akb-tier-badge--coleccionista', $out2 );
		$this->assertStringContainsString( 'Coleccionista', $out2 );

		// 3. Residente de Akiba -> imprime insignia dorada.
		$c3 = new \WP_Comment( array( 'user_id' => 3 ) );
		ob_start();
		$tiers_service->render_review_badge( $c3 );
		$out3 = ob_get_clean();
		$this->assertStringContainsString( 'akb-tier-badge--residente-akiba', $out3 );
		$this->assertStringContainsString( 'Residente de Akiba', $out3 );
	}

	public function test_format_multiplier_preserves_step_precision(): void {
		$this->assertSame( '1.0', Tiers::format_multiplier( 1.0 ) );
		$this->assertSame( '1.2', Tiers::format_multiplier( 1.2 ) );
		$this->assertSame( '1.25', Tiers::format_multiplier( 1.25 ) );
		$this->assertSame( '1,25', Tiers::format_multiplier( 1.25, ',' ) );
		$this->assertSame( '1.5', Tiers::format_multiplier( 1.5 ) );
	}

	public function test_tier_validity_with_soft_landing(): void {
		// Simula vigencia activa de 365 días
		Functions\when( 'get_option' )->alias(
			static fn( string $opt, $default = false ) => match ( $opt ) {
				'akb_rewards_tiers_enabled'      => 'yes',
				'akb_rewards_tier_validity_days' => 365,
				default                          => $default,
			}
		);

		// Usuario 50: 10.000 pts acumulados (Residente de Akiba), pero con fecha de expiración pasada (hace 10 días).
		Functions\when( 'get_user_meta' )->alias(
			static function( int $uid, string $key, bool $single = false ) {
				if ( 50 === $uid ) {
					if ( Ledger::META_TOTAL_EARNED === $key ) {
						return 10000;
					}
					if ( Tiers::META_TIER_EXPIRES_AT === $key ) {
						return time() - ( 10 * 86400 );
					}
				}
				if ( 60 === $uid ) {
					if ( Ledger::META_TOTAL_EARNED === $key ) {
						return 3000; // Coleccionista
					}
					if ( Tiers::META_TIER_EXPIRES_AT === $key ) {
						return time() - ( 10 * 86400 ); // Expirado
					}
				}
				return $single ? '' : array();
			}
		);

		// Usuario 50 expiró de Residente: Soft-Landing a Coleccionista (1.2x) en vez de corte a cero
		$tier50 = Tiers::for_user( 50 );
		$this->assertSame( Tiers::TIER_COLECCIONISTA, $tier50['slug'] );
		$this->assertSame( 1.2, $tier50['multiplier'] );

		// Usuario 60 expiró de Coleccionista: cae a Lector (1.0x)
		$tier60 = Tiers::for_user( 60 );
		$this->assertSame( Tiers::TIER_LECTOR, $tier60['slug'] );
		$this->assertSame( 1.0, $tier60['multiplier'] );
	}

	public function test_vitalicio_mode_never_expires(): void {
		// Simula vigencia = 0 (Vitalicio / no vence jamás)
		Functions\when( 'get_option' )->alias(
			static fn( string $opt, $default = false ) => match ( $opt ) {
				'akb_rewards_tiers_enabled'      => 'yes',
				'akb_rewards_tier_validity_days' => 0,
				default                          => $default,
			}
		);

		Functions\when( 'get_user_meta' )->alias(
			static function( int $uid, string $key, bool $single = false ) {
				if ( 70 === $uid ) {
					if ( Ledger::META_TOTAL_EARNED === $key ) {
						return 15000; // Residente de Akiba
					}
					if ( Tiers::META_TIER_EXPIRES_AT === $key ) {
						return time() - 999999; // Fecha vieja en el pasado
					}
				}
				return $single ? '' : array();
			}
		);

		$tier70 = Tiers::for_user( 70 );
		$this->assertSame( Tiers::TIER_RESIDENTE, $tier70['slug'] );
		$this->assertSame( 1.5, $tier70['multiplier'] );
	}

	/** @testdox for_user() nunca ejecuta escrituras update_user_meta en GET ni activa nivel si el interruptor está apagado. */
	public function test_for_user_never_writes_usermeta_on_get_and_respects_disabled(): void {
		Functions\expect( 'update_user_meta' )->never();

		Functions\when( 'get_option' )->alias(
			static fn( string $opt, $default = false ) => match ( $opt ) {
				'akb_rewards_tiers_enabled'      => 'yes',
				'akb_rewards_tier_validity_days' => 365,
				default                          => $default,
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $key, bool $single = false ) {
				if ( 80 === $uid && Ledger::META_TOTAL_EARNED === $key ) {
					return 3000;
				}
				return $single ? '' : array();
			}
		);

		$tier80 = Tiers::for_user( 80 );
		$this->assertSame( Tiers::TIER_COLECCIONISTA, $tier80['slug'] );
		$this->assertGreaterThan( time(), (int) ( $tier80['expires_at'] ?? 0 ) );

		Functions\when( 'get_option' )->alias(
			static fn( string $opt, $default = false ) => 'akb_rewards_tiers_enabled' === $opt ? 'no' : $default
		);
		$disabled = Tiers::for_user( 80 );
		$this->assertSame( Tiers::TIER_LECTOR, $disabled['slug'] );
		$this->assertSame( 1.0, $disabled['multiplier'] );
		$this->assertSame( 1.0, Tiers::multiplier_for_user( 80 ) );
	}

	/** @testdox Soft-Landing desde Residente expirado fija progress_pct en 0.0 (no 99%). */
	public function test_soft_landing_sets_zero_progress_pct(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $opt, $default = false ) => match ( $opt ) {
				'akb_rewards_tiers_enabled'      => 'yes',
				'akb_rewards_tier_validity_days' => 365,
				default                          => $default,
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $key, bool $single = false ) {
				if ( 90 === $uid ) {
					if ( Ledger::META_TOTAL_EARNED === $key ) {
						return 10000;
					}
					if ( Tiers::META_TIER_EXPIRES_AT === $key ) {
						return time() - 86400;
					}
				}
				return $single ? '' : array();
			}
		);

		$tier90 = Tiers::for_user( 90 );
		$this->assertTrue( ! empty( $tier90['soft_landed'] ) );
		$this->assertSame( 0.0, $tier90['progress_pct'] );
	}
}
