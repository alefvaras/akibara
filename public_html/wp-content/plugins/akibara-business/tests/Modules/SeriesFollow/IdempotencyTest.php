<?php
/**
 * La garantía del módulo: un tomo no se avisa dos veces al mismo cliente.
 *
 * No la sostiene una lectura previa —eso sería check-then-act, con su carrera—
 * sino `UNIQUE (event_key)` más `INSERT IGNORE`. Estas pruebas ejercitan esa
 * restricción a través del repositorio real.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesFollow\DigestQueue;
use Akibara\Business\Tests\Support\SeriesFollowQueueWpdb;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class IdempotencyTest extends TestCase {

	private SeriesFollowQueueWpdb $db;

	private DigestQueue $queue;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->db    = new SeriesFollowQueueWpdb();
		$this->queue = new DigestQueue( $this->db, 'wp_akb_series_follow_queue' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param int $user_id    Cliente.
	 * @param int $product_id Tomo.
	 * @return array<int, array<string, int>>
	 */
	private function aviso( int $user_id, int $product_id, int $volume = 107 ): array {
		return array(
			array(
				'user_id'    => $user_id,
				'product_id' => $product_id,
				'term_id'    => 42,
				'volume'     => $volume,
			),
		);
	}

	/** @return array<int, int> */
	private function ids_con_estado( string $status ): array {
		return array_map(
			static fn ( array $row ): int => (int) $row['id'],
			$this->db->rows_with_status( $status )
		);
	}

	/** @testdox Publicar el mismo tomo dos veces deja UNA sola fila. */
	public function test_publicar_el_mismo_tomo_dos_veces_deja_una_sola_fila(): void {
		self::assertSame( 1, $this->queue->queue( $this->aviso( 10, 5107 ) ) );
		self::assertSame( 0, $this->queue->queue( $this->aviso( 10, 5107 ) ) );

		self::assertCount( 1, $this->db->table );
	}

	/** @testdox Republicar un tomo YA enviado no crea fila nueva ni manda otro correo. */
	public function test_republicar_un_tomo_ya_enviado_no_crea_fila_nueva(): void {
		$this->queue->queue( $this->aviso( 10, 5107 ) );
		$ids = $this->ids_con_estado( DigestQueue::STATUS_PENDING );
		$this->queue->claim( 10, $ids );

		self::assertSame( 0, $this->queue->queue( $this->aviso( 10, 5107 ) ) );
		self::assertCount( 1, $this->db->table );
		self::assertSame(
			array(),
			$this->queue->pending_for_user( 10 ),
			'La fila enviada es invisible para el digest: no hay nada que reenviar.'
		);
	}

	/** @testdox El claim concurrente sólo lo gana un worker. */
	public function test_el_claim_concurrente_solo_lo_gana_un_worker(): void {
		$this->queue->queue( $this->aviso( 10, 5107 ) );
		$ids = $this->ids_con_estado( DigestQueue::STATUS_PENDING );

		self::assertSame( 1, $this->queue->claim( 10, $ids ) );
		self::assertSame( 0, $this->queue->claim( 10, $ids ) );
	}

	/** @testdox El claim de un cliente no puede llevarse las filas de otro. */
	public function test_el_claim_no_cruza_clientes(): void {
		$this->queue->queue( array_merge( $this->aviso( 10, 5107 ), $this->aviso( 11, 5107 ) ) );
		$todas = $this->ids_con_estado( DigestQueue::STATUS_PENDING );

		self::assertSame( 1, $this->queue->claim( 10, $todas ) );
		self::assertCount( 1, $this->db->rows_with_status( DigestQueue::STATUS_PENDING ) );
	}

	/**
	 * `drop('unpublished')` BORRA la fila porque el motivo es transitorio: con la
	 * fila marcada, el `INSERT IGNORE` de un scan futuro afectaría 0 filas y ese
	 * cliente no sabría NUNCA de ese tomo.
	 *
	 * @testdox Una fila descartada por despublicación se borra y permite reavisar.
	 */
	public function test_una_fila_dropeada_por_despublicacion_se_borra_y_permite_reavisar(): void {
		$this->queue->queue( $this->aviso( 10, 5107 ) );
		$this->queue->drop( $this->ids_con_estado( DigestQueue::STATUS_PENDING ), DigestQueue::DROP_UNPUBLISHED );

		self::assertSame( array(), $this->db->table );
		self::assertSame( 1, $this->queue->queue( $this->aviso( 10, 5107 ) ), 'El scan siguiente vuelve a encolarlo.' );
	}

	/**
	 * `owned` y `optout` son decisiones TERMINALES: ya lo tiene, o no lo quiere.
	 * Marcarlas conserva la marca de idempotencia.
	 *
	 * @testdox Una fila descartada por propiedad u opt-out se marca y no vuelve.
	 * @dataProvider motivosTerminales
	 */
	public function test_una_fila_dropeada_por_motivo_terminal_no_vuelve( string $motivo ): void {
		$this->queue->queue( $this->aviso( 10, 5107 ) );
		$this->queue->drop( $this->ids_con_estado( DigestQueue::STATUS_PENDING ), $motivo );

		self::assertCount( 1, $this->db->rows_with_status( DigestQueue::STATUS_DROPPED ) );
		self::assertSame( 0, $this->queue->queue( $this->aviso( 10, 5107 ) ) );
	}

	/** @return array<string, array{0: string}> */
	public static function motivosTerminales(): array {
		return array(
			'ya lo compró'   => array( DigestQueue::DROP_OWNED ),
			'no lo quiere'   => array( DigestQueue::DROP_OPTOUT ),
		);
	}

	/** @testdox Tomos distintos del mismo cliente son eventos distintos. */
	public function test_tomos_distintos_del_mismo_cliente_son_eventos_distintos(): void {
		self::assertSame(
			2,
			$this->queue->queue( array_merge( $this->aviso( 10, 5107 ), $this->aviso( 10, 5108, 108 ) ) )
		);
	}

	/**
	 * Un fallo de envío devuelve la fila al pool con un intento gastado; al
	 * agotarlos queda `failed` (terminal), que drena la cola en vez de
	 * reintentar para siempre.
	 *
	 * @testdox Liberar tres veces agota los intentos y deja la fila en failed.
	 */
	public function test_liberar_agota_los_intentos_y_marca_failed(): void {
		$this->queue->queue( $this->aviso( 10, 5107 ) );

		for ( $intento = 1; $intento <= DigestQueue::MAX_ATTEMPTS; $intento++ ) {
			$ids = $this->ids_con_estado( DigestQueue::STATUS_PENDING );
			$this->queue->claim( 10, $ids );
			$this->queue->release( $ids );

			$esperado = $intento < DigestQueue::MAX_ATTEMPTS
				? DigestQueue::STATUS_PENDING
				: DigestQueue::STATUS_FAILED;

			self::assertCount(
				1,
				$this->db->rows_with_status( $esperado ),
				sprintf( 'Tras el intento %d la fila tiene que estar en %s.', $intento, $esperado )
			);
		}
	}

	/** @testdox Liberar una fila que ya no está reclamada no la resucita. */
	public function test_liberar_una_fila_no_reclamada_no_la_toca(): void {
		$this->queue->queue( $this->aviso( 10, 5107 ) );
		$ids = $this->ids_con_estado( DigestQueue::STATUS_PENDING );

		$this->queue->release( $ids );

		self::assertSame( 0, (int) $this->db->rows_with_status( DigestQueue::STATUS_PENDING )[0]['attempts'] );
	}

	/**
	 * Dos meses tarde no es un aviso, es basura — y eso sí es terminal.
	 *
	 * @testdox Las filas pendientes de más de 60 días se expiran.
	 */
	public function test_filas_pendientes_de_mas_de_60_dias_se_expiran(): void {
		$this->queue->queue( $this->aviso( 10, 5107 ) );
		$this->queue->queue( $this->aviso( 11, 5107 ) );

		// Sólo la primera es vieja.
		$ids = array_keys( $this->db->table );
		$this->db->table[ $ids[0] ]['created_at'] = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );

		self::assertSame( 1, $this->queue->expire_older_than( 60 ) );
		self::assertCount( 1, $this->db->rows_with_status( DigestQueue::STATUS_EXPIRED ) );
		self::assertCount( 1, $this->db->rows_with_status( DigestQueue::STATUS_PENDING ) );
	}

	/** @testdox Una fila expirada conserva la marca: no se reavisa. */
	public function test_una_fila_expirada_no_se_reencola(): void {
		$this->queue->queue( $this->aviso( 10, 5107 ) );
		$ids = array_keys( $this->db->table );
		$this->db->table[ $ids[0] ]['created_at'] = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );
		$this->queue->expire_older_than( 60 );

		self::assertSame( 0, $this->queue->queue( $this->aviso( 10, 5107 ) ) );
	}

	/** @testdox Una fila sin tomo numerado o sin cliente nunca entra a la cola. */
	public function test_filas_invalidas_no_entran(): void {
		self::assertSame(
			0,
			$this->queue->queue(
				array(
					array(
						'user_id'    => 0,
						'product_id' => 5107,
						'term_id'    => 42,
						'volume'     => 107,
					),
					array(
						'user_id'    => 10,
						'product_id' => 5107,
						'term_id'    => 42,
						'volume'     => 0,
					),
				)
			)
		);
		self::assertSame( array(), $this->db->table );
	}

	/** @testdox La clave de idempotencia identifica al par cliente↔tomo. */
	public function test_la_clave_de_evento_identifica_al_par_cliente_tomo(): void {
		self::assertSame( 'u10:p5107', DigestQueue::event_key( 10, 5107 ) );
		self::assertNotSame( DigestQueue::event_key( 10, 5107 ), DigestQueue::event_key( 105, 107 ) );
	}
}
