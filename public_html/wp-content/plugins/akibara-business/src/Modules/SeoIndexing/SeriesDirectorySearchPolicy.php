<?php
/**
 * Indexing policy for the client-side series-directory search URL.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoIndexing;

use Akibara\Business\Modules\SeriesDirectory\Shortcode;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the public URL policy for the client-side series directory.
 *
 * Search variants remain useful and shareable, but are not indexable. Numeric
 * subpages do not exist because the shortcode renders the complete directory
 * in one document; WordPress nevertheless resolves `/series/page/N/` as the
 * same singular page unless this policy turns it into a real 404.
 */
final class SeriesDirectorySearchPolicy {

	private const SEARCH_PARAMETER = 'buscar';

	/** Remembers the verdict after WP_Query stops reporting the request as a page. */
	private bool $invalid_pagination_response = false;

	/** Registers metadata plus the early response-status/cache gate. */
	public function register(): void {
		add_filter( 'wp_robots', array( $this, 'handle_wordpress_robots' ), 99 );
		add_filter( 'rank_math/frontend/robots', array( $this, 'handle_rank_math_robots' ), 99 );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'handle_rank_math_canonical' ), 99 );
		add_filter( 'redirect_canonical', array( $this, 'handle_redirect_canonical' ), 99, 2 );
		add_action( 'rank_math/head', array( $this, 'handle_rank_math_head_canonical' ), 20 );
		add_action( 'template_redirect', array( $this, 'handle_template_redirect' ), 0 );
	}

	/**
	 * Applies `noindex,follow` to WordPress' robots representation.
	 *
	 * @internal Callback de `wp_robots`.
	 *
	 * @param array<string,mixed> $robots Current directives.
	 * @return array<string,mixed>
	 */
	public function handle_wordpress_robots( array $robots ): array {
		if ( ! $this->should_noindex_current_request() ) {
			return $robots;
		}

		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'], $robots['nofollow'] );

		return $robots;
	}

	/**
	 * Applies `noindex,follow` to Rank Math's robots representation.
	 *
	 * @internal Callback de `rank_math/frontend/robots`.
	 *
	 * @param mixed $robots Current directives.
	 * @return mixed
	 */
	public function handle_rank_math_robots( $robots ) {
		if ( ! is_array( $robots ) || ! $this->should_noindex_current_request() ) {
			return $robots;
		}

		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';

		return $robots;
	}

	/**
	 * Points every client-side search variant to the clean directory permalink.
	 *
	 * @internal Callback de `rank_math/frontend/canonical`.
	 *
	 * @param mixed $canonical Current canonical.
	 * @return mixed
	 */
	public function handle_rank_math_canonical( $canonical ) {
		if ( $this->has_invalid_pagination_request() ) {
			return false;
		}

		if ( ! $this->applies_to_current_request() ) {
			return $canonical;
		}

		$permalink = $this->clean_directory_permalink();

		return '' !== $permalink
			? $permalink
			: $canonical;
	}

	/**
	 * Prevents Core from redirecting a rejected singular subpage to page one.
	 *
	 * `redirect_canonical()` has an explicit recovery for missing
	 * `<!--nextpage-->` segments: after this policy sets a 404, Core otherwise
	 * strips the numeric segment and emits a 301 to the directory permalink.
	 * Returning false only for this remembered verdict preserves the intended
	 * 404 while leaving every other canonical redirect untouched.
	 *
	 * @internal Callback de `redirect_canonical`.
	 *
	 * @param mixed $redirect_url  Candidate redirect URL.
	 * @param mixed $requested_url Original requested URL.
	 * @return mixed
	 */
	public function handle_redirect_canonical( $redirect_url, $requested_url ) {
		unset( $requested_url );

		return $this->has_invalid_pagination_request()
			? false
			: $redirect_url;
	}

	/**
	 * Restores the scoped canonical after Rank Math removes its own on noindex.
	 *
	 * Rank Math's robots callback deliberately unregisters its canonical action
	 * for every noindex response. This separate callback survives that removal
	 * and runs only for the directory-search variant required by this policy.
	 *
	 * @internal Callback de `rank_math/head`.
	 */
	public function handle_rank_math_head_canonical(): void {
		if ( $this->has_invalid_pagination_request() || ! $this->applies_to_current_request() ) {
			return;
		}

		$canonical = $this->clean_directory_permalink();
		if ( '' === $canonical ) {
			return;
		}

		echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
	}

	/**
	 * Detects only `buscar` on the singular page that owns the directory.
	 */
	public function applies_to_current_request(): bool {
		if (
			is_admin()
			|| ! array_key_exists( self::SEARCH_PARAMETER, $_GET ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL grammar.
			|| ! function_exists( 'is_page' )
			|| ! is_page()
		) {
			return false;
		}

		return null !== $this->current_directory_post();
	}

	/**
	 * Closes search variants to every local cache and rejects fake subpages.
	 *
	 * `template_redirect:0` runs after the main query is known but before the
	 * Search page-cache lease at priority 1. A 404 verdict is therefore visible
	 * to template selection, Rank Math and every later cache integration.
	 *
	 * @internal Callback de `template_redirect`.
	 */
	public function handle_template_redirect(): void {
		if ( $this->has_invalid_pagination_request() ) {
			$this->invalid_pagination_response = true;

			$wp_query = $GLOBALS['wp_query'] ?? null;
			if ( is_object( $wp_query ) && method_exists( $wp_query, 'set_404' ) ) {
				$wp_query->set_404();
			}

			status_header( 404 );
			$this->mark_response_no_store( 'akibara_series_directory_invalid_page' );
			return;
		}

		if ( $this->applies_to_current_request() ) {
			$this->mark_response_no_store( 'akibara_series_directory_search' );
		}
	}

	/**
	 * Detects numeric subpages of the one-document directory.
	 *
	 * WordPress uses `page` for paginated singular content, while integrations
	 * can expose the same route through `paged`; accepting both avoids an SEO
	 * bypass without affecting the real `/serie/<slug>/page/N/` taxonomy pages.
	 */
	public function has_invalid_pagination_request(): bool {
		if ( $this->invalid_pagination_response ) {
			return true;
		}

		if (
			is_admin()
			|| ! function_exists( 'is_page' )
			|| ! is_page()
			|| null === $this->current_directory_post()
		) {
			return false;
		}

		return $this->current_page() > 1;
	}

	/** @return list<string> Exact edge-specific headers emitted by the gate. */
	public static function edge_no_store_headers(): array {
		return array(
			'Cloudflare-CDN-Cache-Control: no-store',
			'CDN-Cache-Control: no-store',
		);
	}

	/** Applies the shared noindex verdict to search and invalid-page variants. */
	private function should_noindex_current_request(): bool {
		return $this->invalid_pagination_response
			|| $this->has_invalid_pagination_request()
			|| $this->applies_to_current_request();
	}

	/** Prevents origin/LiteSpeed storage and emits defense-in-depth CDN headers. */
	private function mark_response_no_store( string $reason ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Contract shared by WordPress cache plugins.
		}

		nocache_headers();
		do_action( 'litespeed_control_set_nocache', $reason ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Official LiteSpeed integration hook.

		if ( headers_sent() ) {
			return;
		}

		foreach ( self::edge_no_store_headers() as $header ) {
			header( $header, true );
		}
	}

	/** Returns the effective singular/archive page number. */
	private function current_page(): int {
		if ( ! function_exists( 'get_query_var' ) ) {
			return 1;
		}

		return max(
			1,
			(int) get_query_var( 'page' ),
			(int) get_query_var( 'paged' )
		);
	}

	/** Returns the current page only when it contains the exact directory tag. */
	private function current_directory_post(): ?object {
		if ( ! function_exists( 'get_post' ) || ! function_exists( 'has_shortcode' ) ) {
			return null;
		}

		$post = get_post();
		if ( ! is_object( $post ) || ! is_string( $post->post_content ?? null ) ) {
			return null;
		}

		return has_shortcode( $post->post_content, Shortcode::TAG ) ? $post : null;
	}

	/** Returns the clean directory permalink or an empty string. */
	private function clean_directory_permalink(): string {
		$post = $this->current_directory_post();
		if ( null === $post || ! isset( $post->ID ) ) {
			return '';
		}

		$permalink = get_permalink( (int) $post->ID );

		return is_string( $permalink ) ? trim( $permalink ) : '';
	}
}
