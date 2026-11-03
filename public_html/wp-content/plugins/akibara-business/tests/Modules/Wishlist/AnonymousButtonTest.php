<?php
/**
 * El corazón de favoritos NO es un conmutador para quien no ha iniciado sesión.
 *
 * `aria-pressed` promete un control de dos estados que se queda en la página.
 * Para un anónimo el botón hace otra cosa: `wishlist.js::toggle()` lo manda al
 * login con `window.location.href`. Se anunciaba como interruptor y ejecutaba un
 * cambio de contexto, y eso le pasa al 100% del tráfico orgánico — 17 corazones
 * por pantalla en una landing de serie.
 *
 * Es un test de FUENTE y no de render porque `Button::html()` es privado y
 * depende de `Storage`, `get_the_title()` y del loop de WooCommerce; montar todo
 * eso daría un test más frágil que el contrato que protege.
 *
 * @package akibara-business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Wishlist;

use PHPUnit\Framework\TestCase;

final class AnonymousButtonTest extends TestCase {

	private function fuente(): string {
		$ruta = dirname( __DIR__, 3 ) . '/src/Modules/Wishlist/Button.php';
		$this->assertFileExists( $ruta );
		return (string) file_get_contents( $ruta );
	}

	public function test_aria_pressed_es_condicional_a_la_sesion(): void {
		$fuente = $this->fuente();

		$this->assertStringContainsString(
			'is_user_logged_in()',
			$fuente,
			'el botón tiene que saber si hay sesión antes de prometer un conmutador'
		);

		// El atributo no puede volver a emitirse incondicionalmente.
		$this->assertDoesNotMatchRegularExpression(
			'/<button[^>]*\saria-pressed="<\?php echo esc_attr\( \$pressed \)/',
			$fuente,
			'aria-pressed no puede salir siempre: para un anónimo es una promesa falsa'
		);

		// Y cuando sí sale, sigue escapado.
		$this->assertStringContainsString(
			"esc_attr( \$pressed )",
			$fuente,
			'el valor sigue escapando'
		);
	}

	public function test_el_label_del_anonimo_anuncia_el_login(): void {
		$fuente = $this->fuente();

		$this->assertMatchesRegularExpression(
			'/Inicia sesión para guardar/u',
			$fuente,
			'el nombre accesible debe decir lo que de verdad va a pasar'
		);

		// El botón SIGUE existiendo para anónimos: es el punto de captación de
		// cuenta en la superficie con más tráfico orgánico. Un `return` temprano
		// por sesión sería la regresión que este test previene.
		$this->assertDoesNotMatchRegularExpression(
			'/if \(\s*!\s*is_user_logged_in\(\)\s*\)\s*\{\s*return/',
			$fuente,
			'no se esconde el botón: se corrige lo que anuncia'
		);
	}

	public function test_el_hover_del_loop_esta_tras_la_puerta_de_hover(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/wishlist.css' );

		// Sin la puerta, en iOS el :hover queda pegado tras el tap y el corazón
		// guardado y el no guardado se ven igual.
		$this->assertMatchesRegularExpression(
			'/@media \(hover: hover\)\s*\{[^}]*akb-wl-toggle--loop:hover/s',
			$css
		);

		// El foco de teclado NO puede quedar dentro de esa puerta: hay teclados
		// en dispositivos sin hover.
		$this->assertMatchesRegularExpression(
			'/^\.products li\.product \.akb-wl-toggle--loop:focus-visible \{/m',
			$css,
			':focus-visible va suelto, fuera del @media'
		);

		// Y NO se resuelve con un reset en `hover: none`: misma especificidad,
		// gana el último, y un corazón guardado se volvería blanco al tocarlo.
		$this->assertDoesNotMatchRegularExpression(
			'/@media \(hover: none\)\s*\{[^}]*akb-wl-toggle--loop/s',
			$css
		);
	}
}
