<?php
/**
 * Una reseña que escribió la tienda lo dice DONDE SE LEA.
 *
 * `AdminReviews` declara la regla en su propio código —«FTC exige divulgación
 * que no dependa de un clic»— y la cumplía sólo en la ficha, porque el aviso
 * viajaba en el filtro `comment_text`. El carrusel de la portada y el JSON-LD
 * que ve Google construyen su texto por su cuenta y no pasan por ese filtro:
 * ahí la reseña transcrita aparecía como cualquier otra (hallazgo S-97bcade9,
 * 2026-09-18).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\AdminReviews;
use Akibara\Rewards\Tests\Support\RewardsTestCase;

/**
 * @since 0.4.9
 */
final class LaTranscripcionSeDivulgaEnTodasLasSuperficiesTest extends RewardsTestCase {

	/** @testdox El aviso de transcripción tiene una sola autoridad. */
	public function test_the_notice_has_a_single_author(): void {
		$this->wpdb->comment_meta[10][ AdminReviews::META_ADMIN_CREATED ] = array( 1 );

		self::assertSame( AdminReviews::TEXTO_TRANSCRITA, AdminReviews::transcription_notice( 10 ) );
		self::assertSame( '', AdminReviews::transcription_notice( 11 ), 'Una reseña escrita por su autor no lleva aviso.' );

		// Nadie más escribe esa frase a mano.
		foreach ( array( 'Carousel', 'Schema' ) as $clase ) {
			$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Reviews/' . $clase . '.php' );

			self::assertStringContainsString( 'AdminReviews::transcription_notice(', $fuente, $clase . ' pide la frase, no la copia.' );
			self::assertStringNotContainsString( 'publicada por Akibara a nombre', $fuente, 'Dos copias de una divulgación divergen.' );
		}
	}

	/** @testdox El aviso NO depende del interruptor del incentivo. */
	public function test_the_notice_is_never_switched_off(): void {
		$this->wpdb->comment_meta[10][ AdminReviews::META_ADMIN_CREATED ] = array( 1 );

		// Con la divulgación de incentivo apagada —que es como el dueño la
		// dejó el 2026-09-13— la transcripción se sigue diciendo: son dos
		// hechos distintos y sólo uno es opcional.
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_disclosure_enabled' === $option ? 'no' : $default_value
		);

		self::assertSame( AdminReviews::TEXTO_TRANSCRITA, AdminReviews::transcription_notice( 10 ) );
	}

	/** @testdox La insignia de la ficha usa la misma frase que las demás superficies. */
	public function test_the_product_page_badge_uses_the_same_sentence(): void {
		$this->wpdb->comment_meta[10][ AdminReviews::META_ADMIN_CREATED ] = array( 1 );

		$comment                = new \WP_Comment();
		$comment->comment_ID    = 10;

		$html = ( new AdminReviews() )->transcription_badge( 'Edición impecable.', $comment );

		self::assertStringContainsString( 'Edición impecable.', $html );
		self::assertStringContainsString( AdminReviews::TEXTO_TRANSCRITA, $html );
	}
}
