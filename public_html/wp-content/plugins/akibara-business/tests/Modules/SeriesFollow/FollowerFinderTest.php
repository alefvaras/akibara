<?php
/**
 * La consulta inversa: de una serie a sus compradores.
 *
 * El doble no simula MySQL entero, pero sí las tres cosas de las que depende la
 * corrección: el conjunto de estados de compra, el corte por cursor de CLIENTE
 * (no de fila) y la paranoia de lectura que devuelve null en vez de vacío.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Akibara\Business\Modules\SeriesFollow\FollowerFinder;
use Akibara\Business\Tests\Support\SeriesFollowFollowersWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FollowerFinderTest extends TestCase {

	private SeriesFollowFollowersWpdb $db;

	private FollowerFinder $finder;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $hook, $value ) => $value
		);

		$this->db     = new SeriesFollowFollowersWpdb();
		$this->finder = new FollowerFinder( new CustomerCollection( $this->db ), $this->db );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function linea( int $user_id, int $product_id, string $status, int $qty = 1 ): void {
		$this->db->lineas[] = compact( 'user_id', 'product_id', 'status', 'qty' );
	}

	/** @testdox Sólo entran los compradores en estados de compra. */
	public function test_devuelve_solo_compradores_en_estados_de_compra(): void {
		$this->linea( 10, 101, 'wc-processing' );
		$this->linea( 11, 102, 'wc-completed' );
		$this->linea( 12, 103, 'wc-shipping-progress' );
		$this->linea( 20, 104, 'wc-cancelled' );
		$this->linea( 21, 105, 'wc-refunded' );
		$this->linea( 22, 106, 'wc-failed' );
		$this->linea( 23, 107, 'wc-pending' );
		$this->linea( 24, 108, 'wc-on-hold' );

		$followers = $this->finder->followers_of_series( 1042, 0, 200 );

		self::assertSame( array( 10, 11, 12 ), array_keys( (array) $followers ) );
	}

	/**
	 * El estado que acredita puntos en akibara-rewards está DENTRO del conjunto,
	 * pero el conjunto es más ancho: el seguimiento empieza al pagar
	 * (`wc-processing`) y los puntos llegan después. Es contención, no igualdad,
	 * y se fija aquí en vez de con un `require` a otro riel.
	 *
	 * @testdox El estado que acredita puntos está DENTRO del conjunto de compra.
	 */
	public function test_el_award_status_del_programa_de_puntos_esta_dentro_del_conjunto(): void {
		$estados = ( new CustomerCollection( $this->db ) )->purchase_statuses();

		self::assertContains( 'wc-shipping-progress', $estados );
		self::assertContains( 'wc-processing', $estados );
		self::assertNotSame(
			array( 'wc-shipping-progress' ),
			$estados,
			'El seguimiento empieza al pagar, no cuando se acreditan los puntos.'
		);
	}

	/** @testdox [NEG] Una lectura no fiable devuelve null, no un conjunto vacío. */
	public function test_una_lectura_no_fiable_devuelve_null_y_no_array_vacio(): void {
		$this->linea( 10, 101, 'wc-completed' );
		$this->db->falla = true;

		self::assertNull(
			$this->finder->followers_of_series( 1042, 0, 200 ),
			'Convertir una caída en «nadie sigue esta serie» marcaría el tomo como avisado sin avisar a nadie.'
		);
	}

	/** @testdox [NEG] Los invitados sin cuenta no entran. */
	public function test_invitados_sin_user_id_no_entran(): void {
		$this->linea( 0, 101, 'wc-completed' );
		$this->linea( 10, 101, 'wc-completed' );

		self::assertSame( array( 10 ), array_keys( (array) $this->finder->followers_of_series( 1042, 0, 200 ) ) );
	}

	/** @testdox [NEG] Un tomo devuelto entero (neto 0) no cuenta como comprado. */
	public function test_un_tomo_reembolsado_por_completo_no_cuenta(): void {
		$this->linea( 10, 101, 'wc-completed', 1 );
		$this->linea( 10, 101, 'wc-completed', -1 );
		$this->linea( 10, 102, 'wc-completed', 1 );

		self::assertSame( array( 102 ), $this->finder->followers_of_series( 1042, 0, 200 )[10] );
	}

	/**
	 * EL CANDADO DE LA PAGINACIÓN EN DOS CONSULTAS. El `LIMIT` del molde cuenta
	 * filas AGRUPADAS por producto: con 250 tomos y un límite de 200, una sola
	 * consulta cortaría al cliente por la mitad y su máximo comprado saldría mal.
	 *
	 * @testdox Un cliente con más filas que el límite no se parte ni se salta.
	 */
	public function test_un_cliente_con_mas_filas_que_el_limite_no_se_parte_ni_se_salta(): void {
		for ( $volumen = 1; $volumen <= 250; $volumen++ ) {
			$this->linea( 10, 1000 + $volumen, 'wc-completed' );
		}

		$followers = $this->finder->followers_of_series( 1042, 0, 200 );

		self::assertSame( array( 10 ), array_keys( (array) $followers ) );
		self::assertCount( 250, $followers[10] );
	}

	/** @testdox La paginación por cursor de user_id no salta ni repite clientes. */
	public function test_pagina_por_cursor_de_user_id_sin_saltarse_ni_repetir_clientes(): void {
		foreach ( array( 10, 20, 30, 40 ) as $user_id ) {
			$this->linea( $user_id, 101, 'wc-completed' );
		}

		$primera = (array) $this->finder->followers_of_series( 1042, 0, 2 );
		self::assertSame( array( 10, 20 ), array_keys( $primera ) );

		$segunda = (array) $this->finder->followers_of_series( 1042, max( array_keys( $primera ) ), 2 );
		self::assertSame( array( 30, 40 ), array_keys( $segunda ) );

		$tercera = (array) $this->finder->followers_of_series( 1042, max( array_keys( $segunda ) ), 2 );
		self::assertSame( array(), $tercera );
	}

	/** @testdox La consulta reusa los joins autoritativos y el filtro de la serie. */
	public function test_la_consulta_exige_pedido_vivo_y_termino_de_la_serie(): void {
		$this->linea( 10, 101, 'wc-completed' );

		$this->finder->followers_of_series( 1042, 0, 200 );

		self::assertStringContainsString( 'live_activity', $this->db->last_query );
		self::assertStringContainsString( 'live_parent', $this->db->last_query );
		self::assertStringContainsString( 'series.object_id = products.product_id', $this->db->last_query );
	}
}
