<?php
/**
 * Proveedor estricto del precio editorial que Search materializa.
 *
 * El storefront puede volver al precio base ante una lectura no autoritativa;
 * este proveedor lanza una excepción: el índice no debe sellar un precio
 * supuesto como si estuviera verificado.
 *
 * Search captura la configuración y la entrega por adopt_search_snapshot().
 * Business valida y adopta esa misma foto sin releerla por su cuenta, para
 * mantener autoridad compartida y presupuesto de consultas.
 *
 * La máquina de estados de petición pasa EMPTY → PENDING(A) → ACTIVE(A).
 * FAILED es terminal. Invocar sin foto adoptada lanza. Search exige los métodos
 * públicos del callback efectivo de akb_search_effective_price, con prioridad10
 * y accepted_args2, sin un filtro puente reemplazable. PriceAuthorityUnavailable
 * define el contrato de fallo para el consumidor.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.99.14
 */
final class SearchPriceProvider {

	public const STATE_EMPTY   = 'EMPTY';
	public const STATE_PENDING = 'PENDING';
	public const STATE_ACTIVE  = 'ACTIVE';
	public const STATE_FAILED  = 'FAILED';

	private DiscountResolver $resolver;

	private string $state = self::STATE_EMPTY;

	private ?AdoptedSnapshot $snapshot = null;

	private string $token = '';

	/**
	 * Epoch request-local y monótono. Entra en el token para que una foto vieja
	 * no pueda hacerse pasar por fresca aunque su contenido coincida (ABA).
	 */
	private int $epoch = 0;

	/**
	 * Captura la generación de autoridad al adoptar la foto.
	 *
	 * PENDING aún no tiene un resolver ligado. Conservar esta generación hace
	 * observable una invalidación entre adopt() y el primer __invoke(), para que
	 * snapshot_is_fresh() no acepte una configuración que ya fue descartada.
	 */
	private int $generation = -1;

	/**
	 * Reloj inyectable. Entra por CONSTRUCTOR y no por parámetro: la firma
	 * pública `adopt_search_snapshot( array): string` está sellada y el
	 * discovery de Search la verifica exactamente.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * @param DiscountResolver $resolver Resolver.
	 * @param callable|null    $clock    Reloj `fn(): int`; por defecto `time()`.
	 */
	public function __construct( DiscountResolver $resolver, ?callable $clock = null ) {
		$this->resolver = $resolver;
		$this->clock    = $clock ?? static fn(): int => time();
	}

	/** Instante actual según el reloj inyectado. */
	private function now(): int {
		return (int) ( $this->clock )();
	}

	/**
	 * API PÚBLICA — Search entrega su foto ya capturada; Business la valida y la
	 * adopta como `PENDING`. No siembra el lector ni activa el modo estricto:
	 * eso ocurre en el primer `__invoke()`.
	 *
	 * Idempotente dentro del mismo epoch: adoptar dos veces la MISMA foto
	 * devuelve el mismo token. Una foto DISTINTA después de la primera adopción
	 * falla sticky — cambiar de configuración a mitad de operación es
	 * exactamente lo que este contrato existe para impedir.
	 *
	 * @param array<string, mixed> $snapshot Foto de Search.
	 * @return string Token.
	 *
	 * @throws PriceAuthorityUnavailable Si la foto es inválida o contradice la vigente.
	 */
	public function adopt_search_snapshot( array $snapshot ): string {
		if ( self::STATE_FAILED === $this->state ) {
			throw new PriceAuthorityUnavailable( 'La foto de precio ya falló en esta petición y no revive.' );
		}

		$why     = '';
		$adopted = AdoptedSnapshot::from_search( $snapshot, $why, $this->now() );

		if ( null === $adopted ) {
			$this->fail( 'Foto de Search rechazada: ' . $why );
		}

		$json = $adopted->canonical_json();
		if ( '' === $json ) {
			$this->fail( 'La foto de Search no se pudo serializar de forma canónica.' );
		}

		if ( self::STATE_EMPTY === $this->state ) {
			++$this->epoch;
			$this->snapshot   = $adopted;
			$this->token      = $this->token_for( $json );
			$this->generation = $this->resolver->authority_generation();
			$this->state      = self::STATE_PENDING;

			return $this->token;
		}

		// Ya hay foto: sólo se acepta la MISMA, y su token no cambia.
		if ( ! hash_equals( $this->token, $this->token_for( $json ) ) ) {
			$this->fail( 'Se intentó adoptar una foto distinta de la ya vigente en esta petición.' );
		}

		// …y sobre la MISMA autoridad. Readoptar después de una invalidación
		// devolvería un token que ya no describe nada.
		if ( ! $this->generation_is_intact() ) {
			$this->fail( 'La autoridad se invalidó después de adoptar la foto vigente.' );
		}

		return $this->token;
	}

	/**
	 * API PÚBLICA — final-check.
	 *
	 * Exige un token `PENDING`/`ACTIVE` y, en `ACTIVE`, que el resolver siga
	 * ligado a esa misma foto. **No relee la base**: Search compara además su
	 * foto fresca final contra `authoritative_snapshot()`.
	 *
	 * @param string $expected_token Token devuelto por `adopt_search_snapshot()`.
	 * @return bool
	 */
	public function snapshot_is_fresh( string $expected_token ): bool {
		if ( '' === $expected_token || null === $this->snapshot ) {
			return false;
		}

		if ( self::STATE_PENDING !== $this->state && self::STATE_ACTIVE !== $this->state ) {
			return false;
		}

		if ( ! hash_equals( $this->token, $expected_token ) ) {
			return false;
		}

		// La generación se exige en PENDING **y** en ACTIVE: es la única señal
		// que existe antes de que haya vínculo.
		if ( ! $this->generation_is_intact() ) {
			return false;
		}

		if ( self::STATE_ACTIVE === $this->state && ! $this->resolver->is_bound_to( $this->snapshot ) ) {
			return false;
		}

		return $this->resolver->is_authoritative() && ! $this->boundary_crossed();
	}

	/**
	 * API PÚBLICA — la foto vigente, para comparación literal contra la de
	 * Search. No relee nada.
	 *
	 * @return array<string, mixed>
	 */
	public function authoritative_snapshot(): array {
		return null === $this->snapshot ? array() : $this->snapshot->data();
	}

	/**
	 * Token vigente, o cadena vacía.
	 *
	 * Ningún módulo lo llama en runtime: es el observable de los gates cruzados
	 * search↔business (`tools/akibara-search-business/tests/PriceSnapshotIntegrationTest.php`
	 * y `akibara-search/tests/live-write-ledger-integration.php`), que comparan
	 * el token capturado por Search con el que el proveedor tiene vigente. Seam
	 * de pruebas deliberado, no API pública (R10).
	 */
	public function snapshot_token(): string {
		return $this->token;
	}

	/** Estado de la máquina: EMPTY | PENDING | ACTIVE | FAILED. */
	public function state(): string {
		return $this->state;
	}

	/** ¿El precio entregado en esta petición fue autoritativo? */
	public function is_authoritative(): bool {
		return self::STATE_FAILED !== $this->state && $this->resolver->is_authoritative();
	}

	/**
	 * @param mixed       $base    Precio base que trae el filtro.
	 * @param \WC_Product $product Producto que Search está indexando.
	 * @return float
	 *
	 * @throws PriceAuthorityUnavailable Si no hay foto adoptada o el precio no es autoritativo.
	 */
	public function __invoke( $base, $product ): float {
		if ( self::STATE_FAILED === $this->state ) {
			throw new PriceAuthorityUnavailable( 'La foto de precio falló en esta petición y no revive.' );
		}

		if ( null === $this->snapshot ) {
			$this->fail( 'El feed pidió un precio sin haber adoptado la foto de Search.' );
		}

		if ( ! $product instanceof \WC_Product ) {
			$this->fail( 'El feed de Search pidió el precio efectivo sin un WC_Product.' );
		}

		if ( $this->boundary_crossed() ) {
			$this->fail( 'Se cruzó boundary_at: la vigencia de la foto expiró durante la operación.' );
		}

		// El vínculo se verifica al ENTRAR: otro actor pudo invalidar los memos
		// entre dos invocaciones y el precio se estaría calculando con otra
		// configuración bajo el mismo token.
		if ( ! $this->generation_is_intact() ) {
			$this->fail( 'La autoridad se invalidó después de adoptar la foto: la foto ya no describe la configuración vigente.' );
		}

		if ( self::STATE_ACTIVE === $this->state && ! $this->resolver->is_bound_to( $this->snapshot ) ) {
			$this->fail( 'El resolver dejó de estar ligado a la foto adoptada.' );
		}

		try {
			if ( self::STATE_PENDING === $this->state ) {
				// Primer uso: recién acá el resolver queda ligado a la foto
				// adoptada y entra en modo estricto.
				$this->resolver->bind_adopted_snapshot( $this->snapshot );
				$this->state = self::STATE_ACTIVE;
			}

			$discounted = $this->resolver->resolve_strict( $product );
		} catch ( \Throwable $e ) {
			// Todo Throwable pasa por fail(), incluso PriceAuthorityUnavailable emitido
			// por un getter o filtro ajeno. Ningún fallo de lectura puede conservar al
			// proveedor ACTIVE y autoritativo para reintentar bajo el mismo token.

			$this->fail( 'El cálculo del precio editorial lanzó ' . get_class( $e ) . ': ' . $e->getMessage(), $e );
		}

		if ( ! $this->resolver->is_authoritative() ) {
			$this->fail(
				sprintf(
					'Precio editorial no autoritativo para el producto %d: %s',
					$product->get_id(),
					$this->resolver->authority_error()
				)
			);
		}

		// …y al SALIR: nada pudo soltar el vínculo ni invalidar la autoridad
		// durante el cálculo.
		if ( ! $this->resolver->is_bound_to( $this->snapshot ) ) {
			$this->fail( 'El resolver perdió el vínculo con la foto durante el cálculo.' );
		}

		if ( ! $this->generation_is_intact() ) {
			$this->fail( 'La autoridad se invalidó durante el cálculo del precio.' );
		}

		return null !== $discounted ? (float) $discounted : (float) $base;
	}

	/**
	 * Token ABA-safe: `sha256( epoch. NUL. json_canónico)`.
	 *
	 * El separador NUL impide que un epoch y un JSON distintos produzcan la
	 * misma concatenación que otro par.
	 *
	 * @param string $canonical_json JSON canónico de la foto.
	 * @return string
	 */
	private function token_for( string $canonical_json ): string {
		return hash( 'sha256', $this->epoch . "\0" . $canonical_json );
	}

	/** ¿La autoridad sigue siendo la misma generación que se adoptó? */
	private function generation_is_intact(): bool {
		return $this->generation === $this->resolver->authority_generation();
	}

	/** ¿La vigencia declarada por la foto ya expiró? */
	private function boundary_crossed(): bool {
		if ( null === $this->snapshot ) {
			return false;
		}

		$boundary = $this->snapshot->boundary_at();

		return null !== $boundary && $this->now() >= $boundary;
	}

	/**
	 * Pasa a `FAILED` —estado del que no se vuelve— y lanza.
	 *
	 * @param string $why Motivo.
	 * @return void
	 *
	 * @throws PriceAuthorityUnavailable Siempre.
	 */
	private function fail( string $why, ?\Throwable $previous = null ): void {
		$this->state = self::STATE_FAILED;
		$this->resolver->mark_authority_failed( $why );

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- el mensaje va a una excepción interna que nadie imprime al cliente; se registra en el AuditLog.
		throw new PriceAuthorityUnavailable( $why, 0, $previous );
	}
}
