<?php
/**
 * Doble de `$wpdb` para el contrato de AUTORIDAD del precio editorial.
 *
 * No simula MySQL: modela las cuatro fronteras que el camino estricto consulta
 * en crudo —options, atributos WC, relaciones objeto↔término y term-meta— y
 * permite que cualquiera de ellas falle **con la forma real**: la consulta
 * devuelve vacío y deja rastro en `$wpdb->last_error`, que la siguiente consulta
 * exitosa borra.
 *
 * Esa volatilidad es justamente el defecto que se persigue: si la autoridad se
 * comprobara al final del request, el rastro ya no existiría.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

/**
 * @since 1.99.15
 */
final class AuthorityWpdb {

	public string $prefix = 'wp_';

	public string $options = 'wp_options';

	public string $termmeta = 'wp_termmeta';

	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta           = 'wp_postmeta';

	public string $term_taxonomy = 'wp_term_taxonomy';

	public string $last_error = '';

	/**
	 * Filas de `wp_options` que la DB realmente contiene.
	 *
	 * @var array<string, string>
	 */
	public array $option_rows = array();

	/**
	 * Filas de `wp_termmeta` por término. Acepta el atajo escalar
	 * (`501 => '15'`) o filas explícitas
	 * (`501 => [['meta_id' => 9, 'meta_value' => '15']]`) para poder modelar
	 * duplicados y meta_id inválidos.
	 *
	 * @var array<int, mixed>
	 */
	public array $term_meta_rows = array();

	/**
	 * Sobrescribe la respuesta cruda de relaciones para un producto (formas
	 * malformadas: objetos, ceros, negativos, duplicados).
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $relationship_raw = array();

	/** Sobrescribe la respuesta cruda de opciones (filas extra/duplicadas). */
	public ?array $option_raw = null;

	/** Sobrescribe la respuesta cruda de atributos. */
	public ?array $attribute_raw = null;

	/**
	 * Relaciones reales: post_id → lista de [term_id, taxonomy].
	 *
	 * @var array<int, array<int, array{0: int, 1: string}>>
	 */
	public array $relationships = array();

	/**
	 * Filas de `wp_woocommerce_attribute_taxonomies`, con la forma REAL que
	 * consulta el lector estricto: id, name y label.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $attribute_rows = array();

	/**
	 * Fallos programados: fragmento de SQL → mensaje de error. Se consume UNA
	 * vez, como un incidente transitorio de DB.
	 *
	 * @var array<string, string>
	 */
	public array $fail_once = array();

	/**
	 * Consultas recibidas, en orden (para asertar que el camino estricto no se
	 * apoyó en el object cache).
	 *
	 * @var array<int, string>
	 */
	public array $queries = array();

	/**
	 * Programa un fallo transitorio para la próxima consulta que contenga
	 * `$needle`.
	 *
	 * @param string $needle  Fragmento identificador de la consulta.
	 * @param string $message Mensaje de error de MySQL.
	 * @return void
	 */
	public function fail_next( string $needle, string $message = 'MySQL server has gone away' ): void {
		$this->fail_once[ $needle ] = $message;
	}

	/**
	 * @param string $query  Sentencia con placeholders.
	 * @param mixed  ...$args Argumentos.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$query = str_replace( array( '%d', '%f' ), '%s', $query );

		$escaped = array_map(
			static fn( $value ): string => null === $value ? 'NULL' : "'" . str_replace( "'", "''", (string) $value ) . "'",
			$args
		);

		return vsprintf( $query, $escaped );
	}

	/**
	 * ¿Esta consulta debe fallar? Consume el fallo programado y deja el rastro.
	 *
	 * @param string $query Consulta.
	 * @return bool
	 */
	private function maybe_fail( string $query ): bool {
		foreach ( $this->fail_once as $needle => $message ) {
			if ( false !== strpos( $query, $needle ) ) {
				unset( $this->fail_once[ $needle ] );
				$this->last_error = $message;
				return true;
			}
		}

		// Consulta exitosa: MySQL limpia el rastro anterior. Es exactamente lo
		// que hace desaparecer la evidencia de un fallo previo.
		$this->last_error = '';
		return false;
	}

	/**
	 * @param string $query  Consulta.
	 * @param string $output Formato (ignorado; siempre arrays asociativos).
	 * @return array<int, array<string, string>>
	 */
	public function get_results( string $query, $output = null ): array {
		unset( $output );
		$this->queries[] = $query;

		if ( $this->maybe_fail( $query ) ) {
			return array();
		}

		if ( false !== strpos( $query, $this->options ) ) {
			if ( null !== $this->option_raw ) {
				return $this->option_raw;
			}

			$rows = array();
			foreach ( $this->option_rows as $name => $value ) {
				if ( false !== strpos( $query, "'" . $name . "'" ) ) {
					$rows[] = array(
						'option_name'  => (string) $name,
						'option_value' => (string) $value,
					);
				}
			}
			return $rows;
		}

		if ( false !== strpos( $query, $this->termmeta ) ) {
			foreach ( $this->term_meta_rows as $term_id => $value ) {
				if ( false === strpos( $query, "'" . $term_id . "'" ) ) {
					continue;
				}
				if ( is_array( $value ) ) {
					return $value;
				}
				return array( array( 'meta_id' => '11', 'meta_value' => (string) $value ) );
			}
			return array(); // sin filas: ausencia LEGÍTIMA.
		}

		if ( false !== strpos( $query, 'attribute_taxonomies' ) ) {
			if ( null !== $this->attribute_raw ) {
				return $this->attribute_raw;
			}

			$rows = $this->attribute_rows;
			// La consulta real ordena por attribute_id; el doble lo respeta para
			// que el orden del mapa sea verificable.
			usort(
				$rows,
				static fn( array $a, array $b ): int => (int) ( $a['attribute_id'] ?? 0 ) <=> (int) ( $b['attribute_id'] ?? 0 )
			);
			return $rows;
		}

		return array();
	}

	/**
	 * @param string $query Consulta.
	 * @return array<int, string>
	 */
	public function get_col( string $query ): array {
		$this->queries[] = $query;

		if ( $this->maybe_fail( $query ) ) {
			return array();
		}

		if ( false !== strpos( $query, $this->term_relationships ) ) {
			foreach ( $this->relationship_raw as $post_id => $rows ) {
				if ( false !== strpos( $query, "'" . $post_id . "'" ) ) {
					return $rows;
				}
			}

			$out = array();
			foreach ( $this->relationships as $post_id => $pairs ) {
				if ( false === strpos( $query, "'" . $post_id . "'" ) ) {
					continue;
				}
				foreach ( $pairs as $pair ) {
					if ( false !== strpos( $query, "'" . $pair[1] . "'" ) ) {
						$out[] = (string) $pair[0];
					}
				}
			}
			return $out;
		}

		return array();
	}

	/**
	 * @param string $query Consulta.
	 * @return string|null
	 */
	public function get_var( string $query ) {
		$this->queries[] = $query;

		if ( $this->maybe_fail( $query ) ) {
			return null;
		}

		if ( false !== strpos( $query, $this->termmeta ) ) {
			foreach ( $this->term_meta_rows as $term_id => $value ) {
				if ( false !== strpos( $query, "'" . $term_id . "'" ) ) {
					return (string) $value;
				}
			}
			return null; // sin fila: ausencia LEGÍTIMA.
		}

		return null;
	}

	/** ¿Se consultó alguna vez esta tabla/fragmento? */
	public function queried( string $needle ): bool {
		foreach ( $this->queries as $query ) {
			if ( false !== strpos( $query, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
