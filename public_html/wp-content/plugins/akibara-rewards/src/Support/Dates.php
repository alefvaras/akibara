<?php
/**
 * Dates — Clase utilitaria centralizada y canónica de fechas para Akibara.
 *
 * Estandariza el manejo, parseo y presentación de fechas y marcas de tiempo
 * con respecto a la zona horaria oficial del sitio (`wp_timezone()`), eliminando
 * discrepancias entre `date()`, `date_i18n()`, `wp_date()` y `strtotime()`.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.37
 * @api
 */
final class Dates {

	public const MYSQL_DATETIME_FORMAT   = 'Y-m-d H:i:s';
	public const DISPLAY_DATE_FORMAT     = 'd/m/Y';
	public const DISPLAY_DATETIME_FORMAT = 'd/m/Y H:i';

	/** @var array<int, string> */
	public const MESES_ES = array(
		1  => 'enero',
		2  => 'febrero',
		3  => 'marzo',
		4  => 'abril',
		5  => 'mayo',
		6  => 'junio',
		7  => 'julio',
		8  => 'agosto',
		9  => 'septiembre',
		10 => 'octubre',
		11 => 'noviembre',
		12 => 'diciembre',
	);

	/** @var array<int, string> */
	public const MESES_CORTOS_ES = array(
		1  => 'Ene',
		2  => 'Feb',
		3  => 'Mar',
		4  => 'Abr',
		5  => 'May',
		6  => 'Jun',
		7  => 'Jul',
		8  => 'Ago',
		9  => 'Sep',
		10 => 'Oct',
		11 => 'Nov',
		12 => 'Dic',
	);

	/**
	 * Zona horaria canónica del sitio (América/Santiago si no está en entorno WP).
	 *
	 * @return \DateTimeZone
	 */
	public static function timezone(): \DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			try {
				$tz = wp_timezone();
				if ( $tz instanceof \DateTimeZone ) {
					return $tz;
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Fallback si wp_timezone falla.
			}
		}

		return new \DateTimeZone( 'America/Santiago' );
	}

	/**
	 * Convierte cualquier entrada de fecha (timestamp, string MySQL, DateTime) a \DateTimeImmutable en la zona del sitio.
	 *
	 * @param int|string|\DateTimeInterface|null $date Entrada de fecha.
	 * @return \DateTimeImmutable
	 */
	public static function to_datetime( $date = null ): \DateTimeImmutable {
		$tz = self::timezone();

		if ( null === $date || '' === $date ) {
			return new \DateTimeImmutable( 'now', $tz );
		}

		if ( $date instanceof \DateTimeImmutable ) {
			return $date->setTimezone( $tz );
		}

		if ( $date instanceof \DateTimeInterface ) {
			return ( new \DateTimeImmutable( '@' . $date->getTimestamp() ) )->setTimezone( $tz );
		}

		if ( is_int( $date ) ) {
			return ( new \DateTimeImmutable( '@' . $date ) )->setTimezone( $tz );
		}

		if ( is_string( $date ) ) {
			$clean = trim( $date );
			if ( 1 === preg_match( '/^-?\d+$/', $clean ) ) {
				return ( new \DateTimeImmutable( '@' . $clean ) )->setTimezone( $tz );
			}

			$parsed = self::parse_or_null( $clean );
			if ( null !== $parsed ) {
				return $parsed;
			}

			$parsed_date = self::parse_or_null( $clean, 'Y-m-d' );
			if ( null !== $parsed_date ) {
				return $parsed_date;
			}

			try {
				return ( new \DateTimeImmutable( $clean, $tz ) )->setTimezone( $tz );
			} catch ( \Exception $e ) {
				unset( $e );
				return new \DateTimeImmutable( 'now', $tz );
			}
		}

		return new \DateTimeImmutable( 'now', $tz );
	}

	/**
	 * Convierte cualquier entrada de fecha (timestamp, string MySQL, DateTime) a timestamp entero.
	 *
	 * Parsea siempre cadenas sin sufijo de zona horaria usando la zona oficial del sitio
	 * (`wp_timezone()`), evitando el desfase de 3-4h respecto al reloj UTC de PHP.
	 *
	 * @param int|string|\DateTimeInterface|null $date Entrada de fecha.
	 * @return int Timestamp Unix (o time() si es nulo/inválido).
	 */
	public static function to_timestamp( $date = null ): int {
		if ( null === $date || '' === $date ) {
			return time();
		}

		if ( is_int( $date ) ) {
			return $date;
		}

		if ( $date instanceof \DateTimeInterface ) {
			return $date->getTimestamp();
		}

		return self::to_datetime( $date )->getTimestamp();
	}

	/**
	 * Formatea una fecha usando `wp_date()` con la zona horaria del sitio.
	 *
	 * @param int|string|\DateTimeInterface|null $date   Entrada de fecha.
	 * @param string                             $format Formato PHP para fechas.
	 * @return string
	 */
	public static function format( $date = null, string $format = self::DISPLAY_DATE_FORMAT ): string {
		$dt = self::to_datetime( $date );

		if ( function_exists( 'wp_date' ) ) {
			try {
				$formatted = wp_date( $format, $dt->getTimestamp(), self::timezone() );
				if ( is_string( $formatted ) && '' !== $formatted ) {
					return $formatted;
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Fallback si wp_date falla.
			}
		}

		return $dt->format( $format );
	}

	/**
	 * Fecha y hora MySQL canónica actual en la zona horaria del sitio («AAAA-MM-DD HH:MM:SS»).
	 *
	 * @return string
	 */
	public static function now_db(): string {
		if ( function_exists( 'current_time' ) ) {
			try {
				$ct = current_time( 'mysql' );
				if ( is_string( $ct ) && '' !== $ct ) {
					return $ct;
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Fallback si current_time falla.
			}
		}

		return self::to_datetime()->format( self::MYSQL_DATETIME_FORMAT );
	}

	/**
	 * Alias de compatibilidad para now_db().
	 *
	 * @return string
	 */
	public static function mysql_now(): string {
		return self::now_db();
	}

	/**
	 * Fecha humana completa en español (ej. «9 de noviembre de 2026»).
	 *
	 * @param int|string|\DateTimeInterface|null $date Entrada de fecha.
	 * @return string
	 */
	public static function format_human( $date = null ): string {
		$dt         = self::to_datetime( $date );
		$dia        = (int) $dt->format( 'j' );
		$mes        = (int) $dt->format( 'n' );
		$anio       = (int) $dt->format( 'Y' );
		$nombre_mes = self::MESES_ES[ $mes ] ?? $dt->format( 'F' );

		return sprintf( '%d de %s de %d', $dia, $nombre_mes, $anio );
	}

	/**
	 * Alias de compatibilidad para format_human().
	 *
	 * @param int|string|\DateTimeInterface|null $date Entrada de fecha.
	 * @return string
	 */
	public static function human_full( $date = null ): string {
		return self::format_human( $date );
	}

	/**
	 * Fecha humana corta (ej. «9 Nov 2026»).
	 *
	 * @param int|string|\DateTimeInterface|null $date Entrada de fecha.
	 * @return string
	 */
	public static function format_short( $date = null ): string {
		$dt        = self::to_datetime( $date );
		$dia       = (int) $dt->format( 'j' );
		$mes       = (int) $dt->format( 'n' );
		$anio      = (int) $dt->format( 'Y' );
		$mes_corto = self::MESES_CORTOS_ES[ $mes ] ?? $dt->format( 'M' );

		return sprintf( '%d %s %d', $dia, $mes_corto, $anio );
	}

	/**
	 * Alias de compatibilidad para format_short().
	 *
	 * @param int|string|\DateTimeInterface|null $date Entrada de fecha.
	 * @return string
	 */
	public static function human( $date = null ): string {
		return self::format_short( $date );
	}

	/**
	 * Fecha y hora administrativa estándar (ej. «09/11/2026 15:30»).
	 *
	 * @param int|string|\DateTimeInterface|null $date Entrada de fecha.
	 * @return string
	 */
	public static function admin_datetime( $date = null ): string {
		return self::format( $date, self::DISPLAY_DATETIME_FORMAT );
	}

	/**
	 * Parsea una cadena de fecha a DateTimeImmutable en la zona horaria del sitio, o retorna null si es inválida.
	 *
	 * Resetea campos no presentes en el formato (ej. hora `00:00:00` en formato `Y-m-d`) para evitar
	 * arrastrar la hora actual del reloj del servidor.
	 *
	 * @param string|null $val    Texto de fecha (o null).
	 * @param string      $format Formato esperado (default 'Y-m-d H:i:s').
	 * @return \DateTimeImmutable|null
	 */
	public static function parse_or_null( ?string $val, string $format = self::MYSQL_DATETIME_FORMAT ): ?\DateTimeImmutable {
		if ( null === $val || '' === trim( $val ) ) {
			return null;
		}

		$clean_val      = trim( $val );
		$tz             = self::timezone();
		$parse_format   = 0 === strpos( $format, '!' ) ? $format : '!' . $format;
		$compare_format = ltrim( $format, '!|+' );
		$dt             = \DateTimeImmutable::createFromFormat( $parse_format, $clean_val, $tz );

		if ( ! $dt instanceof \DateTimeImmutable ) {
			return null;
		}

		$errors = \DateTimeImmutable::getLastErrors();
		if ( is_array( $errors ) && ( ( $errors['warning_count'] ?? 0 ) > 0 || ( $errors['error_count'] ?? 0 ) > 0 ) ) {
			return null;
		}

		return $dt->format( $compare_format ) === $clean_val ? $dt : null;
	}

	/**
	 * Alias de compatibilidad para parse_or_null().
	 *
	 * @param string $val    Texto de fecha.
	 * @param string $format Formato esperado.
	 * @return \DateTimeImmutable|null
	 */
	public static function parse( string $val, string $format = self::MYSQL_DATETIME_FORMAT ): ?\DateTimeImmutable {
		return self::parse_or_null( $val, $format );
	}

	/**
	 * Calcula la distancia absoluta en días enteros (intervalos de 24h) entre dos fechas ($from y $to).
	 * Si $to es null, toma el momento actual en la zona horaria del sitio.
	 *
	 * Nota: Si se requieren días calendario exactos independientemente de la hora, normalice ambas
	 * fechas previamente con `->setTime(0, 0)` (como en Expiry).
	 *
	 * @param int|string|\DateTimeInterface|null $from Fecha inicial.
	 * @param int|string|\DateTimeInterface|null $to   Fecha final (null para ahora).
	 * @return int Días de diferencia absolutos.
	 */
	public static function diff_days( $from, $to = null ): int {
		$dt_from = self::to_datetime( $from );
		$dt_to   = self::to_datetime( $to );

		return (int) $dt_from->diff( $dt_to )->days;
	}
}
