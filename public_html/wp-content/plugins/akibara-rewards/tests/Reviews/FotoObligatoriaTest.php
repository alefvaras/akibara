<?php
/**
 * Comprobaciones de presencia y mensajes de la foto de reseña.
 * La foto se exige sólo al solicitar puntos desde el 2026-10-05.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\ReviewForm;
use Akibara\Rewards\Tests\Support\RewardsTestCase;

final class FotoObligatoriaTest extends RewardsTestCase {

	/**
	 * El candado mide CÓDIGO, no prosa.
	 *
	 * Sin esto, el comentario que explica por qué NO se dice «campo requerido»
	 * hace fallar la prueba que lo prohíbe. Es la quinta vez esta noche que un
	 * docblock mío tumba su propio test: el patrón está identificado y esta es
	 * la forma de neutralizarlo.
	 */
	private static function sin_comentarios( string $codigo ): string {
		$limpio = array();
		foreach ( explode( "\n", $codigo ) as $linea ) {
			$sin_espacios = ltrim( $linea );
			if ( str_starts_with( $sin_espacios, '//' ) || str_starts_with( $sin_espacios, '*' )
				|| str_starts_with( $sin_espacios, '/*' ) ) {
				continue;
			}
			$limpio[] = $linea;
		}

		return implode( "\n", $limpio );
	}

	/**
	 * @testdox La detección diferencia una foto adjunta de un campo vacío.
	 */
	public function test_an_empty_upload_is_not_a_photo(): void {
		$metodo = new \ReflectionMethod( ReviewForm::class, 'photo_is_present' );
		$metodo->setAccessible( true );

		self::assertFalse(
			$metodo->invoke( null, array() ),
			'Sin ningún fichero, no hay foto.'
		);
		self::assertFalse(
			$metodo->invoke( null, array( 'akb_rf_photo' => array( 'name' => '' ) ) ),
			'Un campo de fichero vacío es lo que llega cuando el cliente no adjunta nada.'
		);
		self::assertFalse(
			$metodo->invoke( null, array( 'otro_campo' => array( 'name' => 'x.jpg' ) ) ),
			'Un fichero con otro nombre de campo no es la foto de la reseña.'
		);
	}

	/**
	 * @testdox Con foto adjunta, la comprobación de presencia pasa.
	 */
	public function test_a_review_with_a_photo_passes_the_check(): void {
		$metodo = new \ReflectionMethod( ReviewForm::class, 'photo_is_present' );
		$metodo->setAccessible( true );

		self::assertTrue(
			$metodo->invoke( null, array( 'akb_rf_photo' => array( 'name' => 'mi-tomo.jpg' ) ) )
		);
	}

	/**
	 * El mensaje lo lee alguien que ya escribió su reseña y está a un clic de
	 * enviarla. Tiene que decir qué falta, y de paso por qué se pide.
	 *
	 * @testdox El mensaje de la foto que falta explica qué hacer, no dice «campo requerido».
	 */
	public function test_the_error_message_explains_itself(): void {
		$fuente = self::sin_comentarios(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Reviews/ReviewForm.php' )
		);

		self::assertMatchesRegularExpression(
			'/foto de (tu|la) compra/i',
			$fuente,
			'El mensaje tiene que nombrar la foto en los términos del cliente.'
		);
		self::assertStringNotContainsString(
			'campo requerido',
			$fuente,
			'«Campo requerido» no le dice a nadie qué hacer.'
		);
	}

	/** @testdox La ficha explica que su reseña siempre es voluntaria, incluso con foto, y conserva avisos existentes. */
	public function test_native_form_explains_voluntary_optional_photo(): void {
		$form = new ReviewForm();
		$args = $form->native_form_notice( array( 'comment_field' => '<textarea name="comment"></textarea>', 'comment_notes_before' => '<p>Aviso existente</p>' ) );
		self::assertSame( '<p>Aviso existente</p>', $args['comment_notes_before'] );
		self::assertStringContainsString( '<textarea name="comment"></textarea>', $args['comment_field'] );
		self::assertStringContainsString( 'puedes añadir una foto y no solicita puntos', $args['comment_field'] );
		self::assertStringContainsString( 'enlace de reseña de tu pedido', $args['comment_field'] );
		\Brain\Monkey\Functions\when( 'get_option' )->alias( static fn( string $option, $default = false ) => 'akb_rewards_reviews_enabled' === $option ? 'no' : $default );
		self::assertStringNotContainsString( 'enlace de reseña de tu pedido', $form->native_form_notice( array() )['comment_field'], 'Con el programa apagado sólo se explica la reseña voluntaria.' );
	}

	/** @testdox La ficha sólo promete revisión pendiente cuando el interruptor nativo está encendido. */
	public function test_native_pending_copy_follows_moderation_toggle(): void {
		$form = new ReviewForm();
		foreach ( array( 'yes', 'no' ) as $enabled ) {
			\Brain\Monkey\Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => 'akb_rewards_moderate_reviews' === $key ? $enabled : $default );
			$args = $form->native_form_notice( array( 'comment_field' => '<textarea name="comment"></textarea>' ) );
			self::assertStringContainsString( 'puedes añadir una foto y no solicita puntos', $args['comment_field'] );
			self::assertStringContainsString( '<textarea name="comment"></textarea>', $args['comment_field'] );
			if ( 'yes' === $enabled ) {
				self::assertStringContainsString( 'Tu reseña quedará pendiente de revisión', $args['comment_field'] );
			} else {
				self::assertStringNotContainsString( 'pendiente de revisión', $args['comment_field'] );
			}
		}
	}

}
