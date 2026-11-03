<?php
/**
 * El candado GLOBAL del listener: dos pasadas solapadas mandaban DOS correos
 * al cliente; el perdedor ahora reintenta y sólo suelta el candado quien lo tiene.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Tests\Support\NullEtaProvider;

use Akibara\Business\Modules\OrderPreventa\ReleaseDateChangeListener;
use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Doble de `$wpdb`: decide quién gana el INSERT IGNORE y guarda la fila del
 * candado. TODO pasa por aquí y nada por la caché de options, que en
 * producción (caché de objetos persistente) mentía.
 */
final class WpdbCandado {
	public string $options = 'wp_options';
	public int $gana = 1;
	/** @var string|null Sello del candado en «la tabla» cuando llega el UPDATE. */
	public ?string $fila = null;

	/** @var string|null Lo que el SELECT devolvió (puede estar desfasado respecto a `$fila`). Null = lo mismo que `$fila`. */
	public ?string $leido = null;
	/** @var array<int, string> */
	public array $consultas = array();

	public function prepare( string $sql, ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
	}

	public function query( string $sql ): int {
		$this->consultas[] = $sql;
		if ( str_starts_with( $sql, 'UPDATE' ) ) {
			// La toma del huérfano: sólo gana quien reemplaza exactamente el
			// sello que leyó (CAS). Dos reintentos coincidentes no ganan los dos.
			preg_match( "/SET `option_value` = '([^']*)'.*AND `option_value` = '([^']*)'/s", $sql, $m );
			if ( isset( $m[2] ) && $m[2] === (string) $this->fila ) {
				$this->fila = $m[1];
				return 1;
			}
			return 0;
		}
		if ( 1 === $this->gana ) {
			$this->fila = (string) time();
		}
		return $this->gana;
	}

	public function get_var( string $sql ): ?string {
		$this->consultas[] = $sql;
		return $this->leido ?? $this->fila;
	}

	/** @param array<string,string> $where */
	public function delete( string $table, array $where ): int {
		$this->fila = null;
		return 1;
	}
}

final class ReleaseDateChangeLockTest extends TestCase {

	private WpdbCandado $wpdb;

	/** @var array<int, array<string, mixed>> */
	private array $agendado = array();

	/** @var array<string, mixed> */
	private array $options = array();

	private int $pasadas = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->vieja = gmdate( 'Y-m-d', time() + 400 * DAY_IN_SECONDS );
		$this->nueva = gmdate( 'Y-m-d', time() + 430 * DAY_IN_SECONDS );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );

		$this->wpdb      = new WpdbCandado();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->agendado  = array();
		$this->options   = array();
		$this->pasadas   = 0;

		$opts =& $this->options;
		Functions\when( 'get_option' )->alias( static function ( string $k, $d = false ) use ( &$opts ) { return $opts[ $k ] ?? $d; } );
		Functions\when( 'update_option' )->alias( static function ( string $k, $v ) use ( &$opts ): bool { $opts[ $k ] = $v; return true; } );
		Functions\when( 'delete_option' )->alias( static function ( string $k ) use ( &$opts ): bool { unset( $opts[ $k ] ); return true; } );

		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);

		$agendado =& $this->agendado;
		Functions\when( 'as_schedule_single_action' )->alias( static function ( ...$a ) use ( &$agendado ): int { $agendado[] = $a; return 1; } );

		// La pasada real: se cuenta y no se hace nada más.
		$pasadas =& $this->pasadas;
		Functions\when( 'wc_get_orders' )->alias( static function () use ( &$pasadas ) { ++$pasadas; return (object) array( 'orders' => array(), 'max_num_pages' => 1 ); } );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fechas RELATIVAS al reloj: con literales, la prueba caducaba el día en que la fecha «futura» pasaba. */
	private string $vieja = '';

	private string $nueva = '';

	private function listener(): ReleaseDateChangeListener {
		return new ReleaseDateChangeListener( new SummaryRecalculator( new NullEtaProvider() ) );
	}

	/**
	 * @testdox Con el candado ganado, la pasada corre y el candado se libera al final.
	 */
	public function test_winner_runs_and_releases(): void {
		$this->wpdb->gana = 1;

		$this->listener()->run_release_date_change( 4242, $this->vieja, $this->nueva );

		self::assertSame( 1, $this->pasadas );
		self::assertStringContainsString( 'INSERT IGNORE', $this->wpdb->consultas[0] );
		self::assertStringContainsString( '_akb_preventa_datechg_lock', $this->wpdb->consultas[0] );
		self::assertNull( $this->wpdb->fila, 'El candado se libera aunque la pasada esté vacía.' );
		self::assertSame( array(), $this->agendado );
	}

	/**
	 * @testdox Con otra pasada del mismo producto en vuelo, NO corre y se reintenta en un minuto.
	 */
	public function test_loser_retries_instead_of_dropping_the_change(): void {
		$this->wpdb->gana = 0;
		$this->wpdb->fila = (string) time(); // fresco, puesto por la otra pasada
		// La caché de options NO tiene la fila (la otra pasada insertó a pelo):
		// leerla por get_option() diría «no existe» y robaría el candado.
		$this->options = array();

		$this->listener()->run_release_date_change( 4242, $this->vieja, $this->nueva );

		self::assertSame( 0, $this->pasadas, 'Dos pasadas solapadas = dos correos al cliente.' );
		self::assertCount( 1, $this->agendado, 'Suprimir el aviso en silencio es lo que el módulo prohíbe.' );
		self::assertSame( array( 'product_id' => 4242, 'old_release_date' => $this->vieja, 'new_release_date' => $this->nueva ), $this->agendado[0][2] );
		self::assertGreaterThan( time(), $this->agendado[0][0] );
	}

	/**
	 * @testdox Dos reintentos sobre el mismo huérfano: sólo uno lo toma (la toma es un UPDATE condicional).
	 */
	public function test_two_losers_cannot_both_take_a_stale_lock(): void {
		$caducado         = (string) ( time() - 16 * MINUTE_IN_SECONDS );
		$this->wpdb->gana = 0;
		$this->wpdb->fila = $caducado;

		$this->listener()->run_release_date_change( 4242, $this->vieja, $this->nueva );
		self::assertSame( 1, $this->pasadas, 'El primero lo toma y procesa.' );
		self::assertNotSame( $caducado, $this->wpdb->fila, 'Y renovó el sello.' );

		// El segundo LEYÓ el sello caducado antes de que el primero lo renovara:
		// su UPDATE condicional (`AND option_value = <lo leído>`) no encuentra la
		// fila y pierde. Un UPDATE sin cláusula lo habría «tomado» igual.
		$this->wpdb->leido = $caducado;
		$this->wpdb->fila  = (string) time();
		$this->listener()->run_release_date_change( 4242, $this->vieja, $this->nueva );

		self::assertSame( 1, $this->pasadas, 'El segundo NO procesa: reintenta.' );
		self::assertCount( 1, $this->agendado );
		$updates = array_values( array_filter( $this->wpdb->consultas, static fn( string $q ): bool => str_starts_with( $q, 'UPDATE' ) ) );
		self::assertStringContainsString( "AND `option_value` = '" . $caducado . "'", (string) end( $updates ), 'La toma compara con lo que leyó.' );
	}

	/**
	 * El perdedor SIN cola donde reintentar procesa igual (perder el aviso es
	 * peor que el riesgo de solape) pero NO suelta el candado ajeno.
	 *
	 * @testdox Sin cola, el perdedor procesa y deja intacto el candado del ganador.
	 */
	public function test_a_loser_without_a_queue_processes_but_keeps_the_others_lock(): void {
		$this->wpdb->gana = 0;
		$this->wpdb->fila = (string) time(); // otra pasada lo tiene, fresco

		$sin_cola = new ReleaseDateChangeListener( new SummaryRecalculator( new NullEtaProvider() ), null, static fn(): bool => false );
		$sin_cola->run_release_date_change( 4242, $this->vieja, $this->nueva );

		self::assertSame( 1, $this->pasadas, 'Procesa igual: no hay dónde reintentar.' );
		self::assertSame( array(), $this->agendado );
		self::assertNotNull( $this->wpdb->fila, 'Si soltara el candado del ganador, dos pasadas solapadas = dos correos.' );
		self::assertSame( array(), array_filter( $this->wpdb->consultas, static fn( string $q ): bool => str_starts_with( $q, 'DELETE' ) ) );
	}

	/**
	 * @testdox Un candado huérfano (pasada muerta hace más de 15 minutos) se toma.
	 */
	public function test_stale_lock_is_taken_over(): void {
		$this->wpdb->gana = 0;
		$this->wpdb->fila = (string) ( time() - 16 * MINUTE_IN_SECONDS );

		$this->listener()->run_release_date_change( 4242, $this->vieja, $this->nueva );

		self::assertSame( 1, $this->pasadas );
		self::assertSame( array(), $this->agendado );
		self::assertNull( $this->wpdb->fila );
	}
}
