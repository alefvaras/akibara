<?php
/**
 * Series Bundles — frontera común de los POST públicos de compra múltiple.
 *
 * El builder de ficha y el riel de landing escriben en el mismo carrito y se
 * sirven desde HTML cacheable. Por eso comparten acá dos invariantes que no
 * deben divergir: validación exacta de origen y normalización acotada de IDs.
 * La autorización de catálogo sigue en `CartSeriesCollector`; esta clase no
 * decide stock, serie, precio ni descuento.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

defined( 'ABSPATH' ) || exit;

final class PublicBatchRequest {

	/**
	 * Normaliza `product_ids[]` con techo ANTES y después del trabajo lineal.
	 *
	 * Una selección con más entradas que el techo se marca `truncated` para que el
	 * handler la rechace ANTES de abrir el carrito. No es seguro procesar sólo el
	 * prefijo y luego afirmar un conjunto exacto de reintento para IDs que nunca se
	 * validaron. `count()` detecta el overflow sin recorrer un POST arbitrario.
	 *
	 * @param int $max Máximo de IDs únicos que procesará el endpoint.
	 * @return array{ids:array<int,int>,truncated:bool}
	 */
	public static function posted_product_ids( int $max ): array {
		$max = max( 1, $max );
		// Los handlers llaman `check_ajax_referer()` antes de este helper. Mantener
		// la verificación allí permite que ficha y landing usen nonces distintos.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- los handlers llaman check_ajax_referer() antes de este helper, para que ficha y landing puedan usar nonces distintos; la exención se cierra cuatro líneas abajo.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida como array, se acota y cada valor pasa por absint debajo.
		$posted    = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? wp_unslash( $_POST['product_ids'] ) : array();
		$truncated = count( $posted ) > $max;
		$raw       = array_slice( $posted, 0, $max );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// `$raw` ya viene acotado a `$max`, así que `$ids` —único y filtrado— no
		// puede excederlo: recortarlo otra vez era un no-op.
		return array(
			'ids'       => array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) ),
			'truncated' => $truncated,
		);
	}

	/**
	 * Chequeo exacto de mismo origen para endpoints públicos cacheables.
	 *
	 * `fetch(..., same-origin)` declara Origin en estos POST. Como no existe un
	 * cliente legítimo del módulo que deba omitir ambas señales, su ausencia falla
	 * cerrada. Se compara origen completo normalizado (scheme + host + puerto
	 * efectivo), no sólo host: `http://`, `https://` y un puerto alternativo son
	 * orígenes distintos para el navegador y también para esta frontera.
	 *
	 * El nonce de invitado embebido en HTML cacheado no es por sí solo una
	 * credencial por sesión, de modo que cada handler suma además rate-limit y
	 * validación completa del producto antes de escribir.
	 */
	public static function origin_allowed(): bool {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ORIGIN'] ) )
			: '';
		if ( '' === $origin && isset( $_SERVER['HTTP_REFERER'] ) ) {
			$origin = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) );
		}
		if ( '' === $origin ) {
			return false;
		}

		$declared = self::normalized_origin( $origin );
		$site     = self::normalized_origin( (string) home_url( '/' ) );

		return null !== $declared && null !== $site && hash_equals( $site, $declared );
	}

	/** Origen canónico `scheme://host:port`, con puerto por defecto explícito. */
	private static function normalized_origin( string $url ): ?string {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		if ( ! is_string( $scheme ) || ! is_string( $host ) || '' === $scheme || '' === $host ) {
			return null;
		}

		$scheme = strtolower( $scheme );
		$host   = strtolower( $host );
		if ( ! is_int( $port ) ) {
			if ( 'https' === $scheme ) {
				$port = 443;
			} elseif ( 'http' === $scheme ) {
				$port = 80;
			} else {
				return null;
			}
		}

		return $scheme . '://' . $host . ':' . $port;
	}
}
