<?php
/**
 * Contrato transaccional del par mode+buckets de Precio.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\FacetRegistry;
use Akibara\Search\PriceBuckets;
use Akibara\Search\SearchAdminSection;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/SearchAdminSection.php';

/** Doble transaccional que ejecuta el UPSERT multi-row de PriceBuckets. */
final class PricePairWpdb {

	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $last_error = '';

	/** @var array<string, array{option_value: string, autoload: string}> */
	public array $rows = array();

	/** @var array<int, string> */
	public array $queries = array();

	public bool $fail_upsert = false;
	public bool $fail_commit = false;
	public bool $corrupt_after_upsert = false;

	/** @var array<string, array{option_value: string, autoload: string}> */
	private array $snapshot = array();

	private bool $in_transaction = false;

	/** @param mixed ...$args Valores SQL. */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace_callback(
				'/%[sd]/',
				static fn(): string => $replacement,
				$query,
				1
			);
		}
		return $query;
	}

	/** @return object|null */
	public function get_row( string $query ): ?object {
		$this->queries[]  = $query;
		$this->last_error = '';
		$values           = self::quoted_values( $query );
		$name             = (string) ( $values[0] ?? '' );
		return isset( $this->rows[ $name ] ) ? (object) $this->rows[ $name ] : null;
	}

	/** @return int|false */
	public function query( string $query ) {
		$this->queries[] = $query;
		$verb            = strtoupper( (string) strtok( ltrim( $query ), " \n\t" ) );

		if ( 'START' === $verb ) {
			$this->last_error    = '';
			$this->snapshot      = $this->rows;
			$this->in_transaction = true;
			return 0;
		}
		if ( 'ROLLBACK' === $verb ) {
			$this->last_error = '';
			if ( $this->in_transaction ) {
				$this->rows = $this->snapshot;
			}
			$this->in_transaction = false;
			return 0;
		}
		if ( 'COMMIT' === $verb ) {
			if ( $this->fail_commit ) {
				$this->last_error = 'simulated commit failure';
				return false;
			}
			$this->last_error     = '';
			$this->in_transaction = false;
			return 0;
		}
		if ( 'INSERT' !== $verb ) {
			$this->last_error = '';
			return 0;
		}
		if ( $this->fail_upsert ) {
			$this->last_error = 'simulated pair upsert failure';
			return false;
		}

		$values = self::quoted_values( $query );
		if ( 6 > count( $values ) ) {
			$this->last_error = 'malformed pair upsert';
			return false;
		}
		$this->last_error = '';
		$this->rows[ $values[0] ] = array( 'option_value' => $values[1], 'autoload' => $values[2] );
		$this->rows[ $values[3] ] = array( 'option_value' => $values[4], 'autoload' => $values[5] );
		if ( $this->corrupt_after_upsert ) {
			$this->rows[ PriceBuckets::OPTION_MODE ]['option_value'] = 'corrupt';
		}
		return 2;
	}

	/** @return array<int, string> */
	private static function quoted_values( string $query ): array {
		preg_match_all( "/'((?:''|[^'])*)'/", $query, $matches );
		return array_map( static fn( string $value ): string => str_replace( "''", "'", $value ), $matches[1] ?? array() );
	}
}

/** @covers \Akibara\Search\PriceBuckets */
final class PriceBucketsTransitionTest extends TestCase {

	/** @var mixed */
	private $previous_wpdb;

	private bool $had_previous_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		$this->had_previous_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb     = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		if ( $this->had_previous_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		parent::tearDown();
	}

	/** @testdox Un cambio de bounds escribe MANUAL y buckets juntos en un solo DML. */
	public function test_changed_bounds_commit_manual_pair_in_one_statement(): void {
		$db = $this->database_with_pair( 'auto', $this->rows( 10000 ) );
		$GLOBALS['wpdb'] = $db;

		$result = $this->persist_pair( $this->rows( 20000 ), 'manual_if_changed', false );

		self::assertSame( array( 'applied' => true, 'changed' => true ), $result );
		self::assertSame( 'manual', $db->rows[ PriceBuckets::OPTION_MODE ]['option_value'] );
		self::assertSame( serialize( $this->rows( 20000 ) ), $db->rows[ FacetRegistry::OPTION_PRECIO_BUCKETS ]['option_value'] );
		self::assertCount( 1, $this->queries_starting_with( $db, 'INSERT' ) );
		self::assertStringContainsString( 'VALUES', $this->queries_starting_with( $db, 'INSERT' )[0] );
		self::assertSame( array( FacetRegistry::OPTION_PRECIO_BUCKETS, PriceBuckets::OPTION_MODE ), $this->first_locked_names( $db ) );
	}

	/** @testdox Re-derivar labels con bounds iguales conserva AUTO aunque persista el par normalizado. */
	public function test_same_bounds_never_switch_to_manual(): void {
		$stored = $this->rows( 10000 );
		$target = $stored;
		$stored[0]['label'] = 'Etiqueta antigua';
		$db = $this->database_with_pair( 'auto', $stored );
		$GLOBALS['wpdb'] = $db;

		$result = $this->persist_pair( $target, 'manual_if_changed', false );

		self::assertSame( array( 'applied' => true, 'changed' => true ), $result );
		self::assertSame( 'auto', $db->rows[ PriceBuckets::OPTION_MODE ]['option_value'] );
		self::assertSame( serialize( $target ), $db->rows[ FacetRegistry::OPTION_PRECIO_BUCKETS ]['option_value'] );
	}

	/** @testdox Recalcular conmuta MANUAL a AUTO junto a los buckets derivados. */
	public function test_recalculate_commits_auto_and_derived_buckets_together(): void {
		$db = $this->database_with_pair( 'manual', $this->rows( 10000 ) );
		$GLOBALS['wpdb'] = $db;

		$result = $this->persist_pair( $this->rows( 30000 ), 'auto', false );

		self::assertSame( array( 'applied' => true, 'changed' => true ), $result );
		self::assertSame( 'auto', $db->rows[ PriceBuckets::OPTION_MODE ]['option_value'] );
		self::assertSame( serialize( $this->rows( 30000 ) ), $db->rows[ FacetRegistry::OPTION_PRECIO_BUCKETS ]['option_value'] );
		self::assertCount( 1, $this->queries_starting_with( $db, 'INSERT' ) );
	}

	/** @testdox Un refresh condicional observa MANUAL bajo lock y no escribe ninguna mitad. */
	public function test_only_if_auto_skips_pair_when_manual_wins_before_lock(): void {
		$db = $this->database_with_pair( 'manual', $this->rows( 10000 ) );
		$GLOBALS['wpdb'] = $db;

		$result = $this->persist_pair( $this->rows( 40000 ), 'auto', true );

		self::assertSame( array( 'applied' => false, 'changed' => false ), $result );
		self::assertSame( 'manual', $db->rows[ PriceBuckets::OPTION_MODE ]['option_value'] );
		self::assertSame( serialize( $this->rows( 10000 ) ), $db->rows[ FacetRegistry::OPTION_PRECIO_BUCKETS ]['option_value'] );
		self::assertCount( 0, $this->queries_starting_with( $db, 'INSERT' ) );
	}

	/** @testdox Un fallo del UPSERT ejecuta ROLLBACK y restaura ambos valores exactos. */
	public function test_pair_upsert_failure_rolls_back_both_rows(): void {
		$db = $this->database_with_pair( 'manual', $this->rows( 10000 ) );
		$before = $db->rows;
		$db->fail_upsert = true;
		$GLOBALS['wpdb'] = $db;

		$result = $this->persist_pair( $this->rows( 50000 ), 'auto', false );

		self::assertFalse( $result );
		self::assertSame( $before, $db->rows );
		self::assertCount( 1, $this->queries_starting_with( $db, 'ROLLBACK' ) );
		self::assertCount( 0, $this->queries_starting_with( $db, 'COMMIT' ) );
	}

	/** @testdox Una relectura post-DML incoherente revierte el par antes del COMMIT. */
	public function test_verification_failure_rolls_back_pair(): void {
		$db = $this->database_with_pair( 'manual', $this->rows( 10000 ) );
		$before = $db->rows;
		$db->corrupt_after_upsert = true;
		$GLOBALS['wpdb'] = $db;

		$result = $this->persist_pair( $this->rows( 60000 ), 'auto', false );

		self::assertFalse( $result );
		self::assertSame( $before, $db->rows );
		self::assertCount( 1, $this->queries_starting_with( $db, 'ROLLBACK' ) );
	}

	/** @testdox Un fallo de COMMIT no se reporta como éxito y ejecuta ROLLBACK. */
	public function test_commit_failure_fails_closed_and_rolls_back(): void {
		$db = $this->database_with_pair( 'manual', $this->rows( 10000 ) );
		$before = $db->rows;
		$db->fail_commit = true;
		$GLOBALS['wpdb'] = $db;

		$result = $this->persist_pair( $this->rows( 70000 ), 'auto', false );

		self::assertFalse( $result );
		self::assertSame( $before, $db->rows );
		self::assertCount( 1, $this->queries_starting_with( $db, 'ROLLBACK' ) );
	}

	/** @testdox Un catálogo sin bandas persiste AUTO y array vacío como par explícito. */
	public function test_empty_auto_transition_keeps_explicit_coherent_pair(): void {
		$db = $this->database_with_pair( 'manual', $this->rows( 10000 ) );
		$GLOBALS['wpdb'] = $db;

		$result = $this->persist_pair( array(), 'auto', false );

		self::assertSame( array( 'applied' => true, 'changed' => true ), $result );
		self::assertSame( 'auto', $db->rows[ PriceBuckets::OPTION_MODE ]['option_value'] );
		self::assertSame( serialize( array() ), $db->rows[ FacetRegistry::OPTION_PRECIO_BUCKETS ]['option_value'] );
	}

	/** @testdox Guardar y Recalcular del admin enrutan por la misma API de PriceBuckets. */
	public function test_admin_routes_both_price_actions_through_atomic_api(): void {
		$method = new \ReflectionMethod( SearchAdminSection::class, 'handle_save_precios' );
		$lines  = file( (string) $method->getFileName() );
		$source = implode(
			'',
			array_slice( $lines ?: array(), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 )
		);

		self::assertStringContainsString( 'PriceBuckets::refresh()', $source );
		self::assertStringContainsString( 'PriceBuckets::save_manual(', $source );
		self::assertStringNotContainsString( 'persist_options_exact(', $source );
		self::assertStringNotContainsString( 'delete_option_exact(', $source );
	}

	/**
	 * @param string                                                       $mode Modo inicial.
	 * @param array<int, array{min: int|null, max: int|null, label: string}> $rows Buckets iniciales.
	 */
	private function database_with_pair( string $mode, array $rows ): PricePairWpdb {
		$db = new PricePairWpdb();
		$db->rows = array(
			PriceBuckets::OPTION_MODE => array( 'option_value' => $mode, 'autoload' => 'no' ),
			FacetRegistry::OPTION_PRECIO_BUCKETS => array( 'option_value' => serialize( $rows ), 'autoload' => 'no' ),
		);
		return $db;
	}

	/** @return array<int, array{min: int|null, max: int|null, label: string}> */
	private function rows( int $cut ): array {
		return array(
			array( 'min' => null, 'max' => $cut, 'label' => PriceBuckets::derive_label( null, $cut ) ),
			array( 'min' => $cut, 'max' => null, 'label' => PriceBuckets::derive_label( $cut, null ) ),
		);
	}

	/**
	 * @param array<int, array{min: int|null, max: int|null, label: string}> $rows Buckets.
	 * @return array{applied: bool, changed: bool}|false
	 */
	private function persist_pair( array $rows, string $policy, bool $only_if_auto ) {
		$method = new \ReflectionMethod( PriceBuckets::class, 'persist_pair_transaction' );
		$method->setAccessible( true );
		/** @var array{applied: bool, changed: bool}|false $result */
		$result = $method->invoke( null, $rows, $policy, $only_if_auto );
		return $result;
	}

	/** @return array<int, string> */
	private function queries_starting_with( PricePairWpdb $db, string $verb ): array {
		return array_values(
			array_filter(
				$db->queries,
				static fn( string $query ): bool => 0 === stripos( ltrim( $query ), $verb )
			)
		);
	}

	/** @return array<int, string> */
	private function first_locked_names( PricePairWpdb $db ): array {
		$names = array();
		foreach ( $db->queries as $query ) {
			if ( false === stripos( $query, 'FOR UPDATE' ) ) {
				continue;
			}
			preg_match( "/option_name = '([^']+)'/", $query, $matches );
			$names[] = (string) ( $matches[1] ?? '' );
			if ( 2 === count( $names ) ) {
				break;
			}
		}
		return $names;
	}
}
