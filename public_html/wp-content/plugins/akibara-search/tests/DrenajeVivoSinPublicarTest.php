<?php
/**
 * Drenar la deuda viva no puede cerrar el buscador ni publicar por producto.
 *
 * EL DEFECTO, medido en el espejo el 2026-09-14 (deuda previa: el camino es
 * byte-idéntico en 0.1.61). Un cambio de configuración editorial marca el
 * catálogo entero como deuda viva —~1.630 productos—, y hasta 0.1.63
 * `process_live_claim()` cerraba POR PRODUCTO su propio ciclo
 * `request_generation_publication` → `consume_generation_publications` →
 * `bump_index_generation` + `RestCache::purge` + huella semántica + recibo
 * editorial, y encima llamaba a `reconcile_now()`. Un solo proceso
 * `wp-cron.php` hizo 2.304 `updated_option` en 27 s, un ciclo cada 50-60 ms,
 * con generaciones consecutivas (…-80130, …-80131).
 *
 * Lo que eso le hace al cliente: el gate público lee el bundle de options DOS
 * veces por petición y exige que cuadre byte a byte
 * (`fresh_publication_identity()` → `bundle_is_ready()`), así que la petición
 * que cae entre dos publicaciones responde 503 `index_unavailable`. Sonda a
 * 4 Hz durante el drenaje: entre 30 % y 60 % de 503; en reposo, 100 % de 200.
 *
 * Y el trabajo no cambiaba nada: el propio registro de auditoría del mismo
 * cambio dice «Revisión editorial N→N+1 re-sellada: 1632 elegibles con precio
 * y precio_html idénticos a los ya indexados, cero rebuild».
 *
 * LO QUE FIJAN ESTAS PRUEBAS (0.1.64):
 *   1. Reescribir filas IDÉNTICAS no publica generación y no escribe el índice:
 *      sólo refresca `indexed_at` y ACKea. Sí queda UN `reconcile_now()` por
 *      lote cerrado, y es deliberado: es lo que re-sella el recibo editorial del
 *      cambio que disparó el drenaje (antes corría 1.630 veces; ahora ~65).
 *   2. Un lote con cambios gasta UNA publicación, no una por producto.
 *   3. El orden del protocolo se conserva: todas las TX1, luego la publicación,
 *      luego todos los ACK.
 *   4. La durabilidad no se debilita: un claim que ya tuvo un intento
 *      (`attempts >= 2`) publica aunque sus filas coincidan, porque esas filas
 *      pueden ser las que un proceso muerto dejó comprometidas SIN publicar.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
}

namespace Akibara\Search\Tests {

	use Akibara\Search\DocumentBuilder;
	use Akibara\Search\EditorialDiscountPublication;
	use Akibara\Search\FacetRegistry;
	use Akibara\Search\Indexer;
	use Akibara\Search\Install;
	use Akibara\Search\ProductSourceAuthority;
	use Akibara\Search\RebuildJob;
	use Akibara\Search\SemanticPublication;
	use Akibara\Search\WorkRepository;
	use Akibara\Search\WriteFence;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__ ) . '/src/Normalizer.php';
	require_once dirname( __DIR__ ) . '/src/FacetRegistry.php';
	require_once dirname( __DIR__ ) . '/src/StockLabels.php';
	require_once dirname( __DIR__ ) . '/src/AuditLog.php';
	require_once dirname( __DIR__ ) . '/src/WorkRepository.php';
	require_once dirname( __DIR__ ) . '/src/WriteFence.php';

	/**
	 * Doble de wpdb con MUCHAS filas de trabajo y con las TRES tablas vivas del
	 * índice materializadas.
	 *
	 * Por qué hacía falta otro doble: `IndexerProtocolWpdb` modela UNA fila de
	 * trabajo y ninguna fila viva, y este defecto sólo se ve drenando 25 o 60
	 * claims y comparando contra lo que el índice ya tiene. Las filas se guardan
	 * como las devolvería MySQL —enteros y DECIMAL(12,2) en su forma textual—
	 * para que la comparación del claim mida lo que mide en producción, no una
	 * coincidencia de tipos PHP.
	 *
	 * Lo que NO modela, a propósito: `available_at`. Aquí un claim reintentado se
	 * puede reclamar de inmediato; en producción espera su demora. Eso acorta las
	 * pruebas sin cambiar ninguna decisión del protocolo.
	 */
	final class DrenajeVivoWpdb {

		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		public string $posts = 'wp_posts';
		public string $last_error = '';

		/** @var array<int, string> */
		public array $queries = array();

		/** @var array<int, string> */
		public array $events = array();

		/** @var array<string, string> option_name => option_value serializado. */
		public array $option_values = array();

		public bool $lock_active = false;
		public ?string $state_json = null;

		/** @var array<int, array<string, mixed>> Filas de trabajo por id. */
		public array $work = array();

		/** @var array<int, array<string, string|null>> Documento vivo por post_id. */
		public array $docs = array();

		/** @var array<int, array<int, array<string, string|null>>> Tokens vivos por post_id. */
		public array $tokens = array();

		/** @var array<int, array<int, array<string, string|null>>> Facetas vivas por post_id. */
		public array $facets = array();

		/**
		 * `attempts` con el que sale CADA arriendo, por producto.
		 *
		 * Es el dato que `live_claim_is_a_noop()` lee de verdad. Contar sentencias
		 * de arriendo no sirve: seguiría en verde aunque el código dejara de mirar
		 * el contador.
		 *
		 * @var array<int, array<int, int>>
		 */
		public array $arriendos = array();

		/**
		 * `microtime(true)` en que se concedió cada arriendo, en orden.
		 *
		 * Permite comprobar que el deadline del lote se mide ANTES del arriendo
		 * del primer claim staged, con el mismo reloj que usa el pump.
		 *
		 * @var array<int, float>
		 */
		public array $arriendos_en = array();

		private int $next_id = 1;
		private bool $transaction_open = false;

		/** @var array<string, mixed>|null */
		private ?array $snapshot = null;

		/**
		 * @param string $query   Sentencia con marcadores.
		 * @param mixed  ...$args Valores.
		 * @return string
		 */
		public function prepare( string $query, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}

			$query   = str_replace( array( '%d', '%f' ), '%s', $query );
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
			$trimmed         = ltrim( $query );

			if ( 0 === stripos( $trimmed, 'START TRANSACTION' ) ) {
				$this->snapshot = array(
					'work'          => $this->work,
					'docs'          => $this->docs,
					'tokens'        => $this->tokens,
					'facets'        => $this->facets,
					'option_values' => $this->option_values,
				);
				$this->transaction_open = true;
				return 0;
			}
			if ( 0 === stripos( $trimmed, 'COMMIT' ) ) {
				$this->events[]         = 'commit';
				$this->snapshot         = null;
				$this->transaction_open = false;
				return 0;
			}
			if ( 0 === stripos( $trimmed, 'ROLLBACK' ) ) {
				if ( is_array( $this->snapshot ) ) {
					$this->work          = $this->snapshot['work'];
					$this->docs          = $this->snapshot['docs'];
					$this->tokens        = $this->snapshot['tokens'];
					$this->facets        = $this->snapshot['facets'];
					$this->option_values = $this->snapshot['option_values'];
				}
				$this->snapshot         = null;
				$this->transaction_open = false;
				return 0;
			}

			$options = $this->option_write( $query, $trimmed );
			if ( null !== $options ) {
				return $options;
			}
			$work = $this->work_write( $query );
			if ( null !== $work ) {
				return $work;
			}

			return $this->index_write( $query, $trimmed );
		}

		/**
		 * CAS de options: mismas tres formas que usa `Install::replace_option_cas()`.
		 *
		 * @param string $query   Sentencia completa.
		 * @param string $trimmed Sentencia sin espacios iniciales.
		 * @return int|false|null null si la sentencia no es de options.
		 */
		private function option_write( string $query, string $trimmed ) {
			if ( false !== stripos( $trimmed, 'INSERT IGNORE INTO wp_options' )
				&& 1 === preg_match( "/VALUES \\('((?:''|[^'])*)', '((?:''|[^'])*)', '((?:''|[^'])*)'\\)/s", $query, $matches ) ) {
				$name = str_replace( "''", "'", $matches[1] );
				if ( array_key_exists( $name, $this->option_values ) ) {
					return 0;
				}
				$this->option_values[ $name ] = str_replace( "''", "'", $matches[2] );
				return 1;
			}
			if ( false !== stripos( $trimmed, 'UPDATE wp_options SET option_value' )
				&& 1 === preg_match( "/SET option_value = '((?:''|[^'])*)' WHERE option_name = '((?:''|[^'])*)' AND (?:BINARY )?option_value = (?:BINARY )?'((?:''|[^'])*)'/s", $query, $matches ) ) {
				$replacement = str_replace( "''", "'", $matches[1] );
				$name        = str_replace( "''", "'", $matches[2] );
				$expected    = str_replace( "''", "'", $matches[3] );
				if ( ! array_key_exists( $name, $this->option_values ) || $expected !== $this->option_values[ $name ] ) {
					return 0;
				}
				$this->option_values[ $name ] = $replacement;
				return 1;
			}
			if ( false !== stripos( $trimmed, 'DELETE FROM wp_options WHERE option_name' )
				&& 1 === preg_match( "/option_name = '((?:''|[^'])*)' AND (?:BINARY )?option_value = (?:BINARY )?'((?:''|[^'])*)'/s", $query, $matches ) ) {
				$name     = str_replace( "''", "'", $matches[1] );
				$expected = str_replace( "''", "'", $matches[2] );
				if ( ! array_key_exists( $name, $this->option_values ) || $expected !== $this->option_values[ $name ] ) {
					return 0;
				}
				unset( $this->option_values[ $name ] );
				return 1;
			}

			return null;
		}

		/**
		 * Outbox durable: alta de deuda, lease, ACK, retry y bloqueo.
		 *
		 * @param string $query Sentencia.
		 * @return int|false|null null si la sentencia no es de la tabla de trabajo.
		 */
		private function work_write( string $query ) {
			if ( false === strpos( $query, '`wp_akb_search_work`' ) ) {
				return null;
			}

			if ( false !== strpos( $query, 'INSERT INTO `wp_akb_search_work`' ) ) {
				preg_match_all( "/\\('(live|repair)', '[^']*', '([0-9]+)', 1, 0, '', 'pending'/", $query, $matches, PREG_SET_ORDER );
				$this->events[] = 'dirty';
				foreach ( $matches as $match ) {
					if ( 'live' !== $match[1] ) {
						continue; // La deuda de reparación no participa de estas pruebas.
					}
					$this->mark_dirty( (int) $match[2] );
				}
				return max( 1, count( $matches ) );
			}

			// `recover_expired()` NO lleva `WHERE id`: barre todos los arriendos
			// vencidos de un scope. Es el camino del proceso muerto, y sin
			// modelarlo esa recuperación devolvía 0 filas en silencio.
			if ( false !== strpos( $query, "SET status = 'pending'" )
				&& false !== strpos( $query, 'lease_until <= UTC_TIMESTAMP()' )
				&& 1 !== preg_match( "/WHERE id = '[0-9]+'/", $query ) ) {
				return $this->recover_expired_rows( $query );
			}

			$id = 1 === preg_match( "/WHERE id = '([0-9]+)'/", $query, $matches ) ? (int) $matches[1] : 0;
			if ( 0 === $id || ! isset( $this->work[ $id ] ) ) {
				return 0;
			}
			$row = $this->work[ $id ];

			if ( false !== strpos( $query, "SET status = 'leased'" ) ) {
				if ( WorkRepository::STATUS_PENDING !== $row['status']
					|| 1 !== preg_match( "/lease_token = '([^']+)'/", $query, $token ) ) {
					return 0;
				}
				$segundos           = 1 === preg_match( "/INTERVAL '([0-9]+)' SECOND/", $query, $intervalo ) ? (int) $intervalo[1] : 60;
				$row['status']      = WorkRepository::STATUS_LEASED;
				$row['lease_token'] = $token[1];
				$row['lease_seq']   = (int) $row['wanted_seq'];
				// Vencimiento REAL, en UTC como `UTC_TIMESTAMP()`: sin él no se
				// puede modelar la recuperación de un arriendo caducado.
				$row['lease_until'] = gmdate( 'Y-m-d H:i:s', time() + $segundos );
				$row['attempts']    = (int) $row['attempts'] + 1;
				$row['last_error']  = null;
				$this->work[ $id ]  = $row;
				$this->arriendos[ (int) $row['product_id'] ][] = (int) $row['attempts'];
				$this->arriendos_en[]                          = microtime( true );
				return 1;
			}

			if ( false !== strpos( $query, 'SET acked_seq = GREATEST' ) ) {
				if ( ! $this->lease_matches( $row, $query ) ) {
					return 0;
				}
				$lease_seq          = (int) $row['lease_seq'];
				$row['acked_seq']   = max( (int) $row['acked_seq'], $lease_seq );
				$row['outcome']     = false !== strpos( $query, "outcome = 'indexed'" )
					? WorkRepository::OUTCOME_INDEXED
					: WorkRepository::OUTCOME_ABSENT;
				$row['status']      = (int) $row['wanted_seq'] > $lease_seq
					? WorkRepository::STATUS_PENDING
					: WorkRepository::STATUS_ACKED;
				$row['lease_token'] = '';
				$row['lease_seq']   = 0;
				$row['lease_until'] = null;
				$row['attempts']    = 0;
				$this->work[ $id ]  = $row;
				$this->events[]     = 'ack';
				return 1;
			}

			if ( false !== strpos( $query, "SET status = 'pending'" ) ) {
				if ( ! $this->lease_matches( $row, $query ) ) {
					return 0;
				}
				$row['status']      = WorkRepository::STATUS_PENDING;
				$row['outcome']     = '';
				$row['lease_token'] = '';
				$row['lease_seq']   = 0;
				$row['lease_until'] = null;
				// El diagnóstico se conserva tal cual: un claim que falla sin
				// decir por qué convierte cualquier rojo de esta suite en una
				// cacería a ciegas.
				$row['last_error']  = 1 === preg_match( "/last_error = '((?:''|[^'])*)'/", $query, $error )
					? str_replace( "''", "'", $error[1] )
					: 'retry';
				$this->work[ $id ]  = $row;
				$this->events[]     = 'retry';
				return 1;
			}

			if ( false !== strpos( $query, "SET status = 'blocked'" ) ) {
				if ( ! $this->lease_matches( $row, $query ) ) {
					return 0;
				}
				$row['status']      = WorkRepository::STATUS_BLOCKED;
				$row['lease_token'] = '';
				$row['lease_seq']   = 0;
				$row['lease_until'] = null;
				$this->work[ $id ]  = $row;
				$this->events[]     = 'blocked';
				return 1;
			}

			return 0;
		}

		/**
		 * Escrituras del índice vivo: DELETE, alta del documento, tokens, facetas
		 * y el refresco del sello temporal.
		 *
		 * @param string $query   Sentencia.
		 * @param string $trimmed Sentencia sin espacios iniciales.
		 * @return int|false
		 */
		private function index_write( string $query, string $trimmed ) {
			$post_id = 1 === preg_match( "/post_id = '([0-9]+)'/", $query, $matches ) ? (int) $matches[1] : 0;

			if ( 0 === stripos( $trimmed, 'DELETE FROM wp_akb_search_' ) && 0 < $post_id ) {
				$tabla = $this->table_of( $query );
				if ( 'docs' === $tabla ) {
					unset( $this->docs[ $post_id ] );
				} elseif ( 'tokens' === $tabla ) {
					unset( $this->tokens[ $post_id ] );
				} elseif ( 'facets' === $tabla ) {
					unset( $this->facets[ $post_id ] );
				}
				$this->events[] = 'live_delete';
				return 1;
			}

			if ( false !== strpos( $query, 'UPDATE wp_akb_search_docs SET indexed_at' ) && 0 < $post_id ) {
				if ( 1 === preg_match( "/SET indexed_at = '((?:''|[^'])*)'/", $query, $sello )
					&& isset( $this->docs[ $post_id ] ) ) {
					$this->docs[ $post_id ]['indexed_at'] = str_replace( "''", "'", $sello[1] );
				}
				$this->events[] = 'sello';
				return 1;
			}

			if ( false !== strpos( $query, 'UPDATE wp_akb_search_docs SET tomo = NULL' ) && 0 < $post_id ) {
				if ( isset( $this->docs[ $post_id ] ) ) {
					$this->docs[ $post_id ]['tomo'] = null;
				}
				$this->events[] = 'live_write';
				return 1;
			}

			if ( 0 === stripos( $trimmed, 'INSERT INTO wp_akb_search_docs' ) ) {
				$filas = self::parse_insert( $query );
				foreach ( $filas as $fila ) {
					$this->docs[ (int) $fila['post_id'] ] = self::as_docs_row( $fila );
				}
				$this->events[] = 'live_write';
				return count( $filas );
			}

			if ( 0 === stripos( $trimmed, 'INSERT IGNORE INTO wp_akb_search_tokens' ) ) {
				$filas     = self::parse_insert( $query );
				$insertadas = 0;
				foreach ( $filas as $fila ) {
					$destino = (int) $fila['post_id'];
					$nueva   = array(
						'token' => (string) $fila['token'],
						'field' => (string) (int) $fila['field'],
					);
					if ( in_array( $nueva, $this->tokens[ $destino ] ?? array(), true ) ) {
						continue; // INSERT IGNORE: la PK colapsa el duplicado exacto.
					}
					$this->tokens[ $destino ][] = $nueva;
					++$insertadas;
				}
				$this->events[] = 'live_write';
				return $insertadas;
			}

			if ( 0 === stripos( $trimmed, 'INSERT IGNORE INTO wp_akb_search_facet_index' ) ) {
				$filas      = self::parse_insert( $query );
				$insertadas = 0;
				foreach ( $filas as $fila ) {
					$destino = (int) $fila['post_id'];
					$nueva   = array(
						'facet'     => (string) $fila['facet'],
						'value'     => (string) $fila['value'],
						'label'     => (string) $fila['label'],
						'value_num' => null === $fila['value_num'] ? null : sprintf( '%.2F', (float) $fila['value_num'] ),
						'term_id'   => null === $fila['term_id'] ? null : (string) (int) $fila['term_id'],
						'parent_id' => null === $fila['parent_id'] ? null : (string) (int) $fila['parent_id'],
					);
					$repetida = false;
					foreach ( $this->facets[ $destino ] ?? array() as $existente ) {
						if ( $existente['facet'] === $nueva['facet'] && $existente['value'] === $nueva['value'] ) {
							$repetida = true; // PK (facet, post_id, value).
							break;
						}
					}
					if ( $repetida ) {
						continue;
					}
					$this->facets[ $destino ][] = $nueva;
					++$insertadas;
				}
				$this->events[] = 'live_write';
				return $insertadas;
			}

			return 1;
		}

		/** @return object|null */
		public function get_row( string $query ): ?object {
			$this->queries[] = $query;

			if ( false === strpos( $query, '`wp_akb_search_work`' ) ) {
				return null;
			}
			if ( 1 === preg_match( "/WHERE id = '([0-9]+)'/", $query, $matches ) ) {
				$id = (int) $matches[1];
				return isset( $this->work[ $id ] ) ? (object) $this->work[ $id ] : null;
			}

			$filtro = 1 === preg_match( "/product_id = '([0-9]+)'/", $query, $matches ) ? (int) $matches[1] : 0;
			if ( false === strpos( $query, 'ORDER BY product_id ASC' ) ) {
				// `WorkRepository::find()`: identidad exacta, sin reclamar nada.
				foreach ( $this->work as $row ) {
					if ( $filtro === (int) $row['product_id'] ) {
						return (object) $row;
					}
				}
				return null;
			}

			$candidatas = array();
			foreach ( $this->work as $row ) {
				if ( WorkRepository::STATUS_PENDING !== $row['status']
					|| ( 0 !== $filtro && $filtro !== (int) $row['product_id'] ) ) {
					continue;
				}
				$candidatas[ (int) $row['product_id'] ] = $row;
			}
			if ( array() === $candidatas ) {
				return null;
			}
			ksort( $candidatas, SORT_NUMERIC );

			return (object) reset( $candidatas );
		}

		/** @return mixed */
		public function get_var( string $query ) {
			$this->queries[] = $query;

			if ( false !== strpos( $query, '@@in_transaction' ) ) {
				return $this->transaction_open ? '1' : '0';
			}
			if ( false !== strpos( $query, 'GET_LOCK(' ) ) {
				$this->lock_active = true;
				return 1;
			}
			if ( false !== strpos( $query, 'CONNECTION_ID()' ) ) {
				return 91;
			}
			if ( false !== strpos( $query, 'IS_USED_LOCK(' ) ) {
				return $this->lock_active ? 91 : null;
			}
			if ( false !== strpos( $query, 'RELEASE_LOCK(' ) ) {
				$this->lock_active = false;
				$this->events[]    = 'release';
				return 1;
			}
			if ( false !== strpos( $query, 'SELECT option_value FROM wp_options' )
				&& 1 === preg_match( "/option_name = '((?:''|[^'])*)'/", $query, $matches ) ) {
				$name = str_replace( "''", "'", $matches[1] );
				if ( RebuildJob::OPTION_STATE === $name ) {
					return $this->state_json;
				}
				return $this->option_values[ $name ] ?? null;
			}
			if ( false !== strpos( $query, 'SELECT id FROM `wp_akb_search_work`' ) ) {
				$id = 1 === preg_match( "/WHERE id = '([0-9]+)'/", $query, $matches ) ? (int) $matches[1] : 0;
				if ( 0 === $id || ! isset( $this->work[ $id ] ) ) {
					return null;
				}
				return $this->lease_matches( $this->work[ $id ], $query ) ? $id : null;
			}

			return null;
		}

		/** @return array<int, array<string, string|null>> */
		public function get_results( string $query, $output = null ): array {
			unset( $output );
			$this->queries[] = $query;

			if ( 1 !== preg_match( '/^\s*SELECT (.+) FROM (wp_akb_search_[a-z_]+) WHERE post_id = \'([0-9]+)\'/', $query, $matches ) ) {
				return array();
			}
			$columnas = array_map( 'trim', explode( ',', $matches[1] ) );
			$tabla    = $this->table_of( $matches[2] );
			$post_id  = (int) $matches[3];

			if ( 'docs' === $tabla ) {
				$filas = isset( $this->docs[ $post_id ] ) ? array( $this->docs[ $post_id ] ) : array();
			} elseif ( 'tokens' === $tabla ) {
				$filas = $this->tokens[ $post_id ] ?? array();
			} else {
				$filas = $this->facets[ $post_id ] ?? array();
			}

			$salida = array();
			foreach ( $filas as $fila ) {
				$proyeccion = array();
				foreach ( $columnas as $columna ) {
					$proyeccion[ $columna ] = $fila[ $columna ] ?? null;
				}
				$salida[] = $proyeccion;
			}

			return $salida;
		}

		/** @return array<int, string> */
		public function get_col( string $query ): array {
			$this->queries[] = $query;

			return array();
		}

		/**
		 * Devuelve a la cola los arriendos vencidos, conservando `attempts`.
		 *
		 * @param string $query Sentencia de `WorkRepository::recover_expired()`.
		 * @return int Filas recuperadas.
		 */
		private function recover_expired_rows( string $query ): int {
			$scope = 1 === preg_match( "/scope = '([a-z]+)'/", $query, $matches ) ? $matches[1] : null;

			$recuperadas = 0;
			foreach ( $this->work as $id => $row ) {
				if ( WorkRepository::STATUS_LEASED !== $row['status']
					|| null === $row['lease_until']
					|| ( null !== $scope && $scope !== $row['scope'] )
					|| (int) strtotime( (string) $row['lease_until'] . ' UTC' ) > time() ) {
					continue;
				}
				$row['status']      = WorkRepository::STATUS_PENDING;
				$row['outcome']     = '';
				$row['lease_token'] = '';
				$row['lease_seq']   = 0;
				$row['lease_until'] = null;
				$row['last_error']  = 'lease_expired';
				// `attempts` NO se toca: es la memoria de que hubo un intento.
				$this->work[ $id ]  = $row;
				++$recuperadas;
			}
			$this->events[] = 'recover';

			return $recuperadas;
		}

		/**
		 * Alta/incremento de la revisión live, con la semántica n+1 de la tabla.
		 *
		 * @param int $product_id Producto.
		 * @return void
		 */
		private function mark_dirty( int $product_id ): void {
			foreach ( $this->work as $id => $row ) {
				if ( $product_id !== (int) $row['product_id'] ) {
					continue;
				}
				$row['wanted_seq'] = (int) $row['wanted_seq'] + 1;
				if ( WorkRepository::STATUS_LEASED !== $row['status'] ) {
					$row['status']      = WorkRepository::STATUS_PENDING;
					$row['outcome']     = '';
					$row['lease_token'] = '';
					$row['lease_seq']   = 0;
					$row['lease_until'] = null;
				}
				$this->work[ $id ] = $row;
				return;
			}

			$id                = $this->next_id++;
			$this->work[ $id ] = array(
				'id'           => $id,
				'scope'        => WorkRepository::SCOPE_LIVE,
				'run_id'       => '',
				'product_id'   => $product_id,
				'wanted_seq'   => 1,
				'acked_seq'    => 0,
				'outcome'      => '',
				'status'       => WorkRepository::STATUS_PENDING,
				'available_at' => '2026-01-01 00:00:00',
				'lease_token'  => '',
				'lease_seq'    => 0,
				'lease_until'  => null,
				'attempts'     => 0,
				'last_error'   => null,
			);
		}

		/**
		 * ¿El WHERE de la sentencia cuadra con el lease vivo de la fila?
		 *
		 * @param array<string, mixed> $row   Fila de trabajo.
		 * @param string               $query Sentencia con su WHERE.
		 * @return bool
		 */
		private function lease_matches( array $row, string $query ): bool {
			if ( WorkRepository::STATUS_LEASED !== $row['status'] ) {
				return false;
			}
			// SÓLO el WHERE. El SET del ACK y del retry empiezan por
			// `lease_token = '', lease_seq = 0`, así que buscar en la sentencia
			// entera comparaba el lease vivo contra el que se está limpiando y
			// ninguna fila casaba nunca.
			$corte = strrpos( $query, ' WHERE ' );
			$donde = false === $corte ? $query : substr( $query, $corte );
			$token = 1 === preg_match( "/lease_token = '([^']*)'/", $donde, $matches ) ? $matches[1] : null;
			$seq   = 1 === preg_match( "/lease_seq = '([0-9]+)'/", $donde, $seqs ) ? (int) $seqs[1] : null;

			return null !== $token && $token === (string) $row['lease_token']
				&& ( null === $seq || $seq === (int) $row['lease_seq'] );
		}

		/**
		 * @param string $query Sentencia o nombre de tabla.
		 * @return string docs|tokens|facets|otra
		 */
		private function table_of( string $query ): string {
			if ( false !== strpos( $query, 'wp_akb_search_docs' ) ) {
				return 'docs';
			}
			if ( false !== strpos( $query, 'wp_akb_search_tokens' ) ) {
				return 'tokens';
			}

			return false !== strpos( $query, 'wp_akb_search_facet_index' ) ? 'facets' : 'otra';
		}

		/**
		 * Columnas + tuplas de un INSERT ya preparado.
		 *
		 * @param string $query Sentencia.
		 * @return array<int, array<string, string|null>>
		 */
		private static function parse_insert( string $query ): array {
			// El INSERT del documento parte la sentencia en tres líneas, así que
			// el separador es «espacio en blanco», no un literal ' VALUES '.
			if ( 1 !== preg_match( '/\sVALUES\s/i', $query, $separador, PREG_OFFSET_CAPTURE ) ) {
				return array();
			}
			$corte = (int) $separador[0][1];
			if ( 1 !== preg_match( '/\(([^()]*)\)\s*$/', rtrim( substr( $query, 0, $corte ) ), $encabezado ) ) {
				return array();
			}
			$columnas = array_map( 'trim', explode( ',', $encabezado[1] ) );

			$tuplas = array();
			preg_match_all(
				"/\\(((?:'(?:''|[^'])*'|NULL|\\s|,)*)\\)/",
				substr( $query, $corte + strlen( $separador[0][0] ) ),
				$matches
			);
			foreach ( $matches[1] ?? array() as $cuerpo ) {
				preg_match_all( "/'((?:''|[^'])*)'|\\bNULL\\b/", $cuerpo, $valores, PREG_SET_ORDER );
				$fila = array();
				foreach ( $valores as $indice => $valor ) {
					if ( ! isset( $columnas[ $indice ] ) ) {
						continue;
					}
					$fila[ $columnas[ $indice ] ] = isset( $valor[1] ) && "'" === substr( $valor[0], 0, 1 )
						? str_replace( "''", "'", $valor[1] )
						: null;
				}
				if ( count( $fila ) === count( $columnas ) ) {
					$tuplas[] = $fila;
				}
			}

			return $tuplas;
		}

		/**
		 * Forma en que MySQL devolvería la fila de documentos: enteros y
		 * DECIMAL(12,2) como texto canónico.
		 *
		 * @param array<string, string|null> $fila Valores del INSERT.
		 * @return array<string, string|null>
		 */
		private static function as_docs_row( array $fila ): array {
			foreach ( array( 'post_id', 'stock_rank', 'menu_order', 'visible', 'tomo' ) as $columna ) {
				if ( array_key_exists( $columna, $fila ) ) {
					$fila[ $columna ] = null === $fila[ $columna ] ? null : (string) (int) $fila[ $columna ];
				}
			}
			if ( array_key_exists( 'price_effective', $fila ) ) {
				$fila['price_effective'] = sprintf( '%.2F', (float) $fila['price_effective'] );
			}

			return $fila;
		}
	}

	/**
	 * @covers \Akibara\Search\Indexer
	 */
	final class DrenajeVivoSinPublicarTest extends TestCase {

		private DrenajeVivoWpdb $wpdb;
		private WorkRepository $work;
		private Indexer $sut;

		/** @var array<string, mixed> */
		private array $options = array();

		/** @var array<int, int> Productos publicados y elegibles. */
		private array $publicados = array();

		/** @var array<int, \WC_Product> Producto concreto por id, cuando el caso lo necesita. */
		private array $productos = array();

		private bool $publicacion_persiste = true;
		private string $sello = '2026-09-14 03:00:00';

		/** @var mixed Precio efectivo inyectado por el filtro del índice. */
		private $precio_efectivo = null;

		private bool $precio_sobreescrito = false;

		/** @var array<int, array{hook: string, args: array<int|string, mixed>}> */
		private array $cola_programada = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			$this->wpdb    = new DrenajeVivoWpdb();
			$this->options = array(
				Install::OPTION_KEY         => Install::DB_VERSION,
				Install::OPTION_SCHEMA_SEAL => Install::schema_seal(),
			);
			$this->publicados          = array();
			$this->productos           = array();
			$this->publicacion_persiste = true;
			$this->sello               = '2026-09-14 03:00:00';
			$this->precio_efectivo     = null;
			$this->precio_sobreescrito = false;
			$this->cola_programada     = array();
			$GLOBALS['wpdb']           = $this->wpdb;

			$this->work = new WorkRepository( $this->wpdb, 'wp_akb_search_work' );
			$fence      = new WriteFence( $this->wpdb, 'akb-drenaje-test' );
			$authority  = new ProductSourceAuthority(
				$this->wpdb,
				fn( array $ids ): array => array_values( array_intersect( $ids, $this->publicados ) ),
				static fn( int $parent_id, int $probe_limit ): array => array()
			);
			$this->sut = new Indexer( new DocumentBuilder(), $this->work, $fence, $authority );
			new RebuildJob( $this->sut, $this->work, $fence );

			$options = &$this->options;
			Functions\when( 'get_option' )->alias(
				static function ( string $name, $default = false ) use ( &$options ) {
					return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
				}
			);
			Functions\when( 'update_option' )->alias(
				function ( string $name, $value ): bool {
					if ( Install::OPTION_INDEX_GEN === $name && ! $this->publicacion_persiste ) {
						return false;
					}
					$this->options[ $name ] = $value;
					if ( Install::OPTION_INDEX_GEN === $name ) {
						$this->wpdb->events[] = 'generation';
					}
					return true;
				}
			);
			Functions\when( 'wp_cache_delete' )->alias( static fn(): bool => true );
			Functions\when( 'delete_transient' )->alias( static fn(): bool => true );
			Functions\when( 'get_transient' )->alias( static fn() => false );
			Functions\when( 'add_action' )->alias( static fn(): bool => true );
			Functions\when( 'as_enqueue_async_action' )->alias(
				function ( string $hook ): int {
					$this->wpdb->events[] = 'wake';
					return 1;
				}
			);
			Functions\when( 'as_schedule_single_action' )->alias(
				function ( int $timestamp, string $hook, array $args = array() ): int {
					unset( $timestamp );
					$this->cola_programada[] = array(
						'hook' => $hook,
						'args' => $args,
					);
					$this->wpdb->events[] = 'wake_delayed';
					return 1;
				}
			);
			Functions\when( 'as_get_scheduled_actions' )->alias(
				function ( array $query ): array {
					foreach ( $this->cola_programada as $pendiente ) {
						if ( $pendiente['hook'] === ( $query['hook'] ?? '' )
							&& $pendiente['args'] === ( $query['args'] ?? array() ) ) {
							return array( 1 );
						}
					}
					return array();
				}
			);
			Functions\when( 'wc_get_product' )->alias(
				function ( int $product_id ) {
					if ( ! in_array( $product_id, $this->publicados, true ) ) {
						return null;
					}
					return $this->productos[ $product_id ] ?? new \WC_Product( $product_id );
				}
			);
			Functions\when( 'get_the_terms' )->alias( static fn() => false );
			Functions\when( 'get_ancestors' )->alias( static fn(): array => array() );
			Functions\when( 'get_terms' )->alias( static fn(): array => array() );
			Functions\when( 'get_term' )->alias( static fn( $term_id, string $taxonomy = '' ) => new \WP_Term( '' === $taxonomy ? 'product_cat' : $taxonomy, (int) $term_id ) );
			Functions\when( 'is_taxonomy_hierarchical' )->alias( static fn( string $taxonomy ): bool => 'product_cat' === $taxonomy );
			Functions\when( 'is_wp_error' )->alias( static fn( $value ): bool => $value instanceof \WP_Error );
			Functions\when( 'get_permalink' )->alias( static fn( int $id ): string => 'https://akibara.cl/producto/' . $id );
			Functions\when( 'wp_kses_post' )->alias( static fn( string $html ): string => $html );
			Functions\when( 'current_time' )->alias( fn(): string => $this->sello );
			Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $value ): string => strip_tags( $value ) );
			Functions\when( 'sanitize_key' )->alias( static fn( string $value ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ) );
			Functions\when( 'wc_placeholder_img_src' )->justReturn( 'https://akibara.cl/placeholder.png' );
			Functions\when( 'apply_filters' )->alias(
				function ( string $hook, $value ) {
					return 'akb_search_effective_price' === $hook && $this->precio_sobreescrito
						? $this->precio_efectivo
						: $value;
				}
			);

			// Defensa: la foto semántica es estado estático compartido entre
			// clases; otra prueba puede dejarla adoptada con un snapshot inválido
			// y la siembra dejaría el índice vacío (semilla 424242, QA 2026-09-24).
			SemanticPublication::reset_authoritative_state_for_tests();
			FacetRegistry::reset_cache();
		}

		protected function tearDown(): void {
			EditorialDiscountPublication::set_test_price_match_sequence( null );
			unset( $GLOBALS['wpdb'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * @testdox Un lote de 25 claims idénticos no publica NINGUNA generación.
		 *
		 * El caso del cambio editorial que no mueve ningún precio, que es el
		 * típico: en 0.1.63 esto publicaba 25 generaciones (25 purgas REST, 100
		 * escrituras de option) y reescribía 25 documentos idénticos. Ahora no
		 * publica ninguna, no escribe el índice y deja los 25 claims ACKeados.
		 */
		public function test_un_lote_de_25_claims_identicos_no_publica_ninguna_generacion(): void {
			$ids = range( 3001, 3025 );
			$this->sembrar_indice( $ids );

			$indice_antes = $this->wpdb->docs;
			$this->ensuciar( $ids );
			$this->reiniciar_eventos();
			$this->sello = '2026-09-14 03:05:00';

			$this->sut->handle_live_pump();

			self::assertSame( 0, $this->contar( 'generation' ), 'Reescribir lo idéntico no puede acuñar generaciones.' );
			self::assertSame( 0, $this->contar( 'live_write' ), 'Ni reescribir las filas del índice.' );
			self::assertSame( 0, $this->contar( 'live_delete' ) );
			self::assertSame( 25, $this->contar( 'ack' ), 'Los 25 claims quedan cerrados igual.' );
			self::assertSame( array(), $this->pendientes(), 'Cero deuda viva al terminar.' );
			foreach ( $ids as $id ) {
				self::assertSame(
					WorkRepository::OUTCOME_INDEXED,
					$this->fila( $id )['outcome'] ?? null,
					'El resultado sigue siendo «indexado», no un fallo silencioso.'
				);
			}
			self::assertSame(
				array_map( static fn( array $fila ): array => array_diff_key( $fila, array( 'indexed_at' => true ) ), $indice_antes ),
				array_map( static fn( array $fila ): array => array_diff_key( $fila, array( 'indexed_at' => true ) ), $this->wpdb->docs ),
				'El contenido servido queda byte a byte igual.'
			);
		}

		/**
		 * @testdox El camino sin cambios refresca `indexed_at` de todos modos.
		 *
		 * No es cosmético: `Reconciler` marca como rancio todo documento con
		 * `post_modified_gmt > indexed_at`, así que un doc que no se reescribe y
		 * tampoco se re-sella quedaría rancio para siempre y el reconciliador lo
		 * re-encolaría en cada corrida — un bucle de trabajo inútil.
		 */
		public function test_el_camino_sin_cambios_refresca_el_sello_temporal(): void {
			$this->sembrar_indice( array( 4001 ) );
			self::assertSame( '2026-09-14 03:00:00', $this->wpdb->docs[4001]['indexed_at'] );

			$this->ensuciar( array( 4001 ) );
			$this->reiniciar_eventos();
			$this->sello = '2026-09-14 04:30:00';

			$this->sut->handle_live_pump();

			self::assertSame( '2026-09-14 04:30:00', $this->wpdb->docs[4001]['indexed_at'] );
			self::assertSame( 1, $this->contar( 'sello' ) );
			self::assertSame( 0, $this->contar( 'generation' ), 'El sello no es contenido servido: no pide generación.' );
		}

		/**
		 * @testdox Un lote de 25 claims CON cambios gasta UNA sola publicación.
		 */
		public function test_un_lote_con_cambios_publica_una_sola_generacion(): void {
			$ids = range( 5001, 5025 );
			$this->sembrar_indice( $ids );

			// Un cambio editorial de verdad: el precio efectivo de todos se mueve.
			$this->precio_sobreescrito = true;
			$this->precio_efectivo     = 7990.0;
			$this->ensuciar( $ids );
			$this->reiniciar_eventos();

			$this->sut->handle_live_pump();

			self::assertSame( 1, $this->contar( 'generation' ), 'Una publicación por LOTE, no por producto.' );
			self::assertSame( 25, $this->contar( 'ack' ) );
			self::assertSame( array(), $this->pendientes() );
			self::assertSame( '7990.00', $this->wpdb->docs[5001]['price_effective'], 'El precio nuevo sí se materializa.' );
		}

		/**
		 * @testdox 60 claims con cambios gastan una publicación por lote (3), no 60.
		 */
		public function test_sesenta_claims_gastan_una_publicacion_por_lote(): void {
			$ids = range( 6001, 6060 );
			$this->publicados = $ids;
			$this->ensuciar( $ids );
			$this->reiniciar_eventos();

			// Tres invocaciones: 25 + 25 + 10, como las encadena `wake_live_work()`.
			$this->sut->handle_live_pump();
			$this->sut->handle_live_pump();
			$this->sut->handle_live_pump();

			self::assertSame( 3, $this->contar( 'generation' ), 'Una generación por invocación del pump.' );
			self::assertSame( 60, $this->contar( 'ack' ) );
			self::assertSame( array(), $this->pendientes() );
			self::assertCount( 60, $this->wpdb->docs );
		}

		/**
		 * @testdox La publicación va DESPUÉS de todas las TX1 y ANTES de todos los ACK.
		 *
		 * El orden es el protocolo: filas comprometidas primero (deuda durable),
		 * publicación después, y sólo entonces el ACK que cierra los claims.
		 */
		public function test_el_orden_publicacion_va_entre_los_commits_y_los_ack(): void {
			$ids = range( 7001, 7003 );
			$this->publicados = $ids;
			$this->ensuciar( $ids );
			$this->reiniciar_eventos();

			$this->sut->handle_live_pump();

			$generacion = array_search( 'generation', $this->wpdb->events, true );
			$escrituras = array_keys( $this->wpdb->events, 'live_write', true );
			$acks       = array_keys( $this->wpdb->events, 'ack', true );
			self::assertIsInt( $generacion );
			self::assertNotSame( array(), $escrituras );
			self::assertCount( 3, $acks );
			self::assertGreaterThan( (int) end( $escrituras ), $generacion, 'Ninguna publicación antes del último COMMIT de filas.' );
			self::assertLessThan( (int) reset( $acks ), $generacion, 'Ningún ACK antes del recibo físico.' );
		}

		/**
		 * @testdox Si la publicación falla, ningún claim del lote se ACKea.
		 *
		 * Las filas quedan comprometidas —eso es la deuda durable— y los claims
		 * vuelven a pending para reintentarse.
		 */
		public function test_una_publicacion_fallida_no_ackea_el_lote(): void {
			$ids = range( 8001, 8003 );
			$this->publicados          = $ids;
			$this->publicacion_persiste = false;
			$this->ensuciar( $ids );
			$this->reiniciar_eventos();

			$this->sut->handle_live_pump();

			self::assertSame( 0, $this->contar( 'ack' ), 'El ACK sólo ocurre tras el recibo físico.' );
			self::assertSame( 3, $this->contar( 'retry' ), 'Los tres claims vuelven a la cola.' );
			self::assertCount( 3, $this->wpdb->docs, 'Las filas comprometidas se conservan: son la deuda.' );
			self::assertSame( array( 8001, 8002, 8003 ), $this->pendientes() );
		}

		/**
		 * @testdox Un claim con un intento previo publica aunque sus filas ya estén.
		 *
		 * ES LA GARANTÍA DE DURABILIDAD, y el motivo por el que el atajo mira
		 * `attempts`. Secuencia: el lote escribe las filas (TX1 comprometida) y la
		 * publicación falla —lo mismo que un proceso que muere justo ahí—. En el
		 * reintento las filas VIVAS ya coinciden con lo que el claim escribiría;
		 * si eso se leyera como «no cambió nada», ese cambio no purgaría nunca la
		 * caché REST ni movería la generación, y el cliente seguiría viendo lo
		 * viejo sin que quedara deuda. El segundo intento publica.
		 */
		public function test_un_claim_reintentado_publica_aunque_las_filas_ya_esten(): void {
			$ids = range( 9001, 9003 );
			$this->publicados          = $ids;
			$this->publicacion_persiste = false;
			$this->ensuciar( $ids );
			$this->sut->handle_live_pump();
			self::assertCount( 3, $this->wpdb->docs, 'Las filas quedaron comprometidas sin publicar.' );

			foreach ( $ids as $id ) {
				self::assertSame( WorkRepository::STATUS_PENDING, $this->fila( $id )['status'] ?? null );
				self::assertSame(
					1,
					(int) ( $this->fila( $id )['attempts'] ?? 0 ),
					'El reintento NO borra el contador de intentos: es la única señal durable de que hubo una escritura sin publicar.'
				);
			}

			$this->publicacion_persiste = true;
			$this->reiniciar_eventos();
			$this->sut->handle_live_pump();

			self::assertSame( 1, $this->contar( 'generation' ), 'La escritura sin publicar SIEMPRE se publica al reintentar.' );
			self::assertSame( 3, $this->contar( 'ack' ) );
			foreach ( $ids as $id ) {
				self::assertSame(
					array( 1, 2 ),
					$this->arriendos_de( $id ),
					'El primer arriendo lee attempts = 1 (atajo permitido) y el reintento attempts = 2 (publicación obligada).'
				);
			}
		}

		/**
		 * @testdox Una baja de un producto que ya estaba ausente no publica ni borra.
		 */
		public function test_una_baja_de_producto_ausente_no_publica_ni_borra(): void {
			$this->ensuciar( array( 9101 ) ); // Nunca estuvo publicado ni indexado.
			$this->reiniciar_eventos();

			$this->sut->handle_live_pump();

			self::assertSame( WorkRepository::OUTCOME_ABSENT, $this->fila( 9101 )['outcome'] ?? null );
			self::assertSame( WorkRepository::STATUS_ACKED, $this->fila( 9101 )['status'] ?? null );
			self::assertSame( 0, $this->contar( 'generation' ) );
			self::assertSame( 0, $this->contar( 'live_delete' ), 'No hay nada que retirar: los tres DELETE sobran.' );
		}

		/**
		 * @testdox Una baja REAL retira las tres tablas y sí publica.
		 */
		public function test_una_baja_real_retira_las_tres_tablas_y_publica(): void {
			$this->sembrar_indice( array( 9201 ) );
			self::assertArrayHasKey( 9201, $this->wpdb->docs );

			$this->publicados = array(); // Deja de estar publicado: toca darlo de baja.
			$this->ensuciar( array( 9201 ) );
			$this->reiniciar_eventos();

			$this->sut->handle_live_pump();

			self::assertSame( WorkRepository::OUTCOME_ABSENT, $this->fila( 9201 )['outcome'] ?? null );
			self::assertSame( 3, $this->contar( 'live_delete' ) );
			self::assertSame( 1, $this->contar( 'generation' ), 'Retirar filas cambia lo servido: se publica.' );
			self::assertSame( array(), $this->wpdb->docs );
		}

		/**
		 * @testdox El camino síncrono de un producto concreto sigue funcionando.
		 *
		 * `process_live_product()` es el camino de `index_product_outcome()`/CLI y de las
		 * bajas conocidas: comparte TX1, publicación y ACK con el pump, como un
		 * lote de uno.
		 */
		public function test_el_camino_sincrono_de_un_producto_publica_y_ackea(): void {
			$this->publicados = array( 9301 );

			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->index_product_outcome( 9301 ) );
			self::assertSame( WorkRepository::STATUS_ACKED, $this->fila( 9301 )['status'] ?? null );
			self::assertSame( 1, $this->contar( 'generation' ) );

			// Y el segundo guardado sin cambios no vuelve a publicar.
			$this->reiniciar_eventos();
			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->index_product_outcome( 9301 ) );
			self::assertSame( 0, $this->contar( 'generation' ) );
			self::assertSame( 0, $this->contar( 'live_write' ) );
		}

		/**
		 * @testdox El presupuesto del lote cabe holgado dentro de una lease.
		 *
		 * Invariante estructural: el lote conserva sus claims LEASED hasta el ACK,
		 * así que el bucle MÁS el cierre tienen que caber dentro de un arriendo.
		 * Si alguien sube el presupuesto, los ACK del lote empiezan a morir stale.
		 */
		public function test_el_presupuesto_del_lote_cabe_en_una_lease(): void {
			$lote   = (int) ( new \ReflectionClassConstant( Indexer::class, 'LIVE_BATCH_SECONDS' ) )->getValue();
			$margen = (int) ( new \ReflectionClassConstant( Indexer::class, 'LIVE_BATCH_CLOSE_MARGIN' ) )->getValue();
			$lease  = (int) ( new \ReflectionClassConstant( Indexer::class, 'LEASE_SECONDS' ) )->getValue();

			self::assertGreaterThan( 0, $lote );
			self::assertGreaterThan( 0, $margen );
			self::assertLessThanOrEqual( $lease / 6, $lote, 'El bucle no puede comerse el arriendo.' );
			self::assertLessThanOrEqual(
				$lease,
				$lote + $margen,
				'Bucle + cierre tienen que caber dentro de UNA lease.'
			);
		}

		/**
		 * @testdox El deadline del lote usa un solo reloj y no interpreta fechas.
		 *
		 * [B1 de la re-revisión de 0.1.64] Antes se comparaba `microtime()` de PHP
		 * con `lease_until`, que escribe el reloj de MySQL: con PHP adelantado más
		 * de 45 s cada lote se cortaba en un claim y el drenaje volvía a publicar
		 * por producto. Ahora el deadline es función pura del instante previo al
		 * arriendo, así que tampoco depende de la zona horaria.
		 */
		public function test_el_deadline_usa_un_solo_reloj(): void {
			$lote   = (int) ( new \ReflectionClassConstant( Indexer::class, 'LIVE_BATCH_SECONDS' ) )->getValue();
			$margen = (int) ( new \ReflectionClassConstant( Indexer::class, 'LIVE_BATCH_CLOSE_MARGIN' ) )->getValue();
			$lease  = (int) ( new \ReflectionClassConstant( Indexer::class, 'LEASE_SECONDS' ) )->getValue();
			$antes  = 1000000.25; // Cualquier instante: la función no lee ningún reloj.

			self::assertSame( $antes + min( $lote, $lease - $margen ), $this->deadline_de( $antes ) );
			self::assertSame( $antes + $lote, $this->deadline_de( $antes ), 'Con los valores vigentes manda el presupuesto de reloj.' );

			$cuerpo = self::sin_comentarios( self::cuerpo_de( Indexer::class, 'live_batch_deadline' ) );
			foreach ( array( 'lease_until', 'strtotime', 'microtime', 'time(' ) as $segundo_reloj ) {
				self::assertStringNotContainsString( $segundo_reloj, $cuerpo, 'El deadline no puede volver a mezclar relojes.' );
			}
		}

		/**
		 * @testdox Con el deadline vencido, el lote cierra en su primer claim staged.
		 *
		 * La prueba de que el deadline se CONSULTA dentro del bucle y de desde qué
		 * instante se mide. El primer claim reclamado falla su TX1 y se suelta, así
		 * que el más viejo del lote es el segundo: el deadline se fija una sola vez,
		 * con el instante tomado DESPUÉS del claim que falló y ANTES del arriendo
		 * del claim que sí entra al lote.
		 */
		public function test_el_lote_cierra_en_el_primer_claim_staged(): void {
			$ids = range( 9501, 9503 );
			$this->publicados = $ids;
			$this->ensuciar( $ids );
			EditorialDiscountPublication::set_test_price_match_sequence( array( false, true, true, true, true, true ) );

			$instantes = array();
			\Patchwork\redefine(
				Indexer::class . '::live_batch_deadline',
				static function ( float $antes_del_claim ) use ( &$instantes ): float {
					$instantes[] = $antes_del_claim;
					return 0.0; // Ya vencido: cerrar en cuanto haya UN claim en el lote.
				}
			);

			$this->reiniciar_eventos();
			$this->sut->handle_live_pump();

			self::assertCount( 1, $instantes, 'El deadline se fija una vez, con el primer claim staged.' );
			self::assertCount( 2, $this->wpdb->arriendos_en, 'Dos arriendos: el que falló y el que entró al lote.' );
			self::assertGreaterThan( $this->wpdb->arriendos_en[0], $instantes[0], 'No se mide desde el claim que falló y se soltó.' );
			self::assertLessThanOrEqual( $this->wpdb->arriendos_en[1], $instantes[0], 'Se mide ANTES del arriendo: la cota es conservadora.' );
			self::assertCount( 1, $this->wpdb->docs, 'El lote cierra con su primer claim.' );
			self::assertSame( 1, $this->contar( 'generation' ), 'Y se publica igual.' );
			self::assertSame( array( 9502, 9503 ), $this->pendientes(), 'El resto queda para la vuelta siguiente.' );
		}

		/**
		 * @testdox Las columnas comparadas son exactamente las escritas, menos las declaradas.
		 *
		 * CANDADO DE FUENTE [M1 de la revisión de 0.1.64]. Nada ataba las columnas
		 * del INSERT de `persist_rows()` a las tres listas literales de
		 * `live_rows_match()`. Hoy cuadran; el día que alguien añada una columna
		 * al documento y olvide el SELECT, la comparación diría «iguales» sobre
		 * una columna que no mira — un falso no-op silencioso y PERMANENTE: esa
		 * columna nunca más se publicaría por el drenaje. Se exige la igualdad en
		 * los dos sentidos, así que también cae una columna comparada que ya no se
		 * escribe. Las excepciones van declaradas aquí, cada una con su motivo.
		 */
		public function test_las_columnas_comparadas_son_las_escritas_menos_las_declaradas(): void {
			$excepciones = array(
				'docs'   => array( 'indexed_at' ), // Sello, no contenido servido: lo refresca refresh_indexed_at().
				'tokens' => array( 'post_id' ),    // Es la clave del WHERE de la lectura.
				'facets' => array( 'post_id' ),    // Ídem.
			);

			$escritas   = self::columnas_escritas();
			$comparadas = self::columnas_comparadas();
			foreach ( $excepciones as $tabla => $fuera ) {
				self::assertNotEmpty( $escritas[ $tabla ] ?? array(), "No se encontraron las columnas escritas de {$tabla}: el candado leería nada." );
				foreach ( $fuera as $columna ) {
					self::assertContains( $columna, $escritas[ $tabla ], "La excepción {$columna} de {$tabla} ya no se escribe: retírala del candado." );
				}
				$esperadas = array_values( array_diff( $escritas[ $tabla ], $fuera ) );
				$actuales  = $comparadas[ $tabla ] ?? array();
				sort( $esperadas, SORT_STRING );
				sort( $actuales, SORT_STRING );
				self::assertSame(
					$esperadas,
					$actuales,
					"live_rows_match() tiene que comparar TODAS las columnas que persist_rows() escribe en {$tabla} (salvo las excepciones declaradas)."
				);
			}
		}

		/**
		 * @testdox Cada lote cerrado reconcilia UNA vez, también si no cambió nada.
		 *
		 * DECISIÓN FIJADA [M2 de la revisión de 0.1.64]. Un lote 100 % sin
		 * cambios llama igual a `reconcile_now()`: es lo que re-sella el recibo
		 * editorial del cambio que disparó el drenaje. Lo que desaparece es el
		 * reconcile POR PRODUCTO. Sin el espía, esta prueba pasaba sólo porque el
		 * runtime editorial no está registrado y `reconcile_now()` es un no-op.
		 */
		public function test_cada_lote_cerrado_reconcilia_una_sola_vez(): void {
			$ids = range( 9701, 9703 );
			$this->sembrar_indice( $ids );

			$reconciles = 0;
			\Patchwork\redefine(
				EditorialDiscountPublication::class . '::reconcile_now',
				static function () use ( &$reconciles ): bool {
					++$reconciles;
					return true;
				}
			);

			$this->ensuciar( $ids );
			$this->reiniciar_eventos();
			$this->sut->handle_live_pump();

			self::assertSame( 0, $this->contar( 'generation' ), 'Precondición: lote sin cambios.' );
			self::assertSame( 1, $reconciles, 'Un lote cerrado = un reconcile, con o sin cambios.' );

			// Sin claims no hay lote que cerrar: tampoco hay reconcile.
			$this->sut->handle_live_pump();
			self::assertSame( 1, $reconciles, 'Una vuelta vacía del pump no reconcilia.' );
		}

		/**
		 * @dataProvider columnasQueSeMueven
		 * @testdox Mover UNA sola columna viva obliga a reescribir y publicar ($caso)
		 *
		 * COBERTURA POR COLUMNA [M4 de la revisión de 0.1.64]. Se muta la fila
		 * VIVA —no la fuente— porque lo que se prueba es la sensibilidad de la
		 * comparación: si una columna no participara, la mutación pasaría por
		 * «iguales» y la publicación no ocurriría. Incluye los bordes que una
		 * comparación laxa (`==`, collation, PAD SPACE) daría por iguales.
		 */
		public function test_mover_una_sola_columna_publica( string $caso ): void {
			$id = 4200;
			if ( 'docs.tomo_nulo_vs_cero' === $caso ) {
				// Título sin número final: el builder calcula tomo NULL.
				$this->productos[ $id ] = new class( $id ) extends \WC_Product {
					public function get_name(): string {
						return 'Manga';
					}
				};
			}
			$this->sembrar_indice( array( $id ) );
			$this->mutar_fila_viva( $caso, $id );

			$this->ensuciar( array( $id ) );
			$this->reiniciar_eventos();
			$this->sut->handle_live_pump();

			self::assertSame( 1, $this->contar( 'generation' ), "La columna del caso «{$caso}» no participa de la comparación." );
			self::assertGreaterThan( 0, $this->contar( 'live_write' ), 'Y la fila se reescribe con lo que dice la fuente.' );
		}

		/**
		 * Caso de mutación → tabla y columna que mueve (null = fila entera).
		 *
		 * Es la fuente del proveedor y del candado de cobertura: añadir una
		 * columna a la comparación sin su caso aquí pone en rojo
		 * `test_cada_columna_comparada_tiene_su_caso_de_mutacion()`.
		 */
		private const CASOS = array(
			'docs.post_id'                  => array( 'docs', 'post_id' ),
			'docs.title_mayusculas'         => array( 'docs', 'title' ),
			'docs.title_espacio_final'      => array( 'docs', 'title' ),
			'docs.title_norm'               => array( 'docs', 'title_norm' ),
			'docs.serie_norm'               => array( 'docs', 'serie_norm' ),
			'docs.autores_norm'             => array( 'docs', 'autores_norm' ),
			'docs.sku'                      => array( 'docs', 'sku' ),
			'docs.sku_norm'                 => array( 'docs', 'sku_norm' ),
			'docs.tomo_otro'                => array( 'docs', 'tomo' ),
			'docs.tomo_nulo_vs_cero'        => array( 'docs', 'tomo' ),
			'docs.stock_rank'               => array( 'docs', 'stock_rank' ),
			'docs.menu_order'               => array( 'docs', 'menu_order' ),
			'docs.price_effective_cero'     => array( 'docs', 'price_effective' ),
			'docs.visible'                  => array( 'docs', 'visible' ),
			'docs.url'                      => array( 'docs', 'url' ),
			'docs.thumb_url'                => array( 'docs', 'thumb_url' ),
			'docs.price_html'               => array( 'docs', 'price_html' ),
			'tokens.token'                  => array( 'tokens', 'token' ),
			'tokens.field_distinto'         => array( 'tokens', 'field' ),
			'tokens.sobra'                  => array( 'tokens', null ),
			'tokens.falta'                  => array( 'tokens', null ),
			'facets.facet'                  => array( 'facets', 'facet' ),
			'facets.value'                  => array( 'facets', 'value' ),
			'facets.label'                  => array( 'facets', 'label' ),
			'facets.value_num'              => array( 'facets', 'value_num' ),
			'facets.term_id_cero_vs_nulo'   => array( 'facets', 'term_id' ),
			'facets.parent_id_cero_vs_nulo' => array( 'facets', 'parent_id' ),
			'facets.sobra'                  => array( 'facets', null ),
			'facets.falta'                  => array( 'facets', null ),
		);

		/** @return array<string, array{0: string}> */
		public static function columnasQueSeMueven(): array {
			$casos = array_keys( self::CASOS );

			return array_combine( $casos, array_map( static fn( string $caso ): array => array( $caso ), $casos ) );
		}

		/**
		 * @testdox Cada columna comparada tiene su caso de mutación, y ningún caso sobra.
		 *
		 * [B2 de la re-revisión de 0.1.64] El candado de columnas ata el INSERT al
		 * SELECT, pero no garantiza que la comparación USE cada columna leída: una
		 * columna nueva en el INSERT y el SELECT y olvidada en `live_rows_match()`
		 * lo pasaba, y también el proveedor, que era una lista fija. Con este
		 * candado la cadena queda cerrada: INSERT ⇒ SELECT (candado de columnas)
		 * ⇒ caso de mutación (éste) ⇒ la comparación la detecta (el proveedor).
		 */
		public function test_cada_columna_comparada_tiene_su_caso_de_mutacion(): void {
			$comparadas = self::columnas_comparadas();
			$cubiertas  = array();
			foreach ( self::CASOS as $caso => $destino ) {
				if ( null !== $destino[1] ) {
					$cubiertas[ $destino[0] ][ $destino[1] ] = $caso;
				}
			}

			foreach ( $comparadas as $tabla => $columnas ) {
				foreach ( $columnas as $columna ) {
					self::assertArrayHasKey(
						$columna,
						$cubiertas[ $tabla ] ?? array(),
						"La columna {$tabla}.{$columna} se compara pero ningún caso de mutación la mueve: añade su caso a CASOS."
					);
				}
			}
			foreach ( $cubiertas as $tabla => $columnas ) {
				foreach ( $columnas as $columna => $caso ) {
					self::assertContains( $columna, $comparadas[ $tabla ] ?? array(), "El caso «{$caso}» mueve una columna que ya no se compara." );
				}
			}
			$tablas = array_keys( $comparadas );
			sort( $tablas, SORT_STRING );
			self::assertSame( array( 'docs', 'facets', 'tokens' ), $tablas, 'Las tres tablas vivas tienen que estar en el candado.' );
		}

		/**
		 * @testdox Un precio 0 guardado como '0.00' NO es un cambio.
		 *
		 * El borde opuesto: `DECIMAL(12,2)` devuelve '0.00' y el builder produce
		 * 0.0. Una comparación ingenua de cadenas vería una diferencia y publicaría
		 * en cada drenaje un producto que no cambió.
		 */
		public function test_un_precio_cero_no_es_un_cambio(): void {
			$this->precio_sobreescrito = true;
			$this->precio_efectivo     = 0.0;
			$this->sembrar_indice( array( 4300 ) );
			self::assertSame( '0.00', $this->wpdb->docs[4300]['price_effective'], 'Precondición: la columna guarda la forma de MySQL.' );

			$this->ensuciar( array( 4300 ) );
			$this->reiniciar_eventos();
			$this->sut->handle_live_pump();

			self::assertSame( 0, $this->contar( 'generation' ) );
			self::assertSame( 0, $this->contar( 'live_write' ) );
		}

		/**
		 * @testdox Proceso muerto entre TX1 y publicación: lease vencida, recuperación y re-claim que publica.
		 *
		 * EL PELIGRO REAL [M5 de la revisión de 0.1.64], de punta a punta y con
		 * el recuperador de producción. El proceso compromete las filas (TX1) y
		 * muere sin publicar ni reintentar: el claim queda LEASED. Pasa el tiempo,
		 * `recover_expired()` lo devuelve a la cola CONSERVANDO `attempts`, y el
		 * siguiente arriendo sale con `attempts = 2`: aunque las filas vivas ya
		 * coinciden con la fuente, el atajo queda prohibido y la escritura
		 * huérfana se publica.
		 */
		public function test_un_proceso_muerto_se_recupera_y_publica(): void {
			$this->publicados = array( 9401 );
			$this->ensuciar( array( 9401 ) );

			$this->comprometer_sin_cerrar( 9401 );
			self::assertCount( 1, $this->wpdb->docs, 'TX1 comprometida: las filas ya están en la tabla viva.' );
			self::assertSame( 0, $this->contar( 'generation' ), 'Y nadie las publicó.' );
			self::assertSame( WorkRepository::STATUS_LEASED, $this->fila( 9401 )['status'] ?? null, 'El claim quedó arrendado por un proceso que ya no existe.' );

			$this->vencer_arriendos();
			self::assertSame( 1, $this->work->recover_expired( WorkRepository::SCOPE_LIVE ) );
			self::assertSame( WorkRepository::STATUS_PENDING, $this->fila( 9401 )['status'] ?? null );
			self::assertSame( 1, (int) ( $this->fila( 9401 )['attempts'] ?? 0 ), 'La recuperación NO borra el contador.' );

			$this->reiniciar_eventos();
			$this->sut->handle_live_pump();

			self::assertSame(
				array( 1, 2 ),
				$this->arriendos_de( 9401 ),
				'El claim del proceso muerto leyó attempts = 1; el re-claim lee attempts = 2.'
			);
			self::assertSame( 1, $this->contar( 'generation' ), 'La escritura huérfana se publica aunque las filas coincidan.' );
			self::assertSame( WorkRepository::STATUS_ACKED, $this->fila( 9401 )['status'] ?? null );
		}

		/**
		 * @testdox La palanca del operador reescribe y publica aunque nada cambie.
		 *
		 * [A2 de la revisión de 0.1.64] `wp akb-search reindex <id>` se usa justo
		 * cuando el índice está raro. Sin la palanca, respondería «reindexado»
		 * sin escribir ni publicar nada.
		 */
		public function test_la_palanca_del_operador_publica_aunque_nada_cambie(): void {
			$this->sembrar_indice( array( 9601 ) );

			$this->reiniciar_eventos();
			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->index_product_outcome( 9601 ) );
			self::assertSame( 0, $this->contar( 'generation' ), 'Control: sin palanca, un claim idéntico no publica.' );

			$this->reiniciar_eventos();
			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->index_product_outcome( 9601, true ) );
			self::assertSame( 1, $this->contar( 'generation' ), 'Con la palanca, publica.' );
			self::assertGreaterThan( 0, $this->contar( 'live_write' ), 'Y reescribe las filas.' );
		}

		/**
		 * @testdox La CLI de reindex es la que acciona la palanca.
		 *
		 * Candado de fuente: la orden humana es el ÚNICO llamador que fuerza, y es
		 * lo que hace verdad el «Producto N reindexado» que imprime.
		 */
		public function test_la_cli_de_reindex_fuerza_la_publicacion(): void {
			$cuerpo = self::sin_comentarios( self::cuerpo_de( \Akibara\Search\Cli::class, 'cmd_reindex', dirname( __DIR__ ) . '/src/Cli.php' ) );

			self::assertStringContainsString( 'index_product_outcome( $product_id, true )', $cuerpo );
		}

		/**
		 * @testdox La CLI no promete una reparación que otro proceso no hará.
		 *
		 * [B3 de la re-revisión de 0.1.64] `RESULT_PENDING` en `reindex` es «otro
		 * proceso tiene el producto» (el pump lo arrendó o el fence está ocupado).
		 * El reintento de ese otro proceso no fuerza la publicación, así que
		 * «el worker lo reintentará» prometía algo que puede no ocurrir. El
		 * mensaje tiene que pedir que se repita la orden.
		 */
		public function test_la_cli_no_promete_una_reparacion_que_no_hara(): void {
			$cuerpo = self::cuerpo_de( \Akibara\Search\Cli::class, 'cmd_reindex', dirname( __DIR__ ) . '/src/Cli.php' );
			$cuerpo = (string) preg_replace( '#^\s*//[^\n]*$#m', '', $cuerpo );

			self::assertSame( 1, preg_match( '/Indexer::RESULT_PENDING === \$outcome \)\s*\{(.*?)\}/s', $cuerpo, $rama ), 'No se encontró la rama PENDING de reindex.' );
			self::assertStringContainsString( 'vuelve a correr la orden', $rama[1] );
			self::assertStringContainsString( 'no se reparó', $rama[1] );
			self::assertStringNotContainsString( 'reintentar', $rama[1], 'Ningún «reintentará»: ese reintento no fuerza la publicación.' );
		}

		/**
		 * @param float $antes_del_claim Instante previo al arriendo del primer claim staged.
		 * @return float
		 */
		private function deadline_de( float $antes_del_claim ): float {
			$metodo = new \ReflectionMethod( Indexer::class, 'live_batch_deadline' );
			$metodo->setAccessible( true );

			return (float) $metodo->invoke( null, $antes_del_claim );
		}

		/**
		 * Deja el índice vivo poblado con estos productos (primer drenaje real).
		 *
		 * @param array<int, int> $ids Productos.
		 * @return void
		 */
		private function sembrar_indice( array $ids ): void {
			$this->publicados = $ids;
			$this->ensuciar( $ids );
			for ( $vuelta = 0; $vuelta < 10 && array() !== $this->pendientes(); $vuelta++ ) {
				$this->sut->handle_live_pump();
			}
			self::assertCount( count( $ids ), $this->wpdb->docs, 'La siembra tiene que dejar el índice completo.' );
		}

		/**
		 * @param array<int, int> $ids Productos.
		 * @return void
		 */
		private function ensuciar( array $ids ): void {
			self::assertTrue( $this->work->mark_live_dirty_many( $ids ) );
		}

		/** @return array<int, int> Productos con deuda viva sin cerrar. */
		private function pendientes(): array {
			$ids = array();
			foreach ( $this->wpdb->work as $row ) {
				if ( WorkRepository::STATUS_ACKED !== $row['status'] ) {
					$ids[] = (int) $row['product_id'];
				}
			}
			sort( $ids, SORT_NUMERIC );

			return $ids;
		}

		/**
		 * @param int $product_id Producto.
		 * @return array<string, mixed>|null
		 */
		private function fila( int $product_id ): ?array {
			foreach ( $this->wpdb->work as $row ) {
				if ( $product_id === (int) $row['product_id'] ) {
					return $row;
				}
			}

			return null;
		}

		/**
		 * `attempts` REAL con el que salió cada arriendo del producto — el mismo
		 * valor que `live_claim_is_a_noop()` consulta. El ACK lo devuelve a 0, así
		 * que leerlo de la fila después del drenaje no probaría nada.
		 *
		 * @param int $product_id Producto.
		 * @return array<int, int>
		 */
		private function arriendos_de( int $product_id ): array {
			return $this->wpdb->arriendos[ $product_id ] ?? array();
		}

		/**
		 * Limpia el contador de eventos entre fases de una prueba.
		 *
		 * Ni las sentencias ni los arriendos se borran: el rastro de `attempts` de
		 * toda la prueba es justo lo que fija la durabilidad.
		 *
		 * @return void
		 */
		private function reiniciar_eventos(): void {
			$this->wpdb->events = array();
		}

		/**
		 * Muta UNA columna de la fila viva del producto.
		 *
		 * @param string $caso       Caso del proveedor.
		 * @param int    $product_id Producto ya sembrado.
		 * @return void
		 */
		private function mutar_fila_viva( string $caso, int $product_id ): void {
			$docs = array(
				// La clave del WHERE no puede venir cambiada de MySQL; el caso prueba
				// que la comprobación defensiva del `post_id` existe y muerde.
				'docs.post_id'              => '99999',
				'docs.title_mayusculas'     => 'MANGA 01',
				'docs.title_espacio_final'  => 'Manga 01 ',
				'docs.title_norm'           => 'manga 02',
				'docs.serie_norm'           => 'naruto',
				'docs.autores_norm'         => 'kishimoto',
				'docs.sku'                  => '9789878000000',
				'docs.sku_norm'             => '9789878000000',
				'docs.tomo_otro'            => '2',
				'docs.tomo_nulo_vs_cero'    => '0',
				'docs.stock_rank'           => '2',
				'docs.menu_order'           => '7',
				'docs.price_effective_cero' => '0.00',
				'docs.visible'              => '0',
				'docs.url'                  => 'https://akibara.cl/producto/otro',
				'docs.thumb_url'            => 'https://akibara.cl/otra.jpg',
				'docs.price_html'           => '$7.990',
			);
			if ( isset( $docs[ $caso ] ) ) {
				$columna = (string) self::CASOS[ $caso ][1];
				$valor   = $docs[ $caso ];
				self::assertNotSame( $valor, $this->wpdb->docs[ $product_id ][ $columna ] ?? null, "Precondición de «{$caso}»: la mutación tiene que cambiar el valor." );
				$this->wpdb->docs[ $product_id ][ $columna ] = $valor;
				return;
			}

			self::assertNotEmpty( $this->wpdb->tokens[ $product_id ] ?? array(), 'Precondición: el producto tiene tokens.' );
			self::assertNotEmpty( $this->wpdb->facets[ $product_id ] ?? array(), 'Precondición: el producto tiene facetas.' );
			switch ( $caso ) {
				case 'tokens.sobra':
					$this->wpdb->tokens[ $product_id ][] = array(
						'token' => 'extra',
						'field' => '1',
					);
					return;
				case 'tokens.token':
					$this->wpdb->tokens[ $product_id ][0]['token'] = 'otro-token';
					return;
				case 'tokens.falta':
					array_pop( $this->wpdb->tokens[ $product_id ] );
					return;
				case 'tokens.field_distinto':
					$this->wpdb->tokens[ $product_id ][0]['field'] = '9' === $this->wpdb->tokens[ $product_id ][0]['field'] ? '8' : '9';
					return;
				case 'facets.facet':
					$this->wpdb->facets[ $product_id ][0]['facet'] = 'otra-faceta';
					return;
				case 'facets.value':
					$this->wpdb->facets[ $product_id ][0]['value'] = 'otro-valor';
					return;
				case 'facets.label':
					$this->wpdb->facets[ $product_id ][0]['label'] = 'Otra etiqueta';
					return;
				case 'facets.value_num':
					$this->wpdb->facets[ $product_id ][0]['value_num'] = '1.00' === $this->wpdb->facets[ $product_id ][0]['value_num'] ? '2.00' : '1.00';
					return;
				case 'facets.term_id_cero_vs_nulo':
					self::assertNull( $this->wpdb->facets[ $product_id ][0]['term_id'], 'Precondición: la faceta no tiene término.' );
					$this->wpdb->facets[ $product_id ][0]['term_id'] = '0';
					return;
				case 'facets.parent_id_cero_vs_nulo':
					self::assertNull( $this->wpdb->facets[ $product_id ][0]['parent_id'], 'Precondición: la faceta no tiene padre.' );
					$this->wpdb->facets[ $product_id ][0]['parent_id'] = '0';
					return;
				case 'facets.sobra':
					$this->wpdb->facets[ $product_id ][] = array(
						'facet'     => 'novedad',
						'value'     => '2026',
						'label'     => '',
						'value_num' => null,
						'term_id'   => null,
						'parent_id' => null,
					);
					return;
				case 'facets.falta':
					array_pop( $this->wpdb->facets[ $product_id ] );
					return;
			}

			self::fail( "Caso de mutación desconocido: {$caso}" );
		}

		/**
		 * TX1 real de un claim y NADA más: así muere un proceso entre el COMMIT de
		 * filas y la publicación.
		 *
		 * @param int $product_id Producto con deuda.
		 * @return void
		 */
		private function comprometer_sin_cerrar( int $product_id ): void {
			$claim = $this->work->claim_product( WorkRepository::SCOPE_LIVE, '', $product_id, 60 );
			self::assertIsArray( $claim );

			$metodo = new \ReflectionMethod( Indexer::class, 'stage_live_claim' );
			$metodo->setAccessible( true );
			self::assertIsArray( $metodo->invoke( $this->sut, $claim ), 'La TX1 del proceso que va a morir se compromete.' );
		}

		/** @return void Deja vencidos todos los arriendos vivos, como si hubiera pasado el tiempo. */
		private function vencer_arriendos(): void {
			foreach ( $this->wpdb->work as $id => $row ) {
				if ( WorkRepository::STATUS_LEASED === $row['status'] ) {
					$this->wpdb->work[ $id ]['lease_until'] = gmdate( 'Y-m-d H:i:s', time() - 1 );
				}
			}
		}

		/**
		 * Columnas que `persist_rows()` escribe en cada tabla, leídas de la fuente.
		 *
		 * @return array<string, array<int, string>>
		 */
		private static function columnas_escritas(): array {
			$cuerpo   = self::sin_comentarios( self::cuerpo_de( Indexer::class, 'persist_rows' ) );
			$columnas = array();
			if ( 1 === preg_match( '/INSERT INTO \{\$docs\}\s*\(([^)]*)\)/s', $cuerpo, $docs ) ) {
				$columnas['docs'] = array_map( 'trim', explode( ',', $docs[1] ) );
			}
			preg_match_all( '/bulk_insert\(\s*\$(tokens|facets),\s*array\(([^)]*)\)/s', $cuerpo, $bulks, PREG_SET_ORDER );
			foreach ( $bulks as $bulk ) {
				$columnas[ $bulk[1] ] = array_map( static fn( string $c ): string => trim( $c, " \t\n'" ), explode( ',', $bulk[2] ) );
			}

			return $columnas;
		}

		/**
		 * Columnas que `live_rows_match()` compara en cada tabla, leídas de la fuente.
		 *
		 * @return array<string, array<int, string>>
		 */
		private static function columnas_comparadas(): array {
			$cuerpo = self::sin_comentarios( self::cuerpo_de( Indexer::class, 'live_rows_match' ) );
			preg_match_all( "/live_rows\\(\\s*Install::(docs|tokens|facets)_table\\(\\),\\s*'([^']+)'/", $cuerpo, $lecturas, PREG_SET_ORDER );
			$columnas = array();
			foreach ( $lecturas as $lectura ) {
				$columnas[ $lectura[1] ] = array_map( 'trim', explode( ',', $lectura[2] ) );
			}

			return $columnas;
		}

		/**
		 * @param string      $clase   Clase.
		 * @param string      $metodo  Método.
		 * @param string|null $fichero Fichero, si la clase no está cargada.
		 * @return string Sólo el cuerpo del método.
		 */
		private static function cuerpo_de( string $clase, string $metodo, ?string $fichero = null ): string {
			if ( null !== $fichero ) {
				$fuente = (string) file_get_contents( $fichero );
				if ( 1 !== preg_match( '/function\s+' . preg_quote( $metodo, '/' ) . '\s*\(.*?\n\t\}\n/s', $fuente, $cuerpo ) ) {
					self::fail( "No se encontró {$clase}::{$metodo}() en la fuente." );
				}
				return $cuerpo[0];
			}
			$reflexion = new \ReflectionMethod( $clase, $metodo );
			$lineas    = (array) file( (string) $reflexion->getFileName() );

			return implode( '', array_slice( $lineas, $reflexion->getStartLine() - 1, $reflexion->getEndLine() - $reflexion->getStartLine() + 1 ) );
		}

		/**
		 * @param string $codigo Fuente PHP.
		 * @return string Sin comentarios de bloque ni de línea (los candados de fuente los quitan ANTES de buscar).
		 */
		private static function sin_comentarios( string $codigo ): string {
			return (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $codigo );
		}

		/**
		 * @param string $event Evento.
		 * @return int
		 */
		private function contar( string $event ): int {
			return count( array_filter( $this->wpdb->events, static fn( string $value ): bool => $event === $value ) );
		}
	}
}
