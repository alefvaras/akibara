<?php
/**
 * Referidos: lo que faltaba para que el programa funcione de verdad.
 *
 * Hallado el 2026-09-13 al escribir el manual del dueño:
 *
 *   - El enlace de invitación NO ligaba a nadie en producción. PHP escribía la
 *     cookie con `setcookie()` y Cloudflare quita el `Set-Cookie` de las
 *     páginas que puede cachear, también en la primera visita (medido con curl:
 *     el origen respondía `akb_ref=…`, el borde lo entregaba sin él). En el
 *     libro de puntos de producción no había un solo premio de referido.
 *   - El tope del padrino contaba también los premios que después se le
 *     descontaron.
 *   - Un premio que no se pagaba no dejaba rastro en el pedido.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Referrals;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.4.1
 */
final class ReferidosCierreTest extends RewardsTestCase {

	private Referrals $referrals;

	/** @var array<int,\WP_User> */
	private array $users = array();

	/** @var array<string,mixed> */
	private array $opciones = array();

	protected function setUp(): void {
		parent::setUp();

		$this->referrals = new Referrals();

		Functions\when( 'get_user_by' )->alias( fn( string $by, $id ) => $this->users[ (int) $id ] ?? false );
		Functions\when( 'wp_hash' )->alias( static fn( string $data ): string => md5( $data ) );
		Functions\when( 'wp_salt' )->justReturn( 'sal-de-prueba' );
		Functions\when( 'add_query_arg' )->alias(
			static fn( string $key, string $value, string $url ): string => $url . '?' . $key . '=' . $value
		);

		$opciones = &$this->opciones;
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) use ( &$opciones ) {
				return array_key_exists( $option, $opciones ) ? $opciones[ $option ] : $default_value;
			}
		);

		$padrino             = new \WP_User( 7 );
		$padrino->user_email = 'padrino@example.com';
		$this->users[7]      = $padrino;
	}

	/** @testdox La visita con el enlace se capta en el navegador, no en PHP. */
	public function test_la_visita_se_capta_en_el_navegador(): void {
		$colgados = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$colgados ): void {
				$colgados[] = $hook;
			}
		);

		$this->referrals->register();

		$this->assertContains( 'wp_enqueue_scripts', $colgados );
		$this->assertNotContains( 'init', $colgados, 'PHP no puede escribir la cookie: Cloudflare quita el Set-Cookie de la página.' );
	}

	/**
	 * @testdox El script deja la cookie que lee el sellado ($busqueda).
	 * @dataProvider busquedas
	 *
	 * @param string $busqueda Lo que trae la barra de direcciones.
	 * @param string $esperada Cookie resultante, '' si no debe escribir nada.
	 */
	public function test_el_script_deja_la_cookie_con_el_codigo_del_enlace( string $busqueda, string $esperada ): void {
		$node = trim( (string) shell_exec( 'command -v node' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'Esta prueba ejecuta el script de verdad y necesita node.' );
		}

		$arnes = 'var document={cookie:""};var window={location:{search:process.argv[1],protocol:"https:"}};'
			. Referrals::capture_script( 30 )
			. 'process.stdout.write(document.cookie);';

		$cookie = (string) shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $arnes ) . ' ' . escapeshellarg( $busqueda ) );

		$this->assertSame( $esperada, $cookie );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function busquedas(): array {
		$cola = '; max-age=2592000; path=/; SameSite=Lax; Secure';

		return array(
			'enlace de invitación'         => array( '?ref=AbC123def0', 'akb_ref=abc123def0' . $cola ),
			'con otros parámetros'         => array( '?utm_source=ig&ref=abc123def0&x=1', 'akb_ref=abc123def0' . $cola ),
			'parámetro con otro nombre'    => array( '?referrer=abc123def0', '' ),
			'vacío'                        => array( '?ref=', '' ),
			'con marcado'                  => array( '?ref=abc<script>', '' ),
			'más largo que cualquier código' => array( '?ref=' . str_repeat( 'a', 21 ), '' ),
			'sin parámetros'               => array( '', '' ),
		);
	}

	/** @testdox Un premio revertido devuelve su cupo del tope. */
	public function test_un_premio_revertido_libera_el_cupo_del_tope(): void {
		$this->opciones['akb_rewards_referral_max_per_user']      = 1;
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;
		$this->wpdb->user_meta[43][ Referrals::META_REFERRED_BY ] = 7;

		$primero = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$this->referrals->maybe_pay_referrer( $primero, 42 );
		$this->referrals->maybe_reverse_referrer( $primero, 42 );
		$this->assertSame( 0, Ledger::balance( 7 ) );

		$this->referrals->maybe_pay_referrer( $this->make_order( 101, 43, 10000.0, 1900.0 ), 43 );

		$this->assertSame( 500, Ledger::balance( 7 ), 'El premio que se le descontó no puede seguir ocupando cupo.' );
	}

	/** @testdox El tope alcanzado queda anotado en el pedido, una sola vez. */
	public function test_el_tope_alcanzado_deja_una_nota_una_sola_vez(): void {
		$this->opciones['akb_rewards_referral_max_per_user']      = 1;
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;
		$this->wpdb->user_meta[43][ Referrals::META_REFERRED_BY ] = 7;

		$this->referrals->maybe_pay_referrer( $this->make_order( 100, 42, 10000.0, 1900.0 ), 42 );

		$segundo = $this->make_order( 101, 43, 10000.0, 1900.0 );
		$this->referrals->maybe_pay_referrer( $segundo, 43 );
		$this->referrals->maybe_pay_referrer( $segundo, 43 );

		$this->assertSame( 500, Ledger::balance( 7 ) );
		$this->assertCount( 1, $segundo->notes, 'Re-completar el pedido no repite la nota.' );
		$this->assertStringContainsString( 'tope', $segundo->notes[0] );
		$this->assertStringContainsString( '#7', $segundo->notes[0], 'La nota dice a quién no se le pagó.' );
	}

	/** @testdox Si no es la primera compra de esa persona, el pedido lo dice. */
	public function test_una_compra_que_no_es_la_primera_deja_la_nota_con_el_motivo(): void {
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;
		Functions\when( 'wc_get_orders' )->justReturn( array( 55 ) );

		$pedido = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$this->referrals->maybe_pay_referrer( $pedido, 42 );

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertCount( 1, $pedido->notes );
		$this->assertStringContainsString( 'no es la primera compra', $pedido->notes[0] );
	}

	/** @testdox Un invitado que vuelve a comprar no llena sus pedidos de la misma nota. */
	public function test_un_invitado_que_vuelve_a_comprar_no_repite_la_nota(): void {
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;
		Functions\when( 'wc_get_orders' )->justReturn( array( 55 ) );

		$primero = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$segundo = $this->make_order( 101, 42, 8000.0, 1520.0 );
		$this->referrals->maybe_pay_referrer( $primero, 42 );
		$this->referrals->maybe_pay_referrer( $segundo, 42 );

		$this->assertCount( 1, $primero->notes );
		$this->assertSame( array(), $segundo->notes, 'El motivo ya quedó anotado una vez para este cliente.' );
	}

	/** @testdox El premio pagado también queda anotado en el pedido que lo pagó. */
	public function test_el_premio_pagado_queda_anotado(): void {
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;
		$pedido = $this->make_order( 100, 42, 10000.0, 1900.0 );

		$this->referrals->maybe_pay_referrer( $pedido, 42 );

		$this->assertStringContainsString( 'se pagaron 500 Puntos Akibara a quien invitó a este cliente (cuenta #7)', implode( ' ', $pedido->notes ) );
	}

	/** @testdox Un cliente que nadie invitó no deja notas de referidos. */
	public function test_sin_padrino_no_hay_nota(): void {
		$pedido = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$this->referrals->maybe_pay_referrer( $pedido, 42 );

		$this->assertSame( array(), $pedido->notes );
	}

	/** @testdox Un premio que no se pudo acreditar avisa al dueño en vez de perderse en silencio. */
	public function test_un_premio_que_no_se_acredita_avisa(): void {
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;
		$pedido                  = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$this->wpdb->lock_result = '0';

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_referral_pay_failed' )->once();

		$this->referrals->maybe_pay_referrer( $pedido, 42 );

		$this->assertSame( 0, Ledger::balance( 7 ) );
	}

	/** @testdox El aviso de premio pagado lleva el pedido que lo justificó. */
	public function test_el_aviso_de_pago_lleva_el_pedido(): void {
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;
		$pedido = $this->make_order( 100, 42, 10000.0, 1900.0 );

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_referral_paid' )
			->once()
			->with( 7, 42, 500, $pedido );

		$this->referrals->maybe_pay_referrer( $pedido, 42 );

		$this->assertSame( 500, Ledger::balance( 7 ) );
	}

	/** @testdox «Ya llevas N» cuenta sólo los premios que el padrino conserva. */
	public function test_mi_cuenta_cuenta_solo_los_premios_vigentes(): void {
		$this->wpdb->user_meta[42][ Referrals::META_REFERRED_BY ] = 7;
		$this->wpdb->user_meta[43][ Referrals::META_REFERRED_BY ] = 7;

		$anulado = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$this->referrals->maybe_pay_referrer( $anulado, 42 );
		$this->referrals->maybe_reverse_referrer( $anulado, 42 );
		$this->referrals->maybe_pay_referrer( $this->make_order( 101, 43, 10000.0, 1900.0 ), 43 );

		ob_start();
		$this->referrals->render_invite_block( 7 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '(ya llevas 1)', $html );
	}
}
