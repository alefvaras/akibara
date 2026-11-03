<?php
/**
 * Tests for the no-code copy configuration.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\CopyManager;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\BrandsDirectory\EditionCopy;
use Akibara\Business\Modules\CopyManager\CopyManager;
use Akibara\Business\Modules\DisponiblesRoute\DisponiblesRoute;
use Akibara\Business\Modules\Forms\Settings as FormsSettings;
use Akibara\Business\Modules\GoogleLoginButton\AccessWall;
use Akibara\Business\Modules\OrderPreventa\ShipPromise;
use Akibara\Business\Modules\PdpEnhancer\PdpSeriesEnhancer;
use Akibara\Business\Modules\PreventasRoute\PreventasRoute;
use Akibara\Business\Services\CheckoutTrustSignals;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class CopyManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_posts' )->justReturn(
			array(
				(object) array(
					'ID'         => 44,
					'post_title' => 'Guía de preventas',
				),
			)
		);
		Functions\when( 'wp_kses' )->alias(
			static fn( string $html ): string => strip_tags( $html, '<strong><em><br>' )
		);
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'is_email' )->alias(
			static fn( $email ) => is_string( $email ) && 1 === preg_match( '/^[^@\s]+@[^@\s.]+\.[^@\s]+$/', $email )
				? $email
				: false
		);
	}

	/**
	 * Todos los ajustes de la pestaña indexados por id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function settings_by_id(): array {
		$by_id = array();
		foreach ( ( new CopyManager( new AdminPanel() ) )->get_settings() as $setting ) {
			if ( isset( $setting['id'] ) ) {
				$by_id[ $setting['id'] ] = $setting;
			}
		}

		return $by_id;
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Revisión de 1.116.8: el saneador cambiaba un correo mal escrito por el de
	 * siempre dentro de `woocommerce_update_options()`, y el panel decía «No
	 * había cambios que guardar» a quien sí había escrito algo.
	 *
	 * Y la revisión independiente de 1.116.9: avisarlo por el canal de
	 * validación tiraba la pestaña ENTERA, así que un tipeo en el correo borraba
	 * los otros textos que el dueño acababa de escribir. Va por el canal
	 * parcial: se guarda todo lo demás y el aviso nombra sólo este campo.
	 *
	 * @testdox Un correo de contacto mal escrito se nombra como «no guardado» sin tirar la pestaña; vacío no es error
	 */
	public function test_a_malformed_contact_email_is_reported_as_not_saved(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		$manager = new CopyManager( new AdminPanel() );

		$_POST        = array( FormsSettings::OPTION_NOTIFY_EMAIL => 'ventas@akibara' );
		$no_guardados = $manager->report_rejected_values( array( 'previo' ), 'copys' );
		self::assertSame( array( 'previo', FormsSettings::OPTION_NOTIFY_EMAIL ), $no_guardados, 'Se suma a lo que ya no entró, no lo pisa.' );

		$_POST = array( FormsSettings::OPTION_NOTIFY_EMAIL => '' );
		self::assertSame( array(), $manager->report_rejected_values( array(), 'copys' ), 'Vacío es volver al de siempre.' );

		$_POST = array( FormsSettings::OPTION_NOTIFY_EMAIL => ' ventas@akibara.cl ' );
		self::assertSame( array(), $manager->report_rejected_values( array(), 'copys' ) );

		$_POST = array( FormsSettings::OPTION_NOTIFY_EMAIL => 'ventas@akibara' );
		self::assertSame( array(), $manager->report_rejected_values( array(), 'carrito' ), 'Otra pestaña no es asunto de este módulo.' );
	}

	/**
	 * @testdox Una guía de preventas que ya no está publicada se nombra como «no guardada»; «Sin guía» no es error
	 */
	public function test_an_unpublished_guide_is_reported_as_not_saved(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_status' )->alias( static fn( int $id ): string => 44 === $id ? 'publish' : 'draft' );
		$manager = new CopyManager( new AdminPanel() );

		$_POST = array( PreventasRoute::OPTION_GUIDE => '45' );
		self::assertSame( array( PreventasRoute::OPTION_GUIDE ), $manager->report_rejected_values( array(), 'copys' ) );
		self::assertNull( $manager->sanitize_preventas_guide( '0', array(), '45' ), 'Es exactamente lo que el saneador iba a descartar.' );

		$_POST = array( PreventasRoute::OPTION_GUIDE => '44' );
		self::assertSame( array(), $manager->report_rejected_values( array(), 'copys' ) );

		$_POST = array( PreventasRoute::OPTION_GUIDE => '0' );
		self::assertSame( array(), $manager->report_rejected_values( array(), 'copys' ) );
	}

	/** @testdox El aviso va por el canal PARCIAL del panel, no por el que tira la pestaña */
	public function test_the_report_is_wired_to_the_partial_channel(): void {
		$filtros = array();
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $callback ) use ( &$filtros ): bool {
				$filtros[ $hook ][] = $callback;
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );

		$manager = new CopyManager( new AdminPanel() );
		$manager->register();

		self::assertContains( array( $manager, 'report_rejected_values' ), $filtros['akibara_panel_options_not_saved'] ?? array() );
		self::assertArrayNotHasKey( 'akibara_panel_save_errors', $filtros, 'Ese canal descarta la pestaña entera.' );
	}

	/** @testdox El motivo también se engancha al canal, junto con el reporte */
	public function test_the_reason_filter_is_wired_too(): void {
		$filtros = array();
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $callback ) use ( &$filtros ): bool {
				$filtros[ $hook ][] = $callback;
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );

		$manager = new CopyManager( new AdminPanel() );
		$manager->register();

		self::assertContains(
			array( $manager, 'option_not_saved_reason' ),
			$filtros['akibara_panel_option_not_saved_reason'] ?? array()
		);
	}

	/**
	 * REAPERTURA S-74d49f79 (2026-09-19): con la causa fija del buscador,
	 * cualquier campo de `copys` que no entrara mostraba «El buscador no
	 * acepta cambios de precio… espera a que termine», que para un correo mal
	 * escrito es una causa FALSA — hay que corregirlo, no esperar.
	 *
	 * @testdox El correo inválido tiene su propio motivo, no el del buscador.
	 */
	public function test_invalid_email_has_its_own_reason(): void {
		$manager = new CopyManager( new AdminPanel() );

		$motivo = $manager->option_not_saved_reason(
			'El buscador no acepta cambios de precio mientras está poniéndose al día con el catálogo. Espera a que termine y vuelve a guardar sólo eso.',
			FormsSettings::OPTION_NOTIFY_EMAIL,
			'copys'
		);

		self::assertStringContainsString( 'no es válido', $motivo );
		self::assertStringNotContainsString( 'buscador', $motivo );
	}

	/** @testdox La guía despublicada tiene su propio motivo, no el del buscador. */
	public function test_unpublished_guide_has_its_own_reason(): void {
		$manager = new CopyManager( new AdminPanel() );

		$motivo = $manager->option_not_saved_reason( 'motivo por defecto', PreventasRoute::OPTION_GUIDE, 'copys' );

		self::assertStringContainsString( 'no está publicada', $motivo );
		self::assertStringNotContainsString( 'buscador', $motivo );
	}

	/**
	 * R-I1-5: un campo recortado SÍ se guardó. Por el canal de los que no
	 * entraron, el panel decía «Se guardó todo menos esto: … Se guardó
	 * recortado». Va por el canal de los guardados con aviso.
	 *
	 * @testdox Un campo recortado avisa que se recortó, por el canal de lo que sí se guardó.
	 */
	public function test_a_clamped_field_says_it_was_clamped(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		$manager = new CopyManager( new AdminPanel() );

		$_POST  = array( 'akb_copy_eta_single_pedido' => str_repeat( 'x', 500 ) );
		$avisos = $manager->report_saved_with_warning( array(), 'copys' );

		self::assertArrayHasKey( 'akb_copy_eta_single_pedido', $avisos );
		self::assertStringContainsString( 'recortad', $avisos['akb_copy_eta_single_pedido'] );
		self::assertStringNotContainsString( 'buscador', $avisos['akb_copy_eta_single_pedido'] );
		self::assertNotContains( 'akb_copy_eta_single_pedido', $manager->report_rejected_values( array(), 'copys' ), 'No es un rechazo: se guardó.' );
	}

	/**
	 * `recortados()` cubría sólo 4 de los ~25 campos que
	 * `required_one_line_copies()` enumera: los otros ~21 —incluidos éste y
	 * el de las cuotas del checkout— pasaban igual por
	 * `sanitize_required_copy()` y se cortaban a `Text::ONE_LINE_MAX` en
	 * silencio, sin que el panel dijera que se recortaron. Pintaba «Ajustes
	 * guardados correctamente» sobre un texto cortado a mitad de frase
	 * (2026-09-19, R2-0a2ff112).
	 *
	 * @testdox Un campo antes NO cubierto (la ventana de despacho) también avisa que se recortó.
	 */
	public function test_a_previously_uncovered_field_also_says_it_was_clamped(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		$manager = new CopyManager( new AdminPanel() );

		$_POST = array( ShipPromise::OPTION_WINDOW => str_repeat( 'x', 500 ) );

		$avisos = $manager->report_saved_with_warning( array(), 'copys' );
		self::assertArrayHasKey(
			ShipPromise::OPTION_WINDOW,
			$avisos,
			'La ventana de despacho no estaba entre los 4 campos hardcodeados de recortados().'
		);
		self::assertStringContainsString( 'recortad', $avisos[ ShipPromise::OPTION_WINDOW ] );
		self::assertStringNotContainsString( 'buscador', $avisos[ ShipPromise::OPTION_WINDOW ] );
	}

	/** @testdox Un campo que cabe entero no se marca como recortado. */
	public function test_a_field_that_fits_is_not_reported_as_clamped(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		$manager = new CopyManager( new AdminPanel() );

		$_POST = array( ShipPromise::OPTION_WINDOW => '2-4 días hábiles' );

		self::assertSame( array(), $manager->report_rejected_values( array(), 'copys' ) );
		self::assertSame( array(), $manager->report_saved_with_warning( array(), 'copys' ) );
	}

	/** @testdox Fuera de `copys` y para options que no reconoce, el filtro deja pasar el motivo por defecto. */
	public function test_unknown_options_and_other_tabs_keep_the_default_reason(): void {
		$manager = new CopyManager( new AdminPanel() );

		self::assertSame(
			'motivo por defecto',
			$manager->option_not_saved_reason( 'motivo por defecto', FormsSettings::OPTION_NOTIFY_EMAIL, 'carrito' ),
			'Otra pestaña no es asunto de este módulo.'
		);
		self::assertSame(
			'motivo por defecto',
			$manager->option_not_saved_reason( 'motivo por defecto', 'akb_taxonomy_discount_percent', 'copys' ),
			'Una option que no es de este módulo no debe inventarle un motivo.'
		);
	}

	/**
	 * @testdox Catalog ordering labels are editable from the Akibara panel
	 */
	public function test_catalog_order_labels_are_exposed_in_admin(): void {
		$panel   = new AdminPanel();
		$manager = new CopyManager( $panel );
		$manager->register_admin_tab();

		$property = new ReflectionProperty( $panel, 'sections' );
		$sections = $property->getValue( $panel );
		$settings = $sections['copys']['settings_fn']();

		$defaults = array();
		foreach ( $settings as $setting ) {
			if ( isset( $setting['id'], $setting['default'] ) ) {
				$defaults[ $setting['id'] ] = $setting['default'];
			}
		}

		self::assertSame( 'Relevancia', $defaults['akb_copy_order_relevance'] );
		self::assertSame( 'Destacados', $defaults['akb_copy_order_featured'] );
		self::assertSame( 'Título: A-Z', $defaults['akb_copy_order_title'] );
		self::assertSame( 'Más vendidos', $defaults['akb_copy_order_popularity'] );
		self::assertSame( 'Más recientes', $defaults['akb_copy_order_date'] );
		self::assertSame( 'Menor precio', $defaults['akb_copy_order_price'] );
		self::assertSame( 'Mayor precio', $defaults['akb_copy_order_price_desc'] );
	}

	/**
	 * @testdox Preventas copy and published guide are editable from the Akibara panel
	 */
	public function test_preventas_content_is_exposed_in_admin(): void {
		$settings = ( new CopyManager( new AdminPanel() ) )->get_settings();
		$by_id    = array();
		foreach ( $settings as $setting ) {
			if ( isset( $setting['id'] ) ) {
				$by_id[ $setting['id'] ] = $setting;
			}
		}

		self::assertSame(
			PreventasRoute::DEFAULT_INTRO_CONTENT,
			$by_id[ PreventasRoute::OPTION_INTRO ]['default']
		);
		self::assertSame(
			'Guía de preventas',
			$by_id[ PreventasRoute::OPTION_GUIDE ]['options']['44']
		);
		self::assertArrayHasKey( '0', $by_id[ PreventasRoute::OPTION_GUIDE ]['options'] );
	}

	/**
	 * @testdox Las palabras de la espera (a pedido / preventa / ya llegó / vencida) se editan en el panel.
	 */
	public function test_preventa_wording_is_exposed_in_admin(): void {
		$by_id = array();
		foreach ( ( new CopyManager( new AdminPanel() ) )->get_settings() as $setting ) {
			if ( isset( $setting['id'] ) ) {
				$by_id[ $setting['id'] ] = $setting;
			}
		}

		foreach ( array(
			'akb_copy_eta_single_preventa',
			'akb_copy_eta_single_pedido',
			'akb_copy_eta_noun_preventa',
			'akb_copy_eta_noun_pedido',
			'akb_copy_eta_item_arrived',
			'akb_copy_eta_overdue',
		) as $id ) {
			self::assertArrayHasKey( $id, $by_id, 'La option ' . $id . ' se lee en el código y no se puede editar.' );
		}
		self::assertStringContainsString( '%s', (string) $by_id['akb_copy_eta_overdue']['default'], 'El texto de promesa vencida lleva la fecha.' );
		self::assertSame(
			$by_id['akb_copy_eta_overdue']['default'],
			( new CopyManager( new AdminPanel() ) )->sanitize_required_copy( '', array( 'id' => 'akb_copy_eta_overdue' ), '  ' ),
			'Vacío vuelve al literal: el cliente atrasado no puede quedarse sin la frase que explica el retraso.'
		);
	}

	/**
	 * @testdox Los textos de las ediciones de editorial se editan en el panel y no pueden quedar vacíos.
	 */
	public function test_edition_wording_is_exposed_and_required(): void {
		$manager = new CopyManager( new AdminPanel() );
		$by_id   = array();
		foreach ( $manager->get_settings() as $setting ) {
			if ( isset( $setting['id'] ) ) {
				$by_id[ $setting['id'] ] = $setting;
			}
		}
		foreach ( EditionCopy::defaults() as $id => $default ) {
			self::assertArrayHasKey( $id, $by_id, $id );
			self::assertSame( $default, $by_id[ $id ]['default'] );
			self::assertSame( $default, $manager->sanitize_required_copy( '', array( 'id' => $id ), '   ' ), 'Vacío vuelve al literal: sin él, el botón de la tarjeta quedaría sin texto.' );
		}
	}

	/**
	 * @testdox Los textos del muro de acceso se editan en el panel y ninguno puede quedar vacío.
	 */
	public function test_access_wall_copy_is_exposed_and_required(): void {
		$manager = new CopyManager( new AdminPanel() );
		$by_id   = $this->settings_by_id();

		foreach ( AccessWall::defaults() as $id => $default ) {
			self::assertArrayHasKey(
				$id,
				$by_id,
				$id . ' tiene que estar en Textos: un cartel que sólo se cambia editando código no lo puede cambiar el dueño.'
			);
			self::assertSame( $default, $by_id[ $id ]['default'] );
			self::assertSame(
				$default,
				$manager->sanitize_required_copy( '', array( 'id' => $id ), '   ' ),
				'Vaciarlo dejaría la página de acceso con un encabezado en blanco.'
			);
		}
	}

	/**
	 * @testdox Preventas settings reject executable markup and unpublished destinations
	 */
	public function test_preventas_settings_are_sanitized(): void {
		Functions\when( 'get_post_type' )->alias(
			static fn( int $post_id ): string => 44 === $post_id ? 'post' : 'page'
		);
		Functions\when( 'get_post_status' )->alias(
			static fn( int $post_id ): string => 44 === $post_id ? 'publish' : 'draft'
		);
		$manager = new CopyManager( new AdminPanel() );

		$copy = $manager->sanitize_preventas_intro(
			'ignored',
			array(),
			'<h1>No</h1><strong>Reserva</strong><script>alert(1)</script>'
		);
		self::assertStringContainsString( '<strong>Reserva</strong>', $copy );
		self::assertStringNotContainsString( '<h1', $copy );
		self::assertStringNotContainsString( '<script', $copy );
		self::assertSame( 44, $manager->sanitize_preventas_guide( '0', array(), '44' ) );
		self::assertNull( $manager->sanitize_preventas_guide( '0', array(), '45' ) );
		self::assertSame( 0, $manager->sanitize_preventas_guide( '44', array(), '0' ) );
	}

	/**
	 * @testdox Contact recipient is editable and defaults to the historic mailbox
	 */
	public function test_contact_notify_email_is_exposed_with_the_current_literal(): void {
		$field = $this->settings_by_id()[ FormsSettings::OPTION_NOTIFY_EMAIL ];

		self::assertSame( FormsSettings::DEFAULT_NOTIFY_EMAIL, $field['default'] );
		self::assertSame( 'email', $field['type'] );
	}

	/**
	 * @testdox A malformed contact recipient falls back instead of being stored
	 */
	public function test_contact_notify_email_falls_back_when_invalid(): void {
		$manager = new CopyManager( new AdminPanel() );

		self::assertSame( 'ventas@akibara.cl', $manager->sanitize_contact_email( '', array(), ' ventas@akibara.cl ' ) );
		self::assertSame( FormsSettings::DEFAULT_NOTIFY_EMAIL, $manager->sanitize_contact_email( '', array(), 'ventas arroba akibara' ) );
		self::assertSame( FormsSettings::DEFAULT_NOTIFY_EMAIL, $manager->sanitize_contact_email( '', array(), '' ) );
		self::assertSame( FormsSettings::DEFAULT_NOTIFY_EMAIL, $manager->sanitize_contact_email( '', array(), array( 'x' ) ) );
	}

	/**
	 * @testdox The stored recipient is what the contact form actually mails
	 */
	public function test_contact_notify_email_is_read_by_the_form(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => FormsSettings::OPTION_NOTIFY_EMAIL === $name
				? 'ventas@akibara.cl'
				: $default
		);

		self::assertSame( 'ventas@akibara.cl', FormsSettings::get_notify_email() );
	}

	/**
	 * @testdox The dispatch window, the installments promise and the footer contact are editable with their current literals
	 */
	public function test_promise_and_footer_copy_are_exposed_with_the_current_literals(): void {
		$by_id = $this->settings_by_id();

		self::assertSame( '1-3 días hábiles', $by_id[ ShipPromise::OPTION_WINDOW ]['default'] );
		self::assertSame( '3 cuotas sin interés', $by_id[ CheckoutTrustSignals::OPTION_INSTALLMENTS ]['default'] );
		self::assertSame(
			'Horario de soporte: Lun a Vie · 10:00 a 18:00 hrs',
			$by_id['akb_copy_footer_support_hours']['default']
		);
		self::assertSame( '+56 9 4424 2844', $by_id['akb_copy_footer_phone']['default'] );
	}

	/**
	 * @testdox An empty dispatch window falls back instead of promising nothing
	 */
	public function test_dispatch_window_falls_back_when_blank(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => ShipPromise::OPTION_WINDOW === $name ? '   ' : $default
		);

		self::assertSame( ShipPromise::DEFAULT_WINDOW, ShipPromise::window() );
	}

	/**
	 * @testdox The saved dispatch window is the one the customer reads
	 */
	public function test_dispatch_window_is_read_back(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => ShipPromise::OPTION_WINDOW === $name
				? '2-5 días hábiles'
				: $default
		);

		self::assertSame( '2-5 días hábiles', ShipPromise::window() );
	}

	/**
	 * @testdox Los CTA de /a-pedido/ y /disponibles/ se editan con sus literales de siempre
	 */
	public function test_route_ctas_are_exposed_with_the_current_literals(): void {
		$by_id = $this->settings_by_id();

		self::assertSame( PreventasRoute::GUIDE_PREFIX, $by_id[ PreventasRoute::OPTION_GUIDE_PREFIX ]['default'] );
		self::assertSame( PreventasRoute::GUIDE_LINK_COPY, $by_id[ PreventasRoute::OPTION_GUIDE_LINK ]['default'] );
		self::assertSame( PreventasRoute::SIBLING_PREFIX, $by_id[ PreventasRoute::OPTION_SIBLING_PREFIX ]['default'] );
		self::assertSame( PreventasRoute::SIBLING_LINK_COPY, $by_id[ PreventasRoute::OPTION_SIBLING_LINK ]['default'] );
		self::assertSame( DisponiblesRoute::SIBLING_PREFIX, $by_id[ DisponiblesRoute::OPTION_SIBLING_PREFIX ]['default'] );
		self::assertSame( DisponiblesRoute::SIBLING_LINK_COPY, $by_id[ DisponiblesRoute::OPTION_SIBLING_LINK ]['default'] );
	}

	/**
	 * @testdox La descripción de «disponibles» ya no promete que el enlace sea fijo
	 */
	public function test_the_disponibles_section_no_longer_claims_the_link_is_fixed(): void {
		$desc = '';
		foreach ( ( new CopyManager( new AdminPanel() ) )->get_settings() as $setting ) {
			if ( 'akb_disponibles_content' === ( $setting['id'] ?? '' ) && 'title' === ( $setting['type'] ?? '' ) ) {
				$desc = (string) ( $setting['desc'] ?? '' );
			}
		}

		self::assertStringNotContainsString( 'no se edita acá', $desc );
	}

	/**
	 * @testdox Los CTA guardados son los que la ruta imprime; vaciarlos repone el literal
	 */
	public function test_route_ctas_are_read_back_and_never_blank(): void {
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $text ): string => strip_tags( $text )
		);
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => match ( $name ) {
				PreventasRoute::OPTION_SIBLING_PREFIX   => '<b>¿Lo quieres hoy?</b>',
				PreventasRoute::OPTION_SIBLING_LINK     => '   ',
				DisponiblesRoute::OPTION_SIBLING_PREFIX => "¿No está?\n Te lo traemos.",
				default                                 => $default,
			}
		);

		// Las etiquetas se retiran: el texto se imprime dentro de esc_html().
		self::assertSame( '¿Lo quieres hoy?', PreventasRoute::sibling_prefix() );
		// Vaciar el enlace a /disponibles/ dejaría esa ruta huérfana.
		self::assertSame( PreventasRoute::SIBLING_LINK_COPY, PreventasRoute::sibling_link_copy() );
		self::assertSame( '¿No está? Te lo traemos.', DisponiblesRoute::sibling_prefix() );
		self::assertSame( DisponiblesRoute::SIBLING_LINK_COPY, DisponiblesRoute::sibling_link_copy() );
	}

	/**
	 * @testdox El pie SÍ se puede apagar: su vacío se guarda, el de una promesa no
	 */
	public function test_optional_copy_keeps_its_blank_but_required_copy_does_not(): void {
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $text ): string => strip_tags( $text )
		);
		$manager = new CopyManager( new AdminPanel() );

		self::assertSame( '', $manager->sanitize_optional_copy( '', array( 'id' => 'akb_copy_footer_phone' ), '  ' ) );
		self::assertSame(
			ShipPromise::DEFAULT_WINDOW,
			$manager->sanitize_required_copy( '', array( 'id' => ShipPromise::OPTION_WINDOW ), '  ' )
		);
		self::assertSame(
			'2 a 4 días hábiles',
			$manager->sanitize_required_copy( '', array( 'id' => ShipPromise::OPTION_WINDOW ), " 2 a 4 \n días hábiles " )
		);
	}

	/**
	 * @testdox The installments promise has ONE source for every surface
	 */
	public function test_installments_copy_has_a_single_source(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => CheckoutTrustSignals::OPTION_INSTALLMENTS === $name
				? '6 cuotas sin interés'
				: $default
		);

		$signals = array_column( CheckoutTrustSignals::all(), 'label', 'id' );

		self::assertSame( '6 cuotas sin interés', CheckoutTrustSignals::installments_copy() );
		self::assertSame( '6 cuotas sin interés', $signals['installments'] );
	}

	/**
	 * Revisión independiente de 1.116.9 (BAJA): el selector sólo listaba entradas
	 * publicadas; con la guía guardada despublicada el navegador elegía «Sin
	 * guía» y guardar cualquier texto la borraba en silencio.
	 *
	 * @testdox La guía guardada aparece en el selector aunque se haya despublicado.
	 */
	public function test_the_saved_guide_stays_in_the_selector_when_unpublished(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => PreventasRoute::OPTION_GUIDE === $option ? '45' : $default_value
		);
		Functions\when( 'get_the_title' )->alias( static fn( $id ): string => 45 === (int) $id ? 'Guía vieja' : '' );

		$opciones = ( new \ReflectionMethod( CopyManager::class, 'published_post_options' ) );
		$opciones->setAccessible( true );
		$lista = $opciones->invoke( new CopyManager( new AdminPanel() ) );

		self::assertSame( 'Guía de preventas', $lista['44'] ?? null, 'Las publicadas siguen ahí.' );
		self::assertSame( 'Guía vieja (no publicada)', $lista['45'] ?? null, 'Y la guardada se nombra, para que guardar no la borre.' );
	}

	/**
	 * D3/D5 (lote de integración 2026-09-23). El tema leía seis options que
	 * nadie podía editar ni validar: la pista del buscador de la cabecera y los
	 * textos del cierre de las guías.
	 *
	 * @testdox Los textos que pinta el tema en la cabecera y las guías se editan en Textos y ninguno queda vacío.
	 */
	public function test_theme_copies_are_exposed_and_required(): void {
		$manager = new CopyManager( new AdminPanel() );
		$by_id   = $this->settings_by_id();

		// Siete desde R-I1-6: el título de los más vendidos en la búsqueda vacía.
		self::assertCount( 7, CopyManager::theme_copy_defaults() );
		self::assertSame( 'Lo más vendido', CopyManager::theme_copy_defaults()[ CopyManager::OPTION_SEARCH_EMPTY_POPULAR ] ?? null );
		self::assertSame( 'akb_copy_search_empty_popular', CopyManager::OPTION_SEARCH_EMPTY_POPULAR, 'El nombre es el contrato con el tema: no cambia.' );
		foreach ( CopyManager::theme_copy_defaults() as $id => $default ) {
			self::assertArrayHasKey( $id, $by_id, $id . ' tiene que estar en Textos.' );
			self::assertSame( $default, $by_id[ $id ]['default'] );
			self::assertSame( $default, $manager->sanitize_required_copy( '', array( 'id' => $id ), "  \n " ), 'Vacío vuelve al literal de fábrica.' );
		}
		self::assertSame(
			'Busca tu serie',
			$manager->sanitize_required_copy( '', array( 'id' => CopyManager::OPTION_SEARCH_PLACEHOLDER ), '<b>Busca</b> tu serie' ),
			'Se imprime escapado: una etiqueta pegada se leería, no se vería.'
		);
	}

	/**
	 * El tema cae al MISMO literal si la option está vacía (akibara_gp_copy()):
	 * si los dos defaults se separan, el panel promete un texto y la tienda
	 * pinta otro.
	 *
	 * @testdox Los literales de fábrica son los mismos que usa el tema de respaldo.
	 */
	public function test_theme_copy_defaults_match_the_theme(): void {
		$tema = dirname( __DIR__, 5 ) . '/themes/akibara-gp/inc/site-copy.php';
		if ( ! is_readable( $tema ) ) {
			self::markTestSkipped( 'El tema no está en el árbol.' );
		}
		$fuente = (string) file_get_contents( $tema );

		foreach ( CopyManager::theme_copy_defaults() as $id => $default ) {
			self::assertStringContainsString( "akibara_gp_copy( '{$id}', '{$default}' )", $fuente, $id );
		}
	}

	/**
	 * R-I1-5 (revisión del lote I1): el aviso iba por el canal de los que NO
	 * entraron y el panel decía «Se guardó todo menos esto: Guía: botón a la
	 * serie. Se guardó, pero sin {serie}…». El valor sí se guarda: el aviso va
	 * por el canal de los guardados con algo que revisar.
	 *
	 * @testdox Un botón de guía sin {serie} se guarda y el panel lo avisa sin decir que no se guardó; vacío no avisa.
	 */
	public function test_guide_series_cta_without_marker_is_flagged(): void {
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$manager = new CopyManager( new AdminPanel() );
		$manager->note_guide_series_cta_written();

		$_POST  = array( CopyManager::OPTION_GUIDE_CTA_SERIES => 'Ver todos los tomos' );
		$avisos = $manager->report_saved_with_warning( array(), 'copys' );
		self::assertArrayHasKey( CopyManager::OPTION_GUIDE_CTA_SERIES, $avisos );
		self::assertStringContainsString( '{serie}', $avisos[ CopyManager::OPTION_GUIDE_CTA_SERIES ] );
		self::assertStringNotContainsString( 'Se guardó', $avisos[ CopyManager::OPTION_GUIDE_CTA_SERIES ], 'Ni «se guardó» ni «no se guardó»: con «No había cambios» al lado, cualquiera mentiría.' );
		self::assertNotContains( CopyManager::OPTION_GUIDE_CTA_SERIES, $manager->report_rejected_values( array(), 'copys' ), 'No es un rechazo.' );
		self::assertSame( 'Ver todos los tomos', $manager->sanitize_required_copy( '', array( 'id' => CopyManager::OPTION_GUIDE_CTA_SERIES ), 'Ver todos los tomos' ), 'Se guarda igual: el aviso no bloquea.' );
		self::assertSame( array(), $manager->report_saved_with_warning( array(), 'carrito' ), 'Otra pestaña no es asunto de este módulo.' );

		$_POST = array( CopyManager::OPTION_GUIDE_CTA_SERIES => 'Todo {serie} aquí' );
		self::assertArrayNotHasKey( CopyManager::OPTION_GUIDE_CTA_SERIES, $manager->report_saved_with_warning( array(), 'copys' ) );

		$_POST = array( CopyManager::OPTION_GUIDE_CTA_SERIES => '' );
		self::assertArrayNotHasKey( CopyManager::OPTION_GUIDE_CTA_SERIES, $manager->report_saved_with_warning( array(), 'copys' ), 'Vacío vuelve al de fábrica, que trae el marcador.' );
	}

	/**
	 * QA2-PANEL-4: con el botón ya guardado sin {serie}, el aviso amarillo
	 * volvía en CADA guardado de la pestaña —aunque el dueño cambiara sólo la
	 * pista del buscador, o nada—, y un botón sin la serie elegido a propósito
	 * no se lo sacaba nunca de encima. Avisa el guardado que lo escribió.
	 *
	 * @testdox El aviso de {serie} sale sólo en el guardado que escribió ese campo, no en los de otros campos.
	 */
	public function test_guide_series_cta_warning_only_when_that_field_was_written(): void {
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// Ya guardado «Ver los tomos»: el formulario lo reenvía tal cual mientras
		// el dueño cambia otro campo. Core no escribe un valor idéntico, así que
		// ningún gancho del campo se dispara.
		$_POST = array(
			CopyManager::OPTION_GUIDE_CTA_SERIES    => 'Ver los tomos',
			CopyManager::OPTION_SEARCH_PLACEHOLDER => 'Busca tu manga',
		);
		$otro_campo = new CopyManager( new AdminPanel() );
		self::assertArrayNotHasKey( CopyManager::OPTION_GUIDE_CTA_SERIES, $otro_campo->report_saved_with_warning( array(), 'copys' ), 'Guardar otro campo no repite el aviso.' );

		// El guardado que SÍ lo escribe avisa.
		$este_campo = new CopyManager( new AdminPanel() );
		$este_campo->note_guide_series_cta_written();
		self::assertArrayHasKey( CopyManager::OPTION_GUIDE_CTA_SERIES, $este_campo->report_saved_with_warning( array(), 'copys' ) );
	}

	/** @testdox Core avisa cuándo se escribe el botón de la guía: al crear la fila y al cambiarla. */
	public function test_guide_series_cta_write_is_noted_from_core_hooks(): void {
		$acciones = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $prioridad = 10, int $args = 1 ) use ( &$acciones ): bool {
				$acciones[ $hook ][] = array( $callback, $args );
				return true;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );

		$manager = new CopyManager( new AdminPanel() );
		$manager->register();

		foreach ( array( 'add_option_', 'update_option_' ) as $gancho ) {
			self::assertContains(
				array( array( $manager, 'note_guide_series_cta_written' ), 0 ),
				$acciones[ $gancho . CopyManager::OPTION_GUIDE_CTA_SERIES ] ?? array(),
				$gancho
			);
		}
	}

	/**
	 * Carril SEO 2026-09-26: el enlace de la barra de la ficha a su serie es un
	 * copy del dueño con {serie}, en su propia sección de Textos, y no puede
	 * quedar vacío (sería un enlace sin texto en 1.641 fichas).
	 *
	 * @testdox El enlace a la serie de la ficha tiene su campo en Textos, con el literal de fábrica y sin poder quedar vacío.
	 */
	public function test_el_campo_del_enlace_a_la_serie_existe_en_textos(): void {
		$campos = array_column( ( new CopyManager( new AdminPanel() ) )->get_settings(), null, 'id' );

		self::assertArrayHasKey( 'akb_copys_pdp_series', $campos, 'Sección propia.' );
		self::assertArrayHasKey( PdpSeriesEnhancer::OPTION_SERIES_LINK, $campos );
		self::assertSame( 'Ver los tomos de {serie}', $campos[ PdpSeriesEnhancer::OPTION_SERIES_LINK ]['default'] );
		self::assertSame( 'Ver los tomos de {serie}', ( new CopyManager( new AdminPanel() ) )->sanitize_required_copy( '', array( 'id' => PdpSeriesEnhancer::OPTION_SERIES_LINK ), '' ), 'Vacío vuelve al de fábrica.' );
	}

	/** @testdox Un enlace a la serie sin {serie} se guarda y el panel lo avisa en el guardado que lo escribió. */
	public function test_avisa_si_el_enlace_a_la_serie_se_guarda_sin_el_marcador(): void {
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_POST = array( PdpSeriesEnhancer::OPTION_SERIES_LINK => 'Ver la colección' );

		$otro_campo = new CopyManager( new AdminPanel() );
		self::assertArrayNotHasKey( PdpSeriesEnhancer::OPTION_SERIES_LINK, $otro_campo->report_saved_with_warning( array(), 'copys' ), 'Sin escribirlo, no avisa.' );

		$manager = new CopyManager( new AdminPanel() );
		$manager->note_pdp_series_link_written();
		$avisos = $manager->report_saved_with_warning( array(), 'copys' );
		self::assertStringContainsString( '{serie}', $avisos[ PdpSeriesEnhancer::OPTION_SERIES_LINK ] ?? '' );
		self::assertArrayNotHasKey( CopyManager::OPTION_GUIDE_CTA_SERIES, $avisos, 'El aviso del botón de la guía no se contagia.' );

		$_POST = array( PdpSeriesEnhancer::OPTION_SERIES_LINK => 'Todos los tomos de {serie}' );
		self::assertArrayNotHasKey( PdpSeriesEnhancer::OPTION_SERIES_LINK, $manager->report_saved_with_warning( array(), 'copys' ) );

		$_POST = array( PdpSeriesEnhancer::OPTION_SERIES_LINK => '' );
		self::assertArrayNotHasKey( PdpSeriesEnhancer::OPTION_SERIES_LINK, $manager->report_saved_with_warning( array(), 'copys' ), 'Vacío vuelve al de fábrica, que trae el marcador.' );
	}

	/** @testdox Core avisa cuándo se escribe el enlace a la serie de la ficha: al crear la fila y al cambiarla. */
	public function test_pdp_series_link_write_is_noted_from_core_hooks(): void {
		$acciones = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $prioridad = 10, int $args = 1 ) use ( &$acciones ): bool {
				$acciones[ $hook ][] = array( $callback, $args );
				return true;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );

		$manager = new CopyManager( new AdminPanel() );
		$manager->register();

		foreach ( array( 'add_option_', 'update_option_' ) as $gancho ) {
			self::assertContains(
				array( array( $manager, 'note_pdp_series_link_written' ), 0 ),
				$acciones[ $gancho . PdpSeriesEnhancer::OPTION_SERIES_LINK ] ?? array(),
				$gancho
			);
		}
	}

	/** @testdox Los avisos de lo que sí se guardó se enganchan al canal propio del panel. */
	public function test_the_saved_with_warning_filter_is_wired(): void {
		$filtros = array();
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $callback ) use ( &$filtros ): bool {
				$filtros[ $hook ][] = $callback;
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );

		$manager = new CopyManager( new AdminPanel() );
		$manager->register();

		self::assertContains( array( $manager, 'report_saved_with_warning' ), $filtros['akibara_panel_options_saved_with_warning'] ?? array() );
	}

	/**
	 * QA-ESC-TECLADO-13: el carrusel de la portada se anunciaba «Hero rotativo
	 * de Akibara», fijo en el código.
	 *
	 * @testdox El nombre del carrusel de la portada se edita en Textos, «Destacados» de fábrica, y no queda vacío.
	 */
	public function test_hero_label_is_exposed_and_required(): void {
		$manager = new CopyManager( new AdminPanel() );
		$by_id   = $this->settings_by_id();

		self::assertArrayHasKey( 'akb_copy_hero_label', $by_id );
		self::assertSame( 'Destacados', $by_id['akb_copy_hero_label']['default'] );
		self::assertSame( 'text', $by_id['akb_copy_hero_label']['type'] );
		self::assertSame( 'Destacados', $manager->sanitize_required_copy( '', array( 'id' => 'akb_copy_hero_label' ), '   ' ), 'Vacío vuelve al de fábrica: el carrusel nunca queda sin nombre.' );
		self::assertSame( 'Lo nuevo', $manager->sanitize_required_copy( '', array( 'id' => 'akb_copy_hero_label' ), "<b>Lo</b>\nnuevo" ), 'Una línea, sin etiquetas.' );
	}

	/**
	 * @testdox La línea de llegada de la barra de compra se edita en Textos, con {fecha}, y no queda vacía.
	 */
	public function test_sticky_bar_eta_copy_is_exposed_and_required(): void {
		$manager = new CopyManager( new AdminPanel() );
		$by_id   = $this->settings_by_id();

		self::assertArrayHasKey( 'akb_copy_eta_bar', $by_id );
		self::assertSame( 'Llega {fecha}', $by_id['akb_copy_eta_bar']['default'] );
		self::assertSame( 'Llega {fecha}', $manager->sanitize_required_copy( '', array( 'id' => 'akb_copy_eta_bar' ), '   ' ) );
	}
}
