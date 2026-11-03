<?php
/**
 * Lo que no se va a acreditar, no se promete — en NINGUNA superficie.
 *
 * La acreditación tiene una compuerta de vigencia: con «Inicio de vigencia del
 * programa» en el futuro, o ilegible, no acredita nadie. La caja ya callaba
 * (0.4.4). La ficha, Mi cuenta y la página pública seguían prometiendo «Gana N
 * Puntos Akibara» con la misma matemática y sin preguntar (hallazgo
 * R2-2c367bf6, ronda 2 del 2026-09-19).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\PdpNotice;
use Akibara\Rewards\Points\ProgramPage;
use Akibara\Rewards\Points\Promise;
use Akibara\Rewards\Points\Referrals;
use Akibara\Rewards\Points\WelcomeBonus;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class LoQueNoAcreditaNoSePrometeTest extends RewardsTestCase {

	private function con_inicio( string $valor ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_program_start' === $option ? $valor : $default_value
		);
	}

	private static function manana(): string {
		return ( new \DateTimeImmutable( '@' . ( time() + 86400 ) ) )->format( 'Y-m-d H:i:s' );
	}

	/** @testdox Con el programa vigente se promete lo mismo que después se acredita. */
	public function test_vigente_promete_la_regla(): void {
		$this->con_inicio( '2026-08-17 00:00:00' );

		self::assertSame( Accrual::PROGRAMA_VIGENTE, Accrual::program_state() );
		self::assertSame( 200, Promise::points_for( 10000.0 ), '2 puntos por cada $100.' );
		self::assertSame(
			array(
				'points' => 2,
				'amount' => 100,
			),
			Promise::rate()
		);
	}

	/** @testdox Con el programa todavía sin empezar, ninguna superficie promete puntos por comprar hoy. */
	public function test_agendado_no_promete_nada_hoy(): void {
		$this->con_inicio( self::manana() );

		self::assertSame( Accrual::PROGRAMA_AGENDADO, Accrual::program_state() );
		self::assertSame( 0, Promise::points_for( 10000.0 ), 'La ficha no imprime «Gana N puntos» con 0.' );
		self::assertSame(
			array(
				'points' => 0,
				'amount' => 0,
			),
			Promise::rate(),
			'Mi cuenta no dice «se suman solos con cada compra».'
		);
	}

	/** @testdox Con la fecha ilegible tampoco: es la misma compuerta que cierra la acreditación. */
	public function test_ilegible_no_promete_nada(): void {
		foreach ( array( '2026-08-17', 'ayer', '17/08/2026' ) as $malo ) {
			$this->con_inicio( $malo );

			self::assertSame( Accrual::PROGRAMA_ILEGIBLE, Accrual::program_state(), $malo );
			self::assertSame( 0, Promise::points_for( 10000.0 ), $malo );
		}
	}

	/**
	 * `createFromFormat()` acepta «2026-13-45 00:00:00» y lo vuelve el 14 de
	 * febrero de 2027 sin avisar. El panel ya exigía el viaje de ida y vuelta;
	 * el motor no, y la página pública habría anunciado una fecha que nadie
	 * escribió.
	 *
	 * @testdox Una fecha que no sobrevive el viaje de ida y vuelta es ILEGIBLE, no «agendada para 2027».
	 */
	public function test_una_fecha_desbordada_es_ilegible(): void {
		$this->con_inicio( '2026-13-45 00:00:00' );

		self::assertNull( Accrual::parse_program_start( '2026-13-45 00:00:00' ) );
		self::assertSame( Accrual::PROGRAMA_ILEGIBLE, Accrual::program_state() );
		self::assertSame( '', ProgramPage::start_label(), 'Ni «desde el 14 de febrero de 2027».' );
	}

	/** Pinta la ficha de un tomo comprable de $11.900. */
	private function ficha(): string {
		Functions\when( 'wc_get_product' )->alias(
			static fn() => new class() extends \WC_Product {
				public function is_purchasable(): bool {
					return true;
				}
			}
		);
		Functions\when( 'wc_get_price_including_tax' )->justReturn( 11900.0 );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_kses_post' )->returnArg();

		ob_start();
		( new PdpNotice() )->render();

		return (string) ob_get_clean();
	}

	/** @testdox La FICHA no imprime «Gana N Puntos Akibara» mientras el programa no rige, y vuelve a imprimirlo cuando rige. */
	public function test_la_ficha_calla_fuera_de_vigencia(): void {
		$this->con_inicio( self::manana() );
		self::assertSame( '', $this->ficha(), 'Agendado: comprar hoy no acredita.' );

		$this->con_inicio( 'ayer' );
		self::assertSame( '', $this->ficha(), 'Ilegible: no acredita nadie.' );

		$this->con_inicio( '2026-08-17 00:00:00' );
		self::assertStringContainsString( '238 Puntos Akibara', $this->ficha() );
	}

	/** @testdox La página pública anuncia la regla CON su fecha si el programa está agendado, y quita la fila si la fecha es ilegible. */
	public function test_la_fila_de_cada_compra_segun_el_estado(): void {
		Functions\when( 'wp_date' )->alias( static fn( string $formato, int $instante ): string => gmdate( 'Y-m-d', $instante ) );

		$this->con_inicio( self::manana() );
		$agendado = ( new ProgramPage() )->render();
		self::assertStringContainsString( '2 puntos por cada $100 de productos, desde el ', $agendado );

		$this->con_inicio( 'ayer' );
		self::assertStringNotContainsString( 'Cada compra elegible', ( new ProgramPage() )->render() );

		$this->con_inicio( '2026-08-17 00:00:00' );
		$vigente = ( new ProgramPage() )->render();
		self::assertStringContainsString( 'Cada compra elegible', $vigente );
		self::assertStringNotContainsString( ', desde el ', $vigente, 'Ya rige: no hace falta decir desde cuándo.' );
	}

	/** @testdox La página del programa dice la tasa con el programa agendado (es la regla) y calla con la fecha ilegible. */
	public function test_la_pagina_del_programa_segun_el_estado(): void {
		Functions\when( 'wp_date' )->alias( static fn( string $formato, int $instante ): string => gmdate( 'Y-m-d', $instante ) );

		$this->con_inicio( self::manana() );
		self::assertSame( '2%', ProgramPage::rate_label() );
		self::assertSame( '2 puntos por cada $100', ProgramPage::ratio_sentence() );
		$agendada = ProgramPage::examples_table();
		self::assertNotSame( '', $agendada );
		self::assertStringContainsString(
			'Esta tabla rige desde el ',
			$agendada,
			'Agendado: comprar hoy leyendo la tabla de ejemplos no acredita nada — tiene que decir desde cuándo (R3-bd4c0ed7).'
		);

		$this->con_inicio( '2026-08-17 00:00:00' );
		self::assertStringNotContainsString(
			'desde el',
			ProgramPage::examples_table(),
			'Ya rige: no hace falta decir desde cuándo.'
		);

		$this->con_inicio( 'ayer' );
		self::assertSame( '0%', ProgramPage::rate_label(), 'Hoy no acredita nadie: no hay «2%» que anunciar.' );
		self::assertSame( '0 puntos', ProgramPage::ratio_sentence() );
		self::assertSame( '', ProgramPage::examples_table() );
	}

	/**
	 * El bono de bienvenida y el premio al padrino cuelgan de la MISMA compuerta:
	 * `Accrual::award()` corta antes de emitir el hook del bono y
	 * `Referrals::reason_not_to_pay()` rechaza el pedido fuera de vigencia. La
	 * primera versión de `Promise` sólo envolvía el tramo por compra, y el
	 * registro, Mi cuenta y la caja seguían prometiendo «se activan con tu
	 * primera compra» (revisión independiente del 2026-09-19).
	 *
	 * @testdox El bono y el premio al padrino tampoco se prometen fuera de vigencia.
	 */
	public function test_el_bono_y_el_padrino_pasan_por_la_misma_compuerta(): void {
		$this->con_inicio( '2026-08-17 00:00:00' );
		self::assertSame( 500, Promise::welcome_bonus() );
		self::assertSame( 500, Promise::referral_points() );

		foreach ( array( self::manana(), 'ayer' ) as $fuera ) {
			$this->con_inicio( $fuera );
			self::assertSame( 0, Promise::welcome_bonus(), "«{$fuera}»: esa primera compra no paga el bono." );
			self::assertSame( 0, Promise::referral_points(), "«{$fuera}»: esa compra del invitado no paga al padrino." );
		}
	}

	/** @testdox El gancho del registro y el banner «esperándote» callan fuera de vigencia. */
	public function test_el_registro_y_mi_cuenta_no_prometen_el_bono_fuera_de_vigencia(): void {
		$gancho = static function (): string {
			ob_start();
			( new WelcomeBonus() )->render_register_teaser();
			return (string) ob_get_clean();
		};

		$this->con_inicio( '2026-08-17 00:00:00' );
		self::assertStringContainsString( '500 Puntos Akibara', $gancho() );
		self::assertTrue( WelcomeBonus::is_pending_for( 7 ) );

		$this->con_inicio( self::manana() );
		self::assertSame( '', $gancho(), 'Quien se registra y compra hoy no recibe el bono: no se le promete.' );
		self::assertFalse( WelcomeBonus::is_pending_for( 7 ) );

		$this->con_inicio( 'ayer' );
		self::assertSame( '', $gancho() );
		self::assertFalse( WelcomeBonus::is_pending_for( 7 ) );
	}

	/** @testdox «Invita a un amigo» no se ofrece mientras la compra del invitado no pague al padrino. */
	public function test_la_invitacion_no_se_ofrece_fuera_de_vigencia(): void {
		Functions\when( 'wp_hash' )->alias( static fn( string $data ): string => md5( $data ) );

		$bloque = static function (): string {
			ob_start();
			( new Referrals() )->render_invite_block( 7 );
			return (string) ob_get_clean();
		};

		$this->con_inicio( '2026-08-17 00:00:00' );
		self::assertStringContainsString( '500 Puntos Akibara', $bloque() );

		$this->con_inicio( self::manana() );
		self::assertSame( '', $bloque() );
	}

	/** @testdox La página pública anuncia el bono y los referidos con la misma regla que la compra. */
	public function test_la_pagina_publica_trata_igual_al_bono_y_a_los_referidos(): void {
		Functions\when( 'wp_date' )->alias( static fn( string $formato, int $instante ): string => gmdate( 'Y-m-d', $instante ) );

		$this->con_inicio( self::manana() );
		$agendado = ( new ProgramPage() )->render();
		self::assertMatchesRegularExpression( '/puntos \(\$500\) de bienvenida \(uno por persona\), desde el /', $agendado );
		self::assertMatchesRegularExpression( '/puntos por invitado[^<]*, desde el /', $agendado );

		$this->con_inicio( 'ayer' );
		$ilegible = ( new ProgramPage() )->render();
		self::assertStringNotContainsString( 'puntos ($500) de bienvenida', $ilegible );
		self::assertStringNotContainsString( 'puntos por invitado', $ilegible );
		self::assertSame( '0', ( new ProgramPage() )->render( array( 'solo' => 'bienvenida' ) ) );

		$this->con_inicio( '2026-08-17 00:00:00' );
		$vigente = ( new ProgramPage() )->render();
		self::assertStringContainsString( '+500 puntos ($500) de bienvenida (uno por persona)', $vigente );
		self::assertStringContainsString( 'puntos por invitado', $vigente );
		self::assertSame( '500', ( new ProgramPage() )->render( array( 'solo' => 'bienvenida' ) ) );
	}
}
