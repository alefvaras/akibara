<?php
/**
 * StoreTime — la zona horaria de la tienda, en un solo lugar.
 *
 * El idioma `function_exists( 'wp_timezone') ? wp_timezone(): new
 * DateTimeZone( 'UTC')` estaba copiado diez veces en la unidad de
 * venta-catálogo, y las copias ya no decían lo mismo:
 * algunas caían a UTC, otras a null, y ReminderEmail llamaba `wp_timezone()`
 * sin guarda —o sea, fatal en cualquier bootstrap sin WordPress cargado, que
 * es exactamente el de la suite.
 *
 * El fallback a UTC NO es cosmético: el servidor corre en UTC y la tienda en
 * America/Santiago, así que después de las 20:00 CL la fecha comparada sería
 * la del día siguiente y una preventa caducaría cuatro horas antes de tiempo.
 * Cuando WordPress no está cargado, UTC es la única respuesta honesta.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.1
 */
final class StoreTime {

	/**
	 * Zona horaria de la tienda; UTC si WordPress no está disponible.
	 */
	public static function zone(): DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		return new DateTimeZone( 'UTC' );
	}

	/**
	 * Medianoche de hoy en la zona de la tienda: el corte con el que una
	 * promesa pasa de «llega» a «llegaba» y una fecha de salida de «anuncio»
	 * a «ya pasó». Estaba copiado en cuatro sitios (cartel, rastreador,
	 * listener, columna) y cada copia podía divergir.
	 *
	 * @since 1.114.16
	 *
	 * @param int|null $now Instante de referencia (tests). null = ahora.
	 * @return int
	 */
	public static function start_of_today( ?int $now = null ): int {
		$hoy = new \DateTimeImmutable( '@' . ( $now ?? time() ) );

		return $hoy->setTimezone( self::zone() )->setTime( 0, 0, 0 )->getTimestamp();
	}

	/**
	 * La fecha de hoy como `Y-m-d` en la zona de la tienda.
	 *
	 * @since 1.114.16
	 *
	 * @param int|null $now Instante de referencia (tests). null = ahora.
	 * @return string
	 */
	public static function today_ymd( ?int $now = null ): string {
		return ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( self::zone() )->format( 'Y-m-d' );
	}
}
