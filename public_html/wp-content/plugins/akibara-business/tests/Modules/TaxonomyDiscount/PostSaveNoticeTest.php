<?php
/**
 * El aviso post-guardado de «Descuentos» sólo habla si hubo cambio.
 *
 * Antes se pintaba ante cualquier `?saved=1` de la pestaña —bastaba entrar y
 * apretar Guardar sin tocar nada— y además contradecía al aviso del
 * `AdminPanel`: `taxonomy_discount` no está exento del purge central, así que
 * el panel decía «se limpió la caché del sitio — el cambio ya está visible»
 * justo encima de un «puede tardar hasta 30 segundos».
 *
 * `akibara_taxonomy_discount_pct_saved` NO sirve como señal de cambio: se
 * dispara una vez por fila posteada (≈277) aunque no se haya tocado ninguna.
 * La señal real son los hooks de meta/opción, que WordPress sólo dispara
 * cuando el valor persistido cambia.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Modules\TaxonomyDiscount\TaxonomyDiscount;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PostSaveNoticeTest extends TestCase {

	/** @var array<string, mixed> */
	private array $original_get;

	/** Transients simulados. @var array<string, mixed> */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_get = $_GET;
		$this->transients   = array();

		$_GET = array(
			'page'  => 'akibara-panel',
			'tab'   => TaxonomyDiscount::PANEL_TAB_ID,
			'saved' => '1',
		);

		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'home_url' )->returnArg( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );

				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Guardar sin cambiar nada no deja ningún aviso de caché.
	 */
	public function test_saving_without_changes_is_silent(): void {
		$module = new TaxonomyDiscount();

		// Guardado real de la pestaña: la acción llega igual aunque nada cambie.
		$module->note_tab_saved();
		$module->persist_save_trace();

		self::assertSame( '', $this->render( $module ) );
	}

	/**
	 * @testdox El aviso dice cuántos términos cambiaron de porcentaje.
	 */
	public function test_notice_reports_how_many_terms_changed(): void {
		$module = new TaxonomyDiscount();

		$module->note_tab_saved();
		$module->note_term_pct_change( 1, 101, Settings::TERM_META_PCT );
		$module->note_term_pct_change( 2, 202, Settings::TERM_META_PCT );
		// El mismo término dos veces (delete + add) cuenta una sola vez.
		$module->note_term_pct_change( 3, 202, Settings::TERM_META_PCT );
		// Meta ajena: no es un cambio de precio.
		$module->note_term_pct_change( 4, 303, '_yoast_wpseo_focuskw' );
		$module->persist_save_trace();

		$html = $this->render( $module );

		self::assertStringContainsString( 'de 2 términos', $html );
	}

	/**
	 * @testdox Sin rastro del guardado el aviso se pinta igual (degradación abierta).
	 */
	public function test_missing_trace_still_warns_about_cache(): void {
		$html = $this->render( new TaxonomyDiscount() );

		self::assertStringContainsString( 'purga de caché', $html );
	}

	/**
	 * @testdox Un cambio de term-meta fuera del panel no inventa un aviso.
	 */
	public function test_term_meta_written_outside_the_panel_leaves_no_trace(): void {
		$module = new TaxonomyDiscount();

		$module->note_term_pct_change( 1, 101, Settings::TERM_META_PCT );
		$module->persist_save_trace();

		self::assertSame( array(), $this->transients );
	}

	/**
	 * @testdox Guardar un descuento tira el memo de reglas de esa misma petición.
	 *
	 * Los memos de términos y de reglas viven toda la petición para no rehacer
	 * el cálculo por cada producto del catálogo. En la petición que GUARDA sí
	 * cambian las reglas, y servir las viejas produce el síntoma más difícil de
	 * diagnosticar que existe: «guardé el descuento y no se aplicó», que
	 * desaparece al recargar.
	 */
	public function test_saving_a_discount_drops_this_requests_rule_memo(): void {
		$module = new TaxonomyDiscount();

		$memo = new \ReflectionProperty( TaxonomyDiscount::class, 'active_rules_cache' );
		$memo->setAccessible( true );
		$terminos = new \ReflectionProperty( TaxonomyDiscount::class, 'terms_cache' );
		$terminos->setAccessible( true );

		$memo->setValue( $module, array( array( 'taxonomy' => 'product_brand', 'pct' => 10 ) ) );
		$terminos->setValue( $module, array( 'product_brand' => array( (object) array( 'term_id' => 7 ) ) ) );

		$module->note_term_pct_change( 1, 7, Settings::TERM_META_PCT );

		self::assertNull( $memo->getValue( $module ), 'El memo de reglas tiene que quedar vacío tras el cambio.' );
		self::assertSame( array(), $terminos->getValue( $module ), 'Y el de términos también: alimenta al de reglas.' );
	}

	/**
	 * @testdox Un cambio de opción del módulo también tira el memo.
	 */
	public function test_a_config_change_also_drops_the_memo(): void {
		$module = new TaxonomyDiscount();

		$memo = new \ReflectionProperty( TaxonomyDiscount::class, 'active_rules_cache' );
		$memo->setAccessible( true );
		$memo->setValue( $module, array( array( 'taxonomy' => 'product_cat', 'pct' => 5 ) ) );

		$module->note_config_change( 'akibara_taxonomy_discount_enabled' );

		self::assertNull( $memo->getValue( $module ) );
	}

	/**
	 * @testdox Una meta ajena NO tira el memo: sería tirarlo en cada guardado del sitio.
	 */
	public function test_an_unrelated_meta_leaves_the_memo_alone(): void {
		$module = new TaxonomyDiscount();

		$memo = new \ReflectionProperty( TaxonomyDiscount::class, 'active_rules_cache' );
		$memo->setAccessible( true );
		$memo->setValue( $module, array( array( 'taxonomy' => 'product_cat', 'pct' => 5 ) ) );

		$module->note_term_pct_change( 1, 7, '_yoast_wpseo_focuskw' );
		$module->note_config_change( 'blogname' );

		self::assertNotNull( $memo->getValue( $module ), 'Tirarlo con cualquier meta anularía el memo entero.' );
	}

	/**
	 * S-2a251a57 (2026-09-19): `note_config_change()` sólo estaba enganchado a
	 * `updated_option`, el hook GLOBAL que WordPress dispara cuando una fila que
	 * YA EXISTÍA cambia de valor. La primera vez que se escribe una option —la
	 * fila no existe todavía— WordPress llama `add_option()` por debajo, que
	 * dispara `added_option`, no `updated_option`. Encender la campaña por
	 * primera vez en un sitio donde `akibara_taxonomy_discount_enabled` nunca se
	 * había guardado tomaba justo esa rama: `note_config_change()` no corría,
	 * `changed_config` se quedaba en `false`, y el aviso de «Precios» de
	 * `render_post_save_notice()` no se pintaba pese a que sí hubo un cambio.
	 *
	 * Comprobar el CABLEADO (`has_action`) hace falta porque la otra prueba de
	 * esta clase —`test_a_config_change_also_drops_the_memo`— llama al método
	 * directo y no puede distinguir «está enganchado sólo a medias» de «está
	 * bien enganchado»: las dos pasan igual si falta `added_option`.
	 *
	 * @testdox `note_config_change` también escucha la PRIMERA escritura de una option (`added_option`).
	 */
	public function test_the_first_ever_write_is_not_missed(): void {
		$module = new TaxonomyDiscount();
		$module->register();

		self::assertNotFalse(
			has_action( 'updated_option', array( $module, 'note_config_change' ) ),
			'La escritura de una fila que ya existía tiene que seguir avisando.'
		);
		self::assertNotFalse(
			has_action( 'added_option', array( $module, 'note_config_change' ) ),
			'Sin este gancho, encender la campaña por primera vez no deja rastro y el aviso de «Precios» no se pinta.'
		);
	}

	private function render( TaxonomyDiscount $module ): string {
		ob_start();
		$module->render_post_save_notice();

		return trim( (string) ob_get_clean() );
	}
}
