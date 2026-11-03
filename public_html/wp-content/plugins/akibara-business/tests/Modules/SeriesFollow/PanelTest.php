<?php
/**
 * El tab del dueño: todo configurable a un clic, y APAGADO de fábrica.
 *
 * Los campos usan el catálogo REAL de WooCommerce (`woocommerce_update_options`
 * es quien guarda), no el de la Settings API de los emails: ahí el texto del
 * checkbox va en `desc`, y con `label` el interruptor sale sin etiqueta.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesFollow\Install;
use Akibara\Business\Modules\SeriesFollow\SeriesFollow;
use Akibara\Business\Modules\SeriesFollow\SeriesFollowEmail;
use Akibara\Business\Plugin;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;

/** Doble mínimo: sólo `is_enabled()` importa para este candado (S-c2a19ef3). */
final class PanelMismatchSpyEmail extends SeriesFollowEmail {

	public bool $habilitado = false;

	// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	public function __construct() {}

	public function is_enabled(): bool {
		return $this->habilitado;
	}
}

final class PanelTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();

		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
		Functions\when( 'get_option' )->alias(
			fn ( string $option, $default_value = false ) => $this->options[ $option ] ?? $default_value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, array<string, mixed>> Campos indexados por id. */
	private function campos(): array {
		$modulo = ( new \ReflectionClass( SeriesFollow::class ) )->newInstanceWithoutConstructor();

		$campos = array();
		foreach ( $modulo->get_settings() as $campo ) {
			if ( isset( $campo['id'] ) ) {
				$campos[ (string) $campo['id'] ] = $campo;
			}
		}

		return $campos;
	}

	/** @testdox El tab declara los ajustes operables del dueño con sus defaults. */
	public function test_get_settings_declara_los_ajustes_con_sus_defaults(): void {
		$campos = $this->campos();

		self::assertSame( 'no', $campos[ SeriesFollow::OPTION_ENABLED ]['default'] );
		self::assertSame( 'yes', $campos[ SeriesFollow::OPTION_TRIGGER_RESTOCK ]['default'] );
		self::assertSame( SeriesFollow::SCOPE_NEW_AND_GAPS, $campos[ SeriesFollow::OPTION_VOLUME_SCOPE ]['default'] );
		self::assertSame( '7', $campos[ SeriesFollow::OPTION_CAP_DAYS ]['default'] );
		self::assertSame( '6', $campos[ SeriesFollow::OPTION_DELAY_HOURS ]['default'] );
		self::assertSame( '5', $campos[ SeriesFollow::OPTION_MAX_SERIES ]['default'] );
	}

	/** @testdox El selector de alcance de tomos ofrece las tres opciones sin código y valida el valor leído. */
	public function test_volume_scope_ofrece_las_tres_opciones_y_valida_lectura(): void {
		$campo = $this->campos()[ SeriesFollow::OPTION_VOLUME_SCOPE ];

		self::assertSame( 'select', $campo['type'] );
		self::assertArrayHasKey( SeriesFollow::SCOPE_NEW_AND_GAPS, $campo['options'] );
		self::assertArrayHasKey( SeriesFollow::SCOPE_ONLY_NEW, $campo['options'] );
		self::assertArrayHasKey( SeriesFollow::SCOPE_ALL_MISSING, $campo['options'] );

		self::assertSame( SeriesFollow::SCOPE_NEW_AND_GAPS, SeriesFollow::volume_scope() );

		$this->options[ SeriesFollow::OPTION_VOLUME_SCOPE ] = SeriesFollow::SCOPE_ONLY_NEW;
		self::assertSame( SeriesFollow::SCOPE_ONLY_NEW, SeriesFollow::volume_scope() );

		$this->options[ SeriesFollow::OPTION_VOLUME_SCOPE ] = SeriesFollow::SCOPE_ALL_MISSING;
		self::assertSame( SeriesFollow::SCOPE_ALL_MISSING, SeriesFollow::volume_scope() );

		$this->options[ SeriesFollow::OPTION_VOLUME_SCOPE ] = 'invalido';
		self::assertSame( SeriesFollow::SCOPE_NEW_AND_GAPS, SeriesFollow::volume_scope() );
	}

	/** @testdox El interruptor de reposición de stock viene activo por defecto y se puede apagar. */
	public function test_trigger_on_restock_viene_activo_y_se_puede_apagar(): void {
		self::assertTrue( SeriesFollow::trigger_on_restock_enabled() );

		$this->options[ SeriesFollow::OPTION_TRIGGER_RESTOCK ] = 'no';
		self::assertFalse( SeriesFollow::trigger_on_restock_enabled() );
	}

	/** @testdox Cada ajuste numérico declara su rango, para que el dueño no pueda romperlo. */
	public function test_los_ajustes_numericos_declaran_su_rango(): void {
		$campos = $this->campos();

		foreach ( array( SeriesFollow::OPTION_CAP_DAYS, SeriesFollow::OPTION_DELAY_HOURS, SeriesFollow::OPTION_MAX_SERIES ) as $id ) {
			self::assertSame( 'number', $campos[ $id ]['type'] );
			self::assertArrayHasKey( 'min', $campos[ $id ]['custom_attributes'] );
			self::assertArrayHasKey( 'max', $campos[ $id ]['custom_attributes'] );
		}
	}

	/**
	 * `woocommerce_update_options()` pinta el texto del checkbox desde `desc`.
	 * Con `label` —la clave de la Settings API de los emails— el interruptor sale
	 * sin etiqueta y el dueño ve una casilla muda.
	 *
	 * @testdox El checkbox usa la clave `desc` del catálogo de WooCommerce.
	 */
	public function test_el_checkbox_usa_la_clave_desc_del_catalogo_de_wc(): void {
		$checkbox = $this->campos()[ SeriesFollow::OPTION_ENABLED ];

		self::assertSame( 'checkbox', $checkbox['type'] );
		self::assertArrayHasKey( 'desc', $checkbox );
		self::assertArrayNotHasKey( 'label', $checkbox );
		self::assertNotSame( '', trim( (string) $checkbox['desc'] ) );
	}

	/** @testdox Los defaults dejan el carril APAGADO. */
	public function test_los_defaults_dejan_el_carril_apagado(): void {
		self::assertFalse( SeriesFollow::enabled() );
	}

	/** @testdox Los guards leen la option y aplican los mínimos. */
	public function test_los_guards_leen_la_option(): void {
		$this->options = array(
			SeriesFollow::OPTION_ENABLED     => 'yes',
			SeriesFollow::OPTION_CAP_DAYS    => '14',
			SeriesFollow::OPTION_DELAY_HOURS => '24',
			SeriesFollow::OPTION_MAX_SERIES  => '3',
		);

		self::assertTrue( SeriesFollow::enabled() );
		self::assertSame( 14, SeriesFollow::weekly_cap_days() );
		self::assertSame( 24 * HOUR_IN_SECONDS, SeriesFollow::digest_delay_seconds() );
		self::assertSame( 3, SeriesFollow::max_series_per_email() );
	}

	/**
	 * Una ventana de acumulación de CERO horas es una opción legítima («mándalo
	 * ya»), y clamparla al default la convertiría en seis horas de espera
	 * silenciosa.
	 *
	 * @testdox Cero horas de espera significa cero, no el default.
	 */
	public function test_cero_horas_de_espera_significa_cero(): void {
		$this->options[ SeriesFollow::OPTION_DELAY_HOURS ] = '0';

		self::assertSame( 0, SeriesFollow::digest_delay_seconds() );
	}

	/** @testdox Un ajuste borrado a mano cae al default, no a cero. */
	public function test_un_ajuste_vacio_cae_al_default(): void {
		$this->options = array(
			SeriesFollow::OPTION_CAP_DAYS   => '',
			SeriesFollow::OPTION_MAX_SERIES => '0',
		);

		self::assertSame( 7, SeriesFollow::weekly_cap_days() );
		self::assertSame( 5, SeriesFollow::max_series_per_email() );
	}

	/** @testdox La cola se muestra en un campo informativo, sin inputs. */
	public function test_la_cola_es_un_campo_informativo(): void {
		$campos = $this->campos();

		self::assertSame( 'akb_sf_pending', $campos['akb_sf_pending']['type'] );
		self::assertFalse( $campos['akb_sf_pending']['is_option'] );
	}

	/**
	 * Las options son `akb_sf_*`. La frontera importa: el `uninstall.php`
	 * del buscador borra por `akb_search_*` y el de Business por `akb_*`, así que
	 * un nombre en el namespace equivocado se borraría solo.
	 *
	 * @testdox Todas las options del módulo viven en el namespace akb_sf_.
	 */
	public function test_todas_las_options_son_del_namespace_del_modulo(): void {
		$options = array(
			SeriesFollow::OPTION_ENABLED,
			SeriesFollow::OPTION_TRIGGER_RESTOCK,
			SeriesFollow::OPTION_VOLUME_SCOPE,
			SeriesFollow::OPTION_CAP_DAYS,
			SeriesFollow::OPTION_DELAY_HOURS,
			SeriesFollow::OPTION_MAX_SERIES,
			Install::OPTION_KEY,
		);

		foreach ( $options as $option ) {
			self::assertStringStartsWith( 'akb_sf_', $option );
		}
	}

	/**
	 * `maybe_install()` corre dentro de `register()`, o sea en TODAS las
	 * peticiones. Sin calentarla, cada visita paga un SELECT por nombre.
	 *
	 * @testdox La versión de esquema entra en el priming del front.
	 */
	public function test_la_version_de_esquema_entra_en_el_priming_del_front(): void {
		$primed = (array) ( new ReflectionClassConstant( Plugin::class, 'FRONT_READ_OPTIONS' ) )->getValue();

		self::assertContains( Install::OPTION_KEY, $primed );
	}

	/**
	 * Las otras NO entran: ninguna se lee en una petición de visitante, y
	 * una entrada de más calienta una fila que nadie pide.
	 *
	 * @testdox Los ajustes operables NO entran en el priming del front.
	 */
	public function test_los_ajustes_operables_no_entran_en_el_priming(): void {
		$primed = (array) ( new ReflectionClassConstant( Plugin::class, 'FRONT_READ_OPTIONS' ) )->getValue();

		foreach (
			array(
				SeriesFollow::OPTION_ENABLED,
				SeriesFollow::OPTION_TRIGGER_RESTOCK,
				SeriesFollow::OPTION_VOLUME_SCOPE,
				SeriesFollow::OPTION_CAP_DAYS,
				SeriesFollow::OPTION_DELAY_HOURS,
				SeriesFollow::OPTION_MAX_SERIES,
			) as $option
		) {
			self::assertNotContains( $option, $primed );
		}
	}

	/**
	 * El asunto y el cuerpo tienen UNA sola fuente de verdad —los settings del
	 * `WC_Email`— y el tab enlaza a esa pantalla. Duplicarlos aquí crearía dos
	 * pantallas para el mismo texto.
	 *
	 * @testdox El tab NO declara ningún campo de texto del correo.
	 */
	public function test_el_tab_no_duplica_el_texto_del_correo(): void {
		$ids = array_keys( $this->campos() );

		self::assertNotContains( 'akb_sf_subject', $ids );
		self::assertNotContains( 'akb_sf_heading', $ids );
		self::assertNotContains( 'akb_sf_body', $ids );
	}

	/** @testdox El id del correo es el que resuelve el enlace de edición del tab. */
	public function test_el_id_del_correo_es_estable(): void {
		self::assertSame( 'akb_sf_series', SeriesFollowEmail::EMAIL_ID );
	}

	/**
	 * EL DEFECTO QUE CIERRA (S-c2a19ef3, 2026-09-19): mismo patrón que en
	 * BackInStock — el panel decía «avisos activos» leyendo SOLO su propia
	 * option, sin saber que el `WC_Email` real estaba apagado en la pantalla de
	 * WooCommerce.
	 *
	 * @testdox [NEG] Con avisos activos en Akibara y el correo apagado en WC, el panel avisa del desacuerdo.
	 */
	public function test_panel_avisa_si_wc_dice_apagado_y_akibara_dice_activo(): void {
		$this->options[ SeriesFollow::OPTION_ENABLED ] = 'yes';
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path );
		Functions\when( 'esc_url' )->returnArg();

		$email = new PanelMismatchSpyEmail();
		$email->habilitado = false;

		self::assertStringContainsString( 'desactivado', $this->notice_html( $email ) );
	}

	/** @testdox Con los dos interruptores de acuerdo, el panel NO avisa de nada. */
	public function test_panel_no_avisa_si_los_dos_interruptores_coinciden(): void {
		$this->options[ SeriesFollow::OPTION_ENABLED ] = 'yes';
		Functions\when( 'admin_url' )->justReturn( '' );
		Functions\when( 'esc_url' )->returnArg();

		$email = new PanelMismatchSpyEmail();
		$email->habilitado = true;

		self::assertSame( '', $this->notice_html( $email ) );
	}

	/** @testdox Con los avisos ya apagados en Akibara, no hay nada que avisar. */
	public function test_panel_no_avisa_con_los_avisos_apagados_en_akibara(): void {
		$this->options[ SeriesFollow::OPTION_ENABLED ] = 'no';

		self::assertSame( '', $this->notice_html( null ) );
	}

	/**
	 * Invoca el método privado con una factory inyectada en vez de stubear la
	 * función global `WC()`: stubearla CONTAGIA — una vez que
	 * `Functions\when('WC')` la declara redefinible, `function_exists('WC')`
	 * queda `true` para el resto del proceso PHP y rompe pruebas de otros
	 * módulos que no la esperan (medido: tumbaba
	 * `GoogleLoginButton\AccessWallTest` al correr la suite completa).
	 *
	 * @param SeriesFollowEmail|null $email Email a resolver, o null para «no resoluble».
	 */
	private function notice_html( ?SeriesFollowEmail $email ): string {
		$metodo = new ReflectionMethod( SeriesFollow::class, 'wc_email_mismatch_notice_html' );
		$metodo->setAccessible( true );

		return (string) $metodo->invoke( null, static fn () => $email );
	}
}
