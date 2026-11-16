<?php
/** Explicit, previewed rescheduling of pending review invitations.
 *
 * @package Akibara\Rewards
 */
declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Dates;
use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps settings changes separate from existing queue changes. @api 
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress HookNotFound
 * @psalm-suppress InvalidArgument
 * @psalm-suppress RedundantCast
 */
final class InvitationReschedule {
	public const SLUG = 'akb-review-reschedule';
	private const PREVIEW = 'akb_reviews_reschedule_preview';
	private const APPLY = 'akb_reviews_reschedule_apply';
	private const LIMIT = 50;
	private const TRANSIENT = 'akb_review_reschedule_';
	/** @var object|null Scheduler store; injectable for isolated queue tests. */
	private $store;

	/**
	 * Accept a scheduler store for tests; production resolves the official store.
	 * @param mixed $store
	 */
	public function __construct( $store = null ) {
		$this->store = $store;
	}

	/** Register the hidden review detail and explicit POST operations. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 99 );
		add_action( 'admin_post_' . self::PREVIEW, array( $this, 'handle_preview' ) );
		add_action( 'admin_post_' . self::APPLY, array( $this, 'handle_apply' ) );
	}

	/** Register the page; the overview provides its navigation link. */
	public function add_page(): void {
		global $admin_page_hooks;
		// Register under Akibara when active (sidebar flyout stays hidden via CSS) so WP keeps Akibara highlighted.
		$parent = is_array( $admin_page_hooks ) && isset( $admin_page_hooks['akibara-panel'] ) ? 'akibara-panel' : '';
		add_submenu_page( $parent, 'Reprogramar invitaciones', 'Reprogramar invitaciones', 'manage_woocommerce', self::SLUG, array( $this, 'render' ) );
	}

	/** Return the stable admin URL. */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** Store a preview for this administrator without modifying orders or actions. */
	public function handle_preview(): void {
		$this->authorize( self::PREVIEW );
		$offset = isset( $_POST['preview_offset'] ) ? absint( $_POST['preview_offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the operation nonce above.
		$plan = $this->preview( $offset );
		$plan['token'] = wp_generate_uuid4();
		set_transient( self::TRANSIENT . get_current_user_id(), $plan, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Consume exactly the server-side preview that the administrator approved. */
	public function handle_apply(): void {
		$this->authorize( self::APPLY );
		$key   = self::TRANSIENT . get_current_user_id();
		$plan  = get_transient( $key );
		$token = isset( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the operation nonce above.
		if ( ! is_array( $plan ) || '' === $token || ! hash_equals( (string) ( $plan['token'] ?? '' ), $token ) ) {
			wp_die( 'La vista previa venció o ya fue utilizada. Genera otra antes de aplicar.' );
		}
		delete_transient( $key );
		$result = $this->apply( $plan );
		set_transient( $key . '_result', $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Read at most fifty candidates and freeze both the old and proposed dates. */
	public function preview( int $offset = 0 ): array {
		$plan = array( 'rows' => array(), 'skipped' => 0, 'more' => false, 'offset' => max( 0, $offset ), 'policy' => $this->policy(), 'created' => time() );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Invitations::enabled() || ! Options::reviews_engine_on() ) {
			return $plan;
		}
		$ids = $this->scheduler()->query_actions( array( 'hook' => Invitations::HOOK, 'status' => 'pending', 'claimed' => false, 'per_page' => self::LIMIT + 1, 'offset' => $plan['offset'], 'orderby' => 'date', 'order' => 'ASC' ) );
		$plan['more'] = count( $ids ) > self::LIMIT;
		foreach ( array_slice( $ids, 0, self::LIMIT ) as $id ) {
			$row = $this->candidate( (int) $id );
			if ( null === $row ) {
				++$plan['skipped'];
				continue;
			}
			$days = Invitations::delay_days();
			$base = Dates::parse_or_null( $row['scheduled'] );
			if ( null === $base ) {
				++$plan['skipped'];
				continue;
			}
			$row['new_due'] = max( $plan['created'] + 5 * MINUTE_IN_SECONDS, $base->getTimestamp() + $days * DAY_IN_SECONDS );
			if ( $row['new_due'] === $row['old_due'] ) {
				++$plan['skipped'];
				continue;
			}
			$plan['rows'][] = $row;
		}
		return $plan;
	}

	/** Apply frozen dates only after claiming and revalidating the original actions. */
	public function apply( array $plan ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		$result = array( 'changed' => 0, 'skipped' => 0, 'errors' => 0 );
		$rows   = array_slice( (array) ( $plan['rows'] ?? array() ), 0, self::LIMIT );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Invitations::enabled() || ! Options::reviews_engine_on() || ( $plan['policy'] ?? array() ) !== $this->policy() || (int) ( $plan['created'] ?? 0 ) < time() - 10 * MINUTE_IN_SECONDS ) {
			$result['skipped'] = count( $rows );
			return $result;
		}
		if ( ! $rows ) {
			return $result;
		}
		$groups = array();
		foreach ( $rows as $row ) {
			$groups[ (string) ( $row['group'] ?? '' ) ][] = $row;
		}
		if ( count( $groups ) > 1 ) {
			foreach ( $groups as $group_rows ) {
				$partial = $this->apply( array_merge( $plan, array( 'rows' => $group_rows ) ) );
				foreach ( $result as $key => $value ) {
					$result[ $key ] = $value + $partial[ $key ];
				}
			}
			return $result;
		}
		$store = $this->scheduler();
		$claim = null;
		$approved_ids = implode( ',', array_map( 'intval', array_column( $rows, 'action_id' ) ) );
		$claim_order = static function ( string $sql, $claim_id, array $hooks ) use ( $approved_ids ): string {
			unset( $claim_id );
			return array( Invitations::HOOK ) === $hooks ? 'ORDER BY CASE WHEN action_id IN (' . $approved_ids . ') THEN 0 ELSE 1 END, scheduled_date_gmt ASC, action_id ASC' : $sql;
		};
		$old_filters = array();
		if ( method_exists( $store, 'get_claim_filter' ) ) {
			foreach ( array( 'hooks', 'group', 'exclude-groups' ) as $filter ) {
				$old_filters[ $filter ] = $store->get_claim_filter( $filter );
			}
		}
		try {
			add_filter( 'action_scheduler_claim_actions_order_by', $claim_order, 10, 3 );
			$claim = $store->stake_claim( count( $rows ), new \DateTime( '@' . max( array_column( $rows, 'old_due' ) ) ), array( Invitations::HOOK ), array_key_first( $groups ) );
			$claimed = array_map( 'intval', $claim->get_actions() );
			foreach ( $rows as $row ) {
				$outcome = $this->apply_claimed_row( $store, $row, $claimed, (int) $claim->get_id() );
				++$result[ $outcome ];
			}
		} catch ( \Throwable $error ) {
			++$result['errors'];
		} finally {
			remove_filter( 'action_scheduler_claim_actions_order_by', $claim_order, 10 );
			if ( null !== $claim ) {
				$store->release_claim( $claim );
			}
			if ( method_exists( $store, 'set_claim_filter' ) ) {
				foreach ( $old_filters as $filter => $value ) {
					$store->set_claim_filter( $filter, $value );
				}
			}
		}
		return $result;
	}

	/**
	 * Reprograma una acción individual reclamada en la cola.
	 *
	 * @param object               $store    Almacén de Action Scheduler.
	 * @param array<string, mixed> $row      Fila aprobada del plan.
	 * @param array<int, int>      $claimed  IDs reclamados.
	 * @param int                  $claim_id ID del claim activo.
	 * @return string 'changed'|'skipped'|'errors'
	 */
	private function apply_claimed_row( $store, array $row, array $claimed, int $claim_id ): string {
		$id = (int) $row['action_id'];
		if ( ! in_array( $id, $claimed, true ) || (int) $row['new_due'] <= time() + 30 ) {
			return 'skipped';
		}
		$current  = $this->candidate( $id, $claim_id );
		$expected = $row;
		unset( $expected['new_due'] );
		if ( $current !== $expected ) {
			return 'skipped';
		}
		$new_id = 0;
		try {
			$action = $store->fetch_action( $id );
			$new_id = (int) as_schedule_single_action( (int) $row['new_due'], Invitations::HOOK, array( (int) $row['order_id'] ), $action->get_group(), false, $action->get_priority() );
			if ( $new_id <= 0 ) {
				return 'errors';
			}
			$store->cancel_action( $id );
			if ( 'canceled' !== $store->get_status( $id ) ) {
				throw new \RuntimeException( 'Original action was not canceled.' );
			}
		} catch ( \Throwable $error ) {
			unset( $error );
			if ( $new_id <= 0 || 'canceled' !== $store->get_status( $id ) ) {
				if ( $new_id > 0 ) {
					$store->cancel_action( $new_id );
				}
				return 'errors';
			}
		}
		$order = wc_get_order( (int) $row['order_id'] );
		if ( $order instanceof \WC_Order ) {
			$order->update_meta_data( Invitations::META_DUE, (int) $row['new_due'] );
			$order->save_meta_data();
		}
		return 'changed';
	}

	/** Render preview controls and exact dates, without performing queue mutations. */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$key = self::TRANSIENT . get_current_user_id();
		$plan = get_transient( $key );
		$result = get_transient( $key . '_result' );
		echo '<div class="wrap akb-rewards-wrap"><div class="akb-rewards-panel">';
		echo '<div class="akb-panel-top-bar"><a href="' . esc_url( admin_url( 'admin.php?page=akb-rewards-settings' ) ) . '" class="akb-panel-back-btn">← Volver a Puntos y Reseñas</a></div>';
		echo '<header class="akb-rewards-head"><div class="akb-rewards-brand"><span class="akb-rewards-gem" aria-hidden="true">&#9670;</span> <h1>Reprogramar Invitaciones</h1> <span class="akb-rewards-ver">Cola</span></div></header>';
		echo '<div class="akb-rewards-body">';
		echo '<div class="akb-rewards-card"><div class="akb-rewards-card__header"><h2 class="akb-rewards-card__title">Gestión de Tiempos de Envío</h2></div><div style="padding:20px 24px;">';
		echo '<p style="margin:0 0 10px; font-size:14px; color:#555;">Aplica los plazos actuales desde la fecha original de despacho o retiro guardada al agendar. Se revisan hasta 50 acciones por tanda. Las enviadas, en ejecución, con fallos de envío o sin un pedido elegible se conservan.</p><p style="margin:0 0 16px; font-size:13px; color:#666;">Si la nueva fecha ya pasó, la vista previa propone cinco minutos desde ahora. Aplicar no envía correos directamente: cambia la fecha para que la cola los procese. Los cambios de ajustes por sí solos no reprograman.</p>';
		if ( is_array( $result ) ) {
			delete_transient( $key . '_result' );
			echo '<div class="notice notice-info inline" style="margin-bottom:16px;"><p>Reprogramadas: ' . esc_html( (string) $result['changed'] ) . '. Omitidas por cambios o vencimiento: ' . esc_html( (string) $result['skipped'] ) . '. Errores: ' . esc_html( (string) $result['errors'] ) . '.</p></div>';
		}
		if ( ! Invitations::enabled() || ! Options::reviews_engine_on() ) {
			echo '<p style="color:#d63638; font-weight:700;">Activa el motor y las invitaciones antes de preparar una reprogramación.</p></div></div></div></div></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::PREVIEW );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::PREVIEW ) . '"><button class="button button-primary">Preparar vista previa</button></form>';
		echo '</div></div>';
		if ( is_array( $plan ) ) {
			echo '<div class="akb-rewards-card"><div class="akb-rewards-card__header"><h2 class="akb-rewards-card__title">Vista previa: ' . esc_html( (string) count( $plan['rows'] ) ) . ' invitaciones</h2></div><div style="padding:20px 24px;">';
			echo '<p style="margin:0 0 16px; color:#555;">Sin cambios o no elegibles en esta tanda: ' . esc_html( (string) $plan['skipped'] ) . '. Fechas en la zona horaria de la tienda. La vista previa vence en diez minutos.</p>';
			if ( $plan['more'] ) {
				echo '<p>Hay más acciones pendientes. Puedes revisar otra tanda sin aplicar ésta.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::PREVIEW );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::PREVIEW ) . '"><input type="hidden" name="preview_offset" value="' . esc_attr( (string) ( $plan['offset'] + self::LIMIT ) ) . '"><button class="button button-secondary">Ver siguientes 50 pendientes</button></form><br>';
			}
			if ( $plan['rows'] ) {
				echo '<div style="overflow:auto; border:2px solid var(--akb-admin-border); border-radius:6px; box-shadow:2px 2px 0 var(--akb-admin-border); margin-bottom:16px;"><table class="widefat striped" style="border:0; margin:0;"><thead><tr><th scope="col" style="background:#FAFAFB; font-weight:800;">Pedido</th><th scope="col" style="background:#FAFAFB; font-weight:800;">Fecha actual</th><th scope="col" style="background:#FAFAFB; font-weight:800;">Nueva fecha</th></tr></thead><tbody>';
				foreach ( $plan['rows'] as $row ) {
					echo '<tr><td>#' . esc_html( (string) $row['order_id'] ) . '</td><td>' . esc_html( Dates::admin_datetime( (int) $row['old_due'] ) ) . '</td><td>' . esc_html( Dates::admin_datetime( (int) $row['new_due'] ) ) . '</td></tr>';
				}
				echo '</tbody></table></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::APPLY );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::APPLY ) . '"><input type="hidden" name="preview_token" value="' . esc_attr( $plan['token'] ) . '"><p><button class="button button-primary">Aplicar estas fechas a ' . esc_html( (string) count( $plan['rows'] ) ) . ' invitaciones</button></p></form>';
			}
			echo '</div></div>';
		}
		echo '</div></div></div>';
	}

	/** Validate a specific original action against its live order and scheduling evidence. */
	private function candidate( int $id, int $claim_id = 0 ): ?array {
		$store = $this->scheduler();
		if ( 'pending' !== $store->get_status( $id ) || (int) $store->get_claim_id( $id ) !== $claim_id ) {
			return null;
		}
		$action = $store->fetch_action( $id );
		$args   = $action->get_args();
		if ( Invitations::HOOK !== $action->get_hook() || count( $args ) !== 1 || ! isset( $args[0] ) || $action->get_schedule()->is_recurring() ) {
			return null;
		}
		$order = wc_get_order( (int) $args[0] );
		if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( Invitations::META_SENT ) || (int) $order->get_meta( Invitations::META_ATTEMPTS ) > 0 || '' !== Invitations::waiting_reason( $order ) ) {
			return null;
		}
		$trigger = (string) $order->get_meta( Invitations::META_TRIGGER );
		$status  = (string) $order->get_status();
		if ( ! Invitations::is_trigger( $trigger ) || ! OrderStates::is_at_or_after( $status, $trigger ) ) {
			return null;
		}
		$jobs = $store->query_actions( array( 'hook' => Invitations::HOOK, 'args' => array( (int) $order->get_id() ), 'status' => array( 'pending', 'in-progress' ), 'per_page' => 2 ) );
		if ( count( $jobs ) !== 1 || (int) $jobs[0] !== $id ) {
			return null;
		}
		return array( 'action_id' => $id, 'order_id' => (int) $order->get_id(), 'old_due' => $action->get_schedule()->get_date()->getTimestamp(), 'status' => $status, 'trigger' => $trigger, 'scheduled' => (string) $order->get_meta( Invitations::META_SCHEDULED ), 'group' => $action->get_group() );
	}

	/**
	 * Resolve the official scheduler storage.
	 * @return mixed
	 */
	private function scheduler() {
		return $this->store ?? \ActionScheduler::store();
	}

	/** Reject previews made against a different set of route settings. */
	private function policy(): array {
		return array( Invitations::trigger_status(), Invitations::delay_days() );
	}

	/** Require both administrator capability and the operation's nonce. */
	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( $action );
	}
}
