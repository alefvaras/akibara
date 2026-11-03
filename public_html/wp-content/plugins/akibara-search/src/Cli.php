<?php
/**
 * Cli — comandos WP-CLI del índice de búsqueda.
 *
 *   wp akb-search rebuild [--async]   Rebuild completo (sync por defecto).
 *   wp akb-search status              Estado del índice y del último rebuild.
 *   wp akb-search reindex <id>        Reindexa un producto puntual.
 *   wp akb-search reconcile           Corre el reconciler ahora.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class Cli {

	/** @var Indexer */
	private Indexer $indexer;

	/** @var RebuildJob */
	private RebuildJob $rebuild;

	/** @var Reconciler */
	private Reconciler $reconciler;

	/**
	 * @param Indexer    $indexer    Indexer.
	 * @param RebuildJob $rebuild    Rebuild job.
	 * @param Reconciler $reconciler Reconciler.
	 */
	public function __construct( Indexer $indexer, RebuildJob $rebuild, Reconciler $reconciler ) {
		$this->indexer    = $indexer;
		$this->rebuild    = $rebuild;
		$this->reconciler = $reconciler;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'akb-search rebuild', array( $this, 'cmd_rebuild' ) );
		\WP_CLI::add_command( 'akb-search status', array( $this, 'cmd_status' ) );
		\WP_CLI::add_command( 'akb-search reindex', array( $this, 'cmd_reindex' ) );
		\WP_CLI::add_command( 'akb-search reconcile', array( $this, 'cmd_reconcile' ) );
		\WP_CLI::add_command( 'akb-search rescue', array( $this, 'cmd_rescue' ) );
	}

	/**
	 * Rebuild completo del índice.
	 *
	 * @param array $args       Posicionales (sin uso).
	 * @param array $assoc_args --async: encolar por Action Scheduler en vez de correr inline.
	 * @return void
	 */
	public function cmd_rebuild( array $args = array(), array $assoc_args = array() ): void {
		Install::maybe_install();

		if ( isset( $assoc_args['async'] ) ) {
			$scheduled = RebuildJob::schedule();
			if ( $scheduled ) {
				AuditLog::record( 'rebuild_encolado', 'Encolado por CLI (wp akb-search rebuild --async)' );
			}
			\WP_CLI::log( $scheduled ? 'Rebuild encolado en Action Scheduler.' : 'Ya hay un rebuild en curso — no se encoló otro.' );
			return;
		}

		AuditLog::record( 'rebuild_encolado', 'Rebuild síncrono por CLI (wp akb-search rebuild)' );
		$result = $this->rebuild->run_sync();
		if ( null === $result ) {
			\WP_CLI::error( 'Ya hay un rebuild en curso (option akb_search_rebuild_state).' );
			return;
		}

		// Contrato de error (plan migraciones B.7): un swap fallido imprimía
		// "Success: 0 docs" con exit 0 y el runbook del cutover confiaba en
		// ese exit code. WP_CLI::error corta con exit != 0.
		$failure = self::rebuild_failure_message( $result, RebuildJob::eligible_product_count() );
		if ( null !== $failure ) {
			\WP_CLI::error( $failure );
			return;
		}

		\WP_CLI::success( sprintf( 'Índice reconstruido: %d docs en %.2fs.', $result['docs'], $result['seconds'] ) );
	}

	/**
	 * Decide si el resultado de run_sync() es un fallo reportable. Pura y
	 * estática para poder testearla sin WP_CLI (RebuildJob es final: no se
	 * mockea — patrón de la casa: construir reales + helpers puros).
	 *
	 * @param array<string, mixed> $result   Resumen de run_sync().
	 * @param int                  $eligible Productos elegibles para el índice.
	 * @return string|null Mensaje de error, o null si el rebuild fue sano.
	 */
	public static function rebuild_failure_message( array $result, int $eligible ): ?string {
		$error = (string) ( $result['error'] ?? '' );
		if ( '' !== $error ) {
			return sprintf( 'Rebuild FALLÓ (%s): el índice anterior sigue sirviendo. Ver `wp akb-search status`.', $error );
		}

		$docs = (int) ( $result['docs'] ?? 0 );

		if ( 0 === $docs && $eligible > 0 ) {
			return sprintf( 'Rebuild terminó con 0 docs pero hay %d productos elegibles — revisar `wp akb-search status`.', $eligible );
		}

		// Validación de cobertura estricta del rebuild: se verifica contra el
		// compromiso inicial del proceso ('expected'). Si se comparara contra el
		// conteo dinámico de 'eligible' al terminar, publicaciones concurrentes
		// durante la ventana de indexación (que ya quedan registradas en cola de
		// eventos) marcarían falsamente el rebuild como incompleto. '$eligible'
		// opera como resguardo si 'expected' no viene provisto.
		$esperado = (int) ( $result['expected'] ?? 0 );
		$minimo   = $esperado > 0 ? $esperado : $eligible;
		if ( $minimo > 0 && $docs < $minimo ) {
			return sprintf(
				'Rebuild incompleto: %d docs de los %d que tenía que indexar — el índice publicado tiene agujeros, revisar `wp akb-search status`.',
				$docs,
				$minimo
			);
		}

		return null;
	}

	/**
	 * Rescata un buscador atascado y arranca un rebuild limpio.
	 *
	 * Para cuando `wp akb-search rebuild` contesta «Ya hay un rebuild en curso» y
	 * el estado no avanza, o el panel muestra un bloqueo huérfano. El orden de
	 * los pasos —sombra primero, estado después— vive en
	 * {@see RebuildJob::rescue()} y no se puede invertir. Si hay tablas `_old` o
	 * un rebuild que sí avanza, no toca nada y lo dice.
	 *
	 * ## EXAMPLES
	 *
	 *     wp akb-search rescue
	 *
	 * @return void
	 */
	public function cmd_rescue(): void {
		$rescate = RebuildJob::rescue();
		if ( ! $rescate['ok'] ) {
			\WP_CLI::error( sprintf( '[%s] %s', $rescate['codigo'], $rescate['mensaje'] ) );
			return;
		}

		\WP_CLI::log( $rescate['mensaje'] );
		$this->cmd_rebuild();
	}

	/**
	 * Estado del índice.
	 *
	 * @return void
	 */
	public function cmd_status(): void {
		global $wpdb;

		foreach ( array(
			'docs'   => Install::docs_table(),
			'tokens' => Install::tokens_table(),
			'facets' => Install::facets_table(),
			'qlog'   => Install::qlog_table(),
		) as $label => $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} recorre las cuatro tablas propias del plugin (docs, tokens, facetas y qlog, todas $wpdb->prefix + literal fijo) y el COUNT no lleva ningún valor que preparar.
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			\WP_CLI::log( sprintf( '%-7s %s filas  (%s)', $label, null === $count ? 'SIN TABLA' : $count, $table ) );
		}

		$last = get_option( RebuildJob::OPTION_LAST );
		if ( is_array( $last ) ) {
			\WP_CLI::log( 'último rebuild: ' . (string) wp_json_encode( $last ) );
		}

		$reconcile = get_option( Reconciler::OPTION_LAST );
		if ( is_array( $reconcile ) ) {
			\WP_CLI::log( 'último reconcile: ' . (string) wp_json_encode( $reconcile ) );
		}
	}

	/**
	 * Reindexa un producto.
	 *
	 * @param array $args [0] = product_id.
	 * @return void
	 */
	public function cmd_reindex( array $args ): void {
		$product_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $product_id <= 0 ) {
			\WP_CLI::error( 'Uso: wp akb-search reindex <product_id>' );
			return;
		}

		// `true` = forzar la publicación. Desde 0.1.64 un claim cuyas filas ya
		// coinciden con el índice no reescribe ni publica generación, que es lo
		// correcto para el drenaje automático. Pero esta orden la escribe una
		// PERSONA, y casi siempre porque el índice está raro (recibo de purga
		// perdido, entrada REST rancia): «ya estaba igual» sería justo la
		// respuesta que no sirve. La palanca de reparación reescribe y publica.
		$outcome = $this->indexer->index_product_outcome( $product_id, true );
		if ( WorkRepository::OUTCOME_INDEXED === $outcome ) {
			\WP_CLI::success( sprintf( 'Producto %d reindexado.', $product_id ) );
		} elseif ( WorkRepository::OUTCOME_ABSENT === $outcome ) {
			\WP_CLI::warning( sprintf( 'Producto %d removido del índice (no publicado o inexistente).', $product_id ) );
		} elseif ( Indexer::RESULT_PENDING === $outcome ) {
			// PENDING aquí es «otro proceso tiene el producto»: el pump lo tiene
			// arrendado o el fence de escritura está ocupado. El reintento de ese
			// otro proceso NO fuerza la publicación (sólo esta orden lo hace), así
			// que prometer «el worker lo reintentará» prometía una reparación que
			// puede no ocurrir. Lo verdadero es pedir que se repita la orden.
			\WP_CLI::error( sprintf( 'Producto %d está ocupado por otro proceso del buscador y no se reparó: vuelve a correr la orden en unos segundos.', $product_id ) );
		} else {
			\WP_CLI::error( sprintf( 'No se pudo confirmar el reindex de %d; revisa la cola durable y `wp akb-search status`.', $product_id ) );
		}
	}

	/**
	 * Corre el reconciler ahora.
	 *
	 * @return void
	 */
	public function cmd_reconcile(): void {
		AuditLog::record( 'reconcile_encolado', 'Reconcile inline por CLI (wp akb-search reconcile)' );
		$summary = $this->reconciler->run();
		\WP_CLI::success( 'Reconcile: ' . (string) wp_json_encode( $summary ) );
	}
}
