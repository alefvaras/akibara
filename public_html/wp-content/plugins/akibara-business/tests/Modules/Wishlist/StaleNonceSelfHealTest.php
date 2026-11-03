<?php
/**
 * Candado: un nonce caducado no puede dejar el corazón muerto.
 *
 * El nonce de Favoritos viaja horneado por `wp_localize_script()` en HTML
 * PÚBLICO que LiteSpeed cachea hasta 7 días — mucho más de lo que vive un nonce
 * (24 h). Con `check_ajax_referer()` fatal, el cliente que abría una pestaña un
 * rato, o al que le tocaba una página desde caché, pulsaba el corazón y leía
 * «No pudimos guardar» para siempre; el propio JS documentaba el síntoma sin
 * poder resolverlo.
 *
 * El carrito nativo ya resuelve esto reenviando un nonce fresco en cada
 * respuesta y adoptándolo en el cliente. Acá se hace lo mismo por la puerta del
 * rechazo: el 403 trae el nonce nuevo y el JS reintenta UNA vez.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Wishlist;

use PHPUnit\Framework\TestCase;

final class StaleNonceSelfHealTest extends TestCase {

	private function backend(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Wishlist/Ajax.php' );
	}

	private function frontend(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/wishlist.js' );
	}

	/**
	 * @testdox El rechazo por nonce no es fatal y devuelve uno fresco.
	 */
	public function test_the_rejection_carries_a_fresh_nonce(): void {
		$php = $this->backend();

		self::assertMatchesRegularExpression(
			"/check_ajax_referer\(\s*self::NONCE_ACTION,\s*'nonce',\s*false\s*\)/",
			$php,
			'Con la verificación fatal no hay a dónde devolver el nonce nuevo.'
		);
		self::assertStringContainsString( "'code'    => 'stale_nonce'", $php );
		self::assertStringContainsString( "'nonce'   => wp_create_nonce( self::NONCE_ACTION )", $php );
		self::assertMatchesRegularExpression( '/\),\s*403\s*\)/', $php, 'El cliente distingue el nonce viejo por el 403.' );
	}

	/**
	 * @testdox El cliente adopta el nonce nuevo y reintenta exactamente una vez.
	 */
	public function test_the_client_retries_once(): void {
		$js = $this->frontend();

		self::assertStringContainsString( 'function adoptNonce(json)', $js );
		self::assertStringContainsString( 'res.status === 403 && !isRetry && adoptNonce(res.json)', $js );
		self::assertStringContainsString( 'toggle(productId, cardToRemoveOnUnsave, true)', $js );
		self::assertMatchesRegularExpression(
			'/if \(!reintentando\) \{\s*lockButtons\(buttons, false\);/',
			$js,
			'Desbloquear entre el rechazo y el reintento deja el corazón pulsable en mitad de la operación.'
		);
	}

	/**
	 * @testdox El endpoint de conteo que nadie consultaba ya no existe.
	 */
	public function test_the_unused_count_endpoint_is_gone(): void {
		$php = $this->backend();

		self::assertStringNotContainsString( 'wp_ajax_akb_wishlist_count', $php );
		self::assertStringNotContainsString( 'wp_ajax_nopriv_akb_wishlist_count', $php );
		self::assertStringNotContainsString(
			'akb_wishlist_count',
			$this->frontend(),
			'El contador del header lo pinta el theme en PHP y lo refresca el evento akb:wishlist:count.'
		);
	}

	/**
	 * @testdox El estado por-usuario del corazón sólo existe en un render con sesión.
	 *
	 * `Storage::has()` responde false con user_id 0, así que la página pública
	 * —la cacheable— no hornea ni `is-saved` ni `aria-pressed`. Es la mitad del
	 * problema que sí se puede fijar desde el código; la otra es que LiteSpeed
	 * nunca cachee un render con sesión, y eso se verifica contra la superficie
	 * servida.
	 */
	public function test_the_saved_state_depends_on_a_session(): void {
		$button = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Wishlist/Button.php' );

		self::assertStringContainsString( '$saved   = Storage::has( $product_id );', $button );
		self::assertMatchesRegularExpression(
			"/\\\$logged \? ' aria-pressed=/",
			$button,
			'`aria-pressed` sólo se emite con sesión: para el invitado el botón no es un conmutador.'
		);

		$storage = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Wishlist/Storage.php' );
		self::assertStringContainsString(
			'user_id=0',
			$storage,
			'La garantía es que sin sesión no hay estado que hornear.'
		);
	}

	/**
	 * @testdox Auto-guardado post-login usa intent add para no borrar favoritos existentes.
	 */
	public function test_post_login_intent_uses_add_mode(): void {
		$php = $this->backend();
		$js  = $this->frontend();

		self::assertStringContainsString( "'add' === \$intent", $php );
		self::assertStringContainsString( "toggle(productId, null, false, 'add')", $js );
		self::assertStringContainsString( 'akb_wl_ids', $js );
	}
}
