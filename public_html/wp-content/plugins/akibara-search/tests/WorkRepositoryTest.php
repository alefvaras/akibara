<?php
/**
 * Pruebas focales del outbox/manifest durable del protocolo v2.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\WorkRepository;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/WorkRepository.php';

/** Error de lectura inyectable para el doble wpdb. */
final class WorkRepositoryReadFailure {
	public string $message;
	public bool $empty_results;

	public function __construct( string $message, bool $empty_results = false ) {
		$this->message       = $message;
		$this->empty_results = $empty_results;
	}
}

/** Doble secuencial de wpdb que conserva el SQL para probar fencing lógico. */
final class WorkRepositoryWpdb {

	public string $prefix = 'wp_';
	public string $last_error = '';

	/** @var array<int, int|false> */
	public array $query_results = array();

	/** @var array<int, object|array<string, mixed>|WorkRepositoryReadFailure|null> */
	public array $row_results = array();

	/** @var array<int, mixed> */
	public array $var_results = array();

	/** @var array<int, array<int, object>|WorkRepositoryReadFailure|null> */
	public array $results_results = array();

	/** @var array<int, string> */
	public array $queries = array();

	/** @param string $query SQL. @param mixed ...$args Valores. @return string */
	public function prepare( string $query, ...$args ): string {
		$index = 0;
		return (string) preg_replace_callback(
			'/%[ds]/',
			static function ( array $match ) use ( &$index, $args ): string {
				$value = $args[ $index++ ];
				return '%d' === $match[0]
					? (string) (int) $value
					: "'" . str_replace( "'", "''", (string) $value ) . "'";
			},
			$query
		);
	}

	/** @param string $query SQL. @return int|false */
	public function query( string $query ) {
		$this->last_error = '';
		$this->queries[]  = $query;
		$result           = array() === $this->query_results ? 1 : array_shift( $this->query_results );
		if ( false === $result ) {
			$this->last_error = 'forced query failure';
		}

		return $result;
	}

	/** @param string $query SQL. @return object|array<string, mixed>|null */
	public function get_row( string $query ) {
		$this->queries[] = $query;
		$result          = array_shift( $this->row_results );
		if ( $result instanceof WorkRepositoryReadFailure ) {
			$this->last_error = $result->message;
			return null;
		}

		return is_array( $result ) ? (object) $result : $result;
	}

	/** @param string $query SQL. @return mixed */
	public function get_var( string $query ) {
		$this->queries[] = $query;
		$result          = array_shift( $this->var_results );
		if ( $result instanceof WorkRepositoryReadFailure ) {
			$this->last_error = $result->message;
			return null;
		}

		return $result;
	}

	/** @param string $query SQL. @return array<int, object>|null */
	public function get_results( string $query ): ?array {
		$this->queries[] = $query;
		$result          = array_shift( $this->results_results );
		if ( $result instanceof WorkRepositoryReadFailure ) {
			$this->last_error = $result->message;
			return $result->empty_results ? array() : null;
		}

		return $result;
	}
}

/** @covers \Akibara\Search\WorkRepository */
final class WorkRepositoryTest extends TestCase {

	/**
	 * @testdox Dirty n+1 conserva el lease n y su ACK deja deuda pending.
	 */
	public function test_dirty_during_lease_is_preserved_by_conditional_ack(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 1, 1 );
		$repository          = $this->repository( $wpdb );
		$claim               = $this->claim();

		self::assertTrue( $repository->mark_live_dirty( 42 ) );
		self::assertTrue( $repository->ack_claim( $claim, WorkRepository::OUTCOME_INDEXED ) );

		self::assertStringContainsString( "status = IF(status = 'leased'", $wpdb->queries[0] );
		self::assertStringContainsString( "lease_token = IF(status = 'leased', lease_token, '')", $wpdb->queries[0] );
		self::assertStringContainsString( 'wanted_seq = wanted_seq + 1', $wpdb->queries[0] );
		self::assertStringContainsString( "status = IF(wanted_seq > 7, 'pending', 'acked')", $wpdb->queries[1] );
		self::assertStringContainsString( 'acked_seq = GREATEST(acked_seq, 7)', $wpdb->queries[1] );
		self::assertStringContainsString( 'attempts = 0', $wpdb->queries[1] );
	}

	/**
	 * @testdox Dirty live batch deduplica, ordena y limita cada INSERT a 250 IDs.
	 */
	public function test_live_dirty_batch_deduplicates_sorts_and_chunks_safely(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 1, 1 );
		$repository          = $this->repository( $wpdb );
		$ids                 = array_merge( array_reverse( range( 1, 251 ) ), array( 7, 250, 251 ) );

		self::assertTrue( $repository->mark_live_dirty_many( $ids ) );
		self::assertCount( 2, $wpdb->queries );

		preg_match_all( "/\\('live', '', ([0-9]+), 1, 0, '', 'pending'/", $wpdb->queries[0], $first_matches );
		preg_match_all( "/\\('live', '', ([0-9]+), 1, 0, '', 'pending'/", $wpdb->queries[1], $second_matches );

		self::assertSame( range( 1, 250 ), array_map( 'intval', $first_matches[1] ) );
		self::assertSame( array( 251 ), array_map( 'intval', $second_matches[1] ) );
		foreach ( $wpdb->queries as $query ) {
			self::assertStringContainsString( 'lease_until IS NOT NULL AND lease_until > UTC_TIMESTAMP()', $query );
			self::assertStringContainsString( "lease_token = IF(status = 'leased', lease_token, '')", $query );
			self::assertStringContainsString( 'wanted_seq = wanted_seq + 1', $query );
		}
	}

	/**
	 * @testdox Dirty live batch valida todo antes de escribir y vacío es no-op válido.
	 */
	public function test_live_dirty_batch_rejects_invalid_input_before_any_write(): void {
		$wpdb       = new WorkRepositoryWpdb();
		$repository = $this->repository( $wpdb );

		self::assertFalse( $repository->mark_live_dirty_many( array( 4, '5' ) ) );
		self::assertSame( 'Invalid live product id.', $repository->last_error() );
		self::assertSame( array(), $wpdb->queries );

		self::assertTrue( $repository->mark_live_dirty_many( array() ) );
		self::assertSame( '', $repository->last_error() );
		self::assertSame( array(), $wpdb->queries );
	}

	/**
	 * @testdox Dirty diferido conserva una barrera futura incluso durante lease n.
	 */
	public function test_deferred_live_dirty_persists_source_write_barrier(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 1 );
		$repository          = $this->repository( $wpdb );

		self::assertTrue( $repository->mark_live_dirty_many( array( 42 ), 10 ) );
		self::assertStringContainsString( 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 SECOND)', $wpdb->queries[0] );
		self::assertStringContainsString( 'GREATEST(available_at, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 SECOND))', $wpdb->queries[0] );
	}

	/**
	 * @testdox Un evento inmediato n+1 nunca acorta la barrera diferida pendiente de otro source write.
	 */
	public function test_immediate_dirty_never_shortens_existing_deferred_barrier(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 1, 1 );
		$repository          = $this->repository( $wpdb );

		self::assertTrue( $repository->mark_live_dirty_many( array( 42 ), 10 ) );
		self::assertTrue( $repository->mark_live_dirty( 42 ) );

		self::assertStringContainsString(
			'available_at = GREATEST(available_at, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 0 SECOND))',
			$wpdb->queries[1]
		);
		self::assertStringNotContainsString( 'available_at = IF(', $wpdb->queries[1] );
	}

	/**
	 * @testdox ACK n+1 nunca acorta una barrera available_at futura.
	 */
	public function test_ack_next_revision_preserves_future_availability_barrier(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 1 );
		$repository          = $this->repository( $wpdb );

		self::assertTrue( $repository->ack_claim( $this->claim(), WorkRepository::OUTCOME_INDEXED ) );
		self::assertStringContainsString( 'GREATEST(available_at, UTC_TIMESTAMP())', $wpdb->queries[0] );
	}

	/**
	 * @testdox Dirty live batch falla cerrado y no ejecuta chunks posteriores al error SQL.
	 */
	public function test_live_dirty_batch_stops_after_failed_chunk(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 1, false, 1 );
		$repository          = $this->repository( $wpdb );

		self::assertFalse( $repository->mark_live_dirty_many( range( 1, 501 ) ) );
		self::assertCount( 2, $wpdb->queries );
		self::assertSame( 'forced query failure', $repository->last_error() );
	}

	/**
	 * @testdox Repair usa una secuencia propia y tampoco invalida un lease vigente.
	 */
	public function test_repair_dirty_is_monotonic_and_preserves_active_lease(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 1 );
		$repository          = $this->repository( $wpdb );

		self::assertTrue( $repository->mark_repair_dirty( 'run-7', 42 ) );
		self::assertStringContainsString( 'lease_until IS NOT NULL AND lease_until > UTC_TIMESTAMP()', $wpdb->queries[0] );
		self::assertStringContainsString( 'wanted_seq = wanted_seq + 1', $wpdb->queries[0] );
	}

	/**
	 * @testdox Dirty repair batch conserva run, orden, chunks y barrera futura.
	 */
	public function test_repair_dirty_batch_is_bounded_and_preserves_future_barrier(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 1, 1 );
		$repository          = $this->repository( $wpdb );
		$ids                 = array_merge( array_reverse( range( 1, 251 ) ), array( 7, 251 ) );

		self::assertTrue( $repository->mark_repair_dirty_many( 'run-7', $ids, 10 ) );
		self::assertCount( 2, $wpdb->queries );

		preg_match_all( "/\\('repair', 'run-7', ([0-9]+), 1, 0, '', 'pending'/", $wpdb->queries[0], $first_matches );
		preg_match_all( "/\\('repair', 'run-7', ([0-9]+), 1, 0, '', 'pending'/", $wpdb->queries[1], $second_matches );

		self::assertSame( range( 1, 250 ), array_map( 'intval', $first_matches[1] ) );
		self::assertSame( array( 251 ), array_map( 'intval', $second_matches[1] ) );
		foreach ( $wpdb->queries as $query ) {
			self::assertStringContainsString( 'lease_until IS NOT NULL AND lease_until > UTC_TIMESTAMP()', $query );
			self::assertStringContainsString( 'wanted_seq = wanted_seq + 1', $query );
			self::assertStringContainsString( 'GREATEST(available_at, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 SECOND))', $query );
		}
	}

	/**
	 * @testdox Claim elige producto ascendente, fija lease_seq y acepta COMMIT igual a cero.
	 */
	public function test_claim_is_deterministic_and_commit_zero_succeeds(): void {
		$wpdb                  = new WorkRepositoryWpdb();
		$wpdb->query_results   = array( 0, 1, 0 );
		$wpdb->row_results     = array( $this->pending_row(), $this->claim() );
		$repository            = $this->repository( $wpdb );

		$claim = $repository->claim_one( WorkRepository::SCOPE_LIVE, '', 60, 'lease-7' );

		self::assertSame( 'lease-7', $claim['lease_token'] ?? null );
		self::assertStringContainsString( 'ORDER BY product_id ASC, id ASC', $wpdb->queries[1] );
		self::assertStringContainsString( 'lease_seq = wanted_seq', $wpdb->queries[2] );
		self::assertStringContainsString( 'AND available_at <= UTC_TIMESTAMP()', $wpdb->queries[1], 'SELECT debe respetar la barrera n+1.' );
		self::assertStringContainsString( 'AND available_at <= UTC_TIMESTAMP()', $wpdb->queries[2], 'UPDATE debe revalidar la misma barrera n+1.' );
		self::assertSame( 'COMMIT', $wpdb->queries[4] );
	}

	/**
	 * @testdox Claim por producto incluye su identidad exacta en el SELECT bloqueante.
	 */
	public function test_claim_product_is_exact(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 0, 1, 0 );
		$wpdb->row_results   = array( $this->pending_row(), $this->claim() );
		$repository          = $this->repository( $wpdb );

		self::assertNotNull( $repository->claim_product( WorkRepository::SCOPE_LIVE, '', 42, 60, 'lease-7' ) );
		self::assertStringContainsString( 'product_id = 42', $wpdb->queries[1] );
	}

	/**
	 * @testdox COMMIT false revierte el claim y nunca lo entrega al worker.
	 */
	public function test_claim_commit_false_rolls_back(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 0, 1, false, 0 );
		$wpdb->row_results   = array( $this->pending_row(), $this->claim() );
		$repository          = $this->repository( $wpdb );

		self::assertNull( $repository->claim_one( WorkRepository::SCOPE_LIVE, '', 60, 'lease-7' ) );
		self::assertSame( 'ROLLBACK', end( $wpdb->queries ) );
		self::assertStringContainsString( 'forced query failure', $repository->last_error() );
	}

	/**
	 * @testdox Token stale o lease vencida fallan la validación FOR UPDATE.
	 */
	public function test_stale_or_expired_claim_fails_validation(): void {
		$wpdb              = new WorkRepositoryWpdb();
		$wpdb->var_results = array( null );
		$repository        = $this->repository( $wpdb );

		self::assertFalse( $repository->validate_claim_for_update( $this->claim() ) );
		self::assertStringContainsString( "lease_token = 'lease-7'", $wpdb->queries[0] );
		self::assertStringContainsString( 'lease_until > UTC_TIMESTAMP()', $wpdb->queries[0] );
	}

	/**
	 * @testdox Un fallo SQL de lectura no se confunde con un simple not-found.
	 */
	public function test_read_error_is_exposed_fail_closed(): void {
		$wpdb              = new WorkRepositoryWpdb();
		$wpdb->row_results = array( new WorkRepositoryReadFailure( 'read exploded' ) );
		$repository        = $this->repository( $wpdb );

		self::assertNull( $repository->find( WorkRepository::SCOPE_LIVE, '', 42 ) );
		self::assertSame( 'read exploded', $repository->last_error() );
	}

	/**
	 * @testdox Summary falla cerrado si wpdb devuelve array vacío junto a last_error.
	 */
	public function test_summary_rejects_empty_rows_with_database_error(): void {
		$wpdb                  = new WorkRepositoryWpdb();
		$wpdb->results_results = array( new WorkRepositoryReadFailure( 'summary exploded', true ) );
		$repository            = $this->repository( $wpdb );

		self::assertFalse( $repository->summary( 'run-7' ) );
		self::assertSame( 'summary exploded', $repository->last_error() );
	}

	/**
	 * @testdox Un fallo se reprograma sin ACK ni outcome absent.
	 */
	public function test_retry_never_turns_failure_into_absence(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 1 );
		$repository          = $this->repository( $wpdb );

		self::assertTrue( $repository->retry_claim( $this->claim(), 'builder failed', 10 ) );
		self::assertStringContainsString( "SET status = 'pending'", $wpdb->queries[0] );
		self::assertStringContainsString( "outcome = ''", $wpdb->queries[0] );
		self::assertStringContainsString( 'GREATEST(available_at, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 SECOND))', $wpdb->queries[0] );
		self::assertStringNotContainsString( "outcome = 'absent'", $wpdb->queries[0] );
	}

	/**
	 * @testdox Recuperar lease vencida tampoco acorta una barrera n+1 futura.
	 */
	public function test_expired_lease_recovery_preserves_future_barrier(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 1 );
		$repository          = $this->repository( $wpdb );

		self::assertSame( 1, $repository->recover_expired( WorkRepository::SCOPE_LIVE, '' ) );
		self::assertStringContainsString( 'available_at = GREATEST(available_at, UTC_TIMESTAMP())', $wpdb->queries[0] );
	}

	/**
	 * @testdox Seed baseline es idempotente y purge cero filas sigue siendo éxito.
	 */
	public function test_seed_and_purge_are_idempotent(): void {
		$wpdb                = new WorkRepositoryWpdb();
		$wpdb->query_results = array( 0, 0 );
		$repository          = $this->repository( $wpdb );

		self::assertTrue( $repository->seed_baseline( 'run-7', array( 9, 3, 9 ) ) );
		self::assertStringContainsString( 'ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)', $wpdb->queries[0] );
		self::assertSame( 0, $repository->purge_run( 'run-7' ) );
		self::assertStringContainsString( "status = 'acked'", $wpdb->queries[1] );
	}

	/**
	 * R7-trabajo-huerfano (2026-09-19): 755 filas baseline de dos corridas del
	 * 2026-09-05 seguían en producción 14 días después, porque `purge_run()`
	 * sólo corre cuando el rebuild llega a DONE. Al arrancar una corrida nueva
	 * se barre todo lo que no sea suyo, en cualquier estado, sin tocar `live`.
	 */
	public function test_purge_other_runs_sweeps_every_run_but_the_new_one(): void {
		$wpdb       = new WorkRepositoryWpdb();
		$repository = $this->repository( $wpdb );

		self::assertNotFalse( $repository->purge_other_runs( 'run-8' ) );
		self::assertStringContainsString( "scope IN ('baseline', 'repair')", $wpdb->queries[0] );
		self::assertStringContainsString( "run_id <> 'run-8'", $wpdb->queries[0] );
		self::assertStringNotContainsString( 'status', $wpdb->queries[0], 'Cualquier estado: ninguna corrida ajena tiene workers.' );

		self::assertFalse( $repository->purge_other_runs( '' ), 'Sin corrida viva no se barre nada.' );
	}

	/** @return WorkRepository */
	private function repository( WorkRepositoryWpdb $wpdb ): WorkRepository {
		return new WorkRepository( $wpdb, 'wp_akb_search_work' );
	}

	/** @return array<string, mixed> */
	private function pending_row(): array {
		return array(
			'id'          => 10,
			'scope'       => WorkRepository::SCOPE_LIVE,
			'run_id'      => '',
			'product_id'  => 42,
			'wanted_seq'  => 7,
			'acked_seq'   => 6,
			'outcome'     => '',
			'status'      => WorkRepository::STATUS_PENDING,
			'lease_token' => '',
			'lease_seq'   => 0,
			'lease_until' => null,
			'attempts'    => 0,
		);
	}

	/**
	 * @testdox Un claim que agota los reintentos queda BLOQUEADO, no girando para siempre.
	 *
	 * El estado `blocked` tenía contador en el panel y veredicto en HealthReport
	 * pero NINGÚN escritor: un producto irreparable reintentaba indefinidamente
	 * con el backoff clampado a una hora, el run nunca cerraba y el contador se
	 * quedaba en cero. La observabilidad existía y era mentira.
	 */
	public function test_un_claim_agotado_queda_bloqueado_en_vez_de_reintentar(): void {
		$db    = new WorkRepositoryWpdb();
		$work  = new WorkRepository( $db, 'wp_akb_search_work' );
		$claim = $this->claim();

		// Todavía dentro del presupuesto de intentos: reintenta con backoff.
		$claim['attempts'] = WorkRepository::MAX_ATTEMPTS - 1;
		self::assertTrue( $work->retry_with_backoff( $claim, 'transitorio' ) );
		self::assertStringContainsString( "status = '" . WorkRepository::STATUS_PENDING . "'", end( $db->queries ) );

		// Agotado: pasa a bloqueado, con el diagnóstico y el conteo de intentos.
		$claim['attempts'] = WorkRepository::MAX_ATTEMPTS;
		self::assertTrue( $work->retry_with_backoff( $claim, 'imagen ilegible' ) );

		$sql = end( $db->queries );
		self::assertStringContainsString( "status = '" . WorkRepository::STATUS_BLOCKED . "'", $sql );
		self::assertStringContainsString( 'imagen ilegible', $sql, 'El diagnóstico original tiene que sobrevivir.' );
		self::assertStringNotContainsString( "outcome = 'indexed'", $sql, 'Bloqueado nunca es un ACK.' );
	}

	/** @return array<string, mixed> */
	private function claim(): array {
		return array(
			'id'          => 10,
			'scope'       => WorkRepository::SCOPE_LIVE,
			'run_id'      => '',
			'product_id'  => 42,
			'wanted_seq'  => 7,
			'acked_seq'   => 6,
			'outcome'     => '',
			'status'      => WorkRepository::STATUS_LEASED,
			'lease_token' => 'lease-7',
			'lease_seq'   => 7,
			'lease_until' => '2099-01-01 00:00:00',
			'attempts'    => 1,
		);
	}
}
