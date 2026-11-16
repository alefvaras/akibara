<?php
/**
 * Contrato del shortcode público `[akb_puntos_resumen]` (página Akibara
 * Puntos): todo sale de la configuración viva — la página no puede mentir.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\ProgramPage;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.2.0
 */
final class ProgramPageTest extends RewardsTestCase {

	/**
	 * La página /akibara-puntos/ con el estado dado, para get_page_by_path().
	 *
	 * @param string $estado post_status de la página.
	 * @return void
	 */
	private static function pagina_en( string $estado ): void {
		Functions\when( 'get_page_by_path' )->alias(
			static function ( string $slug ) use ( $estado ) {
				$page              = new \WP_Post( 27519 );
				$page->post_status = $estado;
				return ProgramPage::SLUG === $slug ? $page : null;
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( \WP_Post $page ) => 'publish' === $page->post_status
				? 'https://akibara.cl/akibara-puntos/'
				: 'https://akibara.cl/?page_id=27519'
		);
	}

	/**
	 * Los estados en los que el invitado no puede abrir la página.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function estados_sin_publicar(): array {
		return array(
			'borrador'   => array( 'draft' ),
			'privada'    => array( 'private' ),
			'pendiente'  => array( 'pending' ),
			'programada' => array( 'future' ),
		);
	}

	/**
	 * REV-F-R-rev2-2: get_page_by_path() no mira el estado. Con la página en
	 * borrador o privada, la ficha, Mi cuenta y el correo de puntos seguían
	 * enlazando a ella (a ?page_id=27519 o a /akibara-puntos/), y el invitado
	 * recibía un 404.
	 *
	 * @dataProvider estados_sin_publicar
	 * @testdox Una página del programa sin publicar ($estado) no se enlaza desde ninguna superficie.
	 *
	 * @param string $estado post_status de la página.
	 */
	public function test_una_pagina_sin_publicar_no_se_enlaza( string $estado ): void {
		self::pagina_en( $estado );

		$this->assertNull( ProgramPage::page(), 'Sólo cuenta la página publicada.' );
		$this->assertSame( '', ProgramPage::url(), 'Sin URL, las superficies no pintan el enlace.' );
	}

	/**
	 * La otra mitad: con la página publicada, el enlace sigue saliendo.
	 *
	 * @testdox La página del programa publicada se enlaza.
	 */
	public function test_la_pagina_publicada_se_enlaza(): void {
		self::pagina_en( 'publish' );

		$this->assertInstanceOf( \WP_Post::class, ProgramPage::page() );
		$this->assertSame( 'https://akibara.cl/akibara-puntos/', ProgramPage::url() );
	}

	public function test_renders_every_earning_lane_from_live_options(): void {
		$html = ( new ProgramPage() )->render();

		$this->assertStringContainsString( '2 puntos por cada $100', $html );
		$this->assertStringContainsString( '+500 puntos ($500) de bienvenida', $html );
		$this->assertStringContainsString( '500 puntos por reseña ($500)', $html );
		$this->assertStringContainsString( '500 puntos por invitado ($500)', $html );
		$this->assertStringContainsString( '1.000 puntos cada año ($1.000)', $html );
		$this->assertStringContainsString( 'duran 12 meses', $html );
		$this->assertStringContainsString( '30 días antes', $html );
		$this->assertStringContainsString( 'akb-pts-table', $html, 'Reutiliza las clases de la página.' );
	}

	/**
	 * Los límites que el cliente sólo descubría al toparse con ellos. En
	 * producción los dos topes están puestos (10 padrinos, 5 reseñas premiadas)
	 * y la página no los decía: quien escribía su sexta reseña se enteraba
	 * porque no le llegaron los puntos.
	 *
	 * @testdox Los topes de referidos y reseñas salen en la página, con los números vivos.
	 */
	public function test_los_topes_salen_con_los_numeros_vivos(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) {
				$mapa = array(
					'akb_rewards_referral_max_per_user' => 10,
					'akb_rewards_review_max_per_user'   => 5,
				);
				return $mapa[ $option ] ?? $default_value;
			}
		);

		$html = ( new ProgramPage() )->render();

		$this->assertStringContainsString( 'hasta 10 invitados', $html );
		$this->assertStringContainsString( 'vitalicio por cuenta', $html );
		$this->assertStringContainsString( 'en total', $html );
		$this->assertStringContainsString( 'hasta 5 premiadas cada 30 días', $html );
		$this->assertStringContainsString( 'el enlace recuerda 30 días', $html, 'La memoria del enlace también es un límite.' );
	}

	/**
	 * @testdox Sin topes configurados, la página no inventa un límite.
	 */
	public function test_sin_topes_no_se_inventan_limites(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) {
				$mapa = array(
					'akb_rewards_referral_max_per_user' => 0,
					'akb_rewards_review_max_per_user'   => 0,
				);
				return $mapa[ $option ] ?? $default_value;
			}
		);

		$html = ( new ProgramPage() )->render();

		$this->assertStringContainsString( '500 puntos por invitado', $html );
		$this->assertStringNotContainsString( 'hasta 10 invitados', $html );
		$this->assertStringNotContainsString( 'vitalicio por cuenta', $html );
		$this->assertStringNotContainsString( 'premiadas)', $html );
	}

	/**
	 * La duda que trae todo cliente —y que el dueño preguntó—: si los puntos
	 * que gano ahora me descuentan ahora. No: se acreditan después de pagar.
	 *
	 * @testdox La página dice que los puntos no sirven para la compra que los genera.
	 */
	public function test_dice_que_no_se_usan_en_la_misma_compra(): void {
		$frase = ProgramPage::redeem_sentence();

		$this->assertStringContainsString( 'no se pueden usar en esa misma compra', $frase );
		$this->assertStringNotContainsString(
			'Listo para enviar',
			$frase,
			'El estado que acredita es configurable: nombrarlo aquí caducaría al cambiarlo.'
		);
	}

	/**
	 * La página decía a mano «se acreditan al quedar Listo para enviar». El
	 * dueño movió el disparador a «Procesando» el 2026-09-16 y esa frase —la
	 * superficie más pública del programa— pasó a mentir hasta que alguien la
	 * editara. Ahora sale del panel.
	 *
	 * @testdox La página dice cuándo se acreditan leyendo el estado del panel.
	 */
	public function test_el_cuando_sale_del_panel(): void {
		Functions\when( 'wc_get_order_status_name' )->alias(
			static fn( string $estado ): string => array(
				'processing'        => 'Procesando',
				'shipping-progress' => 'Listo para enviar',
			)[ $estado ] ?? $estado
		);

		$frase = ( new ProgramPage() )->render( array( 'solo' => 'acreditacion' ) );
		$this->assertStringContainsString( 'Procesando', $frase, 'El default de fábrica es el pago confirmado.' );
		$this->assertStringContainsString( 'confirmamos tu pago', $frase );
		$this->assertStringNotContainsString( 'Listo para enviar', $frase );

		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_award_status' === $option ? 'shipping-progress' : $default_value
		);

		$otra = ( new ProgramPage() )->render( array( 'solo' => 'acreditacion' ) );
		$this->assertStringContainsString( 'Listo para enviar', $otra, 'Si el dueño lo cambia, la página cambia con él.' );
		$this->assertSame( 'Listo para enviar', ( new ProgramPage() )->render( array( 'solo' => 'estado_acreditacion' ) ) );
	}

	/** @testdox Con un estado sin explicación propia, la página no inventa una promesa. */
	public function test_un_estado_cualquiera_no_inventa_explicacion(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_award_status' === $option ? 'on-hold' : $default_value
		);
		Functions\when( 'wc_get_order_status_name' )->alias( static fn( string $estado ): string => 'En espera' );

		$frase = ( new ProgramPage() )->render( array( 'solo' => 'acreditacion' ) );

		$this->assertStringContainsString( 'En espera', $frase );
		$this->assertStringNotContainsString( 'pago', $frase );
		// Y tampoco promete el «o posterior»: fuera de los estados pagados, el
		// motor exige ese estado exacto (revisión independiente 2026-09-16).
		$this->assertStringNotContainsString( 'posterior', $frase );
	}

	/** @testdox Con un estado pagado sí se dice que los posteriores también acreditan. */
	public function test_con_estado_pagado_se_promete_el_posterior(): void {
		Functions\when( 'wc_get_order_status_name' )->alias( static fn( string $estado ): string => 'Procesando' );

		$this->assertStringContainsString( 'posterior', ( new ProgramPage() )->render( array( 'solo' => 'acreditacion' ) ) );
	}

	/**
	 * La regla que el dueño quiso explícita el 2026-09-16: lo pagado con puntos
	 * no vuelve a dar puntos. El motor ya lo hacía (`award_base_for_order()`
	 * resta el canje) y la página no lo decía con todas las letras.
	 *
	 * @testdox La página dice que lo pagado con puntos no acumula.
	 */
	public function test_dice_que_el_canje_no_acumula(): void {
		$frase = ProgramPage::redeem_sentence();

		$this->assertStringContainsString( 'lo que pagas con puntos no vuelve a darte puntos', $frase );
	}

	/**
	 * La tira de garantías del home dice «1 punto = $1». Ese peso sale del
	 * panel: escrito a mano, la portada mentiría el día que el punto cambie.
	 *
	 * @testdox La equivalencia del punto sale de la configuración viva.
	 */
	public function test_el_valor_del_punto_sale_de_la_configuracion(): void {
		$this->assertSame( '1 punto = $1', ( new ProgramPage() )->render( array( 'solo' => 'valor_punto' ) ) );

		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_redeem_value' === $option ? 5 : $default_value
		);
		$this->assertSame( '1 punto = $5', ( new ProgramPage() )->render( array( 'solo' => 'valor_punto' ) ) );
	}

	public function test_disabled_lanes_disappear_instead_of_lying(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) {
				$mapa = array(
					'akb_rewards_welcome_bonus'    => 0,
					'akb_rewards_referral_enabled' => 'no',
					'akb_rewards_expiry_months'    => 0,
					'akb_rewards_review_every_n'   => 3,
				);
				return $mapa[ $option ] ?? $default_value;
			}
		);

		$html = ( new ProgramPage() )->render();

		$this->assertStringNotContainsString( 'bienvenida', $html );
		$this->assertStringNotContainsString( 'invitado', $html );
		$this->assertStringContainsString( '500 puntos cada 3 reseñas', $html, 'La frecuencia «cada N» se dice tal cual.' );
		$this->assertStringContainsString( 'no vencen', $html );
	}

	public function test_solo_vencimiento_returns_the_bare_sentence(): void {
		$frase = ( new ProgramPage() )->render( array( 'solo' => 'vencimiento' ) );

		$this->assertStringContainsString( 'duran 12 meses', $frase );
		$this->assertStringNotContainsString( '<', $frase, 'Sin envoltorio: va dentro de un bullet.' );
	}

	/**
	 * Las piezas sueltas de la prosa. Sin ellas el redactor escribe la cifra a
	 * mano y la página miente al primer cambio de configuración: medido en
	 * PRODUCCIÓN el 2026-09-02 (la configuración decía 7 puntos por cada $100
	 * y el titular de la página seguía diciendo 2).
	 */
	public function test_solo_pieces_come_from_live_options(): void {
		Functions\when( 'wp_date' )->alias(
			static fn( string $format, int $timestamp ): string => gmdate( 'j', $timestamp ) . ' de agosto de ' . gmdate( 'Y', $timestamp )
		);

		$page = new ProgramPage();

		$this->assertSame( '2%', $page->render( array( 'solo' => 'tasa' ) ) );
		$this->assertSame( '2 puntos por cada $100', $page->render( array( 'solo' => 'ratio' ) ) );
		$this->assertSame( '500', $page->render( array( 'solo' => 'bienvenida' ) ) );
		$this->assertStringContainsString( 'de 2026', $page->render( array( 'solo' => 'desde' ) ) );

		$tabla = $page->render( array( 'solo' => 'ejemplos' ) );
		$this->assertStringContainsString( '<td>$10.000</td><td>200 puntos</td><td>$200</td>', $tabla );
		$this->assertStringContainsString( '<td>$55.000</td><td>1.100 puntos</td><td>$1.100</td>', $tabla );
		$this->assertStringContainsString( 'akb-pts-table', $tabla, 'Hereda el diseño de la página.' );
	}

	/** @testdox Al cambiar el monto por compra, TODAS las piezas se mueven con él. */
	public function test_solo_pieces_follow_a_configuration_change(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) {
				$mapa = array(
					'akb_rewards_earn_points' => 7,
					'akb_rewards_earn_amount' => 100,
				);
				return $mapa[ $option ] ?? $default_value;
			}
		);

		$page = new ProgramPage();

		$this->assertSame( '7 puntos por cada $100', $page->render( array( 'solo' => 'ratio' ) ) );
		$this->assertSame( '7%', $page->render( array( 'solo' => 'tasa' ) ) );
		$this->assertStringContainsString( '<td>$10.000</td><td>700 puntos</td>', $page->render( array( 'solo' => 'ejemplos' ) ) );
	}

	/**
	 * @testdox render_club_landing() produce la experiencia completa e interactiva y se actualiza ante cambios de configuración
	 */
	public function test_render_club_landing_is_dynamic_and_reflects_configuration_changes(): void {
		Functions\when( 'wc_get_order_status_name' )->alias( static fn( string $estado ): string => 'Procesando' );

		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) {
				$mapa = array(
					'akb_rewards_earn_points'     => 100,
					'akb_rewards_earn_amount'     => 1000,
					'akb_rewards_redeem_value'    => 1,
					'akb_rewards_birthday_points' => 1500,
					'akb_rewards_review_points'   => 750,
					'akb_rewards_welcome_bonus'   => 2000,
					'akb_rewards_referral_points' => 800,
					'akb_rewards_tiers_enabled'   => 'yes',
				);
				return $mapa[ $option ] ?? $default_value;
			}
		);

		$page = new ProgramPage();
		$html = $page->render( array( 'solo' => 'club' ) );

		// 1. Hero y Badges
		$this->assertStringContainsString( 'Club de Puntos Akibara', $html );
		$this->assertStringContainsString( '1 punto = $1', $html );
		$this->assertStringContainsString( 'Envío Gratis protegido sobre $60.000', $html );

		// 2. Calculadora Interactiva con atributos de datos
		$this->assertStringContainsString( 'id="akb-club-calc"', $html );
		$this->assertStringContainsString( 'data-earn-amount="1000"', $html );
		$this->assertStringContainsString( 'data-earn-points="100"', $html );
		$this->assertStringContainsString( 'data-redeem-value="1"', $html );
		$this->assertStringContainsString( '¿Cuánto ganas en tu próxima compra?', $html );

		// 3. Formas de ganar dinámicas reflejando los valores vivos
		$this->assertStringContainsString( '100 puntos por cada $1.000', $html );
		$this->assertStringContainsString( '+1.500 pts ($1.500 CLP)', $html, 'Cumpleaños dinámico' );
		$this->assertStringContainsString( '+750 pts ($750 CLP)', $html, 'Reseñas dinámicas' );
		$this->assertStringContainsString( '+2.000 pts ($2.000 CLP)', $html, 'Bienvenida dinámica' );
		$this->assertStringContainsString( '+800 pts ($800 CLP)', $html, 'Referidos dinámicos' );

		// 4. Niveles y FAQ
		$this->assertStringContainsString( 'Niveles de Coleccionista', $html );
		$this->assertStringContainsString( 'Lector', $html );
		$this->assertStringContainsString( 'Coleccionista', $html );
		$this->assertStringContainsString( 'Residente de Akiba', $html );
		$this->assertStringContainsString( 'Lo que debes saber', $html );
		$this->assertStringContainsString( '¿Pierdo mi Envío Gratis si canjeo puntos?', $html );
	}

	/** @testdox La landing del club respeta la fecha futura del programa y documenta vigencia y Soft-Landing dinámicamente. */
	public function test_club_landing_respects_future_program_gate_zero_rate_and_renders_validity_faq(): void {
		Functions\when( 'wc_get_order_status_name' )->justReturn( 'Procesando' );
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default = false ) => match ( $option ) {
				'akb_rewards_program_start'      => '2099-01-01 00:00:00',
				'akb_rewards_earn_points'        => 2,
				'akb_rewards_earn_amount'        => 100,
				'akb_rewards_welcome_bonus'      => 500,
				'akb_rewards_referral_points'    => 1000,
				'akb_rewards_tier_validity_days' => 365,
				'akb_rewards_tiers_enabled'      => 'yes',
				default                          => $default,
			}
		);

		$page = new ProgramPage();
		$html = $page->render( array( 'solo' => 'club' ) );

		$this->assertStringContainsString( 'data-earn-points="0"', $html, 'Con inicio futuro Promise::rate() entrega 0 puntos.' );
		$this->assertStringContainsString( '365 días', $html );
		$this->assertStringContainsString( '1 año estándar', $html );
		$this->assertStringContainsString( 'Soft-Landing', $html );
		$this->assertStringNotContainsString( 'Bono de bienvenida', $html, 'Con programa en el futuro no promete bono de bienvenida.' );
		$this->assertStringNotContainsString( 'Invita a tus amigos', $html, 'Con programa en el futuro no promete referidos.' );
		$this->assertStringNotContainsString( 'Por cada compra', $html, 'Sin acumulación activa no muestra tarjeta de compra.' );
	}
}
