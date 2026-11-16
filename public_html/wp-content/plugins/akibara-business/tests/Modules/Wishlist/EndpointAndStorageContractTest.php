<?php
/**
 * Pruebas de contrato para `Wishlist\Storage` (deduplicación de consultas SQL
 * por request), `Wishlist\Endpoint` (cabeceras privadas y título del documento)
 * y `BackInStock\CaptureForm` (encolado en `/mi-cuenta/favoritos/` y búsqueda).
 *
 * @package Akibara\Business\Tests\Modules\Wishlist
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Wishlist;

use Akibara\Business\Modules\BackInStock\CaptureForm;
use Akibara\Business\Modules\Wishlist\Endpoint;
use Akibara\Business\Modules\Wishlist\Storage;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class EndpointAndStorageContractTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Storage::flush_cache();
	}

	protected function tearDown(): void {
		Storage::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Storage::product_ids() hidrata $ids_cache y $has_cache: count(), has() y paginación corren con 0 consultas SQL extra
	 */
	public function test_storage_deduplica_consultas_sql_por_request(): void {
		global $wpdb;
		$old_wpdb = $wpdb ?? null;

		$queries = 0;
		$wpdb    = new class( $queries ) {
			public string $prefix = 'wp_';
			private int $counter;

			public function __construct( int &$counter ) {
				$this->counter = &$counter;
			}

			public function prepare( string $query, ...$args ): string {
				unset( $args );
				return $query;
			}

			/** @return int[] */
			public function get_col( string $sql ): array {
				unset( $sql );
				++$this->counter;
				return array( 501, 502, 503 );
			}

			public function get_var( string $sql ): int {
				unset( $sql );
				++$this->counter;
				return 999;
			}
		};

		try {
			// 1. Primera lectura completa (ej. localize_script o has()): 1 consulta SQL.
			$all = Storage::product_ids( 77 );
			self::assertSame( array( 501, 502, 503 ), $all );
			self::assertSame( 1, $queries, 'La carga inicial ejecuta exactamente 1 SELECT.' );

			// 2. Lecturas subsiguientes en el mismo request (header badge, Render::count, loop has y paginación): 0 consultas SQL.
			self::assertSame( 3, Storage::count( 77 ) );
			self::assertTrue( Storage::has( 502, 77 ) );
			self::assertFalse( Storage::has( 999, 77 ) );
			self::assertSame( array( 501, 502 ), Storage::product_ids( 77, 2, 0 ) );
			self::assertSame( array( 503 ), Storage::product_ids( 77, 2, 2 ) );
			self::assertSame( 1, $queries, 'count(), has() y product_ids() paginado deben servirse desde $ids_cache con 0 queries adicionales.' );
		} finally {
			$wpdb = $old_wpdb;
		}
	}

	/**
	 * @testdox Endpoint registra cabeceras privadas y delega los metadatos al compositor común.
	 */
	public function test_endpoint_registra_cabeceras_privadas_y_titulo(): void {
		$endpoint = new Endpoint();

		Monkey\Actions\expectAdded( 'send_headers' )->once()->with( array( $endpoint, 'private_headers' ) );
		Monkey\Actions\expectAdded( 'template_redirect' )->once()->with( array( $endpoint, 'disable_page_cache' ), 1 );
		Monkey\Filters\expectAdded( 'document_title_parts' )->never();
		Monkey\Filters\expectAdded( 'rank_math/frontend/title' )->never();

		$endpoint->register();

		self::assertSame( 'Favoritos', $endpoint->endpoint_title( 'Mi cuenta' ) );
	}

	/**
	 * @testdox BackInStock\CaptureForm encola sus activos en /mi-cuenta/favoritos/ y en resultados de búsqueda
	 */
	public function test_capture_form_encola_en_favoritos_y_busqueda(): void {
		Functions\when( 'get_option' )->justReturn( 'yes' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'is_archive' )->justReturn( false );

		$form = new CaptureForm();

		// 1. En /mi-cuenta/favoritos/
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->alias( static fn( string $slug = '' ): bool => Endpoint::SLUG === $slug );
		self::assertTrue( $form->should_enqueue(), 'Las tarjetas agotadas en /mi-cuenta/favoritos/ necesitan el modal de Avísame cuando vuelva.' );

		// 2. En otra subpágina de Mi Cuenta (/mi-cuenta/orders/)
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		self::assertFalse( $form->should_enqueue(), 'No debe encolar en otros endpoints de cuenta sin tarjetas de producto.' );

		// 3. En resultados de búsqueda
		Functions\when( 'is_search' )->justReturn( true );
		self::assertTrue( $form->should_enqueue(), 'Las tarjetas agotadas en resultados de búsqueda necesitan el modal de Avísame cuando vuelva.' );
	}
}
