<?php
/**
 * Estado de AUTORIDAD del precio editorial, compartido y request-local.
 *
 * Una sola instancia la comparten `Settings`, `DiscountResolver` y el proveedor
 * estricto: si cualquiera de ellos pierde la certeza, la pierden todos. El
 * estado es MONÓTONO a propósito — no existe "recuperarse a mitad de la
 * petición", porque un cache contaminado no se descontamina solo y un reintento
 * dentro del mismo request leería exactamente lo mismo. La recuperación es una
 * instancia nueva (petición nueva).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.99.15
 */
final class PriceAuthority {

	private bool $available = true;

	private string $error = '';

	/** Huella de la foto autoritativa adoptada, si hubo alguna. */
	private string $fingerprint = '';

	/**
	 * Epoch de invalidación: sube cada vez que se descartan los memos derivados.
	 * Entra en el token para que una foto vieja no pueda hacerse pasar por
	 * fresca aunque su huella coincida.
	 */
	private int $epoch = 0;

	public function available(): bool {
		return $this->available;
	}

	public function error(): string {
		return $this->error;
	}

	/**
	 * Marca la pérdida de autoridad conservando el PRIMER motivo: es el que
	 * explica la causa raíz; los siguientes suelen ser su consecuencia.
	 *
	 * @param string $why Motivo.
	 * @return void
	 */
	public function fail( string $why ): void {
		if ( $this->available ) {
			$this->available = false;
			$this->error     = $why;
		}
	}

	public function fingerprint(): string {
		return $this->fingerprint;
	}

	public function epoch(): int {
		return $this->epoch;
	}

	/** Invalida la foto adoptada y avanza el epoch. */
	public function invalidate(): void {
		++$this->epoch;
		$this->fingerprint = '';
	}

	/**
	 * Token opaco y estable de la foto vigente: huella + epoch. Es lo que el
	 * consumidor guarda para su final-check.
	 */
	public function token(): string {
		if ( '' === $this->fingerprint ) {
			return '';
		}

		return hash( 'sha256', $this->epoch . ':' . $this->fingerprint );
	}

	/**
	 * @param string $fingerprint Huella de la foto en uso.
	 * @return void
	 */
	public function adopt_fingerprint( string $fingerprint ): void {
		$this->fingerprint = $fingerprint;
	}
}
