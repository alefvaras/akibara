<?php
/**
 * Contratos de las superficies de cliente de la Fase 1b: endpoint de Mi
 * cuenta (canje topado por saldo), aviso de la ficha (promesa = matemática
 * real del programa) y empujón del correo de entrega (idea nº4 aprobada).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\AccountRewards;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\PdpNotice;
use Akibara\Rewards\Points\ProgramPage;
use Akibara\Rewards\Points\Redemption;
use Akibara\Rewards\Reviews\ReviewNudge;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.2.0
 */
final class AccountSurfacesTest extends RewardsTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wc_get_order_status_name' )->alias(
			static fn( string $status ): string => array( 'completed' => 'Completado', 'shipping-progress' => 'Listo para enviar' )[ $status ] ?? $status
		);
	}


	public function test_the_menu_item_lands_before_logout(): void {
		$account = new AccountRewards();

		$items = $account->menu_item(
			array(
				'orders'          => 'Pedidos',
				'customer-logout' => 'Salir',
			)
		);

		$this->assertSame(
			array( 'orders', AccountRewards::ENDPOINT, 'customer-logout' ),
			array_keys( $items ),
			'«Puntos Akibara» entra antes de «Salir».'
		);
	}

	public function test_redeem_rejects_changed_balance_then_confirms_explicit_amount(): void {
		$wc = $this->install_fake_wc();
		$wc->cart = new class() extends \WC_Cart {
			public function calculate_totals(): void {}
		};
		$wc->cart->contents = array( array( 'line_total' => 50000.0, 'line_tax' => 0.0 ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_add_notice' )->justReturn( null );
		Functions\when( 'wc_price' )->alias( static fn( $n ) => '$' . number_format( (float) $n, 0, ',', '.' ) );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wc_get_account_endpoint_url' )->justReturn( 'https://akibara.cl/mi-cuenta/rewards/' );
		$redirects = array();
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ) use ( &$redirects ): bool {
				$redirects[] = $url;
				return true;
			}
		);

		Ledger::credit( 7, 300, 'order', 'order', 50 );

		$fijados = ( new AccountRewards() )->process_redeem( 7, 9999 ); // Pide más de lo que tiene.

		$this->assertSame( 0, $fijados );
		$this->assertSame( 0, Redemption::intent_points(), 'Una solicitud por encima del máximo exige confirmar de nuevo.' );
		$this->assertSame( 300, ( new AccountRewards() )->process_redeem_input( 7, '300' ) );
		$this->assertSame( 300, Redemption::intent_points() );

		// Pedir 0 la borra.
		( new AccountRewards() )->process_redeem( 7, 0 );
		$this->assertSame( 0, Redemption::intent_points() );
	}

	/** @testdox El formulario rechaza decimales, negativos y entradas inválidas sin cambiar el canje previo. */
	public function test_redeem_form_validates_raw_input_before_changing_intent(): void {
		$this->install_fake_wc();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wc_get_account_endpoint_url' )->justReturn( 'https://akibara.cl/mi-cuenta/rewards/' );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new \RuntimeException( 'account-redirect' );
			}
		);
		$notices = array();
		Functions\when( 'wc_add_notice' )->alias(
			static function ( string $message, string $type ) use ( &$notices ): void {
				$notices[] = array( $message, $type );
			}
		);
		$original_post = $_POST;
		try {
			foreach ( array( '100.5', '-1', 'abc', '', array( '100' ), (string) PHP_INT_MAX . '0', null ) as $raw ) {
				Redemption::set_intent( 300 );
				$_POST = array( 'akb_rw_action' => AccountRewards::REDEEM_ACTION );
				if ( null !== $raw ) {
					$_POST['akb_rw_points'] = $raw;
				}
				try {
					( new AccountRewards() )->handle_redeem();
					self::fail( 'El wrapper debe redirigir después de validar.' );
				} catch ( \RuntimeException $exception ) {
					self::assertSame( 'account-redirect', $exception->getMessage() );
				}
				self::assertSame( 300, Redemption::intent_points(), 'Una entrada inválida conserva la intención anterior.' );
				self::assertSame( 'error', end( $notices )[1], 'El cliente recibe un error visible.' );
			}
			$_POST = array( 'akb_rw_action' => AccountRewards::REDEEM_ACTION, 'akb_rw_points' => '0' );
			try {
				( new AccountRewards() )->handle_redeem();
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'account-redirect', $exception->getMessage() );
			}
			self::assertSame( 0, Redemption::intent_points(), 'Sólo cero explícito retira el canje.' );
			self::assertSame( 'notice', end( $notices )[1] );
		} finally {
			$_POST = $original_post;
		}
	}

	/** @testdox Una llamada interna negativa tampoco puede retirar el canje existente. */
	public function test_redeem_core_rejects_negative_without_removing_intent(): void {
		$this->install_fake_wc();
		Redemption::set_intent( 300 );
		Functions\expect( 'wc_add_notice' )->once()->with( 'Ingresa un número entero de puntos mayor o igual a cero.', 'error' );

		self::assertSame( 0, ( new AccountRewards() )->process_redeem( 7, -1 ) );
		self::assertSame( 300, Redemption::intent_points(), 'El núcleo conserva la intención ante un negativo.' );
	}

	public function test_the_pdp_promise_uses_the_program_math(): void {
		Functions\when( 'wc_get_product' )->alias(
			static function () {
				$p = new class() extends \WC_Product {
					public function is_purchasable(): bool {
						return true;
					}
				};
				return $p;
			}
		);
		Functions\when( 'wc_get_price_including_tax' )->justReturn( 11900.0 );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_kses_post' )->returnArg();

		ob_start();
		( new PdpNotice() )->render();
		$html = ob_get_clean();

		// 11.900 × 2/100 = 238: la promesa ES la matemática del programa.
		$this->assertStringContainsString( '238', $html );
		$this->assertStringContainsString( 'Puntos Akibara', $html );
		// Y lo que valen, con el cambio del panel: el número suelto no engancha.
		$this->assertStringContainsString( '238 Puntos Akibara = $238', $html );
	}

	public function test_the_pdp_guest_variant_sells_the_welcome_bonus(): void {
		Functions\when( 'wc_get_product' )->alias(
			static function () {
				return new class() extends \WC_Product {
					public function is_purchasable(): bool {
						return true;
					}
				};
			}
		);
		Functions\when( 'wc_get_price_including_tax' )->justReturn( 10000.0 );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_kses_post' )->returnArg();

		ob_start();
		( new PdpNotice() )->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'creando tu cuenta', $html );
		$this->assertStringContainsString( '500', $html, 'El bono de bienvenida es el gancho del invitado.' );
		$this->assertStringContainsString( '200 Puntos Akibara = $200', $html, 'Lo que gana con el tomo, en pesos.' );
		$this->assertStringContainsString( '500 de bienvenida = $500', $html, 'Y el bono, también en pesos.' );
	}

	/**
	 * La equivalencia sale del panel: si el dueño pone el punto a $2, la ficha
	 * lo dice sola. Escrita a mano, mentiría al primer cambio.
	 *
	 * @testdox La equivalencia de la ficha sigue al valor del punto.
	 */
	public function test_la_ficha_sigue_el_valor_del_punto(): void {
		Functions\when( 'wc_get_product' )->alias(
			static function () {
				return new class() extends \WC_Product {
					public function is_purchasable(): bool {
						return true;
					}
				};
			}
		);
		Functions\when( 'wc_get_price_including_tax' )->justReturn( 10000.0 );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_redeem_value' === $option ? 2 : $default_value
		);

		ob_start();
		( new PdpNotice() )->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( '200 Puntos Akibara = $400', $html );
	}

	public function test_the_pdp_never_promises_a_welcome_bonus_the_owner_turned_off(): void {
		Functions\when( 'wc_get_product' )->alias(
			static function () {
				return new class() extends \WC_Product {
					public function is_purchasable(): bool {
						return true;
					}
				};
			}
		);
		Functions\when( 'wc_get_price_including_tax' )->justReturn( 10000.0 );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_welcome_enabled' === $option ? 'no' : $default_value
		);

		ob_start();
		( new PdpNotice() )->render();
		$html = ob_get_clean();

		// Con el carril apagado, la ficha ofrecía «más 0 de bienvenida».
		$this->assertStringNotContainsString( 'bienvenida', $html );
		$this->assertStringNotContainsString( 'creando tu cuenta', $html );
		$this->assertStringContainsString( '200', $html, 'Los puntos de la compra siguen prometiéndose.' );
	}

	public function test_the_program_page_link_is_resolved_once_per_request(): void {
		$resoluciones = 0;
		Functions\when( 'get_page_by_path' )->alias(
			static function ( string $slug ) use ( &$resoluciones ) {
				++$resoluciones;
				$page     = new \WP_Post( 27519 );
				$page->ID = 27519;
				return 'akibara-puntos' === $slug ? $page : null;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/akibara-puntos/' );

		$this->assertSame( 'https://akibara.cl/akibara-puntos/', ProgramPage::url() );
		$this->assertSame( 'https://akibara.cl/akibara-puntos/', ProgramPage::url() );
		ProgramPage::page();

		// La ficha, Mi cuenta y el correo resolvían el slug cada uno por su
		// cuenta, y Mi cuenta dos veces en la misma pantalla.
		$this->assertSame( 1, $resoluciones, 'Una sola resolución por petición.' );
	}

	public function test_waking_the_review_engine_forces_one_more_rewrite_flush(): void {
		$sellado = '';
		$motor   = false; // CusRev vivo: /resena/{token} no registra sus reglas.
		$flushes = 0;

		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) use ( &$sellado ) {
				return AccountRewards::FLUSH_OPTION === $option ? $sellado : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $option, $value, $autoload = null ) use ( &$sellado ): bool {
				unset( $autoload );
				if ( AccountRewards::FLUSH_OPTION === $option ) {
					$sellado = (string) $value;
				}
				return true;
			}
		);
		Functions\when( 'flush_rewrite_rules' )->alias(
			static function () use ( &$flushes ): void {
				++$flushes;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value = null ) use ( &$motor ) {
				return 'akb_rewards_reviews_engine_on' === $hook ? $motor : $value;
			}
		);

		$account = new AccountRewards();

		$account->maybe_flush_rewrites();
		$account->maybe_flush_rewrites();
		$this->assertSame( 1, $flushes, 'Un solo flush por firma de reglas.' );

		// Cutover: se desactiva CusRev y el motor propio despierta con sus
		// rutas. Sin re-flush, todos los /resena/{token} ya enviados por
		// correo caían en 404 — el candado los sellaba fuera.
		$motor = true;

		$account->maybe_flush_rewrites();
		$this->assertSame( 2, $flushes, 'Despertar el motor de reseñas persiste sus reglas.' );

		$account->maybe_flush_rewrites();
		$this->assertSame( 2, $flushes, 'Y no vuelve a flushear en cada petición.' );
	}

	/**
	 * Medido en el espejo el 2026-09-13: otro flush guardó las reglas sin el
	 * endpoint y /mi-cuenta/rewards/ quedó en 404; el candado de firma no lo
	 * veía y no volvía a flushear nunca.
	 *
	 * @testdox Si otro flush borró las reglas de Puntos Akibara, se reparan solas, una vez por hora.
	 */
	public function test_lost_rewrite_rules_heal_themselves_once_per_hour(): void {
		$reglas    = array( 'shop/?$' => 'index.php?post_type=product' ); // Sin «rewards».
		$flushes   = 0;
		$transient = false;

		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) use ( &$reglas ) {
				if ( 'rewrite_rules' === $option ) {
					return $reglas;
				}
				return AccountRewards::FLUSH_OPTION === $option ? '2' : $default_value;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value = null ) => 'akb_rewards_reviews_engine_on' === $hook ? false : $value
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_transient' )->alias( static function () use ( &$transient ) { return $transient; } );
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$transient ): bool {
				unset( $key );
				$transient = $value;
				return true;
			}
		);
		Functions\when( 'flush_rewrite_rules' )->alias(
			static function () use ( &$flushes ): void {
				++$flushes;
			}
		);

		$account = new AccountRewards();
		$account->maybe_flush_rewrites();
		$this->assertSame( 1, $flushes, 'Firma sellada, pero las reglas no están: se reparan.' );

		$account->maybe_flush_rewrites();
		$this->assertSame( 1, $flushes, 'Si otro plugin las vuelve a borrar en cada petición, no se flushea en bucle.' );

		$reglas[ AccountRewards::ENDPOINT . '(/(.*))?/?$' ]          = 'index.php?&rewards=$matches[2]';
		$reglas[ '(.?.+?)/' . AccountRewards::ENDPOINT . '(/(.*))?/?$' ] = 'index.php?pagename=$matches[1]&rewards=$matches[3]';
		$transient = false;
		$account->maybe_flush_rewrites();
		$this->assertSame( 1, $flushes, 'Con las reglas en su sitio no pasa nada.' );
	}

	public function test_the_nudge_only_rides_the_customer_completed_email(): void {
		$nudge = new ReviewNudge();
		$order = $this->make_order( 100, 7, 10000.0, 1900.0 );

		// Al admin: nada.
		ob_start();
		$nudge->render( $order, true, false, (object) array( 'id' => 'customer_completed_order' ) );
		$this->assertSame( '', ob_get_clean() );

		// Otro correo: nada.
		ob_start();
		$nudge->render( $order, false, false, (object) array( 'id' => 'customer_processing_order' ) );
		$this->assertSame( '', ob_get_clean() );

		// El correo de entrega del cliente: el empujón con el monto del dueño.
		ob_start();
		$nudge->render( $order, false, false, (object) array( 'id' => 'customer_completed_order' ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( '500', $html );
		$this->assertStringContainsString( 'reseña con foto', $html );

		// Y la variante de texto plano existe.
		ob_start();
		$nudge->render( $order, false, true, (object) array( 'id' => 'customer_completed_order' ) );
		$plain = ob_get_clean();
		$this->assertStringContainsString( '500 Puntos Akibara', $plain );
		$this->assertStringNotContainsString( '<p', $plain );
	}

	/**
	 * @testdox Con menos puntos que el mínimo, Mi cuenta lo EXPLICA en vez de dar un formulario imposible.
	 *
	 * Hallazgo S-b2c8ef0e: el bloque sólo exigía saldo > 0, así que con 50
	 * puntos y un mínimo de 100 salía un campo `min="100" max="50"`. El
	 * navegador bloqueaba el envío con un mensaje genérico del sistema y la
	 * pantalla no decía en ninguna parte que faltaban puntos.
	 */
	public function test_bajo_el_minimo_se_explica_y_no_hay_formulario_imposible(): void {
		// Los resultados de saldo, carrito y cap se cubren en RedemptionLimitsTest.
		$method = new \ReflectionMethod( Redemption::class, 'unavailable_message' );
		$message = $method->invoke( null, array( 'cart_present' => true, 'base' => 50000.0, 'missing' => 0, 'available' => 50, 'min_points' => 100, 'max_points' => 0, 'value' => 1 ) );
		self::assertSame( 'Te faltan 50 puntos para poder canjear: el mínimo por canje es 100.', $message );
	}

	/**
	 * @testdox El campo de canje nunca nace fuera de su propio rango [min, max].
	 *
	 * Reapertura de S-b2c8ef0e / S-80f7d8ac: el cierre anterior sólo evitó
	 * `min > max`, pero el `value` seguía calculándose con `max( 1, $intent )`.
	 * Sin canje previo (`$intent = 0`, el estado normal al entrar a Mi
	 * cuenta) y el mínimo de fábrica en 100, el campo nacía en `value="1"`
	 * con `min="100"`: inválido de nacimiento, y el clic directo en «Canjear
	 * en mi próxima compra» quedaba bloqueado por el navegador sin
	 * explicación. Un test de grep del fuente no detecta esto porque el
	 * comportamiento depende del RESULTADO del cálculo, no de qué función se
	 * llama.
	 */
	public function test_el_campo_de_canje_nace_con_un_valor_valido(): void {
		$metodo = new \ReflectionMethod( AccountRewards::class, 'valor_inicial_de_canje' );
		$metodo->setAccessible( true );

		$casos = array(
			// [ $disponible, $minimo_canje, $intent, $esperado ]
			array( 500, 100, 0, 100 ),   // Sin canje previo: el defecto original.
			array( 500, 100, 300, 300 ), // Con intención dentro de rango.
			array( 500, 100, 900, 500 ), // Intención por encima del saldo: topa en el máximo.
			array( 120, 100, 0, 100 ),   // Saldo raspando el mínimo.
			array( 500, 1, 0, 1 ),       // Mínimo de fábrica desactivado (Options en 0).
		);

		foreach ( $casos as [ $disponible, $minimo_canje, $intent, $esperado ] ) {
			$valor = $metodo->invoke( null, $disponible, $minimo_canje, $intent );

			self::assertSame(
				$esperado,
				$valor,
				"disponible={$disponible}, minimo={$minimo_canje}, intent={$intent}"
			);
			self::assertGreaterThanOrEqual( $minimo_canje, $valor, 'El value nunca puede nacer bajo el min.' );
			self::assertLessThanOrEqual( $disponible, $valor, 'El value nunca puede nacer sobre el max.' );
		}
	}

	/** @testdox El recordatorio explica la invitación separada sin emitir tokens ni dirigir a un formulario sin foto. */
	public function test_el_empujon_dice_donde_resenar(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => array(
				'akb_rewards_invite_status'     => 'shipping-progress',
				'akb_rewards_invite_delay_days' => 6,
				'akb_rewards_invite_pickup_delay_days' => 2,
				'akb_rewards_nudge_email_ids'   => 'customer_processing_order',
			)[ $key ] ?? $default
		);
		Functions\expect( 'wp_generate_password' )->never();
		$nudge = new ReviewNudge();
		$order = $this->make_order( 100, 7, 10000.0, 1900.0 );
		foreach ( array( false, true ) as $plain ) {
			ob_start();
			$nudge->render( $order, false, $plain, (object) array( 'id' => 'customer_processing_order' ) );
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'correo separado', $html );
			$this->assertStringContainsString( '6 días', $html );
			$this->assertStringContainsString( 'Retiro o entrega directa: 2 días', $html );
			$this->assertStringContainsString( 'La invitación es para reseñar y solicitar puntos', $html );
			$this->assertStringContainsString( 'Listo para enviar', $html );
			$this->assertStringNotContainsString( '#reviews', $html );
			$this->assertStringNotContainsString( 'Deja tu reseña', $html );
			$this->assertStringNotContainsString( 'akb_review_token', $html );
		}
		$this->assertSame( array(), $this->wpdb->inserts, 'Pintar Procesando no emite el formulario ni adelanta la invitación.' );
	}

	/** @testdox Si la invitación ya se envió, el recordatorio identifica el correo existente. */
	public function test_con_invitacion_enviada_se_identifica_el_correo(): void {
		$nudge = new ReviewNudge();
		$order = $this->make_order( 100, 0, 10000.0, 1900.0 );
		$order->update_meta_data( \Akibara\Rewards\Reviews\Invitations::META_SENT, '2026-10-05 10:00:00' );
		ob_start();
		$nudge->render( $order, false, false, (object) array( 'id' => 'customer_completed_order' ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Busca nuestro correo', $html );
		$this->assertStringNotContainsString( 'se programa', $html );
		$this->assertStringNotContainsString( 'view-order', $html );
	}

	/** @testdox Apagar las invitaciones también elimina el aviso de un correo futuro. */
	public function test_no_se_promete_una_invitacion_apagada(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => 'akb_rewards_invite_enabled' === $key ? 'no' : $default
		);
		ob_start();
		( new ReviewNudge() )->render( $this->make_order( 100, 7, 10000.0, 1900.0 ), false, false, (object) array( 'id' => 'customer_completed_order' ) );
		$this->assertSame( '', ob_get_clean() );
	}
}
