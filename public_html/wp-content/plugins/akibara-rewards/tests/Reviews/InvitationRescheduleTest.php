<?php
/** Preview/apply queue safety tests.
 *
 * @package Akibara\Rewards\Tests
 */
declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Invitations;
use Akibara\Rewards\Reviews\InvitationReschedule;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/** Reprogramming does not resend or race a claimed invitation. */
final class InvitationRescheduleTest extends RewardsTestCase {
	/** The System Under Test. */
	private InvitationReschedule $sut;
	private RescheduleQueue $queue;
	private \WC_Order $order;
	private array $options = array();

	/** One paid dispatched order with an original, unattempted action. */
	protected function setUp(): void {
		parent::setUp();
		$this->queue = new RescheduleQueue();
		$this->sut = new InvitationReschedule( $this->queue );
		$this->order = new \WC_Order();
		$this->order->id = 901;
		$this->order->status = 'shipping-progress';
		$this->order->date_paid = new \WC_DateTime( 'now' );
		$this->order->meta = array( Invitations::META_TRIGGER => 'shipping-progress', Invitations::META_SCHEDULED => ( new \DateTimeImmutable( '-1 day', new \DateTimeZone( 'America/Santiago' ) ) )->format( 'Y-m-d H:i:s' ) );
		$this->queue->add( 1, 901, time() + 8 * DAY_IN_SECONDS );
		$this->options = array( 'akb_rewards_invite_status' => 'shipping-progress', 'akb_rewards_invite_delay_days' => 6, 'akb_rewards_invite_pickup_status' => 'completed', 'akb_rewards_invite_pickup_delay_days' => 2 );
		Functions\when( 'get_option' )->alias( fn( string $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_order' )->alias( fn( int $id ) => 901 === $id ? $this->order : false );
		Functions\when( 'as_schedule_single_action' )->alias( function ( int $due, string $hook, array $args, string $group, bool $unique, int $priority ): int {
			$this->assertSame( Invitations::HOOK, $hook );
			$this->assertSame( 'pending', $this->queue->status[1], 'Replacement is written before original cancellation.' );
			$this->assertSame( 9, $this->queue->claims[1], 'The original must be claimed before creating its replacement.' );
			$this->assertFalse( $unique, 'The old action still exists while the replacement is saved.' );
			$this->queue->add( 2, $args[0], $due );
			return 2;
		} );
	}

	/** @testdox The hidden preview page uses a hook that WordPress can authorize. */
	public function test_hidden_page_uses_a_resolvable_admin_hook(): void {
		Functions\expect( 'add_submenu_page' )->once()->with( '', 'Reprogramar invitaciones', 'Reprogramar invitaciones', 'manage_woocommerce', InvitationReschedule::SLUG, array( $this->sut, 'render' ) );
		Functions\expect( 'remove_submenu_page' )->never();
		$this->sut->add_page();
		$this->addToAssertionCount( 1 );
	}

	/** @testdox Preview is read-only and applying keeps the exact proposed date and original dispatch evidence. */
	public function test_preview_and_apply_preserve_frozen_dates(): void {
		$plan = $this->sut->preview();
		$this->assertCount( 1, $plan['rows'] );
		$this->assertSame( 0, $this->queue->claims[1] );
		$this->assertSame( 'pending', $this->queue->status[1] );
		$this->assertSame( array(), $this->order->saved_meta );
		$base = new \DateTimeImmutable( $this->order->meta[ Invitations::META_SCHEDULED ], new \DateTimeZone( 'America/Santiago' ) );
		$this->assertSame( $base->getTimestamp() + 6 * DAY_IN_SECONDS, $plan['rows'][0]['new_due'] );
		$result = $this->sut->apply( $plan );
		$this->assertSame( array( 'changed' => 1, 'skipped' => 0, 'errors' => 0 ), $result );
		$this->assertSame( 'canceled', $this->queue->status[1] );
		$this->assertSame( 'pending', $this->queue->status[2] );
		$this->assertSame( $plan['rows'][0]['new_due'], $this->queue->actions[2]->get_schedule()->get_date()->getTimestamp() );
		$this->assertSame( $plan['rows'][0]['new_due'], $this->order->meta[ Invitations::META_DUE ] );
		$this->assertSame( $plan['rows'][0]['scheduled'], $this->order->meta[ Invitations::META_SCHEDULED ] );
		$this->assertTrue( $this->queue->released );
		$this->assertSame( '', (string) $this->order->get_meta( Invitations::META_SENT ) );
	}

	/** @testdox Sent, failed attempts, processing, unpaid and duplicate actions never enter a preview. */
	public function test_unsafe_candidates_are_excluded(): void {
		$this->order->meta[ Invitations::META_SENT ] = '2026-10-01 10:00:00';
		$this->assertSame( array(), $this->sut->preview()['rows'] );
		unset( $this->order->meta[ Invitations::META_SENT ] );
		$this->order->meta[ Invitations::META_ATTEMPTS ] = 1;
		$this->assertSame( array(), $this->sut->preview()['rows'] );
		unset( $this->order->meta[ Invitations::META_ATTEMPTS ] );
		$this->queue->status[1] = 'in-progress';
		$this->assertSame( array(), $this->sut->preview()['rows'] );
		$this->queue->status[1] = 'pending';
		$this->order->date_paid = null;
		$this->assertSame( array(), $this->sut->preview()['rows'] );
		$this->order->date_paid = new \WC_DateTime( 'now' );
		$this->queue->add( 2, 901, time() + 10 * DAY_IN_SECONDS );
		$this->assertSame( array(), $this->sut->preview()['rows'] );
	}

	/** @testdox A changed order, schedule or policy invalidates the preview without touching the original. */
	public function test_apply_revalidates_the_preview(): void {
		$plan = $this->sut->preview();
		$this->order->status = 'completed';
		$this->assertSame( 1, $this->sut->apply( $plan )['skipped'] );
		$this->assertSame( 'pending', $this->queue->status[1] );
		$this->order->status = 'shipping-progress';
		$this->queue->add( 1, 901, time() + 9 * DAY_IN_SECONDS );
		$this->assertSame( 1, $this->sut->apply( $plan )['skipped'] );
		$this->options['akb_rewards_invite_delay_days'] = 7;
		$this->assertSame( 1, $this->sut->apply( $plan )['skipped'] );
		$this->assertSame( array(), $this->order->saved_meta );
	}

	/** @testdox A failed scheduler write keeps the original pending invitation. */
	public function test_creation_failure_keeps_old_action(): void {
		Functions\when( 'as_schedule_single_action' )->justReturn( 0 );
		$result = $this->sut->apply( $this->sut->preview() );
		$this->assertSame( 1, $result['errors'] );
		$this->assertSame( 'pending', $this->queue->status[1] );
		$this->assertSame( 0, $this->queue->claims[1] );
		$this->assertSame( array(), $this->order->saved_meta );
	}

	/** @testdox If original cancellation fails, the replacement is canceled and the original survives. */
	public function test_cancellation_failure_rolls_back_replacement(): void {
		$this->queue->fail_cancel = true;
		$result = $this->sut->apply( $this->sut->preview() );
		$this->assertSame( 1, $result['errors'] );
		$this->assertSame( 'pending', $this->queue->status[1] );
		$this->assertSame( 'canceled', $this->queue->status[2] );
		$this->assertTrue( $this->queue->released );
	}

	/** @testdox Pickup uses its own delay and elapsed dates become an explicit future preview time. */
	public function test_pickup_and_past_due_dates(): void {
		$this->order->status = 'completed';
		$this->order->meta[ Invitations::META_TRIGGER ] = 'completed';
		$base = new \DateTimeImmutable( $this->order->meta[ Invitations::META_SCHEDULED ], new \DateTimeZone( 'America/Santiago' ) );
		$this->assertSame( $base->getTimestamp() + 2 * DAY_IN_SECONDS, $this->sut->preview()['rows'][0]['new_due'] );
		$this->options['akb_rewards_invite_pickup_delay_days'] = 0;
		$plan = $this->sut->preview();
		$this->assertSame( $plan['created'] + 5 * MINUTE_IN_SECONDS, $plan['rows'][0]['new_due'] );
		$plan['created'] -= 11 * MINUTE_IN_SECONDS;
		$this->assertSame( 1, $this->sut->apply( $plan )['skipped'] );
	}
}

/** Minimal official-store contract used to simulate claim races and failure. */
final class RescheduleQueue {
	public array $actions = array();
	public array $status = array();
	public array $claims = array();
	public bool $released = false;
	public bool $fail_cancel = false;
	public function add( int $id, int $order, int $due ): void {
		$this->actions[ $id ] = new class( $order, $due ) {
			private int $order;
			private int $due;
			public function __construct( int $order, int $due ) { $this->order = $order; $this->due = $due; }
			public function get_args(): array { return array( $this->order ); }
			public function get_hook(): string { return Invitations::HOOK; }
			public function get_group(): string { return ''; }
			public function get_priority(): int { return 10; }
			public function get_schedule(): self { return $this; }
			public function is_recurring(): bool { return false; }
			public function get_date(): \DateTime { return new \DateTime( '@' . $this->due ); }
		};
		$this->status[ $id ] = 'pending';
		$this->claims[ $id ] = 0;
	}
	public function query_actions( array $query ): array {
		$ids = array();
		foreach ( $this->actions as $id => $action ) {
			if ( ! in_array( $this->status[ $id ], (array) $query['status'], true ) || ( isset( $query['args'] ) && $query['args'] !== $action->get_args() ) || ( isset( $query['claimed'] ) && false === $query['claimed'] && $this->claims[ $id ] > 0 ) ) { continue; }
			$ids[] = $id;
		}
		return array_slice( $ids, $query['offset'] ?? 0, $query['per_page'] );
	}
	public function get_status( int $id ): string { return $this->status[ $id ]; }
	public function get_claim_id( int $id ): int { return $this->claims[ $id ]; }
	public function fetch_action( int $id ) { return $this->actions[ $id ]; }
	public function stake_claim( int $count, \DateTime $date, array $hooks ) {
		$ids = array();
		foreach ( $this->actions as $id => $action ) {
			if ( 'pending' === $this->status[ $id ] && 0 === $this->claims[ $id ] && $action->get_schedule()->get_date() <= $date ) { $ids[] = $id; $this->claims[ $id ] = 9; }
		}
		return new class( $ids ) {
			private array $ids;
			public function __construct( array $ids ) { $this->ids = $ids; }
			public function get_actions(): array { return $this->ids; }
			public function get_id(): int { return 9; }
		};
	}
	public function cancel_action( int $id ): void {
		if ( 1 === $id && $this->fail_cancel ) { throw new \RuntimeException( 'Cancel failed.' ); }
		$this->status[ $id ] = 'canceled';
	}
	public function release_claim( $claim ): void {
		foreach ( $claim->get_actions() as $id ) { $this->claims[ $id ] = 0; }
		$this->released = true;
	}
}
