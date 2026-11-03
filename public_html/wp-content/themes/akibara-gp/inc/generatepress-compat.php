<?php
/**
 * GeneratePress compatibility filters.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Stop GeneratePress button states from leaking into custom storefront controls.
 *
 * GeneratePress groups every native `button:hover` and `button:focus` with its
 * form CTAs. Akibara has many native controls (carousel arrows, quantity
 * steppers, filters and icon buttons) whose own resting background must persist
 * through pointer and focus states. Remove only the two bare selectors while
 * retaining the parent theme states for inputs, `.button` CTAs and block links.
 *
 * The replacement is deliberately exact and therefore fails closed if a future
 * GeneratePress release changes the generated selector.
 *
 * @param string $css GeneratePress dynamic CSS.
 * @return string Filtered CSS.
 */
function akibara_gp_scope_generatepress_button_states( string $css ): string {
	$generatepress_selectors = array(
		'button:hover',
		'html input[type="button"]:hover',
		'input[type="reset"]:hover',
		'input[type="submit"]:hover',
		'a.button:hover',
		'button:focus',
		'html input[type="button"]:focus',
		'input[type="reset"]:focus',
		'input[type="submit"]:focus',
		'a.button:focus',
		'a.wp-block-button__link:not(.has-background):active',
		'a.wp-block-button__link:not(.has-background):focus',
		'a.wp-block-button__link:not(.has-background):hover',
	);
	$scoped_selectors        = array_values(
		array_diff( $generatepress_selectors, array( 'button:hover', 'button:focus' ) )
	);

	return str_replace(
		implode( ',', $generatepress_selectors ),
		implode( ',', $scoped_selectors ),
		$css
	);
}

add_filter( 'generate_colors_css_output', 'akibara_gp_scope_generatepress_button_states', PHP_INT_MAX );

// GeneratePress serves a persisted option after the first request, bypassing
// `generate_colors_css_output`; filter that read through the same fail-closed
// transformation so existing caches receive the correction immediately.
add_filter( 'option_generate_dynamic_css_output', 'akibara_gp_scope_generatepress_button_states', PHP_INT_MAX );
