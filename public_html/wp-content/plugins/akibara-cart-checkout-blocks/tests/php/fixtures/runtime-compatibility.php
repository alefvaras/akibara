<?php
/**
 * Fixture mínimo para las pruebas de RuntimeCompatibility.
 *
 * Registra qué handles se desencolan sin tocar WordPress, para poder fijar la
 * invariante «sólo se van los handles declarados, nunca un barrido por
 * prefijo».
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

if ( ! function_exists( 'wp_dequeue_script' ) ) {
	/**
	 * Anota el handle desencolado.
	 *
	 * @param string $handle Handle del script.
	 */
	function wp_dequeue_script( string $handle ): void {
		$GLOBALS['akibara_test_dequeued'][] = $handle;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escapa como lo hace WordPress para las pruebas.
	 *
	 * @param string $text Texto crudo.
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	/**
	 * Cola de scripts simulada.
	 *
	 * El desencolado de Mercado Pago ahora CUENTA cuántos de los handles de su
	 * lista estaban realmente encolados, porque la señal de Site Health anterior
	 * preguntaba si Mercado Pago los DECLARABA en su código —otra cosa— y
	 * reportaba «el carrito Blocks los sigue retirando» mientras el desencolado
	 * no retiraba ni uno.
	 */
	function wp_script_is( string $handle, string $list = 'enqueued' ): bool {
		unset( $list );

		return in_array( $handle, $GLOBALS['akb_test_enqueued_scripts'] ?? array(), true );
	}
}

/*
 * La LECTURA de options vive en `akibara-business/tests/wp-stubs.php`, que el
 * bootstrap compartido carga antes que cualquier fixture. Este archivo tenía su
 * propia copia sobre OTRO global (`akb_test_options`), así que ganaba la que se
 * declarara primero y la escritura de acá quedaba invisible para la lectura de
 * allá. Un solo nombre de global —`akibara_test_options`— y una sola lectura.
 */
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		unset( $autoload );
		if ( ! isset( $GLOBALS['akibara_test_options'] ) ) {
			$GLOBALS['akibara_test_options'] = array();
		}
		$GLOBALS['akibara_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( int $from, int $to = 0 ): string {
		return max( 1, abs( ( $to ?: time() ) - $from ) ) . ' segundos';
	}
}
