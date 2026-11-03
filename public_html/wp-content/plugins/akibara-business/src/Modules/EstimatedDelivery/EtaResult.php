<?php
/**
 * Resultado del cálculo de ETA — value object inmutable.
 *
 * Tres estados posibles:
 *   1) Rango concreto (min_ts, max_ts) → renderer muestra "10 jun – 27 jun".
 *   2) TBA con lead time conocido → renderer muestra "Llega ~2 semanas tras
 *      su salida en Argentina". Aplica cuando el producto está en preventa
 *      sin fecha editorial confirmada PERO tiene brand mapeado a país con
 *      lead time configurado.
 *   3) TBA sin lead time → renderer muestra "Te avisamos en cuanto tengamos
 *      fecha". Fallback cuando el producto es TBA pero no hay brand resuelto
 *      o el país no tiene lead time configurado.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * Value object retornado por DateCalculator::calculate().
 *
 * @since 1.7.0
 */
final class EtaResult {

	/**
	 * Constructor privado — usar factories `range()`, `tba()` o `tba_with_lead()`.
	 *
	 * @param int|null    $min_ts           Timestamp mínimo (null si TBA).
	 * @param int|null    $max_ts           Timestamp máximo (null si TBA).
	 * @param bool        $tba              True si el producto es preventa sin fecha.
	 * @param int|null    $tba_min_days     Lead time mínimo en días (solo TBA con lead).
	 * @param int|null    $tba_max_days     Lead time máximo en días (solo TBA con lead).
	 * @param string|null $tba_country_name Nombre país origen (solo TBA con lead, opcional).
	 */
	private function __construct(
		private ?int $min_ts,
		private ?int $max_ts,
		private bool $tba,
		private ?int $tba_min_days = null,
		private ?int $tba_max_days = null,
		private ?string $tba_country_name = null
	) {}

	/**
	 * Crea un resultado con rango de fechas concretas.
	 *
	 * @param int $min_ts Timestamp mínimo.
	 * @param int $max_ts Timestamp máximo.
	 * @return self
	 */
	public static function range( int $min_ts, int $max_ts ): self {
		return new self( $min_ts, $max_ts, false );
	}

	/**
	 * Crea un resultado TBA sin lead time conocido. Renderer mostrará copy
	 * genérico ("Te avisamos en cuanto tengamos fecha").
	 *
	 * @return self
	 */
	public static function tba(): self {
		return new self( null, null, true );
	}

	/**
	 * Crea un resultado TBA con lead time conocido. Renderer mostrará copy
	 * con compromiso temporal ("Llega ~2 semanas tras su salida en Argentina").
	 *
	 * @param int         $min_days     Lead time mínimo en días (>= 1).
	 * @param int         $max_days     Lead time máximo en días (>= min_days).
	 * @param string|null $country_name Nombre país origen (e.g. "Argentina").
	 *                                  Null si la agregación de cart mezcla países.
	 * @return self
	 */
	public static function tba_with_lead( int $min_days, int $max_days, ?string $country_name = null ): self {
		return new self( null, null, true, $min_days, $max_days, $country_name );
	}

	/**
	 * Timestamp mínimo (null si TBA).
	 *
	 * @return int|null
	 */
	public function min_ts(): ?int {
		return $this->min_ts;
	}

	/**
	 * Timestamp máximo (null si TBA).
	 *
	 * @return int|null
	 */
	public function max_ts(): ?int {
		return $this->max_ts;
	}

	/**
	 * True si el producto es TBA (con o sin lead conocido).
	 *
	 * @return bool
	 */
	public function is_tba(): bool {
		return $this->tba;
	}

	/**
	 * True si es TBA Y tiene lead time conocido (renderer puede mostrar
	 * compromiso temporal en vez de copy genérico).
	 *
	 * @return bool
	 */
	public function has_tba_lead(): bool {
		return $this->tba && null !== $this->tba_min_days;
	}

	/**
	 * Lead time mínimo en días (null si no es TBA o no tiene lead).
	 *
	 * @return int|null
	 */
	public function tba_min_days(): ?int {
		return $this->tba_min_days;
	}

	/**
	 * Lead time máximo en días (null si no es TBA o no tiene lead).
	 *
	 * @return int|null
	 */
	public function tba_max_days(): ?int {
		return $this->tba_max_days;
	}

	/**
	 * Nombre país origen para el copy TBA (null si no aplica o si la
	 * agregación mezcla países).
	 *
	 * @return string|null
	 */
	public function tba_country_name(): ?string {
		return $this->tba_country_name;
	}
}
