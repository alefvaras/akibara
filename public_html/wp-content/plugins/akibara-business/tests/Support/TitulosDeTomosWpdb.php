<?php
/**
 * Doble de `$wpdb` para `SeriesCover::titles()`.
 *
 * Responde en orden INVERSO al pedido, como puede hacerlo la base, para que el
 * desempate entre tomos del mismo número no dependa de ese orden.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

final class TitulosDeTomosWpdb {

	public string $posts = 'wp_posts';

	/** @var array<int, string> */
	public array $consultas = array();

	/** @var array<int, int> */
	private array $ids = array();

	/** @var callable(int): string */
	private $titulo;

	/**
	 * @param callable(int): string $titulo Título de cada id.
	 */
	public function __construct( callable $titulo ) {
		$this->titulo = $titulo;
	}

	public function prepare( string $query, ...$args ): string {
		$this->ids = array_map( 'intval', $args );
		return $query;
	}

	/** @return array<int, object> */
	public function get_results( string $query ): array {
		$this->consultas[] = $query;
		$titulo            = $this->titulo;

		return array_map(
			static fn( int $id ): object => (object) array(
				'ID'         => (string) $id,
				'post_title' => $titulo( $id ),
			),
			array_reverse( $this->ids )
		);
	}
}
