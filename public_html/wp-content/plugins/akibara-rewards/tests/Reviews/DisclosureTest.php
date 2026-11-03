<?php
/**
 * Una reseña premiada se lee sabiendo que fue premiada.
 *
 * Hallazgo S-66cc662a: el programa paga Puntos Akibara por publicar una reseña
 * con foto —un incentivo que Google y la FTC piden divulgar donde se lee la
 * reseña— y el propio código daba esa divulgación por hecha («la divulgación
 * del incentivo va en el listado del PDP», `Carousel`) sin que existiera en
 * ninguna superficie.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Disclosure;
use Akibara\Rewards\Reviews\ReviewPoints;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.4.1
 */
final class DisclosureTest extends RewardsTestCase {

	private Disclosure $disclosure;

	private const AVISO = 'Quien escribió esta reseña ganó Puntos Akibara por publicarla con foto.';

	protected function setUp(): void {
		parent::setUp();

		$this->disclosure = new Disclosure();
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		$this->con_aviso( true );
	}

	/**
	 * El aviso es un interruptor del panel; el dueño lo dejó APAGADO el
	 * 2026-09-13 (con las reseñas premiadas viajando igual a Google, sabiendo
	 * el riesgo). Las pruebas que miden el texto lo encienden.
	 *
	 * @param bool $encendido Estado del interruptor.
	 */
	private function con_aviso( bool $encendido ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_disclosure_enabled' === $option
				? ( $encendido ? 'yes' : 'no' )
				: $default_value
		);
	}

	/** @testdox De fábrica el aviso NO se pinta: es decisión del dueño, y el criterio sigue clasificando. */
	public function test_the_notice_is_off_until_the_owner_turns_it_on(): void {
		$this->con_aviso( false );
		$comentario = $this->resena( 20, 500 );

		$this->assertSame( 'Texto.', $this->disclosure->append_notice( 'Texto.', $comentario ) );
		$this->assertSame( Disclosure::TEXTO_PREMIADA, Disclosure::notice_for( 20 ), 'El criterio de «fue incentivada» no depende del aviso: lo usan las exclusiones del panel.' );
		$this->assertSame( '', Disclosure::visible_notice_for( 20 ) );
	}

	private function resena( int $id, $puntos = null ): \WP_Comment {
		if ( null !== $puntos ) {
			$this->wpdb->comment_meta[ $id ][ ReviewPoints::META_POINTS_PAID ] = array( $puntos );
		}

		return new \WP_Comment(
			array(
				'comment_ID'      => $id,
				'comment_post_ID' => 777,
				'comment_content' => 'Llegó impecable.',
			)
		);
	}

	/** @testdox La reseña que cobró puntos lleva el aviso. */
	public function test_a_rewarded_review_says_so(): void {
		$salida = $this->disclosure->append_notice( '<p>Llegó impecable.</p>', $this->resena( 10, 500 ) );

		$this->assertStringContainsString( self::AVISO, $salida );
		$this->assertStringStartsWith( '<p>Llegó impecable.</p>', $salida, 'El aviso va después del texto, no lo reemplaza.' );
	}

	/** @testdox La reseña que no cobró puntos no dice nada. */
	public function test_an_unrewarded_review_is_left_alone(): void {
		$this->assertSame( '<p>Texto.</p>', $this->disclosure->append_notice( '<p>Texto.</p>', $this->resena( 11 ) ) );
	}

	/**
	 * `pending_account` es el sello de quien reseñó sin cuenta: los puntos
	 * quedaron esperando y todavía NO se pagaron. Decir que ganó puntos ahí
	 * sería el mismo tipo de mentira que el aviso viene a evitar.
	 *
	 * @testdox Con los puntos aún en espera, tampoco.
	 */
	public function test_a_pending_seal_is_not_a_reward_yet(): void {
		$comentario = $this->resena( 12, ReviewPoints::SEAL_PENDING_USER );

		$this->assertSame( '', Disclosure::notice_for( 12 ) );
		$this->assertSame( 'Texto.', $this->disclosure->append_notice( 'Texto.', $comentario ) );
	}

	/** @testdox Un premio de 0 puntos (rechazado) no es un premio. */
	public function test_a_zero_seal_is_not_a_reward(): void {
		$this->assertSame( 'Texto.', $this->disclosure->append_notice( 'Texto.', $this->resena( 13, 0 ) ) );
	}

	/**
	 * Con «premiar cada N reseñas», la intermedia no cobra pero cuenta para el
	 * premio: también es una reseña incentivada, y decir «ganó» sería falso.
	 *
	 * @testdox La reseña que sumó para un premio sin cobrar lo dice con sus palabras.
	 */
	public function test_a_review_that_counted_toward_a_reward_says_so(): void {
		$comentario = $this->resena( 15, 0 );
		$this->wpdb->comment_meta[15][ ReviewPoints::META_COUNTED ] = array( 2 );

		$salida = $this->disclosure->append_notice( 'Texto.', $comentario );

		$this->assertStringContainsString( Disclosure::TEXTO_SUMA, $salida );
		$this->assertStringNotContainsString( 'ganó', $salida );
	}

	/** @testdox Un comentario de blog premiado no se toca: el aviso es de la ficha. */
	public function test_a_post_comment_never_gets_the_notice(): void {
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		$this->assertSame( 'Texto.', $this->disclosure->append_notice( 'Texto.', $this->resena( 14, 500 ) ) );
	}

	/**
	 * `comment_text` se aplica también desde sitios que no pasan el
	 * comentario (plugins viejos, `apply_filters` a mano). Sin el guard, ahí
	 * reventaba.
	 *
	 * @testdox Sin comentario en el filtro, devuelve el texto tal cual.
	 */
	public function test_without_a_comment_the_text_passes_through(): void {
		$this->assertSame( 'Texto.', $this->disclosure->append_notice( 'Texto.' ) );
		$this->assertSame( '', $this->disclosure->append_notice( null ) );
	}

	/** @testdox El aviso se engancha entre la insignia de transcrita y la foto. */
	public function test_the_notice_sits_between_the_badge_and_the_photo(): void {
		$prioridades = array();
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $cb, int $prioridad = 10 ) use ( &$prioridades ): bool {
				$prioridades[ $hook ] = $prioridad;
				return true;
			}
		);

		$this->disclosure->register();

		// 20 = insignia de reseña transcrita, 26 = foto (PhotoSurfaces).
		$this->assertGreaterThan( 20, $prioridades['comment_text'] );
		$this->assertLessThan( 26, $prioridades['comment_text'] );
	}
}
