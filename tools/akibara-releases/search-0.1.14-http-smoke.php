<?php
/**
 * Smoke HTTP hermetico de Akibara Search.
 *
 * Se ejecuta como PHP CLI dentro de la misma red Docker --internal que el
 * WordPress y MariaDB desechables del rail de recovery. No carga WordPress, no
 * sigue redirects y nunca incluye cuerpos HTTP en el receipt.
 */

declare( strict_types = 1 );

const AKB_HTTP_SMOKE_SCHEMA         = 'akibara-search-http-smoke-v1';
const AKB_HTTP_SMOKE_MAX_BODY_BYTES = 8 * 1024 * 1024;

/** @throws RuntimeException */
function akb_http_smoke_require( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * @param array<string, scalar> $query
 * @return array{status:int,body:string,bytes:int,sha256:string,error:string}
 */
function akb_http_smoke_fetch(
	string $origin,
	string $host_header,
	string $path,
	array $query = array(),
	string $accept = 'application/json',
	int $connect_timeout = 10,
	int $timeout = 30
): array {
	akb_http_smoke_require( str_starts_with( $path, '/' ), 'Path HTTP invalido.' );
	$query_string = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	$url          = $origin . $path . ( '' === $query_string ? '' : '?' . $query_string );
	$body         = '';
	$too_large    = false;
	$handle       = curl_init( $url );
	akb_http_smoke_require( false !== $handle, 'No se pudo inicializar cURL.' );

	$options = array(
		CURLOPT_CONNECTTIMEOUT => $connect_timeout,
		CURLOPT_TIMEOUT        => $timeout,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_MAXREDIRS      => 0,
		CURLOPT_PROXY          => '',
		CURLOPT_NOSIGNAL       => true,
		CURLOPT_HTTPHEADER     => array(
			'Host: ' . $host_header,
			'Accept: ' . $accept,
			'Cache-Control: no-cache',
		),
		CURLOPT_USERAGENT      => 'Akibara-Recovery-HTTP-Smoke/1.0',
		CURLOPT_WRITEFUNCTION  => static function ( $curl, string $chunk ) use ( &$body, &$too_large ): int {
			unset( $curl );
			if ( strlen( $body ) + strlen( $chunk ) > AKB_HTTP_SMOKE_MAX_BODY_BYTES ) {
				$too_large = true;
				return 0;
			}
			$body .= $chunk;
			return strlen( $chunk );
		},
	);
	if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTP' ) ) {
		$options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTP;
	}
	curl_setopt_array( $handle, $options );
	$result = curl_exec( $handle );
	$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
	$error  = false === $result ? trim( (string) curl_error( $handle ) ) : '';
	curl_close( $handle );

	if ( $too_large ) {
		$error = 'response_body_exceeded_limit';
	}

	return array(
		'status' => $status,
		'body'   => $body,
		'bytes'  => strlen( $body ),
		'sha256' => hash( 'sha256', $body ),
		'error'  => $error,
	);
}

/** @return array<string, mixed> */
function akb_http_smoke_json( string $body, string $label ): array {
	try {
		$decoded = json_decode( $body, true, 64, JSON_THROW_ON_ERROR );
	} catch ( JsonException $exception ) {
		throw new RuntimeException( $label . ' no devolvio JSON valido.', 0, $exception );
	}
	akb_http_smoke_require( is_array( $decoded ), $label . ' no devolvio un objeto JSON.' );
	return $decoded;
}

try {
	$origin      = trim( (string) getenv( 'AKB_HTTP_ORIGIN' ) );
	$host_header = trim( (string) getenv( 'AKB_HTTP_HOST' ) );
	akb_http_smoke_require(
		1 === preg_match( '#^http://akb-srch-0114-http-[a-z0-9-]+$#D', $origin ),
		'AKB_HTTP_ORIGIN no identifica el contenedor interno esperado.'
	);
	akb_http_smoke_require( 'localhost:8080' === $host_header, 'AKB_HTTP_HOST debe ser localhost:8080.' );
	akb_http_smoke_require( extension_loaded( 'curl' ), 'La extension PHP cURL no esta disponible.' );

	$script_sha = hash_file( 'sha256', __FILE__ );
	akb_http_smoke_require(
		is_string( $script_sha ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $script_sha ),
		'No se pudo identificar el script HTTP exacto.'
	);

	$ready         = false;
	$last_ready    = array( 'status' => 0, 'error' => 'not_started' );
	$ready_attempt = 0;
	for ( $ready_attempt = 1; $ready_attempt <= 45; ++$ready_attempt ) {
		$last_ready = akb_http_smoke_fetch( $origin, $host_header, '/wp-json/', array(), 'application/json', 2, 5 );
		if ( 200 === $last_ready['status'] && '' === $last_ready['error'] ) {
			$ready = true;
			break;
		}
		akb_http_smoke_require(
			$last_ready['status'] < 300 || $last_ready['status'] >= 400,
			'Readiness intento redirigir; se rehusa seguir el redirect.'
		);
		if ( $ready_attempt < 45 ) {
			sleep( 2 );
		}
	}
	akb_http_smoke_require(
		$ready,
		'Readiness HTTP no alcanzo 200 (status=' . (int) $last_ready['status'] . ', error=' . (string) $last_ready['error'] . ').'
	);

	$statuses       = array();
	$response_bytes = array();
	$response_sha   = array();
	$record         = static function ( string $name, array $response ) use ( &$statuses, &$response_bytes, &$response_sha ): string {
		akb_http_smoke_require( '' === (string) $response['error'], $name . ' fallo en cURL: ' . (string) $response['error'] );
		akb_http_smoke_require( 200 === (int) $response['status'], $name . ' devolvio HTTP ' . (int) $response['status'] . '.' );
		akb_http_smoke_require( (int) $response['bytes'] > 0, $name . ' devolvio cuerpo vacio.' );
		$statuses[ $name ]       = (int) $response['status'];
		$response_bytes[ $name ] = (int) $response['bytes'];
		$response_sha[ $name ]   = (string) $response['sha256'];
		return (string) $response['body'];
	};

	$suggest_body = $record(
		'suggest_one_piece',
		akb_http_smoke_fetch( $origin, $host_header, '/wp-json/akibara/v1/search/suggest', array( 'q' => 'one piece' ) )
	);
	$suggest      = akb_http_smoke_json( $suggest_body, 'suggest_one_piece' );
	akb_http_smoke_require( 'one piece' === ( $suggest['query'] ?? null ), 'Suggest no normalizo one piece.' );
	akb_http_smoke_require( is_int( $suggest['total'] ?? null ) && $suggest['total'] > 0, 'Suggest no entrego total entero positivo.' );
	$suggest_items = $suggest['items'] ?? null;
	akb_http_smoke_require(
		is_array( $suggest_items ) && count( $suggest_items ) > 0 && count( $suggest_items ) <= 7,
		'Suggest no entrego entre 1 y 7 items.'
	);
	foreach ( $suggest_items as $item ) {
		akb_http_smoke_require( is_array( $item ), 'Suggest contiene item invalido.' );
		foreach ( array( 'title', 'url', 'badge', 'badge_tone' ) as $key ) {
			akb_http_smoke_require( is_string( $item[ $key ] ?? null ) && '' !== $item[ $key ], 'Suggest omite ' . $key . '.' );
		}
	}

	$count_total = static function ( string $name, string $key, string $value ) use ( $origin, $host_header, $record ): int {
		$body    = $record(
			$name,
			akb_http_smoke_fetch( $origin, $host_header, '/wp-json/akibara/v1/search/count', array( $key => $value ) )
		);
		$payload = akb_http_smoke_json( $body, $name );
		akb_http_smoke_require( is_int( $payload['total'] ?? null ) && $payload['total'] >= 0, $name . ' no entrego total entero.' );
		return $payload['total'];
	};

	$opm_count        = $count_total( 'count_opm', '_buscar', 'one punch man' );
	$opm_alias_count  = $count_total( 'count_opm_alias', '_buscar', 'opm' );
	$opm_series_count = $count_total( 'count_opm_series', '_serie', 'one-punch-man' );
	$opm_volume_count = $count_total( 'count_opm_volume', '_buscar', 'one punch man 03' );
	$atelier_count    = $count_total( 'count_atelier', '_buscar', 'atelier' );
	$atalier_count    = $count_total( 'count_atalier', '_buscar', 'atalier' );
	$aot_count        = $count_total( 'count_aot', '_buscar', 'aot' );
	$aot_phrase_count = $count_total( 'count_aot_phrase', '_buscar', 'attack on titan' );

	akb_http_smoke_require(
		$opm_count > 0 && $opm_count === $opm_alias_count && $opm_count === $opm_series_count,
		'OPM no conserva paridad busqueda/alias/serie.'
	);
	akb_http_smoke_require(
		$opm_volume_count > 0 && $opm_volume_count < $opm_count,
		'El tomo no acota la busqueda OPM.'
	);
	akb_http_smoke_require(
		$atelier_count > 0 && $atelier_count === $atalier_count,
		'La correccion fuzzy atalier a atelier perdio paridad.'
	);
	akb_http_smoke_require(
		$aot_count > 0 && $aot_count === $aot_phrase_count,
		'El sinonimo AOT perdio limites de frase.'
	);

	$opm_suggest_body = $record(
		'suggest_opm',
		akb_http_smoke_fetch( $origin, $host_header, '/wp-json/akibara/v1/search/suggest', array( 'q' => 'one punch man' ) )
	);
	$opm_suggest      = akb_http_smoke_json( $opm_suggest_body, 'suggest_opm' );
	akb_http_smoke_require( ( $opm_suggest['total'] ?? null ) === $opm_count, 'Suggest OPM no conserva el total.' );
	$opm_items = $opm_suggest['items'] ?? null;
	akb_http_smoke_require( is_array( $opm_items ) && count( $opm_items ) > 0 && count( $opm_items ) <= 7, 'Suggest OPM no entrego items validos.' );
	foreach ( $opm_items as $item ) {
		akb_http_smoke_require(
			is_array( $item ) && is_string( $item['title'] ?? null ) && str_starts_with( $item['title'], 'One Punch-Man ' ),
			'Suggest OPM entrego un titulo fuera de serie.'
		);
	}

	$opm_shop = $record(
		'shop_opm',
		akb_http_smoke_fetch( $origin, $host_header, '/tienda/', array( '_buscar' => 'one punch man' ), 'text/html' )
	);
	akb_http_smoke_require( str_contains( $opm_shop, 'data-akb-unit="tomo"' ), 'Shop Manga no etiqueta tomos.' );

	$pack_shop = $record(
		'shop_pack',
		akb_http_smoke_fetch( $origin, $host_header, '/tienda/', array( '_buscar' => 'pack vinland saga' ), 'text/html' )
	);
	akb_http_smoke_require( str_contains( $pack_shop, '>1 tomo<' ), 'La regla pack=1 tomo no aparece en shop.' );

	$zero_shop = $record(
		'shop_zero',
		akb_http_smoke_fetch( $origin, $host_header, '/tienda/', array( '_buscar' => 'zzzzzzzzzzzz' ), 'text/html' )
	);
	akb_http_smoke_require(
		str_contains( $zero_shop, 'akb-shop-toolbar--empty' ) && str_contains( $zero_shop, 'class="akb-filter-toggle"' ),
		'La pagina cero resultados perdio toolbar o boton Filtrar.'
	);

	$receipt = array(
		'schema'          => AKB_HTTP_SMOKE_SCHEMA,
		'result'          => 'PASS',
		'origin_scope'    => 'docker-internal',
		'host_header'     => $host_header,
		'follow_redirects' => false,
		'published_ports' => 0,
		'script_sha256'   => $script_sha,
		'readiness'       => array(
			'attempts'        => $ready_attempt,
			'final_status'    => 200,
			'response_sha256' => (string) $last_ready['sha256'],
		),
		'counts'          => array(
			'opm'        => $opm_count,
			'opm_alias'  => $opm_alias_count,
			'opm_series' => $opm_series_count,
			'opm_volume' => $opm_volume_count,
			'atelier'    => $atelier_count,
			'atalier'    => $atalier_count,
			'aot'        => $aot_count,
			'aot_phrase' => $aot_phrase_count,
		),
		'checks'          => array(
			'suggest_one_piece'       => true,
			'opm_alias_series_parity' => true,
			'opm_volume_narrows'      => true,
			'atelier_fuzzy_parity'    => true,
			'aot_phrase_parity'       => true,
			'opm_titles'              => true,
			'shop_manga_unit'         => true,
			'pack_one_tomo'           => true,
			'zero_toolbar_filter'     => true,
		),
		'http_status'      => $statuses,
		'response_bytes'   => $response_bytes,
		'response_sha256'  => $response_sha,
	);
	$encoded = json_encode( $receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
	echo 'AKB_HTTP_SMOKE_RECEIPT=' . $encoded . PHP_EOL;
} catch ( Throwable $throwable ) {
	$failure = json_encode(
		array(
			'schema' => AKB_HTTP_SMOKE_SCHEMA,
			'result' => 'FAIL',
			'error'  => $throwable->getMessage(),
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
	fwrite( STDERR, 'AKB_HTTP_SMOKE_FAIL=' . ( is_string( $failure ) ? $failure : '{"result":"FAIL"}' ) . PHP_EOL );
	exit( 1 );
}
