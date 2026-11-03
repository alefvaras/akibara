<?php
/** WooCommerce Customers report integration, using its public extension hooks.
 *
 * @package Akibara\Rewards
 */
declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Assets;

defined( 'ABSPATH' ) || exit;

/** Admin-only balances and server-side report filtering. */
final class CustomersAdmin {

	/** @var array<string,mixed> Current REST request filters. */
	private array $filters = array();
	/** @var array<int,array<string,mixed>> Nested REST dispatch contexts. */
	private array $contexts = array();
	/** @var array<int,bool> Existing WordPress accounts on the current page. */
	private array $accounts = array();

	/** Register official report, REST and script hooks. */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'rest_endpoints', array( $this, 'register_parameters' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'begin_request' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'end_request' ), 10, 3 );
		add_filter( 'woocommerce_analytics_customers_query_args', array( $this, 'query_args' ) );
		add_filter( 'woocommerce_analytics_customers_stats_query_args', array( $this, 'query_args' ) );
		add_filter( 'woocommerce_analytics_clauses_where_customers_subquery', array( $this, 'where' ) );
		add_filter( 'woocommerce_analytics_clauses_where_customers_stats_subquery', array( $this, 'where' ) );
		add_filter( 'woocommerce_analytics_report_should_use_cache', array( $this, 'use_cache' ), 10, 2 );
		add_filter( 'woocommerce_analytics_customers_select_query', array( $this, 'prime_accounts' ) );
		add_filter( 'woocommerce_rest_prepare_report_customers', array( $this, 'customer_response' ), 10, 3 );
	}

	/** Load before the wc-admin application so lazy report modules see the filters. */
	public function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selection.
		if ( ! current_user_can( 'manage_woocommerce' ) || 'wc-admin' !== $page ) {
			return;
		}
		$file = 'assets/js/admin-customers.js';
		wp_enqueue_script( 'akb-rewards-customers', Assets::url( $file ), array( 'wp-hooks', 'wp-element' ), Assets::version( $file ), false );
	}

	/** Declare validated custom REST parameters on Customers and its statistics. */
	public function register_parameters( array $endpoints ): array {
		foreach ( array( '/wc-analytics/customers', '/wc-analytics/reports/customers', '/wc-analytics/reports/customers/stats' ) as $route ) {
			if ( ! isset( $endpoints[ $route ] ) ) {
				continue;
			}
			foreach ( $endpoints[ $route ] as &$handler ) {
				if ( ! is_array( $handler ) || ! isset( $handler['callback'] ) ) {
					continue;
				}
				$handler['args']['akb_points'] = array( 'type' => 'string', 'enum' => array( 'all', 'positive', 'zero', 'guest' ), 'default' => 'all' );
				foreach ( array( 'min', 'max' ) as $bound ) {
					$handler['args'][ 'akb_balance_' . $bound ] = array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647 );
				}
				$handler['args']['akb_balance_between'] = array( 'type' => 'array', 'minItems' => 2, 'maxItems' => 2, 'items' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647 ) );
			}
			unset( $handler );
		}
		return $endpoints;
	}

	/** Scope SQL changes to an authenticated Customers request, including nested dispatch. */
	public function begin_request( $response, array $handler, \WP_REST_Request $request ) {
		unset( $handler );
		$this->contexts[] = $this->filters;
		$this->filters    = array();
		if ( current_user_can( 'manage_woocommerce' ) && in_array( $request->get_route(), array( '/wc-analytics/customers', '/wc-analytics/reports/customers', '/wc-analytics/reports/customers/stats' ), true ) ) {
			$this->filters = self::normalize_filters( $request->get_params() );
		}
		return $response;
	}

	/** Restore the previous dispatch context. */
	public function end_request( $response, array $handler, \WP_REST_Request $request ) {
		unset( $handler, $request );
		$this->filters = array_pop( $this->contexts ) ?? array();
		return $response;
	}

	/** Keep custom selectors in the report query and cache identity. */
	public function query_args( array $args ): array {
		return array_merge( $args, $this->filters );
	}

	/** Normalize Woo's numeric filters; between is inclusive, min/max are strict. */
	public static function normalize_filters( array $params ): array {
		$out = array();
		if ( in_array( $params['akb_points'] ?? '', array( 'positive', 'zero', 'guest' ), true ) ) {
			$out['akb_points'] = $params['akb_points'];
		}
		foreach ( array( 'min', 'max' ) as $bound ) {
			$key = 'akb_balance_' . $bound;
			if ( isset( $params[ $key ] ) && is_numeric( $params[ $key ] ) ) {
				$out[ $key ] = max( 0, min( 2147483647, (int) $params[ $key ] ) );
			}
		}
		$between = $params['akb_balance_between'] ?? null;
		if ( is_string( $between ) ) {
			$between = explode( ',', $between );
		}
		if ( is_array( $between ) && 2 === count( $between ) && is_numeric( $between[0] ) && is_numeric( $between[1] ) ) {
			$between = array_map( static fn( $value ): int => max( 0, min( 2147483647, (int) $value ) ), array_values( $between ) );
			sort( $between, SORT_NUMERIC );
			$out['akb_balance_between'] = $between;
		}
		return $out;
	}

	/** Filter the inner query shared by row retrieval, pagination counts and statistics. */
	public function where( array $clauses ): array {
		if ( array() === $this->filters ) {
			return $clauses;
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'wc_customer_lookup';
		$account = "EXISTS (SELECT 1 FROM {$wpdb->users} akb_account WHERE akb_account.ID = {$table}.user_id)";
		$mode    = $this->filters['akb_points'] ?? 'all';
		if ( 'guest' === $mode ) {
			$clauses[] = 'AND NOT ' . $account;
			if ( count( $this->filters ) > 1 ) {
				$clauses[] = 'AND 1 = 0'; // A guest cannot also satisfy a numeric account-balance filter.
			}
			return $clauses;
		}
		// A scalar indexed lookup avoids duplicating customer/order rows. Selecting
		// the first meta row mirrors get_user_meta when legacy duplicates exist.
		$balance = $wpdb->prepare(
			"GREATEST(0, COALESCE((SELECT CAST(akb_points.meta_value AS SIGNED) FROM {$wpdb->usermeta} akb_points WHERE akb_points.user_id = {$table}.user_id AND akb_points.meta_key = %s ORDER BY akb_points.umeta_id ASC LIMIT 1), 0))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress table names only.
			Ledger::META_BALANCE
		);
		$conditions = array( $account );
		if ( 'positive' === $mode ) {
			$conditions[] = $balance . ' > 0';
		} elseif ( 'zero' === $mode ) {
			$conditions[] = $balance . ' = 0';
		}
		foreach ( array( 'min' => '>', 'max' => '<' ) as $bound => $operator ) {
			if ( isset( $this->filters[ 'akb_balance_' . $bound ] ) ) {
				$conditions[] = $balance . ' ' . $operator . ' ' . (int) $this->filters[ 'akb_balance_' . $bound ];
			}
		}
		if ( isset( $this->filters['akb_balance_between'] ) ) {
			$conditions[] = $balance . ' BETWEEN ' . (int) $this->filters['akb_balance_between'][0] . ' AND ' . (int) $this->filters['akb_balance_between'][1];
		}
		$clauses[] = 'AND (' . implode( ' AND ', $conditions ) . ')';
		return $clauses;
	}

	/** Filtered balances must reflect a just-saved adjustment, not Analytics' hourly cache. */
	public function use_cache( bool $use, string $key ): bool {
		return array() !== $this->filters && in_array( $key, array( 'customers', 'customers_stats' ), true ) ? false : $use;
	}

	/** Batch load only the WordPress accounts on the visible page. */
	public function prime_accounts( $results ) {
		$this->accounts = array();
		if ( ! current_user_can( 'manage_woocommerce' ) || ! is_object( $results ) || ! isset( $results->data ) ) {
			return $results;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', array_column( $results->data, 'user_id' ) ) ) ) );
		if ( $ids ) {
			$existing       = get_users( array( 'include' => $ids, 'fields' => 'ID', 'number' => count( $ids ), 'count_total' => false ) );
			$this->accounts = array_fill_keys( array_map( 'intval', $existing ), true );
			update_meta_cache( 'user', $existing );
		}
		return $results;
	}

	/** Add live balance and account-specific management URL, never using Analytics customer_id. */
	public function customer_response( $response, $report, $request ) {
		unset( $request );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $response;
		}
		$user_id = (int) ( $report['user_id'] ?? 0 );
		$exists  = isset( $this->accounts[ $user_id ] );
		$data    = $response->get_data();
		$data['akb_rewards'] = array(
			'has_account' => $exists,
			'balance'     => $exists ? Ledger::balance( $user_id ) : null,
			'manage_url'  => $exists ? CustomerPointsPage::url( $user_id ) : '',
		);
		$response->set_data( $data );
		return $response;
	}
}
