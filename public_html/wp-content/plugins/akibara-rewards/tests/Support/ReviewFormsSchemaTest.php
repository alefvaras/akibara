<?php
/**
 * Garantiza los índices compuestos de akb_rewards_review_forms y el upgrade a 1.5.0.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Support\Schema;
use Brain\Monkey\Functions;

final class ReviewFormsSchemaTest extends RewardsTestCase {

	/** @var array<int, string> */
	private array $ddl = array();

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->ddl     = array();
		$this->options = array( Schema::OPTION_KEY => '1.4.0' );
		$forms_table   = Schema::review_forms_table();
		$ledger_table  = Schema::ledger_table();

		$this->wpdb = new class( $ledger_table, $forms_table ) extends \FakeWpdb {
			private string $ledger_table;
			private string $forms_table;

			public function __construct( string $ledger_table, string $forms_table ) {
				$this->ledger_table = $ledger_table;
				$this->forms_table  = $forms_table;
			}

			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			}

			public function prepare( string $query, ...$args ): string {
				if ( false !== strpos( $query, 'SHOW TABLES LIKE' ) && isset( $args[0] ) ) {
					return 'SHOW_TABLES:' . (string) $args[0];
				}
				return $query;
			}

			public function get_var( $query = null, $x = 0, $y = 0 ) {
				unset( $x, $y );
				if ( is_string( $query ) && 0 === strpos( $query, 'SHOW_TABLES:' ) ) {
					$wanted = substr( $query, strlen( 'SHOW_TABLES:' ) );
					if ( $wanted === $this->ledger_table || $wanted === $this->forms_table ) {
						return $wanted;
					}
				}
				return null;
			}
		};
		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'dbDelta' )->alias(
			function ( string $sql ): array {
				$this->ddl[] = $sql;
				return array();
			}
		);
	}

	/** @testdox Schema 1.5.0 incluye los índices order_used_expires y used_expires en akb_rewards_review_forms y sella versión. */
	public function test_review_forms_indexes_created_on_upgrade(): void {
		self::assertSame( '1.5.0', Schema::DB_VERSION );

		Schema::maybe_install();

		self::assertCount( 2, $this->ddl );
		$forms_sql = $this->ddl[1];
		self::assertStringContainsString( 'KEY order_id (order_id)', $forms_sql );
		self::assertStringContainsString( 'KEY order_used_expires (order_id, used_at, expires_at)', $forms_sql );
		self::assertStringContainsString( 'KEY used_expires (used_at, expires_at)', $forms_sql );
		self::assertSame( '1.5.0', $this->options[ Schema::OPTION_KEY ] );
	}
}
