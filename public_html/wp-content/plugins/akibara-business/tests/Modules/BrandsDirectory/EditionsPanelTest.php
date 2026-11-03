<?php
/**
 * La pantalla «Editoriales · Ediciones»: guarda sólo lo enrolado, sólo lo que
 * cambia, purga por URL y se puede deshacer.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BrandsDirectory;

use Akibara\Business\Modules\BrandsDirectory\BrandLandings;
use Akibara\Business\Modules\BrandsDirectory\EditionFacts;
use Akibara\Business\Modules\BrandsDirectory\EditionsPanel;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Post;
use WP_Term;

/** `wp_safe_redirect` + `exit`: corta el flujo como en producción. */
final class PanelEdicionesRedirigido extends \RuntimeException {}

final class EditionsPanelTest extends TestCase {

	/** @var array<int, array<string, string>> */
	private array $meta = array();

	/** @var list<string> */
	private array $purged = array();

	/** @var int Veces que se purgó la caché de página entera. */
	private int $purged_all = 0;

	/** @var array<string, bool> Autoload con el que se escribió cada option. */
	private array $autoload = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	/** @var array<string, WP_Term> */
	private array $terms = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_POST                           = array();
		$GLOBALS['akibara_test_options'] = array();
		$this->meta       = array();
		$this->purged     = array();
		$this->purged_all = 0;
		$this->autoload   = array();
		$this->transients = array();

		$ar                   = new WP_Term( 263, 'panini-argentina', 'Panini Argentina' );
		$ar->taxonomy         = 'product_brand';
		$ar->count            = 372;
		$ar->term_taxonomy_id = 2630;
		$es                   = new WP_Term( 208, 'panini-espana', 'Panini España' );
		$es->taxonomy         = 'product_brand';
		$es->count            = 49;
		$es->term_taxonomy_id = 2080;
		$this->terms          = array( 'panini-argentina' => $ar, 'panini-espana' => $es );

		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value, ...$args ) => $value );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( $v ) );
		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $t ): string => strip_tags( $t ) );
		Functions\when( 'get_term_by' )->alias( fn( string $by, string $slug ) => $this->terms[ $slug ] ?? false );
		Functions\when( 'get_term' )->alias(
			function ( int $id, string $tax = '' ) {
				foreach ( $this->terms as $t ) {
					if ( $t->term_id === $id && 'product_brand' === $tax ) {
						return $t;
					}
				}
				return null;
			}
		);
		Functions\when( 'get_term_link' )->alias( static fn( WP_Term $t ): string => 'https://akibara.test/marca/' . $t->slug . '/' );
		Functions\when( 'get_term_meta' )->alias( fn( int $id, string $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_term_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->meta[ $id ][ $key ] = (string) $value;
				return true;
			}
		);
		Functions\when( 'delete_term_meta' )->alias(
			function ( int $id, string $key ): bool {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $k, $v, $autoload = null ): bool {
				$GLOBALS['akibara_test_options'][ $k ] = $v;
				$this->autoload[ $k ]                  = (bool) $autoload;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $k ): bool {
				unset( $GLOBALS['akibara_test_options'][ $k ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $k, $v ): bool {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $k ): bool {
				unset( $this->transients[ $k ] );
				return true;
			}
		);
		Functions\when( 'get_page_by_path' )->alias(
			static function () {
				$page              = new WP_Post();
				$page->ID          = 27450;
				$page->post_status = 'publish';
				return $page;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.test/panini/' );
		Functions\when( 'do_action' )->alias(
			function ( string $hook, ...$args ): void {
				if ( 'litespeed_purge_url' === $hook ) {
					$this->purged[] = (string) $args[0];
				}
				if ( 'litespeed_purge_all' === $hook ) {
					++$this->purged_all;
				}
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://akibara.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( array $a, string $u ): string => $u . '?' . http_build_query( $a ) );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new PanelEdicionesRedirigido();
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function guardar( array $post ): string {
		$_POST = $post;
		try {
			( new EditionsPanel() )->handle_save();
		} catch ( PanelEdicionesRedirigido $expected ) {
			// esperado
		}
		return (string) ( $this->transients['akb_editions_notice_1']['texto'] ?? '' );
	}

	/** @testdox Guarda los hechos y las series de la edición, y purga su archivo y la landing (nunca todo). */
	public function test_save_writes_facts_and_purges_by_url(): void {
		$aviso = $this->guardar(
			array(
				'akb_edition_enroll' => array( '263' ),
				'akb_edition'        => array( '263' => array( EditionFacts::META_IDIOMA => ' Español <em>rioplatense</em> ' ) ),
				'akb_edition_series' => array( '263' => array( '12', '11', '12' ) ),
			)
		);

		self::assertSame( 'Español rioplatense', $this->meta[263][ EditionFacts::META_IDIOMA ] );
		self::assertSame( '12,11', $this->meta[263][ EditionFacts::META_SERIES ] );
		self::assertSame( array( 'https://akibara.test/marca/panini-argentina/', 'https://akibara.test/panini/' ), $this->purged );
		self::assertStringContainsString( '1 cambio(s) guardado(s)', $aviso );
		self::assertNotEmpty( $GLOBALS['akibara_test_options']['akb_editions_backup'] ?? array(), 'Hay respaldo para «Deshacer».' );
	}

	/** @testdox Sin cambios no escribe, no purga y lo dice. */
	public function test_no_changes_means_no_writes(): void {
		$this->meta[263][ EditionFacts::META_IDIOMA ] = 'Español rioplatense';

		$aviso = $this->guardar(
			array(
				'akb_edition_enroll' => array( '263' ),
				'akb_edition'        => array( '263' => array( EditionFacts::META_IDIOMA => 'Español rioplatense' ) ),
			)
		);

		self::assertSame( array(), $this->purged );
		self::assertSame( 'Sin cambios que guardar.', $aviso );
	}

	/** @testdox Un id que el render no pintó (o de otra taxonomía) no escribe nada. */
	public function test_only_enrolled_rows_are_written(): void {
		$this->guardar(
			array(
				'akb_edition_enroll' => array( '999' ),
				'akb_edition'        => array( '999' => array( EditionFacts::META_IDIOMA => 'x' ), '263' => array( EditionFacts::META_IDIOMA => 'colado' ) ),
			)
		);

		self::assertSame( array(), $this->meta );
	}

	/** @testdox Las etiquetas de la familia se guardan como option y vuelven al literal si se vacían. */
	public function test_family_labels_are_options_with_defaults(): void {
		$this->guardar(
			array(
				'akb_family_enroll' => array( 'panini' ),
				'akb_family'        => array( 'panini' => array( 'callout_label' => 'Panini Manga', 'menu_label' => '', 'callout_detail' => 'colado' ) ),
			)
		);

		self::assertSame( 'Panini Manga', $GLOBALS['akibara_test_options']['akb_brand_landing_panini_callout_label'] );
		self::assertArrayNotHasKey( 'akb_brand_landing_panini_menu_label', $GLOBALS['akibara_test_options'], 'Vacío = literal: no se guarda una option vacía.' );
		self::assertArrayNotHasKey( 'akb_brand_landing_panini_callout_detail', $GLOBALS['akibara_test_options'], 'Un campo que nadie lee no se escribe.' );
		self::assertSame( 'Panini Manga', BrandLandings::all()['panini']['callout_label'] );
		self::assertContains( 'https://akibara.test/marca/panini-espana/', $this->purged, 'El conmutador de las dos ediciones cambia con la etiqueta.' );
	}

	/** @testdox Cambiar el texto del MENÚ purga la caché entera (está en todas las páginas); los hechos, sólo sus URLs. */
	public function test_menu_label_change_purges_everything(): void {
		$todo = 0;
		Functions\when( 'do_action' )->alias(
			function ( string $hook, ...$args ) use ( &$todo ): void {
				if ( 'litespeed_purge_all' === $hook ) {
					++$todo;
				}
				if ( 'litespeed_purge_url' === $hook ) {
					$this->purged[] = (string) $args[0];
				}
			}
		);
		$this->guardar(
			array(
				'akb_family_enroll' => array( 'panini' ),
				'akb_family'        => array( 'panini' => array( 'menu_label' => 'Panini (AR + ES)' ) ),
			)
		);

		self::assertSame( 1, $todo );
		self::assertSame( array(), $this->purged, 'Con purga total no hace falta la de URLs.' );
	}

	/** @testdox La pantalla pinta cada familia con sus ediciones, hechos y series elegibles. */
	public function test_render_lists_families_and_editions(): void {
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) $n );
		Functions\when( 'wc_price' )->alias( static fn( float $p ): string => '$' . (int) $p );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $t ): string => strip_tags( $t ) );
		$GLOBALS['wpdb'] = new \Akibara\Business\Tests\Support\EditionsWpdb();
		$GLOBALS['wpdb']->series[2630] = array( 11 => 33 );
		$GLOBALS['wpdb']->price[2630]  = 10990.0;
		Functions\when( 'get_term' )->alias(
			function ( int $id, string $tax = '' ) {
				if ( 'pa_serie' === $tax && 11 === $id ) {
					return new WP_Term( 11, 'fire-force', 'Fire Force' );
				}
				foreach ( $this->terms as $t ) {
					if ( $t->term_id === $id && 'product_brand' === $tax ) {
						return $t;
					}
				}
				return null;
			}
		);

		ob_start();
		( new EditionsPanel() )->render();
		$html = (string) ob_get_clean();
		unset( $GLOBALS['wpdb'] );

		self::assertStringContainsString( 'Panini Argentina', $html );
		self::assertStringContainsString( '372 productos · desde $10990', $html );
		self::assertStringContainsString( 'name="akb_edition[263][akibara_edition_idioma]"', $html );
		self::assertStringContainsString( 'name="akb_edition_series[263][]" value="11"', $html );
		self::assertStringContainsString( 'Fire Force', $html );
		self::assertStringContainsString( 'name="akb_family[panini][menu_label]"', $html );
		self::assertStringNotContainsString( 'callout_detail', $html, 'Un campo que nadie lee no se ofrece.' );
	}

	/** @testdox Las etiquetas se guardan con autoload: el menú las lee en todas las páginas. */
	public function test_labels_are_saved_with_autoload(): void {
		$this->guardar(
			array(
				'akb_family_enroll' => array( 'panini' ),
				'akb_family'        => array( 'panini' => array( 'callout_label' => 'Panini Manga', 'menu_label' => '' ) ),
			)
		);

		self::assertSame( true, $this->autoload['akb_brand_landing_panini_callout_label'] ?? null, 'Sin autoload sería una consulta suelta por página.' );
	}

	/** @testdox Deshacer un cambio del texto del menú purga la caché entera, como al guardarlo. */
	public function test_restoring_a_menu_label_purges_everything(): void {
		$this->guardar(
			array(
				'akb_family_enroll' => array( 'panini' ),
				'akb_family'        => array( 'panini' => array( 'callout_label' => 'Panini', 'menu_label' => 'Panini · todo' ) ),
			)
		);
		$this->purged = array();
		$this->purged_all = 0;

		try {
			( new EditionsPanel() )->handle_restore();
		} catch ( PanelEdicionesRedirigido $expected ) {
			// esperado
		}

		self::assertSame( 1, $this->purged_all, 'El menú vive en todas las páginas.' );
		self::assertArrayNotHasKey( 'akb_brand_landing_panini_menu_label', $GLOBALS['akibara_test_options'] );
	}

	/** @testdox Sin respaldo, deshacer avisa y no toca nada. */
	public function test_restore_without_backup_warns_and_writes_nothing(): void {
		$this->meta[263][ EditionFacts::META_IDIOMA ] = 'Intacto';
		$GLOBALS['akibara_test_options']['akb_editions_backup'] = array();

		try {
			( new EditionsPanel() )->handle_restore();
		} catch ( PanelEdicionesRedirigido $expected ) {
			// esperado
		}

		self::assertSame( 'No hay un guardado anterior que deshacer.', $this->transients['akb_editions_notice_1']['texto'] ?? '' );
		self::assertSame( 'Intacto', $this->meta[263][ EditionFacts::META_IDIOMA ] );
		self::assertSame( array(), $this->purged );
	}

	/** @testdox Deshacer repone también las etiquetas de la familia. */
	public function test_restore_puts_back_family_labels(): void {
		$this->guardar(
			array(
				'akb_family_enroll' => array( 'panini' ),
				'akb_family'        => array( 'panini' => array( 'callout_label' => 'Panini Manga', 'menu_label' => '' ) ),
			)
		);
		self::assertSame( 'Panini Manga', $GLOBALS['akibara_test_options']['akb_brand_landing_panini_callout_label'] ?? '' );
		$this->purged = array();

		try {
			( new EditionsPanel() )->handle_restore();
		} catch ( PanelEdicionesRedirigido $expected ) {
			// esperado
		}

		self::assertArrayNotHasKey( 'akb_brand_landing_panini_callout_label', $GLOBALS['akibara_test_options'], 'Vuelve al literal: la option se borra.' );
		self::assertContains( 'https://akibara.test/panini/', $this->purged );
	}

	/** @testdox En el selector sólo va en gris lo que entraría solo: con una elegida, la elegida no. */
	public function test_picker_marks_auto_only_for_the_fillers(): void {
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'checked' )->alias( static fn( $a, $b, $echo ): string => $a === $b ? ' checked' : '' );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) $n );
		Functions\when( 'wc_price' )->alias( static fn( float $p ): string => '$' . (int) $p );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $t ): string => strip_tags( $t ) );
		$GLOBALS['wpdb'] = new \Akibara\Business\Tests\Support\EditionsWpdb();
		$GLOBALS['wpdb']->series[2630] = array( 11 => 33, 12 => 31 );
		$this->meta[263][ EditionFacts::META_SERIES ] = '11';
		Functions\when( 'get_term' )->alias(
			function ( int $id, string $tax = '' ) {
				if ( 'pa_serie' === $tax ) {
					return new WP_Term( $id, 'serie-' . $id, 'Serie ' . $id );
				}
				foreach ( $this->terms as $t ) {
					if ( $t->term_id === $id && 'product_brand' === $tax ) {
						return $t;
					}
				}
				return null;
			}
		);

		ob_start();
		( new EditionsPanel() )->render();
		$html = (string) ob_get_clean();
		unset( $GLOBALS['wpdb'] );

		preg_match_all( '#<label class="akb-editions-picker__item( is-auto)?"><input[^>]*name="akb_edition_series\[263\]\[\]" value="(\d+)"([^>]*)>#', $html, $m, PREG_SET_ORDER );
		$por_serie = array();
		foreach ( $m as $fila ) {
			$por_serie[ (int) $fila[2] ] = array( 'auto' => '' !== $fila[1], 'checked' => false !== strpos( $fila[3], 'checked' ) );
		}
		self::assertSame( array( 'auto' => false, 'checked' => true ), $por_serie[11], 'La elegida va primero, marcada y sin gris.' );
		self::assertSame( array( 'auto' => true, 'checked' => false ), $por_serie[12], 'La que rellena hasta 6 va en gris.' );
	}

	/** @testdox Deshacer repone el guardado anterior. */
	public function test_restore_puts_back_the_previous_values(): void {
		$this->meta[263][ EditionFacts::META_IDIOMA ] = 'Antes';
		$this->guardar(
			array(
				'akb_edition_enroll' => array( '263' ),
				'akb_edition'        => array( '263' => array( EditionFacts::META_IDIOMA => 'Después' ) ),
			)
		);
		self::assertSame( 'Después', $this->meta[263][ EditionFacts::META_IDIOMA ] );

		try {
			( new EditionsPanel() )->handle_restore();
		} catch ( PanelEdicionesRedirigido $expected ) {
			// esperado
		}

		self::assertSame( 'Antes', $this->meta[263][ EditionFacts::META_IDIOMA ] );
		self::assertSame( array(), $GLOBALS['akibara_test_options']['akb_editions_backup'] );
		self::assertContains( 'https://akibara.test/marca/panini-argentina/', $this->purged, 'Deshacer cambia lo servido igual que guardar: misma purga.' );
	}
}
