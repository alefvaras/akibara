<?php
/**
 * RateLimit — limita submissions por IP via transients.
 *
 * Vive en `Utilities` y no en `Modules\Forms` porque hace rato dejó de ser cosa
 * del formulario de contacto: lo consumen también BackInStock (endpoint REST
 * público) y SeriesBundles (dos endpoints del armador de packs).
 *
 * Default: 3 submissions / hora por IP por tipo de form.
 * Sin dependencias externas (Redis/etc) — wp_options/object cache es suficiente
 * para volúmenes bajos esperados de un sitio de e-commerce nicho.
 *
 * El contador se incrementa SIEMPRE por bump(): una sola escritura atómica.
 * El par get_transient()+set_transient() que había antes era un read-modify-write
 * entre procesos PHP distintos y el límite se evadía con concurrencia
 *.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

final class RateLimit {

	private const WINDOW_SECONDS = HOUR_IN_SECONDS;
	private const MAX_PER_WINDOW = 3;

	/**
	 * Topes por tipo de formulario, cuando 3/hora no es el número correcto.
	 *
	 * `bis` («avísame cuando llegue») subió a 12 el  con un caso real
	 * detrás: un cliente se anotó a Jujutsu Kaisen 26, 28 y 29 en el MISMO
	 * segundo y ahí se paró. El 27 existe, está agotado y no lo pidió — el tope
	 * le cortó a mitad de la serie y esa reposición se quedó sin avisar. Pedir
	 * aviso de varios tomos seguidos no es una anomalía: es exactamente lo que
	 * hace un coleccionista al que le falta media colección.
	 *
	 * Sigue habiendo tope porque el endpoint es público y manda un correo de
	 * confirmación: 12/hora acota igual el «subscription bombing» contra un
	 * tercero, y ese correo lleva baja de un clic. El de `contacto` se queda en
	 * 3: ahí el mensaje llega a una bandeja humana y el abuso cuesta más caro.
	 */
	private const MAX_POR_TIPO = array(
		'bis' => 12,
	);

	/**
	 * Grupo de object cache del contador. Solo se usa cuando hay drop-in
	 * persistente: ahí wp_cache_incr() es atómico del lado de Redis/Memcached
	 * y no hace falta bajar a SQL.
	 */
	private const CACHE_GROUP = 'akb_rl';

	/**
	 * Indica si la IP excede la cuota del formulario e incrementa su contador.
	 *
	 * Sólo se exime al personal con manage_woocommerce. Una cuenta de cliente sigue
	 * sujeta a la cuota por IP. La ventana fija incorpora el bucket horario en la
	 * clave y permite incremento atómico; acepta el burst de frontera entre dos
	 * horas consecutivas. Debe llamarse una sola vez por envío de formulario.
	 */
	public static function exceeded( string $kind ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		$max    = self::MAX_POR_TIPO[ $kind ] ?? self::MAX_PER_WINDOW;
		$window = (int) floor( time() / self::WINDOW_SECONDS );
		$key    = 'akb_rl_' . hash( 'sha256', $kind . '|' . self::client_ip() . '|' . $window );

		return self::bump( $key, self::WINDOW_SECONDS, $max ) > $max;
	}

	/**
	 * Rate-limit por ventana de 1 MINUTO, CF-aware, para endpoints públicos de
	 * alta frecuencia (autocomplete, /count del drawer, /track). A diferencia de
	 * exceeded() (3/hora, forms), acá el límite y el bucket son parametrizables.
	 * Side-effect: incrementa; llamar UNA vez por request. El escudo real contra
	 * scraping sigue siendo la regla de Cloudflare del runbook de cutover; esto
	 * es defensa-en-profundidad in-app.
	 *
	 * Exentos SOLO admin/staff (manage_woocommerce), igual que exceeded(): decía
	 * "logueados exentos (admin QA)" pero eximía a CUALQUIER logueado, y con el
	 * registro abierto + Google Login cualquier visitante se hacía cuenta y
	 * quedaba fuera del límite. Un cliente real tecleando
	 * no se acerca a los topes vigentes (60/min de suggest, 120/min de count).
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
	 * Es el corazón del límite: get_transient()+set_transient() dejaba una
	 * ventana read-modify-write entre procesos PHP distintos, y N requests
	 * concurrentes leían todos el mismo valor y pasaban todos.
	 *
	 * Dos caminos, ambos de una sola escritura atómica:
	 *   - Con object cache persistente —el caso REAL del sitio: LiteSpeed dejó
	 *     su `object-cache.php` en `wp-content/`—: wp_cache_add() (crea en 0
	 *     solo si no existe) + wp_cache_incr(), atómicos en el servidor de
	 *     caché. Si un drop-in no implementa incr, cupos individuales creados
	 *     con add() conservan el límite exacto y expiran junto con el bucket.
	 *   - Sin drop-in: INSERT... ON DUPLICATE KEY UPDATE sobre la fila
	 *     `_transient_<key>` de wp_options. Se escribe con FORMA de transient a
	 *     propósito: el cron diario de WP (delete_expired_transients) barre las
	 *     filas vencidas sin código de limpieza propio.
	 *
	 * La fila de timeout se escribe PRIMERO: un contador sin su timeout lo
	 * trataría WP como transient eterno y esa IP quedaría bloqueada para
	 * siempre. Al revés (timeout sin contador) el peor caso es contar de nuevo
	 * desde 0, que es la dirección segura.
	 *
	 * La key SIEMPRE incluye el bucket de tiempo, así una fila vencida no se
	 * relee nunca y la expiración no hay que resolverla dentro de la query.
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
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$timeout_row,
				(string) ( time() + $ttl )
			)
		);

		// CAST y no `option_value + 1`: la columna es LONGTEXT y en modo
		// estricto una basura no numérica abortaría el UPDATE.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off')
				ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
				$value_row
			)
		);

		// Relectura después del incremento: puede venir adelantada por otros
		// requests del mismo bucket, nunca atrasada respecto del propio. Ese
		// sesgo es el correcto para un límite (sobra-cuenta, no sub-cuenta).
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
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
	 * IP del cliente para el rate-limit. El sitio corre detrás de Cloudflare
	 * (origin Hostinger): CF setea/sobrescribe CF-Connecting-IP en el origen.
	 * X-Real-IP / X-Forwarded-For NO los sanitiza CF → son 100% controlables por
	 * el atacante y NUNCA se confían.
	 *
	 * CF-Connecting-IP SOLO se acepta cuando el peer TCP (REMOTE_ADDR) está en
	 * un rango publicado de Cloudflare. Sin esa verificación, cualquiera que
	 * alcance el origen por IP mandaba un CF-Connecting-IP distinto por request
	 * y estrenaba bucket cada vez — es decir, rate-limit evadible sobre los
	 * endpoints públicos (contacto → wp_mail, back-in-stock, búsqueda). La
	 * premisa "el tráfico se fuerza por CF" es configuración de red, no una
	 * garantía del código.
	 *
	 * Sin header CF (o con peer no-Cloudflare) se usa REMOTE_ADDR, que apunta
	 * al edge real y no es spoofeable por el cliente. Peor caso: todo colapsa a
	 * un bucket → el límite es más estricto, nunca evadible.
	 *
	 * OJO — no es una sola fuente de verdad, y decirlo así fue el error: el
	 * plugin akibara-search tiene su PROPIA copia de `client_ip()`,
	 * `is_trusted_proxy()` e `ip_in_cidr()` en `Support\RateLimit`, porque viaja
	 * en otro riel de despliegue y no puede depender de que Business esté
	 * activo. Lo que sí está unificado son los RANGOS: los dos aplican el filtro
	 * `akibara_trusted_proxy_ranges` y Business los siembra (ver
	 * `seed_trusted_proxy_ranges()`), así que la lista de Cloudflare se mantiene
	 * en UN lugar y la copia del buscador queda como respaldo si Business no
	 * está.
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
	 * Rangos publicados de Cloudflare (https://www.cloudflare.com/ips/).
	 *
	 * Ésta es la lista CANÓNICA del proyecto: el buscador tiene su propia copia
	 * del limitador —viaja en otro riel y no puede depender de que Business esté
	 * activo— pero consume estos rangos por el filtro compartido, así que
	 * actualizar Cloudflare se hace acá y en un solo lugar.
	 *
	 * @var array<int, string>
	 */
	public const CLOUDFLARE_RANGES = array(
		// IPv4.
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

	/**
	 * Siembra los rangos canónicos en el filtro compartido.
	 *
	 * Se engancha desde `Plugin::register_modules()`. El buscador aplica el
	 * MISMO filtro sobre su copia local, así que con Business activo los dos
	 * limitadores confían exactamente en la misma lista; sin Business, la copia
	 * del buscador sigue funcionando con su respaldo.
	 *
	 * Prioridad 5 —antes que el default 10— para que un ajuste del sitio pueda
	 * seguir sumando o quitando rangos encima.
	 *
	 * @param mixed $ranges Rangos que traía el filtro (se descartan: acá vive el dueño).
	 * @return array<int, string>
	 */
	public static function seed_trusted_proxy_ranges( $ranges ): array {
		unset( $ranges );

		return self::CLOUDFLARE_RANGES;
	}

	/**
	 * ¿El peer TCP es un edge de Cloudflare (o un proxy declarado de confianza)?
	 *
	 * Rangos publicados en https://www.cloudflare.com/ips/ — cambian muy rara
	 * vez; el filtro `akibara_trusted_proxy_ranges` permite ajustarlos (o sumar
	 * un balanceador propio) sin tocar código.
	 *
	 * @since 1.98.0
	 *
	 * @param string $ip IP del peer, ya validada.
	 * @return bool
	 */
	public static function is_trusted_proxy( string $ip ): bool {
		/**
		 * Filtra los rangos CIDR cuyos requests pueden declarar la IP real del
		 * cliente por cabecera.
		 *
		 * @since 1.98.0
		 *
		 * @param array<int, string> $ranges Rangos CIDR de confianza.
		 */
		$ranges = apply_filters( 'akibara_trusted_proxy_ranges', self::CLOUDFLARE_RANGES );
		if ( ! is_array( $ranges ) ) {
			return false;
		}

		foreach ( $ranges as $cidr ) {
			if ( is_string( $cidr ) && self::ip_in_cidr( $ip, $cidr ) ) {
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

		$ip_bin     = @inet_pton( $ip );      // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- IP ya validada; el silenciador cubre familias no soportadas.
		$subnet_bin = @inet_pton( $subnet );
		$bits       = (int) $bits;

		// Familias distintas (v4 vs v6) → longitudes distintas: nunca coinciden.
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
