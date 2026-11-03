<?php
/** Invalida exclusivamente las copias de la portada en Cloudflare. @package Akibara\Rewards */
declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Log;

defined( 'ABSPATH' ) || exit;

final class HomeCache {

	public const TAG  = 'akb_reviews_home_v1';
	public const HOOK = 'akb_rewards_home_cache_retry';
	private static bool $queued = false;

	public static function register(): void {
		add_filter( 'wp_headers', array( self::class, 'tag_headers' ) );
		add_action( self::HOOK, array( self::class, 'purge' ), 10, 1 );
	}

	/** @param array<string,string> $headers Cabeceras de WordPress. @return array<string,string> */
	public static function tag_headers( array $headers ): array {
		if ( is_front_page() ) {
			$headers['Cache-Tag'] = isset( $headers['Cache-Tag'] ) ? $headers['Cache-Tag'] . ',' . self::TAG : self::TAG;
		}
		return $headers;
	}

	/** Una sola purga por petición, después de invalidar el origen. */
	public static function queue_purge(): void {
		if ( self::$queued || null === self::credentials() ) {
			return;
		}
		self::$queued = true;
		add_action( 'shutdown', array( self::class, 'purge' ), 30 );
	}

	/** @return array{zone:string,key:string,email:string}|null */
	private static function credentials(): ?array {
		// El espejo puede contener opciones copiadas: nunca debe llamar al borde real.
		if ( 'https://akibara.cl' !== rtrim( (string) get_option( 'home', '' ), '/' ) || 'production' !== wp_get_environment_type()
			|| ! get_option( 'litespeed.conf.cdn-cloudflare', false ) ) {
			return null;
		}
		$zone = (string) get_option( 'litespeed.conf.cdn-cloudflare_zone', '' );
		$key  = (string) get_option( 'litespeed.conf.cdn-cloudflare_key', '' );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $zone ) || '' === $key ) {
			return null;
		}
		return array( 'zone' => $zone, 'key' => $key, 'email' => (string) get_option( 'litespeed.conf.cdn-cloudflare_email', '' ) );
	}

	/** @param int|string $attempt Reintento acotado; sin URL ni credencial en la cola. */
	public static function purge( int|string $attempt = 0 ): void {
		$attempt = (int) $attempt;
		$access = self::credentials();
		if ( null === $access ) {
			return;
		}
		$global = 0 === strpos( $access['key'], 'cfk_' ) || 1 === preg_match( '/^[0-9a-f]{37,45}$/', $access['key'] );
		$headers = array( 'Content-Type' => 'application/json' );
		if ( $global ) {
			$headers['X-Auth-Email'] = $access['email'];
			$headers['X-Auth-Key']   = $access['key'];
		} else {
			$headers['Authorization'] = 'Bearer ' . $access['key'];
		}
		$response = wp_remote_post(
			'https://api.cloudflare.com/client/v4/zones/' . $access['zone'] . '/purge_cache',
			array( 'timeout' => 8, 'headers' => $headers, 'body' => (string) wp_json_encode( array( 'tags' => array( self::TAG ) ) ) )
		);
		$body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && ! empty( $body['success'] ) ) {
			return;
		}
		// No registrar la respuesta externa: puede contener datos de autenticación.
		Log::error( 'No se pudo actualizar la caché pública de reseñas de la portada.' );
		if ( $attempt < 2 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 75, self::HOOK, array( $attempt + 1 ), 'akibara-rewards', true );
		}
	}
}
