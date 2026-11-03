<?php
/**
 * Tests del cron de relleno automático y del pipeline batch de UpsellsAutofill.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
}

namespace Akibara\Business\Tests\Modules\UpsellsAutofill {

use Akibara\Business\Modules\UpsellsAutofill\UpsellsAutofill;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Term;

/**
 * Cobertura del scheduling, el guard de la option de última corrida, la purga
 * por PDP y la deserialización del mapa de upsells.
 */
final class UpsellsAutofillTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Desde el interruptor, `maybe_schedule()` y `cron_fill()` preguntan por
		// la option antes de nada. Se declara por defecto para toda la clase
		// —devolviendo el valor de fábrica— y cada test que necesite otra cosa la
		// vuelve a declarar: sin esto, un test que no la mockea revienta.
		Functions\when( 'get_option' )->alias( static fn( $clave, $por_defecto = false ) => $por_defecto );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox register() engancha el callback del cron y el auto-scheduling en init
	 */
	public function test_register_wires_cron_callback_and_scheduling(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$module = new UpsellsAutofill();

		Monkey\Actions\expectAdded( UpsellsAutofill::CRON_HOOK )
			->once()
			->with( array( $module, 'cron_fill' ) );
		Monkey\Actions\expectAdded( 'init' )
			->once()
			->with( array( $module, 'maybe_schedule' ) );

		$module->register();
		self::addToAssertionCount( 2 );
	}

	/**
	 * @testdox maybe_schedule() agenda el evento diario cuando no existe
	 */
	public function test_maybe_schedule_creates_daily_event_when_missing(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( UpsellsAutofill::CRON_HOOK )
			->andReturn( false );
		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( \Mockery::type( 'int' ), 'daily', UpsellsAutofill::CRON_HOOK );

		( new UpsellsAutofill() )->maybe_schedule();
		self::addToAssertionCount( 1 );
	}

	/**
	 * El manual del dueño lo declaraba como defecto con todas las letras: «No
	 * tiene interruptor: sólo se apaga desactivando el plugin». Apagar la tienda
	 * entera para apagar una recomendación.
	 *
	 * @testdox El interruptor nace encendido y sólo un «no» explícito lo apaga
	 */
	public function test_el_interruptor_nace_encendido(): void {
		self::assertTrue(
			UpsellsAutofill::is_enabled(),
			'una option ausente —sitio recién desplegado, base restaurada— no puede apagar lo que llevaba años corriendo'
		);

		Functions\when( 'get_option' )->justReturn( 'no' );
		self::assertFalse( UpsellsAutofill::is_enabled() );

		Functions\when( 'get_option' )->justReturn( 'yes' );
		self::assertTrue( UpsellsAutofill::is_enabled() );
	}

	/**
	 * Se retiran TODAS las ocurrencias, no una: `wp_unschedule_event()` quita la
	 * primera, así que un array de cron con duplicados tardaba varias peticiones
	 * en converger y el panel diría una cosa y la cola otra (revisión
	 * independiente, 2026-09-16).
	 *
	 * @testdox Apagado, maybe_schedule() retira el hook completo y no agenda nada
	 */
	public function test_apagado_retira_el_hook_completo(): void {
		Functions\when( 'get_option' )->justReturn( 'no' );
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( UpsellsAutofill::CRON_HOOK );
		Functions\expect( 'wp_schedule_event' )->never();
		Functions\expect( 'wp_next_scheduled' )->never();

		( new UpsellsAutofill() )->maybe_schedule();
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox maybe_schedule() no re-agenda un evento ya existente
	 */
	public function test_maybe_schedule_is_idempotent(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( UpsellsAutofill::CRON_HOOK )
			->andReturn( time() + 100 );
		Functions\expect( 'wp_schedule_event' )->never();

		( new UpsellsAutofill() )->maybe_schedule();
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox clear_schedule() retira el hook recurrente
	 */
	public function test_clear_schedule_removes_recurring_hook(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( UpsellsAutofill::CRON_HOOK );

		UpsellsAutofill::clear_schedule();
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox cron_fill() sin candidatos no pisa la option de última corrida
	 */
	public function test_cron_fill_without_candidates_preserves_last_run(): void {
		$GLOBALS['wpdb'] = $this->wpdb_returning_candidates( array() );

		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_transient' )->never();

		( new UpsellsAutofill() )->cron_fill();
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox purge_product_pages() purga cada PDP una sola vez vía la API de LiteSpeed
	 */
	public function test_purge_product_pages_deduplicates_and_skips_invalid(): void {
		Actions\expectDone( 'litespeed_purge_post' )->once()->with( 5 );
		Actions\expectDone( 'litespeed_purge_post' )->once()->with( 7 );

		$method = new ReflectionMethod( UpsellsAutofill::class, 'purge_product_pages' );
		$method->setAccessible( true );
		$method->invoke( new UpsellsAutofill(), array( 5, 5, 0, 7 ) );

		self::addToAssertionCount( 2 );
	}

	/**
	 * @testdox products_with_upsell_meta() deserializa el meta y descarta corruptos
	 */
	public function test_products_with_upsell_meta_unserializes_map(): void {
		Functions\when( 'maybe_unserialize' )->alias(
			static function ( $value ) {
				$unserialized = @unserialize( (string) $value, array( 'allowed_classes' => false ) );
				return false === $unserialized ? $value : $unserialized;
			}
		);

		$GLOBALS['wpdb'] = $this->wpdb_returning_meta_rows(
			array(
				array(
					'id'      => '11',
					'upsells' => 'a:3:{i:0;i:21;i:1;s:2:"22";i:2;i:0;}',
				),
				array(
					'id'      => '12',
					'upsells' => 'basura-no-serializada',
				),
			)
		);

		$method = new ReflectionMethod( UpsellsAutofill::class, 'products_with_upsell_meta' );
		$method->setAccessible( true );
		$map = $method->invoke( new UpsellsAutofill() );

		self::assertSame(
			array(
				11 => array( 21, 22 ),
				12 => array(),
			),
			$map
		);
	}

	/**
	 * @testdox serie_term_taxonomy_ids() mapea WP_Term a tt_ids y tolera false
	 */
	public function test_serie_term_taxonomy_ids_reads_cached_terms(): void {
		$term                   = new WP_Term( 9, 'one-piece', 'One Piece' );
		$term->term_taxonomy_id = 42;

		Functions\when( 'get_the_terms' )->justReturn( array( $term ) );

		$method = new ReflectionMethod( UpsellsAutofill::class, 'serie_term_taxonomy_ids' );
		$method->setAccessible( true );

		self::assertSame( array( 42 ), $method->invoke( new UpsellsAutofill(), 11 ) );

		Functions\when( 'get_the_terms' )->justReturn( false );
		self::assertSame( array(), $method->invoke( new UpsellsAutofill(), 11 ) );
	}

	/**
	 * @testdox classify_link() distingue rotos, cross-serie, retrospectivos y válidos
	 * @dataProvider link_classification_provider
	 *
	 * @param array<int,int> $current_tts
	 * @param array<int,int> $upsell_tts
	 */
	public function test_classify_link_matrix(
		bool $alive,
		?int $current_number,
		?int $upsell_number,
		array $current_tts,
		array $upsell_tts,
		string $expected
	): void {
		self::assertSame(
			$expected,
			UpsellsAutofill::classify_link( $alive, $current_number, $upsell_number, $current_tts, $upsell_tts )
		);
	}

	/**
	 * @return array<string, array{bool, ?int, ?int, array<int,int>, array<int,int>, string}>
	 */
	public function link_classification_provider(): array {
		return array(
			'destino borrado o despublicado'      => array( false, 5, null, array( 1 ), array(), UpsellsAutofill::LINK_DEAD ),
			'producto especial sin número'        => array( true, null, 3, array( 1 ), array( 1 ), UpsellsAutofill::LINK_OK ),
			'otra serie (curaduría o resto CSV)'  => array( true, 1, 21, array( 1 ), array( 2 ), UpsellsAutofill::LINK_CROSS_SERIES ),
			'tomo anterior misma serie (retro)'   => array( true, 28, 27, array( 1 ), array( 1 ), UpsellsAutofill::LINK_RETRO ),
			'mismo tomo (edición duplicada)'      => array( true, 5, 5, array( 1 ), array( 1 ), UpsellsAutofill::LINK_RETRO ),
			'tomo siguiente misma serie'          => array( true, 5, 6, array( 1 ), array( 1 ), UpsellsAutofill::LINK_OK ),
			'especial de la misma serie sin nro'  => array( true, 5, null, array( 1 ), array( 1 ), UpsellsAutofill::LINK_OK ),
		);
	}

	/**
	 * @testdox signature() es estable y sensible al orden de la lista
	 */
	public function test_signature_is_order_sensitive_and_stable(): void {
		self::assertSame(
			UpsellsAutofill::signature( array( 1, 2, 3 ) ),
			UpsellsAutofill::signature( array( '1', '2', '3' ) )
		);
		self::assertNotSame(
			UpsellsAutofill::signature( array( 1, 2, 3 ) ),
			UpsellsAutofill::signature( array( 3, 2, 1 ) )
		);
	}

	/**
	 * Fake de wpdb cuyo get_col devuelve la lista dada (candidatos del fill).
	 *
	 * @param array<int, int> $candidates IDs candidatos.
	 */
	private function wpdb_returning_candidates( array $candidates ): object {
		return new class( $candidates ) {
			public string $posts              = 'wp_posts';
			public string $postmeta           = 'wp_postmeta';
			public string $term_relationships = 'wp_term_relationships';
			public string $term_taxonomy      = 'wp_term_taxonomy';

			/** @param array<int, int> $candidates */
			public function __construct( private array $candidates ) {}

			/** @param mixed ...$args */
			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			/** @return array<int, int> */
			public function get_col( string $query ): array {
				return $this->candidates;
			}
		};
	}

	/**
	 * Fake de wpdb cuyo get_results devuelve filas id/upsells (mapa de meta).
	 *
	 * @param array<int, array{id: string, upsells: string}> $rows Filas simuladas.
	 */
	private function wpdb_returning_meta_rows( array $rows ): object {
		return new class( $rows ) {
			public string $posts    = 'wp_posts';
			public string $postmeta = 'wp_postmeta';

			/** @param array<int, array{id: string, upsells: string}> $rows */
			public function __construct( private array $rows ) {}

			/** @return array<int, array{id: string, upsells: string}> */
			public function get_results( string $query, string $output ): array {
				return $this->rows;
			}
		};
	}
}
}
