<?php
/**
 * Narrow Cloudflare file/prefix purge using the credential already managed by
 * LiteSpeed Cache. Stream through `wp eval-file -`; never copy to production.
 */

defined( 'ABSPATH' ) || exit( 1 );

try {
	if ( '1' !== getenv( 'AKB_CLOUDFLARE_PURGE_APPLY' ) ) {
		throw new RuntimeException( 'apply_not_confirmed' );
	}
	if ( 'https://akibara.cl' !== untrailingslashit( home_url( '/' ) ) ) {
		throw new RuntimeException( 'site_url_mismatch' );
	}

	$expected_zone = trim( (string) getenv( 'AKB_CLOUDFLARE_EXPECTED_ZONE_ID' ) );
	if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $expected_zone ) ) {
		throw new RuntimeException( 'expected_zone_invalid' );
	}

	$files_json    = trim( (string) getenv( 'AKB_CLOUDFLARE_PURGE_FILES_JSON' ) );
	$prefixes_json = trim( (string) getenv( 'AKB_CLOUDFLARE_PURGE_PREFIXES_JSON' ) );
	if ( ( '' !== $files_json ) === ( '' !== $prefixes_json ) ) {
		throw new RuntimeException( 'exactly_one_purge_mode_required' );
	}

	$purge_mode = '' !== $files_json ? 'files' : 'prefixes';
	$targets    = json_decode( 'files' === $purge_mode ? $files_json : $prefixes_json, true );
	if (
		! is_array( $targets )
		|| ! array_is_list( $targets )
		|| 1 > count( $targets )
		|| 30 < count( $targets )
	) {
		throw new RuntimeException( $purge_mode . '_invalid' );
	}
	foreach ( $targets as $target ) {
		if ( ! is_string( $target ) ) {
			throw new RuntimeException( $purge_mode . '_target_not_string' );
		}
		if ( 'prefixes' === $purge_mode ) {
			$allowed_prefixes = array(
				'akibara.cl/serie/',
				'akibara.cl/series/',
				'akibara.cl/wp-content/plugins/akibara-business/',
				'akibara.cl/wp-content/plugins/akibara-search/',
				'akibara.cl/wp-content/themes/akibara-gp/',
			);
			if (
				! in_array( $target, $allowed_prefixes, true )
				&& 1 !== preg_match( '#^akibara\.cl/serie/[a-z0-9-]+/$#D', $target )
			) {
				throw new RuntimeException( 'prefix_outside_allowlist:' . $target );
			}
			continue;
		}

		$parts = wp_parse_url( $target );
		$allowed_files = array(
			'/',
			'/series/',
			'/sitemap_index.xml',
			'/pa_serie-sitemap.xml',
			'/llms.txt',
		);
		if (
			! is_array( $parts )
			|| 'https' !== ( $parts['scheme'] ?? '' )
			|| 'akibara.cl' !== ( $parts['host'] ?? '' )
			|| (
				! in_array( $parts['path'] ?? '', $allowed_files, true )
				&& 1 !== preg_match( '#^/serie/[a-z0-9-]+/$#D', $parts['path'] ?? '' )
			)
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['port'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			throw new RuntimeException( 'file_outside_allowlist:' . $target );
		}
	}
	$targets = array_values( array_unique( $targets ) );

	$class = '\\LiteSpeed\\CDN\\Cloudflare';
	if ( ! class_exists( $class ) ) {
		throw new RuntimeException( 'litespeed_cloudflare_unavailable' );
	}
	$client = $class::cls();
	if ( ! $client->conf( 'cdn-cloudflare' ) ) {
		throw new RuntimeException( 'cloudflare_integration_disabled' );
	}
	$zone = (string) $client->conf( 'cdn-cloudflare_zone' );
	if ( $expected_zone !== $zone ) {
		throw new RuntimeException( 'cloudflare_zone_mismatch' );
	}

	$key = (string) $client->conf( 'cdn-cloudflare_key' );
	if ( 30 > strlen( $key ) ) {
		throw new RuntimeException( 'cloudflare_credential_missing' );
	}
	if ( 37 === strlen( $key ) && preg_match( '/^[0-9a-f]+$/D', $key ) ) {
		$headers = array(
			'Content-Type' => 'application/json',
			'X-Auth-Email' => (string) $client->conf( 'cdn-cloudflare_email' ),
			'X-Auth-Key'   => $key,
		);
	} else {
		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $key,
		);
	}

	$response = wp_remote_post(
		'https://api.cloudflare.com/client/v4/zones/' . $zone . '/purge_cache',
		array(
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => wp_json_encode( array( $purge_mode => $targets ) ),
		)
	);
	$key = '';
	unset( $headers );

	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( 'cloudflare_request_failed:' . $response->get_error_code() );
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( 200 !== $status || ! is_array( $body ) || true !== ( $body['success'] ?? false ) ) {
		throw new RuntimeException(
			'cloudflare_api_rejected:' . wp_json_encode(
				array(
					'http_status' => $status,
					'errors'      => is_array( $body ) ? ( $body['errors'] ?? array() ) : array(),
				)
			)
		);
	}

	echo wp_json_encode(
		array(
			'status'       => 'PASS',
			'zone_id'      => $zone,
			'purge_mode'   => $purge_mode,
			'purged_count' => count( $targets ),
			'purged_items' => $targets,
			'result'       => $body['result'] ?? null,
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n";
} catch ( Throwable $error ) {
	fwrite(
		STDERR,
		wp_json_encode(
			array(
				'status' => 'FAIL',
				'error'  => $error->getMessage(),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n"
	);
	exit( 1 );
}
