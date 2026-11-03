<?php
/**
 * El interruptor de la campaña se apaga solo al día siguiente del «hasta».
 *
 * El descuento ya dejaba de aplicarse por la ventana; lo que quedaba
 * encendido era el interruptor, y el panel decía «Activo pero fuera de
 * vigencia» hasta que alguien lo apagara a mano (orden del dueño 2026-09-12).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\CampaignExpiryCron;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CampaignExpiryCronTest extends TestCase {

	/** @var array<int, array{0: string, 1: mixed}> Escrituras de options. */
	private array $escrituras = array();

	/** @var array<string, mixed> Transients escritos. */
	private array $transients = array();

	/** @var array<int, array{0: string, 1: string}> Líneas del registro: nivel y mensaje. */
	private array $registro = array();

	/** @var bool Lo que devuelve update_option. */
	private bool $escritura_entra = true;

	/** @var mixed */
	private $wpdb_original;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->escrituras      = array();
		$this->transients      = array();
		$this->registro        = array();
		$this->escritura_entra = true;
		$this->wpdb_original   = $GLOBALS['wpdb'] ?? null;

		Functions\when( 'get_option' )->alias( static fn( string $o, $d = false ) => $d );
		Functions\when( 'update_option' )->alias(
			function ( string $option, $value ): bool {
				$this->escrituras[] = array( $option, $value );
				return $this->escritura_entra;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		// El registro se captura por REFERENCIA: una arrow function lo copiaría
		// por valor al crear la closure y las líneas escritas se perderían.
		$registro = &$this->registro;
		$logger   = new class( $registro ) {
			/** @var array<int, array{0: string, 1: string}> */
			private array $registro;
			public function __construct( array &$registro ) {
				$this->registro = &$registro;
			}
			public function info( string $m, array $c = array() ): void {
				unset( $c );
				$this->registro[] = array( 'info', $m );
			}
			public function warning( string $m, array $c = array() ): void {
				unset( $c );
				$this->registro[] = array( 'warning', $m );
			}
		};
		Functions\when( 'wc_get_logger' )->justReturn( $logger );
	}

	protected function tearDown(): void {
		if ( null === $this->wpdb_original ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->wpdb_original;
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * La ventana como la lee el cron: de la BASE, no de la caché de options.
	 */
	private function ventana_en_base( string $enabled, string $from, string $to ): void {
		$rows = array(
			array(
				'option_name'  => Settings::OPTION_ENABLED,
				'option_value' => $enabled,
			),
			array(
				'option_name'  => Settings::OPTION_FROM,
				'option_value' => $from,
			),
			array(
				'option_name'  => Settings::OPTION_TO,
				'option_value' => $to,
			),
		);

		$GLOBALS['wpdb'] = new class( $rows ) {
			public string $options = 'wp_options';
			/** @var array<int, array<string, string>> */
			private array $rows;
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}
			public function prepare( string $query, ...$args ): string {
				unset( $args );
				return $query;
			}
			public function get_results( string $query, $output = null ): array {
				unset( $query, $output );
				return $this->rows;
			}
		};
	}

	private function cron(): CampaignExpiryCron {
		return new CampaignExpiryCron( new Settings() );
	}

	/** @testdox Al día siguiente del «hasta» apaga el interruptor, lo registra y deja rastro para el panel. */
	public function test_apaga_el_dia_siguiente_al_hasta(): void {
		$this->ventana_en_base( 'yes', '2026-08-15', '2026-09-11' );

		self::assertSame( 'off', $this->cron()->handle( '2026-09-12' ) );
		self::assertSame( array( array( Settings::OPTION_ENABLED, 'no' ) ), $this->escrituras, 'Una sola escritura, la del interruptor.' );
		self::assertSame(
			array(
				'to'     => '2026-09-11',
				'off_at' => '2026-09-12',
			),
			$this->transients[ CampaignExpiryCron::NOTICE_TRANSIENT ]
		);
		self::assertSame( 'info', $this->registro[0][0] );
		self::assertStringContainsString( '2026-09-11', $this->registro[0][1] );
	}

	/** @testdox El día «hasta» todavía es campaña: no se toca nada. */
	public function test_el_dia_hasta_todavia_es_campana(): void {
		$this->ventana_en_base( 'yes', '2026-08-15', '2026-09-12' );

		self::assertSame( 'kept', $this->cron()->handle( '2026-09-12' ) );
		self::assertSame( array(), $this->escrituras );
		self::assertSame( array(), $this->transients );
	}

	/** @testdox Una campaña sin «hasta» no caduca sola: la gobierna el interruptor. */
	public function test_sin_hasta_no_hay_nada_que_apagar(): void {
		$this->ventana_en_base( 'yes', '2026-08-15', '' );

		self::assertSame( 'kept', $this->cron()->handle( '2027-01-01' ) );
		self::assertSame( array(), $this->escrituras );
	}

	/** @testdox Apagada a mano, el cron no escribe ni deja rastro. */
	public function test_apagada_a_mano_no_toca_nada(): void {
		$this->ventana_en_base( 'no', '2026-08-15', '2026-09-11' );

		self::assertSame( 'kept', $this->cron()->handle( '2026-09-12' ) );
		self::assertSame( array(), $this->escrituras );
		self::assertSame( array(), $this->transients );
	}

	/** @testdox Si la publicación del buscador rechaza la escritura, se registra y mañana se reintenta. */
	public function test_escritura_rechazada_se_registra_y_reintenta(): void {
		$this->ventana_en_base( 'yes', '', '2026-09-11' );
		$this->escritura_entra = false;

		self::assertSame( 'rejected', $this->cron()->handle( '2026-09-12' ) );
		self::assertSame( array(), $this->transients, 'Sin apagado real no hay rastro que mostrar.' );
		self::assertSame( 'warning', $this->registro[0][0] );
		self::assertStringContainsString( 'Se reintenta mañana', $this->registro[0][1] );
	}

	/** @testdox Engancha su corrida y se agenda a diario si falta. */
	public function test_engancha_y_agenda(): void {
		$agendados = array();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( int $ts, string $recurrence, string $hook ) use ( &$agendados ): bool {
				unset( $ts );
				$agendados[] = array( $recurrence, $hook );
				return true;
			}
		);

		$cron = $this->cron();
		$cron->register();
		$cron->maybe_schedule();

		self::assertSame( 10, has_action( CampaignExpiryCron::HOOK, array( $cron, 'handle' ) ) );
		self::assertSame( 10, has_action( 'init', array( $cron, 'maybe_schedule' ) ) );
		self::assertSame( array( array( 'daily', CampaignExpiryCron::HOOK ) ), $agendados );
	}
}
