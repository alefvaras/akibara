<?php
/**
 * Doble de `$wpdb` para {@see \Akibara\Business\Utilities\OptionsFromDatabase}:
 * el único `SELECT ... WHERE option_name IN (...)` que esa utilidad emite.
 *
 * Modela lo que de verdad importa: una clave AUSENTE de `$filas` no aparece en
 * el resultado (igual que una fila que no existe en `wp_options`), y `prepare()`
 * escapa con barras que la base guarda sin ellas — la misma semántica que
 * {@see OptionsRowWpdb}, para SELECT con lista `IN` en vez de una fila.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

class OptionsFromDatabaseWpdb {

	public string $options = 'wp_options';

	/** La tabla de options del doble: option_name => option_value. @var array<string, string> */
	public array $filas = array();

	/** @var array<int, string> */
	public array $consultas = array();

	public function prepare( string $sql, ...$args ): string {
		return (string) preg_replace_callback(
			'/%s/',
			static function ( array $m ) use ( &$args ): string {
				unset( $m );
				return "'" . addslashes( (string) array_shift( $args ) ) . "'";
			},
			$sql
		);
	}

	/**
	 * @param string $sql
	 * @param mixed  $output Ignorado: el doble siempre entrega `ARRAY_A`.
	 * @return array<int, array{option_name: string, option_value: string}>
	 */
	public function get_results( string $sql, $output = null ) {
		unset( $output );
		$this->consultas[] = $sql;

		if ( ! preg_match( '/WHERE option_name IN \( (.+) \)$/', $sql, $m ) ) {
			throw new \LogicException( 'Consulta no modelada en el doble de OptionsFromDatabase: ' . $sql );
		}

		preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $claves );

		$filas = array();
		foreach ( $claves[1] as $clave_escapada ) {
			$clave = stripslashes( $clave_escapada );
			if ( array_key_exists( $clave, $this->filas ) ) {
				$filas[] = array(
					'option_name'  => $clave,
					'option_value' => $this->filas[ $clave ],
				);
			}
		}

		return $filas;
	}
}
