<?php
/**
 * RestCache — política de caché para los endpoints REST públicos del motor de búsqueda
 * (`/suggest`, `/popular`, `/count`).
 *
 * Emite directivas estrictas de `no-store` en todas las respuestas para garantizar
 * paridad inmediata de precios, descuentos y estados de stock sin riesgo de servir
 * snapshots obsoletos desde capas intermedias o navegadores.
 *
 * Blindaje multi-nivel de caché:
 *   - Origen / Navegador: `Cache-Control: no-store, max-age=0`.
 *   - CDN / Cloudflare Edge: `Cloudflare-CDN-Cache-Control: no-store` y `CDN-Cache-Control: no-store`
 *     para asegurar que reglas de borde en proxies no almacenen respuestas dinámicas.
 *   - LiteSpeed Cache: hook `litespeed_control_set_nocache`.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search\Support;

use Akibara\Search\Install;
use Throwable;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.9
 */
final class RestCache {

	/** Tag LiteSpeed de las respuestas cacheadas (add en respuesta, purge en escritura del índice). */
	public const TAG = 'akb_search_rest';

	/** Receipt durable del último dispatch local de purge por generation target. */
	public const OPTION_PURGE_RECEIPT = 'akb_search_rest_purge_receipt';

	/**
	 * Segundos recomendados en cabecera `Retry-After` ante respuestas transitorias 503 (`index_unavailable`).
	 *
	 * Base para el backoff exponencial coordinado en clientes JS (`search-suggest.js`, `akb-search.js`),
	 * permitiendo absorber de forma transparente ventanas breves de reconciliación o recarga de índices.
	 */
	public const RETRY_AFTER_SECONDS = 1;

	/**
	 * Aplica la política de no-almacenamiento en respuestas REST exitosas.
	 *
	 * @param WP_REST_Response $response Respuesta a marcar.
	 * @return void
	 */
	public static function apply( WP_REST_Response $response ): void {
		self::no_store( $response );
	}

	/**
	 * Anuncia que un 503 del gate es transitorio y cuándo reintentar.
	 *
	 * Sólo para `index_unavailable`. Un 429 NO la lleva: el rate-limit no se
	 * arregla reintentando, y los clientes no reintentan un 429.
	 *
	 * @param WP_REST_Response $response Respuesta 503 del gate.
	 * @return void
	 */
	public static function retry_after( WP_REST_Response $response ): void {
		$response->header( 'Retry-After', (string) self::RETRY_AFTER_SECONDS );
	}

	/**
	 * Marca una respuesta como NO cacheable (429 del rate-limit, `/track`).
	 *
	 * @param WP_REST_Response $response Respuesta a marcar.
	 * @return void
	 */
	public static function no_store( WP_REST_Response $response ): void {
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		// Cabeceras explícitas de borde para asegurar que proxies y CDNs intermedios
		// (Cloudflare, etc.) no almacenen en caché respuestas dinámicas de búsqueda.
		$response->header( 'Cloudflare-CDN-Cache-Control', 'no-store' );
		$response->header( 'CDN-Cache-Control', 'no-store' );

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed: es SU control de caché; con prefijo propio no lo escucharía nadie.
			do_action( 'litespeed_control_set_nocache', 'akb-search rest no-store' );
		} catch ( Throwable $throwable ) {
			// Cache-Control ya quedó aplicado. Un callback de optimización no puede
			// convertir una respuesta 503/429/204 en fatal ni borrar el no-store.
		}
	}

	/**
	 * Despacha la purga y persiste un receipt idempotente por generation target.
	 *
	 * El ACK confirma exclusivamente que el hook local `litespeed_purge` fue
	 * despachado y que ese hecho quedó persistido. No alcanza a NINGÚN nodo edge:
	 * sólo `litespeed_purge_all` propaga a Cloudflare, y escalar a eso aquí
	 * vaciaría el borde entero en cada escritura live (ver cabecera del fichero).
	 * La frescura del borde se sostiene con el `no-store` de CDN de `no_store()`,
	 * no con esta purga.
	 *
	 * @param string|null $generation_target Generación exacta; null usa la vigente.
	 * @return bool True sólo si existe receipt durable verificable.
	 */
	public static function purge( ?string $generation_target = null ): bool {
		$generation_target = null === $generation_target
			? (string) get_option( Install::OPTION_INDEX_GEN, '' )
			: $generation_target;
		if ( '' === $generation_target ) {
			return false;
		}
		if ( self::has_purge_receipt( $generation_target ) ) {
			return true;
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed: es SU purga; con prefijo propio no lo escucharía nadie.
			do_action( 'litespeed_purge', self::TAG );
		} catch ( Throwable $throwable ) {
			return false;
		}

		$receipt = array(
			'protocol'          => 1,
			'generation_target' => $generation_target,
			'tag'               => self::TAG,
			'dispatched_at'     => time(),
			'ack_scope'         => 'local_dispatch_only',
		);
		update_option( self::OPTION_PURGE_RECEIPT, $receipt, false );

		$persisted = self::has_purge_receipt( $generation_target );
		return $persisted;
	}

	/**
	 * @param string $generation_target Generación esperada.
	 * @return bool
	 */
	public static function has_purge_receipt( string $generation_target ): bool {
		$receipt = get_option( self::OPTION_PURGE_RECEIPT, null );
		return is_array( $receipt )
			&& 1 === (int) ( $receipt['protocol'] ?? 0 )
			&& $generation_target === (string) ( $receipt['generation_target'] ?? '' )
			&& self::TAG === (string) ( $receipt['tag'] ?? '' )
			&& 'local_dispatch_only' === (string) ( $receipt['ack_scope'] ?? '' )
			&& (int) ( $receipt['dispatched_at'] ?? 0 ) > 0;
	}
}
