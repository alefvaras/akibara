<?php
/**
 * Guardrail de tiempo y zonas horarias para Akibara Rewards.
 *
 * Previene regresiones donde otro módulo invoca directamente `wp_timezone(`,
 * `date_i18n(`, o `DateTimeZone('America/Santiago')` eludiendo `Dates`.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use PHPUnit\Framework\TestCase;

final class DatesContractTest extends TestCase {

	public function test_source_delegates_timezone_and_human_formatting_to_dates_class(): void {
		$src_dir   = dirname( __DIR__, 2 ) . '/src';
		$offenders = array();
		$iterator  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src_dir ) );

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			$rel = ltrim( str_replace( $src_dir, '', (string) $file->getPathname() ), '/' );
			if ( 'Support/Dates.php' === $rel ) {
				continue;
			}

			$lines = preg_split( '/\R/', (string) file_get_contents( $file->getPathname() ) ) ?: array();
			foreach ( $lines as $num => $line ) {
				if ( 1 === preg_match( '#^\s*(\*|//)#', $line ) ) {
					continue;
				}
				if (
					false !== strpos( $line, 'wp_timezone(' )
					|| false !== strpos( $line, 'date_i18n(' )
					|| false !== strpos( $line, "DateTimeZone( 'America/Santiago' )" )
				) {
					$offenders[] = sprintf( '%s:%d', $rel, $num + 1 );
				}
			}
		}

		self::assertSame(
			array(),
			$offenders,
			'La resolución de zona horaria y formato de fecha debe centralizarse en Akibara\Rewards\Support\Dates.'
		);
	}
}
