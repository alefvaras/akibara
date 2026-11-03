<?php
/**
 * Doble de `$wpdb` para las pruebas unitarias del índice.
 *
 * NO simula MySQL: registra las sentencias y devuelve el resultado que el caso
 * necesite. Alcanza para los contratos de control de flujo de `Indexer`
 * (¿commiteó?, ¿rollback?, ¿escribió en sombra?), que es donde vive el defecto
 * del ledger. Todo lo que dependa de semántica real de la DB —transacciones,
 * PK, collation, carreras— se prueba en el gate de integración con MariaDB.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests\Support;

/**
 * @since 0.1.13
 */
final class FakeWpdb {

	/** Prefijo de tablas, como el $wpdb real. */
	public string $prefix = 'wp_';

	/** Último error, como el $wpdb real. */
	public string $last_error = '';

	/**
	 * Sentencias recibidas, en orden.
	 *
	 * @var array<int, string>
	 */
	public array $queries = array();

	/** ¿`START TRANSACTION` tiene éxito? */
	public bool $transaction_ok = true;

	/** ¿Los `DELETE` tienen éxito? */
	public bool $delete_ok = true;

	/** ¿El `INSERT` de docs tiene éxito? */
	public bool $insert_ok = true;

	/**
	 * Valor que devuelve `get_var()` — en persist() es el `indexed_at` de la
	 * fila viva (null = el documento todavía no existe).
	 *
	 * @var string|null
	 */
	public ?string $indexed_at = null;

	/**
	 * Emula `$wpdb->prepare()` lo justo para que la sentencia quede legible:
	 * los tests afirman sobre el VERBO de cada query, no sobre el escapado.
	 *
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
	 * @param string $query Sentencia.
	 * @return int|false
	 */
	public function query( string $query ) {
		$this->queries[] = $query;

		$verb = strtoupper( strtok( ltrim( $query ), " \n\t" ) ?: '' );

		if ( 'START' === $verb ) {
			return $this->transaction_ok ? 1 : false;
		}

		if ( 'DELETE' === $verb ) {
			return $this->delete_ok ? 1 : false;
		}

		if ( 'INSERT' === $verb ) {
			return $this->insert_ok ? 1 : false;
		}

		return 1;
	}

	/**
	 * @param string $query Sentencia.
	 * @return string|null
	 */
	public function get_var( string $query ): ?string {
		$this->queries[] = $query;

		return $this->indexed_at;
	}

	/**
	 * ¿Se ejecutó alguna sentencia que empiece con este verbo/palabra?
	 *
	 * @param string $needle Prefijo buscado (COMMIT, ROLLBACK, …).
	 * @return bool
	 */
	public function ran( string $needle ): bool {
		foreach ( $this->queries as $query ) {
			if ( 0 === strncasecmp( ltrim( $query ), $needle, strlen( $needle ) ) ) {
				return true;
			}
		}

		return false;
	}
}
