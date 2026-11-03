<?php
/**
 * Instala y retira {@see OptionsRowWpdb} como `$wpdb` global, devolviendo el que
 * hubiera (el global se contagia entre pruebas y es fuente de rojos por orden).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

use Brain\Monkey\Functions;

trait OptionsRowDouble {

	private $option_row_wpdb_previo = null;

	private bool $option_row_habia_wpdb = false;

	/** Lo que la caché de objetos cree que NO existe (`notoptions`). @var array<string, bool> */
	protected array $notoptions = array();

	/**
	 * @param array<string, mixed>|null $filas Almacén que comparten el SQL y `get_option()`.
	 * @param OptionsRowWpdb|null       $doble Doble propio (que extienda OptionsRowWpdb).
	 */
	protected function instalar_doble_de_options( ?array &$filas = null, ?OptionsRowWpdb $doble = null ): OptionsRowWpdb {
		$this->option_row_habia_wpdb  = array_key_exists( 'wpdb', $GLOBALS );
		$this->option_row_wpdb_previo = $GLOBALS['wpdb'] ?? null;

		// Todo lo que OptionRow toca además de `$wpdb`, en un solo sitio: repartidos
		// por cada prueba, faltaban en alguna y fallaba sólo en ciertos órdenes.
		$this->notoptions = array();
		Functions\when( 'maybe_serialize' )->alias( static fn( $v ) => is_scalar( $v ) ? (string) $v : serialize( $v ) );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->alias( fn( string $clave, string $grupo = '' ) => 'options' === $grupo && 'notoptions' === $clave ? $this->notoptions : false );
		Functions\when( 'wp_cache_set' )->alias(
			function ( string $clave, $valor, string $grupo = '' ): bool {
				if ( 'options' === $grupo && 'notoptions' === $clave ) {
					$this->notoptions = (array) $valor;
				}
				return true;
			}
		);

		$doble = $doble ?? new OptionsRowWpdb();
		if ( null !== $filas ) {
			$doble->filas = &$filas;
		}
		$GLOBALS['wpdb'] = $doble;

		return $doble;
	}

	protected function retirar_doble_de_options(): void {
		if ( $this->option_row_habia_wpdb ) {
			$GLOBALS['wpdb'] = $this->option_row_wpdb_previo;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}
}
