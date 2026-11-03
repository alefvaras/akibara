<?php
/**
 * Rank Math editor integration.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the featured image already rendered by single.php to Rank Math's
 * editor-only content analysis. This does not alter post_content or frontend
 * markup; it corrects the analyzer's otherwise incomplete view of the page.
 */
function akibara_gp_enqueue_rank_math_content_analysis(): void {
	if ( ! defined( 'RANK_MATH_VERSION' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->base || 'post' !== $screen->post_type ) {
		return;
	}

	list( $src, $version ) = akibara_gp_asset( 'assets/js/rank-math-content-analysis.js' );
	wp_enqueue_script(
		'akibara-rank-math-content-analysis',
		$src,
		array( 'wp-hooks', 'wp-data', 'wp-dom-ready', 'rank-math-analyzer' ),
		$version,
		true
	);
}
add_action(
	'enqueue_block_editor_assets',
	'akibara_gp_enqueue_rank_math_content_analysis',
	20
);
