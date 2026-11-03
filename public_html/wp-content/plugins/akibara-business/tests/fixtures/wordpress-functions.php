<?php
/**
 * Minimal WordPress function fixtures for isolated service tests.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

if ( ! function_exists( '__' ) ) {
	/**
	 * Returns untranslated test copy.
	 *
	 * @param string $text Source text.
	 * @return string
	 */
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Returns the unmodified test value.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Filtered value.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value ) {
		unset( $hook_name );

		return $value;
	}
}

/*
 * `get_option()` se mudó a `tests/wp-stubs.php` —mismo cuerpo y mismo contrato
 * (`$GLOBALS['akibara_test_options']`)— porque el bootstrap lo carga para TODOS
 * los tests, no sólo para los cinco que requieren este fixture. Los que sí lo
 * requieren siguen sembrando el global exactamente igual.
 */

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Returns the requested fixture postmeta value ('' when unset).
	 *
	 * Availability (1.109.0) deriva el estado comercial leyendo `_akb_eta_type`
	 * y `_akb_eta_release_date` por este canal: sin fixture, todo reservable es
	 * «A pedido» — exactamente el default de producción.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Unused (fixture always returns the scalar).
	 * @return mixed
	 */
	function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
		unset( $single );
		$meta = $GLOBALS['akibara_test_postmeta'] ?? array();

		return $meta[ $post_id ][ $key ] ?? '';
	}
}
