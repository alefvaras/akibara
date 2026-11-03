<?php
/**
 * Pruebas del vínculo editorial entre series y entradas publicadas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\RelatedGuides;

use Akibara\Business\Modules\RelatedGuides\SeriesRelatedGuide;
use Akibara\Business\Modules\SiteSpeed\EdgePurge;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://example.test/wp-content/plugins/akibara-business/' );
defined( 'AKIBARA_BUSINESS_VERSION' ) || define( 'AKIBARA_BUSINESS_VERSION', 'test-version' );

final class SeriesRelatedGuideTest extends TestCase {

	/** @var array<string,mixed> */
	private array $original_post = array();

	/** @var array<string,mixed> */
	private array $original_get = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_post = $_POST;
		$this->original_get  = $_GET;
		$_POST               = array();
		$_GET                = array();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		// `register()` decide en `is_admin()` si cuelga la columna del listado
		// de series; el contexto por defecto de estas pruebas es el front.
		Functions\when( 'is_admin' )->justReturn( false );

		// La purga de las guías al cambiar el vínculo (D4): permalinks del sitio
		// y el carril de Cloudflare encendido, como en producción.
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'get_permalink' )->alias( static fn( $id ): string => 'https://akibara.test/guia-' . (int) $id . '/' );
		Functions\when( 'home_url' )->justReturn( 'https://akibara.test' );
		Functions\when( 'get_option' )->alias( static fn( $option, $default_value = false ) => $default_value );
		$this->edge_pendientes( array() );
	}

	protected function tearDown(): void {
		$_POST = $this->original_post;
		$_GET  = $this->original_get;
		$this->edge_pendientes( array() );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Lee (y fija) las URLs que `EdgePurge` tiene anotadas para el borde.
	 *
	 * @param array<string,true>|null $nuevas Si se pasa, reemplaza la lista.
	 * @return array<int,string>
	 */
	private function edge_pendientes( ?array $nuevas = null ): array {
		$prop = new \ReflectionProperty( EdgePurge::class, 'pendientes' );
		$prop->setAccessible( true );
		if ( null !== $nuevas ) {
			$prop->setValue( null, $nuevas );
		}

		return array_keys( (array) $prop->getValue() );
	}

	/**
	 * Prepara un guardado autorizado del término 12 con la guía `$nueva`,
	 * cuando antes estaba ligada `$antes` (0 = ninguna).
	 */
	private function guardado_autorizado( int $antes, string $nueva ): void {
		$_POST = array(
			'akb_related_guide_nonce'   => 'valid',
			'akb_related_guide_post_id' => $nueva,
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'term_exists' )->justReturn( 12 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_term_meta' )->justReturn( $antes > 0 ? (string) $antes : '' );
		Functions\when( 'update_term_meta' )->justReturn( true );
		Functions\when( 'delete_term_meta' )->justReturn( true );
	}

	/**
	 * D4 (revisión T7-1): la guía recién ligada no mostraba la tarjeta de su
	 * serie hasta que vencía su caché, y la desligada seguía con la tarjeta de
	 * una serie que ya no era la suya.
	 *
	 * @testdox Cambiar la guía de una serie purga la vieja y la nueva, en LiteSpeed y en el borde.
	 */
	public function test_changing_the_guide_purges_the_old_and_the_new_one(): void {
		$this->guardado_autorizado( 91, '93' );
		Actions\expectDone( 'litespeed_purge_post' )->once()->with( 91 );
		Actions\expectDone( 'litespeed_purge_post' )->once()->with( 93 );

		( new SeriesRelatedGuide() )->handle_save_term( 12 );

		self::assertSame( array( 'https://akibara.test/guia-91/', 'https://akibara.test/guia-93/' ), $this->edge_pendientes() );
	}

	/**
	 * @testdox Ligar una guía a una serie que no tenía purga sólo esa guía.
	 */
	public function test_linking_a_first_guide_purges_it(): void {
		$this->guardado_autorizado( 0, '93' );
		Actions\expectDone( 'litespeed_purge_post' )->once()->with( 93 );

		( new SeriesRelatedGuide() )->handle_save_term( 12 );

		self::assertSame( array( 'https://akibara.test/guia-93/' ), $this->edge_pendientes() );
	}

	/**
	 * @testdox Quitar la guía purga la que estaba ligada.
	 */
	public function test_clearing_the_guide_purges_the_old_one(): void {
		$this->guardado_autorizado( 91, '0' );
		Actions\expectDone( 'litespeed_purge_post' )->once()->with( 91 );

		( new SeriesRelatedGuide() )->handle_save_term( 12 );

		self::assertSame( array( 'https://akibara.test/guia-91/' ), $this->edge_pendientes() );
	}

	/**
	 * Guardar la serie por otro motivo (el nombre, la descripción) no cambia el
	 * vínculo: purgar la guía ahí sería tirar su caché sin razón.
	 *
	 * @testdox Guardar la serie sin cambiar la guía no purga nada.
	 */
	public function test_saving_without_changing_the_guide_does_not_purge(): void {
		$this->guardado_autorizado( 91, '91' );
		Actions\expectDone( 'litespeed_purge_post' )->never();

		( new SeriesRelatedGuide() )->handle_save_term( 12 );

		self::assertSame( array(), $this->edge_pendientes() );
	}

	/**
	 * @testdox Una guía rechazada (no publicada) no purga nada.
	 */
	public function test_a_rejected_guide_does_not_purge(): void {
		$this->guardado_autorizado( 91, '93' );
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		Actions\expectDone( 'litespeed_purge_post' )->never();

		( new SeriesRelatedGuide() )->handle_save_term( 12 );

		self::assertSame( array(), $this->edge_pendientes() );
	}

	/** @testdox Should register selective guide-content invalidation hooks. */
	public function test_registers_guide_content_invalidation_hooks(): void {
		$module = new SeriesRelatedGuide();
		$module->register();

		self::assertNotFalse( Monkey\Actions\has( 'post_updated', array( $module, 'handle_guide_post_updated' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'transition_post_status', array( $module, 'handle_guide_status_transition' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'before_delete_post', array( $module, 'handle_guide_post_deleted' ) ) );

		// La columna del listado de términos NO se cuelga en front: sus dos
		// filtros sólo se disparan en pantallas de admin.
		self::assertFalse( Monkey\Filters\has( 'manage_edit-pa_serie_columns', array( $module, 'handle_admin_column' ) ) );
	}

	/**
	 * Arriba de la rejilla la guía empujaba el primer tomo 159 px (auditoría del
	 * tema, UM-1). Va bajo la rejilla: después de la paginación (10) y de la
	 * ficha de datos de la serie (`SeriesFacts::render()`, 20).
	 *
	 * @testdox Should print the guide below the grid, after the pagination and the series sheet.
	 */
	public function test_renders_below_the_grid_after_the_series_sheet(): void {
		$module = new SeriesRelatedGuide();
		$module->register();

		self::assertFalse( Monkey\Actions\has( 'woocommerce_before_shop_loop', array( $module, 'handle_render_related_guide' ) ) );
		self::assertSame( 25, Monkey\Actions\has( 'woocommerce_after_shop_loop', array( $module, 'handle_render_related_guide' ) ) );
	}

	/** @testdox Should hang the series-list column only on admin screens. */
	public function test_registers_the_series_list_column_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$module = new SeriesRelatedGuide();
		$module->register();

		self::assertNotFalse( Monkey\Filters\has( 'manage_edit-pa_serie_columns', array( $module, 'handle_admin_column' ) ) );
		self::assertNotFalse( Monkey\Filters\has( 'manage_pa_serie_custom_column', array( $module, 'handle_render_admin_column' ) ) );
	}

	/** @testdox Should coalesce guide title, slug, status and delete changes into exact series purges. */
	public function test_purges_every_dependent_series_after_guide_content_changes(): void {
		$module = new SeriesRelatedGuide();
		$before = (object) array(
			'ID'         => 91,
			'post_type'  => 'post',
			'post_title' => 'Guía anterior',
			'post_name'  => 'guia-anterior',
		);
		$after  = (object) array(
			'ID'         => 91,
			'post_type'  => 'post',
			'post_title' => 'Guía actualizada',
			'post_name'  => 'guia-actualizada',
		);

		Monkey\Actions\expectAdded( 'shutdown' )
			->once()
			->with( array( $module, 'flush_pending_guide_purges' ), PHP_INT_MAX );
		Functions\expect( 'get_terms' )
			->once()
			->with( \Mockery::on( static fn( array $args ): bool => array( '91' ) === $args['meta_query'][0]['value'] ) )
			->andReturn( array( 12, 34, 12 ) );
		Monkey\Actions\expectDone( 'litespeed_purge' )
			->twice()
			->with( \Mockery::on( static fn( string $tag ): bool => in_array( $tag, array( 'T.12', 'T.34' ), true ) ) );

		$module->handle_guide_post_updated( 91, $after, $before );
		$module->handle_guide_status_transition( 'draft', 'publish', $after );
		$module->handle_guide_post_deleted( 91, $after );
		$module->flush_pending_guide_purges();

		self::assertTrue( true, 'Las tres rutas compartieron una consulta y dos tags únicos.' );
	}

	/** @testdox Should ignore no-op or non-post updates when invalidating guides. */
	public function test_ignores_guide_updates_that_cannot_change_the_block(): void {
		$module = new SeriesRelatedGuide();
		$post   = (object) array(
			'ID'         => 91,
			'post_type'  => 'post',
			'post_title' => 'Sin cambios',
			'post_name'  => 'sin-cambios',
		);

		$module->handle_guide_post_updated( 91, $post, clone $post );
		$module->handle_guide_status_transition( 'publish', 'publish', $post );
		$module->handle_guide_post_deleted( 91, (object) array( 'post_type' => 'page' ) );
		$module->flush_pending_guide_purges();

		self::assertTrue( true, 'No-op paths must not schedule or purge anything.' );
	}

	/**
	 * @testdox Should persist only a published post for an authorized term edit.
	 */
	public function test_saves_a_valid_published_guide(): void {
		$_POST = array(
			'akb_related_guide_nonce'   => 'valid',
			'akb_related_guide_post_id' => '91',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_product_terms' )
			->andReturn( true );
		Functions\when( 'term_exists' )->justReturn( 12 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		$update = array();
		Functions\when( 'update_term_meta' )->alias(
			static function ( int $term_id, string $key, int $post_id ) use ( &$update ): bool {
				$update = array( $term_id, $key, $post_id );
				return true;
			}
		);

		( new SeriesRelatedGuide() )->handle_save_term( 12 );
		self::assertSame( array( 12, SeriesRelatedGuide::META_KEY, 91 ), $update );
	}

	/**
	 * @testdox Should reject an unauthorized or unpublished guide without changing meta.
	 */
	public function test_rejects_invalid_guide_updates(): void {
		$_POST = array(
			'akb_related_guide_nonce'   => 'valid',
			'akb_related_guide_post_id' => '91',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'term_exists' )->justReturn( 12 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		$write_count = 0;
		Functions\when( 'update_term_meta' )->alias(
			static function () use ( &$write_count ): bool {
				++$write_count;
				return true;
			}
		);
		Functions\when( 'delete_term_meta' )->alias(
			static function () use ( &$write_count ): bool {
				++$write_count;
				return true;
			}
		);

		( new SeriesRelatedGuide() )->handle_save_term( 12 );
		self::assertSame( 0, $write_count );
	}

	/**
	 * @testdox Should require WooCommerce product-term management capability.
	 */
	public function test_rejects_user_without_product_term_capability(): void {
		$_POST = array(
			'akb_related_guide_nonce'   => 'valid',
			'akb_related_guide_post_id' => '91',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_product_terms' )
			->andReturn( false );
		Functions\when( 'term_exists' )->justReturn( 12 );
		$write_count = 0;
		Functions\when( 'update_term_meta' )->alias(
			static function () use ( &$write_count ): bool {
				++$write_count;
				return true;
			}
		);
		Functions\when( 'delete_term_meta' )->alias(
			static function () use ( &$write_count ): bool {
				++$write_count;
				return true;
			}
		);

		( new SeriesRelatedGuide() )->handle_save_term( 12 );
		self::assertSame( 0, $write_count );
	}

	/**
	 * @testdox Should remove the relation when the UI selects no guide.
	 */
	public function test_deletes_meta_when_guide_is_cleared(): void {
		$_POST = array(
			'akb_related_guide_nonce'   => 'valid',
			'akb_related_guide_post_id' => '0',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'term_exists' )->justReturn( 12 );
		$deleted = array();
		Functions\when( 'delete_term_meta' )->alias(
			static function ( int $term_id, string $key ) use ( &$deleted ): bool {
				$deleted = array( $term_id, $key );
				return true;
			}
		);

		( new SeriesRelatedGuide() )->handle_save_term( 12 );
		self::assertSame( array( 12, SeriesRelatedGuide::META_KEY ), $deleted );
	}

	/**
	 * @testdox Should render the guide once without adding an H1 or schema.
	 */
	public function test_renders_guide_only_on_the_clean_first_page(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 1 );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'  => 12,
				'taxonomy' => 'pa_serie',
			)
		);
		Functions\when( 'get_term_meta' )->justReturn( 91 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_the_title' )->justReturn( 'Cómo leer la serie' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/guia/' );

		ob_start();
		( new SeriesRelatedGuide() )->handle_render_related_guide();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="akb-related-guide"', $html );
		self::assertStringContainsString( 'Guía de lectura', $html );
		self::assertStringContainsString( 'Leer la guía', $html );
		self::assertStringNotContainsString( '<h1', $html );
		self::assertStringNotContainsString( 'application/ld+json', $html );

		$_GET = array( '_genero' => 'aventura' );
		ob_start();
		( new SeriesRelatedGuide() )->handle_render_related_guide();
		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * «Limpia» es SIN FACETAS, no «sin cadena de consulta».
	 *
	 * El guard comparaba `! empty( $_GET )`, así que la misma landing —misma
	 * colección, mismo canonical— perdía el bloque editorial en cuanto llegaba
	 * con `?utm_source=…`, `?gclid=…` o `?fbclid=…`. Es decir: justo el tráfico
	 * de campaña, el que más necesita el contexto, era el único que no lo veía.
	 *
	 * @dataProvider consultas_de_landing
	 *
	 * @testdox Should keep the guide under tracking parameters and drop it only for real facets.
	 *
	 * @param array<string,string> $query    Cadena de consulta de la petición.
	 * @param bool                 $esperado Si la landing sigue siendo la canónica limpia.
	 * @param string               $porque   Motivo legible del caso.
	 */
	public function test_tracking_parameters_do_not_hide_the_guide( array $query, bool $esperado, string $porque ): void {
		$term            = new \WP_Term( 77, 'one-piece', 'One Piece' );
		$term->taxonomy  = 'pa_serie';

		Functions\when( 'is_tax' )->alias( static fn( $requested ): bool => 'pa_serie' === $requested );
		Functions\when( 'get_queried_object' )->justReturn( $term );
		Functions\when( 'get_query_var' )->justReturn( 0 );

		$_GET = $query;

		self::assertSame( $esperado, ( new SeriesRelatedGuide() )->is_clean_series_archive(), $porque );
	}

	/**
	 * @return array<string, array{0:array<string,string>,1:bool,2:string}>
	 */
	public static function consultas_de_landing(): array {
		return array(
			'sin cadena de consulta'   => array( array(), true, 'La landing desnuda es la canónica.' ),
			'utm de campaña'           => array( array( 'utm_source' => 'instagram', 'utm_medium' => 'social' ), true, 'Un utm no cambia la colección.' ),
			'clic de Google Ads'       => array( array( 'gclid' => 'abc123' ), true, 'gclid lo añade el anunciante, no el catálogo.' ),
			'clic de Meta'             => array( array( 'fbclid' => 'abc123' ), true, 'fbclid lo añade la red, no el catálogo.' ),
			'faceta del buscador'      => array( array( '_disponibilidad' => 'instock' ), false, 'Una faceta SÍ define otra colección.' ),
			'orden del catálogo'       => array( array( 'orderby' => 'price' ), false, 'Reordenar produce una variante.' ),
			'filtro de WooCommerce'    => array( array( 'filter_editorial' => 'ivrea' ), false, 'La gramática de WooCommerce también cuenta.' ),
			'campaña MÁS faceta'       => array( array( 'utm_source' => 'ig', '_disponibilidad' => 'instock' ), false, 'Basta una faceta para dejar de ser la canónica.' ),
		);
	}
}
