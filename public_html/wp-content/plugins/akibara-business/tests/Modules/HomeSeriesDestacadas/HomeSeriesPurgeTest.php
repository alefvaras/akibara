<?php
/**
 * Guardar la vitrina «Series más buscadas» purga sólo sus páginas, y la
 * portada llena sus huecos con las series de reserva.
 *
 * ── Los defectos medidos (producción, 2026-09-26) ───────────────────────────
 * 1. La pestaña «Series en la portada» disparaba el purge all del panel
 *    (Cloudflare incluido) por una lista que sólo se ve en la portada: el
 *    dueño no podía reordenarla sin enfriar el sitio entero.
 * 2. El panel prometía «en el orden en que se muestran; máximo 24» y la
 *    portada pintaba 8 (`count="8"` en la página 210): cuatro series
 *    guardadas no salían en ningún lado, y el panel no lo decía.
 * 3. `render()` recortaba la lista a `count` ANTES de descartar las series sin
 *    tomos: una serie vacía dejaba un hueco aunque hubiera reservas debajo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\HomeSeriesDestacadas;

require_once __DIR__ . '/HomeSeriesEligibilityWpdb.php';

use Akibara\Business\Modules\HomeSeriesDestacadas\HomeSeriesDestacadas;
use Akibara\Business\Services\SeriesHubUrl;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class HomeSeriesPurgeTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, string> Etiquetas purgadas, en orden. */
	private array $purgas = array();

	/** @var array<int, string> Etiquetas puestas, en orden. */
	private array $etiquetas = array();

	private bool $purge_all = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );
		defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://akibara.cl/wp-content/plugins/akibara-business/' );

		$this->options = array( HomeSeriesDestacadas::OPTION_ENABLED => 'yes' );
		Functions\when( 'get_option' )->alias( fn ( string $key, $default = false ) => $this->options[ $key ] ?? $default );

		Actions\expectDone( 'litespeed_purge' )->zeroOrMoreTimes()->whenHappen(
			function ( $tag ): void {
				$this->purgas[] = (string) $tag;
			}
		);
		Actions\expectDone( 'litespeed_tag_add' )->zeroOrMoreTimes()->whenHappen(
			function ( $tag ): void {
				$this->etiquetas[] = (string) $tag;
			}
		);
		Actions\expectDone( 'litespeed_purge_all' )->zeroOrMoreTimes()->whenHappen(
			function (): void {
				$this->purge_all = true;
			}
		);
	}

	protected function tearDown(): void {
		\WP_Query::$akb_resolver = null;
		SeriesHubUrl::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Ganchos que el módulo registra, por nombre.
	 *
	 * @return array<string, array<int, array{0:callable,1:int}>>
	 */
	private function ganchos( HomeSeriesDestacadas $module ): array {
		$acciones = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $prioridad = 10, int $args = 1 ) use ( &$acciones ): bool {
				unset( $prioridad );
				$acciones[ $hook ][] = array( $callback, $args );
				return true;
			}
		);
		Functions\when( 'add_shortcode' )->justReturn( true );
		$module->register();

		return $acciones;
	}

	public function test_crear_o_cambiar_cualquiera_de_las_tres_options_purga(): void {
		$module   = new HomeSeriesDestacadas();
		$acciones = $this->ganchos( $module );

		foreach ( array( HomeSeriesDestacadas::OPTION_ENABLED, HomeSeriesDestacadas::OPTION_TITLE, HomeSeriesDestacadas::OPTION_SLUGS ) as $option ) {
			foreach ( array( 'add_option_', 'update_option_' ) as $gancho ) {
				self::assertContains( array( array( $module, 'purge_showcase' ), 0 ), $acciones[ $gancho . $option ] ?? array(), $gancho . $option );
			}
		}
		self::assertContains( array( array( $module, 'purge_on_deleted_option' ), 1 ), $acciones['deleted_option'] ?? array() );
	}

	public function test_cambiar_la_lista_purga_solo_la_vitrina_y_la_portada(): void {
		( new HomeSeriesDestacadas() )->purge_showcase();

		// 'F' es la etiqueta automática de la portada en LiteSpeed: cubre una
		// portada cacheada antes de que existiera la etiqueta propia.
		self::assertSame( array( HomeSeriesDestacadas::CACHE_TAG, 'F' ), $this->purgas );
		self::assertFalse( $this->purge_all, 'Nunca purga el sitio entero.' );
	}

	public function test_tres_options_cambiadas_en_un_guardado_purgan_una_sola_vez(): void {
		$module = new HomeSeriesDestacadas();
		$module->purge_showcase();
		$module->purge_showcase();
		$module->purge_on_deleted_option( HomeSeriesDestacadas::OPTION_TITLE );

		self::assertCount( 2, $this->purgas, 'Una sola ronda: la etiqueta propia y la de la portada.' );
	}

	public function test_borrar_otra_option_no_purga(): void {
		( new HomeSeriesDestacadas() )->purge_on_deleted_option( 'blogname' );

		self::assertSame( array(), $this->purgas );
	}

	public function test_borrar_una_option_de_la_vitrina_purga(): void {
		( new HomeSeriesDestacadas() )->purge_on_deleted_option( HomeSeriesDestacadas::OPTION_SLUGS );

		self::assertSame( array( HomeSeriesDestacadas::CACHE_TAG, 'F' ), $this->purgas );
	}

	public function test_nunca_anota_el_borde_por_prefijo(): void {
		// `EdgePurge::anotar( home_url( '/' ) )` purgaría por el prefijo
		// `akibara.cl/`: el borde entero.
		$fuente = (string) file_get_contents( AKIBARA_BUSINESS_DIR . 'src/Modules/HomeSeriesDestacadas/HomeSeriesDestacadas.php' );
		$codigo = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $fuente );

		self::assertStringNotContainsString( 'EdgePurge', $codigo );
		self::assertStringNotContainsString( 'litespeed_purge_all', $codigo );
	}

	public function test_la_pagina_se_etiqueta_aunque_la_vitrina_este_apagada(): void {
		// Si la portada apagada no llevara la etiqueta, encender la vitrina
		// purgaría una etiqueta que ninguna página tiene.
		$this->options[ HomeSeriesDestacadas::OPTION_ENABLED ] = 'no';

		self::assertSame( '', ( new HomeSeriesDestacadas() )->render( array() ) );
		self::assertSame( array( HomeSeriesDestacadas::CACHE_TAG ), $this->etiquetas );
	}

	public function test_una_serie_sin_tomos_deja_entrar_a_la_reserva(): void {
		$this->options[ HomeSeriesDestacadas::OPTION_SLUGS ] = "blue-lock\nvacia\nnaruto\none-piece";

		$html = $this->render_resolviendo( array( 'vacia' ), 3 );

		self::assertSame( 3, substr_count( $html, 'akb-home-series__item' ), 'count=3 pinta 3 aunque una de las 3 primeras no tenga tomos.' );
		self::assertStringContainsString( '/serie/one-piece/', $html, 'La reserva entra en el hueco.' );
		self::assertStringNotContainsString( '/serie/vacia/', $html );
	}

	public function test_no_resuelve_mas_series_de_las_que_pinta(): void {
		$this->options[ HomeSeriesDestacadas::OPTION_SLUGS ] = "blue-lock\nnaruto\none-piece\nfrieren";
		$lookups = 0;

		$this->render_resolviendo( array(), 2, $lookups );

		self::assertSame( 2, $lookups, 'Con las 2 primeras resueltas, las reservas no se consultan.' );
	}

	/**
	 * @dataProvider portadas
	 */
	public function test_el_panel_dice_cuantas_muestra_la_portada( string $contenido, ?int $esperado ): void {
		$this->options['page_on_front'] = 210;
		Functions\when( 'get_post_field' )->justReturn( $contenido );
		Functions\when( 'shortcode_parse_atts' )->alias(
			static function ( string $text ) {
				preg_match_all( '/(\w+)="([^"]*)"/', $text, $m, PREG_SET_ORDER );
				return '' === $text ? '' : array_column( $m, 2, 1 );
			}
		);

		self::assertSame( $esperado, HomeSeriesDestacadas::front_page_count() );
	}

	/** @return array<string, array{0:string,1:?int}> */
	public static function portadas(): array {
		return array(
			'producción hoy'        => array( '<p>x</p>[akb_series_destacadas count="8" columns="4"]', 8 ),
			'sin count'             => array( '[akb_series_destacadas]', HomeSeriesDestacadas::DEFAULT_COUNT ),
			'por encima del máximo' => array( '[akb_series_destacadas count="99"]', HomeSeriesDestacadas::MAX_SLUGS ),
			'sin el bloque'         => array( '<p>Hola</p>', null ),
		);
	}

	/**
	 * Pinta con una caché vacía y términos que resuelven, salvo los vacíos.
	 *
	 * @param array<int, string> $vacias  Slugs sin tomos publicados.
	 * @param int                $count   `count` del shortcode.
	 * @param int                $lookups Consultas de término hechas.
	 */
	private function render_resolviendo( array $vacias, int $count, int &$lookups = 0 ): string {
		Functions\when( 'sanitize_title' )->alias(
			static fn ( string $t ): string => trim( (string) preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $t ) ), '-' )
		);
		Functions\when( 'shortcode_atts' )->alias( static fn ( array $defaults, array $atts ): array => array_merge( $defaults, $atts ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.cl' . $path );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		SeriesHubUrl::reset();
		\WP_Query::$akb_resolver = static fn (): array => array();
		Functions\when( 'get_term_by' )->alias(
			static function ( string $field, string $slug ) use ( $vacias, &$lookups ) {
				unset( $field );
				++$lookups;
				$term           = new \WP_Term( 1000 + crc32( $slug ) % 1000, $slug, ucfirst( $slug ) );
				$term->taxonomy = 'pa_serie';
				$term->count    = in_array( $slug, $vacias, true ) ? 0 : 5;
				return $term;
			}
		);
		Functions\when( 'get_term_link' )->alias( static fn ( \WP_Term $term ): string => 'https://akibara.cl/serie/' . $term->slug . '/' );

		$wpdb            = new HomeSeriesEligibilityWpdb();
		$wpdb->fail      = true;
		$original        = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $wpdb;

		try {
			return ( new HomeSeriesDestacadas() )->render( array( 'count' => $count ) );
		} finally {
			if ( null === $original ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $original;
			}
		}
	}
}
