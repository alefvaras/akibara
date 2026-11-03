<?php
/**
 * FilterSet — parsea los query params `_*` a selecciones de faceta (clase PURA).
 *
 * Gramática de URL: `?_facet=valor`, prefijo `_`, multi-valor por coma (`?_categoria=shonen,seinen`).
 *
 * `_paged` no es una faceta: es la paginación y se procesa por separado.
 * `_buscar` es el texto de búsqueda (consumido por QueryEngine).
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class FilterSet {

	/** Param reservado: texto de búsqueda. */
	public const PARAM_SEARCH = '_buscar';

	/** Param reservado: página. */
	public const PARAM_PAGED = '_paged';

	/**
	 * Cota máxima de valores por faceta en URL (`MAX_VALUES_PER_FACET = 200`):
	 * protege la consulta principal contra inyecciones de parámetros masivos
	 * que sobrecarguen las cláusulas `IN` y la resolución de términos.
	 */
	private const MAX_VALUES_PER_FACET = 200;

	/** @var array<string, array<int, string>> facet => valores seleccionados. */
	private array $selections;

	/**
	 * Contexto IMPLÍCITO del archive (p.ej. /categoria-producto/manga/ ⇒
	 * categoria=manga). NO es una selección del usuario: participa de los JOINs
	 * de counts/filtrado vía joined(), pero no genera chips, no marca checkboxes
	 * (values() lo excluye) y no cuenta para has_facets() — así apply() nunca
	 * convierte un archive plano en dependiente del índice (fail-open).
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $context = array();

	/** @var string Texto de búsqueda (vacío si no hay). */
	private string $search;

	/** @var int Página (>= 1). */
	private int $paged;

	/**
	 * @param array<string, array<int, string>> $selections facet => valores.
	 * @param string                             $search     Texto de búsqueda.
	 * @param int                                $paged      Página.
	 */
	public function __construct( array $selections, string $search = '', int $paged = 1 ) {
		$this->selections = $selections;
		$this->search     = $search;
		$this->paged      = max( 1, $paged );
	}

	/**
	 * Construye desde el array crudo de query vars (típicamente `$_GET`).
	 * Solo acepta facetas conocidas (whitelist del registry) — un param `_x`
	 * arbitrario no crea una faceta fantasma.
	 *
	 * Las facetas en `$single_select` (las de `ui=radio`) se normalizan a un único valor
	 * para garantizar validez en el marcado del control y consistencia en conteos y chips.
	 *
	 * @param array<string, mixed> $query         Query vars crudas.
	 * @param array<int, string>   $known_facets  Nombres de faceta válidos.
	 * @param array<int, string>   $single_select Facetas de selección única (ui=radio).
	 * @return self
	 */
	public static function from_query( array $query, array $known_facets, array $single_select = array() ): self {
		$known      = array_flip( $known_facets );
		$single     = array_flip( $single_select );
		$selections = array();

		foreach ( $query as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key || '_' !== $key[0] ) {
				continue;
			}
			if ( self::PARAM_SEARCH === $key || self::PARAM_PAGED === $key ) {
				continue;
			}
			$facet = substr( $key, 1 );
			if ( ! isset( $known[ $facet ] ) ) {
				continue;
			}

			$values = self::split_values( $value );
			if ( isset( $single[ $facet ] ) && count( $values ) > 1 ) {
				$values = array( $values[0] ); // faceta radio: gana el primer valor.
			}
			if ( ! empty( $values ) ) {
				$selections[ $facet ] = $values;
			}
		}

		$search = isset( $query[ self::PARAM_SEARCH ] ) ? trim( (string) $query[ self::PARAM_SEARCH ] ) : '';
		$paged  = isset( $query[ self::PARAM_PAGED ] ) ? (int) $query[ self::PARAM_PAGED ] : 1;

		return new self( $selections, $search, $paged );
	}

	/**
	 * Valores de una faceta (vacío si no está seleccionada).
	 *
	 * @param string $facet Faceta.
	 * @return array<int, string>
	 */
	public function values( string $facet ): array {
		return $this->selections[ $facet ] ?? array();
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function all(): array {
		return $this->selections;
	}

	/**
	 * @return bool
	 */
	public function has_facets(): bool {
		return ! empty( $this->selections );
	}

	/**
	 * @return string
	 */
	public function search(): string {
		return $this->search;
	}

	/**
	 * @return bool
	 */
	public function has_search(): bool {
		return '' !== $this->search;
	}

	/**
	 * @return int
	 */
	public function paged(): int {
		return $this->paged;
	}

	/**
	 * Copia sin la faceta dada — el set base para contar las opciones de esa
	 * faceta ("todas las facetas MENOS X"), preservando búsqueda, página y
	 * contexto (el contexto del archive NO es except-self-able: el usuario no
	 * puede des-seleccionarlo, así que siempre acota la base).
	 *
	 * @param string $facet Faceta a excluir.
	 * @return self
	 */
	public function without( string $facet ): self {
		$copy = $this->selections;
		unset( $copy[ $facet ] );
		$clone          = new self( $copy, $this->search, $this->paged );
		$clone->context = $this->context;
		return $clone;
	}

	/**
	 * Copia con el término del archive como contexto implícito de la faceta.
	 *
	 * @param string $facet Faceta dueña del término (p.ej. 'categoria').
	 * @param string $value Slug del término del archive.
	 * @return self
	 */
	public function with_context( string $facet, string $value ): self {
		$clone          = new self( $this->selections, $this->search, $this->paged );
		$clone->context = array( $facet => array( $value ) );
		return $clone;
	}

	/**
	 * @return bool
	 */
	public function has_context(): bool {
		return ! empty( $this->context );
	}

	/**
	 * Faceta dueña del contexto del archive (null si no hay contexto).
	 *
	 * @return string|null
	 */
	public function context_facet(): ?string {
		return empty( $this->context ) ? null : (string) array_key_first( $this->context );
	}

	/**
	 * Selecciones para construir JOINs: explícitas ∪ contexto. El contexto solo
	 * aplica si su faceta no tiene selección explícita (la selección del usuario
	 * manda; evita dos JOINs AND sobre la misma faceta = intersección vacía).
	 *
	 * @return array<string, array<int, string>>
	 */
	public function joined(): array {
		$out = $this->selections;
		foreach ( $this->context as $facet => $values ) {
			if ( ! isset( $out[ $facet ] ) ) {
				$out[ $facet ] = $values;
			}
		}
		return $out;
	}

	/**
	 * Divide un valor crudo (string con comas, o array) en valores limpios.
	 *
	 * @param mixed $value Valor crudo.
	 * @return array<int, string>
	 */
	private static function split_values( $value ): array {
		$parts = is_array( $value ) ? $value : explode( ',', (string) $value );

		// Recorte ANTES de sanitizar: `sanitize_title()` no es gratis y el
		// objetivo es que la URL no gobierne ni el nº de placeholders del `IN`
		// ni el nº de queries de `normalize_filters()`.
		$parts = array_slice( $parts, 0, self::MAX_VALUES_PER_FACET );

		$out = array();
		foreach ( $parts as $part ) {
			$clean = sanitize_title( (string) $part );
			if ( '' !== $clean ) {
				$out[ $clean ] = true; // dedup preservando primer orden.
			}
		}

		// Normalización explícita a string: PHP convierte a entero las claves numéricas de array
		// (ej. tramos de precio u oferta). Se fuerza `strval` para asegurar cumplimiento
		// del contrato de tipo estricto en `FacetService::valid_facet_value()`.
		return array_map( 'strval', array_keys( $out ) );
	}
}
