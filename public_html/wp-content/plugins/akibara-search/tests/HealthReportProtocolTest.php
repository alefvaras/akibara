<?php
/**
 * Contrato de observabilidad del protocolo durable.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\HealthReport;
use Akibara\Search\RebuildJob;
use Akibara\Search\WorkRepository;
use PHPUnit\Framework\TestCase;

final class HealthReportProtocolTest extends TestCase {

	public function test_work_status_aggregates_every_scope(): void {
		$status = HealthReport::work_status(
			array(
				WorkRepository::SCOPE_LIVE => array(
					WorkRepository::STATUS_PENDING => 2,
					WorkRepository::STATUS_LEASED  => 1,
				),
				WorkRepository::SCOPE_REPAIR => array(
					WorkRepository::STATUS_PENDING => 3,
					WorkRepository::STATUS_BLOCKED => 4,
				),
			)
		);

		self::assertSame( 5, $status['pending'] );
		self::assertSame( 1, $status['leased'] );
		self::assertSame( 4, $status['blocked'] );
		self::assertSame( '', $status['error'] );
	}

	public function test_terminal_rebuild_is_attention_immediately(): void {
		$result = HealthReport::verdict(
			$this->healthy_data(
				array(
					'phase'      => RebuildJob::PHASE_BLOCKED,
					'error'      => 'ambiguous_topology',
					'topology'   => 'ambiguous',
					'updated_at' => 1000,
				)
			)
		);

		self::assertSame( HealthReport::ATENCION, $result['verdict'] );
		self::assertSame( 'protocolo_bloqueado', $result['reasons'][0]['code'] );
		self::assertSame( '', $result['reasons'][0]['cta'] );
	}

	public function test_work_read_failure_is_never_green(): void {
		$data                  = $this->healthy_data();
		$data['work']['error'] = 'table missing';

		$result = HealthReport::verdict( $data );

		self::assertSame( HealthReport::ATENCION, $result['verdict'] );
		self::assertSame( 'work_no_observable', $result['reasons'][0]['code'] );
	}

	public function test_blocked_live_work_is_never_green_without_rebuild(): void {
		$data                    = $this->healthy_data();
		$data['work']['blocked'] = 1;

		$result = HealthReport::verdict( $data );

		self::assertSame( HealthReport::ATENCION, $result['verdict'] );
		self::assertSame( 'work_bloqueado', $result['reasons'][0]['code'] );
	}

	public function test_newer_reconciler_failure_is_visible_without_overwriting_last_success(): void {
		$data = $this->healthy_data();
		$data['reconcile_error'] = array(
			'ran_at' => 1050,
			'error'  => 'missing_read_failed',
		);

		$result = HealthReport::verdict( $data );

		self::assertSame( HealthReport::ATENCION, $result['verdict'] );
		self::assertContains( 'reconciler_fallido', array_column( $result['reasons'], 'code' ) );
	}

	public function test_older_reconciler_failure_does_not_poison_newer_success(): void {
		$data = $this->healthy_data();
		$data['reconcile_error'] = array(
			'ran_at' => 900,
			'error'  => 'missing_read_failed',
		);

		$result = HealthReport::verdict( $data );

		self::assertSame( HealthReport::SANO, $result['verdict'] );
	}

	/**
	 * Hallazgo S-09b30833 (segunda mitad): desde que los tres servicios viajan
	 * como par {configurado, efectivo}, `empty()` sobre el de indexación da
	 * siempre false —un array con dos `false` no está vacío— y el aviso de
	 * «filtros activos con la indexación apagada» no volvería a salir nunca.
	 *
	 * @testdox El aviso de indexación apagada mira lo EFECTIVO, no si el array existe.
	 */
	public function test_indexing_flag_is_read_by_effective_state(): void {
		$data                      = $this->healthy_data();
		$data['flags']['indexing']  = array( 'configured' => true, 'published' => false, 'effective' => false );
		$data['flags']['facets']    = array( 'configured' => true, 'published' => true, 'effective' => true );

		$result = HealthReport::verdict( $data );

		self::assertContains( 'flags', array_column( $result['reasons'], 'code' ) );
	}

	public function test_structured_flags_use_effective_state_not_array_truthiness(): void {
		$data = $this->healthy_data();
		$data['flags'] = array(
			'indexing' => array( 'configured' => false, 'published' => false, 'effective' => false ),
			'suggest'  => array( 'configured' => true, 'published' => true, 'effective' => false ),
			'facets'   => array( 'configured' => false, 'published' => false, 'effective' => false ),
		);

		$result = HealthReport::verdict( $data );

		self::assertSame( HealthReport::SANO, $result['verdict'] );
		self::assertNotContains( 'flags', array_column( $result['reasons'], 'code' ) );

		$data['flags']['facets']['effective'] = true;
		$result                              = HealthReport::verdict( $data );

		self::assertSame( HealthReport::ATENCION, $result['verdict'] );
		self::assertContains( 'flags', array_column( $result['reasons'], 'code' ) );
	}

	/**
	 * B-3 de la revisión de 0.1.63: el REST del panel no llevaba `semantic` y el
	 * poll no podía distinguir un ajuste pendiente de publicar de uno ilegible.
	 *
	 * @testdox El REST de estado del panel expone la foto semántica.
	 */
	public function test_el_rest_del_panel_expone_la_foto_semantica(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/src/Search.php' );
		$inicio = (int) strpos( $fuente, 'public function handle_admin_status' );
		$cuerpo = substr( $fuente, $inicio, (int) strpos( $fuente, 'return $response;', $inicio ) - $inicio );
		// Sin comentarios: el que explica el cambio nombra la clave que se busca.
		$cuerpo = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $cuerpo );

		self::assertStringContainsString(
			'\'semantic\' => $data[\'semantic\']',
			(string) preg_replace( '/\s+/', ' ', $cuerpo )
		);
	}

	/**
	 * @param array<string, mixed>|null $rebuild Estado activo opcional.
	 * @return array<string, mixed>
	 */
	private function healthy_data( ?array $rebuild = null ): array {
		return array(
			'now'            => 1100,
			'docs'           => 10,
			'docs_visible'   => 10,
			'eligible'       => 10,
			'variables'      => 0,
			'rebuild'        => $rebuild,
			'work'           => HealthReport::work_status( array() ),
			'topology'       => 'clean_pre',
			'last_rebuild'   => array( 'finished_at' => 1000 ),
			'reconcile_last' => array( 'ran_at' => 1000, 'checked' => 10 ),
			'reconcile_error'=> array(),
			'missing_indexes' => array(),
			'token_collation' => 'utf8mb4_bin',
			'flags'           => array(
				'indexing' => array( 'configured' => true, 'published' => true, 'effective' => true ),
				'suggest'  => array( 'configured' => true, 'published' => true, 'effective' => true ),
				'facets'   => array( 'configured' => true, 'published' => true, 'effective' => true ),
			),
		);
	}
}
