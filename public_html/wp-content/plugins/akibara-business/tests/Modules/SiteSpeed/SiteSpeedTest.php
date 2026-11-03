<?php
/**
 * Pestaña «Velocidad»: campos y el estado del recalentado dicho para el dueño.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SiteSpeed;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\ScriptDiagnostics\CrossOriginScripts;
use Akibara\Business\Modules\SiteSpeed\AnalyticsAfterLoad;
use Akibara\Business\Modules\SiteSpeed\CacheWarmer;
use Akibara\Business\Modules\SiteSpeed\GoogleAdsTag;
use Akibara\Business\Modules\SiteSpeed\EdgePurge;
use Akibara\Business\Modules\SiteSpeed\LeanCart;
use Akibara\Business\Modules\SiteSpeed\SeriesCacheTags;
use Akibara\Business\Modules\SiteSpeed\SiteSpeed;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SiteSpeedTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d, ',', '.' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $f, int $t ) => gmdate( 'd/m/Y H:i', $t ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, int|string> $cambios Claves del estado.
	 * @return array<string, int|string>
	 */
	private function estado( array $cambios ): array {
		return array_merge(
			array(
				'started'      => 0,
				'finished'     => 0,
				'day'          => '',
				'manual'       => 0,
				'cursor'       => 0,
				'total'        => 0,
				'calentadas'   => 0,
				'ya_calientes' => 0,
				'sin_cache'    => 0,
				'errores'      => 0,
				'cortada'      => 0,
				'ultimo_error' => '',
			),
			$cambios
		);
	}

	/** @testdox Nunca recalentado. */
	public function test_never_started(): void {
		self::assertSame( 'Todavía no se ha recalentado ninguna ficha.', SiteSpeed::describe_state( $this->estado( array() ), true ) );
		self::assertSame( 'Apagado. Todavía no se ha recalentado ninguna ficha.', SiteSpeed::describe_state( $this->estado( array() ), false ) );
	}

	/** @testdox Una pasada en curso dice cuánto lleva. */
	public function test_open_pass(): void {
		$frase = SiteSpeed::describe_state( $this->estado( array( 'started' => 1789450000, 'cursor' => 120, 'total' => 1639, 'calentadas' => 200, 'ya_calientes' => 40 ) ), true );
		self::assertStringStartsWith( 'En curso desde el ', $frase );
		self::assertStringContainsString( '120 de 1.639 fichas', $frase );
		self::assertStringContainsString( '200 guardadas ahora', $frase );
		self::assertStringContainsString( '40 ya estaban guardadas', $frase );
		self::assertStringNotContainsString( 'error', $frase );
	}

	/** @testdox Una pasada cortada a las 06:00 lo dice, y los errores traen el último. */
	public function test_cut_pass_with_errors(): void {
		$frase = SiteSpeed::describe_state(
			$this->estado( array( 'started' => 1789450000, 'finished' => 1789470000, 'cortada' => 1, 'cursor' => 900, 'total' => 1639, 'errores' => 2, 'ultimo_error' => 'https://akibara.cl/x/: respondió 503' ) ),
			true
		);
		self::assertStringStartsWith( 'Se cortó a las 06:00 el ', $frase );
		self::assertStringContainsString( '900 de 1.639 fichas', $frase );
		self::assertStringContainsString( '2 con error. Último: https://akibara.cl/x/: respondió 503', $frase );
	}

	/** @testdox Una pasada terminada lo dice. */
	public function test_finished_pass(): void {
		$frase = SiteSpeed::describe_state( $this->estado( array( 'started' => 1789450000, 'finished' => 1789460000, 'cursor' => 1639, 'total' => 1639 ) ), true );
		self::assertStringStartsWith( 'Última pasada terminada el ', $frase );
	}

	/** @testdox Los campos: el recalentado siempre; la etiqueta de Ads sólo con Google for WooCommerce. */
	public function test_settings_fields(): void {
		$ids = array_column( ( new SiteSpeed( new AdminPanel(), new CacheWarmer(), new SeriesCacheTags(), new AnalyticsAfterLoad(), new LeanCart() ) )->get_settings(), 'id' );

		self::assertContains( CacheWarmer::OPTION_ENABLED, $ids );
		self::assertContains( EdgePurge::ENABLED_OPTION, $ids );

		// Hallazgo S-0bd6d16c: este apagado de emergencia sólo existía por línea
		// de comandos, y lo que apaga es lo que decide si un script de pago se
		// carga o el navegador lo bloquea.
		self::assertContains(
			CrossOriginScripts::OPTION_ENABLED,
			$ids,
			'El apagado de emergencia de los scripts de terceros tiene que verse en el panel.'
		);
		self::assertContains( AnalyticsAfterLoad::OPTION, $ids, 'PM-2: el retraso de GA4 y Clarity es del dueño y tiene que poder tocarlo.' );
		$campos = array_column( ( new SiteSpeed( new AdminPanel(), new CacheWarmer(), new SeriesCacheTags(), new AnalyticsAfterLoad(), new LeanCart() ) )->get_settings(), null, 'id' );
		self::assertSame( 'no', $campos[ AnalyticsAfterLoad::OPTION ]['default'], 'Apagado por defecto: decisión pendiente del dueño.' );
		self::assertSame( 'checkbox', $campos[ AnalyticsAfterLoad::OPTION ]['type'] );
		if ( GoogleAdsTag::glue_active() ) {
			self::assertContains( GoogleAdsTag::OPTION, $ids );
		} else {
			self::assertNotContains( GoogleAdsTag::OPTION, $ids, 'Sin Google for WooCommerce no hay interruptor que mostrar.' );
		}
		if ( ! LeanCart::glue_active() ) {
			self::assertNotContains( LeanCart::OPTION, $ids, 'Sin Mercado Pago no hay nada que quitar del carrito.' );
		}
	}

	/**
	 * PR-2 (verificación de producción 2026-09-26). Mercado Pago activo: la
	 * constante contamina el proceso.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Con Mercado Pago, la pestaña trae el interruptor del carrito: encendido de fábrica, fuera de alloptions y explicado para el dueño.
	 */
	public function test_con_mercado_pago_el_interruptor_del_carrito(): void {
		define( 'MP_VERSION', '8.9.3' );

		$ajustes = ( new SiteSpeed( new AdminPanel(), new CacheWarmer(), new SeriesCacheTags(), new AnalyticsAfterLoad(), new LeanCart() ) )->get_settings();
		$campos  = array_column( $ajustes, null, 'id' );
		$titulo  = array_values( array_filter( $ajustes, static fn( array $c ): bool => 'akb_site_speed_lean_cart' === ( $c['id'] ?? '' ) && 'title' === $c['type'] ) );

		self::assertArrayHasKey( LeanCart::OPTION, $campos );
		self::assertSame( 'checkbox', $campos[ LeanCart::OPTION ]['type'] );
		self::assertSame( 'yes', $campos[ LeanCart::OPTION ]['default'], 'Encendido de fábrica: en el carrito no hay nada de Mercado Pago que usar.' );
		self::assertFalse( $campos[ LeanCart::OPTION ]['autoload'], 'Sólo se lee en /carrito/.' );

		self::assertCount( 1, $titulo, 'Una sección propia, con su explicación.' );
		$desc = (string) $titulo[0]['desc'];
		self::assertStringContainsString( 'el carrito no los ejecuta', $desc, 'Dice qué quita: la ejecución, no la descarga.' );
		self::assertStringContainsString( 'la página de pago los encuentre ya descargados', $desc, 'Dice que pagar no se vuelve más lento (revisión C-N).' );
		// REV-CN-2o-1: los descargaba al terminar de cargar, y el panel prometía que
		// la página de pago cargaba igual aunque el cliente saliera antes.
		self::assertStringContainsString( 'desde que empieza a cargar', $desc, 'Dice cuándo los descarga.' );
		self::assertStringContainsString( 'aunque el cliente pase a pagar antes de que el carrito termine de cargar', $desc, 'Y que cubre a quien pasa a pagar enseguida.' );
		self::assertStringNotContainsString( 'cuando termina de cargar', $desc, 'Ya no espera al final de la carga.' );
		self::assertStringNotContainsString( 'La página de pago carga lo mismo', $desc, 'Se leía como velocidad; lo que no cambia es lo que descarga y ejecuta.' );
		self::assertStringContainsString( 'Lo que descarga y ejecuta la página de pago es lo mismo con el interruptor encendido o apagado', $desc );
		self::assertStringNotContainsString( 'no los descarga', $desc, 'Los descarga después de cargar: prometer lo contrario es falso.' );
		self::assertStringNotContainsString( 'KB', $desc, 'Con la precarga, el carrito termina bajando casi lo mismo: el ahorro medido en KB ya no vale.' );
		self::assertStringContainsString( 'Apágalo', $desc, 'Dice cuándo apagarlo.' );
		self::assertSame( 'sectionend', $campos['akb_site_speed_lean_cart']['type'], 'La sección se cierra.' );
	}

	/**
	 * QA2-PANEL-2 (QA integral 2026-09-24): el interruptor prometía que Analytics
	 * espera a que la página cargue, y con la etiqueta de Google Ads encendida
	 * no es así: su gtag.js se descarga en crudo y trae Analytics con él.
	 *
	 * @testdox Con la etiqueta de Ads y el retraso de Analytics encendidos a la vez, la pestaña lo avisa; con uno solo, no.
	 */
	public function test_avisa_cuando_ads_deshace_el_retraso_de_analytics(): void {
		self::assertSame( SiteSpeed::ADS_WARNING, SiteSpeed::analytics_ads_warning( true, true ) );
		self::assertSame( '', SiteSpeed::analytics_ads_warning( true, false ) );
		self::assertSame( '', SiteSpeed::analytics_ads_warning( false, true ) );
		self::assertSame( '', SiteSpeed::analytics_ads_warning( false, false ) );
		self::assertStringContainsString( 'Google Analytics', SiteSpeed::ADS_WARNING );
		self::assertStringContainsString( 'Clarity', SiteSpeed::ADS_WARNING, 'Dice también lo que sí sigue esperando.' );
	}

	/**
	 * @testdox La descripción del interruptor dice lo que no cubre sólo cuando existe la etiqueta de Ads.
	 */
	public function test_la_promesa_del_interruptor_dice_su_limite(): void {
		$desc = '';
		foreach ( ( new SiteSpeed( new AdminPanel(), new CacheWarmer(), new SeriesCacheTags(), new AnalyticsAfterLoad(), new LeanCart() ) )->get_settings() as $campo ) {
			if ( 'akb_site_speed_analytics' === ( $campo['id'] ?? '' ) && 'title' === $campo['type'] ) {
				$desc = (string) $campo['desc'];
			}
		}
		self::assertStringContainsString( 'esperan a que la página termine de cargar', $desc );

		if ( GoogleAdsTag::glue_active() ) {
			self::assertStringEndsWith( SiteSpeed::ADS_CAVEAT, $desc );
		} else {
			self::assertStringNotContainsString( SiteSpeed::ADS_CAVEAT, $desc, 'Sin Google for WooCommerce no hay etiqueta de Ads de la que hablar.' );
		}
	}

	/**
	 * @testdox La pestaña consulta el cruce con las dos opciones reales.
	 */
	public function test_la_pestana_consulta_el_cruce(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/SiteSpeed/SiteSpeed.php' );

		self::assertStringContainsString( 'self::analytics_ads_warning( GoogleAdsTag::glue_active() && GoogleAdsTag::enabled(), AnalyticsAfterLoad::enabled() )', $fuente );
	}

	/**
	 * Google for WooCommerce activo: la constante contamina el proceso.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @testdox Con Google for WooCommerce, la descripción dice el límite y la pestaña avisa si están las dos encendidas.
	 */
	public function test_con_google_for_woocommerce_la_pestana_avisa(): void {
		define( 'WC_GLA_VERSION', '3.0.0' );
		$opciones = array( GoogleAdsTag::OPTION => 'yes', AnalyticsAfterLoad::OPTION => 'yes' );
		Functions\when( 'get_option' )->alias( static fn( string $n, $d = false ) => $opciones[ $n ] ?? $d );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://akibara.cl/wp-admin/admin-post.php' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$sut  = new SiteSpeed( new AdminPanel(), new CacheWarmer(), new SeriesCacheTags(), new AnalyticsAfterLoad(), new LeanCart() );
		$desc = '';
		foreach ( $sut->get_settings() as $campo ) {
			if ( 'akb_site_speed_analytics' === ( $campo['id'] ?? '' ) && 'title' === $campo['type'] ) {
				$desc = (string) $campo['desc'];
			}
		}
		self::assertStringEndsWith( SiteSpeed::ADS_CAVEAT, $desc );

		ob_start();
		$sut->render_admin_section();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'notice-warning', $html );
		self::assertStringContainsString( SiteSpeed::ADS_WARNING, $html );
	}
}
