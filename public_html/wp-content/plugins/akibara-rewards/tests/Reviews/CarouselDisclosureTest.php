<?php
/** @package Akibara\Rewards\Tests */
declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\AdminReviews;
use Akibara\Rewards\Reviews\Carousel;
use Akibara\Rewards\Reviews\Disclosure;
use Akibara\Rewards\Reviews\ReviewPoints;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class CarouselDisclosureTest extends RewardsTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_the_title' )->justReturn( 'Tomo comprado' );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.test/tomo/' );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'wp_script_is' )->justReturn( false );
	}

	/** @testdox El aviso de puntos del home queda apagado por defecto según la orden del dueño. */
	public function test_home_disclosure_defaults_to_disabled(): void {
		self::assertFalse( Options::carousel_disclosure_on() );
	}

	/** @testdox El aviso de puntos está completamente erradicado del home y de la ficha. */
	public function test_home_toggle_preserves_pdp_and_transcription_on_warm_cache(): void {
		$cards = array( array(
			'author' => 'Lector', 'text' => 'Opinión real sobre el tomo.', 'rating' => 4, 'title' => '',
			'photo_id' => 0, 'photo_url' => '', 'cover_id' => 0, 'product' => 777, 'comment' => 20,
			'aviso' => '', 'origen' => AdminReviews::TEXTO_TRANSCRITA,
		) );
		Functions\when( 'get_transient' )->justReturn( array( 'eligible' => 1, 'cards' => $cards ) );
		Functions\expect( 'set_transient' )->never();
		$this->wpdb->comment_meta[20][ ReviewPoints::META_POINTS_PAID ] = array( 500 );
		$comment = new \WP_Comment( array( 'comment_ID' => 20, 'comment_post_ID' => 777 ) );
		Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => 'akb_rewards_carousel_min' === $key ? 1 : $default );

		$html = ( new Carousel() )->render();
		self::assertStringNotContainsString( Disclosure::TEXTO_PREMIADA, $html );
		self::assertStringContainsString( AdminReviews::TEXTO_TRANSCRITA, $html, 'El origen no es el aviso de puntos.' );
		self::assertStringContainsString( 'Opinión real sobre el tomo.', $html, 'El control no elimina tarjetas.' );
		self::assertSame( 'Texto en la ficha.', ( new Disclosure() )->append_notice( 'Texto en la ficha.', $comment ), 'La ficha no añade aviso.' );
		self::assertFalse( Options::disclosure_on(), 'disclosure_on es false siempre.' );
		self::assertFalse( Options::carousel_disclosure_on(), 'carousel_disclosure_on es false siempre.' );
		self::assertSame( array(), $this->wpdb->inserts, 'Cambiar presentación no acredita puntos.' );
	}

	/** @testdox OFF oculta también el aviso de avance hacia un premio, conservando la transcripción. */
	public function test_home_off_suppresses_progress_notice_only(): void {
		Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => 'akb_rewards_carousel_disclosure_enabled' === $key ? 'no' : $default );
		$markup = new \ReflectionMethod( Carousel::class, 'markup' );
		$markup->setAccessible( true );
		$html = $markup->invoke( new Carousel(), array( array(
			'author' => 'Lector', 'text' => 'Opinión sobre la edición.', 'rating' => 3, 'title' => '',
			'photo_id' => 0, 'photo_url' => '', 'cover_id' => 0, 'product' => 777, 'comment' => 21,
			'aviso' => Disclosure::TEXTO_SUMA, 'origen' => AdminReviews::TEXTO_TRANSCRITA,
		) ) );
		self::assertStringNotContainsString( Disclosure::TEXTO_SUMA, $html );
		self::assertStringContainsString( AdminReviews::TEXTO_TRANSCRITA, $html );
	}
}
