<?php
/**
 * Doble de `wpdb` compartido por las pruebas de la vitrina de series.
 *
 * Vivía dentro de `HomeSeriesDestacadasTest.php` y `CacheDistinguePorCountTest`
 * lo usaba sin cargarlo: corrida sola caía con «Class not found» (2026-09-19).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\HomeSeriesDestacadas;

/** Doble de `wpdb` para el agregado de `SeriesCatalogEligibility`. */
final class HomeSeriesEligibilityWpdb {

	public string $posts              = 'wp_posts';
	public string $terms              = 'wp_terms';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta           = 'wp_postmeta';
	public string $last_error         = '';
	public string $last_query         = '';
	public bool $ready                = true;
	public bool $fail                 = false;

	/** @var list<array<string,mixed>> */
	public array $rows = array();

	/**
	 * @param string $query   SQL con marcadores.
	 * @param mixed  ...$args Valores.
	 */
	public function prepare( string $query, ...$args ): string {
		unset( $args );

		return $query;
	}

	/**
	 * @param string $query  SQL preparado.
	 * @param string $output Formato pedido.
	 * @return list<array<string,mixed>>|null
	 */
	public function get_results( string $query, string $output = 'OBJECT' ): ?array {
		unset( $output );
		$this->last_query = $query;

		return $this->fail ? null : $this->rows;
	}
}
