<?php
/**
 * Garantiza la evolución de índices y versión de esquema en BackInStock\Install.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BackInStock;

use Akibara\Business\Modules\BackInStock\Install;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class IndexSchemaTest extends TestCase {

	/** @var array<int, string> */
	private array $ddl = array();

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->ddl     = array();
		$this->options = array( Install::OPTION_KEY => '2.0.0' );

		$GLOBALS['wpdb'] = new class {
			public string $prefix     = 'wp_';
			public string $last_error = '';

			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $sql, ...$args ): string {
				unset( $args );
				return $sql;
			}

			public function get_var( string $sql ): string {
				unset( $sql );
				return 'wp_akb_bis_subscribers';
			}
		};

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

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox El upgrade a DB_VERSION 2.1.0 ejecuta dbDelta con los 3 índices compuestos nuevos y sella la versión. */
	public function test_upgrade_adds_composite_indexes_and_seals_version(): void {
		self::assertSame( '2.1.0', Install::DB_VERSION );

		Install::maybe_install();

		self::assertCount( 1, $this->ddl );
		$sql = $this->ddl[0];
		self::assertStringContainsString( 'KEY idx_user_status_product (user_id, status, product_id)', $sql );
		self::assertStringContainsString( 'KEY idx_email_status (email, status)', $sql );
		self::assertStringContainsString( 'KEY idx_status_created (status, created_at)', $sql );
		self::assertSame( '2.1.0', $this->options[ Install::OPTION_KEY ] );
	}
}
