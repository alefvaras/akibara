<?php
/**
 * Las superficies de reseñas explican las condiciones del premio.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\RewardMessage;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class RewardMessageTest extends RewardsTestCase {

	/** @testdox La promesa explica foto, aprobación, compra, cuenta y producto único. */
	public function test_default_requirements_are_explicit(): void {
		$text = RewardMessage::text();
		$this->assertStringContainsString( '500 Puntos Akibara', $text );
		$this->assertStringContainsString( '= $500 CLP de descuento', $text );
		$this->assertStringContainsString( 'debes adjuntar una foto real', $text );
		$this->assertStringNotContainsString( 'Reseña voluntaria', $text );
		$this->assertStringContainsString( 'reseña con foto aprobada que cumpla los requisitos', $text );
		$this->assertStringContainsString( 'Un premio por producto y cliente', $text );
		$this->assertStringContainsString( 'compra debe estar verificada', $text );
		$this->assertStringContainsString( 'premio queda pendiente', $text );
		$this->assertStringContainsString( 'verifiques el correo', $text );
	}

	/** @testdox La cadencia y el límite cambian el mensaje sin cambiar la política. */
	public function test_cadence_and_cap_are_visible(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => array(
				'akb_rewards_review_every_n'          => 3,
				'akb_rewards_review_max_per_user'     => 5,
				'akb_rewards_review_require_verified' => 'no',
			)[ $key ] ?? $default
		);
		$text = RewardMessage::text();
		$this->assertStringContainsString( 'por cada 3 reseñas', $text );
		$this->assertStringContainsString( 'intermedias suman avance', $text );
		$this->assertStringContainsString( 'Tope de 5 premios', $text );
		$this->assertStringNotContainsString( 'compra debe estar verificada', $text );
	}

	/** @testdox El carril apagado no promete puntos. */
	public function test_disabled_lane_is_silent(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => 'akb_rewards_reviews_enabled' === $key ? 'no' : $default
		);
		$this->assertSame( '', RewardMessage::text() );
	}

	/** @testdox La oferta y la equivalencia siguen la configuración y aceptan puntos ya acreditados. */
	public function test_amount_label_uses_configured_value_and_actual_points(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => array(
				'akb_rewards_review_points' => 240,
				'akb_rewards_redeem_value'  => 3,
			)[ $key ] ?? $default
		);
		$this->assertSame( '240 Puntos Akibara = $720 CLP de descuento', RewardMessage::amount_label() );
		$this->assertSame( '125 Puntos Akibara = $375 CLP de descuento', RewardMessage::amount_label( 125 ) );
		$this->assertStringContainsString( '240 Puntos Akibara = $720 CLP de descuento', RewardMessage::text( false ) );
		$this->assertStringNotContainsString( 'Reseña voluntaria', RewardMessage::text( false ) );
	}
}
