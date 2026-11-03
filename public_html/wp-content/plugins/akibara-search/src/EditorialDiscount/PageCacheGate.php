<?php
/**
 * Control y limitación de caché de página (LiteSpeed y cabeceras HTTP).
 *
 * @package Akibara\Search\EditorialDiscount
 */

declare( strict_types = 1 );

namespace Akibara\Search\EditorialDiscount;

use Akibara\Search\EditorialDiscountPublication;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Gating y TTL de caché pública para respuestas HTTP.
 *
 * Extraído de EditorialDiscountPublication para aislar el protocolo de caché
 * de la máquina de estados de indexación.
 */
final class PageCacheGate {

	/**
	 * Vida máxima que este plugin autoriza para una respuesta de página.
	 */
	public const PAGE_CACHE_TTL = 3600;

	/**
	 * La ficha de producto vive hasta la medianoche de la tienda, menos este margen.
	 */
	public const PRODUCT_PAGE_MIDNIGHT_MARGIN = 60;

	/**
	 * Franja ciega antes de un borde de ventana editorial (12 horas).
	 */
	public const PAGE_CACHE_BOUNDARY_HORIZON = 43200;

	/**
	 * Limita el TTL de caché de página para la respuesta actual.
	 *
	 * @param EditorialDiscountPublication $publication
	 */
	public static function cap_page_cache_ttl( EditorialDiscountPublication $publication ): void {
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return;
		}

		// (1) Superficie pública, sin estado por usuario y con espacio de claves acotado.
		if ( ! self::page_surface_is_publicly_cacheable() ) {
			self::mark_page_no_store( 'akibara page surface is not publicly cacheable' );
			return;
		}

		// (2) La lease resuelve con autoridad: el materializado está publicado.
		$token = EditorialDiscountPublication::capture_serving_token();
		if ( null === $token ) {
			self::mark_page_no_store( 'akibara editorial materialization closed' );
			return;
		}

		// (3) y (4) Ventana cerrada AHORA y su próximo borde fuera del horizonte.
		$ttl = self::authorized_page_cache_ttl( EditorialDiscountPublication::get_serving_capture_window() );
		if ( null === $ttl ) {
			self::mark_page_no_store( 'akibara editorial window is active, its boundary is inside the cache horizon, or the store day ends within the midnight margin' );
			return;
		}

		$publication->set_page_publication_token( $token );
		self::register_page_finalize( $publication );

		try {
			// LiteSpeed bufferea la página entera y emite headers en `shutdown:0`.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed.
			do_action( 'litespeed_control_set_ttl', $ttl, 'akibara editorial publication lease' );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			self::mark_page_no_store( 'akibara could not cap the page cache ttl' );
		}
	}

	/**
	 * Segunda mitad del gate: revalida la lease justo antes de que LiteSpeed decida almacenar.
	 *
	 * @param EditorialDiscountPublication $publication
	 * @param mixed                        $esi
	 */
	public static function finalize_page_cache( EditorialDiscountPublication $publication, $esi = false ): void {
		unset( $esi );
		$token = $publication->get_page_publication_token();
		if ( null === $token || ! EditorialDiscountPublication::serving_token_matches( $token, 'page_cache' ) ) {
			self::mark_page_no_store( 'akibara publication changed in-flight' );
		}
	}

	/**
	 * Engancha la revalidación final una sola vez por request.
	 *
	 * @param EditorialDiscountPublication $publication
	 */
	public static function register_page_finalize( EditorialDiscountPublication $publication ): void {
		if ( $publication->is_page_finalize_registered() || ! function_exists( 'add_action' ) ) {
			return;
		}
		$publication->set_page_finalize_registered( true );
		add_action( 'litespeed_control_finalize', array( $publication, 'finalize_page_cache' ), 10, 1 );
	}

	/**
	 * TTL autorizado para esta respuesta, o null si no se autoriza almacenarla.
	 *
	 * @param array<string, mixed>|null $window Ventana capturada con el token.
	 * @return int|null
	 */
	public static function authorized_page_cache_ttl( ?array $window ): ?int {
		if ( ! is_array( $window ) || ! array_key_exists( 'active', $window )
			|| ! array_key_exists( 'boundary_at', $window ) || ! is_bool( $window['active'] ) ) {
			return null;
		}

		// Campaña viva: el precio editorial es filter-only.
		if ( true === $window['active'] ) {
			return null;
		}

		$now     = time();
		$ceiling = self::page_cache_ceiling( $window, $now );
		if ( null === $ceiling ) {
			return null;
		}

		$boundary = $window['boundary_at'];
		if ( null === $boundary ) {
			return $ceiling;
		}
		if ( ! is_int( $boundary ) ) {
			return null;
		}

		$remaining = $boundary - $now;
		if ( $remaining <= self::PAGE_CACHE_BOUNDARY_HORIZON ) {
			return null;
		}

		$ttl = min( $ceiling, $remaining - self::PRODUCT_PAGE_MIDNIGHT_MARGIN );
		return $ttl > 0 ? $ttl : null;
	}

	/**
	 * Techo de vida de ESTA respuesta, antes de mirar el borde.
	 *
	 * @param array<string, mixed> $window Ventana capturada con el token.
	 * @param int                  $now    Marca de tiempo actual.
	 * @return int|null
	 */
	public static function page_cache_ceiling( array $window, int $now ): ?int {
		if ( ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
			return self::PAGE_CACHE_TTL;
		}
		$zone = $window['timezone'] ?? null;
		if ( ! is_string( $zone ) || '' === $zone ) {
			return self::PAGE_CACHE_TTL;
		}
		return self::seconds_before_store_midnight( $now, $zone );
	}

	/**
	 * Segundos desde `$now` hasta la próxima medianoche de la zona, menos el margen.
	 *
	 * @param int    $now  Marca de tiempo actual.
	 * @param string $zone Zona de la tienda (`America/Santiago` o `-03:00`).
	 * @return int|null
	 */
	public static function seconds_before_store_midnight( int $now, string $zone ): ?int {
		try {
			$tz       = new DateTimeZone( $zone );
			$midnight = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->modify( 'tomorrow' );
		} catch ( Throwable $throwable ) {
			return null;
		}
		$seconds = $midnight->getTimestamp() - $now - self::PRODUCT_PAGE_MIDNIGHT_MARGIN;
		return $seconds > 0 ? $seconds : null;
	}

	/**
	 * Superficies que este plugin admite almacenar públicamente.
	 *
	 * @return bool
	 */
	public static function page_surface_is_publicly_cacheable(): bool {
		try {
			foreach ( array( 'DOING_AJAX', 'DOING_CRON', 'REST_REQUEST', 'XMLRPC_REQUEST', 'WP_CLI', 'DOING_AUTOSAVE', 'IFRAME_REQUEST' ) as $flag ) {
				if ( defined( $flag ) && constant( $flag ) ) {
					return false;
				}
			}
			$method = isset( $_SERVER['REQUEST_METHOD'] )
				? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
				: '';
			if ( 'GET' !== $method && 'HEAD' !== $method ) {
				return false;
			}

			$query_string = isset( $_SERVER['QUERY_STRING'] )
				? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) )
				: '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo se mide SI vienen parámetros; ningún valor se lee.
			if ( '' !== $query_string || ( isset( $_GET ) && is_array( $_GET ) && array() !== $_GET ) ) {
				return false;
			}
			if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
				return false;
			}
			if ( self::request_carries_private_cookie() ) {
				return false;
			}

			foreach ( array( 'is_preview', 'is_customize_preview', 'post_password_required', 'is_cart', 'is_checkout', 'is_account_page' ) as $predicate ) {
				if ( function_exists( $predicate ) && true === $predicate() ) {
					return false;
				}
			}
			return true;
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Cookies que delatan estado por usuario.
	 *
	 * @return bool
	 */
	public static function request_carries_private_cookie(): bool {
		if ( ! isset( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
			return false;
		}
		$private = array(
			'wordpress_logged_in_',
			'wp-postpass_',
			'comment_author_',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'wp_woocommerce_session_',
			'akb_',
		);
		foreach ( array_keys( $_COOKIE ) as $name ) {
			foreach ( $private as $prefix ) {
				if ( is_string( $name ) && 0 === strpos( $name, $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * No-store de página resistente a callbacks de cache de terceros.
	 *
	 * @param string $reason
	 */
	public static function mark_page_no_store( string $reason ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- nombre estándar que leen LiteSpeed y los demás cachés.
			define( 'DONOTCACHEPAGE', true );
		}
		try {
			if ( function_exists( 'nocache_headers' ) ) {
				nocache_headers();
			}
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed.
			do_action( 'litespeed_control_set_nocache', $reason );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}
	}
}
