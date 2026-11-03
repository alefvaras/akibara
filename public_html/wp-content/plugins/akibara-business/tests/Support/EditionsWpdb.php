<?php
/**
 * Doble de `$wpdb` para las consultas de EditionFacts: series por volumen y
 * precio «desde». Responde por término de la consulta, para que un cambio en
 * el SQL que deje de pedir lo que dice pedir deje la prueba en rojo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

final class EditionsWpdb {

	public string $prefix            = 'wp_';
	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta           = 'wp_postmeta';
	public string $posts             = 'wp_posts';
	public string $term_taxonomy     = 'wp_term_taxonomy';
	public string $terms             = 'wp_terms';

	/** @var array<int, array<int, int>> term_taxonomy_id de la edición → [serie_id => tomos]. */
	public array $series = array();

	/** @var array<int, float> term_taxonomy_id de la edición → precio mínimo. */
	public array $price = array();

	/** @var list<string> */
	public array $queries = array();

	/** @param mixed ...$args Valores. */
	public function prepare( string $query, ...$args ): string {
		// Como wpdb: `%d` entero, `%s` entre comillas simples y escapado.
		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $m ) use ( &$args ): string {
				$valor = array_shift( $args );
				return '%d' === $m[0] ? (string) (int) $valor : "'" . addslashes( (string) $valor ) . "'";
			},
			$query
		);
	}

	/**
	 * @param mixed $output Formato.
	 * @return array<int, array<string, int>>
	 */
	public function get_results( string $query, $output = null ): array {
		$this->queries[] = $query;
		// La consulta tiene que pedir exactamente lo que dice: sin cualquiera de
		// estas cláusulas, el doble no responde y el test cae.
		foreach ( array( "tts.taxonomy = 'pa_serie'", "p.post_type = 'product'", "p.post_status = 'publish'", "tv.name = 'exclude-from-catalog'", 'GROUP BY tts.term_id', 'ORDER BY n DESC' ) as $clausula ) {
			if ( false === strpos( $query, $clausula ) ) {
				return array();
			}
		}
		if ( ! preg_match( '/trb\.term_taxonomy_id = (\d+)/', $query, $m ) ) {
			return array();
		}
		$rows = array();
		foreach ( $this->series[ (int) $m[1] ] ?? array() as $serie_id => $n ) {
			$rows[] = array( 'term_id' => $serie_id, 'n' => $n );
		}
		preg_match( '/LIMIT (\d+)/', $query, $l );
		return array_slice( $rows, 0, isset( $l[1] ) ? (int) $l[1] : count( $rows ) );
	}

	/** @return mixed */
	public function get_var( string $query ) {
		$this->queries[] = $query;
		foreach ( array( 'wc_product_meta_lookup', "p.post_status = 'publish'", "l.stock_status IN ('instock', 'onbackorder')", 'l.min_price > 0', 'MIN(l.min_price)', "tv.name = 'exclude-from-catalog'" ) as $clausula ) {
			if ( false === strpos( $query, $clausula ) ) {
				return null;
			}
		}
		if ( ! preg_match( '/trb\.term_taxonomy_id = (\d+)/', $query, $m ) ) {
			return null;
		}
		return $this->price[ (int) $m[1] ] ?? null;
	}
}
