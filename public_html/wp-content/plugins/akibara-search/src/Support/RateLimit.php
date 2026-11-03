<?php
/**
 * RateLimit — límite por IP/minuto para endpoints públicos del buscador
 * (autocomplete `/suggest`, `/count` del drawer). CF-aware, vía transients.
 *
 * Copia self-contained que ROMPE la dependencia con `Forms\RateLimit` de
 * akibara-business. Incluye SOLO lo que el buscador usa: `per_minute()`, el
 * contador atómico `bump()` que lo respalda y el resolvedor de IP CF-aware. El
 * `exceeded()` (3/hora, forms) NO se copia — sería código muerto en este plugin.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class RateLimit {

	/**
	 * Grupo de object cache del contador. Solo se usa cuando hay drop-in
	 * persistente: ahí wp_cache_incr() es atómico del lado de Redis/Memcached
	 * y no hace falta bajar a SQL.
	 */
	private const CACHE_GROUP = 'akb_rl';

	/**
	 * Rate-limit por ventana de 1 minuto, compatible con Cloudflare, para endpoints públicos
	 * de alta frecuencia. Actúa como defensa en profundidad a nivel de aplicación.
	 *
	 * La exención de rate-limit aplica exclusivamente a roles administrativos con
	 * capacidad `manage_woocommerce`. No se eximen usuarios autenticados genéricos
	 * para prevenir abusos de scraping desde cuentas cliente estándar.
	 *
	 * El contador se incrementa mediante `bump()` utilizando una sola escritura
	 * atómica (`INSERT ... ON DUPLICATE KEY UPDATE`) para evitar condiciones de
	 * carrera (read-modify-write) entre procesos PHP bajo alta concurrencia.
	 *
	 * @param string $bucket Namespace del límite (p.ej. 'suggest', 'count').
	 * @param int    $max    Máximo de requests en la ventana de 1 minuto.
	 * @return bool True si excedió (el caller debería 429/no-op).
	 */
	public static function per_minute( string $bucket, int $max ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		$minute = (int) floor( time() / MINUTE_IN_SECONDS );
		$key    = 'akb_rlm_' . hash( 'sha256', $bucket . '|' . self::client_ip() . '|' . $minute );

		return self::bump( $key, MINUTE_IN_SECONDS, $max ) > $max;
	}

	/**
	 * Incrementa el contador de `$key` y devuelve el valor YA incrementado.
	 *
	 * CALCO DELIBERADO de `Business\Modules\Forms\RateLimit::bump()`: este
	 * archivo es self-contained por decisión de diseño (ver el docblock de
	 * arriba) y el buscador NO puede depender de que akibara-business esté
	 * activo. **Si la mecánica del contador cambia hay que actualizar AMBAS
	 * copias.**
	 *
	 * Dos caminos, ambos de una sola escritura atómica:
	 *   - Con object cache persistente: wp_cache_add() (crea en 0 solo si no
	 *     existe) + wp_cache_incr(), atómicos en el servidor de caché. Si un
	 *     drop-in no implementa incr, cupos individuales creados con add()
	 *     conservan el límite exacto y expiran junto con el bucket.
	 *   - Sin drop-in: INSERT ... ON DUPLICATE KEY UPDATE sobre la fila
	 *     `_transient_<key>` de wp_options. Se escribe con FORMA de transient a
	 *     propósito: el cron diario de WP (delete_expired_transients) barre las
	 *     filas vencidas sin código de limpieza propio. La premisa se sostiene
	 *     SÓLO en esta rama — ese GC hace `return` temprano cuando hay object
	 *     cache externo (wp-includes/option.php), y el sitio ya tiene el drop-in
	 *     de LiteSpeed, así que hoy corre la rama de arriba y esta no escribe
	 *     nada. Las filas heredadas de antes del drop-in las purga la migración
	 *     de esquema de Install.
	 *
	 * La fila de timeout se escribe PRIMERO: un contador sin su timeout lo
	 * trataría WP como transient eterno y esa IP quedaría bloqueada para
	 * siempre. Al revés (timeout sin contador) el peor caso es contar de nuevo
	 * desde 0, que es la dirección segura.
	 *
	 * La key SIEMPRE incluye el bucket de tiempo (el minuto lo mete
	 * per_minute()), así una fila vencida no se relee nunca y la expiración no
	 * hay que resolverla dentro de la query.
	 *
	 * @param string $key Key ya namespaceada y hasheada (incluye el bucket).
	 * @param int    $ttl Vida del bucket en segundos.
	 * @param int    $limit Umbral que todavía puede pasar.
	 * @return int Contador después de este request (>= 1).
	 */
	private static function bump( string $key, int $ttl, int $limit ): int {
		if ( wp_using_ext_object_cache() ) {
			$blocked_at = max( 1, $limit + 1 );
			$marker_key = $key . ':slots';
			$marker     = wp_cache_get( $marker_key, self::CACHE_GROUP );

			if ( false !== $marker ) {
				return is_numeric( $marker )
					? self::claim_cache_slot( $key, $ttl, max( 0, (int) $marker ), $blocked_at )
					: $blocked_at;
			}

			wp_cache_add( $key, 0, self::CACHE_GROUP, $ttl );
			$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );

			/*
			 * Algunos drop-ins implementan add() pero no incr(). Devolver 1 en
			 * cada fallo hacía el límite evadible; bajar a transients SQL dejaría
			 * basura porque Core no los barre con object cache externo. El primer
			 * fallo publica primero un marcador `switching`, toma después el
			 * baseline alcanzado y cambia el bucket de forma irreversible a cupos
			 * efímeros creados con add(). Mientras el baseline se fija, cualquier
			 * request concurrente falla cerrado.
			 */
			if ( false === $count ) {
				$won_switch = wp_cache_add( $marker_key, 'switching', self::CACHE_GROUP, $ttl );
				$marker = wp_cache_get( $marker_key, self::CACHE_GROUP );
				if ( $won_switch ) {
					$known = wp_cache_get( $key, self::CACHE_GROUP );
					if ( 'switching' !== $marker || ! is_numeric( $known ) ) {
						return $blocked_at;
					}

					$baseline = max( 0, (int) $known );
					if ( ! wp_cache_set( $marker_key, $baseline, self::CACHE_GROUP, $ttl ) ) {
						return $blocked_at;
					}
					$marker = wp_cache_get( $marker_key, self::CACHE_GROUP );
				}

				return is_numeric( $marker )
					? self::claim_cache_slot( $key, $ttl, max( 0, (int) $marker ), $blocked_at )
					: $blocked_at;
			}

			/*
			 * Releer el marcador cierra la carrera con otro request que cambió a
			 * cupos mientras este incr estaba en vuelo. Si el baseline ya incluye
			 * el incremento, el count es válido; si quedó después, reclama cupo y
			 * el incremento del contador base se ignora desde ahora.
			 */
			$marker = wp_cache_get( $marker_key, self::CACHE_GROUP );
			if ( false === $marker ) {
				return max( 1, (int) $count );
			}
			if ( ! is_numeric( $marker ) ) {
				return $blocked_at;
			}

			$baseline = max( 0, (int) $marker );
			return (int) $count <= $baseline
				? max( 1, (int) $count )
				: self::claim_cache_slot( $key, $ttl, $baseline, $blocked_at );
		}

		global $wpdb;

		$value_row   = '_transient_' . $key;
		$timeout_row = '_transient_timeout_' . $key;

		// autoload 'off': el contador NUNCA debe entrar en alloptions. WP 6.6+
		// usa on/off y las versiones viejas solo autocargan 'yes', así que
		// 'off' es no-autoload en ambas eras.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- el contador del límite requiere escritura atómica directa sin pasar por el cache de options.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$wpdb->options} es el nombre canónico de tabla de WordPress; bucket y TTL viajan parametrizados.
				$timeout_row,
				(string) ( time() + $ttl )
			)
		);

		// CAST y no `option_value + 1`: la columna es LONGTEXT y en modo
		// estricto una entrada no numérica abortaría el UPDATE.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- el contador del límite requiere incremento atómico en base de datos.
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off')
				ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$wpdb->options} es la tabla de options; parámetro viaja por %s.
				$value_row
			)
		);

		// Relectura después del incremento: puede venir adelantada por otros
		// requests del mismo bucket, nunca atrasada respecto del propio. Ese
		// sesgo es el correcto para un límite (sobra-cuenta, no sub-cuenta).
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lectura en tiempo real del contador atómico.
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$wpdb->options} es el nombre de la tabla; parámetro por %s.
				$value_row
			)
		);

		return max( 1, $count );
	}

	/**
	 * Reclama un contador absoluto mediante llaves efímeras y add() atómico.
	 *
	 * El cupo `$blocked_at` representa a todos los requests bloqueados. Una vez
	 * creado sirve además como fast-path O(1), sin volver a recorrer el límite.
	 */
	private static function claim_cache_slot( string $key, int $ttl, int $baseline, int $blocked_at ): int {
		$blocked_key = $key . ':slot:' . $blocked_at;
		if ( false !== wp_cache_get( $blocked_key, self::CACHE_GROUP ) ) {
			return $blocked_at;
		}

		for ( $candidate = max( 1, $baseline + 1 ); $candidate <= $blocked_at; ++$candidate ) {
			$slot_key = $key . ':slot:' . $candidate;
			if ( wp_cache_add( $slot_key, 1, self::CACHE_GROUP, $ttl ) ) {
				return $candidate;
			}
		}

		return $blocked_at;
	}

	/**
	 * IP del cliente para el rate-limit, CF-aware. X-Forwarded-For / X-Real-IP
	 * NUNCA se confían (controlables por el atacante).
	 *
	 * CF-Connecting-IP solo se acepta cuando el peer TCP (`REMOTE_ADDR`) pertenece a un
	 * rango validado de Cloudflare; de lo contrario, se descarta para evitar spoofing.
	 *
	 * En caso de peer no reconocido o cabecera ausente, se utiliza estrictamente
	 * REMOTE_ADDR garantizando un bucket seguro y no eludible.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';

		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::is_trusted_proxy( $remote ) ) {
			$cf = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}

		return $remote;
	}

	/**
	 * ¿El peer TCP es un edge de Cloudflare (o un proxy declarado de confianza)?
	 *
	 * DUPLICADO A PROPÓSITO de `Business\Modules\Forms\RateLimit`: este archivo
	 * es self-contained por decisión de diseño (ver el docblock de arriba), y
	 * delegar en Business ataría la seguridad del buscador a que ese plugin esté
	 * activo. **Si Cloudflare cambia sus rangos hay que actualizar AMBAS copias**
	 * — o, mejor, usar el filtro `akibara_trusted_proxy_ranges`, que las dos
	 * respetan y permite corregirlo sin tocar código.
	 *
	 * @since 0.1.7
	 *
	 * @param string $ip IP del peer, ya validada.
	 * @return bool
	 */
	public static function is_trusted_proxy( string $ip ): bool {
		$ranges = array(
			// IPv4 — https://www.cloudflare.com/ips/
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			// IPv6.
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);

		/** Mismo filtro que expone Business — punto único de ajuste sin deploy. */
		try {
			$ranges = apply_filters( 'akibara_trusted_proxy_ranges', $ranges );
		} catch ( \Throwable $exception ) {
			return false; // nunca confiar CF-Connecting-IP ante un hook roto.
		}
		if ( ! is_array( $ranges ) || count( $ranges ) > 100 ) {
			return false;
		}

		foreach ( $ranges as $cidr ) {
			if ( ! is_string( $cidr ) || '' === $cidr || strlen( $cidr ) > 80 ) {
				return false;
			}
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Comparación IP ⊂ CIDR a nivel de bits (IPv4 e IPv6).
	 *
	 * @param string $ip   IP candidata.
	 * @param string $cidr Rango `dirección/prefijo`.
	 * @return bool
	 */
	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return false;
		}

		list( $subnet, $bits ) = explode( '/', $cidr, 2 );

		$ip_bin     = @inet_pton( $ip );     // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- IP ya validada.
		$subnet_bin = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false devuelto en CIDR mal formado es el camino previsto.
		$bits       = (int) $bits;

		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$max_bits = strlen( $ip_bin ) * 8;
		if ( $bits < 0 || $bits > $max_bits ) {
			return false;
		}

		$whole_bytes = intdiv( $bits, 8 );
		if ( $whole_bytes > 0 && strncmp( $ip_bin, $subnet_bin, $whole_bytes ) !== 0 ) {
			return false;
		}

		$remaining = $bits % 8;
		if ( 0 === $remaining ) {
			return true;
		}

		$mask = ~( ( 1 << ( 8 - $remaining ) ) - 1 ) & 0xFF;

		return ( ord( $ip_bin[ $whole_bytes ] ) & $mask ) === ( ord( $subnet_bin[ $whole_bytes ] ) & $mask );
	}
}
