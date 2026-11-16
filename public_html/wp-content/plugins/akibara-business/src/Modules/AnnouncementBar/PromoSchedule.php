<?php
/**
 * PromoSchedule — Resolución única de vigencias, horarios de corte y cuenta regresiva.
 *
 * Centraliza el cálculo horario de la barra de anuncios bajo `StoreTime::zone()`
 * (America/Santiago cuando WordPress está montado), delegando la comparación de
 * rangos a `TaxonomyDiscount\Settings::window_covers()` y leyendo la campaña
 * activa mediante `DiscountCopy::campaign_window()` (Single Responsibility / DIP).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AnnouncementBar;

use Akibara\Business\Modules\DiscountCopy\DiscountCopy;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 */
final class PromoSchedule {

	public const DEFAULT_CUTOFF_HM = '23:59';

	/**
	 * Valida estrictamente formato 24h `HH:MM` (00:00..23:59) sin desbordes.
	 */
	public static function is_valid_time_hm( string $raw ): bool {
		return 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', trim( $raw ) );
	}

	/**
	 * Normaliza un horario `HH:MM` al valor válido o cae al cierre de fábrica (`23:59`).
	 * @param mixed $raw
	 */
	public static function parse_time_hm( $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return self::DEFAULT_CUTOFF_HM;
		}

		$clean = trim( (string) $raw );

		return self::is_valid_time_hm( $clean ) ? $clean : self::DEFAULT_CUTOFF_HM;
	}

	/**
	 * Valida fecha de calendario real `Y-m-d` (rechaza e.g. `2026-02-31`).
	 * @param mixed $raw
	 */
	public static function parse_ymd( $raw ): ?string {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}

		$clean = trim( (string) $raw );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $clean, $m ) ) {
			return null;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $clean : null;
	}

	/**
	 * Formatea una fecha `Y-m-d` a estándar chileno legible (`dd/mm/aaaa`), o vacío si es inválida.
	 */
	public static function format_ymd_cl( string $ymd ): string {
		$valid = self::parse_ymd( $ymd );
		if ( null === $valid ) {
			return '';
		}

		$parts = explode( '-', $valid );

		return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
	}

	/**
	 * Devuelve el timestamp Unix exacto para el fin de la promoción en hora oficial de Chile.
	 */
	public static function closing_timestamp( string $date_ymd, string $time_hm = self::DEFAULT_CUTOFF_HM ): ?int {
		$valid_date = self::parse_ymd( $date_ymd );
		if ( null === $valid_date ) {
			return null;
		}

		$valid_hm = self::parse_time_hm( $time_hm );
		$zone     = StoreTime::zone();
		$dt       = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $valid_date . ' ' . $valid_hm . ':59', $zone );
		$errors   = \DateTimeImmutable::getLastErrors();

		if (
			false === $dt
			|| ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) )
			|| $dt->format( 'Y-m-d' ) !== $valid_date
		) {
			return null;
		}

		return $dt->getTimestamp();
	}

	/**
	 * Resuelve la época Unix límite del contador leyendo exclusivamente la campaña activa de Descuentos.
	 */
	public static function countdown_target_epoch( string $cutoff_hm = self::DEFAULT_CUTOFF_HM ): ?int {
		$end_ymd = DiscountCopy::campaign_end_date();
		if ( '' === $end_ymd ) {
			return null;
		}

		return self::closing_timestamp( $end_ymd, $cutoff_hm );
	}

	/**
	 * Época Unix en que vence por completo la campaña (23:59:59 del último día en hora Chile).
	 */
	public static function campaign_end_epoch(): ?int {
		$end_ymd = DiscountCopy::campaign_end_date();
		if ( '' === $end_ymd ) {
			return null;
		}

		return self::closing_timestamp( $end_ymd, self::DEFAULT_CUTOFF_HM );
	}
}
