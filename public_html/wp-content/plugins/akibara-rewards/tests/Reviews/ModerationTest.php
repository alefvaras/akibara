<?php
/**
 * Contrato de la decisión A del dueño: NADA se publica ni premia sin su
 * aceptación.
 *
 * En producción `comment_previously_approved = 1` auto-aprueba a quien ya
 * tiene un comentario aprobado; este filtro corre después y lo revierte para
 * las reseñas. Era la única pieza de la decisión A sin prueba unitaria.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Moderation;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.3.1
 */
final class ModerationTest extends RewardsTestCase {

	private Moderation $moderation;

	protected function setUp(): void {
		parent::setUp();

		$this->moderation = new Moderation();

		// Todo lo que se reseña acá es un producto salvo que el test diga otra cosa.
		Functions\when( 'get_post_type' )->alias(
			static fn( int $id ): string => 777 === $id ? 'product' : 'post'
		);
	}

	/**
	 * Datos de comentario con los defaults del formulario de reseña.
	 *
	 * @param array<string,mixed> $overrides Cambios.
	 * @return array<string,mixed>
	 */
	private function commentdata( array $overrides = array() ): array {
		return array_merge(
			array(
				'comment_post_ID' => 777,
				'comment_type'    => 'review',
				'comment_parent'  => 0,
			),
			$overrides
		);
	}

	public function test_an_auto_approved_product_review_is_held_anyway(): void {
		// 1 = WordPress ya la había aprobado (comment_previously_approved).
		$this->assertSame( 0, $this->moderation->hold_product_reviews( 1, $this->commentdata() ) );
	}

	public function test_rest_and_xmlrpc_shapes_do_not_escape_the_hold(): void {
		// Woo sólo re-etiqueta como 'review' el POST del front: por REST el
		// tipo llega como '' o 'comment' y esquivaba la retención.
		$this->assertSame( 0, $this->moderation->hold_product_reviews( 1, $this->commentdata( array( 'comment_type' => '' ) ) ) );
		$this->assertSame( 0, $this->moderation->hold_product_reviews( 1, $this->commentdata( array( 'comment_type' => 'comment' ) ) ) );
	}

	public function test_spam_is_never_rescued_and_errors_are_never_overwritten(): void {
		$this->assertSame( 'spam', $this->moderation->hold_product_reviews( 'spam', $this->commentdata() ) );

		$error = new \WP_Error( 'bloqueado', 'No.' );
		$this->assertSame( $error, $this->moderation->hold_product_reviews( $error, $this->commentdata() ) );
	}

	public function test_thread_replies_pingbacks_and_blog_comments_pass_through(): void {
		$this->assertSame(
			1,
			$this->moderation->hold_product_reviews( 1, $this->commentdata( array( 'comment_parent' => 5 ) ) ),
			'Una respuesta del hilo no es una reseña.'
		);
		$this->assertSame(
			1,
			$this->moderation->hold_product_reviews( 1, $this->commentdata( array( 'comment_type' => 'pingback' ) ) )
		);
		$this->assertSame(
			1,
			$this->moderation->hold_product_reviews( 1, $this->commentdata( array( 'comment_post_ID' => 42 ) ) ),
			'Un comentario del blog no pasa por la moderación de reseñas.'
		);
	}

	public function test_the_owner_can_switch_the_mandatory_moderation_off(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_moderate_reviews' === $option ? 'no' : $default_value
		);

		$this->assertSame( 1, $this->moderation->hold_product_reviews( 1, $this->commentdata() ), 'Apagada, manda el veredicto de WordPress.' );
	}

	public function test_the_default_is_on_even_without_the_option_saved(): void {
		// Instalación recién estrenada: la option no existe todavía y la
		// decisión A tiene que regir igual.
		$this->assertSame( 0, $this->moderation->hold_product_reviews( 1, $this->commentdata() ) );
	}
}
