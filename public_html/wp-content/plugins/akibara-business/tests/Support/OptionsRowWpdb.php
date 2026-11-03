<?php
/**
 * Doble de `$wpdb` para las tres escrituras atómicas de {@see \Akibara\Business\Utilities\OptionRow}.
 *
 * Modela la semántica de MariaDB, que es lo que se está probando:
 *
 * - `INSERT IGNORE` **no pisa** una fila existente (devuelve 0 filas). Eso es
 *   justo lo que `add_option()` no garantiza, y el motivo de esta utilidad.
 * - Un `UPDATE` que dejaría el mismo valor afecta **0** filas: WordPress no
 *   activa `MYSQLI_CLIENT_FOUND_ROWS`.
 * - `prepare()` escapa con barras; la base guarda el valor sin ellas.
 *
 * Vive en un solo sitio a propósito: cada copia de esta semántica es una copia
 * que puede quedarse vieja y dejar pasar una regresión. Un doble que además
 * necesite consultas propias (posts, postmeta) **extiende** esta clase.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

class OptionsRowWpdb {

	public string $posts    = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $options  = 'wp_options';

	/** La tabla de options del doble. @var array<string, mixed> */
	public array $filas = array();

	/** @var array<int, string> */
	public array $consultas = array();

	public function prepare( string $sql, ...$args ): string {
		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $m ) use ( &$args ): string {
				$valor = array_shift( $args );
				return '%d' === $m[0] ? (string) (int) $valor : "'" . addslashes( (string) $valor ) . "'";
			},
			$sql
		);
	}

	public function esc_like( string $texto ): string {
		return addcslashes( $texto, '_%\\' );
	}

	/** La base guarda el valor sin las barras que puso `prepare()`. */
	protected function crudo( string $valor ): string {
		return stripslashes( $valor );
	}

	public function get_var( string $sql ) {
		$this->consultas[] = $sql;
		if ( preg_match( "/SELECT option_value FROM wp_options WHERE option_name = '([^']*)'/", $sql, $m ) ) {
			$clave = $this->crudo( $m[1] );
			return array_key_exists( $clave, $this->filas ) ? (string) $this->filas[ $clave ] : null;
		}

		return $this->get_var_propio( $sql );
	}

	public function query( string $sql ): int {
		$this->consultas[] = $sql;
		if ( preg_match( "/^INSERT IGNORE INTO wp_options \( option_name, option_value, autoload \) VALUES \( '([^']*)', '([^']*)', 'off' \)$/", $sql, $m ) ) {
			if ( array_key_exists( $this->crudo( $m[1] ), $this->filas ) ) {
				return 0;
			}
			$this->filas[ $this->crudo( $m[1] ) ] = $this->crudo( $m[2] );
			return 1;
		}
		if ( preg_match( "/^UPDATE wp_options SET option_value = '([^']*)' WHERE option_name = '([^']*)' AND BINARY option_value = '([^']*)'$/", $sql, $m ) ) {
			if ( ( $this->filas[ $this->crudo( $m[2] ) ] ?? null ) !== $this->crudo( $m[3] ) || $m[1] === $m[3] ) {
				return 0;
			}
			$this->filas[ $this->crudo( $m[2] ) ] = $this->crudo( $m[1] );
			return 1;
		}
		if ( preg_match( "/^DELETE FROM wp_options WHERE option_name = '([^']*)' AND BINARY option_value = '([^']*)'$/", $sql, $m ) ) {
			if ( ( $this->filas[ $this->crudo( $m[1] ) ] ?? null ) !== $this->crudo( $m[2] ) ) {
				return 0;
			}
			unset( $this->filas[ $this->crudo( $m[1] ) ] );
			return 1;
		}

		throw new \LogicException( 'Consulta no modelada en el doble de options: ' . $sql );
	}

	/**
	 * Consultas que no son de la fila de options: las resuelve quien extienda.
	 *
	 * @param string $sql Consulta ya preparada.
	 */
	protected function get_var_propio( string $sql ) {
		return null;
	}
}
