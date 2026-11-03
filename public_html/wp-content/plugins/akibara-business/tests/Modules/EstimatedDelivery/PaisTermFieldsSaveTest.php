<?php
/**
 * Contrato de guardado de los días de tránsito por país.
 *
 * Dos defectos que esta suite fija:
 *
 * 1. `edited_pa_pais` lo dispara CUALQUIER `wp_update_term()`, no sólo la
 *    pantalla del término. Un update programático dentro de una sesión con
 *    `manage_categories` llegaba sin `akb_min_days`/`akb_max_days`, el handler
 *    lo leía como «los dos vacíos» y BORRABA los días configurados del país.
 *    Es el mismo patrón que ya cobró en ProductDataPanelSaveTest: un campo que
 *    no viaja en el POST no significa «vaciar».
 * 2. Con min/max inválidos el handler no escribía NI avisaba, mientras
 *    WordPress imprimía «Elemento actualizado». La pantalla gemela del panel
 *    (EstimatedDelivery::save_pais_range_pair) sí avisa desde siempre.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\PaisTermFields;
use Akibara\Business\Modules\EstimatedDelivery\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PaisTermFieldsSaveTest extends TestCase {

	private const TERM_ID = 44;

	/** @var array<string, mixed> */
	private array $original_post = array();

	/** @var array<string, mixed> */
	private array $meta = array();

	/** @var list<string> */
	private array $borradas = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	private PaisTermFields $sut;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_post = $_POST;
		$_POST               = array();
		$this->meta          = array();
		$this->borradas      = array();
		$this->transients    = array();
		$this->sut           = new PaisTermFields();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'update_term_meta' )->alias(
			function ( int $term_id, string $key, $value ): bool {
				$this->meta[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_term_meta' )->alias(
			function ( int $term_id, string $key ): bool {
				$this->borradas[] = $key;
				unset( $this->meta[ $key ] );
				return true;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ] ?? false
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		$_POST = $this->original_post;
		Monkey\tearDown();
		parent::tearDown();
	}

	private function clave_transient(): string {
		return PaisTermFields::SAVE_ERRORS_TRANSIENT_PREFIX . 7;
	}

	public function test_un_update_programatico_sin_los_campos_no_borra_los_dias(): void {
		// $_POST vacío: exactamente lo que ve el handler cuando el término lo
		// actualiza otro código, no la pantalla de edición.
		$this->sut->save_term_fields( self::TERM_ID );

		self::assertSame( array(), $this->borradas, 'Un POST sin los campos no puede borrar term-meta.' );
		self::assertSame( array(), $this->meta );
		self::assertSame( array(), $this->transients );
	}

	public function test_valores_validos_se_persisten(): void {
		$_POST['akb_min_days'] = '10';
		$_POST['akb_max_days'] = '25';

		$this->sut->save_term_fields( self::TERM_ID );

		self::assertSame(
			array(
				Settings::TERM_META_MIN_DAYS => 10,
				Settings::TERM_META_MAX_DAYS => 25,
			),
			$this->meta
		);
		self::assertSame( array(), $this->transients, 'Un guardado válido no deja avisos.' );
	}

	public function test_vaciar_los_dos_campos_borra_la_term_meta(): void {
		$_POST['akb_min_days'] = '';
		$_POST['akb_max_days'] = '';

		$this->sut->save_term_fields( self::TERM_ID );

		self::assertSame(
			array( Settings::TERM_META_MIN_DAYS, Settings::TERM_META_MAX_DAYS ),
			$this->borradas,
			'Vaciar ambos campos a mano sigue siendo la forma de caer al fallback.'
		);
	}

	public function test_valores_invalidos_no_escriben_y_dejan_aviso(): void {
		// max < min: la regla es 1 ≤ min ≤ max ≤ 365.
		$_POST['akb_min_days'] = '30';
		$_POST['akb_max_days'] = '5';

		$this->sut->save_term_fields( self::TERM_ID );

		self::assertSame( array(), $this->meta, 'Un rango inválido no debe pisar el valor anterior.' );
		self::assertSame( array(), $this->borradas );

		$avisos = $this->transients[ $this->clave_transient() ] ?? array();
		self::assertCount( 1, $avisos, 'El fallo tiene que dejar rastro: callar es peor que no tener el campo.' );
		self::assertStringContainsString( 'min=30', (string) $avisos[0] );
		self::assertStringContainsString( 'max=5', (string) $avisos[0] );
		self::assertStringContainsString( 'No se guardó', (string) $avisos[0] );
	}

	public function test_el_aviso_se_imprime_una_sola_vez(): void {
		$_POST['akb_min_days'] = '0';
		$_POST['akb_max_days'] = '400';
		$this->sut->save_term_fields( self::TERM_ID );

		ob_start();
		$this->sut->render_save_notice();
		$primera = (string) ob_get_clean();

		ob_start();
		$this->sut->render_save_notice();
		$segunda = (string) ob_get_clean();

		self::assertStringContainsString( 'notice notice-error', $primera );
		self::assertStringContainsString( 'valores inválidos', $primera );
		self::assertSame( '', $segunda, 'El transient se consume: un aviso no puede reaparecer en otra pantalla.' );
	}
}
