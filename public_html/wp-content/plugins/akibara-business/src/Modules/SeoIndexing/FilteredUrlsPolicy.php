<?php
/**
 * Indexing policy for filtered catalog and query-driven utility URLs.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoIndexing;

use Akibara\Business\Utilities\CatalogQueryGrammar;

defined( 'ABSPATH' ) || exit;

/**
 * Marks catalog variants and query-driven utility URLs as `noindex,follow`
 * through WordPress and Rank Math.
 * Empty filtered collections additionally return a real 404 while preserving
 * the catalog template and its useful empty state.
 *
 * Rank Math's UI can configure archive defaults, but it cannot express a
 * policy based on arbitrary query-parameter grammar. This module is therefore
 * the narrow code-only exception: it owns the public grammar used by the
 * Akibara search plugin and WooCommerce filters without creating redirects or
 * writing Rank Math options.
 */
final class FilteredUrlsPolicy {

	/**
	 * Akibara Search's reserved text-query parameter.
	 *
	 * Text searches remain `noindex,follow`, but an empty text result is a
	 * useful search response rather than a missing faceted collection.
	 */
	private const TEXT_SEARCH_PARAMETER = CatalogQueryGrammar::TEXT_SEARCH_PARAMETER;

	/**
	 * Registers both robots representations and the filtered-empty status.
	 *
	 * Rank Math renders its own robots meta while WordPress remains the
	 * fallback when Rank Math is unavailable.
	 */
	public function register(): void {
		add_filter( 'wp_robots', array( $this, 'handle_wordpress_robots' ) );
		add_filter( 'rank_math/frontend/robots', array( $this, 'handle_rank_math_robots' ), 99 );
		add_action( 'template_redirect', array( $this, 'handle_template_redirect' ), 20 );
	}

	/**
	 * Detects filter and ordering parameters only on product listings.
	 */
	public function has_catalog_filters(): bool {
		if ( empty( $_GET ) || is_admin() || ! $this->is_catalog_listing_context() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lectura de solo lectura: decide la política de indexación de ESTA petición; no guarda nada.
			return false;
		}

		foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lectura de solo lectura: decide la política de indexación de ESTA petición; no guarda nada.
			if ( is_string( $key ) && $this->is_catalog_filter_parameter( $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detects URL actions and utility endpoints that must never become
	 * indexable variants.
	 *
	 * These requests can appear on product pages, catalog listings, or regular
	 * content pages such as MailPoet's subscription-management endpoint.
	 */
	public function has_nonindexable_query_action(): bool {
		if ( empty( $_GET ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lectura de solo lectura: decide la política de indexación de ESTA petición; no guarda nada.
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Lectura de solo lectura: decide la política de indexación de ESTA petición; no guarda nada.
		return array_key_exists( 'add-to-cart', $_GET )
			|| array_key_exists( 'add-to-wishlist', $_GET )
			|| array_key_exists( 'mailpoet_page', $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Applies the policy to WordPress' robots representation.
	 *
	 * @param array<string,mixed> $robots Current directives.
	 * @return array<string,mixed>
	 */
	public function handle_wordpress_robots( array $robots ): array {
		if ( ! $this->should_noindex_request() ) {
			return $robots;
		}

		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'], $robots['nofollow'] );

		return $robots;
	}

	/**
	 * Applies the same policy to Rank Math's robots representation.
	 *
	 * @param mixed $robots Current Rank Math directives.
	 * @return mixed
	 */
	public function handle_rank_math_robots( $robots ) {
		if ( ! is_array( $robots ) || ! $this->should_noindex_request() ) {
			return $robots;
		}

		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';

		return $robots;
	}

	/**
	 * Returns a real 404 for empty filtered collections.
	 *
	 * The response keeps WooCommerce's catalog template so the visitor still
	 * receives its explanatory empty state and can remove filters. Text search
	 * and transactional URLs are deliberately excluded: this status belongs
	 * only to catalog variants recognized by the filter grammar below.
	 *
	 * @internal Callback for `template_redirect`.
	 */
	public function handle_template_redirect(): void {
		if (
			( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ! $this->has_empty_collection_status_filters()
		) {
			return;
		}

		$wp_query = $GLOBALS['wp_query'] ?? null;
		if (
			! is_object( $wp_query )
			|| ! isset( $wp_query->found_posts )
			|| 0 !== (int) $wp_query->found_posts
		) {
			return;
		}

		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Detects filters that can make a catalog collection genuinely absent.
	 *
	 * `_buscar` participates in the robots policy through
	 * {@see has_catalog_filters()}, but it cannot by itself turn an empty
	 * internal search into a 404. A request that combines text search with a
	 * real facet still qualifies because the facet defines a collection
	 * variant.
	 */
	private function has_empty_collection_status_filters(): bool {
		if ( empty( $_GET ) || is_admin() || ! $this->is_catalog_listing_context() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lectura de solo lectura: decide la política de indexación de ESTA petición; no guarda nada.
			return false;
		}

		foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lectura de solo lectura: decide la política de indexación de ESTA petición; no guarda nada.
			if (
				is_string( $key )
				&& self::TEXT_SEARCH_PARAMETER !== $key
				&& $this->is_catalog_filter_parameter( $key )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Limits filter grammar to WooCommerce product collection surfaces.
	 */
	private function is_catalog_listing_context(): bool {
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return true;
		}

		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			return true;
		}

		if ( ! function_exists( 'is_search' ) || ! is_search() || ! function_exists( 'get_query_var' ) ) {
			return false;
		}

		$post_type = get_query_var( 'post_type' );

		return 'product' === $post_type
			|| ( is_array( $post_type ) && in_array( 'product', $post_type, true ) );
	}

	/**
	 * Delegates to the shared Akibara and WooCommerce query grammar.
	 *
	 * The vocabulary lives in {@see CatalogQueryGrammar} because two modules
	 * with different consequences depend on the same answer.
	 */
	private function is_catalog_filter_parameter( string $key ): bool {
		return CatalogQueryGrammar::is_filter_parameter( $key );
	}

	private function should_noindex_request(): bool {
		return $this->has_catalog_filters() || $this->has_nonindexable_query_action();
	}
}
