<?php
/**
 * El scan de un tomo recién publicado: quién entra a la cola y quién NO.
 *
 * Los descartes son el contrato. Cada negativo describe un cliente real de la
 * tienda al que un aviso equivocado le llegaría al inbox.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Akibara\Business\Modules\SeriesFollow\CutoffPhrase;
use Akibara\Business\Modules\SeriesFollow\DigestQueue;
use Akibara\Business\Modules\SeriesFollow\DigestRunner;
use Akibara\Business\Modules\SeriesFollow\FollowerFinder;
use Akibara\Business\Modules\SeriesFollow\FollowRegistry;
use Akibara\Business\Modules\SeriesFollow\SeriesFollow;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Services\VolumeVariant;
use Akibara\Business\Tests\Support\SeriesFollowFixedEta;
use Akibara\Business\Tests\Support\SeriesFollowFollowersWpdb;
use Akibara\Business\Tests\Support\SeriesFollowQueueWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ScanTest extends TestCase {

	private const TOMO_PUBLICADO = 5107;
	private const TERM_ID        = 42;
	private const TTID           = 1042;

	private SeriesFollowQueueWpdb $queue_db;

	private SeriesFollowFollowersWpdb $orders_db;

	/** @var array<int, string> product_id ⇒ título. */
	private array $titulos = array();

	private string $post_status = 'publish';

	private string $post_date_gmt = '';

	private bool $oculto = false;

	/** @var array<int, array<int, int>> user_id ⇒ term_ids dados de baja. */
	private array $bajas = array();

	/** @var array<int, array{hook:string, args:array<string,mixed>}> */
	private array $scheduled = array();

	/** @var int|false Corrida de digest ya pendiente. */
	private $digest_pendiente = false;

	/** @var array<int, string> Marca `_akb_variante_tomo` por producto. */
	private array $variantes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->queue_db      = new SeriesFollowQueueWpdb();
		$this->orders_db     = new SeriesFollowFollowersWpdb();
		$this->titulos       = array( self::TOMO_PUBLICADO => 'One Piece 107' );
		$this->post_status   = 'publish';
		$this->post_date_gmt = gmdate( 'Y-m-d H:i:s' );
		$this->oculto        = false;
		$this->bajas         = array();
		$this->scheduled     = array();
		$this->digest_pendiente = false;
		$this->variantes        = array();

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'update_meta_cache' )->justReturn( array() );
		Functions\when( 'wc_get_logger' )->justReturn(
			new class() {
				/** @param array<string, mixed> $context */
				public function warning( string $message, array $context = array() ): void {
					unset( $message, $context );
				}
			}
		);

		Functions\when( 'get_post_status' )->alias( fn (): string => $this->post_status );
		Functions\when( 'get_post_field' )->alias( fn (): string => $this->post_date_gmt );
		Functions\when( 'has_term' )->alias( fn (): bool => $this->oculto );
		Functions\when( 'get_the_title' )->alias( fn ( int $id ): string => $this->titulos[ $id ] ?? '' );
		Functions\when( 'get_post_meta' )->alias(
			fn ( int $id, string $key = '', bool $single = false ): string => VolumeVariant::META_KEY === $key
				? ( $this->variantes[ $id ] ?? '' )
				: ''
		);
		Functions\when( 'get_the_terms' )->alias(
			fn ( int $id, string $taxonomy ) => 'pa_serie' === $taxonomy
				? array(
					(object) array(
						'term_id'          => self::TERM_ID,
						'term_taxonomy_id' => self::TTID,
						'name'             => 'One Piece',
					),
				)
				: false
		);
		Functions\when( 'get_user_meta' )->alias(
			fn ( int $user_id, string $key ) => FollowRegistry::META_OPTOUT === $key
				? ( $this->bajas[ $user_id ] ?? array() )
				: ''
		);

		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook, array $args = array() ): int {
				unset( $timestamp );
				$this->scheduled[] = compact( 'hook', 'args' );
				return count( $this->scheduled );
			}
		);
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $query ): array {
				unset( $query );
				return $this->digest_pendiente ? array( 1 ) : array();
			}
		);
		Functions\when( 'as_next_scheduled_action' )->alias( fn () => $this->digest_pendiente );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function compra( int $user_id, int $product_id, string $titulo, string $status = 'wc-completed' ): void {
		$this->orders_db->lineas[] = array(
			'user_id'    => $user_id,
			'product_id' => $product_id,
			'status'     => $status,
			'qty'        => 1,
		);
		$this->titulos[ $product_id ] = $titulo;
	}

	private function runner(): DigestRunner {
		$collection = new CustomerCollection( $this->orders_db );

		return new DigestRunner(
			new DigestQueue( $this->queue_db, 'wp_akb_series_follow_queue' ),
			new FollowerFinder( $collection, $this->orders_db ),
			$collection,
			new CutoffPhrase(
				new SeriesFollowFixedEta(),
				new PedidoPolicy()
			)
		);
	}

	/** @return array<int, int> user_ids encolados. */
	private function encolados(): array {
		return array_map(
			static fn ( array $row ): int => (int) $row['user_id'],
			$this->queue_db->rows_with_status( DigestQueue::STATUS_PENDING )
		);
	}

	/** @testdox Sólo se encolan los tomos POSTERIORES al máximo comprado. */
	public function test_solo_encola_tomos_mayores_al_maximo_comprado(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->compra( 11, 5108, 'One Piece 108' );

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array( 10 ), $this->encolados() );
	}

	/**
	 * @testdox [NEG] Una edición especial del mismo tomo no encola a nadie.
	 *
	 * El caso real: meter «Demon Slayer 23 (Edición Especial)» dentro de la serie
	 * asigna `pa_serie` y eso dispara el escaneo, aunque el producto lleve días
	 * publicado. Sin la marca, todos los clientes con menos del 23 recibirían
	 * «ya salió el tomo 23» de un tomo que salió meses atrás.
	 */
	public function test_una_variante_del_mismo_tomo_no_encola_a_nadie(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->variantes[ self::TOMO_PUBLICADO ] = VolumeVariant::KIND_EDICION_ESPECIAL;

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array(), $this->encolados() );
	}

	/** @testdox El mismo tomo SIN la marca sí encola: la marca es lo único que cambia. */
	public function test_el_mismo_tomo_sin_marca_sigue_encolando(): void {
		$this->compra( 10, 5100, 'One Piece 100' );

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array( 10 ), $this->encolados() );
	}

	/** @testdox [NEG] Quien YA compró el tomo publicado no se encola. */
	public function test_cliente_que_ya_compro_el_tomo_publicado_no_se_encola(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->compra( 10, self::TOMO_PUBLICADO, 'One Piece 107' );

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array(), $this->encolados() );
	}

	/** @testdox [NEG] Quien se dio de baja de la serie no se encola. */
	public function test_cliente_con_opt_out_de_la_serie_no_se_encola(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->compra( 11, 5100, 'One Piece 100' );
		$this->bajas[10] = array( self::TERM_ID );

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array( 11 ), $this->encolados() );
	}

	/**
	 * Sin ningún tomo NUMERADO de la serie no es un seguidor: quien compró sólo
	 * un artbook o un Expansion Pass no está leyendo la serie, y «salió el tomo
	 * 107» le llegaría de la nada.
	 *
	 * @testdox [NEG] Quien sólo tiene material sin número no se encola.
	 * @dataProvider comprasSinNumero
	 */
	public function test_cliente_sin_ningun_tomo_numerado_de_la_serie_no_se_encola( string $titulo ): void {
		$this->compra( 10, 5900, $titulo );

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array(), $this->encolados() );
	}

	/** @return array<string, array{0: string}> */
	public static function comprasSinNumero(): array {
		return array(
			'artbook'        => array( 'One Piece Artbook' ),
			'expansion pass' => array( 'Shangri La Frontier 1 Expansion Pass' ),
			'tomo cero'      => array( 'One Piece 0' ),
		);
	}

	/** @testdox [NEG] Un producto despublicado entre el encolado y el worker no encola nada. */
	public function test_producto_despublicado_entre_el_encolado_y_el_worker_no_encola_nada(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->post_status = 'draft';

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array(), $this->encolados() );
	}

	/** @testdox [NEG] Un producto oculto del catálogo no encola nada. */
	public function test_producto_excluido_del_catalogo_no_encola_nada(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->oculto = true;

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array(), $this->encolados() );
	}

	/**
	 * Republicar un tomo viejo no es una novedad, y los clientes que compraron
	 * la serie DESPUÉS del aviso original no tienen fila `sent` que los proteja:
	 * sin este guard recibirían un «salió el tomo 107» de hace dos años.
	 *
	 * @testdox [NEG] Republicar un tomo de hace dos años no encola a nadie.
	 */
	public function test_republicar_un_tomo_de_hace_dos_anos_no_encola_a_nadie(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->post_date_gmt = gmdate( 'Y-m-d H:i:s', time() - ( 730 * DAY_IN_SECONDS ) );

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array(), $this->encolados() );
	}

	/**
	 * Un tomo agotado (`outofstock`) no se puede comprar hoy; no se anuncia al
	 * crearse en agotado sino cuando su stock pasa a Disponible o A pedido.
	 *
	 * @testdox [NEG] Un tomo agotado NO se encola hasta que pasa a Disponible o A pedido.
	 */
	public function test_un_tomo_agotado_no_encola_hasta_que_se_habilita(): void {
		$this->compra( 10, 5100, 'One Piece 100' );

		Functions\expect( 'wc_get_product' )->never();
		Functions\when( 'get_post_meta' )->alias(
			fn ( int $id, string $key = '', bool $single = false ): string => '_stock_status' === $key
				? 'outofstock'
				: ( VolumeVariant::META_KEY === $key ? ( $this->variantes[ $id ] ?? '' ) : '' )
		);

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array(), $this->encolados() );
	}

	/**
	 * Cuando un tomo publicado hace meses se repone hoy (`_akb_sf_restocked_at`),
	 * el guard de 60 días mira la fecha de reposición y permite avisar.
	 *
	 * @testdox Un tomo antiguo repuesto hoy SÍ encola gracias a _akb_sf_restocked_at.
	 */
	public function test_un_tomo_antiguo_repuesto_hoy_si_encola(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->post_date_gmt = gmdate( 'Y-m-d H:i:s', time() - ( 730 * DAY_IN_SECONDS ) );

		Functions\when( 'get_post_meta' )->alias(
			static fn ( int $id, string $key = '', bool $single = false ): string => \Akibara\Business\Modules\SeriesFollow\PublishListener::META_RESTOCKED_AT === $key
				? (string) time()
				: 'instock'
		);

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array( 10 ), $this->encolados() );
	}

	/**
	 * Si el cliente compró el 100 y el 110, y se habilita el 107 (hueco intermedio),
	 * el alcance por defecto (`new_and_gaps`) lo encola; si sólo compró el 110, no.
	 *
	 * @testdox Con alcance new_and_gaps, un tomo menor sólo encola a quien tiene ese hueco intermedio.
	 */
	public function test_alcance_new_and_gaps_encola_hueco_intermedio_y_descarta_sin_base(): void {
		// Cliente 10: compró 100 y 110 -> 107 es un hueco intermedio.
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->compra( 10, 5110, 'One Piece 110' );
		// Cliente 11: sólo compró 110 -> no sabemos si colecciona desde abajo en Akibara.
		$this->compra( 11, 5110, 'One Piece 110' );

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array( 10 ), $this->encolados() );
	}

	/**
	 * El dueño puede cambiar el alcance desde el panel sin código (`only_new` o `all_missing`).
	 *
	 * @testdox El selector de alcance only_new y all_missing gobierna a quién se encola un tomo menor.
	 */
	public function test_selector_de_alcance_only_new_y_all_missing(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->compra( 10, 5110, 'One Piece 110' );
		$this->compra( 11, 5110, 'One Piece 110' );

		Functions\when( 'get_option' )->alias(
			static fn ( string $opt, $def = false ) => SeriesFollow::OPTION_VOLUME_SCOPE === $opt
				? SeriesFollow::SCOPE_ONLY_NEW
				: 'yes'
		);

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );
		self::assertSame( array(), $this->encolados(), 'Con only_new no se encola ningún tomo menor al máximo.' );

		$this->queue_db->table = array();
		Functions\when( 'get_option' )->alias(
			static fn ( string $opt, $def = false ) => SeriesFollow::OPTION_VOLUME_SCOPE === $opt
				? SeriesFollow::SCOPE_ALL_MISSING
				: 'yes'
		);

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );
		self::assertSame( array( 10, 11 ), $this->encolados(), 'Con all_missing se encola a cualquiera que no tenga ese tomo.' );
	}

	/**
	 * Guardia de editorial (`product_brand`): si por error humano se sube un tomo
	 * de otra editorial en la misma `pa_serie`, no se avisa al coleccionista que
	 * compró otra editorial.
	 *
	 * @testdox [NEG] Un tomo de otra editorial no se encola al coleccionista de una editorial distinta.
	 */
	public function test_guardia_de_editorial_no_mezcla_coleccionistas_de_distinta_editorial(): void {
		$this->compra( 10, 5100, 'One Piece 100' ); // Compró Ivrea (brand 201)
		$this->compra( 11, 5200, 'One Piece 100' ); // Compró Panini (brand 202)

		Functions\when( 'get_the_terms' )->alias(
			static function ( int $id, string $taxonomy ) {
				if ( 'pa_serie' === $taxonomy ) {
					return array(
						(object) array(
							'term_id'          => self::TERM_ID,
							'term_taxonomy_id' => self::TTID,
							'name'             => 'One Piece',
						),
					);
				}
				if ( 'product_brand' === $taxonomy ) {
					if ( 5100 === $id || self::TOMO_PUBLICADO === $id ) {
						return array( (object) array( 'term_id' => 201, 'name' => 'Ivrea' ) );
					}
					if ( 5200 === $id ) {
						return array( (object) array( 'term_id' => 202, 'name' => 'Panini' ) );
					}
				}
				return false;
			}
		);

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array( 10 ), $this->encolados(), 'Sólo el coleccionista de Ivrea recibe el aviso de Ivrea.' );
	}

	/**
	 * Una lectura caída NO puede convertirse en «nadie sigue esta serie»: si se
	 * encolara el descarte, `uniq_event` impediría reintentar y el tomo quedaría
	 * sin avisar para siempre.
	 *
	 * @testdox [NEG] Una lectura no fiable sale sin marcar nada y deja reintentar.
	 */
	public function test_una_lectura_caida_no_marca_nada(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->orders_db->falla = true;

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array(), $this->queue_db->table );
		self::assertSame( array(), $this->scheduled, 'Ni siquiera se agenda el digest: no hay nada que enviar.' );
	}

	/** @testdox Un producto sin serie ni tomo reconocible no encola nada. */
	public function test_producto_sin_serie_no_encola_nada(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		Functions\when( 'get_the_terms' )->justReturn( false );

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array(), $this->queue_db->table );
	}

	/** @testdox El scan agenda la corrida del digest cuando no hay ninguna pendiente. */
	public function test_el_primer_scan_agenda_la_corrida(): void {
		$this->compra( 10, 5100, 'One Piece 100' );

		// `as_next_scheduled_action()` devuelve FALSE cuando no hay nada
		// agendado. Con `null ===` el carril quedaría mudo para siempre.
		$this->digest_pendiente = false;

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		$hooks = array_column( $this->scheduled, 'hook' );
		self::assertSame( array( SeriesFollow::HOOK_DIGEST ), $hooks );
	}

	/** @testdox Con una corrida ya pendiente, el scan NO agenda una segunda. */
	public function test_un_scan_posterior_no_agenda_una_segunda_corrida(): void {
		$this->compra( 10, 5100, 'One Piece 100' );
		$this->digest_pendiente = 999;

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame( array(), $this->scheduled );
	}

	/**
	 * Una serie con miles de compradores no puede reventar un worker: la página
	 * llena se re-agenda con el cursor en los args.
	 *
	 * @testdox Una página llena re-agenda el scan con el cursor del último cliente.
	 */
	public function test_una_pagina_llena_reagenda_el_scan_con_cursor(): void {
		for ( $user_id = 1; $user_id <= 200; $user_id++ ) {
			$this->compra( $user_id, 5100, 'One Piece 100' );
		}

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		$scans = array_values(
			array_filter(
				$this->scheduled,
				static fn ( array $job ): bool => SeriesFollow::HOOK_SCAN === $job['hook']
			)
		);

		self::assertCount( 1, $scans );
		self::assertSame(
			array(
				'product_id' => self::TOMO_PUBLICADO,
				'cursor'     => 200,
			),
			$scans[0]['args']
		);
	}

	/** @testdox Una página incompleta no re-agenda: no quedan clientes. */
	public function test_una_pagina_incompleta_no_reagenda_el_scan(): void {
		$this->compra( 10, 5100, 'One Piece 100' );

		$this->runner()->scan( self::TOMO_PUBLICADO, 0 );

		self::assertSame(
			array(),
			array_filter(
				$this->scheduled,
				static fn ( array $job ): bool => SeriesFollow::HOOK_SCAN === $job['hook']
			)
		);
	}

	/** @testdox Action Scheduler entrega los args posicionales y el scan los entiende. */
	public function test_el_scan_acepta_los_args_como_array_asociativo(): void {
		$this->compra( 10, 5100, 'One Piece 100' );

		$this->runner()->scan(
			array(
				'product_id' => self::TOMO_PUBLICADO,
				'cursor'     => 0,
			)
		);

		self::assertSame( array( 10 ), $this->encolados() );
	}
}
