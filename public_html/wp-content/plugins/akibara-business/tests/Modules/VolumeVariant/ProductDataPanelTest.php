<?php
/**
 * La palanca del dueño para marcar «otra versión del mismo tomo».
 *
 * El testigo del panel es la mitad importante: sin él, cualquier guardado que
 * no incluya el formulario —una edición rápida, una actualización masiva, un
 * `wp post update`— se leería como «el dueño lo dejó en tomo normal» y borraría
 * la marca en silencio. Es el defecto que ya se pagó en el panel de
 * Disponibilidad y que este archivo impide reeditar.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\VolumeVariant;

use Akibara\Business\Modules\VolumeVariant\ProductDataPanel;
use Akibara\Business\Services\VolumeVariant;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ProductDataPanelTest extends TestCase {

	/** @var array<string, string> Metas escritas por el guardado. */
	private array $written = array();

	/** @var array<int, string> Metas borradas por el guardado. */
	private array $deleted = array();

	private bool $can_edit = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->written  = array();
		$this->deleted  = array();
		$this->can_edit = true;
		$_POST          = array();

		Functions\when( 'current_user_can' )->alias( fn (): bool => $this->can_edit );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->written[ $key ] = (string) $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( int $id, string $key ): bool {
				$this->deleted[] = $key;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox El tab se registra en el meta box de datos del producto. */
	public function test_el_tab_se_registra(): void {
		$tabs = ( new ProductDataPanel() )->add_tab( array() );

		self::assertArrayHasKey( 'akb_variante', $tabs );
		self::assertSame( 'akb_variante_data', $tabs['akb_variante']['target'] );
		self::assertSame( 'Variante del tomo', $tabs['akb_variante']['label'] );
	}

	/** @testdox El tab no pisa los que ya registraron WooCommerce y los demás módulos. */
	public function test_el_tab_no_pisa_los_existentes(): void {
		$tabs = ( new ProductDataPanel() )->add_tab( array( 'akb_eda' => array( 'label' => 'Disponibilidad' ) ) );

		self::assertArrayHasKey( 'akb_eda', $tabs );
		self::assertArrayHasKey( 'akb_variante', $tabs );
	}

	/**
	 * @testdox [NEG] Sin el testigo del panel, el guardado no toca la marca.
	 *
	 * Es el caso de la edición rápida y de cualquier guardado programático: el
	 * POST no trae el panel y leerlo como «vacío» borraría la marca.
	 */
	public function test_sin_el_testigo_no_escribe_ni_borra(): void {
		$_POST[ VolumeVariant::META_KEY ] = VolumeVariant::KIND_EDICION_ESPECIAL;

		( new ProductDataPanel() )->save_product_meta( 27916 );

		self::assertSame( array(), $this->written );
		self::assertSame( array(), $this->deleted );
	}

	/** @testdox [NEG] Sin permiso de edición no se guarda nada. */
	public function test_sin_permiso_no_escribe(): void {
		$this->can_edit                       = false;
		$_POST[ ProductDataPanel::PANEL_MARKER ] = '1';
		$_POST[ VolumeVariant::META_KEY ]        = VolumeVariant::KIND_EDICION_ESPECIAL;

		( new ProductDataPanel() )->save_product_meta( 27916 );

		self::assertSame( array(), $this->written );
		self::assertSame( array(), $this->deleted );
	}

	/** @testdox Un valor del enum se guarda tal cual. */
	public function test_guarda_un_valor_del_enum(): void {
		$_POST[ ProductDataPanel::PANEL_MARKER ] = '1';
		$_POST[ VolumeVariant::META_KEY ]        = VolumeVariant::KIND_PORTADA_ALTERNATIVA;

		( new ProductDataPanel() )->save_product_meta( 27916 );

		self::assertSame(
			array( VolumeVariant::META_KEY => VolumeVariant::KIND_PORTADA_ALTERNATIVA ),
			$this->written
		);
		self::assertSame( array(), $this->deleted );
	}

	/**
	 * @testdox [NEG] Un valor fuera del enum no persiste: se guarda «tomo normal».
	 */
	public function test_un_valor_fuera_del_enum_no_persiste(): void {
		$_POST[ ProductDataPanel::PANEL_MARKER ] = '1';
		$_POST[ VolumeVariant::META_KEY ]        = 'variante_inventada';

		( new ProductDataPanel() )->save_product_meta( 27916 );

		self::assertSame( array(), $this->written );
		self::assertSame( array( VolumeVariant::META_KEY ), $this->deleted );
	}

	/**
	 * @testdox Volver a «tomo normal» BORRA la meta en vez de guardar un vacío.
	 *
	 * Así «no hay meta» y «tomo normal» son el mismo estado y ninguna consulta
	 * tiene que distinguir los dos.
	 */
	public function test_volver_a_tomo_normal_borra_la_meta(): void {
		$_POST[ ProductDataPanel::PANEL_MARKER ] = '1';
		$_POST[ VolumeVariant::META_KEY ]        = VolumeVariant::KIND_NONE;

		( new ProductDataPanel() )->save_product_meta( 27916 );

		self::assertSame( array(), $this->written );
		self::assertSame( array( VolumeVariant::META_KEY ), $this->deleted );
	}

	/** @testdox Se engancha a los tres hooks oficiales de WooCommerce. */
	public function test_se_engancha_a_los_hooks_de_woocommerce(): void {
		$hooks  = array();
		$module = new ProductDataPanel();

		Functions\when( 'add_filter' )->alias(
			static function ( string $hook ) use ( &$hooks ): bool {
				$hooks[] = $hook;
				return true;
			}
		);
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$hooks ): bool {
				$hooks[] = $hook;
				return true;
			}
		);

		$module->register();

		self::assertSame(
			array(
				'woocommerce_product_data_tabs',
				'woocommerce_product_data_panels',
				'woocommerce_process_product_meta',
			),
			$hooks
		);
	}
}
