<?php
/**
 * El panel elige correos de una lista y guarda destinatarios de verdad.
 *
 * «Correos que llevan la invitación» pedía escribir identificadores internos
 * de WooCommerce separados por coma: nadie que opere la tienda sabe que el
 * correo de «Pedido completado» se llama `customer_completed_order`, y un
 * nombre mal escrito apagaba la invitación sin aviso (regla de la casa: todo
 * operable sin código, con los valores reales a la vista).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Support\SettingsPage;
use Brain\Monkey\Functions;

/**
 * @since 0.4.1
 */
final class PanelCorreosYAlertasTest extends RewardsTestCase {

	private SettingsPage $page;

	/** @var array<int,array{key:string,value:mixed}> */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();

		$this->page = new SettingsPage();
		$wc         = $this->install_fake_wc();

		$wc->mailer()->emails = array(
			'WC_Email_Customer_Processing_Order' => (object) array(
				'id'    => 'customer_processing_order',
				'title' => 'Pedido en procesamiento',
			),
			'WC_Email_Customer_Completed_Order' => (object) array(
				'id'    => 'customer_completed_order',
				'title' => 'Pedido completado',
			),
		);

		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ): string => trim( (string) $v ) );
		$transients = &$this->transients;
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$transients ): bool {
				$transients[] = array(
					'key'   => $key,
					'value' => $value,
				);
				return true;
			}
		);
	}

	/** @testdox Los correos que llevan la invitación se eligen de la lista de WooCommerce. */
	public function test_los_correos_se_eligen_de_la_lista(): void {
		$guardado = $this->page->save_fields(
			array( 'akb_rewards_nudge_email_ids' => array( 'customer_completed_order', 'inventado', 'customer_processing_order' ) )
		);

		$this->assertSame( 'customer_processing_order,customer_completed_order', $guardado['akb_rewards_nudge_email_ids'], 'Sólo correos que existen, en el orden de la lista.' );
	}

	/** @testdox Sin ninguna casilla marcada, ningún correo lleva la invitación. */
	public function test_sin_casillas_no_lleva_ninguno(): void {
		$guardado = $this->page->save_fields( array() );

		$this->assertSame( '', $guardado['akb_rewards_nudge_email_ids'] );
	}

	/** @testdox El formulario real sin casillas marcadas guarda «ninguno». */
	public function test_el_formulario_sin_casillas_guarda_ninguno(): void {
		$guardado = $this->page->save_fields( array( 'akb_rewards_nudge_email_ids' => array( '' ) ) );

		$this->assertSame( '', $guardado['akb_rewards_nudge_email_ids'] );
	}

	/** @testdox Un correo guardado que ya no está en la lista no se borra en silencio. */
	public function test_un_correo_guardado_que_ya_no_existe_se_conserva(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_nudge_email_ids' === $option ? 'customer_completed_order,correo_de_otro_plugin' : $default_value
		);

		$guardado = $this->page->save_fields(
			array( 'akb_rewards_nudge_email_ids' => array( '', 'customer_completed_order', 'correo_de_otro_plugin' ) )
		);

		$this->assertSame( 'customer_completed_order,correo_de_otro_plugin', $guardado['akb_rewards_nudge_email_ids'] );
	}

	/** @testdox Sin la lista de correos de WooCommerce se conserva lo guardado. */
	public function test_sin_la_lista_se_conserva_lo_guardado(): void {
		$GLOBALS['akb_rewards_test_wc']->mailer()->emails = array();
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_nudge_email_ids' === $option ? 'customer_processing_order' : $default_value
		);

		$guardado = $this->page->save_fields( array( 'akb_rewards_nudge_email_ids' => array( '' ) ) );

		$this->assertSame( 'customer_processing_order', $guardado['akb_rewards_nudge_email_ids'] );
	}

	/** @testdox Los destinatarios de las alertas descartan lo que no es un correo y lo avisan. */
	public function test_los_destinatarios_descartan_lo_que_no_es_correo(): void {
		$guardado = $this->page->save_fields(
			array( 'akb_rewards_alerts_email' => 'contacto@akibara.cl; bodega, bodega@akibara.cl' )
		);

		$this->assertSame( 'contacto@akibara.cl, bodega@akibara.cl', $guardado['akb_rewards_alerts_email'] );
		$avisos = end( $this->transients )['value'] ?? array();
		$this->assertStringContainsString( 'bodega', (string) ( end( $avisos )['texto'] ?? '' ) );
	}
}
