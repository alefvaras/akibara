<?php
/**
 * Guard over the BlueX plugin surface this adapter deliberately overrides.
 *
 * Akibara does not patch `bluex-for-woocommerce`; it wraps it. That wrapping only
 * holds while a handful of vendor strings stay exactly as they are today. If a
 * BlueX update renames any of them the override degrades **silently**: no fatal,
 * no notice, just a checkout where BlueX's own permissive PUDO integration runs
 * again next to ours. This test turns that silent drift into a red build.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use PHPUnit\Framework\TestCase;

final class VendorPudoContractTest extends TestCase {

	/**
	 * BlueX plugin version this override was read against and validated with.
	 *
	 * The local vendor tree must match production before this marker advances.
	 * BlueX 3.3.2 keeps the dequeued handles, Store API namespace, trusted widget
	 * origin and message type. Its confirmed-agency pricing changes preserve the
	 * public get_geolocation/get_pricing methods used by Akibara's provider.
	 * The vendor presentation script remains dequeued on the Blocks checkout.
	 */
	private const REVIEWED_VENDOR_VERSION = '3.3.2';

	/**
	 * Returns the vendor plugin directory, or skips when it is not installed.
	 */
	private function vendor_dir(): string {
		$dir = dirname( __DIR__, 3 ) . '/bluex-for-woocommerce';

		if ( ! is_dir( $dir ) ) {
			self::markTestSkipped(
				'bluex-for-woocommerce is not present in this checkout; the vendor guard needs the real plugin source.'
			);
		}

		return $dir;
	}

	/**
	 * Reads a vendor file, failing loudly when the path itself moved.
	 */
	private function vendor_source( string $relative ): string {
		$path = $this->vendor_dir() . '/' . $relative;

		self::assertFileExists(
			$path,
			"BlueX moved or removed {$relative}. Re-read its PUDO integration before trusting this adapter."
		);

		$source = file_get_contents( $path );
		self::assertIsString( $source );

		return $source;
	}

	/**
	 * @testdox Should still find the exact BlueX asset handles this adapter dequeues.
	 */
	public function test_vendor_block_pudo_asset_handles_are_unchanged(): void {
		$ours   = file_get_contents( dirname( __DIR__, 2 ) . '/src/PudoIntegration.php' );
		$vendor = $this->vendor_source( 'includes/class-wc-correios-blocks-integration.php' );

		self::assertIsString( $ours );

		foreach (
			array(
				'VENDOR_SCRIPT' => 'bluex-blocks-pudo-integration',
				'VENDOR_STYLE'  => 'bluex-blocks-pudo-css',
			) as $constant => $handle
		) {
			self::assertSame(
				1,
				preg_match( "/private const {$constant} = '" . preg_quote( $handle, '/' ) . "';/", $ours ),
				"PudoIntegration::{$constant} must keep naming the handle it dequeues."
			);
			self::assertStringContainsString(
				"'{$handle}'",
				$vendor,
				"BlueX no longer registers '{$handle}'. dequeue_vendor_pudo_assets() is now a no-op and "
					. "BlueX's own block PUDO integration will run alongside this adapter."
			);
		}
	}

	/**
	 * @testdox Should keep replacing BlueX under the identical Store API namespace.
	 */
	public function test_store_api_namespace_still_collides_with_the_vendor(): void {
		$vendor = $this->vendor_source( 'assets/js/frontend/blocks-pudo-integration.js' );

		self::assertSame(
			'bluex_pudo',
			\Akibara\CartCheckoutBlocks\PudoIntegration::WRITE_NAMESPACE,
			'The override only wins by owning the same namespace BlueX writes to.'
		);
		self::assertStringContainsString(
			"namespace: 'bluex_pudo'",
			$vendor,
			'BlueX changed its Store API namespace, so this adapter is no longer replacing anything: '
				. 'its permissive callback would be reachable again under the new name.'
		);
	}

	/**
	 * @testdox Should keep matching the widget origin and postMessage type the adapter trusts.
	 */
	public function test_widget_origin_and_message_type_are_unchanged(): void {
		$vendor = $this->vendor_source( 'includes/class-wc-correios-blocks-integration.php' );
		$ours   = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/pudo-blocks.js' );

		self::assertIsString( $ours );
		self::assertStringContainsString(
			"var WIDGET_ORIGIN = 'https://widget-pudo.blue.cl';",
			$ours,
			'pudo-blocks.js pins the widget origin; it must stay the production one BlueX ships.'
		);
		self::assertStringContainsString(
			"'https://widget-pudo.blue.cl'",
			$vendor,
			'BlueX repointed its production widget. The pinned origin check in pudo-blocks.js would drop '
				. 'every legitimate message and the picker would stop saving points.'
		);
		self::assertStringContainsString(
			"event.data.type !== 'pudo:select'",
			$ours,
			'The adapter must keep filtering on the vendor message type.'
		);
		self::assertStringContainsString(
			'pudo:select',
			$this->vendor_source( 'assets/js/frontend/custom-checkout-map.js' ),
			'BlueX renamed its postMessage type; the adapter would silently ignore every selection.'
		);
	}

	/**
	 * @testdox Should force a re-read of the vendor PUDO code whenever BlueX changes version.
	 */
	public function test_vendor_version_is_the_one_this_override_was_reviewed_against(): void {
		$vendor = $this->vendor_source( 'woocommerce-bluex.php' );

		self::assertSame(
			1,
			preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9a-zA-Z.\-]*)\s*$/m', $vendor, $matches ),
			'Could not read the BlueX plugin version header.'
		);
		self::assertSame(
			self::REVIEWED_VENDOR_VERSION,
			$matches[1],
			sprintf(
				'BlueX moved from %s to %s. Re-read includes/class-wc-correios-blocks-integration.php, '
					. 'assets/js/frontend/blocks-pudo-integration.js (its message handler and what it writes) and '
					. 'assets/js/frontend/pudo-widget-security.js, confirm the dequeue and namespace override '
					. 'still hold, then bump REVIEWED_VENDOR_VERSION.',
				self::REVIEWED_VENDOR_VERSION,
				$matches[1]
			)
		);
	}
}
