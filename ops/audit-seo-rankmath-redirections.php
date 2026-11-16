<?php
/**
 * Read-only Rank Math redirection audit for Akibara.
 *
 * Usage:
 *   wp eval-file - < ops/audit-seo-rankmath-redirections.php
 *
 * Optional environment variables:
 *   AKB_SEO_REDIRECT_HTTP_CHECK=1  Validate every unique internal 301 target.
 *   AKB_SEO_REDIRECT_HTTP_BASE=... Override only the transport origin used by
 *                                  HTTP checks (for example http://wordpress
 *                                  from the Docker CLI container).
 *   AKB_SEO_REDIRECT_STRICT=1      Exit non-zero when a required invariant
 *                                  fails.
 *
 * This file is intentionally read-only and uses Rank Math's public DB reader.
 */

use RankMath\Redirections\DB as RankMathRedirectionsDB;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this file through WP-CLI: wp eval-file -\n" );
	exit( 1 );
}

if ( ! class_exists( RankMathRedirectionsDB::class ) ) {
	WP_CLI::error( 'Rank Math redirections module is unavailable.' );
}

/**
 * Normalize an exact source the same way Rank Math compares it: trailing slash
 * does not distinguish two exact rules.
 *
 * @param string $pattern Source pattern.
 * @return string
 */
function akb_seo_redirect_logical_exact_source( $pattern ) {
	$pattern = rawurldecode( trim( (string) $pattern ) );
	$pattern = preg_replace( '#/+#', '/', $pattern );
	return strtolower( trim( $pattern, '/' ) );
}

/**
 * Resolve a destination against the current WordPress host.
 *
 * @param string $destination Stored destination.
 * @return string
 */
function akb_seo_redirect_absolute_destination( $destination ) {
	$destination = trim( (string) $destination );
	if ( '' === $destination ) {
		return '';
	}

	if ( preg_match( '#^https?://#i', $destination ) ) {
		return $destination;
	}

	return home_url( '/' . ltrim( $destination, '/' ) );
}

/**
 * Whether a URL belongs to an Akibara-controlled environment.
 *
 * @param string $url Absolute URL.
 * @return bool
 */
function akb_seo_redirect_is_akibara_internal( $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	return in_array(
		$host,
		array(
			'akibara.cl',
			'www.akibara.cl',
			'localhost',
			'127.0.0.1',
			'wordpress',
		),
		true
	);
}

/**
 * Convert the destination to a transport URL while preserving path/query.
 *
 * @param string $destination Absolute logical destination.
 * @param string $transport_base Optional transport origin.
 * @return string
 */
function akb_seo_redirect_transport_url( $destination, $transport_base ) {
	if ( '' === $transport_base ) {
		return $destination;
	}

	$path  = (string) wp_parse_url( $destination, PHP_URL_PATH );
	$query = (string) wp_parse_url( $destination, PHP_URL_QUERY );
	$url   = untrailingslashit( $transport_base ) . '/' . ltrim( $path, '/' );

	if ( '' !== $query ) {
		$url .= '?' . $query;
	}

	return $url;
}

/**
 * Canonical URL origin including a non-default port.
 *
 * @param string $url Absolute URL.
 * @return string
 */
function akb_seo_redirect_origin( $url ) {
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$port   = wp_parse_url( $url, PHP_URL_PORT );

	if ( '' === $scheme || '' === $host ) {
		return '';
	}

	return $scheme . '://' . $host . ( $port ? ':' . absint( $port ) : '' );
}

$result = RankMathRedirectionsDB::get_redirections(
	array(
		'status'  => 'active',
		'limit'   => 5000,
		'paged'   => 1,
		'orderby' => 'id',
		'order'   => 'ASC',
	)
);

$records                  = isset( $result['redirections'] ) && is_array( $result['redirections'] ) ? $result['redirections'] : array();
$home_origin              = akb_seo_redirect_origin( home_url( '/' ) );
$environment              = wp_get_environment_type();
$logical_sources          = array();
$malformed_sources        = array();
$terminal_with_target     = array();
$internal_host_mismatches = array();
$production_leaks         = array();
$exact_metacharacters     = array();
$regex_rules              = array();
$shop_redirects           = array();
$shop_pagination_rules    = array();
$reservation_route_rules  = array();
$comparison_counts        = array();
$code_counts              = array();
$source_rule_count        = 0;
$http_destinations        = array();
$semantic_route_results   = array();
$semantic_route_failures  = array();

foreach ( $records as $record ) {
	$id          = absint( $record['id'] ?? 0 );
	$header_code = absint( $record['header_code'] ?? 0 );
	$destination = (string) ( $record['url_to'] ?? '' );
	$sources     = maybe_unserialize( $record['sources'] ?? '' );

	$code_counts[ $header_code ] = ( $code_counts[ $header_code ] ?? 0 ) + 1;

	if ( ! is_array( $sources ) ) {
		$malformed_sources[] = array(
			'id'          => $id,
			'raw_sources' => (string) ( $record['sources'] ?? '' ),
			'code'        => $header_code,
			'destination' => $destination,
		);
		continue;
	}

	if ( in_array( $header_code, array( 410, 451 ), true ) && '' !== trim( $destination ) ) {
		$terminal_with_target[] = array(
			'id'          => $id,
			'sources'     => $sources,
			'code'        => $header_code,
			'destination' => $destination,
		);
	}

	$absolute_destination = akb_seo_redirect_absolute_destination( $destination );
	if (
		301 === $header_code
		&& '' !== $absolute_destination
		&& akb_seo_redirect_is_akibara_internal( $absolute_destination )
	) {
		$destination_origin = akb_seo_redirect_origin( $absolute_destination );
		if ( $destination_origin !== $home_origin ) {
			$internal_host_mismatches[] = array(
				'id'                   => $id,
				'sources'              => $sources,
				'stored_destination'   => $destination,
				'resolved_destination' => $absolute_destination,
				'current_origin'       => $home_origin,
				'destination_origin'   => $destination_origin,
			);
		}

		if ( false === strpos( $absolute_destination, '$' ) ) {
			$http_destinations[ $absolute_destination ] = true;
		}
	}

	if ( 'production' === $environment && '' !== $absolute_destination ) {
		$destination_host = strtolower( (string) wp_parse_url( $absolute_destination, PHP_URL_HOST ) );
		$is_nonproduction_subdomain = str_ends_with( $destination_host, '.akibara.cl' )
			&& ! in_array( $destination_host, array( 'akibara.cl', 'www.akibara.cl' ), true );
		if (
			$is_nonproduction_subdomain
			|| in_array( $destination_host, array( 'localhost', '127.0.0.1', 'wordpress' ), true )
		) {
			$production_leaks[] = array(
				'id'          => $id,
				'sources'     => $sources,
				'destination' => $absolute_destination,
			);
		}
	}

	foreach ( $sources as $source ) {
		$pattern    = (string) ( $source['pattern'] ?? '' );
		$comparison = (string) ( $source['comparison'] ?? 'exact' );
		$ignore     = (string) ( $source['ignore'] ?? '' );
		$logical    = 'exact' === $comparison
			? akb_seo_redirect_logical_exact_source( $pattern )
			: strtolower( ltrim( trim( $pattern ), '^/' ) );

		++$source_rule_count;
		$comparison_counts[ $comparison ] = ( $comparison_counts[ $comparison ] ?? 0 ) + 1;

		if ( 'exact' === $comparison ) {
			$key     = 'exact|' . $logical;

			$logical_sources[ $key ][] = array(
				'id'          => $id,
				'pattern'     => $pattern,
				'ignore'      => $ignore,
				'code'        => $header_code,
				'destination' => $destination,
				'hits'        => absint( $record['hits'] ?? 0 ),
			);

			// Dot is valid in an exact URL (for example wp-login.php); these are
			// only characters that strongly indicate an accidentally exact
			// wildcard/regex rule.
			if ( preg_match( '/[\\\\^$*+?()[\]{}|]/', $pattern ) ) {
				$exact_metacharacters[] = array(
					'id'          => $id,
					'pattern'     => $pattern,
					'code'        => $header_code,
					'destination' => $destination,
					'hits'        => absint( $record['hits'] ?? 0 ),
				);
			}
		} elseif ( 'regex' === $comparison ) {
			$regex_rules[] = array(
				'id'          => $id,
				'pattern'     => $pattern,
				'code'        => $header_code,
				'destination' => $destination,
				'hits'        => absint( $record['hits'] ?? 0 ),
			);
		}

		if ( 0 === strpos( $logical, 'tienda/page/' ) ) {
			$shop_pagination_rules[] = array(
				'id'          => $id,
				'pattern'     => $pattern,
				'comparison'  => $comparison,
				'code'        => $header_code,
				'destination' => $destination,
			);
		}

		if ( false !== strpos( strtolower( $pattern ), 'mis-reservas' ) ) {
			$reservation_route_rules[] = array(
				'id'          => $id,
				'pattern'     => $pattern,
				'comparison'  => $comparison,
				'code'        => $header_code,
				'destination' => $destination,
			);
		}

		$resolved_path = (string) wp_parse_url( $absolute_destination, PHP_URL_PATH );
		if ( 301 === $header_code && '/tienda/' === trailingslashit( $resolved_path ) ) {
			$shop_redirects[] = array(
				'id'                 => $id,
				'pattern'            => $pattern,
				'comparison'         => $comparison,
				'stored_destination' => $destination,
				'hits'               => absint( $record['hits'] ?? 0 ),
			);
		}
	}
}

$logical_duplicates = array();
foreach ( $logical_sources as $key => $rules ) {
	if ( count( $rules ) > 1 ) {
		$logical_duplicates[] = array(
			'logical_source' => substr( $key, strlen( 'exact|' ) ),
			'rules'          => $rules,
		);
	}
}

$closed_set_coverage_failures = array();
$reviewed_brand_slugs = array(
	'arechi-manga',
	'ivrea-argentina',
	'ivrea-espana',
	'milky-way',
	'ovni-press',
	'panini-argentina',
	'panini-espana',
	'planeta-espana',
);

$assert_exact_301 = static function ( $source, $expected_path, $kind ) use ( $logical_sources, &$closed_set_coverage_failures ) {
	$rules = $logical_sources[ 'exact|' . $source ] ?? array();
	$valid = array_filter(
		$rules,
		static function ( $rule ) use ( $expected_path ) {
			$destination_url  = akb_seo_redirect_absolute_destination( $rule['destination'] ?? '' );
			$destination_path = trailingslashit( (string) wp_parse_url( $destination_url, PHP_URL_PATH ) );
			return 301 === absint( $rule['code'] ?? 0 )
				&& trailingslashit( $expected_path ) === $destination_path;
		}
	);
	if ( 1 !== count( $rules ) || 1 !== count( $valid ) ) {
		$closed_set_coverage_failures[] = array(
			'type'          => $kind,
			'source'        => $source,
			'expected_path' => trailingslashit( $expected_path ),
			'rules'         => $rules,
		);
	}
};

foreach ( $reviewed_brand_slugs as $reviewed_brand_slug ) {
	$brand_term = get_term_by( 'slug', $reviewed_brand_slug, 'product_brand' );
	if ( ! $brand_term instanceof WP_Term || (int) $brand_term->count < 1 ) {
		$closed_set_coverage_failures[] = array(
			'type' => 'reviewed_brand_target_missing_or_empty',
			'slug' => $reviewed_brand_slug,
		);
		continue;
	}
	$assert_exact_301(
		'brand/' . $reviewed_brand_slug,
		'/marca/' . $reviewed_brand_slug . '/',
		'reviewed_brand_legacy_gap'
	);
}

$rank_math_general_options = get_option( 'rank-math-options-general', array() );
$wc_remove_product_base    = is_array( $rank_math_general_options )
	? ( $rank_math_general_options['wc_remove_product_base'] ?? null )
	: null;
$evidence_product_aliases  = array();
$redundant_product_rows    = array();
$invalid_product_rows      = array();
foreach ( $logical_sources as $source_key => $rules ) {
	$logical_source = substr( $source_key, strlen( 'exact|' ) );
	if ( 0 !== strpos( $logical_source, 'product/' ) ) {
		continue;
	}
	foreach ( $rules as $rule ) {
		if ( in_array( absint( $rule['code'] ?? 0 ), array( 410, 451 ), true ) ) {
			continue;
		}
		$destination_url  = akb_seo_redirect_absolute_destination( $rule['destination'] ?? '' );
		$destination_path = trim( (string) wp_parse_url( $destination_url, PHP_URL_PATH ), '/' );
		$target_product   = '' !== $destination_path && false === strpos( $destination_path, '/' )
			? get_page_by_path( $destination_path, OBJECT, 'product' )
			: null;
		$source_slug      = substr( $logical_source, strlen( 'product/' ) );
		if (
			301 === absint( $rule['code'] ?? 0 )
			&& $target_product instanceof WP_Post
			&& 'publish' === $target_product->post_status
		) {
			if ( $source_slug === $destination_path ) {
				$redundant_product_rows[] = array(
					'source' => $logical_source,
					'rule'   => $rule,
				);
			} else {
				$evidence_product_aliases[] = array(
					'source' => $logical_source,
					'rule'   => $rule,
				);
			}
		} else {
			$invalid_product_rows[] = array(
				'source' => $logical_source,
				'rule'   => $rule,
			);
		}
	}
}

$invalid_series_inventory = array();
foreach ( $logical_sources as $source_key => $rules ) {
	$logical_source = substr( $source_key, strlen( 'exact|' ) );
	if ( 0 !== strpos( $logical_source, 'serie/' ) ) {
		continue;
	}
	foreach ( $rules as $rule ) {
		if ( in_array( absint( $rule['code'] ?? 0 ), array( 410, 451 ), true ) ) {
			continue;
		}
		$destination_url  = akb_seo_redirect_absolute_destination( $rule['destination'] ?? '' );
		$destination_path = trim( (string) wp_parse_url( $destination_url, PHP_URL_PATH ), '/' );
		$valid_target     = false;
		if ( 0 === strpos( $destination_path, 'serie/' ) ) {
			$target_slug = substr( $destination_path, strlen( 'serie/' ) );
			$target_term = get_term_by( 'slug', $target_slug, 'pa_serie' );
			$valid_target = $target_term instanceof WP_Term && (int) $target_term->count > 0;
		} elseif ( '' !== $destination_path && false === strpos( $destination_path, '/' ) ) {
			$target_product = get_page_by_path( $destination_path, OBJECT, 'product' );
			$valid_target   = $target_product instanceof WP_Post
				&& 'publish' === $target_product->post_status;
		}
		if (
			301 !== absint( $rule['code'] ?? 0 )
			|| ! $valid_target
			|| $logical_source === akb_seo_redirect_logical_exact_source( $destination_path )
		) {
			$invalid_series_inventory[] = array(
				'source' => $logical_source,
				'rule'   => $rule,
			);
		}
	}
}

$open_editorial_regex_rules = array_values(
	array_filter(
		$regex_rules,
		static function ( $rule ) {
			return (bool) preg_match(
				'#(?:^|[\\\\^/])(product|brand|serie)/#i',
				(string) ( $rule['pattern'] ?? '' )
			);
		}
	)
);

$semantic_expectations = array(
	array(
		'source' => 'tienda/page/69',
		'type'   => 'none',
		'reason' => 'Dynamic shop pagination must resolve natively.',
	),
	array(
		'source' => 'tienda/page/78',
		'type'   => 'none',
		'reason' => 'Dynamic shop pagination must resolve natively.',
	),
	array(
		'source' => 'tienda/page/135',
		'type'   => 'none',
		'reason' => 'Dynamic shop pagination must resolve natively.',
	),
	array(
		'source' => 'tienda/page/142',
		'type'   => 'none',
		'reason' => 'Dynamic shop pagination must resolve natively.',
	),
	array(
		'source' => 'tienda/page/144',
		'type'   => 'none',
		'reason' => 'Dynamic shop pagination must resolve natively.',
	),
	array(
		'source' => 'tienda/page/160',
		'type'   => 'none',
		'reason' => 'Dynamic shop pagination must resolve natively.',
	),
	array(
		'source' => 'genero/accion/page/47',
		'type'   => 'none',
		'reason' => 'Action pagination may become valid again as the catalogue grows.',
	),
	array(
		'source' => 'mis-reservas',
		'type'   => 'terminal',
		'code'   => 410,
		'reason' => 'The removed standalone reservations route is permanently gone.',
	),
	array(
		'source'      => 'mi-cuenta/mis-reservas',
		'type'        => 'redirect',
		'code'        => 301,
		'destination' => '/mi-cuenta/',
		'reason'      => 'The former account sub-route has a functional account replacement.',
	),
	array(
		'source'      => 'serie',
		'type'        => 'redirect',
		'code'        => 301,
		'destination' => '/tienda/',
		'reason'      => 'The retired series index must not redirect to itself.',
	),
	array(
		'source'      => 'autor/tatsukia-fujimoto',
		'type'        => 'redirect',
		'code'        => 301,
		'destination' => '/autor/tatsuki-fujimoto/',
		'reason'      => 'Corrected author slug.',
	),
	array(
		'source'      => 'autor/tappei-nagatsuk',
		'type'        => 'redirect',
		'code'        => 301,
		'destination' => '/autor/tappei-nagatsuki/',
		'reason'      => 'Corrected author slug.',
	),
	array(
		'source'      => 'autor/masamure-shirow',
		'type'        => 'redirect',
		'code'        => 301,
		'destination' => '/autor/masamune-shirow/',
		'reason'      => 'Corrected author slug.',
	),
	array(
		'source'      => 'wishlist',
		'type'        => 'redirect',
		'code'        => 301,
		'destination' => '/mi-cuenta/favoritos/',
		'reason'      => 'Former wishlist page now uses the account endpoint.',
	),
	array(
		'source'      => 'mi-cuenta/downloads',
		'type'        => 'redirect',
		'code'        => 301,
		'destination' => '/mi-cuenta/',
		'reason'      => 'Disabled downloads endpoint returns to the account root.',
	),
);

foreach ( $semantic_expectations as $expectation ) {
	$matched = RankMathRedirectionsDB::match_redirections( $expectation['source'], true );
	$actual  = false === $matched
		? array( 'matched' => false )
		: array(
			'matched'     => true,
			'id'          => absint( $matched['id'] ?? 0 ),
			'code'        => absint( $matched['header_code'] ?? 0 ),
			'destination' => (string) ( $matched['url_to'] ?? '' ),
		);
	$passed  = false;

	if ( 'none' === $expectation['type'] ) {
		$passed = false === $matched;
	} elseif ( false !== $matched && absint( $matched['header_code'] ?? 0 ) === $expectation['code'] ) {
		if ( 'terminal' === $expectation['type'] ) {
			$passed = '' === trim( (string) ( $matched['url_to'] ?? '' ) );
		} else {
			$expected_path = trailingslashit( '/' . ltrim( $expectation['destination'], '/' ) );
			$actual_url    = akb_seo_redirect_absolute_destination( $matched['url_to'] ?? '' );
			$actual_path   = trailingslashit( (string) wp_parse_url( $actual_url, PHP_URL_PATH ) );
			$passed        = $expected_path === $actual_path;
		}
	}

	$result_entry = array(
		'source'      => $expectation['source'],
		'expectation' => $expectation,
		'actual'      => $actual,
		'passed'      => $passed,
	);
	$semantic_route_results[] = $result_entry;
	if ( ! $passed ) {
		$semantic_route_failures[] = $result_entry;
	}
}

$http_results = array();
$http_failures = array();
if ( '1' === getenv( 'AKB_SEO_REDIRECT_HTTP_CHECK' ) ) {
	$transport_base = trim( (string) getenv( 'AKB_SEO_REDIRECT_HTTP_BASE' ) );
	$current_host   = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$current_port   = wp_parse_url( home_url( '/' ), PHP_URL_PORT );
	$host_header    = $current_host . ( $current_port ? ':' . $current_port : '' );

	foreach ( array_keys( $http_destinations ) as $destination ) {
		$request_url = akb_seo_redirect_transport_url( $destination, $transport_base );
		$response    = wp_remote_head(
			$request_url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array( 'Host' => $host_header ),
				'user-agent'  => 'Akibara-SEO-Redirect-Audit/1.0',
			)
		);

		if ( is_wp_error( $response ) ) {
			$entry = array(
				'destination' => $destination,
				'request_url' => $request_url,
				'error'       => $response->get_error_message(),
			);
		} else {
			$status   = absint( wp_remote_retrieve_response_code( $response ) );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			$entry    = array(
				'destination' => $destination,
				'request_url' => $request_url,
				'status'      => $status,
				'location'    => $location,
			);
		}

		$http_results[] = $entry;
		if ( isset( $entry['error'] ) || 200 !== ( $entry['status'] ?? 0 ) || '' !== ( $entry['location'] ?? '' ) ) {
			$http_failures[] = $entry;
		}
	}
}

ksort( $code_counts );
ksort( $comparison_counts );
usort(
	$shop_redirects,
	static function ( $left, $right ) {
		return strcmp( $left['pattern'], $right['pattern'] );
	}
);

$failed_invariants = array();
if ( ! empty( $malformed_sources ) ) {
	$failed_invariants[] = 'malformed_sources';
}
if ( ! empty( $logical_duplicates ) ) {
	$failed_invariants[] = 'logical_duplicates';
}
if ( ! empty( $terminal_with_target ) ) {
	$failed_invariants[] = 'terminal_with_target';
}
if ( ! empty( $internal_host_mismatches ) ) {
	$failed_invariants[] = 'internal_host_mismatches';
}
if ( ! empty( $production_leaks ) ) {
	$failed_invariants[] = 'production_environment_leaks';
}
if ( ! empty( $exact_metacharacters ) ) {
	$failed_invariants[] = 'exact_sources_with_metacharacters';
}
if ( ! empty( $http_failures ) ) {
	$failed_invariants[] = 'internal_301_target_http_failures';
}
if ( ! empty( $semantic_route_failures ) ) {
	$failed_invariants[] = 'semantic_route_failures';
}
if ( ! empty( $shop_pagination_rules ) ) {
	$failed_invariants[] = 'shop_pagination_redirect_rules';
}
if ( ! empty( $closed_set_coverage_failures ) ) {
	$failed_invariants[] = 'closed_set_editorial_redirect_gaps';
}
if ( ! empty( $invalid_series_inventory ) ) {
	$failed_invariants[] = 'invalid_series_inventory_routes';
}
if ( ! empty( $open_editorial_regex_rules ) ) {
	$failed_invariants[] = 'open_editorial_regex_rules';
}
if ( 'on' !== $wc_remove_product_base ) {
	$failed_invariants[] = 'rank_math_wc_remove_product_base_disabled';
}
if ( ! empty( $redundant_product_rows ) ) {
	$failed_invariants[] = 'ui_redundant_product_redirect_rows';
}
if ( ! empty( $invalid_product_rows ) ) {
	$failed_invariants[] = 'invalid_product_redirect_rows';
}

$standalone_reservation_rules = array_values(
	array_filter(
		$reservation_route_rules,
		static function ( $rule ) {
			return 'regex' === $rule['comparison']
				&& '^mis-reservas/?$' === $rule['pattern']
				&& 410 === $rule['code']
				&& '' === trim( $rule['destination'] );
		}
	)
);
$account_reservation_rules = array_values(
	array_filter(
		$reservation_route_rules,
		static function ( $rule ) {
			return 'exact' === $rule['comparison']
				&& 'mi-cuenta/mis-reservas' === akb_seo_redirect_logical_exact_source( $rule['pattern'] )
				&& 301 === $rule['code']
				&& '/mi-cuenta/' === trailingslashit(
					(string) wp_parse_url(
						akb_seo_redirect_absolute_destination( $rule['destination'] ),
						PHP_URL_PATH
					)
				);
		}
	)
);
if (
	2 !== count( $reservation_route_rules )
	|| 1 !== count( $standalone_reservation_rules )
	|| 1 !== count( $account_reservation_rules )
) {
	$failed_invariants[] = 'reservation_route_cardinality';
}

$audit = array(
	'generated_at' => gmdate( 'c' ),
	'environment'  => $environment,
	'home_url'     => home_url( '/' ),
	'summary'      => array(
		'active_records'                    => count( $records ),
		'active_source_rules'               => $source_rule_count,
		'code_counts'                       => $code_counts,
		'comparison_counts'                 => $comparison_counts,
		'malformed_source_records'          => count( $malformed_sources ),
		'logical_duplicate_groups'          => count( $logical_duplicates ),
		'terminal_rules_with_destination'   => count( $terminal_with_target ),
		'internal_host_mismatches'          => count( $internal_host_mismatches ),
		'production_environment_leaks'      => count( $production_leaks ),
		'exact_sources_with_metacharacters' => count( $exact_metacharacters ),
		'legacy_regex_rules'                => count( $regex_rules ),
			'shop_redirect_rules'               => count( $shop_redirects ),
			'shop_pagination_rules'             => count( $shop_pagination_rules ),
			'reservation_route_rules'            => count( $reservation_route_rules ),
		'http_destinations_checked'         => count( $http_results ),
		'http_failures'                     => count( $http_failures ),
		'rank_math_wc_remove_product_base'   => $wc_remove_product_base,
		'evidence_product_alias_rows'        => count( $evidence_product_aliases ),
		'redundant_product_redirect_rows'    => count( $redundant_product_rows ),
		'invalid_product_redirect_rows'      => count( $invalid_product_rows ),
		'reviewed_brand_legacy_expected'    => count( $reviewed_brand_slugs ),
		'closed_set_coverage_failures'       => count( $closed_set_coverage_failures ),
		'invalid_series_inventory_routes'   => count( $invalid_series_inventory ),
		'open_editorial_regex_rules'         => count( $open_editorial_regex_rules ),
		'semantic_routes_checked'           => count( $semantic_route_results ),
		'semantic_route_failures'           => count( $semantic_route_failures ),
		'failed_invariants'                 => $failed_invariants,
	),
	'details'      => array(
		'malformed_sources'        => $malformed_sources,
		'logical_duplicates'       => $logical_duplicates,
		'terminal_with_target'     => $terminal_with_target,
		'internal_host_mismatches' => $internal_host_mismatches,
		'production_leaks'         => $production_leaks,
		'exact_metacharacters'     => $exact_metacharacters,
		'legacy_regex_rules'       => $regex_rules,
			'shop_redirects'           => $shop_redirects,
			'shop_pagination_rules'    => $shop_pagination_rules,
			'reservation_route_rules'  => $reservation_route_rules,
		'http_results'             => $http_results,
		'http_failures'            => $http_failures,
		'closed_set_coverage_failures' => $closed_set_coverage_failures,
		'invalid_series_inventory' => $invalid_series_inventory,
		'open_editorial_regex_rules' => $open_editorial_regex_rules,
		'evidence_product_aliases' => $evidence_product_aliases,
		'redundant_product_rows' => $redundant_product_rows,
		'invalid_product_rows' => $invalid_product_rows,
		'semantic_route_results'   => $semantic_route_results,
		'semantic_route_failures'  => $semantic_route_failures,
	),
);

WP_CLI::line( wp_json_encode( $audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

if ( ! empty( $failed_invariants ) && '1' === getenv( 'AKB_SEO_REDIRECT_STRICT' ) ) {
	WP_CLI::error( 'Rank Math redirection invariants failed: ' . implode( ', ', $failed_invariants ) );
}
