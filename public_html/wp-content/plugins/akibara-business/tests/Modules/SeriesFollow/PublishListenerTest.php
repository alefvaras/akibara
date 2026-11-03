<?php
/**
 * El listener de publicación: encola y sale.
 *
 * Este listener viaja encima de `set_object_terms`, que es el evento más caro de
 * la casa (guardar cualquier término dispara el rebuild del buscador y deja
 * `suggest` en 503 unos seis minutos). Por eso hay una prueba dedicada a que no
 * añada consultas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow {

	use Akibara\Business\Modules\SeriesFollow\PublishListener;
	use Akibara\Business\Modules\SeriesFollow\SeriesFollow;
	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class PublishListenerTest extends TestCase {

		/** @var array<int, array{timestamp:int, hook:string, args:array<string,mixed>, group:string}> */
		private array $scheduled = array();

		/** @var array<int, array{hook:string, args:array<string,mixed>, group:string}> */
		private array $lookups = array();

		private bool $ya_agendado = false;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			$this->scheduled   = array();
			$this->lookups     = array();
			$this->ya_agendado = false;

			Functions\when( 'as_schedule_single_action' )->alias(
				function ( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
					$this->scheduled[] = compact( 'timestamp', 'hook', 'args', 'group' );
					return count( $this->scheduled );
				}
			);
			Functions\when( 'as_has_scheduled_action' )->alias(
				function ( string $hook, $args = null, string $group = '' ): bool {
					$this->lookups[] = array(
						'hook'  => $hook,
						'args'  => is_array( $args ) ? $args : array(),
						'group' => $group,
					);
					return $this->ya_agendado;
				}
			);
			Functions\when( 'get_option' )->justReturn( 'yes' );
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		private function post( int $id, string $type = 'product' ): \WP_Post {
			$post            = new \WP_Post();
			$post->ID        = $id;
			$post->post_type = $type;

			return $post;
		}

		/** @testdox Publicar un producto encola UN job con los args del producto. */
		public function test_publish_de_un_producto_encola_un_solo_job_con_los_args_del_producto(): void {
			( new PublishListener() )->on_transition( 'publish', 'draft', $this->post( 700 ) );

			self::assertCount( 1, $this->scheduled );
			self::assertSame( SeriesFollow::HOOK_SCAN, $this->scheduled[0]['hook'] );
			self::assertSame( SeriesFollow::AS_GROUP, $this->scheduled[0]['group'] );
			self::assertSame(
				array(
					'product_id' => 700,
					'cursor'     => 0,
				),
				$this->scheduled[0]['args'],
				'Los args viajan SIEMPRE con las dos claves: el dedupe de Action Scheduler matchea args exactos.'
			);
		}

		/** @testdox El scan se agenda con colchón: el importer asigna los términos después de insertar el post. */
		public function test_el_scan_no_corre_en_el_mismo_tick_que_la_publicacion(): void {
			$antes = time();
			( new PublishListener() )->on_transition( 'publish', 'draft', $this->post( 700 ) );

			self::assertGreaterThanOrEqual(
				$antes + PublishListener::SCAN_DELAY_SECONDS,
				$this->scheduled[0]['timestamp'],
				'Escanear en el mismo tick encontraría el producto sin pa_serie y lo descartaría en silencio, para siempre.'
			);
		}

		/** @testdox Guardar un producto YA publicado no encola nada. */
		public function test_guardar_un_producto_ya_publicado_no_encola_nada(): void {
			( new PublishListener() )->on_transition( 'publish', 'publish', $this->post( 700 ) );

			self::assertSame( array(), $this->scheduled );
		}

		/** @testdox Publicar algo que no es un producto no encola nada. */
		public function test_publicar_un_post_que_no_es_producto_no_encola_nada(): void {
			( new PublishListener() )->on_transition( 'publish', 'draft', $this->post( 700, 'post' ) );

			self::assertSame( array(), $this->scheduled );
		}

		/**
		 * El candado contra la trampa de `$unique`: `as_enqueue_async_action` con
		 * `$unique = true` dedupea por hook+group SIN mirar args, así que un
		 * import de 50 tomos encolaría A y descartaría B..Z en silencio.
		 *
		 * @testdox Un segundo publish del mismo producto no duplica el job.
		 */
		public function test_un_segundo_publish_del_mismo_producto_no_duplica_el_job(): void {
			$listener = new PublishListener();
			$listener->on_transition( 'publish', 'draft', $this->post( 700 ) );

			$this->ya_agendado = true;
			$listener->on_transition( 'publish', 'draft', $this->post( 700 ) );

			self::assertCount( 1, $this->scheduled );
			self::assertSame(
				array(
					'product_id' => 700,
					'cursor'     => 0,
				),
				$this->lookups[1]['args'],
				'El dedupe consulta por ARGS, no por hook+grupo.'
			);
		}

		/**
		 * `transition_post_status` corre en el camino del importer y del admin.
		 * Cualquier consulta añadida aquí se paga en cada publicación.
		 *
		 * @testdox El listener no lee términos ni hidrata el producto en transition_post_status.
		 */
		public function test_el_listener_no_hace_ninguna_consulta_en_transition_post_status(): void {
			Functions\expect( 'get_the_terms' )->never();
			Functions\expect( 'wc_get_product' )->never();
			Functions\expect( 'get_post_type' )->never();
			Functions\expect( 'get_post_status' )->never();

			( new PublishListener() )->on_transition( 'publish', 'draft', $this->post( 700 ) );

			self::assertCount( 1, $this->scheduled );
		}

		/** @testdox Asignar pa_serie después del insert encola el scan (red del importer). */
		public function test_asignar_pa_serie_despues_del_insert_encola_el_scan(): void {
			Functions\when( 'get_post_type' )->justReturn( 'product' );
			Functions\when( 'get_post_status' )->justReturn( 'publish' );

			( new PublishListener() )->on_terms( 700, array(), array( 1042 ), 'pa_serie', false, array() );

			self::assertCount( 1, $this->scheduled );
			self::assertSame( 700, $this->scheduled[0]['args']['product_id'] );
		}

		/** @testdox Guardar cualquier OTRA taxonomía sale sin leer nada. */
		public function test_otra_taxonomia_sale_sin_leer_el_post(): void {
			Functions\expect( 'get_post_type' )->never();
			Functions\expect( 'get_post_status' )->never();

			( new PublishListener() )->on_terms( 700, array(), array( 9 ), 'product_cat', false, array() );

			self::assertSame( array(), $this->scheduled );
		}

		/** @testdox Con los avisos apagados no se encola nada. */
		public function test_con_el_interruptor_apagado_no_encola(): void {
			Functions\when( 'get_option' )->justReturn( 'no' );

			( new PublishListener() )->on_transition( 'publish', 'draft', $this->post( 700 ) );

			self::assertSame( array(), $this->scheduled );
		}

		/**
		 * El import del dueño y el importer de WordPress corren por CLI/cron,
		 * donde `is_admin()` es false: un listener registrado sólo en el admin no
		 * vería ninguna de las publicaciones que importan.
		 *
		 * @testdox Los hooks se registran fuera de is_admin().
		 */
		public function test_el_listener_esta_registrado_fuera_de_is_admin(): void {
			Functions\when( 'is_admin' )->justReturn( false );

			( new PublishListener() )->register();

			self::assertTrue( has_action( 'transition_post_status' ) );
			self::assertTrue( has_action( 'set_object_terms' ) );
			self::assertTrue( has_action( 'woocommerce_product_set_stock_status' ) );
			self::assertTrue( has_action( 'woocommerce_variation_set_stock_status' ) );
			Actions\expectDone( 'nada' )->never();
		}

		/**
		 * @testdox Pasar un tomo publicado de agotado a Disponible o A pedido sella timestamp y encola el scan.
		 */
		public function test_pasar_producto_a_instock_o_onbackorder_encola_el_scan_y_sella_meta(): void {
			$sellados = array();
			Functions\when( 'get_post_type' )->justReturn( 'product' );
			Functions\when( 'get_post_status' )->justReturn( 'publish' );
			Functions\when( 'update_post_meta' )->alias(
				static function ( int $id, string $key, $val ) use ( &$sellados ): bool {
					$sellados[ $id ][ $key ] = $val;
					return true;
				}
			);

			$listener = new PublishListener();
			$listener->on_stock_status_change( 700, 'instock' );
			$listener->on_stock_status_change( 701, 'onbackorder' );

			self::assertCount( 2, $this->scheduled );
			self::assertSame( 700, $this->scheduled[0]['args']['product_id'] );
			self::assertSame( 701, $this->scheduled[1]['args']['product_id'] );
			self::assertArrayHasKey( PublishListener::META_RESTOCKED_AT, $sellados[700] ?? array() );
			self::assertArrayHasKey( PublishListener::META_RESTOCKED_AT, $sellados[701] ?? array() );
		}

		/**
		 * @testdox [NEG] Pasar un producto a outofstock o con el interruptor de reposición apagado no encola nada.
		 */
		public function test_pasar_a_outofstock_o_con_reposicion_apagada_no_encola(): void {
			Functions\when( 'get_post_type' )->justReturn( 'product' );
			Functions\when( 'get_post_status' )->justReturn( 'publish' );
			Functions\when( 'update_post_meta' )->justReturn( true );

			$listener = new PublishListener();
			$listener->on_stock_status_change( 700, 'outofstock' );
			self::assertSame( array(), $this->scheduled );

			Functions\when( 'get_option' )->alias(
				static fn ( string $opt, $def = false ) => SeriesFollow::OPTION_TRIGGER_RESTOCK === $opt ? 'no' : 'yes'
			);
			$listener->on_stock_status_change( 700, 'instock' );
			self::assertSame( array(), $this->scheduled );
		}
	}
}
