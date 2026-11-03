<?php
/**
 * GA4 y Clarity después de la carga (PM-2): apagado por defecto; encendido, las
 * dos etiquetas salen inertes y sus colas siguen vivas desde el primer momento.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SiteSpeed;

use Akibara\Business\Modules\SiteSpeed\AnalyticsAfterLoad;
use Akibara\Business\Modules\SiteSpeed\GoogleAdsTag;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AnalyticsAfterLoadTest extends TestCase {

	/**
	 * The System Under Test.
	 *
	 * @var AnalyticsAfterLoad
	 */
	private AnalyticsAfterLoad $sut;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array<int, mixed>> */
	private array $added = array();

	/** @var array<int, array<int, mixed>> */
	private array $removed = array();

	/** @var int|false */
	private $clarity_priority = false;

	private const GTAG_SRC = 'https://www.googletagmanager.com/gtag/js?id=G-VXW8CW2FXR';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->sut              = new AnalyticsAfterLoad();
		$this->options          = array();
		$this->added            = array();
		$this->removed          = array();
		$this->clarity_priority = false;

		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'add_action' )->alias(
			function ( ...$args ): bool {
				$this->added[] = $args;
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( ...$args ): bool {
				$this->added[] = $args;
				return true;
			}
		);
		Functions\when( 'remove_action' )->alias(
			function ( ...$args ): bool {
				$this->removed[] = $args;
				return true;
			}
		);
		Functions\when( 'has_action' )->alias(
			fn( string $hook, $callback = false ) => ( 'wp_head' === $hook && AnalyticsAfterLoad::CLARITY_PRINTER === $callback ) ? $this->clarity_priority : false
		);
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( string $code, array $attrs = array() ): void {
				echo '<script' . self::attrs( $attrs ) . '>' . $code . "</script>\n";
			}
		);
		Functions\when( 'wp_print_script_tag' )->alias(
			static function ( array $attrs ): void {
				echo '<script' . self::attrs( $attrs ) . "></script>\n";
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @param array<string, mixed> $attrs */
	private static function attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $k => $v ) {
			$out .= ' ' . $k . '="' . htmlspecialchars( (string) $v, ENT_QUOTES ) . '"';
		}
		return $out;
	}

	/** @return array<string, mixed> */
	private function gtag(): array {
		return array(
			'id'    => AnalyticsAfterLoad::GTAG_ID,
			'src'   => self::GTAG_SRC,
			'async' => true,
		);
	}

	/**
	 * @testdox Apagado (fila ausente o cualquier valor que no sea yes), la etiqueta de GA4 sale como siempre.
	 */
	public function test_gtag_is_untouched_unless_explicitly_on(): void {
		foreach ( array( null, 'no', '1', 'YES', '' ) as $valor ) {
			$this->options = null === $valor ? array() : array( AnalyticsAfterLoad::OPTION => $valor );
			self::assertSame( $this->gtag(), $this->sut->defer_gtag( $this->gtag() ), 'Valor ' . var_export( $valor, true ) . ': no se toca.' );
		}

		ob_start();
		$this->sut->print_loader();
		self::assertSame( '', ob_get_clean(), 'Sin nada diferido no hay cargador.' );
	}

	/**
	 * @testdox Encendido, la etiqueta de GA4 sale inerte con su URL a la espera y fuera del optimizador.
	 */
	public function test_gtag_is_deferred_when_on(): void {
		$this->options[ AnalyticsAfterLoad::OPTION ] = 'yes';

		$attrs = $this->sut->defer_gtag( $this->gtag() );

		self::assertSame( 'text/plain', $attrs['type'], 'Un type que no es JavaScript no se ejecuta ni se descarga.' );
		self::assertSame( self::GTAG_SRC, $attrs[ AnalyticsAfterLoad::ATTRIBUTE ] );
		self::assertArrayNotHasKey( 'src', $attrs );
		self::assertArrayNotHasKey( 'async', $attrs );
		self::assertSame( '1', $attrs['data-no-optimize'], 'LiteSpeed no puede reescribirla.' );
		self::assertSame( AnalyticsAfterLoad::GTAG_ID, $attrs['id'] );
	}

	/**
	 * @testdox Encendido, sólo cambia la etiqueta de GA4: su script en línea, las demás y una sin src siguen igual.
	 */
	public function test_only_the_gtag_tag_changes(): void {
		$this->options[ AnalyticsAfterLoad::OPTION ] = 'yes';

		$otras = array(
			array( 'id' => 'google_gtagjs-inline' ),
			array( 'id' => 'akibara-native-cart-js', 'src' => 'https://akibara.cl/x.js' ),
			array( 'src' => 'https://akibara.cl/y.js' ),
			array( 'id' => AnalyticsAfterLoad::GTAG_ID, 'src' => '' ),
		);
		foreach ( $otras as $attrs ) {
			self::assertSame( $attrs, $this->sut->defer_gtag( $attrs ) );
		}
		self::assertSame( 'no es un array', $this->sut->defer_gtag( 'no es un array' ) );
	}

	/**
	 * @testdox Encendido y con Clarity enganchado, su impresora se cambia por la de dos tiempos en la misma prioridad.
	 */
	public function test_clarity_printer_is_swapped_when_on(): void {
		$this->options[ AnalyticsAfterLoad::OPTION ] = 'yes';
		$this->clarity_priority                      = 10;

		$this->sut->swap_clarity_printer();

		self::assertSame( array( array( 'wp_head', AnalyticsAfterLoad::CLARITY_PRINTER, 10 ) ), $this->removed );
		self::assertSame( array( array( 'wp_head', array( $this->sut, 'print_clarity' ), 10 ) ), $this->added );
	}

	/**
	 * @testdox Apagado, o con Clarity ya quitado (espejo, QA), la impresora de Clarity no se toca.
	 */
	public function test_clarity_printer_untouched_when_off_or_absent(): void {
		$this->clarity_priority = 10;
		$this->sut->swap_clarity_printer();

		$this->options[ AnalyticsAfterLoad::OPTION ] = 'yes';
		$this->clarity_priority                      = false;
		$this->sut->swap_clarity_printer();

		self::assertSame( array(), $this->removed );
		self::assertSame( array(), $this->added );
	}

	/**
	 * @testdox La cola de Clarity se define al instante y su etiqueta espera; luego el cargador sale en el pie.
	 */
	public function test_clarity_queue_now_tag_later(): void {
		$this->options[ AnalyticsAfterLoad::OPTION ] = 'yes';
		$this->options['clarity_project_id']         = ' wfbtgymm9g ';

		ob_start();
		$this->sut->print_clarity();
		$head = (string) ob_get_clean();

		self::assertStringContainsString( 'window.clarity=window.clarity||function(){(window.clarity.q=window.clarity.q||[]).push(arguments)};', $head, 'Sin la cola, los eventos de comercio se perderían mientras la etiqueta espera.' );
		self::assertStringContainsString( 'type="text/plain"', $head );
		self::assertStringContainsString( AnalyticsAfterLoad::ATTRIBUTE . '="https://www.clarity.ms/tag/wfbtgymm9g?ref=wordpress"', $head );
		self::assertStringNotContainsString( 'src=', $head, 'La etiqueta de Clarity no puede descargarse al leer el HTML.' );
		self::assertSame( 2, substr_count( $head, 'data-no-optimize="1"' ) );

		ob_start();
		$this->sut->print_loader();
		$pie = (string) ob_get_clean();
		$js  = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/analytics-after-load.js' );

		self::assertStringContainsString( 'id="akb-analytics-after-load"', $pie );
		self::assertStringContainsString( 'data-no-optimize="1"', $pie );
		self::assertStringContainsString( trim( $js ), $pie );
	}

	/**
	 * @testdox Sin proyecto de Clarity no se imprime nada, igual que el plugin.
	 */
	public function test_no_clarity_project_prints_nothing(): void {
		$this->options[ AnalyticsAfterLoad::OPTION ] = 'yes';
		$this->options['clarity_project_id']         = '   ';

		ob_start();
		$this->sut->print_clarity();
		$this->sut->print_loader();
		self::assertSame( '', ob_get_clean() );
	}

	/**
	 * @testdox El id del proyecto no puede romper la URL ni el atributo.
	 */
	public function test_project_id_is_encoded(): void {
		$this->options[ AnalyticsAfterLoad::OPTION ] = 'yes';
		$this->options['clarity_project_id']         = 'ab"c d';

		ob_start();
		$this->sut->print_clarity();
		$head = (string) ob_get_clean();

		self::assertStringContainsString( 'https://www.clarity.ms/tag/ab%22c%20d?ref=wordpress', $head );
	}

	/**
	 * @testdox Se engancha en el front: filtro de GA4, cambio de Clarity antes de wp_head:10, cargador en el pie y purga al cambiar.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$ganchos = array_map( static fn( array $a ): string => $a[0] . ':' . ( $a[2] ?? 10 ), $this->added );
		self::assertContains( 'wp_script_attributes:10', $ganchos );
		self::assertContains( 'wp_head:1', $ganchos );
		self::assertContains( 'wp_footer:99', $ganchos );
		self::assertContains( 'update_option_' . AnalyticsAfterLoad::OPTION . ':10', $ganchos );
		self::assertContains( 'add_option_' . AnalyticsAfterLoad::OPTION . ':10', $ganchos );

		$purga = array_values( array_filter( $this->added, static fn( array $a ): bool => 'update_option_' . AnalyticsAfterLoad::OPTION === $a[0] ) );
		self::assertSame( array( GoogleAdsTag::class, 'purge_after_change' ), $purga[0][1], 'Misma regla que la etiqueta de Ads: purga sólo si cambia.' );
	}

	/**
	 * @testdox En el admin, donde guarda el panel, se engancha la purga y nada del front.
	 */
	public function test_register_hooks_only_the_purge_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->sut->register();

		// El panel guarda en `admin_init` (is_admin() verdadero): sin estos dos
		// ganchos ahí, encender o apagar la casilla no purgaba y el HTML cacheado
		// seguía con las etiquetas de antes (revisión B5-R1).
		$ganchos = array_map( static fn( array $a ): string => $a[0] . ':' . ( $a[2] ?? 10 ), $this->added );
		self::assertSame(
			array(
				'update_option_' . AnalyticsAfterLoad::OPTION . ':10',
				'add_option_' . AnalyticsAfterLoad::OPTION . ':10',
			),
			$ganchos,
			'En el admin sólo la purga: nada de filtros ni impresores del front.'
		);
		self::assertSame( array( GoogleAdsTag::class, 'purge_after_change' ), $this->added[0][1] );
		self::assertSame( array( GoogleAdsTag::class, 'purge_after_add' ), $this->added[1][1] );
	}

	/**
	 * @testdox Guardar la casilla desde el panel deja la purga de LiteSpeed en cola al cambiar de valor, y no si el valor no cambia.
	 */
	public function test_panel_save_queues_the_purge(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->sut->register();

		$purga = array_values( array_filter( $this->added, static fn( array $a ): bool => 'update_option_' . AnalyticsAfterLoad::OPTION === $a[0] ) );
		self::assertCount( 1, $purga );
		$this->added = array();

		// Guardar sin cambiar el valor: no hay nada que purgar.
		call_user_func( $purga[0][1], 'no', 'no' );
		self::assertSame( array(), $this->added, 'Sin cambio de valor no se purga.' );

		// Encender: la purga queda para el final de la petición.
		call_user_func( $purga[0][1], 'no', 'yes' );
		self::assertCount( 1, $this->added );
		self::assertSame( 'shutdown', $this->added[0][0] );
	}
}
