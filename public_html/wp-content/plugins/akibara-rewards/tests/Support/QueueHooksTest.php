<?php
/**
 * La lista de hooks de la cola que este plugin entrega a la tarjeta de salud.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Support\QueueHooks;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey;

final class QueueHooksTest extends RewardsTestCase {

	public function test_the_list_matches_the_uninstaller_one_to_one(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		preg_match_all( "/as_unschedule_all_actions\(\s*'([a-z0-9_]+)'/", $uninstall, $m );

		$this->assertSame( array(), array_diff( QueueHooks::ALL, $m[1] ), 'Todo hook vigilado se retira al desinstalar.' );
		$this->assertSame( array(), array_diff( $m[1], QueueHooks::ALL ), 'Todo hook que se retira está vigilado.' );
	}

	public function test_it_adds_its_hooks_to_the_health_card_by_filter(): void {
		$hooks = new QueueHooks();
		Monkey\Filters\expectAdded( 'akibara_queue_health_hooks' )->once()->with( array( $hooks, 'add_hooks' ) );
		$hooks->register();
		$this->addToAssertionCount( 1 );

		$this->assertSame(
			array( 'akibara_catalog_order_daily', ...QueueHooks::ALL ),
			$hooks->add_hooks( array( 'akibara_catalog_order_daily', 'akb_rewards_expiry_daily' ) ),
			'Sin repetidos y sin perder los de business.'
		);
		$this->assertSame( QueueHooks::ALL, $hooks->add_hooks( 'no es una lista' ) );
	}

	public function test_the_plugin_registers_it(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );
		$this->assertStringContainsString( 'QueueHooks() )->register()', $plugin );
	}
}
